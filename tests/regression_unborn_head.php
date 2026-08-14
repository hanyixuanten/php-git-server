<?php

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/git_fixture.php';

test_case('unborn HEAD follows the first pushed branch', function () {
    $fixture = git_fixture_create();
    try {
        $oid = git_fixture_history($fixture, array('one'))[0];
        git_fixture_write_ref($fixture, 'refs/heads/master', $oid);
        test_assert_true(git_service_update_unborn_head(
            $fixture, array('refs/heads/master')));
        test_assert_same(
            "ref: refs/heads/master\n",
            file_get_contents($fixture.'/HEAD'));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('an established HEAD remains unchanged', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('main', 'master'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        git_fixture_write_ref($fixture, 'refs/heads/master', $history[1]);
        test_assert_true(git_service_update_unborn_head(
            $fixture, array('refs/heads/master')));
        test_assert_same(
            "ref: refs/heads/main\n",
            file_get_contents($fixture.'/HEAD'));
    } finally {
        test_remove_directory($fixture);
    }
});
