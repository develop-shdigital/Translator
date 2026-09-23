<?php
/**
 * OpenAI-compatible engine: error handling, answer formats, parsing, retries.
 *
 * @package SHDT
 */

use SHDT\Admin\Admin;
use SHDT\Engines\AI_Engine;
use SHDT\Engines\Engine_Exception;
use SHDT\Settings;

/**
 * OpenAI engine with canned HTTP answers instead of the network.
 */
class SHDT_Test_OpenAI extends SHDT\Engines\OpenAI {

	/**
	 * Answers to hand out, in order: [ status, body ].
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Request bodies sent (decoded).
	 *
	 * @var array
	 */
	public $bodies = array();

	protected function system_prompt( array $source, array $target ) {
		return 'Translate. Answer as JSON.';
	}

	protected function send( array $requests ) {
		$out = array();
		foreach ( $requests as $key => $request ) {
			$this->bodies[] = json_decode( $request['body'], true );
			$answer         = array_shift( $this->answers );
			$out[ $key ]    = array(
				'status'  => $answer[0],
				'body'    => is_string( $answer[1] ) ? $answer[1] : json_encode( $answer[1] ),
				'headers' => isset( $answer[2] ) ? $answer[2] : array(),
			);
		}
		return $out;
	}

	public function request( array $texts ) {
		return json_decode( $this->build_request( $texts, array(), array(), array() )['body'], true );
	}

	public function parse( array $texts, $status, $body, array $headers = array() ) {
		return $this->parse_response( $texts, $status, is_string( $body ) ? $body : json_encode( $body ), $headers );
	}
}

/**
 * A chat completion answer with this content.
 *
 * @param mixed  $content Content (arrays are JSON encoded).
 * @param string $finish  finish_reason.
 * @return array
 */
function shdt_test_completion( $content, $finish = 'stop' ) {
	return array(
		'choices' => array(
			array(
				'message'       => array( 'content' => is_string( $content ) ? $content : json_encode( $content ) ),
				'finish_reason' => $finish,
			),
		),
	);
}

/**
 * Engine with these OpenAI settings.
 *
 * @param array $settings Settings.
 * @return SHDT_Test_OpenAI
 */
function shdt_test_openai( array $settings = array() ) {
	$GLOBALS['shdt_test_options'] = array(
		'shdt_settings' => array_merge(
			array(
				'openai_base'  => 'https://api.openai.com/v1',
				'openai_key'   => 'sk-test',
				'openai_model' => 'gpt-4o-mini',
			),
			$settings
		),
	);
	return new SHDT_Test_OpenAI( new Settings() );
}

/**
 * Exception thrown by a callback, or null.
 *
 * @param callable $fn Callback.
 * @return Engine_Exception|null
 */
function shdt_test_catch( $fn ) {
	try {
		$fn();
	} catch ( Engine_Exception $e ) {
		return $e;
	}
	return null;
}

// Exception defaults: service problems never count against the texts.
shdt_assert( ( new Engine_Exception( 'x' ) )->counts, 'unpaused errors count against texts' );
shdt_assert( ! ( new Engine_Exception( 'x', 60 ) )->counts, 'pausing errors do not count against texts' );

// Error answers.
$e = shdt_test_openai();

$quota = $e->api_error( 429, array( 'message' => 'You exceeded your current quota, please check your plan and billing details.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota' ), '' );
shdt_assert( 1800 === $quota->pause && ! $quota->counts && Engine_Exception::SCOPE_ENGINE === $quota->scope, 'no credit (insufficient_quota) pauses 30 minutes', $quota->getMessage() );
shdt_assert_contains( $quota->getMessage(), 'no credit', 'no credit message explains the cause' );

$credit = $e->api_error( 429, array( 'message' => 'Credit balance exhausted', 'type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted' ), '' );
shdt_assert( 1800 === $credit->pause, 'credit_balance_exhausted pauses 30 minutes' );

$spend = $e->api_error( 429, array( 'message' => 'Spend limit reached', 'type' => 'requests', 'code' => 'organization_spend_limit_exceeded' ), '' );
shdt_assert( 1800 === $spend->pause, 'spend limit pauses 30 minutes' );

$rate = $e->api_error( 429, array( 'message' => 'Rate limit reached for gpt-4o-mini. Visit https://platform.openai.com/account/billing to increase your limits.', 'type' => 'requests', 'code' => 'rate_limit_exceeded' ), '' );
shdt_assert( 120 === $rate->pause && ! $rate->counts, 'real rate limit keeps the short pause', (string) $rate->pause );

$retry = $e->api_error( 429, array( 'message' => 'Slow down', 'code' => 'rate_limit_exceeded' ), '', array( 'retry-after' => '20' ) );
shdt_assert( 30 === $retry->pause, 'Retry-After is honoured with a minimum', (string) $retry->pause );

