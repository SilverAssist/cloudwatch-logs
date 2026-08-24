<?php
/**
 * Silver Assist CloudWatch Logs - Settings repository
 *
 * @package SilverAssist\CloudWatchLogs\Repository
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Repository;

use SilverAssist\CloudWatchLogs\Core\Activator;
use SilverAssist\CloudWatchLogs\Utils\Encryption;

\defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single option holding every plugin setting.
 *
 * Values defined as constants in wp-config.php always win over what is stored
 * in the database and are never written back to it, so a site can keep its AWS
 * configuration out of the options table entirely.
 *
 * @since 1.0.0
 */
class SettingsRepository {

	/**
	 * Supported credential resolution modes.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	public const AUTH_MODES = [ 'auto', 'keys', 'secret' ];

	/**
	 * Shortest tail polling interval accepted, in seconds.
	 *
	 * CloudWatch Logs allows five FilterLogEvents calls per second per account
	 * and region, shared with every other consumer, so the floor is deliberate.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public const MIN_POLL_INTERVAL = 5;

	/**
	 * Longest tail polling interval accepted, in seconds.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public const MAX_POLL_INTERVAL = 300;

	/**
	 * Largest page size accepted for a single search.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public const MAX_PAGE_SIZE = 1000;

	/**
	 * Constant overrides, mapped to the setting they replace.
	 *
	 * @var array<string, string>
	 * @since 1.0.0
	 */
	private const CONSTANT_MAP = [
		'SILVER_ASSIST_CLOUDWATCH_AUTH_MODE'  => 'auth_mode',
		'SILVER_ASSIST_CLOUDWATCH_REGION'     => 'region',
		'SILVER_ASSIST_CLOUDWATCH_LOG_GROUP'  => 'log_group',
		'SILVER_ASSIST_CLOUDWATCH_ACCESS_KEY' => 'access_key_id',
		'SILVER_ASSIST_CLOUDWATCH_SECRET_KEY' => 'secret_access_key',
		'SILVER_ASSIST_CLOUDWATCH_SECRET_ID'  => 'secret_id',
	];

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 1.0.0
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self The repository.
	 * @since 1.0.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Every setting, with defaults and constant overrides applied.
	 *
	 * The secret access key is returned exactly as stored (still sealed); use
	 * get_secret_access_key() to obtain the plaintext.
	 *
	 * @return array<string, mixed> The effective settings.
	 * @since 1.0.0
	 */
	public function all(): array {
		$stored = \get_option( Activator::OPTION_NAME );

		if ( ! \is_array( $stored ) ) {
			$stored = [];
		}

		$settings = \array_merge( Activator::DEFAULT_SETTINGS, $stored );

		foreach ( self::CONSTANT_MAP as $constant => $key ) {
			if ( \defined( $constant ) ) {
				$value = \constant( $constant );

				if ( \is_string( $value ) && '' !== $value ) {
					$settings[ $key ] = $value;
				}
			}
		}

		return $settings;
	}

	/**
	 * Read a single setting as a string.
	 *
	 * @param string $key Setting name.
	 * @return string The value, or an empty string when unset.
	 * @since 1.0.0
	 */
	public function get_string( string $key ): string {
		$settings = $this->all();

		return isset( $settings[ $key ] ) && \is_scalar( $settings[ $key ] )
			? (string) $settings[ $key ]
			: '';
	}

	/**
	 * Read a single setting as an integer.
	 *
	 * @param string $key Setting name.
	 * @return int The value, or 0 when unset.
	 * @since 1.0.0
	 */
	public function get_int( string $key ): int {
		$settings = $this->all();

		return isset( $settings[ $key ] ) && \is_scalar( $settings[ $key ] )
			? (int) $settings[ $key ]
			: 0;
	}

	/**
	 * The configured credential resolution mode.
	 *
	 * @return string One of auto, keys or secret.
	 * @since 1.0.0
	 */
	public function get_auth_mode(): string {
		$mode = $this->get_string( 'auth_mode' );

		return \in_array( $mode, self::AUTH_MODES, true ) ? $mode : 'auto';
	}

	/**
	 * The AWS secret access key in plaintext.
	 *
	 * @return string The key, or an empty string when unset or unreadable.
	 * @since 1.0.0
	 */
	public function get_secret_access_key(): string {
		$stored = $this->get_string( 'secret_access_key' );

		if ( '' === $stored ) {
			return '';
		}

		if ( ! Encryption::is_encrypted( $stored ) ) {
			// A value supplied through a wp-config.php constant is plaintext by design.
			return $stored;
		}

		return Encryption::decrypt( $stored ) ?? '';
	}

