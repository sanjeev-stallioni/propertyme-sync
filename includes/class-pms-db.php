<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom table layer — the plugin's own data store for synced PropertyMe data.
 *
 * Every lot fetched from the API is written here first (normalized columns
 * for querying + the complete raw JSON payload for auditing/remapping).
 * Built purely on $wpdb / dbDelta — no third-party plugin involved.
 */
class PMS_DB {

	const DB_VERSION        = '1.1'; // 1.1: added the pms_log table
	const DB_VERSION_OPTION = 'pms_db_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pms_properties';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'pms_log';
	}

	/** Create/upgrade the table. Safe to call repeatedly (dbDelta diffs schema). */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lot_id VARCHAR(64) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			address VARCHAR(255) NOT NULL DEFAULT '',
			suburb VARCHAR(190) NOT NULL DEFAULT '',
			postcode VARCHAR(16) NOT NULL DEFAULT '',
			bed VARCHAR(8) NOT NULL DEFAULT '',
			bath VARCHAR(8) NOT NULL DEFAULT '',
			car VARCHAR(8) NOT NULL DEFAULT '',
			rent VARCHAR(64) NOT NULL DEFAULT '',
			property_type VARCHAR(100) NOT NULL DEFAULT '',
			listing_status VARCHAR(50) NOT NULL DEFAULT '',
			agent_name VARCHAR(190) NOT NULL DEFAULT '',
			agent_email VARCHAR(190) NOT NULL DEFAULT '',
			description LONGTEXT NULL,
			raw LONGTEXT NULL,
			synced_at DATETIME NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lot_id (lot_id),
			KEY post_id (post_id),
			KEY listing_status (listing_status)
		) {$charset};" );

		$log_table = self::log_table();
		dbDelta( "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			logged_at DATETIME NOT NULL,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY level (level)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Run install() again if the plugin was updated with a newer schema. */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
			PMS_Logger::migrate_from_option();
		}
	}

	/**
	 * Insert or update one lot row (matched by PropertyMe lot id).
	 *
	 * @param array $fields Normalized columns (subset of the table columns).
	 * @param array $raw    Full API payload for the lot.
	 * @return int Row id, or 0 on failure.
	 */
	public static function upsert_lot( array $fields, array $raw ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array_merge( $fields, array(
			'raw'        => wp_json_encode( $raw ),
			'synced_at'  => $now,
			'updated_at' => $now,
		) );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE lot_id = %s',
			$fields['lot_id']
		) );

		if ( $existing_id ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing_id ) );
			return (int) $existing_id;
		}

		$data['created_at'] = $now;
		return $wpdb->insert( self::table(), $data ) ? (int) $wpdb->insert_id : 0;
	}

	public static function set_post_id( $row_id, $post_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'post_id' => (int) $post_id ), array( 'id' => (int) $row_id ) );
	}

	public static function get_by_lot_id( $lot_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE lot_id = %s',
			$lot_id
		), ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::log_table() );
		delete_option( self::DB_VERSION_OPTION );
	}
}
