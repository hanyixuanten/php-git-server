<?php

/* Bilingual user interface strings. English is the fallback: a key missing from
   the active catalog is looked up in English, and an unknown key is returned as
   is so a missing translation stays visible instead of rendering an empty page.

   Only browser output is translated. Plain-text HTTP and Git protocol responses
   stay in English because they are read by tooling rather than by people. */

function i18n_supported_languages() {
    return array('en', 'zh');
}

function i18n_fallback_language() {
    return 'en';
}

function i18n_cookie_name() {
    return 'PHPGITSERVERLANG';
}

function i18n_language_is_supported($value) {
    return is_string($value) && in_array($value, i18n_supported_languages(), TRUE);
}

/* Accept-Language is only inspected for a language tag, never a region, so
   zh-Hans, zh-TW and zh all select the Chinese catalog. */
function i18n_detect_language() {
    $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if ($header === '' || strlen($header) > 512) {
        return i18n_fallback_language();
    }

    $best = i18n_fallback_language();
    $best_quality = -1.0;
    foreach (explode(',', $header) as $entry) {
        $parts = explode(';', $entry);
        $tag = strtolower(trim($parts[0]));
        if ($tag === '') {
            continue;
        }

        $quality = 1.0;
        for ($index = 1; $index < count($parts); $index += 1) {
            $parameter = trim($parts[$index]);
            if (strncasecmp($parameter, 'q=', 2) === 0) {
                $quality = (float) substr($parameter, 2);
            }
        }

        $primary = explode('-', $tag);
        $language = $primary[0] === '*' ? i18n_fallback_language() : $primary[0];
        if (!i18n_language_is_supported($language)) {
            continue;
        }

        if ($quality > $best_quality) {
            $best = $language;
            $best_quality = $quality;
        }
    }

    return $best_quality < 0 ? i18n_fallback_language() : $best;
}

/* Resolution order: an explicit ?lang= request, a previously remembered choice,
   the configured language, then Accept-Language. */
function i18n_configure($configured=NULL, $url_base='') {
    $requested = isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : '';
    if (i18n_language_is_supported($requested)) {
        i18n_remember_language($requested, $url_base);
        return i18n_language($requested);
    }

    $remembered = isset($_COOKIE[i18n_cookie_name()])
        && is_string($_COOKIE[i18n_cookie_name()]) ? $_COOKIE[i18n_cookie_name()] : '';
    if (i18n_language_is_supported($remembered)) {
        return i18n_language($remembered);
    }

    if (i18n_language_is_supported($configured)) {
        return i18n_language($configured);
    }

    return i18n_language(i18n_detect_language());
}

function i18n_remember_language($language, $url_base) {
    if (headers_sent()) {
        return FALSE;
    }

    $base = rtrim((string) $url_base, '/');
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
        && strcasecmp((string) $_SERVER['HTTPS'], 'off') !== 0;
    return setcookie(i18n_cookie_name(), $language, array(
        'expires' => time() + 31536000,
        'path' => $base === '' ? '/' : $base.'/',
        'secure' => $secure,
        'httponly' => FALSE,
        'samesite' => 'Lax'));
}

function i18n_language($language=NULL) {
    static $active = NULL;

    if ($language !== NULL) {
        $active = i18n_language_is_supported($language)
            ? $language : i18n_fallback_language();
    }

    return $active === NULL ? i18n_fallback_language() : $active;
}

function i18n_html_lang() {
    return i18n_language() === 'zh' ? 'zh-CN' : 'en';
}

function t($key, $arguments=array()) {
    $catalogs = i18n_catalogs();
    $language = i18n_language();

    if (isset($catalogs[$language][$key])) {
        $value = $catalogs[$language][$key];
    } else if (isset($catalogs[i18n_fallback_language()][$key])) {
        $value = $catalogs[i18n_fallback_language()][$key];
    } else {
        return $key;
    }

    if (empty($arguments)) {
        return $value;
    }

    $replacements = array();
    foreach ($arguments as $name => $argument) {
        $replacements['{'.$name.'}'] = (string) $argument;
    }
    return strtr($value, $replacements);
}

/* Renders the language links. The caller supplies the page URL because each
   entry point has its own address, and none of them use a query string. */
function i18n_language_switcher($url) {
    $labels = array('en' => 'English', 'zh' => '中文');
    $active = i18n_language();
    $links = array();

    foreach (i18n_supported_languages() as $language) {
        $label = htmlspecialchars($labels[$language], ENT_QUOTES, 'UTF-8');
        if ($language === $active) {
            $links[] = '<strong lang="'.($language === 'zh' ? 'zh-CN' : 'en').'">'
                .$label.'</strong>';
            continue;
        }

        $href = htmlspecialchars($url.'?lang='.$language, ENT_QUOTES, 'UTF-8');
        $links[] = '<a lang="'.($language === 'zh' ? 'zh-CN' : 'en').'" href="'.$href.'">'
            .$label.'</a>';
    }

    return '<span class="language-switcher">'.implode(' · ', $links).'</span>';
}

