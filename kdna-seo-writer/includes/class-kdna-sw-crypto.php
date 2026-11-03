<?php
/**
 * API key encryption.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the Anthropic API key encrypted, and reads it back for server use only.
 *
 * The key is locked with a secret derived from the site's own salts in
 * wp-config.php, using libsodium (built into PHP, and backed up by WordPress's
 * own copy). Someone who gets hold of a database backup alone cannot read the
 * key. If the salts are ever changed, the old key can no longer be unlocked and
 * the Connection tab asks for it again.
 *
 * The key is never sent to the browser, never included in REST responses and
 * never shown in debug output.
 */
final class KDNA_SeoWriter_Crypto {

	/**
	 * Option holding the encrypted key.
	 */
	public const OPTION = 'kdna_sw_api_key';

	/**
	 * Marker at the start of stored values, so the format can change later.
	 */
	private const PREFIX = 'kdnasw1:';

	/**
	 * Encrypts and saves the API key.
	 *
	 * @param string $api_key The plain key as typed in the Connection tab.
	 * @return bool True when the key was saved.
	 */
	public static function save_key( string $api_key ): bool {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return false;
		}

		$sealed = self::encrypt( $api_key );
		if ( '' === $sealed ) {
			return false;
		}

		update_option( self::OPTION, $sealed, false );
		return true;
	}

	/**
	 * Deletes the stored API key.
	 *
	 * @return void
	 */
	public static function delete_key(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Tells you whether a key is saved, without unlocking it.
	 *
	 * @return bool True when something is stored.
	 */
	public static function has_key(): bool {
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && str_starts_with( $stored, self::PREFIX );
	}

	/**
	 * Tells you whether the saved key can still be unlocked with today's salts.
	 *
	 * @return bool True when the key decrypts correctly.
	 */
	public static function key_is_readable(): bool {
		return '' !== self::get_key();
	}

	/**
	 * Unlocks and returns the API key for use in a server-side request.
	 *
	 * Only the Anthropic client should call this, and the result must never be
	 * printed, logged or returned to the browser.
	 *
	 * @return string The plain key, or an empty string if none can be read.
	 */
	public static function get_key(): string {
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || ! str_starts_with( $stored, self::PREFIX ) ) {
			return '';
		}
		return self::decrypt( $stored );
	}

	/**
	 * Builds the 32-byte encryption secret from the site's salts.
	 *
	 * @return string Raw 32-byte secret.
	 */
	private static function secret(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'kdna-seo-writer-api-key' );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Value to encrypt.
	 * @return string Encrypted value ready to store, or empty on failure.
	 */
	private static function encrypt( string $plain ): string {
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret() );
			return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding for binary data.
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypts a stored value.
	 *
	 * @param string $stored Value as stored in the option.
	 * @return string Plain value, or empty if it cannot be unlocked.
	 */
	private static function decrypt( string $stored ): string {
		try {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding for binary data.
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret() );
			return false === $plain ? '' : $plain;
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
