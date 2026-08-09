<?php

function git_object_store_uint32($buffer, $offset) {
    $value = unpack('Nvalue', substr($buffer, $offset, 4));
    return $value['value'];
}

function git_object_store_repository_format($git_path) {
    $config_path = get_safe_file_path($git_path, '/config');
    if ($config_path === FALSE) {
        return 'sha1';
    }

    $config = @parse_ini_file($config_path, TRUE, INI_SCANNER_RAW);
    if ($config === FALSE) {
        return FALSE;
    }

    foreach ($config as $section => $values) {
        if (strcasecmp($section, 'extensions') !== 0) {
            continue;
        }
        foreach ($values as $name => $value) {
            if (strcasecmp($name, 'objectFormat') === 0) {
                return strtolower(trim($value));
            }
        }
    }

    return 'sha1';
}

function git_object_store_create($git_path) {
    if (git_object_store_repository_format($git_path) !== 'sha1') {
        return FALSE;
    }

    $store = array(
        'path' => $git_path,
        'objects' => array(),
        'locations' => array(),
        'pack_offsets' => array(),
        'pack_objects' => array());

    $pack_dir = get_safe_dir_path($git_path, '/objects/pack');
    if ($pack_dir === FALSE) {
        return $store;
    }

    $entries = @scandir($pack_dir);
    if ($entries === FALSE) {
        return $store;
    }

    foreach ($entries as $entry) {
        if (!preg_match('~^(pack-[0-9a-f]{40})\.idx$~D', $entry, $matches)) {
            continue;
        }

        $pack_name = $matches[1].'.pack';
        $idx_path = get_safe_file_path($git_path, '/objects/pack/'.$entry);
        $pack_path = get_safe_file_path($git_path, '/objects/pack/'.$pack_name);
        if ($idx_path !== FALSE && $pack_path !== FALSE) {
            git_object_store_load_index($store, $idx_path, $pack_path);
        }
    }

    return $store;
}

function git_object_store_load_index(&$store, $idx_path, $pack_path) {
    $buffer = @file_get_contents($idx_path);
    if ($buffer === FALSE || strlen($buffer) < 1068
        || substr($buffer, 0, 4) !== "\xfftOc"
        || git_object_store_uint32($buffer, 4) !== 2) {
        return FALSE;
    }

    $count = git_object_store_uint32($buffer, 8 + 255 * 4);
    $oids_offset = 8 + 256 * 4;
    $offsets_offset = $oids_offset + $count * 20 + $count * 4;
    $large_offsets_offset = $offsets_offset + $count * 4;
    if ($large_offsets_offset + 40 > strlen($buffer)) {
        return FALSE;
    }

    $pack_key = $pack_path;
    if (!isset($store['pack_offsets'][$pack_key])) {
        $store['pack_offsets'][$pack_key] = array();
    }

    for ($index = 0; $index < $count; $index += 1) {
        $oid = bin2hex(substr($buffer, $oids_offset + $index * 20, 20));
        $offset = git_object_store_uint32($buffer, $offsets_offset + $index * 4);
        if (($offset & 0x80000000) !== 0) {
            $large_index = $offset & 0x7fffffff;
            $position = $large_offsets_offset + $large_index * 8;
            if ($position + 8 > strlen($buffer)) {
                return FALSE;
            }
            $high = git_object_store_uint32($buffer, $position);
            $low = git_object_store_uint32($buffer, $position + 4);
            $offset = $high * 4294967296 + $low;
        }

        $store['locations'][$oid] = array($pack_path, $offset);
        $store['pack_offsets'][$pack_key][(string) $offset] = $oid;
    }

    return TRUE;
}

function git_object_store_parse_loose($compressed, $expected_oid) {
    $inflated = @zlib_decode($compressed);
    if ($inflated === FALSE) {
        return FALSE;
    }

    $separator = strpos($inflated, "\0");
    if ($separator === FALSE
        || !preg_match('~^(commit|tree|blob|tag) ([0-9]+)$~D', substr($inflated, 0, $separator), $matches)) {
        return FALSE;
    }

    $body = substr($inflated, $separator + 1);
    if (strlen($body) !== (int) $matches[2]
        || sha1(substr($inflated, 0, $separator + 1).$body) !== $expected_oid) {
        return FALSE;
    }

    return array('type' => $matches[1], 'body' => $body, 'oid' => $expected_oid);
}

