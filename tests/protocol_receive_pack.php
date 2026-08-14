<?php

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/git_fixture.php';

function receive_test_run($fixture, $updates, $pack='', $options=array()) {
    $result = receive_test_run_result($fixture, $updates, $pack, $options);
    return $result[1];
}

function receive_test_run_result($fixture, $updates, $pack='', $options=array()) {
    $input = test_stream(git_fixture_receive_input($updates, $pack));
    ob_start();
    try {
        $result = git_receive_pack_rpc_native(git_fixture_repository($fixture, $options), $input);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
        fclose($input);
    }
    return array($result, $output);
}

test_case('receive-pack does not advertise atomic before crash recovery exists', function () {
    test_assert_false(strpos(git_receive_pack_capabilities(), 'atomic') !== FALSE);
});

test_case('receive-pack accepts the first branch push', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one'));
        $pack = git_fixture_pack($fixture, array($history[0]));
        git_fixture_remove_loose_objects($fixture);
        test_assert_false(git_fixture_has_object($fixture, $history[0]));
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $history[0],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack ok\n", $output);
        test_assert_contains("ok refs/heads/main\n", $output);
        test_assert_same($history[0], resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack accepts a fast-forward update', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        $output = receive_test_run($fixture, array(array(
            'old' => $history[0],
            'new' => $history[1],
            'ref' => 'refs/heads/main')),
            git_fixture_pack($fixture, array($history[1])));

        test_assert_contains("unpack ok\n", $output);
        test_assert_same($history[1], resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a non-fast-forward update', function () {
    $fixture = git_fixture_create();
    try {
        $main = git_fixture_history($fixture, array('main'))[0];
        $other = git_fixture_history($fixture, array('other'))[0];
        git_fixture_write_ref($fixture, 'refs/heads/main', $main);
        $output = receive_test_run($fixture, array(array(
            'old' => $main,
            'new' => $other,
            'ref' => 'refs/heads/main')),
            git_fixture_pack($fixture, array($other)));

        test_assert_contains("unpack non-fast-forward\n", $output);
        test_assert_contains("ng refs/heads/main non-fast-forward\n", $output);
        test_assert_same($main, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack deletes an existing ref', function () {
    $fixture = git_fixture_create();
    try {
        $oid = git_fixture_history($fixture, array('one'))[0];
        git_fixture_write_ref($fixture, 'refs/heads/topic', $oid);
        $output = receive_test_run($fixture, array(array(
            'old' => $oid,
            'new' => str_repeat('0', 40),
            'ref' => 'refs/heads/topic')));

        test_assert_contains("unpack ok\n", $output);
        test_assert_contains("ok refs/heads/topic\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/topic')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack accepts an annotated tag', function () {
    $fixture = git_fixture_create();
    try {
        $commit = git_fixture_history($fixture, array('one'))[0];
        $tag = git_fixture_tag($fixture, $commit);
        $pack = git_fixture_pack($fixture, array($tag));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $tag,
            'ref' => 'refs/tags/v1.0.0')),
            $pack);

        test_assert_contains("unpack ok\n", $output);
        test_assert_same($tag, resolve_ref($fixture, 'refs/tags/v1.0.0')[1]);
        test_assert_true(git_fixture_has_object($fixture, $tag));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a corrupt pack without changing refs', function () {
    $fixture = git_fixture_create();
    try {
        $oid = git_fixture_history($fixture, array('one'))[0];
        $pack = git_fixture_pack($fixture, array($oid));
        git_fixture_remove_loose_objects($fixture);
        $pack[strlen($pack) - 1] = chr(ord($pack[strlen($pack) - 1]) ^ 1);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $oid,
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid pack\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
        test_assert_false(git_fixture_has_object($fixture, $oid));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects an object graph with a missing object', function () {
    $fixture = git_fixture_create();
    try {
        $missing_tree = str_repeat('a', 40);
        $body = 'tree '.$missing_tree."\n";
        $body .= "author Fixture <fixture@example.test> 1700000000 +0000\n";
        $body .= "committer Fixture <fixture@example.test> 1700000000 +0000\n\nmissing tree\n";
        $commit = git_fixture_object($fixture, 'commit', $body);
        $pack = git_fixture_pack_objects(array($commit));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $commit['oid'],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a commit without a tree header', function () {
    $fixture = git_fixture_create();
    try {
        $body = "author Fixture <fixture@example.test> 1700000000 +0000\n";
        $body .= "committer Fixture <fixture@example.test> 1700000000 +0000\n\nmissing tree\n";
        $commit = git_fixture_object($fixture, 'commit', $body);
        $pack = git_fixture_pack_objects(array($commit));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $commit['oid'],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a malformed tree entry mode', function () {
    $fixture = git_fixture_create();
    try {
        $blob = git_fixture_object($fixture, 'blob', "fixture\n");
        $tree = git_fixture_object($fixture, 'tree', "100600 fixture.txt\0".hex2bin($blob['oid']));
        $body = 'tree '.$tree['oid']."\n";
        $body .= "author Fixture <fixture@example.test> 1700000000 +0000\n";
        $body .= "committer Fixture <fixture@example.test> 1700000000 +0000\n\ninvalid tree\n";
        $commit = git_fixture_object($fixture, 'commit', $body);
        $pack = git_fixture_pack_objects(array($blob, $tree, $commit));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $commit['oid'],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects an annotated tag without a declared type', function () {
    $fixture = git_fixture_create();
    try {
        $commit = git_fixture_history($fixture, array('one'))[0];
        $body = 'object '.$commit."\n";
        $body .= "tag v1.0.0\n";
        $body .= "tagger Fixture <fixture@example.test> 1700000100 +0000\n\ninvalid tag\n";
        $tag = git_fixture_object($fixture, 'tag', $body);
        $pack = git_fixture_pack_objects(array($tag));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $tag['oid'],
            'ref' => 'refs/tags/v1.0.0')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/tags/v1.0.0')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a commit whose tree points to a blob', function () {
    $fixture = git_fixture_create();
    try {
        $blob = git_fixture_object($fixture, 'blob', "not a tree\n");
        $body = 'tree '.$blob['oid']."\n";
        $body .= "author Fixture <fixture@example.test> 1700000000 +0000\n";
        $body .= "committer Fixture <fixture@example.test> 1700000000 +0000\n\nwrong type\n";
        $commit = git_fixture_object($fixture, 'commit', $body);
        $pack = git_fixture_pack_objects(array($blob, $commit));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $commit['oid'],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a malformed parent header', function () {
    $fixture = git_fixture_create();
    try {
        $blob = git_fixture_object($fixture, 'blob', "fixture\n");
        $tree = git_fixture_object($fixture, 'tree', "100644 fixture.txt\0".hex2bin($blob['oid']));
        $body = 'tree '.$tree['oid']."\n";
        $body .= "parent not-an-object-id\n";
        $body .= "author Fixture <fixture@example.test> 1700000000 +0000\n";
        $body .= "committer Fixture <fixture@example.test> 1700000000 +0000\n\ninvalid parent\n";
        $commit = git_fixture_object($fixture, 'commit', $body);
        $pack = git_fixture_pack_objects(array($blob, $tree, $commit));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $commit['oid'],
            'ref' => 'refs/heads/main')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a tag whose declared type does not match its target', function () {
    $fixture = git_fixture_create();
    try {
        $blob = git_fixture_object($fixture, 'blob', "fixture\n");
        $body = 'object '.$blob['oid']."\n";
        $body .= "type commit\n";
        $body .= "tag v1.0.0\n";
        $body .= "tagger Fixture <fixture@example.test> 1700000100 +0000\n\nwrong type\n";
        $tag = git_fixture_object($fixture, 'tag', $body);
        $pack = git_fixture_pack_objects(array($blob, $tag));
        git_fixture_remove_loose_objects($fixture);
        $output = receive_test_run($fixture, array(array(
            'old' => str_repeat('0', 40),
            'new' => $tag['oid'],
            'ref' => 'refs/tags/v1.0.0')),
            $pack);

        test_assert_contains("unpack invalid object graph\n", $output);
        test_assert_same(NULL, resolve_ref($fixture, 'refs/tags/v1.0.0')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rejects a stale old OID without changing the ref', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two', 'three'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[1]);
        $output = receive_test_run($fixture, array(array(
            'old' => $history[0],
            'new' => $history[2],
            'ref' => 'refs/heads/main')),
            git_fixture_pack($fixture, array($history[2])));

        test_assert_contains("unpack stale or locked ref\n", $output);
        test_assert_contains("ng refs/heads/main stale or locked ref\n", $output);
        test_assert_same($history[1], resolve_ref($fixture, 'refs/heads/main')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack returns failure when refs are rejected', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[1]);
        $result = receive_test_run_result($fixture, array(array(
            'old' => $history[0],
            'new' => $history[1],
            'ref' => 'refs/heads/main')),
            git_fixture_pack($fixture, array($history[1])));

        test_assert_false($result[0]);
        test_assert_contains("ng refs/heads/main stale or locked ref\n", $result[1]);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('receive-pack rolls back earlier refs when a later commit fails', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        git_fixture_write_ref($fixture, 'refs/heads/topic', $history[0]);
        $updates = array(
            array('old' => $history[0], 'new' => $history[1], 'ref' => 'refs/heads/main'),
            array('old' => $history[0], 'new' => $history[1], 'ref' => 'refs/heads/topic'));
        $locks = git_receive_pack_lock_updates($fixture, $updates);
        test_assert_true(is_array($locks), 'Expected both refs to lock.');
        $locks[1]['path'] = $fixture.'/missing/refs/heads/topic';

        test_assert_false(git_receive_pack_commit_updates($fixture, $locks));
        test_assert_same($history[0], resolve_ref($fixture, 'refs/heads/main')[1]);
        test_assert_same($history[0], resolve_ref($fixture, 'refs/heads/topic')[1]);
    } finally {
        test_remove_directory($fixture);
    }
});
