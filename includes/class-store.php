<?php
/**
 * Translation memory: one row per (language, original string).
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Store {

	const PENDING  = 0;
	const AUTO     = 1;
	const MANUAL   = 2;
	const OUTDATED = 3; // Shown to visitors, but queued to be redone by the current engine.

	/**
	 * Engine value of pending rows that came from visitors' browsers (dynamic
	 * content): the queue sends them to AI engines one at a time.
	 */
	const VISITOR = 'visitor';

	/**
	 * Failed attempts after which a row is only retried once a day.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Transient caching count_fallback().
	 */
	const FALLBACK_COUNTS = 'shdt_fallback_counts';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'shdt_strings';
	}

	/**
	 * Stable hash of a normalised string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function hash( $text ) {
		return md5( (string) $text );
	}

	/**
	 * Current MySQL timestamp.
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Look up translations for many hashes.
	 *
	 * @param string   $lang   Language.
	 * @param string[] $hashes Hashes.
	 * @return array hash => [ 'translated' => string|null, 'status' => int ]
	 */
	public function get_many( $lang, array $hashes ) {
		global $wpdb;
		$out    = array();
		$hashes = array_values( array_unique( $hashes ) );
		$table  = self::table();

		foreach ( array_chunk( $hashes, 400 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args = array_merge( array( $lang ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, hash, translated, status, attempts FROM {$table} WHERE lang = %s AND hash IN ({$in})", $args ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$out[ $row['hash'] ] = array(
					'id'         => (int) $row['id'],
					'translated' => $row['translated'],
					'status'     => (int) $row['status'],
					'attempts'   => (int) $row['attempts'],
				);
			}
		}
		return $out;
	}

	/**
	 * Store machine translations. Never overwrites manual edits.
	 *
	 * @param string $lang  Language.
	 * @param array  $items List of [ original, translated, engine, url, status (AUTO|OUTDATED) ].
	 */
	public function save_many( $lang, array $items ) {
		global $wpdb;
		if ( ! $items ) {
			return;
		}
		$table = self::table();
		$now   = self::now();

		foreach ( array_chunk( $items, 100 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $item ) {
				$values[] = '(%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)';
				array_push(
					$args,
					$lang,
					self::hash( $item['original'] ),
					$item['original'],
					$item['translated'],
					self::hash( Text::normalize( $item['translated'] ) ),
					isset( $item['status'] ) && self::OUTDATED === $item['status'] ? self::OUTDATED : self::AUTO,
					isset( $item['engine'] ) ? substr( (string) $item['engine'], 0, 32 ) : '',
					isset( $item['origin'] ) ? substr( (string) $item['origin'], 0, 16 ) : '',
					isset( $item['url'] ) ? substr( (string) $item['url'], 0, 255 ) : '',
					$now,
					$now
				);
			}
			$sql = "INSERT INTO {$table} (lang,hash,original,translated,thash,status,engine,origin,url,created_at,updated_at) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE'
				// Text that came from visitors stays marked, whatever translates it.
				. " origin = IF(origin = '', VALUES(origin), origin),"
				. ' translated = IF(status = 2, translated, VALUES(translated)),'
				. ' thash = IF(status = 2, thash, VALUES(thash)),'
				. ' engine = IF(status = 2, engine, VALUES(engine)),'
				. ' attempts = IF(status = 2, attempts, 0),'
				// Assignments run left to right: status stays last so the IFs above still see the old value.
				. ' status = IF(status = 2, 2, VALUES(status)),'
				. ' updated_at = VALUES(updated_at)';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Remember strings that still need a translation (processed by the queue).
	 *
	 * @param string   $lang      Language.
	 * @param string[] $originals Normalised originals.
	 * @param string   $url       Page where they were seen.
	 * @param string   $source    Store::VISITOR for text sent by browsers, '' otherwise.
	 */
	public function add_pending( $lang, array $originals, $url = '', $source = '' ) {
		global $wpdb;
		if ( ! $originals ) {
			return;
		}
		$table = self::table();
		$now   = self::now();
		foreach ( array_chunk( array_values( array_unique( $originals ) ), 100 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $original ) {
				$values[] = '(%s,%s,%s,NULL,%d,%s,%s,%s,%s,%s)';
				array_push( $args, $lang, self::hash( $original ), $original, self::PENDING, substr( (string) $source, 0, 32 ), substr( (string) $source, 0, 16 ), substr( (string) $url, 0, 255 ), $now, $now );
			}
			$sql = "INSERT IGNORE INTO {$table} (lang,hash,original,translated,status,engine,origin,url,created_at,updated_at) VALUES " . implode( ',', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Rows for the background queue: missing translations first, then outdated ones.
	 *
	 * @param int      $limit    Max rows.
	 * @param int[]    $statuses PENDING and/or OUTDATED.
	 * @param string[] $langs    Languages; null = the active target languages
	 *                           (rows of removed languages wait until they are added again).
	 * @param int[]    $exclude  Row ids to leave out (already tried in this run).
	 * @return array id, lang, original, status, engine, origin
	 */
	public function get_pending( $limit = 200, array $statuses = array( self::PENDING, self::OUTDATED ), $langs = null, array $exclude = array() ) {
		global $wpdb;
		$table   = self::table();
		$where   = self::pending_where( $statuses, $langs );
		$exclude = array_filter( array_map( 'absint', $exclude ) );
		if ( $exclude ) {
			$where .= ' AND id NOT IN (' . implode( ',', $exclude ) . ')';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared in pending_where().
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, lang, original, status, engine, origin FROM {$table} WHERE {$where} ORDER BY status ASC, attempts ASC, id ASC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * SQL condition for rows waiting for the queue.
	 *
	 * @param int[]         $statuses Statuses.
	 * @param string[]|null $langs    Languages; null = active target languages.
	 * @return string
	 */
	private static function pending_where( array $statuses, $langs ) {
		global $wpdb;
		if ( null === $langs ) {
			$langs = array_keys( shdt()->languages()->targets() );
			if ( ! $langs ) {
				return '1 = 0';
			}
		}
		// Rows that failed too often are retried once a day instead of every run.
		$where = 'status IN (' . implode( ',', array_map( 'absint', $statuses ? $statuses : array( self::PENDING ) ) ) . ')'
			. $wpdb->prepare( ' AND (attempts < %d OR updated_at < %s)', self::MAX_ATTEMPTS, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		if ( $langs ) {
			$langs  = array_values( array_map( 'strval', $langs ) );
			$where .= $wpdb->prepare( ' AND lang IN (' . implode( ',', array_fill( 0, count( $langs ), '%s' ) ) . ')', $langs ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		}
		return $where;
	}

	/**
	 * Count rows waiting for the queue.
	 *
	 * @param int[]    $statuses PENDING and/or OUTDATED.
	 * @param string[] $langs    Languages; null = the active target languages.
	 * @return int
	 */
	public function count_pending( array $statuses = array( self::PENDING, self::OUTDATED ), $langs = null ) {
		global $wpdb;
		$table = self::table();
		$where = self::pending_where( $statuses, $langs );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared in pending_where().
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * SQL condition: automatic translations not made by one of $engines.
	 * Manual edits and imported translations never match.
	 *
	 * @param string[] $engines Engine ids that count as "current".
	 * @return string Prepared SQL fragment.
	 */
	private static function other_engine_where( array $engines ) {
		global $wpdb;
		$engines = array_values( array_merge( array_map( 'strval', $engines ), array( 'manual', 'import' ) ) );
		$in      = implode( ',', array_fill( 0, count( $engines ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->prepare( "(status = 1 OR (status = 3 AND attempts >= %d)) AND engine NOT IN ({$in})", array_merge( array( self::MAX_ATTEMPTS ), $engines ) );
	}

	/**
	 * Translations made by other engines than the selected one, for the admin:
	 * auto (kept as they are), outdated (queued to be redone), stuck (the redo failed
	 * too often). Cached for a few minutes.
	 *
	 * @param string[] $engines Engine ids that count as "current".
	 * @return array { auto, outdated, stuck, total, engines: id => count, fallback: id => count (outdated and stuck rows only) }
	 */
	public function count_fallback( array $engines ) {
		$key    = md5( implode( ',', $engines ) );
		$cached = get_transient( self::FALLBACK_COUNTS );
		if ( is_array( $cached ) && isset( $cached[ $key ] ) ) {
			return $cached[ $key ];
		}

		global $wpdb;
		$table   = self::table();
		$exclude = array_values( array_merge( array_map( 'strval', $engines ), array( 'manual', 'import', '', self::VISITOR ) ) );
		$in      = implode( ',', array_fill( 0, count( $exclude ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT engine, status, attempts >= %d AS stuck, COUNT(*) AS n FROM {$table} WHERE status IN (1, 3) AND engine NOT IN ({$in}) GROUP BY engine, status, stuck", array_merge( array( self::MAX_ATTEMPTS ), $exclude ) ), ARRAY_A );

		$out = array(
			'auto'     => 0,
			'outdated' => 0,
			'stuck'    => 0,
			'total'    => 0,
			'engines'  => array(),
			'fallback' => array(),
		);
		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			if ( 1 === (int) $row['status'] ) {
				$out['auto'] += $n;
			} elseif ( (int) $row['stuck'] ) {
				$out['stuck'] += $n;
			} else {
				$out['outdated'] += $n;
			}
			$out['total'] += $n;

			$out['engines'][ $row['engine'] ] = ( isset( $out['engines'][ $row['engine'] ] ) ? $out['engines'][ $row['engine'] ] : 0 ) + $n;
			if ( 3 === (int) $row['status'] ) {
				$out['fallback'][ $row['engine'] ] = ( isset( $out['fallback'][ $row['engine'] ] ) ? $out['fallback'][ $row['engine'] ] : 0 ) + $n;
			}
		}
		arsort( $out['engines'] );
		arsort( $out['fallback'] );

		$cached         = is_array( $cached ) ? $cached : array();
		$cached[ $key ] = $out;
		set_transient( self::FALLBACK_COUNTS, $cached, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Give rows that failed before another full set of attempts (after the
	 * engine works again, e.g. credit added or key fixed).
	 *
	 * @return int Rows reset.
	 */
	public function reset_attempts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->query( "UPDATE {$table} SET attempts = 0 WHERE status IN (0, 3) AND attempts > 0" );
		delete_transient( self::FALLBACK_COUNTS );
		return $count;
	}

	/**
	 * Automatic translations produced by another engine.
	 *
	 * @param string[] $engines Engine ids that count as "current".
	 * @return int
	 */
	public function count_other_engine( array $engines ) {
		global $wpdb;
		$table = self::table();
		$where = self::other_engine_where( $engines );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * Queue automatic translations of other engines for re-translation.
	 * They stay visible until the new translation replaces them.
	 *
	 * @param string[] $engines Engine ids that count as "current".
	 * @return int Rows marked.
	 */
	public function mark_outdated( array $engines ) {
		global $wpdb;
		$table = self::table();
		$where = self::other_engine_where( $engines );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		$count = (int) $wpdb->query( "UPDATE {$table} SET status = 3, attempts = 0 WHERE {$where}" );
		delete_transient( self::FALLBACK_COUNTS );
		return $count;
	}

	/**
	 * Increase the attempt counter of rows that failed to translate.
	 *
	 * @param int[] $ids Row IDs.
	 */
	public function bump_attempts( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- IDs are absint() above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id IN (" . implode( ',', $ids ) . ')', self::now() ) );
		delete_transient( self::FALLBACK_COUNTS );
	}

	/**
	 * When rows that used up their attempts are due for their daily retry.
	 *
	 * @return int|null Unix time, null when there are none.
	 */
	public function next_retry() {
		global $wpdb;
		$table = self::table();
		$langs = array_keys( shdt()->languages()->targets() );
		if ( ! $langs ) {
			return null;
		}
		$in = implode( ',', array_fill( 0, count( $langs ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(updated_at) FROM {$table} WHERE status IN (0, 3) AND attempts >= %d AND lang IN ({$in})", array_merge( array( self::MAX_ATTEMPTS ), $langs ) ) );
		return $oldest ? (int) strtotime( $oldest . ' UTC' ) + DAY_IN_SECONDS : null;
	}

	/**
	 * Which of the given texts are already translations in this language.
	 * Used to avoid re-translating text that is already translated (dynamic content).
	 *
	 * @param string   $lang    Language.
	 * @param string[] $thashes Hashes of normalised texts.
	 * @return array thash => true
	 */
	public function known_translations( $lang, array $thashes ) {
		global $wpdb;
		$out   = array();
		$table = self::table();
		foreach ( array_chunk( array_values( array_unique( $thashes ) ), 400 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT thash FROM {$table} WHERE lang = %s AND thash IN ({$in})", array_merge( array( $lang ), $chunk ) ) );
			foreach ( (array) $rows as $thash ) {
				$out[ $thash ] = true;
			}
		}
		return $out;
	}

	/**
	 * Reverse lookup: original for an exact translated string.
	 *
	 * @param string $lang Language.
	 * @param string $text Translated text.
	 * @return string|null
	 */
	public function original_for( $lang, $text ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT original FROM {$table} WHERE lang = %s AND thash = %s LIMIT 1", $lang, self::hash( Text::normalize( $text ) ) ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Create or update a manual translation.
	 *
	 * @param string $lang       Language.
	 * @param string $original   Normalised original.
	 * @param string $translated Translation.
	 * @return int Row ID.
	 */
	public function save_manual( $lang, $original, $translated ) {
		global $wpdb;
		$table = self::table();
		$now   = self::now();
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (lang,hash,original,translated,thash,status,engine,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s)
				ON DUPLICATE KEY UPDATE translated = VALUES(translated), thash = VALUES(thash), status = VALUES(status), engine = VALUES(engine), updated_at = VALUES(updated_at)",
				$lang,
				self::hash( $original ),
				$original,
				$translated,
				self::hash( Text::normalize( $translated ) ),
				self::MANUAL,
				'manual',
				$now,
				$now
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE lang = %s AND hash = %s", $lang, self::hash( $original ) ) );
	}

	/**
	 * Get a single row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public function get_row( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Update a row's translation (marks it as manually reviewed).
	 *
	 * @param int    $id         Row ID.
	 * @param string $translated Translation.
	 * @return bool
	 */
	public function update_row( $id, $translated ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'translated' => $translated,
				'thash'      => self::hash( Text::normalize( $translated ) ),
				'status'     => self::MANUAL,
				'engine'     => 'manual',
				'updated_at' => self::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete rows.
	 *
	 * @param int[] $ids Row IDs.
	 * @return int Deleted rows.
	 */
	public function delete_rows( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = self::table();
		delete_transient( self::FALLBACK_COUNTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- IDs are absint() above.
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' );
	}

	/**
	 * Search / list rows for the editor.
	 *
	 * @param array $args lang, status, search, page, per_page.
	 * @return array [ rows, total ]
	 */
	public function query( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'lang'     => '',
				'status'   => '',
				'search'   => '',
				'page'     => 1,
				'per_page' => 50,
			)
		);

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $args['lang'] ) {
			$where[]  = 'lang = %s';
			$params[] = $args['lang'];
		}
		if ( '' !== $args['status'] && null !== $args['status'] ) {
			$where[]  = 'status = %d';
			$params[] = (int) $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(original LIKE %s OR translated LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$table    = self::table();
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT id, lang, original, translated, status, engine, attempts, url, updated_at FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Per-language statistics.
	 *
	 * @return array lang => [ total, pending, auto, manual, outdated, chars ]
	 */
	public function stats() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT lang, status, COUNT(*) AS n, SUM(CHAR_LENGTH(original)) AS chars FROM {$table} GROUP BY lang, status", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$lang = $row['lang'];
			if ( ! isset( $out[ $lang ] ) ) {
				$out[ $lang ] = array(
					'total'    => 0,
					'pending'  => 0,
					'auto'     => 0,
					'manual'   => 0,
					'outdated' => 0,
					'chars'    => 0,
				);
			}
			$n                     = (int) $row['n'];
			$out[ $lang ]['total'] += $n;
			$out[ $lang ]['chars'] += (int) $row['chars'];
			$names                 = array(
				self::PENDING  => 'pending',
				self::AUTO     => 'auto',
				self::MANUAL   => 'manual',
				self::OUTDATED => 'outdated',
			);
			$key                   = isset( $names[ (int) $row['status'] ] ) ? $names[ (int) $row['status'] ] : 'auto';
			$out[ $lang ][ $key ]  += $n;
		}
		return $out;
	}

	/**
	 * Delete translations.
	 *
	 * @param string $lang  Language or '' for all.
	 * @param string $scope auto | pending | all.
	 * @return int Deleted rows.
	 */
	public function clear( $lang = '', $scope = 'auto' ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $lang ) {
			$where[]  = 'lang = %s';
			$params[] = $lang;
		}
		if ( 'auto' === $scope ) {
			$where[] = 'status IN (0,1,3)';
		} elseif ( 'pending' === $scope ) {
			$where[] = 'status = 0';
		}
		$sql = "DELETE FROM {$table} WHERE " . implode( ' AND ', $where );
		delete_transient( self::FALLBACK_COUNTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) ( $params ? $wpdb->query( $wpdb->prepare( $sql, $params ) ) : $wpdb->query( $sql ) );
	}

	/**
	 * Export translated rows.
	 *
	 * @param string $lang Language or '' for all.
	 * @return array
	 */
	public function export( $lang = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $lang ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT lang, original, translated, status, engine FROM {$table} WHERE status > 0 AND lang = %s ORDER BY id", $lang ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT lang, original, translated, status, engine FROM {$table} WHERE status > 0 ORDER BY id", ARRAY_A );
	}

	/**
	 * Import rows produced by export().
	 *
	 * @param array $rows Rows.
	 * @return int Imported rows.
	 */
	public function import( array $rows ) {
		$count  = 0;
		$auto   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['lang'] ) || ! isset( $row['original'], $row['translated'] ) ) {
				continue;
			}
			$lang       = sanitize_text_field( $row['lang'] );
			$original   = Text::normalize( (string) $row['original'] );
			$translated = Text::normalize( Text::canonical_placeholders( (string) $row['translated'] ) );
			if ( '' === $original || '' === $translated ) {
				continue;
			}
			// Tags edited by hand must still match the original, or the page markup breaks.
			if ( Text::has_placeholders( $original ) ) {
				if ( ! Text::placeholders_match( $original, $translated ) ) {
					continue;
				}
			} elseif ( Text::has_placeholders( $translated ) ) {
				$translated = Text::normalize( preg_replace( Text::PLACEHOLDER, '', $translated ) );
			}
			if ( isset( $row['status'] ) && self::MANUAL === (int) $row['status'] ) {
				$this->save_manual( $lang, $original, $translated );
			} else {
				// Keep which engine made an automatic translation, so Google texts moved
				// from another site are still recognised (and redone) as Google texts.
				$engine          = isset( $row['engine'] ) && is_string( $row['engine'] ) && preg_match( '/^[a-z0-9_\-]{1,32}$/', $row['engine'] ) && ! in_array( $row['engine'], array( 'manual', self::VISITOR ), true ) ? $row['engine'] : 'import';
				$auto[ $lang ][] = array(
					'original'   => $original,
					'translated' => $translated,
					'engine'     => $engine,
					'status'     => 'import' !== $engine && isset( $row['status'] ) && self::OUTDATED === (int) $row['status'] ? self::OUTDATED : self::AUTO,
				);
			}
			$count++;
		}
		foreach ( $auto as $lang => $items ) {
			$this->save_many( $lang, $items );
		}
		delete_transient( self::FALLBACK_COUNTS );
		return $count;
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
