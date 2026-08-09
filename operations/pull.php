<?php

function register_pull_services(&$services) {
    register_service(
        $services,
        'GET',
        '/info/refs',
        'pull_advertise_upload_pack',
        'pull_is_upload_pack_request');
    register_service($services, 'POST', '/git-upload-pack', 'pull_run_upload_pack');
}

function pull_is_upload_pack_request($request) {
    return request_has_service($request, 'git-upload-pack');
}

function pull_require_read_access($repository) {
    if (!$repository['options']['read']) {
        send_error(403, 'Forbidden', 'Repository reads are disabled.');
    }
}

function pull_advertise_upload_pack($repository, $request, $application) {
    pull_require_read_access($repository);
    git_service_advertise($application, $repository, $request, 'git-upload-pack');
}

function pull_run_upload_pack($repository, $request, $application) {
    pull_require_read_access($repository);

    if (!request_content_type_is($request['content_type'], 'application/x-git-upload-pack-request')) {
        send_error(415, 'Unsupported Media Type', 'Invalid upload-pack content type.');
    }

    $input = @fopen('php://input', 'rb');
    if ($input === FALSE) {
        send_error(400, 'Bad Request', 'Unable to read the Git request.');
    }

    git_service_rpc($application, $repository, $request, 'git-upload-pack', $input);
    fclose($input);
}
