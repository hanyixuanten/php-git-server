<?php

/* Ref parsing below is based on Git's http-backend implementation and the
   original PHP port by Jon Lund Steffensen, July 2011. Licensed under GPL2. */

function str_endswith($string, $test) {
    $string_length = strlen($string);
    $test_length = strlen($test);
    if ($test_length > $string_length) {
        return FALSE;
    }

    return substr_compare($string, $test, -$test_length) === 0;
}

function repository_default_options() {
    return array(
        'read' => TRUE,
        'push' => FALSE,
        'owner' => NULL,
        'private' => FALSE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE,
        'allow_non_fast_forward' => FALSE,
        'max_object_bytes' => 268435456,
        'max_pack_objects' => 100000,
        'max_request_bytes' => 0);
}

function repository_is_private($repository) {
    return !empty($repository['options']['private']);
}

function repository_user_is_owner($repository, $user) {
    $owner = isset($repository['options']['owner']) ? $repository['options']['owner'] : NULL;
    return is_string($owner) && $owner !== ''
        && is_string($user) && hash_equals($owner, $user);
}

function repository_require_private_access($repository, $request) {
    if (repository_is_private($repository) && $request['user'] === NULL) {
        require_authentication(
            'A valid username and access token are required to access this private repository.');
    }

    if (repository_is_private($repository)) {
        header('Cache-Control: private, no-store, max-age=0');
    }
}

function repository_require_read_access($repository, $request) {
    repository_require_private_access($repository, $request);

    if (!$repository['options']['read']) {
        send_error(403, 'Forbidden', 'Repository reads are disabled.');
    }
}

function repository_header_nocache($repository) {
    header_nocache();
    if (repository_is_private($repository)) {
        header('Cache-Control: private, no-store, max-age=0');
    }
}

function managed_repository_root($configuration) {
    if (!is_array($configuration) || empty($configuration)) {
        return FALSE;
    }

    $application_root = realpath(dirname(__DIR__));
    if ($application_root === FALSE) {
        return FALSE;
    }

    $path = $application_root.DIRECTORY_SEPARATOR.'repos';
    if (!file_exists($path) && !is_link($path)
        && !@mkdir($path, 0777) && !is_dir($path)) {
        return FALSE;
    }
    if (is_link($path)) {
        return FALSE;
    }

    $root = realpath($path);
    return $root === $path && is_dir($root) ? $root : FALSE;
}

function normalize_managed_repository_name($value) {
    if (!is_string($value)) {
        return FALSE;
    }

    $name = trim($value);
    if (str_endswith($name, '.git')) {
        $name = substr($name, 0, -4);
    }

    if (!preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9_-])?$~D', $name)) {
        return FALSE;
    }

    return $name.'.git';
}

function managed_repository_is_bare($path) {
    return !is_link($path)
        && is_dir($path)
        && get_safe_file_path($path, '/HEAD') !== FALSE
        && get_safe_dir_path($path, '/objects') !== FALSE
        && get_safe_dir_path($path, '/refs') !== FALSE;
}

function remove_managed_repository_directory($path) {
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }

    if (!is_dir($path)) {
        return TRUE;
    }

    $entries = @scandir($path);
    if ($entries === FALSE) {
        return FALSE;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (!remove_managed_repository_directory($path.DIRECTORY_SEPARATOR.$entry)) {
            return FALSE;
        }
    }

    return @rmdir($path);
}

function managed_repository_definitions($configuration) {
    $root = managed_repository_root($configuration);
    if ($root === FALSE) {
        return array();
    }

    $options = isset($configuration['options']) && is_array($configuration['options'])
        ? $configuration['options'] : array();
    $metadata = auth_repository_metadata();
    if ($metadata === FALSE) {
        $metadata = array();
    }
    $definitions = array();
    $entries = @scandir($root);
    if ($entries === FALSE) {
        return array();
    }

    foreach ($entries as $entry) {
        if (normalize_managed_repository_name($entry) !== $entry) {
            continue;
        }

        $path = $root.DIRECTORY_SEPARATOR.$entry;
        if (!managed_repository_is_bare($path)) {
            continue;
        }

        if (isset($metadata[$entry]) && !$metadata[$entry]['ready']) {
            continue;
        }

        $repository_metadata = isset($metadata[$entry])
            ? $metadata[$entry] : array('owner' => NULL, 'private' => TRUE);
        unset($repository_metadata['ready']);
        $definitions[] = array(
            '/'.$entry,
            $path,
            array_merge($options, $repository_metadata));
    }

    return $definitions;
}

