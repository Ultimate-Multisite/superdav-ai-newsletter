<?php
/**
 * Per-segment personalization planner.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Personalization;

use SdAiNewsletter\Cache\PersonalizationCache;
use SdAiNewsletter\Core\AiClient;
use SdAiNewsletter\Core\Settings;
use WP_Error;

/**
 * Plans audience segments for per-segment and hybrid personalization modes.
 *
 * Per-segment mode: derives a deterministic segment ID for each recipient from
 * a configurable subset of placeholder keys (default: `country`, `language`).
 * Recipients sharing the same segment tuple share the same AI-generated body.
 * For an audience of 100 split across 3 countries, this is 3 AI calls instead
 * of 100.
 *
 * Hybrid mode: asks the AI once per campaign whether the personalization prompt
 * benefits from per-recipient depth (fall through to `Personalizer`) or whether
 * per-segment is sufficient (run the per-segment path). The decision is cached
 * per (provider × campaign_id × prompt_hash) and never re-billed for the same
 * campaign.
 *
 * The planner never throws inside a filter: every public method either returns
 * the personalized body, the original body, or a WP_Error that the caller
 * (Personalizer) translates into a safe fallback.
 */
final class SegmentPlanner {

	/**
	 * Default placeholder keys used to derive a segment signature when the
	 * `segment_keys` setting is empty. These were chosen because they are
	 * available in every adapter's placeholder map and are the most common
	 * audience-segmentation axes.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_SEGMENT_KEYS = array( 'country', 'language' );

	/**
	 * Hybrid-decision cache key suffix. Stored alongside personalization
	 * results in the same cache group; keyed on a synthetic kind so it cannot
	 * collide with a real content kind (`html` / `text` / `subject`).
	 */
	private const HYBRID_DECISION_KIND = '__hybrid_decision__';

	/**
	 * Per-segment cached body kind suffix. Same rationale as above.
	 */
	private const SEGMENT_BODY_KIND_PREFIX = '__segment__';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * AI Client wrapper.
	 *
	 * @var AiClient
	 */
	private AiClient $ai_client;

	/**
	 * Prompt renderer.
	 *
	 * @var PromptRenderer
	 */
	private PromptRenderer $renderer;

	/**
	 * Personalization cache.
	 *
	 * @var PersonalizationCache
	 */
	private PersonalizationCache $cache;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings  Settings repository.
	 * @param AiClient             $ai_client AI Client wrapper.
	 * @param PromptRenderer       $renderer  Prompt renderer.
	 * @param PersonalizationCache $cache     Personalization cache.
	 */
	public function __construct(
		Settings $settings,
		AiClient $ai_client,
		PromptRenderer $renderer,
		PersonalizationCache $cache
	) {
		$this->settings  = $settings;
		$this->ai_client = $ai_client;
		$this->renderer  = $renderer;
		$this->cache     = $cache;
	}

