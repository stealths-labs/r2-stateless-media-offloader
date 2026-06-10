<?php
/**
 * Migration page template.
 *
 * Provided by Admin_Migration::render_page():
 *   @var \R2Offload\Settings $settings
 *   @var array               $state
 *
 * @package R2Offload
 */

defined( 'ABSPATH' ) || exit;

$r2offload_configured = $settings->is_configured();

// Authoritative resumability is supplied by render_page() (Migration_Runner::
// is_resumable()); default to false only if a caller forgets. "has run" = a run
// is active or paused, which drives both the buttons and the mode lock.
if ( ! isset( $r2offload_resumable ) ) {
	$r2offload_resumable = false;
}
$r2offload_running = ! empty( $state['running'] );
$r2offload_has_run = $r2offload_running || $r2offload_resumable;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Migrate Media to R2', 'r2-stateless-media-offload' ); ?></h1>

	<p class="description" style="max-width:46em;">
		<?php esc_html_e( 'Copies existing media (original + every size) into R2, reading from a local copy when present or fetching from the current public URL otherwise — so it works whether your media is local, on GCS, or on S3. Runs in the background and survives leaving this page.', 'r2-stateless-media-offload' ); ?>
	</p>
	<p class="description" style="max-width:46em;">
		<?php
		echo wp_kses_post(
			__( 'For very large libraries, the WP-CLI command is recommended: <code>wp r2offload sync</code>.', 'r2-stateless-media-offload' )
		);
		?>
	</p>
	<p class="description" style="max-width:46em;">
		<?php
		echo wp_kses_post(
			__( '<strong>Already copied your media into R2 another way</strong> (e.g. Cloudflare R2 data migration / Super Slurper)? Just run Migrate — files already in R2 are detected and registered without re-uploading.', 'r2-stateless-media-offload' )
		);
		?>
	</p>

	<?php if ( ! $r2offload_configured ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'R2 is not configured yet. <a href="%s">Add your credentials</a> first.', 'r2-stateless-media-offload' ) ),
				esc_url( admin_url( 'options-general.php?page=r2offload-settings' ) )
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
		<div class="notice notice-info"><p>
			<?php
			echo wp_kses_post(
				__( '<strong>WP-Cron is disabled on this site</strong> (<code>DISABLE_WP_CRON</code>). Background batches then run only while this page is open, or via your server’s system cron calling <code>wp-cron.php</code>. If neither applies, the migration will pause when you leave — keep this page open, or run the WP-CLI command <code>wp r2offload sync</code> instead.', 'r2-stateless-media-offload' )
			);
			?>
		</p></div>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="r2offload-mig-mode"><?php esc_html_e( 'Mode', 'r2-stateless-media-offload' ); ?></label></th>
			<td>
				<?php $r2offload_mode = isset( $state['mode'] ) ? (string) $state['mode'] : 'upload'; ?>
				<select id="r2offload-mig-mode" <?php disabled( $r2offload_has_run ); ?>>
					<option value="upload" <?php selected( $r2offload_mode, 'upload' ); ?>><?php esc_html_e( 'Migrate (upload to R2)', 'r2-stateless-media-offload' ); ?></option>
					<option value="force" <?php selected( $r2offload_mode, 'force' ); ?>><?php esc_html_e( 'Force re-upload (replace existing in R2)', 'r2-stateless-media-offload' ); ?></option>
					<option value="dry-run" <?php selected( $r2offload_mode, 'dry-run' ); ?>><?php esc_html_e( 'Dry run (count + size, no upload)', 'r2-stateless-media-offload' ); ?></option>
					<option value="verify" <?php selected( $r2offload_mode, 'verify' ); ?>><?php esc_html_e( 'Verify (check objects exist in R2)', 'r2-stateless-media-offload' ); ?></option>
				</select>
			</td>
		</tr>
	</table>

	<?php
	// Button state machine (mirrored live by the JS); $r2offload_has_run computed
	// up top (a run is active or paused):
	//   Start — enabled only when idle/done (no active or paused run).
	//   Pause — enabled when a run is active or paused; one toggle button labelled
	//           "Pause" while running and "Resume" while paused.
	//   Stop  — terminal (not resumable); enabled when a run is active or paused.
	// Only a paused run resumes; bootstrap the toggle's label AND data-action from
	// the server so an early click (before the first status poll) hits the right
	// endpoint.
	$r2offload_can_resume = $r2offload_resumable && ! $r2offload_running;
	$r2offload_pause_lbl  = $r2offload_can_resume
		? __( 'Resume', 'r2-stateless-media-offload' )
		: __( 'Pause', 'r2-stateless-media-offload' );
	?>
	<p>
		<?php // Not gated on credentials: a dry-run preview (count + size) runs without them, matching `wp r2offload sync --dry-run`. Upload/verify without credentials return a clear error. ?>
		<button type="button" class="button button-primary" id="r2offload-mig-start" <?php disabled( $r2offload_has_run ); ?>>
			<?php esc_html_e( 'Start', 'r2-stateless-media-offload' ); ?>
		</button>
		<button type="button" class="button" id="r2offload-mig-pause" data-action="<?php echo esc_attr( $r2offload_can_resume ? 'resume' : 'pause' ); ?>" <?php disabled( ! $r2offload_has_run ); ?>>
			<?php echo esc_html( $r2offload_pause_lbl ); ?>
		</button>
		<button type="button" class="button" id="r2offload-mig-stop" <?php disabled( ! $r2offload_has_run ); ?>>
			<?php esc_html_e( 'Stop', 'r2-stateless-media-offload' ); ?>
		</button>
	</p>

	<style>
		@keyframes r2offload-stripe {
			from { background-position: 40px 0; }
			to   { background-position: 0 0; }
		}
		#r2offload-mig-bar.r2offload-running {
			background-image: repeating-linear-gradient(
				45deg,
				transparent, transparent 10px,
				rgba(255,255,255,.18) 10px, rgba(255,255,255,.18) 20px
			);
			animation: r2offload-stripe .7s linear infinite;
		}
	</style>
	<div style="max-width:46em;">
		<div style="background:#e2e4e7;border-radius:3px;overflow:hidden;height:24px;">
			<div id="r2offload-mig-bar" style="background-color:#2271b1;color:#fff;height:24px;line-height:24px;text-align:center;width:0;white-space:nowrap;transition:width .3s;">0%</div>
		</div>
		<p id="r2offload-mig-text" aria-live="polite" style="margin-top:.5em;display:flex;align-items:center;gap:4px;">
			<span class="spinner" id="r2offload-mig-spinner" style="float:none;margin:0;"></span>
			<span id="r2offload-mig-text-inner"><?php esc_html_e( 'Idle', 'r2-stateless-media-offload' ); ?></span>
		</p>
		<p id="r2offload-mig-migrated" aria-live="polite" style="margin:.25em 0 0;font-weight:600;display:none;"></p>
		<div id="r2offload-mig-errors" class="notice notice-error inline" style="display:none;margin:.75em 0 0;padding:.5em .75em;"></div>

		<details id="r2offload-mig-log-details" style="margin-top:1em;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'Activity log', 'r2-stateless-media-offload' ); ?></summary>
			<div id="r2offload-mig-log"
				style="margin-top:.5em;height:220px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.7;background:#1d2021;color:#ebdbb2;border-radius:3px;padding:8px 12px;white-space:pre;"
				aria-label="<?php esc_attr_e( 'Migration activity log', 'r2-stateless-media-offload' ); ?>"
			></div>
		</details>

		<details style="margin-top:1em;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'What do these counts mean?', 'r2-stateless-media-offload' ); ?></summary>
			<table class="widefat striped" style="max-width:46em;margin-top:.5em;">
				<tbody>
					<tr>
						<td style="width:8em;"><strong><?php esc_html_e( 'Uploaded', 'r2-stateless-media-offload' ); ?></strong></td>
						<td><?php esc_html_e( 'The file was not in R2 — it was newly copied up.', 'r2-stateless-media-offload' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Updated', 'r2-stateless-media-offload' ); ?></strong></td>
						<td><?php esc_html_e( 'The file was already in R2 but the wrong size (or you chose Force re-upload), so it was replaced.', 'r2-stateless-media-offload' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Adopted', 'r2-stateless-media-offload' ); ?></strong></td>
						<td><?php esc_html_e( 'The correct file was already in R2 (e.g. copied by Cloudflare Super Slurper). No bytes were moved — WordPress was just registered to serve it from R2.', 'r2-stateless-media-offload' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Skipped', 'r2-stateless-media-offload' ); ?></strong></td>
						<td><?php esc_html_e( 'Already in R2 and already registered by a previous run — nothing to do.', 'r2-stateless-media-offload' ); ?></td>
					</tr>
				</tbody>
			</table>
		</details>

		<details style="margin-top:1em;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'How does the background job work?', 'r2-stateless-media-offload' ); ?></summary>
			<div style="max-width:46em;margin-top:.5em;">
				<p><?php esc_html_e( 'Migration runs as a scheduled background task (WP-Cron). You can safely close this tab at any time — the job keeps going on the server and is not tied to your browser session. Come back to this page at any time to check progress; the counts and status reflect the live state of the job.', 'r2-stateless-media-offload' ); ?></p>
				<table class="widefat striped" style="margin-top:.5em;">
					<tbody>
						<tr>
							<td style="width:6em;"><strong><?php esc_html_e( 'Pause', 'r2-stateless-media-offload' ); ?></strong></td>
							<td><?php esc_html_e( 'Stops processing after the current batch finishes and saves your position. Click Resume to continue from exactly where you left off — no work is lost.', 'r2-stateless-media-offload' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Stop', 'r2-stateless-media-offload' ); ?></strong></td>
							<td><?php esc_html_e( 'Cancels the run and discards its position and counters — files already migrated to R2 stay in R2 and stay registered. The next Start scans the library from the beginning; already-migrated items are detected and skipped quickly.', 'r2-stateless-media-offload' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</details>
	</div>
</div>
