<?php
/**
 * Silver Assist CloudWatch Logs - Shared helpers
 *
 * @package SilverAssist\CloudWatchLogs\Utils
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Utils;

\defined( 'ABSPATH' ) || exit;

/**
 * Small helpers shared across the plugin.
 *
 * @since 1.0.0
 */
class Helpers {

	/**
	 * Nonce action used by every AJAX endpoint of this plugin.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const NONCE_ACTION = 'silver_assist_cloudwatch_ajax';

	/**
	 * The capability required to read logs through this plugin.
	 *
	 * @return string The capability name.
	 * @since 1.0.0
	 */
	public static function required_capability(): string {
		/**
		 * Filters the capability required to view and search CloudWatch logs.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name. Default 'manage_options'.
		 */
		$capability = \apply_filters( 'silver_assist_cloudwatch_capability', 'manage_options' );

		return '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Whether the current user may use the plugin.
	 *
	 * @return bool True when the user holds the required capability.
	 * @since 1.0.0
	 */
	public static function current_user_can_access(): bool {
		return \current_user_can( self::required_capability() );
	}

	/**
	 * Validate an incoming AJAX request.
	 *
	 * Checks the HTTP method, the nonce and the capability, so individual
	 * handlers only deal with their own payload.
	 *
	 * @return bool True when the request may proceed.
	 * @since 1.0.0
	 */
	public static function validate_ajax_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		if ( 'POST' !== $method ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified on the next line.
		$nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! \wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		return self::current_user_can_access();
	}

	/**
	 * Throttle an action per user, protecting the shared CloudWatch API quota.
	 *
	 * CloudWatch Logs allows five FilterLogEvents calls per second per account
	 * and region, shared across every site and person using it, so the admin
	 * screens must not be able to burst beyond a slow human pace.
	 *
	 * @param string $action   Identifier of the throttled action.
	 * @param int    $interval Minimum seconds between two calls.
	 * @return bool True when the caller may proceed, false when it must wait.
	 * @since 1.0.0
	 */
	public static function throttle( string $action, int $interval = 1 ): bool {
		$key = \sprintf( 'silver_assist_cloudwatch_throttle_%s_%d', $action, \get_current_user_id() );

		if ( false !== \get_transient( $key ) ) {
			return false;
		}

		\set_transient( $key, \time(), \max( 1, $interval ) );

		return true;
	}

	/**
	 * Throttle an action across the whole site.
	 *
	 * The per-user throttle stops one person from hammering the API; this one
	 * stops several administrators tailing at once from multiplying the
	 * request rate against a quota that is shared by the entire AWS account.
	 *
	 * @param string $action   Identifier of the throttled action.
	 * @param int    $interval Minimum seconds between two calls.
	 * @return bool True when the caller may proceed, false when it must wait.
	 * @since 1.0.0
	 */
	public static function throttle_site( string $action, int $interval = 1 ): bool {
		$key = \sprintf( 'silver_assist_cloudwatch_lock_%s', $action );

		if ( false !== \get_transient( $key ) ) {
			return false;
		}

		\set_transient( $key, \time(), \max( 1, $interval ) );

		return true;
	}

	/**
	 * Sanitize a search term without altering what it matches.
	 *
	 * WordPress's sanitize_text_field() strips anything that looks like a tag, which would
	 * silently change a search for "<html>" or a regex containing angle
	 * brackets into a different search. The term never reaches the page as
	 * markup — it is sent to the CloudWatch API and escaped on output — so
	 * stripping control characters and capping the length is the correct
	 * treatment here.
	 *
	 * @param string $term Raw search term.
	 * @return string The sanitized term.
	 * @since 1.0.0
	 */
	public static function sanitize_search_term( string $term ): string {
		$cleaned = \preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $term );

		if ( ! \is_string( $cleaned ) ) {
			// A malformed UTF-8 sequence makes preg_replace() return null.
			return '';
		}

		return \trim( \mb_substr( $cleaned, 0, 2048 ) );
	}

	/**
	 * Format a millisecond timestamp in the site's timezone.
	 *
	 * @param int $timestamp_ms Milliseconds since the Unix epoch.
	 * @return string Formatted date and time, or an empty string when unset.
	 * @since 1.0.0
	 */
	public static function format_timestamp( int $timestamp_ms ): string {
		if ( $timestamp_ms <= 0 ) {
			return '';
		}

		return (string) \wp_date(
			\sprintf( '%s %s', \get_option( 'date_format' ), \get_option( 'time_format' ) ),
			(int) \floor( $timestamp_ms / 1000 )
		);
	}

	/**
	 * Format a byte count for display.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string Human readable size.
	 * @since 1.0.0
	 */
	public static function format_bytes( int $bytes ): string {
		$formatted = (string) \size_format( \max( 0, $bytes ), 1 );

		return '' !== $formatted ? $formatted : '0 B';
	}
}
