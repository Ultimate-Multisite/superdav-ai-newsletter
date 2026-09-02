<?php
/**
 * Safe customer check-in pilot workflow.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\CheckIn;

use SdAiNewsletter\Core\AiClient;
use WP_Error;

/**
 * Generates, freezes, approves, and conditionally delivers check-in drafts.
 */
final class PilotService {

	/**
	 * Maximum non-rejected recipients allowed in the pilot.
	 */
	public const PILOT_LIMIT = 5;

	/**
	 * Draft workflow states.
	 */
	public const STATUS_DRAFT    = 'draft';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_SENDING  = 'sending';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_SENT     = 'sent';

	/**
	 * Repository lock key serializing every mutation of the shared option.
	 */
	private const WORKFLOW_LOCK = 'pilot-workflow';

	/**
	 * Context keys that may be included in the AI prompt.
	 */
	private const MODEL_CONTEXT_KEYS = [
		'active_addons',
	];

	/**
	 * System instruction used only to generate the closing feedback question.
	 */
	private const SYSTEM_PROMPT = 'Write one warm closing paragraph of at most 45 words for an Ultimate Multisite annual check-in. The product recap is already written. If active_addons contains names, ask whether those add-ons are working well. If it is empty, ask generally about any add-ons. Invite one feature request. Never invent or imply customer activity, tracking, monitoring, analytics, account details, or add-on names not supplied. Include no URLs, unsubscribe text, greeting, recap, or signature. Output plain text only.';

	/**
	 * AI client.
	 *
	 * @var AiClient
	 */
	private AiClient $ai_client;

	/**
	 * Draft repository.
	 *
	 * @var PilotRepository
	 */
	private PilotRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param AiClient        $ai_client  AI client.
	 * @param PilotRepository $repository Draft repository.
	 */
	public function __construct( AiClient $ai_client, PilotRepository $repository ) {
		$this->ai_client  = $ai_client;
		$this->repository = $repository;
	}

