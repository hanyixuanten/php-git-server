<?php

if (!file_exists(__DIR__.'/config.php')) {
    require(__DIR__.'/lib/install_redirect.php');
    redirect_to_installer();
}

require(__DIR__.'/config.php');
require(__DIR__.'/lib/http.php');
require(__DIR__.'/lib/auth.php');
require(__DIR__.'/lib/repository.php');
require(__DIR__.'/lib/router.php');
require(__DIR__.'/lib/git_protocol.php');
require(__DIR__.'/lib/git_object_store.php');
require(__DIR__.'/lib/git_upload_pack.php');
require(__DIR__.'/lib/git_receive_pack.php');
require(__DIR__.'/lib/git_service.php');
require(__DIR__.'/operations/branch.php');
require(__DIR__.'/operations/tag.php');
require(__DIR__.'/operations/clone.php');
require(__DIR__.'/operations/pull.php');
require(__DIR__.'/operations/push.php');

/* The home page lists the configured repositories served by this application.
   Repositories with reads disabled stay hidden so that the public entry point
   does not disclose private repository names. */
function home_page_is_request($url_base, $url_path) {
    $base = rtrim((string) $url_base, '/');
    return $url_path === $base || $url_path === $base.'/';
}

function home_page_url($url_base) {
    $base = rtrim((string) $url_base, '/');
    return $base === '' ? '/' : $base.'/';
}

function home_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function home_managed_repositories_configured($configuration) {
    return is_array($configuration) && !empty($configuration);
}

function home_creation_is_authorized($configuration) {
    return auth_is_enabled() && get_authenticated_user() !== NULL;
}

function home_session_cookie_is_secure($configuration) {
    if (isset($configuration['session_cookie_secure'])) {
        return $configuration['session_cookie_secure'] === TRUE;
    }

    $https = isset($_SERVER['HTTPS']) ? strtolower((string) $_SERVER['HTTPS']) : '';
    return $https === 'on' || $https === '1'
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
}

function home_start_session($url_base, $configuration) {
    if (auth_is_enabled()) {
        return auth_start_session();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return TRUE;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        return FALSE;
    }

    session_name('PHPGITSERVER');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => home_page_url($url_base),
        'secure' => home_session_cookie_is_secure($configuration),
        'httponly' => TRUE,
        'samesite' => 'Strict'));
    return @session_start();
}