$key = $e->api_error( 401, array( 'message' => 'Incorrect API key provided', 'code' => 'invalid_api_key' ), '' );
shdt_assert( 1800 === $key->pause && ! $key->counts, 'bad key pauses 30 minutes' );

$model = $e->api_error( 404, array( 'message' => 'The model `gpt-9` does not exist or you do not have access to it.', 'code' => 'model_not_found' ), '' );
shdt_assert( 1800 === $model->pause, 'unknown model pauses 30 minutes' );

$context = $e->api_error( 400, array( 'message' => "This model's maximum context length is 128000 tokens.", 'code' => 'context_length_exceeded' ), '' );
shdt_assert( 0 === $context->pause && $context->counts && $context->split, 'too long: batch problem, split, no pause' );

$plain = $e->api_error( 400, array( 'message' => "This model's maximum context length is 128000 tokens." ), '' );
shdt_assert( 0 === $plain->pause, 'a message mentioning "model" alone is not an account problem', (string) $plain->pause );

$lmstudio = $e->api_error( 400, "'response_format.type' must be 'json_schema' or 'text'", '{"error":"\'response_format.type\' must be \'json_schema\' or \'text\'"}' );
shdt_assert( "OpenAI-compatible API returned HTTP 400: 'response_format.type' must be 'json_schema' or 'text'" === $lmstudio->getMessage(), 'string error messages are used as they are', $lmstudio->getMessage() );
shdt_assert( 0 === $lmstudio->pause && ! $lmstudio->counts, 'unsupported answer format does not pause' );

// Successful answers.
$texts = array( 'One', 'Two', 'Three' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( array( 'translations' => array( 'Eins', 'Zwei', 'Drei' ) ) ) ), 'plain answer' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( "```json\n{\"translations\": [\"Eins\", \"Zwei\", \"Drei\"]}\n```" ) ), 'code fences' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( array( 'translations' => array( '0' => 'Eins', '1' => 'Zwei', '2' => 'Drei' ) ) ) ), 'numbered object' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( array( 'translations' => array( array( 'text' => 'Eins' ), array( 'text' => 'Zwei' ), array( 'text' => 'Drei' ) ) ) ) ), 'list of objects' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( array( 'result' => array( 'translations' => array( 'Eins', 'Zwei', 'Drei' ) ) ) ) ), 'nested result' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === $e->parse( $texts, 200, shdt_test_completion( 'Sure {sic}! Here: {"translations": ["Eins", "Zwei", "Drei"]} Enjoy {x}.' ) ), 'prose around the JSON' );
shdt_assert( array( 'Eins', 'Zwei', 'Drei' ) === AI_Engine::extract_translations( '["Eins", "Zwei", "Drei"]' ), 'bare list' );

$fewer = shdt_test_catch( function () use ( $e, $texts ) {
	$e->parse( $texts, 200, shdt_test_completion( array( 'translations' => array( 'Eins', 'Zwei' ) ) ) );
} );
shdt_assert( $fewer && $fewer->split && 0 === $fewer->pause, 'missing item: split and retry' );

$refused = shdt_test_catch( function () use ( $e, $texts ) {
	$e->parse( $texts, 200, array( 'choices' => array( array( 'message' => array( 'content' => null, 'refusal' => 'I cannot help with that.' ), 'finish_reason' => 'stop' ) ) ) );
} );
shdt_assert( $refused && $refused->split && false !== strpos( $refused->getMessage(), 'I cannot help' ), 'refusal is reported with its reason' );

$cut = shdt_test_catch( function () use ( $e, $texts ) {
	$e->parse( $texts, 200, shdt_test_completion( '{"translations": ["Eins", "Zw', 'length' ) );
} );
shdt_assert( $cut && $cut->split && false !== strpos( $cut->getMessage(), 'cut off' ), 'cut-off answer' );

// Request format per server.
$body = $e->request( $texts );
shdt_assert( 'json_schema' === $body['response_format']['type'] && true === $body['response_format']['json_schema']['strict'], 'OpenAI gets strict structured outputs' );
$items = $body['response_format']['json_schema']['schema']['properties']['translations'];
shdt_assert( 3 === $items['minItems'] && 3 === $items['maxItems'], 'schema requires one answer per text' );

$empty_model = shdt_test_openai( array( 'openai_model' => '' ) );
shdt_assert( 'gpt-4o-mini' === $empty_model->request( $texts )['model'] && $empty_model->is_available(), 'OpenAI without a model uses the default model' );

$local = shdt_test_openai(
	array(
		'openai_base' => 'http://localhost:11434/v1',
		'openai_key'  => '',
		'openai_model' => 'llama3.1',
	)
);
shdt_assert( $local->is_available() && 'json_object' === $local->request( $texts )['response_format']['type'], 'local server: no key needed, JSON mode' );

