<?php

require_once dirname(__DIR__).'/lib/repository.php';
require_once dirname(__DIR__).'/lib/git_protocol.php';
require_once dirname(__DIR__).'/lib/git_object_store.php';
require_once dirname(__DIR__).'/lib/git_upload_pack.php';
require_once dirname(__DIR__).'/lib/git_receive_pack.php';
require_once dirname(__DIR__).'/lib/git_service.php';

if (!isset($GLOBALS['test_results'])) {
    $GLOBALS['test_results'] = array('passed' => 0, 'failed' => 0);
}

function test_case($name, $callback) {
    try {
        $callback();
        $GLOBALS['test_results']['passed'] += 1;
        fwrite(STDOUT, "ok - ".$name."\n");
    } catch (Throwable $error) {
        $GLOBALS['test_results']['failed'] += 1;
        fwrite(STDERR, "not ok - ".$name."\n  ".$error->getMessage()."\n");
    }
}

function test_fail($message) {
    throw new RuntimeException($message);
}

function test_assert_true($value, $message='Expected value to be TRUE.') {
    if ($value !== TRUE) {
        test_fail($message);
    }
}

function test_assert_false($value, $message='Expected value to be FALSE.') {
    if ($value !== FALSE) {
        test_fail($message);
    }
}

function test_assert_same($expected, $actual, $message='') {
    if ($expected !== $actual) {
        $detail = 'Expected '.var_export($expected, TRUE).', got '.var_export($actual, TRUE).'.';
        test_fail($message === '' ? $detail : $message.' '.$detail);
    }
}

function test_assert_contains($needle, $haystack, $message='') {
    if (strpos($haystack, $needle) === FALSE) {
        $detail = 'Expected output to contain '.var_export($needle, TRUE).'.';
        test_fail($message === '' ? $detail : $message.' '.$detail);
    }
}

function test_capture_output($callback) {
    ob_start();
    $result = NULL;
    $output = '';
    try {
        $result = $callback();
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if ($result !== TRUE) {
        test_fail('Protocol function returned failure.');
    }
    return $output;
}

function test_stream($contents) {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === FALSE || fwrite($stream, $contents) !== strlen($contents)) {
        test_fail('Unable to create test input stream.');
    }
    rewind($stream);
    return $stream;
}

function test_remove_directory($path) {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        test_remove_directory($path.DIRECTORY_SEPARATOR.$entry);
    }
    @rmdir($path);
}

function test_protocol_packets($buffer) {
    $stream = test_stream($buffer);
    $packets = array();
    while (!feof($stream)) {
        $position = ftell($stream);
        $packet = git_protocol_read_packet($stream);
        if ($packet === FALSE) {
            fseek($stream, $position);
            break;
        }
        $packets[] = $packet;
    }
    fclose($stream);
    return $packets;
}
