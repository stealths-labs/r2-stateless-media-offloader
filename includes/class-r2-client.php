<?php
/**
 * Cloudflare R2 client — S3-compatible API with AWS Signature V4.
 *
 * Clean-room implementation built only on the WordPress HTTP API
 * (wp_remote_request). No AWS SDK dependency.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class R2_Client {

	/** @var Settings */
	private $settings;

	/** R2 uses a fixed region token for SigV4. */
	private $region = 'auto';

	/** @var string */
	private $service = 's3';

	/** Max transient-failure retries. */
	private $max_retries = 3;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	// -----------------------------------------------------------------
	//  Public API
	// -----------------------------------------------------------------

	/**
	 * Verify credentials by listing the bucket (max 1 key).
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection() {
		$response = $this->request( 'GET', '/', array( 'list-type' => '2', 'max-keys' => '1' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				'r2offload_connection_failed',
				sprintf(
					/* translators: 1: HTTP status, 2: response body */
					__( 'R2 returned HTTP %1$d: %2$s', 'r2-stateless-media-offload' ),
					(int) $code,
					wp_strip_all_tags( wp_remote_retrieve_body( $response ) )
				)
			);
		}
		return true;
	}

	/**
	 * Upload a local file to R2.
	 *
	 * @param string $local_path    Absolute local path.
	 * @param string $key           Destination object key.
	 * @param string $content_type  MIME type (auto-detected if empty).
	 * @param array  $extra_headers Additional headers (e.g. Cache-Control).
	 * @return true|\WP_Error
	 */
	public function upload_file( $local_path, $key, $content_type = '', $extra_headers = array() ) {
		if ( ! is_readable( $local_path ) ) {
			return new \WP_Error( 'r2offload_file_unreadable', __( 'Local file not readable.', 'r2-stateless-media-offload' ) );
		}

		// NOTE: reads the whole file into memory — the WordPress HTTP API has no
		// streaming PUT. Fine for images; chunked multipart upload for large
		// files (video) is tracked as a follow-up. Until then, refuse files past
		// a safe cap so a single large object can't exhaust the PHP heap and
		// OOM-kill the process mid-upload. Filterable for hosts with more
		// headroom.
		$max_bytes = (int) apply_filters( 'r2offload_max_upload_bytes', 50 * 1024 * 1024, $local_path, $key );
		if ( $max_bytes > 0 ) {
			$size = filesize( $local_path );
			if ( false === $size ) {
				// Can't determine the size, so can't guarantee it's under the
				// cap — refuse rather than risk an unbounded in-memory read.
				return new \WP_Error(
					'r2offload_filesize_unknown',
					__( 'Unable to determine file size to enforce the upload limit.', 'r2-stateless-media-offload' )
				);
			}
			if ( $size > $max_bytes ) {
				return new \WP_Error(
					'r2offload_file_too_large',
					sprintf(
						/* translators: 1: file size in bytes, 2: limit in bytes */
						__( 'File is %1$d bytes, above the in-memory upload limit of %2$d bytes. Increase the r2offload_max_upload_bytes filter once multipart streaming is available, or exclude this file.', 'r2-stateless-media-offload' ),
						(int) $size,
						$max_bytes
					)
				);
			}
		}

		$body = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $body ) {
			return new \WP_Error( 'r2offload_file_read_failed', __( 'Unable to read local file.', 'r2-stateless-media-offload' ) );
		}

		if ( '' === $content_type ) {
			$ft           = wp_check_filetype( $local_path );
			$content_type = $ft['type'] ? $ft['type'] : 'application/octet-stream';
		}

		$headers  = array_merge( array( 'Content-Type' => $content_type ), $extra_headers );
		$response = $this->request( 'PUT', '/' . ltrim( $key, '/' ), array(), $body, $headers );
		return $this->expect_2xx( $response, 'r2offload_upload_failed', __( 'Upload failed', 'r2-stateless-media-offload' ) );
	}

	/**
	 * Delete an object.
	 *
	 * @param string $key
	 * @return true|\WP_Error
	 */
	public function delete_object( $key ) {
		$response = $this->request( 'DELETE', '/' . ltrim( $key, '/' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $code || 200 === $code ) {
			return true;
		}
		return new \WP_Error( 'r2offload_delete_failed', sprintf( /* translators: %d: HTTP status */ __( 'Delete failed HTTP %d', 'r2-stateless-media-offload' ), $code ) );
	}

	/**
	 * Whether an object exists (HEAD).
	 *
	 * @param string $key
	 * @return bool
	 */
	public function object_exists( $key ) {
		$response = $this->request( 'HEAD', '/' . ltrim( $key, '/' ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * List objects (paginated).
	 *
	 * @param string $prefix
	 * @param int    $max_keys
	 * @param string $continuation_token
	 * @return array|\WP_Error { keys: array, is_truncated: bool, next_token: string }
	 */
	public function list_objects( $prefix = '', $max_keys = 1000, $continuation_token = '' ) {
		$params = array(
			'list-type' => '2',
			'max-keys'  => (string) min( $max_keys, 1000 ),
		);
		if ( '' !== $prefix ) {
			$params['prefix'] = $prefix;
		}
		if ( '' !== $continuation_token ) {
			$params['continuation-token'] = $continuation_token;
		}

		$response = $this->request( 'GET', '/', $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// R2 error responses (bad credentials, missing bucket, denied) are
		// valid S3-format XML with <Error> instead of <Contents>; without this
		// check they'd parse cleanly and return an empty list as if successful.
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				'r2offload_list_failed',
				sprintf(
					/* translators: 1: HTTP status, 2: response body */
					__( 'R2 returned HTTP %1$d: %2$s', 'r2-stateless-media-offload' ),
					(int) $code,
					wp_strip_all_tags( wp_remote_retrieve_body( $response ) )
				)
			);
		}

		// LIBXML_NONET blocks network access during parse; defence-in-depth
		// against XXE on PHP < 8.0 where external entities aren't disabled by
		// default.
		$xml = simplexml_load_string(
			wp_remote_retrieve_body( $response ),
			'SimpleXMLElement',
			LIBXML_NOCDATA | LIBXML_NONET
		);
		if ( false === $xml ) {
			return new \WP_Error( 'r2offload_parse_error', __( 'Unable to parse R2 list response.', 'r2-stateless-media-offload' ) );
		}

		$keys = array();
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $item ) {
				$keys[] = array(
					'key'           => (string) $item->Key,
					'size'          => (int) $item->Size,
					'last_modified' => (string) $item->LastModified,
				);
			}
		}

		return array(
			'keys'         => $keys,
			'is_truncated' => isset( $xml->IsTruncated ) && 'true' === (string) $xml->IsTruncated,
			'next_token'   => isset( $xml->NextContinuationToken ) ? (string) $xml->NextContinuationToken : '',
		);
	}

	/**
	 * Download an object from R2 to a local file (authenticated GET).
	 *
	 * Works against a private bucket regardless of custom-domain setup. Reads
	 * the body into memory then writes it — fine for images; large media would
	 * want a streamed/range download (future).
	 *
	 * @param string $key
	 * @param string $local_path Destination path (parent dir created if needed).
	 * @return true|\WP_Error
	 */
	public function download_object( $key, $local_path ) {
		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Stream the body straight to disk so a large object (e.g. video in a
		// Stateless restore) never has to fit in PHP memory.
		$response = $this->request( 'GET', '/' . ltrim( $key, '/' ), array(), '', array(), $local_path );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			// On a non-2xx the error body was streamed to the file; discard it.
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}
			return new \WP_Error( 'r2offload_download_failed', sprintf( /* translators: %d: HTTP status */ __( 'Download failed HTTP %d', 'r2-stateless-media-offload' ), $code ) );
		}

		if ( ! file_exists( $local_path ) ) {
			return new \WP_Error( 'r2offload_write_failed', __( 'Could not write downloaded object to disk.', 'r2-stateless-media-offload' ) );
		}

		// Guard against a truncated download: compare against Content-Length.
		$len = wp_remote_retrieve_header( $response, 'content-length' );
		if ( '' !== (string) $len && (int) $len !== (int) filesize( $local_path ) ) {
			wp_delete_file( $local_path );
			return new \WP_Error( 'r2offload_download_incomplete', __( 'Downloaded object was incomplete.', 'r2-stateless-media-offload' ) );
		}
		return true;
	}

	/**
	 * Public URL for an object — custom domain if set, else R2 endpoint.
	 *
	 * @param string $key
	 * @return string
	 */
	public function get_object_url( $key ) {
		// Percent-encode each key segment so the served URL is valid for keys
		// with spaces / non-ASCII characters (no-op for plain ASCII keys). The
		// edge/origin decodes it back to the raw object key on lookup.
		$path   = $this->encode_path( '/' . ltrim( $key, '/' ) );
		$domain = $this->settings->get( 'custom_domain' );
		if ( '' !== $domain ) {
			$domain = rtrim( $domain, '/' );
			if ( 0 !== strpos( $domain, 'http' ) ) {
				$domain = 'https://' . $domain;
			}
			return $domain . $path;
		}
		return $this->endpoint() . $path;
	}

	// -----------------------------------------------------------------
	//  Internal: HTTP + SigV4
	// -----------------------------------------------------------------

	/**
	 * @return string  https://<account>.r2.cloudflarestorage.com/<bucket>
	 */
	private function endpoint() {
		return 'https://' . $this->host() . '/' . rawurlencode( $this->settings->get( 'bucket' ) );
	}

	/**
	 * @return string  <account>.r2.cloudflarestorage.com
	 */
	private function host() {
		return $this->settings->get( 'account_id' ) . '.r2.cloudflarestorage.com';
	}

	/**
	 * Send a SigV4-signed request, with retry/backoff on transient failures.
	 *
	 * @param string $method
	 * @param string $path           Path after the bucket (leading slash).
	 * @param array  $query_params
	 * @param string $body
	 * @param array  $extra_headers
	 * @param string $stream_to Absolute path to stream the response body into,
	 *                          instead of buffering it in memory. For large
	 *                          downloads (e.g. video restores in Stateless mode).
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $query_params = array(), $body = '', $extra_headers = array(), $stream_to = '' ) {
		if ( ! $this->settings->is_configured() ) {
			return new \WP_Error( 'r2offload_not_configured', __( 'R2 credentials are not configured.', 'r2-stateless-media-offload' ) );
		}

		$host   = $this->host();
		$bucket = $this->settings->get( 'bucket' );
		// Encode the path ONCE here (each segment, slashes preserved) and reuse
		// the exact same string for both the canonical request and the actual
		// URL. Encoding in only one of the two places — or twice in one — makes
		// the signed path differ from the sent path and R2 returns a 403 for any
		// key with characters rawurlencode touches (spaces, non-ASCII filenames).
		$uri = '/' . rawurlencode( $bucket ) . $this->encode_path( $path );

		$now      = time();
		$date     = gmdate( 'Ymd', $now );
		$datetime = gmdate( 'Ymd\THis\Z', $now );

		$payload_hash = hash( 'sha256', $body );

		$headers = array_merge(
			array(
				'Host'                 => $host,
				'x-amz-content-sha256' => $payload_hash,
				'x-amz-date'           => $datetime,
			),
			$extra_headers
		);

		// Signed-header list (lowercased, sorted).
		$lower = array();
		foreach ( $headers as $k => $v ) {
			$lower[ strtolower( $k ) ] = trim( (string) $v );
		}
		ksort( $lower );
		$signed_headers    = implode( ';', array_keys( $lower ) );
		$canonical_headers = '';
		foreach ( $lower as $k => $v ) {
			$canonical_headers .= $k . ':' . $v . "\n";
		}

		ksort( $query_params );
		$canonical_query = $this->build_query( $query_params );

		$canonical_request = implode(
			"\n",
			array(
				$method,
				$uri, // Already encoded above; must match the request URL exactly.
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope          = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign = implode(
			"\n",
			array(
				'AWS4-HMAC-SHA256',
				$datetime,
				$scope,
				hash( 'sha256', $canonical_request ),
			)
		);

		$signing_key = $this->signing_key( $date );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->settings->get( 'access_key' ),
			$scope,
			$signed_headers,
			$signature
		);

		$url = 'https://' . $host . $uri;
		if ( '' !== $canonical_query ) {
			$url .= '?' . $canonical_query;
		}

		// WordPress sets Host itself.
		unset( $headers['Host'] );

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => 60,
			'sslverify' => true,
		);
		if ( '' !== $stream_to ) {
			// Stream the response straight to disk — the body never sits in
			// PHP memory, so large objects can't exhaust the heap.
			$args['stream']   = true;
			$args['filename'] = $stream_to;
		}

		// Retry with linear backoff on transient transport errors / 5xx.
		$attempt = 0;
		do {
			$attempt++;
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code < 500 ) {
					return $response;
				}
			}
			if ( $attempt < $this->max_retries ) {
				sleep( $attempt ); // 1s, 2s backoff.
			}
		} while ( $attempt < $this->max_retries );

		return $response;
	}

	/**
	 * @param array|\WP_Error $response
	 * @param string          $error_code
	 * @param string          $error_msg
	 * @return true|\WP_Error
	 */
	private function expect_2xx( $response, $error_code, $error_msg ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code <= 299 ) {
			return true;
		}
		return new \WP_Error( $error_code, $error_msg . sprintf( ' (HTTP %d)', $code ) );
	}

	/**
	 * Derive the SigV4 signing key.
	 *
	 * @param string $date Ymd
	 * @return string Binary key.
	 */
	private function signing_key( $date ) {
		$secret  = $this->settings->get( 'secret_key' );
		$k_date  = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k_reg   = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_svc   = hash_hmac( 'sha256', $this->service, $k_reg, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_svc, true );
	}

	/**
	 * Canonical query string (sorted, RFC-3986 encoded).
	 *
	 * @param array $params
	 * @return string
	 */
	private function build_query( $params ) {
		if ( empty( $params ) ) {
			return '';
		}
		ksort( $params );
		$parts = array();
		foreach ( $params as $k => $v ) {
			$parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		return implode( '&', $parts );
	}

	/**
	 * Encode a URI path per S3 spec (encode each segment, keep the slashes).
	 *
	 * @param string $path
	 * @return string
	 */
	private function encode_path( $path ) {
		$segments = explode( '/', $path );
		return implode( '/', array_map( 'rawurlencode', $segments ) );
	}
}
