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

function manage_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function manage_home_url($url_base) {
    $base = rtrim((string) $url_base, '/');
    return $base === '' ? '/' : $base.'/';
}

function manage_page_url($url_base) {
    return rtrim((string) $url_base, '/').'/manage.php';
}

function manage_post_value($name) {
    return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
}

function manage_csrf_token() {
    if (!auth_start_session()) {
        return FALSE;
    }

    if (!isset($_SESSION['manage_csrf_token'])
        || !is_string($_SESSION['manage_csrf_token'])
        || strlen($_SESSION['manage_csrf_token']) !== 64) {
        try {
            $_SESSION['manage_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return FALSE;
        }
    }

    return $_SESSION['manage_csrf_token'];
}

function manage_require_csrf() {
    if (!request_content_type_is(
        get_request_header('Content-Type'),
        'application/x-www-form-urlencoded')) {
        send_error(415, 'Unsupported Media Type', 'Expected a form-encoded request.');
    }

    $expected = manage_csrf_token();
    if ($expected === FALSE
        || !hash_equals($expected, manage_post_value('csrf_token'))) {
        send_error(403, 'Forbidden', 'The form security token is invalid.');
    }
}

function manage_set_notice($type, $message) {
    if (!auth_start_session()) {
        return FALSE;
    }

    $_SESSION['manage_notice'] = array(
        'type' => $type,
        'message' => $message);
    return TRUE;
}

function manage_take_notice() {
    if (!auth_start_session() || !isset($_SESSION['manage_notice'])) {
        return NULL;
    }

    $notice = $_SESSION['manage_notice'];
    unset($_SESSION['manage_notice']);
    return is_array($notice) ? $notice : NULL;
}

function manage_redirect($url_base) {
    send_status(303, 'See Other');
    header('Location: '.manage_page_url($url_base));
    die();
}

function manage_result_notice($result) {
    $name = isset($result['name']) ? $result['name'] : '';
    switch ($result['status']) {
        case 'user_created': return array('success', t('manage.user_created', array('username' => $result['username'])));
        case 'user_updated': return array('success', t('manage.user_updated'));
        case 'tokens_revoked': return array('success', t('manage.tokens_revoked', array('count' => $result['count'])));
        case 'password_updated': return array('success', t('manage.password_updated'));
        case 'user_deleted': return array('success', t('manage.user_deleted'));
        case 'repository_updated': return array('success', t('manage.repository_updated'));
        case 'deleted': return array('success', t('manage.repository_deleted', array('name' => $name)));
        case 'record_deleted': return array('success', t('manage.repository_record_deleted', array('name' => $name)));
        case 'invalid_username': return array('error', t('notice.invalid_username'));
        case 'invalid_password': return array('error', t('notice.invalid_password'));
        case 'password_mismatch': return array('error', t('notice.password_mismatch'));
        case 'username_exists': return array('error', t('manage.username_exists'));
        case 'invalid_user': return array('error', t('manage.invalid_user'));
        case 'invalid_owner': return array('error', t('manage.invalid_owner'));
        case 'invalid_repository': return array('error', t('manage.invalid_repository'));
        case 'administrator_protected': return array('error', t('manage.administrator_protected'));
        case 'self_protected': return array('error', t('manage.self_protected'));
        case 'user_owns_repositories': return array('error', t('manage.user_owns_repositories'));
        case 'configured_owner': return array('error', t('manage.configured_owner'));
        case 'configured_repository': return array('error', t('manage.configured_repository'));
        case 'confirmation_required': return array('error', t('manage.confirmation_required'));
        case 'forbidden': return array('error', t('manage.forbidden'));
        case 'not_found': return array('error', t('manage.not_found'));
        case 'repository_busy': return array('error', t('manage.repository_busy'));
        case 'root_unavailable': return array('error', t('manage.root_unavailable'));
        case 'cleanup_failed': return array('error', t('manage.cleanup_failed'));
        case 'restore_failed': return array('error', t('manage.restore_failed'));
        case 'metadata_unavailable':
        case 'database_unavailable': return array('error', t('manage.database_unavailable'));
        default: return array('error', t('manage.action_failed'));
    }
}

function manage_target_user($value) {
    $user = auth_find_user_by_id($value);
    if ($user === FALSE) {
        return array('status' => 'database_unavailable');
    }
    if ($user === NULL) {
        return array('status' => 'invalid_user');
    }

    return array('status' => 'found', 'user' => $user);
}

/* A deleted username can be registered again, so an account still named as a
   static $repos owner must keep its push identity reserved. */
function manage_user_owns_configured_repository($definitions, $url_base, $username) {
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository === FALSE || !empty($repository['options']['_managed'])) {
            continue;
        }

        if (repository_user_is_owner($repository, $username)) {
            return TRUE;
        }
    }

    return FALSE;
}