function git_object_store_read(&$store, $oid) {
    $oid = strtolower($oid);
    if (!preg_match('~^[0-9a-f]{40}$~D', $oid)) {
        return FALSE;
    }
    if (isset($store['objects'][$oid])) {
        return $store['objects'][$oid];
    }

    $loose_path = get_safe_file_path(
        $store['path'],
        '/objects/'.substr($oid, 0, 2).'/'.substr($oid, 2));
    if ($loose_path !== FALSE) {
        $compressed = @file_get_contents($loose_path);
        $object = $compressed === FALSE ? FALSE : git_object_store_parse_loose($compressed, $oid);
    } else if (isset($store['locations'][$oid])) {
        $location = $store['locations'][$oid];
        $object = git_object_store_read_pack_offset($store, $location[0], $location[1]);
        if ($object !== FALSE && $object['oid'] !== $oid) {
            $object = FALSE;
        }
    } else {
        return FALSE;
    }

    if ($object !== FALSE) {
        $store['objects'][$oid] = $object;
    }
    return $object;
}

function git_object_store_read_byte($file) {
    $byte = fread($file, 1);
    return $byte === FALSE || strlen($byte) !== 1 ? FALSE : ord($byte);
}

function git_object_store_read_pack_offset(&$store, $pack_path, $offset) {
    $cache_key = $pack_path.':'.$offset;
    if (isset($store['pack_objects'][$cache_key])) {
        return $store['pack_objects'][$cache_key];
    }

    $file = @fopen($pack_path, 'rb');
    if ($file === FALSE || fseek($file, $offset) !== 0) {
        if ($file !== FALSE) {
            fclose($file);
        }
        return FALSE;
    }

    $byte = git_object_store_read_byte($file);
    if ($byte === FALSE) {
        fclose($file);
        return FALSE;
    }

    $type_number = ($byte >> 4) & 7;
    $size = $byte & 15;
    $shift = 4;
    while (($byte & 0x80) !== 0) {
        $byte = git_object_store_read_byte($file);
        if ($byte === FALSE || $shift > 60) {
            fclose($file);
            return FALSE;
        }
        $size |= ($byte & 0x7f) << $shift;
        $shift += 7;
    }

    $base_offset = NULL;
    $base_oid = NULL;
    if ($type_number === 6) {
        $byte = git_object_store_read_byte($file);
        if ($byte === FALSE) {
            fclose($file);
            return FALSE;
        }
        $distance = $byte & 0x7f;
        while (($byte & 0x80) !== 0) {
            $byte = git_object_store_read_byte($file);
            if ($byte === FALSE) {
                fclose($file);
                return FALSE;
            }
            $distance = (($distance + 1) << 7) | ($byte & 0x7f);
        }
        $base_offset = $offset - $distance;
        if ($base_offset < 12) {
            fclose($file);
            return FALSE;
        }
    } else if ($type_number === 7) {
        $binary_oid = fread($file, 20);
        if ($binary_oid === FALSE || strlen($binary_oid) !== 20) {
            fclose($file);
            return FALSE;
        }
        $base_oid = bin2hex($binary_oid);
    }

    $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
    if ($context === FALSE) {
        fclose($file);
        return FALSE;
    }

    $body = '';
    while (inflate_get_status($context) !== ZLIB_STREAM_END && !feof($file)) {
        $chunk = fread($file, 65536);
        if ($chunk === FALSE || $chunk === '') {
            break;
        }
        $inflated = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
        if ($inflated === FALSE) {
            fclose($file);
            return FALSE;
        }
        $body .= $inflated;
    }
    fclose($file);

    if (inflate_get_status($context) !== ZLIB_STREAM_END || strlen($body) !== $size) {
        return FALSE;
    }

    $types = array(1 => 'commit', 2 => 'tree', 3 => 'blob', 4 => 'tag');
    if (isset($types[$type_number])) {
        $type = $types[$type_number];
    } else if ($type_number === 6 || $type_number === 7) {
        $base = $type_number === 6
            ? git_object_store_read_pack_offset($store, $pack_path, $base_offset)
            : git_object_store_read($store, $base_oid);
        if ($base === FALSE) {
            return FALSE;
        }
        $body = git_object_store_apply_delta($base['body'], $body);
        if ($body === FALSE) {
            return FALSE;
        }
        $type = $base['type'];
    } else {
        return FALSE;
    }

    $oid = sha1($type.' '.strlen($body)."\0".$body);
    $object = array('type' => $type, 'body' => $body, 'oid' => $oid);
    $store['pack_objects'][$cache_key] = $object;
    $store['objects'][$oid] = $object;
    return $object;
}

function git_object_store_delta_varint($delta, &$position) {
    $value = 0;
    $shift = 0;
    do {
        if ($position >= strlen($delta) || $shift > 56) {
            return FALSE;
        }
        $byte = ord($delta[$position]);
        $position += 1;
        $value |= ($byte & 0x7f) << $shift;
        $shift += 7;
    } while (($byte & 0x80) !== 0);

    return $value;
}

