<?php

/* First-run installer. It imports schema.mysql.sql into an existing database and
   writes config.php. The missing config.php is the only thing gating this
   endpoint, so it refuses to run once that file exists. */

require(__DIR__.'/lib/http.php');
require(__DIR__.'/lib/i18n.php');

// Installer has no config yet, so language follows the request/cookie/browser.
i18n_configure(NULL, rtrim(str_replace('\\', '/', dirname(install_page_url())), '/'));

function install_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function install_config_path() {
    return __DIR__.DIRECTORY_SEPARATOR.'config.php';
}

function install_is_complete() {
    return file_exists(install_config_path());
}

function install_page_url() {
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    return $script === '' ? '/install.php' : $script;
}

function install_home_url() {
    $base = rtrim(str_replace('\\', '/', dirname(install_page_url())), '/');
    return $base === '' ? '/' : $base.'/';
}

function install_request_is_https() {
    $https = isset($_SERVER['HTTPS']) ? strtolower((string) $_SERVER['HTTPS']) : '';
    return $https === 'on' || $https === '1'
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
}

function install_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return TRUE;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        return FALSE;
    }

    session_name('PHPGITSERVERINSTALL');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => install_home_url(),
        'secure' => install_request_is_https(),
        'httponly' => TRUE,
        'samesite' => 'Strict'));
    return @session_start();
}

function install_csrf_token() {
    if (!install_start_session()) {
        return FALSE;
    }

    if (!isset($_SESSION['install_csrf_token'])
        || !is_string($_SESSION['install_csrf_token'])
        || strlen($_SESSION['install_csrf_token']) !== 64) {
        try {
            $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return FALSE;
        }
    }

    return $_SESSION['install_csrf_token'];
}

function install_post_value($name) {
    return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
}

function install_post_checked($name) {
    return isset($_POST[$name]) && $_POST[$name] === '1';
}

/* MySQL identifiers cannot be bound as parameters, so they are restricted to a
   conservative character set before any interpolation. Hyphens are allowed
   because shared hosting panels routinely generate names that contain them. */
function install_identifier_is_valid($value) {
    return is_string($value) && preg_match('~^[A-Za-z0-9_$-]{1,64}$~D', $value) === 1;
}

function install_host_is_valid($value) {
    return is_string($value) && preg_match('~^[A-Za-z0-9._%-]{1,255}$~D', $value) === 1;
}

function install_quote_identifier($value) {
    return '`'.str_replace('`', '``', $value).'`';
}

function install_schema_statements() {
    $buffer = @file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'schema.mysql.sql');
    if ($buffer === FALSE) {
        return FALSE;
    }

    $statements = array();
    foreach (preg_split('~;\s*(?:\r?\n|$)~', $buffer) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    return $statements;
}

function install_validate($input) {
    $errors = array();

    if ($input['url_base'] !== ''
        && !preg_match('~^/[A-Za-z0-9._~/-]*[A-Za-z0-9._~-]$~D', $input['url_base'])) {
        $errors[] = t('install.error_url_base');
    }
    if ($input['git_executable'] === '' || strlen($input['git_executable']) > 255
        || preg_match('~[\x00-\x1F\x7F]~', $input['git_executable'])) {
        $errors[] = t('install.error_git_executable');
    }
    if (!install_host_is_valid($input['db_host'])) {
        $errors[] = t('install.error_db_host');
    }
    if (!preg_match('~^[1-9][0-9]{0,4}$~D', $input['db_port']) || (int) $input['db_port'] > 65535) {
        $errors[] = t('install.error_db_port');
    }
    if (!install_identifier_is_valid($input['db_name'])) {
        $errors[] = t('install.error_db_name');
    }
    if (!install_identifier_is_valid($input['db_user'])) {
        $errors[] = t('install.error_db_user');
    }
    if ($input['db_password'] === '' || strlen($input['db_password']) > 255) {
        $errors[] = t('install.error_db_password');
    }
    if (auth_normalize_username($input['admin_username']) === FALSE) {
        $errors[] = t('install.error_admin_username');
    }
    if (!auth_password_is_valid($input['admin_password'])) {
        $errors[] = t('install.error_admin_password');
    } else if (!hash_equals($input['admin_password'], $input['admin_password_confirmation'])) {
        $errors[] = t('install.error_admin_password_mismatch');
    }

    return $errors;
}

