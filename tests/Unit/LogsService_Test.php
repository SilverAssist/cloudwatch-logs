<?php
/**
 * Log search service tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Unit;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use RuntimeException;
use SilverAssist\CloudWatchLogs\Model\LogQuery;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Service\ClientFactory;
use SilverAssist\CloudWatchLogs\Service\LogsService;
use WP_UnitTestCase;

/**
 * Drives the service with the SDK's MockHandler, so no request leaves the host.
 *
 * @since 1.0.0
 */
class LogsService_Test extends WP_UnitTestCase {

	/**
	 * Build a service whose client returns the queued results.
	 *
	 * Retries are disabled here so one queued error surfaces as one failure.
	 * In production ClientFactory asks for three retries, which is why a
	 * transient throttle usually resolves itself before the user sees it.
	 *
	 * @param MockHandler $handler Queued results or errors.
	 * @return LogsService The service under test.
	 */
	private function service_with( MockHandler $handler ): LogsService {
		$client = new CloudWatchLogsClient(
			[
				'version'     => '2014-03-28',
				'region'      => 'us-east-1',
				'credentials' => [
					'key'    => 'test-key',
					'secret' => 'test-secret',
				],
				'handler'     => $handler,
				'retries'     => 0,
			]
		);

		$factory = $this->createMock( ClientFactory::class );
		$factory->method( 'make_logs_client' )->willReturn( $client );

		return new LogsService( SettingsRepository::instance(), $factory );
	}

	/**
	 * Build a query for the tests.
	 *
	 * @param array<string, mixed> $input Extra request values.
	 * @return LogQuery The query.
	 */
	private function query( array $input = [] ): LogQuery {
		return LogQuery::from_input( \array_merge( [ 'range' => '1h' ], $input ), '/ecs/site', 200 );
	}

	/**
	 * A successful search returns events and the pagination token.
	 *
	 * @return void
	 */
	public function test_search_returns_events_and_the_next_token(): void {
		$handler = new MockHandler();
		$handler->append(
			new Result(
				[
					'events'             => [
						[
							'eventId'       => '1',
							'timestamp'     => 1700000000000,
							'logStreamName' => 'ecs/php/a',
							'message'       => 'first',
						],
						[
							'eventId'       => '2',
							'timestamp'     => 1700000001000,
							'logStreamName' => 'ecs/php/b',
							'message'       => 'second',
						],
					],
					'nextToken'          => 'token-abc',
					'searchedLogStreams' => [ [ 'logStreamName' => 'ecs/php/a' ] ],
				]
			)
		);

		$page = $this->service_with( $handler )->search( $this->query() );

		$this->assertCount( 2, $page['events'] );
		$this->assertSame( 'first', $page['events'][0]->message );
		$this->assertSame( 'ecs/php/b', $page['events'][1]->stream );
		$this->assertSame( 'token-abc', $page['next_token'] );
		$this->assertSame( 1, $page['searched_streams'] );
	}

	/**
	 * An empty result is a valid answer, not an error.
	 *
	 * @return void
	 */
	public function test_search_handles_an_empty_result(): void {
		$handler = new MockHandler();
		$handler->append( new Result( [] ) );

		$page = $this->service_with( $handler )->search( $this->query() );

		$this->assertSame( [], $page['events'] );
		$this->assertSame( '', $page['next_token'] );
		$this->assertSame( 0, $page['searched_streams'] );
	}

	/**
	 * A denied request explains which permission is missing.
	 *
	 * @return void
	 */
	public function test_access_denied_names_the_missing_permission(): void {
		$handler = new MockHandler();
		$handler->append(
			static function ( CommandInterface $cmd ): AwsException {
				return new AwsException(
					'Denied',
					$cmd,
					[
						'code'    => 'AccessDeniedException',
						'message' => 'User is not authorized',
					]
				);
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/logs:FilterLogEvents/' );

		$this->service_with( $handler )->search( $this->query() );
	}

	/**
	 * AWS messages reach the browser unescaped, ready for textContent.
	 *
	 * The reported symptom was a viewer showing
	 * `Error executing &quot;FilterLogEvents&quot; on &quot;https://...&quot;`
	 * because the message was escaped before it was ever rendered.
	 *
	 * @return void
	 */
	public function test_error_messages_are_not_pre_escaped(): void {
		$handler = new MockHandler();
		$handler->append(
			static function ( CommandInterface $cmd ): AwsException {
				return new AwsException(
					'Boom',
					$cmd,
					[
						'code'    => 'InvalidParameterException',
						'message' => 'Error executing "FilterLogEvents" on "https://logs.us-east-1.amazonaws.com/"',
					]
				);
			}
		);

		try {
			$this->service_with( $handler )->search( $this->query() );
			$this->fail( 'The rejected search must raise.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringNotContainsString( '&quot;', $e->getMessage() );
			$this->assertStringContainsString( '"FilterLogEvents"', $e->getMessage() );
		}
	}

	/**
	 * Throttling explains the account-wide quota rather than blaming the user.
	 *
	 * @return void
	 */
	public function test_throttling_explains_the_account_quota(): void {
		$handler = new MockHandler();
		$handler->append(
			static function ( CommandInterface $cmd ): AwsException {
				return new AwsException(
					'Rate exceeded',
					$cmd,
					[
						'code'    => 'ThrottlingException',
						'message' => 'Rate exceeded',
					]
				);
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/five searches per second/' );

		$this->service_with( $handler )->search( $this->query() );
	}

	/**
	 * A rejected filter pattern surfaces what CloudWatch objected to.
	 *
	 * @return void
	 */
	public function test_invalid_pattern_surfaces_the_aws_message(): void {
		$handler = new MockHandler();
		$handler->append(
			static function ( CommandInterface $cmd ): AwsException {
				return new AwsException(
					'Invalid',
					$cmd,
					[
						'code'    => 'InvalidParameterException',
						'message' => 'Invalid filter pattern',
					]
				);
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid filter pattern/' );

		$this->service_with( $handler )->search( $this->query() );
	}
}
