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

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="r2offload-mig-mode"><?php esc_html_e( 'Mode', 'r2-stateless-media-offload' ); ?></label></th>
			<td>
				<select id="r2offload-mig-mode" <?php disabled( ! empty( $state['running'] ) ); ?>>
					<option value="upload"><?php esc_html_e( 'Migrate (upload to R2)', 'r2-stateless-media-offload' ); ?></option>
					<option value="dry-run"><?php esc_html_e( 'Dry run (count + size, no upload)', 'r2-stateless-media-offload' ); ?></option>
					<option value="verify"><?php esc_html_e( 'Verify (check objects exist in R2)', 'r2-stateless-media-offload' ); ?></option>
				</select>
			</td>
		</tr>
	</table>

	<?php
	$r2offload_resumable = empty( $state['running'] )
		&& empty( $state['finished_at'] )
		&& ( (int) $state['started_at'] > 0 || '' !== (string) $state['cursor'] );
	?>
	<p>
		<button type="button" class="button button-primary" id="r2offload-mig-start" <?php disabled( ! $r2offload_configured || ! empty( $state['running'] ) ); ?>>
			<?php esc_html_e( 'Start', 'r2-stateless-media-offload' ); ?>
		</button>
		<button type="button" class="button" id="r2offload-mig-resume" <?php disabled( ! $r2offload_resumable ); ?> style="<?php echo $r2offload_resumable ? '' : 'display:none;'; ?>">
			<?php esc_html_e( 'Resume', 'r2-stateless-media-offload' ); ?>
		</button>
		<button type="button" class="button" id="r2offload-mig-stop" <?php disabled( empty( $state['running'] ) ); ?>>
			<?php esc_html_e( 'Stop', 'r2-stateless-media-offload' ); ?>
		</button>
	</p>

	<div style="max-width:46em;">
		<div style="background:#e2e4e7;border-radius:3px;overflow:hidden;height:24px;">
			<div id="r2offload-mig-bar" style="background:#2271b1;color:#fff;height:24px;line-height:24px;text-align:center;width:0;white-space:nowrap;transition:width .3s;">0%</div>
		</div>
		<p id="r2offload-mig-text" aria-live="polite" style="margin-top:.5em;">
			<?php esc_html_e( 'Idle', 'r2-stateless-media-offload' ); ?>
		</p>
	</div>
</div>
