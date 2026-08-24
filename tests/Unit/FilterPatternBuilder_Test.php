<?php
/**
 * Filter pattern construction tests.
 *
 * @package SilverAssist\CloudWatchLogs\Tests
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Tests\Unit;

use InvalidArgumentException;
use SilverAssist\CloudWatchLogs\Model\FilterPatternBuilder;
use WP_UnitTestCase;

/**
 * Verifies each search mode produces the pattern CloudWatch expects.
 *
 * @since 1.0.0
 */
class FilterPatternBuilder_Test extends WP_UnitTestCase {

	/**
	 * A text search is quoted so it matches literally.
	 *
	 * @return void
	 */
	public function test_text_mode_quotes_the_term(): void {
		$this->assertSame( '"fatal error"', FilterPatternBuilder::build( 'fatal error', 'text' ) );
	}

	/**
	 * Embedded quotes cannot break out of the quoted term.
	 *
	 * @return void
	 */
	public function test_text_mode_strips_embedded_quotes(): void {
		$this->assertSame( '"a b"', FilterPatternBuilder::build( 'a" "b', 'text' ) );
	}

	/**
	 * A regex is wrapped in the delimiters CloudWatch requires.
	 *
	 * @return void
	 */
	public function test_regex_mode_wraps_the_expression(): void {
		$this->assertSame( '%4[0-9]{2}%', FilterPatternBuilder::build( '4[0-9]{2}', 'regex' ) );
	}

	/**
	 * An expression pasted from the console is not wrapped twice.
	 *
	 * @return void
	 */
	public function test_regex_mode_accepts_an_already_wrapped_expression(): void {
		$this->assertSame( '%ERROR%', FilterPatternBuilder::build( '%ERROR%', 'regex' ) );
	}

	/**
	 * A filter pattern is passed through untouched.
	 *
	 * @return void
	 */
	public function test_pattern_mode_passes_the_input_through(): void {
		$this->assertSame( '{ $.level = "error" }', FilterPatternBuilder::build( '{ $.level = "error" }', 'pattern' ) );
	}

	/**
	 * An empty search means no filtering at all.
	 *
	 * @return void
	 */
	public function test_empty_term_produces_no_pattern(): void {
		$this->assertSame( '', FilterPatternBuilder::build( '   ', 'text' ) );
		$this->assertSame( '', FilterPatternBuilder::build( '', 'regex' ) );
	}

	/**
	 * An unknown mode is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function test_unknown_mode_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FilterPatternBuilder::build( 'anything', 'sql' );
	}

	/**
	 * A regex cannot contain the delimiter CloudWatch uses.
	 *
	 * @return void
	 */
	public function test_regex_containing_a_delimiter_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FilterPatternBuilder::build( '50% done', 'regex' );
	}

	/**
	 * A term longer than CloudWatch accepts is refused before the API call.
	 *
	 * @return void
	 */
	public function test_overlong_term_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FilterPatternBuilder::build( \str_repeat( 'a', FilterPatternBuilder::MAX_LENGTH + 1 ), 'text' );
	}

	/**
	 * More expressions than CloudWatch allows are refused before the API call.
	 *
	 * @return void
	 */
	public function test_too_many_regexes_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FilterPatternBuilder::build( '%a% %b% %c%', 'pattern' );
	}
}
