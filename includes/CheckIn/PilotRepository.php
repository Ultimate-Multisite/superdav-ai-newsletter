<?php
/**
 * Durable storage for customer check-in pilot drafts.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\CheckIn;

use WP_Error;

/**
 * Stores the deliberately small pilot in one non-autoloaded site option.
 */
final class PilotRepository {

	/**
	 * Option containing records indexed by UUID.
	 */
	private const OPTION_NAME = 'sd_ai_newsletter_check_in_pilot_v1';

	/**
	 * Lock option prefix used to prevent duplicate concurrent sends.
	 */
	private const LOCK_PREFIX = 'sd_ai_newsletter_check_in_lock_';

	/**
	 * Get all records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$records = get_option( self::OPTION_NAME, [] );

		return is_array( $records ) ? $records : [];
	}

	/**
	 * Find one record.
	 *
	 * @param string $id Draft UUID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function find( string $id ) {
		$records = $this->all();

		if ( ! isset( $records[ $id ] ) || ! is_array( $records[ $id ] ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_missing',
				__( 'Check-in draft not found.', 'superdav-ai-newsletter' ),
			);
		}

		return $records[ $id ];
	}

	/**
	 * Create a record.
	 *
	 * @param array<string, mixed> $record Record values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $record ) {
		$id = wp_generate_uuid4();

		$record['id']         = $id;
		$record['created_at'] = gmdate( 'c' );

		$records        = $this->all();
		$records[ $id ] = $record;

		if ( ! update_option( self::OPTION_NAME, $records, false ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_store_failed',
				__( 'Could not store the check-in draft.', 'superdav-ai-newsletter' ),
			);
		}

		return $record;
	}

	/**
	 * Replace one existing record.
	 *
	 * @param array<string, mixed> $record Complete record with an ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save( array $record ) {
		$id      = (string) ( $record['id'] ?? '' );
		$records = $this->all();

		if ( '' === $id || ! isset( $records[ $id ] ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_missing',
				__( 'Check-in draft not found.', 'superdav-ai-newsletter' ),
			);
		}

		$records[ $id ] = $record;

		if ( ! update_option( self::OPTION_NAME, $records, false ) ) {
			return new WP_Error(
				'sd_ai_newsletter_check_in_store_failed',
				__( 'Could not update the check-in draft.', 'superdav-ai-newsletter' ),
			);
		}

		return $record;
	}

	/**
	 * Acquire an atomic workflow lock.
	 *
	 * Locks deliberately never expire automatically. A crashed or ambiguous
	 * process must require manual review rather than risk a duplicate delivery.
	 *
	 * @param string $id Draft UUID.
	 * @return bool
	 */
	public function acquire_send_lock( string $id ): bool {
		$lock_name = self::LOCK_PREFIX . md5( $id );

		return add_option( $lock_name, time(), '', false );
	}

	/**
	 * Release a send lock.
	 *
	 * @param string $id Draft UUID.
	 * @return void
	 */
	public function release_send_lock( string $id ): void {
		delete_option( self::LOCK_PREFIX . md5( $id ) );
	}
}
