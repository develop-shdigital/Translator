<?php
/**
 * Translation memory: one row per (language, original string).
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Store {

	const PENDING = 0;
	const AUTO    = 1;
	const MANUAL  = 2;

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
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT hash, translated, status FROM {$table} WHERE lang = %s AND hash IN ({$in})", $args ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$out[ $row['hash'] ] = array(
					'translated' => $row['translated'],
					'status'     => (int) $row['status'],
				);
			}
		}
		return $out;
	}

	/**
	 * Store machine translations. Never overwrites manual edits.
	 *
	 * @param string $lang  Language.
	 * @param array  $items List of [ original, translated, engine, url ].
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
				$values[] = '(%s,%s,%s,%s,%s,%d,%s,%s,%s,%s)';
				array_push(
					$args,
					$lang,
					self::hash( $item['original'] ),
					$item['original'],
					$item['translated'],
					self::hash( Text::normalize( $item['translated'] ) ),
					self::AUTO,
					isset( $item['engine'] ) ? substr( (string) $item['engine'], 0, 32 ) : '',
					isset( $item['url'] ) ? substr( (string) $item['url'], 0, 255 ) : '',
					$now,
					$now
				);
			}
			$sql = "INSERT INTO {$table} (lang,hash,original,translated,thash,status,engine,url,created_at,updated_at) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE'
				. ' translated = IF(status = 2, translated, VALUES(translated)),'
				. ' thash = IF(status = 2, thash, VALUES(thash)),'
				. ' engine = IF(status = 2, engine, VALUES(engine)),'
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
	 */
	public function add_pending( $lang, array $originals, $url = '' ) {
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
				$values[] = '(%s,%s,%s,NULL,%d,%s,%s,%s)';
				array_push( $args, $lang, self::hash( $original ), $original, self::PENDING, substr( (string) $url, 0, 255 ), $now, $now );
			}
			$sql = "INSERT IGNORE INTO {$table} (lang,hash,original,translated,status,url,created_at,updated_at) VALUES " . implode( ',', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Pending rows for the background queue.
	 *
	 * @param int         $limit Max rows.
	 * @param string|null $lang  Language filter.
	 * @return array
	 */
	public function get_pending( $limit = 200, $lang = null ) {
		global $wpdb;
		$table = self::table();
		if ( $lang ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, lang, original FROM {$table} WHERE status = 0 AND lang = %s AND attempts < 5 ORDER BY attempts ASC, id ASC LIMIT %d", $lang, $limit ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, lang, original FROM {$table} WHERE status = 0 AND attempts < 5 ORDER BY attempts ASC, id ASC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Count pending rows.
	 *
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 0 AND attempts < 5" );
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
		$wpdb->query( "UPDATE {$table} SET attempts = attempts + 1 WHERE id IN (" . implode( ',', $ids ) . ')' );
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
		$list_sql  = "SELECT id, lang, original, translated, status, engine, url, updated_at FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";

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
	 * @return array lang => [ total, pending, auto, manual, chars ]
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
					'total'   => 0,
					'pending' => 0,
					'auto'    => 0,
					'manual'  => 0,
					'chars'   => 0,
				);
			}
			$n                     = (int) $row['n'];
			$out[ $lang ]['total'] += $n;
			$out[ $lang ]['chars'] += (int) $row['chars'];
			$key                   = self::PENDING === (int) $row['status'] ? 'pending' : ( self::MANUAL === (int) $row['status'] ? 'manual' : 'auto' );
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
			$where[] = 'status IN (0,1)';
		} elseif ( 'pending' === $scope ) {
			$where[] = 'status = 0';
		}
		$sql = "DELETE FROM {$table} WHERE " . implode( ' AND ', $where );
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
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT lang, original, translated, status FROM {$table} WHERE status > 0 AND lang = %s ORDER BY id", $lang ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT lang, original, translated, status FROM {$table} WHERE status > 0 ORDER BY id", ARRAY_A );
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
			$translated = (string) $row['translated'];
			if ( '' === $original || '' === $translated ) {
				continue;
			}
			if ( isset( $row['status'] ) && self::MANUAL === (int) $row['status'] ) {
				$this->save_manual( $lang, $original, $translated );
			} else {
				$auto[ $lang ][] = array(
					'original'   => $original,
					'translated' => $translated,
					'engine'     => 'import',
				);
			}
			$count++;
		}
		foreach ( $auto as $lang => $items ) {
			$this->save_many( $lang, $items );
		}
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