function home_csrf_token($url_base, $configuration) {
    if (!home_start_session($url_base, $configuration)) {
        return FALSE;
    }

    if (!isset($_SESSION['home_csrf_token'])
        || !is_string($_SESSION['home_csrf_token'])
        || strlen($_SESSION['home_csrf_token']) !== 64) {
        try {
            $_SESSION['home_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return FALSE;
        }
    }

    return $_SESSION['home_csrf_token'];
}

function home_take_notice($url_base, $configuration) {
    if (!home_start_session($url_base, $configuration) || !isset($_SESSION['home_notice'])) {
        return NULL;
    }

    $notice = $_SESSION['home_notice'];
    unset($_SESSION['home_notice']);
    return is_array($notice) ? $notice : NULL;
}

function home_set_notice($url_base, $configuration, $type, $message, $token=NULL) {
    if (!home_start_session($url_base, $configuration)) {
        return FALSE;
    }

    $_SESSION['home_notice'] = array('type' => $type, 'message' => $message);
    if ($token !== NULL) {
        $_SESSION['home_notice']['token'] = $token;
    }
    return TRUE;
}

function home_redirect($url_base) {
    send_status(303, 'See Other');
    header('Location: '.home_page_url($url_base));
    die();
}

function home_post_value($name) {
    return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
}

function home_require_csrf($url_base, $configuration) {
    if (!request_content_type_is(get_request_header('Content-Type'), 'application/x-www-form-urlencoded')) {
        send_error(415, 'Unsupported Media Type', 'Expected a form-encoded request.');
    }

    $expected_token = home_csrf_token($url_base, $configuration);
    if ($expected_token === FALSE
        || !hash_equals($expected_token, home_post_value('csrf_token'))) {
        send_error(403, 'Forbidden', 'The form security token is invalid.');
    }
}

function home_auth_result_notice($result) {
    switch ($result['status']) {
        case 'registered':
            return array('success', '账号已注册并登录。');
        case 'logged_in':
            return array('success', '登录成功。');
        case 'registration_disabled':
            return array('error', '当前不允许注册新账号。');
        case 'invalid_username':
            return array('error', '用户名须为 3 至 64 个字母、数字、点、短横线或下划线。');
        case 'invalid_password':
            return array('error', '密码长度须为 8 至 72 个字符，且不能超过 72 字节。');
        case 'password_mismatch':
            return array('error', '两次输入的密码不一致。');
        case 'username_exists':
            return array('error', '该用户名已存在或不可用。');
        case 'invalid_credentials':
            return array('error', '用户名或密码错误。');
        case 'invalid_token_name':
            return array('error', 'Token 名称不能为空，且最多 80 个字符。');
        case 'token_created':
            return array('success', 'Access token 已创建。请立即保存，关闭页面后无法再次查看。');
        case 'token_revoked':
            return array('success', 'Access token 已撤销。');
        case 'invalid_token':
            return array('error', 'Access token 不存在或已撤销。');
        case 'session_unavailable':
            return array('error', '当前无法建立安全会话。');
        default:
            return array('error', '认证数据库当前不可用。');
    }
}

function home_handle_auth_action($url_base, $configuration, $action) {
    if (!auth_is_enabled()) {
        send_error(404, 'Not Found', 'Account authentication is disabled.');
    }

    home_require_csrf($url_base, $configuration);
    $session_user = auth_session_user();

    if ($action === 'register') {
        if ($session_user !== NULL) {
            send_error(409, 'Conflict', 'Already authenticated.');
        }
        $result = auth_register(
            home_post_value('username'),
            home_post_value('password'),
            home_post_value('password_confirmation'));
    } else if ($action === 'login') {
        if ($session_user !== NULL) {
            send_error(409, 'Conflict', 'Already authenticated.');
        }
        $result = auth_login(home_post_value('username'), home_post_value('password'));
    } else if ($action === 'logout') {
        if ($session_user === NULL) {
            send_error(403, 'Forbidden', 'Login is required.');
        }
        if (!auth_logout()) {
            send_error(500, 'Internal Server Error', 'Unable to close the login session.');
        }
        home_set_notice($url_base, $configuration, 'success', '已退出登录。');
        home_redirect($url_base);
    } else if ($action === 'create_token') {
        if ($session_user === NULL) {
            send_error(403, 'Forbidden', 'Login is required.');
        }
        $result = auth_create_access_token($session_user['id'], home_post_value('token_name'));
    } else if ($action === 'revoke_token') {
        if ($session_user === NULL) {
            send_error(403, 'Forbidden', 'Login is required.');
        }
        $result = auth_revoke_access_token($session_user['id'], home_post_value('token_id'));
    } else {
        send_error(400, 'Bad Request', 'Unknown account action.');
    }

    $notice = home_auth_result_notice($result);
    $plaintext_token = isset($result['token']) ? $result['token'] : NULL;
    home_set_notice($url_base, $configuration, $notice[0], $notice[1], $plaintext_token);
    home_redirect($url_base);
}

function home_repository_url_conflicts($url_base, $definitions, $configuration, $name) {
    $expected_url = rtrim((string) $url_base, '/').'/'.$name;
    $root = managed_repository_root($configuration);
    $managed_path = $root === FALSE ? NULL : $root.DIRECTORY_SEPARATOR.$name;
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository === FALSE || $repository['url'] !== $expected_url) {
            continue;
        }

        if ($managed_path !== NULL && $repository['path'] === $managed_path) {
            continue;
        }

        if ($managed_path !== NULL
            && realpath($repository['path']) !== FALSE
            && realpath($repository['path']) === realpath($managed_path)) {
            continue;
        }

            return TRUE;
    }

    return FALSE;
}

function home_creation_result_notice($result) {
    $name = isset($result['name']) ? $result['name'] : '';
    switch ($result['status']) {
        case 'invalid_name':
            return array(422, 'error', '仓库名称格式无效。');
        case 'already_exists':
            return array(409, 'error', '仓库 '.$name.' 已存在。');
        case 'create_busy':
            return array(409, 'error', '另一个仓库正在创建，请稍后重试。');
        case 'root_unavailable':
            return array(503, 'error', '仓库存放目录不可用或不可写。');
        case 'git_unavailable':
            return array(503, 'error', 'Git 初始化服务当前不可用。');
        case 'metadata_unavailable':
            return array(503, 'error', '仓库所有权信息无法保存，请稍后重试。');
        default:
            return array(500, 'error', '仓库创建失败，请检查服务器日志。');
    }
}