function manage_handle_action($url_base, $definitions, $configuration, $administrator) {
    manage_require_csrf();
    $action = manage_post_value('action');

    if ($action === 'create_user') {
        $result = auth_create_user(
            manage_post_value('username'),
            manage_post_value('password'),
            manage_post_value('password_confirmation'));
    } else if ($action === 'set_user_status') {
        $target = manage_target_user(manage_post_value('user_id'));
        if ($target['status'] !== 'found') {
            $result = $target;
        } else if ((int) $target['user']['id'] === (int) $administrator['id']
            && manage_post_value('active') !== '1') {
            $result = array('status' => 'self_protected');
        } else {
            $active = manage_post_value('active');
            $result = ($active === '0' || $active === '1')
                ? auth_set_user_active($target['user']['id'], $active === '1')
                : array('status' => 'invalid_user');
        }
    } else if ($action === 'revoke_user_tokens') {
        $result = auth_revoke_user_access_tokens(manage_post_value('user_id'));
    } else if ($action === 'reset_user_password') {
        $result = auth_set_user_password(
            manage_post_value('user_id'),
            manage_post_value('password'),
            manage_post_value('password_confirmation'));
    } else if ($action === 'delete_user') {
        $target = manage_target_user(manage_post_value('user_id'));
        if ($target['status'] !== 'found') {
            $result = $target;
        } else if ((int) $target['user']['id'] === (int) $administrator['id']) {
            $result = array('status' => 'self_protected');
        } else if (!hash_equals(
            $target['user']['username'],
            manage_post_value('confirmation'))) {
            $result = array('status' => 'confirmation_required');
        } else if (manage_user_owns_configured_repository(
            $definitions, $url_base, $target['user']['username'])) {
            $result = array('status' => 'configured_owner');
        } else {
            $result = auth_delete_user($target['user']['id']);
        }
    } else if ($action === 'update_repository') {
        $visibility = manage_post_value('visibility');
        $result = ($visibility === 'public' || $visibility === 'private')
            ? auth_update_repository_metadata(
                manage_post_value('repository_id'),
                manage_post_value('owner_user_id'),
                $visibility === 'private')
            : array('status' => 'invalid_repository');
    } else if ($action === 'delete_repository') {
        $name = normalize_managed_repository_name(
            manage_post_value('repository_name'));
        if ($name === FALSE) {
            $result = array('status' => 'invalid_repository');
        } else if (!hash_equals($name, manage_post_value('confirmation'))) {
            $result = array('status' => 'confirmation_required');
        } else {
            $result = delete_managed_repository(
                $configuration,
                $definitions,
                $url_base,
                $name,
                $administrator['id'],
                TRUE);
        }
    } else {
        send_error(400, 'Bad Request', 'Unknown management action.');
    }

    $notice = manage_result_notice($result);
    manage_set_notice($notice[0], $notice[1]);
    manage_redirect($url_base);
}

