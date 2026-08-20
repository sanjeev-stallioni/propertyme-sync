<?php
/**
 * Plugin Name:       PropertyMe Sync
 * Description:       Syncs properties and agents from PropertyMe into WordPress.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Stallioni Net Solutions
 * Text Domain:       propertyme-sync
 * Author URI: https://stallioni.com/
 */

defined( 'ABSPATH' ) || exit;

define( 'PMS_VERSION', '0.1.0' );
define( 'PMS_PLUGIN_FILE', __FILE__ );
define( 'PMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PMS_PLUGIN_DIR . 'includes/class-pms-crypto.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-db.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-logger.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-oauth.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-api.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-sync.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-media.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-reaxml.php';
require_once PMS_PLUGIN_DIR . 'includes/class-pms-settings.php';

/**
 * Central access to plugin settings.
 *
 * The client secret is stored encrypted (see PMS_Crypto) and is decrypted
 * transparently here, so callers never touch ciphertext.
 */
function pms_get_settings() {
	$defaults = array(
		'client_id'          => '',
		'client_secret'      => '', // stored encrypted
		'authorize_endpoint' => 'https://login.propertyme.com/connect/authorize',
		'token_endpoint'     => 'https://login.propertyme.com/connect/token',
		'api_base'           => 'https://app.propertyme.com/api/v1',
		'scope'              => 'activity:read communication:read contact:read property:read transaction:read offline_access',
		'redirect_uri'       => home_url( '/home/callback' ),
		'sync_interval'      => 'pms_12h',
		'sync_enabled'       => 0,
	);
	$saved = get_option( 'pms_settings', array() );
	$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

	if ( ! empty( $s['client_secret'] ) ) {
		$s['client_secret'] = PMS_Crypto::decrypt( $s['client_secret'] );
	}
	return $s;
}

register_activation_hook( __FILE__, array( 'PMS_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PMS_Sync', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	PMS_OAuth::init();
	PMS_Sync::init();
	if ( is_admin() ) {
		PMS_Settings::init();
	}
} );
