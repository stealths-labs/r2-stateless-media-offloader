<?php
/**
 * Settings store with dual credential model.
 *
 * Each value resolves from a wp-config constant first (e.g. R2OFFLOAD_ACCESS_KEY),
 * falling back to the DB-stored option from the admin UI. When a constant is
 * defined, the UI displays "Defined in wp-config.php" and disables the field.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_KEY = 'r2offload_settings';

	/**
	 * Map of setting key => wp-config constant name.
	 *
	 * @var array<string,string>
	 */
	private $constants = array(
		'account_id'    => 'R2OFFLOAD_ACCOUNT_ID',
		'access_key'    => 'R2OFFLOAD_ACCESS_KEY',
		'secret_key'    => 'R2OFFLOAD_SECRET_KEY',
		'bucket'        => 'R2OFFLOAD_BUCKET',
		'custom_domain' => 'R2OFFLOAD_CUSTOM_DOMAIN',
		'mode'          => 'R2OFFLOAD_MODE',
		'cache_control' => 'R2OFFLOAD_CACHE_CONTROL',
		'path_prefix'   => 'R2OFFLOAD_PATH_PREFIX',
	);

	/**
	 * Default values for settings without a constant or stored value.
	 *
	 * @var array<string,string>
	 */
	private $defaults = array(
		'mode'          => 'cdn', // Safe on-ramp. Flip to 'stateless' once verified.
		'cache_control' => 'public, max-age=31536000',
		'custom_domain' => '',
		'path_prefix'   => '', // e.g. 'uploads/'. Governs NEW uploads only.
	);

	/** @var array|null */
	private $stored = null;

	/**
	 * Resolve a setting: constant first, then DB, then default.
	 *
	 * @param string $key
	 * @return string
	 */
	public function get( $key ) {
		if ( isset( $this->constants[ $key ] ) && defined( $this->constants[ $key ] ) ) {
			return (string) constant( $this->constants[ $key ] );
		}
		$stored = $this->stored();
		if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] ) {
			$value = (string) $stored[ $key ];
			// The secret is stored encrypted at rest; decrypt on read.
			return ( 'secret_key' === $key ) ? $this->decrypt( $value ) : $value;
		}
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '';
	}

	/**
	 * Encrypt a value for storage at rest (used for the secret key).
	 * Keyed off the site's auth salt. Returns plaintext unchanged if OpenSSL
	 * is unavailable.
	 *
	 * @param string $plain
	 * @return string
	 */
	public function encrypt_secret( $plain ) {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		// AES-256-GCM (authenticated) — detects tampering, unlike CBC.
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return $plain;
		}
		// Versioned marker so the format can evolve without breaking old blobs.
		return 'r2enc:v2:' . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a stored secret. Values without the marker are treated as
	 * plaintext (legacy / OpenSSL-unavailable installs).
	 *
	 * @param string $stored
	 * @return string
	 */
	private function decrypt( $stored ) {
		// No marker → legitimate legacy plaintext, return as-is.
		if ( 0 !== strpos( $stored, 'r2enc:' ) ) {
			return $stored;
		}
		// Marked as encrypted but we can't decrypt → surface, don't silently blank.
		$fail = function ( $why ) {
			error_log( 'r2offload: could not decrypt stored secret (' . $why . '). The site auth salt may have rotated — re-enter the Secret Access Key.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		};
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $fail( 'OpenSSL unavailable' );
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		// v2 — AES-256-GCM: iv(12) | tag(16) | ciphertext.
		if ( 0 === strpos( $stored, 'r2enc:v2:' ) ) {
			$raw = base64_decode( substr( $stored, 9 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return $fail( 'corrupt ciphertext' );
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return ( false === $plain ) ? $fail( 'wrong key or tampered' ) : $plain;
		}

		// v1 — AES-256-CBC: iv(16) | ciphertext (backward compatibility).
		$raw = base64_decode( substr( $stored, 6 ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return $fail( 'corrupt ciphertext' );
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return ( false === $plain ) ? $fail( 'wrong key' ) : $plain;
	}

	/**
	 * Resolve an offloaded attachment's original R2 key, or false when it isn't
	 * offloaded. Single source of truth shared by the URL rewriter, the
	 * stateless read path, and deletes.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	public function resolve_object_key( $attachment_id ) {
		$key = (string) get_post_meta( $attachment_id, '_r2offload_key', true );
		if ( '' === $key ) {
			$synced = get_post_meta( $attachment_id, '_r2offload_synced', true );
			$file   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( $synced && '' !== $file ) {
				$key = $this->object_key( $file );
			}
		}
		return ( '' === $key ) ? false : $key;
	}

	/**
	 * Whether a setting is locked by a wp-config constant.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function is_constant( $key ) {
		return isset( $this->constants[ $key ] ) && defined( $this->constants[ $key ] );
	}

	/**
	 * Build the canonical R2 object key for a path relative to the uploads dir.
	 *
	 * Single source of truth for key construction — offloader, migrator, URL
	 * rewriter and stream wrapper all route through this so they can never
	 * disagree. The `path_prefix` setting governs NEW keys only; existing media
	 * is resolved from its stored `_r2offload_key` (see SWR-313).
	 *
	 * @param string $relative e.g. '2017/03/the-kitsches.jpg'
	 * @return string e.g. 'uploads/2017/03/the-kitsches.jpg'
	 */
	public function object_key( $relative ) {
		$prefix   = ltrim( $this->get( 'path_prefix' ), '/' );
		$relative = ltrim( (string) $relative, '/' );
		if ( '' !== $prefix ) {
			$prefix = trailingslashit( $prefix );
		}
		return $prefix . $relative;
	}

	/**
	 * Enumerate an attachment's files — the original plus every registered size
	 * — as uploads-relative paths. Single source of truth so the offloader,
	 * migrator and delete path can never disagree on which files an attachment
	 * comprises. The original is always first.
	 *
	 * @param array  $metadata Attachment metadata (wp_get_attachment_metadata()).
	 * @param string $relative Original `_wp_attached_file` path.
	 * @return array<int,array{relative:string,size:string,filename:string}>
	 */
	public static function enumerate_files( $metadata, $relative ) {
		$relative = (string) $relative;
		if ( '' === $relative ) {
			return array();
		}

		$dir = dirname( $relative );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

		$files = array(
			array(
				'relative' => $relative,
				'size'     => '',
				'filename' => wp_basename( $relative ),
			),
		);

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$filename = (string) $size_data['file'];
				$files[]  = array(
					'relative' => $dir . $filename,
					'size'     => (string) $size_name,
					'filename' => $filename,
				);
			}
		}

		return $files;
	}

	/**
	 * Is the plugin fully configured to talk to R2?
	 *
	 * @return bool
	 */
	public function is_configured() {
		foreach ( array( 'account_id', 'access_key', 'secret_key', 'bucket' ) as $required ) {
			if ( '' === $this->get( $required ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lazy-load the stored option.
	 *
	 * @return array
	 */
	private function stored() {
		if ( null === $this->stored ) {
			$this->stored = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $this->stored ) ) {
				$this->stored = array();
			}
		}
		return $this->stored;
	}

	/**
	 * Register the admin settings page (SWR-309).
	 */
	public function register() {
		( new Admin_Settings( $this ) )->register();
	}
}
