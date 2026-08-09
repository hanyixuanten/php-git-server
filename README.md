PHP Git server
==============

This project serves configured Git repositories through PHP. It supports:

- Pure-PHP Smart HTTP clone, fetch, pull and push for SHA-1 repositories.
- Dumb HTTP reads for compatibility and pure-PHP bare-repository creation.
- Smart HTTP `upload-pack` for clone, fetch and pull.
- Smart HTTP `receive-pack` for push.
- Remote branch and tag creation, update and deletion through push.
- MySQL-backed account registration and login.
- Hashed, revocable access tokens for authenticated Git pushes.
- Repository ownership with owner-only pushes.
- Public/private repositories and authenticated private reads.
- Authenticated public/private bare-repository creation from the home page.
- Per-repository controls for reads, pushes, visibility, branch refs,
  tag refs, other ref namespaces and push request size.

`index.php` is the application entry point. Protocol responsibilities are split
into `operations/clone.php`, `operations/pull.php`, `operations/push.php`,
`operations/branch.php` and `operations/tag.php`; shared routing, repository,
HTTP and Git process code is under `lib/`.

Requirements
------------

- PHP 7.4 or newer.
- Apache with `mod_rewrite` and `.htaccess` enabled for normal deployment.
- MySQL 5.7+/MariaDB 10.2+ and PHP PDO MySQL (`pdo_mysql`) when account
    authentication is enabled.
- PHP zlib and hash extensions for the native Git protocol implementation.
- Git and PHP `proc_open` are optional; when available, Git remains the Smart
    HTTP backend for full protocol and hook compatibility.
- Read access to published repositories; write access is also required for push
    and for the managed repository directory when home-page creation is enabled.

If Git or `proc_open` is unavailable, the native PHP backend supports ordinary
SHA-1 clone, fetch, pull and push, including deltas, branches and tags. It does
not currently support SHA-256 repositories, shallow or filtered fetches, signed
push certificates, Git hooks or protocol v2-only features.
Push is disabled by default and should be enabled only behind HTTPS.

Configuration
-------------

Copy `config.php.sample` to `config.php`, import `schema.mysql.sql`, then
configure `$url_base`, `$git_executable`, `$auth`, `$repos` and optionally
`$managed_repositories`. A writable repository can be configured as:

```php
$repos = array(
    array('/project.git', '/srv/git/project.git', array(
        'read' => TRUE,
        'push' => TRUE,
        'owner' => 'alice',
        'private' => TRUE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE)));
```

Git uses HTTP Basic authentication: enter the registered username as the
username and an access token as the password. Private repositories require a
valid token for every Git read, and only the configured owner can push to any
writable repository. The application stores password hashes and token SHA-256
digests in MySQL; token plaintext is shown only once.

Existing installations should run `migration.repository-ownership.mysql.sql`
and assign an owner/visibility row for each existing managed repository before
enabling pushes.

To create bare repositories from the home page, enable managed repositories.
They are always stored in the application's `repos` directory and discovered
automatically; this path cannot be changed in `config.php`:

```php
$managed_repositories = array(
    'options' => array(
        'read' => TRUE,
        'push' => TRUE));
```

The home page uses the logged-in application session for repository creation,
records that account as owner, and lets the owner choose public or private.
Anonymous visitors see only the public repository section. Logged-in users also
see a separate private section, but Git access to private repositories still
requires an access token rather than the browser session.
The `repos` directory is created automatically when missing. Its parent must be
writable for that first creation, and the resulting directory must be readable,
writable and searchable by PHP and reserved for this application. Set
`$auth['session_cookie_secure']` to `TRUE` when HTTPS terminates at a trusted
reverse proxy; direct HTTPS deployments are detected from the web-server
connection automatically.

See `usage.md` for complete Chinese installation, configuration, operation and
security instructions.
