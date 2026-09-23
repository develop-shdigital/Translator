<?php
/**
 * DeepL API (free and pro keys).
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class Deepl extends Base_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'deepl';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'DeepL', 'shd-translator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return '' !== (string) $this->settings->get( 'deepl_key', '' );
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
		return 20000;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function timeout() {
		return 30;
	}

	/**
	 * DeepL target code (EN-GB, PT-BR, ZH-HANS…).
	 *
	 * @param array $lang Language.
	 * @return string
	 */
	private function target_code( array $lang ) {
		$base = strtoupper( substr( $lang['code'], 0, 2 ) );
		switch ( $base ) {
			case 'EN':
				return 'en_US' === $lang['locale'] ? 'EN-US' : 'EN-GB';
			case 'PT':
				return 'pt-BR' === $lang['code'] || 'pt_BR' === $lang['locale'] ? 'PT-BR' : 'PT-PT';
			case 'ZH':
				return 'zh-TW' === $lang['code'] ? 'ZH-HANT' : 'ZH-HANS';
			case 'NO':
				return 'NB';
		}
		return $base;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$key  = (string) $this->settings->get( 'deepl_key', '' );
		$host = ':fx' === substr( $key, -3 ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';

		$escaped = array();
		foreach ( $texts as $text ) {
			$escaped[] = self::escape_markup( $text );
		}

		$source_code = strtoupper( substr( $source['code'], 0, 2 ) );
		$body        = array(
			'text'                => $escaped,
			'source_lang'         => 'NO' === $source_code ? 'NB' : $source_code,
			'target_lang'         => $this->target_code( $target ),
			'tag_handling'        => 'xml',
			'preserve_formatting' => true,
		);

		$formality = (string) $this->settings->get( 'deepl_formality', 'default' );
		if ( 'default' !== $formality ) {
			// "prefer_" variants never fail for languages without formality support.
			$body['formality'] = 0 === strpos( $formality, 'prefer_' ) ? $formality : 'prefer_' . $formality;
		}

		$ai_context = trim( (string) $this->settings->get( 'ai_context', '' ) );
		if ( '' !== $ai_context ) {
			$body['context'] = $ai_context;
		}

		return array(
			'url'     => $host . '/v2/translate',
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'DeepL-Auth-Key ' . $key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( $body, true );
		if ( 200 !== $status ) {
			$message = isset( $data['message'] ) ? $data['message'] : substr( wp_strip_all_tags( $body ), 0, 200 );
			throw $this->http_error( $status, $message, $headers );
		}
		$out = array();
		foreach ( isset( $data['translations'] ) ? array_values( (array) $data['translations'] ) : array() as $i => $row ) {
			if ( isset( $row['text'] ) && isset( $texts[ $i ] ) ) {
				$out[ $i ] = self::unescape_markup( $row['text'] );
			}
		}
		return count( $out ) === count( $texts ) ? $out : array();
	}
}
