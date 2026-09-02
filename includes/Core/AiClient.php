<?php
/**
 * WordPress AI Client wrapper.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Core;

use WP_Error;

/**
 * Thin wrapper around the WordPress 7.0+ AI Client API.
 *
 * Uses `wp_ai_client_prompt()` when available; surfaces a WP_Error otherwise so
 * callers can fall back to the original (un-personalized) message body.
 */
final class AiClient {

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the WordPress AI Client API is available on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Send a prompt and return the model's text output.
	 *
	 * @param string               $user_prompt   User-facing prompt (the rewrite instructions + email body).
	 * @param string               $system_prompt System / role prompt.
	 * @param array<string, mixed> $options       Optional overrides: model, max_output_tokens.
	 * @return string|WP_Error Generated text, or WP_Error on failure.
	 */
	public function generate_text( string $user_prompt, string $system_prompt = '', array $options = [] ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'sd_ai_newsletter_client_unavailable',
				__( 'WordPress AI Client API is not available. Requires WordPress 7.0+ with at least one AI connector configured.', 'superdav-ai-newsletter' ),
			);
		}

		$model             = (string) ( $options['model'] ?? $this->settings->model() );
		$max_output_tokens = (int) ( $options['max_output_tokens'] ?? $this->settings->get( 'max_output_tokens', 1024 ) );

		$args = [
			'prompt'            => $user_prompt,
			'model'             => $model,
			'max_output_tokens' => $max_output_tokens,
		];

		if ( '' !== $system_prompt ) {
			$args['system_instruction'] = $system_prompt;
		}

		/**
		 * Filter the arguments used to configure the WordPress AI Client builder.
		 *
		 * @param array<string, mixed> $args     The prompt arguments.
		 * @param string               $user_prompt The user prompt.
		 * @param string               $system_prompt The system prompt.
		 */
		$args = (array) apply_filters( 'sd_ai_newsletter_prompt_args', $args, $user_prompt, $system_prompt );

		try {
			$result = \wp_ai_client_prompt( (string) ( $args['prompt'] ?? $user_prompt ) );

			// WordPress 7.0+ returns a fluent prompt builder. Configure it before
			// calling the terminating generate_text() method.
			if ( is_object( $result ) && is_a( $result, 'WP_AI_Client_Prompt_Builder' ) ) {
				if ( ! empty( $args['model'] ) ) {
					$result->using_model_preference( (string) $args['model'] );
				}

				if ( ! empty( $args['system_instruction'] ) ) {
					$result->using_system_instruction( (string) $args['system_instruction'] );
				}

				if ( ! empty( $args['max_output_tokens'] ) ) {
					$result->using_max_tokens( (int) $args['max_output_tokens'] );
				}

				if ( isset( $args['temperature'] ) ) {
					$result->using_temperature( (float) $args['temperature'] );
				}

				if ( isset( $args['top_p'] ) ) {
					$result->using_top_p( (float) $args['top_p'] );
				}

				if ( isset( $args['top_k'] ) ) {
					$result->using_top_k( (int) $args['top_k'] );
				}

				$result = $result->generate_text();
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'sd_ai_newsletter_client_exception',
				$e->getMessage(),
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Result shape may vary by WP version; normalize to a string.
		if ( is_string( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			if ( isset( $result['text'] ) && is_string( $result['text'] ) ) {
				return $result['text'];
			}
			if ( isset( $result['content'] ) && is_string( $result['content'] ) ) {
				return $result['content'];
			}
		}

		if ( is_object( $result ) ) {
			if ( isset( $result->text ) && is_string( $result->text ) ) {
				return $result->text;
			}
			if ( method_exists( $result, '__toString' ) ) {
				return (string) $result;
			}
		}

		return new WP_Error(
			'sd_ai_newsletter_client_unexpected_response',
			__( 'AI Client returned an unexpected response shape.', 'superdav-ai-newsletter' ),
		);
	}
}
