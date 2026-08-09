<?php

function git_protocol_read_exact($stream, $length) {
    $buffer = '';
    while (strlen($buffer) < $length && !feof($stream)) {
        $chunk = fread($stream, $length - strlen($buffer));
        if ($chunk === FALSE) {
            return FALSE;
        }
        if ($chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    return strlen($buffer) === $length ? $buffer : FALSE;
}

function git_protocol_read_packet($stream) {
    $header = git_protocol_read_exact($stream, 4);
    if ($header === FALSE || !preg_match('~^[0-9a-fA-F]{4}$~D', $header)) {
        return FALSE;
    }

    $length = hexdec($header);
    if ($length === 0) {
        return array('type' => 'flush', 'payload' => '');
    }
    if ($length === 1) {
        return array('type' => 'delim', 'payload' => '');
    }
    if ($length === 2) {
        return array('type' => 'response-end', 'payload' => '');
    }
    if ($length < 4 || $length > 65520) {
        return FALSE;
    }

    $payload = git_protocol_read_exact($stream, $length - 4);
    return $payload === FALSE ? FALSE : array('type' => 'data', 'payload' => $payload);
}

function git_protocol_format_packet($payload) {
    $length = strlen($payload) + 4;
    if ($length > 65520) {
        return FALSE;
    }

    return sprintf('%04x', $length).$payload;
}

function git_protocol_write_packet($stream, $payload) {
    $packet = git_protocol_format_packet($payload);
    return $packet !== FALSE && fwrite($stream, $packet) === strlen($packet);
}

function git_protocol_write_special($stream, $type) {
    $packets = array(
        'flush' => '0000',
        'delim' => '0001',
        'response-end' => '0002');

    return isset($packets[$type]) && fwrite($stream, $packets[$type]) === 4;
}