function home_deletion_result_notice($result) {
    $name = isset($result['name']) ? $result['name'] : '';
    switch ($result['status']) {
        case 'deleted':
            return array(303, 'success', '仓库 '.$name.' 已删除。');
        case 'record_deleted':
            return array(303, 'success', '仓库记录 '.$name.' 已删除，非 bare 路径未作改动。');
        case 'invalid_repository':
            return array(422, 'error', '仓库名称格式无效。');
        case 'forbidden':
            return array(403, 'error', '只有仓库所有者可以删除该仓库。');
        case 'configured_repository':
            return array(403, 'error', '该路径由 config.php 静态配置，不能从网页删除。');
        case 'not_found':
            return array(404, 'error', '托管仓库不存在或尚未创建完成。');
        case 'repository_busy':
            return array(409, 'error', '仓库正在执行其他操作，请稍后重试。');
        case 'root_unavailable':
            return array(503, 'error', '仓库存放目录不可用或不可写。');
        case 'metadata_unavailable':
            return array(503, 'error', '仓库所有权信息当前不可用。');
        case 'cleanup_failed':
            return array(500, 'error', '仓库记录已删除，但残留目录清理失败，请检查服务器日志。');
        case 'restore_failed':
            return array(500, 'error', '删除失败且仓库目录无法恢复，请立即检查服务器日志。');
        default:
            return array(500, 'error', '仓库删除失败，请检查服务器日志。');
    }
}

function home_create_repository(
    $url_base,
    $definitions,
    $configuration,
    $application) {
    if (!home_managed_repositories_configured($configuration)) {
        send_error(403, 'Forbidden', 'Repository creation is disabled.');
    }
    if (!home_creation_is_authorized($configuration)) {
        send_error(403, 'Forbidden', 'Authenticated access is required to create repositories.');
    }
    home_require_csrf($url_base, $configuration);

    $owner = auth_session_user();
    if ($owner === NULL) {
        send_error(403, 'Forbidden', 'Login is required to create repositories.');
    }

    $visibility = home_post_value('visibility');
    if ($visibility !== 'public' && $visibility !== 'private') {
        send_error(422, 'Unprocessable Content', 'Repository visibility is invalid.');
    }

    $value = isset($_POST['repository_name']) && is_string($_POST['repository_name'])
        ? $_POST['repository_name'] : '';
    $name = normalize_managed_repository_name($value);
    if ($name !== FALSE
        && home_repository_url_conflicts($url_base, $definitions, $configuration, $name)) {
        $result = array('status' => 'already_exists', 'name' => $name);
    } else {
        $result = git_service_create_managed_repository(
            $application,
            $configuration,
            $value,
            $owner['id'],
            $visibility === 'private');
    }

    if ($result['status'] === 'created') {
        home_set_notice(
            $url_base, $configuration, 'success', '仓库 '.$result['name'].' 已创建。');
        home_redirect($url_base);
    }

    $notice = home_creation_result_notice($result);
    send_status($notice[0], $notice[0] === 409 ? 'Conflict' : ($notice[0] === 422
        ? 'Unprocessable Content' : ($notice[0] === 503 ? 'Service Unavailable' : 'Internal Server Error')));
    home_render(
        $url_base,
        $definitions,
        $configuration,
        array('type' => $notice[1], 'message' => $notice[2]),
        $value,
        $visibility);
    die();
}

function home_delete_repository($url_base, $definitions, $configuration) {
    if (!home_managed_repositories_configured($configuration)) {
        send_error(403, 'Forbidden', 'Managed repositories are disabled.');
    }
    home_require_csrf($url_base, $configuration);

    $user = auth_session_user();
    if ($user === NULL) {
        send_error(403, 'Forbidden', 'Login is required to delete repositories.');
    }
    if (home_post_value('confirmation') !== 'delete') {
        send_error(422, 'Unprocessable Content', 'Repository deletion must be confirmed.');
    }

    $result = delete_managed_repository(
        $configuration,
        $definitions,
        $url_base,
        home_post_value('repository_name'),
        $user['id']);
    $notice = home_deletion_result_notice($result);
    if ($result['status'] === 'deleted') {
        home_set_notice($url_base, $configuration, $notice[1], $notice[2]);
        home_redirect($url_base);
    }

    send_error($notice[0], $notice[0] === 403 ? 'Forbidden'
        : ($notice[0] === 404 ? 'Not Found'
        : ($notice[0] === 409 ? 'Conflict'
        : ($notice[0] === 422 ? 'Unprocessable Content'
        : ($notice[0] === 503 ? 'Service Unavailable' : 'Internal Server Error')))), $notice[2]);
}

function home_repository_url_cmp($left, $right) {
    return strcmp($left['url'], $right['url']);
}

function home_visible_repositories($url_base, $definitions, $include_private) {
    $repositories = array('public' => array(), 'private' => array());

    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository === FALSE || !$repository['options']['read']) {
            continue;
        }

        $visibility = repository_is_private($repository) ? 'private' : 'public';
        if ($visibility === 'private' && !$include_private) {
            continue;
        }

        $git_path = realpath($repository['path']);
        if ($git_path === FALSE || !is_dir($git_path)) {
            continue;
        }

        $repository['path'] = $git_path;
        $repositories[$visibility][] = $repository;
    }

    usort($repositories['public'], 'home_repository_url_cmp');
    usort($repositories['private'], 'home_repository_url_cmp');
    return $repositories;
}

