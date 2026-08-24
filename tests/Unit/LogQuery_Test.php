<?php
/**
 * Search parameter tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Unit;

use InvalidArgumentException;
use SilverAssist\CloudWatchLogs\Model\LogEvent;
use SilverAssist\CloudWatchLogs\Model\LogQuery;
use WP_UnitTestCase;

/**
 * Verifies range resolution and the arguments sent to the API.
 *
 * @since 1.0.0
 */
class LogQuery_Test extends WP_UnitTestCase {

	/**
	 * A relative range resolves to a window ending now.
	 *
	 * @return void
	 */
	public function test_relative_range_resolves_to_a_window_ending_now(): void {
		$query = LogQuery::from_input( [ 'range' => '1h' ], '/ecs/site', 200 );

		$this->assertSame( 3600 * 1000, $query->end_ms - $query->start_ms );
		$this->assertEqualsWithDelta( \time() * 1000, $query->end_ms, 5000 );
	}

	/**
	 * An unknown range falls back to the default rather than failing.
	 *
	 * @return void
	 */
	public function test_unknown_range_falls_back_to_one_hour(): void {
		$query = LogQuery::from_input( [ 'range' => 'last-century' ], '/ecs/site', 200 );

		$this->assertSame( 3600 * 1000, $query->end_ms - $query->start_ms );
	}

	/**
	 * A custom range is used as given.
	 *
	 * @return void
	 */
	public function test_custom_range_is_used_as_given(): void {
		$query = LogQuery::from_input(
			[
				'range'    => 'custom',
				'start_ms' => 1700000000000,
				'end_ms'   => 1700000600000,
			],
			'/ecs/site',
			200
		);

		$this->assertSame( 1700000000000, $query->start_ms );
		$this->assertSame( 1700000600000, $query->end_ms );
	}

	/**
	 * An inverted custom range is refused.
	 *
	 * @return void
	 */
	public function test_inverted_custom_range_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		LogQuery::from_input(
			[
				'range'    => 'custom',
				'start_ms' => 1700000600000,
				'end_ms'   => 1700000000000,
			],
			'/ecs/site',
			200
		);
	}

	/**
	 * An incomplete custom range is refused.
	 *
	 * @return void
	 */
	public function test_incomplete_custom_range_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		LogQuery::from_input( [ 'range' => 'custom' ], '/ecs/site', 200 );
	}

	/**
	 * A query without a log group is refused.
	 *
	 * @return void
	 */
	public function test_missing_log_group_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		LogQuery::from_input( [ 'range' => '1h' ], '', 200 );
	}

	/**
	 * Optional arguments are omitted rather than sent empty.
	 *
	 * @return void
	 */
	public function test_optional_arguments_are_omitted_when_unset(): void {
		$args = LogQuery::from_input( [ 'range' => '1h' ], '/ecs/site', 200 )->to_api_args();

		$this->assertSame( '/ecs/site', $args['logGroupName'] );
		$this->assertSame( 200, $args['limit'] );
		$this->assertArrayNotHasKey( 'filterPattern', $args );
		$this->assertArrayNotHasKey( 'logStreamNamePrefix', $args );
		$this->assertArrayNotHasKey( 'nextToken', $args );
	}

	/**
	 * Search, stream prefix and pagination reach the API when set.
	 *
	 * @return void
	 */
	public function test_populated_arguments_reach_the_api(): void {
		$args = LogQuery::from_input(
			[
				'range'         => '1h',
				'search'        => 'timeout',
				'search_mode'   => 'text',
				'stream_prefix' => 'ecs/php',
				'next_token'    => 'token-123',
			],
			'/ecs/site',
			50
		)->to_api_args();

		$this->assertSame( '"timeout"', $args['filterPattern'] );
		$this->assertSame( 'ecs/php', $args['logStreamNamePrefix'] );
		$this->assertSame( 'token-123', $args['nextToken'] );
	}

	/**
	 * A follow-up query starts just after the last event seen.
	 *
	 * @return void
	 */
	public function test_following_query_starts_after_the_last_event(): void {
		$query = LogQuery::from_input(
			[
				'range'       => '1h',
				'search'      => 'timeout',
				'search_mode' => 'text',
			],
			'/ecs/site',
			200
		);

		$next = $query->following( 1700000000000 );

		$this->assertSame( 1700000000001, $next->start_ms );
		$this->assertSame( '"timeout"', $next->pattern, 'The follow-up must keep the same filter.' );
		$this->assertSame( '', $next->next_token, 'A follow-up starts a fresh page.' );
	}

	/**
	 * An API row becomes an event with a formatted timestamp.
	 *
	 * @return void
	 */
	public function test_log_event_is_built_from_an_api_row(): void {
		$event = LogEvent::from_api(
			[
				'eventId'       => '123',
				'timestamp'     => 1700000000000,
				'logStreamName' => 'ecs/php/abc',
				'message'       => 'Something happened',
			]
		);

		$array = $event->to_array();

		$this->assertSame( '123', $array['id'] );
		$this->assertSame( 'ecs/php/abc', $array['stream'] );
		$this->assertSame( 'Something happened', $array['message'] );
		$this->assertNotSame( '', $array['time'] );
	}

	/**
	 * A partial API row does not produce warnings or wrong types.
	 *
	 * @return void
	 */
	public function test_log_event_tolerates_a_partial_api_row(): void {
		$array = LogEvent::from_api( [] )->to_array();

		$this->assertSame( '', $array['id'] );
		$this->assertSame( 0, $array['timestamp'] );
		$this->assertSame( '', $array['time'] );
	}
}
