<?php
/**
 * Plugin bootstrap.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

namespace SdAiNewsletter\Core;

use SdAiNewsletter\Adapters\Newsletter\NewsletterAdapter;
use SdAiNewsletter\Admin\SettingsPage;

/**
 * Bootstraps the plugin: settings, AI client, and adapters.
 */
final class Plugin {

	/**
	 * Singleton instance of the plugin.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Constructor.
	 */
	private function __construct() {
		$this->settings  = new Settings();
		$this->ai_client = new AiClient( $this->settings );
	}

	/**
	 * Get the plugin singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap hooks. Called on `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$plugin = self::instance();

		// Load text domain.
		load_plugin_textdomain(
			'superdav-ai-newsletter',
			false,
			dirname( SD_AI_NEWSLETTER_BASENAME ) . '/languages',
		);

		// Admin UI.
		if ( is_admin() ) {
			( new SettingsPage( $plugin->settings ) )->register();
		}

		// Adapter: Newsletter (Stefano Lissa). Auto-registers only if active.
		( new NewsletterAdapter(
			$plugin->settings,
			$plugin->ai_client,
		) )->register();
	}

	/**
	 * Activation hook. Currently a no-op (settings are lazy).
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Reserved for future schema migrations / cache table creation.
		do_action( 'sd_ai_newsletter_activate' );
	}

	/**
	 * Deactivation hook. Currently a no-op.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		do_action( 'sd_ai_newsletter_deactivate' );
	}

	/**
	 * Get the settings repository.
	 *
	 * @return Settings
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * Get the AI client wrapper.
	 *
	 * @return AiClient
	 */
	public function get_ai_client(): AiClient {
		return $this->ai_client;
	}
}
