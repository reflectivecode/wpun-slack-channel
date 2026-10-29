<?php
/**
 * The Slack channel.
 *
 * @package wpun-slack-channel
 */

namespace WPUN_Slack_Channel;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Posts a notification to Slack through an incoming webhook.
 *
 * The type is `slack` on purpose: it is the type of the instance WP Updates Notifier 2.0.0 migrates from the 1.x
 * settings, and registering it makes that instance available again. Its two settings keep their 1.x meaning:
 * `webhook_url` and `channel` (the old "channel override").
 *
 * Payload: `text` (always), `username` and `icon_emoji` (as in 1.x), and `channel` when one is set. Slack apps created
 * today ignore the last three and post as the app to the channel chosen when the webhook was made. Older "legacy"
 * webhooks still honor them. Sending them does no harm either way.
 *
 * @since 1.0.0
 */
class Slack_Channel extends Channel {

	/**
	 * The channel type, the same as the instance migrated from 1.x.
	 */
	const TYPE = 'slack';

	/**
	 * The longest message to send, in characters, before escaping. Slack cuts messages at 40,000; a long list is not
	 * readable in a channel anyway.
	 */
	const MAX_LENGTH = 3000;

	/**
	 * The most updates to list in a message.
	 */
	const MAX_ITEMS = 50;

	/**
	 * Seconds to wait for Slack before giving up.
	 */
	const TIMEOUT = 10;

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * The API versions this channel needs.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return REQUIRED_API;
	}

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Slack', 'wpun-slack-channel' );
	}

	/**
	 * One sentence about the channel.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Posts the list of available updates to a Slack channel through an incoming webhook.', 'wpun-slack-channel' );
	}

	/**
	 * The settings of an instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// The address carries the token: anyone who has it can post to the Slack channel.
			'webhook_url' => array(
				'type'        => 'url',
				'label'       => __( 'Webhook address', 'wpun-slack-channel' ),
				'description' => __( 'The incoming webhook URL from your Slack app, starting with https://hooks.slack.com/.', 'wpun-slack-channel' ),
				'required'    => true,
				'secret'      => true,
			),
			'channel'     => array(
				'type'              => 'text',
				'label'             => __( 'Channel override', 'wpun-slack-channel' ),
				'description'       => __( 'Optional, for example #updates. Only older "legacy" webhooks honor it; newer ones always post to the channel chosen in Slack.', 'wpun-slack-channel' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Whether the channel supports a feature.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * Deliver the notification through one instance.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$url = $instance->get_setting( 'webhook_url', '' );
		if ( ! is_string( $url ) || '' === $url ) {
			return Delivery_Result::failure( __( 'No webhook address is set.', 'wpun-slack-channel' ), 'not_configured' );
		}

		$payload = array(
			'username'   => __( 'WP Updates Notifier', 'wpun-slack-channel' ),
			'icon_emoji' => ':robot_face:',
			'text'       => self::escape( $this->message( $notification ) ),
		);

		$override = $instance->get_setting( 'channel', '' );
		if ( is_string( $override ) && '' !== $override ) {
			$payload['channel'] = $override;
		}

		// Site code may still change what is sent, with `sc_wpun_channel_payload_slack`.
		$filtered = $this->filter_payload( $payload, $notification, $instance );
		$payload  = is_array( $filtered ) ? $filtered : $payload;

		// wp_safe_remote_post() refuses addresses on the server's own network.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		return $this->result( $response );
	}

	/**
	 * Escape text for Slack's message format.
	 *
	 * Slack treats `&`, `<` and `>` as control characters (links, mentions) and wants them as entities. It ignores
	 * backslash escapes, so the Markdown renderer is not used. The plain text has no other markup, and Slack turns
	 * bare addresses into links by itself.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * The text to post, before escaping.
	 *
	 * @param Notification $notification The notification.
	 * @return string
	 */
	private function message( Notification $notification ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		if ( $notification->is_test() ) {
			// translators: 1: Site name. 2: Site address.
			return sprintf( __( 'This is a test message from WP Updates Notifier for %1$s (%2$s).', 'wpun-slack-channel' ), $site, $notification->get_site_url() );
		}

		return Notification_Renderer::plain_text( $notification, self::MAX_LENGTH, self::MAX_ITEMS );
	}

	/**
	 * Turn the answer of the HTTP API into a Delivery_Result. The messages never contain the webhook address.
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_safe_remote_post() returned.
	 * @return Delivery_Result
	 */
	private function result( $response ): Delivery_Result {
		if ( is_wp_error( $response ) ) {
			return Delivery_Result::failure(
				__( 'The request to Slack failed.', 'wpun-slack-channel' ),
				'http_error',
				null,
				null,
				array( 'error_code' => (string) $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return Delivery_Result::success();
		}

		if ( 429 === $status ) {
			return Delivery_Result::failure(
				// translators: %d: HTTP status code.
				sprintf( __( 'Slack is limiting requests (HTTP %d).', 'wpun-slack-channel' ), $status ),
				'rate_limited',
				$status,
				Delivery_Result::retry_after_from_response( $response )
			);
		}

		// Slack explains a refusal in the body, for example "invalid_token", "no_service" or "channel_not_found".
		// Only a short word of that kind is passed on.
		$reason = trim( (string) wp_remote_retrieve_body( $response ) );
		$reason = 1 === preg_match( '/^[a-z_]{1,40}$/D', $reason ) ? $reason : '';

		if ( '' !== $reason ) {
			// translators: 1: HTTP status code. 2: Reason given by Slack, a short code.
			$message = sprintf( __( 'Slack answered with HTTP %1$d (%2$s).', 'wpun-slack-channel' ), $status, $reason );
		} else {
			// translators: %d: HTTP status code.
			$message = sprintf( __( 'Slack answered with HTTP %d.', 'wpun-slack-channel' ), $status );
		}

		return Delivery_Result::failure( $message, 'bad_status', $status );
	}
}
