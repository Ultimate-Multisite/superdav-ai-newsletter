<?php
/**
 * Per-recipient personalization orchestrator.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Personalization;

use SdAiNewsletter\Cache\PersonalizationCache;
use SdAiNewsletter\Core\AiClient;
use SdAiNewsletter\Core\Settings;

/**
 * Combines settings, prompt rendering, AI Client invocation, and caching to
 * produce a personalized email body for a single recipient.
 *
 * Every adapter calls this single entry point once per recipient. Branching
 * on `Settings::mode()` happens here:
 *  - MODE_PER_RECIPIENT : one AI call per (campaign × subscriber × prompt).
 *  - MODE_PER_SEGMENT   : delegate to SegmentPlanner; one AI call per
 *                         (campaign × segment × prompt), reused for every
 *                         recipient in that segment.
 *  - MODE_HYBRID        : ask SegmentPlanner::should_segment() once per
 *                         campaign; if true, run the per-segment path,
 *                         otherwise fall through to per-recipient.
 *
 * On any failure (no AI client, empty template, AI error) the original body
 * is returned — adapters never need their own try/catch.
 */
final class Personalizer {

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
	 * Per-segment planner. Lazily constructed.
	 *
	 * @var SegmentPlanner|null
	 */
	private ?SegmentPlanner $segment_planner;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings        Settings repository.
	 * @param AiClient             $ai_client       AI Client wrapper.
	 * @param PromptRenderer       $renderer        Prompt renderer.
	 * @param PersonalizationCache $cache           Personalization cache.
	 * @param SegmentPlanner|null  $segment_planner Optional segment planner (defaults to a fresh instance).
	 */
	public function __construct(
		Settings $settings,
		AiClient $ai_client,
		PromptRenderer $renderer,
		PersonalizationCache $cache,
		?SegmentPlanner $segment_planner = null
	) {
		$this->settings        = $settings;
		$this->ai_client       = $ai_client;
		$this->renderer        = $renderer;
		$this->cache           = $cache;
		$this->segment_planner = $segment_planner;
	}

	/**
	 * Lazily resolve the segment planner.
	 *
	 * @return SegmentPlanner
	 */
	private function segment_planner(): SegmentPlanner {
		if ( null === $this->segment_planner ) {
			$this->segment_planner = new SegmentPlanner(
				$this->settings,
				$this->ai_client,
				$this->renderer,
				$this->cache,
			);
		}
		return $this->segment_planner;
	}

	/**
	 * Personalize a body for a single recipient.
	 *
	 * @param string                     $provider      Adapter ID (e.g. "newsletter").
	 * @param int                        $campaign_id   Campaign / email ID.
	 * @param int                        $subscriber_id Subscriber / user ID.
	 * @param string                     $kind          "html" | "text" | "subject".
	 * @param string                     $body          Original body / subject.
	 * @param array<string, scalar|null> $placeholders  Recipient placeholder values.
	 * @return string The personalized body, or the original on any failure (when fallback enabled).
	 */
	public function personalize(
		string $provider,
		int $campaign_id,
		int $subscriber_id,
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

		$mode = $this->settings->mode();

		if ( Settings::MODE_PER_SEGMENT === $mode ) {
			return $this->segment_planner()->personalize_for_segment(
				$provider,
				$campaign_id,
				$kind,
				$body,
				$placeholders,
			);
		}

		if ( Settings::MODE_HYBRID === $mode
			&& $this->segment_planner()->should_segment( $provider, $campaign_id, $body, $placeholders )
		) {
			return $this->segment_planner()->personalize_for_segment(
				$provider,
				$campaign_id,
				$kind,
				$body,
				$placeholders,
			);
		}

		// Per-recipient (and hybrid-fallthrough) path.
		$rendered_prompt = $this->renderer->render( $template, $placeholders, $body );
		$prompt_hash     = hash( 'sha256', $rendered_prompt );
		$cache_key       = $this->cache->key( $provider, $campaign_id, $subscriber_id, $kind, $prompt_hash );

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
				 * Fires when AI personalization falls back to the original body.
				 *
				 * @param \WP_Error $error  The AI Client error.
				 * @param string    $provider     Adapter ID.
				 * @param int       $campaign_id  Campaign ID.
				 * @param int       $subscriber_id Subscriber ID.
				 */
				do_action( 'sd_ai_newsletter_personalization_failed', $result, $provider, $campaign_id, $subscriber_id );
				return $body;
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
		 * Filter the final personalized body before it's returned.
		 *
		 * @param string $personalized The AI-generated body.
		 * @param string $body         The original body.
		 * @param string $provider     Adapter ID.
		 * @param int    $campaign_id  Campaign ID.
		 * @param int    $subscriber_id Subscriber ID.
		 */
		return (string) apply_filters(
			'sd_ai_newsletter_personalized_body',
			$personalized,
			$body,
			$provider,
			$campaign_id,
			$subscriber_id,
		);
	}
}
