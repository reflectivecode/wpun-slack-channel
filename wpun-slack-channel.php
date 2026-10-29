<?php
/**
 * Plugin Name:       WP Updates Notifier Slack Channel
 * Description:       Sends WP Updates Notifier alerts to Slack through an incoming webhook. Restores the Slack notifications that version 1.x had and 2.0.0 dropped, and picks up the Slack settings 2.0.0 kept.
 * Version:           1.0.0-beta.1
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.0
 * License:           GPL-2.0-or-later
 * Text Domain:       wpun-slack-channel
 *
 * An add-on: a separate plugin that extends WP Updates Notifier through its public API only. WP Updates Notifier 2.0.0
 * migrates the 1.x Slack settings into a channel instance of the type `slack` and keeps it, unavailable, until a channel
 * of that type is registered. This plugin registers it, so the old webhook starts working again without being set up twice.
 *
 * @package wpun-slack-channel
 */

namespace WPUN_Slack_Channel;

use WPUN\Channel_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The versions of the WP Updates Notifier extension API this add-on works with, as a constraint.
 */
const REQUIRED_API = '^1.0';

/**
 * Whether the installed WP Updates Notifier offers the API this add-on was written for.
 *
 * @return bool
 */
function api_is_supported(): bool {
	return function_exists( 'sc_wpun_api_satisfies' ) && \sc_wpun_api_satisfies( REQUIRED_API );
}

/**
 * Wire the add-on, if the installed plugin offers the API it was written for.
 *
 * It takes no parameters on purpose: WordPress passes an empty string to callbacks of `plugins_loaded`.
 *
 * @return void
 */
function boot(): void {
	if ( ! api_is_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\api_notice' );
		return;
	}

	require_once __DIR__ . '/class-slack-channel.php';

	add_action(
		'sc_wpun_register_channels',
		static function ( Channel_Registry $registry ) {
			$registry->register( new Slack_Channel() );
		}
	);
}

/**
 * Tell the administrator that WP Updates Notifier is missing or too old.
 *
 * @return void
 */
function api_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'WP Updates Notifier Slack Channel needs WP Updates Notifier 2.0.0 or later. Please install or update it.', 'wpun-slack-channel' )
		. '</p></div>';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
