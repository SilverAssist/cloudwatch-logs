# Architecture — Silver Assist CloudWatch Logs

Why this plugin is built the way it is: the research behind it, the
constraints the CloudWatch Logs API imposes, and the decisions those led
to. What it *does* is in the [README](../README.md); how to work on it is
in [`.github/copilot-instructions.md`](../.github/copilot-instructions.md).

This is a record of reasoning, not a task list. It is worth keeping
because most of what follows is expensive to re-derive and impossible to
recover by reading the code: the code shows a five-second polling floor,
not the account-wide quota that forces it.

---

## 1. Naming

The repository name drops the `silver-assist` prefix because it already
lives under the `SilverAssist` organization, matching `wp-plugin-kernel`,
`wp-settings-hub` and `coding-standards`. Everything that lands in a
*shared* WordPress namespace — constants, options, AJAX actions,
transients, text domain — keeps the `SILVER_ASSIST_` / `silver-assist-`
prefix, both to satisfy `WordPress.NamingConventions.PrefixAllGlobals`
and to stay consistent with the other plugins in the hub.

Because the folder (`cloudwatch-logs`) and the text domain
(`silver-assist-cloudwatch-logs`) deliberately differ, the plugin must
call `load_plugin_textdomain()` explicitly against its own `/languages`
directory — WordPress's implicit lookup keys off the folder name and
would not find the translations on its own.

---

## 2. Background research

### 2.1 Why this plugin

AWS's own official CloudWatch plugin for WordPress is discontinued, and
no maintained alternative covers reading arbitrary log groups from the
admin.

### 2.2 How the target sites are already wired

All 13 WordPress sites in this workspace already require
`aws/aws-sdk-php` at the project level, and `cicd-wp-config.php` already
instantiates `Aws\SecretsManager\SecretsManagerClient` **without explicit
credentials** — meaning the ECS task role already supplies them through
the SDK's default provider chain. Region is `us-east-1`; the DB secret
name arrives through the `SECRET_DB` environment variable.

Consequence: in production the plugin should need no keys at all, only
IAM permissions. Static keys are a local/dev convenience, not the norm.

Plugins are not tracked inside the site repositories (`.gitignore`
excludes `wp-content/plugins`), so distribution is a release ZIP plus
`silverassist/wp-github-updater`, same as the other plugins.

### 2.3 What the CloudWatch Logs API can actually do

Verified against the SDK installed in the sites (`aws/aws-sdk-php`
v3.383.2):

- **`FilterLogEvents`** — time range + filter pattern + optional log
  stream prefix, paginated with `nextToken`. Supports **regular
  expressions** in filter patterns (`%pattern%`) since 2023, capped at
  2 regex per pattern. Quota: **5 transactions per second per account
  per region** — this is the binding constraint on any polling design.
- **Logs Insights** (`StartQuery` / `GetQueryResults`) — real regex,
  aggregations, asynchronous, but **billed per GB scanned**.
- **Live Tail** — `CloudWatchLogsClient::startLiveTailCheckingForResults()`
  does exist in the PHP SDK and returns a `Generator`, but it is a
  persistent HTTP/2 stream: it pins a PHP-FPM worker for the life of the
  session, costs $0.01/min beyond the 1,800 free minutes per month, and
  tends to break behind proxies/CloudFront. Rejected for v1.

### 2.4 Decisions taken

| Decision | Choice | Rationale |
|---|---|---|
| Credentials | IAM role (default chain) **+** manual key/secret **+** Secrets Manager | Role covers ECS with zero config; keys cover local dev; Secrets Manager mirrors the existing `cicd-wp-config.php` pattern |
| Search engine | `FilterLogEvents`, with Logs Insights left for later | No per-query cost, regex already supported, simplest correct thing |
| Tail | Polling `FilterLogEvents` | No extra cost, no pinned worker, survives proxies |
| SDK packaging | Reuse the site's SDK if already loaded, otherwise load a trimmed bundled copy | The 13 ECS sites already load it; the bundled copy keeps the plugin usable on plain installs |

---

## 3. Architecture

Follows `SILVERASSIST_STANDARDS.md v2.0.0`: PSR-4, `AbstractPlugin` +
`LoadableInterface` from `silverassist/wp-plugin-kernel`, settings page
registered through `silverassist/wp-settings-hub`, updates through
`silverassist/wp-github-updater`.