function manage_send_head($url_base, $administrator) {
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="__LANG__">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITLE__</title>
<style>
:root { color-scheme: light dark; --ink: #20252b; --muted: #65707d; --line: #d7dce2;
    --surface: #f5f7f8; --accent: #176b43; --danger: #a43a3a; --warning: #8a6116; }
* { box-sizing: border-box; }
body { margin: 0; color: var(--ink); background: #fff;
    font-family: "IBM Plex Sans", "Noto Sans CJK SC", sans-serif; line-height: 1.5; }
header { border-bottom: 1px solid var(--line); background: var(--surface); }
.header-inner, main { width: min(92rem, calc(100% - 2rem)); margin: 0 auto; }
.header-inner { display: flex; min-height: 4.5rem; align-items: center;
    justify-content: space-between; gap: 1rem; }
h1 { margin: 0; font-family: "IBM Plex Mono", "Noto Sans Mono CJK SC", monospace;
    font-size: 1.25rem; letter-spacing: 0; }
.identity { margin: .15rem 0 0; color: var(--muted); font-size: .9rem; }
nav { display: flex; align-items: center; gap: .75rem; }
a { color: #075d8f; }
main { padding: 2rem 0 4rem; }
section { padding: 0 0 2.5rem; }
section + section { padding-top: 2rem; border-top: 1px solid var(--line); }
h2 { margin: 0 0 .3rem; font-size: 1.15rem; }
.section-lead { margin: 0 0 1.25rem; color: var(--muted); }
.notice { margin: 0 0 1.5rem; padding: .7rem .85rem; border-left: .25rem solid; }
.notice-success { border-color: var(--accent); background: #edf8f1; color: #155735; }
.notice-error { border-color: var(--danger); background: #fff0f0; color: #842828; }
.create-user { display: grid; grid-template-columns: minmax(12rem, 1fr) minmax(12rem, 1fr)
    minmax(12rem, 1fr) auto; gap: .75rem; align-items: end; max-width: 70rem; }
label, .label { display: block; margin-bottom: .25rem; font-weight: 600; font-size: .88rem; }
input:not([type="checkbox"]), select, button { min-height: 2.35rem; border-radius: .3rem; font: inherit; }
input:not([type="checkbox"]), select { width: 100%; padding: .4rem .55rem; border: 1px solid #9ba4b0;
    color: var(--ink); background: #fff; }
input:not([type="checkbox"]):focus, select:focus { outline: 2px solid #1769aa; outline-offset: 1px; }
button { padding: .4rem .75rem; border: 1px solid #175b3a; color: #fff;
    background: var(--accent); font-weight: 600; cursor: pointer; white-space: nowrap; }
button:hover { background: #125635; }
.button-danger { border-color: #8d2d2d; background: var(--danger); }
.button-danger:hover { background: #842e2e; }
.button-muted { border-color: #56616d; background: #626e7b; }
.button-muted:hover { background: #505a65; }
.table-wrap { overflow-x: auto; border-top: 1px solid var(--line); }
table { width: 100%; border-collapse: collapse; font-size: .92rem; }
th, td { padding: .65rem .55rem; border-bottom: 1px solid var(--line);
    text-align: left; vertical-align: top; }
th { color: #4c5662; background: var(--surface); font-size: .8rem;
    text-transform: uppercase; white-space: nowrap; }
code { font-family: "IBM Plex Mono", "Noto Sans Mono CJK SC", monospace;
    font-size: .92em; word-break: break-all; }
.badge { display: inline-block; padding: .08rem .45rem; border: 1px solid currentColor;
    border-radius: 999px; font-size: .76rem; white-space: nowrap; }
.badge-good { color: #176b43; }
.badge-muted { color: var(--muted); }
.badge-warning { color: var(--warning); }
.stack { display: grid; gap: .45rem; min-width: 9rem; }
.inline-form { display: flex; align-items: center; gap: .4rem; }
.inline-form select { min-width: 8rem; }
.confirm { display: flex; align-items: center; gap: .4rem; margin: 0; color: var(--muted);
    font-weight: 400; font-size: .8rem; }
.confirm input { width: auto; min-height: auto; }
details { min-width: 15rem; }
summary { color: #075d8f; cursor: pointer; }
details form { display: grid; gap: .45rem; margin-top: .55rem; }
.empty { margin: 0; padding: 1rem; border: 1px dashed #aeb6c0; color: var(--muted); }
@media (max-width: 54rem) {
    .header-inner { align-items: flex-start; flex-direction: column; padding: .9rem 0; }
    .create-user { grid-template-columns: 1fr; }
}
@media (prefers-color-scheme: dark) {
    :root { --ink: #e6e9ef; --muted: #9aa4b2; --line: #303844;
        --surface: #191e25; --accent: #237a51; --danger: #a94444; --warning: #d0a24b; }
    body { background: #11151a; }
    input:not([type="checkbox"]), select { border-color: #596474; background: #1a1f27; color: var(--ink); }
    a, summary { color: #76bde5; }
    th { color: #b5bdc8; }
    .notice-success { background: #152b20; color: #95dab1; }
    .notice-error { background: #331c1c; color: #f0abab; }
}
</style>
</head>
<body>
HTML;
    echo '<header><div class="header-inner"><div><h1>PHP Git 服务器 / 管理</h1>' ."\n";
    echo '<p class="identity">管理员：<strong>'.manage_escape($administrator['username'])
        .'</strong></p></div>' ."\n";
    echo '<nav><a href="'.manage_escape(manage_home_url($url_base)).'">返回仓库首页</a> '
        .i18n_language_switcher(manage_page_url($url_base));
    echo '</nav></div></header>' ."\n";
}

function manage_send_create_user($url_base, $csrf_token) {
    echo '<section aria-labelledby="create-user-title"><h2 id="create-user-title">创建用户</h2>' ."\n";
    echo '<p class="section-lead">管理员创建的账号会立即启用。</p>' ."\n";
    echo '<form class="create-user" method="post" action="'
        .manage_escape(manage_page_url($url_base)).'">' ."\n";
    echo '<input type="hidden" name="csrf_token" value="'.manage_escape($csrf_token).'">' ."\n";
    echo '<input type="hidden" name="action" value="create_user">' ."\n";
    echo '<div><label for="new-username">用户名</label><input id="new-username" name="username" '
        .'minlength="3" maxlength="64" autocomplete="off" required></div>' ."\n";
    echo '<div><label for="new-password">初始密码</label><input id="new-password" name="password" '
        .'type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
    echo '<div><label for="new-password-confirmation">确认密码</label>'
        .'<input id="new-password-confirmation" name="password_confirmation" type="password" '
        .'minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
    echo '<button type="submit">创建用户</button></form></section>' ."\n";
}

function manage_hidden_fields($csrf_token, $action, $user_id=NULL) {
    $fields = '<input type="hidden" name="csrf_token" value="'
        .manage_escape($csrf_token).'">' ."\n";
    $fields .= '<input type="hidden" name="action" value="'
        .manage_escape($action).'">' ."\n";
    if ($user_id !== NULL) {
        $fields .= '<input type="hidden" name="user_id" value="'
            .manage_escape($user_id).'">' ."\n";
    }
    return $fields;
}

function manage_send_users($url_base, $csrf_token, $users, $administrator) {
    echo '<section aria-labelledby="users-title"><h2 id="users-title">用户</h2>' ."\n";
    echo '<p class="section-lead">停用会立即阻止网页登录和 Access Token 认证。删除用户前必须先处理其仓库。</p>' ."\n";
    if ($users === FALSE) {
        echo '<p class="notice notice-error">当前无法读取用户列表。</p></section>' ."\n";
        return;
    }
    if (empty($users)) {
        echo '<p class="empty">当前没有用户。</p></section>' ."\n";
        return;
    }

    $action_url = manage_escape(manage_page_url($url_base));
    echo '<div class="table-wrap"><table><thead><tr><th>用户</th><th>状态</th><th>仓库</th>'
        .'<th>有效 Token</th><th>创建时间</th><th>账号操作</th><th>密码</th></tr></thead><tbody>' ."\n";
    foreach ($users as $user) {
        $active = (int) $user['is_active'] === 1;
        $is_administrator = auth_user_is_administrator($user);
        $is_self = (int) $user['id'] === (int) $administrator['id'];
        echo '<tr><td><code>'.manage_escape($user['username']).'</code><br>';
        if ($is_administrator) {
            echo '<span class="badge badge-good">管理员</span>';
        } else if ($is_self) {
            echo '<span class="badge badge-muted">当前账号</span>';
        }
        echo '</td><td><span class="badge '.($active ? 'badge-good' : 'badge-warning').'">'
            .($active ? '启用' : '停用').'</span></td>';
        echo '<td>'.manage_escape($user['repository_count']).'</td>';
        echo '<td>'.manage_escape($user['active_token_count']).'</td>';
        echo '<td>'.manage_escape($user['created_at']).'</td><td><div class="stack">';

        if ((!$is_administrator || !$active) && !($is_self && $active)) {
            echo '<form method="post" action="'.$action_url.'">'
                .manage_hidden_fields($csrf_token, 'set_user_status', $user['id']);
            echo '<input type="hidden" name="active" value="'.($active ? '0' : '1').'">';
            echo '<button class="button-muted" type="submit">'.($active ? '停用' : '启用').'</button></form>';
        }

        echo '<form method="post" action="'.$action_url.'">'
            .manage_hidden_fields($csrf_token, 'revoke_user_tokens', $user['id']);
        echo '<button class="button-muted" type="submit">撤销全部 Token</button></form>';

        if (!$is_administrator && !$is_self && (int) $user['repository_count'] === 0) {
            echo '<form class="stack" method="post" action="'.$action_url.'">'
                .manage_hidden_fields($csrf_token, 'delete_user', $user['id']);
            echo '<label class="confirm"><input name="confirmation" type="checkbox" value="'
                .manage_escape($user['username']).'" required> 确认删除账号</label>';
            echo '<button class="button-danger" type="submit">删除用户</button></form>';
        }
        echo '</div></td><td><details><summary>重置密码</summary>';
        echo '<form method="post" action="'.$action_url.'">'
            .manage_hidden_fields($csrf_token, 'reset_user_password', $user['id']);
        echo '<input name="password" type="password" minlength="8" maxlength="72" '
            .'autocomplete="new-password" aria-label="新密码" placeholder="新密码" required>';
        echo '<input name="password_confirmation" type="password" minlength="8" maxlength="72" '
            .'autocomplete="new-password" aria-label="确认新密码" placeholder="确认新密码" required>';
        echo '<button type="submit">保存新密码</button></form></details></td></tr>' ."\n";
    }
    echo '</tbody></table></div></section>' ."\n";
}

function manage_repository_state($repository, $configuration, $definitions, $url_base) {
    $root = managed_repository_root($configuration);
    if ($root === FALSE) {
        return array('badge-warning', '根目录不可用', FALSE);
    }

    $path = $root.DIRECTORY_SEPARATOR.$repository['repository_name'];
    if (managed_repository_path_is_configured($definitions, $url_base, $path)) {
        return array('badge-warning', '静态配置', FALSE);
    }
    if (!managed_repository_is_bare($path)) {
        return array('badge-warning', '仅记录', TRUE);
    }
    if ((int) $repository['is_ready'] !== 1) {
        return array('badge-warning', '未完成', TRUE);
    }

    return array('badge-good', '可用', TRUE);
}

function manage_send_repositories(
    $url_base,
    $csrf_token,
    $repositories,
    $users,
    $configuration,
    $definitions) {
    echo '<section aria-labelledby="repositories-title"><h2 id="repositories-title">托管仓库</h2>' ."\n";
    echo '<p class="section-lead">此处操作数据库记录与 <code>repos</code> 目录中的受管 bare 仓库。</p>' ."\n";
    if ($repositories === FALSE) {
        echo '<p class="notice notice-error">当前无法读取托管仓库列表。</p></section>' ."\n";
        return;
    }
    if (empty($repositories)) {
        echo '<p class="empty">当前没有托管仓库。</p></section>' ."\n";
        return;
    }

    $action_url = manage_escape(manage_page_url($url_base));
    echo '<div class="table-wrap"><table><thead><tr><th>仓库</th><th>状态</th><th>所有者与可见性</th>'
        .'<th>创建时间</th><th>删除</th></tr></thead><tbody>' ."\n";
    foreach ($repositories as $repository) {
        $state = manage_repository_state(
            $repository, $configuration, $definitions, $url_base);
        echo '<tr><td><code>'.manage_escape($repository['repository_name']).'</code></td>';
        echo '<td><span class="badge '.manage_escape($state[0]).'">'
            .manage_escape($state[1]).'</span></td><td>';
        echo '<form class="inline-form" method="post" action="'.$action_url.'">';
        echo '<input type="hidden" name="csrf_token" value="'.manage_escape($csrf_token).'">';
        echo '<input type="hidden" name="action" value="update_repository">';
        echo '<input type="hidden" name="repository_id" value="'
            .manage_escape($repository['id']).'">';
        echo '<select name="owner_user_id" aria-label="仓库所有者">';
        foreach ($users === FALSE ? array() : $users as $user) {
            if ((int) $user['is_active'] !== 1
                && (int) $user['id'] !== (int) $repository['owner_user_id']) {
                continue;
            }
            echo '<option value="'.manage_escape($user['id']).'"'
                .((int) $user['id'] === (int) $repository['owner_user_id'] ? ' selected' : '').'>'
                .manage_escape($user['username']).((int) $user['is_active'] === 1 ? '' : '（已停用）')
                .'</option>';
        }
        echo '</select><select name="visibility" aria-label="仓库可见性">';
        echo '<option value="public"'.((int) $repository['is_private'] === 0 ? ' selected' : '')
            .'>公开</option>';
        echo '<option value="private"'.((int) $repository['is_private'] === 1 ? ' selected' : '')
            .'>私有</option></select><button type="submit">保存</button></form></td>';
        echo '<td>'.manage_escape($repository['created_at']).'</td><td>';
        if (!$state[2]) {
            echo '<span class="badge badge-muted">不可删除</span>';
        } else {
            echo '<form class="stack" method="post" action="'.$action_url.'">';
            echo '<input type="hidden" name="csrf_token" value="'.manage_escape($csrf_token).'">';
            echo '<input type="hidden" name="action" value="delete_repository">';
            echo '<input type="hidden" name="repository_name" value="'
                .manage_escape($repository['repository_name']).'">';
            echo '<label class="confirm"><input name="confirmation" type="checkbox" value="'
                .manage_escape($repository['repository_name']).'" required> 确认永久删除</label>';
            echo '<button class="button-danger" type="submit">删除仓库</button></form>';
        }
        echo '</td></tr>' ."\n";
    }
    echo '</tbody></table></div></section>' ."\n";
}

function manage_send_configured_repositories($url_base, $definitions) {
    echo '<section aria-labelledby="configured-title"><h2 id="configured-title">配置仓库</h2>' ."\n";
    echo '<p class="section-lead">这些仓库来自 <code>config.php</code>，管理界面只读显示，不修改配置或文件。</p>' ."\n";
    $repositories = array();
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository !== FALSE) {
            $repositories[] = $repository;
        }
    }
    if (empty($repositories)) {
        echo '<p class="empty">当前没有配置仓库。</p></section>' ."\n";
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr><th>URL</th><th>路径</th><th>所有者</th>'
        .'<th>可见性</th><th>读 / 写</th></tr></thead><tbody>' ."\n";
    foreach ($repositories as $repository) {
        $owner = $repository['options']['owner'] === NULL
            ? '未设置' : $repository['options']['owner'];
        echo '<tr><td><code>'.manage_escape($repository['url']).'</code></td>';
        echo '<td><code>'.manage_escape($repository['path']).'</code></td>';
        echo '<td><code>'.manage_escape($owner).'</code></td>';
        echo '<td>'.(repository_is_private($repository) ? '私有' : '公开').'</td>';
        echo '<td>'.($repository['options']['read'] ? '读' : '禁用读取').' / '
            .($repository['options']['push'] ? '写' : '只读').'</td></tr>' ."\n";
    }
    echo '</tbody></table></div></section>' ."\n";
}

function manage_render(
    $url_base,
    $definitions,
    $configuration,
    $administrator,
    $notice) {
    $csrf_token = manage_csrf_token();
    if ($csrf_token === FALSE) {
        send_error(500, 'Internal Server Error', 'Unable to initialize a secure session.');
    }

    $users = auth_list_users();
    $repositories = auth_list_repository_metadata();
    header_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; '
        .'form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

    ob_start();
    manage_send_head($url_base, $administrator);
    echo '<main>' ."\n";
    if ($notice !== NULL && isset($notice['type'], $notice['message'])) {
        echo '<p class="notice notice-'.manage_escape($notice['type']).'" role="status">'
            .manage_escape($notice['message']).'</p>' ."\n";
    }
    manage_send_create_user($url_base, $csrf_token);
    manage_send_users($url_base, $csrf_token, $users, $administrator);
    manage_send_repositories(
        $url_base,
        $csrf_token,
        $repositories,
        $users,
        $configuration,
        $definitions);
    manage_send_configured_repositories($url_base, $definitions);
    echo '</main></body></html>' ."\n";
    $markup = ob_get_clean();
    $markup = str_replace('__LANG__', manage_escape(i18n_html_lang()), $markup);
    $markup = str_replace('__TITLE__', manage_escape(t('manage.title')), $markup);
    echo i18n_translate_markup($markup);
}

if (!isset($url_base)) {
    $url_base = '';
}
if (!isset($repos) || !is_array($repos)) {
    send_error(500, 'Internal Server Error', 'The repository configuration is invalid.');
}
if (!isset($auth) || !is_array($auth)) {
    send_error(500, 'Internal Server Error', 'The authentication configuration is invalid.');
}
if (!isset($managed_repositories)) {
    $managed_repositories = array();
}
if (!is_array($managed_repositories)) {
    send_error(500, 'Internal Server Error', 'The managed repository configuration is invalid.');
}

i18n_configure(isset($language) ? $language : NULL, $url_base);
auth_configure($auth, $url_base);
if (!auth_is_enabled()) {
    send_error(404, 'Not Found', 'Account authentication is disabled.');
}

$administrator = auth_session_user();
if ($administrator === NULL || !auth_user_is_administrator($administrator)) {
    send_error(403, 'Forbidden', 'An administrator login is required.');
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'POST') {
    manage_handle_action($url_base, $repos, $managed_repositories, $administrator);
}
if ($method !== 'GET' && $method !== 'HEAD') {
    send_status(405, 'Method Not Allowed');
    header('Allow: GET, HEAD, POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    die();
}

$notice = $method === 'GET' ? manage_take_notice() : NULL;
manage_render(
    $url_base,
    $repos,
    $managed_repositories,
    $administrator,
    $notice);