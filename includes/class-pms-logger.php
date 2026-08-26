<?php
defined( 'ABSPATH' ) || exit;

/**
 * Activity log, stored in the plugin's own table (wp_pms_log).
 *
 * A dedicated table rather than an option: each entry is a single INSERT
 * instead of a read-modify-write of the whole log (which loses entries when
 * two syncs overlap), rows can be filtered by level/date, and nothing the
 * plugin writes lands in wp_options. The table is never autoloaded, so the
 * front end never reads it.
 *
 * Rows are pruned to MAX_ROWS newest, occasionally rather than on every write.
 */
class PMS_Logger {

	const OPTION    = 'pms_log'; // legacy store, migrated on upgrade
	const MAX_ROWS  = 1000;
	/** Prune roughly once every N writes (cheap, keeps the table bounded). */
	const PRUNE_ODDS = 50;

	public static function log( $level, $message, $context = null ) {
		global $wpdb;

		$row = array(
			'logged_at' => current_time( 'mysql' ),
			'level'     => (string) $level,
			'message'   => (string) $message,
			'context'   => is_null( $context ) ? null : wp_json_encode( $context ),
		);

		// A missing table here is an expected, recoverable case (plugin files
		// updated before the upgrade ran), so keep the failed attempt quiet:
		// suppress_errors covers the schema probe $wpdb runs before inserting.
		$was_suppressed = $wpdb->suppress_errors( true );
		$inserted       = $wpdb->insert( PMS_DB::log_table(), $row );
		if ( false === $inserted ) {
			PMS_DB::install();
			$wpdb->suppress_errors( $was_suppressed );
			$inserted = $wpdb->insert( PMS_DB::log_table(), $row );
		} else {
			$wpdb->suppress_errors( $was_suppressed );
		}

		if ( $inserted && 1 === wp_rand( 1, self::PRUNE_ODDS ) ) {
			self::prune();
		}
	}

	public static function info( $message, $context = null )  { self::log( 'info', $message, $context ); }
	public static function error( $message, $context = null ) { self::log( 'error', $message, $context ); }

	/**
	 * Newest entries first, in the shape the settings page renders.
	 *
	 * @param int    $count Maximum rows.
	 * @param string $level Optional level filter ('info'/'error').
	 */
	public static function tail( $count = 30, $level = '' ) {
		global $wpdb;
		$table = PMS_DB::log_table();
		$count = max( 1, (int) $count );

		if ( '' !== $level ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT logged_at, level, message, context FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
				$level,
				$count
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT logged_at, level, message, context FROM {$table} ORDER BY id DESC LIMIT %d",
				$count
			), ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'time'    => $row['logged_at'],
				'level'   => $row['level'],
				'message' => $row['message'],
				'context' => (string) $row['context'],
			);
		}
		return $out;
	}

	public static function count( $level = '' ) {
		global $wpdb;
		$table = PMS_DB::log_table();
		if ( '' !== $level ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level = %s", $level ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** Delete all but the newest MAX_ROWS entries. */
	public static function prune() {
		global $wpdb;
		$table  = PMS_DB::log_table();
		$cutoff = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
			self::MAX_ROWS
		) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) );
		}
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . PMS_DB::log_table() );
	}

	/**
	 * One-time move of the pre-1.1 option-based log into the table.
	 * Runs from PMS_DB::maybe_upgrade(); the option is removed afterwards.
	 */
	public static function migrate_from_option() {
		$rows = get_option( self::OPTION, null );
		if ( ! is_array( $rows ) ) {
			delete_option( self::OPTION );
			return 0;
		}

		global $wpdb;
		$moved = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ok = $wpdb->insert( PMS_DB::log_table(), array(
				'logged_at' => empty( $row['time'] ) ? current_time( 'mysql' ) : $row['time'],
				'level'     => empty( $row['level'] ) ? 'info' : $row['level'],
				'message'   => isset( $row['message'] ) ? (string) $row['message'] : '',
				'context'   => empty( $row['context'] ) ? null : (string) $row['context'],
			) );
			if ( $ok ) {
				$moved++;
			}
		}
		delete_option( self::OPTION );
		return $moved;
	}
}
