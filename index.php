<?php

require('config.php');

$url_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($url_path === FALSE) {
    $url_path = '';
}


/* The following code has been ported from Git source <http://git-scm.com>
   by Jon Lund Steffensen, July 2011. Licenced under GPL2. */

function str_endswith($s, $test) {
    $strlen = strlen($s);
    $testlen = strlen($test);
    if ($testlen > $strlen) return FALSE;
    return substr_compare($s, $test, -$testlen) === 0;
}

function send_status($code, $reason) {
    $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    header($protocol.' '.$code.' '.$reason);
}

function header_nocache() {
    header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');
    header('Pragma: no-cache');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
}

function header_cache_forever() {
    header('Expires: '.gmdate('D, d M Y H:i:s', time() + 31536000).' GMT');
    header('Cache-Control: public, max-age=31536000');
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

function send_local_file($type, $git_path, $name) {
    $path = get_safe_file_path($git_path, $name);
    if ($path === FALSE) {
        send_status(404, 'Not Found');
        die();
    }

    $f = @fopen($path, 'rb');
    if (!$f) {
        send_status(404, 'Not Found');
        die();
    }

    $stat = fstat($f);
    header('Content-Type: '.$type);
    header('Content-Length: '.$stat['size']);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $stat['mtime']).' GMT');

    fpassthru($f);
    fclose($f);
}

function get_text_file($git_path, $name) {
    header_nocache();
    send_local_file('text/plain', $git_path, $name);
}

function get_loose_object($git_path, $name) {
    header_cache_forever();
    send_local_file('application/x-git-loose-object', $git_path, $name);
}

function get_pack_file($git_path, $name) {
    header_cache_forever();
    send_local_file('application/x-git-packed-objects', $git_path, $name);
}

function get_idx_file($git_path, $name) {
    header_cache_forever();
    send_local_file('application/x-git-packed-objects-toc', $git_path, $name);
}


function ref_entry_cmp($a, $b) {
    return strcmp($a[0], $b[0]);
}

function read_packed_refs($f) {
    $list = array();
    $last_ref = NULL;

    while (($line = fgets($f)) !== FALSE) {
        if (preg_match('~^([0-9a-f]{40})\s(\S+)~', $line, $matches)) {
            $last_ref = $matches[2];
            $list[] = array($last_ref, $matches[1]);
        } else if ($last_ref !== NULL && preg_match('~^\^([0-9a-f]{40})~', $line, $matches)) {
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

    $f = @fopen($packed_refs_path, 'r');
    $list = array();

    if ($f) {
        $list = read_packed_refs($f);
        fclose($f);
    }

    return $list;
}

function resolve_ref($git_path, $ref) {
    $depth = 5;

    while (TRUE) {
        $depth -= 1;
        if ($depth < 0) {
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        $path = $git_path.'/'.$ref;
        if (!@lstat($path)) {
            foreach (get_packed_refs($git_path) as $pref) {
                if (!strcmp($pref[0], $ref)) {
                    return array($ref, $pref[1]);
                }
            }
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        if (is_link($path)) {
            $dest = readlink($path);
            if (strlen($dest) >= 5 && !strcmp('refs/', substr($dest, 0, 5))) {
                $ref = $dest;
                continue;
            }
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        if (is_dir($path)) {
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        $safe_path = get_safe_file_path($git_path, '/'.$ref);
        if ($safe_path === FALSE) {
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        $buffer = @file_get_contents($safe_path);
        if ($buffer === FALSE) {
            return array(NULL, '0000000000000000000000000000000000000000');
        }

        if (!preg_match('~^ref:\s*(refs/[^\r\n]+)\s*$~', $buffer, $matches)) {
            if (!preg_match('~^([0-9a-f]{40})\s*$~', $buffer, $matches)) {
                return array(NULL, '0000000000000000000000000000000000000000');
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

    $dir = @opendir($path);
    if ($dir === FALSE) {
        return $list;
    }

    while (($entry = readdir($dir)) !== FALSE) {
        if ($entry[0] == '.') continue;
        if (strlen($entry) > 255) continue;
        if (str_endswith($entry, '.lock')) continue;

        $entry_path = $path.'/'.$entry;

        if (is_dir($entry_path) && !is_link($entry_path)) {
            $list = get_ref_dir($git_path, $base.'/'.$entry, $list);
        } else {
            $r = resolve_ref($git_path, $base.'/'.$entry);
            if ($r[0] !== NULL) {
                $list[] = array($base.'/'.$entry, $r[1]);
            }
        }
    }

    closedir($dir);
    usort($list, 'ref_entry_cmp');
    return $list;
}

function get_loose_refs($git_path) {
    return get_ref_dir($git_path, 'refs');
}

function get_refs($git_path) {
    $refs = array();

    foreach (get_packed_refs($git_path) as $ref) {
        $refs[$ref[0]] = $ref[1];
    }

    foreach (get_loose_refs($git_path) as $ref) {
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

function get_info_refs($git_path, $name) {
    header_nocache();
    header('Content-Type: text/plain');

    foreach (get_refs($git_path) as $ref) {
        echo $ref[1]."\t".$ref[0]."\n";
    }
}

function get_info_packs($git_path, $name) {
    header_nocache();
    header('Content-Type: text/plain; charset=utf-8');

    $pack_dir = get_safe_dir_path($git_path, '/objects/pack');
    if ($pack_dir === FALSE) {
        return;
    }

    $dir = @opendir($pack_dir);
    if ($dir === FALSE) {
        return;
    }

    $packs = array();
    while (($entry = readdir($dir)) !== FALSE) {
        if (preg_match('~^(pack-[0-9a-f]{40})\.idx$~', $entry, $matches)) {
            $name = $matches[1];
            if (get_safe_file_path($git_path, '/objects/pack/'.$name.'.pack') !== FALSE) {
                $packs[] = $name;
            }
        }
    }
    closedir($dir);

    sort($packs);
    foreach ($packs as $name) {
        echo 'P '.$name.'.pack'."\n";
    }
}


$services = array(
    array('GET', '/HEAD$', 'get_text_file'),
    array('GET', '/info/refs$', 'get_info_refs'),
    array('GET', '/objects/info/alternates$', 'get_text_file'),
    array('GET', '/objects/info/http-alternates$', 'get_text_file'),
    array('GET', '/objects/info/packs$', 'get_info_packs'),
    array('GET', '/objects/[0-9a-f]{2}/[0-9a-f]{38}$', 'get_loose_object'),
    array('GET', '/objects/pack/pack-[0-9a-f]{40}\\.pack$', 'get_pack_file'),
    array('GET', '/objects/pack/pack-[0-9a-f]{40}\\.idx$', 'get_idx_file'));


foreach ($repos as $repo) {
    $repo_url = preg_quote($url_base.$repo[0], '~');
    if (preg_match('~^'.$repo_url.'(/.*)$~', $url_path, $matches)) {
        $repo_path = $matches[1];

        if (!is_dir($repo[1])) {
            send_status(404, 'Not Found');
            die();
        }

        foreach ($services as $service) {
            if (preg_match('~^'.$service[1].'~', $repo_path)) {

                if ($_SERVER['REQUEST_METHOD'] != $service[0]) {
                    send_status(405, 'Method Not Allowed');
                    header('Allow: '.$service[0]);
                    echo 'Method Not Allowed';
                    die();
                }

                call_user_func($service[2], $repo[1], $repo_path);
                die();
            }
        }

        send_status(404, 'Not Found');
        die();
    }
}

send_status(404, 'Not Found');
die();
