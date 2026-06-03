<?php
/**
 * Admin settings screen — dual credential model + Test Connection.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings screen, handles saves, and the AJAX connection test.
 */
class Admin_Settings {

	const PAGE_SLUG    = 'r2offload-settings';
	const NONCE_ACTION = 'r2offload_save_settings';
	const NONCE_FIELD  = 'r2offload_settings_nonce';
	const AJAX_ACTION  = 'r2offload_test_connection';

	/**
	 * Editable, non-secret settings shown as text inputs.
	 *
	 * @var string[]
	 */
	private $text_fields = array( 'account_id', 'access_key', 'bucket', 'custom_domain', 'cache_control', 'path_prefix' );

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the admin menu, save handler, scripts, and AJAX endpoint.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Add Settings → R2 Offload.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'R2 Offload', 'r2-stateless-media-offload' ),
			__( 'R2 Offload', 'r2-stateless-media-offload' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist settings on POST. Secrets and constant-locked fields are handled
	 * with care: a constant-locked field is never written to the DB, and the
	 * secret is only overwritten when a new value is actually submitted.
	 */
	public function maybe_save() {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$existing = get_option( Settings::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$new      = $existing;

		// Drop any key now locked by a wp-config constant so a stale DB copy
		// can't linger (or silently re-activate if the constant is removed).
		foreach ( array_merge( $this->text_fields, array( 'mode', 'secret_key' ) ) as $key ) {
			if ( $this->settings->is_constant( $key ) ) {
				unset( $new[ $key ] );
			}
		}

		foreach ( $this->text_fields as $key ) {
			if ( $this->settings->is_constant( $key ) ) {
				continue; // Locked by wp-config — never store.
			}
			$raw          = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$raw          = is_string( $raw ) ? $raw : ''; // A crafted array submission must not warn.
			$new[ $key ] = sanitize_text_field( $raw );
		}

		// Mode (radio) — only the two known values; default to the safe on-ramp.
		if ( ! $this->settings->is_constant( 'mode' ) ) {
			$mode         = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'cdn';
			$new['mode'] = in_array( $mode, array( 'cdn', 'stateless' ), true ) ? $mode : 'cdn';
		}

		// Secret: only overwrite when a non-empty value is submitted, so the
		// "leave blank to keep" password field doesn't wipe a stored secret.
		// Stored encrypted at rest.
		if ( ! $this->settings->is_constant( 'secret_key' ) ) {
			$raw_secret = isset( $_POST['secret_key'] ) ? wp_unslash( $_POST['secret_key'] ) : '';
			// Cast a crafted array submission to '' (don't warn), then trim — R2
			// keys are whitespace-free, so trimming only guards against an
			// accidentally-pasted leading/trailing space or newline.
			$submitted = is_string( $raw_secret ) ? trim( $raw_secret ) : '';
			if ( '' !== $submitted ) {
				$new['secret_key'] = $this->settings->encrypt_secret( $submitted );
			}
		}

		// autoload = false: keep credentials (incl. the encrypted secret) out of
		// the autoloaded options cache that loads on every request. update_option's
		// autoload arg only takes effect when the value actually changes, so on a
		// no-op save it wouldn't flip an option that was somehow created with
		// autoload on. Enforce it explicitly where core supports it (WP 6.6+);
		// on older versions the first save created the row via add_option(...,
		// false), so it is already correct.
		update_option( Settings::OPTION_KEY, $new, false );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Settings::OPTION_KEY, false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Enqueue the inline Test Connection script on our page only.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		$data = array(
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			'testing' => __( 'Testing…', 'r2-stateless-media-offload' ),
			'failed'  => __( 'Connection test failed.', 'r2-stateless-media-offload' ),
		);
		wp_add_inline_script( 'jquery', 'window.R2OFFLOAD=' . wp_json_encode( $data ) . ';' );
		wp_add_inline_script( 'jquery', $this->inline_js() );
	}

	/**
	 * The Test Connection client script.
	 *
	 * @return string
	 */
	private function inline_js() {
		return <<<'JS'
jQuery(function($){
	var $btn = $('#r2offload-test-connection');
	if ( ! $btn.length ) { return; }
	var $out = $('#r2offload-test-result');
	// Render a message as plain text — never as HTML — to avoid injecting
	// server-supplied content (e.g. an R2 error body) as markup.
	function show(cls, msg){
		$out.attr('class','notice ' + cls + ' inline').empty().append($('<p>').text(msg)).show();
	}
	$btn.on('click', function(e){
		e.preventDefault();
		$btn.prop('disabled', true);
		show('notice-info', R2OFFLOAD.testing);
		$.post(ajaxurl, { action: R2OFFLOAD.action, nonce: R2OFFLOAD.nonce })
			.done(function(res){
				var ok = res && res.success;
				var msg = (res && res.data && res.data.message) ? res.data.message : (ok ? 'OK' : R2OFFLOAD.failed);
				show(ok ? 'notice-success' : 'notice-error', msg);
			})
			.fail(function(){ show('notice-error', R2OFFLOAD.failed); })
			.always(function(){ $btn.prop('disabled', false); });
	});
});
JS;
	}

	/**
	 * AJAX: test the currently-saved (or constant) R2 configuration.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'r2-stateless-media-offload' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! $this->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Save your credentials first, then test.', 'r2-stateless-media-offload' ) ) );
		}

		$result = Plugin::instance()->client()->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		wp_send_json_success( array( 'message' => __( 'Connected to R2 successfully.', 'r2-stateless-media-offload' ) ) );
	}

	/**
	 * Render the settings page. Exposes $settings to the template.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings; // Used by the template include.
		$page     = self::PAGE_SLUG;
		$nonce_action = self::NONCE_ACTION;
		$nonce_field  = self::NONCE_FIELD;
		require R2OFFLOAD_PLUGIN_DIR . 'templates/settings-page.php';
	}
}
