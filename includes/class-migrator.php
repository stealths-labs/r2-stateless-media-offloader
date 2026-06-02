<?php
/**
 * Universal media library migrator.
 *
 * Walks the `attachment` post type and copies every attachment (plus every
 * registered intermediate size) into R2. For each attachment we pick the best
 * source we can find at runtime:
 *
 *   1. A local copy on disk (`get_attached_file()` exists).
 *   2. The current public URL served by whatever offload plugin is in place
 *      today (wp-stateless on GCS, WP Offload Media on S3/Spaces, …) via
 *      `wp_get_attachment_url()`. The basename is swapped for each size so
 *      all variants come from the same host.
 *
 * The R2 key is the attachment's `_wp_attached_file` relative path (e.g.
 * `2017/03/the-kitsches.jpg`), so the key space matches what WordPress
 * already records — no separate lookup table.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate existing media library attachments into R2.
 */
class Migrator {

	const META_SYNCED    = '_r2offload_synced';
	const META_SYNCED_AT = '_r2offload_synced_at';

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/** @var bool */
	private $dry_run = false;

	/** @var bool */
	private $verify = false;

	/**
	 * Per-file download timeout in seconds. Matches WordPress's own
	 * `download_url()` default so large videos / PDFs aren't truncated.
	 *
	 * @var int
	 */
	private $download_timeout = 300;

	/**
	 * @param R2_Client|null $client
	 * @param Settings|null  $settings
	 */
	public function __construct( ?R2_Client $client = null, ?Settings $settings = null ) {
		$this->client   = $client ? $client : Plugin::instance()->client();
		$this->settings = $settings ? $settings : Plugin::instance()->settings();
	}

	/**
	 * Toggle dry-run mode: count + size without uploading.
	 *
	 * @param bool $on
	 * @return self
	 */
	public function set_dry_run( $on ) {
		$this->dry_run = (bool) $on;
		return $this;
	}

	/**
	 * Toggle verify mode: HEAD-check expected keys in R2.
	 *
	 * @param bool $on
	 * @return self
	 */
	public function set_verify( $on ) {
		$this->verify = (bool) $on;
		return $this;
	}

	/**
	 * Override the per-file download timeout (seconds). Useful for libraries
	 * that contain large media (video / hi-res PDFs).
	 *
	 * @param int $seconds
	 * @return self
	 */
	public function set_download_timeout( $seconds ) {
		$this->download_timeout = max( 1, (int) $seconds );
		return $this;
	}

	// -----------------------------------------------------------------
	//  Public API
	// -----------------------------------------------------------------

	/**
	 * Migrate a single attachment (original + every registered size).
	 *
	 * @param int $attachment_id
	 * @return array { uploaded:int, skipped:int, bytes:int, errors:string[] }
	 */
	public function migrate_attachment( $attachment_id ) {
		$result = array(
			'uploaded' => 0,
			'skipped'  => 0,
			'bytes'    => 0,
			'errors'   => array(),
		);

		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			$result['errors'][] = __( 'Invalid attachment ID.', 'r2-stateless-media-offload' );
			return $result;
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $relative ) {
			$result['errors'][] = sprintf(
				/* translators: %d: attachment ID */
				__( 'Attachment %d has no _wp_attached_file.', 'r2-stateless-media-offload' ),
				$attachment_id
			);
			return $result;
		}
		$relative = ltrim( $relative, '/' );

		$items = $this->build_items( $attachment_id, $relative );

		foreach ( $items as $item ) {
			$this->migrate_item( $attachment_id, $item, $result );
		}

		// Only flag the attachment as synced when a real upload pass cleared
		// the queue. Dry-run / verify never write postmeta.
		if (
			! $this->dry_run
			&& ! $this->verify
			&& empty( $result['errors'] )
			&& ( $result['uploaded'] + $result['skipped'] ) > 0
		) {
			update_post_meta( $attachment_id, self::META_SYNCED, 1 );
			// Preserve the original first-sync timestamp on re-runs that find
			// every item already present in R2.
			$first_synced_at = get_post_meta( $attachment_id, self::META_SYNCED_AT, true );
			if ( '' === (string) $first_synced_at ) {
				update_post_meta( $attachment_id, self::META_SYNCED_AT, time() );
			}
		}

