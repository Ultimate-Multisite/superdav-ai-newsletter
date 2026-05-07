<?php
/**
 * Plugin Name: AI Newsletter
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-newsletter
 * Description: Per-recipient and per-segment AI personalization for self-hosted WordPress newsletter plugins. Uses the WordPress 7.0+ AI Client and any configured connector. Newsletter (Stefano Lissa) supported in v0.1; FluentCRM and Groundhogg adapters planned.
 * Version:     0.1.0
 * Author:      superdav42
 * Author URI:  https://github.com/superdav42
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Text Domain: superdav-ai-newsletter
 * Domain Path: /languages
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SD_AI_NEWSLETTER_VERSION', '0.1.0' );
define( 'SD_AI_NEWSLETTER_FILE', __FILE__ );
define( 'SD_AI_NEWSLETTER_DIR', __DIR__ );
define( 'SD_AI_NEWSLETTER_URL', plugin_dir_url( __FILE__ ) );
define( 'SD_AI_NEWSLETTER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Built-in fallback model ID used when no model is configured in settings
 * and the configured AI Client connector exposes no default.
 *
 * Override at runtime via the `sd_ai_newsletter_default_model` filter.
 */
define( 'SD_AI_NEWSLETTER_DEFAULT_MODEL', 'gpt-4o-mini' );

// Composer autoloader — required.
if ( file_exists( SD_AI_NEWSLETTER_DIR . '/vendor/autoload.php' ) ) {
	require_once SD_AI_NEWSLETTER_DIR . '/vendor/autoload.php';
} else {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'AI Newsletter is missing its vendor dependencies. Please run "composer install" in the plugin directory.',
					'superdav-ai-newsletter',
				),
			);
		},
	);
	return;
}

use SdAiNewsletter\Core\Plugin;

// Activation / deactivation hooks fire before plugins_loaded.
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Boot.
add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 5 );