	/**
	 * Generate frozen drafts for explicitly selected Newsletter subscribers.
	 *
	 * @param int[] $subscriber_ids Newsletter subscriber IDs.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function generate( array $subscriber_ids ) {
		$subscriber_ids = array_values( array_unique( array_filter( array_map( 'absint', $subscriber_ids ) ) ) );

		if ( empty( $subscriber_ids ) || count( $subscriber_ids ) > self::PILOT_LIMIT ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_invalid_count',
				sprintf(
					/* translators: %d: maximum number of pilot recipients. */
					__( 'Select between one and %d subscriber IDs.', 'superdav-ai-newsletter' ),
					self::PILOT_LIMIT,
				),
			);
		}

		if ( ! $this->repository->acquire_send_lock( self::WORKFLOW_LOCK ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_generation_locked',
				__( 'Pilot draft generation is already running or requires manual lock review.', 'superdav-ai-newsletter' ),
			);
		}

		try {
			return $this->generate_locked( $subscriber_ids );
		} finally {
			$this->repository->release_send_lock( self::WORKFLOW_LOCK );
		}
	}

	/**
	 * Generate drafts while holding the global pilot lock.
	 *
	 * @param int[] $subscriber_ids Newsletter subscriber IDs.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function generate_locked( array $subscriber_ids ) {

		$active_records = array_filter(
			$this->repository->all(),
			static fn( array $record ): bool => self::STATUS_REJECTED !== ( $record['status'] ?? '' ),
		);

		if ( count( $active_records ) + count( $subscriber_ids ) > self::PILOT_LIMIT ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_pilot_full',
				__( 'The pilot is limited to five non-rejected recipients in total.', 'superdav-ai-newsletter' ),
			);
		}

		$drafts = [];

		foreach ( $subscriber_ids as $subscriber_id ) {
			if ( $this->has_existing_record( $subscriber_id ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_duplicate',
					sprintf(
						/* translators: %d: Newsletter subscriber ID. */
						__( 'Subscriber %d already has a non-rejected pilot record.', 'superdav-ai-newsletter' ),
						$subscriber_id,
					),
				);
			}

			$subscriber = $this->get_confirmed_subscriber( $subscriber_id );

			if ( is_wp_error( $subscriber ) ) {
				return $subscriber;
			}

			$context = $this->get_customer_context( $subscriber );

			if ( empty( $context['customer_found'] ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_customer_missing',
					sprintf(
						/* translators: %d: Newsletter subscriber ID. */
						__( 'Subscriber %d is not linked to an Ultimate Multisite customer.', 'superdav-ai-newsletter' ),
						$subscriber_id,
					),
				);
			}

			if ( empty( $context['explicit_opt_in'] ) || 'verified_customer_meta' !== ( $context['consent_source'] ?? '' ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_consent_missing',
					__( 'Dedicated customer check-in consent and its evidence are not recorded.', 'superdav-ai-newsletter' ),
				);
			}

			$model_context = array_intersect_key( $context, array_flip( self::MODEL_CONTEXT_KEYS ) );

			$encoded_context = wp_json_encode( $model_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( ! is_string( $encoded_context ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_json_failed',
					__( 'Could not encode the customer snapshot.', 'superdav-ai-newsletter' ),
				);
			}

			$feedback_question = $this->ai_client->generate_text(
				"Create the closing feedback question from this narrowly allowlisted add-on context:\n\n{$encoded_context}",
				self::SYSTEM_PROMPT,
				[ 'max_output_tokens' => 100 ],
			);

			if ( is_wp_error( $feedback_question ) ) {
				return $feedback_question;
			}

			$feedback_question = $this->validate_feedback_question( $feedback_question );

			if ( is_wp_error( $feedback_question ) ) {
				return $feedback_question;
			}

			$first_name = sanitize_text_field( (string) ( $context['first_name'] ?? $subscriber->name ?? '' ) );
			$subject    = __( 'A year of Ultimate Multisite improvements — what should we build next?', 'superdav-ai-newsletter' );
			$body_html  = $this->build_html_body( $first_name, $feedback_question );
			$body_text  = $this->build_text_body( $first_name, $feedback_question );
			$content_hash = $this->content_hash( $subject, $body_html, $body_text );

			$record = $this->repository->create(
				[
					'subscriber_id'   => $subscriber_id,
					'recipient_email' => sanitize_email( (string) $subscriber->email ),
					'recipient_name'  => trim( $first_name . ' ' . sanitize_text_field( (string) ( $subscriber->surname ?? '' ) ) ),
					'snapshot'        => [ 'ultimate_multisite' => $context ],
					'consent_evidence' => 'verified_customer_meta',
					'subject'         => $subject,
					'feedback_question' => $feedback_question,
					'body_html'       => $body_html,
					'body_text'       => $body_text,
					'content_hash'    => $content_hash,
					'status'          => self::STATUS_DRAFT,
				]
			);

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			$drafts[] = $record;
		}

		return $drafts;
	}

	/**
	 * Approve frozen copy after human review.
	 *
	 * @param string $id Draft UUID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function approve( string $id ) {
		if ( ! $this->repository->acquire_send_lock( self::WORKFLOW_LOCK ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_locked',
				__( 'This check-in is already being processed.', 'superdav-ai-newsletter' ),
			);
		}

		try {
			$record = $this->repository->find( $id );

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			if ( self::STATUS_DRAFT !== ( $record['status'] ?? '' ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_not_draft',
					__( 'Only a draft can be approved.', 'superdav-ai-newsletter' ),
				);
			}

			$valid = $this->validate_frozen_record( $record );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$subscriber = $this->get_confirmed_subscriber( (int) $record['subscriber_id'] );

			if ( is_wp_error( $subscriber ) ) {
				return $subscriber;
			}

			$consent = $this->validate_current_consent( $record, $subscriber );

			if ( is_wp_error( $consent ) ) {
				return $consent;
			}

			$record['status']      = self::STATUS_APPROVED;
			$record['approved_at'] = gmdate( 'c' );
			$approver              = get_current_user_id();
			$record['approved_by'] = $approver ? $approver : 'wp-cli';

			return $this->repository->save( $record );
		} finally {
			$this->repository->release_send_lock( self::WORKFLOW_LOCK );
		}
	}

	/**
	 * Reject an unsent record.
	 *
	 * @param string $id Draft UUID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function reject( string $id ) {
		if ( ! $this->repository->acquire_send_lock( self::WORKFLOW_LOCK ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_locked',
				__( 'This check-in is already being processed.', 'superdav-ai-newsletter' ),
			);
		}

		try {
			$record = $this->repository->find( $id );

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			if ( in_array( $record['status'] ?? '', [ self::STATUS_SENT, self::STATUS_SENDING ], true ) ) {
				return new WP_Error(
					'sd_ai_newsletter_check_in_already_sent',
					__( 'A sent or in-progress check-in cannot be rejected.', 'superdav-ai-newsletter' ),
				);
			}

			$record['status']      = self::STATUS_REJECTED;
			$record['rejected_at'] = gmdate( 'c' );

			return $this->repository->save( $record );
		} finally {
			$this->repository->release_send_lock( self::WORKFLOW_LOCK );
		}
	}

	/**
	 * Validate an approved record and optionally deliver it.
	 *
	 * @param string $id   Draft UUID.
	 * @param bool   $live Whether to deliver instead of dry-running.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send( string $id, bool $live = false ) {
		if ( ! $live ) {
			return $this->process_send( $id, false );
		}

		if ( ! $this->repository->acquire_send_lock( self::WORKFLOW_LOCK ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_locked',
				__( 'This check-in is already being processed.', 'superdav-ai-newsletter' ),
			);
		}

		try {
			return $this->process_send( $id, true );
		} finally {
			$this->repository->release_send_lock( self::WORKFLOW_LOCK );
		}
	}

	/**
	 * Validate a freshly loaded record and optionally deliver it.
	 *
	 * @param string $id   Draft UUID.
	 * @param bool   $live Whether to deliver instead of dry-running.
	 * @return array<string, mixed>|WP_Error
	 */
	private function process_send( string $id, bool $live ) {
		$record = $this->repository->find( $id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( self::STATUS_APPROVED !== ( $record['status'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_not_approved',
				__( 'Only an approved check-in can be sent or dry-run.', 'superdav-ai-newsletter' ),
			);
		}

		$valid = $this->validate_frozen_record( $record );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$subscriber = $this->get_confirmed_subscriber( (int) $record['subscriber_id'] );

		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		if ( ! hash_equals(
			strtolower( trim( (string) ( $record['recipient_email'] ?? '' ) ) ),
			strtolower( trim( (string) $subscriber->email ) ),
		) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_recipient_changed',
				__( 'The subscriber email changed after review; reject this record and generate a new draft.', 'superdav-ai-newsletter' ),
			);
		}

		$consent = $this->validate_current_consent( $record, $subscriber );

		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		if ( ! $live ) {
			$record['dry_run'] = true;

			return $record;
		}

		if ( ! class_exists( '\\Newsletter' ) || ! class_exists( '\\NewsletterEngine' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_newsletter_missing',
				__( 'The Newsletter delivery engine is unavailable.', 'superdav-ai-newsletter' ),
			);
		}

		$record['status']     = self::STATUS_SENDING;
		$record['sending_at'] = gmdate( 'c' );
		$record               = $this->repository->save( $record );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$email               = new \stdClass();
		$email->id           = '0';
		$email->type         = 'message';
		$email->subject      = (string) $record['subject'];
		$email->message      = (string) $record['body_html'];
		$email->message_text = (string) $record['body_text'];
		$email->track        = 0;
		$email->token        = '';
		$email->options      = [ 'sd_ai_check_in' => true ];

		$message = \NewsletterEngine::instance()->build_message( $email, $subscriber );
		$result  = \Newsletter::instance()->get_mailer()->send( $message );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_send_failed',
				__( 'The Newsletter delivery engine did not complete the send. The record remains in the sending state for manual review.', 'superdav-ai-newsletter' ),
			);
		}

		$record['status']  = self::STATUS_SENT;
		$record['sent_at'] = gmdate( 'c' );

		return $this->repository->save( $record );
	}

	/**
	 * Get the repository for read-only CLI views.
	 *
	 * @return PilotRepository
	 */
	public function repository(): PilotRepository {
		return $this->repository;
	}

	/**
	 * Check for an existing non-rejected record for a subscriber.
	 *
	 * @param int $subscriber_id Newsletter subscriber ID.
	 * @return bool
	 */
	private function has_existing_record( int $subscriber_id ): bool {
		foreach ( $this->repository->all() as $record ) {
			if ( (int) ( $record['subscriber_id'] ?? 0 ) === $subscriber_id
				&& self::STATUS_REJECTED !== ( $record['status'] ?? '' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a currently confirmed Newsletter subscriber.
	 *
	 * @param int $subscriber_id Newsletter subscriber ID.
	 * @return object|WP_Error
	 */
	private function get_confirmed_subscriber( int $subscriber_id ) {
		if ( ! class_exists( '\\NewsletterSubscription' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_newsletter_missing',
				__( 'The Newsletter subscriber API is unavailable.', 'superdav-ai-newsletter' ),
			);
		}

		$subscriber = \NewsletterSubscription::instance()->get_user( $subscriber_id );

		if ( ! $subscriber ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_subscriber_missing',
				__( 'Newsletter subscriber not found.', 'superdav-ai-newsletter' ),
			);
		}

		if ( 'C' !== (string) ( $subscriber->status ?? '' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_subscriber_ineligible',
				__( 'The subscriber is not currently confirmed and eligible.', 'superdav-ai-newsletter' ),
			);
		}

		if ( ! is_email( (string) ( $subscriber->email ?? '' ) ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_email_invalid',
				__( 'The subscriber email address is invalid.', 'superdav-ai-newsletter' ),
			);
		}

		return $subscriber;
	}

	/**
	 * Re-check consent immediately before a dry run or live send.
	 *
	 * @param array<string, mixed> $record     Stored record.
	 * @param object               $subscriber Current Newsletter subscriber.
	 * @return true|WP_Error
	 */
	private function validate_current_consent( array $record, object $subscriber ) {
		if ( 'verified_customer_meta' !== ( $record['consent_evidence'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_consent_missing',
				__( 'Verified customer check-in consent is not attached to this record.', 'superdav-ai-newsletter' ),
			);
		}

		$context = $this->get_customer_context( $subscriber );

		if ( empty( $context['explicit_opt_in'] ) || 'verified_customer_meta' !== ( $context['consent_source'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_consent_withdrawn',
				__( 'Dedicated customer check-in consent is no longer present.', 'superdav-ai-newsletter' ),
			);
		}

		return true;
	}

	/**
	 * Load context directly from the known Ultimate Multisite provider.
	 *
	 * Consent is never accepted from the public filter chain, where another
	 * callback could manufacture eligibility fields.
	 *
	 * @param object $subscriber Current Newsletter subscriber.
	 * @return array<string, mixed>
	 */
	private function get_customer_context( object $subscriber ): array {
		if ( ! class_exists( '\\Ultimate_Multisite\\Newsletter\\Customer_Snapshot_Provider' ) ) {
			return [];
		}

		$provider = \Ultimate_Multisite\Newsletter\Customer_Snapshot_Provider::get_instance();
		$snapshot = $provider->build_snapshot( [], $subscriber );

		return $this->allowlist_context( $snapshot );
	}

	/**
	 * Keep only the fixed Ultimate Multisite context schema.
	 *
	 * @param array<string, mixed> $snapshot Raw snapshot.
	 * @return array<string, mixed>
	 */
	private function allowlist_context( array $snapshot ): array {
		$context = $snapshot['ultimate_multisite'] ?? [];

		if ( ! is_array( $context ) ) {
			return [];
		}

		$boolean_keys = [ 'customer_found', 'explicit_opt_in' ];
		$list_keys   = [ 'active_addons' ];
		$string_keys = [ 'consent_source', 'first_name' ];
		$clean = [];

		foreach ( $boolean_keys as $key ) {
			$clean[ $key ] = ! empty( $context[ $key ] );
		}

		foreach ( $list_keys as $key ) {
			$values        = is_array( $context[ $key ] ?? null ) ? $context[ $key ] : [];
			$clean[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $values ) ) );
		}

		foreach ( $string_keys as $key ) {
			$clean[ $key ] = sanitize_text_field( (string) ( $context[ $key ] ?? '' ) );
		}

		return $clean;
	}

	/**
	 * Validate and normalize AI output.
	 *
	 * @param string $feedback_question Raw model output.
	 * @return string|WP_Error
	 */
	private function validate_feedback_question( string $feedback_question ) {
		$feedback_question = trim( sanitize_textarea_field( wp_strip_all_tags( $feedback_question ) ) );

		if ( '' === $feedback_question || mb_strlen( $feedback_question ) > 350 ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_feedback_length',
				__( 'The generated feedback question was empty or exceeded 350 characters.', 'superdav-ai-newsletter' ),
			);
		}

		$words = preg_split( '/\s+/u', $feedback_question );

		if ( false === $words || count( array_filter( $words ) ) > 45 ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_feedback_words',
				__( 'The generated feedback question exceeded 45 words.', 'superdav-ai-newsletter' ),
			);
		}

		if ( preg_match( '~(?:https?://|www\.)~i', $feedback_question ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_feedback_url',
				__( 'The generated feedback question contained a URL and was rejected.', 'superdav-ai-newsletter' ),
			);
		}

		if ( preg_match( '/\b(?:activity|analytics|monitor|monitoring|noticed|saw|track|tracked|tracking|usage)\b/i', $feedback_question ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_feedback_observation',
				__( 'The generated feedback question implied customer observation and was rejected.', 'superdav-ai-newsletter' ),
			);
		}

		return $feedback_question;
	}

	/**
	 * Build the fixed HTML year-in-review template.
	 *
	 * @param string $first_name        Local greeting name.
	 * @param string $feedback_question Validated AI feedback question.
	 * @return string
	 */
	private function build_html_body( string $first_name, string $feedback_question ): string {
		$greeting = $first_name
			? sprintf(
				/* translators: %s: recipient first name. */
				__( 'Hi %s,', 'superdav-ai-newsletter' ),
				$first_name,
			)
			: __( 'Hi,', 'superdav-ai-newsletter' );
		$html = '<p>' . esc_html( $greeting ) . '</p>';
		$html .= '<p>' . esc_html__( 'Over the past year, we have made substantial improvements across Ultimate Multisite. Here is a quick recap of the main areas we worked on:', 'superdav-ai-newsletter' ) . '</p><ul>';

		foreach ( $this->year_in_review_items() as $item ) {
			$html .= '<li>' . esc_html( $item ) . '</li>';
		}

		$html .= '</ul><p>' . esc_html( $feedback_question ) . '</p>';
		$html .= '<p>' . esc_html__( 'If anything is getting in your way, just reply to this email. We read every response.', 'superdav-ai-newsletter' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Best,', 'superdav-ai-newsletter' ) . '<br>' . esc_html__( 'The Ultimate Multisite team', 'superdav-ai-newsletter' ) . '</p>';
		$html .= '<hr><p><a href="{unsubscribe_url}">' . esc_html__( 'Unsubscribe', 'superdav-ai-newsletter' ) . '</a></p>';

		return $html;
	}

	/**
	 * Build the fixed plain-text year-in-review template.
	 *
	 * @param string $first_name        Local greeting name.
	 * @param string $feedback_question Validated AI feedback question.
	 * @return string
	 */
	private function build_text_body( string $first_name, string $feedback_question ): string {
		$greeting = $first_name
			? sprintf(
				/* translators: %s: recipient first name. */
				__( 'Hi %s,', 'superdav-ai-newsletter' ),
				$first_name,
			)
			: __( 'Hi,', 'superdav-ai-newsletter' );
		$items = array_map(
			static fn( string $item ): string => '- ' . $item,
			$this->year_in_review_items(),
		);

		return $greeting . "\n\n"
			. __( 'Over the past year, we have made substantial improvements across Ultimate Multisite. Here is a quick recap of the main areas we worked on:', 'superdav-ai-newsletter' ) . "\n\n"
			. implode( "\n", $items ) . "\n\n"
			. $feedback_question . "\n\n"
			. __( 'If anything is getting in your way, just reply to this email. We read every response.', 'superdav-ai-newsletter' ) . "\n\n"
			. __( 'Best,', 'superdav-ai-newsletter' ) . "\n"
			. __( 'The Ultimate Multisite team', 'superdav-ai-newsletter' ) . "\n\n"
			. __( 'Unsubscribe:', 'superdav-ai-newsletter' ) . ' {unsubscribe_url}';
	}

	/**
	 * Return the verified product themes included in the annual recap.
	 *
	 * @return string[]
	 */
	private function year_in_review_items(): array {
		return [
			__( 'Hosting and domains: added DirectAdmin, Hostinger hPanel, CyberPanel, Plesk, Laravel Forge, RunCloud V3, and Cloudflare Custom Hostnames and DNS tools, with stronger domain-mapping guidance.', 'superdav-ai-newsletter' ),
			__( 'Checkout and access: improved inline login, passwords and passkeys, email verification, billing fields, free, trial, and paid checkout, and cross-domain single sign-on.', 'superdav-ai-newsletter' ),
			__( 'Payments and billing: added PayPal Commerce and guided setup, Stripe Connect and Checkout, pay-what-you-want pricing, billing-period controls, standalone invoices, payment-method management, and Iranian Toman currency.', 'superdav-ai-newsletter' ),
			__( 'Sites and templates: added self-booting single-site and network export and import bundles, safer duplication, the Template Library, stronger template selection and switching, and main-site promotion.', 'superdav-ai-newsletter' ),
			__( 'Operations and communication: added External Cron Service, Amazon SES and OCI Email Delivery, availability diagnostics, dashboard log review, and safer broadcast email handling.', 'superdav-ai-newsletter' ),
			__( 'Reliability and security: hardened customer and admin actions, credentials, webhooks, imports, exports, DNS tools, checkout, provisioning, mapped domains, and background jobs across varied hosting environments.', 'superdav-ai-newsletter' ),
		];
	}

	/**
	 * Calculate the immutable-copy hash.
	 *
	 * @param string $subject   Subject.
	 * @param string $body_html HTML body.
	 * @param string $body_text Text body.
	 * @return string
	 */
	private function content_hash( string $subject, string $body_html, string $body_text ): string {
		return hash( 'sha256', $subject . "\n" . $body_html . "\n" . $body_text );
	}

	/**
	 * Ensure approved content still matches its frozen hash.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @return true|WP_Error
	 */
	private function validate_frozen_record( array $record ) {
		$actual = $this->content_hash(
			(string) ( $record['subject'] ?? '' ),
			(string) ( $record['body_html'] ?? '' ),
			(string) ( $record['body_text'] ?? '' ),
		);

		if ( ! hash_equals( (string) ( $record['content_hash'] ?? '' ), $actual ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_copy_changed',
				__( 'The frozen copy has changed since generation and cannot proceed.', 'superdav-ai-newsletter' ),
			);
		}

		return true;
	}
}
