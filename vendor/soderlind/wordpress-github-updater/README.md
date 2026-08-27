# WordPress Plugin GitHub Updater

Composer library for handling WordPress plugin updates from GitHub repositories. Built on `yahnis-elsts/plugin-update-checker` with built-in rate-limit mitigation.

## Features

- **Automatic updates** from GitHub releases or branch commits.
- **Rate-limit mitigation**: Configurable check intervals (default 6h) and throttling (72h when update already known).
- **GitHub token support** for private repos and higher API limits (60 → 5000 requests/hour).
- Release-asset filtering using regex.
- Branch selection (default: `main`).
- Canonical static API (`GitHubUpdater::init`) plus backward-compatible wrapper.
- Error handling with `WP_DEBUG` logging.

## Installation

```bash
composer require soderlind/wordpress-github-updater
```

This automatically includes `yahnis-elsts/plugin-update-checker` as a dependency.

## Requirements

- PHP 7.4+
- WordPress plugin context (`ABSPATH` must be defined).
- A GitHub repository with releases or branch updates.

## Quick Start

```php
use Soderlind\WordPress\GitHubUpdater;

GitHubUpdater::init(
	github_url:   'https://github.com/username/plugin-name',
	plugin_file:  __FILE__,
	plugin_slug:  'plugin-name',
	name_regex:   '/plugin-name\.zip/',
	branch:       'main',
	check_period: 6,            // Hours between checks (default: 6)
	auth_token:   '',           // Optional GitHub token
);
```

Positional equivalent:

```php
\Soderlind\WordPress\GitHubUpdater::init(
	'https://github.com/username/plugin-name',
	__FILE__,
	'plugin-name',
	'/plugin-name\.zip/',
	'main',
	6,   // check_period
	''   // auth_token
);
```

### With GitHub Token (for private repos or higher rate limits)

```php
GitHubUpdater::init(
	github_url:   'https://github.com/username/private-plugin',
	plugin_file:  __FILE__,
	plugin_slug:  'private-plugin',
	auth_token:   defined('MY_PLUGIN_GITHUB_TOKEN') ? MY_PLUGIN_GITHUB_TOKEN : '',
);
```

## Backward Compatibility API

`GitHub_Plugin_Updater` is still available for existing integrations.

```php
\Soderlind\WordPress\GitHub_Plugin_Updater::create(
	'https://github.com/username/plugin-name',
	__FILE__,
	'plugin-name',
	'main',
	6,  // check_period
	''  // auth_token
);

\Soderlind\WordPress\GitHub_Plugin_Updater::create_with_assets(
	'https://github.com/username/plugin-name',
	__FILE__,
	'plugin-name',
	'/plugin-name\.zip/',
	'main',
	6,  // check_period
	''  // auth_token
);
```

## Configuration

### `GitHubUpdater::init(...)`

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `github_url` | Yes | - | Full repository URL (`https://github.com/owner/repo`) |
| `plugin_file` | Yes | - | Absolute path to the main plugin file |
| `plugin_slug` | Yes | - | Plugin slug used by WordPress |
| `name_regex` | No | `''` | Regex to match release asset zip filename |
| `branch` | No | `'main'` | Branch to track |
| `check_period` | No | `6` | Hours between update checks. Set to `0` to disable automatic checks. |
| `auth_token` | No | `''` | GitHub personal access token for private repos or higher rate limits |

### Rate-Limit Mitigation

This library includes built-in protection against GitHub API timeouts/overload:

1. **Configurable check interval**: Default is 6 hours (vs. upstream default of 12h). Adjust via `check_period`.
2. **Throttled checks**: When an update is already known, checks are reduced to every 72 hours.
3. **Token authentication**: Provide a GitHub token to increase rate limits from 60 to 5000 requests/hour.
4. **Error logging**: API errors are logged when `WP_DEBUG` is enabled.

## Recommended Integration Pattern

From your main plugin file:

```php
// Autoloaded via Composer
use Soderlind\WordPress\GitHubUpdater;

GitHubUpdater::init(
	github_url:   'https://github.com/owner/repo',
	plugin_file:  __FILE__,
	plugin_slug:  'my-plugin',
	name_regex:   '/my-plugin\.zip/',
	branch:       'main',
	check_period: 6,
);
```

## Workflow Templates

This repository includes two workflow templates in `.github/workflows/`:

- `on-release-add.zip.yml` — Release-triggered build + upload
- `manually-build-zip.yml` — Manual build/upload for a provided tag

Copy them into your plugin repository at `.github/workflows/`.

### Workflow Checklist

1. Set `PLUGIN_ZIP` to your plugin zip file (example: `my-plugin.zip`).
2. Keep `composer install --no-dev --optimize-autoloader` so dependencies are packaged.
3. Keep the verification step that checks `vendor/yahnis-elsts/plugin-update-checker` exists in the zip.
4. Ensure `name_regex` in PHP matches your zip filename convention.

## Troubleshooting

### Updates do not appear

1. Confirm your plugin file, slug, repo URL, and branch are correct.
2. Publish a release with an attached zip that matches `name_regex`.
3. Trigger a manual update check in WordPress Admin (or wait for scheduled checks).

### Dependency is missing in release zip

If `plugin-update-checker` is not in `vendor/`, updater setup fails. In `WP_DEBUG`, you will see:

`GitHubUpdater (your-plugin-slug): Missing dependency yahnis-elsts/plugin-update-checker...`

### GitHub API rate limits

The library includes built-in rate-limit mitigation:

- **Check interval**: By default, checks only every 6 hours.
- **Throttling**: When an update is already available, checks reduce to every 72 hours.
- **Token auth**: Pass a GitHub token via `auth_token` to increase limits from 60 to 5000 requests/hour.

If you still hit rate limits, increase `check_period` or add a GitHub token.

### Private repositories

Pass your GitHub personal access token via the `auth_token` parameter:

```php
GitHubUpdater::init(
	github_url:  'https://github.com/username/private-repo',
	plugin_file: __FILE__,
	plugin_slug: 'private-plugin',
	auth_token:  MY_GITHUB_TOKEN,
);
```

## Real-World Reference

WordPress plugins at https://github.com/soderlind

## License

GPL-2.0-or-later.
