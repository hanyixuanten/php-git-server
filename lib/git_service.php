<?php

function git_service_executable_available($application) {
    if (!isset($application['git_executable'])
        || !is_string($application['git_executable'])
        || $application['git_executable'] === '') {
        return FALSE;
    }

    $executable = $application['git_executable'];
    if (strpos($executable, DIRECTORY_SEPARATOR) !== FALSE) {
        return is_file($executable) && is_executable($executable);
    }

    $path = getenv('PATH');
    if ($path === FALSE) {
        return FALSE;
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$executable;
        if (is_file($candidate) && is_executable($candidate)) {
            return TRUE;
        }
    }

    return FALSE;
}

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

function git_service_init_bare_repository_with_php($path) {
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

function git_service_init_bare_repository($application, $path) {
    if (!function_exists('proc_open') || !git_service_executable_available($application)) {
        return git_service_init_bare_repository_with_php($path);
    }

    $error = @tmpfile();
    if ($error === FALSE) {
        return FALSE;
    }

    $descriptor_spec = array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => $error);
    $pipes = array();
    $command = array(
        $application['git_executable'],
        'init',
        '--bare',
        '--quiet',
        $path);
    $request = array('git_protocol' => NULL, 'user' => get_authenticated_user());
    $process = @proc_open(
        $command,
        $descriptor_spec,
        $pipes,
        dirname($path),
        git_service_environment($request));

    if (!is_resource($process)) {
        fclose($error);
        return FALSE;
    }

    $exit_code = proc_close($process);
    if ($exit_code !== 0) {
        rewind($error);
        $message = stream_get_contents($error);
        error_log(
            'Git repository initialization failed for '.$path.' with exit code '
            .$exit_code.': '.trim($message));
    }

    fclose($error);
    if ($exit_code !== 0) {
        return FALSE;
    }

    $head = "ref: refs/heads/main\n";
    return @file_put_contents($path.'/HEAD', $head, LOCK_EX) === strlen($head);
}

function git_service_create_managed_repository(
    $application,
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
        if (!git_service_init_bare_repository($application, $temporary_path)
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

function git_service_is_protocol_v2($request) {
    if ($request['git_protocol'] === NULL) {
        return FALSE;
    }

    return preg_match('~(?:^|:)version=2(?:$|:)~', $request['git_protocol']) === 1;
}

function git_service_environment($request) {
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = array();
    }

    unset($environment['GIT_DIR']);
    unset($environment['GIT_WORK_TREE']);
    unset($environment['GIT_PROTOCOL']);

    if ($request['git_protocol'] !== NULL
        && strlen($request['git_protocol']) <= 1024
        && strpos($request['git_protocol'], "\0") === FALSE
        && strpos($request['git_protocol'], "\n") === FALSE
        && strpos($request['git_protocol'], "\r") === FALSE) {
        $environment['GIT_PROTOCOL'] = $request['git_protocol'];
    }

    if ($request['user'] !== NULL) {
        $environment['REMOTE_USER'] = $request['user'];
    }

    if (isset($_SERVER['REMOTE_ADDR'])) {
        $environment['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
    }

    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $environment['GIT_HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'];
    }

    return $environment;
}

function git_service_command($application, $service, $repository, $advertise) {
    if ($service === 'git-upload-pack') {
        $subcommand = 'upload-pack';
    } else if ($service === 'git-receive-pack') {
        $subcommand = 'receive-pack';
    } else {
        return FALSE;
    }

    $command = array(
        $application['git_executable'],
        $subcommand,
        '--stateless-rpc');

    if ($subcommand === 'upload-pack') {
        $command[] = '--strict';
    }

    if ($advertise) {
        $command[] = '--advertise-refs';
    }

    $command[] = $repository['path'];
    return $command;
}

function git_service_pump_process($pipes, $input) {
    stream_set_blocking($pipes[0], FALSE);
    stream_set_blocking($pipes[1], FALSE);

    $input_done = $input === NULL;
    $input_buffer = '';
    $stdin_open = TRUE;
    $stdout_open = TRUE;

    while ($stdin_open || $stdout_open) {
        if (!$input_done && strlen($input_buffer) < 65536) {
            $chunk = fread($input, 65536);
            if ($chunk === FALSE) {
                return FALSE;
            }

            if ($chunk !== '') {
                $input_buffer .= $chunk;
            }

            if (feof($input)) {
                $input_done = TRUE;
            }
        }

        if ($stdin_open && $input_done && $input_buffer === '') {
            fclose($pipes[0]);
            $stdin_open = FALSE;
        }

        $read = $stdout_open ? array($pipes[1]) : array();
        $write = $stdin_open && $input_buffer !== '' ? array($pipes[0]) : array();
        $except = NULL;

        if (empty($read) && empty($write)) {
            continue;
        }

        $ready = @stream_select($read, $write, $except, 30);
        if ($ready === FALSE) {
            return FALSE;
        }

        if (!empty($write)) {
            $written = fwrite($pipes[0], $input_buffer);
            if ($written === FALSE) {
                return FALSE;
            }
            if ($written > 0) {
                $input_buffer = (string) substr($input_buffer, $written);
            }
        }

        if (!empty($read)) {
            $output = fread($pipes[1], 65536);
            if ($output === FALSE) {
                return FALSE;
            }

            if ($output !== '') {
                echo $output;
            }

            if (feof($pipes[1])) {
                fclose($pipes[1]);
                $stdout_open = FALSE;
            }
        }
    }

    return TRUE;
}

function git_service_run(
    $application,
    $repository,
    $request,
    $service,
    $advertise,
    $input=NULL,
    $prefix='') {
    if (!function_exists('proc_open') || !git_service_executable_available($application)) {
        send_error(503, 'Service Unavailable', 'Git Smart HTTP is not available.');
    }

    $command = git_service_command($application, $service, $repository, $advertise);
    if ($command === FALSE) {
        send_error(403, 'Forbidden', 'Unsupported Git service.');
    }

    $error = @tmpfile();
    if ($error === FALSE) {
        send_error(500, 'Internal Server Error', 'Unable to create a Git error stream.');
    }

    $descriptor_spec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => $error);
    $pipes = array();
    $process = @proc_open(
        $command,
        $descriptor_spec,
        $pipes,
        dirname($repository['path']),
        git_service_environment($request));

    if (!is_resource($process)) {
        fclose($error);
        send_error(503, 'Service Unavailable', 'Unable to start the Git service.');
    }

    if ($prefix !== '') {
        echo $prefix;
    }

    $stream_succeeded = git_service_pump_process($pipes, $input);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exit_code = proc_close($process);
    if (!$stream_succeeded || $exit_code !== 0) {
        rewind($error);
        $message = stream_get_contents($error);
        error_log(
            'Git service '.$service.' failed for '.$repository['url'].' with exit code '
            .$exit_code.': '.trim($message));
    }

    fclose($error);
    return $stream_succeeded && $exit_code === 0 ? 0 : $exit_code;
}

