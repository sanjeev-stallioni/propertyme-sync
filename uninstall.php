<?php
// Remove all stored credentials, tokens, and state when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pms_properties' );

delete_option( 'pms_settings' );
delete_option( 'pms_db_version' );
delete_option( 'pms_tokens' );
delete_option( 'pms_oauth_state' );
delete_option( 'pms_log' );
delete_option( 'pms_last_sync' );
wp_clear_scheduled_hook( 'pms_sync_event' );
