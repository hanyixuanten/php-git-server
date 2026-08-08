# Repository Guidelines

## Project Structure & Module Organization

This is a small PHP implementation of Git's dumb HTTP protocol. The request router and all protocol helpers live in `index.php`. Apache rewrite rules are in `.htaccess`; they forward requests to the router while preventing direct serving of repository files. Copy `config.php.sample` to `config.php` and set `$url_base` plus the `$repos` mapping for each served repository. `README.md` documents the project's purpose. There are currently no separate source, asset, or test directories.

## Build, Test, and Development Commands

No package manager, build step, or automated test suite is included. Use PHP's built-in checks while editing:

```sh
php -l index.php        # check PHP syntax
php -l config.php       # check local configuration after copying the sample
```

To exercise the application locally, serve the repository through a PHP-enabled Apache configuration so that `.htaccess` rewrite behavior is included. Verify a configured repository with a read-only command such as:

```sh
git ls-remote http://localhost/php-git-server/php-git-server.git
```

Also test unsupported paths and methods: they must return `404` and `405` respectively.

## Coding Style & Naming Conventions

Match the existing PHP style: four-space indentation, opening braces on the same line as declarations, single-quoted strings unless interpolation is needed, and lowercase snake_case function names (for example, `get_packed_refs`). Keep request handlers small and add a matching entry to `$services` for each supported endpoint. Preserve the existing compatibility-oriented syntax (`array(...)`, not short arrays) unless a deliberate project-wide modernization is proposed.

## Testing Guidelines

Make focused manual regression checks for changes to routing, refs, or object delivery. Test loose objects, packed repositories, symbolic refs, `packed-refs`, and missing files where relevant. Do not commit real `config.php` files or repository data; use the sample configuration and temporary fixtures outside the repository.

## Commit & Pull Request Guidelines

Recent commits use short, imperative, sentence-case subjects, e.g. `Clarify overview in README.` Keep each commit limited to one change. Pull requests should explain the protocol behavior affected, list the manual commands/results used for verification, and link relevant issues. Include configuration or Apache notes when deployment behavior changes.

## Security & Configuration

Treat `config.php` as environment-specific and keep repository paths explicit. Changes must not broaden file access beyond configured Git directories or expose arbitrary server files.
