<?php
namespace WPPE\Security;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SecurityHelper {
	/**
	 * Verify standard admin capabilities.
	 */
	public static function check_admin_capabilities(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'wp-performance-engine' ), 403 );
		}
	}

	/**
	 * Verify Ajax/REST Nonce.
	 *
	 * @param string $action Nonce action name.
	 * @param string $query_arg Field name.
	 */
	public static function verify_nonce( string $action, string $query_arg = '_wpnonce' ): void {
		$nonce = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce security token.', 'wp-performance-engine' ) ), 403 );
		}
	}

	/**
	 * Encrypt a string using OpenSSL.
	 *
	 * @param string $data Plaintext string.
	 * @return string Encrypted base64 representation.
	 */
	public static function encrypt( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$key = self::get_encryption_key();
		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length( $method );
		$iv = openssl_random_pseudo_bytes( $iv_length );

		$encrypted = openssl_encrypt( $data, $method, $key, 0, $iv );
		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt an OpenSSL encrypted string.
	 *
	 * @param string $data Encrypted base64 representation.
	 * @return string Decrypted plaintext string.
	 */
	public static function decrypt( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$decoded = base64_decode( $data );
		if ( false === $decoded ) {
			return '';
		}

		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length( $method );
		if ( strlen( $decoded ) < $iv_length ) {
			return '';
		}

		$iv = substr( $decoded, 0, $iv_length );
		$encrypted = substr( $decoded, $iv_length );

		$key = self::get_encryption_key();
		$decrypted = openssl_decrypt( $encrypted, $method, $key, 0, $iv );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Retrieve or derive encryption key from wp-config salts.
	 */
	private static function get_encryption_key(): string {
		$salt = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wppe-fallback-secret-salt-key' );
		return hash( 'sha256', $salt );
	}
}
