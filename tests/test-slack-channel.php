<?php
/**
 * The Slack add-on in this repository, which restores the channel 2.0.0 dropped.
 *
 * @package wpun-slack-channel
 */

use WPUN\Channel_Instance;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN_Slack_Channel\Slack_Channel;

// Loaded the way WordPress loads a plugin file. Loading only defines things; nothing is wired until boot() runs.
require_once dirname( __DIR__ ) . '/wpun-slack-channel.php';

/**
 * Registration, delivery, escaping and results of the Slack channel.
 */
class Test_Slack_Channel extends Slack_Test_Case {

	/**
	 * The secret address of the webhook.
	 */
	const URL = 'https://hooks.slack.com/services/T000/B000/SECRET-TOKEN-123';

	/**
	 * "Activate" the add-on and switch email off, so only the Slack instance receives anything.
	 */
	public function set_up() {
		parent::set_up();

		WPUN_Slack_Channel\boot();
		if ( ! sc_wpun()->channels()->has( Slack_Channel::TYPE ) ) {
			sc_wpun()->channels()->register( new Slack_Channel() );
		}

		sc_wpun()->config()->flush();
		$this->isolate_slack_instance();
	}

	/**
	 * Put the registry back as the rest of the suite expects it.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( Slack_Channel::TYPE );
		if ( ! sc_wpun()->channels()->has( 'email' ) ) {
			sc_wpun()->channels()->register( new WPUN\Modules\Email\Email_Channel() );
		}
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Store an instance the way the 2.0.0 upgrade from 1.x does: id and type `slack`, settings `webhook_url` and `channel`.
	 *
	 * @param array $settings Settings to replace.
	 * @return Channel_Instance
	 */
	private function instance( array $settings = array() ) {
		$instance = new Channel_Instance( 'slack', Slack_Channel::TYPE, 'Slack', true, array_merge( array( 'webhook_url' => self::URL ), $settings ) );
		sc_wpun_channels()->save( $instance );

		return $instance;
	}

