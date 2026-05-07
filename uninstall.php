<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options when the user clicks "Delete" in the WordPress admin.
 *
 * @package SdAiNewsletter
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sd_ai_newsletter_settings' );

// Cached personalization results live in the object cache and transients.
// Object-cache entries expire on flush; transients matching our group prefix
// will expire naturally within their 7-day TTL.
