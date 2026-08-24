<?php
/**
 * Silver Assist CloudWatch Logs - Credential resolution
 *
 * @package SilverAssist\CloudWatchLogs\Service
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Service;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use RuntimeException;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;

\defined( 'ABSPATH' ) || exit;

/**
 * Turns the configured authentication mode into AWS SDK credentials.
 *
 * Three modes are supported:
 *
 * - `auto`   — return null and let the SDK's default provider chain apply, which
 *              is how the ECS task role is picked up with nothing stored locally.
 * - `keys`   — an access key id and secret access key entered in the settings.
 * - `secret` — an AWS Secrets Manager secret holding those keys as JSON, fetched
 *              with the default chain, mirroring how wp-config.php already reads
 *              the database credentials on these sites.
 *
 * Credentials fetched from Secrets Manager are memoised for the current request
 * only. They are deliberately never written to a transient: that would put a
 * live AWS secret key into wp_options in plaintext, which is precisely what the
 * `secret` mode exists to avoid.
 *
 * @since 1.0.0
 */
class CredentialsResolver {

	/**
	 * JSON keys accepted for the access key id, in order of preference.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	private const ACCESS_KEY_FIELDS = [ 'accessKeyId', 'access_key_id', 'aws_access_key_id', 'AWS_ACCESS_KEY_ID' ];

	/**
	 * JSON keys accepted for the secret access key, in order of preference.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	private const SECRET_KEY_FIELDS = [ 'secretAccessKey', 'secret_access_key', 'aws_secret_access_key', 'AWS_SECRET_ACCESS_KEY' ];

	/**
	 * JSON keys accepted for an optional session token.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	private const SESSION_TOKEN_FIELDS = [ 'sessionToken', 'session_token', 'aws_session_token', 'AWS_SESSION_TOKEN' ];

	/**
	 * Credentials already fetched from Secrets Manager during this request.
	 *
	 * @var array<string, Credentials>
	 * @since 1.0.0
	 */
	private static array $memo = [];

	/**
	 * Settings source.
	 *
	 * @var SettingsRepository
	 * @since 1.0.0
	 */
	private SettingsRepository $settings;

	/**
	 * Build the resolver.
	 *
	 * @param SettingsRepository|null $settings Settings source, defaulting to the shared repository.
	 * @since 1.0.0
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? SettingsRepository::instance();
	}

	/**
	 * Resolve credentials for the configured mode.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form, keyed as the settings are.
	 * @return Credentials|null Explicit credentials, or null to use the SDK's default provider chain.
	 * @throws RuntimeException When the selected mode is configured but unusable.
	 * @since 1.0.0
	 */
	public function resolve( array $overrides = [] ): ?Credentials {
		$mode = $overrides['auth_mode'] ?? $this->settings->get_auth_mode();

		return match ( $mode ) {
			'keys'   => $this->resolve_from_keys( $overrides ),
			'secret' => $this->resolve_from_secrets_manager( $overrides ),
			default  => null,
		};
	}

	/**
	 * Build credentials from the keys stored in the settings.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return Credentials The resolved credentials.
	 * @throws RuntimeException When either half of the key pair is missing.
	 * @since 1.0.0
	 */
	private function resolve_from_keys( array $overrides ): Credentials {
		$key    = $overrides['access_key_id'] ?? $this->settings->get_string( 'access_key_id' );
		$secret = $overrides['secret_access_key'] ?? '';

		if ( '' === $secret ) {
			if ( $this->settings->has_unreadable_secret_access_key() ) {
				throw new RuntimeException(
					\esc_html__(
						'The stored secret access key can no longer be decrypted. This happens when the site security salts change; re-enter the key to fix it.',
						'silver-assist-cloudwatch-logs'
					)
				);
			}

			$secret = $this->settings->get_secret_access_key();
		}

		if ( '' === $key || '' === $secret ) {
			throw new RuntimeException(
				\esc_html__(
					'Enter both an access key ID and a secret access key, or switch to another authentication mode.',
					'silver-assist-cloudwatch-logs'
				)
			);
		}

		return new Credentials( $key, $secret );
	}

