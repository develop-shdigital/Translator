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

	const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Answer formats, from the most to the least strict. Servers that reject a
	 * format get the next one; the working format is remembered per server and model.
	 */
	const FORMATS = array( 'json_schema', 'json_object', 'none' );

	/**
	 * OpenAI error codes of HTTP 429 answers that mean "no credit / limit reached"
	 * rather than "too many requests right now". Retrying does not help.
	 */
	const BILLING_CODES = array( 'insufficient_quota', 'credit_balance_exhausted', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded', 'billing_not_active', 'billing_hard_limit_reached' );

	/**
	 * Error codes caused by one batch (too long, flagged, invalid output), not by the account.
	 */
	const BATCH_CODES = array( 'context_length_exceeded', 'string_above_max_length', 'invalid_prompt', 'json_validate_failed', 'content_filter' );

	/**
	 * Set when the server rejected the answer format during the last run.
	 *
	 * @var bool
	 */
	private $format_rejected = false;

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
	 * API base URL without a trailing slash.
	 *
	 * @return string
	 */
	private function base() {
		return untrailingslashit( trim( (string) $this->settings->get( 'openai_base', '' ) ) );
	}

	/**
	 * Whether the base URL is OpenAI's own API.
	 *
	 * @return bool
	 */
	private function is_openai() {
		return 'api.openai.com' === strtolower( (string) wp_parse_url( $this->base(), PHP_URL_HOST ) );
	}

	/**
	 * Whether the base URL is a server on this machine (Ollama, LM Studio).
	 *
	 * @return bool
	 */
	private function is_local() {
		return (bool) preg_match( '~^https?://(localhost|127\.0\.0\.1|\[::1\])([:/]|$)~i', $this->base() );
	}

	/**
	 * Model name; OpenAI's own API falls back to the default model.
	 *
	 * @return string
	 */
	public function model() {
		$model = trim( (string) $this->settings->get( 'openai_model', '' ) );
		return '' === $model && $this->is_openai() ? self::DEFAULT_MODEL : $model;
	}

	/**
	 * Settings that still have to be filled in before the engine can work.
	 *
	 * @return string[] Field labels.
	 */
	public function missing() {
		$missing = array();
		if ( '' === $this->base() ) {
			$missing[] = __( 'API base URL', 'shd-translator' );
		}
		if ( '' === (string) $this->settings->get( 'openai_key', '' ) && ! $this->is_local() ) {
			$missing[] = __( 'API key', 'shd-translator' );
		}
		if ( '' === $this->model() ) {
			$missing[] = __( 'Model', 'shd-translator' );
		}
		return $missing;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return ! $this->missing();
	}

	/**
	 * Answer format to request from this server and model.
	 *
	 * @return string One of self::FORMATS.
	 */
	public function format() {
		$saved = get_option( 'shdt_openai_format', array() );
		$key   = md5( strtolower( $this->base() ) . '|' . $this->model() );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) && in_array( $saved[ $key ], self::FORMATS, true ) ) {
			return $saved[ $key ];
		}
		// Structured outputs guarantee one answer per text on OpenAI itself; other
		// servers differ in what they support, so they start with plain JSON mode.
		return $this->is_openai() ? 'json_schema' : 'json_object';
	}

	/**
	 * Remember the next simpler answer format after the server rejected the current one.
	 *
	 * @return bool False when there is nothing simpler left.
	 */
	private function step_down() {
		$index = array_search( $this->format(), self::FORMATS, true );
		if ( false === $index || ! isset( self::FORMATS[ $index + 1 ] ) ) {
			return false;
		}
		$saved = get_option( 'shdt_openai_format', array() );
		$saved = is_array( $saved ) ? array_slice( $saved, -20, null, true ) : array();

		$saved[ md5( strtolower( $this->base() ) . '|' . $this->model() ) ] = self::FORMATS[ $index + 1 ];
		update_option( 'shdt_openai_format', $saved, false );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Batches rejected because of the answer format are sent once more with a simpler format.
	 */
	public function translate_batches( array $batches, array $source, array $target, array $context, $deadline ) {
		$this->format_rejected = false;
		$results               = parent::translate_batches( $batches, $source, $target, $context, $deadline );
		if ( ! $this->format_rejected || ! $this->step_down() ) {
			return $results;
		}

		$retry = array_diff_key( $batches, $results );
		if ( ! $retry || $this->error || microtime( true ) >= $deadline ) {
			return $results;
		}
		$sent     = $this->sent;
		$failures = $this->failures;
		$more     = parent::translate_batches( $retry, $source, $target, $context, $deadline );

		$this->sent     = array_values( array_unique( array_merge( $sent, $this->sent ) ) );
		$this->failures = array_replace( array_diff_key( $failures, $more ), $this->failures );
		return $results + $more;
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
			'model'    => $this->model(),
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt( $source, $target ),
				),
				array(
					'role'    => 'user',
					'content' => $this->user_message( $texts, $context ),
				),
			),
		);

		$format = $this->format();
		if ( 'json_schema' === $format ) {
			$schema = $this->schema();

			$schema['properties']['translations']['minItems'] = count( $texts );
			$schema['properties']['translations']['maxItems'] = count( $texts );

			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'translations',
					'strict' => true,
					'schema' => $schema,
				),
			);
		} elseif ( 'json_object' === $format ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		return array(
			'url'     => $this->base() . '/chat/completions',
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( apply_filters( 'shdt_openai_request', $body, $texts, $target ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( array $texts, $status, $body, array $headers ) {
		$data = json_decode( (string) $body, true );
		if ( 200 !== $status ) {
			throw $this->api_error( $status, is_array( $data ) && isset( $data['error'] ) ? $data['error'] : null, (string) $body, $headers );
		}

		$choice  = isset( $data['choices'][0] ) && is_array( $data['choices'][0] ) ? $data['choices'][0] : array();
		$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
		$finish  = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

		if ( ! empty( $message['refusal'] ) && is_string( $message['refusal'] ) ) {
			/* translators: 1: engine name, 2: reason given by the model */
			throw new Engine_Exception( sprintf( __( '%1$s declined to translate this batch: %2$s', 'shd-translator' ), $this->label(), $message['refusal'] ), 0, 0, Engine_Exception::SCOPE_ENGINE, true, true );
		}
		if ( 'length' === $finish ) {
			/* translators: %s: engine name */
			throw new Engine_Exception( sprintf( __( '%s answer was cut off (batch too large).', 'shd-translator' ), $this->label() ), 0, 0, Engine_Exception::SCOPE_ENGINE, true, true );
		}
		if ( 'content_filter' === $finish ) {
			/* translators: %s: engine name */
			throw new Engine_Exception( sprintf( __( '%s blocked this batch with its content filter.', 'shd-translator' ), $this->label() ), 0, 0, Engine_Exception::SCOPE_ENGINE, true, true );
		}

		$content = isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';
		return $this->parse_translations( $content, $texts );
	}

	/**
	 * Build the exception for an error answer, using OpenAI's error type and code.
	 *
	 * @param int               $status  HTTP status.
	 * @param array|string|null $error   The "error" member of the answer.
	 * @param string            $body    Raw body.
	 * @param array             $headers Lower-case headers.
	 * @return Engine_Exception
	 */
	public function api_error( $status, $error, $body, array $headers = array() ) {
		$status  = (int) $status;
		$type    = is_array( $error ) && isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : '';
		$code    = is_array( $error ) && isset( $error['code'] ) && is_scalar( $error['code'] ) ? (string) $error['code'] : '';
		$param   = is_array( $error ) && isset( $error['param'] ) && is_string( $error['param'] ) ? $error['param'] : '';
		$message = '';
		if ( is_array( $error ) && isset( $error['message'] ) && is_string( $error['message'] ) ) {
			$message = $error['message'];
		} elseif ( is_string( $error ) ) {
			$message = $error; // LM Studio and others: {"error": "text"}.
		}
		if ( '' === trim( $message ) ) {
			$message = substr( wp_strip_all_tags( $body ), 0, 200 );
		}

		// No credit, spend limit or billing not set up: waiting does not help.
		if ( 429 === $status && ( 'insufficient_quota' === $type || in_array( $code, self::BILLING_CODES, true ) ) ) {
			return new Engine_Exception(
				sprintf(
					/* translators: %s: error message from the service */
					__( 'The API account has no credit left or reached its spending limit (%s). A ChatGPT subscription does not include API usage: add credit or raise the limit in the billing settings of your API account, then click "Test the saved engine".', 'shd-translator' ),
					$message
				),
				(int) apply_filters( 'shdt_billing_pause', 1800 ),
				$status,
				Engine_Exception::SCOPE_ENGINE,
				false
			);
		}

		// The server does not support the requested answer format: use a simpler one.
		if ( 400 === $status && 'none' !== $this->format() && ( 0 === strpos( $param, 'response_format' ) || preg_match( '/response_format|json_schema|json_object|structured output/i', $message ) ) ) {
			$this->format_rejected = true;
			return new Engine_Exception( $this->error_message( $status, $message ), 0, $status, Engine_Exception::SCOPE_ENGINE, false );
		}

		if ( in_array( $code, self::BATCH_CODES, true ) ) {
			return new Engine_Exception( $this->error_message( $status, $message ), 0, $status, Engine_Exception::SCOPE_ENGINE, true, 'context_length_exceeded' === $code || 'string_above_max_length' === $code );
		}
		if ( in_array( $code, array( 'model_not_found', 'invalid_api_key', 'unsupported_country_region_territory' ), true ) ) {
			return new Engine_Exception( $this->error_message( $status, $message ), 1800, $status, Engine_Exception::SCOPE_ENGINE, false );
		}

		return $this->http_error( $status, $message, $headers );
	}
}
