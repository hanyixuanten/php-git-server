<?php

/* Sends a first-run visitor to install.php. The target is derived from the
   current script path, so it is always a fixed same-directory location. */
function redirect_to_installer() {
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $target = $base.'/install.php';

    if (!file_exists(__DIR__.'/../install.php')) {
        $protocol = isset($_SERVER['SERVER_PROTOCOL'])
            ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($protocol.' 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'config.php is missing and install.php is not available.';
        die();
    }

    $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    header($protocol.' 303 See Other');
    header('Location: '.$target);
    header('Cache-Control: no-store');
    die();
}
