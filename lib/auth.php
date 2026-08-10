<?php

$auth_configuration = array();
$auth_url_base = '';
$auth_cached_user_resolved = FALSE;
$auth_cached_user = NULL;

function auth_configure($configuration, $url_base) {
    global $auth_configuration, $auth_url_base;
    global $auth_cached_user_resolved, $auth_cached_user;

    $auth_configuration = is_array($configuration) ? $configuration : array();
    $auth_url_base = (string) $url_base;
    $auth_cached_user_resolved = FALSE;
    $auth_cached_user = NULL;
}

function auth_is_enabled() {
    global $auth_configuration;
    return !empty($auth_configuration)
        && (!isset($auth_configuration['enabled']) || $auth_configuration['enabled'] === TRUE);
}

function auth_registration_is_enabled() {
    global $auth_configuration;
    return auth_is_enabled()
        && (!isset($auth_configuration['registration_enabled'])
            || $auth_configuration['registration_enabled'] === TRUE);
}

function auth_user_is_administrator($user) {
    global $auth_configuration;

    $username = is_array($user) && isset($user['username'])
        ? $user['username'] : $user;
    if (!is_string($username)
        || !isset($auth_configuration['administrators'])
        || !is_array($auth_configuration['administrators'])) {
        return FALSE;
    }

    foreach ($auth_configuration['administrators'] as $administrator) {
        $normalized = auth_normalize_username($administrator);
        if ($normalized !== FALSE && hash_equals($normalized, $username)) {
            return TRUE;
        }
    }

    return FALSE;
}

function auth_session_cookie_is_secure() {
    global $auth_configuration;
    if (isset($auth_configuration['session_cookie_secure'])) {
        return $auth_configuration['session_cookie_secure'] === TRUE;
    }

    $https = isset($_SERVER['HTTPS']) ? strtolower((string) $_SERVER['HTTPS']) : '';
    return $https === 'on' || $https === '1'
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
}

function auth_session_path() {
    global $auth_url_base;
    $base = rtrim($auth_url_base, '/');
    return $base === '' ? '/' : $base.'/';
}

function auth_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return TRUE;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        return FALSE;
    }

    session_name('PHPGITSERVER');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => auth_session_path(),
        'secure' => auth_session_cookie_is_secure(),
        'httponly' => TRUE,
        'samesite' => 'Strict'));
    return @session_start();
}

