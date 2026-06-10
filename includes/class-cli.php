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
			$this->refuse_if_migration_active( $settings );
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
	 * Error out when a background migration is running or a worker is still
	 * finishing a batch. CLI commands that write to the library (sync upload,
	 * pull, reset) do NOT share the background runner's lock, so running them
	 * alongside an active migration would put two unsynchronised writers on
	 * the same attachments' _r2offload_* meta. Check BOTH the running flag AND
	 * the live lock: just after a Stop the run is no longer "running" but a
	 * worker may still be finishing its in-flight batch and holding the lock.
	 *
	 * @param Settings $settings
	 */
	private function refuse_if_migration_active( $settings ) {
		$runner = new Migration_Runner( $settings );
		// Bust the per-request options cache before reading: a stale cached
		// running=false (read earlier this request, before a background start())
		// would defeat the control-plane check and let two writers interleave.
		wp_cache_delete( Migration_Runner::STATE_OPTION, 'options' );
		if ( ! empty( $runner->state()['running'] ) || $runner->has_active_worker() ) {
			\WP_CLI::error( 'A background migration is running or finishing a batch (Media → Migrate to R2). Stop it and wait a moment for the current batch to finish before running this command.' );
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
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$batch   = $this->positive_int_arg( $assoc_args, 'batch', 50 );

		// Dry-run only reads local postmeta (reports what would be downloaded),
		// so it doesn't require credentials — matching `sync --dry-run`. When
		// credentials ARE available, dry-run still gets a client so the legacy
		// HEAD filter below can run and its counts match a real pull; without
		// them, legacy counts degrade to an upper bound.
		$settings = Plugin::instance()->settings();
		if ( ! $dry_run && ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}
		$client = ( ! $dry_run || $settings->is_configured() ) ? Plugin::instance()->client() : null;

		// A live pull deletes _r2offload_* meta that an active background
		// migration worker writes — same unsynchronised-writers hazard as an
		// upload sync, so apply the same guard. Dry-run is read-only.
		if ( ! $dry_run ) {
			$this->refuse_if_migration_active( $settings );
		}

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

		global $wpdb;

		$last_id   = 0;
		$restored  = 0;
		$skipped   = 0;
		$errors    = 0;
		$dry_count = 0;

		\WP_CLI::log( sprintf( 'Mode: %s   Batch size: %d', $dry_run ? 'dry-run' : 'pull', $batch ) );
		\WP_CLI::log( '' );

		do {
			// Keyset pagination (ID > last seen), NOT offset: successful restores
			// delete META_SYNCED, shrinking the result set under an offset walk —
			// offset += batch would then skip the next batch of still-synced
			// attachments and the command could report success with files still
			// only on R2.
			$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset pagination over postmeta; WP_Query cannot express ID > x.
				"SELECT DISTINCT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND pm.post_id > %d
				 ORDER BY pm.post_id ASC
				 LIMIT %d",
				Settings::META_SYNCED,
				$last_id,
				$batch
			) );

			if ( empty( $ids ) ) {
				break;
			}
			$last_id = (int) end( $ids );

			foreach ( $ids as $id ) {
				$id = (int) $id;

				$base_key     = (string) get_post_meta( $id, Settings::META_KEY, true );
				$relative_raw = (string) get_post_meta( $id, '_wp_attached_file', true );
				$objects_raw  = get_post_meta( $id, Settings::META_OBJECTS, true );
				$objects      = Settings::normalize_object_keys( is_array( $objects_raw ) ? $objects_raw : array() );

				if ( '' === $relative_raw ) {
					\WP_CLI::warning( sprintf( '#%d: missing attached-file path — skipping.', $id ) );
					++$skipped;
					continue;
				}

				if ( '' === $base_key ) {
					// Mirror Settings::resolve_object_key(): a synced attachment with
					// no stored key is still served from R2 under the key derived
					// from the current path_prefix + _wp_attached_file — restore it
					// rather than skipping it.
					$base_key = $settings->object_key( $relative_raw );
				}

				if ( empty( $objects ) ) {
					// Legacy attachment (offloaded before the META_OBJECTS ownership
					// manifest existed) — derive the expected keys from current
					// metadata, mirroring Offloader::r2_keys_for(). Only OPTIONAL
					// keys (edit-history backup sizes, which may never have been
					// uploaded) are HEAD-filtered when missing; a REQUIRED key (the
					// original or a size the current metadata references) missing
					// from R2 fails the attachment so its meta is never cleared
					// while a file the site serves can't be restored. The HEAD
					// checks also run in dry-run when credentials allow, keeping
					// its counts honest.
					$derived = $this->derive_legacy_object_keys( $id, $base_key, ltrim( $relative_raw, '/' ) );
					if ( null !== $client ) {
						$missing_required = array();
						foreach ( $derived['required'] as $key ) {
							if ( ! $client->object_exists( $key ) ) {
								$missing_required[] = $key;
							}
						}
						if ( ! empty( $missing_required ) ) {
							\WP_CLI::warning( sprintf(
								'[#%d] legacy registration: %d required object(s) missing from R2 (%s) — R2 meta kept.',
								$id,
								count( $missing_required ),
								implode( ', ', $missing_required )
							) );
							++$errors;
							continue;
						}
						$derived['optional'] = array_values( array_filter( $derived['optional'], array( $client, 'object_exists' ) ) );
					}
					$objects = array_merge( $derived['required'], $derived['optional'] );
					if ( empty( $objects ) ) {
						\WP_CLI::warning( sprintf( '#%d: no objects found in R2 for legacy registration — skipping.', $id ) );
						++$skipped;
						continue;
					}
				}

				// Map each key's basename back to its uploads-relative LOCAL path.
				// Manifest keys can carry DIFFERENT prefixes (path_prefix may have
				// changed between offloads), so stripping one inferred prefix would
				// misplace older keys. And R2 keys always collapse a size's subdir
				// to a sibling basename (see Settings::enumerate_files) while the
				// LOCAL file may live in a subdir carried by the size's 'file'
				// field — so neither the key's path nor its bare basename is the
				// local path. enumerate_files() is the single source of truth for
				// that mapping; backup sizes (absent from live metadata) are bare
				// basenames in the original's directory, covered by the fallback.
				$relative_clean = ltrim( $relative_raw, '/' );
				$relative_dir   = dirname( $relative_clean );
				$relative_dir   = ( '.' === $relative_dir ) ? '' : trailingslashit( $relative_dir );
				$local_map      = array();
				$ambiguous      = array();
				foreach ( Settings::enumerate_files( wp_get_attachment_metadata( $id ), $relative_clean ) as $file ) {
					$name = (string) $file['filename'];
					if ( isset( $local_map[ $name ] ) && $local_map[ $name ] !== $file['relative'] ) {
						// Two different local paths share one basename — and R2 keys
						// collapse to basenames, so only ONE object exists for both.
						// Restoring it to either path leaves the other missing; the
						// upload-side collision already lost one file's content.
						// Skip rather than clear meta over an incomplete restore.
						$ambiguous[ $name ] = true;
						continue;
					}
					$local_map[ $name ] = $file['relative'];
				}
				if ( ! empty( $ambiguous ) ) {
					\WP_CLI::warning( sprintf(
						'#%d: duplicate basenames in attachment metadata (%s) — skipping to avoid an incomplete restore.',
						$id,
						implode( ', ', array_keys( $ambiguous ) )
					) );
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					\WP_CLI::log( sprintf( '  #%d: would restore %d file(s)', $id, count( $objects ) ) );
					$dry_count += count( $objects );
					continue;
				}

				// Download every key in the ownership manifest.
				$att_errors = array();
				foreach ( $objects as $key ) {
					$name       = wp_basename( $key );
					$local_rel  = isset( $local_map[ $name ] ) ? $local_map[ $name ] : $relative_dir . $name;
					$local_path = $uploads_basedir . $local_rel;

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
		\WP_CLI::log( 'Skipped:  ' . $skipped . ' attachment(s)  (missing/invalid registration — see warnings)' );
		\WP_CLI::log( 'Errors:   ' . $errors . ' attachment(s)  (R2 meta kept; images still served from R2)' );
		if ( $errors > 0 ) {
			\WP_CLI::error( sprintf( 'Pull finished with %d error(s) — see warnings above. Re-run to retry.', $errors ) );
		}
		if ( $skipped > 0 ) {
			// Skipped attachments were NOT restored — don't claim deactivation is
			// safe, and exit non-zero so automation can detect the partial result.
			\WP_CLI::error( sprintf( 'Pull finished but %d attachment(s) were skipped — review the warnings above before deactivating.', $skipped ) );
		}
		\WP_CLI::success( 'Pull complete — deactivating the plugin is now safe.' );
	}

	/**
	 * Derive the expected R2 keys for an attachment that has no META_OBJECTS
	 * ownership manifest (offloaded before the manifest existed). Mirrors the
	 * derivation in Offloader::r2_keys_for(): the stored original key, every
	 * file enumerated from live attachment metadata, plus edit-history backup
	 * sizes — all in the stored original's directory.
	 *
	 * Keys are split by obligation: 'required' (the original + every size the
	 * CURRENT metadata references — files the site actively serves) and
	 * 'optional' (edit-history backup sizes, which may never have been
	 * uploaded). A required key missing from R2 must fail the restore; an
	 * optional one may be silently filtered.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $base_key Stored original R2 key (META_KEY).
	 * @param string $relative Uploads-relative attached file path.
	 * @return array{required:string[],optional:string[]}
	 */
	private function derive_legacy_object_keys( $id, $base_key, $relative ) {
		$dir = dirname( $base_key );
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		$required = array( $base_key );
		$metadata = wp_get_attachment_metadata( $id );
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$required[] = $dir . $file['filename'];
		}

		$optional = array();
		$backups  = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $backup ) {
				if ( ! empty( $backup['file'] ) ) {
					$optional[] = $dir . wp_basename( (string) $backup['file'] );
				}
			}
		}
		$required = array_values( array_unique( $required ) );
		// A key can't be both: required wins.
		$optional = array_values( array_diff( array_unique( $optional ), $required ) );

		return array(
			'required' => $required,
			'optional' => $optional,
		);
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

		// A live reset deletes _r2offload_* meta that an active background
		// migration worker writes — refuse to interleave, like sync/pull.
		if ( ! $dry_run ) {
			$this->refuse_if_migration_active( Plugin::instance()->settings() );
		}

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

		// Count/collect over ALL four keys, matching exactly what the live
		// delete touches: a partially-failed offload can leave e.g. META_KEY
		// without META_SYNCED, so counting META_SYNCED alone would under-report.
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		if ( $dry_run ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are literal %s built from a fixed-size array.
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
				$meta_keys
			) );
			\WP_CLI::log( sprintf( 'Would reset: %d attachment(s)', $count ) );
			\WP_CLI::success( 'Dry-run complete — nothing changed.' );
			return;
		}

		// Chunked keyset walk so a multi-million-attachment library never
		// materialises every affected ID in PHP at once. Each chunk: collect the
		// IDs (BEFORE deleting, so their meta caches can be invalidated after),
		// delete that chunk's rows, then invalidate those caches — raw DELETEs
		// bypass delete_post_meta()'s cache updates, and on a persistent object
		// cache (Redis/Memcached) the rewriter would otherwise keep seeing the
		// attachments as offloaded — and keep serving R2 URLs — until the
		// entries expire.
		$chunk_size  = 1000;
		$last_id     = 0;
		$deleted     = 0;
		$attachments = 0;

		do {
			$chunk = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are literal %s built from a fixed-size array.
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key IN ({$placeholders}) AND post_id > %d
				 ORDER BY post_id ASC
				 LIMIT %d",
				array_merge( $meta_keys, array( $last_id, $chunk_size ) )
			) );
			if ( empty( $chunk ) ) {
				break;
			}
			$last_id      = (int) end( $chunk );
			$attachments += count( $chunk );

			$id_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$deleted        += (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are literal %s/%d built from fixed-size arrays.
				"DELETE FROM {$wpdb->postmeta}
				 WHERE meta_key IN ({$placeholders}) AND post_id IN ({$id_placeholders})",
				array_merge( $meta_keys, array_map( 'intval', $chunk ) )
			) );

			foreach ( $chunk as $post_id ) {
				wp_cache_delete( (int) $post_id, 'post_meta' );
			}
			$this->flush_object_cache();
		} while ( count( $chunk ) === $chunk_size );

		\WP_CLI::success( sprintf( 'Reset complete — removed %d postmeta row(s) across %d attachment(s).', $deleted, $attachments ) );
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
