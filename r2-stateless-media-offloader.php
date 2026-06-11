<?php
/**
 * Plugin Name:       R2 Stateless Media Offloader
 * Plugin URI:        https://github.com/stealths-labs/r2-stateless-media-offloader
 * Description:       Offload your WordPress media library to Cloudflare R2 — zero egress fees, with a stateless mode for ephemeral/containerised WordPress. A clean-room alternative to wp-stateless built for R2.
 * Version:           0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            wiiiimm
 * Author URI:        https://github.com/wiiiimm
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       r2-stateless-media-offloader
 * Domain Path:       /languages
 *
 * @package R2Offload
 *
 * Created by wiiiimm, shipped by stealths-labs.
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

define( 'R2OFFLOAD_VERSION', '0.3.1' );
define( 'R2OFFLOAD_PLUGIN_FILE', __FILE__ );
define( 'R2OFFLOAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'R2OFFLOAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the R2Offload namespace.
 *
 * Maps R2Offload\Foo_Bar  ->  includes/class-foo-bar.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'R2Offload\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'R2Offload\\' ) );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = R2OFFLOAD_PLUGIN_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * On deactivation, clear the background-migration cron and stop any run in
 * progress so a stale tick can't linger or resume mid-batch.
 */
register_deactivation_hook( __FILE__, array( Migration_Runner::class, 'on_deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		Plugin::instance()->init();
	}
);
