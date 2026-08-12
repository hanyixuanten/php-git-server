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
:root { color-scheme: light dark; --canvas: #f3f7f5; --surface: #fff; --surface-soft: #f7faf8;
    --ink: #17231d; --muted: #63736a; --line: #d9e4dd; --accent: #167447; --accent-strong: #0d5a34;
    --accent-soft: #e8f5ed; --danger: #b23b3b; --warning: #9a6b12; --link: #0d658f;
    --shadow: 0 16px 42px rgba(25,59,42,.07); }
* { box-sizing: border-box; }
body { margin: 0; color: var(--ink); background: var(--canvas); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", "Noto Sans CJK SC", sans-serif; line-height: 1.55; }
body::before { position: fixed; z-index: -1; inset: 0; content: ""; background: radial-gradient(circle at 4% 0%, #dcf1e5 0, transparent 28rem), radial-gradient(circle at 96% 0%, #e0f1f8 0, transparent 29rem); }
header { border-bottom: 1px solid rgba(217,228,221,.85); background: rgba(255,255,255,.8); backdrop-filter: blur(16px); }
.header-inner, main { width: min(92rem, calc(100% - 2rem)); margin: 0 auto; }
.header-inner { display: flex; min-height: 5.15rem; align-items: center; justify-content: space-between; gap: 1rem; }
h1 { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 1.25rem; letter-spacing: -.035em; }
h1::before { display: inline-grid; width: 1.95rem; height: 1.95rem; place-items: center; margin-right: .55rem; content: "⌘"; border-radius: .55rem; color: #fff; background: var(--accent); font-size: 1.1rem; vertical-align: -.15em; }
.identity { margin: .22rem 0 0 2.52rem; color: var(--muted); font-size: .84rem; }
nav { display: flex; align-items: center; gap: .75rem; font-size: .9rem; }
a, summary { color: var(--link); } nav a { font-weight: 700; text-decoration: none; } nav a:hover { text-decoration: underline; }
main { padding: 2rem 0 4rem; }
section { margin-bottom: 1.25rem; padding: 1.4rem; border: 1px solid var(--line); border-radius: .9rem; background: var(--surface); box-shadow: var(--shadow); }
section + section { padding-top: 1.4rem; border-top: 1px solid var(--line); }
h2 { margin: 0 0 .35rem; font-size: 1.15rem; letter-spacing: -.018em; } h2::before { display: inline-block; width: .48rem; height: .48rem; margin: 0 .48rem .09rem 0; content: ""; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 4px var(--accent-soft); }
.section-lead { margin: 0 0 1.25rem; color: var(--muted); }
.notice { margin: 0 0 1.25rem; padding: .78rem 1rem; border: 1px solid; border-left: .32rem solid; border-radius: .55rem; font-weight: 600; }
.notice-success { border-color: #a7d9b9; border-left-color: var(--accent); background: var(--accent-soft); color: #155735; }.notice-error { border-color: #f1c5c5; border-left-color: var(--danger); background: #fff1f1; color: #852b2b; }
.create-user { display: grid; grid-template-columns: minmax(12rem,1fr) minmax(12rem,1fr) minmax(12rem,1fr) auto; gap: .75rem; align-items: end; max-width: 70rem; }
label, .label { display: block; margin-bottom: .32rem; color: #304239; font-size: .82rem; font-weight: 800; }
input:not([type="checkbox"]), select, button { min-height: 2.55rem; border-radius: .52rem; font: inherit; }
input:not([type="checkbox"]), select { width: 100%; padding: .48rem .66rem; border: 1px solid #bbcbbf; color: var(--ink); background: #fff; }
input:not([type="checkbox"]):focus, select:focus { border-color: var(--accent); outline: 0; box-shadow: 0 0 0 3px rgba(22,116,71,.16); }
button { padding: .48rem .85rem; border: 1px solid var(--accent-strong); color: #fff; background: var(--accent); font-weight: 700; cursor: pointer; white-space: nowrap; box-shadow: 0 2px 4px rgba(13,90,52,.15); transition: transform .15s, background .15s; } button:hover { background: var(--accent-strong); transform: translateY(-1px); }.button-danger { border-color: #922f2f; background: var(--danger); }.button-danger:hover { background: #902d2d; }.button-muted { border-color: #56616d; background: #626e7b; }.button-muted:hover { background: #505a65; }
.table-wrap { overflow-x: auto; margin: 1rem -1.4rem -1.4rem; border-top: 1px solid var(--line); border-radius: 0 0 .9rem .9rem; }
table { width: 100%; border-collapse: collapse; font-size: .91rem; } th, td { padding: .78rem .8rem; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; } th { color: #526259; background: #f4f8f5; font-size: .72rem; font-weight: 800; letter-spacing: .065em; text-transform: uppercase; white-space: nowrap; } tbody tr { transition: background .12s; } tbody tr:hover { background: #f8fcf9; } tbody tr:last-child td { border-bottom: 0; }
code { padding: .07rem .24rem; border-radius: .24rem; background: #edf3ef; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9em; word-break: break-all; }.badge { display: inline-block; padding: .12rem .55rem; border: 1px solid currentColor; border-radius: 999px; font-size: .74rem; font-weight: 700; white-space: nowrap; }.badge-good { color: var(--accent); background: var(--accent-soft); }.badge-muted { color: var(--muted); background: #f2f5f3; }.badge-warning { color: var(--warning); background: #fff7e6; }
.stack { display: grid; gap: .45rem; min-width: 9rem; }.inline-form { display: flex; align-items: center; gap: .4rem; }.inline-form select { min-width: 8rem; }.confirm { display: flex; align-items: center; gap: .4rem; margin: 0; color: var(--muted); font-weight: 500; font-size: .78rem; }.confirm input { width: auto; min-height: auto; accent-color: var(--danger); }
details { min-width: 15rem; } summary { cursor: pointer; font-weight: 700; } details form { display: grid; gap: .45rem; margin-top: .55rem; }.empty { margin: 0; padding: 1rem; border: 1px dashed #aebfb3; border-radius: .55rem; color: var(--muted); background: var(--surface-soft); }
@media (max-width: 54rem) { .header-inner { align-items: flex-start; flex-direction: column; padding: .95rem 0; }.create-user { grid-template-columns: 1fr; } }
@media (max-width: 40rem) { .header-inner, main { width: min(100% - 1.25rem, 92rem); } nav { flex-wrap: wrap; } section { padding: 1.1rem; }.table-wrap { margin: 1rem -1.1rem -1.1rem; } }
@media (prefers-color-scheme: dark) { :root { --canvas:#101916;--surface:#17231e;--surface-soft:#1d2b25;--ink:#e5efe8;--muted:#a0b2a8;--line:#31443a;--accent:#42a76d;--accent-strong:#2d8953;--accent-soft:#173b27;--danger:#d05a5a;--warning:#e0ad50;--link:#75c4eb;--shadow:0 16px 42px rgba(0,0,0,.2); } body::before { background:radial-gradient(circle at 4% 0%,#173e2a 0,transparent 28rem),radial-gradient(circle at 96% 0%,#163744 0,transparent 29rem); } header { border-color:rgba(49,68,58,.85);background:rgba(23,35,30,.8); } label,.label { color:#d5e2d9; } input:not([type="checkbox"]),select { border-color:#587064;background:#132019;color:var(--ink); } th { color:#b9cabf;background:#1b2a23; } tbody tr:hover { background:#1b2a23; } code { background:#22332b; }.badge-muted { background:#26362e; }.badge-warning { background:#3e3218; }.notice-success { border-color:#356d4b;color:#a9e5bd; }.notice-error { border-color:#713838;background:#3a2020;color:#ffb6b6; } }
</style>
</head>
<body>
HTML;
    echo '<header><div class="header-inner"><div><h1>'.manage_escape(t('manage.heading')).'</h1>' ."\n";
    echo '<p class="identity">'.manage_escape(t('manage.administrator_label')).'：<strong>'.manage_escape($administrator['username'])
        .'</strong></p></div>' ."\n";
    echo '<nav><a href="'.manage_escape(manage_home_url($url_base)).'">'.manage_escape(t('manage.back_to_home')).'</a> '
        .i18n_language_switcher(manage_page_url($url_base));
    echo '</nav></div></header>' ."\n";
}

function manage_send_create_user($url_base, $csrf_token) {
    echo '<section aria-labelledby="create-user-title"><h2 id="create-user-title">'.manage_escape(t('manage.create_user_title')).'</h2>' ."\n";
    echo '<p class="section-lead">'.manage_escape(t('manage.create_user_lead')).'</p>' ."\n";
    echo '<form class="create-user" method="post" action="'
        .manage_escape(manage_page_url($url_base)).'">' ."\n";
    echo '<input type="hidden" name="csrf_token" value="'.manage_escape($csrf_token).'">' ."\n";
    echo '<input type="hidden" name="action" value="create_user">' ."\n";
    echo '<div><label for="new-username">'.manage_escape(t('manage.label_username')).'</label><input id="new-username" name="username" '
        .'minlength="3" maxlength="64" autocomplete="off" required></div>' ."\n";
    echo '<div><label for="new-password">'.manage_escape(t('manage.label_initial_password')).'</label><input id="new-password" name="password" '
        .'type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
    echo '<div><label for="new-password-confirmation">'.manage_escape(t('manage.label_password_confirmation')).'</label>'
        .'<input id="new-password-confirmation" name="password_confirmation" type="password" '
        .'minlength="8" maxlength="72" autocomplete="new-password" required></div>' ."\n";
    echo '<button type="submit">'.manage_escape(t('manage.button_create_user')).'</button></form></section>' ."\n";
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
    echo '<section aria-labelledby="users-title"><h2 id="users-title">'.manage_escape(t('manage.users_title')).'</h2>' ."\n";
    echo '<p class="section-lead">'.manage_escape(t('manage.users_lead')).'</p>' ."\n";
    if ($users === FALSE) {
        echo '<p class="notice notice-error">'.manage_escape(t('manage.users_unavailable')).'</p></section>' ."\n";
        return;
    }
    if (empty($users)) {
        echo '<p class="empty">'.manage_escape(t('manage.users_empty')).'</p></section>' ."\n";
        return;
    }

    $action_url = manage_escape(manage_page_url($url_base));
    echo '<div class="table-wrap"><table><thead><tr><th>'.manage_escape(t('manage.th_user')).'</th><th>'.manage_escape(t('manage.th_status')).'</th><th>'.manage_escape(t('manage.th_repositories')).'</th>'
        .'<th>'.manage_escape(t('manage.th_active_tokens')).'</th><th>'.manage_escape(t('manage.th_created_at')).'</th><th>'.manage_escape(t('manage.th_account_actions')).'</th><th>'.manage_escape(t('manage.th_password')).'</th></tr></thead><tbody>' ."\n";
    foreach ($users as $user) {
        $active = (int) $user['is_active'] === 1;
        $is_administrator = auth_user_is_administrator($user);
        $is_self = (int) $user['id'] === (int) $administrator['id'];
        echo '<tr><td><code>'.manage_escape($user['username']).'</code><br>';
        if ($is_administrator) {
            echo '<span class="badge badge-good">'.manage_escape(t('manage.badge_administrator')).'</span>';
        } else if ($is_self) {
            echo '<span class="badge badge-muted">'.manage_escape(t('manage.badge_current_account')).'</span>';
        }
        echo '</td><td><span class="badge '.($active ? 'badge-good' : 'badge-warning').'">'
            .manage_escape($active ? t('manage.badge_active') : t('manage.badge_inactive')).'</span></td>';
        echo '<td>'.manage_escape($user['repository_count']).'</td>';
        echo '<td>'.manage_escape($user['active_token_count']).'</td>';
        echo '<td>'.manage_escape($user['created_at']).'</td><td><div class="stack">';

        if ((!$is_administrator || !$active) && !($is_self && $active)) {
            echo '<form method="post" action="'.$action_url.'">'
                .manage_hidden_fields($csrf_token, 'set_user_status', $user['id']);
            echo '<input type="hidden" name="active" value="'.($active ? '0' : '1').'">';
            echo '<button class="button-muted" type="submit">'.manage_escape($active ? t('manage.button_deactivate') : t('manage.button_activate')).'</button></form>';
        }

        echo '<form method="post" action="'.$action_url.'">'
            .manage_hidden_fields($csrf_token, 'revoke_user_tokens', $user['id']);
        echo '<button class="button-muted" type="submit">'.manage_escape(t('manage.button_revoke_all_tokens')).'</button></form>';

        if (!$is_administrator && !$is_self && (int) $user['repository_count'] === 0) {
            echo '<form class="stack" method="post" action="'.$action_url.'">'
                .manage_hidden_fields($csrf_token, 'delete_user', $user['id']);
            echo '<label class="confirm"><input name="confirmation" type="checkbox" value="'
                .manage_escape($user['username']).'" required> '.manage_escape(t('manage.confirm_delete_account')).'</label>';
            echo '<button class="button-danger" type="submit">'.manage_escape(t('manage.button_delete_user')).'</button></form>';
        }
        echo '</div></td><td><details><summary>'.manage_escape(t('manage.summary_reset_password')).'</summary>';
        echo '<form method="post" action="'.$action_url.'">'
            .manage_hidden_fields($csrf_token, 'reset_user_password', $user['id']);
        echo '<input name="password" type="password" minlength="8" maxlength="72" '
            .'autocomplete="new-password" aria-label="'.manage_escape(t('manage.placeholder_new_password')).'" placeholder="'.manage_escape(t('manage.placeholder_new_password')).'" required>';
        echo '<input name="password_confirmation" type="password" minlength="8" maxlength="72" '
            .'autocomplete="new-password" aria-label="'.manage_escape(t('manage.placeholder_confirm_new_password')).'" placeholder="'.manage_escape(t('manage.placeholder_confirm_new_password')).'" required>';
        echo '<button type="submit">'.manage_escape(t('manage.button_save_password')).'</button></form></details></td></tr>' ."\n";
    }
    echo '</tbody></table></div></section>' ."\n";
}

function manage_repository_state($repository, $configuration, $definitions, $url_base) {
    $root = managed_repository_root($configuration);
    if ($root === FALSE) {
        return array('badge-warning', t('manage.state_root_unavailable'), FALSE);
    }

    $path = $root.DIRECTORY_SEPARATOR.$repository['repository_name'];
    if (managed_repository_path_is_configured($definitions, $url_base, $path)) {
        return array('badge-warning', t('manage.state_configured'), FALSE);
    }
    if (!managed_repository_is_bare($path)) {
        return array('badge-warning', t('manage.state_record_only'), TRUE);
    }
    if ((int) $repository['is_ready'] !== 1) {
        return array('badge-warning', t('manage.state_incomplete'), TRUE);
    }

    return array('badge-good', t('manage.state_ready'), TRUE);
}

function manage_send_repositories(
    $url_base,
    $csrf_token,
    $repositories,
    $users,
    $configuration,
    $definitions) {
    echo '<section aria-labelledby="repositories-title"><h2 id="repositories-title">'.manage_escape(t('manage.repositories_title')).'</h2>' ."\n";
    echo '<p class="section-lead">'.t('manage.repositories_lead').'</p>' ."\n";
    if ($repositories === FALSE) {
        echo '<p class="notice notice-error">'.manage_escape(t('manage.repositories_unavailable')).'</p></section>' ."\n";
        return;
    }
    if (empty($repositories)) {
        echo '<p class="empty">'.manage_escape(t('manage.repositories_empty')).'</p></section>' ."\n";
        return;
    }

    $action_url = manage_escape(manage_page_url($url_base));
    echo '<div class="table-wrap"><table><thead><tr><th>'.manage_escape(t('manage.th_repository')).'</th><th>'.manage_escape(t('manage.th_status')).'</th><th>'.manage_escape(t('manage.th_owner_visibility')).'</th>'
        .'<th>'.manage_escape(t('manage.th_created_at')).'</th><th>'.manage_escape(t('manage.th_delete')).'</th></tr></thead><tbody>' ."\n";
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
        echo '<select name="owner_user_id" aria-label="'.manage_escape(t('manage.aria_repository_owner')).'">';
        foreach ($users === FALSE ? array() : $users as $user) {
            if ((int) $user['is_active'] !== 1
                && (int) $user['id'] !== (int) $repository['owner_user_id']) {
                continue;
            }
            echo '<option value="'.manage_escape($user['id']).'"'
                .((int) $user['id'] === (int) $repository['owner_user_id'] ? ' selected' : '').'>'
                .manage_escape($user['username']).((int) $user['is_active'] === 1 ? '' : manage_escape(t('manage.user_deactivated_suffix')))
                .'</option>';
        }
        echo '</select><select name="visibility" aria-label="'.manage_escape(t('manage.aria_repository_visibility')).'">';
        echo '<option value="public"'.((int) $repository['is_private'] === 0 ? ' selected' : '')
            .'>'.manage_escape(t('manage.public')).'</option>';
        echo '<option value="private"'.((int) $repository['is_private'] === 1 ? ' selected' : '')
            .'>'.manage_escape(t('manage.private')).'</option></select><button type="submit">'.manage_escape(t('manage.button_save')).'</button></form></td>';
        echo '<td>'.manage_escape($repository['created_at']).'</td><td>';
        if (!$state[2]) {
            echo '<span class="badge badge-muted">'.manage_escape(t('manage.badge_not_deletable')).'</span>';
        } else {
            echo '<form class="stack" method="post" action="'.$action_url.'">';
            echo '<input type="hidden" name="csrf_token" value="'.manage_escape($csrf_token).'">';
            echo '<input type="hidden" name="action" value="delete_repository">';
            echo '<input type="hidden" name="repository_name" value="'
                .manage_escape($repository['repository_name']).'">';
            echo '<label class="confirm"><input name="confirmation" type="checkbox" value="'
                .manage_escape($repository['repository_name']).'" required> '.manage_escape(t('manage.confirm_permanent_delete')).'</label>';
            echo '<button class="button-danger" type="submit">'.manage_escape(t('manage.button_delete_repository')).'</button></form>';
        }
        echo '</td></tr>' ."\n";
    }
    echo '</tbody></table></div></section>' ."\n";
}

function manage_send_configured_repositories($url_base, $definitions) {
    echo '<section aria-labelledby="configured-title"><h2 id="configured-title">'.manage_escape(t('manage.configured_title')).'</h2>' ."\n";
    echo '<p class="section-lead">'.t('manage.configured_lead').'</p>' ."\n";
    $repositories = array();
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository !== FALSE) {
            $repositories[] = $repository;
        }
    }
    if (empty($repositories)) {
        echo '<p class="empty">'.manage_escape(t('manage.configured_empty')).'</p></section>' ."\n";
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr><th>'.manage_escape(t('manage.th_url')).'</th><th>'.manage_escape(t('manage.th_path')).'</th><th>'.manage_escape(t('manage.th_owner_visibility')).'</th>'
        .'<th>'.manage_escape(t('manage.th_visibility')).'</th><th>'.manage_escape(t('manage.th_read_write')).'</th></tr></thead><tbody>' ."\n";
    foreach ($repositories as $repository) {
        $owner = $repository['options']['owner'] === NULL
            ? t('manage.value_unset') : $repository['options']['owner'];
        echo '<tr><td><code>'.manage_escape($repository['url']).'</code></td>';
        echo '<td><code>'.manage_escape($repository['path']).'</code></td>';
        echo '<td><code>'.manage_escape($owner).'</code></td>';
        echo '<td>'.manage_escape(repository_is_private($repository) ? t('manage.private') : t('manage.public')).'</td>';
        echo '<td>'.manage_escape($repository['options']['read'] ? t('manage.value_read') : t('manage.value_read_disabled')).' / '
            .manage_escape($repository['options']['push'] ? t('manage.value_write') : t('manage.value_read_only')).'</td></tr>' ."\n";
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