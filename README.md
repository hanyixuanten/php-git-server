PHP Git server
==============

This project serves configured Git repositories through PHP. It supports:

- Dumb HTTP reads for compatibility.
- Smart HTTP `upload-pack` for clone, fetch and pull.
- Smart HTTP `receive-pack` for push.
- Remote branch and tag creation, update and deletion through push.
- Per-repository controls for reads, pushes, authentication, branch refs,
  tag refs, other ref namespaces and push request size.

`index.php` is the application entry point. Protocol responsibilities are split
into `operations/clone.php`, `operations/pull.php`, `operations/push.php`,
`operations/branch.php` and `operations/tag.php`; shared routing, repository,
HTTP and Git process code is under `lib/`.

Requirements
------------

- PHP 7.4 or newer.
- Apache with `mod_rewrite` and `.htaccess` enabled for normal deployment.
- Git installed on the server for Smart HTTP clone/pull and all push support.
- PHP `proc_open` enabled for Smart HTTP.
- Read access to published repositories; write access is also required for push.

If Git or `proc_open` is unavailable, read-only Dumb HTTP remains available.
Push is disabled by default and should be enabled only behind HTTPS and trusted
web-server authentication.

Configuration
-------------

Copy `config.php.sample` to `config.php`, then configure `$url_base`,
`$git_executable` and `$repos`. A writable repository can be configured as:

```php
$repos = array(
    array('/project.git', '/srv/git/project.git', array(
        'read' => TRUE,
        'push' => TRUE,
        'require_auth' => TRUE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE)));
```

`require_auth` trusts only `REMOTE_USER`, which must be set by authenticated
Apache or reverse-proxy configuration. Setting it to `FALSE` permits anonymous
push and is suitable only for isolated development environments.

See `usage.md` for complete Chinese installation, configuration, operation and
security instructions.