		return $result;
	}

	/**
	 * Resumable batch migration ordered by attachment ID.
	 *
	 * @param int    $batch_size
	 * @param string $cursor Last processed attachment ID (exclusive lower bound).
	 * @return array {
	 *     processed:int, uploaded:int, skipped:int, bytes:int,
	 *     errors:string[], next_cursor:string, done:bool
	 * }
	 */
	public function migrate_batch( $batch_size = 100, $cursor = '' ) {
		$batch_size = max( 1, (int) $batch_size );
		$cursor_id  = '' === $cursor ? 0 : (int) $cursor;

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'attachment'
					AND post_status != 'trash'
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
				$cursor_id,
				$batch_size
			)
		);

		$aggregate = array(
			'processed'   => 0,
			'uploaded'    => 0,
			'skipped'     => 0,
			'bytes'       => 0,
			'errors'      => array(),
			'next_cursor' => (string) $cursor_id,
			'done'        => true,
		);

		if ( empty( $ids ) ) {
			return $aggregate;
		}

		foreach ( $ids as $raw_id ) {
			$id  = (int) $raw_id;
			$res = $this->migrate_attachment( $id );

			$aggregate['processed']  += 1;
			$aggregate['uploaded']   += (int) $res['uploaded'];
			$aggregate['skipped']    += (int) $res['skipped'];
			$aggregate['bytes']      += (int) $res['bytes'];
			$aggregate['next_cursor'] = (string) $id;

			foreach ( $res['errors'] as $err ) {
				$aggregate['errors'][] = sprintf( '[#%d] %s', $id, $err );
			}
		}

		$aggregate['done'] = count( $ids ) < $batch_size;
		return $aggregate;
	}

	// -----------------------------------------------------------------
	//  Internals
	// -----------------------------------------------------------------

	/**
	 * Build the unique list of (key, size, filename) items for an attachment.
	 *
	 * @param int    $attachment_id
	 * @param string $relative Original `_wp_attached_file` path.
	 * @return array<string,array{key:string,size:string,filename:string}>
	 */
	private function build_items( $attachment_id, $relative ) {
		$dir = dirname( $relative );
		$dir = ( '' === $dir || '.' === $dir ) ? '' : trailingslashit( $dir );

		$items = array();
		$items[ $relative ] = array(
			'key'      => $relative,
			'size'     => '',
			'filename' => wp_basename( $relative ),
		);

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$filename = (string) $size_data['file'];
				$key      = $dir . $filename;
				if ( isset( $items[ $key ] ) ) {
					continue; // Sizes can share a filename with the original.
				}
				$items[ $key ] = array(
					'key'      => $key,
					'size'     => (string) $size_name,
					'filename' => $filename,
				);
			}
		}

		return $items;
	}

	/**
	 * Migrate one (attachment, size) item — verify, dry-run, or upload.
	 *
	 * Mutates $result in place so the per-attachment summary aggregates the
	 * outcome for every variant.
	 *
	 * @param int   $attachment_id
	 * @param array $item   { key, size, filename }
	 * @param array $result Mutated in place.
	 * @return void
	 */
	private function migrate_item( $attachment_id, array $item, array &$result ) {
		$key  = $item['key'];
		$size = $item['size'];

		if ( $this->verify ) {
			if ( $this->client->object_exists( $key ) ) {
				$result['skipped'] += 1;
			} else {
				$result['errors'][] = sprintf(
					/* translators: %s: object key */
					__( 'Missing in R2: %s', 'r2-stateless-media-offload' ),
					$key
				);
			}
			return;
		}

		// Real-upload path skips anything already in R2.
		if ( ! $this->dry_run && $this->client->object_exists( $key ) ) {
			$result['skipped'] += 1;
			return;
		}

		$local     = $this->local_path_for( $attachment_id, $size, $item );
		$has_local = ( '' !== $local && is_readable( $local ) );

		if ( $this->dry_run ) {
			$bytes = $this->measure_source( $attachment_id, $size, $item, $local, $has_local, $result, $key );
			if ( null !== $bytes ) {
				$result['uploaded'] += 1;
				$result['bytes']    += $bytes;
			}
			return;
		}

		$tmp        = '';
		$local_path = '';

		if ( $has_local ) {
			$local_path = $local;
		} else {
			$url = $this->url_for( $attachment_id, $size, $item );
			if ( '' === $url ) {
				$result['errors'][] = sprintf(
					/* translators: %s: object key */
					__( '%s: no source URL', 'r2-stateless-media-offload' ),
					$key
				);
				return;
			}
			$downloaded = $this->download_to_tempfile( $url );
			if ( is_wp_error( $downloaded ) ) {
				$result['errors'][] = sprintf( '%s: %s', $key, $downloaded->get_error_message() );
				return;
			}
			$tmp        = (string) $downloaded;
			$local_path = $tmp;
		}

		// $local_path is readable at this point (either checked via is_readable()
		// for the local case or by download_to_tempfile() for the remote case).
		$size_bytes = (int) filesize( $local_path );
		$headers    = array();
		$cc         = $this->settings->get( 'cache_control' );
		if ( '' !== $cc ) {
			$headers['Cache-Control'] = $cc;
		}

		$uploaded = $this->client->upload_file(
			$local_path,
			$key,
			$this->guess_content_type( $item['filename'] ),
			$headers
		);

		if ( '' !== $tmp ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $uploaded ) ) {
			$result['errors'][] = sprintf( '%s: %s', $key, $uploaded->get_error_message() );
			return;
		}

		$result['uploaded'] += 1;
		$result['bytes']    += $size_bytes;
	}

	/**
	 * Dry-run measurement: report bytes without downloading.
	 *
	 * Uses filesize() for local copies and HEAD for remote URLs. Returns null
	 * if the source can't be measured (an error is appended to $result).
	 *
	 * @param int    $attachment_id
	 * @param string $size
	 * @param array  $item
	 * @param string $local
	 * @param bool   $has_local
	 * @param array  $result
	 * @param string $key
	 * @return int|null
	 */
	private function measure_source( $attachment_id, $size, array $item, $local, $has_local, array &$result, $key ) {
		if ( $has_local ) {
			$bytes = filesize( $local ); // Caller has already checked is_readable().
			return false === $bytes ? 0 : (int) $bytes;
		}
		$url = $this->url_for( $attachment_id, $size, $item );
		if ( '' === $url ) {
			$result['errors'][] = sprintf(
				/* translators: %s: object key */
				__( '%s: no source URL', 'r2-stateless-media-offload' ),
				$key
			);
			return null;
		}
		$head = wp_remote_head(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $head ) ) {
			$result['errors'][] = sprintf( '%s: %s', $key, $head->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $head );
		if ( $code < 200 || $code >= 300 ) {
			$result['errors'][] = sprintf(
				/* translators: 1: object key, 2: HTTP status */
				__( '%1$s: HEAD returned HTTP %2$d', 'r2-stateless-media-offload' ),
				$key,
				$code
			);
			return null;
		}
		$len = wp_remote_retrieve_header( $head, 'content-length' );
		return is_string( $len ) ? (int) $len : 0;
	}

	/**
	 * Resolve a local disk path for an attachment/size, if any exists.
	 *
	 * @param int    $attachment_id
	 * @param string $size       '' for the full-size original.
	 * @param array  $item
	 * @return string Absolute path, or '' when not derivable.
	 */
	private function local_path_for( $attachment_id, $size, array $item ) {
		if ( '' === $size ) {
			$path = get_attached_file( $attachment_id );
			return is_string( $path ) ? $path : '';
		}
		$original = get_attached_file( $attachment_id );
		if ( ! is_string( $original ) || '' === $original ) {
			return '';
		}
		return trailingslashit( dirname( $original ) ) . $item['filename'];
	}

	/**
	 * Resolve the current public URL for an attachment/size by swapping the
	 * basename of the original URL — keeps every variant on the same host as
	 * whatever offload plugin is serving today.
	 *
	 * @param int    $attachment_id
	 * @param string $size
	 * @param array  $item
	 * @return string URL or '' if none derivable.
	 */
	private function url_for( $attachment_id, $size, array $item ) {
		$base = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}
		if ( '' === $size ) {
			return $base;
		}
		$pos = strrpos( $base, '/' );
		if ( false === $pos ) {
			return $base;
		}
		return substr( $base, 0, $pos + 1 ) . $item['filename'];
	}

	/**
	 * Download a URL to a temp file via the WordPress HTTP API.
	 *
	 * @param string $url
	 * @return string|\WP_Error Tempfile path on success.
	 */
	private function download_to_tempfile( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, $this->download_timeout );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! is_string( $tmp ) || ! is_readable( $tmp ) ) {
			return new \WP_Error(
				'r2offload_download_failed',
				__( 'Downloaded tempfile is not readable.', 'r2-stateless-media-offload' )
			);
		}
		return $tmp;
	}

	/**
	 * @param string $filename_or_path
	 * @return string MIME type, falling back to application/octet-stream.
	 */
	private function guess_content_type( $filename_or_path ) {
		$ft = wp_check_filetype( $filename_or_path );
		return ! empty( $ft['type'] ) ? (string) $ft['type'] : 'application/octet-stream';
	}
}