function install_connect($dsn, $username, $password) {
    return new PDO($dsn, $username, $password, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => FALSE));
}

function install_database_dsn($input) {
    return 'mysql:host='.$input['db_host'].';port='.$input['db_port']
        .';dbname='.$input['db_name'].';charset=utf8mb4';
}

function install_import_schema($connection) {
    $statements = install_schema_statements();
    if ($statements === FALSE) {
        return array('status' => 'schema_unreadable');
    }

    $existing = $connection->query('SHOW TABLES LIKE \'pgit_%\'')->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existing)) {
        return array('status' => 'schema_present', 'tables' => count($existing));
    }

    try {
        foreach ($statements as $statement) {
            $connection->exec($statement);
        }
    } catch (PDOException $exception) {
        $driver_code = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        if ($driver_code === 1142 || $driver_code === 1044) {
            return array('status' => 'schema_denied');
        }
        throw $exception;
    }

    return array('status' => 'schema_imported', 'tables' => count($statements));
}

/* SHOW GRANTS output is awkward to parse reliably, so DELETE is confirmed with a
   statement that matches no rows but still requires the privilege. */
function install_verify_delete_privilege($connection) {
    try {
        $connection->exec('DELETE FROM pgit_users WHERE 1 = 0');
        return TRUE;
    } catch (PDOException $exception) {
        return FALSE;
    }
}

function install_create_administrator($connection, $username, $password) {
    $statement = $connection->prepare(
        'SELECT id FROM pgit_users WHERE username = ? LIMIT 1');
    $statement->execute(array($username));
    if ($statement->fetch() !== FALSE) {
        return 'administrator_exists';
    }

    $statement = $connection->prepare(
        'INSERT INTO pgit_users (username, password_hash) VALUES (?, ?)');
    $statement->execute(array($username, password_hash($password, PASSWORD_DEFAULT)));
    return 'administrator_created';
}

function install_config_contents($input) {
    $administrators = "        ".var_export($input['admin_username'], TRUE).",\n";
    $secure_cookie = $input['session_cookie_secure']
        ? "    'session_cookie_secure' => TRUE,\n" : '';

    return "<?php\n\n"
        ."/* Generated by install.php. See config.php.sample for every option. */\n\n"
        ."\$url_base = ".var_export($input['url_base'], TRUE).";\n"
        ."\$git_executable = ".var_export($input['git_executable'], TRUE).";\n"
        ."\$language = ".var_export($input['language'], TRUE).";\n\n"
        ."\$auth = array(\n"
        ."    'enabled' => TRUE,\n"
        ."    'registration_enabled' => ".($input['registration_enabled'] ? 'TRUE' : 'FALSE').",\n"
        .$secure_cookie
        ."    'administrators' => array(\n"
        .$administrators
        ."    ),\n"
        ."    'database' => array(\n"
        ."        'dsn' => ".var_export(install_database_dsn($input), TRUE).",\n"
        ."        'username' => ".var_export($input['db_user'], TRUE).",\n"
        ."        'password' => ".var_export($input['db_password'], TRUE)."));\n\n"
        ."\$managed_repositories = array(\n"
        ."    'options' => array(\n"
        ."        'read' => TRUE,\n"
        ."        'push' => TRUE,\n"
        ."        'branches' => TRUE,\n"
        ."        'tags' => TRUE,\n"
        ."        'other_refs' => FALSE,\n"
        ."        'allow_non_fast_forward' => FALSE,\n"
        ."        'max_object_bytes' => 268435456,\n"
        ."        'max_pack_objects' => 100000,\n"
        ."        'max_request_bytes' => 0));\n\n"
        ."\$repos = array(\n"
        ."    array('/php-git-server.git', '.git', array(\n"
        ."        'read' => TRUE,\n"
        ."        'push' => FALSE,\n"
        ."        'owner' => NULL,\n"
        ."        'private' => FALSE)),\n"
        ."    );\n";
}

