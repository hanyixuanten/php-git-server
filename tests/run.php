<?php

require_once __DIR__.'/bootstrap.php';

$test_files = array(
    __DIR__.'/protocol_upload_pack.php',
    __DIR__.'/protocol_receive_pack.php',
    __DIR__.'/regression_unborn_head.php');

foreach ($test_files as $test_file) {
    require $test_file;
}

$passed = $GLOBALS['test_results']['passed'];
$failed = $GLOBALS['test_results']['failed'];
fwrite(STDOUT, "\n".$passed.' passed, '.$failed." failed\n");
exit($failed === 0 ? 0 : 1);
