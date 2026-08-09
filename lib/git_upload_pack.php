<?php

function git_upload_pack_capabilities($git_path) {
    $capabilities = array(
        'side-band-64k',
        'ofs-delta',
        'agent=php-git-server/1');

    $head = resolve_ref($git_path, 'HEAD');
    if ($head[0] !== NULL && strpos($head[0], 'refs/') === 0) {
        $capabilities[] = 'symref=HEAD:'.$head[0];
    }

    return implode(' ', $capabilities);
}

function git_upload_pack_advertised_refs($git_path) {
    $refs = array();
    $head = resolve_ref($git_path, 'HEAD');
    if ($head[1] !== NULL) {
        $refs[] = array('HEAD', $head[1]);
    }
    foreach (get_repository_refs($git_path) as $ref) {
        $refs[] = $ref;
    }
    return $refs;
}

function git_upload_pack_advertise_native($repository) {
    $refs = git_upload_pack_advertised_refs($repository['path']);
    if (empty($refs)) {
        echo git_protocol_format_packet(
            str_repeat('0', 40).' capabilities^{}'."\0".git_upload_pack_capabilities($repository['path'])."\n");
        echo '0000';
        return TRUE;
    }

    foreach ($refs as $index => $ref) {
        $payload = $ref[1].' '.$ref[0];
        if ($index === 0) {
            $payload .= "\0".git_upload_pack_capabilities($repository['path']);
        }
        echo git_protocol_format_packet($payload."\n");
    }
    echo '0000';
    return TRUE;
}

function git_upload_pack_parse_request($input) {
    $request = array('wants' => array(), 'haves' => array(), 'capabilities' => array());
    $first_want = TRUE;

    while (($packet = git_protocol_read_packet($input)) !== FALSE) {
        if ($packet['type'] === 'flush') {
            continue;
        }
        if ($packet['type'] !== 'data') {
            return FALSE;
        }

        $line = rtrim($packet['payload'], "\r\n");
        if (preg_match('~^want ([0-9a-f]{40})(?: (.*))?$~D', $line, $matches)) {
            $request['wants'][] = $matches[1];
            if ($first_want && isset($matches[2]) && $matches[2] !== '') {
                foreach (explode(' ', $matches[2]) as $capability) {
                    $request['capabilities'][$capability] = TRUE;
                }
            }
            $first_want = FALSE;
        } else if (preg_match('~^have ([0-9a-f]{40})$~D', $line, $matches)) {
            $request['haves'][] = $matches[1];
        } else if ($line === 'done') {
            break;
        } else {
            return FALSE;
        }
    }

    if ($packet === FALSE || empty($request['wants'])) {
        return FALSE;
    }
    return $request;
}

function git_upload_pack_send_sideband($channel, $buffer) {
    $offset = 0;
    $length = strlen($buffer);
    while ($offset < $length) {
        $chunk = substr($buffer, $offset, 65515);
        echo git_protocol_format_packet(chr($channel).$chunk);
        $offset += strlen($chunk);
    }
}

function git_upload_pack_rpc_native($repository, $input) {
    $request = git_upload_pack_parse_request($input);
    if ($request === FALSE) {
        return FALSE;
    }

    $store = git_object_store_create($repository['path']);
    if ($store === FALSE) {
        return FALSE;
    }

    $advertised_roots = array();
    foreach (git_upload_pack_advertised_refs($repository['path']) as $ref) {
        $advertised_roots[$ref[1]] = TRUE;
    }
    $advertised = git_object_store_collect($store, array_keys($advertised_roots));
    if ($advertised === FALSE) {
        return FALSE;
    }
    foreach ($request['wants'] as $want) {
        if (!isset($advertised[$want])) {
            return FALSE;
        }
    }

    $objects = git_object_store_collect($store, $request['wants']);
    if ($objects === FALSE) {
        return FALSE;
    }

    $last_common = NULL;
    foreach ($request['haves'] as $have) {
        $common = git_object_store_collect($store, array($have));
        if ($common === FALSE) {
            continue;
        }
        $last_common = $have;
        foreach ($common as $oid => $object) {
            unset($objects[$oid]);
        }
    }

    $pack = git_object_store_build_pack($objects);
    if ($pack === FALSE) {
        return FALSE;
    }

    echo git_protocol_format_packet(
        $last_common === NULL ? "NAK\n" : 'ACK '.$last_common."\n");
    if (isset($request['capabilities']['side-band-64k'])) {
        git_upload_pack_send_sideband(1, $pack);
        echo '0000';
    } else {
        echo $pack;
    }
    return TRUE;
}