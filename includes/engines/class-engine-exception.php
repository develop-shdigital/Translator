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
	 * Seconds to pause the engine (circuit breaker). 0 = do not pause.
	 *
	 * @var int
	 */
	public $pause = 0;

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param int    $pause   Seconds to pause the engine.
	 * @param int    $code    HTTP status.
	 */
	public function __construct( $message, $pause = 0, $code = 0 ) {
		parent::__construct( $message, (int) $code );
		$this->pause = (int) $pause;
	}
}
