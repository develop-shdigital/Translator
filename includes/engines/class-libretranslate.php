<?php
/**
 * LibreTranslate (self-hosted, open source).
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class Libretranslate extends Base_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'libretranslate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'LibreTranslate (self-hosted)', 'shd-translator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return '' !== (string) $this->settings->get( 'libre_url', '' );
	}

	/**
	 * Settings that still have to be filled in.
	 *
	 * @return string[]
	 */
	public function missing() {
		return $this->is_available() ? array() : array( __( 'Server URL', 'shd-translator' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function timeout() {
		return 60;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function code( array $lang ) {
		$map = array(
			'zh-CN' => 'zh',
			'zh-TW' => 'zt',
			'pt-BR' => 'pb',
			'he'    => 'he',
		);
		return isset( $map[ $lang['code'] ] ) ? $map[ $lang['code'] ] : strtolower( substr( $lang['code'], 0, 2 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$escaped = array();
		foreach ( $texts as $text ) {
			$escaped[] = self::escape_markup( $text );
		}
		$body = array(
			'q'      => $escaped,
			'source' => $this->code( $source ),
			'target' => $this->code( $target ),
			'format' => 'html',
		);
		$key  = (string) $this->settings->get( 'libre_key', '' );
		if ( '' !== $key ) {
			$body['api_key'] = $key;
		}
		return array(
			'url'     => untrailingslashit( (string) $this->settings->get( 'libre_url', '' ) ) . '/translate',
			'method'  => 'POST',
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( $body, true );
		if ( 200 !== $status ) {
			$message = isset( $data['error'] ) ? (string) $data['error'] : substr( wp_strip_all_tags( $body ), 0, 200 );
			throw $this->http_error( $status, $message, $headers );
		}
		$list = isset( $data['translatedText'] ) ? (array) $data['translatedText'] : array();
		$out  = array();
		foreach ( array_values( $list ) as $i => $value ) {
			if ( is_string( $value ) && isset( $texts[ $i ] ) ) {
				$out[ $i ] = self::unescape_markup( $value );
			}
		}
		return count( $out ) === count( $texts ) ? $out : array();
	}
}
