<?php
/**
 * WP-CLI commands.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage R2 Stateless Media Offload from the command line.
 */
class CLI {

	/**
	 * Verify the R2 connection and round-trip an object (upload, head, list, delete).
	 *
	 * This is the validation gate for the SigV4 client (SWR-304).
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload test
	 *
	 * @when after_wp_load
	 */
	public function test( $args, $assoc_args ) {
		$client   = Plugin::instance()->client();
		$settings = Plugin::instance()->settings();

		if ( ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}

		\WP_CLI::log( 'Bucket:   ' . $settings->get( 'bucket' ) );
		\WP_CLI::log( 'Endpoint: ' . $settings->get( 'account_id' ) . '.r2.cloudflarestorage.com' );
		\WP_CLI::log( '' );

		// 1. Connection.
		\WP_CLI::log( '1/5 test_connection ...' );
		$res = $client->test_connection();
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Connection failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Connected.' );

		// 2. Upload.
		$key = 'r2offload-test/' . gmdate( 'Ymd-His' ) . '.txt';
		$tmp = wp_tempnam( 'r2offload-test' );
		file_put_contents( $tmp, "r2offload round-trip test\n" ); // phpcs:ignore
		\WP_CLI::log( "2/5 upload  -> {$key}" );
		$res = $client->upload_file( $tmp, $key, 'text/plain', array( 'Cache-Control' => 'no-store' ) );
		wp_delete_file( $tmp );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Upload failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Uploaded.' );

		// 3. Exists.
		\WP_CLI::log( '3/5 head    (object_exists)' );
		if ( ! $client->object_exists( $key ) ) {
			\WP_CLI::error( 'object_exists returned false for an object we just uploaded.' );
		}
		\WP_CLI::success( 'Exists.' );

		// 4. List.
		\WP_CLI::log( '4/5 list    (prefix r2offload-test/)' );
		$list = $client->list_objects( 'r2offload-test/', 10 );
		if ( is_wp_error( $list ) ) {
			\WP_CLI::error( 'List failed: ' . $list->get_error_message() );
		}
		\WP_CLI::success( 'Listed ' . count( $list['keys'] ) . ' object(s).' );

		// 5. Delete.
		\WP_CLI::log( '5/5 delete' );
		$res = $client->delete_object( $key );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Delete failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Deleted.' );

		\WP_CLI::log( '' );
		\WP_CLI::success( 'R2_Client validation gate PASSED — SigV4 round-trip works. 🎉' );
		\WP_CLI::log( 'Public URL would be: ' . $client->get_object_url( $key ) );
	}

	/**
	 * Migrate the media library to R2 from anywhere (local, GCS, S3, …).
	 *
	 * Walks the `attachment` post type in batches and copies each
	 * attachment's original plus every registered intermediate size into R2.
	 * Reads from a local copy when available, otherwise fetches from the
	 * current public URL (whatever offload plugin is in place today). The R2
	 * key matches each file's `_wp_attached_file` relative path.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Attachments processed per batch (default: 100).
	 *
	 * [--dry-run]
	 * : Report counts + total bytes; upload nothing.
	 *
	 * [--verify]
	 * : HEAD-check expected keys in R2 and report any that are missing.
	 *
	 * [--timeout=<seconds>]
	 * : Per-file download timeout for remote fetches (default: 300).
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload sync --dry-run
	 *     wp r2offload sync --batch=250
	 *     wp r2offload sync --verify
	 *     wp r2offload sync --timeout=900   # libraries with large video
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$settings = Plugin::instance()->settings();
		$dry_run  = ! empty( $assoc_args['dry-run'] );
		$verify   = ! empty( $assoc_args['verify'] );

		// Dry-run without verify is the only mode that doesn't touch R2.
		$needs_r2 = ! $dry_run || $verify;
		if ( $needs_r2 && ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}

		$batch   = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;
		$timeout = isset( $assoc_args['timeout'] ) ? max( 1, (int) $assoc_args['timeout'] ) : 300;

		$migrator = new Migrator();
		$migrator->set_dry_run( $dry_run )
			->set_verify( $verify )
			->set_download_timeout( $timeout );

		$mode = $verify ? 'verify' : ( $dry_run ? 'dry-run' : 'upload' );
		\WP_CLI::log( sprintf( 'Mode: %s   Batch size: %d   Download timeout: %ds', $mode, $batch, $timeout ) );
		\WP_CLI::log( '' );

		$cursor = '';
		$totals = array(
			'processed' => 0,
			'uploaded'  => 0,
			'skipped'   => 0,
			'bytes'     => 0,
			'errors'    => 0,
		);

		do {
			$result = $migrator->migrate_batch( $batch, $cursor );

			$totals['processed'] += (int) $result['processed'];
			$totals['uploaded']  += (int) $result['uploaded'];
			$totals['skipped']   += (int) $result['skipped'];
			$totals['bytes']     += (int) $result['bytes'];
			$totals['errors']    += count( $result['errors'] );

			foreach ( $result['errors'] as $err ) {
				\WP_CLI::warning( $err );
			}

			\WP_CLI::log(
				sprintf(
					'  batch -> processed=%d uploaded=%d skipped=%d bytes=%s next_cursor=%s',
					$result['processed'],
					$result['uploaded'],
					$result['skipped'],
					size_format( (int) $result['bytes'], 2 ),
					'' === $result['next_cursor'] ? '-' : $result['next_cursor']
				)
			);

			$cursor = (string) $result['next_cursor'];
			$done   = ! empty( $result['done'] );
		} while ( ! $done );

		\WP_CLI::log( '' );
		\WP_CLI::log( '--- Summary ---' );
		\WP_CLI::log( 'Mode:       ' . $mode );
		\WP_CLI::log( 'Processed:  ' . $totals['processed'] . ' attachment(s)' );
		if ( $verify ) {
			// In verify mode the Migrator records existing keys as "skipped".
			\WP_CLI::log( 'Found:      ' . $totals['skipped'] . ' item(s)' );
			\WP_CLI::log( 'Missing:    ' . $totals['errors'] . ' item(s)' );
		} else {
			\WP_CLI::log( 'Uploaded:   ' . $totals['uploaded'] . ' item(s)' );
			\WP_CLI::log( 'Skipped:    ' . $totals['skipped'] . ' item(s)' );
			\WP_CLI::log( 'Total size: ' . size_format( $totals['bytes'], 2 ) );
			\WP_CLI::log( 'Errors:     ' . $totals['errors'] );
		}

		if ( ! $verify && $totals['errors'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d error(s) — see warnings above.', $totals['errors'] ) );
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry-run complete — nothing uploaded.' );
		} elseif ( $verify ) {
			$totals['errors'] > 0
				? \WP_CLI::warning( 'Verify finished with missing keys.' )
				: \WP_CLI::success( 'Verify finished — all expected keys present in R2.' );
		} else {
			\WP_CLI::success( 'Sync complete.' );
		}
	}
}

\WP_CLI::add_command( 'r2offload', __NAMESPACE__ . '\\CLI' );
