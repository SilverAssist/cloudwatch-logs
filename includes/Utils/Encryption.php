<?php
/**
 * Silver Assist CloudWatch Logs - Credential encryption
 *
 * @package SilverAssist\CloudWatchLogs\Utils
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Utils;

\defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts credential values stored in the options table.
 *
 * Values are sealed with AES-256-GCM under a key derived from the site's
 * `secure_auth` salt, so a database dump alone does not expose the AWS secret
 * access key. Rotating the salts intentionally invalidates stored credentials:
 * decryption fails cleanly and the admin is asked to re-enter them.
 *
 * @since 1.0.0
 */
class Encryption {

	/**
	 * Cipher used to seal credential values.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Marker identifying a value produced by this class.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const PREFIX = 'sacw:v1:';

	/**
	 * Length of the GCM authentication tag, in bytes.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Whether the platform can seal and open credential values.
	 *
	 * @return bool True when the OpenSSL extension provides the cipher.
	 * @since 1.0.0
	 */
	public static function is_available(): bool {
		return \function_exists( 'openssl_encrypt' )
			&& \in_array( self::CIPHER, \openssl_get_cipher_methods(), true );
	}

	/**
	 * Seal a plaintext value.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|null The sealed value, or null when encryption is unavailable or fails.
	 * @since 1.0.0
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( '' === $plaintext || ! self::is_available() ) {
			return null;
		}

		$iv_length = \openssl_cipher_iv_length( self::CIPHER );

		if ( $iv_length <= 0 ) {
			return null;
		}

		$iv  = \openssl_random_pseudo_bytes( $iv_length );
		$tag = '';

		$ciphertext = \openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( ! \is_string( $ciphertext ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding for the sealed binary payload, not obfuscated code.
		return self::PREFIX . \base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Open a value sealed by encrypt().
	 *
	 * @param string $value Stored value.
	 * @return string|null The plaintext, or null when the value cannot be opened.
	 * @since 1.0.0
	 */
	public static function decrypt( string $value ): ?string {
		if ( ! self::is_encrypted( $value ) || ! self::is_available() ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding for the sealed binary payload, not obfuscated code.
		$payload = \base64_decode( \substr( $value, \strlen( self::PREFIX ) ), true );

		if ( ! \is_string( $payload ) ) {
			return null;
		}

		$iv_length = \openssl_cipher_iv_length( self::CIPHER );

		if ( $iv_length <= 0 || \strlen( $payload ) <= $iv_length + self::TAG_LENGTH ) {
			return null;
		}

		$iv         = \substr( $payload, 0, $iv_length );
		$tag        = \substr( $payload, $iv_length, self::TAG_LENGTH );
		$ciphertext = \substr( $payload, $iv_length + self::TAG_LENGTH );

		$plaintext = \openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return \is_string( $plaintext ) ? $plaintext : null;
	}

	/**
	 * Whether a stored value was produced by this class.
	 *
	 * @param string $value Stored value.
	 * @return bool True when the value carries this class's marker.
	 * @since 1.0.0
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === \strpos( $value, self::PREFIX );
	}

	/**
	 * Derive the encryption key from the site's salts.
	 *
	 * @return string Raw 32-byte key.
	 * @since 1.0.0
	 */
	private static function get_key(): string {
		return \hash( 'sha256', \wp_salt( 'secure_auth' ), true );
	}
}