function auth_database() {
    global $auth_configuration;
    static $connection = NULL;
    static $connection_key = NULL;

    if (!auth_is_enabled()) {
        return FALSE;
    }

    $database = isset($auth_configuration['database'])
        && is_array($auth_configuration['database'])
        ? $auth_configuration['database'] : array();
    $dsn = isset($database['dsn']) ? $database['dsn'] : '';
    $username = isset($database['username']) ? $database['username'] : '';
    $password = isset($database['password']) ? $database['password'] : '';
    if (!is_string($dsn) || $dsn === ''
        || !is_string($username) || !is_string($password)) {
        error_log('Authentication database configuration is invalid.');
        return FALSE;
    }

    $key = hash('sha256', $dsn."\0".$username);
    if ($connection instanceof PDO && $connection_key === $key) {
        return $connection;
    }

    try {
        $connection = new PDO($dsn, $username, $password, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => FALSE));
        $connection_key = $key;
        return $connection;
    } catch (PDOException $exception) {
        error_log('Authentication database connection failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_normalize_username($value) {
    if (!is_string($value)) {
        return FALSE;
    }

    $username = trim($value);
    if (!preg_match('~^(?=.{3,64}$)[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9_-]$~D', $username)) {
        return FALSE;
    }

    return $username;
}

function auth_password_is_valid($password) {
    return is_string($password)
        && strlen($password) <= 72
        && preg_match('~^.{8,72}$~usD', $password) === 1;
}

function auth_find_active_user_by_id($database, $user_id) {
    $statement = $database->prepare(
        'SELECT id, username FROM pgit_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $statement->execute(array($user_id));
    $user = $statement->fetch();
    return $user === FALSE ? NULL : $user;
}

function auth_find_user_by_id($user_id_value) {
    $user_id = auth_normalize_record_id($user_id_value);
    if ($user_id === FALSE) {
        return NULL;
    }

    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->prepare(
            'SELECT id, username, is_active, created_at, updated_at '
            .'FROM pgit_users WHERE id = ? LIMIT 1');
        $statement->execute(array($user_id));
        $user = $statement->fetch();
        return $user === FALSE ? NULL : $user;
    } catch (PDOException $exception) {
        error_log('User lookup failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_session_user() {
    if (!auth_is_enabled()) {
        return NULL;
    }
    if (session_status() !== PHP_SESSION_ACTIVE
        && (!isset($_COOKIE['PHPGITSERVER']) || !is_string($_COOKIE['PHPGITSERVER']))) {
        return NULL;
    }
    if (!auth_start_session()
        || !isset($_SESSION['auth_user_id']) || !is_int($_SESSION['auth_user_id'])) {
        return NULL;
    }

    $database = auth_database();
    if ($database === FALSE) {
        return NULL;
    }

    try {
        $user = auth_find_active_user_by_id($database, $_SESSION['auth_user_id']);
        if ($user === NULL) {
            unset($_SESSION['auth_user_id']);
        }
        return $user;
    } catch (PDOException $exception) {
        error_log('Authentication session lookup failed: '.$exception->getMessage());
        return NULL;
    }
}

function auth_basic_credentials() {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return array((string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']);
    }

    $authorization = get_request_header('Authorization');
    if (!is_string($authorization)
        || !preg_match('~^Basic[ \t]+([A-Za-z0-9+/]+={0,2})$~iD', trim($authorization), $matches)) {
        return NULL;
    }

    $decoded = base64_decode($matches[1], TRUE);
    if ($decoded === FALSE || strpos($decoded, ':') === FALSE) {
        return NULL;
    }

    return explode(':', $decoded, 2);
}

function auth_token_user() {
    if (!auth_is_enabled()) {
        return NULL;
    }

    $credentials = auth_basic_credentials();
    if ($credentials === NULL) {
        return NULL;
    }

    $username = auth_normalize_username($credentials[0]);
    $token = $credentials[1];
    if ($username === FALSE || !preg_match('~^pgs_[a-f0-9]{64}$~D', $token)) {
        return NULL;
    }

    $database = auth_database();
    if ($database === FALSE) {
        return NULL;
    }

    try {
        $statement = $database->prepare(
            'SELECT pgit_users.id, pgit_users.username, pgit_access_tokens.id AS token_id '
            .'FROM pgit_access_tokens JOIN pgit_users '
            .'ON pgit_users.id = pgit_access_tokens.user_id '
            .'WHERE pgit_users.username = ? AND pgit_users.is_active = 1 '
            .'AND pgit_access_tokens.token_hash = ? AND pgit_access_tokens.revoked_at IS NULL '
            .'AND (pgit_access_tokens.expires_at IS NULL '
            .'OR pgit_access_tokens.expires_at > CURRENT_TIMESTAMP) '
            .'LIMIT 1');
        $statement->execute(array($username, hash('sha256', $token)));
        $user = $statement->fetch();
        if ($user === FALSE) {
            return NULL;
        }

        $update = $database->prepare(
            'UPDATE pgit_access_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute(array($user['token_id']));
        unset($user['token_id']);
        return $user;
    } catch (PDOException $exception) {
        error_log('Access token lookup failed: '.$exception->getMessage());
        return NULL;
    }
}

function auth_register($username_value, $password, $password_confirmation) {
    if (!auth_registration_is_enabled()) {
        return array('status' => 'registration_disabled');
    }

    $username = auth_normalize_username($username_value);
    if ($username === FALSE) {
        return array('status' => 'invalid_username');
    }
    if (auth_user_is_administrator($username)) {
        return array('status' => 'username_exists');
    }
    if (!auth_password_is_valid($password)) {
        return array('status' => 'invalid_password');
    }
    if (!is_string($password_confirmation) || !hash_equals($password, $password_confirmation)) {
        return array('status' => 'password_mismatch');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'INSERT INTO pgit_users (username, password_hash) VALUES (?, ?)');
        $statement->execute(array($username, password_hash($password, PASSWORD_DEFAULT)));
        $user_id = (int) $database->lastInsertId();
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            return array('status' => 'username_exists');
        }
        error_log('User registration failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }

    if (!auth_start_session() || !session_regenerate_id(TRUE)) {
        return array('status' => 'session_unavailable');
    }
    $_SESSION['auth_user_id'] = $user_id;
    auth_reset_cached_user();
    return array('status' => 'registered', 'username' => $username);
}

function auth_create_user($username_value, $password, $password_confirmation) {
    $username = auth_normalize_username($username_value);
    if ($username === FALSE) {
        return array('status' => 'invalid_username');
    }
    if (!auth_password_is_valid($password)) {
        return array('status' => 'invalid_password');
    }
    if (!is_string($password_confirmation) || !hash_equals($password, $password_confirmation)) {
        return array('status' => 'password_mismatch');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'INSERT INTO pgit_users (username, password_hash) VALUES (?, ?)');
        $statement->execute(array($username, password_hash($password, PASSWORD_DEFAULT)));
        return array(
            'status' => 'user_created',
            'id' => (int) $database->lastInsertId(),
            'username' => $username);
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            return array('status' => 'username_exists');
        }
        error_log('Administrative user creation failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_set_user_password($user_id_value, $password, $password_confirmation) {
    $user_id = auth_normalize_record_id($user_id_value);
    if ($user_id === FALSE) {
        return array('status' => 'invalid_user');
    }
    if (!auth_password_is_valid($password)) {
        return array('status' => 'invalid_password');
    }
    if (!is_string($password_confirmation) || !hash_equals($password, $password_confirmation)) {
        return array('status' => 'password_mismatch');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'UPDATE pgit_users SET password_hash = ? WHERE id = ?');
        $statement->execute(array(password_hash($password, PASSWORD_DEFAULT), $user_id));
        return array('status' => $statement->rowCount() === 1
            ? 'password_updated' : 'invalid_user');
    } catch (PDOException $exception) {
        error_log('Administrative password reset failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_login($username_value, $password) {
    $dummy_hash = '$2y$12$1EmDXXQYPUpbo5wFP6frV.F5Qu6bsg2hw.q9wFG8DxlLRiEQaqcL.';
    $username = auth_normalize_username($username_value);
    if ($username === FALSE || !auth_password_is_valid($password)) {
        password_verify('', $dummy_hash);
        return array('status' => 'invalid_credentials');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'SELECT id, username, password_hash FROM pgit_users '
            .'WHERE username = ? AND is_active = 1 LIMIT 1');
        $statement->execute(array($username));
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('User login lookup failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }

    $hash = $user === FALSE ? $dummy_hash : $user['password_hash'];
    if (!password_verify($password, $hash) || $user === FALSE) {
        return array('status' => 'invalid_credentials');
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        try {
            $statement = $database->prepare(
                'UPDATE pgit_users SET password_hash = ? WHERE id = ?');
            $statement->execute(array(password_hash($password, PASSWORD_DEFAULT), $user['id']));
        } catch (PDOException $exception) {
            error_log('Password rehash failed: '.$exception->getMessage());
        }
    }

    if (!auth_start_session() || !session_regenerate_id(TRUE)) {
        return array('status' => 'session_unavailable');
    }
    $_SESSION['auth_user_id'] = (int) $user['id'];
    auth_reset_cached_user();
    return array('status' => 'logged_in', 'username' => $user['username']);
}

function auth_logout() {
    if (!auth_start_session()) {
        return FALSE;
    }

    unset(
        $_SESSION['auth_user_id'],
        $_SESSION['home_csrf_token'],
        $_SESSION['manage_csrf_token'],
        $_SESSION['manage_notice']);
    if (!session_regenerate_id(TRUE)) {
        return FALSE;
    }
    auth_reset_cached_user();
    return TRUE;
}

function auth_normalize_token_name($value) {
    if (!is_string($value)) {
        return FALSE;
    }

    $name = trim($value);
    if (!preg_match('~^.{1,80}$~usD', $name) || preg_match('~[\x00-\x1F\x7F]~', $name)) {
        return FALSE;
    }
    return $name;
}

function auth_create_access_token($user_id, $name_value) {
    $name = auth_normalize_token_name($name_value);
    if ($name === FALSE) {
        return array('status' => 'invalid_token_name');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $token = 'pgs_'.bin2hex(random_bytes(32));
        $statement = $database->prepare(
            'INSERT INTO pgit_access_tokens (user_id, name, token_hash) VALUES (?, ?, ?)');
        $statement->execute(array($user_id, $name, hash('sha256', $token)));
        return array('status' => 'token_created', 'name' => $name, 'token' => $token);
    } catch (Exception $exception) {
        error_log('Access token creation failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_list_access_tokens($user_id) {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->prepare(
            'SELECT id, name, created_at, last_used_at, expires_at '
            .'FROM pgit_access_tokens WHERE user_id = ? AND revoked_at IS NULL '
            .'ORDER BY created_at DESC, id DESC');
        $statement->execute(array($user_id));
        return $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Access token listing failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_revoke_access_token($user_id, $token_id) {
    if (!is_string($token_id) || !preg_match('~^[1-9][0-9]*$~D', $token_id)) {
        return array('status' => 'invalid_token');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'UPDATE pgit_access_tokens SET revoked_at = CURRENT_TIMESTAMP '
            .'WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $statement->execute(array($token_id, $user_id));
        return array('status' => $statement->rowCount() === 1 ? 'token_revoked' : 'invalid_token');
    } catch (PDOException $exception) {
        error_log('Access token revocation failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_normalize_record_id($value) {
    if (is_int($value)) {
        return $value > 0 ? $value : FALSE;
    }
    if (!is_string($value) || !preg_match('~^[1-9][0-9]*$~D', $value)) {
        return FALSE;
    }

    $id = (int) $value;
    return $id > 0 ? $id : FALSE;
}

function auth_list_users() {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->query(
            'SELECT pgit_users.id, pgit_users.username, pgit_users.is_active, '
            .'pgit_users.created_at, pgit_users.updated_at, '
            .'(SELECT COUNT(*) FROM pgit_access_tokens '
            .'WHERE pgit_access_tokens.user_id = pgit_users.id '
            .'AND pgit_access_tokens.revoked_at IS NULL) AS active_token_count, '
            .'(SELECT COUNT(*) FROM pgit_repositories '
            .'WHERE pgit_repositories.owner_user_id = pgit_users.id) AS repository_count '
            .'FROM pgit_users ORDER BY pgit_users.username');
        return $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('User administration listing failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_set_user_active($user_id_value, $active) {
    $user_id = auth_normalize_record_id($user_id_value);
    if ($user_id === FALSE || !is_bool($active)) {
        return array('status' => 'invalid_user');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        if (!$active) {
            $statement = $database->prepare(
                'SELECT username FROM pgit_users WHERE id = ? LIMIT 1');
            $statement->execute(array($user_id));
            $user = $statement->fetch();
            if ($user === FALSE) {
                return array('status' => 'invalid_user');
            }
            if (auth_user_is_administrator($user)) {
                return array('status' => 'administrator_protected');
            }
        }

        $statement = $database->prepare(
            'UPDATE pgit_users SET is_active = ? WHERE id = ?');
        $statement->execute(array($active ? 1 : 0, $user_id));
        if ($statement->rowCount() === 1) {
            return array('status' => 'user_updated');
        }

        $statement = $database->prepare('SELECT id FROM pgit_users WHERE id = ? LIMIT 1');
        $statement->execute(array($user_id));
        return array('status' => $statement->fetch() === FALSE
            ? 'invalid_user' : 'user_updated');
    } catch (PDOException $exception) {
        error_log('User status update failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_revoke_user_access_tokens($user_id_value) {
    $user_id = auth_normalize_record_id($user_id_value);
    if ($user_id === FALSE) {
        return array('status' => 'invalid_user');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare('SELECT id FROM pgit_users WHERE id = ? LIMIT 1');
        $statement->execute(array($user_id));
        if ($statement->fetch() === FALSE) {
            return array('status' => 'invalid_user');
        }

        $statement = $database->prepare(
            'UPDATE pgit_access_tokens SET revoked_at = CURRENT_TIMESTAMP '
            .'WHERE user_id = ? AND revoked_at IS NULL');
        $statement->execute(array($user_id));
        return array('status' => 'tokens_revoked', 'count' => $statement->rowCount());
    } catch (PDOException $exception) {
        error_log('User access token revocation failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_delete_user($user_id_value) {
    $user_id = auth_normalize_record_id($user_id_value);
    if ($user_id === FALSE) {
        return array('status' => 'invalid_user');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'SELECT username FROM pgit_users WHERE id = ? LIMIT 1');
        $statement->execute(array($user_id));
        $user = $statement->fetch();
        if ($user === FALSE) {
            return array('status' => 'invalid_user');
        }
        if (auth_user_is_administrator($user)) {
            return array('status' => 'administrator_protected');
        }

        $statement = $database->prepare('DELETE FROM pgit_users WHERE id = ?');
        $statement->execute(array($user_id));
        return array('status' => $statement->rowCount() === 1
            ? 'user_deleted' : 'invalid_user');
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            return array('status' => 'user_owns_repositories');
        }
        error_log('User deletion failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_list_repository_metadata() {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->query(
            'SELECT pgit_repositories.id, pgit_repositories.repository_name, '
            .'pgit_repositories.owner_user_id, pgit_repositories.is_private, '
            .'pgit_repositories.is_ready, pgit_repositories.created_at, '
            .'pgit_repositories.updated_at, pgit_users.username AS owner '
            .'FROM pgit_repositories JOIN pgit_users '
            .'ON pgit_users.id = pgit_repositories.owner_user_id '
            .'ORDER BY pgit_repositories.repository_name');
        return $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Repository administration listing failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_find_repository_metadata($name) {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->prepare(
            'SELECT pgit_repositories.id, pgit_repositories.repository_name, '
            .'pgit_repositories.owner_user_id, pgit_repositories.is_private, '
            .'pgit_repositories.is_ready, pgit_users.username AS owner '
            .'FROM pgit_repositories JOIN pgit_users '
            .'ON pgit_users.id = pgit_repositories.owner_user_id '
            .'WHERE pgit_repositories.repository_name = ? LIMIT 1');
        $statement->execute(array($name));
        $repository = $statement->fetch();
        return $repository === FALSE ? NULL : $repository;
    } catch (PDOException $exception) {
        error_log('Repository metadata lookup failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_update_repository_metadata(
    $repository_id_value,
    $owner_user_id_value,
    $private) {
    $repository_id = auth_normalize_record_id($repository_id_value);
    $owner_user_id = auth_normalize_record_id($owner_user_id_value);
    if ($repository_id === FALSE || $owner_user_id === FALSE || !is_bool($private)) {
        return array('status' => 'invalid_repository');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'SELECT owner_user_id FROM pgit_repositories WHERE id = ? LIMIT 1');
        $statement->execute(array($repository_id));
        $repository = $statement->fetch();
        if ($repository === FALSE) {
            return array('status' => 'invalid_repository');
        }

        if ((int) $repository['owner_user_id'] !== $owner_user_id) {
            $statement = $database->prepare(
                'SELECT id FROM pgit_users WHERE id = ? AND is_active = 1 LIMIT 1');
            $statement->execute(array($owner_user_id));
        }
        if ((int) $repository['owner_user_id'] !== $owner_user_id
            && $statement->fetch() === FALSE) {
            return array('status' => 'invalid_owner');
        }

        $statement = $database->prepare(
            'UPDATE pgit_repositories SET owner_user_id = ?, is_private = ? WHERE id = ?');
        $statement->execute(array($owner_user_id, $private ? 1 : 0, $repository_id));
        if ($statement->rowCount() === 1) {
            return array('status' => 'repository_updated');
        }

        $statement = $database->prepare(
            'SELECT id FROM pgit_repositories WHERE id = ? LIMIT 1');
        $statement->execute(array($repository_id));
        return array('status' => $statement->fetch() === FALSE
            ? 'invalid_repository' : 'repository_updated');
    } catch (PDOException $exception) {
        error_log('Repository metadata update failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_delete_repository_metadata($repository_id_value, $name, $owner_user_id_value=NULL) {
    $repository_id = auth_normalize_record_id($repository_id_value);
    $owner_user_id = $owner_user_id_value === NULL
        ? NULL : auth_normalize_record_id($owner_user_id_value);
    if ($repository_id === FALSE || !is_string($name)
        || ($owner_user_id_value !== NULL && $owner_user_id === FALSE)) {
        return array('status' => 'invalid_repository');
    }

    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $query = 'DELETE FROM pgit_repositories WHERE id = ? AND repository_name = ?';
        $values = array($repository_id, $name);
        if ($owner_user_id !== NULL) {
            $query .= ' AND owner_user_id = ?';
            $values[] = $owner_user_id;
        }

        $statement = $database->prepare($query);
        $statement->execute($values);
        return array('status' => $statement->rowCount() === 1
            ? 'metadata_deleted' : 'metadata_changed');
    } catch (PDOException $exception) {
        error_log('Repository metadata deletion failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_repository_metadata() {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->query(
            'SELECT pgit_repositories.repository_name, pgit_repositories.is_private, '
            .'pgit_repositories.is_ready, '
            .'pgit_users.username AS owner '
            .'FROM pgit_repositories JOIN pgit_users '
            .'ON pgit_users.id = pgit_repositories.owner_user_id');
        $metadata = array();
        foreach ($statement->fetchAll() as $repository) {
            $metadata[$repository['repository_name']] = array(
                'owner' => $repository['owner'],
                'private' => (bool) $repository['is_private'],
                'ready' => (bool) $repository['is_ready']);
        }
        return $metadata;
    } catch (PDOException $exception) {
        error_log('Repository metadata lookup failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_reserve_repository_metadata($name, $owner_user_id, $private) {
    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'INSERT INTO pgit_repositories '
            .'(repository_name, owner_user_id, is_private, is_ready) VALUES (?, ?, ?, 0)');
        $statement->execute(array($name, $owner_user_id, $private ? 1 : 0));
        return array('status' => 'reserved', 'id' => (int) $database->lastInsertId());
    } catch (PDOException $exception) {
        $driver_code = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        if ($driver_code !== 1062) {
            error_log('Repository metadata reservation failed: '.$exception->getMessage());
            return array('status' => 'database_unavailable');
        }
    }

    try {
        $statement = $database->prepare(
            'SELECT id, owner_user_id, is_ready FROM pgit_repositories '
            .'WHERE repository_name = ? LIMIT 1');
        $statement->execute(array($name));
        $metadata = $statement->fetch();
        if ($metadata === FALSE || (int) $metadata['is_ready'] !== 0
            || (int) $metadata['owner_user_id'] !== (int) $owner_user_id) {
            return array('status' => 'already_exists');
        }

        $update = $database->prepare(
            'UPDATE pgit_repositories SET is_private = ? WHERE id = ? AND is_ready = 0');
        $update->execute(array($private ? 1 : 0, $metadata['id']));
        return array('status' => 'reserved', 'id' => (int) $metadata['id']);
    } catch (PDOException $exception) {
        error_log('Repository metadata reservation recovery failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_complete_repository_metadata($metadata_id, $owner_user_id) {
    $database = auth_database();
    if ($database === FALSE) {
        return FALSE;
    }

    try {
        $statement = $database->prepare(
            'UPDATE pgit_repositories SET is_ready = 1 '
            .'WHERE id = ? AND owner_user_id = ? AND is_ready = 0');
        $statement->execute(array($metadata_id, $owner_user_id));
        return $statement->rowCount() === 1;
    } catch (PDOException $exception) {
        error_log('Repository metadata completion failed: '.$exception->getMessage());
        return FALSE;
    }
}

function auth_recover_repository_metadata($name, $owner_user_id, $private) {
    $database = auth_database();
    if ($database === FALSE) {
        return array('status' => 'database_unavailable');
    }

    try {
        $statement = $database->prepare(
            'UPDATE pgit_repositories SET is_private = ?, is_ready = 1 '
            .'WHERE repository_name = ? AND owner_user_id = ? AND is_ready = 0');
        $statement->execute(array($private ? 1 : 0, $name, $owner_user_id));
        return array('status' => $statement->rowCount() === 1 ? 'recovered' : 'not_found');
    } catch (PDOException $exception) {
        error_log('Repository metadata recovery failed: '.$exception->getMessage());
        return array('status' => 'database_unavailable');
    }
}

function auth_get_authenticated_user() {
    global $auth_cached_user_resolved, $auth_cached_user;
    if ($auth_cached_user_resolved) {
        return $auth_cached_user;
    }

    $auth_cached_user_resolved = TRUE;
    $user = auth_session_user();

    $auth_cached_user = $user === NULL ? NULL : $user['username'];
    return $auth_cached_user;
}

function auth_get_access_token_user() {
    $user = auth_token_user();
    return $user === NULL ? NULL : $user['username'];
}

function auth_reset_cached_user() {
    global $auth_cached_user_resolved, $auth_cached_user;
    $auth_cached_user_resolved = FALSE;
    $auth_cached_user = NULL;
}
