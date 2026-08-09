<?php

require(__DIR__.'/config.php');
require(__DIR__.'/lib/http.php');
require(__DIR__.'/lib/repository.php');
require(__DIR__.'/lib/router.php');
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

function home_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
@media (prefers-color-scheme: dark) {
    body { color: #e6e9ef; background: #12161c; }
    .lead, caption, footer, .badge-quiet, .empty { color: #9aa4b2; }
    th, td { border-color: #2b323d; }
    pre { border-color: #2b323d; background: #1a1f27; }
    .empty { border-color: #3a424f; }
}
</style>
</head>
<body>
<h1>PHP Git 服务器</h1>
<p class="lead">通过 HTTP 发布下列 Git 仓库，支持 clone、fetch、pull，并可按仓库启用 push。</p>
HTML;
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

function home_render($url_base, $definitions) {
    $repositories = home_visible_repositories($url_base, $definitions);
    $prefix = home_clone_url_prefix();

    header_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');

    home_send_head();

    if (empty($repositories)) {
        home_send_empty_notice();
    } else {
        home_send_repository_table($repositories, $prefix);
    }

    home_send_usage($repositories, $prefix);

    echo '<footer>详细安装、配置和安全说明见 <code>usage.md</code>。</footer>'."\n";
    echo '</body>'."\n".'</html>'."\n";
}

function home_dispatch($url_base, $definitions) {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

    if ($method !== 'GET' && $method !== 'HEAD') {
        send_status(405, 'Method Not Allowed');
        header('Allow: GET, HEAD');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        die();
    }

    home_render($url_base, $definitions);
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

$application = array(
    'git_executable' => $git_executable,
    'push_ref_rules' => array());
$services = array();

register_branch_operation($application);
register_tag_operation($application);
register_pull_services($services);
register_push_services($services);
register_clone_services($services);

$url_path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if ($url_path === FALSE) {
    $url_path = '';
}

if (home_page_is_request($url_base, $url_path)) {
    home_dispatch($url_base, $repos);
}

$repository = find_configured_repository($url_base, $repos, $url_path);
if ($repository === FALSE) {
    send_error(404, 'Not Found', 'Repository not found.');
}

$request = create_http_request($url_path, $repository);
dispatch_service($services, $repository, $request, $application);
