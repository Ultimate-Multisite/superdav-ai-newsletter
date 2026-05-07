<?php
/**
 * FluentCRM adapter.
 *
 * Hooks into FluentCRM's per-recipient email pipeline to perform AI
 * personalization just before each email is handed to the underlying mailer.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Adapters\FluentCRM;

use SdAiNewsletter\Cache\PersonalizationCache;
use SdAiNewsletter\Contracts\PersonalizationProviderInterface;
use SdAiNewsletter\Core\AiClient;
use SdAiNewsletter\Core\Settings;
use SdAiNewsletter\Personalization\Personalizer;
use SdAiNewsletter\Personalization\PromptRenderer;

/**
 * FluentCRM plugin adapter.
 *
 * Filters used (verified against FluentCRM's developer-docs filter index):
 *  - fluent_crm/email_data_before_headers — fires once per recipient just
 *    before email headers are built. Provides `$data` (subject, body, from,
 *    etc.), `$subscriber`, and `$emailModel` (CampaignEmail). This is where
 *    we rewrite the subject and HTML body for each recipient.
 *  - fluentcrm_email_body_text — last chance to rewrite the body just before
 *    click-tracking URLs are injected. We intentionally use the earlier
 *    `email_data_before_headers` filter for body rewriting too, so that the
 *    AI's output flows through FluentCRM's normal click-tracking pipeline.
 *    `fluentcrm_email_body_text` is wired here for sites whose mail flow
 *    bypasses `email_data_before_headers` (rare).
 *
 * Sources:
 *  - app/Services/Libs/Mailer/Mailer.php (email_data_before_headers)
 *  - app/Models/CampaignEmail.php        (fluentcrm_email_body_text)
 */
