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
		add_action( 'wp_ajax_r2offload_migrate_status', array( $this, 'ajax_status' ) );
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
			array( 'nonce' => wp_create_nonce( self::AJAX_NONCE ) )
		) . ';' );
		wp_add_inline_script( 'jquery', $this->inline_js() );
	}

	/**
	 * @return string
	 */
	private function inline_js() {
		return <<<'JS'
jQuery(function($){
	var $bar = $('#r2offload-mig-bar'), $txt = $('#r2offload-mig-text');
	var $start = $('#r2offload-mig-start'), $stop = $('#r2offload-mig-stop');
	var $resume = $('#r2offload-mig-resume');
	var $mode = $('#r2offload-mig-mode');
	var polling = false;

	function render(s){
		var pct = s.total > 0 ? Math.min(100, Math.round((s.processed / s.total) * 100)) : 0;
		$bar.css('width', pct + '%').text(pct + '%');
		var resumable = !s.running && !s.finished_at && ((s.started_at > 0) || s.cursor);
		$txt.text(
			(s.running ? 'Running' : (s.finished_at ? 'Done' : (resumable ? 'Stopped' : 'Idle'))) +
			' — ' + s.processed + ' / ' + s.total + ' processed' +
			'  ·  uploaded ' + s.uploaded + '  ·  skipped ' + s.skipped + '  ·  errors ' + s.errors
		);
		$start.prop('disabled', !!s.running);
		$stop.prop('disabled', !s.running);
		$resume.prop('disabled', !resumable).toggle(!!resumable);
		$mode.prop('disabled', !!s.running);
	}
	function poll(){
		$.post(ajaxurl, { action:'r2offload_migrate_status', nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){
				if(res && res.success){
					render(res.data);
					if(res.data.running){ setTimeout(poll, 1500); } else { polling = false; }
				} else { polling = false; }
			})
			.fail(function(){ polling = false; });
	}
	function startPolling(){ if(!polling){ polling = true; poll(); } }

	$start.on('click', function(){
		$.post(ajaxurl, { action:'r2offload_migrate_start', nonce:R2OFFLOAD_MIG.nonce, mode:$mode.val() })
			.done(function(res){ if(res && res.success){ render(res.data); startPolling(); } });
	});
	$resume.on('click', function(){
		$.post(ajaxurl, { action:'r2offload_migrate_resume', nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){ if(res && res.success){ render(res.data); if(res.data.running){ startPolling(); } } });
	});
	$stop.on('click', function(){
		$.post(ajaxurl, { action:'r2offload_migrate_stop', nonce:R2OFFLOAD_MIG.nonce })
			.done(function(res){ if(res && res.success){ render(res.data); } });
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
		if ( ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configure R2 credentials first.', 'r2-stateless-media-offload' ) ) );
		}
		wp_send_json_success( $this->runner->start( $mode ) );
	}

	/**
	 * AJAX: resume a stopped migration from where it left off.
	 */
	public function ajax_resume() {
		$this->guard();
		if ( ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Configure R2 credentials first.', 'r2-stateless-media-offload' ) ) );
		}
		wp_send_json_success( $this->runner->resume() );
	}

	/**
	 * AJAX: stop a migration.
	 */
	public function ajax_stop() {
		$this->guard();
		wp_send_json_success( $this->runner->stop() );
	}

	/**
	 * AJAX: report status and, if running, advance one batch (keeps progress
	 * moving while the admin watches; the cron tick drives it otherwise).
	 */
	public function ajax_status() {
		$this->guard();
		$state = $this->runner->state();
		if ( ! empty( $state['running'] ) ) {
			$state = $this->runner->run_one_batch();
		}
		wp_send_json_success( $state );
	}

	/**
	 * Shared capability + nonce check for the AJAX endpoints.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'r2-stateless-media-offload' ) ), 403 );
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
		$settings = $this->settings; // Used by the template include.
		$state    = $this->runner->state();
		require R2OFFLOAD_PLUGIN_DIR . 'templates/migration-page.php';
	}
}
