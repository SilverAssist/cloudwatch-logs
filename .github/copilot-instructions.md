# Silver Assist CloudWatch Logs — AI agent instructions

WordPress plugin that reads, searches and follows an Amazon CloudWatch
Logs group from the WordPress admin.

## Project facts

| Field | Value |
|---|---|
| Namespace | `SilverAssist\CloudWatchLogs\` → `includes/` |
| Main file | `silver-assist-cloudwatch-logs.php` (folder is `cloudwatch-logs`) |
| Text domain | `silver-assist-cloudwatch-logs` |
| Global prefixes | `SILVER_ASSIST_CLOUDWATCH_` / `silver_assist_cloudwatch_` |
| PHP / WordPress | 8.2+ / 6.5+ |
| Standards | `SilverAssistWP` (PHPCS), PHPStan level 8, PHPUnit + WP Test Suite |

The repository name drops the `silver-assist` prefix because it lives
under the `SilverAssist` organization. Anything landing in a *shared*
WordPress namespace keeps the prefix. The plugin folder and the text
domain deliberately differ, so `load_plugin_textdomain()` is called
explicitly — do not "fix" that by renaming either one.

## Architecture

`Core\Plugin` extends `AbstractPlugin` from `silverassist/wp-plugin-kernel`
and lists components in `get_components()`. Every component implements
`LoadableInterface`, exposes a **static `instance()`** (the kernel calls
it, and skips any class without one), and declares a priority: 10 core,
20 services, 30 admin, 40 assets.

```text
Service\SdkLoader (10)          reuse the site's AWS SDK, or the bundled one
Admin\AdminPage (30)            hub registration + logs/settings tabs
Admin\Ajax\ConnectionAjaxHandler (30)
Admin\Ajax\LogsAjaxHandler (30)
Admin\AssetManager (40)
```

Plain classes (no `LoadableInterface`): `Repository\SettingsRepository`,
`Service\CredentialsResolver`, `Service\ClientFactory`,
`Service\ConnectionTester`, `Service\LogsService`, everything in `Model\`
and `Utils\`. Views under `View\Admin\` are static renderers.

The Settings Hub gives a plugin **one** page, which is why the viewer and
the settings are tabs of `AdminPage` rather than two menu entries.

## Front-end assets

`assets/js/admin.js` (settings screen) and `assets/js/viewer.js` (log
viewer) are **ES6+ with JSDoc**, matching the other Silver Assist
plugins: `const`/`let`, arrow functions, template literals,
destructuring, `Set`, classes, and `async`/`await` for anything hitting
admin-ajax. No `var`, no `.then()` chains, no jQuery — this plugin has no
jQuery dependency, so do not add one. They are served to the browser
as-is; there is no build step and none is wanted.

Every function, method, constant and typedef carries a JSDoc block with
`@since`, `@param {Type} name - Description` and `@returns`. Files open
with `@file`, `@version`, `@author`, `@since`.

`viewer.js` is one `LogViewer` class rather than module-level mutable
state, so the search, pagination and tail state cannot drift apart.

## Rules that are easy to get wrong here

- **Never cache AWS credentials in the database.** Credentials read from
  Secrets Manager are memoised per request only; a transient would put a
  live secret key into `wp_options` in plaintext.
- **Never render a log message as markup.** Log lines routinely contain
  HTML. `LogViewer.buildRow()` and `highlight()` build rows with
  `textContent` and `createTextNode`; keep it that way. The one
  `innerHTML` in the codebase parses the *status card* markup that the
  server itself rendered and escaped, never log content.
- **Do not sanitize the search term with `sanitize_text_field()`.** It
  strips anything tag-shaped and silently changes what the user searched
  for. Use `Helpers::sanitize_search_term()`.
- **Respect the CloudWatch quota.** Five `FilterLogEvents` calls per
  second per AWS account, shared with everything else in it. The poll
  interval floor, the per-user throttle, the site-wide lock and the
  backoff all exist for that reason — do not relax them.
- **CloudWatch filter patterns are not regular expressions.** A regex has
  to be wrapped in `%…%`, at most two per pattern. `FilterPatternBuilder`
  owns that translation.
- **`describeLogGroups` matches on prefix**, so an exact-name check is
  required before declaring a group found.

## Before pushing

```bash
composer phpcs      # must be 0 errors
composer phpstan    # level 8, must be clean
composer phpunit    # WP Test Suite; needs scripts/install-wp-tests.sh first
wp i18n make-pot . languages/silver-assist-cloudwatch-logs.pot \
  --domain=silver-assist-cloudwatch-logs --exclude=vendor,tests,build,node_modules
```

Run the `core-review` skill before opening a PR: this repository's
documentation makes concrete claims about behaviour, and stale claims are
the failure mode most likely to survive the automated checks.

## Release

`scripts/build-release.sh [version]` produces `build/cloudwatch-logs-v*.zip`.
It copies the whole `--no-dev` vendor tree (the AWS SDK needs Guzzle,
PSR-7, JMESPath and aws-crt-php at runtime) and asserts that the SDK trim
actually ran — if `vendor/aws/aws-sdk-php/src/Ec2` survives, the
`removeUnusedServices` Composer script did not fire and the ZIP would
carry ~50MB of dead code.
