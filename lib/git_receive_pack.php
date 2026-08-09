<?php

function git_receive_pack_capabilities() {
    return 'report-status delete-refs side-band-64k quiet ofs-delta agent=php-git-server/1';
}

function git_receive_pack_advertise_native($repository) {
    $refs = get_repository_refs($repository['path']);
    if (empty($refs)) {
        echo git_protocol_format_packet(
            str_repeat('0', 40).' capabilities^{}'."\0".git_receive_pack_capabilities()."\n");
    } else {
        $first = TRUE;
        foreach ($refs as $ref) {
            if (str_endswith($ref[0], '^{}')) {
                continue;
            }
            $payload = $ref[1].' '.$ref[0];
            if ($first) {
                $payload .= "\0".git_receive_pack_capabilities();
                $first = FALSE;
            }
            echo git_protocol_format_packet($payload."\n");
        }
    }
    echo '0000';
    return TRUE;
}

function git_receive_pack_valid_ref($ref) {
    if (strpos($ref, 'refs/') !== 0
        || strlen($ref) > 1024
        || strpos($ref, '..') !== FALSE
        || strpos($ref, '@{') !== FALSE
        || strpos($ref, '//') !== FALSE
        || preg_match('~[\x00-\x20\x7f\~^:?*\\\[]~', $ref)
        || preg_match('~(?:^|/)\.|\.(?:lock)?$|/$~', $ref)) {
        return FALSE;
    }

    return TRUE;
}

function git_receive_pack_parse_commands($input) {
    $updates = array();
    $capabilities = array();
    $seen_refs = array();
    $first = TRUE;

    while (($packet = git_protocol_read_packet($input)) !== FALSE) {
        if ($packet['type'] === 'flush') {
            return array('updates' => $updates, 'capabilities' => $capabilities);
        }
        if ($packet['type'] !== 'data') {
            return FALSE;
        }

        $parts = $first ? explode("\0", $packet['payload'], 2) : array($packet['payload']);
        $command = rtrim($parts[0], "\r\n");
        if (!preg_match('~^([0-9a-f]{40}) ([0-9a-f]{40}) ([^\x00-\x20\x7f]+)$~D', $command, $matches)
            || !git_receive_pack_valid_ref($matches[3])
            || isset($seen_refs[$matches[3]])) {
            return FALSE;
        }
        $seen_refs[$matches[3]] = TRUE;

        if ($first && isset($parts[1])) {
            foreach (preg_split('~\s+~', trim($parts[1])) as $capability) {
                if ($capability !== '') {
                    $capabilities[$capability] = TRUE;
                }
            }
        }

        $updates[] = array('old' => $matches[1], 'new' => $matches[2], 'ref' => $matches[3]);
        $first = FALSE;
    }

    return FALSE;
}

function git_receive_pack_read_varint($buffer, &$position, $limit) {
    $value = 0;
    $shift = 0;
    do {
        if ($position >= $limit || $shift > 56) {
            return FALSE;
        }
        $byte = ord($buffer[$position]);
        $position += 1;
        $value |= ($byte & 0x7f) << $shift;
        $shift += 7;
    } while (($byte & 0x80) !== 0);
    return $value;
}

function git_receive_pack_inflate_entry($pack, &$position, $limit, $expected_size, $max_object_bytes) {
    $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
    if ($context === FALSE) {
        return FALSE;
    }

    $body = '';
    $read = 0;
    while (inflate_get_status($context) !== ZLIB_STREAM_END && $position + $read < $limit) {
        $chunk = substr($pack, $position + $read, min(65536, $limit - $position - $read));
        $inflated = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
        if ($inflated === FALSE) {
            return FALSE;
        }
        $body .= $inflated;
        $read = inflate_get_read_len($context);
        if (strlen($body) > $expected_size
            || ($max_object_bytes > 0 && strlen($body) > $max_object_bytes)) {
            return FALSE;
        }
    }
    if (inflate_get_status($context) !== ZLIB_STREAM_END
        || $read < 1
        || $position + $read > $limit
        || strlen($body) !== $expected_size) {
        return FALSE;
    }
    $position += $read;
    return $body;
}

