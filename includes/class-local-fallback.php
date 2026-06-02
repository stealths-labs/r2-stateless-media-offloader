<?php
/**
 * Local fallback — the R2 read path for Stateless mode (SWR-307).
 *
 * In Stateless mode local copies are removed after offload, so WordPress can't
 * read a file off disk for operations like thumbnail regeneration or image
 * editing. Rather than back the whole uploads directory with an r2:// stream
 * wrapper (invasive, high-risk), this hooks the single chokepoint WordPress
 * uses to resolve a file's local path and transparently restores the bytes
 * from R2 on demand. The restored copy is transient working data; the offloader
 * re-uploads (and, in Stateless mode, re-removes) any derivatives produced.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Local_Fallback {

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/**
	 * Temp files restored this request, removed on shutdown.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * @param R2_Client $client
	 * @param Settings  $settings
	 */
	public function __construct( R2_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook the read path. Only active in Stateless mode — in CDN mode the local
	 * copies are still present, so nothing to restore.
	 */
	public function register() {
		if ( 'stateless' !== $this->settings->get( 'mode' ) ) {
			return;
		}
		add_filter( 'get_attached_file', array( $this, 'ensure_local' ), 10, 2 );
		add_filter( 'load_image_to_edit_path', array( $this, 'ensure_local_for_edit' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'cleanup' ) );
	}

	/**
	 * Provide a readable local path for an attachment's original, restoring it
	 * from R2 to a temporary file when it has been removed (Stateless mode).
	 *
	 * The container's uploads directory is not assumed writable at runtime, so
	 * the restore lands in the system temp dir and that path is returned —
	 * WordPress image functions accept any readable path as a source.
	 *
	 * @param string $file          Expected local path.
	 * @param int    $attachment_id
	 * @return string
	 */
	public function ensure_local( $file, $attachment_id ) {
		if ( '' === (string) $file || file_exists( $file ) ) {
			return $file;
		}
		$key = $this->original_key( (int) $attachment_id );
		if ( false === $key ) {
			return $file;
		}
		$tmp = $this->restore_to_temp( $key, wp_basename( $file ), (int) $attachment_id );
		return ( '' === $tmp ) ? $file : $tmp;
	}

	/**
	 * Same guarantee for the image-editor load path.
	 *
	 * @param string $filepath
	 * @param int    $attachment_id
	 * @param string|int[] $size
	 * @return string
	 */
	public function ensure_local_for_edit( $filepath, $attachment_id, $size ) {
		if ( '' === (string) $filepath || file_exists( $filepath ) ) {
			return $filepath;
		}
		$original = $this->original_key( (int) $attachment_id );
		if ( false === $original ) {
			return $filepath;
		}
		// The editor path may be a size; restore the matching R2 object by
		// keeping the original's directory and the requested basename.
		$dir = dirname( $original );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
		$key = $dir . wp_basename( $filepath );
		$tmp = $this->restore_to_temp( $key, wp_basename( $filepath ), (int) $attachment_id );
		return ( '' === $tmp ) ? $filepath : $tmp;
	}

	/**
	 * Download an R2 object to a temporary file and return its path.
	 *
	 * @param string $key
	 * @param string $basename Preserve the extension so image functions work.
	 * @param int    $attachment_id For error context.
	 * @return string Temp path on success, '' on failure.
	 */
	private function restore_to_temp( $key, $basename, $attachment_id ) {
		$tmp = wp_tempnam( $basename );
		if ( ! $tmp ) {
			return '';
		}
		$restored = $this->client->download_object( $key, $tmp );
		if ( is_wp_error( $restored ) ) {
			wp_delete_file( $tmp );
			error_log( sprintf( 'r2offload: restore failed for %s (attachment %d): %s', $key, $attachment_id, $restored->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}
		$this->temp_files[] = $tmp;
		return $tmp;
	}

	/**
	 * Remove restored temp files at the end of the request.
	 */
	public function cleanup() {
		foreach ( $this->temp_files as $tmp ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
		$this->temp_files = array();
	}

	/**
	 * Resolve an offloaded attachment's original R2 key, or false.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	private function original_key( $attachment_id ) {
		return $this->settings->resolve_object_key( $attachment_id );
	}
}