	/**
	 * Resolve a recipient to its segment-level personalized body.
	 *
	 * The same segment ID across recipients yields a single AI call: the result
	 * is cached on first generation and reused for every subsequent recipient
	 * in the same segment.
	 *
	 * @param string                     $provider      Adapter ID (e.g. "newsletter").
	 * @param int                        $campaign_id   Campaign / email ID.
	 * @param string                     $kind          "html" | "text" | "subject".
	 * @param string                     $body          Original body / subject.
	 * @param array<string, scalar|null> $placeholders  Recipient placeholder values.
	 * @return string The personalized body, or the original on any failure (when fallback enabled).
	 */
	public function personalize_for_segment(
		string $provider,
		int $campaign_id,
		string $kind,
		string $body,
		array $placeholders
	): string {
		if ( '' === trim( $body ) ) {
			return $body;
		}

		if ( ! $this->ai_client->is_available() ) {
			return $body;
		}

		$template = (string) $this->settings->get( 'personalization_prompt', '' );
		if ( '' === trim( $template ) ) {
			return $body;
		}

		$segment_id = $this->segment_id_for( $placeholders );

		// Render the prompt with a *segment representative* placeholder map so
		// that all recipients in the same segment produce the same prompt hash
		// and therefore the same cache key.
		$segment_placeholders = $this->segment_placeholders( $placeholders );
		$rendered_prompt      = $this->renderer->render( $template, $segment_placeholders, $body );
		$prompt_hash          = hash( 'sha256', $rendered_prompt );
		$segment_kind         = self::SEGMENT_BODY_KIND_PREFIX . $kind;
		$cache_key            = $this->cache->key(
			$provider,
			$campaign_id,
			$this->segment_cache_id( $segment_id ),
			$segment_kind,
			$prompt_hash,
		);

		if ( (bool) $this->settings->get( 'cache_enabled', true ) ) {
			$cached = $this->cache->get( $cache_key );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$system_prompt = (string) $this->settings->get( 'system_prompt', '' );
		$result        = $this->ai_client->generate_text( $rendered_prompt, $system_prompt );

		if ( is_wp_error( $result ) ) {
			if ( (bool) $this->settings->get( 'fallback_on_error', true ) ) {
				/**
				 * Fires when AI per-segment personalization falls back to the original body.
				 *
				 * @param \WP_Error $error       The AI Client error.
				 * @param string    $provider    Adapter ID.
				 * @param int       $campaign_id Campaign ID.
				 * @param string    $segment_id  Segment ID that failed.
				 */
				do_action( 'sd_ai_newsletter_segment_personalization_failed', $result, $provider, $campaign_id, $segment_id );
			}
			return $body;
		}

		$personalized = trim( (string) $result );
		if ( '' === $personalized ) {
			return $body;
		}

		if ( (bool) $this->settings->get( 'cache_enabled', true ) ) {
			$this->cache->set( $cache_key, $personalized );
		}

		/**
		 * Filter the final per-segment personalized body before it's returned.
		 *
		 * @param string $personalized The AI-generated body.
		 * @param string $body         The original body.
		 * @param string $provider     Adapter ID.
		 * @param int    $campaign_id  Campaign ID.
		 * @param string $segment_id   Segment ID.
		 */
		return (string) apply_filters(
			'sd_ai_newsletter_segment_personalized_body',
			$personalized,
			$body,
			$provider,
			$campaign_id,
			$segment_id,
		);
	}

	/**
	 * Decide, for hybrid mode, whether to run per-segment or per-recipient
	 * personalization for this campaign.
	 *
	 * The decision is cached per (provider × campaign_id × prompt_hash) so it
	 * costs at most one AI call per campaign. On any error (no AI client,
	 * empty template, AI failure) the function defaults to `false` — i.e. fall
	 * through to per-recipient — which preserves the previous v0.1 behaviour.
	 *
	 * @param string                     $provider     Adapter ID.
	 * @param int                        $campaign_id  Campaign ID.
	 * @param string                     $body         Original body (used as input to the AI decision prompt).
	 * @param array<string, scalar|null> $placeholders A representative placeholder map (any recipient's; only the *keys* matter for the decision).
	 * @return bool True if per-segment is preferred, false to fall through to per-recipient.
	 */
	public function should_segment(
		string $provider,
		int $campaign_id,
		string $body,
		array $placeholders
	): bool {
		if ( ! $this->ai_client->is_available() ) {
			return false;
		}

		$template = (string) $this->settings->get( 'personalization_prompt', '' );
		if ( '' === trim( $template ) ) {
			return false;
		}

		$rendered_prompt = $this->renderer->render( $template, $placeholders, $body );
		$prompt_hash     = hash( 'sha256', $rendered_prompt );
		$cache_key       = $this->cache->key(
			$provider,
			$campaign_id,
			0,
			self::HYBRID_DECISION_KIND,
			$prompt_hash,
		);

		if ( (bool) $this->settings->get( 'cache_enabled', true ) ) {
			$cached = $this->cache->get( $cache_key );
			if ( '1' === $cached ) {
				return true;
			}
			if ( '0' === $cached ) {
				return false;
			}
		}

		$decision_prompt = $this->build_decision_prompt(
			$template,
			array_keys( $placeholders ),
		);
		$decision_system = $this->build_decision_system_prompt();

		$result = $this->ai_client->generate_text( $decision_prompt, $decision_system );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$decision = $this->parse_decision( (string) $result );

		if ( (bool) $this->settings->get( 'cache_enabled', true ) ) {
			$this->cache->set( $cache_key, $decision ? '1' : '0' );
		}

		/**
		 * Filter the hybrid-mode segmentation decision.
		 *
		 * @param bool   $decision    True for per-segment, false for per-recipient.
		 * @param string $provider    Adapter ID.
		 * @param int    $campaign_id Campaign ID.
		 */
		return (bool) apply_filters(
			'sd_ai_newsletter_hybrid_should_segment',
			$decision,
			$provider,
			$campaign_id,
		);
	}

	/**
	 * Derive a deterministic segment ID for a recipient from their placeholders.
	 *
	 * The segment ID is the concatenation of normalized values for the
	 * configured `segment_keys`. Recipients with identical tuples share a
	 * segment; recipients with empty values share an `__unknown__` segment.
	 *
	 * @param array<string, scalar|null> $placeholders Recipient placeholders.
	 * @return string Stable, lowercase segment ID.
	 */
	public function segment_id_for( array $placeholders ): string {
		$keys  = $this->configured_segment_keys();
		$parts = array();
		foreach ( $keys as $key ) {
			$value   = isset( $placeholders[ $key ] ) ? (string) $placeholders[ $key ] : '';
			$value   = strtolower( trim( $value ) );
			$value   = preg_replace( '/[^a-z0-9_-]+/i', '_', $value ) ?? '';
			$parts[] = $key . '=' . ( '' === $value ? '__unknown__' : $value );
		}
		return implode( '|', $parts );
	}

	/**
	 * Build a placeholder map representative of a segment (preserves only the
	 * segment-defining keys; other keys are blanked so they don't influence
	 * the prompt hash and therefore don't fragment the cache).
	 *
	 * @param array<string, scalar|null> $placeholders Recipient placeholders.
	 * @return array<string, scalar|null>
	 */
	private function segment_placeholders( array $placeholders ): array {
		$segment_keys = $this->configured_segment_keys();
		$out          = array();
		foreach ( $placeholders as $key => $value ) {
			if ( in_array( $key, $segment_keys, true ) ) {
				$out[ $key ] = $value;
			} else {
				// Blank non-segment keys so they don't fragment the cache, but
				// keep the keys so `{{first_name|fallback}}` substitutions
				// behave consistently.
				$out[ $key ] = '';
			}
		}
		return $out;
	}

	/**
	 * Map a segment ID string to a non-zero integer for the cache key
	 * `subscriber_id` slot. Cache keys take an int there, so we hash the
	 * segment ID into a stable 31-bit integer.
	 *
	 * @param string $segment_id Segment ID.
	 * @return int
	 */
	private function segment_cache_id( string $segment_id ): int {
		// crc32 returns 32-bit; mask to 31-bit positive int for portability.
		return (int) ( crc32( $segment_id ) & 0x7FFFFFFF );
	}

	/**
	 * The currently configured segment keys, with safe defaults.
	 *
	 * @return array<int, string>
	 */
	private function configured_segment_keys(): array {
		$keys = $this->settings->get( 'segment_keys', self::DEFAULT_SEGMENT_KEYS );
		if ( ! is_array( $keys ) || array() === $keys ) {
			$keys = self::DEFAULT_SEGMENT_KEYS;
		}
		$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );

		/**
		 * Filter the placeholder keys used to derive the segment signature.
		 *
		 * @param array<int, string> $keys Segment keys.
		 */
		return (array) apply_filters( 'sd_ai_newsletter_segment_keys', $keys );
	}

