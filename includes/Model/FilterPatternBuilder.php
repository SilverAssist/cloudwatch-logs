<?php
/**
 * Silver Assist CloudWatch Logs - Filter pattern construction
 *
 * @package SilverAssist\CloudWatchLogs\Model
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Model;

use InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Turns a search box entry into a CloudWatch Logs filter pattern.
 *
 * CloudWatch filter patterns are not regular expressions by default: a bare
 * term matches literally, a quoted term matches a phrase, and a regex has to be
 * wrapped in percent signs. This class keeps that distinction explicit instead
 * of guessing what the user meant.
 *
 * @since 1.0.0
 */
class FilterPatternBuilder {

	/**
	 * Supported search modes.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	public const MODES = [ 'text', 'regex', 'pattern' ];

	/**
	 * Longest search term accepted.
	 *
	 * CloudWatch rejects filter patterns longer than 1024 characters.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public const MAX_LENGTH = 1024;

	/**
	 * Most regular expressions CloudWatch accepts inside one filter pattern.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public const MAX_REGEX_COUNT = 2;

	/**
	 * Build a filter pattern from a search term.
	 *
	 * @param string $term Raw search term.
	 * @param string $mode One of text, regex or pattern.
	 * @return string The filter pattern, empty when nothing was searched for.
	 * @throws InvalidArgumentException When the term cannot be turned into a valid pattern.
	 * @since 1.0.0
	 */
	public static function build( string $term, string $mode ): string {
		$term = \trim( $term );

		if ( '' === $term ) {
			return '';
		}

		if ( ! \in_array( $mode, self::MODES, true ) ) {
			throw new InvalidArgumentException(
				\esc_html__( 'Unknown search mode.', 'silver-assist-cloudwatch-logs' )
			);
		}

		$pattern = match ( $mode ) {
			'regex'   => self::build_regex( $term ),
			'pattern' => $term,
			default   => self::build_text( $term ),
		};

		self::assert_length( $pattern );
		self::assert_regex_count( $pattern );

		return $pattern;
	}

	/**
	 * Quote a literal term so spaces and punctuation match as typed.
	 *
	 * @param string $term Raw search term.
	 * @return string The quoted term.
	 * @since 1.0.0
	 */
	private static function build_text( string $term ): string {
		// A quoted term is matched literally, so an embedded quote must go.
		return '"' . \str_replace( '"', '', $term ) . '"';
	}

	/**
	 * Wrap a regular expression in the delimiters CloudWatch expects.
	 *
	 * @param string $term Raw regular expression, with or without delimiters.
	 * @return string The delimited expression.
	 * @throws InvalidArgumentException When the expression is empty once unwrapped.
	 * @since 1.0.0
	 */
	private static function build_regex( string $term ): string {
		// Accept an expression the user already wrapped, so pasting from the
		// CloudWatch console does not produce a double-wrapped pattern.
		if ( \strlen( $term ) > 1 && \str_starts_with( $term, '%' ) && \str_ends_with( $term, '%' ) ) {
			$term = \substr( $term, 1, -1 );
		}

		if ( '' === $term ) {
			throw new InvalidArgumentException(
				\esc_html__( 'Enter a regular expression to search for.', 'silver-assist-cloudwatch-logs' )
			);
		}

		if ( \str_contains( $term, '%' ) ) {
			throw new InvalidArgumentException(
				\esc_html__( 'A regular expression cannot contain a percent sign; CloudWatch uses it as the delimiter.', 'silver-assist-cloudwatch-logs' )
			);
		}

		return '%' . $term . '%';
	}

	/**
	 * Reject a pattern CloudWatch would refuse for its length.
	 *
	 * @param string $pattern The built filter pattern.
	 * @return void
	 * @throws InvalidArgumentException When the pattern is too long.
	 * @since 1.0.0
	 */
	private static function assert_length( string $pattern ): void {
		if ( \strlen( $pattern ) <= self::MAX_LENGTH ) {
			return;
		}

		throw new InvalidArgumentException(
			\esc_html(
				\sprintf(
					/* translators: %d: the maximum number of characters. */
					\__( 'The search is too long; CloudWatch accepts at most %d characters.', 'silver-assist-cloudwatch-logs' ),
					self::MAX_LENGTH
				)
			)
		);
	}

	/**
	 * Reject a pattern using more regular expressions than CloudWatch allows.
	 *
	 * @param string $pattern The built filter pattern.
	 * @return void
	 * @throws InvalidArgumentException When the pattern holds too many expressions.
	 * @since 1.0.0
	 */
	private static function assert_regex_count( string $pattern ): void {
		$delimiters = \substr_count( $pattern, '%' );

		if ( \intdiv( $delimiters, 2 ) <= self::MAX_REGEX_COUNT ) {
			return;
		}

		throw new InvalidArgumentException(
			\esc_html(
				\sprintf(
					/* translators: %d: the maximum number of regular expressions. */
					\__( 'CloudWatch accepts at most %d regular expressions in one filter pattern.', 'silver-assist-cloudwatch-logs' ),
					self::MAX_REGEX_COUNT
				)
			)
		);
	}
}