function merge_managed_repository_definitions($url_base, $definitions, $configuration) {
    $urls = array();
    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository !== FALSE) {
            $urls[$repository['url']] = TRUE;
        }
    }

    foreach (managed_repository_definitions($configuration) as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository === FALSE || isset($urls[$repository['url']])) {
            continue;
        }

        $definitions[] = $definition;
        $urls[$repository['url']] = TRUE;
    }

    return $definitions;
}

function normalize_repository($definition, $url_base) {
    if (!is_array($definition)) {
        return FALSE;
    }

    if (isset($definition['url']) && isset($definition['path'])) {
        $public_path = $definition['url'];
        $git_path = $definition['path'];
        $options = isset($definition['options']) && is_array($definition['options'])
            ? $definition['options'] : array();
    } else if (isset($definition[0]) && isset($definition[1])) {
        $public_path = $definition[0];
        $git_path = $definition[1];
        $options = isset($definition[2]) && is_array($definition[2])
            ? $definition[2] : array();
    } else {
        return FALSE;
    }

    if (!is_string($public_path) || $public_path === '' || $public_path[0] !== '/') {
        return FALSE;
    }

    if (!is_string($git_path) || $git_path === '') {
        return FALSE;
    }

    if ($git_path[0] !== DIRECTORY_SEPARATOR) {
        $git_path = dirname(__DIR__).DIRECTORY_SEPARATOR.$git_path;
    }

    $base = rtrim((string) $url_base, '/');
    $url = $base.rtrim($public_path, '/');
    if ($url === '') {
        return FALSE;
    }

    $normalized_options = array_merge(repository_default_options(), $options);
    if (!is_string($normalized_options['owner']) || $normalized_options['owner'] === '') {
        $normalized_options['owner'] = NULL;
    }
    $normalized_options['private'] = (bool) $normalized_options['private'];

    return array(
        'url' => $url,
        'path' => $git_path,
        'options' => $normalized_options);
}

function find_configured_repository($url_base, $definitions, $url_path) {
    $repositories = array();

    foreach ($definitions as $definition) {
        $repository = normalize_repository($definition, $url_base);
        if ($repository !== FALSE) {
            $repositories[] = $repository;
        }
    }

    usort($repositories, 'repository_url_length_cmp');

    foreach ($repositories as $repository) {
        $url_length = strlen($repository['url']);
        if (substr($url_path, 0, $url_length) !== $repository['url']) {
            continue;
        }

        $remaining = substr($url_path, $url_length);
        if ($remaining === '' || $remaining[0] !== '/') {
            continue;
        }

        $git_real_path = realpath($repository['path']);
        if ($git_real_path === FALSE || !is_dir($git_real_path)) {
            send_error(404, 'Not Found', 'Repository not found.');
        }

        $repository['path'] = $git_real_path;
        $repository['request_path'] = $remaining;
        return $repository;
    }

    return FALSE;
}

function repository_url_length_cmp($left, $right) {
    return strlen($right['url']) - strlen($left['url']);
}