	/**
	 * A notification of plugin updates.
	 *
	 * @param string $name Name of the one plugin.
	 * @param bool   $test Whether it is a test notification.
	 * @return Notification
	 */
	private function notification( $name = 'Plugin 1', $test = false ) {
		$item = new Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'plugin-1/plugin-1.php',
				'name'            => $name,
				'current_version' => '1.0',
				'new_version'     => '2.0',
			)
		);

		return new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array( $item ), array( 'is_test' => $test ) );
	}

	/**
	 * Fake the answer of Slack.
	 *
	 * @param int|WP_Error $response Status code, or an error.
	 * @param string       $body     Response body.
	 * @param array        $headers  Response headers.
	 */
	private function slack_answers( $response, $body = 'ok', array $headers = array() ) {
		if ( is_int( $response ) ) {
			$response            = $this->fake_response( $response, $body );
			$response['headers'] = $headers;
		}
		$this->fake_http( 'hooks.slack.com', $response );
	}

	/**
	 * The JSON body of the one request that was made.
	 *
	 * @return array
	 */
	private function sent_payload() {
		$requests = $this->requests_to( 'hooks.slack.com' );
		$this->assertCount( 1, $requests );

		return json_decode( $requests[0]['args']['body'], true );
	}

	/**
	 * The channel registers under the type that the 1.x migration stores.
	 */
	public function test_the_channel_has_the_type_of_the_migrated_instance() {
		$this->assertSame( 'slack', Slack_Channel::TYPE );
		$this->assertTrue( sc_wpun()->channels()->has( 'slack' ) );
		$this->assertArrayNotHasKey( 'slack', sc_wpun()->channels()->get_rejected() );
	}

	/**
	 * A stored 1.x instance sends again, with its webhook and channel override.
	 */
	public function test_a_migrated_instance_resumes() {
		$this->slack_answers( 200 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance( array( 'channel' => '#updates' ) ) );

		$this->assertTrue( $result->is_success() );
		$payload = $this->sent_payload();
		$this->assertSame( '#updates', $payload['channel'] );
		$this->assertSame( 'WP Updates Notifier', $payload['username'] );
		$this->assertSame( ':robot_face:', $payload['icon_emoji'] );
		$this->assertStringContainsString( 'Plugin 1 from version 1.0 to 2.0', $payload['text'] );
		$this->assertSame( self::URL, $this->requests_to( 'hooks.slack.com' )[0]['url'] );
	}

	/**
	 * With no channel override, no channel is sent.
	 */
	public function test_no_channel_key_without_an_override() {
		$this->slack_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertArrayNotHasKey( 'channel', $this->sent_payload() );
	}

	/**
	 * Characters Slack treats as markup are sent as entities, so a name cannot make a link or a mention.
	 */
	public function test_slack_control_characters_are_escaped() {
		$this->slack_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification( 'A & B <!channel> <https://evil.example|click>' ), $this->instance() );

		$text = $this->sent_payload()['text'];
		$this->assertStringContainsString( 'A &amp; B &lt;!channel&gt; &lt;https://evil.example|click&gt;', $text );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( '>', $text );
	}

	/**
	 * Escaping is exactly the three entities Slack asks for, and does not escape twice.
	 */
	public function test_escape() {
		$this->assertSame( '&amp;lt; &amp; &lt; &gt;', Slack_Channel::escape( '&lt; & < >' ) );
		$this->assertSame( 'plain text', Slack_Channel::escape( 'plain text' ) );
	}

	/**
	 * A test message says so and goes through the same request.
	 */
	public function test_the_test_message() {
		$this->slack_answers( 200 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification( 'Plugin 1', true ), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'test message', $this->sent_payload()['text'] );
	}

	/**
	 * Site code can still adjust the payload, and a filter that returns junk is ignored.
	 */
	public function test_the_payload_filter() {
		$this->slack_answers( 200 );
		add_filter(
			'sc_wpun_channel_payload_slack',
			static function ( $payload ) {
				$payload['username'] = 'Ops bot';
				return $payload;
			}
		);

		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertSame( 'Ops bot', $this->sent_payload()['username'] );

		$this->reset_http();
		remove_all_filters( 'sc_wpun_channel_payload_slack' );
		add_filter( 'sc_wpun_channel_payload_slack', '__return_false' );
		$this->slack_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertSame( 'WP Updates Notifier', $this->sent_payload()['username'] );
	}

	/**
	 * Without a webhook address nothing is sent.
	 */
	public function test_no_address_is_a_failure() {
		$this->slack_answers( 200 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance( array( 'webhook_url' => '' ) ) );

		$this->assertFalse( $result->is_success() );
		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
	}

	/**
	 * Slack's short reason is passed on, a longer body is not.
	 */
	public function test_failure_reasons() {
		$this->slack_answers( 404, 'no_service' );
		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'HTTP 404 (no_service)', $result->get_message() );

		$this->reset_http();
		$this->slack_answers( 500, '<html>Server error SECRET-TOKEN-123</html>' );
		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'HTTP 500.', $result->get_message() );
		$this->assertStringNotContainsString( 'html', $result->get_message() );
	}

	/**
	 * Slack asking to slow down is passed on with the wait.
	 */
	public function test_rate_limit_passes_retry_after() {
		$this->slack_answers( 429, 'rate_limited', array( 'retry-after' => '120' ) );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertSame( 120, $result->get_retry_after() );
	}

	/**
	 * No failure message holds the secret address, whatever the failure.
	 */
	public function test_no_failure_message_holds_the_address() {
		$answers = array( 400, 401, 403, 404, 429, 500, 502, new WP_Error( 'http_request_failed', self::URL ) );
		foreach ( $answers as $answer ) {
			$this->reset_http();
			$this->slack_answers( $answer );

			$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

			$this->assertFalse( $result->is_success() );
			$this->assertStringNotContainsString( 'SECRET-TOKEN', $result->get_message() );
			$this->assertStringNotContainsString( 'hooks.slack.com', $result->get_message() );
		}
	}
}
