<?php
/**
 * Main plugin orchestrator.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	private $settings;

	/** @var R2_Client */
	private $client;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Initialise components. Called on plugins_loaded.
	 */
	public function init() {
		$this->settings = new Settings();
		$this->client   = new R2_Client( $this->settings );

		// Admin UI.
		if ( is_admin() ) {
			$this->settings->register();
			add_action( 'admin_notices', array( $this, 'maybe_warn_no_public_domain' ) );
		}

		// Offload new uploads to R2 (original + all sizes).
		$offloader = new Offloader( $this->client, $this->settings );
		$offloader->register();

		// Serve offloaded media from R2 / the custom domain (render-time).
		$rewriter = new URL_Rewriter( $this->client, $this->settings );
		$rewriter->register();

		// Stateless read path: restore files from R2 on demand for image ops.
		$fallback = new Local_Fallback( $this->client, $this->settings );
		$fallback->register();

		// Multisite: these objects live for the whole request and memoise
		// per-site state (settings, and attachment-ID-keyed key caches). A
		// request that switch_to_blog()s must not keep resolving against the
		// previous site, so drop those caches whenever the active blog changes.
		add_action( 'switch_blog', array( $this->settings, 'flush_request_cache' ) );
		add_action( 'switch_blog', array( $rewriter, 'flush_request_cache' ) );
		add_action( 'switch_blog', array( $fallback, 'flush_request_cache' ) );
		// The offloader's per-request dedupe is keyed by attachment ID, which is
		// not unique across a network — flush it too so a cached ID from one site
		// can't suppress a legitimate upload of a same-ID attachment on another.
		add_action( 'switch_blog', array( $offloader, 'flush_request_cache' ) );

		// Background migration runner (cron-driven) + admin UI.
		$runner = new Migration_Runner( $this->settings );
		$runner->register();
		if ( is_admin() ) {
			( new Admin_Migration( $this->settings, $runner ) )->register();
		}

		// WP-CLI commands (loads its own guard).
		require_once R2OFFLOAD_PLUGIN_DIR . 'includes/class-cli.php';
	}

	/**
	 * Warn when R2 is configured but no custom domain is set: media can't be
	 * served publicly from R2 (the S3 API endpoint requires auth), so URL
	 * rewriting stays off — and in Stateless mode, where local copies are
	 * removed, media would be unreachable.
	 */
	public function maybe_warn_no_public_domain() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// A stored secret that no longer decrypts (auth salt rotated) makes the
		// plugin behave as unconfigured — say so explicitly instead of leaving
		// the admin to guess from upload failures.
		if ( $this->settings->secret_decrypt_failed() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'R2 Media Offload: the stored Secret Access Key could not be decrypted (the site\'s security keys may have changed). Re-enter it in Settings → R2 Offload.', 'r2-stateless-media-offload' )
			);
			return;
		}
		if ( ! $this->settings->is_configured() || $this->settings->serves_public_url() ) {
			return;
		}
		$stateless = 'stateless' === $this->settings->get( 'mode' );
		$msg       = $stateless
			? __( 'R2 Media Offload: no Custom Domain is set. In Stateless mode media is served only from R2, which needs a public custom domain — offloaded media will not load until you set one.', 'r2-stateless-media-offload' )
			: __( 'R2 Media Offload: no Custom Domain is set, so media is still served from this server. Add a Cloudflare custom domain to serve from R2.', 'r2-stateless-media-offload' );
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $stateless ? 'error' : 'warning' ),
			esc_html( $msg )
		);
	}

	/**
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * @return R2_Client
	 */
	public function client() {
		return $this->client;
	}
}