final class FluentCrmAdapter implements PersonalizationProviderInterface {

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
	 * Per-recipient personalizer.
	 *
	 * @var Personalizer
	 */
	private Personalizer $personalizer;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings  Settings repository.
	 * @param AiClient $ai_client AI Client wrapper.
	 */
	public function __construct( Settings $settings, AiClient $ai_client ) {
		$this->settings     = $settings;
		$this->ai_client    = $ai_client;
		$this->personalizer = new Personalizer(
			$settings,
			$ai_client,
			new PromptRenderer(),
			new PersonalizationCache(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'fluentcrm';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'FluentCRM', 'superdav-ai-newsletter' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		// FluentCRM exposes either a global helper or a defined version constant.
		return defined( 'FLUENTCRM' )
			|| defined( 'FLUENTCRM_PLUGIN_VERSION' )
			|| function_exists( 'FluentCrmApi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// Primary hook: per-recipient subject + body just before headers.
		add_filter(
			'fluent_crm/email_data_before_headers',
			array( $this, 'filter_email_data_before_headers' ),
			20,
			3,
		);

		// Late-stage body filter (defence-in-depth for unusual send paths).
		add_filter(
			'fluentcrm_email_body_text',
			array( $this, 'filter_email_body_text' ),
			20,
			3,
		);
	}

	/**
	 * Rewrite the subject + body of a per-recipient email before headers are built.
	 *
	 * @param mixed  $data        Email data array (subject, body, from, ...).
	 * @param object $subscriber  FluentCRM Subscriber model.
	 * @param object $email_model FluentCRM CampaignEmail model.
	 * @return array<string, mixed>|mixed
	 */
	public function filter_email_data_before_headers( $data, $subscriber, $email_model ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( ! is_object( $subscriber ) || ! is_object( $email_model ) ) {
			return $data;
		}

		if ( ! $this->should_personalize( $email_model ) ) {
			return $data;
		}

		$campaign_id   = (int) ( $email_model->campaign_id ?? $email_model->id ?? 0 );
		$subscriber_id = (int) ( $subscriber->id ?? 0 );
		if ( 0 === $campaign_id || 0 === $subscriber_id ) {
			return $data;
		}

		$placeholders = $this->build_placeholders( $subscriber, $email_model );

		// HTML body.
		if ( (bool) $this->settings->get( 'personalize_body', true )
			&& isset( $data['body'] )
			&& is_string( $data['body'] )
			&& '' !== $data['body']
		) {
			$data['body'] = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'html',
				$data['body'],
				$placeholders,
			);
		}

		// Subject line.
		if ( (bool) $this->settings->get( 'personalize_subject', true )
			&& isset( $data['subject'] )
			&& is_string( $data['subject'] )
			&& '' !== $data['subject']
		) {
			$data['subject'] = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'subject',
				$data['subject'],
				$placeholders,
			);
		}

		// Mark this email-model so the late-stage body filter skips it.
		if ( is_object( $email_model ) ) {
			$email_model->_sd_ai_newsletter_done = true;
		}

		return $data;
	}

	/**
	 * Late-stage HTML body rewrite (used only when the earlier filter did not
	 * fire — relies on the cache to avoid double-billing).
	 *
	 * Because the cache key includes the prompt hash (which depends on the
	 * input body), an already-personalized body would hash differently from
	 * the original and miss the cache. To prevent re-billing, we mark the
	 * body as personalized via a low-overhead in-process flag on the
	 * `$campaign_email` object.
	 *
	 * @param mixed  $email_body     HTML email body.
	 * @param object $subscriber     FluentCRM Subscriber model.
	 * @param object $campaign_email FluentCRM CampaignEmail model.
	 * @return mixed
	 */
	public function filter_email_body_text( $email_body, $subscriber, $campaign_email ) {
		if ( ! is_string( $email_body ) || '' === $email_body ) {
			return $email_body;
		}

		if ( ! is_object( $subscriber ) || ! is_object( $campaign_email ) ) {
			return $email_body;
		}

		// If the earlier filter already personalized this email, skip.
		if ( ! empty( $campaign_email->_sd_ai_newsletter_done ) ) {
			return $email_body;
		}

		if ( ! $this->should_personalize( $campaign_email ) ) {
			return $email_body;
		}

		if ( ! (bool) $this->settings->get( 'personalize_body', true ) ) {
			return $email_body;
		}

		$campaign_id   = (int) ( $campaign_email->campaign_id ?? $campaign_email->id ?? 0 );
		$subscriber_id = (int) ( $subscriber->id ?? 0 );
		if ( 0 === $campaign_id || 0 === $subscriber_id ) {
			return $email_body;
		}

		return $this->personalizer->personalize(
			$this->id(),
			$campaign_id,
			$subscriber_id,
			'html',
			$email_body,
			$this->build_placeholders( $subscriber, $campaign_email ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Translates a FluentCRM Subscriber model into the generic placeholder map.
	 *
	 * @param object $user  FluentCRM Subscriber model.
	 * @param object $email FluentCRM CampaignEmail model.
	 * @return array<string, scalar|null>
	 */
	public function build_placeholders( object $user, object $email ): array {
		$first_name = (string) ( $user->first_name ?? '' );
		$last_name  = (string) ( $user->last_name ?? '' );
		$email_addr = (string) ( $user->email ?? '' );
		$country    = (string) ( $user->country ?? '' );
		// FluentCRM stores language on the contact when WPML / Polylang are
		// integrated; otherwise it's empty.
		$language = (string) ( $user->language ?? '' );

		// FluentCRM `created_at` is a MySQL datetime string.
		$days_since_signup = '';
		$created_at        = (string) ( $user->created_at ?? '' );
		if ( '' !== $created_at ) {
			$ts = strtotime( $created_at );
			if ( false !== $ts && $ts > 0 ) {
				$days_since_signup = (string) max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
			}
		}

		$placeholders = array(
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email_addr,
			'country'           => $country,
			'language'          => $language,
			'days_since_signup' => $days_since_signup,
			'campaign_subject'  => (string) ( $email->email_subject ?? $email->subject ?? '' ),
			'campaign_id'       => (string) ( $email->campaign_id ?? $email->id ?? '' ),
		);

		/**
		 * Filter the placeholder map for a FluentCRM recipient.
		 *
		 * @param array<string, scalar|null> $placeholders The placeholder map.
		 * @param object                     $user         The subscriber model.
		 * @param object                     $email        The campaign-email model.
		 */
		return (array) apply_filters(
			'sd_ai_newsletter_fluentcrm_placeholders',
			$placeholders,
			$user,
			$email,
		);
	}

	/**
	 * Whether AI personalization should run for this email.
	 *
	 * @param object $email_model FluentCRM CampaignEmail model.
	 * @return bool
	 */
	private function should_personalize( $email_model ): bool {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		if ( ! $this->ai_client->is_available() ) {
			return false;
		}

		$mode = $this->settings->mode();
		if ( ! in_array(
			$mode,
			array( Settings::MODE_PER_RECIPIENT, Settings::MODE_PER_SEGMENT, Settings::MODE_HYBRID ),
			true,
		) ) {
			return false;
		}

		/**
		 * Per-campaign opt-out filter (FluentCRM).
		 *
		 * Return false to skip AI personalization for a specific campaign.
		 *
		 * @param bool   $personalize Default true.
		 * @param object $email_model The campaign-email model.
		 */
		return (bool) apply_filters( 'sd_ai_newsletter_fluentcrm_should_personalize', true, $email_model );
	}
}
