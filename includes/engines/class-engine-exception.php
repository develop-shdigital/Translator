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
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param int    $pause   Seconds to pause.
	 * @param int    $code    HTTP status.
	 * @param string $scope   SCOPE_ENGINE or SCOPE_LANGUAGE.
	 */
	public function __construct( $message, $pause = 0, $code = 0, $scope = self::SCOPE_ENGINE ) {
		parent::__construct( $message, (int) $code );
		$this->pause = (int) $pause;
		$this->scope = self::SCOPE_LANGUAGE === $scope ? self::SCOPE_LANGUAGE : self::SCOPE_ENGINE;
	}
}