function git_service_advertise($application, $repository, $request, $service) {
    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && !git_service_native_available()) {
        send_error(503, 'Service Unavailable', 'The native Git service requires PHP zlib and hash support.');
    }

    repository_header_nocache($repository);
    header('Content-Type: application/x-'.$service.'-advertisement');
    header('X-Content-Type-Options: nosniff');

    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && $service === 'git-upload-pack') {
        echo format_packet_line('# service='.$service."\n").'0000';
        if (!git_upload_pack_advertise_native($repository)) {
            error_log('Native Git advertisement failed for '.$repository['url'].'.');
        }
        return;
    }
    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && $service === 'git-receive-pack') {
        echo format_packet_line('# service='.$service."\n").'0000';
        if (!git_receive_pack_advertise_native($repository)) {
            error_log('Native Git receive advertisement failed for '.$repository['url'].'.');
        }
        return;
    }

    $prefix = '';
    if (!git_service_is_protocol_v2($request)) {
        $prefix = format_packet_line('# service='.$service."\n").'0000';
    }

    git_service_run($application, $repository, $request, $service, TRUE, NULL, $prefix);
}

function git_service_rpc($application, $repository, $request, $service, $input) {
    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && !git_service_native_available()) {
        send_error(503, 'Service Unavailable', 'The native Git service requires PHP zlib and hash support.');
    }

    repository_header_nocache($repository);
    header('Content-Type: application/x-'.$service.'-result');
    header('X-Content-Type-Options: nosniff');

    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && $service === 'git-upload-pack') {
        if (!git_upload_pack_rpc_native($repository, $input)) {
            error_log('Native Git upload-pack failed for '.$repository['url'].'.');
        }
        return;
    }
    if ((!function_exists('proc_open') || !git_service_executable_available($application))
        && $service === 'git-receive-pack') {
        if (!git_receive_pack_rpc_native($repository, $input)) {
            error_log('Native Git receive-pack failed for '.$repository['url'].'.');
        }
        return;
    }

    git_service_run($application, $repository, $request, $service, FALSE, $input);
}
