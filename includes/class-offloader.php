<?php
/**
 * Offloader — pushes new uploads (original + every size) to R2.
 *
 * Mode-aware: CDN keeps local copies as a fallback; Stateless removes them.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Offloader {

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/**
	 * R2 keys already uploaded this request, per attachment, so repeated
	 * metadata-filter firings don't re-send the same object. Keyed PER KEY —
	 * not per attachment — because since WP 5.3 wp_create_image_subsizes()
	 * fires wp_update_attachment_metadata once with NO sizes and again after
	 * EACH generated sub-size: a per-attachment flag set on the first firing
	 * (original only) would suppress every later firing and the thumbnails
	 * would never reach R2 while the attachment was already marked synced —
	 * 404s on every size URL.
	 *
	 * @var array<int,array<string,bool>>  attachment_id => set of uploaded keys.
	 */
	private $offloaded = array();

	/**
	 * Local paths queued for Stateless-mode deletion at shutdown, keyed by
	 * "{blog}:{attachment}". Deferred — NOT deleted inline — because during
	 * incremental sub-size generation WordPress still reads the original file
	 * to produce the remaining sizes; deleting it mid-generation would abort
	 * the rest of the thumbnails. The delete decision (attachment synced?) is
	 * made at flush time against the request-final state.
	 *
	 * @var array<string,array{blog:int,attachment:int,paths:string[]}>
	 */
	private $cleanup_queue = array();

	/**
	 * Attachments whose sub-size generation is in progress this request, keyed
	 * by "{blog}:{attachment}" (see flag_generating()). While flagged, the
	 * incremental wp_update_attachment_metadata firings are skipped; the
	 * batched upload happens on the final wp_generate_attachment_metadata
	 * firing, or on shutdown for resume paths that never fire it.
	 *
	 * @var array<string,array{blog:int,attachment:int}>
	 */
	private $generating = array();

	/**
	 * @param R2_Client $client
	 * @param Settings  $settings
	 */
	public function __construct( R2_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Drop the per-request offload dedupe and generation flags. Hooked on
	 * `switch_blog` (see Plugin): both are keyed by attachment ID, which is NOT
	 * unique across a multisite network, so a cached ID must not survive a
	 * switch to another blog and suppress a legitimate upload there. Also
	 * bounds memory in a long-lived CLI process that switches between many
	 * sites. (The deferred-generation queue and cleanup queue carry their blog
	 * id per entry, so they survive switches and flush correctly.)
	 */
	public function flush_request_cache() {
		$this->offloaded = array();
	}

	/**
	 * Hook into the media pipeline.
	 */
	public function register() {
		// Fires after WordPress has generated every registered size (new uploads,
		// thumbnail regeneration).
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload' ), 10, 2 );
		// In-admin image edits (crop/rotate/scale) persist via
		// wp_update_attachment_metadata and never call
		// wp_generate_attachment_metadata — without this hook the edited files are
		// never offloaded (404 in Stateless, stale original in CDN). offload()
		// dedupes per request, so a normal upload (where both filters fire) still
		// uploads each variant only once.
		add_filter( 'wp_update_attachment_metadata', array( $this, 'offload' ), 10, 2 );
		// Defer-flag a NEW attachment the moment its post is created — BEFORE
		// wp_create_image_subsizes()'s first metadata save (which carries an
		// empty sizes array and fires ahead of intermediate_image_sizes_advanced).
		// Flagging only at the intermediate filter would let that first firing
		// upload the original and mark the attachment synced while every size
		// save is deferred: a premature-synced window where renders emit R2
		// size URLs that 404 (and get edge-cached). With the flag set from
		// creation, nothing uploads until the final batched pass and the synced
		// flag only ever appears alongside complete metadata.
		add_action( 'add_attachment', array( $this, 'flag_new_attachment' ) );
		// Also fires at the START of wp_create_image_subsizes() — covers the
		// REST post-process RESUME path, where the attachment already exists
		// (no add_attachment) but generation restarts. Uploads must be DEFERRED
		// while generation runs: without this, every per-size
		// wp_update_attachment_metadata firing would PUT to R2 inline between
		// GD resizes, stretching the upload request on slow hosts. Mirrors how
		// wp-stateless ships everything once at the end.
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'flag_generating' ), 10, 3 );
		// Mirror deletions to R2.
		add_action( 'delete_attachment', array( $this, 'delete' ) );
	}

	/**
	 * Defer-flag a newly created attachment post (add_attachment) so every
	 * metadata firing of its creation flow — including the first, sizes-less
	 * save inside wp_create_image_subsizes() — is deferred to the final
	 * batched pass.
	 *
	 * @param int $post_id
	 */
	public function flag_new_attachment( $post_id ) {
		$this->mark_generating( (int) $post_id );
	}

	/**
	 * Mark an attachment as mid-generation so offload() defers its uploads
	 * (intermediate_image_sizes_advanced — the REST post-process resume path,
	 * where the attachment already exists so add_attachment never fired).
	 *
	 * @param array $new_sizes     Sizes to generate (passed through unchanged).
	 * @param array $image_meta    Attachment metadata (unused).
	 * @param int   $attachment_id
	 * @return array
	 */
	public function flag_generating( $new_sizes, $image_meta, $attachment_id ) {
		$this->mark_generating( (int) $attachment_id );
		return $new_sizes;
	}

	/**
	 * Record the defer flag and arm the shutdown backstop: paths that never
	 * fire the wp_generate_attachment_metadata filter (REST post-process
	 * resume, programmatic wp_insert_attachment flows) would otherwise end the
	 * request with nothing uploaded.
	 *
	 * @param int $attachment_id
	 */
	private function mark_generating( $attachment_id ) {
		$k                      = get_current_blog_id() . ':' . $attachment_id;
		$this->generating[ $k ] = array(
			'blog'       => get_current_blog_id(),
			'attachment' => $attachment_id,
		);
		// Priority 5: must run BEFORE flush_local_cleanup (default 10) so the
		// stateless cleanup sees the synced state these uploads produce.
		add_action( 'shutdown', array( $this, 'flush_deferred' ), 5 );
	}

	/**
	 * Shutdown backstop: offload any attachment still flagged as generating
	 * (its wp_generate_attachment_metadata filter never fired — the REST
	 * post-process resume path). Reads the FINAL metadata from the DB.
	 * Public because it's a hook callback.
	 */
	public function flush_deferred() {
		$deferred         = $this->generating;
		$this->generating = array();
		foreach ( $deferred as $entry ) {
			$switched = false;
			if ( is_multisite() && get_current_blog_id() !== $entry['blog'] ) {
				switch_to_blog( $entry['blog'] );
				$switched = true;
			}
			// wp_get_attachment_metadata() can return false (a filter, or a
			// metadata-less programmatic wp_insert_attachment). Fall back to
			// the raw stored value, then normalise to an empty array so
			// offload_now() still handles the original via _wp_attached_file
			// instead of the entry being silently skipped.
			$metadata = wp_get_attachment_metadata( $entry['attachment'] );
			if ( ! is_array( $metadata ) ) {
				$metadata = get_post_meta( $entry['attachment'], '_wp_attachment_metadata', true );
			}
			if ( ! is_array( $metadata ) ) {
				$metadata = array();
			}
			// allow_cleanup=false: shutdown metadata can be PARTIAL — the
			// request may have died mid-generation, before the generate filter
			// fired. Marking synced from it is safe (the rewriter only emits
			// URLs for sizes the metadata lists, and offload_now() confirms
			// each of those in R2 first), but deleting the ORIGINAL is not:
			// the REST resume regenerates missing sizes from it. The backstop
			// therefore cleans confirmed-uploaded size files only and retains
			// the original until a complete inline pass.
			$this->offload_now( $metadata, $entry['attachment'], false );
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Metadata-filter callback: route to an immediate offload or defer while
	 * sub-size generation is in progress.
	 *
	 * Since WP 5.3 wp_create_image_subsizes() fires
	 * wp_update_attachment_metadata once with NO sizes and again after EACH
	 * generated sub-size. Uploading inline on those firings interleaves
	 * network PUTs between GD resizes inside the upload request; instead,
	 * firings for a flagged attachment are skipped and the single batched
	 * upload happens on the final wp_generate_attachment_metadata firing
	 * (complete metadata, all GD work done — the wp-stateless model), with a
	 * shutdown backstop for resume paths that never fire it.
	 *
	 * @param array $metadata      Attachment metadata (passes through unchanged).
	 * @param int   $attachment_id
	 * @return array
	 */
	public function offload( $metadata, $attachment_id ) {
		$k = get_current_blog_id() . ':' . (int) $attachment_id;
		if ( 'wp_generate_attachment_metadata' === current_filter() ) {
			// Generation (if any) is complete — this firing carries the full
			// metadata. Clear the flag and upload now.
			unset( $this->generating[ $k ] );
		} elseif ( isset( $this->generating[ $k ] ) ) {
			// Incremental mid-generation firing — defer (final firing or the
			// shutdown backstop will upload everything in one pass).
			return $metadata;
		}
		return $this->offload_now( $metadata, $attachment_id );
	}

	/**
	 * Offload an attachment's original + all sizes to R2.
	 *
	 * @param array $metadata      Attachment metadata (passes through unchanged).
	 * @param int   $attachment_id
	 * @param bool  $allow_cleanup Whether this pass may queue the ORIGINAL's
	 *                             local copy for Stateless cleanup. False for
	 *                             the shutdown backstop, whose metadata
	 *                             snapshot cannot prove generation finished —
	 *                             it cleans uploaded size files only.
	 * @return array
	 */
	private function offload_now( $metadata, $attachment_id, $allow_cleanup = true ) {
		if ( ! $this->settings->is_configured() ) {
			return $metadata;
		}
		// Lets operators freeze offload-on-upload during a migration window when
		// another plugin (e.g. wp-stateless) still owns ingestion — return false
		// to stop new uploads being pushed to R2 until this plugin takes over.
		if ( ! apply_filters( 'r2offload_offload_on_upload', true, $attachment_id, $metadata ) ) {
			return $metadata;
		}

		$files = $this->collect_files( $metadata, $attachment_id );
		if ( empty( $files ) ) {
			return $metadata;
		}

		$original_relative = isset( $metadata['file'] )
			? $metadata['file']
			: (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original_key = $this->base_object_key( $attachment_id, $original_relative );

		$already_synced = (bool) get_post_meta( $attachment_id, Settings::META_SYNCED, true );
		$is_stateless   = 'stateless' === $this->settings->get( 'mode' );

		// Per-KEY dedupe across this request's metadata-filter firings: a normal
		// upload still reaches here twice with full metadata (the final
		// wp_generate_attachment_metadata pass, then media_handle_upload's
		// closing wp_update_attachment_metadata), and the shutdown backstop may
		// overlap an earlier partial pass. Each call uploads only the keys it
		// hasn't sent yet. A failed key is NOT recorded, so a later pass
		// retries it.
		$done    = isset( $this->offloaded[ $attachment_id ] ) ? $this->offloaded[ $attachment_id ] : array();
		$pending = array();
		foreach ( $files as $local_path => $key ) {
			if ( ! isset( $done[ $key ] ) ) {
				$pending[ $local_path ] = $key;
			}
		}

		if ( ! empty( $pending ) ) {
			$cache_control = $this->settings->get( 'cache_control' );
			$headers       = ( '' !== $cache_control ) ? array( 'Cache-Control' => $cache_control ) : array();

			$upload = $this->upload_variants( $pending, $original_key, $headers );

			// Record every key that actually reached R2 into the attachment's
			// ownership manifest AND the per-request dedupe — BEFORE the
			// partial-failure bail below. Even when a sibling variant fails, the
			// objects that DID upload exist and this attachment owns them, so a
			// later delete must still reap them (SWR-333), and a later firing
			// must not re-send them. record_objects() is a no-op on an empty list.
			$uploaded_keys = array();
			foreach ( $upload['uploaded_paths'] as $uploaded_path ) {
				if ( isset( $pending[ $uploaded_path ] ) ) {
					$uploaded_keys[]                    = $pending[ $uploaded_path ];
					$done[ $pending[ $uploaded_path ] ] = true;
				}
			}
			Settings::record_objects( $attachment_id, $uploaded_keys );
			$this->offloaded[ $attachment_id ] = $done;

			// Stateless mode: collect this pass's confirmed uploads for deletion
			// at SHUTDOWN — also BEFORE the failure bail, so locals uploaded on a
			// pass that subsequently fails don't leak on disk (their keys are in
			// $done, so no later firing re-queues them). Deletion is deferred and
			// re-gated at shutdown (see flush_local_cleanup): WordPress may still
			// need the original on disk to generate the remaining sub-sizes, and
			// whether deleting is safe depends on the attachment's FINAL synced
			// state for the request, not this firing's snapshot.
			//
			// Require a public serving URL: without a custom domain the URL
			// rewriter stays off and WordPress emits local /uploads URLs, so
			// deleting the local files would 404 the media. Keep the local copies
			// (CDN-like) until a custom domain is configured.
			if ( $is_stateless && $this->settings->serves_public_url() ) {
				$cleanup_paths = $upload['uploaded_paths'];
				if ( ! $allow_cleanup ) {
					// Shutdown-backstop pass: the metadata snapshot cannot prove
					// generation finished, and a future REST post-process resume
					// regenerates missing sub-sizes FROM THE ORIGINAL — so the
					// original must stay on disk until a complete inline pass
					// confirms generation is done. Confirmed-uploaded SIZE files
					// are safe to clean even here: a resume never reads existing
					// size files (sizes already in metadata are skipped, missing
					// ones are recreated from the original).
					$cleanup_paths = array();
					foreach ( $upload['uploaded_paths'] as $uploaded_path ) {
						if ( isset( $pending[ $uploaded_path ] ) && $pending[ $uploaded_path ] !== $original_key ) {
							$cleanup_paths[] = $uploaded_path;
						}
					}
				}
				$this->queue_local_cleanup( $attachment_id, $cleanup_paths );
			}

			if ( $upload['failed'] ) {
				// A variant failed to reach R2 (its key stays un-recorded, so a
				// later firing retries it). If this attachment was already synced
				// (e.g. a re-offload after a new image size was added), the URL
				// rewriter would keep serving every size from R2 and the missing
				// one 404s. In CDN mode the local copies are intact, so drop the
				// synced flag to serve everything locally until a later offload
				// restores full R2 coverage. In Stateless mode the other variants
				// live only in R2, so un-syncing would 404 them instead — keep
				// serving from R2 and let the next pass retry. Either way, leave
				// local copies in place.
				if ( $already_synced && ! $is_stateless ) {
					delete_post_meta( $attachment_id, Settings::META_SYNCED );
				}
				return $metadata;
			}
		}

		// Fully present = the original AND every file the CURRENT metadata
		// references is confirmed in R2 this request. Evaluated on every firing
		// (including no-op ones) because the verdict changes as metadata grows
		// during incremental sub-size generation; a variant skipped as
		// unreadable is simply absent from $done, so it fails this check and
		// the attachment is not flagged.
		$fully_present = isset( $done[ $original_key ] );
		foreach ( $files as $key ) {
			if ( ! isset( $done[ $key ] ) ) {
				$fully_present = false;
				break;
			}
		}

		// Only mark the attachment offloaded once the ORIGINAL and every size
		// are in R2 — a stray size upload (or a skipped, missing variant) must
		// not flag media that isn't fully present. Idempotent on re-firings.
		if ( $fully_present ) {
			$this->mark_synced( $attachment_id, $original_key );
		}

		return $metadata;
	}

	/**
	 * Queue confirmed-in-R2 local copies for deletion when the request ends.
	 * Collection happens at upload time (every pass, even ones that later
	 * fail); the delete decision is made at shutdown against the attachment's
	 * FINAL synced state. Entries carry the blog id so a multisite request
	 * that switches blogs deletes under the right site at flush time.
	 * add_action() dedupes an identical callback, so repeated queuing
	 * registers the shutdown handler once.
	 *
	 * @param int      $attachment_id
	 * @param string[] $paths
	 */
	private function queue_local_cleanup( $attachment_id, array $paths ) {
		if ( empty( $paths ) ) {
			return;
		}
		$blog = get_current_blog_id();
		$k    = $blog . ':' . (int) $attachment_id;
		if ( ! isset( $this->cleanup_queue[ $k ] ) ) {
			$this->cleanup_queue[ $k ] = array(
				'blog'       => $blog,
				'attachment' => (int) $attachment_id,
				'paths'      => array(),
			);
		}
		$this->cleanup_queue[ $k ]['paths'] = array_merge( $this->cleanup_queue[ $k ]['paths'], $paths );
		add_action( 'shutdown', array( $this, 'flush_local_cleanup' ) );
	}

	/**
	 * Shutdown handler: delete the queued local copies (Stateless cleanup of
	 * confirmed uploads). Deletes ONLY when the attachment ends the request
	 * synced — the request-final verdict, covering both "fully present now"
	 * and "already synced before this re-offload". Media that never reached
	 * the synced state keeps its local copies: it's still served from disk.
	 * Public because it's a hook callback.
	 */
	public function flush_local_cleanup() {
		$queue               = $this->cleanup_queue;
		$this->cleanup_queue = array();
		foreach ( $queue as $entry ) {
			$switched = false;
			if ( is_multisite() && get_current_blog_id() !== $entry['blog'] ) {
				switch_to_blog( $entry['blog'] );
				$switched = true;
			}
			if ( get_post_meta( $entry['attachment'], Settings::META_SYNCED, true ) ) {
				$this->cleanup_locals( array_unique( $entry['paths'] ) );
			}
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Upload each local variant to R2.
	 *
	 * @param array<string,string> $files        local-path => R2-key.
	 * @param string               $original_key The original's R2 key.
	 * @param array                $headers      Per-object headers.
	 * @return array{failed:bool,uploaded_paths:string[],original_uploaded:bool,all_present:bool}
	 *         On the first WP_Error, returns failed=true; the caller never
	 *         strands media. all_present is false when any variant was missing
	 *         locally (skipped) so the attachment isn't marked fully offloaded.
	 */
	private function upload_variants( $files, $original_key, $headers ) {
		$uploaded_paths    = array();
		$original_uploaded = false;
		$all_present       = true;

		foreach ( $files as $local_path => $key ) {
			if ( ! is_readable( $local_path ) ) {
				// A size file is missing locally (e.g. another stateless plugin
				// already removed it). Don't claim the attachment is fully
				// offloaded — the URL rewriter would 404 on that size.
				$all_present = false;
				continue;
			}
			$result = $this->client->upload_file( $local_path, $key, '', $headers );
			if ( is_wp_error( $result ) ) {
				return array(
					'failed'            => true,
					'uploaded_paths'    => $uploaded_paths,
					'original_uploaded' => $original_uploaded,
					'all_present'       => false,
				);
			}
			$uploaded_paths[] = $local_path;
			if ( $key === $original_key ) {
				$original_uploaded = true;
			}
		}

		return array(
			'failed'            => false,
			'uploaded_paths'    => $uploaded_paths,
			'original_uploaded' => $original_uploaded,
			'all_present'       => $all_present,
		);
	}

	/**
	 * Record the synced flag, timestamp, and the original's actual R2 key.
	 *
	 * @param int    $attachment_id
	 * @param string $original_key
	 */
	private function mark_synced( $attachment_id, $original_key ) {
		update_post_meta( $attachment_id, Settings::META_SYNCED, 1 );
		update_post_meta( $attachment_id, Settings::META_SYNCED_AT, time() );
		// Store the original's actual R2 key so readers resolve it independently
		// of the current path_prefix setting.
		update_post_meta( $attachment_id, Settings::META_KEY, $original_key );
	}

	/**
	 * Delete the given local copies (Stateless cleanup of confirmed uploads).
	 *
	 * @param string[] $uploaded_paths
	 */
	private function cleanup_locals( $uploaded_paths ) {
		foreach ( $uploaded_paths as $local_path ) {
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}
		}
	}

	/**
	 * Remove an attachment's original + all sizes from R2.
	 *
	 * @param int $attachment_id
	 */
	public function delete( $attachment_id ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		// Lets operators stop mirroring WP deletions to R2 during a migration
		// window (return false) so R2 and the still-live source can't diverge
		// before cutover is final.
		if ( ! apply_filters( 'r2offload_mirror_deletes', true, $attachment_id ) ) {
			return;
		}
		foreach ( $this->r2_keys_for( $attachment_id ) as $key ) {
			$deleted = $this->client->delete_object( $key );
			if ( is_wp_error( $deleted ) ) {
				// Best-effort: a failed delete (network blip, transient 5xx)
				// leaves an orphaned object in R2. Log it so it can be reaped
				// later rather than failing silently.
				error_log( sprintf( 'r2offload: delete failed for %s (attachment %d): %s', $key, (int) $attachment_id, $deleted->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * All R2 keys for an attachment (original + every size), resolved from the
	 * stored `_r2offload_key` so deletes still hit the right objects even if the
	 * path_prefix setting changed since upload. Falls back to the current
	 * path_prefix when no stored key exists.
	 *
	 * Returns nothing for an attachment this plugin never offloaded: deleting
	 * such an attachment must not issue R2 DELETEs for computed keys, which
	 * could remove objects the plugin hasn't claimed — e.g. media bulk-copied
	 * into R2 (Super Slurper) but not yet adopted via `wp r2offload sync`.
	 *
	 * The "never claimed" test is `! META_SYNCED && '' === META_KEY` — NOT
	 * META_SYNCED alone: a re-offload that partially fails clears META_SYNCED
	 * (CDN fallback) but leaves META_KEY and the already-uploaded objects in R2.
	 * Gating delete on META_SYNCED alone would then orphan those objects.
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	private function r2_keys_for( $attachment_id ) {
		// Prefer the explicit ownership manifest when present: it lists exactly the
		// keys THIS attachment uploaded/adopted (across edit history and path_prefix
		// changes), so we never delete an object another attachment owns and never
		// miss one (SWR-333). Attachments offloaded before the manifest existed have
		// none — fall through to deriving keys from current metadata.
		$manifest = get_post_meta( $attachment_id, Settings::META_OBJECTS, true );
		if ( is_array( $manifest ) && ! empty( $manifest ) ) {
			return Settings::normalize_object_keys( $manifest );
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original = (string) get_post_meta( $attachment_id, Settings::META_KEY, true );

		// Never claimed by this plugin (no synced flag AND no stored key) — leave
		// any externally-present objects untouched.
		if ( ! get_post_meta( $attachment_id, Settings::META_SYNCED, true ) && '' === $original ) {
			return array();
		}

		if ( '' === $original ) {
			if ( '' === $relative ) {
				return array();
			}
			$original = $this->settings->object_key( $relative );
		}

		// Keys live in the stored original's directory (path_prefix may have
		// changed since sync), but the filename set comes from the shared
		// Settings::enumerate_files() helper so the delete path can't drift
		// from the upload/migrate paths. Seed with $original so it's always
		// included even for the degenerate empty-_wp_attached_file case.
		$dir = dirname( $original );
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		$keys     = array( $original );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$keys[] = $dir . $file['filename'];
		}

		// Image edits keep the pre-edit copies in _wp_attachment_backup_sizes (so
		// "Restore original" works); these are NOT in the live metadata above.
		// WordPress deletes them locally on attachment delete, so reap their R2
		// objects too — otherwise they orphan as billable storage. Each entry's
		// 'file' is a bare basename living in the same directory.
		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $backup ) {
				if ( ! empty( $backup['file'] ) ) {
					$keys[] = $dir . wp_basename( (string) $backup['file'] );
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Build a map of local-path => R2-key for the original and every size.
	 *
	 * The R2 key is the file's path relative to the uploads dir
	 * (i.e. the `_wp_attached_file` value), so it maps 1:1 to WordPress's
	 * canonical location and the URL rewriter can reconstruct it.
	 *
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array<string,string>  local_path => r2_key
	 */
	private function collect_files( $metadata, $attachment_id ) {
		$relative = isset( $metadata['file'] )
			? $metadata['file']
			: get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! $relative ) {
			return array();
		}

		$uploads = wp_get_upload_dir();
		// Bail if the uploads dir is unavailable, mirroring Migrator::local_path_for()
		// — without a valid basedir the local paths (and thus the upload) are wrong.
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array();
		}
		$basedir = trailingslashit( $uploads['basedir'] );

		// Anchor keys on the attachment's base key: its stored _r2offload_key
		// when already synced, else the current path_prefix. This keeps a
		// re-offload (e.g. new size on regeneration) uploading into the SAME
		// directory the URL rewriter serves from, even if path_prefix changed
		// since the first sync — otherwise the new size would 404. The original
		// keeps its exact key; sizes/original_image are siblings in its dir.
		$base_key = $this->base_object_key( $attachment_id, $relative );
		$dir      = dirname( $base_key );
		$dir      = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		$files = array();
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$key = ( '' === $file['size'] ) ? $base_key : $dir . $file['filename'];
			$files[ $basedir . $file['relative'] ] = $key;
		}

		return $files;
	}

	/**
	 * The attachment's canonical R2 key for the original: its stored
	 * `_r2offload_key` when already synced (so it survives a later path_prefix
	 * change), else derived from the current path_prefix. Shared by offload()
	 * and collect_files() so the marked key, the uploaded keys and the URL
	 * rewriter all agree.
	 *
	 * Invariant this relies on: META_KEY is ONLY ever written together with
	 * META_SYNCED (here and in Migrator) and is never cleared on its own — so a
	 * non-empty META_KEY means the attachment was synced at that key, and
	 * anchoring on it (without re-checking META_SYNCED) is safe.
	 *
	 * @param int    $attachment_id
	 * @param string $relative
	 * @return string
	 */
	private function base_object_key( $attachment_id, $relative ) {
		$stored = (string) get_post_meta( $attachment_id, Settings::META_KEY, true );
		return ( '' !== $stored ) ? $stored : $this->settings->object_key( (string) $relative );
	}
}
