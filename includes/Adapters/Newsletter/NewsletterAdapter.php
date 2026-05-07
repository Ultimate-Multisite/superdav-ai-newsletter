<?php
/**
 * Newsletter (Stefano Lissa) adapter.
 *
 * Hooks into the Newsletter plugin's send-loop filters to perform per-recipient
 * AI personalization just before each email is handed to wp_mail().
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Adapters\Newsletter;

use SdAiNewsletter\Cache\PersonalizationCache;
use SdAiNewsletter\Contracts\PersonalizationProviderInterface;
use SdAiNewsletter\Core\AiClient;
use SdAiNewsletter\Core\Settings;
use SdAiNewsletter\Personalization\Personalizer;
use SdAiNewsletter\Personalization\PromptRenderer;

/**
 * Newsletter plugin adapter.
 *
 * Filters used (all defined in newsletter/classes/NewsletterEngine.php):
 *  - newsletter_send_user        — fires once per recipient, just before the message is built.
 *  - newsletter_message_text     — plain-text body, per recipient.
 *  - newsletter_message          — final TNP_Mailer_Message, per recipient (HTML body, subject, headers).
 *  - newsletter_send_skip        — allow this adapter to skip a recipient (unused in v0.1).
 *
 * Each filter receives `$user` (the subscriber row) and `$email` (the campaign).
 */
final class NewsletterAdapter implements PersonalizationProviderInterface {

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
		return 'newsletter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Newsletter (Stefano Lissa)', 'superdav-ai-newsletter' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		// The Newsletter plugin defines this constant in plugin.php.
		return defined( 'NEWSLETTER_VERSION' )
			|| class_exists( '\\NewsletterEngine' )
			|| function_exists( 'tnp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// Plain-text body.
		add_filter(
			'newsletter_message_text',
			[ $this, 'filter_message_text' ],
			20,
			3,
		);

		// Final message (subject + HTML body + headers).
		add_filter(
			'newsletter_message',
			[ $this, 'filter_message' ],
			20,
			3,
		);
	}

	/**
	 * Filter the plain-text body of an email for a single recipient.
	 *
	 * @param string $body_text Plain-text body.
	 * @param object $email     Newsletter email/campaign object.
	 * @param object $user      Newsletter subscriber object.
	 * @return string
	 */
	public function filter_message_text( $body_text, $email, $user ) {
		if ( ! is_string( $body_text ) || '' === $body_text ) {
			return $body_text;
		}

		if ( ! $this->should_personalize( $email ) ) {
			return $body_text;
		}

		$campaign_id   = (int) ( $email->id ?? 0 );
		$subscriber_id = (int) ( $user->id ?? 0 );
		if ( 0 === $campaign_id || 0 === $subscriber_id ) {
			return $body_text;
		}

		return $this->personalizer->personalize(
			$this->id(),
			$campaign_id,
			$subscriber_id,
			'text',
			$body_text,
			$this->build_placeholders( $user, $email ),
		);
	}

	/**
	 * Filter the final message object (HTML body + subject + headers) for a single recipient.
	 *
	 * Operates on `$message->body` (HTML) and `$message->subject` based on settings flags.
	 *
	 * @param object $message TNP_Mailer_Message-shaped object.
	 * @param object $email   Newsletter email/campaign object.
	 * @param object $user    Newsletter subscriber object.
	 * @return object
	 */
	public function filter_message( $message, $email, $user ) {
		if ( ! is_object( $message ) ) {
			return $message;
		}

		if ( ! $this->should_personalize( $email ) ) {
			return $message;
		}

		$campaign_id   = (int) ( $email->id ?? 0 );
		$subscriber_id = (int) ( $user->id ?? 0 );
		if ( 0 === $campaign_id || 0 === $subscriber_id ) {
			return $message;
		}

		$placeholders = $this->build_placeholders( $user, $email );

		// HTML body.
		if ( (bool) $this->settings->get( 'personalize_body', true )
			&& isset( $message->body )
			&& is_string( $message->body )
			&& '' !== $message->body
		) {
			$message->body = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'html',
				$message->body,
				$placeholders,
			);
		}

		// Subject line — per-recipient subject personalization is the highest-ROI knob.
		if ( (bool) $this->settings->get( 'personalize_subject', true )
			&& isset( $message->subject )
			&& is_string( $message->subject )
			&& '' !== $message->subject
		) {
			$message->subject = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'subject',
				$message->subject,
				$placeholders,
			);
		}

		return $message;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object $user  Newsletter subscriber row.
	 * @param object $email Newsletter campaign row.
	 * @return array<string, scalar|null>
	 */
	public function build_placeholders( object $user, object $email ): array {
		$first_name = (string) ( $user->name ?? '' );
		$last_name  = (string) ( $user->surname ?? '' );
		$email_addr = (string) ( $user->email ?? '' );
		$language   = (string) ( $user->language ?? '' );
		$country    = (string) ( $user->country ?? '' );

		// Newsletter subscriber rows store created_time as unix timestamp.
		$days_since_signup = '';
		if ( ! empty( $user->created_time ) && is_numeric( $user->created_time ) ) {
			$created_ts        = (int) $user->created_time;
			$days_since_signup = (string) max( 0, (int) floor( ( time() - $created_ts ) / DAY_IN_SECONDS ) );
		}

		$placeholders = [
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email_addr,
			'country'           => $country,
			'language'          => $language,
			'days_since_signup' => $days_since_signup,
			'campaign_subject'  => (string) ( $email->subject ?? '' ),
			'campaign_id'       => (string) ( $email->id ?? '' ),
		];

		/**
		 * Filter the placeholder map for a Newsletter recipient.
		 *
		 * @param array<string, scalar|null> $placeholders The placeholder map.
		 * @param object                     $user         The subscriber row.
		 * @param object                     $email        The campaign row.
		 */
		return (array) apply_filters(
			'sd_ai_newsletter_newsletter_placeholders',
			$placeholders,
			$user,
			$email,
		);
	}

	/**
	 * Whether AI personalization should run for this campaign.
	 *
	 * @param object $email Newsletter email/campaign object.
	 * @return bool
	 */
	private function should_personalize( $email ): bool {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		if ( ! $this->ai_client->is_available() ) {
			return false;
		}

		// v0.2 honours per-recipient, per-segment, and hybrid modes through
		// the per-recipient hook surface — the Personalizer branches on mode
		// internally and reuses cached segment bodies across recipients.
		$mode = $this->settings->mode();
		if ( ! in_array(
			$mode,
			[ Settings::MODE_PER_RECIPIENT, Settings::MODE_PER_SEGMENT, Settings::MODE_HYBRID ],
			true,
		) ) {
			return false;
		}

		/**
		 * Per-campaign opt-out filter.
		 *
		 * Return false to skip AI personalization for a specific campaign.
		 *
		 * @param bool   $personalize Default true.
		 * @param object $email       The campaign row.
		 */
		return (bool) apply_filters( 'sd_ai_newsletter_should_personalize', true, $email );
	}
}
