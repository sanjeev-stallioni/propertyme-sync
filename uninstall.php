<?php
// Remove all stored credentials, tokens, and state when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pms_properties' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pms_log' );

delete_option( 'pms_settings' );
delete_option( 'pms_db_version' );
delete_option( 'pms_tokens' );
delete_option( 'pms_oauth_state' );
delete_option( 'pms_log' );
delete_option( 'pms_last_sync' );
delete_option( 'pms_delta_cursor' );
delete_option( 'pms_delta_runs' );
delete_option( 'pms_orphan_summaries_cleared' );
delete_option( 'pms_scope_activity_restored' );
delete_option( 'pms_agent_placeholder_image' );
delete_option( 'pms_layout_template_post' );
delete_transient( 'pms_sync_running' );
delete_transient( 'withpm_property_types' );
wp_clear_scheduled_hook( 'pms_sync_event' );
