<?php
/**
 * Tail and throttling integration tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Integration;

use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\CloudWatchLogs\View\Admin\LogViewerView;
use WP_UnitTestCase;

/**
 * Verifies the throttles that protect the shared CloudWatch quota, and the
 * controls the tail depends on.
 *
 * @since 1.0.0
 */
class Tail_Test extends WP_UnitTestCase {

	/**
	 * Reset settings and locks before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		\delete_option( Activator::OPTION_NAME );
		\delete_transient( 'silver_assist_cloudwatch_lock_search_logs' );
	}

	/**
	 * Clean up afterwards.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\delete_option( Activator::OPTION_NAME );
		\delete_transient( 'silver_assist_cloudwatch_lock_search_logs' );

		parent::tear_down();
	}

	/**
	 * The per-user throttle lets the first call through and holds the second.
	 *
	 * @return void
	 */
	public function test_per_user_throttle_holds_the_second_call(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue( Helpers::throttle( 'tail_test', 30 ) );
		$this->assertFalse( Helpers::throttle( 'tail_test', 30 ) );
	}

	/**
	 * The per-user throttle is not shared between users.
	 *
	 * @return void
	 */
	public function test_per_user_throttle_is_per_user(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Helpers::throttle( 'tail_test_two', 30 ) );

		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Helpers::throttle( 'tail_test_two', 30 ), 'A second administrator has their own budget.' );
	}

	/**
	 * The site-wide lock does stop a second administrator.
	 *
	 * @return void
	 */
	public function test_site_throttle_is_shared_between_users(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Helpers::throttle_site( 'search_logs', 30 ) );

		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse(
			Helpers::throttle_site( 'search_logs', 30 ),
			'Two administrators tailing at once must not double the request rate.'
		);
	}

	/**
	 * The viewer renders the tail toggle and export controls.
	 *
	 * @return void
	 */
	public function test_viewer_renders_tail_and_export_controls(): void {
		SettingsRepository::instance()->save(
			[
				'auth_mode'     => 'auto',
				'log_group'     => '/ecs/site',
				'region'        => 'us-east-1',
				'poll_interval' => 15,
			]
		);

		\ob_start();
		LogViewerView::render( SettingsRepository::instance() );
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'id="sacw-tail"', $output );
		$this->assertStringContainsString( 'id="sacw-export-csv"', $output );
		$this->assertStringContainsString( 'id="sacw-export-json"', $output );
		$this->assertStringContainsString( 'refresh every 15s', $output );
	}

	/**
	 * The export buttons start disabled, since nothing is loaded yet.
	 *
	 * @return void
	 */
	public function test_export_controls_start_disabled(): void {
		SettingsRepository::instance()->save(
			[
				'auth_mode' => 'auto',
				'log_group' => '/ecs/site',
				'region'    => 'us-east-1',
			]
		);

		\ob_start();
		LogViewerView::render( SettingsRepository::instance() );
		$output = (string) \ob_get_clean();

		$this->assertMatchesRegularExpression( '/id="sacw-export-csv"[^>]*disabled/', $output );
		$this->assertMatchesRegularExpression( '/id="sacw-export-json"[^>]*disabled/', $output );
	}

	/**
	 * The refresh interval offered to the browser respects the configured floor.
	 *
	 * @return void
	 */
	public function test_poll_interval_never_drops_below_the_quota_floor(): void {
		SettingsRepository::instance()->save(
			[
				'auth_mode'     => 'auto',
				'log_group'     => '/ecs/site',
				'poll_interval' => 1,
			]
		);

		$this->assertSame(
			SettingsRepository::MIN_POLL_INTERVAL,
			SettingsRepository::instance()->get_int( 'poll_interval' )
		);
	}
}
