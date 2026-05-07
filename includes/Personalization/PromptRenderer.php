<?php
/**
 * Prompt template renderer.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Personalization;

/**
 * Renders the configured personalization prompt template with placeholder
 * substitution from a recipient placeholder map.
 *
 * Supports `{{key}}` and `{{key|fallback}}` syntax. Unknown placeholders are
 * left empty (or replaced with the fallback if provided).
 */
final class PromptRenderer {

	/**
	 * Render the prompt template by substituting placeholders.
	 *
	 * @param string                     $template Template string with `{{key}}` placeholders.
	 * @param array<string, scalar|null> $values   Placeholder values.
	 * @param string                     $body     The original email body to be substituted for `{{body}}`.
	 * @return string Rendered prompt.
	 */
	public function render( string $template, array $values, string $body ): string {
		$values['body'] = $body;

		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\.]+)(?:\s*\|\s*([^}]*))?\s*\}\}/',
			static function ( array $match ) use ( $values ): string {
				$key      = $match[1];
				$fallback = $match[2] ?? '';

				if ( array_key_exists( $key, $values ) && null !== $values[ $key ] && '' !== $values[ $key ] ) {
					return (string) $values[ $key ];
				}
				return (string) $fallback;
			},
			$template,
		);
	}
}