function git_object_store_apply_delta($base, $delta, $max_result_bytes=0) {
    $position = 0;
    $base_size = git_object_store_delta_varint($delta, $position);
    $result_size = git_object_store_delta_varint($delta, $position);
    if ($base_size === FALSE || $result_size === FALSE || $base_size !== strlen($base)
        || ($max_result_bytes > 0 && $result_size > $max_result_bytes)) {
        return FALSE;
    }

    $result = '';
    while ($position < strlen($delta)) {
        $command = ord($delta[$position]);
        $position += 1;
        if (($command & 0x80) === 0) {
            if ($command === 0 || $position + $command > strlen($delta)) {
                return FALSE;
            }
            $result .= substr($delta, $position, $command);
            $position += $command;
            continue;
        }

        $copy_offset = 0;
        $copy_size = 0;
        $offset_shift = 0;
        for ($mask = 1; $mask <= 8; $mask <<= 1) {
            if (($command & $mask) !== 0) {
                if ($position >= strlen($delta)) {
                    return FALSE;
                }
                $copy_offset |= ord($delta[$position]) << $offset_shift;
                $position += 1;
            }
            $offset_shift += 8;
        }
        $size_shift = 0;
        for ($mask = 16; $mask <= 64; $mask <<= 1) {
            if (($command & $mask) !== 0) {
                if ($position >= strlen($delta)) {
                    return FALSE;
                }
                $copy_size |= ord($delta[$position]) << $size_shift;
                $position += 1;
            }
            $size_shift += 8;
        }
        if ($copy_size === 0) {
            $copy_size = 65536;
        }
        if ($copy_offset + $copy_size > strlen($base)) {
            return FALSE;
        }
        $result .= substr($base, $copy_offset, $copy_size);
    }

    return strlen($result) === $result_size ? $result : FALSE;
}

function git_object_store_links($object) {
    $links = array();
    if ($object['type'] === 'commit') {
        foreach (preg_split('~\n~', $object['body']) as $line) {
            if ($line === '') {
                break;
            }
            if (preg_match('~^(?:tree|parent) ([0-9a-f]{40})$~D', $line, $matches)) {
                $links[] = $matches[1];
            }
        }
    } else if ($object['type'] === 'tag') {
        if (preg_match('~^object ([0-9a-f]{40})$~m', $object['body'], $matches)) {
            $links[] = $matches[1];
        }
    } else if ($object['type'] === 'tree') {
        $position = 0;
        $length = strlen($object['body']);
        while ($position < $length) {
            $separator = strpos($object['body'], "\0", $position);
            if ($separator === FALSE || $separator + 21 > $length
                || !preg_match('~^[0-7]+ [^/]+$~D', substr($object['body'], $position, $separator - $position))) {
                return FALSE;
            }
            $links[] = bin2hex(substr($object['body'], $separator + 1, 20));
            $position = $separator + 21;
        }
    }

    return $links;
}

function git_object_store_collect(&$store, $roots) {
    $objects = array();
    $pending = array_values($roots);
    while (!empty($pending)) {
        $oid = array_pop($pending);
        if (isset($objects[$oid])) {
            continue;
        }
        $object = git_object_store_read($store, $oid);
        if ($object === FALSE) {
            return FALSE;
        }
        $links = git_object_store_links($object);
        if ($links === FALSE) {
            return FALSE;
        }
        $objects[$oid] = $object;
        foreach ($links as $link) {
            if (!isset($objects[$link])) {
                $pending[] = $link;
            }
        }
    }

    return $objects;
}

function git_object_store_pack_header($type, $size) {
    $types = array('commit' => 1, 'tree' => 2, 'blob' => 3, 'tag' => 4);
    if (!isset($types[$type])) {
        return FALSE;
    }

    $byte = ($types[$type] << 4) | ($size & 15);
    $size >>= 4;
    $header = '';
    while ($size > 0) {
        $header .= chr($byte | 0x80);
        $byte = $size & 0x7f;
        $size >>= 7;
    }
    return $header.chr($byte);
}

function git_object_store_build_pack($objects) {
    $pack = 'PACK'.pack('N', 2).pack('N', count($objects));
    foreach ($objects as $object) {
        $header = git_object_store_pack_header($object['type'], strlen($object['body']));
        $compressed = gzcompress($object['body']);
        if ($header === FALSE || $compressed === FALSE) {
            return FALSE;
        }
        $pack .= $header.$compressed;
    }

    return $pack.hash('sha1', $pack, TRUE);
}