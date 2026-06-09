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
		file_put_contents( $tmp, "r2offload round-trip test\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations -- diagnostic round-trip: a few bytes to a wp_tempnam() file in a CLI-only command.
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
	 * [--force]
	 * : Re-upload (replace) objects already in R2 instead of adopting them.
	 *
	 * [--timeout=<seconds>]
	 * : Per-file download timeout for remote fetches (default: 300).
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload sync --dry-run
	 *     wp r2offload sync --batch=250
	 *     wp r2offload sync --verify
	 *     wp r2offload sync --force          # replace everything already in R2
	 *     wp r2offload sync --timeout=900    # libraries with large video
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$settings = Plugin::instance()->settings();
		$dry_run  = ! empty( $assoc_args['dry-run'] );
		$verify   = ! empty( $assoc_args['verify'] );
		// Force re-upload: replace objects already in R2 instead of adopting them.
		// Ignored alongside dry-run/verify (those never upload).
		$force    = ! empty( $assoc_args['force'] ) && ! $dry_run && ! $verify;

		// Verify and upload require R2 credentials. Dry-run doesn't require them
		// and never uploads — but it still issues a HEAD per item to report
		// adoption-aware counts (what an upload would skip), degrading to
		// "count everything as to-upload" when R2 isn't configured.
		$needs_r2 = ! $dry_run || $verify;
		if ( $needs_r2 && ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}

		// Upload mode writes (uploads + _r2offload_* meta). The CLI drives the
		// Migrator directly and does NOT share the background runner's lock, so
		// running it while an admin/cron migration is active would put two
		// unsynchronised writers on the same library. Refuse rather than
		// double-process. Check BOTH the running flag AND the live lock: just after
		// a Stop the run is no longer "running" but a worker may still be finishing
		// its in-flight batch and holding the lock. Dry-run/verify are read-only
		// (HEADs only), so they may run alongside a background migration.
		if ( ! $dry_run && ! $verify ) {
			$runner = new Migration_Runner( $settings );
			if ( ! empty( $runner->state()['running'] ) || $runner->has_active_worker() ) {
				\WP_CLI::error( 'A background migration is running or finishing a batch (Media → Migrate to R2). Stop it and wait a moment for the current batch to finish before running an upload sync.' );
			}
		}

		$batch   = $this->positive_int_arg( $assoc_args, 'batch', 100 );
		$timeout = $this->positive_int_arg( $assoc_args, 'timeout', 300 );

		$migrator = new Migrator();
		$migrator->set_dry_run( $dry_run )
			->set_verify( $verify )
			->set_force( $force )
			->set_download_timeout( $timeout );

		$mode = $verify ? 'verify' : ( $dry_run ? 'dry-run' : ( $force ? 'force' : 'upload' ) );
		\WP_CLI::log( sprintf( 'Mode: %s   Batch size: %d   Download timeout: %ds', $mode, $batch, $timeout ) );
		\WP_CLI::log( '' );

		// Upload mode retries failed items across passes (the cursor advances
		// past errors, so a single forward walk can leave them un-migrated),
		// matching the background runner. Verify/dry-run don't upload, so a
		// retry pass would just re-report the same items. Force re-uploads every
		// item, so a retry pass would re-send everything again — single pass too.
		$totals = $this->run_passes( $migrator, $batch, ( ! $verify && ! $dry_run && ! $force ) );

		$this->print_summary( $mode, $verify, $totals );

		// Exit code matters for automation: a dry-run is always a success (its
		// "errors" are just unmeasurable previews), but a real sync or a verify
		// that finished with errors/missing keys must exit non-zero so callers
		// can detect it — WP_CLI::warning/success both exit 0.
		if ( $dry_run ) {
			\WP_CLI::success( 'Dry-run complete — nothing uploaded.' );
			return;
		}
		if ( $totals['errors'] > 0 ) {
			\WP_CLI::error(
				$verify
					? sprintf( 'Verify finished with %d missing key(s).', $totals['errors'] )
					: sprintf( 'Sync finished with %d error(s) — see warnings above.', $totals['errors'] )
			);
		}
		\WP_CLI::success( $verify ? 'Verify finished — all expected keys present in R2.' : 'Sync complete.' );
	}

	/**
	 * Reset the in-memory object cache and query log between batches (the classic
	 * long-running-WP-CLI "stop the insanity"). This clears only the in-process
	 * runtime caches — it does NOT call wp_cache_flush(), which on a shared
	 * persistent backend (Redis/Memcached) would wipe the whole site's cache
	 * mid-migration. __remoteset() re-seeds the runtime layer for such backends.
	 */
	private function flush_object_cache() {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array();

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}
		foreach ( array( 'group_ops', 'stats', 'memcache_debug', 'cache' ) as $prop ) {
			if ( property_exists( $wp_object_cache, $prop ) ) {
				$wp_object_cache->$prop = array();
			}
		}
		if ( is_callable( array( $wp_object_cache, '__remoteset' ) ) ) {
			$wp_object_cache->__remoteset();
		}
	}

	/**
	 * Validate a positive-integer assoc arg, erroring on garbage rather than
	 * silently coercing (e.g. --batch=foo would otherwise become 1).
	 *
	 * @param array  $assoc_args
	 * @param string $key
	 * @param int    $default
	 * @return int
	 */
	private function positive_int_arg( $assoc_args, $key, $default ) {
		if ( ! isset( $assoc_args[ $key ] ) ) {
			return $default;
		}
		$value = (string) $assoc_args[ $key ];
		if ( ! ctype_digit( $value ) || (int) $value < 1 ) {
			\WP_CLI::error( sprintf( '--%s must be a positive integer.', $key ) );
		}
		return (int) $value;
	}

	/**
	 * Run batches until done, retrying failed items across passes in upload mode
	 * (bounded by MAX_PASSES). Returns the final-pass totals (uploaded/bytes are
	 * cumulative across the whole run).
	 *
	 * @param Migrator $migrator
	 * @param int      $batch
	 * @param bool     $retry_passes
	 * @return array{processed:int,uploaded:int,skipped:int,bytes:int,errors:int}
	 */
	private function run_passes( $migrator, $batch, $retry_passes ) {
		$cursor      = '';
		$totals      = array(
			'processed' => 0,
			'uploaded'  => 0,
			'updated'   => 0,
			'adopted'   => 0,
			'skipped'   => 0,
			'bytes'     => 0,
			'errors'    => 0,
		);
		$pass        = 1;
		$pass_errors = 0;

		do {
			$result = $migrator->migrate_batch( $batch, $cursor );

			$totals['processed'] += (int) $result['processed'];
			$totals['uploaded']  += (int) $result['uploaded'];
			$totals['updated']   += (int) $result['updated'];
			$totals['adopted']   += (int) $result['adopted'];
			$totals['skipped']   += (int) $result['skipped'];
			$totals['bytes']     += (int) $result['bytes'];
			$totals['errors']    += count( $result['errors'] );
			$pass_errors         += count( $result['errors'] );

			foreach ( $result['errors'] as $err ) {
				\WP_CLI::warning( $err );
			}

			\WP_CLI::log(
				sprintf(
					'  batch -> processed=%d uploaded=%d updated=%d adopted=%d skipped=%d bytes=%s next_cursor=%s',
					$result['processed'],
					$result['uploaded'],
					$result['updated'],
					$result['adopted'],
					$result['skipped'],
					size_format( (int) $result['bytes'], 2 ),
					'' === $result['next_cursor'] ? '-' : $result['next_cursor']
				)
			);

			$cursor = (string) $result['next_cursor'];
			$done   = ! empty( $result['done'] );

			if ( $done && $retry_passes && $pass_errors > 0 && $pass < Migration_Runner::MAX_PASSES ) {
				++$pass;
				\WP_CLI::log( sprintf( '  pass %d had %d error(s) — re-scanning to retry (pass %d of %d)…', $pass - 1, $pass_errors, $pass, Migration_Runner::MAX_PASSES ) );
				$pass_errors = 0;
				$cursor      = '';
				$done        = false;
				// A new pass re-walks the whole library. Reset the counts that
				// describe library state so the summary reflects the FINAL pass,
				// not the sum of every pass; uploaded/updated/bytes stay cumulative
				// as they measure real bytes moved across the run.
				$totals['processed'] = 0;
				$totals['adopted']   = 0;
				$totals['skipped']   = 0;
				$totals['errors']    = 0;
			}

			// Each batch primes the post + post-meta caches (Migrator::migrate_batch);
			// with the default in-process object cache nothing evicts them, so a long
			// run over a large library would grow memory without bound. Reset the
			// runtime caches between batches to keep it flat.
			$this->flush_object_cache();
		} while ( ! $done );

		if ( $retry_passes && $pass >= Migration_Runner::MAX_PASSES && $pass_errors > 0 ) {
			\WP_CLI::warning( sprintf(
				'Reached the maximum of %d passes with %d item(s) still failing — re-run sync to retry them.',
				Migration_Runner::MAX_PASSES,
				$pass_errors
			) );
		}

		return $totals;
	}

	/**
	 * Restore all R2-offloaded attachments back to the local /uploads directory
	 * and remove their offload registration. Run this before deactivating the
	 * plugin in Stateless mode to avoid 404s.
	 *
	 * Each attachment's postmeta is only cleared after ALL its files download
	 * successfully, so a partial failure leaves the R2 copy serving that
	 * attachment via the rewriter (images stay live) while local restoration
	 * continues for the rest.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Attachments processed per batch (default: 50).
	 *
	 * [--dry-run]
	 * : Report what would be downloaded; write nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload pull --dry-run
	 *     wp r2offload pull --yes
	 *     wp r2offload pull --batch=25
	 *
	 * @when after_wp_load
	 */
	public function pull( $args, $assoc_args ) {
		$settings = Plugin::instance()->settings();
		if ( ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$batch   = $this->positive_int_arg( $assoc_args, 'batch', 50 );
		$client  = Plugin::instance()->client();

		if ( ! $dry_run && empty( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				'This will download every R2-offloaded file back to your server and remove the R2 registration. ' .
				'Are you sure you want to continue?'
			);
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			\WP_CLI::error( 'Could not determine the uploads directory.' );
		}
		$uploads_basedir = trailingslashit( $uploads['basedir'] );

		$offset    = 0;
		$restored  = 0;
		$skipped   = 0;
		$errors    = 0;
		$dry_count = 0;

		\WP_CLI::log( sprintf( 'Mode: %s   Batch size: %d', $dry_run ? 'dry-run' : 'pull', $batch ) );
		\WP_CLI::log( '' );

		do {
			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Settings::META_SYNCED,
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			) );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$id = (int) $id;

				$base_key     = (string) get_post_meta( $id, Settings::META_KEY, true );
				$relative_raw = (string) get_post_meta( $id, '_wp_attached_file', true );
				$objects_raw  = get_post_meta( $id, Settings::META_OBJECTS, true );
				$objects      = Settings::normalize_object_keys( is_array( $objects_raw ) ? $objects_raw : array() );

				if ( '' === $base_key || '' === $relative_raw || empty( $objects ) ) {
					\WP_CLI::warning( sprintf( '#%d: missing R2 key or objects manifest — skipping.', $id ) );
					++$skipped;
					continue;
				}

				// Determine path_prefix by comparing the stored base key with the
				// uploads-relative path. e.g. key=uploads/2024/07/img.jpg,
				// relative=2024/07/img.jpg → prefix=uploads/
				$relative_clean = ltrim( $relative_raw, '/' );
				$prefix         = '';
				if ( '' !== $relative_clean && '' !== $base_key ) {
					$base_clean = ltrim( $base_key, '/' );
					if ( strlen( $base_clean ) > strlen( $relative_clean ) &&
						substr( $base_clean, -strlen( $relative_clean ) ) === $relative_clean
					) {
						$prefix = substr( $base_clean, 0, strlen( $base_clean ) - strlen( $relative_clean ) );
					}
				}

				if ( $dry_run ) {
					\WP_CLI::log( sprintf( '  #%d: would restore %d file(s)', $id, count( $objects ) ) );
					$dry_count += count( $objects );
					continue;
				}

				// Download every key in the ownership manifest.
				$att_errors = array();
				foreach ( $objects as $key ) {
					$key_clean = ltrim( $key, '/' );
					// Strip prefix to get the uploads-relative path.
					$relative_key = ( '' !== $prefix && 0 === strpos( $key_clean, $prefix ) )
						? substr( $key_clean, strlen( $prefix ) )
						: $key_clean;

					$local_path = $uploads_basedir . $relative_key;

					// Skip if already restored (idempotent re-runs).
					if ( file_exists( $local_path ) ) {
						continue;
					}

					$result = $client->download_object( $key, $local_path );
					if ( is_wp_error( $result ) ) {
						$att_errors[] = sprintf( '%s: %s', $key, $result->get_error_message() );
					}
				}

				if ( ! empty( $att_errors ) ) {
					foreach ( $att_errors as $err ) {
						\WP_CLI::warning( sprintf( '[#%d] %s', $id, $err ) );
					}
					++$errors;
					// Leave meta intact: attachment still served from R2 via the rewriter.
					continue;
				}

				// All files restored — clear the offload registration.
				delete_post_meta( $id, Settings::META_SYNCED );
				delete_post_meta( $id, Settings::META_KEY );
				delete_post_meta( $id, Settings::META_SYNCED_AT );
				delete_post_meta( $id, Settings::META_OBJECTS );
				++$restored;

				\WP_CLI::log( sprintf( '  #%d: restored %d file(s) — registration cleared.', $id, count( $objects ) ) );
			}

			$offset += $batch;
			$this->flush_object_cache();

		} while ( count( $ids ) === $batch );

		\WP_CLI::log( '' );
		\WP_CLI::log( '--- Summary ---' );
		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Would restore: %d file(s) across attachments', $dry_count ) );
			\WP_CLI::success( 'Dry-run complete — nothing downloaded.' );
			return;
		}
		\WP_CLI::log( 'Restored: ' . $restored . ' attachment(s)' );
		\WP_CLI::log( 'Skipped:  ' . $skipped . ' attachment(s)  (missing manifest)' );
		\WP_CLI::log( 'Errors:   ' . $errors . ' attachment(s)  (R2 meta kept; images still served from R2)' );
		if ( $errors > 0 ) {
			\WP_CLI::error( sprintf( 'Pull finished with %d error(s) — see warnings above. Re-run to retry.', $errors ) );
		}
		\WP_CLI::success( 'Pull complete — deactivating the plugin is now safe.' );
	}

	/**
	 * Clear all R2 offload registration from the media library WITHOUT downloading
	 * files. Use this when switching to a different offload plugin that has already
	 * taken ownership of the files, or to force a clean re-migration.
	 *
	 * WARNING: In Stateless mode (local copies deleted) this makes images 404
	 * immediately after running. Run `pull` instead unless the new provider is
	 * already serving the files.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many attachments would be reset; change nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload reset --dry-run
	 *     wp r2offload reset --yes
	 *
	 * @when after_wp_load
	 */
	public function reset( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( ! $dry_run && empty( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				'This removes all R2 offload registration (postmeta) from every attachment. ' .
				'In Stateless mode this causes immediate 404s — run `pull` first unless another plugin is already serving the files. ' .
				'Are you sure?'
			);
		}

		global $wpdb;

		$meta_keys = array(
			Settings::META_SYNCED,
			Settings::META_KEY,
			Settings::META_SYNCED_AT,
			Settings::META_OBJECTS,
		);

		if ( $dry_run ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Settings::META_SYNCED
			) );
			\WP_CLI::log( sprintf( 'Would reset: %d attachment(s)', $count ) );
			\WP_CLI::success( 'Dry-run complete — nothing changed.' );
			return;
		}

		$deleted = 0;
		foreach ( $meta_keys as $key ) {
			$rows    = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				$key
			) );
			$deleted += $rows;
		}

		\WP_CLI::success( sprintf( 'Reset complete — removed %d postmeta row(s).', $deleted ) );
	}

	/**
	 * Print the run summary.
	 *
	 * @param string $mode
	 * @param bool   $verify
	 * @param array  $totals
	 */
	private function print_summary( $mode, $verify, $totals ) {
		\WP_CLI::log( '' );
		\WP_CLI::log( '--- Summary ---' );
		\WP_CLI::log( 'Mode:       ' . $mode );
		\WP_CLI::log( 'Processed:  ' . $totals['processed'] . ' attachment(s)' );
		if ( $verify ) {
			// In verify mode the Migrator records existing keys as "skipped".
			\WP_CLI::log( 'Found:      ' . $totals['skipped'] . ' item(s)' );
			\WP_CLI::log( 'Missing:    ' . $totals['errors'] . ' item(s)' );
		} else {
			\WP_CLI::log( 'Uploaded:   ' . $totals['uploaded'] . ' item(s)  (new)' );
			\WP_CLI::log( 'Updated:    ' . $totals['updated'] . ' item(s)  (replaced in R2)' );
			\WP_CLI::log( 'Adopted:    ' . $totals['adopted'] . ' item(s)  (already in R2, newly registered)' );
			\WP_CLI::log( 'Skipped:    ' . $totals['skipped'] . ' item(s)  (already registered)' );
			\WP_CLI::log( 'Total size: ' . size_format( $totals['bytes'], 2 ) );
			\WP_CLI::log( 'Errors:     ' . $totals['errors'] );
		}
	}
}

\WP_CLI::add_command( 'r2offload', __NAMESPACE__ . '\\CLI' );
