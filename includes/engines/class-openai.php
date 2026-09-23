<?php
/**
 * OpenAI-compatible chat completions (OpenAI, OpenRouter, Mistral, local Ollama…).
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

class OpenAI extends AI_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'OpenAI-compatible API', 'shd-translator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		$base = (string) $this->settings->get( 'openai_base', '' );
		// Local servers (Ollama, LM Studio) do not need a key.
		$local = (bool) preg_match( '~^https?://(localhost|127\.0\.0\.1|\[::1\])~', $base );
		return '' !== $base && '' !== (string) $this->settings->get( 'openai_model', '' ) && ( $local || '' !== (string) $this->settings->get( 'openai_key', '' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_request( array $texts, array $source, array $target, array $context ) {
		$headers = array( 'Content-Type' => 'application/json' );
		$key     = (string) $this->settings->get( 'openai_key', '' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$body = array(
			'model'           => (string) $this->settings->get( 'openai_model', '' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt( $source, $target ),
				),
				array(
					'role'    => 'user',
					'content' => $this->user_message( $texts, $context ),
				),
			),
			'response_format' => array( 'type' => 'json_object' ),
		);

		return array(
			'url'     => untrailingslashit( (string) $this->settings->get( 'openai_base', '' ) ) . '/chat/completions',
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( apply_filters( 'shdt_openai_request', $body, $texts, $target ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( $body, true );
		if ( 200 !== $status ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( wp_strip_all_tags( $body ), 0, 200 );
			throw $this->http_error( $status, $message, $headers );
		}
		$content = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		return $this->parse_translations( $content, $texts );
	}
}
