<?php
/**
 * Admin migration screen — background migrate-to-R2 with live progress.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Admin_Migration {

	const PAGE_SLUG   = 'r2offload-migrate';
	const AJAX_NONCE  = 'r2offload_migrate';

	/** @var Settings */
	private $settings;

	/** @var Migration_Runner */
	private $runner;

	/**
	 * @param Settings         $settings
	 * @param Migration_Runner $runner
	 */
	public function __construct( Settings $settings, Migration_Runner $runner ) {
		$this->settings = $settings;
		$this->runner   = $runner;
	}

	/**
	 * Hook the admin menu, scripts, and AJAX endpoints.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_r2offload_migrate_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_r2offload_migrate_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_r2offload_migrate_stop', array( $this, 'ajax_stop' ) );
		add_action( 'wp_ajax_r2offload_migrate_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_r2offload_migrate_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_r2offload_migrate_retry', array( $this, 'ajax_retry' ) );
	}

	/**
	 * Add Media → Migrate to R2.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'upload.php',
			__( 'Migrate to R2', 'r2-stateless-media-offload' ),
			__( 'Migrate to R2', 'r2-stateless-media-offload' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the progress UI script on our page only.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'media_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', 'window.R2OFFLOAD_MIG=' . wp_json_encode(
			array(
				'nonce'     => wp_create_nonce( self::AJAX_NONCE ),
				'pause'     => __( 'Pause', 'r2-stateless-media-offload' ),
				'resume'    => __( 'Resume', 'r2-stateless-media-offload' ),
				'errorsLbl' => __( 'Recent errors', 'r2-stateless-media-offload' ),
				'retryLbl'  => __( 'Retry', 'r2-stateless-media-offload' ),
				'migrated'  => __( 'Migrated to R2', 'r2-stateless-media-offload' ),
				'remaining' => __( 'remaining', 'r2-stateless-media-offload' ),
			)
		) . ';' );
		wp_add_inline_script( 'jquery', $this->inline_js() );
	}

	/**
	 * @return string
	 */
	private function inline_js() {
		return <<<'JS'
jQuery(function($){
	var $bar = $('#r2offload-mig-bar'), $txtWrap = $('#r2offload-mig-text');
	var $txt = $('#r2offload-mig-text-inner'), $spinner = $('#r2offload-mig-spinner');
	var $migrated = $('#r2offload-mig-migrated'), $errs = $('#r2offload-mig-errors');
	var $start = $('#r2offload-mig-start'), $pause = $('#r2offload-mig-pause'), $stop = $('#r2offload-mig-stop');
	var $mode = $('#r2offload-mig-mode');
	var $log = $('#r2offload-mig-log');
	var $logDetails = $('#r2offload-mig-log-details');
	var polling = false;
	var pollFailCount = 0;        // Consecutive AJAX failures; triggers auto-pause after threshold.
	var POLL_MAX_RETRIES    = 3;  // Auto-pause after this many consecutive poll failures.
	var POLL_RETRY_DELAY_MS = 2000; // Delay between retry attempts (ms).
	// Track the last rendered tail entry to detect ring-buffer rotation (the
	// server caps log_entries at 200; once full, length stays 200 while content
	// keeps sliding, so comparing length alone would stop updates).
	var lastLogTail = null;

	function renderLog(entries) {
		if ( !$log.length ) { return; }
		if ( !entries || !entries.length ) {
			// Empty array signals a fresh start — clear any stale content.
			if ( lastLogTail !== null ) {
				$log.text('');
				lastLogTail = null;
			}
			return;
		}
		var tail = entries[ entries.length - 1 ];
		if ( tail === lastLogTail ) { return; } // Content unchanged.
		lastLogTail = tail;
		// Plain-text only — never inject as HTML.
		$log.text( entries.join('\n') );
		// Auto-scroll to the newest entry.
		$log.scrollTop( $log[0].scrollHeight );
	}

	function render(s){
		var pct = s.total > 0 ? Math.min(100, Math.round((s.processed / s.total) * 100)) : 0;
		$bar.css('width', pct + '%').text(pct + '%');
		// Animated striped progress bar + spinner while running.
		if (s.running) {
			$bar.addClass('r2offload-running');
			$spinner.addClass('is-active');
		} else {
			$bar.removeClass('r2offload-running');
			$spinner.removeClass('is-active');
		}
		// "resumable" = PAUSED (can resume), authoritative from the server; a
		// terminal Stop is cancelled and NOT resumable.
		var resumable = ('resumable' in s) ? !!s.resumable
			: (!s.running && !s.finished_at && !s.cancelled && ((s.started_at > 0) || s.cursor));
		var hasRun = !!s.running || resumable; // A run is active or paused.
		var passLabel = (s.pass && s.pass > 1) ? ' (retry pass ' + s.pass + ')' : '';
		var statusWord = s.running ? 'Running'
			: (s.finished_at ? 'Done'
			: (resumable ? 'Paused'
			: (s.cancelled ? 'Stopped' : 'Idle')));
		$txt.text(
			statusWord + passLabel +
			' — ' + s.processed + ' / ' + s.total + ' processed' +
			'  ·  uploaded ' + s.uploaded +
			'  ·  updated ' + (s.updated || 0) +
			'  ·  adopted ' + (s.adopted || 0) +
			'  ·  skipped ' + s.skipped +
			'  ·  errors ' + (s.errored || 0)
		);
		$txtWrap.attr('aria-live', s.running ? 'off' : 'polite'); // Suppress aria-live chatter during rapid updates.

		// Migrated vs remaining (library-wide truth from the server count).
		if ( $migrated.length ) {
			if ( typeof s.migrated !== 'undefined' && s.total > 0 ) {
				var remaining = Math.max(0, s.total - s.migrated);
				$migrated.text(R2OFFLOAD_MIG.migrated + ': ' + s.migrated + ' / ' + s.total + '  ·  ' + remaining + ' ' + R2OFFLOAD_MIG.remaining).show();
			} else if ( typeof s.migrated !== 'undefined' && s.migrated > 0 ) {
				$migrated.text(R2OFFLOAD_MIG.migrated + ': ' + s.migrated).show();
			} else {
				$migrated.hide();
			}
		}

		// Recent error messages — text nodes only (never innerHTML), so an R2
		// error body can't inject markup. Retry button shown per-item when the
		// message carries a [#ID] prefix and the migration is not running.
		if ( $errs.length ) {
			var list = (s.recent_errors && s.recent_errors.length) ? s.recent_errors : [];
			if ( list.length ) {
				var $h = $('<p>').css({margin:'0 0 .25em', fontWeight:'600'}).text(R2OFFLOAD_MIG.errorsLbl + ' (' + (s.errors || 0) + '):');
				var $ul = $('<ul>').css({margin:0, paddingLeft:'1.2em'});
				list.forEach(function(msg){
					var $li  = $('<li>').css({display:'flex', alignItems:'baseline', gap:'6px'});
					var $txt = $('<span>').text(msg);
					$li.append($txt);
					var idMatch = msg.match(/^\[#(\d+)\]/);
					if ( idMatch ) {
						var attId = idMatch[1];
						var $btn  = $('<button type="button">').text(R2OFFLOAD_MIG.retryLbl)
							.css({fontSize:'0.75em', padding:'1px 6px', cursor:'pointer', flexShrink:0})
							.prop('disabled', !!s.running)
							.on('click', function(){
								$btn.prop('disabled', true).text('…');
								$.post(ajaxurl, { action:'r2offload_migrate_retry', nonce:R2OFFLOAD_MIG.nonce, attachment_id:attId })
									.done(function(res){
										if(res && res.success){ render(res.data); }
										else { $btn.prop('disabled', false).text(R2OFFLOAD_MIG.retryLbl); }
									})
									.fail(function(){ $btn.prop('disabled', false).text(R2OFFLOAD_MIG.retryLbl); });
							});
						$li.append($btn);
					}
					$ul.append($li);
				});
				$errs.empty().append($h).append($ul).show();
			} else {
				$errs.hide().empty();
			}
		}

		// Activity log panel — renderLog always runs so an empty log_entries
		// array on a fresh start clears stale DOM content from the previous run.
		// Auto-open the panel the first time real entries arrive.
		var logEntries = (s.log_entries && s.log_entries.length) ? s.log_entries : [];
		if ( logEntries.length && $logDetails.length && !$logDetails.prop('open') ) {
			$logDetails.prop('open', true);
		}
		renderLog( logEntries );

		if (s.mode) { $mode.val(s.mode); }
		// Buttons: Start only when idle/done; Pause/Stop only when a run is
		// active or paused; the Pause button doubles as Resume when paused.
		// Only a PAUSED run resumes; idle/done/stopped default to "Pause" so a
		// disabled terminal button never misleadingly reads "Resume".
		var canResume = resumable && !s.running;
		$start.prop('disabled', hasRun);
		$pause.prop('disabled', !hasRun)
			.text( canResume ? R2OFFLOAD_MIG.resume : R2OFFLOAD_MIG.pause )
			.data('action', canResume ? 'resume' : 'pause');
		$stop.prop('disabled', !hasRun);
		$mode.prop('disabled', hasRun);
	}
	function poll(){
		$.post(ajaxurl, { action:'r2offload_migrate_status', nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){
				pollFailCount = 0;
				if(res && res.success){
					render(res.data);
					if(res.data.running){ setTimeout(poll, 1500); } else { polling = false; }
				} else { polling = false; clearRunningUI(); $txt.text('Polling error — reload or click a button to retry.'); }
			})
			.fail(function(){
				pollFailCount++;
				if ( pollFailCount < POLL_MAX_RETRIES ) {
					// Brief network hiccup — retry a couple of times before acting.
					setTimeout(poll, POLL_RETRY_DELAY_MS);
					return;
				}
				// Persistent connection loss — auto-pause so the server-side run
				// stops cleanly and can be resumed when the connection returns.
				pollFailCount = 0;
				polling = false;
				clearRunningUI();
				$txt.text('Connection lost — pausing…');
				$.post(ajaxurl, { action:'r2offload_migrate_stop', nonce:R2OFFLOAD_MIG.nonce })
					.done(function(res){
						if(res && res.success){
							render(res.data);
							// Only tell the user to Resume if the server confirmed a
							// resumable (paused) state; if it finished naturally just
							// before the connection dropped, render() already shows Done.
							if ( res.data.resumable ) {
								$txt.text('Paused — connection was lost. Click Resume to continue.');
							}
						} else {
							// Stop request reached the server but was rejected — the run
							// may still be active. Don't mention Resume here because
							// render() never ran so the button still reads "Pause".
							$txt.text('Connection lost — reload to resync the migration state before retrying.');
						}
					})
					.fail(function(){
						// Stop request never reached the server — state is unknown.
						$txt.text('Connection lost — reload to resync the migration state before retrying.');
					});
			});
	}
	function startPolling(){ if(!polling){ polling = true; pollFailCount = 0; poll(); } }
	function showError(res, fallback){ $txtWrap.attr('aria-live', 'polite'); $txt.text((res && res.data && res.data.message) ? res.data.message : fallback); }

	function clearRunningUI(){ $spinner.removeClass('is-active'); $bar.removeClass('r2offload-running'); $txtWrap.attr('aria-live', 'polite'); }
	$start.on('click', function(){
		$.post(ajaxurl, { action:'r2offload_migrate_start', nonce:R2OFFLOAD_MIG.nonce, mode:$mode.val() })
			.done(function(res){ if(res && res.success){ render(res.data); startPolling(); } else { showError(res, 'Could not start the migration.'); } })
			.fail(function(){ clearRunningUI(); $txt.text('Connection lost — reload or try again.'); });
	});
	// One button toggles Pause (while running) and Resume (while paused).
	$pause.on('click', function(){
		var resume = ( $pause.data('action') === 'resume' );
		var endpoint = resume ? 'r2offload_migrate_resume' : 'r2offload_migrate_stop';
		$.post(ajaxurl, { action: endpoint, nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){
				if(res && res.success){ render(res.data); if(res.data.running){ startPolling(); } }
				else { showError(res, resume ? 'Could not resume the migration.' : 'Could not pause the migration.'); }
			})
			.fail(function(){ clearRunningUI(); $txt.text('Connection lost — reload or try again.'); });
	});
	$stop.on('click', function(){
		$.post(ajaxurl, { action:'r2offload_migrate_cancel', nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){ if(res && res.success){ render(res.data); } else { showError(res, 'Could not stop the migration.'); } })
			.fail(function(){ clearRunningUI(); $txt.text('Connection lost — reload or try again.'); });
	});

	// Initial state + resume polling if a migration is already running.
	$.post(ajaxurl, { action:'r2offload_migrate_status', nonce:R2OFFLOAD_MIG.nonce })
		.done(function(res){ if(res && res.success){ render(res.data); if(res.data.running){ startPolling(); } } });
});
JS;
	}

	/**
	 * AJAX: start a migration.
	 */
	public function ajax_start() {
		$this->guard();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'upload';
		if ( ! in_array( $mode, array( 'upload', 'force', 'dry-run', 'verify' ), true ) ) {
			$mode = 'upload';
		}
		// Don't reset an already-running migration. The Start button is disabled
		// in the UI while running, but a stale page or a direct AJAX call could
		// still hit this; calling start() would reset the cursor, counters and
		// run_id out from under a cron/poll worker that may still hold the lock —
		// duplicate work and a progress UI that no longer matches the background
		// run. Treat it as a no-op that returns the live state. (A crashed run
		// keeps running=true but self-resumes via its already-scheduled tick.)
		$current = $this->runner->state();
		// Don't reset a run that's active OR merely paused (resumable). The UI
		// disables Start in both cases, but a stale tab or a direct admin-ajax call
		// could still hit this and wipe a paused run's cursor/counters/run_id. To
		// start fresh over a paused run, Stop (cancel) it first.
		if ( ! empty( $current['running'] ) || $this->runner->is_resumable( $current ) ) {
			$this->respond( $current );
			return; // respond() (wp_send_json_success) already exits; explicit for static analysis.
		}
		// Upload and verify need R2 credentials; dry-run only counts (degrading to
		// "everything as to-upload" when R2 isn't reachable), so allow it to
		// preview before credentials exist — matching `wp r2offload sync --dry-run`.
		if ( 'dry-run' !== $mode && ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configure R2 credentials first.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$this->respond( $this->runner->start( $mode ) );
	}

	/**
	 * Send a state payload, augmented with the authoritative `resumable` flag so
	 * the JS never has to re-derive the resume condition (single source of
	 * truth: Migration_Runner::is_resumable()).
	 *
	 * @param array $state
	 */
	private function respond( array $state ) {
		$state['resumable'] = $this->runner->is_resumable( $state );
		// Library-wide "migrated" count (attachments registered on R2), so the UI
		// can show migrated vs remaining independent of the current pass/cursor.
		$state['migrated'] = $this->runner->count_synced();
		wp_send_json_success( $state );
	}

	/**
	 * AJAX: resume a stopped migration from where it left off.
	 */
	public function ajax_resume() {
		$this->guard();
		$state = $this->runner->state();
		// A stopped dry-run never needed credentials, so let it resume without
		// them (matching ajax_start). Upload/verify still require configuration.
		if ( 'dry-run' !== ( isset( $state['mode'] ) ? (string) $state['mode'] : '' ) && ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configure R2 credentials first.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		if ( ! $this->runner->is_resumable( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'There is no stopped migration to resume.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$this->respond( $this->runner->resume() );
	}

	/**
	 * AJAX: stop a migration.
	 */
	public function ajax_stop() {
		$this->guard();
		$this->respond( $this->runner->stop() );
	}

	/**
	 * AJAX: terminally stop a migration (not resumable — the UI returns to Start).
	 */
	public function ajax_cancel() {
		$this->guard();
		$this->respond( $this->runner->cancel() );
	}

	/**
	 * AJAX: report status and, if running, advance one batch (keeps progress
	 * moving while the admin watches; the cron tick drives it otherwise).
	 */
	public function ajax_status() {
		$this->guard();
		$state = $this->runner->state();
		if ( ! empty( $state['running'] ) ) {
			// Short budget: this runs inside admin-ajax.php and must return well
			// within web execution limits. WP-Cron advances the bulk.
			$state = $this->runner->run_one_batch( Migration_Runner::AJAX_BATCH_MAX_SECONDS );
		}
		$this->respond( $state );
	}

	/**
	 * AJAX: retry a single attachment that previously errored.
	 */
	public function ajax_retry() {
		$this->guard();
		$state = $this->runner->state();
		if ( ! empty( $state['running'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cannot retry while migration is running.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		// Just after a Pause/Stop the run is no longer "running" but a batch
		// worker may still be finishing its in-flight batch under the lock.
		// retry_attachment() runs a second Migrator OUTSIDE that lock, so it
		// must not start while the worker could be processing (possibly the
		// same attachment). Same window the WP-CLI sync guard covers.
		if ( $this->runner->has_active_worker() ) {
			wp_send_json_error( array( 'message' => __( 'A migration batch is still finishing — try again in a moment.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		// A dry-run never needed credentials and the retry runs in the stored
		// mode, so let dry-run retries through without them — matching
		// ajax_start()/ajax_resume(). Upload/verify/force retries still require
		// configuration.
		if ( 'dry-run' !== ( isset( $state['mode'] ) ? (string) $state['mode'] : '' ) && ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configure R2 credentials first.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$raw_id        = isset( $_POST['attachment_id'] ) ? $_POST['attachment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$attachment_id = (int) $raw_id;
		if ( $attachment_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Attachment not found.', 'r2-stateless-media-offload' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$result = $this->runner->retry_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			// Lock acquisition lost to a worker that became active after the
			// guards above — a failure, not a completed retry.
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		$this->respond( $result );
	}

	/**
	 * Shared capability + nonce check for the AJAX endpoints.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'r2-stateless-media-offload' ) ), 403 );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
	}

	/**
	 * Render the migration page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings            = $this->settings; // Used by the template include.
		$state               = $this->runner->state();
		$r2offload_resumable = $this->runner->is_resumable( $state );
		require R2OFFLOAD_PLUGIN_DIR . 'templates/migration-page.php';
	}
}
