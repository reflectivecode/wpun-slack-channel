<?php
/**
 * Base test case: no real network, no real mail.
 *
 * @package wpun-slack-channel
 */

use WPUN\Channel_Instance;

/**
 * Base class for the add-on's tests. Loaded by tests/bootstrap.php after WordPress's test library.
 */
abstract class Slack_Test_Case extends WP_UnitTestCase {

	/**
	 * Every HTTP request attempted during the test: array of [ 'url' => string, 'args' => array ].
	 *
	 * @var array
	 */
	protected $http_requests = array();

	/**
	 * Fake responses: array of [ 'pattern' => string, 'response' => array|WP_Error ].
	 *
	 * @var array
	 */
	private $http_fakes = array();

	/**
	 * Set up: block the network and reset the mailer.
	 */
	public function set_up() {
		parent::set_up();

		$this->http_requests = array();
		$this->http_fakes    = array();
		add_filter( 'pre_http_request', array( $this, 'handle_http' ), 1, 3 );
		reset_phpmailer_instance();
	}

	/**
	 * Start from no stored channel instances, with email off, so only the instance a test stores receives anything.
	 */
	protected function isolate_slack_instance() {
		delete_option( 'sc_wpun_channels' );
		sc_wpun_channels()->save( new Channel_Instance( 'email', 'email', 'Email', false, array( 'to' => 'ops@example.org' ) ) );
	}

	/**
	 * Fake any HTTP request whose URL contains $pattern. The first matching fake wins.
	 *
	 * @param string         $pattern  Substring of the URL.
	 * @param array|WP_Error $response Response (see fake_response()) or error.
	 */
	protected function fake_http( $pattern, $response ) {
		$this->http_fakes[] = array(
			'pattern'  => $pattern,
			'response' => $response,
		);
	}

	/**
	 * Forget the fakes and the requests made so far, to fake a different answer for the next request.
	 */
	protected function reset_http() {
		$this->http_fakes    = array();
		$this->http_requests = array();
	}

	/**
	 * Build an HTTP API response array.
	 *
	 * @param int    $code Status code.
	 * @param string $body Body.
	 * @return array
	 */
	protected function fake_response( $code = 200, $body = '' ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Network guard: fake what was asked for, fail the test on anything else.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request args.
	 * @param string               $url  URL.
	 * @return array|WP_Error
	 */
	public function handle_http( $pre, $args, $url ) {
		$this->http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		foreach ( $this->http_fakes as $fake ) {
			if ( false !== strpos( $url, $fake['pattern'] ) ) {
				return $fake['response'];
			}
		}

		$this->fail( 'Unexpected HTTP request to ' . $url );
	}

	/**
	 * HTTP requests made so far whose URL contains $pattern.
	 *
	 * @param string $pattern Substring of the URL.
	 * @return array
	 */
	protected function requests_to( $pattern ) {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $request ) use ( $pattern ) {
					return false !== strpos( $request['url'], $pattern );
				}
			)
		);
	}
}