	/**
	 * Build the decision prompt asking the AI whether per-segment is sufficient.
	 *
	 * @param string             $template       The personalization prompt template.
	 * @param array<int, string> $available_keys Placeholder keys present for this audience.
	 * @return string
	 */
	private function build_decision_prompt( string $template, array $available_keys ): string {
		$keys_list = '' === implode( ', ', $available_keys )
			? '(none)'
			: implode( ', ', $available_keys );

		return "You are deciding whether an email-personalization task can be handled with a small number of segment-level rewrites or whether it needs a full per-recipient rewrite for every subscriber.\n\n" .
			"Personalization prompt:\n---\n" . $template . "\n---\n\n" .
			'Placeholder keys available for each recipient: ' . $keys_list . "\n\n" .
			"Reply with the single word PER_SEGMENT if the prompt mostly varies along coarse axes (country, language, audience tier) and a few rewrites would cover the audience well. Reply with the single word PER_RECIPIENT if the prompt depends on per-individual details (first name, free-text fields, days since signup, behaviour) and a per-recipient rewrite is required.\n\n" .
			'Output exactly one of: PER_SEGMENT, PER_RECIPIENT.';
	}

	/**
	 * System prompt for the hybrid decision.
	 *
	 * @return string
	 */
	private function build_decision_system_prompt(): string {
		return 'You are a routing classifier. Output exactly one of two tokens (PER_SEGMENT or PER_RECIPIENT) and nothing else. Do not add explanations.';
	}

	/**
	 * Parse the AI's decision output. Defaults to false (per-recipient) on any
	 * ambiguity to preserve the safer, higher-quality path.
	 *
	 * @param string $raw The raw AI output.
	 * @return bool True if per-segment.
	 */
	private function parse_decision( string $raw ): bool {
		$normalized = strtoupper( trim( $raw ) );
		// Trim trailing punctuation/quotes the model may add.
		$normalized = preg_replace( '/[^A-Z_]/', '', $normalized ) ?? '';
		if ( 'PER_SEGMENT' === $normalized ) {
			return true;
		}
		// Substring fallback: the model may emit a short sentence containing
		// the token. Default to per-recipient if neither token is found.
		if ( false !== strpos( $normalized, 'PER_SEGMENT' ) && false === strpos( $normalized, 'PER_RECIPIENT' ) ) {
			return true;
		}
		return false;
	}
}
