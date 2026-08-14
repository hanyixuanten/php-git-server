<?php

require_once dirname(__DIR__).'/lib/repository.php';
require_once dirname(__DIR__).'/lib/git_service.php';

function test_remove_directory($path) {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entry_path = $path.'/'.$entry;
        if (is_dir($entry_path)) {
            test_remove_directory($entry_path);
        } else {
            unlink($entry_path);
        }
    }
    rmdir($path);
}

$path = sys_get_temp_dir().'/php-git-server-head-'.bin2hex(random_bytes(8)).'.git';
mkdir($path.'/refs/heads', 0777, TRUE);
mkdir($path.'/objects', 0777, TRUE);
file_put_contents($path.'/HEAD', "ref: refs/heads/main\n");
file_put_contents($path.'/refs/heads/master', str_repeat('1', 40)."\n");

try {
    git_service_update_unborn_head($path, array('refs/heads/master'));
    $head = file_get_contents($path.'/HEAD');
    if ($head !== "ref: refs/heads/master\n") {
        fwrite(STDERR, 'Expected HEAD to follow the first pushed branch, got: '.$head);
        exit(1);
    }

    file_put_contents($path.'/refs/heads/main', str_repeat('2', 40)."\n");
    git_service_update_unborn_head($path, array('refs/heads/master'));
    $head = file_get_contents($path.'/HEAD');
    if ($head !== "ref: refs/heads/master\n") {
        fwrite(STDERR, "Expected an existing HEAD target to remain unchanged.\n");
        exit(1);
    }
} finally {
    test_remove_directory($path);
}

fwrite(STDOUT, "unborn HEAD regression test passed\n");
