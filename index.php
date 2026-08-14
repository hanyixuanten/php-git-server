<?php

if (!file_exists(__DIR__.'/config.php')) {
    require(__DIR__.'/lib/install_redirect.php');
    redirect_to_installer();
}

require(__DIR__.'/config.php');
require(__DIR__.'/lib/http.php');
require(__DIR__.'/lib/i18n.php');
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
        case 'registered':          return array('success', t('notice.registered'));
        case 'logged_in':           return array('success', t('notice.logged_in'));
        case 'registration_disabled': return array('error', t('notice.registration_disabled'));
        case 'invalid_username':    return array('error', t('notice.invalid_username'));
        case 'invalid_password':    return array('error', t('notice.invalid_password'));
        case 'password_mismatch':   return array('error', t('notice.password_mismatch'));
        case 'username_exists':     return array('error', t('notice.username_exists'));
        case 'invalid_credentials': return array('error', t('notice.invalid_credentials'));
        case 'invalid_token_name':  return array('error', t('notice.invalid_token_name'));
        case 'token_created':       return array('success', t('notice.token_created'));
        case 'token_revoked':       return array('success', t('notice.token_revoked'));
        case 'invalid_token':       return array('error', t('notice.invalid_token'));
        case 'session_unavailable': return array('error', t('notice.session_unavailable'));
        default:                    return array('error', t('notice.auth_database_unavailable'));
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
        home_set_notice($url_base, $configuration, 'success', t('notice.logged_out'));
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
        case 'invalid_name':        return array(422, 'error', t('notice.repository_invalid_name'));
        case 'already_exists':      return array(409, 'error', t('notice.repository_exists', array('name' => $name)));
        case 'create_busy':         return array(409, 'error', t('notice.repository_create_busy'));
        case 'root_unavailable':    return array(503, 'error', t('notice.repository_root_unavailable'));
        case 'metadata_unavailable': return array(503, 'error', t('notice.repository_metadata_unsaved'));
        default:                    return array(500, 'error', t('notice.repository_create_failed'));
    }
}

