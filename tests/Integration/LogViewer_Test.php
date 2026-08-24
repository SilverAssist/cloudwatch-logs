<?php
/**
 * Log viewer integration tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Integration;

use SilverAssist\CloudWatchLogs\Admin\Ajax\LogsAjaxHandler;
use SilverAssist\CloudWatchLogs\Admin\AdminPage;
use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\CloudWatchLogs\View\Admin\LogViewerView;
use WP_UnitTestCase;

/**
 * Verifies tab routing, the viewer markup and the search sanitizer.
 *
 * @since 1.0.0
 */
class LogViewer_Test extends WP_UnitTestCase {

	/**
	 * Reset settings and request state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		\delete_option( Activator::OPTION_NAME );
		unset( $_GET['tab'] );
	}

	/**
	 * Clean up afterwards.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\delete_option( Activator::OPTION_NAME );
		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * The search endpoint is registered.
	 *
	 * @return void
	 */
	public function test_search_endpoint_is_registered(): void {
		$handler = LogsAjaxHandler::instance();
		$handler->init();

		$this->assertNotFalse( \has_action( 'wp_ajax_silver_assist_cloudwatch_search_logs', [ $handler, 'search' ] ) );
		$this->assertSame( 30, $handler->get_priority() );
	}

	/**
	 * An unconfigured site opens on the settings tab, since the viewer would be empty.
	 *
	 * @return void
	 */
	public function test_unconfigured_site_opens_on_settings(): void {
		$this->assertSame( 'settings', AdminPage::instance()->current_tab() );
	}

	/**
	 * A configured site opens on the logs tab.
	 *
	 * @return void
	 */
	public function test_configured_site_opens_on_logs(): void {
		SettingsRepository::instance()->save(
			[
				'auth_mode' => 'auto',
				'log_group' => '/ecs/site',
				'region'    => 'us-east-1',
			]
		);

		$this->assertSame( 'logs', AdminPage::instance()->current_tab() );
	}

	/**
	 * An explicit tab wins, and an unknown one does not.
	 *
	 * @return void
	 */
	public function test_requested_tab_is_honoured_when_known(): void {
		$_GET['tab'] = 'settings';
		$this->assertSame( 'settings', AdminPage::instance()->current_tab() );

		$_GET['tab'] = 'exploit';
		$this->assertSame( 'settings', AdminPage::instance()->current_tab(), 'An unknown tab falls back, never renders.' );
	}

	/**
	 * The viewer renders its toolbar and results table.
	 *
	 * @return void
	 */
	public function test_viewer_renders_its_controls(): void {
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

		$this->assertStringContainsString( 'id="sacw-range"', $output );
		$this->assertStringContainsString( 'id="sacw-search-mode"', $output );
		$this->assertStringContainsString( 'id="sacw-stream-prefix"', $output );
		$this->assertStringContainsString( 'id="sacw-events-body"', $output );
		$this->assertStringContainsString( 'id="sacw-load-more"', $output );
		$this->assertStringContainsString( 'data-log-group="/ecs/site"', $output );
	}

	/**
	 * Both tabs are rendered for an administrator, with the right one active.
	 *
	 * @return void
	 */
	public function test_page_renders_tabs(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET['tab'] = 'settings';

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
		$this->assertStringContainsString( 'tab=logs', $output );
		$this->assertStringContainsString( 'name="log_group"', $output, 'The settings tab must render the form.' );
		$this->assertStringNotContainsString( 'id="sacw-events-body"', $output, 'The viewer must not render on the settings tab.' );
	}

	/**
	 * The search sanitizer keeps what a search actually needs.
	 *
	 * @return void
	 */
	public function test_search_sanitizer_preserves_meaningful_characters(): void {
		$this->assertSame(
			'<html> "quoted" 4[0-9]{2}',
			Helpers::sanitize_search_term( '<html> "quoted" 4[0-9]{2}' ),
			'Stripping tags would silently change what the user searched for.'
		);
	}

	/**
	 * Control characters are removed and the term is capped.
	 *
	 * @return void
	 */
	public function test_search_sanitizer_strips_control_characters_and_caps_length(): void {
		$this->assertSame( 'abc', Helpers::sanitize_search_term( "a\x00b\x07c" ) );
		$this->assertSame( 2048, \mb_strlen( Helpers::sanitize_search_term( \str_repeat( 'x', 5000 ) ) ) );
	}
}
