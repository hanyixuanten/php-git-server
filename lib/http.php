<?php

function send_status($code, $reason) {
    $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    header($protocol.' '.$code.' '.$reason);
}

function send_error($code, $reason, $message) {
    send_status($code, $reason);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    die();
}

function header_nocache() {
    header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');
    header('Pragma: no-cache');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
}

function header_cache_forever() {
    header('Expires: '.gmdate('D, d M Y H:i:s', time() + 31536000).' GMT');
    header('Cache-Control: public, max-age=31536000');
}

function get_request_header($name) {
    $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    if (!strcasecmp($name, 'Content-Type') && isset($_SERVER['CONTENT_TYPE'])) {
        return $_SERVER['CONTENT_TYPE'];
    }

    if (!strcasecmp($name, 'Content-Length') && isset($_SERVER['CONTENT_LENGTH'])) {
        return $_SERVER['CONTENT_LENGTH'];
    }

    return NULL;
}

function request_content_type_is($content_type, $expected) {
    if ($content_type === NULL) {
        return TRUE;
    }

    $parts = explode(';', $content_type, 2);
    return !strcasecmp(trim($parts[0]), $expected);
}

function get_authenticated_user() {
    if (isset($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
        return $_SERVER['REMOTE_USER'];
    }

    return NULL;
}

function format_packet_line($payload) {
    return sprintf('%04x', strlen($payload) + 4).$payload;
}

function send_packet_line($payload) {
    echo format_packet_line($payload);
}
