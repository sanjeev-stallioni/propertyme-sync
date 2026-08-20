<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin PropertyMe API client. Adds the bearer token, retries once after a
 * refresh on 401, and returns decoded JSON or WP_Error.
 */
class PMS_API {

	/**
	 * @param string $path  Path relative to the API base, e.g. 'lots'.
	 * @param array  $query Optional query args.
	 * @return array|WP_Error
	 */
	public static function get( $path, array $query = array() ) {
		return self::request( 'GET', $path, $query );
	}

	private static function request( $method, $path, array $query = array(), $retrying = false ) {
		$token = PMS_OAuth::access_token();
		if ( '' === $token ) {
			return new WP_Error( 'pms_not_connected', 'Not connected to PropertyMe (no valid access token).' );
		}

		$s   = pms_get_settings();
		$url = trailingslashit( $s['api_base'] ) . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$res = wp_remote_request( $url, array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 401 === $code && ! $retrying && PMS_OAuth::refresh() ) {
			return self::request( $method, $path, $query, true );
		}
		$body = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'pms_api_http', 'HTTP ' . $code . ' from ' . $path . ': ' . substr( $body, 0, 300 ) );
		}

		$json = json_decode( $body, true );
		if ( null === $json && 'null' !== trim( $body ) ) {
			return new WP_Error( 'pms_api_json', 'Non-JSON response from ' . $path );
		}
		return $json;
	}
}
