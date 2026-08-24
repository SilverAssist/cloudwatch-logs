<?php
/**
 * Silver Assist CloudWatch Logs - Connection validation
 *
 * @package SilverAssist\CloudWatchLogs\Service
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Service;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use SilverAssist\CloudWatchLogs\Model\ConnectionStatus;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use Throwable;

\defined( 'ABSPATH' ) || exit;

/**
 * Probes the configured log group and reports what the credentials can see.
 *
 * @since 1.0.0
 */
class ConnectionTester {

	/**
	 * How long a successful status is reused before probing AWS again.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	private const CACHE_TTL = 300;

	/**
	 * Transient holding the cached status.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const CACHE_KEY = 'silver_assist_cloudwatch_connection_status';

	/**
	 * Settings source.
	 *
	 * @var SettingsRepository
	 * @since 1.0.0
	 */
	private SettingsRepository $settings;

	/**
	 * AWS client factory.
	 *
	 * @var ClientFactory
	 * @since 1.0.0
	 */
	private ClientFactory $clients;

	/**
	 * Build the tester.
	 *
	 * @param SettingsRepository|null $settings Settings source, defaulting to the shared repository.
	 * @param ClientFactory|null      $clients  Client factory, defaulting to a new one.
	 * @since 1.0.0
	 */
	public function __construct( ?SettingsRepository $settings = null, ?ClientFactory $clients = null ) {
		$this->settings = $settings ?? SettingsRepository::instance();
		$this->clients  = $clients ?? new ClientFactory( $this->settings );
	}

	/**
	 * Return the cached status, probing AWS only when nothing is cached.
	 *
	 * @return ConnectionStatus The current status.
	 * @since 1.0.0
	 */
	public function cached_status(): ConnectionStatus {
		$cached = \get_transient( self::CACHE_KEY );

		if ( \is_array( $cached ) ) {
			return ConnectionStatus::from_array( $cached );
		}

		return $this->test_and_cache();
	}