function home_deletion_result_notice($result) {
    $name = isset($result['name']) ? $result['name'] : '';
    switch ($result['status']) {
        case 'deleted':             return array(303, 'success', t('notice.repository_deleted', array('name' => $name)));
        case 'record_deleted':      return array(303, 'success', t('notice.repository_record_deleted', array('name' => $name)));
        case 'invalid_repository':  return array(422, 'error', t('notice.repository_invalid_name'));
        case 'forbidden':           return array(403, 'error', t('notice.repository_delete_forbidden'));
        case 'configured_repository': return array(403, 'error', t('notice.repository_configured_home'));
        case 'not_found':           return array(404, 'error', t('notice.repository_not_found'));
        case 'repository_busy':     return array(409, 'error', t('notice.repository_busy'));
        case 'root_unavailable':    return array(503, 'error', t('notice.repository_root_unavailable'));
        case 'metadata_unavailable': return array(503, 'error', t('notice.repository_metadata_unavailable'));
        case 'cleanup_failed':      return array(500, 'error', t('notice.repository_cleanup_failed'));
        case 'restore_failed':      return array(500, 'error', t('notice.repository_restore_failed'));
        default:                    return array(500, 'error', t('notice.repository_delete_failed'));
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
            $configuration,
            $value,
            $owner['id'],
            $visibility === 'private');
    }

    if ($result['status'] === 'created') {
        home_set_notice(
            $url_base, $configuration, 'success', t('notice.repository_created', array('name' => $result['name'])));
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

function home_send_head($url_base='') {
    $lang = home_escape(i18n_html_lang());
    $title = home_escape(t('home.title'));
    echo '<!DOCTYPE html>'."\n"
        .'<html lang="'.$lang.'">'."\n"
        .'<head>'."\n"
        .'<meta charset="utf-8">'."\n"
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
        .'<title>'.$title.'</title>'."\n";
    echo <<<'HTML'
<style>
:root {
    color-scheme: light dark;
    --canvas: #f3f7f5; --surface: #ffffff; --surface-soft: #f8fbf9;
    --ink: #17231d; --muted: #62726a; --line: #d9e4dd;
    --accent: #167447; --accent-strong: #0d5a34; --accent-soft: #e8f5ed;
    --link: #0d658f; --danger: #b23b3b; --danger-soft: #fff1f1;
    --shadow: 0 16px 42px rgba(25, 59, 42, .08);
}
* { box-sizing: border-box; }
html { background: var(--canvas); }
body {
    width: min(74rem, calc(100% - 2rem)); margin: 0 auto; padding: 3.5rem 0 3rem;
    color: var(--ink); background: transparent; line-height: 1.6;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", "Noto Sans CJK SC", sans-serif;
}
body::before {
    position: fixed; z-index: -1; inset: 0; content: "";
    background: radial-gradient(circle at 8% 0%, #dff3e7 0, transparent 27rem),
        radial-gradient(circle at 94% 8%, #e1f0f7 0, transparent 26rem), var(--canvas);
}
h1 { margin: 0; font-size: clamp(1.8rem, 4vw, 2.45rem); line-height: 1.15; letter-spacing: -.045em; }
h1::before { display: inline-grid; width: 2.35rem; height: 2.35rem; place-items: center; margin-right: .7rem;
    content: "⌘"; border-radius: .68rem; color: #fff; background: var(--accent); font-size: 1.45rem;
    vertical-align: -.18em; box-shadow: 0 7px 18px rgba(22, 116, 71, .24); }
h2 { margin: 0 0 .45rem; font-size: 1.12rem; line-height: 1.3; letter-spacing: -.015em; }
p { margin: 0 0 1rem; }
.lead { max-width: 48rem; margin: .6rem 0 0; color: var(--muted); font-size: 1.02rem; }
.language-switcher { display: inline-flex; margin-top: .85rem; padding: .25rem .5rem; border: 1px solid var(--line);
    border-radius: 999px; background: rgba(255,255,255,.62); font-size: .82rem; }
.language-switcher a, a { color: var(--link); }
.account, .create, .repositories, .empty {
    border: 1px solid var(--line); border-radius: .9rem; background: var(--surface); box-shadow: var(--shadow);
}
.account, .create { margin: 1.75rem 0; padding: 1.35rem; }
.account::before, .create::before, .repositories h2::before {
    display: inline-block; width: .48rem; height: .48rem; margin: 0 .48rem .09rem 0; content: "";
    border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 4px var(--accent-soft);
}
.account-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; }
.account form, .create form, .token-form { display: flex; gap: .65rem; align-items: end; }
.account .credentials { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; flex: 1; }
.field { flex: 1 1 22rem; }
label { display: block; margin-bottom: .35rem; color: #304239; font-size: .86rem; font-weight: 700; }
input:not([type="radio"]):not([type="checkbox"]) {
    width: 100%; min-height: 2.7rem; padding: .56rem .75rem; border: 1px solid #bbcbbf;
    border-radius: .52rem; color: var(--ink); background: #fff; font: inherit; transition: border-color .15s, box-shadow .15s;
}
input:not([type="radio"]):not([type="checkbox"]):focus { border-color: var(--accent); outline: 0;
    box-shadow: 0 0 0 3px rgba(22,116,71,.16); }
.visibility { flex: 0 0 13rem; margin: 0; padding: 0; border: 0; }
.visibility legend { margin-bottom: .35rem; color: #304239; font-size: .86rem; font-weight: 700; }
.visibility-options { display: grid; grid-template-columns: 1fr 1fr; min-height: 2.7rem; overflow: hidden;
    border: 1px solid #bbcbbf; border-radius: .52rem; background: #fff; }
.visibility-option { position: relative; margin: 0; font-weight: 600; }
.visibility-option + .visibility-option { border-left: 1px solid #bbcbbf; }
.visibility-option input { position: absolute; opacity: 0; }
.visibility-option span { display: flex; height: 100%; align-items: center; justify-content: center; padding: 0 .75rem; cursor: pointer; }
.visibility-option input:checked + span { color: #fff; background: var(--accent); }
.visibility-option input:focus-visible + span { outline: 3px solid #79c99d; outline-offset: -3px; }
button { min-height: 2.7rem; padding: .55rem 1rem; border: 1px solid var(--accent-strong); border-radius: .52rem;
    color: #fff; background: var(--accent); font: inherit; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 4px rgba(13,90,52,.15); transition: transform .15s, background .15s, box-shadow .15s; }
button:hover { background: var(--accent-strong); box-shadow: 0 5px 12px rgba(13,90,52,.2); transform: translateY(-1px); }
button:focus-visible { outline: 3px solid #79c99d; outline-offset: 2px; }
.button-danger { border-color: #922f2f; background: var(--danger); }
.button-danger:hover { background: #902d2d; }
.account-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.account-actions { display: flex; align-items: center; gap: .75rem; }
.account-bar form, .token-list form { display: block; }
.account-bar button, .token-list button { width: auto; margin: 0; }
.account-actions a { font-weight: 700; }
.confirm-delete { display: flex; align-items: center; gap: .35rem; margin: 0; color: var(--muted); font-size: .78rem; font-weight: 500; white-space: nowrap; }
.confirm-delete input { width: auto; min-height: auto; accent-color: var(--danger); }
.token-result { padding: .8rem 1rem; user-select: all; }
.token-list { margin: 1rem 0 0; padding: 0; list-style: none; }
.token-list li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .75rem 0; border-bottom: 1px solid var(--line); }
.hint { margin: .42rem 0 0; color: var(--muted); font-size: .87rem; }
.notice { margin: 1.1rem 0; padding: .78rem 1rem; border: 1px solid; border-left: .32rem solid; border-radius: .55rem; font-weight: 600; }
.notice-success { border-color: #a7d9b9; border-left-color: var(--accent); background: var(--accent-soft); color: #155735; }
.notice-error { border-color: #f1c5c5; border-left-color: var(--danger); background: var(--danger-soft); color: #852b2b; }
.repositories { margin-top: 1.4rem; overflow: hidden; }
.repositories h2 { margin: 0; padding: 1.1rem 1.3rem; border-bottom: 1px solid var(--line); background: var(--surface-soft); }
.repositories table { width: 100%; border-collapse: collapse; }
caption { padding: .9rem 1.3rem .2rem; color: var(--muted); text-align: left; }
th, td { padding: .78rem .8rem; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
th { color: #526259; background: #f5f9f6; font-size: .75rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }
tbody tr { transition: background .12s; }
tbody tr:hover { background: #f8fcf9; }
tbody tr:last-child td { border-bottom: 0; }
th.count, td.count { text-align: right; }
code { padding: .08rem .28rem; border-radius: .25rem; background: #edf3ef; font-size: .9em; word-break: break-all; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { padding: .85rem 1rem; overflow-x: auto; border: 1px solid var(--line); border-radius: .62rem; background: #f5f8f6; }
pre code { padding: 0; background: transparent; }
.badge { display: inline-block; padding: .12rem .55rem; border: 1px solid currentColor; border-radius: 999px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
.badge-push { color: var(--accent); background: var(--accent-soft); }
.badge-quiet { color: var(--muted); background: #f3f5f4; }
.empty { margin-top: 1.4rem; padding: 1.25rem; color: var(--muted); border-style: dashed; box-shadow: none; }
.repositories .empty { margin: 1.1rem; }
.empty p:last-child { margin-bottom: 0; }
footer { margin-top: 2rem; color: var(--muted); font-size: .86rem; text-align: center; }
@media (max-width: 42rem) {
    body { width: min(100% - 1.25rem, 74rem); padding-top: 2rem; }
    h1::before { display: none; }
    .account, .create { padding: 1.1rem; }
    .account-grid, .account .credentials { display: block; }
    .account form, .create form, .token-form { display: block; }
    .visibility { margin-top: .75rem; }
    button { width: 100%; margin-top: .75rem; }
    .account-bar button, .token-list button { width: auto; margin-top: 0; }
    .repositories { overflow-x: auto; }
    .repositories table { min-width: 50rem; }
}
@media (prefers-color-scheme: dark) {
    :root { --canvas: #101916; --surface: #17231e; --surface-soft: #1d2b25; --ink: #e5efe8;
        --muted: #a0b2a8; --line: #31443a; --accent: #42a76d; --accent-strong: #2d8953;
        --accent-soft: #173b27; --link: #75c4eb; --danger: #d05a5a; --danger-soft: #3a2020;
        --shadow: 0 16px 42px rgba(0,0,0,.2); }
    body::before { background: radial-gradient(circle at 8% 0%, #173e2a 0, transparent 27rem), radial-gradient(circle at 94% 8%, #163744 0, transparent 26rem), var(--canvas); }
    h1::before { color: #102319; }
    label, .visibility legend { color: #d5e2d9; }
    input:not([type="radio"]):not([type="checkbox"]), .visibility-options { border-color: #587064; background: #132019; color: var(--ink); }
    .visibility-option + .visibility-option { border-color: #587064; }
    code { background: #22332b; }
    pre, th { background: #1b2a23; }
    tbody tr:hover { background: #1b2a23; }
    .language-switcher { background: rgba(23,35,30,.62); }
    .notice-success { border-color: #356d4b; color: #a9e5bd; }
    .notice-error { border-color: #713838; color: #ffb6b6; }
    .badge-quiet { background: #26362e; }
}
</style>
</head>
<body>
HTML;
    echo '<h1>'.home_escape(t('home.title')).'</h1>'."\n";
    echo '<p class="lead">'.home_escape(t('home.lead')).'</p>'."\n";
    echo i18n_language_switcher(home_page_url($url_base))."\n";
}

function home_send_authentication($url_base, $configuration, $notice) {
    if (!auth_is_enabled()) {
        return;
    }

    echo '<section class="account" aria-labelledby="account-title">' ."\n";
    echo '<h2 id="account-title">'.home_escape(t('home.account_title')).'</h2>' ."\n";
    if ($notice !== NULL && isset($notice['token']) && is_string($notice['token'])) {
        echo '<pre class="token-result"><code>'.home_escape($notice['token']).'</code></pre>' ."\n";
    }

    $csrf_token = home_csrf_token($url_base, $configuration);
    if ($csrf_token === FALSE) {
        echo '<p class="notice notice-error" role="status">'.home_escape(t('home.form_unavailable')).'</p>' ."\n";
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
        echo '<div class="credentials"><div><label for="login-username">'.home_escape(t('home.username')).'</label>' ."\n";
        echo '<input id="login-username" name="username" maxlength="64" autocomplete="username" required></div>' ."\n";
        echo '<div><label for="login-password">'.home_escape(t('home.password')).'</label>' ."\n";
        echo '<input id="login-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="current-password" required></div></div>' ."\n";
        echo '<button type="submit">'.home_escape(t('home.login')).'</button></form>' ."\n";

        if (auth_registration_is_enabled()) {
            echo '<form method="post" action="'.$action.'">' ."\n".$csrf_field;
            echo '<input type="hidden" name="action" value="register">' ."\n";
            echo '<div class="credentials"><div><label for="register-username">'.home_escape(t('home.register_username')).'</label>' ."\n";
            echo '<input id="register-username" name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9_-]" autocomplete="username" required></div>' ."\n";
            echo '<div><label for="register-password">'.home_escape(t('home.password')).'</label>' ."\n";
            echo '<input id="register-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
            echo '<div><label for="register-password-confirmation">'.home_escape(t('home.password_confirmation')).'</label>' ."\n";
            echo '<input id="register-password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div></div>' ."\n";
            echo '<button type="submit">'.home_escape(t('home.register')).'</button></form>' ."\n";
        }
        echo '</div>' ."\n";
        echo '<p class="hint">'.home_escape(t('home.auth_hint')).'</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    echo '<div class="account-bar"><p>'.home_escape(t('home.current_account')).'：<strong>'
        .home_escape($user['username']).'</strong></p>' ."\n";
    echo '<div class="account-actions">';
    if (auth_user_is_administrator($user)) {
        echo '<a href="'.home_escape(rtrim((string) $url_base, '/').'/manage.php').'">'
            .home_escape(t('home.manage')).'</a>';
    }
    echo '<form method="post" action="'.$action.'">'.$csrf_field;
    echo '<input type="hidden" name="action" value="logout">' ."\n";
    echo '<button class="button-danger" type="submit">'.home_escape(t('home.logout')).'</button></form></div></div>' ."\n";
    echo '<form class="token-form" method="post" action="'.$action.'">'.$csrf_field;
    echo '<input type="hidden" name="action" value="create_token">' ."\n";
    echo '<div class="field"><label for="token-name">'.home_escape(t('home.token_name')).'</label>' ."\n";
    echo '<input id="token-name" name="token_name" maxlength="80" placeholder="'
        .home_escape(t('home.token_name_placeholder')).'" required></div>' ."\n";
    echo '<button type="submit">'.home_escape(t('home.create_token')).'</button></form>' ."\n";

    $tokens = auth_list_access_tokens($user['id']);
    if ($tokens === FALSE) {
        echo '<p class="notice notice-error">'.home_escape(t('home.token_list_unavailable')).'</p>' ."\n";
    } else if (!empty($tokens)) {
        echo '<ul class="token-list">' ."\n";
        foreach ($tokens as $token) {
            $last_used = $token['last_used_at'] === NULL
                ? t('home.token_never_used')
                : t('home.token_last_used', array('time' => $token['last_used_at']));
            $created = t('home.token_created_at', array('time' => $token['created_at']));
            echo '<li><span><strong>'.home_escape($token['name']).'</strong><br>';
            echo '<span class="hint">'.home_escape($created).' · '.home_escape($last_used).'</span></span>' ."\n";
            echo '<form method="post" action="'.$action.'">'.$csrf_field;
            echo '<input type="hidden" name="action" value="revoke_token">' ."\n";
            echo '<input type="hidden" name="token_id" value="'.home_escape($token['id']).'">' ."\n";
            echo '<button class="button-danger" type="submit">'.home_escape(t('home.revoke')).'</button></form></li>' ."\n";
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
    echo '<h2 id="create-title">'.home_escape(t('home.create_repository')).'</h2>' ."\n";
    if ($notice !== NULL) {
        echo '<p class="notice notice-'.home_escape($notice['type']).'" role="status">'
            .home_escape($notice['message']).'</p>' ."\n";
    }

    if (!home_creation_is_authorized($configuration)) {
        echo '<p class="lead">'.home_escape(t('home.create_login_required')).'</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    $token = home_csrf_token($url_base, $configuration);
    if ($token === FALSE) {
        echo '<p class="notice notice-error" role="status">'.home_escape(t('home.form_unavailable')).'</p>' ."\n";
        echo '</section>' ."\n";
        return;
    }

    echo '<form method="post" action="'.home_escape(home_page_url($url_base)).'">' ."\n";
    echo '<input type="hidden" name="csrf_token" value="'.home_escape($token).'">' ."\n";
    echo '<input type="hidden" name="action" value="create_repository">' ."\n";
    echo '<div class="field"><label for="repository-name">'.home_escape(t('home.repository_name')).'</label>' ."\n";
    echo '<input id="repository-name" name="repository_name" type="text" maxlength="68" '
        .'pattern="[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9_-])?(?:\.git)?" '
        .'placeholder="project" value="'
        .home_escape($value).'" autocomplete="off" required>' ."\n";
    echo '<p class="hint">'.t('home.repository_name_hint').'</p></div>' ."\n";
    echo '<fieldset class="visibility"><legend>'.home_escape(t('home.visibility')).'</legend><div class="visibility-options">' ."\n";
    echo '<label class="visibility-option"><input name="visibility" type="radio" value="public"'
        .($visibility !== 'private' ? ' checked' : '').'><span>'.home_escape(t('home.public')).'</span></label>' ."\n";
    echo '<label class="visibility-option"><input name="visibility" type="radio" value="private"'
        .($visibility === 'private' ? ' checked' : '').'><span>'.home_escape(t('home.private')).'</span></label>' ."\n";
    echo '</div></fieldset>' ."\n";
    echo '<button type="submit">'.home_escape(t('home.create_repository')).'</button>' ."\n";
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
    echo '<thead><tr><th scope="col">'.home_escape(t('home.th_repository')).'</th>'
        .'<th scope="col">'.home_escape(t('home.th_owner')).'</th>'
        .'<th scope="col">'.home_escape(t('home.th_clone_url')).'</th>'
        .'<th scope="col">'.home_escape(t('home.th_default_branch')).'</th>'
        .'<th scope="col" class="count">'.home_escape(t('home.th_branches')).'</th>'
        .'<th scope="col" class="count">'.home_escape(t('home.th_tags')).'</th>'
        .'<th scope="col">'.home_escape(t('home.th_access')).'</th>'
        .'<th scope="col">'.home_escape(t('home.th_actions')).'</th></tr></thead>' ."\n";
    echo '<tbody>'."\n";

    foreach ($repositories as $repository) {
        $summary = home_repository_summary($repository);
        if ($summary['head'] !== NULL) {
            $head = '<code>'.home_escape($summary['head']).'</code>';
        } else if ($summary['branches'] === 0) {
            $head = '<span class="badge badge-quiet">'.home_escape(t('home.badge_empty')).'</span>';
        } else {
            $head = '<span class="badge badge-quiet">'.home_escape(t('home.badge_no_branch')).'</span>';
        }
        $owner = $repository['options']['owner'] === NULL
            ? '<span class="badge badge-quiet">'.home_escape(t('home.badge_unset')).'</span>'
            : '<code>'.home_escape($repository['options']['owner']).'</code>';
        $access = $repository['options']['push'] && $repository['options']['owner'] !== NULL
            ? '<span class="badge badge-push">'.home_escape(t('home.badge_owner_push')).'</span>'
            : '<span class="badge badge-quiet">'.home_escape(t('home.badge_read_only')).'</span>';

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
                .'value="delete" required> '.home_escape(t('home.confirm_delete')).'</label>' ."\n";
            echo '<button class="button-danger" type="submit">'.home_escape(t('home.delete')).'</button></form>';
        } else {
            echo '<span class="badge badge-quiet">'.home_escape(t('home.badge_none')).'</span>';
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
    echo '<div class="empty">'."\n";
    echo '<p>'.home_escape(t('home.empty_title')).'</p>'."\n";
    echo '<p>'.t('home.empty_body').'</p>'."\n";
    echo '</div>'."\n";
}

function home_send_usage($repositories, $prefix) {
    $example = empty($repositories)
        ? $prefix.'/project.git'
        : $prefix.$repositories[0]['url'];

    echo '<h2>'.home_escape(t('home.usage_title')).'</h2>'."\n";
    echo '<pre><code>git clone '.home_escape($example)."\n"
        .'git pull'."\n"
        .'git push origin main</code></pre>'."\n";
    echo '<p class="lead">'.t('home.usage_hint').'</p>'."\n";
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

    home_send_head($url_base);
    if ($notice !== NULL) {
        echo '<p class="notice notice-'.home_escape($notice['type']).'" role="status">'
            .home_escape($notice['message']).'</p>' ."\n";
    }
    home_send_authentication($url_base, $configuration, $notice);
    home_send_creation($url_base, $configuration, NULL, $value, $visibility);

    home_send_repository_section(
        'public-repositories',
        t('home.public_repositories'),
        $repositories['public'],
        $prefix,
        t('home.no_public_repositories'),
        $url_base,
        $configuration);
    if ($show_private) {
        home_send_repository_section(
            'private-repositories',
            t('home.private_repositories'),
            $repositories['private'],
            $prefix,
            t('home.no_private_repositories'),
            $url_base,
            $configuration);
    }

    home_send_usage(array_merge($repositories['public'], $repositories['private']), $prefix);

    echo '<footer>'.t('home.footer').'</footer>'."\n";
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


if (!isset($auth)) {
    $auth = array();
}
if (!is_array($auth)) {
    send_error(500, 'Internal Server Error', 'The authentication configuration is invalid.');
}
i18n_configure(isset($language) ? $language : NULL, $url_base);
auth_configure($auth, $url_base);

if (!isset($managed_repositories)) {
    $managed_repositories = array();
}
if (!is_array($managed_repositories)) {
    send_error(500, 'Internal Server Error', 'The managed repository configuration is invalid.');
}

$application = array('push_ref_rules' => array());
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
