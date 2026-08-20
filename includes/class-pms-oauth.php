<?php
defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.0 authorization-code flow against the PropertyMe Identity Service.
 *
 * The redirect URI registered with PropertyMe is <site>/home/callback, so we
 * intercept that path before WordPress routing runs. Tokens are stored
 * encrypted in the pms_tokens option:
 *   { access_token, refresh_token, expires_at }
 * Access tokens live ~30 minutes; the refresh token (offline_access scope)
 * is used to mint new ones without user interaction.
 */
class PMS_OAuth {

	const TOKENS_OPTION = 'pms_tokens';
	const STATE_OPTION  = 'pms_oauth_state';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_callback' ), 0 );
	}

	/* ---------- Authorization ---------- */

	public static function authorize_url() {
		$s     = pms_get_settings();
		$state = wp_generate_password( 24, false, false );
		update_option( self::STATE_OPTION, array( 'state' => $state, 'created' => time() ), false );

		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => rawurlencode( $s['client_id'] ),
				'redirect_uri'  => rawurlencode( $s['redirect_uri'] ),
				'scope'         => rawurlencode( $s['scope'] ),
				'state'         => $state,
			),
			$s['authorize_endpoint']
		);
	}

	public static function maybe_handle_callback() {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! $path || untrailingslashit( $path ) !== '/home/callback' ) {
			return;
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			PMS_Logger::error( 'OAuth callback returned error: ' . $error );
			self::redirect_to_settings( 'oauth_error' );
		}
		if ( '' === $code ) {
			self::redirect_to_settings( 'oauth_missing_code' );
		}

		$stored = get_option( self::STATE_OPTION );
		delete_option( self::STATE_OPTION );
		$state_ok = is_array( $stored )
			&& ! empty( $stored['state'] )
			&& hash_equals( $stored['state'], $state )
			&& ( time() - (int) $stored['created'] ) < 15 * MINUTE_IN_SECONDS;
		if ( ! $state_ok ) {
			PMS_Logger::error( 'OAuth callback rejected: state mismatch or expired.' );
			self::redirect_to_settings( 'oauth_state_mismatch' );
		}

		$ok = self::exchange_code( $code );
		self::redirect_to_settings( $ok ? 'connected' : 'oauth_exchange_failed' );
	}

	private static function exchange_code( $code ) {
		$s        = pms_get_settings();
		$response = self::token_request( array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $s['redirect_uri'],
		) );
		if ( is_wp_error( $response ) ) {
			PMS_Logger::error( 'Token exchange failed: ' . $response->get_error_message() );
			return false;
		}
		self::store_tokens( $response );
		PMS_Logger::info( 'Connected to PropertyMe (tokens received).' );
		return true;
	}

	/* ---------- Token access & refresh ---------- */

	/**
	 * Returns a valid access token, refreshing it first when it is within
	 * 60 seconds of expiry. Returns '' when not connected / refresh failed.
	 */
	public static function access_token() {
		$t = self::tokens();
		if ( empty( $t ) ) {
			return '';
		}
		if ( ! empty( $t['access_token'] ) && time() < ( (int) $t['expires_at'] - 60 ) ) {
			return $t['access_token'];
		}
		return self::refresh() ? self::tokens()['access_token'] : '';
	}

	public static function refresh() {
		$t = self::tokens();
		if ( empty( $t['refresh_token'] ) ) {
			PMS_Logger::error( 'Cannot refresh: no refresh token stored. Re-connect via the settings page.' );
			return false;
		}
		$response = self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $t['refresh_token'],
		) );
		if ( is_wp_error( $response ) ) {
			PMS_Logger::error( 'Token refresh failed: ' . $response->get_error_message() );
			return false;
		}
		// Keep the old refresh token if the server did not rotate it.
		if ( empty( $response['refresh_token'] ) ) {
			$response['refresh_token'] = $t['refresh_token'];
		}
		self::store_tokens( $response );
		return true;
	}

	/**
	 * PropertyMe's identity server expects client credentials as an HTTP
	 * Basic Authorization header (client_secret_basic — matching their
	 * HelloPropertyMe.NET sample, which uses IdentityModel's TokenClient).
	 * Credentials in the POST body get rejected with 400 invalid_request,
	 * so Basic auth is tried first and body credentials kept as a fallback.
	 */
	private static function token_request( array $body ) {
		$s = pms_get_settings();
		if ( '' === $s['client_id'] || '' === $s['client_secret'] ) {
			return new WP_Error( 'pms_no_credentials', 'Client ID / Client Secret are not configured.' );
		}

		$basic = self::token_http( $s['token_endpoint'], $body, array(
			'Accept'        => 'application/json',
			'Authorization' => 'Basic ' . base64_encode( $s['client_id'] . ':' . $s['client_secret'] ),
		) );
		if ( ! is_wp_error( $basic ) ) {
			return $basic;
		}

		$body['client_id']     = $s['client_id'];
		$body['client_secret'] = $s['client_secret'];
		$fallback = self::token_http( $s['token_endpoint'], $body, array( 'Accept' => 'application/json' ) );
		if ( ! is_wp_error( $fallback ) ) {
			return $fallback;
		}
		// Report the Basic-auth attempt's error (the expected auth style).
		return $basic;
	}

	private static function token_http( $endpoint, array $body, array $headers ) {
		$res = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => $body,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
			return new WP_Error( 'pms_token_http', 'HTTP ' . $code . ': ' . substr( wp_remote_retrieve_body( $res ), 0, 300 ) );
		}
		return $json;
	}

	private static function store_tokens( array $token_response ) {
		$data = array(
			'access_token'  => $token_response['access_token'],
			'refresh_token' => $token_response['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $token_response['expires_in'] ?? 1800 ),
		);
		update_option( self::TOKENS_OPTION, PMS_Crypto::encrypt( wp_json_encode( $data ) ), false );
	}

	public static function tokens() {
		$stored = get_option( self::TOKENS_OPTION, '' );
		if ( '' === $stored ) {
			return array();
		}
		$json = json_decode( PMS_Crypto::decrypt( $stored ), true );
		return is_array( $json ) ? $json : array();
	}

	public static function is_connected() {
		$t = self::tokens();
		return ! empty( $t['refresh_token'] ) || ( ! empty( $t['access_token'] ) && time() < (int) $t['expires_at'] );
	}

	public static function disconnect() {
		delete_option( self::TOKENS_OPTION );
		PMS_Logger::info( 'Disconnected: stored tokens removed.' );
	}

	private static function redirect_to_settings( $status ) {
		wp_safe_redirect( add_query_arg( 'pms_status', $status, admin_url( 'options-general.php?page=propertyme-sync' ) ) );
		exit;
	}
}
