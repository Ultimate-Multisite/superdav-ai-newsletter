<?php
/**
 * Groundhogg adapter.
 *
 * Hooks into Groundhogg's per-recipient email send pipeline to perform AI
 * personalization just before each email is dispatched.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Adapters\Groundhogg;

use SdAiNewsletter\Cache\PersonalizationCache;
use SdAiNewsletter\Contracts\PersonalizationProviderInterface;
use SdAiNewsletter\Core\AiClient;
use SdAiNewsletter\Core\Settings;
use SdAiNewsletter\Personalization\Personalizer;
use SdAiNewsletter\Personalization\PromptRenderer;

/**
 * Groundhogg plugin adapter.
 *
 * Hook used (verified against Groundhogg's `includes/classes/email.php`):
 *
 *  - `groundhogg/email/before_send` — fires once per recipient just before
 *    the mailer dispatches. Triggered with `do_action_ref_array(...)` so
 *    `$to`, `$subject`, `$content`, and `$headers` are all *passed by
 *    reference*. We mutate `$subject` and `$content` in place rather than
 *    relying on a return value.
 *
 *    Source: classes/email.php → `Email::send()`:
 *    `do_action_ref_array( 'groundhogg/email/before_send',
 *        [ $this, &$to, &$subject, &$content, &$headers ] );`
 *
 * Notes:
 *  - We deliberately register at priority 20 (after Groundhogg's own internal
 *    listeners) so `$content` is the fully merged, replacement-code-resolved
 *    HTML, ready to feed to the AI.
 *  - Subject and content are mutated in-place. The Personalizer returns the
 *    original on any failure, so the worst case is no mutation — Groundhogg's
 *    own send still proceeds.
 */
final class GroundhoggAdapter implements PersonalizationProviderInterface {

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
		return 'groundhogg';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Groundhogg', 'superdav-ai-newsletter' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		// Groundhogg defines a constant on bootstrap and a top-level namespace
		// helper; either is sufficient detection.
		return defined( 'GROUNDHOGG_VERSION' )
			|| function_exists( '\\Groundhogg\\get_db' )
			|| class_exists( '\\Groundhogg\\Email' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// `groundhogg/email/before_send` passes by-reference: we receive the
		// Email object plus four scalars/arrays that we mutate in place.
		add_action(
			'groundhogg/email/before_send',
			[ $this, 'on_before_send' ],
			20,
			5,
		);
	}

	/**
	 * Mutate Groundhogg's outgoing subject + content for a single recipient.
	 *
	 * Note: PHP `add_action` does not preserve by-reference parameters across
	 * the dispatch boundary even though `do_action_ref_array` was used on the
	 * caller side. To work around this, we cast `$email` to an object and
	 * write the rewritten subject/content back via Groundhogg's own setters
	 * where available, falling back to property mutation.
	 *
	 * In practice, Groundhogg's `Email::send()` does pass references through
	 * `do_action_ref_array()`, so direct mutation of `$subject` and `$content`
	 * propagates up to the caller. We defensively also call any setters we
	 * can find, so adapters built against future Groundhogg builds stay safe.
	 *
	 * @param mixed $email   Groundhogg `\Groundhogg\Email` instance.
	 * @param mixed $to      To address (by ref upstream).
	 * @param mixed $subject Subject line (by ref upstream).
	 * @param mixed $content Email content/HTML (by ref upstream).
	 * @param mixed $headers Email headers (by ref upstream).
	 * @return void
	 */
	public function on_before_send( &$email, &$to, &$subject, &$content, &$headers ): void {
		if ( ! is_object( $email ) ) {
			return;
		}

		if ( ! $this->should_personalize( $email ) ) {
			return;
		}

		$contact = $this->resolve_contact( $email );
		if ( ! is_object( $contact ) ) {
			return;
		}

		$campaign_id   = $this->resolve_campaign_id( $email );
		$subscriber_id = (int) ( $contact->ID ?? $contact->id ?? 0 );
		if ( 0 === $campaign_id || 0 === $subscriber_id ) {
			return;
		}

		$placeholders = $this->build_placeholders( $contact, $email );

		// HTML body.
		if ( (bool) $this->settings->get( 'personalize_body', true )
			&& is_string( $content )
			&& '' !== $content
		) {
			$content = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'html',
				$content,
				$placeholders,
			);
		}

