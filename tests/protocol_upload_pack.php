<?php

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/git_fixture.php';

test_case('upload-pack advertises refs and capabilities', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);

        $output = test_capture_output(function () use ($fixture) {
            return git_upload_pack_advertise_native(git_fixture_repository($fixture));
        });

        test_assert_contains($history[0].' HEAD', $output);
        test_assert_contains($history[0].' refs/heads/main', $output);
        test_assert_contains('side-band-64k', $output);
        test_assert_contains('multi_ack_detailed', $output);
        test_assert_contains('no-done', $output);
        test_assert_contains('thin-pack', $output);
        test_assert_contains('include-tag', $output);
        test_assert_same('0000', substr($output, -4));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('upload-pack returns NAK and a valid pack for clone', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        $request = git_protocol_format_packet('want '.$history[0]."\n").'0000'
            .git_protocol_format_packet("done\n");
        $input = test_stream($request);
        $output = test_capture_output(function () use ($fixture, $input) {
            return git_upload_pack_rpc_native(git_fixture_repository($fixture), $input);
        });
        fclose($input);

        $nak = git_protocol_format_packet("NAK\n");
        test_assert_same($nak, substr($output, 0, strlen($nak)));
        $pack = substr($output, strlen($nak));
        test_assert_same('PACK', substr($pack, 0, 4));
        test_assert_same(hash('sha1', substr($pack, 0, -20), TRUE), substr($pack, -20));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('upload-pack ACKs a common commit and excludes its history', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[1]);
        $request = git_protocol_format_packet('want '.$history[1]."\n").'0000'
            .git_protocol_format_packet('have '.$history[0]."\n")
            .git_protocol_format_packet("done\n");
        $input = test_stream($request);
        $output = test_capture_output(function () use ($fixture, $input) {
            return git_upload_pack_rpc_native(git_fixture_repository($fixture), $input);
        });
        fclose($input);

        test_assert_same(
            git_protocol_format_packet('ACK '.$history[0]."\n"),
            substr($output, 0, 49));
        test_assert_same('PACK', substr($output, 49, 4));
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('upload-pack rejects an unadvertised want', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('advertised', 'hidden'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        $request = git_protocol_format_packet('want '.$history[1]."\n").'0000'
            .git_protocol_format_packet("done\n");
        $input = test_stream($request);
        $result = NULL;
        $output = '';
        ob_start();
        try {
            $result = git_upload_pack_rpc_native(git_fixture_repository($fixture), $input);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            fclose($input);
        }
        test_assert_false($result);
        test_assert_same('', $output);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('upload-pack reports detailed common and ready acknowledgments', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one', 'two'));
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[1]);
        $request = git_protocol_format_packet(
            'want '.$history[1]." multi_ack_detailed no-done\n").'0000'
            .git_protocol_format_packet('have '.$history[0]."\n").'0000';
        $input = test_stream($request);
        $output = test_capture_output(function () use ($fixture, $input) {
            return git_upload_pack_rpc_native(git_fixture_repository($fixture), $input);
        });
        fclose($input);

        test_assert_contains('ACK '.$history[0]." common\n", $output);
        test_assert_contains('ACK '.$history[0]." ready\n", $output);
        test_assert_contains('PACK', $output);
    } finally {
        test_remove_directory($fixture);
    }
});

test_case('upload-pack include-tag sends an annotated tag for a wanted commit', function () {
    $fixture = git_fixture_create();
    try {
        $history = git_fixture_history($fixture, array('one'));
        $tag = git_fixture_tag($fixture, $history[0]);
        git_fixture_write_ref($fixture, 'refs/heads/main', $history[0]);
        git_fixture_write_ref($fixture, 'refs/tags/v1.0.0', $tag);
        $request = git_protocol_format_packet(
            'want '.$history[0]." include-tag\n").'0000'.git_protocol_format_packet("done\n");
        $input = test_stream($request);
        $output = test_capture_output(function () use ($fixture, $input) {
            return git_upload_pack_rpc_native(git_fixture_repository($fixture), $input);
        });
        fclose($input);

        $nak = git_protocol_format_packet("NAK\n");
        $pack = substr($output, strlen($nak));
        $store = git_object_store_create($fixture);
        $objects = git_receive_pack_parse($pack, $store, 0, 0);
        test_assert_true(is_array($objects));
        test_assert_true(isset($objects[$tag]), 'Expected include-tag to add the tag object.');
    } finally {
        test_remove_directory($fixture);
    }
});