```text
includes/
├── Core/
│   ├── Plugin.php              extends AbstractPlugin, lists components
│   └── Activator.php           default options, cleanup on deactivate
├── Service/
│   ├── SdkLoader.php           (10) reuse-or-bundle AWS SDK resolution
│   ├── CredentialsResolver.php      auto | keys | secret → SDK credentials
│   ├── ClientFactory.php            builds CloudWatchLogsClient
│   ├── LogsService.php              FilterLogEvents wrapper
│   └── ConnectionTester.php         validation + status snapshot
├── Repository/
│   └── SettingsRepository.php       typed access to the single option
├── Admin/
│   ├── AdminPage.php           (30) hub registration + tab dispatch
│   ├── AssetManager.php        (40) CSS/JS, scoped to plugin screens
│   └── Ajax/
│       ├── ConnectionAjaxHandler.php
│       └── LogsAjaxHandler.php
├── View/Admin/
│   ├── SettingsView.php        settings tab
│   ├── StatusTableView.php     connection card
│   └── LogViewerView.php       logs tab
├── Model/
│   ├── LogQuery.php                 validated search parameters
│   ├── LogEvent.php
│   ├── ConnectionStatus.php
│   └── FilterPatternBuilder.php     text | regex | raw → filter pattern
└── Utils/
    ├── Encryption.php               AES-256-GCM over wp_salt()
    └── Helpers.php
```

Priority bands are the standard ones: 10 core, 20 services, 30 admin,
40 utils/assets.

The Settings Hub gives a plugin one page, so the viewer and the settings
are two tabs of a single `AdminPage` rather than two menu entries. The
viewer is the default tab, because reading logs is the point of the
plugin — except on a site that is not configured yet, where the viewer
would have nothing to show and the settings tab opens instead.

### 3.1 SDK loading

`SdkLoader` checks `class_exists(\Aws\CloudWatchLogs\CloudWatchLogsClient::class)`
first and reuses whatever the site already autoloaded; only if absent
does it require the plugin's own `vendor/autoload.php`. The bundled copy
is trimmed at install time:

```json
"extra": { "aws/aws-sdk-php": ["CloudWatchLogs", "SecretsManager"] },
"scripts": { "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices" }
```

`Sts`, `Sso`, `SsoOidc`, `Kms`, `S3` and `Signin` are retained by the
trimming script itself — they are required by the credential provider
chain.

`scripts/build-release.sh` therefore diverges from the version inherited
from the other plugins, which cherry-picks `vendor/silverassist/*` and
`vendor/composer/installers`: the AWS SDK drags in Guzzle, PSR-7,
JMESPath and `aws-crt-php` at runtime, so this plugin copies the whole
`--no-dev` vendor tree and prunes tests, docs and markdown from it. The
build then asserts that the CloudWatch Logs and Secrets Manager clients
survived and that the trimming actually ran (no `src/Ec2` left behind).

---

## 4. Configuration

A single option, `silver_assist_cloudwatch_settings`:

| Key | Purpose |
|---|---|
| `auth_mode` | `auto` (IAM role / default chain), `keys`, or `secret` |
| `region` | defaults to `us-east-1` |
| `log_group` | autocompleted from `DescribeLogGroups` once a connection works |
| `access_key_id` | `keys` mode only |
| `secret_access_key` | `keys` mode only, encrypted at rest |
| `secret_id` | `secret` mode: Secrets Manager name/ARN, resolved with the default chain |
| `poll_interval` | tail refresh, minimum 5s, default 10s |
| `default_range` | default time window for the viewer |
| `page_size` | events per page, default 200 |

Rules:

- The settings screen shows the three basic fields, plus a toggle that
  swaps them for the single Secrets Manager field, plus `auto` offered
  first since it needs no input at all on ECS.
- `wp-config.php` constants (`SILVER_ASSIST_CLOUDWATCH_AUTH_MODE`,
  `..._REGION`, `..._LOG_GROUP`, `..._ACCESS_KEY`, `..._SECRET_KEY`,
  `..._SECRET_ID`) take precedence, never touch the database, and render
  their fields disabled with a note pointing at `wp-config.php`.
- Credentials read from Secrets Manager are memoised **for the current
  request only**. Caching them in a transient would write a live AWS
  secret key into `wp_options` in plaintext, defeating the point of the
  mode; the extra API call per admin request costs a fraction of a cent
  per ten thousand calls.
- The secret access key is encrypted with AES-256-GCM using a key
  derived from `wp_salt( 'secure_auth' )`, is never returned to the
  browser, renders masked, and an empty submission means "keep current".

### 4.1 Connection validation

An AJAX action (nonce + capability checked) runs `DescribeLogGroups`,
`DescribeLogStreams` (limit 1) and `sts:GetCallerIdentity`, then renders
the status card using the same visual pattern as
`silver-assist-post-revalidate` (`status-card` / `card-header` /
`status-indicator active|inactive`):

| Log group | Region | Retention | Stored size | Latest stream | Last event | IAM identity | Auth mode |
|---|---|---|---|---|---|---|---|

