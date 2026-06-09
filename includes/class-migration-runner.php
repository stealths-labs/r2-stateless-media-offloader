<?php
/**
 * Migration runner — background, resumable media migration (SWR-331).
 *
 * Drives Migrator::migrate_batch() in the background via WP-Cron so the job
 * survives the admin closing the tab. Progress lives in a single option; the
 * admin UI polls it and may also advance a batch for responsiveness. Same
 * batch engine as the WP-CLI command, so results match exactly.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Migration_Runner {

	const STATE_OPTION = 'r2offload_migration';
	const CRON_HOOK    = 'r2offload_migrate_tick';
	const BATCH        = 100;
	const LOCK_OPTION  = 'r2offload_migration_lock';
	// Abort a run after this many consecutive whole-batch failures, so a
	// persistently-throwing batch (e.g. corrupt metadata) can't spin the cron
	// forever. Reset to zero on any batch that completes.
	const MAX_FAIL_STREAK = 5;
	// How many times the post-batch persist re-reads and retries its CAS when a
	// concurrent stop()/start() write keeps winning the race.
	const PERSIST_RETRIES = 5;
	// How many full passes a run makes. The cursor advances past attachments
	// that errored (so a bad item can't stall forward progress), so when a pass
	// finishes with errors we re-scan from the start to retry them — already-
	// done items skip fast. Bounded so permanent failures can't loop forever.
	const MAX_PASSES = 3;
	// Wall-clock budget for one batch. Kept well under LOCK_TTL so a batch can
	// never run long enough for the lock to expire and admit a second worker on
	// the same cursor — even after the single slow item that crosses the budget.
	const BATCH_MAX_SECONDS = 45;
	// Shorter budget for the admin-ajax-driven batch: a status poll runs inside
	// admin-ajax.php and must return well within typical web execution limits,
	// so it advances only a small slice and lets WP-Cron do the bulk.
	const AJAX_BATCH_MAX_SECONDS = 10;
	// Long enough that a healthy batch (up to BATCH items, each able to block
	// on a remote download) is never mistaken for a crashed worker; the
	// compare-and-swap in acquire_lock() is the actual correctness guarantee,
	// this only bounds how long a genuinely dead lock blocks progress.
	const LOCK_TTL     = 1800;

	// How many recent per-item error messages to keep in the state for the admin
	// UI. A small ring buffer — enough to be useful, bounded so the option stays small.
	const MAX_RECENT_ERRORS = 20;

	/** @var Settings */
	private $settings;

	/**
	 * The exact lock value this instance holds, so it only ever releases or
	 * reclaims its own lock. Empty when no lock is held.
	 *
	 * @var string
	 */
	private $lock_value = '';

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the cron callback that advances the migration in the background.
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'tick' ) );
	}

	/**
	 * Plugin-deactivation cleanup: unschedule any pending tick (its callback is
	 * gone once deactivated) and mark a running migration stopped so it doesn't
	 * resume mid-batch on reactivation. Static so it can be a deactivation hook.
	 */
	public static function on_deactivate( $network_deactivating = false ) {
		// A NETWORK deactivation removes the plugin from every site at once, and
		// cron/state are per-site, so clean every site (otherwise sibling sites
		// keep an orphaned, now-inert tick and a stale running=true state). But a
		// PER-SITE deactivation (or a single-site install) must touch ONLY the
		// current site — looping all sites there would wipe cron and migration
		// state for sibling subsites that still have the plugin active.
		// register_deactivation_hook passes $network_deactivating to tell them
		// apart.
		if ( is_multisite() && $network_deactivating ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::cleanup_site();
				restore_current_blog();
			}
			return;
		}
		self::cleanup_site();
	}

	/**
	 * Per-site deactivation cleanup: drop the scheduled tick, release the batch
	 * lock, and stop a run.
	 */
	private static function cleanup_site() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// Drop the batch lock too — otherwise a worker that was mid-batch at
		// deactivation leaves it set until its TTL, blocking the first batch
		// after reactivation.
		delete_option( self::LOCK_OPTION );
		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['running'] ) ) {
			$state['running'] = false;
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Default (idle) state shape.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'running'     => false,
			'mode'        => 'upload', // upload | dry-run | verify
			'run_id'      => '',       // Identifies the active run (see run_one_batch).
			'fail_streak' => 0,        // Consecutive whole-batch failures (circuit breaker).
			'pass'        => 1,        // Current pass number (failed items retried up to MAX_PASSES).
			'pass_errors' => 0,        // Per-item errors recorded in the current pass.
			'cursor'      => '',
			'processed'   => 0,
			'uploaded'    => 0,
			'updated'     => 0,
			'adopted'     => 0,
			'skipped'     => 0,
			'errored'     => 0,
			'errors'      => 0,
			'bytes'       => 0,
			'total'       => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'cancelled'     => false,    // Terminally stopped (not resumable) vs paused.
			'last_error'    => '',
			'recent_errors' => array(), // Last few per-item error messages for the UI.
			'log_entries'   => array(), // Ring buffer of recent per-item activity lines.
			'last_batch_at' => 0,       // Unix timestamp of the most recent completed batch.
		);
	}

	/**
	 * Current migration state (merged onto defaults).
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return array_merge( self::default_state(), is_array( $state ) ? $state : array() );
	}

	/**
	 * Like state(), but bypasses the per-request options cache so writes made
	 * by start()/stop() in another process are visible. Used by the batch
	 * worker, where a stale "running" read would defeat the control-plane
	 * guards.
	 *
	 * @return array
	 */
	private function fresh_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );
		return $this->state();
	}

	/**
	 * Start (or restart) a migration in the given mode.
	 *
	 * @param string $mode upload | dry-run | verify
	 * @return array New state.
	 */
	public function start( $mode = 'upload' ) {
		$mode  = in_array( $mode, array( 'upload', 'force', 'dry-run', 'verify' ), true ) ? $mode : 'upload';
		$state = self::default_state();
		$state['running']    = true;
		$state['mode']       = $mode;
		// New run token: any batch worker still finishing a previous run will
		// see the token change and discard its write instead of clobbering
		// this fresh start.
		$state['run_id']     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		$state['total']      = $this->count_attachments();
		$state['started_at'] = time();
		update_option( self::STATE_OPTION, $state, false );

		// NB: we deliberately do NOT force-delete any existing lock here. A lock
		// can't be told apart from a still-alive-but-slow worker's lock at a single
		// point in time (its TTL is the only liveness signal), so clearing it would
		// admit a second worker on the same library whenever a prior run is merely
		// slow — the per-item uploads/meta writes overlap before run_id can discard
		// the stale worker's state. A lock orphaned by a crashed worker instead
		// self-heals through acquire_lock()'s expired-lock CAS reclaim within
		// LOCK_TTL. Correctness (one worker) is worth more than instant restart.
		$this->schedule_next();
		return $state;
	}

	/**
	 * Resume a stopped-but-unfinished migration from where it left off —
	 * keeping the cursor and counters that stop() preserved. No-op (returns
	 * state unchanged) if a run is already active or there's nothing to resume.
	 *
	 * @return array New state.
	 */
	public function resume() {
		$state = $this->fresh_state();
		if ( ! empty( $state['running'] ) || ! $this->is_resumable( $state ) ) {
			return $state;
		}
		$state['running']     = true;
		$state['finished_at'] = 0;
		// KEEP the existing run_id: a worker from before the stop may still be
		// finishing its batch. Same token means it persists its completed
		// cursor/counters normally instead of discarding them as stale — so a
		// quick Stop→Resume can't lose (and reprocess) that batch. (start()
		// mints a new token precisely because it DOES want to invalidate them.)
		$state['fail_streak'] = 0;
		$state['total']       = $this->count_attachments();
		update_option( self::STATE_OPTION, $state, false );

		$this->schedule_next();
		return $state;
	}

	/**
	 * Whether a (non-running) state represents a migration that was stopped
	 * before completing and so can be resumed.
	 *
	 * @param array $state
	 * @return bool
	 */
	public function is_resumable( array $state ) {
		return empty( $state['running'] )
			&& empty( $state['finished_at'] )
			&& empty( $state['cancelled'] ) // A terminal Stop is not resumable; only a Pause is.
			&& ( (int) $state['started_at'] > 0 || '' !== (string) $state['cursor'] );
	}

	/**
	 * Append messages to the state's recent-errors ring buffer, keeping only the
	 * most recent MAX_RECENT_ERRORS. Pure helper — returns the new array.
	 *
	 * @param array    $state
	 * @param string[] $messages
	 * @return string[]
	 */
	private function append_recent_errors( array $state, array $messages ) {
		$existing = isset( $state['recent_errors'] ) && is_array( $state['recent_errors'] ) ? $state['recent_errors'] : array();
		$merged   = array_merge( $existing, array_values( $messages ) );
		return array_slice( $merged, -self::MAX_RECENT_ERRORS );
	}

	/**
	 * Retry a single attachment that previously errored. Runs migrate_attachment()
	 * directly (outside the batch loop) in the run's stored mode, updates the
	 * state's error counters and recent_errors ring buffer via CAS, then returns
	 * the new state. No-op (returns current state) when the attachment is not in
	 * the error ring buffer.
	 *
	 * Must only be called when the migration is not actively running AND no batch
	 * worker still holds the lock (has_active_worker()) — the caller is
	 * responsible for both guards.
	 *
	 * @param int $attachment_id
	 * @return array|\WP_Error Updated state (same shape as state()), or WP_Error
	 *                         when the batch lock could not be acquired.
	 */
	public function retry_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$prefix        = '[#' . $attachment_id . '] ';

		// Only retry an attachment that is actually in the error ring buffer —
		// that buffer is what the UI's Retry button was rendered from. Without
		// this precondition a stray/replayed request for a never-errored ID
		// would decrement `errored` and inflate an outcome counter, breaking
		// the processed === sum(outcomes) invariant.
		$state  = $this->fresh_state();
		$listed = false;
		foreach ( (array) $state['recent_errors'] as $msg ) {
			if ( 0 === strpos( (string) $msg, $prefix ) ) {
				$listed = true;
				break;
			}
		}
		if ( $attachment_id <= 0 || ! $listed ) {
			return $state;
		}

		// Hold the BATCH LOCK for the whole retry. The caller's running /
		// has_active_worker() guards are only a snapshot: the user can click
		// Retry and then Resume while this retry is still in flight, and the
		// resumed run_one_batch() could otherwise process the same attachment
		// in parallel with this migrator. Under the lock, that worker loses
		// acquire_lock(), reschedules its tick, and proceeds once we release.
		if ( ! $this->acquire_lock() ) {
			// A worker became active between the caller's guard and here —
			// don't run a second migrator alongside it. WP_Error (not a state
			// array) so the AJAX handler reports a failure instead of letting
			// the UI render this as a completed retry.
			return new \WP_Error(
				'r2offload_retry_locked',
				__( 'A migration batch is still finishing — try again in a moment.', 'r2-stateless-media-offload' )
			);
		}
		try {
			// Honour the run's mode like run_one_batch() does: a retry from a
			// dry-run or verify run must stay read-only, and a force run must keep
			// replacing existing objects. Heartbeat wired like the batch worker so
			// a slow multi-size retry keeps refreshing the lock instead of letting
			// it expire and admit a second worker mid-retry.
			$mode     = isset( $state['mode'] ) ? (string) $state['mode'] : 'upload';
			$migrator = new Migrator();
			$migrator->set_dry_run( 'dry-run' === $mode )
				->set_verify( 'verify' === $mode )
				->set_force( 'force' === $mode )
				->set_download_timeout( 300 )
				->set_heartbeat( array( $this, 'refresh_lock' ) );
			$res = $migrator->migrate_attachment( $attachment_id );

			return $this->fold_retry_result( $prefix, $res );
		} finally {
			// try/finally so the lock is ALWAYS released — a fatal in
			// migrate_attachment() must never strand it.
			$this->release_lock();
		}
	}

	/**
	 * Fold a single-attachment retry result into the stored state via CAS.
	 * Split out of retry_attachment() so the lock-holding section stays small.
	 *
	 * @param string $prefix The '[#ID] ' ring-buffer prefix for this attachment.
	 * @param array  $res    Result array from Migrator::migrate_attachment().
	 * @return array Updated (persisted) state.
	 */
	private function fold_retry_result( $prefix, array $res ) {
		// Fold the outcome into the FRESHEST state via CAS, like the batch
		// worker / stop() / cancel(): a worker finishing its in-flight batch
		// (or a concurrent retry) may persist between our read and our write,
		// and a blind update_option would clobber its cursor/counters.
		for ( $attempt = 0; $attempt < self::PERSIST_RETRIES; $attempt++ ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$expected = get_option( self::STATE_OPTION, array() );
			$state    = array_merge( self::default_state(), is_array( $expected ) ? $expected : array() );

			// Remove prior error messages for this attachment from the ring buffer.
			$removed = 0;
			$kept    = array();
			foreach ( (array) $state['recent_errors'] as $msg ) {
				if ( 0 === strpos( (string) $msg, $prefix ) ) {
					++$removed;
				} else {
					$kept[] = $msg;
				}
			}
			if ( 0 === $removed ) {
				// A concurrent writer already cleared this attachment's errors
				// (double-click, second tab) — don't adjust the counters twice.
				return $state;
			}
			// Mirror the per-message decrement from the original batch run.
			// pass_errors too: the batch path increments it per error message and
			// the pass-transition logic uses it to decide whether a retry re-scan
			// is needed — leaving it positive after manual retries would trigger
			// an extra pass (and counter reset) for errors already fixed here.
			// recent_errors is cleared on every pass transition, so $removed only
			// ever covers current-pass messages.
			$state['errors']      = max( 0, (int) $state['errors'] - $removed );
			$state['pass_errors'] = max( 0, (int) $state['pass_errors'] - $removed );

			if ( ! empty( $res['errors'] ) ) {
				// Still failing — replace with the fresh error messages.
				$new_msgs = array_map(
					function ( $err ) use ( $prefix ) {
						return $prefix . $err;
					},
					$res['errors']
				);
				$state['recent_errors'] = $this->append_recent_errors(
					array( 'recent_errors' => $kept ),
					$new_msgs
				);
				$state['errors']      = (int) $state['errors'] + count( $new_msgs );
				$state['pass_errors'] = (int) $state['pass_errors'] + count( $new_msgs );
				// errored count unchanged — still one errored attachment.
			} else {
				// Retry succeeded — transition this attachment out of the error
				// bucket. Outcome priority matches migrate_batch(): missing-source
				// before the positive outcomes, so an attachment with some
				// variants uploaded and some missing isn't counted as "uploaded"
				// (META_SYNCED was not written in that case).
				$state['recent_errors'] = $kept;
				$state['errored']       = max( 0, (int) $state['errored'] - 1 );
				if ( isset( $res['missing'] ) && (int) $res['missing'] > 0 ) {
					$state['skipped'] = (int) $state['skipped'] + 1;
				} elseif ( (int) $res['uploaded'] > 0 ) {
					$state['uploaded'] = (int) $state['uploaded'] + 1;
				} elseif ( (int) $res['updated'] > 0 ) {
					$state['updated'] = (int) $state['updated'] + 1;
				} elseif ( (int) $res['adopted'] > 0 ) {
					$state['adopted'] = (int) $state['adopted'] + 1;
				} else {
					$state['skipped'] = (int) $state['skipped'] + 1;
				}
			}

			if ( $this->cas_state( $expected, $state ) ) {
				return $state;
			}
			// CAS lost to a concurrent write — re-read and retry.
		}
		// CAS exhausted under sustained contention — return the persisted truth
		// rather than an in-memory state that never landed.
		return $this->fresh_state();
	}

	/**
	 * Count attachments already registered as offloaded to R2 (the "migrated"
	 * number for the UI). A single indexed COUNT on the postmeta meta_key index —
	 * cheap enough to call on each status poll.
	 *
	 * @return int
	 */
	public function count_synced() {
		global $wpdb;
		// Scope to non-trashed attachments to match count_attachments() (the "total"
		// denominator). Counting raw postmeta rows would include sync meta left on a
		// trashed attachment, inflating "migrated" past "total".
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed COUNT over a join, constant query, no user input.
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND p.post_status != 'trash'",
				Settings::META_SYNCED
			)
		);
	}

	/**
	 * Terminally stop a run: halt like stop()/pause (CAS-safe, preserving the
	 * worker's latest counters for display), then mark it cancelled so it is NOT
	 * resumable — the UI returns to "Start" rather than offering "Resume".
	 * Clears run_id so any late worker discards its write.
	 *
	 * @return array The persisted state.
	 */
	public function cancel() {
		// Stamp the terminal flags (running=false, cancelled, cleared run_id) onto
		// the FRESHEST state via CAS — same retry flow as stop() — so a worker that
		// saves newer cursor/counters between our read and write isn't clobbered.
		// Clearing run_id makes any later worker discard its post-batch write.
		for ( $attempt = 0; $attempt < self::PERSIST_RETRIES; $attempt++ ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$expected = get_option( self::STATE_OPTION, array() );
			$state    = array_merge( self::default_state(), is_array( $expected ) ? $expected : array() );
			$state['running']   = false;
			$state['cancelled'] = true;
			$state['run_id']    = '';
			if ( $this->cas_state( $expected, $state ) ) {
				break;
			}
			// CAS lost to a concurrent worker write — re-read and retry.
		}
		$this->clear_scheduled();
		$this->release_lock();
		return $this->fresh_state();
	}

	/**
	 * Stop a running migration. Progress is preserved so it can be resumed.
	 *
	 * @return array New state.
	 */
	public function stop() {
		// Flip running=false on the FRESHEST state via CAS, not a blind write of
		// this request's snapshot: stop() doesn't hold the batch lock, so a worker
		// may persist a newer cursor/counters between our read and our write. A
		// blind update_option would clobber that — regressing the cursor (one batch
		// re-processed on resume) and the counters. The CAS preserves the worker's
		// latest progress and only changes `running`.
		for ( $attempt = 0; $attempt < self::PERSIST_RETRIES; $attempt++ ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$expected = get_option( self::STATE_OPTION, array() );
			$state    = array_merge( self::default_state(), is_array( $expected ) ? $expected : array() );
			if ( empty( $state['running'] ) ) {
				break; // Already stopped (or never ran) — nothing to flip.
			}
			$state['running'] = false;
			if ( $this->cas_state( $expected, $state ) ) {
				break;
			}
			// CAS lost to a concurrent worker write — re-read and retry.
		}
		$this->clear_scheduled();
		$this->release_lock();
		// Return the ACTUAL persisted state, not our last in-memory attempt: if the
		// CAS exhausted its retries under sustained contention, running may still be
		// true in the DB, and the caller (the admin UI) must see the truth rather
		// than a "stopped" state that didn't persist.
		return $this->fresh_state();
	}

	/**
	 * Whether a batch worker currently holds the migration lock — i.e. one is
	 * actively processing (or crashed within the lock's TTL). Distinct from
	 * state['running']: after stop() the run is no longer "running" but a prior
	 * worker may still be finishing its in-flight batch and holding the lock
	 * (stop() can't release another process's lock). The WP-CLI command checks
	 * this — it bypasses this lock, so without the check it could upload alongside
	 * that in-flight worker. Reads straight from the DB, bypassing the options
	 * cache, since the lock is a cross-process signal.
	 *
	 * @return bool
	 */
	public function has_active_worker() {
		global $wpdb;
		$current = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cross-process lock; must bypass the cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		if ( '' === $current ) {
			return false;
		}
		$parts   = explode( '|', $current );
		$expires = isset( $parts[1] ) ? (int) $parts[1] : 0;
		return $expires > time(); // Non-expired lock → a worker is (or very recently was) active.
	}

	/**
	 * Process exactly one batch and persist progress. Safe to call from the
	 * cron tick or the status poll. Re-schedules itself until done.
	 *
	 * @return array Current state after the batch.
	 */
	public function run_one_batch( $max_seconds = self::BATCH_MAX_SECONDS ) {
		$state = $this->state();
		if ( empty( $state['running'] ) ) {
			return $state;
		}

		// Mutex so the cron tick and the status poll can't process the same
		// cursor concurrently. acquire_lock() is atomic, so only one worker
		// wins; the rest just report current state.
		if ( ! $this->acquire_lock() ) {
			// Another worker holds the lock. It may be a SUPERSEDED worker from a
			// prior run after a quick stop→start: it still holds the lock and will
			// exit on a run_id mismatch WITHOUT scheduling a tick, while this fresh
			// run's single start() tick gives up here. Reschedule so progress
			// resumes once the lock frees — otherwise the migration stalls with
			// running=true and no queued tick until someone reopens the page.
			// schedule_next() is idempotent (no-op if a tick is already due); the
			// next tick re-checks running and stops rescheduling once the run ends.
			$this->schedule_next();
			return $state;
		}

		// try/finally so the lock is ALWAYS released — a fatal in migrate_batch()
		// must never strand it and block all progress until the TTL expires.
		try {
			// Re-read under the lock: a stop()/start() may have landed between
			// the first read and acquiring the lock. Bail BEFORE doing any work
			// if the run was stopped or superseded — once migrate_batch() runs
			// it uploads files and writes _r2offload_* meta, side effects the
			// post-batch guard can't undo. fresh_state() busts the options cache
			// first: stop() runs in another process and only clears ITS cache,
			// so a plain get_option() here would return this request's stale,
			// still-"running" copy and the guard would never fire.
			$state = $this->fresh_state();
			if ( empty( $state['running'] ) ) {
				return $state;
			}

			// Arm the next tick BEFORE doing batch work. wp_schedule_single_event
			// persists immediately, so if this process dies mid-batch (timeout,
			// OOM, deploy) the chain isn't lost — a queued tick still fires and
			// resumes once the lock's TTL lapses. The post-batch path clears it
			// on done/stop. schedule_next() is idempotent.
			$this->schedule_next();

			// The run token this worker belongs to. A batch can take a while,
			// and the lock only serialises batch workers — it doesn't stop the
			// control plane (start()/stop()) changing state meanwhile. We
			// capture the token now and re-validate before persisting (below).
			$run_id = (string) $state['run_id'];

			// Defensive default — the catch below also sets it, but this makes
			// $result unambiguously defined for the post-batch checks regardless.
			$result = array( 'done' => false );

			try {
				$migrator = new Migrator( null, $this->settings );
				$migrator->set_dry_run( 'dry-run' === $state['mode'] )
					->set_verify( 'verify' === $state['mode'] )
					->set_force( 'force' === $state['mode'] )
					->set_heartbeat( array( $this, 'refresh_lock' ) );

				$result = $migrator->migrate_batch( self::BATCH, (string) $state['cursor'], (int) $max_seconds );

				$state['processed'] += (int) $result['processed'];
				$state['uploaded']  += (int) $result['uploaded'];
				$state['updated']   += (int) $result['updated'];
				$state['adopted']   += (int) $result['adopted'];
				$state['skipped']   += (int) $result['skipped'];
				$state['errored']   += (int) $result['errored'];
				$state['errors']    += count( $result['errors'] );
				$state['pass_errors'] += count( $result['errors'] );
				$state['bytes']     += (int) $result['bytes'];
				$state['cursor']     = (string) $result['next_cursor'];
				// Circuit breaker: a batch that THREW bumps the streak in catch
				// below. Also bump it when an upload/force batch processed items but
				// NONE had any successful outcome — i.e. every item failed (e.g. wrong
				// credentials → every PUT 403) — so a uniformly-failing run aborts
				// instead of grinding the whole library. ANY successful outcome clears
				// it — including adoption (uploaded=skipped=0 but adopted>0, the Super
				// Slurper path) and updates, or the breaker would trip on a fully-
				// adopted library. Force writes too, so it's gated alongside upload;
				// dry-run/verify count progress differently and are not gated.
				$batch_made_progress = (
					(int) $result['uploaded'] > 0
					|| (int) $result['updated'] > 0
					|| (int) $result['adopted'] > 0
					|| (int) $result['skipped'] > 0
				);
				if ( in_array( $state['mode'], array( 'upload', 'force' ), true ) && (int) $result['processed'] > 0 && ! $batch_made_progress ) {
					++$state['fail_streak'];
				} else {
					$state['fail_streak'] = 0;
				}
				if ( ! empty( $result['errors'] ) ) {
					$state['last_error']    = (string) end( $result['errors'] );
					$state['recent_errors'] = $this->append_recent_errors( $state, array_map( 'strval', $result['errors'] ) );
				}

				// Append per-item log lines to the ring buffer (keep last 200).
				if ( ! empty( $result['log'] ) ) {
					$log = isset( $state['log_entries'] ) ? (array) $state['log_entries'] : array();
					$log = array_merge( $log, array_map( 'strval', $result['log'] ) );
					if ( count( $log ) > 200 ) {
						$log = array_slice( $log, -200 );
					}
					$state['log_entries'] = $log;
				}
				$state['last_batch_at'] = time();
			} catch ( \Throwable $e ) {
				// Record the failure and let the next tick retry rather than
				// killing the run (and leaking the lock). A persistent throw is
				// caught by the circuit breaker below.
				++$state['errors'];
				++$state['fail_streak'];
				$state['last_error']    = $e->getMessage();
				$state['recent_errors'] = $this->append_recent_errors( $state, array( $e->getMessage() ) );
				$result                 = array( 'done' => false );
			}

			// Multi-pass: the cursor advances past attachments that errored, so a
			// pass can finish with items still un-migrated. If this pass reached
			// the end but recorded errors, re-scan from the start to retry them
			// (already-done items skip fast). Bounded by MAX_PASSES so a
			// permanently-failing item can't loop forever.
			if (
				! empty( $result['done'] )
				&& 'upload' === $state['mode']
				&& (int) $state['pass_errors'] > 0
				&& (int) $state['pass'] < self::MAX_PASSES
			) {
				$state['pass']        = (int) $state['pass'] + 1;
				$state['pass_errors'] = 0;
				// Re-counting for the new pass so the admin UI reflects the FINAL
				// pass (library state) rather than the sum across passes — items
				// that succeed on retry should drop out of these counts. Matches
				// the WP-CLI summary. bytes stays cumulative (real data moved);
				// all attachment-level outcome counters are reset so processed
				// === uploaded + updated + adopted + skipped + errored holds
				// within a single pass and the UI totals never exceed processed.
				$state['processed']     = 0;
				$state['uploaded']      = 0;
				$state['updated']       = 0;
				$state['adopted']       = 0;
				$state['skipped']       = 0;
				$state['errored']       = 0;
				$state['errors']        = 0;
				$state['recent_errors'] = array(); // Show only the final pass's errors.
				$state['cursor']        = '';    // Re-scan from the first attachment.
				$result['done']         = false; // Keep the run going.
			}

			// Reconcile-and-persist. A concurrent stop()/start() can change the
			// state option between our read and our write. We re-read fresh
			// (cache-busted, so cross-process writes are visible) and CAS on the
			// exact observed value; on contention we retry rather than drop the
			// cursor we actually advanced this batch. This both honours the
			// latest control-plane decision AND preserves real progress — e.g. a
			// stop() that races our save no longer discards a migrated batch.
			for ( $attempt = 0; $attempt < self::PERSIST_RETRIES; $attempt++ ) {
				wp_cache_delete( self::STATE_OPTION, 'options' );
				$expected_raw = get_option( self::STATE_OPTION, array() );
				$current      = array_merge( self::default_state(), is_array( $expected_raw ) ? $expected_raw : array() );

				if ( (string) $current['run_id'] !== $run_id ) {
					// A different run owns the state now — our progress is moot.
					return $current;
				}

				// Carry this batch's progress onto the freshest control state.
				$next         = $state;
				$reschedule   = false;

				if ( empty( $current['running'] ) ) {
					// stop() landed: keep our progress for resume, stay stopped.
					$next['running'] = false;
				} elseif ( (int) $state['fail_streak'] >= self::MAX_FAIL_STREAK ) {
					// Circuit breaker: repeated whole-batch failures (e.g. corrupt
					// metadata at the cursor) — abort instead of looping forever.
					$next['running']     = false;
					$next['finished_at'] = time();
					$next['last_error']  = sprintf(
						/* translators: 1: number of failures, 2: last error message */
						__( 'Migration aborted after %1$d consecutive batch failures. Last error: %2$s', 'r2-stateless-media-offload' ),
						(int) $state['fail_streak'],
						(string) $state['last_error']
					);
				} elseif ( ! empty( $result['done'] ) ) {
					$next['running']     = false;
					$next['finished_at'] = time();
				} else {
					$reschedule = true;
				}

				if ( $this->cas_state( $expected_raw, $next ) ) {
					if ( $reschedule ) {
						$this->schedule_next();
					} else {
						$this->clear_scheduled();
					}
					return $next;
				}
				// CAS lost to a concurrent write — re-read and reconcile again.
			}

			// Exhausted retries under sustained contention. We couldn't save this
			// batch's progress, but we must not leave a "running" state with no
			// cron event queued, or the migration silently stalls. Re-read and,
			// if the run is still active, make sure a tick is scheduled to retry.
			$final = $this->fresh_state();
			if ( ! empty( $final['running'] ) ) {
				$this->schedule_next();
			}
			return $final;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Compare-and-swap the migration state option.
	 *
	 * Updates STATE_OPTION to $new only if its stored value still exactly
	 * matches $expected_raw (the RAW, un-merged value read post-batch). A
	 * concurrent start()/stop() will have changed the stored value, so the
	 * UPDATE matches no row and we report failure — the caller then discards
	 * its stale progress instead of clobbering the newer control-plane state.
	 *
	 * @param mixed $expected_raw Value previously read from get_option().
	 * @param array $new          New state to store.
	 * @return bool True if this worker's write won.
	 */
	private function cas_state( $expected_raw, array $new ) {
		global $wpdb;

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $new ),
				self::STATE_OPTION,
				maybe_serialize( $expected_raw )
			)
		);
		wp_cache_delete( self::STATE_OPTION, 'options' );

		if ( $rows > 0 ) {
			return true;
		}
		// Zero rows changed: either a concurrent writer moved the value on (real
		// CAS failure) or our new value equalled the old (a no-op write that is
		// still the outcome we wanted). Distinguish by re-reading.
		return maybe_serialize( get_option( self::STATE_OPTION, array() ) ) === maybe_serialize( $new );
	}

	/**
	 * Atomically acquire the batch lock.
	 *
	 * Two independent callers can contend: the background cron tick and the
	 * admin status poll. The lock value is `{owner-token}|{expires-at}`:
	 *
	 *  - First acquire uses add_option(), a single INSERT guarded by the unique
	 *    index on option_name, so only one caller can create the row.
	 *  - Reclaiming an expired lock uses a compare-and-swap UPDATE keyed on the
	 *    exact value observed, so when several callers all see the same stale
	 *    lock, only the first UPDATE matches — the rest find the value already
	 *    changed and back off. No blind update_option() that all of them win.
	 *
	 * NB: we INSERT directly rather than via add_option(), which uses
	 * `INSERT ... ON DUPLICATE KEY UPDATE` and so would let two concurrent
	 * first-acquirers both "succeed" (one inserts, the other updates — both
	 * report affected rows). A plain INSERT fails on the duplicate key, so only
	 * one racer wins. Reads go straight to the DB too, bypassing the options
	 * cache, since this is a cross-process lock.
	 *
	 * release_lock() and the reclaim both key on this instance's own value, so
	 * a worker can never delete or steal a lock another worker still holds.
	 *
	 * @return bool True if this caller now holds the lock.
	 */
	private function acquire_lock() {
		global $wpdb;

		$now   = time();
		$value = $this->new_lock_value( $now );

		// Atomic create: a plain INSERT fails (no rows, duplicate-key error)
		// when the lock row already exists, so exactly one concurrent caller
		// can create it. autoload 'no' keeps it out of the alloptions cache.
		$suppressed = $wpdb->suppress_errors( true ); // Duplicate-key is expected, not a real error.
		$inserted   = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::LOCK_OPTION,
				$value,
				'no'
			)
		);
		$wpdb->suppress_errors( $suppressed );
		if ( 1 === (int) $inserted ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			$this->lock_value = $value;
			return true;
		}

		// Row exists. Read the live value straight from the DB (not get_option,
		// which can serve a stale/cached miss for this lock).
		$current = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		$parts   = explode( '|', $current );
		$expires = isset( $parts[1] ) ? (int) $parts[1] : 0;
		if ( '' === $current || $expires > $now ) {
			return false; // Gone (someone released — try again next tick) or still held.
		}

		// Expired — reclaim via compare-and-swap; exactly one racer can win.
		$swapped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::LOCK_OPTION,
				$current
			)
		);
		if ( 1 === (int) $swapped ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			$this->lock_value = $value;
			return true;
		}
		return false;
	}

	/**
	 * Extend this instance's lock expiry (compare-and-swap on the exact value
	 * we hold). Called as the migrator's heartbeat so a single attachment with
	 * many slow remote fetches can't let the lock lapse and admit a second
	 * worker. A no-op if we no longer hold the lock (the CAS simply misses).
	 */
	public function refresh_lock() {
		global $wpdb;

		if ( '' === $this->lock_value ) {
			return;
		}
		$parts = explode( '|', $this->lock_value ); // Always >= 1 element.
		$token = $parts[0];
		if ( '' === $token ) {
			return;
		}
		$new = $token . '|' . ( time() + self::LOCK_TTL );
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new,
				self::LOCK_OPTION,
				$this->lock_value
			)
		);
		if ( $updated ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			$this->lock_value = $new;
		}
	}

	/**
	 * Release the batch lock, but only if this instance still owns it.
	 */
	private function release_lock() {
		global $wpdb;

		if ( '' === $this->lock_value ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_OPTION,
				$this->lock_value
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$this->lock_value = '';
	}

	/**
	 * Build a fresh lock value: a unique owner token plus an expiry timestamp.
	 *
	 * @param int $now Current Unix time.
	 * @return string
	 */
	private function new_lock_value( $now ) {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		return $token . '|' . ( $now + self::LOCK_TTL );
	}

	/**
	 * Cron callback — advance one batch in the background.
	 */
	public function tick() {
		$this->run_one_batch();
	}

	/**
	 * Schedule the next background tick (a few seconds out) if not already due.
	 */
	private function schedule_next() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Cancel any pending tick.
	 */
	private function clear_scheduled() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Count non-trashed attachments (the migration total).
	 *
	 * @return int
	 */
	private function count_attachments() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- constant query, no user input; result is cached in state['total'] for the run.
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
		);
	}
}
