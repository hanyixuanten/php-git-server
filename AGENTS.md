# Repository Guidelines

## Project Structure & Module Organization

This is a PHP Smart HTTP Git server with no dependency manager or generated build output.

- `index.php` is the public entry point and request router.
- `install.php` creates the initial configuration and database schema.
- `manage.php` provides administrator and managed-repository operations.
- `lib/` contains shared HTTP, authentication, repository, routing, Git protocol, and i18n code.
- `operations/` handles clone, pull, push, branch, and tag requests.
- `schema.mysql.sql` and `migration.repository-ownership.mysql.sql` define database changes.
- `config.php.sample` documents settings; local `config.php` must never be committed.
- `repos/` contains runtime bare repositories. `README.md` and `usage.md` document deployment.

There is no dedicated automated test directory.

## Build, Test, and Development Commands

PHP is interpreted directly, so there is no build step. Run syntax checks from the repository root:

```sh
php -l index.php
php -l install.php
php -l manage.php
for file in lib/*.php operations/*.php; do php -l "$file" || exit 1; done
```

Check required runtime support with `php -m | grep pdo_mysql`. For a local smoke test, use a disposable `config.php` and PHP-capable web server, then exercise the UI and Git endpoints with `git ls-remote` or `git clone`.

## Coding Style & Naming Conventions

Use four-space indentation, same-line opening braces, uppercase PHP constants (`TRUE`, `FALSE`, `NULL`), and explicit `<?php` files. Use `snake_case` functions and variables, page-specific helper prefixes such as `home_`, `install_`, and `manage_`, and lowercase filenames. Escape HTML values with the local `*_escape()` helper. Keep security headers, CSRF checks, path validation, and protocol responses intact. Put user-facing web text in `lib/i18n.php` and access it through `t()`.

## Testing Guidelines

No PHPUnit or coverage requirement is configured. Every change should pass all `php -l` checks and include a focused manual smoke test for affected HTTP or Git behavior. For authentication, repository, or push changes, test both success and rejection paths.

## Commit & Pull Request Guidelines

Existing commits use short, imperative, lowercase summaries such as `add management page` and `fix permission errors`. Keep commits focused and concise. Pull requests should explain behavior and security impact, list validation commands, identify configuration or schema changes, and include screenshots for UI changes. Never include credentials, production `config.php`, or real repository data.

## Security & Configuration Tips

Use a disposable database locally. Review `usage.md` before changing routing, permissions, authentication, or ownership. Keep `config.php` restricted to the application, prevent direct web exposure of `repos/`, and update SQL migration files when persistent metadata changes.
