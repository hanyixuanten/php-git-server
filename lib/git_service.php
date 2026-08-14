<?php

function git_service_native_available() {
    return function_exists('inflate_init')
        && function_exists('inflate_add')
        && function_exists('inflate_get_read_len')
        && function_exists('gzcompress')
        && function_exists('hash')
        && in_array('sha1', hash_algos(), TRUE);
}

function git_service_write_repository_file($path, $contents) {
    return @file_put_contents($path, $contents, LOCK_EX) === strlen($contents);
}

/* A newly-created repository starts with an unborn main branch. If the first
   push creates a differently named branch, make that branch the default. */
function git_service_update_unborn_head($git_path, $updated_refs) {
    $head = resolve_ref($git_path, 'HEAD');
    if ($head[1] !== NULL) {
        return TRUE;
    }

    $head_path = get_safe_file_path($git_path, '/HEAD');
    $contents = $head_path === FALSE ? FALSE : @file_get_contents($head_path);
    if ($contents === FALSE
        || !preg_match('~^ref:\s*(refs/heads/[^\r\n]+)\s*$~', $contents)) {
        return TRUE;
    }

    $branch = NULL;
    foreach ($updated_refs as $ref) {
        $resolved = resolve_ref($git_path, $ref);
        if (strpos($ref, 'refs/heads/') === 0 && $resolved[1] !== NULL) {
            $branch = $ref;
            break;
        }
    }
    if ($branch === NULL) {
        return TRUE;
    }

    $new_contents = "ref: ".$branch."\n";
    return @file_put_contents($git_path.'/HEAD', $new_contents, LOCK_EX)
        === strlen($new_contents);
}

function git_service_init_bare_repository($path) {
    $directories = array(
        '',
        '/branches',
        '/hooks',
        '/info',
        '/objects',
        '/objects/info',
        '/objects/pack',
        '/refs',
        '/refs/heads',
        '/refs/tags');

    foreach ($directories as $directory) {
        if (!@mkdir($path.$directory, 0777) && !is_dir($path.$directory)) {
            return FALSE;
        }
    }

    $files = array(
        '/HEAD' => "ref: refs/heads/main\n",
        '/config' => "[core]\n\trepositoryformatversion = 0\n\tfilemode = true\n\tbare = true\n",
        '/description' => "Unnamed repository; edit this file 'description' to name the repository.\n");

    foreach ($files as $name => $contents) {
        if (!git_service_write_repository_file($path.$name, $contents)) {
            return FALSE;
        }
    }

    return TRUE;
}

function git_service_create_managed_repository(
    $configuration,
    $value,
    $owner_user_id,
    $private) {
    $name = normalize_managed_repository_name($value);
    if ($name === FALSE) {
        return array('status' => 'invalid_name');
    }

    $root = managed_repository_root($configuration);
    if ($root === FALSE || !is_writable($root) || @scandir($root) === FALSE) {
        return array('status' => 'root_unavailable');
    }

    $path = $root.DIRECTORY_SEPARATOR.$name;
    $lock_path = $root.DIRECTORY_SEPARATOR.'.create.lock';
    $lock = @fopen($lock_path, 'c+b');
    if ($lock === FALSE) {
        $status = file_exists($path) || is_link($path) ? 'already_exists' : 'create_failed';
        return array('status' => $status, 'name' => $name);
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array('status' => 'create_busy', 'name' => $name);
    }

    $temporary_path = NULL;
    try {
        if (file_exists($path) || is_link($path)) {
            if (managed_repository_is_bare($path)) {
                $recovery = auth_recover_repository_metadata(
                    $name, (int) $owner_user_id, $private);
                if ($recovery['status'] === 'recovered') {
                    return array(
                        'status' => 'created',
                        'name' => $name,
                        'path' => $path,
                        'private' => (bool) $private);
                }
                if ($recovery['status'] === 'database_unavailable') {
                    return array('status' => 'metadata_unavailable', 'name' => $name);
                }
            }
            return array('status' => 'already_exists', 'name' => $name);
        }

        try {
            $suffix = bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            return array('status' => 'create_failed', 'name' => $name);
        }

        $temporary_path = $root.DIRECTORY_SEPARATOR.'.create-'.$suffix.'.tmp';
        if (!git_service_init_bare_repository($temporary_path)
            || !managed_repository_is_bare($temporary_path)) {
            return array('status' => 'create_failed', 'name' => $name);
        }

        if (file_exists($path) || is_link($path)) {
            return array('status' => 'already_exists', 'name' => $name);
        }

        $reservation = auth_reserve_repository_metadata(
            $name, (int) $owner_user_id, $private);
        if ($reservation['status'] === 'already_exists') {
            return array('status' => 'already_exists', 'name' => $name);
        }
        if ($reservation['status'] !== 'reserved') {
            return array('status' => 'metadata_unavailable', 'name' => $name);
        }

        if (!@rename($temporary_path, $path)) {
            return array('status' => 'create_failed', 'name' => $name);
        }

        $temporary_path = NULL;
        if (!auth_complete_repository_metadata(
            $reservation['id'], (int) $owner_user_id)) {
            return array('status' => 'metadata_unavailable', 'name' => $name);
        }

        return array(
            'status' => 'created',
            'name' => $name,
            'path' => $path,
            'private' => (bool) $private);
    } finally {
        if ($temporary_path !== NULL) {
            remove_managed_repository_directory($temporary_path);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function git_service_advertise($application, $repository, $request, $service) {
    if (!git_service_native_available()) {
        send_error(503, 'Service Unavailable', 'The native Git service requires PHP zlib and hash support.');
    }

    repository_header_nocache($repository);
    header('Content-Type: application/x-'.$service.'-advertisement');
    header('X-Content-Type-Options: nosniff');

    echo format_packet_line('# service='.$service."\n").'0000';
    if ($service === 'git-upload-pack') {
        if (!git_upload_pack_advertise_native($repository)) {
            error_log('Native Git advertisement failed for '.$repository['url'].'.');
        }
        return;
    }
    if ($service === 'git-receive-pack') {
        if (!git_receive_pack_advertise_native($repository)) {
            error_log('Native Git receive advertisement failed for '.$repository['url'].'.');
        }
        return;
    }

    send_error(403, 'Forbidden', 'Unsupported Git service.');
}

function git_service_rpc($application, $repository, $request, $service, $input) {
    if (!git_service_native_available()) {
        send_error(503, 'Service Unavailable', 'The native Git service requires PHP zlib and hash support.');
    }

    repository_header_nocache($repository);
    header('Content-Type: application/x-'.$service.'-result');
    header('X-Content-Type-Options: nosniff');

    if ($service === 'git-upload-pack') {
        if (!git_upload_pack_rpc_native($repository, $input)) {
            error_log('Native Git upload-pack failed for '.$repository['url'].'.');
        }
        return;
    }
    if ($service === 'git-receive-pack') {
        if (!git_receive_pack_rpc_native($repository, $input)) {
            error_log('Native Git receive-pack failed for '.$repository['url'].'.');
            return 1;
        }
        return 0;
    }

    send_error(403, 'Forbidden', 'Unsupported Git service.');
}
