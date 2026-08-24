<?php
/**
 * Settings page and AJAX integration tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Integration;

use SilverAssist\CloudWatchLogs\Admin\Ajax\ConnectionAjaxHandler;
use SilverAssist\CloudWatchLogs\Admin\AssetManager;
use SilverAssist\CloudWatchLogs\Admin\AdminPage;
use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Core\Plugin;
use SilverAssist\SettingsHub\SettingsHub;
use SilverAssist\CloudWatchLogs\Model\ConnectionStatus;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\CloudWatchLogs\View\Admin\StatusTableView;
use WP_UnitTestCase;

/**
 * Verifies hook registration, capability handling and rendered output.
 *
 * @since 1.0.0
 */
class AdminPage_Test extends WP_UnitTestCase {

	/**
	 * Reset settings before each test.
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
		\remove_all_filters( 'silver_assist_cloudwatch_capability' );
		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * The admin components register their hooks.
	 *
	 * @return void
	 */
	public function test_components_register_their_hooks(): void {
		$page = AdminPage::instance();
		$page->init();

		$this->assertNotFalse( \has_action( 'admin_menu', [ $page, 'register_with_hub' ] ) );
		$this->assertNotFalse( \has_action( 'admin_init', [ $page, 'handle_submit' ] ) );

		$ajax = ConnectionAjaxHandler::instance();
		$ajax->init();

		$this->assertNotFalse( \has_action( 'wp_ajax_silver_assist_cloudwatch_test_connection', [ $ajax, 'test_connection' ] ) );
		$this->assertNotFalse( \has_action( 'wp_ajax_silver_assist_cloudwatch_list_log_groups', [ $ajax, 'list_log_groups' ] ) );

		$assets = AssetManager::instance();
		$assets->init();

		$this->assertNotFalse( \has_action( 'admin_enqueue_scripts', [ $assets, 'enqueue' ] ) );
	}

	/**
	 * The Settings Hub card offers the Check Updates button.
	 *
	 * The button is what the other Silver Assist plugins expose, and it only
	 * appears when the update channel actually initialised — so this also
	 * catches the updater silently failing to wire itself up.
	 *
	 * @return void
	 */
	public function test_hub_card_offers_the_check_updates_action(): void {
		Plugin::instance()->init();
		AdminPage::instance()->register_with_hub();

		$registered = SettingsHub::get_instance()->get_plugins();

		$this->assertArrayHasKey( AdminPage::PAGE_SLUG, $registered );

		$actions = $registered[ AdminPage::PAGE_SLUG ]['actions'] ?? [];
		$labels  = \array_column( $actions, 'label' );

		$this->assertContains( 'Check Updates', $labels );
	}

	/**
	 * The Check Updates button prints the script that drives it.
	 *
	 * @return void
	 */
	public function test_check_updates_button_prints_its_script(): void {
		Plugin::instance()->init();

		\ob_start();
		AdminPage::instance()->render_check_updates_script();
		$output = (string) \ob_get_clean();

		$this->assertNotSame( '', $output, 'The button needs its inline script to do anything.' );
	}

	/**
	 * Components declare the priorities the kernel orders them by.
	 *
	 * @return void
	 */
	public function test_components_declare_their_priority_band(): void {
		$this->assertSame( 30, AdminPage::instance()->get_priority() );
		$this->assertSame( 30, ConnectionAjaxHandler::instance()->get_priority() );
		$this->assertSame( 40, AssetManager::instance()->get_priority() );
	}

	/**
	 * Assets are only enqueued on the plugin's own screens.
	 *
	 * @return void
	 */
	public function test_assets_load_only_on_the_plugin_screen(): void {
		$assets = AssetManager::instance();

		$assets->enqueue( 'index.php' );
		$this->assertFalse( \wp_style_is( 'silver-assist-cloudwatch-admin', 'enqueued' ) );

		$assets->enqueue( 'toplevel_page_' . AdminPage::PAGE_SLUG );
		$this->assertTrue( \wp_style_is( 'silver-assist-cloudwatch-admin', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'silver-assist-cloudwatch-admin', 'enqueued' ) );
	}

	/**
	 * The required capability is filterable.
	 *
	 * @return void
	 */
	public function test_required_capability_is_filterable(): void {
		$this->assertSame( 'manage_options', Helpers::required_capability() );

		\add_filter( 'silver_assist_cloudwatch_capability', static fn (): string => 'edit_posts' );
		$this->assertSame( 'edit_posts', Helpers::required_capability() );

		\add_filter( 'silver_assist_cloudwatch_capability', static fn (): string => '' );
		$this->assertSame( 'manage_options', Helpers::required_capability(), 'An empty filter value must not disable the check.' );
	}

	/**
	 * A subscriber cannot reach the settings screen.
	 *
	 * @return void
	 */
	public function test_render_is_empty_without_the_capability(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * An administrator sees the configuration form.
	 *
	 * @return void
	 */
	public function test_render_shows_the_form_for_an_administrator(): void {
		$_GET['tab'] = 'settings';
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'name="log_group"', $output );
		$this->assertStringContainsString( 'name="auth_mode"', $output );
		$this->assertStringContainsString( 'sacw-test-connection', $output );
	}