	/**
	 * Probe the configured log group.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return ConnectionStatus The result, cached when successful.
	 * @since 1.0.0
	 */
	public function test( array $overrides = [] ): ConnectionStatus {
		$log_group = $overrides['log_group'] ?? $this->settings->get_string( 'log_group' );
		$region    = $overrides['region'] ?? $this->settings->get_string( 'region' );
		$auth_mode = $overrides['auth_mode'] ?? $this->settings->get_auth_mode();

		if ( '' === $log_group ) {
			return ConnectionStatus::failure(
				\__( 'Enter the name of the log group you want to read.', 'silver-assist-cloudwatch-logs' ),
				'',
				$log_group,
				$region,
				$auth_mode
			);
		}

		try {
			$logs   = $this->clients->make_logs_client( $overrides );
			$result = $logs->describeLogGroups(
				[
					'logGroupNamePrefix' => $log_group,
					'limit'              => 50,
				]
			);

			$group = $this->find_exact_group( $result['logGroups'] ?? [], $log_group );

			if ( null === $group ) {
				return ConnectionStatus::failure(
					\sprintf(
						/* translators: %s: the configured log group name. */
						\__( 'The credentials work, but no log group named "%s" was found in this region.', 'silver-assist-cloudwatch-logs' ),
						$log_group
					),
					'ResourceNotFoundException',
					$log_group,
					$region,
					$auth_mode
				);
			}

			$stream = $this->describe_latest_stream( $logs, $log_group );

			return new ConnectionStatus(
				connected: true,
				log_group: $log_group,
				region: $region,
				auth_mode: $auth_mode,
				arn: isset( $group['arn'] ) && \is_string( $group['arn'] ) ? $group['arn'] : null,
				retention: isset( $group['retentionInDays'] ) ? (int) $group['retentionInDays'] : null,
				stored_bytes: isset( $group['storedBytes'] ) ? (int) $group['storedBytes'] : 0,
				latest_stream: $stream['name'],
				last_event_ms: $stream['last_event_ms'],
				identity: $this->describe_identity( $overrides )
			);
		} catch ( AwsException $e ) {
			return ConnectionStatus::failure(
				$this->explain( $e ),
				(string) $e->getAwsErrorCode(),
				$log_group,
				$region,
				$auth_mode
			);
		} catch ( Throwable $e ) {
			return ConnectionStatus::failure( $e->getMessage(), '', $log_group, $region, $auth_mode );
		} finally {
			// Any probe invalidates whatever was cached; the caller stores the fresh result.
			\delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Probe and cache the result.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return ConnectionStatus The result.
	 * @since 1.0.0
	 */
	public function test_and_cache( array $overrides = [] ): ConnectionStatus {
		$status = $this->test( $overrides );

		\set_transient( self::CACHE_KEY, $status->to_array(), self::CACHE_TTL );

		return $status;
	}

	/**
	 * Pick the log group whose name matches exactly.
	 *
	 * The API matches log group names on prefix, so "/ecs/app" also returns
	 * "/ecs/app-staging"; only an exact name counts as configured.
	 *
	 * @param mixed  $groups Log groups returned by the API.
	 * @param string $name   Configured log group name.
	 * @return array<string, mixed>|null The matching group, or null.
	 * @since 1.0.0
	 */
	private function find_exact_group( mixed $groups, string $name ): ?array {
		if ( ! \is_array( $groups ) ) {
			return null;
		}

		foreach ( $groups as $group ) {
			if ( \is_array( $group ) && ( $group['logGroupName'] ?? '' ) === $name ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Describe the most recently active stream of a log group.
	 *
	 * @param CloudWatchLogsClient $logs      CloudWatch Logs client.
	 * @param string               $log_group Log group name.
	 * @return array{name: string, last_event_ms: int} Stream summary, empty when the group has no streams.
	 * @since 1.0.0
	 */
	private function describe_latest_stream( CloudWatchLogsClient $logs, string $log_group ): array {
		try {
			$result = $logs->describeLogStreams(
				[
					'logGroupName' => $log_group,
					'orderBy'      => 'LastEventTime',
					'descending'   => true,
					'limit'        => 1,
				]
			);
		} catch ( AwsException $e ) {
			// Listing streams needs its own permission; the group itself is still readable.
			unset( $e );

			return [
				'name'          => '',
				'last_event_ms' => 0,
			];
		}

		$streams = $result['logStreams'] ?? [];
		$stream  = \is_array( $streams ) && isset( $streams[0] ) && \is_array( $streams[0] ) ? $streams[0] : [];

		return [
			'name'          => (string) ( $stream['logStreamName'] ?? '' ),
			'last_event_ms' => (int) ( $stream['lastEventTimestamp'] ?? 0 ),
		];
	}

	/**
	 * Report which IAM identity the resolved credentials belong to.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return string The caller ARN, or an empty string when it cannot be read.
	 * @since 1.0.0
	 */
	private function describe_identity( array $overrides ): string {
		try {
			$result = $this->clients->make_sts_client( $overrides )->getCallerIdentity();

			return (string) ( $result['Arn'] ?? '' );
		} catch ( Throwable $e ) {
			// The identity is informational; failing to read it must not fail the check.
			unset( $e );

			return '';
		}
	}

	/**
	 * Turn an AWS error into an actionable message.
	 *
	 * @param AwsException $e The error returned by the SDK.
	 * @return string A message naming the likely cause.
	 * @since 1.0.0
	 */
	private function explain( AwsException $e ): string {
		$detail = $e->getAwsErrorMessage() ?? $e->getMessage();

		return match ( (string) $e->getAwsErrorCode() ) {
			'AccessDeniedException', 'AccessDenied' => \sprintf(
				/* translators: %s: the error message returned by AWS. */
				\__( 'Access denied. Grant logs:DescribeLogGroups, logs:DescribeLogStreams and logs:FilterLogEvents on this log group to the IAM identity in use. AWS said: %s', 'silver-assist-cloudwatch-logs' ),
				$detail
			),
			'UnrecognizedClientException', 'InvalidClientTokenId', 'InvalidSignatureException' => \sprintf(
				/* translators: %s: the error message returned by AWS. */
				\__( 'AWS rejected the credentials. Check the access key and secret, or the Secrets Manager secret. AWS said: %s', 'silver-assist-cloudwatch-logs' ),
				$detail
			),
			'ResourceNotFoundException' => \sprintf(
				/* translators: %s: the error message returned by AWS. */
				\__( 'The log group does not exist in this region. AWS said: %s', 'silver-assist-cloudwatch-logs' ),
				$detail
			),
			'ThrottlingException' => \__(
				'AWS is throttling requests for this account. Wait a moment and try again.',
				'silver-assist-cloudwatch-logs'
			),
			default => $detail,
		};
	}
}
