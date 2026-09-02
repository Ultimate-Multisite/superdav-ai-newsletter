<?php
/**
 * WP-CLI interface for the customer check-in pilot.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\CheckIn;

/**
 * Manages a bounded, human-approved customer check-in pilot.
 */
final class CliCommand {

	/**
	 * Pilot service.
	 *
	 * @var PilotService
	 */
	private PilotService $service;

	/**
	 * Constructor.
	 *
	 * @param PilotService $service Pilot service.
	 */
	public function __construct( PilotService $service ) {
		$this->service = $service;
	}

	/**
	 * Generate frozen drafts for one to five selected subscribers.
	 *
	 * ## OPTIONS
	 *
	 * --subscriber=<ids>
	 * : Comma-separated Newsletter subscriber IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-newsletter check-in generate --subscriber=12,34,56
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function generate( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$ids = array_map( 'trim', explode( ',', (string) ( $assoc_args['subscriber'] ?? '' ) ) );
		$result = $this->service->generate( array_map( 'absint', $ids ) );

		$this->halt_on_error( $result );

		foreach ( $result as $record ) {
			\WP_CLI::log(
				sprintf(
					'Draft %s created for subscriber %d (%s).',
					$record['id'],
					$record['subscriber_id'],
					$this->mask_email( (string) $record['recipient_email'] ),
				),
			);
		}

		\WP_CLI::success( 'Draft generation completed. No email was sent.' );
	}

	/**
	 * List all pilot records without exposing full email addresses.
	 *
	 * @return void
	 */
	public function list_drafts(): void {
		$rows = [];

		foreach ( $this->service->repository()->all() as $record ) {
			$rows[] = [
				'id'         => (string) ( $record['id'] ?? '' ),
				'subscriber' => (int) ( $record['subscriber_id'] ?? 0 ),
				'recipient'  => $this->mask_email( (string) ( $record['recipient_email'] ?? '' ) ),
				'status'     => (string) ( $record['status'] ?? '' ),
				'created'    => (string) ( $record['created_at'] ?? '' ),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'subscriber', 'recipient', 'status', 'created' ] );
	}

	/**
	 * Show the exact frozen copy and consent record for human review.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Draft UUID.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function show( array $args ): void {
		$id     = (string) ( $args[0] ?? '' );
		$record = $this->service->repository()->find( $id );

		$this->halt_on_error( $record );

		\WP_CLI::log( 'ID: ' . $record['id'] );
		\WP_CLI::log( 'Status: ' . $record['status'] );
		\WP_CLI::log( 'Recipient: ' . $record['recipient_email'] );
		\WP_CLI::log( 'Consent: ' . $record['consent_evidence'] );
		\WP_CLI::log( 'Content hash: ' . $record['content_hash'] );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Subject: ' . $record['subject'] );
		\WP_CLI::log( '' );
		\WP_CLI::log( "Plain-text body:\n" . $record['body_text'] );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'No email was sent.' );
	}

	/**
	 * Approve one frozen draft after reviewing it with `show`.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Draft UUID.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function approve( array $args ): void {
		$id     = (string) ( $args[0] ?? '' );
		$result = $this->service->approve( $id );

		$this->halt_on_error( $result );
		\WP_CLI::success( 'Draft approved and frozen. No email was sent.' );
	}

	/**
	 * Reject one unsent pilot record.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Draft UUID.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function reject( array $args ): void {
		$id     = (string) ( $args[0] ?? '' );
		$result = $this->service->reject( $id );

		$this->halt_on_error( $result );
		\WP_CLI::success( 'Pilot record rejected. No email was sent.' );
	}

	/**
	 * Dry-run an approved record, or deliberately send exactly one record.
	 *
	 * The default is always dry-run. Live delivery requires both `--live` and an
	 * exact `--confirm=<id>` value matching the single approved record.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Approved draft UUID.
	 *
	 * [--live]
	 * : Deliver the message instead of dry-running.
	 *
	 * [--confirm=<id>]
	 * : Exact draft UUID; required with --live.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function send( array $args, array $assoc_args ): void {
		$id   = (string) ( $args[0] ?? '' );
		$live = isset( $assoc_args['live'] );

		if ( $live && ! hash_equals( $id, (string) ( $assoc_args['confirm'] ?? '' ) ) ) {
			\WP_CLI::error( 'Live delivery requires --confirm=<id> matching the approved record exactly.' );
		}

		$result = $this->service->send( $id, $live );

		$this->halt_on_error( $result );

		if ( $live ) {
			\WP_CLI::success( 'One approved check-in was sent.' );
			return;
		}

		\WP_CLI::log( 'DRY RUN — no email was sent.' );
		\WP_CLI::log( 'Record: ' . $result['id'] );
		\WP_CLI::log( 'Recipient: ' . $this->mask_email( (string) $result['recipient_email'] ) );
		\WP_CLI::log( 'Subject: ' . $result['subject'] );
		\WP_CLI::log( 'Content hash: ' . $result['content_hash'] );
		\WP_CLI::success( 'Approval, consent, subscriber status, and frozen-copy checks passed.' );
	}

	/**
	 * Stop on a WP_Error result.
	 *
	 * @param mixed $result Operation result.
	 * @return void
	 */
	private function halt_on_error( $result ): void {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result );
		}

		if ( false === $result || null === $result ) {
			\WP_CLI::error( 'The pilot operation did not return a result.' );
		}
	}

	/**
	 * Mask an email address for list and dry-run output.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );

		if ( 2 !== count( $parts ) ) {
			return '***';
		}

		$prefix = '' !== $parts[0] ? mb_substr( $parts[0], 0, 1 ) : '';

		return $prefix . '***@' . $parts[1];
	}
}
