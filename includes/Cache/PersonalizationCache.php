<?php
/**
 * Per-recipient × per-campaign personalization result cache.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Cache;

/**
 * Caches AI-generated personalized bodies keyed on (provider, campaign_id,
 * subscriber_id, content_kind, prompt_hash).
 *
 * Backed by the WordPress object cache (group `sd_ai_newsletter`) with a
 * 7-day TTL by default, and falls back to transients when persistent object
 * caching is not available — relevant for resends so the same recipient is
 * not billed twice for identical inputs.
 */
final class PersonalizationCache {

	/**
	 * Cache group name (used by both wp_cache_* and transient namespacing).
	 */
	public const GROUP = 'sd_ai_newsletter';

	/**
	 * Default TTL (7 days).
	 */
	public const TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Build a deterministic cache key.
	 *
	 * @param string $provider     Adapter ID (e.g. "newsletter").
	 * @param int    $campaign_id  Campaign / email ID.
	 * @param int    $subscriber_id Subscriber / user ID.
	 * @param string $kind         Content kind (e.g. "html", "text", "subject").
	 * @param string $prompt_hash  Hash of the rendered prompt input.
	 * @return string
	 */
	public function key( string $provider, int $campaign_id, int $subscriber_id, string $kind, string $prompt_hash ): string {
		return sprintf(
			'%s:%d:%d:%s:%s',
			$provider,
			$campaign_id,
			$subscriber_id,
			$kind,
			$prompt_hash,
		);
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return string|null Cached body, or null on miss.
	 */
	public function get( string $key ): ?string {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && is_string( $value ) ) {
			return $value;
		}

		$transient = get_transient( self::GROUP . '_' . md5( $key ) );
		if ( is_string( $transient ) ) {
			return $transient;
		}

		return null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Cache key.
	 * @param string $value Body to cache.
	 * @param int    $ttl   TTL in seconds (default: 7 days).
	 * @return void
	 */
	public function set( string $key, string $value, int $ttl = self::TTL ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
		set_transient( self::GROUP . '_' . md5( $key ), $value, $ttl );
	}
}