/* Exclusive creation keeps a second concurrent installer from overwriting a
   configuration that was just written. */
function install_write_config($input) {
    $path = install_config_path();
    $handle = @fopen($path, 'xb');
    if ($handle === FALSE) {
        return FALSE;
    }

    $contents = install_config_contents($input);
    $written = fwrite($handle, $contents);
    fclose($handle);
    if ($written !== strlen($contents)) {
        @unlink($path);
        return FALSE;
    }

    @chmod($path, 0600);
    return TRUE;
}

function install_run($input) {
    $steps = array();

    try {
        $connection = install_connect(
            install_database_dsn($input), $input['db_user'], $input['db_password']);
        $steps[] = array('success', t('install.step_connected'));

        $import = install_import_schema($connection);
        if ($import['status'] === 'schema_unreadable') {
            return array('errors' => array(t('install.error_schema_unreadable')), 'steps' => $steps);
        }
        if ($import['status'] === 'schema_denied') {
            return array(
                'errors' => array(t('install.error_schema_denied')),
                'steps' => $steps);
        }
        $steps[] = $import['status'] === 'schema_imported'
            ? array('success', t('install.step_schema_imported', array('count' => $import['tables'])))
            : array('success', t('install.step_schema_present'));

        if (!install_verify_delete_privilege($connection)) {
            return array(
                'errors' => array(t('install.error_delete_privilege')),
                'steps' => $steps);
        }
        $steps[] = array('success', t('install.step_privileges'));

        $administrator = install_create_administrator(
            $connection, $input['admin_username'], $input['admin_password']);
        $steps[] = $administrator === 'administrator_created'
            ? array('success', t('install.step_admin_created', array('name' => $input['admin_username'])))
            : array('success', t('install.step_admin_promoted', array('name' => $input['admin_username'])));
    } catch (PDOException $exception) {
        return array(
            'errors' => array(t('install.error_database', array('message' => $exception->getMessage()))),
            'steps' => $steps);
    }

    if (!install_write_config($input)) {
        return array(
            'errors' => array(t('install.error_config_write')),
            'steps' => $steps);
    }
    $steps[] = array('success', t('install.step_config_written'));

    return array('errors' => array(), 'steps' => $steps, 'complete' => TRUE);
}