function git_receive_pack_parse($pack, &$store, $max_object_bytes, $max_pack_objects) {
    $length = strlen($pack);
    if ($length < 32 || substr($pack, 0, 4) !== 'PACK'
        || git_object_store_uint32($pack, 4) !== 2
        || !hash_equals(substr($pack, -20), hash('sha1', substr($pack, 0, -20), TRUE))) {
        return FALSE;
    }

    $count = git_object_store_uint32($pack, 8);
    if (($max_pack_objects > 0 && $count > $max_pack_objects)
        || $count > strlen($pack) - 32) {
        return FALSE;
    }
    $limit = $length - 20;
    $position = 12;
    $entries = array();
    $offset_entries = array();

    for ($index = 0; $index < $count; $index += 1) {
        $offset = $position;
        if ($position >= $limit) {
            return FALSE;
        }
        $byte = ord($pack[$position]);
        $position += 1;
        $type_number = ($byte >> 4) & 7;
        $size = $byte & 15;
        $shift = 4;
        while (($byte & 0x80) !== 0) {
            if ($position >= $limit || $shift > 60) {
                return FALSE;
            }
            $byte = ord($pack[$position]);
            $position += 1;
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        }
        if ($max_object_bytes > 0 && $size > $max_object_bytes) {
            return FALSE;
        }

        $base_offset = NULL;
        $base_oid = NULL;
        if ($type_number === 6) {
            if ($position >= $limit) {
                return FALSE;
            }
            $byte = ord($pack[$position]);
            $position += 1;
            $distance = $byte & 0x7f;
            while (($byte & 0x80) !== 0) {
                if ($position >= $limit) {
                    return FALSE;
                }
                $byte = ord($pack[$position]);
                $position += 1;
                $distance = (($distance + 1) << 7) | ($byte & 0x7f);
            }
            $base_offset = $offset - $distance;
        } else if ($type_number === 7) {
            if ($position + 20 > $limit) {
                return FALSE;
            }
            $base_oid = bin2hex(substr($pack, $position, 20));
            $position += 20;
        }

        $body = git_receive_pack_inflate_entry(
            $pack, $position, $limit, $size, $max_object_bytes);
        if ($body === FALSE || strlen($body) !== $size) {
            return FALSE;
        }
        $entries[$index] = array(
            'type_number' => $type_number,
            'body' => $body,
            'base_offset' => $base_offset,
            'base_oid' => $base_oid,
            'object' => NULL);
        $offset_entries[(string) $offset] = $index;
    }

    if ($position !== $limit) {
        return FALSE;
    }

    $types = array(1 => 'commit', 2 => 'tree', 3 => 'blob', 4 => 'tag');
    $remaining = $count;
    while ($remaining > 0) {
        $progress = FALSE;
        foreach ($entries as $index => &$entry) {
            if ($entry['object'] !== NULL) {
                continue;
            }

            if (isset($types[$entry['type_number']])) {
                $type = $types[$entry['type_number']];
                $body = $entry['body'];
            } else if ($entry['type_number'] === 6) {
                $key = (string) $entry['base_offset'];
                if (!isset($offset_entries[$key])) {
                    return FALSE;
                }
                $base = $entries[$offset_entries[$key]]['object'];
                if ($base === NULL) {
                    continue;
                }
                $type = $base['type'];
                $body = git_object_store_apply_delta(
                    $base['body'], $entry['body'], $max_object_bytes);
            } else if ($entry['type_number'] === 7) {
                $base = isset($store['objects'][$entry['base_oid']])
                    ? $store['objects'][$entry['base_oid']]
                    : git_object_store_read($store, $entry['base_oid']);
                if ($base === FALSE) {
                    continue;
                }
                $type = $base['type'];
                $body = git_object_store_apply_delta(
                    $base['body'], $entry['body'], $max_object_bytes);
            } else {
                return FALSE;
            }

            if ($body === FALSE) {
                return FALSE;
            }
            $oid = sha1($type.' '.strlen($body)."\0".$body);
            $entry['object'] = array('type' => $type, 'body' => $body, 'oid' => $oid);
            $store['objects'][$oid] = $entry['object'];
            $remaining -= 1;
            $progress = TRUE;
        }
        unset($entry);
        if (!$progress) {
            return FALSE;
        }
    }

    $objects = array();
    foreach ($entries as $entry) {
        $objects[$entry['object']['oid']] = $entry['object'];
    }
    return $objects;
}

function git_receive_pack_write_objects($git_path, $objects) {
    foreach ($objects as $oid => $object) {
        $directory = $git_path.'/objects/'.substr($oid, 0, 2);
        $path = $directory.'/'.substr($oid, 2);
        if (is_file($path)) {
            continue;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0777, TRUE)) {
            return FALSE;
        }
        $compressed = gzcompress($object['type'].' '.strlen($object['body'])."\0".$object['body']);
        $temporary = @tempnam($directory, 'incoming-');
        if ($compressed === FALSE || $temporary === FALSE
            || @file_put_contents($temporary, $compressed, LOCK_EX) !== strlen($compressed)) {
            if ($temporary !== FALSE) {
                @unlink($temporary);
            }
            return FALSE;
        }
        if (!@rename($temporary, $path) && !is_file($path)) {
            @unlink($temporary);
            return FALSE;
        }
        @chmod($path, 0444);
    }
    return TRUE;
}

