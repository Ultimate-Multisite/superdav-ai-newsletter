<?php
/**
 * Plugin settings repository.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Core;

/**
 * Per-site settings stored in the wp_options table.
 *
 * All settings are per-site by design. The plugin is NOT a network addon.
 */
final class Settings {

	/**
	 * Option name for plugin settings.
	 */
	public const OPTION_NAME = 'sd_ai_newsletter_settings';

	/**
	 * Personalization mode: per-recipient AI rewrite.
	 */
	public const MODE_PER_RECIPIENT = 'per_recipient';

	/**
	 * Personalization mode: AI per-segment (one call per segment, used for all recipients in segment).
	 */
	public const MODE_PER_SEGMENT = 'per_segment';

	/**
	 * Personalization mode: hybrid — AI decides whether to segment or per-recipient based on the prompt and audience.
	 */
	public const MODE_HYBRID = 'hybrid';

	/**
	 * Personalization mode: off (passthrough).
	 */
	public const MODE_OFF = 'off';

	/**
	 * Cached settings array.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Get the default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'                => false,
			'mode'                   => self::MODE_OFF,
			'model'                  => '',
			'system_prompt'          => self::default_system_prompt(),
			'personalization_prompt' => self::default_personalization_prompt(),
			'max_output_tokens'      => 1024,
			'cache_enabled'          => true,
			'fallback_on_error'      => true,
			'personalize_subject'    => true,
			'personalize_body'       => true,
			'allowed_placeholders'   => [
				'first_name',
				'last_name',
				'email',
				'country',
				'language',
				'days_since_signup',
			],
			'segment_keys'           => [
				'country',
				'language',
			],
		];
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_NAME, [] );
			$this->cache = wp_parse_args(
				is_array( $stored ) ? $stored : [],
				self::defaults(),
			);
		}
		return $this->cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array<string, mixed> $values Partial values to merge.
	 * @return bool True on success.
	 */
	public function update( array $values ): bool {
		$current     = $this->all();
		$merged      = array_merge( $current, $values );
		$this->cache = $merged;
		return (bool) update_option( self::OPTION_NAME, $merged );
	}

	/**
	 * Whether AI personalization is enabled for this site.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled', false )
			&& self::MODE_OFF !== $this->get( 'mode', self::MODE_OFF );
	}

	/**
	 * The currently selected personalization mode.
	 *
	 * @return string One of self::MODE_*
	 */
	public function mode(): string {
		$mode = (string) $this->get( 'mode', self::MODE_OFF );
		return in_array(
			$mode,
			[ self::MODE_PER_RECIPIENT, self::MODE_PER_SEGMENT, self::MODE_HYBRID, self::MODE_OFF ],
			true,
		) ? $mode : self::MODE_OFF;
	}

	/**
	 * The configured model ID. Falls back to the global default constant.
	 *
	 * @return string
	 */
	public function model(): string {
		$model = trim( (string) $this->get( 'model', '' ) );
		if ( '' === $model ) {
			$model = (string) apply_filters(
				'sd_ai_newsletter_default_model',
				SD_AI_NEWSLETTER_DEFAULT_MODEL,
			);
		}
		return $model;
	}

	/**
	 * Default system prompt.
	 *
	 * @return string
	 */
	private static function default_system_prompt(): string {
		return 'You are an expert email personalization assistant. Your job is to subtly rewrite a marketing or transactional email so it speaks more directly to the specific recipient described in the user message. Preserve all links, calls to action, unsubscribe footers, and structural HTML. Do not invent facts about the recipient. Keep the same approximate length. Output only the rewritten email body.';
	}

	/**
	 * Default per-recipient personalization prompt.
	 *
	 * @return string
	 */
	private static function default_personalization_prompt(): string {
		return "Rewrite the email below for the following recipient.\n\nRecipient:\n- Name: {{first_name}} {{last_name}}\n- Country: {{country}}\n- Language: {{language}}\n- Days since signup: {{days_since_signup}}\n\nKeep it the same approximate length. Preserve every link, the unsubscribe footer, and all HTML structure. Output only the rewritten body.\n\nEmail:\n{{body}}";
	}
}
