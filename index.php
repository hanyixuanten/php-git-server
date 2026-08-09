<?php

require(__DIR__.'/config.php');
require(__DIR__.'/lib/http.php');
require(__DIR__.'/lib/repository.php');
require(__DIR__.'/lib/router.php');
require(__DIR__.'/lib/git_service.php');
require(__DIR__.'/operations/branch.php');
require(__DIR__.'/operations/tag.php');
require(__DIR__.'/operations/clone.php');
require(__DIR__.'/operations/pull.php');
require(__DIR__.'/operations/push.php');

if (!isset($url_base)) {
    $url_base = '';
}

if (!isset($repos) || !is_array($repos)) {
    send_error(500, 'Internal Server Error', 'The repository configuration is invalid.');
}

if (!isset($git_executable)) {
    $git_executable = 'git';
}

$application = array(
    'git_executable' => $git_executable,
    'push_ref_rules' => array());
$services = array();

register_branch_operation($application);
register_tag_operation($application);
register_pull_services($services);
register_push_services($services);
register_clone_services($services);

$url_path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if ($url_path === FALSE) {
    $url_path = '';
}

$repository = find_configured_repository($url_base, $repos, $url_path);
if ($repository === FALSE) {
    send_error(404, 'Not Found', 'Repository not found.');
}

$request = create_http_request($url_path, $repository);
dispatch_service($services, $repository, $request, $application);
