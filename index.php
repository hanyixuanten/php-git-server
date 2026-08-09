<?php

require(__DIR__.'/config.php');
require(__DIR__.'/lib/http.php');
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

function home_creation_requires_auth($configuration) {
    return !isset($configuration['require_auth']) || $configuration['require_auth'];
}

function home_creation_is_authorized($configuration) {
    return !home_creation_requires_auth($configuration) || get_authenticated_user() !== NULL;
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

function home_repository_url_exists($url_base, $definitions, $name) {
    $expected_url = rtrim((string) $url_base, '/').'/'.$name;
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository !== FALSE && $repository['url'] === $expected_url) {
            return TRUE;
        }
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
        default:
            return array(500, 'error', '仓库创建失败，请检查服务器日志。');
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
    if (!request_content_type_is(get_request_header('Content-Type'), 'application/x-www-form-urlencoded')) {
        send_error(415, 'Unsupported Media Type', 'Expected a form-encoded request.');
    }

    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : '';
    $expected_token = home_csrf_token($url_base, $configuration);
    if ($expected_token === FALSE || !hash_equals($expected_token, $token)) {
        send_error(403, 'Forbidden', 'The form security token is invalid.');
    }

    $value = isset($_POST['repository_name']) && is_string($_POST['repository_name'])
        ? $_POST['repository_name'] : '';
    $name = normalize_managed_repository_name($value);
    if ($name !== FALSE && home_repository_url_exists($url_base, $definitions, $name)) {
        $result = array('status' => 'already_exists', 'name' => $name);
    } else {
        $result = git_service_create_managed_repository($application, $configuration, $value);
    }

    if ($result['status'] === 'created') {
        $_SESSION['home_notice'] = array(
            'type' => 'success',
            'message' => '仓库 '.$result['name'].' 已创建。');
        send_status(303, 'See Other');
        header('Location: '.home_page_url($url_base));
        die();
    }

    $notice = home_creation_result_notice($result);
    send_status($notice[0], $notice[0] === 409 ? 'Conflict' : ($notice[0] === 422
        ? 'Unprocessable Content' : ($notice[0] === 503 ? 'Service Unavailable' : 'Internal Server Error')));
    home_render(
        $url_base,
        $definitions,
        $configuration,
        array('type' => $notice[1], 'message' => $notice[2]),
        $value);
    die();
}

function home_repository_url_cmp($left, $right) {
    return strcmp($left['url'], $right['url']);
}