	/**
	 * The log group field is backed by the datalist the script fills.
	 *
	 * @return void
	 */
	public function test_log_group_field_offers_suggestions(): void {
		$_GET['tab'] = 'settings';
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'list="sacw-log-group-options"', $output );
		$this->assertStringContainsString( '<datalist id="sacw-log-group-options">', $output );
	}

	/**
	 * Only the selected credential mode's fields are visible on first paint.
	 *
	 * @return void
	 */
	public function test_unselected_auth_fields_are_hidden_server_side(): void {
		$_GET['tab'] = 'settings';
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		SettingsRepository::instance()->save(
			[
				'auth_mode' => 'secret',
				'log_group' => '/ecs/site',
				'secret_id' => 'prod/site/cloudwatch',
			]
		);

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/data-auth-mode="keys"[^>]*hidden/',
			$output,
			'The key fields must start hidden when Secrets Manager is selected.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/data-auth-mode="secret"[^>]*hidden/',
			$output,
			'The selected mode\'s fields must be visible.'
		);
	}

	/**
	 * The stored secret is never rendered into the page.
	 *
	 * @return void
	 */
	public function test_stored_secret_is_never_rendered(): void {
		$_GET['tab'] = 'settings';
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		SettingsRepository::instance()->save(
			[
				'auth_mode'         => 'keys',
				'log_group'         => '/ecs/site',
				'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret_access_key' => 'never-render-me',
			]
		);

		\ob_start();
		AdminPage::instance()->render();
		$output = (string) \ob_get_clean();

		$this->assertStringNotContainsString( 'never-render-me', $output );
	}

	/**
	 * A failed status explains itself instead of claiming a connection.
	 *
	 * @return void
	 */
	public function test_status_card_reports_a_failure(): void {
		$status = ConnectionStatus::failure(
			'Access denied for this log group.',
			'AccessDeniedException',
			'/ecs/site',
			'us-east-1',
			'auto'
		);

		\ob_start();
		StatusTableView::render( $status );
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'status-indicator inactive', $output );
		$this->assertStringContainsString( 'Access denied for this log group.', $output );
		$this->assertStringContainsString( 'AccessDeniedException', $output );
	}

	/**
	 * A successful status shows the log group details.
	 *
	 * @return void
	 */
	public function test_status_card_reports_a_connection(): void {
		$status = new ConnectionStatus(
			connected: true,
			log_group: '/ecs/site',
			region: 'us-east-1',
			auth_mode: 'auto',
			arn: 'arn:aws:logs:us-east-1:123456789012:log-group:/ecs/site:*',
			retention: 30,
			stored_bytes: 1048576,
			latest_stream: 'ecs/php/abc123',
			last_event_ms: 1700000000000,
			identity: 'arn:aws:sts::123456789012:assumed-role/site-task/abc'
		);

		\ob_start();
		StatusTableView::render( $status );
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'status-indicator active', $output );
		$this->assertStringContainsString( '/ecs/site', $output );
		$this->assertStringContainsString( '30 days', $output );
		$this->assertStringContainsString( 'Host IAM role', $output );
		$this->assertStringContainsString( 'assumed-role/site-task/abc', $output );
	}

	/**
	 * Saving requires a valid nonce.
	 *
	 * @return void
	 */
	public function test_submit_without_a_nonce_saves_nothing(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['silver_assist_cloudwatch_save'] = '1';
		$_POST['log_group']                     = '/ecs/should-not-be-saved';

		try {
			AdminPage::instance()->handle_submit();
			$this->fail( 'A missing nonce must stop the request.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Security check failed', $e->getMessage() );
		} finally {
			unset( $_POST['silver_assist_cloudwatch_save'], $_POST['log_group'] );
		}

		$this->assertSame( '', SettingsRepository::instance()->get_string( 'log_group' ) );
	}

	/**
	 * A valid submission is persisted.
	 *
	 * @return void
	 */
	public function test_valid_submission_is_persisted(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['silver_assist_cloudwatch_save']  = '1';
		$_POST['silver_assist_cloudwatch_nonce'] = \wp_create_nonce( 'silver_assist_cloudwatch_settings' );
		$_POST['auth_mode']                      = 'auto';
		$_POST['log_group']                      = '/ecs/site';
		$_POST['region']                         = 'eu-west-1';
		$_POST['poll_interval']                  = '30';

		AdminPage::instance()->handle_submit();

		unset(
			$_POST['silver_assist_cloudwatch_save'],
			$_POST['silver_assist_cloudwatch_nonce'],
			$_POST['auth_mode'],
			$_POST['log_group'],
			$_POST['region'],
			$_POST['poll_interval']
		);

		$settings = SettingsRepository::instance();

		$this->assertSame( '/ecs/site', $settings->get_string( 'log_group' ) );
		$this->assertSame( 'eu-west-1', $settings->get_string( 'region' ) );
		$this->assertSame( 30, $settings->get_int( 'poll_interval' ) );
	}
}
