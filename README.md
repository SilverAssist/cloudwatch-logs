# Silver Assist CloudWatch Logs

View, search and follow the events of an Amazon CloudWatch Logs log group from
the WordPress admin, without opening the AWS console.

> **Status:** feature complete and pending its first release. See
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the reasoning behind
> how it is built.

## Requirements

- PHP 8.2+
- WordPress 6.5+
- An AWS principal allowed to read the target log group

## Available now

- A settings screen at **Silver Assist → CloudWatch Logs**
- Three credential modes: the IAM role of the host (nothing stored),
  a manual access key and secret (encrypted at rest), or an AWS Secrets
  Manager secret
- Connection validation with a status table showing log group, region,
  retention, stored size, latest stream, last event, the resolved IAM
  identity and the credential mode in use
- Log group autocompletion
- A log viewer with time ranges, plain text / **regular expression** /
  raw filter pattern search, an optional stream prefix, and pagination
- Tail mode that polls for new events, backing off when AWS throttles and
  pausing while the tab is in the background
- CSV and JSON export of the loaded events

## Configuring credentials in wp-config.php

Any of these constants overrides the corresponding stored setting and keeps
it out of the database entirely:

```php
define( 'SILVER_ASSIST_CLOUDWATCH_AUTH_MODE', 'auto' );  // auto | keys | secret
define( 'SILVER_ASSIST_CLOUDWATCH_REGION', 'us-east-1' );
define( 'SILVER_ASSIST_CLOUDWATCH_LOG_GROUP', '/ecs/my-site' );
define( 'SILVER_ASSIST_CLOUDWATCH_ACCESS_KEY', '...' );  // keys mode
define( 'SILVER_ASSIST_CLOUDWATCH_SECRET_KEY', '...' );  // keys mode
define( 'SILVER_ASSIST_CLOUDWATCH_SECRET_ID', 'prod/site/cloudwatch' ); // secret mode
```

On ECS, EC2 or anywhere else with an attached IAM role, leave the mode as
`auto` and configure nothing but the log group: the AWS SDK resolves the
role automatically.

## Filters

| Filter | Purpose |
|---|---|
| `silver_assist_cloudwatch_capability` | Capability required to use the plugin. Default `manage_options`. |
| `silver_assist_cloudwatch_client_config` | Final AWS SDK client configuration, before a client is built. |

## Installation

Download the release ZIP from the
[Releases](https://github.com/SilverAssist/cloudwatch-logs/releases) page and
install it from **Plugins → Add New → Upload Plugin**. Updates are delivered
through GitHub via `silverassist/wp-github-updater`.

## AWS permissions

The principal used by the plugin needs:

```json
{
  "Effect": "Allow",
  "Action": [
    "logs:FilterLogEvents",
    "logs:DescribeLogGroups",
    "logs:DescribeLogStreams"
  ],
  "Resource": "arn:aws:logs:REGION:ACCOUNT:log-group:YOUR-LOG-GROUP:*"
}
```

Those are the only three CloudWatch calls the plugin makes; it never calls
`GetLogEvents`, so there is no reason to grant it.

Add `secretsmanager:GetSecretValue` on the secret's ARN if you configure the
plugin with an AWS Secrets Manager secret.

The status table also calls `sts:GetCallerIdentity` to report which IAM
identity was resolved. That call needs no policy — AWS always allows it — and
the plugin treats a failure as "identity not reported" rather than a failed
connection.

## Development

```bash
composer install
composer phpcs      # WordPress coding standards
composer phpstan    # static analysis
bash scripts/install-wp-tests.sh wordpress_test root '' localhost latest
composer phpunit    # test suite
```

`composer test` runs the full quality suite.

## License

This plugin is licensed under the **PolyForm Noncommercial License 1.0.0**.

### Key Points

- ✅ **Free for noncommercial use**: You can use, modify, and distribute this plugin for any noncommercial purpose
- ✅ **Open source**: Source code is publicly available
- ❌ **No commercial use**: Commercial use requires a separate commercial license
- ✅ **Modifications allowed**: You can create and distribute modified versions (noncommercial only)
- ✅ **Attribution**: Please maintain attribution to Silver Assist

### Full License Text

```text
PolyForm Noncommercial License 1.0.0
Copyright (C) 2026 Silver Assist

The licensor grants you a copyright license for the licensed material to do
everything you might do with the licensed material that would otherwise infringe
the licensor's copyright in it, for any noncommercial purpose, for the duration
of the license, and in all territories.

Commercial purposes means use of the licensed material for a purpose intended
for or directed toward commercial advantage or monetary compensation.
```

**Full license**: See [LICENSE](LICENSE) file or visit https://polyformproject.org/licenses/noncommercial/1.0.0

### Commercial License

If you need to use this plugin for commercial purposes, please contact Silver Assist for licensing options.

## Credits

Developed by [Silver Assist](http://silverassist.com/)

---

**Made with ❤️ by [Silver Assist](https://silverassist.com)**