Counting every stream would mean paginating `DescribeLogStreams` over
groups that routinely hold thousands, so the table reports the most
recently active stream instead — the number that actually answers "is
anything still writing to this group?".

The snapshot is cached in a transient for 5 minutes so the table renders
on page load without calling AWS every time.

---

## 5. Log viewer

- **Time range**: presets (5m / 15m / 1h / 3h / 12h / 24h) plus a custom
  `datetime-local` range, rendered in the site's timezone.
- **Search**, three modes over `FilterLogEvents`:
  - *Text* — quoted term.
  - *Regex* — wrapped as `%pattern%`; length and the 2-regex-per-pattern
    limit are validated before the call.
  - *Advanced* — raw filter pattern, with the AWS error surfaced verbatim
    when it is rejected.
- **Stream filter**: optional log stream name prefix.
- **Results**: local timestamp, stream name, message; a message that is
  entirely JSON is pretty-printed, and anything over twelve lines is
  collapsed behind its first line. Matches are highlighted **for the
  literal text mode only** — a CloudWatch filter pattern and its regex
  dialect are not JavaScript regular expressions, so reimplementing them
  in the browser would mark the wrong spans. Pagination uses the API's
  `nextToken`; CSV/JSON export of what is loaded comes with the tail.
- **Tail**: auto-refresh toggle. The browser sends the timestamp of the
  newest event it holds and the server rebuilds the query as
  `startTime = that + 1`, keeping the same filter; events are deduplicated
  on `eventId`. New rows scroll into view only when the reader is already
  within 120px of the bottom — further up means they are reading history
  and must not be yanked. Tailing pauses while the tab is in the
  background, since a tab nobody is looking at cannot usefully follow a
  log but would still spend the shared quota.
  **Exponential backoff when AWS reports throttling**, capped at the
  maximum interval, and a stop after five consecutive failures. Two
  transient locks guard the quota: one per user, one site-wide so two
  simultaneous administrators cannot double the request rate against the
  5 TPS account limit.
- **Export**: the loaded events are handed to the browser as CSV or JSON,
  named after the log group.

---

## 6. Security

- `manage_options`, filterable through
  `silver_assist_cloudwatch_capability`.
- Nonce verification on every AJAX endpoint.
- WPCS sanitization on input, escaping on output.
- Per-user internal rate limiting, independent of the tail backoff.
- No credential value ever appears in an AJAX response.

---

## 7. Deliberately out of scope

These were considered and rejected for 1.0.0, each for a reason that has
not changed:

- **Live Tail** (`StartLiveTail`). The PHP SDK really does support it —
  `CloudWatchLogsClient::startLiveTailCheckingForResults()` returns a
  Generator — so "PHP cannot do it" is the wrong reason to skip it. The
  right ones: it is a persistent HTTP/2 stream that pins a PHP-FPM worker
  for the life of the session, it costs $0.01 per minute beyond the 1,800
  free minutes a month, and it tends to break behind proxies and
  CloudFront. Polling delivers most of the value at none of that cost.
- **A Logs Insights query mode** (`StartQuery` / `GetQueryResults`). Real
  regular expressions and aggregations, but asynchronous and billed per
  gigabyte scanned. Worth adding as an explicit "advanced" mode, never as
  the default search.
- **Multiple log groups at once.** `FilterLogEvents` reads one group;
  covering several means either several calls against a five-per-second
  account quota, or Live Tail, which takes up to ten.


---

## 8. External prerequisite

The ECS task role needs permissions it most likely does not have today:

- `logs:FilterLogEvents`
- `logs:DescribeLogGroups`
- `logs:DescribeLogStreams`
- `secretsmanager:GetSecretValue` — only for `secret` mode

Those are the only calls the plugin makes against CloudWatch Logs;
`logs:GetLogEvents` is deliberately not required. `sts:GetCallerIdentity`
is also called, to report the resolved identity in the status table, but
AWS allows it unconditionally and a failure only blanks that one row.

Without them the `auto` mode fails with `AccessDeniedException`. The
plugin surfaces that error explicitly in the status card rather than
degrading silently.

---

## 9. References

- [CloudWatch Logs quotas](https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
- [Regex filter pattern syntax](https://aws.amazon.com/about-aws/whats-new/2023/09/amazon-cloudwatch-logs-regular-expression-filter-pattern-syntax-support)
- [Live Tail](https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/CloudWatchLogs_LiveTail.html)
- [CloudWatch pricing](https://aws.amazon.com/cloudwatch/pricing/)
- [CloudWatchLogsClient (PHP SDK)](https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CloudWatchLogs.CloudWatchLogsClient.html)
