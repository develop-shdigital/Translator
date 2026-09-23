<?php
/**
 * Claude (Anthropic Messages API).
 *
 * Talks to the REST API through the WordPress HTTP API instead of the PHP SDK:
 * a plugin cannot safely bundle Composer dependencies (version clashes with
 * other plugins) and must keep running on PHP 7.4.
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class Anthropic extends AI_Engine {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Suggested models for the settings screen.
	 *
	 * @return array
	 */
	public static function models() {
		return array(
			'claude-opus-5'    => 'Claude Opus 5 (best quality)',
			'claude-sonnet-5'  => 'Claude Sonnet 5 (fast, lower cost)',
			'claude-haiku-4-5' => 'Claude Haiku 4.5 (fastest, lowest cost)',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Claude AI (Anthropic)', 'shd-translator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return '' !== (string) $this->settings->get( 'anthropic_key', '' );
	}

	/**
	 * Configured model.
	 *
	 * @return string
	 */
	private function model() {
		$model = trim( (string) $this->settings->get( 'anthropic_model', '' ) );
		return '' !== $model ? $model : 'claude-opus-5';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$model   = $this->model();
		$headers = array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => (string) $this->settings->get( 'anthropic_key', '' ),
			'anthropic-version' => '2023-06-01',
		);

		$body = array(
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => $this->system_prompt( $source, $target ),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $this->user_message( $texts, $context ),
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $this->schema(),
				),
			),
		);

		// Translation is a light task: low effort keeps it fast and inexpensive.
		if ( preg_match( '/^claude-(opus|sonnet|fable|mythos)-(4-[678]|5)/', $model ) ) {
			$body['output_config']['effort'] = 'low';
		}

		// Let the API re-run a request declined by a safety classifier on a suitable model
		// instead of failing it (false positives on marketing copy would otherwise stall a page).
		if ( preg_match( '/^claude-(opus|fable)-5/', $model ) ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}

		return array(
			'url'     => self::ENDPOINT,
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( apply_filters( 'shdt_anthropic_request', $body, $texts, $target ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( $body, true );

		if ( 200 !== $status ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( wp_strip_all_tags( $body ), 0, 200 );
			if ( 529 === $status ) {
				throw new Engine_Exception( __( 'Claude is temporarily overloaded.', 'shd-translator' ), 60, $status );
			}
			throw $this->http_error( $status, $message, $headers );
		}

		$stop = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			throw new Engine_Exception( __( 'Claude declined to translate this batch.', 'shd-translator' ), 0 );
		}
		if ( 'max_tokens' === $stop ) {
			throw new Engine_Exception( __( 'Claude answer was cut off (batch too large).', 'shd-translator' ), 0 );
		}

		$text = '';
		foreach ( isset( $data['content'] ) ? (array) $data['content'] : array() as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return $this->parse_translations( $text, $texts );
	}
}
