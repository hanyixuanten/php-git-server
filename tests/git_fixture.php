<?php

function git_fixture_create() {
    $path = sys_get_temp_dir().'/php-git-server-test-'.bin2hex(random_bytes(8)).'.git';
    if (!git_service_init_bare_repository($path)) {
        test_remove_directory($path);
        test_fail('Unable to initialize fixture repository.');
    }
    return $path;
}

function git_fixture_repository($path, $options=array()) {
    return array(
        'url' => '/fixture.git',
        'path' => $path,
        'options' => array_merge(repository_default_options(), $options));
}

function git_fixture_object($path, $type, $body, $write=TRUE) {
    $contents = $type.' '.strlen($body)."\0".$body;
    $oid = sha1($contents);
    if ($write) {
        $directory = $path.'/objects/'.substr($oid, 0, 2);
        if (!is_dir($directory) && !mkdir($directory, 0777, TRUE)) {
            test_fail('Unable to create fixture object directory.');
        }
        $compressed = gzcompress($contents);
        if ($compressed === FALSE
            || file_put_contents($directory.'/'.substr($oid, 2), $compressed) !== strlen($compressed)) {
            test_fail('Unable to write fixture object.');
        }
    }
    return array('type' => $type, 'body' => $body, 'oid' => $oid);
}

function git_fixture_history($path, $messages) {
    $blob = git_fixture_object($path, 'blob', "fixture\n");
    $tree = git_fixture_object($path, 'tree', "100644 fixture.txt\0".hex2bin($blob['oid']));
    $commits = array();
    $parent = NULL;
    foreach ($messages as $index => $message) {
        $body = 'tree '.$tree['oid']."\n";
        if ($parent !== NULL) {
            $body .= 'parent '.$parent."\n";
        }
        $timestamp = 1700000000 + $index;
        $body .= 'author Fixture <fixture@example.test> '.$timestamp." +0000\n";
        $body .= 'committer Fixture <fixture@example.test> '.$timestamp." +0000\n\n".$message."\n";
        $commit = git_fixture_object($path, 'commit', $body);
        $commits[] = $commit['oid'];
        $parent = $commit['oid'];
    }
    return $commits;
}

function git_fixture_tag($path, $target, $name='v1.0.0') {
    $body = 'object '.$target."\n";
    $body .= "type commit\n";
    $body .= 'tag '.$name."\n";
    $body .= "tagger Fixture <fixture@example.test> 1700000100 +0000\n\nfixture tag\n";
    $tag = git_fixture_object($path, 'tag', $body);
    return $tag['oid'];
}

function git_fixture_write_ref($path, $ref, $oid) {
    $ref_path = $path.'/'.$ref;
    if (!is_dir(dirname($ref_path)) && !mkdir(dirname($ref_path), 0777, TRUE)) {
        test_fail('Unable to create fixture ref directory.');
    }
    if (file_put_contents($ref_path, $oid."\n") !== 41) {
        test_fail('Unable to write fixture ref.');
    }
}

function git_fixture_pack_objects($objects) {
    $indexed = array();
    foreach ($objects as $object) {
        $indexed[$object['oid']] = $object;
    }
    $pack = git_object_store_build_pack($indexed);
    if ($pack === FALSE) {
        test_fail('Unable to build fixture pack.');
    }
    return $pack;
}

function git_fixture_pack($path, $oids) {
    $store = git_object_store_create($path);
    $objects = git_object_store_collect($store, $oids);
    if ($objects === FALSE) {
        test_fail('Unable to collect objects for fixture pack.');
    }
    return git_fixture_pack_objects(array_values($objects));
}

function git_fixture_remove_loose_objects($path) {
    foreach (scandir($path.'/objects') as $entry) {
        if (!preg_match('~^[0-9a-f]{2}$~D', $entry)) {
            continue;
        }
        test_remove_directory($path.'/objects/'.$entry);
    }
}

function git_fixture_has_object($path, $oid) {
    $store = git_object_store_create($path);
    return $store !== FALSE && git_object_store_read($store, $oid) !== FALSE;
}

function git_fixture_receive_input($updates, $pack='', $capabilities='report-status') {
    $input = '';
    foreach ($updates as $index => $update) {
        $payload = $update['old'].' '.$update['new'].' '.$update['ref'];
        if ($index === 0 && $capabilities !== '') {
            $payload .= "\0".$capabilities;
        }
        $input .= git_protocol_format_packet($payload."\n");
    }
    return $input.'0000'.$pack;
}