	/**
	 * Fetch credentials from an AWS Secrets Manager secret.
	 *
	 * @param array<string, string> $overrides Values from an unsaved settings form.
	 * @return Credentials The resolved credentials.
	 * @throws RuntimeException When the secret is missing, unreadable or malformed.
	 * @since 1.0.0
	 */
	private function resolve_from_secrets_manager( array $overrides ): Credentials {
		$secret_id = $overrides['secret_id'] ?? $this->settings->get_string( 'secret_id' );
		$region    = $overrides['region'] ?? $this->settings->get_string( 'region' );

		if ( '' === $secret_id ) {
			throw new RuntimeException(
				\esc_html__( 'Enter the name or ARN of the Secrets Manager secret holding the AWS credentials.', 'silver-assist-cloudwatch-logs' )
			);
		}

		$memo_key = $region . '|' . $secret_id;

		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		try {
			$client = new SecretsManagerClient(
				[
					'version' => '2017-10-17',
					'region'  => $region,
				]
			);

			$result = $client->getSecretValue(
				[
					'SecretId'     => $secret_id,
					'VersionStage' => 'AWSCURRENT',
				]
			);
		} catch ( AwsException $e ) {
			throw new RuntimeException(
				\esc_html(
					\sprintf(
						/* translators: %s: the error message returned by AWS. */
						\__( 'Could not read the Secrets Manager secret: %s', 'silver-assist-cloudwatch-logs' ),
						$e->getAwsErrorMessage() ?? $e->getMessage()
					)
				),
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained as the previous exception, never rendered.
				$e
			);
		}

		$payload = $this->extract_secret_string( $result['SecretString'] ?? null, $result['SecretBinary'] ?? null );
		$decoded = \json_decode( $payload, true );

		if ( ! \is_array( $decoded ) ) {
			throw new RuntimeException(
				\esc_html__( 'The Secrets Manager secret does not contain JSON.', 'silver-assist-cloudwatch-logs' )
			);
		}

		$key    = $this->pick( $decoded, self::ACCESS_KEY_FIELDS );
		$secret = $this->pick( $decoded, self::SECRET_KEY_FIELDS );
		$token  = $this->pick( $decoded, self::SESSION_TOKEN_FIELDS );

		if ( '' === $key || '' === $secret ) {
			throw new RuntimeException(
				\esc_html__(
					'The Secrets Manager secret must contain "accessKeyId" and "secretAccessKey" fields.',
					'silver-assist-cloudwatch-logs'
				)
			);
		}

		$credentials             = new Credentials( $key, $secret, '' !== $token ? $token : null );
		self::$memo[ $memo_key ] = $credentials;

		return $credentials;
	}

	/**
	 * Normalise the two shapes a secret value can arrive in.
	 *
	 * @param mixed $secret_string The SecretString field, when present.
	 * @param mixed $secret_binary The SecretBinary field, when present.
	 * @return string The decoded secret payload.
	 * @throws RuntimeException When neither field holds a usable value.
	 * @since 1.0.0
	 */
	private function extract_secret_string( mixed $secret_string, mixed $secret_binary ): string {
		if ( \is_string( $secret_string ) && '' !== $secret_string ) {
			return $secret_string;
		}

		if ( \is_string( $secret_binary ) && '' !== $secret_binary ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a binary secret payload returned by AWS, not obfuscated code.
			$decoded = \base64_decode( $secret_binary, true );

			if ( \is_string( $decoded ) ) {
				return $decoded;
			}
		}

		// SecretBinary arrives as a stream when the SDK is given a binary secret.
		if ( \is_object( $secret_binary ) && \method_exists( $secret_binary, '__toString' ) ) {
			return (string) $secret_binary;
		}

		throw new RuntimeException(
			\esc_html__( 'The Secrets Manager secret is empty.', 'silver-assist-cloudwatch-logs' )
		);
	}

	/**
	 * Read the first present field from a decoded secret.
	 *
	 * @param array<string, mixed> $data   Decoded secret.
	 * @param array<int, string>   $fields Accepted field names, in order of preference.
	 * @return string The value, or an empty string when no field matched.
	 * @since 1.0.0
	 */
	private function pick( array $data, array $fields ): string {
		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) && \is_string( $data[ $field ] ) && '' !== $data[ $field ] ) {
				return $data[ $field ];
			}
		}

		return '';
	}
}