function i18n_translate_markup($markup) {
    $markup = str_replace('__LANG__', htmlspecialchars(i18n_html_lang(), ENT_QUOTES, 'UTF-8'), $markup);
    $markup = str_replace('__TITLE__', htmlspecialchars(t('install.title'), ENT_QUOTES, 'UTF-8'), $markup);
    if (i18n_language() === 'en') {
        $catalog = i18n_catalog_zh();
        $english = i18n_catalog_en();
        foreach ($catalog as $key => $source) {
            if (isset($english[$key]) && $source !== $english[$key]) {
                $markup = str_replace($source, $english[$key], $markup);
            }
        }
    }
    return $markup;
}

function i18n_catalogs() {
    static $catalogs = NULL;

    if ($catalogs === NULL) {
        $catalogs = array('en' => i18n_catalog_en(), 'zh' => i18n_catalog_zh());
    }

    return $catalogs;
}

function i18n_catalog_en() {
    return array(
        'app.name' => 'PHP Git Server',
        'language.label' => 'Language',

        /* Shared account results, used by the home page and the installer. */
        'notice.registered' => 'The account was registered and signed in.',
        'notice.logged_in' => 'Signed in.',
        'notice.logged_out' => 'Signed out.',
        'notice.registration_disabled' => 'New account registration is disabled.',
        'notice.invalid_username' => 'A username must be 3 to 64 letters, digits, dots,'
            .' hyphens or underscores.',
        'notice.invalid_password' => 'A password must be 8 to 72 characters and no more'
            .' than 72 bytes.',
        'notice.password_mismatch' => 'The two passwords do not match.',
        'notice.username_exists' => 'That username already exists or is unavailable.',
        'notice.invalid_credentials' => 'The username or password is incorrect.',
        'notice.invalid_token_name' => 'A token name is required and may be at most 80'
            .' characters.',
        'notice.token_created' => 'The access token was created. Copy it now; it cannot be'
            .' shown again after you leave this page.',
        'notice.token_revoked' => 'The access token was revoked.',
        'notice.invalid_token' => 'That access token does not exist or was already revoked.',
        'notice.session_unavailable' => 'A secure session cannot be established right now.',
        'notice.auth_database_unavailable' => 'The account database is unavailable right now.',

        'notice.repository_invalid_name' => 'The repository name is not valid.',
        'notice.repository_exists' => 'Repository {name} already exists.',
        'notice.repository_create_busy' => 'Another repository is being created. Try again'
            .' shortly.',
        'notice.repository_root_unavailable' => 'The repository directory is unavailable or'
            .' not writable.',
        'notice.repository_git_unavailable' => 'The Git initialization service is unavailable'
            .' right now.',
        'notice.repository_metadata_unsaved' => 'Repository ownership could not be saved.'
            .' Try again shortly.',
        'notice.repository_create_failed' => 'Repository creation failed. Check the server'
            .' log.',
        'notice.repository_created' => 'Repository {name} was created.',
        'notice.repository_deleted' => 'Repository {name} was deleted.',
        'notice.repository_record_deleted' => 'The record for {name} was deleted. A missing'
            .' or non-bare path was left unchanged.',
        'notice.repository_delete_forbidden' => 'Only the repository owner can delete it.',
        'notice.repository_configured_home' => 'That path is configured statically in'
            .' config.php and cannot be deleted from the web page.',
        'notice.repository_not_found' => 'The managed repository does not exist or is not'
            .' finished yet.',
        'notice.repository_busy' => 'The repository is busy with another operation. Try'
            .' again shortly.',
        'notice.repository_metadata_unavailable' => 'Repository ownership data is'
            .' unavailable right now.',
        'notice.repository_cleanup_failed' => 'The repository record was deleted but the'
            .' leftover directory could not be cleaned up. Check the server log.',
        'notice.repository_restore_failed' => 'Deletion failed and the repository directory'
            .' could not be restored. Check the server log immediately.',
        'notice.repository_delete_failed' => 'Repository deletion failed. Check the server'
            .' log.',

        /* Home page. */
        'home.title' => 'PHP Git Server',
        'home.lead' => 'The repositories below are published over HTTP with support for'
            .' clone, fetch and pull, and push can be enabled per repository.',
        'home.account_title' => 'Account and access tokens',
        'home.form_unavailable' => 'A secure form cannot be initialized right now.',
        'home.username' => 'Username',
        'home.password' => 'Password',
        'home.login' => 'Sign in',
        'home.register_username' => 'New username',
        'home.password_confirmation' => 'Confirm password',
        'home.register' => 'Register',
        'home.auth_hint' => 'Web sign-in uses your password. Git clone, pull and push use'
            .' your username and an access token.',
        'home.current_account' => 'Signed in as',
        'home.manage' => 'Manage',
        'home.logout' => 'Sign out',
        'home.token_name' => 'New token name',
        'home.token_name_placeholder' => 'work laptop',
        'home.create_token' => 'Create token',
        'home.token_list_unavailable' => 'The access token list cannot be read right now.',
        'home.token_never_used' => 'never used',
        'home.token_last_used' => 'last used {time}',
        'home.token_created_at' => 'created {time}',
        'home.revoke' => 'Revoke',
        'home.create_repository' => 'Create repository',
        'home.create_login_required' => 'Sign in to an application account to create a'
            .' repository.',
        'home.repository_name' => 'Repository name',
        'home.repository_name_hint' => 'Letters, digits, dots, hyphens and underscores are'
            .' allowed. The <code>.git</code> suffix is optional.',
        'home.visibility' => 'Visibility',
        'home.public' => 'Public',
        'home.private' => 'Private',
        'home.th_repository' => 'Repository',
        'home.th_owner' => 'Owner',
        'home.th_clone_url' => 'Clone URL',
        'home.th_default_branch' => 'Default branch',
        'home.th_branches' => 'Branches',
        'home.th_tags' => 'Tags',
        'home.th_access' => 'Access',
        'home.th_actions' => 'Actions',
        'home.badge_empty' => 'empty',
        'home.badge_no_branch' => 'no branch',
        'home.badge_unset' => 'unset',
        'home.badge_owner_push' => 'owner can push',
        'home.badge_read_only' => 'read only',
        'home.confirm_delete' => 'Confirm deletion',
        'home.delete' => 'Delete',
        'home.badge_none' => 'none',
        'home.public_repositories' => 'Public repositories',
        'home.private_repositories' => 'Private repositories',
        'home.no_public_repositories' => 'There are no public repositories yet.',
        'home.no_private_repositories' => 'There are no private repositories yet.',
        'home.empty_title' => 'No readable repository is configured.',
        'home.empty_body' => 'Copy <code>config.php.sample</code> to <code>config.php</code>,'
            .' set the repository paths in <code>$repos</code>, and make sure each'
            .' repository directory exists with the <code>read</code> option set to'
            .' <code>TRUE</code>.',
        'home.usage_title' => 'Usage',
        'home.usage_hint' => 'Push requires the <code>push</code> option on the repository.'
            .' When authentication is enabled it also requires an account username and an'
            .' access token.',
        'home.footer' => 'See <code>usage.md</code> for installation, configuration and'
            .' security details.',

        /* Installer. */
        'install.title' => 'Install - PHP Git Server',
        'install.heading' => 'PHP Git Server installation',
        'install.session_unavailable' => 'A secure session cannot be initialized, so the'
            .' installation cannot continue.',
        'install.lead' => 'No <code>config.php</code> was found in the project root. Prepare'
            .' an empty database and an account for it, then fill in the fields below to'
            .' import the schema, create the first administrator and generate the'
            .' configuration file.',
        'install.warning_http' => 'This is not an HTTPS connection. Passwords would be sent'
            .' in clear text. Use HTTPS, or open the installer from the local machine only.',
        'install.error_no_pdo_mysql' => 'The PHP <code>pdo_mysql</code> extension is not'
            .' enabled, so the installation cannot continue. Install it and restart PHP.',
        'install.error_not_writable' => 'The project directory is not writable, so'
            .' <code>config.php</code> cannot be generated. Adjust the directory'
            .' permissions and reload.',
        'install.legend_application' => 'Application',
        'install.label_url_base' => 'Base path',
        'install.hint_url_base' => 'Leave empty when deploying at the domain root.',
        'install.label_git_executable' => 'Git executable',
        'install.hint_git_executable' => 'Falls back to the pure PHP implementation when'
            .' unavailable.',
        'install.label_language' => 'Interface language',
        'install.language_auto' => 'Follow the browser (English fallback)',
        'install.language_en' => 'English',
        'install.language_zh' => 'Chinese',
        'install.label_registration_enabled' => 'Allow public registration of new accounts',
        'install.label_session_cookie_secure' => 'HTTPS terminates at a trusted reverse'
            .' proxy (set a Secure cookie)',
        'install.legend_database' => 'Database',
        'install.hint_database' => 'Create the database and its account yourself. The'
            .' installer does not create them and does not need root privileges. The'
            .' account needs SELECT, INSERT, UPDATE and DELETE on that database. If it can'
            .' also create tables, the installer imports the schema automatically.',
        'install.label_db_host' => 'Host',
        'install.hint_db_host' => 'Usually <code>localhost</code> on shared hosting.',
        'install.label_db_port' => 'Port',
        'install.label_db_name' => 'Database name',
        'install.hint_db_name' => 'Panel-generated names are usually prefixed, for example'
            .' <code>cpuser_git</code>.',
        'install.label_db_user' => 'Database account',
        'install.label_db_password' => 'Database account password',
        'install.hint_schema_import' => 'If the account cannot create tables, import'
            .' <code>schema.mysql.sql</code> with the phpMyAdmin in your hosting panel'
            .' first, then submit this page. Existing tables are skipped.',
        'install.legend_administrator' => 'Administrator account',
        'install.hint_administrator' => 'This account is written to'
            .' <code>$auth[\'administrators\']</code> and can reach the management page as'
            .' soon as the installation finishes.',
        'install.label_admin_username' => 'Username',
        'install.label_admin_password' => 'Password',
        'install.label_admin_password_confirmation' => 'Confirm password',
        'install.button_submit' => 'Start installation',

        'install.error_url_base' => 'The base path must start with / and must not end with'
            .' /. Leave it empty when deploying at the domain root.',
        'install.error_git_executable' => 'The Git executable path is not valid.',
        'install.error_db_host' => 'The database host name is not valid.',
        'install.error_db_port' => 'The database port is not valid.',
        'install.error_db_name' => 'A database name may contain only letters, digits,'
            .' underscores and hyphens, up to 64 characters.',
        'install.error_db_user' => 'A database username may contain only letters, digits,'
            .' underscores and hyphens, up to 64 characters.',
        'install.error_db_password' => 'The database password is required and may be at'
            .' most 255 characters.',
        'install.error_admin_username' => 'An administrator username must be 3 to 64'
            .' letters, digits, dots, hyphens or underscores.',
        'install.error_admin_password' => 'An administrator password must be 8 to 72'
            .' characters and no more than 72 bytes.',
        'install.error_admin_password_mismatch' => 'The two administrator passwords do not'
            .' match.',
        'install.error_pdo_mysql_missing' => 'The PHP pdo_mysql extension is not enabled.',
        'install.error_schema_unreadable' => 'schema.mysql.sql could not be read.',
        'install.error_schema_denied' => 'This database account cannot create tables.'
            .' Import schema.mysql.sql from the project root using the phpMyAdmin or the'
            .' database import feature of your hosting panel, then submit this page again.'
            .' The installer detects the existing tables and skips the import.',
        'install.error_delete_privilege' => 'This database account is missing the DELETE'
            .' privilege, so deleting repositories and users would fail. Grant DELETE to'
            .' the account in your hosting panel or database, then try again.',
        'install.error_database' => 'The database operation failed: {message}',
        'install.error_config_write' => 'config.php could not be written. Check that the'
            .' project directory is writable and that the file does not already exist.',
        'install.step_connected' => 'Connected with the database account.',
        'install.step_schema_imported' => 'Imported {count} tables.',
        'install.step_schema_present' => 'Existing pgit_ tables were found, so the import'
            .' was skipped.',
        'install.step_privileges_ok' => 'Confirmed the account has SELECT, INSERT, UPDATE'
            .' and DELETE.',
        'install.step_admin_created' => 'Administrator account {username} was created.',
        'install.step_admin_promoted' => 'Account {username} already existed and was made an'
            .' administrator.',
        'install.step_config_written' => 'config.php was written with mode 0600.',
        'install.complete' => 'Installation complete.',
        'install.next_steps_title' => 'Do this now',
        'install.next_steps_remove' => 'Delete the installer so it cannot run again if the'
            .' configuration is ever removed. With a shell:',
        'install.next_steps_shared_hosting' => 'On shared hosting, delete'
            .' <code>install.php</code> with the file manager in your panel or over FTP.',
        'install.next_steps_permissions' => 'Confirm that <code>config.php</code> is'
            .' readable only by the application process, and check the permissions on the'
            .' <code>repos</code> directory. See <code>usage.md</code> for details.',
        'install.link_home' => 'Go to the home page and sign in',

        /* Management page. */
        'manage.title' => 'Server management - PHP Git Server',
        'manage.heading' => 'PHP Git Server / management',
        'manage.administrator_label' => 'Administrator',
        'manage.back_to_home' => 'Back to the repository home page',
        'manage.create_user_title' => 'Create user',
        'manage.create_user_lead' => 'An account created by an administrator is active'
            .' immediately.',
        'manage.label_username' => 'Username',
        'manage.label_initial_password' => 'Initial password',
        'manage.label_password_confirmation' => 'Confirm password',
        'manage.button_create_user' => 'Create user',
        'manage.users_title' => 'Users',
        'manage.users_lead' => 'Deactivating an account immediately blocks web sign-in and'
            .' access token authentication. A user\'s repositories must be handled before'
            .' the user can be deleted.',
        'manage.users_unavailable' => 'The user list cannot be read right now.',
        'manage.users_empty' => 'There are no users yet.',
        'manage.th_user' => 'User',
        'manage.th_status' => 'Status',
        'manage.th_repositories' => 'Repositories',
        'manage.th_active_tokens' => 'Active tokens',
        'manage.th_created_at' => 'Created',
        'manage.th_account_actions' => 'Account actions',
        'manage.th_password' => 'Password',
        'manage.badge_administrator' => 'administrator',
        'manage.badge_current_account' => 'current account',
        'manage.badge_active' => 'active',
        'manage.badge_inactive' => 'inactive',
        'manage.button_deactivate' => 'Deactivate',
        'manage.button_activate' => 'Activate',
        'manage.button_revoke_all_tokens' => 'Revoke all tokens',
        'manage.confirm_delete_account' => 'Confirm account deletion',
        'manage.button_delete_user' => 'Delete user',
        'manage.summary_reset_password' => 'Reset password',
        'manage.placeholder_new_password' => 'New password',
        'manage.placeholder_confirm_new_password' => 'Confirm new password',
        'manage.button_save_password' => 'Save new password',
        'manage.repositories_title' => 'Managed repositories',
        'manage.repositories_lead' => 'These actions affect the database records and the'
            .' managed bare repositories in the <code>repos</code> directory.',
        'manage.repositories_unavailable' => 'The managed repository list cannot be read'
            .' right now.',
        'manage.repositories_empty' => 'There are no managed repositories yet.',
        'manage.th_repository' => 'Repository',
        'manage.th_owner_visibility' => 'Owner and visibility',
        'manage.th_delete' => 'Delete',
        'manage.aria_repository_owner' => 'Repository owner',
        'manage.aria_repository_visibility' => 'Repository visibility',
        'manage.user_deactivated_suffix' => ' (deactivated)',
        'manage.button_save' => 'Save',
        'manage.badge_not_deletable' => 'not deletable',
        'manage.confirm_permanent_delete' => 'Confirm permanent deletion',
        'manage.button_delete_repository' => 'Delete repository',
        'manage.state_root_unavailable' => 'root unavailable',
        'manage.state_configured' => 'statically configured',
        'manage.state_record_only' => 'record only',
        'manage.state_incomplete' => 'incomplete',
        'manage.state_ready' => 'ready',
        'manage.configured_title' => 'Configured repositories',
        'manage.configured_lead' => 'These repositories come from <code>config.php</code>.'
            .' The management page shows them read only and changes neither the'
            .' configuration nor the files.',
        'manage.configured_empty' => 'There are no configured repositories.',
        'manage.th_url' => 'URL',
        'manage.th_path' => 'Path',
        'manage.th_visibility' => 'Visibility',
        'manage.th_read_write' => 'Read / write',
        'manage.value_unset' => 'unset',
        'manage.value_read' => 'read',
        'manage.value_read_disabled' => 'read disabled',
        'manage.value_write' => 'write',
        'manage.value_read_only' => 'read only',
        'manage.public' => 'Public',
        'manage.private' => 'Private',

        'manage.user_created' => 'User {username} was created.',
        'manage.user_updated' => 'The account status was updated.',
        'manage.tokens_revoked' => 'Revoked {count} active access tokens.',
        'manage.password_updated' => 'The account password was reset.',
        'manage.user_deleted' => 'The user was deleted.',
        'manage.repository_updated' => 'The repository owner and visibility were updated.',
        'manage.repository_deleted' => 'Managed repository {name} was deleted.',
        'manage.repository_record_deleted' => 'The record for {name} was deleted. A missing'
            .' or non-bare path was left unchanged.',
        'manage.username_exists' => 'That username already exists.',
        'manage.invalid_user' => 'The user does not exist or the parameters are not valid.',
        'manage.invalid_owner' => 'The new repository owner does not exist or is'
            .' unavailable.',
        'manage.invalid_repository' => 'The repository does not exist or the parameters are'
            .' not valid.',
        'manage.administrator_protected' => 'An administrator listed in the configuration'
            .' cannot be deactivated or deleted.',
        'manage.self_protected' => 'The signed-in account cannot be deactivated or deleted.',
        'manage.user_owns_repositories' => 'This user still owns repositories. Transfer or'
            .' delete them first.',
        'manage.configured_owner' => 'This user owns a static repository from config.php.'
            .' Change the owner in the configuration first.',
        'manage.configured_repository' => 'That path is configured statically in config.php'
            .' and cannot be deleted from the management page.',
        'manage.confirmation_required' => 'The confirmation value is not valid. Confirm'
            .' again.',
        'manage.forbidden' => 'The repository permissions changed, so the operation was'
            .' refused.',
        'manage.not_found' => 'The managed repository directory or record does not exist.',
        'manage.repository_busy' => 'The repository is busy with another operation. Try'
            .' again shortly.',
        'manage.root_unavailable' => 'The repository directory is unavailable or not'
            .' writable.',
        'manage.cleanup_failed' => 'The repository record was deleted but the leftover'
            .' directory could not be cleaned up. Check the server log.',
        'manage.restore_failed' => 'Deletion failed and the repository directory could not'
            .' be restored. Check the server log immediately.',
        'manage.database_unavailable' => 'The management database is unavailable right now.',
        'manage.action_failed' => 'The operation failed. Check the server log.');
}

