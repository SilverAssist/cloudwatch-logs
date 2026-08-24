<?php
/**
 * Settings repository tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Unit;

use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Utils\Encryption;
use WP_UnitTestCase;

/**
 * Verifies sanitization, secret handling and the configured-state rules.
 *
 * @since 1.0.0
 */
class SettingsRepository_Test extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Start each test from a clean option.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		\delete_option( Activator::OPTION_NAME );
		$this->settings = SettingsRepository::instance();
	}

	/**
	 * Remove the option again so tests stay independent.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\delete_option( Activator::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Defaults apply when nothing has been saved.
	 *
	 * @return void
	 */
	public function test_defaults_apply_when_nothing_is_stored(): void {
		$this->assertSame( 'auto', $this->settings->get_auth_mode() );
		$this->assertSame( 'us-east-1', $this->settings->get_string( 'region' ) );
		$this->assertSame( 10, $this->settings->get_int( 'poll_interval' ) );
	}

	/**
	 * An unknown authentication mode falls back to the safe default.
	 *
	 * @return void
	 */
	public function test_unknown_auth_mode_falls_back_to_auto(): void {
		$saved = $this->settings->save( [ 'auth_mode' => 'root-access' ] );

		$this->assertSame( 'auto', $saved['auth_mode'] );
		$this->assertSame( 'auto', $this->settings->get_auth_mode() );
	}

	/**
	 * The secret access key is stored sealed, never in plaintext.
	 *
	 * @return void
	 */
	public function test_secret_access_key_is_stored_encrypted(): void {
		if ( ! Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL with aes-256-gcm is required.' );
		}

		$this->settings->save(
			[
				'auth_mode'         => 'keys',
				'log_group'         => '/ecs/site',
				'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret_access_key' => 'super-secret-value',
			]
		);

		$raw = \get_option( Activator::OPTION_NAME );

		$this->assertIsArray( $raw );
		$this->assertNotSame( 'super-secret-value', $raw['secret_access_key'] );
		$this->assertTrue( Encryption::is_encrypted( (string) $raw['secret_access_key'] ) );
		$this->assertSame( 'super-secret-value', $this->settings->get_secret_access_key() );
	}

	/**
	 * Submitting an empty secret keeps the stored one.
	 *
	 * @return void
	 */
	public function test_empty_secret_submission_keeps_the_stored_key(): void {
		if ( ! Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL with aes-256-gcm is required.' );
		}

		$this->settings->save(
			[
				'auth_mode'         => 'keys',
				'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret_access_key' => 'keep-me',
			]
		);

		$this->settings->save(
			[
				'auth_mode'         => 'keys',
				'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret_access_key' => '',
			]
		);

		$this->assertSame( 'keep-me', $this->settings->get_secret_access_key() );
	}

	/**
	 * Switching mode discards the credentials the new mode cannot use.
	 *
	 * @return void
	 */
	public function test_switching_mode_discards_unused_credentials(): void {
		$this->settings->save(
			[
				'auth_mode'         => 'keys',
				'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret_access_key' => 'discard-me',
			]
		);

		$saved = $this->settings->save(
			[
				'auth_mode' => 'auto',
				'log_group' => '/ecs/site',
			]
		);

		$this->assertSame( '', $saved['access_key_id'] );
		$this->assertSame( '', $saved['secret_access_key'] );
		$this->assertSame( '', $this->settings->get_secret_access_key() );
	}

	/**
	 * The polling interval is clamped to the range the API quota allows.
	 *
	 * @return void
	 */
	public function test_poll_interval_is_clamped(): void {
		$this->assertSame( SettingsRepository::MIN_POLL_INTERVAL, $this->settings->save( [ 'poll_interval' => 1 ] )['poll_interval'] );
		$this->assertSame( SettingsRepository::MAX_POLL_INTERVAL, $this->settings->save( [ 'poll_interval' => 99999 ] )['poll_interval'] );
		$this->assertSame( 10, $this->settings->save( [ 'poll_interval' => 0 ] )['poll_interval'] );
	}

	/**
	 * The page size is clamped to what a single API call can return.
	 *
	 * @return void
	 */
	public function test_page_size_is_clamped(): void {
		$this->assertSame( SettingsRepository::MAX_PAGE_SIZE, $this->settings->save( [ 'page_size' => 50000 ] )['page_size'] );
		$this->assertSame( 1, $this->settings->save( [ 'page_size' => 1 ] )['page_size'] );
	}

	/**
	 * Each mode has its own definition of "ready to connect".
	 *
	 * @return void
	 */
	public function test_is_configured_depends_on_the_auth_mode(): void {
		$this->assertFalse( $this->settings->is_configured(), 'A missing log group is never configured.' );

		$this->settings->save(
			[
				'auth_mode' => 'auto',
				'log_group' => '/ecs/site',
				'region'    => 'us-east-1',
			]
		);
		$this->assertTrue( $this->settings->is_configured(), 'The host role needs no stored credentials.' );

		$this->settings->save(
			[
				'auth_mode' => 'secret',
				'log_group' => '/ecs/site',
				'region'    => 'us-east-1',
			]
		);
		$this->assertFalse( $this->settings->is_configured(), 'Secrets Manager mode needs a secret id.' );

		$this->settings->save(
			[
				'auth_mode' => 'secret',
				'log_group' => '/ecs/site',
				'region'    => 'us-east-1',
				'secret_id' => 'prod/site/cloudwatch',
			]
		);
		$this->assertTrue( $this->settings->is_configured() );
	}

	/**
	 * An unreadable stored key is reported rather than silently treated as empty.
	 *
	 * @return void
	 */
	public function test_unreadable_secret_is_reported(): void {
		\update_option(
			Activator::OPTION_NAME,
			\array_merge(
				Activator::DEFAULT_SETTINGS,
				[
					'auth_mode'         => 'keys',
					'secret_access_key' => 'sacw:v1:' . \base64_encode( 'corrupted-payload' ),
				]
			)
		);

		$this->assertTrue( $this->settings->has_unreadable_secret_access_key() );
		$this->assertSame( '', $this->settings->get_secret_access_key() );
	}
}
