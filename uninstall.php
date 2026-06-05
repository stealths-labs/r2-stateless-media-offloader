<?php
/**
 * Uninstall cleanup for R2 Stateless Media Offload.
 *
 * Runs only when the plugin is DELETED (not on deactivate). Removes the plugin's
 * own options and post-meta so nothing — including the encrypted Secret Access
 * Key — is left behind in the database.
 *
 * IMPORTANT: this NEVER deletes media. It touches only plugin bookkeeping
 * (options + `_r2offload_*` post-meta). The objects already in R2 and any local
 * files are left exactly as they are — uninstalling the plugin must not destroy
 * the user's media library. (If the site was running in Stateless mode, switch
 * back to CDN mode and pull media local again BEFORE deleting the plugin, since
 * without the plugin nothing rewrites URLs to R2.)
 *
 * @package R2Offload
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's options and post-meta for the current site.
 */
function r2offload_uninstall_cleanup_site() {
	// Options: settings (incl. the encrypted secret), migration state, batch lock.
	delete_option( 'r2offload_settings' );
	delete_option( 'r2offload_migration' );
	delete_option( 'r2offload_migration_lock' );

	// Per-attachment bookkeeping. delete_post_meta_by_key removes the key from
	// every post in one query each. This only forgets which R2 object an
	// attachment maps to — it does not delete the object or any file.
	delete_post_meta_by_key( '_r2offload_synced' );
	delete_post_meta_by_key( '_r2offload_synced_at' );
	delete_post_meta_by_key( '_r2offload_key' );
	delete_post_meta_by_key( '_r2offload_objects' );
}

// Options and post-meta are per-site, so on a network clean every site — mirrors
// Migration_Runner::on_deactivate(). number => 0 means "no limit".
if ( is_multisite() ) {
	// On a very large network the synchronous per-site cleanup can approach PHP's
	// max_execution_time. Stop at ~80% of it rather than fataling mid-uninstall:
	// the only cost of an unreached site is harmless leftover options/meta (the
	// plugin is gone either way), which a network admin can mop up via WP-CLI, e.g.
	//   wp site list --field=url | xargs -I% wp option delete r2offload_settings --url=%
	$r2offload_max = (int) ini_get( 'max_execution_time' );
	// Bail at 80% of the limit — floor(max*0.8) is always strictly below the limit
	// for max >= 2. 0 = no limit (WP-CLI), and a 1s limit is too small to deadline
	// meaningfully, so both skip the check (no deadline).
	$r2offload_deadline = ( $r2offload_max >= 2 ) ? time() + (int) floor( $r2offload_max * 0.8 ) : 0;
	$r2offload_bailed   = false;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $r2offload_site_id ) {
		if ( $r2offload_deadline && time() >= $r2offload_deadline ) {
			$r2offload_bailed = true;
			break;
		}
		switch_to_blog( (int) $r2offload_site_id );
		try {
			r2offload_uninstall_cleanup_site();
		} catch ( \Throwable $e ) {
			// Best-effort: a DB error on one site must not abort the whole
			// uninstall — log and move on. restore_current_blog() below still runs.
			error_log( 'r2offload uninstall: cleanup failed for site ' . (int) $r2offload_site_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		restore_current_blog();
	}
	if ( $r2offload_bailed ) {
		error_log( 'r2offload uninstall: approached max_execution_time; some network sites still hold plugin options/meta — clean up via WP-CLI if needed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
} else {
	try {
		r2offload_uninstall_cleanup_site();
	} catch ( \Throwable $e ) {
		// Best-effort: log but don't abort the uninstall.
		error_log( 'r2offload uninstall: cleanup failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
