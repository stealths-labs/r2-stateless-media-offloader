<?php
/**
 * Admin settings page — dual credential model + Test Connection.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings screen, handles saves, and AJAX connection tests.
 */
class Admin_Settings {

	const PAGE_SLUG       = 'r2offload-settings';
	const NONCE_ACTION    = 'r2offload_save_settings';
	const NONCE_FIELD     = 'r2offload_settings_nonce';
	const AJAX_NONCE      = 'r2offload_test_connection';
	const AJAX_ACTION     = 'r2offload_test_connection';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook admin menu, save handler, scripts, and AJAX.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
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
	 * Enqueue inline script for Test Connection on our settings page only.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$inline = sprintf(
			'jQuery(function($){
				var $btn = $("#r2offload-test-connection");
				var $result = $("#r2offload-test-connection-result");
				$btn.on("click", function(e){
					e.preventDefault();
					$btn.prop("disabled", true);
					$result.removeClass("notice-success notice-error").addClass("notice notice-info").text(%s);
					$.post(ajaxurl, {
						action: %s,
						nonce: %s
					}).done(function(res){
						if (res.success) {
							$result.removeClass("notice-info notice-error").addClass("notice-success").text(res.data.message);
						} else {
							var msg = (res.data && res.data.message) ? res.data.message : %s;
							$result.removeClass("notice-info notice-success").addClass("notice-error").text(msg);
						}
					}).fail(function(){
						$result.removeClass("notice-info notice-success").addClass("notice-error").text(%s);
					}).always(function(){
						$btn.prop("disabled", false);
					});
				});
			});',
			wp_json_encode( __( 'Testing connection…', 'r2-stateless-media-offload' ) ),
			wp_json_encode( self::AJAX_ACTION ),
			wp_json_encode( wp_create_nonce( self::AJAX_NONCE ) ),
			wp_json_encode( __( 'Connection test failed.', 'r2-stateless-media-offload' ) ),
			wp_json_encode( __( 'Connection test request failed.', 'r2-stateless-media-offload' ) )
		);

		wp_add_inline_script( 'jquery', $inline );
	}

	/**
	 * Process settings form POST.
	 */
	public function maybe_save_settings() {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$existing = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$keys = array(
			'account_id',
			'access_key',
			'secret_key',
			'bucket',
			'custom_domain',
			'cache_control',
			'mode',
		);

		foreach ( $keys as $key ) {
			if ( $this->settings->is_constant( $key ) ) {
				continue;
			}

			if ( 'secret_key' === $key ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw = isset( $_POST[ 'r2offload_' . $key ] ) ? wp_unslash( $_POST[ 'r2offload_' . $key ] ) : '';
				if ( '' !== $raw ) {
					$existing[ $key ] = sanitize_text_field( $raw );
				}
				continue;
			}

			if ( 'mode' === $key ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$mode = isset( $_POST['r2offload_mode'] ) ? wp_unslash( $_POST['r2offload_mode'] ) : 'cdn';
				$existing['mode'] = in_array( $mode, array( 'cdn', 'stateless' ), true ) ? $mode : 'cdn';
				continue;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = isset( $_POST[ 'r2offload_' . $key ] ) ? wp_unslash( $_POST[ 'r2offload_' . $key ] ) : '';
			$existing[ $key ] = sanitize_text_field( $raw );
		}

		$existing['delete_on_attachment_delete'] = ! empty( $_POST['r2offload_delete_on_attachment_delete'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option( Settings::OPTION_KEY, $existing );

		add_settings_error(
			self::PAGE_SLUG,
			'r2offload_saved',
			__( 'Settings saved.', 'r2-stateless-media-offload' ),
			'success'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler for Test Connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'r2-stateless-media-offload' ) ),
				403
			);
		}

		$result = Plugin::instance()->client()->test_connection();

		if ( true === $result ) {
			wp_send_json_success(
				array(
					'message' => __( 'Connection successful.', 'r2-stateless-media-offload' ),
				)
			);
		}

		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Connection test failed.', 'r2-stateless-media-offload' );
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * Render the settings page template.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$delete_on_attachment_delete = array_key_exists( 'delete_on_attachment_delete', $stored )
			? (bool) $stored['delete_on_attachment_delete']
			: true;

		$has_secret = $this->has_stored_secret() || ( ! $this->settings->is_constant( 'secret_key' ) && '' !== $this->settings->get( 'secret_key' ) );

		$settings = $this->settings;

		$template = R2OFFLOAD_PLUGIN_DIR . 'templates/settings-page.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * Whether a stored (non-constant) secret exists in the DB option.
	 *
	 * @return bool
	 */
	public function has_stored_secret() {
		$stored = get_option( Settings::OPTION_KEY, array() );
		return is_array( $stored ) && ! empty( $stored['secret_key'] );
	}

}
