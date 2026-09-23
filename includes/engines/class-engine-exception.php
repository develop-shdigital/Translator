<?php
/**
 * Raised when an engine cannot be used right now.
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

class Engine_Exception extends \Exception {

	/**
	 * The whole engine is unusable (bad key, no credit, rate limit, outage).
	 */
	const SCOPE_ENGINE = 'engine';

	/**
	 * Only the current target language is affected (e.g. unsupported language pair).
	 */
	const SCOPE_LANGUAGE = 'language';

	/**
	 * Seconds to pause (circuit breaker). 0 = only this request failed, nothing is paused.
	 *
	 * @var int
	 */
	public $pause = 0;

	/**
	 * What the pause applies to: SCOPE_ENGINE or SCOPE_LANGUAGE.
	 *
	 * @var string
	 */
	public $scope = self::SCOPE_ENGINE;

	/**
	 * Whether the failure counts against the texts of the batch (a retry limit
	 * applies). Problems of the service itself (no credit, bad key, outage,
	 * network) never use up the texts' attempts.
	 *
	 * @var bool
	 */
	public $counts = true;

	/**
	 * Whether sending the batch again in smaller parts may work (wrong number of
	 * answers, answer cut off, one text refused).
	 *
	 * @var bool
	 */
	public $split = false;

	/**
	 * Constructor.
	 *
	 * @param string    $message Message.
	 * @param int       $pause   Seconds to pause.
	 * @param int       $code    HTTP status.
	 * @param string    $scope   SCOPE_ENGINE or SCOPE_LANGUAGE.
	 * @param bool|null $counts  Counts against the texts; default: only when nothing is paused.
	 * @param bool      $split   Smaller batches may succeed.
	 */
	public function __construct( $message, $pause = 0, $code = 0, $scope = self::SCOPE_ENGINE, $counts = null, $split = false ) {
		parent::__construct( $message, (int) $code );
		$this->pause  = (int) $pause;
		$this->scope  = self::SCOPE_LANGUAGE === $scope ? self::SCOPE_LANGUAGE : self::SCOPE_ENGINE;
		$this->counts = null === $counts ? 0 === $this->pause : (bool) $counts;
		$this->split  = (bool) $split;
	}

	/**
	 * What went wrong, independent of the language (see describe()).
	 *
	 * @var string
	 */
	public $kind = '';

	/**
	 * The service's own error text, stored untranslated.
	 *
	 * @var string
	 */
	public $raw = '';

	/**
	 * Set the kind of error and the service's own message.
	 *
	 * Error records are shown to the site owner later, maybe in another language
	 * than the visitor's request that produced them, so they are stored as
	 * kind + raw message and put into words when displayed.
	 *
	 * @param string $kind Kind (see describe()).
	 * @param string $raw  Service message.
	 * @return $this
	 */
	public function kind( $kind, $raw = '' ) {
		$this->kind = (string) $kind;
		$this->raw  = (string) $raw;
		return $this;
	}

	/**
	 * Language-independent record of this error.
	 *
	 * @return array { kind, raw, status, message }
	 */
	public function record() {
		return array(
			'kind'    => $this->kind,
			'raw'     => $this->raw,
			'status'  => $this->getCode(),
			'message' => $this->getMessage(),
		);
	}

	/**
	 * Put an error record into words, in the current (admin) language.
	 *
	 * @param array  $record Record from record() (older records: only 'message').
	 * @param string $label  Engine name.
	 * @return string
	 */
	public static function describe( array $record, $label ) {
		$raw    = isset( $record['raw'] ) ? (string) $record['raw'] : '';
		$status = isset( $record['status'] ) ? (int) $record['status'] : 0;
		switch ( isset( $record['kind'] ) ? $record['kind'] : '' ) {
			case 'http':
				/* translators: 1: engine name, 2: HTTP status, 3: error message */
				return sprintf( __( '%1$s returned HTTP %2$d: %3$s', 'shd-translator' ), $label, $status, $raw );
			case 'billing':
				/* translators: %s: error message from the service */
				return sprintf( __( 'The API account has no credit left or reached its spending limit (%s). A ChatGPT subscription does not include API usage: add credit or raise the limit in the billing settings of your API account, then click "Test the saved engine".', 'shd-translator' ), $raw );
			case 'network':
				/* translators: 1: engine name, 2: error message, e.g. "cURL error 7: Failed to connect" */
				return sprintf( __( '%1$s could not be reached: %2$s', 'shd-translator' ), $label, $raw );
			case 'no_completion':
				/* translators: %s: engine name */
				return sprintf( __( '%s did not return a chat completion. Check the API base URL.', 'shd-translator' ), $label );
			case 'incomplete':
				/* translators: %s: engine */
				return sprintf( __( '%s returned an incomplete answer.', 'shd-translator' ), $label );
			case 'echo':
				/* translators: %s: engine */
				return sprintf( __( '%s returned the texts untranslated.', 'shd-translator' ), $label );
			case 'refusal':
				/* translators: 1: engine name, 2: reason given by the model */
				return '' !== $raw ? sprintf( __( '%1$s declined to translate this batch: %2$s', 'shd-translator' ), $label, $raw ) : sprintf( /* translators: %s: engine name */ __( '%s declined to translate this batch.', 'shd-translator' ), $label );
			case 'length':
				/* translators: %s: engine name */
				return sprintf( __( '%s answer was cut off (batch too large).', 'shd-translator' ), $label );
			case 'filter':
				/* translators: %s: engine name */
				return sprintf( __( '%s blocked this batch with its content filter.', 'shd-translator' ), $label );
			case 'overloaded':
				/* translators: %s: engine name */
				return sprintf( __( '%s is temporarily overloaded.', 'shd-translator' ), $label );
			case 'rate_limited_server':
				return __( 'Google temporarily rate-limited this server. Translations continue in the background, or use "Translate with my browser" under Tools.', 'shd-translator' );
			case 'unexpected':
				/* translators: %s: engine name */
				return sprintf( __( 'Unexpected response from %s.', 'shd-translator' ), $label );
			case 'daily_quota':
				/* translators: %s: engine name */
				return sprintf( __( '%s daily quota reached.', 'shd-translator' ), $label );
		}
		return isset( $record['message'] ) ? (string) $record['message'] : $raw;
	}
}