/* Returns the branch HEAD points at, including a branch that has no commit yet,
   or NULL when HEAD is detached or unreadable. */
function home_head_branch($git_path) {
    $head = resolve_ref($git_path, 'HEAD');
    if ($head[0] !== NULL && strpos($head[0], 'refs/heads/') === 0) {
        return substr($head[0], strlen('refs/heads/'));
    }

    $path = get_safe_file_path($git_path, '/HEAD');
    if ($path === FALSE) {
        return NULL;
    }

    $buffer = @file_get_contents($path);
    if ($buffer !== FALSE
        && preg_match('~^ref:\s*refs/heads/([^\r\n]+?)\s*$~', $buffer, $matches)) {
        return $matches[1];
    }

    return NULL;
}

function home_repository_summary($repository) {
    $summary = array(
        'head' => home_head_branch($repository['path']),
        'branches' => 0,
        'tags' => 0);

    foreach (get_repository_refs($repository['path']) as $ref) {
        if (strpos($ref[0], 'refs/heads/') === 0) {
            $summary['branches'] += 1;
        } else if (strpos($ref[0], 'refs/tags/') === 0 && !str_endswith($ref[0], '^{}')) {
            $summary['tags'] += 1;
        }
    }

    return $summary;
}

/* Clone URLs are shown as absolute URLs only when the request host is a plain
   name and optional port, because the Host header is client controlled. */
function home_clone_url_prefix() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (!preg_match('~^[A-Za-z0-9._-]+(?::[0-9]{1,5})?$~', $host)) {
        return '';
    }

    $scheme = 'http';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
        && strcasecmp($_SERVER['HTTPS'], 'off')) {
        $scheme = 'https';
    }

    return $scheme.'://'.$host;
}

