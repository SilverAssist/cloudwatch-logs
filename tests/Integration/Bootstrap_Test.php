<?php
/**
 * Bootstrap integration tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Integration;

use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Core\Plugin;
use SilverAssist\CloudWatchLogs\Core\Updater;
use SilverAssist\PluginKernel\Interfaces\LoadableInterface;
use SilverAssist\WpGithubUpdater\Updater as GitHubUpdater;
use WP_UnitTestCase;

/**
 * Verifies that the plugin boots inside a real WordPress environment.
 *
 * @since 1.0.0
 */
class Bootstrap_Test extends WP_UnitTestCase {

	/**
	 * Plugin constants are defined when the main file loads.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'SILVER_ASSIST_CLOUDWATCH_VERSION' ) );
		$this->assertTrue( defined( 'SILVER_ASSIST_CLOUDWATCH_FILE' ) );
		$this->assertTrue( defined( 'SILVER_ASSIST_CLOUDWATCH_PATH' ) );
		$this->assertTrue( defined( 'SILVER_ASSIST_CLOUDWATCH_URL' ) );
		$this->assertTrue( defined( 'SILVER_ASSIST_CLOUDWATCH_BASENAME' ) );
	}

	/**
	 * The version constant matches the header of the main plugin file.
	 *
	 * @return void
	 */
	public function test_version_constant_matches_plugin_header(): void {
		$header = get_file_data(
			SILVER_ASSIST_CLOUDWATCH_FILE,
			[ 'Version' => 'Version' ]
		);

		$this->assertSame( $header['Version'], SILVER_ASSIST_CLOUDWATCH_VERSION );
	}

	/**
	 * The bootstrap exposes a singleton that the kernel can load.
	 *
	 * @return void
	 */
	public function test_plugin_instance_is_a_loadable_singleton(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
		$this->assertInstanceOf( LoadableInterface::class, $plugin );
		$this->assertSame( $plugin, Plugin::instance() );
	}

	/**
	 * The update channel actually starts.
	 *
	 * The updater is wired behind a class_exists() guard, so a wrong namespace
	 * or constructor signature would disable updates silently instead of
	 * failing. This asserts the wiring really took.
	 *
	 * @return void
	 */
	public function test_update_channel_is_wired(): void {
		$this->assertTrue(
			class_exists( GitHubUpdater::class ),
			'The wp-github-updater package must be installed under the namespace the plugin expects.'
		);

		$plugin = Plugin::instance();
		$plugin->init();

		$updater = $plugin->get_updater();

		$this->assertInstanceOf( Updater::class, $updater );
		$this->assertInstanceOf( GitHubUpdater::class, $updater );
	}

	/**
	 * The release asset the updater looks for is the one the build produces.
	 *
	 * @return void
	 */
	public function test_release_asset_pattern_matches_the_build_output(): void {
		$build = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/build-release.sh' );

		$this->assertIsString( $build );
		$this->assertStringContainsString(
			'ZIP_FILE="${PLUGIN_SLUG}-v${VERSION}.zip"',
			$build,
			'The build names the ZIP after the plugin folder; the updater asset pattern must match it.'
		);

		$updater = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/Updater.php' );

		$this->assertIsString( $updater );
		$this->assertStringContainsString( "'asset_pattern'      => 'cloudwatch-logs-v{version}.zip'", $updater );
	}

	/**
	 * Activation seeds defaults without clobbering an existing configuration.
	 *
	 * @return void
	 */
	public function test_activation_seeds_defaults_and_preserves_existing_values(): void {
		delete_option( Activator::OPTION_NAME );

		Activator::activate();
		$this->assertSame( Activator::DEFAULT_SETTINGS, get_option( Activator::OPTION_NAME ) );

		update_option(
			Activator::OPTION_NAME,
			[
				'log_group' => '/ecs/example',
				'region'    => 'eu-west-1',
			]
		);

		Activator::activate();
		$settings = get_option( Activator::OPTION_NAME );

		$this->assertSame( '/ecs/example', $settings['log_group'], 'Existing values must survive re-activation.' );
		$this->assertSame( 'eu-west-1', $settings['region'] );
		$this->assertSame( 'auto', $settings['auth_mode'], 'Missing keys must be filled from the defaults.' );

		delete_option( Activator::OPTION_NAME );
	}

	/**
	 * Deactivation clears the cached connection status.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_cached_status(): void {
		set_transient( 'silver_assist_cloudwatch_connection_status', [ 'connected' => true ], HOUR_IN_SECONDS );

		Activator::deactivate();

		$this->assertFalse( get_transient( 'silver_assist_cloudwatch_connection_status' ) );
	}
}