function git_receive_pack_lock_updates($git_path, $updates) {
    usort($updates, function ($left, $right) {
        return strcmp($left['ref'], $right['ref']);
    });
    for ($index = 0; $index < count($updates); $index += 1) {
        for ($other = $index + 1; $other < count($updates); $other += 1) {
            if (strpos($updates[$other]['ref'], $updates[$index]['ref'].'/') === 0) {
                return FALSE;
            }
        }
    }
    $existing_refs = get_repository_refs($git_path);
    foreach ($updates as $update) {
        foreach ($existing_refs as $existing) {
            if (str_endswith($existing[0], '^{}') || $existing[0] === $update['ref']) {
                continue;
            }
            if (strpos($existing[0], $update['ref'].'/') === 0
                || strpos($update['ref'], $existing[0].'/') === 0) {
                return FALSE;
            }
        }
    }
    $locks = array();
    foreach ($updates as $update) {
        $path = $git_path.'/'.$update['ref'];
        $directory = dirname($path);
        $components = explode('/', $update['ref']);
        $prefix = $git_path;
        for ($index = 0; $index < count($components) - 1; $index += 1) {
            $prefix .= '/'.$components[$index];
            if (is_file($prefix) || is_link($prefix)) {
                git_receive_pack_release_locks($locks);
                return FALSE;
            }
        }
        if (is_dir($path)) {
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0777, TRUE)) {
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
        $lock_path = $path.'.lock';
        $file = @fopen($lock_path, 'x+b');
        if ($file === FALSE) {
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
        $resolved = resolve_ref($git_path, $update['ref']);
        $actual = $resolved[1] === NULL ? str_repeat('0', 40) : $resolved[1];
        if (!hash_equals($actual, $update['old'])) {
            fclose($file);
            @unlink($lock_path);
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
        if ($update['new'] !== str_repeat('0', 40)
            && fwrite($file, $update['new']."\n") !== 41) {
            fclose($file);
            @unlink($lock_path);
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
        fflush($file);
        $locks[] = array('file' => $file, 'path' => $path, 'lock_path' => $lock_path, 'update' => $update);
    }
    return $locks;
}

function git_receive_pack_remove_packed_refs($git_path, $updates) {
    $delete = array();
    foreach ($updates as $update) {
        if ($update['new'] === str_repeat('0', 40)) {
            $delete[$update['ref']] = TRUE;
            $delete[$update['ref'].'^{}'] = TRUE;
        }
    }
    if (empty($delete)) {
        return TRUE;
    }

    $packed_path = get_safe_file_path($git_path, '/packed-refs');
    if ($packed_path === FALSE) {
        return TRUE;
    }
    $input = @fopen($packed_path, 'rb');
    $lock_path = $git_path.'/packed-refs.lock';
    $output = @fopen($lock_path, 'x+b');
    if ($input === FALSE || $output === FALSE) {
        if ($input !== FALSE) {
            fclose($input);
        }
        if ($output !== FALSE) {
            fclose($output);
            @unlink($lock_path);
        }
        return FALSE;
    }

    $skip_peeled = FALSE;
    while (($line = fgets($input)) !== FALSE) {
        if (preg_match('~^[0-9a-f]{40} (\S+)~D', $line, $matches)) {
            $skip_peeled = isset($delete[$matches[1]]);
            if ($skip_peeled) {
                continue;
            }
        } else if ($skip_peeled && isset($line[0]) && $line[0] === '^') {
            continue;
        } else {
            $skip_peeled = FALSE;
        }
        if (fwrite($output, $line) !== strlen($line)) {
            fclose($input);
            fclose($output);
            @unlink($lock_path);
            return FALSE;
        }
    }
    fclose($input);
    fflush($output);
    fclose($output);
    return @rename($lock_path, $packed_path);
}

function git_receive_pack_release_locks($locks) {
    foreach ($locks as $lock) {
        if (is_resource($lock['file'])) {
            fclose($lock['file']);
        }
        @unlink($lock['lock_path']);
    }
}

function git_receive_pack_commit_updates($git_path, $locks) {
    $updates = array();
    foreach ($locks as $lock) {
        $updates[] = $lock['update'];
    }
    if (!git_receive_pack_remove_packed_refs($git_path, $updates)) {
        return FALSE;
    }

    foreach ($locks as &$lock) {
        fclose($lock['file']);
        $lock['file'] = NULL;
        if ($lock['update']['new'] === str_repeat('0', 40)) {
            if (is_file($lock['path']) && !@unlink($lock['path'])) {
                git_receive_pack_release_locks($locks);
                return FALSE;
            }
            @unlink($lock['lock_path']);
        } else if (!@rename($lock['lock_path'], $lock['path'])) {
            git_receive_pack_release_locks($locks);
            return FALSE;
        }
    }
    unset($lock);
    return TRUE;
}

function git_receive_pack_status($capabilities, $updates, $unpack_ok, $message) {
    $status = git_protocol_format_packet($unpack_ok ? "unpack ok\n" : 'unpack '.$message."\n");
    foreach ($updates as $update) {
        $status .= git_protocol_format_packet(
            ($unpack_ok ? 'ok ' : 'ng ').$update['ref'].($unpack_ok ? "\n" : ' '.$message."\n"));
    }
    $status .= '0000';
    if (isset($capabilities['side-band-64k'])) {
        git_upload_pack_send_sideband(1, $status);
        echo '0000';
    } else {
        echo $status;
    }
}

function git_receive_pack_is_ancestor(&$store, $ancestor, $descendant) {
    if ($ancestor === $descendant) {
        return TRUE;
    }

    $seen = array();
    $pending = array($descendant);
    while (!empty($pending)) {
        $oid = array_pop($pending);
        if (isset($seen[$oid])) {
            continue;
        }
        $seen[$oid] = TRUE;
        $object = git_object_store_read($store, $oid);
        if ($object === FALSE || $object['type'] !== 'commit') {
            return FALSE;
        }
        foreach (preg_split('~\n~', $object['body']) as $line) {
            if ($line === '') {
                break;
            }
            if (preg_match('~^parent ([0-9a-f]{40})$~D', $line, $matches)) {
                if ($matches[1] === $ancestor) {
                    return TRUE;
                }
                $pending[] = $matches[1];
            }
        }
    }

    return FALSE;
}

function git_receive_pack_rpc_native($repository, $input) {
    $commands = git_receive_pack_parse_commands($input);
    if ($commands === FALSE || empty($commands['updates'])) {
        return FALSE;
    }

    $zero = str_repeat('0', 40);
    $needs_pack = FALSE;
    foreach ($commands['updates'] as $update) {
        if ($update['new'] !== $zero) {
            $needs_pack = TRUE;
        }
    }

    $store = git_object_store_create($repository['path']);
    $objects = array();
    if ($store === FALSE) {
        git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'unsupported repository format');
        return TRUE;
    }
    if ($needs_pack) {
        $pack = stream_get_contents($input);
        $objects = $pack === FALSE ? FALSE : git_receive_pack_parse(
            $pack,
            $store,
            (int) $repository['options']['max_object_bytes'],
            (int) $repository['options']['max_pack_objects']);
        if ($objects === FALSE) {
            git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'invalid pack');
            return TRUE;
        }
    }

    foreach ($commands['updates'] as $update) {
        if ($update['new'] === $zero) {
            continue;
        }
        $reachable = git_object_store_collect($store, array($update['new']));
        if ($reachable === FALSE
            || (strpos($update['ref'], 'refs/heads/') === 0
                && $store['objects'][$update['new']]['type'] !== 'commit')) {
            git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'invalid object graph');
            return TRUE;
        }
        if (strpos($update['ref'], 'refs/heads/') === 0
            && $update['old'] !== $zero
            && !$repository['options']['allow_non_fast_forward']
            && !git_receive_pack_is_ancestor($store, $update['old'], $update['new'])) {
            git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'non-fast-forward');
            return TRUE;
        }
    }

    $locks = git_receive_pack_lock_updates($repository['path'], $commands['updates']);
    if ($locks === FALSE) {
        git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'stale or locked ref');
        return TRUE;
    }
    if (!git_receive_pack_write_objects($repository['path'], $objects)
        || !git_receive_pack_commit_updates($repository['path'], $locks)) {
        git_receive_pack_release_locks($locks);
        git_receive_pack_status($commands['capabilities'], $commands['updates'], FALSE, 'repository update failed');
        return TRUE;
    }

    git_receive_pack_status($commands['capabilities'], $commands['updates'], TRUE, '');
    return TRUE;
}