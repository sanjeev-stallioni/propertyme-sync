<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal ring-buffer log stored in one option, surfaced on the settings page.
 */
class PMS_Logger {

	const OPTION   = 'pms_log';
	const MAX_ROWS = 200;

	public static function log( $level, $message, $context = null ) {
		$rows   = get_option( self::OPTION, array() );
		$rows   = is_array( $rows ) ? $rows : array();
		$rows[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => $message,
			'context' => is_null( $context ) ? '' : wp_json_encode( $context ),
		);
		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, -self::MAX_ROWS );
		}
		update_option( self::OPTION, $rows, false );
	}

	public static function info( $message, $context = null )  { self::log( 'info', $message, $context ); }
	public static function error( $message, $context = null ) { self::log( 'error', $message, $context ); }

	public static function tail( $count = 30 ) {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_reverse( array_slice( $rows, -$count ) ) : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
