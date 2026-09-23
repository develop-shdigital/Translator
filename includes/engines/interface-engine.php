<?php
/**
 * Translation engine contract.
 *
 * @package SHDT
 */

namespace SHDT\Engines;

defined( 'ABSPATH' ) || exit;

interface Engine {

	/**
	 * Unique engine id.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the engine is configured and usable.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Maximum number of strings per request.
	 *
	 * @return int
	 */
	public function max_batch();

	/**
	 * Maximum characters per request.
	 *
	 * @return int
	 */
	public function max_chars();

	/**
	 * Translate several batches, in parallel where possible.
	 *
	 * @param array[] $batches  List of string lists (strings may contain <xN> placeholders).
	 * @param array   $source   Source language entry.
	 * @param array   $target   Target language entry.
	 * @param array   $context  Extra context (page title, url…).
	 * @param float   $deadline Unix time (microtime) after which no new request should start.
	 * @return array[] Per batch key: index => translation. Missing indexes failed.
	 */
	public function translate_batches( array $batches, array $source, array $target, array $context, $deadline );

	/**
	 * Error that stopped the last translate_batches() call (quota, auth, blocked IP…).
	 *
	 * @return Engine_Exception|null
	 */
	public function error();
}
