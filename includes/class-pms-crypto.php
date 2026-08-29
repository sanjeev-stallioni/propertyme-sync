<?php
defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets (client secret, OAuth tokens) at rest in the options table.
 *
 * The key is derived from the site's AUTH_KEY/AUTH_SALT in wp-config.php, so
 * a database dump alone does not expose credentials. Uses libsodium
 * (bundled with PHP >= 7.2) with an OpenSSL AES-256-GCM fallback.
 */
class PMS_Crypto {

	const PREFIX_SODIUM  = '$pmss$';
	const PREFIX_OPENSSL = '$pmso$';

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}
		return hash( 'sha256', 'pms-crypto-v1|' . $material, true ); // 32 bytes
	}

	/** Is this value already ciphertext produced by encrypt()? */
	public static function is_encrypted( $stored ) {
		$stored = (string) $stored;
		return 0 === strpos( $stored, self::PREFIX_SODIUM ) || 0 === strpos( $stored, self::PREFIX_OPENSSL );
	}

	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ) );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$out   = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
			return false === $out ? '' : $out;
		}
		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ) );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $out ? '' : $out;
		}
		// Legacy/plaintext value (e.g. saved before encryption existed).
		return $stored;
	}
}
