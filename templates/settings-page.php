<?php
/**
 * Settings page template.
 *
 * Included by Admin_Settings::render_page(), which provides:
 *   @var \R2Offload\Settings $settings
 *   @var string $page          Page slug.
 *   @var string $nonce_action
 *   @var string $nonce_field
 *
 * This is a template, not a class file — it intentionally has no namespace
 * declaration (PHP `include` does not inherit the caller's namespace).
 *
 * @package R2Offload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render one text/password field with dual-credential (constant-lock) support.
 *
 * @param \R2Offload\Settings $settings
 * @param string              $key
 * @param string              $label
 * @param string              $description
 * @param bool                $is_secret
 */
$r2offload_field = static function ( $settings, $key, $label, $description = '', $is_secret = false ) {
	$locked   = $settings->is_constant( $key );
	$value    = $settings->get( $key );
	$has_value = ( '' !== $value );
	$id       = 'r2offload_' . $key;
	?>
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<?php if ( $is_secret ) : ?>
				<input type="password" name="secret_key" id="<?php echo esc_attr( $id ); ?>"
					class="regular-text" autocomplete="new-password"
					placeholder="<?php echo $has_value ? esc_attr__( '•••••••• (set — leave blank to keep)', 'r2-stateless-media-offload' ) : ''; ?>"
					<?php disabled( $locked ); ?> />
			<?php else : ?>
				<input type="text" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $id ); ?>"
					class="regular-text" value="<?php echo esc_attr( $value ); ?>"
					<?php disabled( $locked ); ?> />
			<?php endif; ?>

			<?php if ( $locked ) : ?>
				<p class="description"><em><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></em></p>
			<?php elseif ( '' !== $description ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};

$r2offload_mode   = $settings->get( 'mode' );
$r2offload_locked_mode = $settings->is_constant( 'mode' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'R2 Stateless Media Offload', 'r2-stateless-media-offload' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'r2-stateless-media-offload' ); ?></p></div>
	<?php endif; ?>

	<form id="r2offload-settings-form" method="post" action="">
		<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>

		<h2 class="title"><?php esc_html_e( 'Cloudflare R2 Credentials', 'r2-stateless-media-offload' ); ?></h2>
		<p class="description">
			<?php
			echo wp_kses_post(
				__( 'Use the <strong>Access Key ID</strong> and <strong>Secret Access Key</strong> from Cloudflare R2 → <em>Manage R2 API Tokens</em> (Object Read &amp; Write, scoped to your bucket) — <strong>not</strong> the <code>cfat_…</code> API token. <a href="https://developers.cloudflare.com/r2/api/tokens/" target="_blank" rel="noopener noreferrer">Token docs ↗</a>', 'r2-stateless-media-offload' )
			);
			?>
		</p>
		<table class="form-table" role="presentation">
			<?php
			$r2offload_field( $settings, 'account_id', __( 'Account ID', 'r2-stateless-media-offload' ), __( 'Your Cloudflare account ID (also the subdomain of your R2 S3 endpoint).', 'r2-stateless-media-offload' ) );
			$r2offload_field( $settings, 'access_key', __( 'Access Key ID', 'r2-stateless-media-offload' ) );
			$r2offload_field( $settings, 'secret_key', __( 'Secret Access Key', 'r2-stateless-media-offload' ), '', true );
			$r2offload_field( $settings, 'bucket', __( 'Bucket', 'r2-stateless-media-offload' ), __( 'The R2 bucket name (create it first — the token cannot create buckets).', 'r2-stateless-media-offload' ) );
			?>
		</table>

		<p>
			<button type="button" class="button" id="r2offload-test-connection"><?php esc_html_e( 'Test Connection', 'r2-stateless-media-offload' ); ?></button>
			<span id="r2offload-test-result" class="notice inline" style="display:none;margin:0;" role="status" aria-live="polite"></span>
		</p>

		<h2 class="title"><?php esc_html_e( 'Delivery', 'r2-stateless-media-offload' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$r2offload_field( $settings, 'custom_domain', __( 'Custom Domain', 'r2-stateless-media-offload' ), __( 'e.g. <code>cdn.example.com</code> — set up under R2 → Custom Domains. Leave blank to serve from the R2 endpoint.', 'r2-stateless-media-offload' ) );
			$r2offload_field( $settings, 'cache_control', __( 'Cache-Control', 'r2-stateless-media-offload' ), __( 'Sent with each object. Default: <code>public, max-age=31536000</code> (1 year).', 'r2-stateless-media-offload' ) );
			$r2offload_field( $settings, 'path_prefix', __( 'Path Prefix', 'r2-stateless-media-offload' ), __( 'Object key prefix in R2, e.g. <code>uploads/</code>. Changing this only affects new uploads; existing media keeps its stored key.', 'r2-stateless-media-offload' ) );
			?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Mode', 'r2-stateless-media-offload' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Offload Mode', 'r2-stateless-media-offload' ); ?></th>
				<td>
					<fieldset <?php disabled( $r2offload_locked_mode ); ?>>
						<label>
							<input type="radio" name="mode" value="cdn" <?php checked( $r2offload_mode, 'cdn' ); ?> />
							<?php esc_html_e( 'CDN — keep local copies as a fallback (safe on-ramp)', 'r2-stateless-media-offload' ); ?>
						</label><br />
						<label>
							<input type="radio" name="mode" value="stateless" <?php checked( $r2offload_mode, 'stateless' ); ?> />
							<?php esc_html_e( 'Stateless — remove local copies; media lives only in R2', 'r2-stateless-media-offload' ); ?>
						</label>
					</fieldset>
					<?php if ( $r2offload_locked_mode ) : ?>
						<p class="description"><em><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></em></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Start in CDN mode, verify media serves from R2, then switch to Stateless.', 'r2-stateless-media-offload' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
