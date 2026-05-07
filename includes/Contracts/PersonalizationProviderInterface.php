<?php
/**
 * Adapter interface for newsletter-plugin AI personalization providers.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Contracts;

/**
 * Adapter contract: each supported newsletter plugin (Newsletter, FluentCRM,
 * Groundhogg, …) implements this interface so the AI personalization core can
 * register hooks against that plugin's send pipeline.
 *
 * Implementations are responsible for:
 *  - Detecting whether the underlying newsletter plugin is active.
 *  - Registering the plugin-specific filters/actions that fire per recipient
 *    (or per campaign for per-segment mode).
 *  - Translating the plugin's own user/campaign objects into the generic
 *    placeholder map used by the personalization prompt template.
 */
interface PersonalizationProviderInterface {

	/**
	 * Stable machine ID for this provider (e.g. "newsletter", "fluentcrm").
	 *
	 * Used in cache keys and the admin "supported plugins" list.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label for this provider (e.g. "Newsletter (Stefano Lissa)").
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the underlying newsletter plugin is active on this site.
	 *
	 * Adapters MUST short-circuit hook registration when this returns false.
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Register all hooks needed for this adapter to participate in the send loop.
	 *
	 * Called once on `plugins_loaded`. Implementations should `return` early
	 * if `is_active()` is false.
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * Build the placeholder map for a single recipient.
	 *
	 * Returned keys correspond to the placeholders allowed in the
	 * personalization prompt template (e.g. {{first_name}}, {{country}},
	 * {{days_since_signup}}). Adapters may add provider-specific keys.
	 *
	 * @param object $user  Plugin-specific user/subscriber object.
	 * @param object $email Plugin-specific email/campaign object.
	 * @return array<string, scalar|null>
	 */
	public function build_placeholders( object $user, object $email ): array;
}
