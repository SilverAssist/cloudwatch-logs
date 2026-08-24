# Changelog

All notable changes to Silver Assist CloudWatch Logs will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-08-24

### Added

- **Check Updates** button on the plugin's Settings Hub card, matching the other
  Silver Assist plugins. It appears only when the GitHub update channel actually
  initialised, so a card without it is a signal in itself.
- **Settings** and **View Logs** links on the plugin's row in the plugins list,
  next to Deactivate. Each opens the tab its label promises.

## [1.0.1] - 2026-08-24

### Fixed

- **The plugin could not reach AWS on a site that already loads the AWS SDK**,
  failing with `Call to undefined method GuzzleHttp\Psr7\Utils::redactUriForMessage()`
  regardless of the credential mode. Composer registers its autoloader ahead of
  everything already loaded, so this plugin's bundled Guzzle won over the copy
  the site had, while PSR-7 — already resolved earlier, for instance by a
  `wp-config.php` Secrets Manager call — stayed on the site's older version. The
  result was a mixed dependency graph: a newer Guzzle calling PSR-7 methods that
  the loaded copy did not have. The plugin now appends its autoloader instead of
  prepending it, so the host's copy of any shared library always wins and each
  site keeps a consistent set of versions. The plugin's own classes are
  unaffected, since nothing else supplies them.

## [1.0.0] - 2026-08-24

First release.

### Added

- **Log viewer** under **Silver Assist → CloudWatch Logs**: read a CloudWatch
  Logs group from the WordPress admin, over a time range (six presets or a
  custom one), with an optional log stream prefix and pagination.
- **Three search modes** — literal text, a CloudWatch regular expression, or a
  raw filter pattern. Searches are validated before the API call (length, the
  two-regex limit, the reserved percent delimiter), so a mistake is explained
  locally instead of returning an opaque AWS error.
- **Tail mode** that polls for events newer than the last one on screen,
  deduplicating by event id, scrolling only when the reader is already at the
  bottom, pausing while the tab is in the background, backing off when AWS
  reports throttling, and stopping after five consecutive failures.
- **Three credential modes**: the host IAM role with nothing stored, an access
  key and secret sealed with AES-256-GCM, or an AWS Secrets Manager secret.
  `wp-config.php` constants override any of them and never touch the database.
- **Connection validation** reporting the log group, region, retention, stored
  size, latest stream, last event, resolved IAM identity and credential mode,
  with AWS errors translated into messages that say what to do.
- **Log group autocompletion** from the AWS account.
- **CSV and JSON export** of the loaded events.
- Readable output: JSON messages are pretty-printed, long ones collapse behind
  their first line, and matches are highlighted for literal searches. Messages
  are always rendered as text, never as markup.
- A filterable capability (`silver_assist_cloudwatch_capability`) and two
  throttles on the AJAX endpoints, one per user and one site-wide, so the admin
  screens cannot burst past the CloudWatch API quota that is shared by the whole
  AWS account.
- Automatic updates from this repository's GitHub releases.
- Spanish-ready: translation template at
  `languages/silver-assist-cloudwatch-logs.pot`.
