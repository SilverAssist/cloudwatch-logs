<?php
/**
 * Silver Assist CloudWatch Logs - AWS client construction
 *
 * @package SilverAssist\CloudWatchLogs\Service
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Service;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Sts\StsClient;
use RuntimeException;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;

\defined( 'ABSPATH' ) || exit;

/**
 * Builds configured AWS clients.
 *
 * @since 1.0.0
 */
class ClientFactory {

	/**
	 * CloudWatch Logs API version this plugin targets.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const LOGS_API_VERSION = '2014-03-28';

	/**
	 * STS API version used to report the resolved identity.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const STS_API_VERSION = '2011-06-15';

	/**
	 * Settings source.
	 *
	 * @var SettingsRepository
	 * @since 1.0.0
	 */
	private SettingsRepository $settings;

	/**
	 * Credential resolver.
	 *
	 * @var CredentialsResolver
	 * @since 1.0.0
	 */
	private CredentialsResolver $credentials;

	/**
	 * Build the factory.
	 *
	 * @param SettingsRepository|null  $settings    Settings source, defaulting to the shared repository.
	 * @param CredentialsResolver|null $credentials Credential resolver, defaulting to a new one.
	 * @since 1.0.0
	 */
	public function __construct( ?SettingsRepository $settings = null, ?CredentialsResolver $credentials = null ) {
		$this->settings    = $settings ?? SettingsRepository::instance();
		$this->credentials = $credentials ?? new CredentialsResolver( $this->settings );
	}

	/**
	 * Build a CloudWatch Logs client.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return CloudWatchLogsClient The configured client.
	 * @throws RuntimeException When the SDK is unavailable or credentials cannot be resolved.
	 * @since 1.0.0
	 */
	public function make_logs_client( array $overrides = [] ): CloudWatchLogsClient {
		$this->assert_sdk_available();

		return new CloudWatchLogsClient( $this->build_config( self::LOGS_API_VERSION, $overrides ) );
	}

	/**
	 * Build an STS client, used to report which identity the plugin resolved.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return StsClient The configured client.
	 * @throws RuntimeException When the SDK is unavailable or credentials cannot be resolved.
	 * @since 1.0.0
	 */
	public function make_sts_client( array $overrides = [] ): StsClient {
		$this->assert_sdk_available();

		return new StsClient( $this->build_config( self::STS_API_VERSION, $overrides ) );
	}

	/**
	 * Assemble the shared client configuration.
	 *
	 * @param string                $version   API version for the client being built.
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return array<string, mixed> The SDK client configuration.
	 * @throws RuntimeException When credentials cannot be resolved.
	 * @since 1.0.0
	 */
	private function build_config( string $version, array $overrides ): array {
		$region = $overrides['region'] ?? $this->settings->get_string( 'region' );

		if ( '' === $region ) {
			throw new RuntimeException(
				\esc_html__( 'Select an AWS region before connecting.', 'silver-assist-cloudwatch-logs' )
			);
		}

		$config = [
			'version' => $version,
			'region'  => $region,
			'retries' => 3,
			'http'    => [
				'connect_timeout' => 5,
				'timeout'         => 30,
			],
		];

		$credentials = $this->credentials->resolve( $overrides );

		if ( null !== $credentials ) {
			$config['credentials'] = $credentials;
		}

		/**
		 * Filters the configuration used to build the plugin's AWS clients.
		 *
		 * Callbacks must return the configuration array; anything else breaks
		 * client construction.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config  SDK client configuration.
		 * @param string               $version API version of the client being built.
		 */
		return \apply_filters( 'silver_assist_cloudwatch_client_config', $config, $version );
	}

	/**
	 * Fail loudly when the AWS SDK is not autoloadable.
	 *
	 * @return void
	 * @throws RuntimeException When the SDK is missing.
	 * @since 1.0.0
	 */
	private function assert_sdk_available(): void {
		if ( SdkLoader::is_available() ) {
			return;
		}

		throw new RuntimeException(
			\esc_html__(
				'The AWS SDK for PHP is not available. Reinstall the plugin from its release package, or run "composer install" in the plugin directory.',
				'silver-assist-cloudwatch-logs'
			)
		);
	}
}