function home_visible_repositories($url_base, $definitions) {
    $repositories = array();

    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository === FALSE || !$repository['options']['read']) {
            continue;
        }

        $git_path = realpath($repository['path']);
        if ($git_path === FALSE || !is_dir($git_path)) {
            continue;
        }

        $repository['path'] = $git_path;
        $repositories[] = $repository;
    }

    usort($repositories, 'home_repository_url_cmp');
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
.create { margin: 1.75rem 0 2rem; padding: 1rem 0; border-top: 1px solid #d5dae1;
    border-bottom: 1px solid #d5dae1; }
.create h2 { margin: 0 0 .25rem; }
.create form { display: flex; gap: .6rem; align-items: end; }
.field { flex: 1 1 22rem; }
label { display: block; margin-bottom: .3rem; font-weight: 600; }
input { box-sizing: border-box; width: 100%; min-height: 2.6rem; padding: .5rem .7rem;
    border: 1px solid #9ba4b0; border-radius: .35rem; background: #fff; color: #1f2530;
    font: inherit; }
input:focus { border-color: #1769aa; outline: 2px solid #1769aa; outline-offset: 1px; }
button { min-height: 2.6rem; padding: .5rem 1rem; border: 1px solid #175b3a;
    border-radius: .35rem; color: #fff; background: #176b43; font: inherit;
    font-weight: 600; cursor: pointer; }
button:hover { background: #125635; }
.hint { margin: .4rem 0 0; color: #5b6472; font-size: .9rem; }
.notice { margin: 1rem 0; padding: .65rem .8rem; border-left: .25rem solid; }
.notice-success { border-color: #1f7a4b; background: #edf8f1; color: #155735; }
.notice-error { border-color: #b33a3a; background: #fff0f0; color: #842828; }
table { width: 100%; border-collapse: collapse; }
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
    .create form { display: block; }
    button { width: 100%; margin-top: .65rem; }
    table { display: block; overflow-x: auto; }
}
@media (prefers-color-scheme: dark) {
    body { color: #e6e9ef; background: #12161c; }
    .lead, .hint, caption, footer, .badge-quiet, .empty { color: #9aa4b2; }
    .create, th, td { border-color: #2b323d; }
    input { border-color: #596474; background: #1a1f27; color: #e6e9ef; }
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

function home_send_creation($url_base, $configuration, $notice, $value) {
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
        echo '<p class="lead">需要先通过 Web 服务器身份验证，才能创建仓库。</p>' ."\n";
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
    echo '<div class="field"><label for="repository-name">仓库名称</label>' ."\n";
    echo '<input id="repository-name" name="repository_name" type="text" maxlength="68" '
        .'pattern="[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9_-])?(?:\.git)?" '
        .'placeholder="project" value="'
        .home_escape($value).'" autocomplete="off" required>' ."\n";
    echo '<p class="hint">可使用字母、数字、点、短横线和下划线；<code>.git</code> 后缀可省略。</p></div>' ."\n";
    echo '<button type="submit">创建仓库</button>' ."\n";
    echo '</form>' ."\n";
    echo '</section>' ."\n";
}

function home_send_repository_table($repositories, $prefix) {
    echo '<table>'."\n";
    echo '<caption>已配置且允许读取的仓库</caption>'."\n";
    echo '<thead><tr><th scope="col">仓库</th><th scope="col">克隆地址</th>'
        .'<th scope="col">默认分支</th><th scope="col" class="count">分支</th>'
        .'<th scope="col" class="count">标签</th><th scope="col">权限</th></tr></thead>'."\n";
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
        $access = $repository['options']['push']
            ? '<span class="badge badge-push">读取 / 推送</span>'
            : '<span class="badge badge-quiet">只读</span>';

        echo '<tr>';
        echo '<td>'.home_escape(basename($repository['url'])).'</td>';
        echo '<td><code>'.home_escape($prefix.$repository['url']).'</code></td>';
        echo '<td>'.$head.'</td>';
        echo '<td class="count">'.home_escape($summary['branches']).'</td>';
        echo '<td class="count">'.home_escape($summary['tags']).'</td>';
        echo '<td>'.$access.'</td>';
        echo '</tr>'."\n";
    }

    echo '</tbody>'."\n".'</table>'."\n";
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
        .'由 Web 服务器完成身份验证。</p>'."\n";
}

function home_render(
    $url_base,
    $definitions,
    $configuration,
    $notice=NULL,
    $value='') {
    $repositories = home_visible_repositories($url_base, $definitions);
    $prefix = home_clone_url_prefix();

    header_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');

    home_send_head();
    home_send_creation($url_base, $configuration, $notice, $value);

    if (empty($repositories)) {
        home_send_empty_notice();
    } else {
        home_send_repository_table($repositories, $prefix);
    }

    home_send_usage($repositories, $prefix);

    echo '<footer>详细安装、配置和安全说明见 <code>usage.md</code>。</footer>'."\n";
    echo '</body>'."\n".'</html>'."\n";
}

function home_dispatch($url_base, $definitions, $configuration, $application) {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

    if ($method === 'POST') {
        home_create_repository($url_base, $definitions, $configuration, $application);
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        send_status(405, 'Method Not Allowed');
        header('Allow: GET, HEAD, POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        die();
    }

    $notice = $method === 'GET'
        && home_managed_repositories_configured($configuration)
        && home_creation_is_authorized($configuration)
        ? home_take_notice($url_base, $configuration) : NULL;
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
dispatch_service($services, $repository, $request, $application);
