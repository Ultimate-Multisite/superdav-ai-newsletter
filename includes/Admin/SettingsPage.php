<?php
/**
 * Admin settings page (per-site).
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Admin;

use SdAiNewsletter\Core\Settings;

/**
 * Renders the "AI Newsletter" admin page under Settings.
 *
 * Per-site only; no network admin surface.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'sd-ai-newsletter';

	/**
	 * Settings group name (Settings API).
	 */
	public const SETTINGS_GROUP = 'sd_ai_newsletter';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the menu entry under Settings.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'AI Newsletter', 'superdav-ai-newsletter' ),
			__( 'AI Newsletter', 'superdav-ai-newsletter' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			),
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$defaults = Settings::defaults();
		$out      = $defaults;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['enabled']             = ! empty( $input['enabled'] );
		$out['cache_enabled']       = ! empty( $input['cache_enabled'] );
		$out['fallback_on_error']   = ! empty( $input['fallback_on_error'] );
		$out['personalize_subject'] = ! empty( $input['personalize_subject'] );
		$out['personalize_body']    = ! empty( $input['personalize_body'] );

		$valid_modes = array(
			Settings::MODE_OFF,
			Settings::MODE_PER_RECIPIENT,
			Settings::MODE_PER_SEGMENT,
			Settings::MODE_HYBRID,
		);
		$mode        = isset( $input['mode'] ) ? (string) $input['mode'] : Settings::MODE_OFF;
		$out['mode'] = in_array( $mode, $valid_modes, true ) ? $mode : Settings::MODE_OFF;

		$out['model']                  = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : '';
		$out['system_prompt']          = isset( $input['system_prompt'] ) ? wp_kses_post( (string) $input['system_prompt'] ) : $defaults['system_prompt'];
		$out['personalization_prompt'] = isset( $input['personalization_prompt'] ) ? wp_kses_post( (string) $input['personalization_prompt'] ) : $defaults['personalization_prompt'];
		$out['max_output_tokens']      = isset( $input['max_output_tokens'] ) ? max( 64, min( 8192, (int) $input['max_output_tokens'] ) ) : 1024;

		// segment_keys: comma-separated string in the form, normalized to an array of slugs.
		if ( isset( $input['segment_keys'] ) ) {
			$raw                 = is_array( $input['segment_keys'] )
				? implode( ',', array_map( 'strval', $input['segment_keys'] ) )
				: (string) $input['segment_keys'];
			$keys                = array_filter(
				array_map(
					static fn( string $k ): string => preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( $k ) ) ) ?? '',
					explode( ',', $raw ),
				),
				static fn( string $k ): bool => '' !== $k,
			);
			$out['segment_keys'] = array() === $keys ? $defaults['segment_keys'] : array_values( $keys );
		}

		return $out;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$values    = $this->settings->all();
		$ai_ready  = function_exists( 'wp_ai_client_prompt' );
		$nl_active = defined( 'NEWSLETTER_VERSION' ) || function_exists( 'tnp' );
		$fc_active = defined( 'FLUENTCRM' ) || defined( 'FLUENTCRM_PLUGIN_VERSION' ) || function_exists( 'FluentCrmApi' );
		$gh_active = defined( 'GROUNDHOGG_VERSION' ) || class_exists( '\\Groundhogg\\Email' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Newsletter', 'superdav-ai-newsletter' ); ?></h1>

			<p><?php esc_html_e( 'Per-recipient and per-segment AI personalization for self-hosted WordPress newsletter plugins.', 'superdav-ai-newsletter' ); ?></p>

			<table class="widefat" style="max-width:720px;margin-bottom:1em;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'WordPress AI Client', 'superdav-ai-newsletter' ); ?></th>
						<td>
							<?php if ( $ai_ready ) : ?>
								<span style="color:#2271b1;">&#x2714;&nbsp;<?php esc_html_e( 'Available', 'superdav-ai-newsletter' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;">&#x2718;&nbsp;<?php esc_html_e( 'Unavailable. Requires WordPress 7.0+ with at least one AI connector configured under Settings &rarr; Connectors.', 'superdav-ai-newsletter' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Newsletter plugin', 'superdav-ai-newsletter' ); ?></th>
						<td>
							<?php if ( $nl_active ) : ?>
								<span style="color:#2271b1;">&#x2714;&nbsp;<?php esc_html_e( 'Active. Per-recipient hooks will fire.', 'superdav-ai-newsletter' ); ?></span>
							<?php else : ?>
								<span style="color:#646970;">&#x2014;&nbsp;<?php esc_html_e( 'Not active.', 'superdav-ai-newsletter' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'FluentCRM', 'superdav-ai-newsletter' ); ?></th>
						<td>
							<?php if ( $fc_active ) : ?>
								<span style="color:#2271b1;">&#x2714;&nbsp;<?php esc_html_e( 'Active. Per-recipient hooks will fire.', 'superdav-ai-newsletter' ); ?></span>
							<?php else : ?>
								<span style="color:#646970;">&#x2014;&nbsp;<?php esc_html_e( 'Not active.', 'superdav-ai-newsletter' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Groundhogg', 'superdav-ai-newsletter' ); ?></th>
						<td>
							<?php if ( $gh_active ) : ?>
								<span style="color:#2271b1;">&#x2714;&nbsp;<?php esc_html_e( 'Active. Per-recipient hooks will fire.', 'superdav-ai-newsletter' ); ?></span>
							<?php else : ?>
								<span style="color:#646970;">&#x2014;&nbsp;<?php esc_html_e( 'Not active.', 'superdav-ai-newsletter' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! $nl_active && ! $fc_active && ! $gh_active ) : ?>
						<tr>
							<th><?php esc_html_e( 'No host newsletter plugin detected', 'superdav-ai-newsletter' ); ?></th>
							<td>
								<span style="color:#b32d2e;">&#x2718;&nbsp;<?php esc_html_e( 'Install one of: Newsletter (by Stefano Lissa), FluentCRM, or Groundhogg to enable AI personalization.', 'superdav-ai-newsletter' ); ?></span>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form action="options.php" method="post">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-enabled"><?php esc_html_e( 'Enable AI personalization', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<input type="checkbox" id="sd-ai-newsletter-enabled" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $values['enabled'] ) ); ?> />
								<p class="description"><?php esc_html_e( 'Master on/off switch. When off, all messages pass through unchanged.', 'superdav-ai-newsletter' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-mode"><?php esc_html_e( 'Personalization mode', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<select id="sd-ai-newsletter-mode" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[mode]">
									<option value="<?php echo esc_attr( Settings::MODE_OFF ); ?>" <?php selected( $values['mode'], Settings::MODE_OFF ); ?>><?php esc_html_e( 'Off (passthrough)', 'superdav-ai-newsletter' ); ?></option>
									<option value="<?php echo esc_attr( Settings::MODE_PER_RECIPIENT ); ?>" <?php selected( $values['mode'], Settings::MODE_PER_RECIPIENT ); ?>><?php esc_html_e( 'Per-recipient (one AI call per subscriber, highest impact)', 'superdav-ai-newsletter' ); ?></option>
									<option value="<?php echo esc_attr( Settings::MODE_PER_SEGMENT ); ?>" <?php selected( $values['mode'], Settings::MODE_PER_SEGMENT ); ?>><?php esc_html_e( 'Per-segment (one AI call per segment, cheapest)', 'superdav-ai-newsletter' ); ?></option>
									<option value="<?php echo esc_attr( Settings::MODE_HYBRID ); ?>" <?php selected( $values['mode'], Settings::MODE_HYBRID ); ?>><?php esc_html_e( 'Hybrid — AI decides segments from the prompt and audience', 'superdav-ai-newsletter' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Per-segment groups recipients by country and language by default and reuses one AI rewrite per segment. Hybrid asks the AI once per campaign whether per-segment is sufficient or per-recipient is required.', 'superdav-ai-newsletter' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-segment-keys"><?php esc_html_e( 'Segment keys', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<?php
								$segment_keys = isset( $values['segment_keys'] ) && is_array( $values['segment_keys'] )
									? $values['segment_keys']
									: array();
								?>
								<input type="text" id="sd-ai-newsletter-segment-keys" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[segment_keys]" value="<?php echo esc_attr( implode( ',', $segment_keys ) ); ?>" class="regular-text" placeholder="country,language" />
								<p class="description"><?php esc_html_e( 'Comma-separated placeholder keys used to derive a segment for each recipient (per-segment and hybrid modes only). Defaults to country,language.', 'superdav-ai-newsletter' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-model"><?php esc_html_e( 'Model', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<input type="text" id="sd-ai-newsletter-model" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[model]" value="<?php echo esc_attr( (string) $values['model'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( SD_AI_NEWSLETTER_DEFAULT_MODEL ); ?>" />
								<p class="description">
								<?php
									printf(
										/* translators: %s: default model id constant */
										esc_html__( 'Model ID to send to the WordPress AI Client. Leave empty to use the default (%s) or a connector-provided default.', 'superdav-ai-newsletter' ),
										'<code>' . esc_html( SD_AI_NEWSLETTER_DEFAULT_MODEL ) . '</code>',
									);
								?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'What to personalize', 'superdav-ai-newsletter' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[personalize_subject]" value="1" <?php checked( ! empty( $values['personalize_subject'] ) ); ?> /> <?php esc_html_e( 'Subject line', 'superdav-ai-newsletter' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[personalize_body]" value="1" <?php checked( ! empty( $values['personalize_body'] ) ); ?> /> <?php esc_html_e( 'Body (HTML and text)', 'superdav-ai-newsletter' ); ?></label>
								<p class="description"><?php esc_html_e( 'Subject-line-only personalization captures most of the impact at a fraction of the cost.', 'superdav-ai-newsletter' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-system-prompt"><?php esc_html_e( 'System prompt', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<textarea id="sd-ai-newsletter-system-prompt" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[system_prompt]" rows="4" class="large-text code"><?php echo esc_textarea( (string) $values['system_prompt'] ); ?></textarea>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-prompt"><?php esc_html_e( 'Personalization prompt', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<textarea id="sd-ai-newsletter-prompt" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[personalization_prompt]" rows="10" class="large-text code"><?php echo esc_textarea( (string) $values['personalization_prompt'] ); ?></textarea>
								<p class="description">
								<?php
									printf(
										/* translators: %s: list of placeholder keys */
										esc_html__( 'Available placeholders: %1$s. The %2$s placeholder is replaced with the original email body.', 'superdav-ai-newsletter' ),
										'<code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{email}}</code>, <code>{{country}}</code>, <code>{{language}}</code>, <code>{{days_since_signup}}</code>, <code>{{campaign_subject}}</code>, <code>{{campaign_id}}</code>',
										'<code>{{body}}</code>',
									);
								?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="sd-ai-newsletter-max-tokens"><?php esc_html_e( 'Max output tokens', 'superdav-ai-newsletter' ); ?></label></th>
							<td>
								<input type="number" id="sd-ai-newsletter-max-tokens" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[max_output_tokens]" value="<?php echo esc_attr( (string) $values['max_output_tokens'] ); ?>" min="64" max="8192" step="64" />
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Reliability', 'superdav-ai-newsletter' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[cache_enabled]" value="1" <?php checked( ! empty( $values['cache_enabled'] ) ); ?> /> <?php esc_html_e( 'Cache personalized bodies (subscriber × campaign × prompt)', 'superdav-ai-newsletter' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[fallback_on_error]" value="1" <?php checked( ! empty( $values['fallback_on_error'] ) ); ?> /> <?php esc_html_e( 'On AI failure, fall back to the original (non-personalized) body', 'superdav-ai-newsletter' ); ?></label>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