function home_send_head() {
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Git 服务器</title>
<style>
:root { color-scheme: light dark; }
body { max-width: 62rem; margin: 0 auto; padding: 2.5rem 1.25rem; line-height: 1.6;
    font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans CJK SC", sans-serif;
    color: #1f2530; background: #ffffff; }
h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
h2 { margin: 2.5rem 0 .5rem; font-size: 1.1rem; }
p { margin: 0 0 1rem; }
.lead { color: #5b6472; }
.account, .create { margin: 1.75rem 0 2rem; padding: 1rem 0; border-top: 1px solid #d5dae1;
    border-bottom: 1px solid #d5dae1; }
.account h2, .create h2 { margin: 0 0 .25rem; }
.account-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; }
.account form, .create form, .token-form { display: flex; gap: .6rem; align-items: end; }
.account .credentials { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; flex: 1; }
.field { flex: 1 1 22rem; }
label { display: block; margin-bottom: .3rem; font-weight: 600; }
input:not([type="radio"]):not([type="checkbox"]) { box-sizing: border-box; width: 100%; min-height: 2.6rem; padding: .5rem .7rem;
    border: 1px solid #9ba4b0; border-radius: .35rem; background: #fff; color: #1f2530;
    font: inherit; }
input:not([type="radio"]):not([type="checkbox"]):focus { border-color: #1769aa; outline: 2px solid #1769aa; outline-offset: 1px; }
.visibility { flex: 0 0 13rem; margin: 0; padding: 0; border: 0; }
.visibility legend { margin-bottom: .3rem; font-weight: 600; }
.visibility-options { display: grid; grid-template-columns: 1fr 1fr; min-height: 2.6rem;
    overflow: hidden; border: 1px solid #9ba4b0; border-radius: .35rem; }
.visibility-option { position: relative; margin: 0; font-weight: 500; }
.visibility-option + .visibility-option { border-left: 1px solid #9ba4b0; }
.visibility-option input { position: absolute; opacity: 0; }
.visibility-option span { display: flex; height: 100%; align-items: center; justify-content: center;
    padding: 0 .75rem; cursor: pointer; }
.visibility-option input:checked + span { color: #fff; background: #176b43; }
.visibility-option input:focus-visible + span { outline: 2px solid #1769aa; outline-offset: -3px; }
button { min-height: 2.6rem; padding: .5rem 1rem; border: 1px solid #175b3a;
    border-radius: .35rem; color: #fff; background: #176b43; font: inherit;
    font-weight: 600; cursor: pointer; }
button:hover { background: #125635; }
.button-danger { border-color: #983434; background: #a43a3a; }
.button-danger:hover { background: #842e2e; }
.account-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.account-actions { display: flex; align-items: center; gap: .75rem; }
.account-bar form, .token-list form { display: block; }
.account-bar button, .token-list button { width: auto; margin: 0; }
.account-actions a { color: #075d8f; font-weight: 600; }
.confirm-delete { display: flex; align-items: center; gap: .35rem; margin: 0;
    color: #5b6472; font-size: .8rem; font-weight: 400; white-space: nowrap; }
.confirm-delete input { width: auto; min-height: auto; }
.token-result { user-select: all; }
.token-list { margin: 1rem 0 0; padding: 0; list-style: none; }
.token-list li { display: flex; justify-content: space-between; gap: 1rem; align-items: center;
    padding: .65rem 0; border-bottom: 1px solid #d5dae1; }
.hint { margin: .4rem 0 0; color: #5b6472; font-size: .9rem; }
.notice { margin: 1rem 0; padding: .65rem .8rem; border-left: .25rem solid; }
.notice-success { border-color: #1f7a4b; background: #edf8f1; color: #155735; }
.notice-error { border-color: #b33a3a; background: #fff0f0; color: #842828; }
table { width: 100%; border-collapse: collapse; }
.repositories { margin-top: 2.5rem; }
.repositories h2 { margin-top: 0; }
caption { padding-bottom: .5rem; color: #5b6472; text-align: left; }
th, td { padding: .6rem .5rem; border-bottom: 1px solid #d5dae1; text-align: left;
    vertical-align: top; }
th { font-weight: 600; white-space: nowrap; }
th.count, td.count { text-align: right; }
code { font-size: .95em; word-break: break-all;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { padding: .75rem 1rem; overflow-x: auto; border: 1px solid #d5dae1;
    border-radius: .4rem; background: #f6f7f9; }
.badge { display: inline-block; padding: .05rem .55rem; border: 1px solid currentColor;
    border-radius: 999px; font-size: .8rem; white-space: nowrap; }
.badge-push { color: #1f6f43; }
.badge-quiet { color: #5b6472; }
.empty { padding: 1.25rem; border: 1px dashed #b9c0ca; border-radius: .5rem;
    color: #5b6472; }
.empty p:last-child { margin-bottom: 0; }
footer { margin-top: 2.5rem; color: #5b6472; font-size: .9rem; }
@media (max-width: 42rem) {
    body { padding-top: 1.5rem; }
    .account-grid, .account .credentials { display: block; }
    .account form, .create form, .token-form { display: block; }
    .visibility { margin-top: .65rem; }
    button { width: 100%; margin-top: .65rem; }
    .account-bar button, .token-list button { width: auto; margin-top: 0; }
    table { display: block; overflow-x: auto; }
}
@media (prefers-color-scheme: dark) {
    body { color: #e6e9ef; background: #12161c; }
    .lead, .hint, caption, footer, .badge-quiet, .empty, .confirm-delete { color: #9aa4b2; }
    .account, .create, th, td, .token-list li { border-color: #2b323d; }
    input:not([type="radio"]):not([type="checkbox"]) { border-color: #596474; background: #1a1f27; color: #e6e9ef; }
    .visibility-options, .visibility-option + .visibility-option { border-color: #596474; }
    pre { border-color: #2b323d; background: #1a1f27; }
    .empty { border-color: #3a424f; }
    .notice-success { background: #152b20; color: #95dab1; }
    .notice-error { background: #331c1c; color: #f0abab; }
}
</style>
</head>
<body>
<h1>PHP Git 服务器</h1>
<p class="lead">通过 HTTP 发布下列 Git 仓库，支持 clone、fetch、pull，并可按仓库启用 push。</p>
HTML;
}

function home_send_authentication($url_base, $configuration, $notice) {
    if (!auth_is_enabled()) {
        return;
    }

    echo '<section class="account" aria-labelledby="account-title">' ."\n";
    echo '<h2 id="account-title">账号与 Access Token</h2>' ."\n";
    if ($notice !== NULL && isset($notice['token']) && is_string($notice['token'])) {
        echo '<pre class="token-result"><code>'.home_escape($notice['token']).'</code></pre>' ."\n";
    }

    $csrf_token = home_csrf_token($url_base, $configuration);
    if ($csrf_token === FALSE) {
        echo '<p class="notice notice-error" role="status">当前无法初始化安全表单。</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    $user = auth_session_user();
    $action = home_escape(home_page_url($url_base));
    $csrf_field = '<input type="hidden" name="csrf_token" value="'
        .home_escape($csrf_token).'">' ."\n";
    if ($user === NULL) {
        echo '<div class="account-grid">' ."\n";
        echo '<form method="post" action="'.$action.'">' ."\n".$csrf_field;
        echo '<input type="hidden" name="action" value="login">' ."\n";
        echo '<div class="credentials"><div><label for="login-username">用户名</label>' ."\n";
        echo '<input id="login-username" name="username" maxlength="64" autocomplete="username" required></div>' ."\n";
        echo '<div><label for="login-password">密码</label>' ."\n";
        echo '<input id="login-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="current-password" required></div></div>' ."\n";
        echo '<button type="submit">登录</button></form>' ."\n";

        if (auth_registration_is_enabled()) {
            echo '<form method="post" action="'.$action.'">' ."\n".$csrf_field;
            echo '<input type="hidden" name="action" value="register">' ."\n";
            echo '<div class="credentials"><div><label for="register-username">注册用户名</label>' ."\n";
            echo '<input id="register-username" name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9_-]" autocomplete="username" required></div>' ."\n";
            echo '<div><label for="register-password">密码</label>' ."\n";
            echo '<input id="register-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
            echo '<div><label for="register-password-confirmation">确认密码</label>' ."\n";
            echo '<input id="register-password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div></div>' ."\n";
            echo '<button type="submit">注册</button></form>' ."\n";
        }
        echo '</div>' ."\n";
        echo '<p class="hint">网页登录使用密码；Git clone、pull 和 push 使用用户名与 access token。</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    echo '<div class="account-bar"><p>当前账号：<strong>'.home_escape($user['username']).'</strong></p>' ."\n";
    echo '<div class="account-actions">';
    if (auth_user_is_administrator($user)) {
        echo '<a href="'.home_escape(rtrim((string) $url_base, '/').'/manage.php').'">管理</a>';
    }
    echo '<form method="post" action="'.$action.'">'.$csrf_field;
    echo '<input type="hidden" name="action" value="logout">' ."\n";
    echo '<button class="button-danger" type="submit">退出</button></form></div></div>' ."\n";
    echo '<form class="token-form" method="post" action="'.$action.'">'.$csrf_field;
    echo '<input type="hidden" name="action" value="create_token">' ."\n";
    echo '<div class="field"><label for="token-name">新 Token 名称</label>' ."\n";
    echo '<input id="token-name" name="token_name" maxlength="80" placeholder="工作电脑" required></div>' ."\n";
    echo '<button type="submit">创建 Token</button></form>' ."\n";

    $tokens = auth_list_access_tokens($user['id']);
    if ($tokens === FALSE) {
        echo '<p class="notice notice-error">当前无法读取 access token 列表。</p>' ."\n";
    } else if (!empty($tokens)) {
        echo '<ul class="token-list">' ."\n";
        foreach ($tokens as $token) {
            $last_used = $token['last_used_at'] === NULL ? '从未使用' : '最后使用 '.$token['last_used_at'];
            echo '<li><span><strong>'.home_escape($token['name']).'</strong><br>';
            echo '<span class="hint">创建于 '.home_escape($token['created_at']).'；'.home_escape($last_used).'</span></span>' ."\n";
            echo '<form method="post" action="'.$action.'">'.$csrf_field;
            echo '<input type="hidden" name="action" value="revoke_token">' ."\n";
            echo '<input type="hidden" name="token_id" value="'.home_escape($token['id']).'">' ."\n";
            echo '<button class="button-danger" type="submit">撤销</button></form></li>' ."\n";
        }
        echo '</ul>' ."\n";
    }
    echo '</section>' ."\n";
}

function home_send_creation($url_base, $configuration, $notice, $value, $visibility) {
    if (!home_managed_repositories_configured($configuration)) {
        return;
    }

    echo '<section class="create" aria-labelledby="create-title">' ."\n";
    echo '<h2 id="create-title">创建仓库</h2>' ."\n";
    if ($notice !== NULL) {
        echo '<p class="notice notice-'.home_escape($notice['type']).'" role="status">'
            .home_escape($notice['message']).'</p>' ."\n";
    }

    if (!home_creation_is_authorized($configuration)) {
        echo '<p class="lead">需要先登录应用账号，才能创建仓库。</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    $token = home_csrf_token($url_base, $configuration);
    if ($token === FALSE) {
        echo '<p class="notice notice-error" role="status">当前无法初始化安全表单。</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    echo '<form method="post" action="'.home_escape(home_page_url($url_base)).'">' ."\n";
    echo '<input type="hidden" name="csrf_token" value="'.home_escape($token).'">' ."\n";
    echo '<input type="hidden" name="action" value="create_repository">' ."\n";
    echo '<div class="field"><label for="repository-name">仓库名称</label>' ."\n";
    echo '<input id="repository-name" name="repository_name" type="text" maxlength="68" '
        .'pattern="[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9_-])?(?:\.git)?" '
        .'placeholder="project" value="'
        .home_escape($value).'" autocomplete="off" required>' ."\n";
    echo '<p class="hint">可使用字母、数字、点、短横线和下划线；<code>.git</code> 后缀可省略。</p></div>' ."\n";
    echo '<fieldset class="visibility"><legend>可见性</legend><div class="visibility-options">' ."\n";
    echo '<label class="visibility-option"><input name="visibility" type="radio" value="public"'
        .($visibility !== 'private' ? ' checked' : '').'><span>公开</span></label>' ."\n";
    echo '<label class="visibility-option"><input name="visibility" type="radio" value="private"'
        .($visibility === 'private' ? ' checked' : '').'><span>私有</span></label>' ."\n";
    echo '</div></fieldset>' ."\n";
    echo '<button type="submit">创建仓库</button>' ."\n";
    echo '</form>' ."\n";
    echo '</section>' ."\n";
}

function home_send_repository_table(
    $repositories,
    $prefix,
    $caption,
    $url_base,
    $configuration) {
    $user = auth_session_user();
    $csrf_token = $user === NULL ? FALSE : home_csrf_token($url_base, $configuration);
    echo '<table>'."\n";
    echo '<caption>'.home_escape($caption).'</caption>'."\n";
    echo '<thead><tr><th scope="col">仓库</th><th scope="col">所有者</th><th scope="col">克隆地址</th>'
        .'<th scope="col">默认分支</th><th scope="col" class="count">分支</th>'
        .'<th scope="col" class="count">标签</th><th scope="col">权限</th>'
        .'<th scope="col">操作</th></tr></thead>' ."\n";
    echo '<tbody>'."\n";

    foreach ($repositories as $repository) {
        $summary = home_repository_summary($repository);
        if ($summary['head'] !== NULL) {
            $head = '<code>'.home_escape($summary['head']).'</code>';
        } else if ($summary['branches'] === 0) {
            $head = '<span class="badge badge-quiet">空仓库</span>';
        } else {
            $head = '<span class="badge badge-quiet">未指向分支</span>';
        }
        $owner = $repository['options']['owner'] === NULL
            ? '<span class="badge badge-quiet">未设置</span>'
            : '<code>'.home_escape($repository['options']['owner']).'</code>';
        $access = $repository['options']['push'] && $repository['options']['owner'] !== NULL
            ? '<span class="badge badge-push">所有者可推送</span>'
            : '<span class="badge badge-quiet">只读</span>';

        echo '<tr>';
        echo '<td>'.home_escape(basename($repository['url'])).'</td>';
        echo '<td>'.$owner.'</td>';
        echo '<td><code>'.home_escape($prefix.$repository['url']).'</code></td>';
        echo '<td>'.$head.'</td>';
        echo '<td class="count">'.home_escape($summary['branches']).'</td>';
        echo '<td class="count">'.home_escape($summary['tags']).'</td>';
        echo '<td>'.$access.'</td>';
        echo '<td>';
        if ($user !== NULL && $csrf_token !== FALSE
            && repository_user_is_owner($repository, $user['username'])
            && home_repository_is_managed($repository, $configuration)) {
            echo '<form method="post" action="'.home_escape(home_page_url($url_base)).'">' ."\n";
            echo '<input type="hidden" name="csrf_token" value="'.home_escape($csrf_token).'">' ."\n";
            echo '<input type="hidden" name="action" value="delete_repository">' ."\n";
            echo '<input type="hidden" name="repository_name" value="'
                .home_escape(basename($repository['url'])).'">' ."\n";
            echo '<label class="confirm-delete"><input name="confirmation" type="checkbox" '
                .'value="delete" required> 确认删除</label>' ."\n";
            echo '<button class="button-danger" type="submit">删除</button></form>';
        } else {
            echo '<span class="badge badge-quiet">无</span>';
        }
        echo '</td>';
        echo '</tr>'."\n";
    }

    echo '</tbody>'."\n".'</table>'."\n";
}

function home_repository_is_managed($repository, $configuration) {
    return !empty($repository['options']['_managed']);
}

function home_send_repository_section(
    $id,
    $title,
    $repositories,
    $prefix,
    $empty_message,
    $url_base,
    $configuration) {
    echo '<section class="repositories" aria-labelledby="'.home_escape($id).'">' ."\n";
    echo '<h2 id="'.home_escape($id).'">'.home_escape($title).'</h2>' ."\n";
    if (empty($repositories)) {
        echo '<p class="empty">'.home_escape($empty_message).'</p>' ."\n";
    } else {
        home_send_repository_table(
            $repositories, $prefix, $title, $url_base, $configuration);
    }
    echo '</section>' ."\n";
}

function home_send_empty_notice() {
    echo <<<'HTML'
<div class="empty">
<p>当前没有可读取的仓库。</p>
<p>请复制 <code>config.php.sample</code> 为 <code>config.php</code>，在 <code>$repos</code>
中配置仓库路径，并确认仓库目录存在且 <code>read</code> 选项为 <code>TRUE</code>。</p>
</div>
HTML;
}

function home_send_usage($repositories, $prefix) {
    $example = empty($repositories)
        ? $prefix.'/project.git'
        : $prefix.$repositories[0]['url'];

    echo '<h2>使用方法</h2>'."\n";
    echo '<pre><code>git clone '.home_escape($example)."\n"
        .'git pull'."\n"
        .'git push origin main</code></pre>'."\n";
    echo '<p class="lead">push 需要仓库启用 <code>push</code> 选项；启用认证时还需要'
        .'使用账号用户名和 access token。</p>'."\n";
}

function home_render(
    $url_base,
    $definitions,
    $configuration,
    $notice=NULL,
    $value='',
    $visibility='public') {
    $show_private = get_authenticated_user() !== NULL;
    $repositories = home_visible_repositories($url_base, $definitions, $show_private);
    $prefix = home_clone_url_prefix();

    header_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');

    home_send_head();
    if ($notice !== NULL) {
        echo '<p class="notice notice-'.home_escape($notice['type']).'" role="status">'
            .home_escape($notice['message']).'</p>' ."\n";
    }
    home_send_authentication($url_base, $configuration, $notice);
    home_send_creation($url_base, $configuration, NULL, $value, $visibility);

    home_send_repository_section(
        'public-repositories',
        '公开仓库',
        $repositories['public'],
        $prefix,
        '当前没有公开仓库。',
        $url_base,
        $configuration);
    if ($show_private) {
        home_send_repository_section(
            'private-repositories',
            '私有仓库',
            $repositories['private'],
            $prefix,
            '当前没有私有仓库。',
            $url_base,
            $configuration);
    }

    home_send_usage(array_merge($repositories['public'], $repositories['private']), $prefix);

    echo '<footer>详细安装、配置和安全说明见 <code>usage.md</code>。</footer>'."\n";
    echo '</body>'."\n".'</html>'."\n";
}

function home_dispatch($url_base, $definitions, $configuration, $application) {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

    if ($method === 'POST') {
        $action = home_post_value('action');
        if ($action === 'delete_repository') {
            home_delete_repository($url_base, $definitions, $configuration);
        } else if ($action !== '' && $action !== 'create_repository') {
            home_handle_auth_action($url_base, $configuration, $action);
        }
        home_create_repository($url_base, $definitions, $configuration, $application);
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        send_status(405, 'Method Not Allowed');
        header('Allow: GET, HEAD, POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        die();
    }

    $notice = $method === 'GET' ? home_take_notice($url_base, $configuration) : NULL;
    home_render($url_base, $definitions, $configuration, $notice);
    die();
}

if (!isset($url_base)) {
    $url_base = '';
}

if (!isset($repos) || !is_array($repos)) {
    send_error(500, 'Internal Server Error', 'The repository configuration is invalid.');
}

if (!isset($git_executable)) {
    $git_executable = 'git';
}

if (!isset($auth)) {
    $auth = array();
}
if (!is_array($auth)) {
    send_error(500, 'Internal Server Error', 'The authentication configuration is invalid.');
}
auth_configure($auth, $url_base);

if (!isset($managed_repositories)) {
    $managed_repositories = array();
}
if (!is_array($managed_repositories)) {
    send_error(500, 'Internal Server Error', 'The managed repository configuration is invalid.');
}

$application = array(
    'git_executable' => $git_executable,
    'push_ref_rules' => array());
$services = array();

register_branch_operation($application);
register_tag_operation($application);
register_pull_services($services);
register_push_services($services);
register_clone_services($services);

$repos = merge_managed_repository_definitions($url_base, $repos, $managed_repositories);

$url_path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if ($url_path === FALSE) {
    $url_path = '';
}

if (home_page_is_request($url_base, $url_path)) {
    home_dispatch($url_base, $repos, $managed_repositories, $application);
}

$repository = find_configured_repository($url_base, $repos, $url_path);
if ($repository === FALSE) {
    send_error(404, 'Not Found', 'Repository not found.');
}

$request = create_http_request($url_path, $repository);
repository_require_private_access($repository, $request);
dispatch_service($services, $repository, $request, $application);