function i18n_catalog_zh() {
    return array(
        'app.name' => 'PHP Git 服务器',
        'language.label' => '语言',

        'notice.registered' => '账号已注册并登录。',
        'notice.logged_in' => '登录成功。',
        'notice.logged_out' => '已退出登录。',
        'notice.registration_disabled' => '当前不允许注册新账号。',
        'notice.invalid_username' => '用户名须为 3 至 64 个字母、数字、点、短横线或下划线。',
        'notice.invalid_password' => '密码长度须为 8 至 72 个字符，且不能超过 72 字节。',
        'notice.password_mismatch' => '两次输入的密码不一致。',
        'notice.username_exists' => '该用户名已存在或不可用。',
        'notice.invalid_credentials' => '用户名或密码错误。',
        'notice.invalid_token_name' => 'Token 名称不能为空，且最多 80 个字符。',
        'notice.token_created' => 'Access token 已创建。请立即保存，关闭页面后无法再次查看。',
        'notice.token_revoked' => 'Access token 已撤销。',
        'notice.invalid_token' => 'Access token 不存在或已撤销。',
        'notice.session_unavailable' => '当前无法建立安全会话。',
        'notice.auth_database_unavailable' => '认证数据库当前不可用。',

        'notice.repository_invalid_name' => '仓库名称格式无效。',
        'notice.repository_exists' => '仓库 {name} 已存在。',
        'notice.repository_create_busy' => '另一个仓库正在创建，请稍后重试。',
        'notice.repository_root_unavailable' => '仓库存放目录不可用或不可写。',
        'notice.repository_git_unavailable' => 'Git 初始化服务当前不可用。',
        'notice.repository_metadata_unsaved' => '仓库所有权信息无法保存，请稍后重试。',
        'notice.repository_create_failed' => '仓库创建失败，请检查服务器日志。',
        'notice.repository_created' => '仓库 {name} 已创建。',
        'notice.repository_deleted' => '仓库 {name} 已删除。',
        'notice.repository_record_deleted' => '仓库记录 {name} 已删除，非 bare 路径未作改动。',
        'notice.repository_delete_forbidden' => '只有仓库所有者可以删除该仓库。',
        'notice.repository_configured_home' => '该路径由 config.php 静态配置，不能从网页删除。',
        'notice.repository_not_found' => '托管仓库不存在或尚未创建完成。',
        'notice.repository_busy' => '仓库正在执行其他操作，请稍后重试。',
        'notice.repository_metadata_unavailable' => '仓库所有权信息当前不可用。',
        'notice.repository_cleanup_failed' => '仓库记录已删除，但残留目录清理失败，请检查服务器日志。',
        'notice.repository_restore_failed' => '删除失败且仓库目录无法恢复，请立即检查服务器日志。',
        'notice.repository_delete_failed' => '仓库删除失败，请检查服务器日志。',

        'home.title' => 'PHP Git 服务器',
        'home.lead' => '通过 HTTP 发布下列 Git 仓库，支持 clone、fetch、pull，并可按仓库启用 push。',
        'home.account_title' => '账号与 Access Token',
        'home.form_unavailable' => '当前无法初始化安全表单。',
        'home.username' => '用户名',
        'home.password' => '密码',
        'home.login' => '登录',
        'home.register_username' => '注册用户名',
        'home.password_confirmation' => '确认密码',
        'home.register' => '注册',
        'home.auth_hint' => '网页登录使用密码；Git clone、pull 和 push 使用用户名与 access token。',
        'home.current_account' => '当前账号',
        'home.manage' => '管理',
        'home.logout' => '退出',
        'home.token_name' => '新 Token 名称',
        'home.token_name_placeholder' => '工作电脑',
        'home.create_token' => '创建 Token',
        'home.token_list_unavailable' => '当前无法读取 access token 列表。',
        'home.token_never_used' => '从未使用',
        'home.token_last_used' => '最后使用 {time}',
        'home.token_created_at' => '创建于 {time}',
        'home.revoke' => '撤销',
        'home.create_repository' => '创建仓库',
        'home.create_login_required' => '需要先登录应用账号，才能创建仓库。',
        'home.repository_name' => '仓库名称',
        'home.repository_name_hint' => '可使用字母、数字、点、短横线和下划线；<code>.git</code> 后缀可省略。',
        'home.visibility' => '可见性',
        'home.public' => '公开',
        'home.private' => '私有',
        'home.th_repository' => '仓库',
        'home.th_owner' => '所有者',
        'home.th_clone_url' => '克隆地址',
        'home.th_default_branch' => '默认分支',
        'home.th_branches' => '分支',
        'home.th_tags' => '标签',
        'home.th_access' => '权限',
        'home.th_actions' => '操作',
        'home.badge_empty' => '空仓库',
        'home.badge_no_branch' => '未指向分支',
        'home.badge_unset' => '未设置',
        'home.badge_owner_push' => '所有者可推送',
        'home.badge_read_only' => '只读',
        'home.confirm_delete' => '确认删除',
        'home.delete' => '删除',
        'home.badge_none' => '无',
        'home.public_repositories' => '公开仓库',
        'home.private_repositories' => '私有仓库',
        'home.no_public_repositories' => '当前没有公开仓库。',
        'home.no_private_repositories' => '当前没有私有仓库。',
        'home.empty_title' => '当前没有可读取的仓库。',
        'home.empty_body' => '请复制 <code>config.php.sample</code> 为 <code>config.php</code>，'
            .'在 <code>$repos</code> 中配置仓库路径，并确认仓库目录存在且 <code>read</code> '
            .'选项为 <code>TRUE</code>。',
        'home.usage_title' => '使用方法',
        'home.usage_hint' => 'push 需要仓库启用 <code>push</code> 选项；启用认证时还需要'
            .'使用账号用户名和 access token。',
        'home.footer' => '详细安装、配置和安全说明见 <code>usage.md</code>。',

        'install.title' => '安装 - PHP Git 服务器',
        'install.heading' => 'PHP Git 服务器安装',
        'install.session_unavailable' => '当前无法初始化安全会话，无法继续安装。',
        'install.lead' => '检测到项目根目录没有 <code>config.php</code>。请先准备好一个空数据库'
            .'和对应账号，填写以下信息即可导入表结构、创建首个管理员账号并生成配置文件。',
        'install.warning_http' => '当前不是 HTTPS 连接。密码将以明文传输，'
            .'建议改用 HTTPS 或仅从本机访问安装页面。',
        'install.error_no_pdo_mysql' => 'PHP 未启用 <code>pdo_mysql</code> 扩展，'
            .'安装无法继续。请先安装该扩展并重启 PHP。',
        'install.error_not_writable' => '项目目录不可写，无法生成 <code>config.php</code>。'
            .'请调整目录权限后刷新。',
        'install.legend_application' => '应用',
        'install.label_url_base' => '基础路径',
        'install.hint_url_base' => '部署在域名根路径时留空。',
        'install.label_git_executable' => 'Git 可执行文件',
        'install.hint_git_executable' => '不可用时会回退到纯 PHP 实现。',
        'install.label_language' => '界面语言',
        'install.language_auto' => '跟随浏览器（默认英语）',
        'install.language_en' => 'English',
        'install.language_zh' => '中文',
        'install.label_registration_enabled' => '允许公开注册新账号',
        'install.label_session_cookie_secure' => 'HTTPS 在可信反向代理终止（设置 Secure Cookie）',
        'install.legend_database' => '数据库',
        'install.hint_database' => '请先自行创建好数据库和账号，安装器不会创建它们，也不需要 root 权限。'
            .'账号需对该库具有 SELECT、INSERT、UPDATE 和 DELETE 权限；'
            .'若还具有建表权限，安装器会自动导入表结构。',
        'install.label_db_host' => '主机',
        'install.hint_db_host' => '共享主机通常是 <code>localhost</code>。',
        'install.label_db_port' => '端口',
        'install.label_db_name' => '数据库名',
        'install.hint_db_name' => '面板生成的名称通常带前缀，例如 <code>cpuser_git</code>。',
        'install.label_db_user' => '数据库账号',
        'install.label_db_password' => '数据库账号密码',
        'install.hint_schema_import' => '若该账号没有建表权限，请先用面板的 phpMyAdmin 导入 '
            .'<code>schema.mysql.sql</code>，再提交本页面；已存在的表会被自动跳过。',
        'install.legend_administrator' => '管理员账号',
        'install.hint_administrator' => '该账号会写入 <code>$auth[\'administrators\']</code>，'
            .'安装完成后即可访问管理界面。',
        'install.label_admin_username' => '用户名',
        'install.label_admin_password' => '密码',
        'install.label_admin_password_confirmation' => '确认密码',
        'install.button_submit' => '开始安装',

        'install.error_url_base' => '基础路径必须以 / 开头，且不能以 / 结尾；部署在域名根路径时请留空。',
        'install.error_git_executable' => 'Git 可执行文件路径无效。',
        'install.error_db_host' => '数据库主机名无效。',
        'install.error_db_port' => '数据库端口无效。',
        'install.error_db_name' => '数据库名只能包含字母、数字、下划线和短横线，最多 64 个字符。',
        'install.error_db_user' => '数据库用户名只能包含字母、数字、下划线和短横线，最多 64 个字符。',
        'install.error_db_password' => '数据库密码不能为空，且最多 255 个字符。',
        'install.error_admin_username' => '管理员用户名须为 3 至 64 个字母、数字、点、短横线或下划线。',
        'install.error_admin_password' => '管理员密码长度须为 8 至 72 个字符，且不能超过 72 字节。',
        'install.error_admin_password_mismatch' => '两次输入的管理员密码不一致。',
        'install.error_pdo_mysql_missing' => 'PHP 未启用 pdo_mysql 扩展。',
        'install.error_schema_unreadable' => '无法读取 schema.mysql.sql。',
        'install.error_schema_denied' => '该数据库账号没有建表权限。请用主机面板的 phpMyAdmin 或数据库导入功能'
            .'导入项目根目录的 schema.mysql.sql，然后重新提交本页面；'
            .'安装器会自动识别已存在的表并跳过导入。',
        'install.error_delete_privilege' => '该数据库账号缺少 DELETE 权限，删除仓库和用户会失败。'
            .'请在主机面板或数据库中为该账号授予 DELETE 权限后重试。',
        'install.error_database' => '数据库操作失败：{message}',
        'install.error_config_write' => 'config.php 写入失败。请确认项目目录可写，或该文件是否已存在。',
        'install.step_connected' => '已使用数据库账号连接成功。',
        'install.step_schema_imported' => '已导入 {count} 张表。',
        'install.step_schema_present' => '检测到已存在的 pgit_ 表，跳过导入。',
        'install.step_privileges_ok' => '已确认账号具备 SELECT、INSERT、UPDATE 和 DELETE 权限。',
        'install.step_admin_created' => '管理员账号 {username} 已创建。',
        'install.step_admin_promoted' => '账号 {username} 已存在，将其设为管理员。',
        'install.step_config_written' => 'config.php 已写入，权限设为 0600。',
        'install.complete' => '安装完成。',
        'install.next_steps_title' => '请立即完成以下操作',
        'install.next_steps_remove' => '删除安装脚本，避免它在配置被移除后再次可用。有 shell 时执行：',
        'install.next_steps_shared_hosting' => '共享主机可用面板的文件管理器或 FTP 直接删除 '
            .'<code>install.php</code>。',
        'install.next_steps_permissions' => '确认 <code>config.php</code> 仅应用进程可读，'
            .'并检查 <code>repos</code> 目录权限。详细说明见 <code>usage.md</code>。',
        'install.link_home' => '进入首页并登录',

        'manage.title' => '服务器管理 - PHP Git 服务器',
        'manage.heading' => 'PHP Git 服务器 / 管理',
        'manage.administrator_label' => '管理员',
        'manage.back_to_home' => '返回仓库首页',
        'manage.create_user_title' => '创建用户',
        'manage.create_user_lead' => '管理员创建的账号会立即启用。',
        'manage.label_username' => '用户名',
        'manage.label_initial_password' => '初始密码',
        'manage.label_password_confirmation' => '确认密码',
        'manage.button_create_user' => '创建用户',
        'manage.users_title' => '用户',
        'manage.users_lead' => '停用会立即阻止网页登录和 Access Token 认证。删除用户前必须先处理其仓库。',
        'manage.users_unavailable' => '当前无法读取用户列表。',
        'manage.users_empty' => '当前没有用户。',
        'manage.th_user' => '用户',
        'manage.th_status' => '状态',
        'manage.th_repositories' => '仓库',
        'manage.th_active_tokens' => '有效 Token',
        'manage.th_created_at' => '创建时间',
        'manage.th_account_actions' => '账号操作',
        'manage.th_password' => '密码',
        'manage.badge_administrator' => '管理员',
        'manage.badge_current_account' => '当前账号',
        'manage.badge_active' => '启用',
        'manage.badge_inactive' => '停用',
        'manage.button_deactivate' => '停用',
        'manage.button_activate' => '启用',
        'manage.button_revoke_all_tokens' => '撤销全部 Token',
        'manage.confirm_delete_account' => '确认删除账号',
        'manage.button_delete_user' => '删除用户',
        'manage.summary_reset_password' => '重置密码',
        'manage.placeholder_new_password' => '新密码',
        'manage.placeholder_confirm_new_password' => '确认新密码',
        'manage.button_save_password' => '保存新密码',
        'manage.repositories_title' => '托管仓库',
        'manage.repositories_lead' => '此处操作数据库记录与 <code>repos</code> 目录中的受管 bare 仓库。',
        'manage.repositories_unavailable' => '当前无法读取托管仓库列表。',
        'manage.repositories_empty' => '当前没有托管仓库。',
        'manage.th_repository' => '仓库',
        'manage.th_owner_visibility' => '所有者与可见性',
        'manage.th_delete' => '删除',
        'manage.aria_repository_owner' => '仓库所有者',
        'manage.aria_repository_visibility' => '仓库可见性',
        'manage.user_deactivated_suffix' => '（已停用）',
        'manage.button_save' => '保存',
        'manage.badge_not_deletable' => '不可删除',
        'manage.confirm_permanent_delete' => '确认永久删除',
        'manage.button_delete_repository' => '删除仓库',
        'manage.state_root_unavailable' => '根目录不可用',
        'manage.state_configured' => '静态配置',
        'manage.state_record_only' => '仅记录',
        'manage.state_incomplete' => '未完成',
        'manage.state_ready' => '可用',
        'manage.configured_title' => '配置仓库',
        'manage.configured_lead' => '这些仓库来自 <code>config.php</code>，管理界面只读显示，'
            .'不修改配置或文件。',
        'manage.configured_empty' => '当前没有配置仓库。',
        'manage.th_url' => 'URL',
        'manage.th_path' => '路径',
        'manage.th_visibility' => '可见性',
        'manage.th_read_write' => '读 / 写',
        'manage.value_unset' => '未设置',
        'manage.value_read' => '读',
        'manage.value_read_disabled' => '禁用读取',
        'manage.value_write' => '写',
        'manage.value_read_only' => '只读',
        'manage.public' => '公开',
        'manage.private' => '私有',

        'manage.user_created' => '用户 {username} 已创建。',
        'manage.user_updated' => '用户状态已更新。',
        'manage.tokens_revoked' => '已撤销 {count} 个有效 Access Token。',
        'manage.password_updated' => '用户密码已重置。',
        'manage.user_deleted' => '用户已删除。',
        'manage.repository_updated' => '仓库所有者与可见性已更新。',
        'manage.repository_deleted' => '托管仓库 {name} 已删除。',
        'manage.repository_record_deleted' => '仓库记录 {name} 已删除；缺失或非 bare 路径未作改动。',
        'manage.username_exists' => '该用户名已存在。',
        'manage.invalid_user' => '用户不存在或参数无效。',
        'manage.invalid_owner' => '新的仓库所有者不存在或不可用。',
        'manage.invalid_repository' => '仓库不存在或参数无效。',
        'manage.administrator_protected' => '配置中的管理员账号不能被停用或删除。',
        'manage.self_protected' => '不能停用或删除当前登录账号。',
        'manage.user_owns_repositories' => '该用户仍拥有仓库；请先转移或删除这些仓库。',
        'manage.configured_owner' => '该用户是 config.php 静态仓库的所有者；请先在配置中更换所有者。',
        'manage.configured_repository' => '该路径由 config.php 静态配置，不能从管理界面删除。',
        'manage.confirmation_required' => '操作确认值无效，请重新确认。',
        'manage.forbidden' => '仓库权限已变化，操作被拒绝。',
        'manage.not_found' => '托管仓库目录或记录不存在。',
        'manage.repository_busy' => '仓库正在执行其他操作，请稍后重试。',
        'manage.root_unavailable' => '仓库存放目录不可用或不可写。',
        'manage.cleanup_failed' => '仓库记录已删除，但残留目录清理失败，请检查服务器日志。',
        'manage.restore_failed' => '删除失败且仓库目录无法恢复，请立即检查服务器日志。',
        'manage.database_unavailable' => '管理数据库当前不可用。',
        'manage.action_failed' => '操作失败，请检查服务器日志。');
}