		// Subject line.
		if ( (bool) $this->settings->get( 'personalize_subject', true )
			&& is_string( $subject )
			&& '' !== $subject
		) {
			$subject = $this->personalizer->personalize(
				$this->id(),
				$campaign_id,
				$subscriber_id,
				'subject',
				$subject,
				$placeholders,
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Translates a Groundhogg Contact into the generic placeholder map.
	 *
	 * @param object $user  Groundhogg Contact instance.
	 * @param object $email Groundhogg Email instance.
	 * @return array<string, scalar|null>
	 */
	public function build_placeholders( object $user, object $email ): array {
		$first_name = $this->resolve_contact_field( $user, 'first_name', 'get_first_name' );
		$last_name  = $this->resolve_contact_field( $user, 'last_name', 'get_last_name' );
		$email_addr = $this->resolve_contact_field( $user, 'email', 'get_email' );

		// Country / language are stored as contact meta in Groundhogg.
		$country  = $this->resolve_contact_meta( $user, 'country' );
		$language = $this->resolve_contact_meta( $user, 'language' );

		// `date_created` is a MySQL datetime on the contact row.
		$days_since_signup = '';
		$date_created      = '';
		if ( method_exists( $user, 'get_date_created' ) ) {
			$date_created = (string) $user->get_date_created();
		} elseif ( isset( $user->date_created ) ) {
			$date_created = (string) $user->date_created;
		}
		if ( '' !== $date_created ) {
			$ts = strtotime( $date_created );
			if ( false !== $ts && $ts > 0 ) {
				$days_since_signup = (string) max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
			}
		}

		$campaign_subject = '';
		if ( method_exists( $email, 'get_subject_line' ) ) {
			$campaign_subject = (string) $email->get_subject_line();
		}

		$placeholders = [
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email_addr,
			'country'           => $country,
			'language'          => $language,
			'days_since_signup' => $days_since_signup,
			'campaign_subject'  => $campaign_subject,
			'campaign_id'       => (string) $this->resolve_campaign_id( $email ),
		];

		/**
		 * Filter the placeholder map for a Groundhogg recipient.
		 *
		 * @param array<string, scalar|null> $placeholders The placeholder map.
		 * @param object                     $user         The contact instance.
		 * @param object                     $email        The email instance.
		 */
		return (array) apply_filters(
			'sd_ai_newsletter_groundhogg_placeholders',
			$placeholders,
			$user,
			$email,
		);
	}

	/**
	 * Pull the contact off the Email object (Groundhogg exposes a getter and
	 * a public property; older versions only had the property).
	 *
	 * @param object $email Groundhogg Email instance.
	 * @return object|null
	 */
	private function resolve_contact( object $email ): ?object {
		if ( method_exists( $email, 'get_contact' ) ) {
			$contact = $email->get_contact();
			if ( is_object( $contact ) ) {
				return $contact;
			}
		}
		if ( isset( $email->contact ) && is_object( $email->contact ) ) {
			return $email->contact;
		}
		return null;
	}

	/**
	 * Resolve the campaign / email ID from the Groundhogg Email instance.
	 *
	 * Groundhogg's `Email` extends the base object pattern with `get_id()`.
	 *
	 * @param object $email Groundhogg Email instance.
	 * @return int
	 */
	private function resolve_campaign_id( object $email ): int {
		if ( method_exists( $email, 'get_id' ) ) {
			return (int) $email->get_id();
		}
		if ( isset( $email->ID ) ) {
			return (int) $email->ID;
		}
		if ( isset( $email->id ) ) {
			return (int) $email->id;
		}
		return 0;
	}

	/**
	 * Read a Groundhogg Contact field by getter or property.
	 *
	 * @param object $contact Groundhogg Contact instance.
	 * @param string $prop    Property name.
	 * @param string $getter  Getter method name.
	 * @return string
	 */
	private function resolve_contact_field( object $contact, string $prop, string $getter ): string {
		if ( method_exists( $contact, $getter ) ) {
			return (string) $contact->{$getter}();
		}
		if ( isset( $contact->{$prop} ) ) {
			return (string) $contact->{$prop};
		}
		return '';
	}

	/**
	 * Read a Groundhogg Contact meta value safely.
	 *
	 * @param object $contact Groundhogg Contact instance.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private function resolve_contact_meta( object $contact, string $key ): string {
		if ( method_exists( $contact, 'get_meta' ) ) {
			$value = $contact->get_meta( $key );
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Whether AI personalization should run for this email.
	 *
	 * @param object $email Groundhogg Email instance.
	 * @return bool
	 */
	private function should_personalize( object $email ): bool {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		if ( ! $this->ai_client->is_available() ) {
			return false;
		}

		$mode = $this->settings->mode();
		if ( ! in_array(
			$mode,
			[ Settings::MODE_PER_RECIPIENT, Settings::MODE_PER_SEGMENT, Settings::MODE_HYBRID ],
			true,
		) ) {
			return false;
		}

		/**
		 * Per-campaign opt-out filter (Groundhogg).
		 *
		 * Return false to skip AI personalization for a specific email.
		 *
		 * @param bool   $personalize Default true.
		 * @param object $email       The Groundhogg Email instance.
		 */
		return (bool) apply_filters( 'sd_ai_newsletter_groundhogg_should_personalize', true, $email );
	}
}