$router = shdt_test_openai(
	array(
		'openai_base'  => 'https://openrouter.ai/api/v1',
		'openai_key'   => '',
		'openai_model' => '',
	)
);
shdt_assert( array( 'API key', 'Model' ) === $router->missing() && ! $router->is_available(), 'missing settings are named', implode( ',', $router->missing() ) );

// A server that rejects structured outputs gets JSON mode, in the same call.
$e            = shdt_test_openai();
$e->answers[] = array( 400, array( 'error' => array( 'message' => "Invalid parameter: 'response_format' of type 'json_schema' is not supported with this model.", 'param' => 'response_format' ) ) );
$e->answers[] = array( 200, shdt_test_completion( array( 'translations' => array( 'Eins', 'Zwei', 'Drei' ) ) ) );
$out          = $e->translate_batches( array( $texts ), array(), array(), array(), microtime( true ) + 30 );
shdt_assert( array( array( 'Eins', 'Zwei', 'Drei' ) ) === $out, 'rejected format: retried with JSON mode', json_encode( $out ) );
shdt_assert( 'json_schema' === $e->bodies[0]['response_format']['type'] && 'json_object' === $e->bodies[1]['response_format']['type'], 'formats tried in order' );
shdt_assert( 'json_object' === $e->format(), 'working format is remembered' );
shdt_assert( ! $e->failures() && ! $e->error(), 'no failure left after the retry' );

// A wrong number of answers: the batch is split instead of going to another engine.
$e            = shdt_test_openai();
$four         = array( 'One', 'Two', 'Three', 'Four' );
$e->answers[] = array( 200, shdt_test_completion( array( 'translations' => array( 'Eins', 'Zwei und Drei', 'Vier' ) ) ) );
$e->answers[] = array( 200, shdt_test_completion( array( 'translations' => array( 'Eins', 'Zwei' ) ) ) );
$e->answers[] = array( 200, shdt_test_completion( array( 'translations' => array( 'Drei', 'Vier' ) ) ) );
$out          = $e->translate_batches( array( $four ), array(), array(), array(), microtime( true ) + 30 );
ksort( $out[0] );
shdt_assert( array( 'Eins', 'Zwei', 'Drei', 'Vier' ) === $out[0], 'mismatched batch completed from its halves', json_encode( $out ) );
shdt_assert( 3 === count( $e->bodies ) && array( 0 ) === $e->sent(), 'halves sent once each, caller sees its own batch' );

// No credit: engine error, texts not blamed, no retries.
$e            = shdt_test_openai();
$e->answers[] = array( 429, array( 'error' => array( 'message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota' ) ) );
$out          = $e->translate_batches( array( $texts ), array(), array(), array(), microtime( true ) + 30 );
$failures     = $e->failures();
shdt_assert( ! $out && $e->error() && 1800 === $e->error()->pause && ! $failures[0]->counts && 1 === count( $e->bodies ), 'no credit: paused, not retried, attempts kept' );

// Engine health: a failure is remembered until the engine answers again.
$GLOBALS['shdt_test_options'] = array();
SHDT\Engines\Base_Engine::log( 'openai', 'HTTP 429: no credit' );
$failing = SHDT\Engines\Base_Engine::failing( 'openai' );
shdt_assert( $failing && 'HTTP 429: no credit' === $failing['message'], 'failure is recorded' );
$GLOBALS['shdt_test_options']['shdt_engine_health']['openai']['time'] = time() - 10;
SHDT\Engines\Base_Engine::healthy( 'openai' );
shdt_assert( null === SHDT\Engines\Base_Engine::failing( 'openai' ), 'a successful answer ends the failure' );

// API keys typed or pasted into the settings.
shdt_assert( 'sk-stored' === Admin::normalize_key( Admin::MASK, 'sk-stored' ), 'untouched mask keeps the key' );
shdt_assert( 'sk-new-456' === Admin::normalize_key( Admin::MASK . 'sk-new-456', 'sk-stored' ), 'text pasted after the mask replaces the key' );
shdt_assert( 'sk-test-123' === Admin::normalize_key( 'Bearer sk-test-123', '' ), '"Bearer " prefix removed' );
shdt_assert( 'sk-test-123' === Admin::normalize_key( " sk-test\n-123 ", '' ), 'whitespace and line breaks removed' );
shdt_assert( '' === Admin::normalize_key( '', 'sk-stored' ), 'empty field removes the key' );
shdt_assert( Admin::same_server( 'https://api.openai.com/v1/', 'https://API.OpenAI.com/v1' ), 'same server despite case and slash' );
shdt_assert( Admin::same_server( 'https://api.openai.com/v1', 'https://api.openai.com:443/v2' ), 'path and default port do not matter' );
shdt_assert( ! Admin::same_server( 'http://api.openai.com/v1', 'https://api.openai.com/v1' ), 'http is another server' );
shdt_assert( ! Admin::same_server( 'https://openrouter.ai/api/v1', 'https://api.openai.com/v1' ), 'other host is another server' );
