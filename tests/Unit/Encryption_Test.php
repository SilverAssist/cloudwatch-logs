<?php
/**
 * Credential encryption tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Unit;

use SilverAssist\CloudWatchLogs\Utils\Encryption;
use WP_UnitTestCase;

/**
 * Verifies that credentials survive a round trip and fail closed otherwise.
 *
 * @since 1.0.0
 */
class Encryption_Test extends WP_UnitTestCase {

	/**
	 * Skip the suite when the platform lacks the cipher.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL with aes-256-gcm is required.' );
		}
	}

	/**
	 * A sealed value can be opened again.
	 *
	 * @return void
	 */
	public function test_round_trip_returns_the_original_value(): void {
		$secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

		$sealed = Encryption::encrypt( $secret );

		$this->assertIsString( $sealed );
		$this->assertNotSame( $secret, $sealed, 'The stored value must not be the plaintext.' );
		$this->assertStringNotContainsString( $secret, $sealed );
		$this->assertSame( $secret, Encryption::decrypt( $sealed ) );
	}

	/**
	 * Sealing the same value twice produces different ciphertexts.
	 *
	 * @return void
	 */
	public function test_encryption_uses_a_fresh_initialisation_vector(): void {
		$first  = Encryption::encrypt( 'same-value' );
		$second = Encryption::encrypt( 'same-value' );

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Values produced by this class are recognisable.
	 *
	 * @return void
	 */
	public function test_is_encrypted_only_matches_sealed_values(): void {
		$sealed = Encryption::encrypt( 'value' );

		$this->assertIsString( $sealed );
		$this->assertTrue( Encryption::is_encrypted( $sealed ) );
		$this->assertFalse( Encryption::is_encrypted( 'AKIAIOSFODNN7EXAMPLE' ) );
		$this->assertFalse( Encryption::is_encrypted( '' ) );
	}

	/**
	 * An empty value is never sealed.
	 *
	 * @return void
	 */
	public function test_empty_input_is_not_encrypted(): void {
		$this->assertNull( Encryption::encrypt( '' ) );
	}

	/**
	 * Tampered or foreign values fail to open rather than returning garbage.
	 *
	 * @return void
	 */
	public function test_decrypt_rejects_tampered_and_foreign_values(): void {
		$sealed = Encryption::encrypt( 'value' );
		$this->assertIsString( $sealed );

		$this->assertNull( Encryption::decrypt( 'not-encrypted' ) );
		$this->assertNull( Encryption::decrypt( $sealed . 'x' ) );
		$this->assertNull( Encryption::decrypt( 'sacw:v1:' . \base64_encode( 'too-short' ) ) );
	}
}
