<?php
/**
 * Google Translate (free web endpoint, no API key).
 *
 * Uses the public endpoint that powers Google's browser translation
 * features. It needs no key or account, accepts batches and keeps inline
 * placeholder tags. It is unofficial: there is no SLA and Google may
 * rate-limit busy server IPs, which is why every result is cached and the
 * engine pauses itself on HTTP 429.
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class Google extends Base_Engine {

	const ENDPOINT = 'https://clients5.google.com/translate_a/t';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'google';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Google Translate (free, no key)', 'shd-translator' );
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
		return 4000;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function timeout() {
		return 15;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$query = array();
		foreach ( $texts as $text ) {
			$query[] = 'q=' . rawurlencode( self::escape_markup( $text ) );
		}
		$url = add_query_arg(
			array(
				'client' => 'dict-chrome-ex',
				'sl'     => rawurlencode( $this->code( $source ) ),
				'tl'     => rawurlencode( $this->code( $target ) ),
			),
			self::ENDPOINT
		);
		return array(
			'url'     => apply_filters( 'shdt_google_endpoint', $url, $source, $target ),
			'method'  => 'POST',
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8' ),
			'body'    => implode( '&', $query ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		if ( 429 === $status || 403 === $status ) {
			// Google blocks by IP; back off for a while instead of slowing every page view.
			throw new Engine_Exception( __( 'Google temporarily rate-limited this server. Translations continue in the background, or use "Translate with my browser" under Tools.', 'shd-translator' ), 1800, $status );
		}
		if ( 200 !== $status ) {
			throw $this->http_error( $status, substr( wp_strip_all_tags( $body ), 0, 200 ), $headers );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new Engine_Exception( __( 'Unexpected response from Google Translate.', 'shd-translator' ), 300 );
		}
		// A single string may come back unwrapped.
		if ( 1 === count( $texts ) && isset( $data[0] ) && is_string( $data[0] ) && count( $data ) > 1 && ! is_string( $data[1] ) ) {
			$data = array( $data[0] );
		}

		$out = array();
		foreach ( array_values( $data ) as $i => $item ) {
			if ( is_array( $item ) ) {
				$item = isset( $item[0] ) ? $item[0] : null;
			}
			if ( is_string( $item ) && isset( $texts[ $i ] ) ) {
				$out[ $i ] = self::unescape_markup( $item );
			}
		}
		if ( count( $out ) !== count( $texts ) ) {
			// Misaligned batch: do not risk mixing strings up.
			return array();
		}
		return $out;
	}
}
