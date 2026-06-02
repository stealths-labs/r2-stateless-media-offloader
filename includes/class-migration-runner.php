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
	// Long enough that a healthy batch (up to BATCH items, each able to block
	// on a remote download) is never mistaken for a crashed worker; the
	// compare-and-swap in acquire_lock() is the actual correctness guarantee,
	// this only bounds how long a genuinely dead lock blocks progress.
	const LOCK_TTL     = 1800;

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
			'cursor'      => '',
			'processed'   => 0,
			'uploaded'    => 0,
			'skipped'     => 0,
			'errors'      => 0,
			'bytes'       => 0,
			'total'       => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'last_error'  => '',
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
		$mode  = in_array( $mode, array( 'upload', 'dry-run', 'verify' ), true ) ? $mode : 'upload';
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

		$this->schedule_next();
		return $state;
	}

	/**
	 * Stop a running migration. Progress is preserved so it can be resumed.
	 *
	 * @return array New state.
	 */
	public function stop() {
		$state            = $this->state();
		$state['running'] = false;
		update_option( self::STATE_OPTION, $state, false );
		$this->clear_scheduled();
		$this->release_lock();
		return $state;
	}

	/**
	 * Process exactly one batch and persist progress. Safe to call from the
	 * cron tick or the status poll. Re-schedules itself until done.
	 *
	 * @return array Current state after the batch.
	 */
	public function run_one_batch() {
		$state = $this->state();
		if ( empty( $state['running'] ) ) {
			return $state;
		}

		// Mutex so the cron tick and the status poll can't process the same
		// cursor concurrently. acquire_lock() is atomic, so only one worker
		// wins; the rest just report current state.
		if ( ! $this->acquire_lock() ) {
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

			// The run token this worker belongs to. A batch can take a while,
			// and the lock only serialises batch workers — it doesn't stop the
			// control plane (start()/stop()) changing state meanwhile. We
			// capture the token now and re-validate before persisting (below).
			$run_id = (string) $state['run_id'];

			try {
				$migrator = new Migrator( null, $this->settings );
				$migrator->set_dry_run( 'dry-run' === $state['mode'] )
					->set_verify( 'verify' === $state['mode'] );

				$result = $migrator->migrate_batch( self::BATCH, (string) $state['cursor'] );

				$state['processed'] += (int) $result['processed'];
				$state['uploaded']  += (int) $result['uploaded'];
				$state['skipped']   += (int) $result['skipped'];
				$state['errors']    += count( $result['errors'] );
				$state['bytes']     += (int) $result['bytes'];
				$state['cursor']     = (string) $result['next_cursor'];
				$state['fail_streak'] = 0; // Batch completed — clear the breaker.
				if ( ! empty( $result['errors'] ) ) {
					$state['last_error'] = (string) end( $result['errors'] );
				}
			} catch ( \Throwable $e ) {
				// Record the failure and let the next tick retry rather than
				// killing the run (and leaking the lock). A persistent throw is
				// caught by the circuit breaker below.
				++$state['errors'];
				++$state['fail_streak'];
				$state['last_error'] = $e->getMessage();
				$result              = array( 'done' => false );
			}

			// Re-read the control plane after the (potentially slow) batch. Bust
			// the options cache first so we see writes made by stop()/start() in
			// other processes, not this request's stale copy. We keep the RAW
			// stored value too, so the persist below can be a true compare-and-
			// swap: if start()/stop() writes between this read and our write, the
			// CAS fails and we discard our stale progress rather than reviving a
			// stopped run or wiping a fresh restart.
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$expected_raw = get_option( self::STATE_OPTION, array() );
			$current      = array_merge( self::default_state(), is_array( $expected_raw ) ? $expected_raw : array() );
			if ( (string) $current['run_id'] !== $run_id ) {
				// A different run owns the state now — discard our writes entirely.
				return $current;
			}

			if ( empty( $current['running'] ) ) {
				// stop() landed mid-batch. Persist the progress we made so the
				// job can resume, but honour the stop: stay stopped, don't
				// reschedule.
				$state['running'] = false;
				if ( $this->cas_state( $expected_raw, $state ) ) {
					$this->clear_scheduled();
				}
				return $this->state();
			}

			// Circuit breaker: too many consecutive whole-batch failures means
			// the batch can't make progress (e.g. corrupt metadata at the
			// cursor). Abort the run instead of rescheduling another doomed tick.
			if ( (int) $state['fail_streak'] >= self::MAX_FAIL_STREAK ) {
				$state['running']     = false;
				$state['finished_at'] = time();
				$state['last_error']  = sprintf(
					/* translators: 1: number of failures, 2: last error message */
					__( 'Migration aborted after %1$d consecutive batch failures. Last error: %2$s', 'r2-stateless-media-offload' ),
					(int) $state['fail_streak'],
					(string) $state['last_error']
				);
				if ( $this->cas_state( $expected_raw, $state ) ) {
					$this->clear_scheduled();
				}
				return $this->state();
			}

			if ( ! empty( $result['done'] ) ) {
				$state['running']     = false;
				$state['finished_at'] = time();
				if ( $this->cas_state( $expected_raw, $state ) ) {
					$this->clear_scheduled();
				}
				return $this->state();
			}

			if ( $this->cas_state( $expected_raw, $state ) ) {
				$this->schedule_next();
			}
			return $this->state();
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
	 * release_lock() and the reclaim both key on this instance's own value, so
	 * a worker can never delete or steal a lock another worker still holds.
	 *
	 * @return bool True if this caller now holds the lock.
	 */
	private function acquire_lock() {
		global $wpdb;

		$now   = time();
		$value = $this->new_lock_value( $now );

		// Non-autoloaded so the lock never rides along in the options cache.
		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			$this->lock_value = $value;
			return true;
		}

		$current = (string) get_option( self::LOCK_OPTION, '' );
		$parts   = explode( '|', $current );
		$expires = isset( $parts[1] ) ? (int) $parts[1] : 0;
		if ( $expires > $now ) {
			return false; // Still held by a live worker.
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
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
		);
	}
}
