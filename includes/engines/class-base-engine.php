<?php
/**
 * Shared HTTP plumbing for engines (parallel requests, errors, markup escaping).
 *
 * @package SHDT
 */

namespace SHDT\Engines;

use SHDT\Settings;
use SHDT\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

abstract class Base_Engine implements Engine {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Error of the last run.
	 *
	 * @var Engine_Exception|null
	 */
	protected $error = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build one HTTP request for a batch.
	 *
	 * @param string[] $texts   Strings.
	 * @param array    $source  Source language.
	 * @param array    $target  Target language.
	 * @param array    $context Context.
	 * @return array [ url, method, headers, body, timeout ]
	 */
	abstract protected function build_request( array $texts, array $source, array $target, array $context );

	/**
	 * Parse the HTTP response of a batch.
	 *
	 * @param string[] $texts   Strings sent.
	 * @param int      $status  HTTP status.
	 * @param string   $body    Body.
	 * @param array    $headers Lower-case headers.
	 * @return array index => translation
	 * @throws Engine_Exception On failure.
	 */
	abstract protected function parse_response( array $texts, $status, $body, array $headers );

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_batch() {
		return 50;
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_chars() {
		return 5000;
	}

	/**
	 * Whether "never translate" terms must be hidden behind placeholders.
	 * AI engines get the list in their instructions instead, which keeps grammar intact.
	 *
	 * @return bool
	 */
	public function protects_terms() {
		return true;
	}

	/**
	 * Parallel requests per round.
	 *
	 * @return int
	 */
	protected function concurrency() {
		return 3;
	}

	/**
	 * Default request timeout in seconds.
	 *
	 * @return int
	 */
	protected function timeout() {
		return 20;
	}

	/**
	 * {@inheritDoc}
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translate_batches( array $batches, array $source, array $target, array $context, $deadline ) {
		$this->error = null;
		$results     = array();
		$concurrency = max( 1, (int) apply_filters( 'shdt_engine_concurrency', $this->concurrency(), $this->id() ) );

		foreach ( array_chunk( $batches, $concurrency, true ) as $group ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				break;
			}

			$requests = array();
			foreach ( $group as $key => $texts ) {
				$request            = $this->build_request( array_values( $texts ), $source, $target, $context );
				$request['timeout'] = (int) max( 5, min( isset( $request['timeout'] ) ? $request['timeout'] : $this->timeout(), ceil( $remaining ) + 5 ) );
				$requests[ $key ]   = $request;
			}

			foreach ( $this->send( $requests ) as $key => $response ) {
				try {
					if ( $response instanceof Engine_Exception ) {
						throw $response;
					}
					$results[ $key ] = $this->parse_response( array_values( $group[ $key ] ), $response['status'], $response['body'], $response['headers'] );
				} catch ( Engine_Exception $e ) {
					self::log( $this->id(), $e->getMessage() );
					if ( $e->pause > 0 ) {
						$this->error = $e;
					}
				}
			}

			if ( $this->error ) {
				break;
			}
		}
		return $results;
	}

	/**
	 * Send requests (in parallel when possible).
	 *
	 * @param array $requests key => request.
	 * @return array key => response array|Engine_Exception
	 */
	protected function send( array $requests ) {
		$class = self::requests_class();
		if ( count( $requests ) > 1 && $class && ! self::proxy_enabled() && apply_filters( 'shdt_parallel_requests', true ) ) {
			return $this->send_parallel( $class, $requests );
		}

		$out = array();
		foreach ( $requests as $key => $request ) {
			$response = wp_remote_request(
				$request['url'],
				apply_filters(
					'shdt_http_args',
					array(
						'method'     => $request['method'],
						'headers'    => $request['headers'],
						'body'       => $request['body'],
						'timeout'    => $request['timeout'],
						'user-agent' => self::user_agent(),
					),
					$this->id()
				)
			);
			if ( is_wp_error( $response ) ) {
				$out[ $key ] = new Engine_Exception( $response->get_error_message(), 0 );
				continue;
			}
			$headers = array();
			$raw     = wp_remote_retrieve_headers( $response );
			if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
				$raw = $raw->getAll();
			}
			foreach ( (array) $raw as $name => $value ) {
				$headers[ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
			$out[ $key ] = array(
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'headers' => $headers,
			);
		}
		return $out;
	}

	/**
	 * Parallel transport through the Requests library bundled with WordPress.
	 *
	 * @param string $class    Requests class name.
	 * @param array  $requests Requests.
	 * @return array
	 */
	private function send_parallel( $class, array $requests ) {
		$verify = apply_filters( 'https_ssl_verify', true, '' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false;
		$multi  = array();
		$max    = 5;
		foreach ( $requests as $key => $request ) {
			$max           = max( $max, $request['timeout'] );
			$multi[ $key ] = array(
				'url'     => $request['url'],
				'headers' => $request['headers'],
				'data'    => 'GET' === $request['method'] ? array() : $request['body'],
				'type'    => $request['method'],
				'options' => array(
					'timeout'         => $request['timeout'],
					'connect_timeout' => min( 10, $request['timeout'] ),
					'useragent'       => self::user_agent(),
					'verify'          => $verify,
				),
			);
		}

		$out = array();
		try {
			$responses = call_user_func(
				array( $class, 'request_multiple' ),
				$multi,
				array(
					'timeout' => $max,
					'verify'  => $verify,
				)
			);
		} catch ( \Exception $e ) {
			foreach ( $requests as $key => $request ) {
				$out[ $key ] = new Engine_Exception( $e->getMessage(), 0 );
			}
			return $out;
		}

		foreach ( $requests as $key => $request ) {
			$response = isset( $responses[ $key ] ) ? $responses[ $key ] : null;
			if ( ! is_object( $response ) || $response instanceof \Exception || ! isset( $response->status_code ) ) {
				$out[ $key ] = new Engine_Exception( $response instanceof \Exception ? $response->getMessage() : 'Request failed', 0 );
				continue;
			}
			$headers = array();
			if ( isset( $response->headers ) && is_object( $response->headers ) && method_exists( $response->headers, 'getAll' ) ) {
				foreach ( $response->headers->getAll() as $name => $values ) {
					$headers[ strtolower( $name ) ] = implode( ', ', (array) $values );
				}
			}
			$out[ $key ] = array(
				'status'  => (int) $response->status_code,
				'body'    => (string) $response->body,
				'headers' => $headers,
			);
		}
		return $out;
	}

	/**
	 * The Requests class bundled with WordPress, if available.
	 *
	 * @return string|null
	 */
	private static function requests_class() {
		if ( function_exists( '_wp_http_get_object' ) ) {
			_wp_http_get_object();
		}
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return '\WpOrg\Requests\Requests';
		}
		if ( class_exists( '\Requests' ) ) {
			return '\Requests';
		}
		return null;
	}

	/**
	 * Whether WordPress is configured to use an HTTP proxy.
	 *
	 * @return bool
	 */
	private static function proxy_enabled() {
		return defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' );
	}

	/**
	 * User agent.
	 *
	 * @return string
	 */
	protected static function user_agent() {
		return 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ) . ' SHD-Translator/' . SHDT_VERSION;
	}

	/**
	 * Remember the last engine error for the admin screen.
	 *
	 * @param string $engine  Engine id.
	 * @param string $message Message.
	 */
	public static function log( $engine, $message ) {
		update_option(
			'shdt_last_error',
			array(
				'engine'  => $engine,
				'message' => wp_strip_all_tags( (string) $message ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Build an exception from an HTTP status.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @param array  $headers Headers.
	 * @return Engine_Exception
	 */
	protected function http_error( $status, $message, array $headers = array() ) {
		$pause = 0;
		if ( 429 === $status ) {
			$pause = isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ? max( 30, (int) $headers['retry-after'] ) : 120;
		} elseif ( in_array( $status, array( 401, 402, 403, 456 ), true ) ) {
			$pause = 1800;
		} elseif ( $status >= 500 ) {
			$pause = 120;
		} elseif ( 400 === $status || 404 === $status ) {
			$pause = 600;
		}
		/* translators: 1: engine name, 2: HTTP status, 3: error message */
		return new Engine_Exception( sprintf( __( '%1$s returned HTTP %2$d: %3$s', 'shd-translator' ), $this->label(), $status, $message ), $pause, $status );
	}

	/**
	 * Escape text for engines that expect HTML/XML, keeping placeholders as tags.
	 *
	 * @param string $text Text with placeholders.
	 * @return string
	 */
	protected static function escape_markup( $text ) {
		$parts = preg_split( Text::PLACEHOLDER, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 === $i % 4 ) {
				$out .= htmlspecialchars( $parts[ $i ], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				continue;
			}
			$out .= '<' . $parts[ $i ] . 'x' . $parts[ $i + 1 ] . $parts[ $i + 2 ] . '>';
			$i   += 2;
		}
		return $out;
	}

	/**
	 * Decode markup returned by an engine back to text with placeholders.
	 *
	 * @param string $text Engine output.
	 * @return string
	 */
	protected static function unescape_markup( $text ) {
		return Text::canonical_placeholders( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Language code for this engine.
	 *
	 * @param array $lang Language entry.
	 * @return string
	 */
	protected function code( array $lang ) {
		return isset( $lang['google'] ) ? $lang['google'] : $lang['code'];
	}
}
