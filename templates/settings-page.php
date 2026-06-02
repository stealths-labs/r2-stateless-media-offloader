<?php
/**
 * Admin settings page template.
 *
 * Variables available from Admin_Settings::render_page():
 *   $settings                      Settings instance
 *   $stored                        array  Raw option from DB
 *   $delete_on_attachment_delete   bool
 *   $has_secret                    bool   Whether secret_key resolves to a value
 *
 * @package R2Offload
 */

defined( 'ABSPATH' ) || exit;

/** @var Settings $settings */
/** @var array $stored */
/** @var bool $delete_on_attachment_delete */
/** @var bool $has_secret */

$r2_docs_url = 'https://developers.cloudflare.com/r2/api/s3/tokens/';
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( Admin_Settings::PAGE_SLUG ); ?>

	<form method="post" action="">
		<?php wp_nonce_field( Admin_Settings::NONCE_ACTION, Admin_Settings::NONCE_FIELD ); ?>

		<h2 class="title"><?php esc_html_e( 'R2 Credentials', 'r2-stateless-media-offload' ); ?></h2>
		<p class="description">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: opening strong tag, 2: closing strong tag, 3: opening em tag, 4: closing em tag, 5: opening anchor, 6: closing anchor */
					__(
						'Use the %1$sAccess Key ID%2$s and %1$sSecret Access Key%2$s from Cloudflare R2 → Manage R2 API Tokens (Object Read & Write, scoped to your bucket) — NOT the %3$scfat_…%4$s API token. %5$sR2 API token documentation%6$s.',
						'r2-stateless-media-offload'
					),
					'<strong>',
					'</strong>',
					'<em>',
					'</em>',
					'<a href="' . esc_url( $r2_docs_url ) . '" target="_blank" rel="noopener noreferrer">',
					'</a>'
				),
				array(
					'strong' => array(),
					'em'     => array(),
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<?php
			$credential_fields = array(
				'account_id' => array(
					'label'       => __( 'Account ID', 'r2-stateless-media-offload' ),
					'type'        => 'text',
					'description' => __( 'Your Cloudflare account ID (found in the R2 dashboard).', 'r2-stateless-media-offload' ),
				),
				'access_key' => array(
					'label'       => __( 'Access Key ID', 'r2-stateless-media-offload' ),
					'type'        => 'text',
					'description' => '',
				),
				'secret_key' => array(
					'label'       => __( 'Secret Access Key', 'r2-stateless-media-offload' ),
					'type'        => 'password',
					'description' => __( 'Leave blank to keep the current secret.', 'r2-stateless-media-offload' ),
				),
				'bucket'     => array(
					'label'       => __( 'Bucket', 'r2-stateless-media-offload' ),
					'type'        => 'text',
					'description' => __( 'R2 bucket name.', 'r2-stateless-media-offload' ),
				),
			);

			foreach ( $credential_fields as $key => $field ) :
				$is_constant = $settings->is_constant( $key );
				$value       = $settings->get( $key );
				?>
			<tr>
				<th scope="row">
					<label for="r2offload_<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $field['label'] ); ?>
					</label>
				</th>
				<td>
					<?php if ( $is_constant ) : ?>
						<input
							type="<?php echo esc_attr( 'secret_key' === $key ? 'password' : 'text' ); ?>"
							id="r2offload_<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( 'secret_key' === $key ? '' : $value ); ?>"
							class="regular-text"
							<?php if ( 'secret_key' === $key && '' !== $value ) : ?>
								placeholder="<?php esc_attr_e( '•••• set', 'r2-stateless-media-offload' ); ?>"
							<?php endif; ?>
							disabled
						/>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></p>
					<?php elseif ( 'secret_key' === $key ) : ?>
						<input
							type="password"
							name="r2offload_secret_key"
							id="r2offload_secret_key"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( $has_secret ? '•••• set' : '' ); ?>"
						/>
						<?php if ( '' !== $field['description'] ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<input
							type="text"
							name="r2offload_<?php echo esc_attr( $key ); ?>"
							id="r2offload_<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
						/>
						<?php if ( '' !== $field['description'] ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
				<?php
			endforeach;
			?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Delivery', 'r2-stateless-media-offload' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="r2offload_custom_domain"><?php esc_html_e( 'Custom domain', 'r2-stateless-media-offload' ); ?></label>
				</th>
				<td>
					<?php if ( $settings->is_constant( 'custom_domain' ) ) : ?>
						<input type="text" id="r2offload_custom_domain" value="<?php echo esc_attr( $settings->get( 'custom_domain' ) ); ?>" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></p>
					<?php else : ?>
						<input
							type="text"
							name="r2offload_custom_domain"
							id="r2offload_custom_domain"
							value="<?php echo esc_attr( $settings->get( 'custom_domain' ) ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'cdn.example.com', 'r2-stateless-media-offload' ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Optional. Public URL for served media (e.g. cdn-asia.example.org).', 'r2-stateless-media-offload' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="r2offload_cache_control"><?php esc_html_e( 'Cache-Control', 'r2-stateless-media-offload' ); ?></label>
				</th>
				<td>
					<?php if ( $settings->is_constant( 'cache_control' ) ) : ?>
						<input type="text" id="r2offload_cache_control" value="<?php echo esc_attr( $settings->get( 'cache_control' ) ); ?>" class="regular-text" disabled />
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></p>
					<?php else : ?>
						<input
							type="text"
							name="r2offload_cache_control"
							id="r2offload_cache_control"
							value="<?php echo esc_attr( $settings->get( 'cache_control' ) ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'HTTP Cache-Control header for uploaded objects.', 'r2-stateless-media-offload' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'r2-stateless-media-offload' ); ?></th>
				<td>
					<?php if ( $settings->is_constant( 'mode' ) ) : ?>
						<p>
							<strong><?php echo esc_html( 'cdn' === $settings->get( 'mode' ) ? __( 'CDN (keep local)', 'r2-stateless-media-offload' ) : __( 'Stateless (remove local)', 'r2-stateless-media-offload' ) ); ?></strong>
						</p>
						<p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'r2-stateless-media-offload' ); ?></p>
					<?php else : ?>
						<fieldset>
							<label>
								<input
									type="radio"
									name="r2offload_mode"
									value="cdn"
									<?php checked( $settings->get( 'mode' ), 'cdn' ); ?>
								/>
								<?php esc_html_e( 'CDN (keep local)', 'r2-stateless-media-offload' ); ?>
							</label>
							<br />
							<label>
								<input
									type="radio"
									name="r2offload_mode"
									value="stateless"
									<?php checked( $settings->get( 'mode' ), 'stateless' ); ?>
								/>
								<?php esc_html_e( 'Stateless (remove local)', 'r2-stateless-media-offload' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'CDN mode keeps local copies as a safe on-ramp. Stateless removes local files after upload.', 'r2-stateless-media-offload' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete from R2', 'r2-stateless-media-offload' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="r2offload_delete_on_attachment_delete"
							id="r2offload_delete_on_attachment_delete"
							value="1"
							<?php checked( $delete_on_attachment_delete ); ?>
						/>
						<?php esc_html_e( 'Delete from R2 when an attachment is deleted in WordPress', 'r2-stateless-media-offload' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'r2-stateless-media-offload' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Connection', 'r2-stateless-media-offload' ); ?></h2>
	<p>
		<button type="button" class="button button-secondary" id="r2offload-test-connection">
			<?php esc_html_e( 'Test Connection', 'r2-stateless-media-offload' ); ?>
		</button>
	</p>
	<div id="r2offload-test-connection-result" class="notice" style="display:inline-block;padding:6px 12px;margin:0;" aria-live="polite"></div>
</div>
