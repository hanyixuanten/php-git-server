<?php

function register_service(&$services, $method, $pattern, $handler, $predicate=NULL) {
    $services[] = array(
        'method' => $method,
        'pattern' => $pattern,
        'handler' => $handler,
        'predicate' => $predicate);
}

function create_http_request($url_path, $repository) {
    return array(
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        'url_path' => $url_path,
        'path' => $repository['request_path'],
        'query' => $_GET,
        'content_type' => get_request_header('Content-Type'),
        'content_length' => get_request_header('Content-Length'),
        'content_encoding' => get_request_header('Content-Encoding'),
        'git_protocol' => get_request_header('Git-Protocol'),
        'user' => get_authenticated_user());
}

function request_has_service($request, $service) {
    return count($request['query']) === 1
        && isset($request['query']['service'])
        && $request['query']['service'] === $service;
}

function dispatch_service($services, $repository, $request, $application) {
    $allowed_methods = array();

    foreach ($services as $service) {
        if (!preg_match('~^'.$service['pattern'].'$~', $request['path'])) {
            continue;
        }

        if ($service['predicate'] !== NULL
            && !call_user_func($service['predicate'], $request)) {
            continue;
        }

        if ($request['method'] !== $service['method']) {
            $allowed_methods[$service['method']] = TRUE;
            continue;
        }

        call_user_func($service['handler'], $repository, $request, $application);
        die();
    }

    if (!empty($allowed_methods)) {
        send_status(405, 'Method Not Allowed');
        header('Allow: '.implode(', ', array_keys($allowed_methods)));
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        die();
    }

    send_error(404, 'Not Found', 'Resource not found.');
}