	/**
	 * Whether a secret access key is stored but can no longer be decrypted.
	 *
	 * This happens when the site's salts are rotated after the key was saved.
	 *
	 * @return bool True when the stored key is unreadable.
	 * @since 1.0.0
	 */
	public function has_unreadable_secret_access_key(): bool {
		$stored = $this->get_string( 'secret_access_key' );

		return '' !== $stored && Encryption::is_encrypted( $stored ) && null === Encryption::decrypt( $stored );
	}

	/**
	 * Whether the settings hold enough information to talk to CloudWatch.
	 *
	 * @return bool True when a log group and the selected mode's inputs are present.
	 * @since 1.0.0
	 */
	public function is_configured(): bool {
		if ( '' === $this->get_string( 'log_group' ) || '' === $this->get_string( 'region' ) ) {
			return false;
		}

		return match ( $this->get_auth_mode() ) {
			'keys'   => '' !== $this->get_string( 'access_key_id' ) && '' !== $this->get_secret_access_key(),
			'secret' => '' !== $this->get_string( 'secret_id' ),
			default  => true,
		};
	}

	/**
	 * Whether a setting is locked by a wp-config.php constant.
	 *
	 * @param string $key Setting name.
	 * @return bool True when a constant supplies the value.
	 * @since 1.0.0
	 */
	public function is_constant_defined( string $key ): bool {
		foreach ( self::CONSTANT_MAP as $constant => $mapped_key ) {
			if ( $mapped_key === $key && \defined( $constant ) && '' !== (string) \constant( $constant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize and persist submitted settings.
	 *
	 * An empty secret access key means "keep the stored one", so that the
	 * masked field in the form never erases a working configuration.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @return array<string, mixed> The settings as persisted.
	 * @since 1.0.0
	 */
	public function save( array $input ): array {
		$auth_mode = isset( $input['auth_mode'] ) ? (string) $input['auth_mode'] : '';
		$settings  = [
			'auth_mode'     => \in_array( $auth_mode, self::AUTH_MODES, true ) ? $auth_mode : 'auto',
			'region'        => isset( $input['region'] ) ? \sanitize_text_field( (string) $input['region'] ) : 'us-east-1',
			'log_group'     => isset( $input['log_group'] ) ? \sanitize_text_field( (string) $input['log_group'] ) : '',
			'access_key_id' => isset( $input['access_key_id'] ) ? \sanitize_text_field( (string) $input['access_key_id'] ) : '',
			'secret_id'     => isset( $input['secret_id'] ) ? \sanitize_text_field( (string) $input['secret_id'] ) : '',
			'poll_interval' => $this->clamp(
				isset( $input['poll_interval'] ) ? (int) $input['poll_interval'] : 0,
				self::MIN_POLL_INTERVAL,
				self::MAX_POLL_INTERVAL,
				(int) Activator::DEFAULT_SETTINGS['poll_interval']
			),
			'default_range' => isset( $input['default_range'] ) ? \sanitize_text_field( (string) $input['default_range'] ) : '1h',
			'page_size'     => $this->clamp(
				isset( $input['page_size'] ) ? (int) $input['page_size'] : 0,
				1,
				self::MAX_PAGE_SIZE,
				(int) Activator::DEFAULT_SETTINGS['page_size']
			),
		];

		$submitted_secret = isset( $input['secret_access_key'] ) ? \trim( (string) $input['secret_access_key'] ) : '';

		if ( '' === $submitted_secret ) {
			// Preserve whatever is already stored, still sealed.
			$stored                        = \get_option( Activator::OPTION_NAME );
			$settings['secret_access_key'] = \is_array( $stored ) && isset( $stored['secret_access_key'] )
				? (string) $stored['secret_access_key']
				: '';
		} else {
			$settings['secret_access_key'] = Encryption::encrypt( $submitted_secret ) ?? '';
		}

		// Discard credentials that belong to a mode the site is not using.
		if ( 'keys' !== $settings['auth_mode'] ) {
			$settings['access_key_id']     = '';
			$settings['secret_access_key'] = '';
		}

		if ( 'secret' !== $settings['auth_mode'] ) {
			$settings['secret_id'] = '';
		}

		\update_option( Activator::OPTION_NAME, $settings );
		\delete_transient( 'silver_assist_cloudwatch_connection_status' );

		return $settings;
	}

	/**
	 * Constrain an integer to a range, falling back when out of bounds.
	 *
	 * @param int $value    Submitted value.
	 * @param int $min      Lowest accepted value.
	 * @param int $max      Highest accepted value.
	 * @param int $fallback Value used when the submission is zero or negative.
	 * @return int The constrained value.
	 * @since 1.0.0
	 */
	private function clamp( int $value, int $min, int $max, int $fallback ): int {
		if ( $value <= 0 ) {
			return $fallback;
		}

		return \max( $min, \min( $max, $value ) );
	}
}
