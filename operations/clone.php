<?php

function register_clone_services(&$services) {
    register_service($services, 'GET', '/HEAD', 'clone_get_text_file');
    register_service($services, 'GET', '/info/refs', 'clone_get_info_refs');
    register_service($services, 'GET', '/objects/info/alternates', 'clone_get_text_file');
    register_service($services, 'GET', '/objects/info/http-alternates', 'clone_get_text_file');
    register_service($services, 'GET', '/objects/info/packs', 'clone_get_info_packs');
    register_service(
        $services,
        'GET',
        '/objects/[0-9a-f]{2}/(?:[0-9a-f]{38}|[0-9a-f]{62})',
        'clone_get_loose_object');
    register_service(
        $services,
        'GET',
        '/objects/pack/pack-(?:[0-9a-f]{40}|[0-9a-f]{64})\\.pack',
        'clone_get_pack_file');
    register_service(
        $services,
        'GET',
        '/objects/pack/pack-(?:[0-9a-f]{40}|[0-9a-f]{64})\\.idx',
        'clone_get_idx_file');
}

function clone_send_local_file($type, $repository, $name) {
    $path = get_safe_file_path($repository['path'], $name);
    if ($path === FALSE) {
        send_error(404, 'Not Found', 'Git object not found.');
    }

    $file = @fopen($path, 'rb');
    if (!$file) {
        send_error(404, 'Not Found', 'Git object not found.');
    }

    $stat = fstat($file);
    header('Content-Type: '.$type);
    header('Content-Length: '.$stat['size']);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $stat['mtime']).' GMT');
    header('X-Content-Type-Options: nosniff');

    fpassthru($file);
    fclose($file);
}

function clone_header_object_cache($repository) {
    if (repository_is_private($repository)) {
        header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');
        header('Pragma: no-cache');
        header('Cache-Control: private, no-store, max-age=0');
        return;
    }

    header_cache_forever();
}

function clone_get_text_file($repository, $request, $application) {
    repository_require_read_access($repository, $request);
    repository_header_nocache($repository);
    clone_send_local_file('text/plain; charset=utf-8', $repository, $request['path']);
}

function clone_get_loose_object($repository, $request, $application) {
    repository_require_read_access($repository, $request);
    clone_header_object_cache($repository);
    clone_send_local_file('application/x-git-loose-object', $repository, $request['path']);
}

function clone_get_pack_file($repository, $request, $application) {
    repository_require_read_access($repository, $request);
    clone_header_object_cache($repository);
    clone_send_local_file('application/x-git-packed-objects', $repository, $request['path']);
}

function clone_get_idx_file($repository, $request, $application) {
    repository_require_read_access($repository, $request);
    clone_header_object_cache($repository);
    clone_send_local_file(
        'application/x-git-packed-objects-toc', $repository, $request['path']);
}

function clone_get_info_refs($repository, $request, $application) {
    repository_require_read_access($repository, $request);

    if (!empty($request['query'])) {
        send_error(403, 'Forbidden', 'Unsupported Git service.');
    }

    repository_header_nocache($repository);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    foreach (get_repository_refs($repository['path']) as $ref) {
        echo $ref[1]."\t".$ref[0]."\n";
    }
}

function clone_get_info_packs($repository, $request, $application) {
    repository_require_read_access($repository, $request);
    repository_header_nocache($repository);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $pack_dir = get_safe_dir_path($repository['path'], '/objects/pack');
    if ($pack_dir === FALSE) {
        return;
    }

    $directory = @opendir($pack_dir);
    if ($directory === FALSE) {
        return;
    }

    $packs = array();
    while (($entry = readdir($directory)) !== FALSE) {
        if (preg_match('~^(pack-(?:[0-9a-f]{40}|[0-9a-f]{64}))\\.idx$~', $entry, $matches)) {
            $name = $matches[1];
            if (get_safe_file_path($repository['path'], '/objects/pack/'.$name.'.pack') !== FALSE) {
                $packs[] = $name;
            }
        }
    }
    closedir($directory);

    sort($packs);
    foreach ($packs as $name) {
        echo 'P '.$name.'.pack'."\n";
    }
}