function get_safe_file_path($git_path, $name) {
    clearstatcache(TRUE, $git_path);
    clearstatcache(TRUE, $git_path.$name);

    $git_real_path = realpath($git_path);
    $file_real_path = realpath($git_path.$name);

    if ($git_real_path === FALSE || $file_real_path === FALSE) {
        return FALSE;
    }

    $git_prefix = rtrim($git_real_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (strpos($file_real_path, $git_prefix) !== 0 || !is_file($file_real_path)) {
        return FALSE;
    }

    return $file_real_path;
}

function get_safe_dir_path($git_path, $name) {
    clearstatcache(TRUE, $git_path);
    clearstatcache(TRUE, $git_path.$name);

    $git_real_path = realpath($git_path);
    $dir_real_path = realpath($git_path.$name);

    if ($git_real_path === FALSE || $dir_real_path === FALSE) {
        return FALSE;
    }

    $git_prefix = rtrim($git_real_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (strpos($dir_real_path, $git_prefix) !== 0 || !is_dir($dir_real_path)) {
        return FALSE;
    }

    return $dir_real_path;
}

function ref_entry_cmp($left, $right) {
    return strcmp($left[0], $right[0]);
}

function read_packed_refs($file) {
    $list = array();
    $last_ref = NULL;

    while (($line = fgets($file)) !== FALSE) {
        if (preg_match('~^([0-9a-f]{40}|[0-9a-f]{64})\s(\S+)~', $line, $matches)) {
            $last_ref = $matches[2];
            $list[] = array($last_ref, $matches[1]);
        } else if ($last_ref !== NULL
            && preg_match('~^\^([0-9a-f]{40}|[0-9a-f]{64})~', $line, $matches)) {
            $list[] = array($last_ref.'^{}', $matches[1]);
        }
    }

    usort($list, 'ref_entry_cmp');
    return $list;
}

function get_packed_refs($git_path) {
    $packed_refs_path = get_safe_file_path($git_path, '/packed-refs');
    if ($packed_refs_path === FALSE) {
        return array();
    }

    $file = @fopen($packed_refs_path, 'r');
    $list = array();

    if ($file) {
        $list = read_packed_refs($file);
        fclose($file);
    }

    return $list;
}

function resolve_ref($git_path, $ref) {
    $depth = 5;

    while (TRUE) {
        $depth -= 1;
        if ($depth < 0) {
            return array(NULL, NULL);
        }

        $path = $git_path.'/'.$ref;
        if (!@lstat($path)) {
            foreach (get_packed_refs($git_path) as $packed_ref) {
                if (!strcmp($packed_ref[0], $ref)) {
                    return array($ref, $packed_ref[1]);
                }
            }
            return array(NULL, NULL);
        }

        if (is_link($path)) {
            $destination = readlink($path);
            if (strlen($destination) >= 5 && !strcmp('refs/', substr($destination, 0, 5))) {
                $ref = $destination;
                continue;
            }
            return array(NULL, NULL);
        }

        if (is_dir($path)) {
            return array(NULL, NULL);
        }

        $safe_path = get_safe_file_path($git_path, '/'.$ref);
        if ($safe_path === FALSE) {
            return array(NULL, NULL);
        }

        $buffer = @file_get_contents($safe_path);
        if ($buffer === FALSE) {
            return array(NULL, NULL);
        }

        if (!preg_match('~^ref:\s*(refs/[^\r\n]+)\s*$~', $buffer, $matches)) {
            if (!preg_match('~^([0-9a-f]{40}|[0-9a-f]{64})\s*$~', $buffer, $matches)) {
                return array(NULL, NULL);
            }

            return array($ref, $matches[1]);
        }

        $ref = $matches[1];
    }
}

function get_ref_dir($git_path, $base, $list=array()) {
    $path = get_safe_dir_path($git_path, '/'.$base);
    if ($path === FALSE) {
        return $list;
    }

    $directory = @opendir($path);
    if ($directory === FALSE) {
        return $list;
    }

    while (($entry = readdir($directory)) !== FALSE) {
        if ($entry[0] == '.' || strlen($entry) > 255 || str_endswith($entry, '.lock')) {
            continue;
        }

        $entry_path = $path.'/'.$entry;
        if (is_dir($entry_path) && !is_link($entry_path)) {
            $list = get_ref_dir($git_path, $base.'/'.$entry, $list);
        } else {
            $resolved = resolve_ref($git_path, $base.'/'.$entry);
            if ($resolved[0] !== NULL) {
                $list[] = array($base.'/'.$entry, $resolved[1]);
            }
        }
    }

    closedir($directory);
    usort($list, 'ref_entry_cmp');
    return $list;
}

function get_repository_refs($git_path) {
    $refs = array();

    foreach (get_packed_refs($git_path) as $ref) {
        $refs[$ref[0]] = $ref[1];
    }

    foreach (get_ref_dir($git_path, 'refs') as $ref) {
        $refs[$ref[0]] = $ref[1];
        unset($refs[$ref[0].'^{}']);
    }

    $list = array();
    foreach ($refs as $name => $hash) {
        $list[] = array($name, $hash);
    }

    usort($list, 'ref_entry_cmp');
    return $list;
}
