<?php

function register_push_services(&$services) {
    register_service(
        $services,
        'GET',
        '/info/refs',
        'push_advertise_receive_pack',
        'push_is_receive_pack_request');
    register_service($services, 'POST', '/git-receive-pack', 'push_run_receive_pack');
}

function push_is_receive_pack_request($request) {
    return request_has_service($request, 'git-receive-pack');
}

function push_require_access($repository, $request) {
    if (!$repository['options']['push']) {
        send_error(403, 'Forbidden', 'Repository pushes are disabled.');
    }

    if ($request['user'] === NULL) {
        require_authentication('A valid username and access token are required for push.');
    }

    if (!repository_user_is_owner($repository, $request['user'])) {
        send_error(403, 'Forbidden', 'Only the repository owner can push.');
    }
}

function push_advertise_receive_pack($repository, $request, $application) {
    push_require_access($repository, $request);
    git_service_advertise($application, $repository, $request, 'git-receive-pack');
}

function push_spool_request($request, $max_bytes) {
    if ($request['content_encoding'] !== NULL
        && strcasecmp($request['content_encoding'], 'identity')) {
        send_error(415, 'Unsupported Media Type', 'Compressed push requests are not supported.');
    }

    if ($max_bytes > 0 && $request['content_length'] !== NULL
        && (int) $request['content_length'] > $max_bytes) {
        send_error(413, 'Payload Too Large', 'The push request exceeds the configured limit.');
    }

    $source = @fopen('php://input', 'rb');
    $temporary = @tmpfile();
    if ($source === FALSE || $temporary === FALSE) {
        send_error(500, 'Internal Server Error', 'Unable to create push request streams.');
    }

    $total = 0;
    while (!feof($source)) {
        $buffer = fread($source, 65536);
        if ($buffer === FALSE) {
            fclose($source);
            fclose($temporary);
            send_error(400, 'Bad Request', 'Unable to read the push request.');
        }

        $total += strlen($buffer);
        if ($max_bytes > 0 && $total > $max_bytes) {
            fclose($source);
            fclose($temporary);
            send_error(413, 'Payload Too Large', 'The push request exceeds the configured limit.');
        }

        if ($buffer !== '' && fwrite($temporary, $buffer) !== strlen($buffer)) {
            fclose($source);
            fclose($temporary);
            send_error(500, 'Internal Server Error', 'Unable to buffer the push request.');
        }
    }

    fclose($source);
    rewind($temporary);
    return $temporary;
}

function push_read_exact($stream, $length) {
    $buffer = '';
    while (strlen($buffer) < $length && !feof($stream)) {
        $chunk = fread($stream, $length - strlen($buffer));
        if ($chunk === FALSE) {
            return FALSE;
        }
        $buffer .= $chunk;
    }

    return strlen($buffer) === $length ? $buffer : FALSE;
}

function push_parse_ref_command($command) {
    $command = rtrim($command, "\r\n");
    if (!preg_match(
        '~^(?:[0-9a-f]{40}|[0-9a-f]{64}) (?:[0-9a-f]{40}|[0-9a-f]{64}) ([^\x00-\x20\x7f]+)$~',
        $command,
        $matches)) {
        return FALSE;
    }

    return $matches[1];
}

function push_parse_certificate($stream, $updates) {
    $certificate_body = FALSE;

    while (TRUE) {
        $header = push_read_exact($stream, 4);
        if ($header === FALSE || !preg_match('~^[0-9a-fA-F]{4}$~', $header)) {
            return FALSE;
        }

        $length = hexdec($header);
        if ($length < 4 || $length > 65520) {
            return FALSE;
        }

        $payload = push_read_exact($stream, $length - 4);
        if ($payload === FALSE) {
            return FALSE;
        }

        if ($payload === "push-cert-end\n") {
            rewind($stream);
            return $updates;
        }

        if (!$certificate_body) {
            if ($payload === "\n") {
                $certificate_body = TRUE;
            }
            continue;
        }

        $ref = push_parse_ref_command($payload);
        if ($ref !== FALSE) {
            $updates[] = $ref;
        }
    }
}

function push_parse_updates($stream) {
    $updates = array();

    while (TRUE) {
        $header = push_read_exact($stream, 4);
        if ($header === FALSE || !preg_match('~^[0-9a-fA-F]{4}$~', $header)) {
            return FALSE;
        }

        $length = hexdec($header);
        if ($length === 0) {
            break;
        }

        if ($length < 4 || $length > 65520) {
            return FALSE;
        }

        $payload = push_read_exact($stream, $length - 4);
        if ($payload === FALSE) {
            return FALSE;
        }

        $command = explode("\0", $payload, 2);
        $command = rtrim($command[0], "\r\n");
        if (preg_match('~^shallow (?:[0-9a-f]{40}|[0-9a-f]{64})$~', $command)) {
            continue;
        }

        if ($command === 'push-cert') {
            return push_parse_certificate($stream, $updates);
        }

        $ref = push_parse_ref_command($command);
        if ($ref === FALSE) {
            return FALSE;
        }

        $updates[] = $ref;
    }

    rewind($stream);
    return $updates;
}

function push_validate_updates($repository, $updates, $application) {
    foreach ($updates as $ref) {
        $matched = FALSE;

        foreach ($application['push_ref_rules'] as $rule) {
            if (strpos($ref, $rule['prefix']) !== 0) {
                continue;
            }

            $matched = TRUE;
            if (!$repository['options'][$rule['option']]) {
                return ucfirst($rule['name']).' updates are disabled for this repository.';
            }
            break;
        }

        if (!$matched && !$repository['options']['other_refs']) {
            return 'Updates outside refs/heads and refs/tags are disabled.';
        }
    }

    return TRUE;
}

function push_run_receive_pack($repository, $request, $application) {
    push_require_access($repository, $request);

    if (!request_content_type_is($request['content_type'], 'application/x-git-receive-pack-request')) {
        send_error(415, 'Unsupported Media Type', 'Invalid receive-pack content type.');
    }

    $max_bytes = (int) $repository['options']['max_request_bytes'];
    $input = push_spool_request($request, $max_bytes);
    $updates = push_parse_updates($input);
    if ($updates === FALSE) {
        fclose($input);
        send_error(400, 'Bad Request', 'Invalid Git receive-pack request.');
    }

    $validation = push_validate_updates($repository, $updates, $application);
    if ($validation !== TRUE) {
        fclose($input);
        send_error(403, 'Forbidden', $validation);
    }

    $exit_code = git_service_rpc(
        $application, $repository, $request, 'git-receive-pack', $input);
    if ($exit_code === 0
        && !git_service_update_unborn_head($repository['path'], $updates)) {
        error_log('Unable to update unborn HEAD for '.$repository['url'].'.');
    }
    fclose($input);
}