function install_send_head() {
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="__LANG__">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITLE__</title>
<style>
:root { color-scheme: light dark; --canvas:#f3f7f5;--surface:#fff;--surface-soft:#f7faf8;--ink:#17231d;--muted:#63736a;--line:#d9e4dd;--accent:#167447;--accent-strong:#0d5a34;--accent-soft:#e8f5ed;--danger:#b23b3b;--warning:#9a6b12;--link:#0d658f;--shadow:0 18px 46px rgba(25,59,42,.09); }
* { box-sizing:border-box; } html { background:var(--canvas); } body { width:min(54rem,calc(100% - 2rem));margin:0 auto;padding:3.5rem 0;color:var(--ink);background:transparent;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI","Noto Sans CJK SC",sans-serif;line-height:1.6; } body::before { position:fixed;z-index:-1;inset:0;content:"";background:radial-gradient(circle at 7% 0%,#dcf1e5 0,transparent 28rem),radial-gradient(circle at 96% 7%,#e0f1f8 0,transparent 27rem),var(--canvas); }
h1 { margin:0;font-size:clamp(1.75rem,4vw,2.35rem);line-height:1.15;letter-spacing:-.045em; } h1::before { display:inline-grid;width:2.3rem;height:2.3rem;place-items:center;margin-right:.7rem;content:"⌘";border-radius:.68rem;color:#fff;background:var(--accent);font-size:1.38rem;vertical-align:-.18em;box-shadow:0 7px 18px rgba(22,116,71,.24); } h2 { margin:2rem 0 .6rem;font-size:1.15rem;letter-spacing:-.02em; } p { margin:0 0 1rem; }.language-switcher { display:inline-flex;margin:.8rem 0 1.5rem;padding:.25rem .5rem;border:1px solid var(--line);border-radius:999px;background:rgba(255,255,255,.6);font-size:.82rem; }.language-switcher a,a { color:var(--link); }.lead { margin:0 0 1.4rem;padding:1.1rem 1.2rem;border:1px solid #cde3d5;border-radius:.75rem;color:#315340;background:var(--accent-soft); }
form { padding:1.35rem;border:1px solid var(--line);border-radius:.9rem;background:var(--surface);box-shadow:var(--shadow); } fieldset { margin:0 0 1.1rem;padding:1.15rem;border:1px solid var(--line);border-radius:.7rem;background:var(--surface-soft); } legend { padding:0 .45rem;color:var(--ink);font-weight:800; }.grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.95rem; } label { display:block;margin-bottom:.35rem;color:#304239;font-size:.85rem;font-weight:800; } input[type="text"],input[type="password"],input[type="number"] { width:100%;min-height:2.65rem;padding:.52rem .7rem;border:1px solid #bbcbbf;border-radius:.52rem;background:#fff;color:var(--ink);font:inherit;transition:border-color .15s,box-shadow .15s; } input:focus { border-color:var(--accent);outline:0;box-shadow:0 0 0 3px rgba(22,116,71,.16); }.check { display:flex;align-items:flex-start;gap:.55rem;margin-top:.9rem; }.check input { margin-top:.32rem;accent-color:var(--accent); }.check label { margin:0;font-weight:600; }.hint { margin:.35rem 0 0;color:var(--muted);font-size:.84rem; }button { min-height:2.7rem;padding:.55rem 1.35rem;border:1px solid var(--accent-strong);border-radius:.52rem;color:#fff;background:var(--accent);font:inherit;font-weight:800;cursor:pointer;box-shadow:0 2px 4px rgba(13,90,52,.15);transition:transform .15s,background .15s,box-shadow .15s; }button:hover { background:var(--accent-strong);box-shadow:0 5px 12px rgba(13,90,52,.2);transform:translateY(-1px); }button:focus-visible { outline:3px solid #79c99d;outline-offset:2px; }.notice { margin:0 0 1rem;padding:.78rem 1rem;border:1px solid;border-left:.32rem solid;border-radius:.55rem;font-weight:600; }.notice-success { border-color:#a7d9b9;border-left-color:var(--accent);background:var(--accent-soft);color:#155735; }.notice-error { border-color:#f1c5c5;border-left-color:var(--danger);background:#fff1f1;color:#852b2b; }.notice-warning { border-color:#ebd39c;border-left-color:var(--warning);background:#fff7e6;color:#6d4c11; }ul { margin:0 0 1rem;padding-left:1.2rem; }code { padding:.07rem .25rem;border-radius:.24rem;background:#edf3ef;font-size:.9em;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }pre { padding:.85rem 1rem;overflow-x:auto;border:1px solid var(--line);border-radius:.62rem;background:#f5f8f6; }pre code { padding:0;background:transparent; }
@media (max-width:40rem) { body { width:min(100% - 1.25rem,54rem);padding-top:2rem; }h1::before { display:none; }.grid { grid-template-columns:1fr; }form { padding:1.05rem; }fieldset { padding:1rem; }button { width:100%; } }
@media (prefers-color-scheme:dark) { :root { --canvas:#101916;--surface:#17231e;--surface-soft:#1d2b25;--ink:#e5efe8;--muted:#a0b2a8;--line:#31443a;--accent:#42a76d;--accent-strong:#2d8953;--accent-soft:#173b27;--danger:#d05a5a;--warning:#e0ad50;--link:#75c4eb;--shadow:0 18px 46px rgba(0,0,0,.22); }body::before { background:radial-gradient(circle at 7% 0%,#173e2a 0,transparent 28rem),radial-gradient(circle at 96% 7%,#163744 0,transparent 27rem),var(--canvas); }h1::before { color:#102319; }.language-switcher { background:rgba(23,35,30,.62); }label { color:#d5e2d9; }fieldset { background:var(--surface-soft); }input[type="text"],input[type="password"],input[type="number"] { border-color:#587064;background:#132019;color:var(--ink); }code { background:#22332b; }pre { background:#1b2a23; }.lead { border-color:#356d4b;color:#b7dfc4; }.notice-success { border-color:#356d4b;color:#a9e5bd; }.notice-error { border-color:#713838;background:#3a2020;color:#ffb6b6; }.notice-warning { border-color:#745b27;background:#3e3218;color:#f1d48e; } }
</style>
</head>
<body>
<h1>PHP Git 服务器安装</h1>
HTML;
    echo i18n_language_switcher(install_page_url())."\n";
}

function install_send_form($input, $errors) {
    $token = install_csrf_token();
    if ($token === FALSE) {
        echo '<p class="notice notice-error">当前无法初始化安全会话，无法继续安装。</p>' ."\n";
        return;
    }

    echo '<p class="lead">检测到项目根目录没有 <code>config.php</code>。请先准备好一个空数据库'
        .'和对应账号，填写以下信息即可导入表结构、创建首个管理员账号并生成配置文件。</p>' ."\n";
    if (!install_request_is_https()) {
        echo '<p class="notice notice-warning">当前不是 HTTPS 连接。密码将以明文传输，'
            .'建议改用 HTTPS 或仅从本机访问安装页面。</p>' ."\n";
    }
    if (!extension_loaded('pdo_mysql')) {
        echo '<p class="notice notice-error">PHP 未启用 <code>pdo_mysql</code> 扩展，'
            .'安装无法继续。请先安装该扩展并重启 PHP。</p>' ."\n";
    }
    if (!is_writable(__DIR__)) {
        echo '<p class="notice notice-error">项目目录不可写，无法生成 <code>config.php</code>。'
            .'请调整目录权限后刷新。</p>' ."\n";
    }
    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>' ."\n";
        foreach ($errors as $error) {
            echo '<li>'.install_escape($error).'</li>' ."\n";
        }
        echo '</ul></div>' ."\n";
    }

    echo '<form method="post" action="'.install_escape(install_page_url()).'">' ."\n";
    echo '<input type="hidden" name="csrf_token" value="'.install_escape($token).'">' ."\n";

    echo '<fieldset><legend>应用</legend><div class="grid">' ."\n";
    echo '<div><label for="url_base">基础路径</label>'
        .'<input id="url_base" name="url_base" type="text" value="'
        .install_escape($input['url_base']).'" placeholder="/php-git-server">'
        .'<p class="hint">部署在域名根路径时留空。</p></div>' ."\n";
    echo '<div><label for="git_executable">Git 可执行文件</label>'
        .'<input id="git_executable" name="git_executable" type="text" value="'
        .install_escape($input['git_executable']).'" required>'
        .'<p class="hint">不可用时会回退到纯 PHP 实现。</p></div>' ."\n";
    echo '<div><label for="language">'.install_escape(t('install.label_language')).'</label>'
        .'<select id="language" name="language">'
        .'<option value=""'.($input['language'] === '' ? ' selected' : '').'>'
        .install_escape(t('install.language_auto')).'</option>'
        .'<option value="en"'.($input['language'] === 'en' ? ' selected' : '').'>English</option>'
        .'<option value="zh"'.($input['language'] === 'zh' ? ' selected' : '').'>中文</option>'
        .'</select></div>' ."\n";
    echo '</div><div class="check"><input id="registration_enabled" name="registration_enabled" '
        .'type="checkbox" value="1"'.($input['registration_enabled'] ? ' checked' : '').'>'
        .'<label for="registration_enabled">允许公开注册新账号</label></div>' ."\n";
    echo '<div class="check"><input id="session_cookie_secure" name="session_cookie_secure" '
        .'type="checkbox" value="1"'.($input['session_cookie_secure'] ? ' checked' : '').'>'
        .'<label for="session_cookie_secure">HTTPS 在可信反向代理终止（设置 Secure Cookie）</label>'
        .'</div></fieldset>' ."\n";

    echo '<fieldset><legend>数据库</legend>' ."\n";
    echo '<p class="hint">请先自行创建好数据库和账号，安装器不会创建它们，也不需要 root 权限。'
        .'账号需对该库具有 SELECT、INSERT、UPDATE 和 DELETE 权限；'
        .'若还具有建表权限，安装器会自动导入表结构。</p>' ."\n";
    echo '<div class="grid">' ."\n";
    echo '<div><label for="db_host">主机</label><input id="db_host" name="db_host" type="text" '
        .'value="'.install_escape($input['db_host']).'" required>'
        .'<p class="hint">共享主机通常是 <code>localhost</code>。</p></div>' ."\n";
    echo '<div><label for="db_port">端口</label><input id="db_port" name="db_port" '
        .'type="number" min="1" max="65535" value="'.install_escape($input['db_port'])
        .'" required></div>' ."\n";
    echo '<div><label for="db_name">数据库名</label><input id="db_name" name="db_name" '
        .'type="text" value="'.install_escape($input['db_name']).'" required>'
        .'<p class="hint">面板生成的名称通常带前缀，例如 <code>cpuser_git</code>。</p></div>' ."\n";
    echo '<div><label for="db_user">数据库账号</label><input id="db_user" name="db_user" '
        .'type="text" value="'.install_escape($input['db_user']).'" required></div>' ."\n";
    echo '<div><label for="db_password">数据库账号密码</label><input id="db_password" '
        .'name="db_password" type="password" autocomplete="new-password" required></div>' ."\n";
    echo '</div>' ."\n";
    echo '<p class="hint">若该账号没有建表权限，请先用面板的 phpMyAdmin 导入 '
        .'<code>schema.mysql.sql</code>，再提交本页面；已存在的表会被自动跳过。</p>' ."\n";
    echo '</fieldset>' ."\n";

    echo '<fieldset><legend>管理员账号</legend>' ."\n";
    echo '<p class="hint">该账号会写入 <code>$auth[\'administrators\']</code>，'
        .'安装完成后即可访问管理界面。</p>' ."\n";
    echo '<div class="grid">' ."\n";
    echo '<div><label for="admin_username">用户名</label><input id="admin_username" '
        .'name="admin_username" type="text" minlength="3" maxlength="64" value="'
        .install_escape($input['admin_username']).'" autocomplete="off" required></div>' ."\n";
    echo '<div><label for="admin_password">密码</label><input id="admin_password" '
        .'name="admin_password" type="password" minlength="8" maxlength="72" '
        .'autocomplete="new-password" required></div>' ."\n";
    echo '<div><label for="admin_password_confirmation">确认密码</label>'
        .'<input id="admin_password_confirmation" name="admin_password_confirmation" '
        .'type="password" minlength="8" maxlength="72" autocomplete="new-password" required>'
        .'</div>' ."\n";
    echo '</div></fieldset>' ."\n";

    echo '<button type="submit">开始安装</button>' ."\n";
    echo '</form>' ."\n";
}

function install_send_result($steps) {
    echo '<p class="notice notice-success">安装完成。</p>' ."\n";
    foreach ($steps as $step) {
        echo '<p class="notice notice-'.install_escape($step[0]).'">'
            .install_escape($step[1]).'</p>' ."\n";
    }

    echo '<h2>请立即完成以下操作</h2>' ."\n";
    echo '<p>删除安装脚本，避免它在配置被移除后再次可用。有 shell 时执行：</p>' ."\n";
    echo '<pre><code>rm install.php</code></pre>' ."\n";
    echo '<p>共享主机可用面板的文件管理器或 FTP 直接删除 <code>install.php</code>。</p>' ."\n";
    echo '<p>确认 <code>config.php</code> 仅应用进程可读，并检查 <code>repos</code> 目录权限。'
        .'详细说明见 <code>usage.md</code>。</p>' ."\n";
    echo '<p><a href="'.install_escape(install_home_url()).'">进入首页并登录</a></p>' ."\n";
}

function install_send_foot() {
    echo '</body>' ."\n".'</html>' ."\n";
}

if (install_is_complete()) {
    send_error(
        403,
        'Forbidden',
        'Installation is already complete. Remove install.php, or delete config.php to reinstall.');
}

require(__DIR__.'/lib/auth.php');

/* Session must be started before any output so the cookie can be set. */
install_start_session();

ob_start('i18n_translate_markup');
header_nocache();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; '
    .'form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    send_status(405, 'Method Not Allowed');
    header('Allow: GET, HEAD, POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    die();
}

$input = array(
    'url_base' => rtrim(str_replace('\\', '/', dirname(install_page_url())), '/'),
    'git_executable' => 'git',
    'registration_enabled' => TRUE,
    'session_cookie_secure' => FALSE,
    'language' => '',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'php_git_server',
    'db_user' => 'php_git_server',
    'db_password' => '',

    'admin_username' => '',
    'admin_password' => '',
    'admin_password_confirmation' => '');

if ($method !== 'POST') {
    install_send_head();
    install_send_form($input, array());
    install_send_foot();
    die();
}

if (!request_content_type_is(
    get_request_header('Content-Type'), 'application/x-www-form-urlencoded')) {
    send_error(415, 'Unsupported Media Type', 'Expected a form-encoded request.');
}

$expected_token = install_csrf_token();
if ($expected_token === FALSE
    || !hash_equals($expected_token, install_post_value('csrf_token'))) {
    send_error(403, 'Forbidden', 'The form security token is invalid.');
}

$input = array(
    'url_base' => rtrim(trim(install_post_value('url_base')), '/'),
    'git_executable' => trim(install_post_value('git_executable')),
    'registration_enabled' => install_post_checked('registration_enabled'),
    'session_cookie_secure' => install_post_checked('session_cookie_secure'),
    'language' => install_post_value('language'),
    'db_host' => trim(install_post_value('db_host')),
    'db_port' => trim(install_post_value('db_port')),
    'db_name' => trim(install_post_value('db_name')),
    'db_user' => trim(install_post_value('db_user')),
    'db_password' => install_post_value('db_password'),
    'admin_username' => trim(install_post_value('admin_username')),
    'admin_password' => install_post_value('admin_password'),
    'admin_password_confirmation' => install_post_value('admin_password_confirmation'));

$errors = install_validate($input);
if (!extension_loaded('pdo_mysql')) {
    $errors[] = t('install.error_pdo_mysql_missing');
}

install_send_head();
if (!empty($errors)) {
    install_send_form($input, $errors);
    install_send_foot();
    die();
}

$result = install_run($input);
if (empty($result['errors'])) {
    install_send_result($result['steps']);
} else {
    foreach ($result['steps'] as $step) {
        echo '<p class="notice notice-'.install_escape($step[0]).'">'
            .install_escape($step[1]).'</p>' ."\n";
    }
    install_send_form($input, $result['errors']);
}
install_send_foot();
