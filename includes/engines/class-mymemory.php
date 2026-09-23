<?php
/**
 * MyMemory (free, no key; a contact e-mail raises the daily quota).
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class Mymemory extends Base_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'mymemory';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'MyMemory (free, no key)', 'shd-translator' );
	}

	/**
	 * One string per request.
	 *
	 * @return int
	 */
	public function max_batch() {
		return 1;
	}

	/**
	 * The API rejects queries over 500 bytes.
	 *
	 * @return int
	 */
	public function max_chars() {
		return 450;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function concurrency() {
		return 5;
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
	protected function code( array $lang ) {
		return str_replace( '_', '-', $lang['locale'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$args  = array(
			'q'        => rawurlencode( $texts[0] ),
			'langpair' => rawurlencode( $this->code( $source ) . '|' . $this->code( $target ) ),
		);
		$email = (string) $this->settings->get( 'mymemory_email', '' );
		if ( '' !== $email ) {
			$args['de'] = rawurlencode( $email );
		}
		return array(
			'url'     => add_query_arg( $args, 'https://api.mymemory.translated.net/get' ),
			'method'  => 'GET',
			'headers' => array(),
			'body'    => null,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( $body, true );
		if ( 200 !== $status ) {
			throw $this->http_error( $status, substr( wp_strip_all_tags( $body ), 0, 200 ), $headers );
		}
		$text        = isset( $data['responseData']['translatedText'] ) ? (string) $data['responseData']['translatedText'] : '';
		$code        = isset( $data['responseStatus'] ) ? (int) $data['responseStatus'] : 0;
		$quota_ended = false !== stripos( $text, 'MYMEMORY WARNING' ) || ! empty( $data['quotaFinished'] ) || 429 === $code;
		if ( $quota_ended ) {
			// Never store the warning as a translation.
			throw new Engine_Exception( __( 'MyMemory daily quota reached.', 'shd-translator' ), 6 * HOUR_IN_SECONDS, 429 );
		}
		if ( 200 !== $code || '' === $text ) {
			return array();
		}
		return array( 0 => self::unescape_markup( $text ) );
	}
}
