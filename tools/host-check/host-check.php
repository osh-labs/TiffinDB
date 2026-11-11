<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * TiffinDB host check.
 *
 * A single, self-contained script that checks whether a web host meets the
 * assumptions TiffinDB makes about shared hosting (PHP version, extensions,
 * limits, a writable data directory outside the web root, SQLite WAL, file
 * locking, Apache .htaccess overrides, Authorization header pass-through).
 * See README.md next to this file for the procedure.
 *
 * This file deliberately avoids syntax newer than PHP 7.0 so that an old PHP
 * reports its version instead of failing with a parse error.
 *
 * Network use: when run over HTTP, and unless ?selftest=0 is given, the script
 * makes a few HTTP(S) requests to the local address and port that received the
 * current request (SERVER_ADDR / SERVER_PORT), sending the request's own Host
 * header. Those requests carry a per-run random nonce, never the token. The
 * script makes no other network calls.
 */

// ---------------------------------------------------------------------------
// Maintainer setting: set a random token of at least 16 letters and digits
// (A-Z, a-z, 0-9 only) before uploading, then visit
// host-check.php?token=<the same value>.
// An empty or invalid token disables HTTP use entirely. The CLI needs no token.
// ---------------------------------------------------------------------------
const HOST_CHECK_TOKEN = '';

// ---------------------------------------------------------------------------

const HC_FORMAT = 2;
const HC_MIN_TOKEN_LENGTH = 16;
const HC_MIN_PHP = '8.3.0';
const HC_PREFIX = 'tiffindb-hostcheck-';
const HC_NAME_PATTERN = '/^tiffindb-hostcheck-[0-9a-f]{16}$/';
const HC_STALE_SECONDS = 600;
const HC_DUMMY_AUTH = 'Bearer tiffindb-hostcheck-dummy-value';

$GLOBALS['hc'] = array(
    'checks'   => array(),
    'masks'    => array(),
    'cleanup'  => array(),
    'last_err' => '',
    'done'     => false,
    'side_dir' => '',
);

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '0');
}
error_reporting(E_ALL);
set_error_handler('hc_error_handler');
register_shutdown_function('hc_shutdown');

hc_main();

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function hc_main()
{
    $isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

    if ($isCli) {
        $args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : array();
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                echo hc_usage();
                $GLOBALS['hc']['done'] = true;
                exit(0);
            }
            if (defined('STDERR')) {
                fwrite(STDERR, "Unknown option.\n\n" . hc_usage());
            }
            $GLOBALS['hc']['done'] = true;
            exit(2);
        }
    } else {
        hc_http_headers();
        if (isset($_GET['probe'])) {
            hc_answer_probe(); // exits
        }
        hc_http_guard(); // exits on refusal
    }

    // Remember the configured limit before raising it for this run.
    $GLOBALS['hc']['max_execution_time'] = (string) ini_get('max_execution_time');
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    ob_start();
    $selftest = !$isCli && !(isset($_GET['selftest']) && $_GET['selftest'] === '0');
    try {
        hc_register_masks($isCli);
        hc_run_checks($isCli, $selftest);
    } catch (Throwable $e) {
        hc_record('internal.error', 'FAIL', 'The check stopped early with an internal error: ' . hc_clean($e->getMessage()));
    }
    hc_cleanup_all();
    hc_check_cleanup();

    $report = hc_render($isCli, $selftest);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo $report;
    $GLOBALS['hc']['done'] = true;

    if ($isCli) {
        exit(hc_count('FAIL') > 0 ? 1 : 0);
    }
}

function hc_usage()
{
    return "TiffinDB host check\n\n"
        . "Usage: php host-check.php [--help]\n\n"
        . "Prints PASS / WARN / FAIL / INFO lines and a JSON block.\n"
        . "Exit code: 0 = no FAIL lines, 1 = at least one FAIL line, 2 = usage error.\n"
        . "From the CLI the script's own directory stands in for the document root,\n"
        . "and the Apache and HTTP checks are skipped; run it over HTTP for those.\n";
}

// ---------------------------------------------------------------------------
// HTTP access guard
// ---------------------------------------------------------------------------

function hc_http_headers()
{
    if (!function_exists('header') || headers_sent()) {
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
}

function hc_token_valid($token)
{
    return is_string($token) && strlen($token) >= HC_MIN_TOKEN_LENGTH && preg_match('/^[A-Za-z0-9]+$/', $token) === 1;
}

function hc_http_guard()
{
    $token = (string) HOST_CHECK_TOKEN;
    if (!hc_token_valid($token)) {
        hc_refuse("TiffinDB host check: HTTP use is disabled.\n\n"
            . "To enable it, open host-check.php in a text editor and set HOST_CHECK_TOKEN\n"
            . "near the top to a random value of at least " . HC_MIN_TOKEN_LENGTH . " characters, using only\n"
            . "letters and digits (A-Z, a-z, 0-9). Upload it again and visit\n"
            . "host-check.php?token=<that value>.\n"
            . "Delete the file from the host when you are done.\n");
    }
    $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if (!hash_equals($token, $given)) {
        hc_refuse("Forbidden: missing or wrong token.\n");
    }
}

function hc_refuse($text)
{
    if (function_exists('http_response_code')) {
        http_response_code(403);
    }
    echo $text;
    $GLOBALS['hc']['done'] = true;
    exit(0);
}

// Answers the Authorization self-request. Authenticated by the per-run nonce
// whose SHA-256 hash the running check stored in its temporary directory.
// Prints only whether the header is present, never its value.
function hc_answer_probe()
{
    $run = isset($_GET['run']) && is_string($_GET['run']) ? $_GET['run'] : '';
    $nonce = isset($_GET['nonce']) && is_string($_GET['nonce']) ? $_GET['nonce'] : '';
    $ok = false;
    if ($_GET['probe'] === 'auth' && preg_match(HC_NAME_PATTERN, $run) === 1 && preg_match('/^[0-9a-f]{32}$/', $nonce) === 1) {
        $stored = hc_fs('file_get_contents', __DIR__ . '/' . $run . '/nonce.sha256');
        $ok = is_string($stored) && hash_equals(trim($stored), hash('sha256', $nonce));
    }
    if (!$ok) {
        hc_refuse("Forbidden.\n");
    }
    echo hc_auth_source() === '' ? 'auth=absent' : 'auth=present';
    $GLOBALS['hc']['done'] = true;
    exit(0);
}

// Report only where the header was found, never its value.
function hc_auth_source()
{
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $key;
        }
    }
    foreach (array('getallheaders', 'apache_request_headers') as $fn) {
        if (function_exists($fn)) {
            $headers = @call_user_func($fn);
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string) $name) === 'authorization' && (string) $value !== '') {
                        return $fn;
                    }
                }
            }
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Error handling, safe calls, masking and cleanup
// ---------------------------------------------------------------------------

function hc_error_handler($errno, $errstr)
{
    $GLOBALS['hc']['last_err'] = (string) $errstr;
    return true; // never let PHP print warnings (they may contain paths)
}

function hc_last_error()
{
    $msg = $GLOBALS['hc']['last_err'];
    $GLOBALS['hc']['last_err'] = '';
    return hc_clean($msg);
}

// Call a function a host may have disabled. Returns false (and notes why)
// when it is unavailable or throws, so a disabled function never aborts the run.
function hc_fs($fn)
{
    $args = func_get_args();
    array_shift($args);
    if (!function_exists($fn)) {
        $GLOBALS['hc']['last_err'] = $fn . '() is disabled';
        $GLOBALS['hc']['disabled_seen'][$fn] = true;
        return false;
    }
    try {
        return call_user_func_array($fn, $args);
    } catch (Throwable $e) {
        $GLOBALS['hc']['last_err'] = $e->getMessage();
        return false;
    }
}

function hc_shutdown()
{
    hc_cleanup_all();
    if ($GLOBALS['hc']['done']) {
        return;
    }
    $err = error_get_last();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $msg = is_array($err) && isset($err['message']) ? hc_clean($err['message']) : 'unknown error';
    $left = hc_leftovers();
    echo "TiffinDB host check stopped with an internal error: " . $msg . "\n"
        . (count($left) === 0
            ? "No temporary files created by this run remain.\n"
            : "These temporary directories could not be removed; delete them by hand: " . implode(', ', $left) . "\n");
}

function hc_mask_add($value, $label)
{
    if (!is_string($value) || strlen($value) < 2) {
        return;
    }
    if (!isset($GLOBALS['hc']['masks'][$value])) {
        $GLOBALS['hc']['masks'][$value] = $label;
    }
    $real = hc_fs('realpath', $value);
    if (is_string($real) && strlen($real) > 1 && !isset($GLOBALS['hc']['masks'][$real])) {
        $GLOBALS['hc']['masks'][$real] = $label;
    }
}

function hc_register_masks($isCli)
{
    $ctx = hc_context($isCli);
    hc_mask_add($ctx['outside'], $ctx['outside_label']);
    if ($ctx['docroot'] !== '') {
        hc_mask_add($ctx['docroot'], '<docroot>');
        hc_mask_add(isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '', '<docroot>');
    }
    hc_mask_add($ctx['script_dir'], $ctx['script_label']);
    hc_mask_add(__FILE__, $ctx['script_label'] . '/' . basename(__FILE__));
    hc_mask_add(function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '', '<tmp>');
    hc_mask_add((string) ini_get('upload_tmp_dir'), '<upload-tmp>');
    hc_mask_add(function_exists('getcwd') ? (string) @getcwd() : '', '<cwd>');
    if (function_exists('getenv')) {
        hc_mask_add((string) getenv('HOME'), '<home>');
    }
    foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'LOCAL_ADDR', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR') as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && strlen($_SERVER[$key]) >= 3) {
            hc_mask_add($_SERVER[$key], '<host>');
            $host = preg_replace('/:\d+$/', '', $_SERVER[$key]);
            if (strlen($host) >= 3) {
                hc_mask_add($host, '<host>');
            }
        }
    }
    $token = (string) HOST_CHECK_TOKEN;
    if ($token !== '') {
        hc_mask_add($token, '<token>');
        hc_mask_add(rawurlencode($token), '<token>');
        hc_mask_add(urlencode($token), '<token>');
    }
    uksort($GLOBALS['hc']['masks'], 'hc_len_desc');
}

function hc_len_desc($a, $b)
{
    return strlen((string) $b) - strlen((string) $a);
}

// Mask known paths, hosts, the token, generic absolute paths and IP addresses.
function hc_mask($text)
{
    $text = (string) $text;
    foreach ($GLOBALS['hc']['masks'] as $value => $label) {
        $text = str_replace((string) $value, $label, $text);
    }
    $text = preg_replace('#(?<![\w.<>~-])/(?:[^\s/\'"<>]+/)+[^\s/\'"<>]*#', '<path>', $text);
    $text = preg_replace('#[A-Za-z]:\\\\[^\s\'"<>]+#', '<path>', $text);
    $text = preg_replace('/\[[0-9A-Fa-f:.]+\]/', '<ip>', $text);
    $text = preg_replace('/(?<![\w:])(?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{1,4}|\d{1,3}(?:\.\d{1,3}){3})?(?![\w:])/', '<ip>', $text);
    $text = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '<ip>', $text);
    return $text;
}

// For dynamic text such as error messages: also mask host and user names.
function hc_clean($text)
{
    $text = hc_mask($text);
    $names = array();
    if (function_exists('gethostname')) {
        $names[] = (string) @gethostname();
    }
    if (function_exists('get_current_user')) {
        $names[] = (string) @get_current_user();
    }
    if (function_exists('getenv')) {
        $names[] = (string) getenv('USER');
        $names[] = (string) getenv('LOGNAME');
    }
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['name'])) {
            $names[] = (string) $pw['name'];
        }
    }
    foreach ($names as $name) {
        if (strlen($name) >= 2) {
            $text = preg_replace('/(?<![A-Za-z0-9_-])' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', '<name>', $text);
        }
    }
    $text = preg_replace('/\s+/', ' ', trim($text));
    return strlen($text) > 300 ? substr($text, 0, 300) . '...' : $text;
}

function hc_random_hex($bytes)
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        return '';
    }
}

function hc_unique_name()
{
    $hex = hc_random_hex(8);
    if ($hex === '') {
        $hex = substr(md5(uniqid('', true) . mt_rand()), 0, 16);
    }
    return HC_PREFIX . $hex;
}

// Normalise a directory path for comparison ('/' stays '/').
function hc_norm($path)
{
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    return $path === '' ? '/' : $path;
}

function hc_join($parent, $name)
{
    $parent = hc_norm($parent);
    return ($parent === '/' ? '' : $parent) . '/' . $name;
}

// Create a uniquely named directory inside $parent. It is registered for
// cleanup before mkdir, so nothing created is ever forgotten.
function hc_make_temp_dir($parent, $mode)
{
    if (!hc_fs('is_dir', $parent)) {
        return '';
    }
    $dir = hc_join($parent, hc_unique_name());
    $GLOBALS['hc']['cleanup'][$dir] = hc_norm($parent);
    if (!hc_fs('mkdir', $dir, $mode)) {
        return '';
    }
    hc_fs('chmod', $dir, $mode);
    return $dir;
}

// Delete a tree without following symbolic links.
function hc_rmtree($path)
{
    if (hc_fs('is_link', $path) || hc_fs('is_file', $path)) {
        return (bool) hc_fs('unlink', $path);
    }
    if (!hc_fs('is_dir', $path)) {
        return true;
    }
    $items = hc_fs('scandir', $path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                hc_rmtree($path . '/' . $item);
            }
        }
    }
    return (bool) hc_fs('rmdir', $path);
}

function hc_cleanup_all()
{
    foreach ($GLOBALS['hc']['cleanup'] as $dir => $parent) {
        // Only ever delete directories this run created, by exact name.
        if (preg_match(HC_NAME_PATTERN, basename($dir)) === 1 && hc_norm(dirname($dir)) === $parent) {
            hc_rmtree($dir);
        }
    }
}

// Directories this run created that still exist. If existence cannot be
// checked, the directory is listed rather than assumed gone.
function hc_leftovers()
{
    $left = array();
    $canCheck = function_exists('file_exists') && function_exists('is_link');
    foreach ($GLOBALS['hc']['cleanup'] as $dir => $parent) {
        if (!$canCheck || @file_exists($dir) || @is_link($dir)) {
            $left[] = hc_mask($dir);
        }
    }
    return $left;
}

function hc_check_cleanup()
{
    if (count($GLOBALS['hc']['cleanup']) === 0) {
        hc_record('fs.cleanup', 'INFO', 'No temporary files were created.');
        return;
    }
    $left = hc_leftovers();
    if (count($left) === 0) {
        hc_record('fs.cleanup', 'PASS', 'All temporary files and directories created by this check were removed.', array());
    } else {
        hc_record('fs.cleanup', 'FAIL', 'Could not remove (or could not confirm removal of): ' . implode(', ', $left) . '. Delete these by hand.', $left);
    }
}

// Remove leftovers of an earlier run that was killed before cleaning up.
// Only names this script generates are touched; symbolic links are never
// followed or removed; directories younger than HC_STALE_SECONDS are left
// alone in case another run is in progress.
function hc_sweep_stale($parent, $label, &$removed, &$kept)
{
    $items = hc_fs('scandir', $parent);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if (preg_match(HC_NAME_PATTERN, $item) !== 1) {
            continue;
        }
        $path = hc_join($parent, $item);
        if (hc_fs('is_link', $path) || !hc_fs('is_dir', $path)) {
            $kept[] = $label . '/' . $item . ' (not a plain directory)';
            continue;
        }
        $mtime = hc_fs('filemtime', $path);
        if (!is_int($mtime) || $mtime > time() - HC_STALE_SECONDS) {
            $kept[] = $label . '/' . $item . ' (recent; another run may be active)';
            continue;
        }
        hc_rmtree($path);
        if (@file_exists($path)) {
            $kept[] = $label . '/' . $item . ' (could not remove)';
        } else {
            $removed[] = $label . '/' . $item;
        }
    }
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

function hc_record($id, $status, $message, $value = null)
{
    if (is_string($value)) {
        $value = hc_mask($value);
    } elseif (is_array($value)) {
        array_walk_recursive($value, 'hc_mask_ref');
    }
    $GLOBALS['hc']['checks'][$id] = array(
        'status'  => $status,
        'message' => hc_mask($message),
        'value'   => $value,
    );
}

function hc_mask_ref(&$item)
{
    if (is_string($item)) {
        $item = hc_mask($item);
    }
}

function hc_count($status)
{
    $n = 0;
    foreach ($GLOBALS['hc']['checks'] as $check) {
        if ($check['status'] === $status) {
            $n++;
        }
    }
    return $n;
}

function hc_render($isCli, $selftest)
{
    $groups = array(
        'php'      => 'PHP',
        'ext'      => 'Extensions',
        'crypto'   => 'Extensions',
        'sqlite'   => 'SQLite',
        'limit'    => 'Limits',
        'fs'       => 'Filesystem',
        'web'      => 'Web server',
        'internal' => 'Internal',
    );
    $out = "TiffinDB host check (report format " . HC_FORMAT . ")\n";
    $out .= "This report is designed to be safe to paste into a public issue: it contains no\n"
        . "hostnames, IP addresses, usernames, absolute paths, environment variables or\n"
        . "secrets. Paths appear as <docroot>, <docroot>/.. or <script-dir>. Read it before\n"
        . "posting anyway.\n\n";
    $out .= 'Mode: ' . ($isCli ? 'CLI' : 'HTTP');
    $out .= $isCli ? " (Apache and HTTP checks need an HTTP run)\n" : ($selftest ? " (self-requests on)\n" : " (self-requests off: selftest=0)\n");

    $byGroup = array();
    foreach (array_unique(array_values($groups)) as $name) {
        $byGroup[$name] = '';
    }
    $byGroup['Other'] = '';
    foreach ($GLOBALS['hc']['checks'] as $id => $check) {
        $parts = explode('.', $id, 2);
        $group = isset($groups[$parts[0]]) ? $groups[$parts[0]] : 'Other';
        $byGroup[$group] .= sprintf("%-4s  %-30s %s\n", $check['status'], $id, $check['message']);
    }
    foreach ($byGroup as $name => $lines) {
        if ($lines !== '') {
            $out .= "\n== " . $name . " ==\n" . $lines;
        }
    }

    $fail = hc_count('FAIL');
    $summary = array('pass' => hc_count('PASS'), 'warn' => hc_count('WARN'), 'fail' => $fail, 'info' => hc_count('INFO'));
    $out .= sprintf("\nSummary: %d PASS, %d WARN, %d FAIL, %d INFO\n", $summary['pass'], $summary['warn'], $fail, $summary['info']);
    $out .= $fail > 0
        ? "Result: blocking problems found (see FAIL lines).\n"
        : "Result: no blocking problems found.\n";

    $json = array(
        'tool'        => 'tiffindb-host-check',
        'format'      => HC_FORMAT,
        'mode'        => $isCli ? 'cli' : 'http',
        'selftest'    => $selftest,
        'php_version' => PHP_VERSION,
        'summary'     => $summary,
        'checks'      => $GLOBALS['hc']['checks'],
    );
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = function_exists('json_encode') ? json_encode($json, $flags) : false;
    $out .= "\n----- BEGIN TIFFINDB HOST CHECK JSON -----\n";
    $out .= ($encoded === false ? '{"error": "json_encode unavailable"}' : $encoded) . "\n";
    $out .= "----- END TIFFINDB HOST CHECK JSON -----\n";
    // Final safety net over the whole report.
    return hc_mask($out);
}

// ---------------------------------------------------------------------------
// Context: where things are, without printing where things are
// ---------------------------------------------------------------------------

function hc_context($isCli)
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }
    $scriptDir = hc_fs('realpath', __DIR__);
    if (!is_string($scriptDir) || $scriptDir === '') {
        $scriptDir = __DIR__;
    }
    $scriptDir = hc_norm($scriptDir);
    $docroot = '';
    if (!$isCli && isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
        $real = hc_fs('realpath', $_SERVER['DOCUMENT_ROOT']);
        if (is_string($real) && $real !== '') {
            $real = hc_norm($real);
            if ($scriptDir === $real || strpos($scriptDir . '/', ($real === '/' ? '' : $real) . '/') === 0) {
                $docroot = $real;
            }
        }
    }
    if ($docroot !== '') {
        $rel = ltrim(substr($scriptDir, strlen($docroot)), '/');
        $scriptLabel = '<docroot>' . ($rel === '' ? '' : '/' . $rel);
        $outside = dirname($docroot);
        $outsideLabel = '<docroot>/..';
        $outsideNote = 'outside the web root';
    } else {
        $scriptLabel = '<script-dir>';
        $outside = dirname($scriptDir);
        $outsideLabel = '<script-dir>/..';
        $outsideNote = $isCli
            ? 'the parent of the script directory; outside the web root only if the script sits directly in the web root'
            : 'the parent of the script directory; the document root is unknown, so this may be inside the web root';
    }
    $ctx = array(
        'docroot'       => $docroot,
        'script_dir'    => $scriptDir,
        'script_label'  => $scriptLabel,
        'outside'       => hc_norm($outside),
        'outside_label' => $outsideLabel,
        'outside_note'  => $outsideNote,
    );
    return $ctx;
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

function hc_run_checks($isCli, $selftest)
{
    hc_check_php();
    hc_check_extensions();
    hc_check_sqlite_memory();
    hc_check_limits($isCli);
    hc_check_functions();
    $ctx = hc_context($isCli);
    hc_check_filesystem($ctx, $isCli);
    hc_check_web($ctx, $isCli, $selftest);
}

function hc_check_php()
{
    $ok = version_compare(PHP_VERSION, HC_MIN_PHP, '>=');
    hc_record('php.version', $ok ? 'PASS' : 'FAIL', 'PHP ' . PHP_VERSION . ($ok ? '' : ' is too old; TiffinDB needs ' . HC_MIN_PHP . ' or later') . '.', PHP_VERSION);
    hc_record('php.sapi', 'INFO', 'Server API: ' . PHP_SAPI . '.', PHP_SAPI);
    hc_record('php.os_family', 'INFO', 'OS family: ' . (defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS) . '.', defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS);
}

function hc_check_extensions()
{
    // id => array(extension, status if missing, why)
    $list = array(
        'ext.pdo_sqlite' => array('pdo_sqlite', 'FAIL', 'all TiffinDB data is stored in SQLite via PDO'),
        'ext.zip'        => array('zip', 'FAIL', 'needed for XLSX import/export and project archives'),
        'ext.xmlreader'  => array('xmlreader', 'FAIL', 'needed for XLSX import'),
        'ext.mbstring'   => array('mbstring', 'FAIL', 'needed for correct text handling'),
        'ext.session'    => array('session', 'FAIL', 'needed for sign-in'),
        'ext.json'       => array('json', 'FAIL', 'needed for JSON export and internal data'),
        'ext.fileinfo'   => array('fileinfo', 'WARN', 'used for attachment MIME checks'),
        'ext.openssl'    => array('openssl', 'WARN', 'used for HTTPS requests and some crypto; random_bytes is checked separately'),
    );
    foreach ($list as $id => $item) {
        $loaded = extension_loaded($item[0]);
        $version = $loaded ? (string) phpversion($item[0]) : '';
        hc_record($id, $loaded ? 'PASS' : $item[1],
            $item[0] . ($loaded ? ' loaded' . ($version !== '' && $version !== PHP_VERSION ? ' (' . $version . ')' : '') : ' missing: ' . $item[2]) . '.',
            $loaded);
    }

    $randOk = false;
    if (function_exists('random_bytes')) {
        try {
            $randOk = strlen(random_bytes(16)) === 16;
        } catch (Throwable $e) {
            $randOk = false;
        }
    }
    hc_record('crypto.random_bytes', $randOk ? 'PASS' : 'FAIL', $randOk ? 'random_bytes works.' : 'random_bytes is unavailable; secure tokens cannot be generated.', $randOk);

    $hasArgon = defined('PASSWORD_ARGON2ID') && function_exists('password_hash');
    $argonWorks = false;
    if ($hasArgon) {
        $hash = @password_hash('tiffindb-host-check', PASSWORD_ARGON2ID);
        $argonWorks = is_string($hash) && password_verify('tiffindb-host-check', $hash);
    }
    $bcrypt = function_exists('password_hash') && is_string(@password_hash('tiffindb-host-check', PASSWORD_BCRYPT));
    if ($argonWorks) {
        hc_record('crypto.argon2id', 'PASS', 'password_hash supports Argon2id.', 'argon2id');
    } elseif ($bcrypt) {
        hc_record('crypto.argon2id', 'WARN', 'Argon2id unavailable; TiffinDB will fall back to bcrypt.', 'bcrypt');
    } else {
        hc_record('crypto.argon2id', 'FAIL', 'password_hash is unavailable.', 'none');
    }
}

function hc_check_sqlite_memory()
{
    if (!extension_loaded('pdo_sqlite') || !class_exists('PDO')) {
        hc_record('sqlite.version', 'FAIL', 'Skipped: pdo_sqlite is missing.');
        return;
    }
    try {
        $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.7.0', '<')) {
            hc_record('sqlite.version', 'FAIL', 'SQLite ' . $version . ' is too old for WAL (needs 3.7.0 or later).', $version);
        } elseif (version_compare($version, '3.35.0', '<')) {
            hc_record('sqlite.version', 'WARN', 'SQLite ' . $version . ' supports WAL but lacks features from 3.35 (DROP COLUMN, RETURNING).', $version);
        } else {
            hc_record('sqlite.version', 'PASS', 'SQLite ' . $version . ' (supports WAL).', $version);
        }

        $fts = false;
        try {
            $pdo->exec('CREATE VIRTUAL TABLE hc_fts USING fts5(body)');
            $pdo->exec("INSERT INTO hc_fts (body) VALUES ('example searchable text')");
            $fts = (int) $pdo->query("SELECT count(*) FROM hc_fts WHERE hc_fts MATCH 'searchable'")->fetchColumn() === 1;
        } catch (Throwable $e) {
            $fts = false;
        }
        hc_record('sqlite.fts5', $fts ? 'PASS' : 'WARN', $fts ? 'FTS5 full-text search available.' : 'FTS5 unavailable; search would need a slower fallback.', $fts);

        $json = false;
        try {
            $json = (int) $pdo->query("SELECT json_extract('{\"a\":2}', '$.a')")->fetchColumn() === 2;
        } catch (Throwable $e) {
            $json = false;
        }
        hc_record('sqlite.json1', $json ? 'PASS' : 'WARN', $json ? 'JSON1 functions available.' : 'JSON1 functions unavailable.', $json);
        $pdo = null;
    } catch (Throwable $e) {
        hc_record('sqlite.version', 'FAIL', 'Could not open an in-memory SQLite database: ' . hc_clean($e->getMessage()));
    }
}

function hc_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $n = (float) $value;
    $unit = strtolower(substr($value, -1));
    if ($unit === 'g') {
        $n *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $n *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $n *= 1024;
    }
    return (int) $n;
}

function hc_check_limits($isCli)
{
    $mb = 1024 * 1024;

    $raw = (string) ini_get('memory_limit');
    $v = hc_bytes($raw);
    hc_record('limit.memory_limit', ($v === -1 || $v >= 128 * $mb) ? 'PASS' : 'WARN',
        'memory_limit = ' . $raw . (($v === -1 || $v >= 128 * $mb) ? '' : ' (below 128M; large imports may fail)') . '.', $raw);

    $raw = isset($GLOBALS['hc']['max_execution_time']) ? $GLOBALS['hc']['max_execution_time'] : (string) ini_get('max_execution_time');
    $v = (int) $raw;
    $note = $isCli ? ' (the CLI ignores this; check over HTTP)' : '';
    hc_record('limit.max_execution_time', ($v === 0 || $v >= 30) ? 'PASS' : 'WARN',
        'max_execution_time = ' . $raw . 's' . (($v === 0 || $v >= 30) ? '' : ' (below 30s; long imports may stop)') . $note . '.', $v);

    $rawUp = (string) ini_get('upload_max_filesize');
    $up = hc_bytes($rawUp);
    hc_record('limit.upload_max_filesize', ($up === -1 || $up >= 8 * $mb) ? 'PASS' : 'WARN',
        'upload_max_filesize = ' . $rawUp . (($up === -1 || $up >= 8 * $mb) ? '' : ' (below 8M; attachments and imports are limited)') . '.', $rawUp);

    $rawPost = (string) ini_get('post_max_size');
    $post = hc_bytes($rawPost);
    $postOk = ($post <= 0) || ($post >= 8 * $mb && ($up <= 0 || $post >= $up));
    hc_record('limit.post_max_size', $postOk ? 'PASS' : 'WARN',
        'post_max_size = ' . $rawPost . ($postOk ? '' : ' (should be at least 8M and at least upload_max_filesize)') . '.', $rawPost);

    $raw = (string) ini_get('max_file_uploads');
    $v = (int) $raw;
    hc_record('limit.max_file_uploads', $v >= 10 ? 'PASS' : 'WARN', 'max_file_uploads = ' . $raw . ($v >= 10 ? '' : ' (below 10)') . '.', $v);

    $raw = ini_get('max_input_vars');
    $v = $raw === false ? 1000 : (int) $raw;
    hc_record('limit.max_input_vars', $v >= 1000 ? 'PASS' : 'WARN', 'max_input_vars = ' . ($raw === false ? 'default' : $raw) . ($v >= 1000 ? '' : ' (below 1000; large forms may lose fields)') . '.', $v);
}

function hc_check_functions()
{
    $required = array('fopen', 'fclose', 'flock', 'mkdir', 'rename', 'unlink', 'rmdir', 'file_put_contents',
        'file_get_contents', 'file_exists', 'is_dir', 'is_link', 'scandir', 'realpath', 'is_writable', 'tempnam',
        'header', 'setcookie', 'session_start', 'password_hash', 'password_verify', 'hash_equals', 'random_bytes');
    $optional = array('chmod', 'filemtime', 'ini_set', 'set_time_limit', 'ignore_user_abort', 'finfo_open',
        'mb_strlen', 'stream_socket_client', 'opcache_invalidate');

    $disabledList = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $missingReq = array();
    $missingOpt = array();
    foreach ($required as $fn) {
        if (in_array($fn, $disabledList, true) || !function_exists($fn)) {
            $missingReq[] = $fn;
        }
    }
    foreach ($optional as $fn) {
        if (in_array($fn, $disabledList, true) || !function_exists($fn)) {
            $missingOpt[] = $fn;
        }
    }
    $value = array('required' => $missingReq, 'optional' => $missingOpt);
    if (count($missingReq) > 0) {
        hc_record('php.disable_functions', 'FAIL', 'Required functions disabled or missing: ' . implode(', ', $missingReq)
            . (count($missingOpt) ? '; optional: ' . implode(', ', $missingOpt) : '') . '.', $value);
    } elseif (count($missingOpt) > 0) {
        hc_record('php.disable_functions', 'WARN', 'Optional functions disabled or missing: ' . implode(', ', $missingOpt) . '.', $value);
    } else {
        hc_record('php.disable_functions', 'PASS', 'No commonly needed functions are disabled.', $value);
    }
    $basedir = (string) ini_get('open_basedir');
    hc_record('php.open_basedir', 'INFO', 'open_basedir is ' . ($basedir === '' ? 'not set' : 'set (value not shown)') . '.', $basedir !== '');
}

function hc_check_filesystem($ctx, $isCli)
{
    $label = $ctx['outside_label'];

    // 0. Leftovers from an earlier run that was killed.
    $removed = array();
    $kept = array();
    hc_sweep_stale($ctx['outside'], $label, $removed, $kept);
    hc_sweep_stale($ctx['script_dir'], $ctx['script_label'], $removed, $kept);
    if (count($kept) > 0) {
        hc_record('fs.stale_leftovers', 'WARN', 'Earlier-run directories left in place: ' . implode(', ', $kept)
            . (count($removed) ? '; removed: ' . implode(', ', $removed) : '') . '. Delete leftovers by hand once no check is running.',
            array('removed' => $removed, 'kept' => $kept));
    } elseif (count($removed) > 0) {
        hc_record('fs.stale_leftovers', 'INFO', 'Removed directories left by an earlier interrupted run: ' . implode(', ', $removed) . '.',
            array('removed' => $removed, 'kept' => array()));
    } else {
        hc_record('fs.stale_leftovers', 'INFO', 'No leftovers from earlier runs found.', array('removed' => array(), 'kept' => array()));
    }

    // 1. Directory outside the web root.
    $outDir = hc_make_temp_dir($ctx['outside'], 0700);
    if ($outDir !== '') {
        hc_record('fs.outside_webroot_writable', 'PASS', 'Can create a directory in ' . $label . ' (' . $ctx['outside_note'] . ').', true);
    } else {
        $err = hc_last_error();
        hc_record('fs.outside_webroot_writable', 'WARN', 'Cannot create a directory in ' . $label . ' (' . $ctx['outside_note'] . ')'
            . ($err !== '' ? ': ' . $err : '') . '. TiffinDB would have to keep data/ inside the web root behind a deny-all .htaccess.', false);
    }

    // 2. Directory beside the script (used for the .htaccess probes and as a fallback).
    $sideDir = hc_make_temp_dir($ctx['script_dir'], 0755);
    $GLOBALS['hc']['side_dir'] = $sideDir;
    if ($sideDir !== '') {
        hc_record('fs.beside_script_writable', 'PASS', 'Can create a directory in ' . $ctx['script_label'] . '.', true);
    } else {
        $err = hc_last_error();
        hc_record('fs.beside_script_writable', 'WARN', 'Cannot create a directory in ' . $ctx['script_label'] . ($err !== '' ? ': ' . $err : '') . '.', false);
    }

    $workDir = $outDir !== '' ? $outDir : $sideDir;
    $workLabel = $outDir !== '' ? $label : $ctx['script_label'];

    // 3. SQLite on disk with WAL.
    if ($workDir === '') {
        hc_record('fs.sqlite_file', 'FAIL', 'Skipped: no writable directory found.');
    } elseif (!extension_loaded('pdo_sqlite')) {
        hc_record('fs.sqlite_file', 'FAIL', 'Skipped: pdo_sqlite is missing.');
    } else {
        hc_check_sqlite_file($workDir, $workLabel);
    }

    // 4. flock.
    if ($workDir === '') {
        hc_record('fs.flock', 'FAIL', 'Skipped: no writable directory found.');
    } else {
        hc_check_flock($workDir, $workLabel);
    }

    // 5. Upload temp directory.
    $up = (string) ini_get('upload_tmp_dir');
    if ($up === '' && function_exists('sys_get_temp_dir')) {
        $up = sys_get_temp_dir();
    }
    $upOk = $up !== '' && hc_fs('is_dir', $up) && hc_fs('is_writable', $up);
    hc_record('fs.upload_tmp_writable', $upOk ? 'PASS' : 'WARN', $upOk ? 'Upload temporary directory is writable.' : 'Upload temporary directory is not writable or not visible (open_basedir?); uploads may fail.', (bool) $upOk);
}

function hc_check_sqlite_file($dir, $label)
{
    $file = $dir . '/check.sqlite';
    $marker = 'tiffindb-' . hc_random_hex(4);
    $pdo = null;
    $pdo2 = null;
    $stmt = null;
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $mode = strtolower((string) $pdo->query('PRAGMA journal_mode=WAL')->fetchColumn());
        hc_record('fs.sqlite_journal_mode', $mode === 'wal' ? 'PASS' : 'WARN',
            'Journal mode obtained: ' . $mode . ($mode === 'wal' ? '.' : ' (WAL refused; the filesystem may not support shared memory).'), $mode);
        $pdo->exec('CREATE TABLE hc_check (id INTEGER PRIMARY KEY, v TEXT NOT NULL)');
        $stmt = $pdo->prepare('INSERT INTO hc_check (v) VALUES (?)');
        $stmt->execute(array($marker));
        $stmt = null;
        // Read back through a second connection, as concurrent requests would.
        $pdo2 = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $stmt = $pdo2->query('SELECT v FROM hc_check WHERE id = 1');
        $read = (string) $stmt->fetchColumn();
        $stmt = null;
        $pdo2 = null;
        if ($mode === 'wal') {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        }
        $pdo = null;
        if ($read === $marker) {
            hc_record('fs.sqlite_file', 'PASS', 'Created a SQLite database in ' . $label . ', wrote and read a row.', true);
        } else {
            hc_record('fs.sqlite_file', 'FAIL', 'SQLite database in ' . $label . ' returned wrong data.', false);
        }
    } catch (Throwable $e) {
        $stmt = null;
        $pdo2 = null;
        $pdo = null;
        hc_record('fs.sqlite_file', 'FAIL', 'SQLite database in ' . $label . ' failed: ' . hc_clean($e->getMessage()), false);
    }
    foreach (array('', '-wal', '-shm', '-journal') as $suffix) {
        if (hc_fs('file_exists', $file . $suffix)) {
            hc_fs('unlink', $file . $suffix);
        }
    }
}

function hc_check_flock($dir, $label)
{
    $file = $dir . '/lock-test';
    if (!function_exists('flock')) {
        hc_record('fs.flock', 'FAIL', 'flock() is disabled.', false);
        return;
    }
    $a = hc_fs('fopen', $file, 'c+');
    if (!is_resource($a)) {
        hc_record('fs.flock', 'FAIL', 'Cannot open a lock file in ' . $label . '.', false);
        return;
    }
    if (!@flock($a, LOCK_EX | LOCK_NB)) {
        hc_fs('fclose', $a);
        hc_fs('unlink', $file);
        hc_record('fs.flock', 'FAIL', 'flock() does not work (in ' . $label . ').', false);
        return;
    }
    $b = hc_fs('fopen', $file, 'c+');
    $excluded = is_resource($b) && !@flock($b, LOCK_EX | LOCK_NB);
    if (is_resource($b)) {
        hc_fs('fclose', $b);
    }
    @flock($a, LOCK_UN);
    hc_fs('fclose', $a);
    hc_fs('unlink', $file);
    if ($excluded) {
        hc_record('fs.flock', 'PASS', 'flock() works and excludes a second lock (in ' . $label . ').', true);
    } else {
        hc_record('fs.flock', 'WARN', 'flock() succeeds but does not exclude a second lock (in ' . $label . '; network filesystem?).', false);
    }
}

// ---------------------------------------------------------------------------
// Web server checks
// ---------------------------------------------------------------------------

function hc_server_family()
{
    $software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
    if (PHP_SAPI === 'apache2handler' || strpos($software, 'apache') !== false) {
        return 'apache';
    }
    if (PHP_SAPI === 'cli-server') {
        return 'php-builtin';
    }
    if (PHP_SAPI === 'litespeed' || strpos($software, 'litespeed') !== false) {
        return 'litespeed';
    }
    if (strpos($software, 'nginx') !== false) {
        return 'nginx';
    }
    return 'unknown';
}

function hc_check_web($ctx, $isCli, $selftest)
{
    if ($isCli) {
        hc_record('web.server', 'INFO', 'Skipped in CLI mode.');
        hc_record('web.https', 'INFO', 'Skipped in CLI mode.');
        hc_record('web.selftest', 'INFO', 'Skipped in CLI mode; run over HTTP to test .htaccess and the Authorization header.');
        return;
    }

    $family = hc_server_family();
    $names = array('apache' => 'Apache', 'litespeed' => 'LiteSpeed', 'nginx' => 'nginx', 'php-builtin' => 'PHP built-in server', 'unknown' => 'unknown (server name hidden)');
    if ($family === 'apache') {
        hc_record('web.server', 'PASS', 'Running under Apache.', $family);
    } else {
        hc_record('web.server', 'WARN', 'Not detected as Apache: ' . $names[$family] . '. TiffinDB v1 assumes Apache .htaccess.', $family);
    }
    $htaccessApplies = ($family === 'apache' || $family === 'litespeed' || $family === 'unknown');

    // HTTPS as PHP sees it. Forwarded-proto headers are client-controlled and
    // are not trusted; they only change the wording of a WARN.
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    if ($https) {
        hc_record('web.https', 'PASS', 'Request arrived over HTTPS.', true);
    } else {
        $claimed = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        hc_record('web.https', 'WARN', 'PHP sees plain HTTP' . ($claimed ? ' (an X-Forwarded-Proto header claims HTTPS; not trusted by this check)' : '')
            . '; TiffinDB needs HTTPS for Secure cookies.', false);
    }

    if (!$selftest) {
        hc_record('web.selftest', 'INFO', 'Self-requests skipped (selftest=0); no probe files written; .htaccess and Authorization checks not run.');
        return;
    }
    if (PHP_SAPI === 'cli-server') {
        $workers = function_exists('getenv') ? (int) getenv('PHP_CLI_SERVER_WORKERS') : 0;
        if ($workers < 2) {
            hc_record('web.selftest', 'WARN', 'Self-requests skipped: the PHP built-in server is single-threaded (set PHP_CLI_SERVER_WORKERS=2 or more).');
            return;
        }
    }
    $target = hc_self_target($https);
    if (is_string($target)) {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: ' . $target . '.');
        return;
    }
    if (!function_exists('stream_socket_client') || !function_exists('stream_context_create')) {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: stream_socket_client is disabled.');
        return;
    }
    $sideDir = $GLOBALS['hc']['side_dir'];
    $nonce = hc_random_hex(16);
    if ($sideDir === '' || $nonce === '') {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: no writable directory beside the script or no random source.');
        return;
    }
    $probe = hc_write_probe_files($sideDir, $nonce);
    if ($probe === null) {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: could not write the temporary probe files beside the script.');
        return;
    }
    hc_record('web.selftest', 'INFO', 'Self-requests sent to the local address and port that received this request.', true);

    $run = basename($sideDir);
    $dirPath = $target['dir'] . '/' . $run;

    // .htaccess probes
    $control = hc_http_get($target, $dirPath . '/control/probe.txt', array());
    $controlOk = $control['status'] === 200 && strpos($control['body'], $probe['control']) !== false;
    if (!$controlOk) {
        hc_record('web.htaccess_deny', 'WARN', 'Inconclusive: could not fetch an unprotected control file (' . hc_status_text($control) . ').');
        hc_record('web.htaccess_rewrite', 'WARN', 'Inconclusive: control request failed.');
    } else {
        $deny = hc_http_get($target, $dirPath . '/deny/probe.txt', array());
        $leaked = strpos($deny['body'], $probe['deny']) !== false;
        if (($deny['status'] === 403 || $deny['status'] === 404) && !$leaked) {
            hc_record('web.htaccess_deny', 'PASS', 'Deny-all .htaccess works (probe file returned ' . $deny['status'] . ').', $deny['status']);
        } elseif ($leaked) {
            hc_record('web.htaccess_deny', $htaccessApplies ? 'FAIL' : 'WARN',
                'Probe file behind a deny-all .htaccess was served (' . hc_status_text($deny) . ')'
                . ($htaccessApplies ? '; .htaccess overrides are ignored.' : '; expected, as this server does not read .htaccess.'), $deny['status']);
        } elseif ($deny['status'] === 500) {
            hc_record('web.htaccess_deny', 'WARN', 'Probe returned 500: .htaccess was read but a directive was rejected (AllowOverride may exclude AuthConfig).', 500);
        } else {
            hc_record('web.htaccess_deny', 'WARN', 'Unexpected result for deny probe (' . hc_status_text($deny) . ').', $deny['status']);
        }

        $rw = hc_http_get($target, $dirPath . '/rewrite/rewritten.txt', array());
        if ($rw['status'] === 200 && strpos($rw['body'], $probe['rewrite']) !== false) {
            hc_record('web.htaccess_rewrite', 'PASS', 'mod_rewrite rules in .htaccess work.', true);
        } else {
            $why = $rw['status'] === 500 ? 'rewrite directives rejected (AllowOverride FileInfo or Options FollowSymLinks missing?)' : 'rewrite rule not applied';
            hc_record('web.htaccess_rewrite', $htaccessApplies ? 'FAIL' : 'WARN', 'mod_rewrite test failed: ' . $why . ' (' . hc_status_text($rw) . ').', false);
        }
    }

    // Authorization header (dummy value; only presence is reported)
    $hdr = array('Authorization: ' . HC_DUMMY_AUTH);
    $q = 'run=' . $run . '&nonce=' . $nonce;
    $direct = hc_http_get($target, $target['script'] . '?probe=auth&' . $q, $hdr);
    $viaRewrite = hc_http_get($target, $dirPath . '/rewrite/auth.php?' . $q, $hdr);
    $directOk = $direct['status'] === 200 && trim($direct['body']) === 'auth=present';
    $rewriteOk = $viaRewrite['status'] === 200 && trim($viaRewrite['body']) === 'auth=present';
    if ($directOk) {
        hc_record('web.authorization_header', 'PASS', 'Authorization header reaches PHP without extra configuration (value not shown).', 'direct');
    } elseif ($rewriteOk) {
        hc_record('web.authorization_header', 'PASS', 'Authorization header reaches PHP only with a RewriteRule E=HTTP_AUTHORIZATION line in .htaccess.', 'via-rewrite');
    } elseif ($direct['status'] === 200) {
        hc_record('web.authorization_header', 'WARN', 'Authorization header does not reach PHP, even with the RewriteRule workaround ('
            . hc_status_text($viaRewrite) . '); the post-v1 MCP server would not work.', 'absent');
    } else {
        hc_record('web.authorization_header', 'WARN', 'Inconclusive: self-request failed (' . hc_status_text($direct) . ').', 'unknown');
    }
}

function hc_status_text($res)
{
    if ($res['status'] === 0) {
        return 'no response: ' . $res['error'];
    }
    return 'HTTP ' . $res['status'];
}

// Where self-requests go: the local address and port that received this
// request, never a host taken from the request. The Host header is only sent
// along (validated) so that name-based virtual hosting picks the same site.
// Returns an array, or a string explaining why self-requests are not possible.
function hc_self_target($https)
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        return 'the Host header is missing or not in an expected format';
    }
    $addr = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';
    if ($addr === '' && PHP_SAPI === 'cli-server' && isset($_SERVER['SERVER_NAME'])) {
        $addr = (string) $_SERVER['SERVER_NAME']; // the built-in server reports its bind address here
    }
    if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
        return 'the server did not report the local address that received the request';
    }
    $port = isset($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '';
    if (!preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
        return 'the server did not report the local port that received the request';
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    if (!preg_match('#^/[A-Za-z0-9._~/-]*$#', $script) || strpos($script, '..') !== false) {
        return 'the script path is not in an expected format';
    }
    $peer = preg_replace('/:\d+$/', '', $host);
    $peer = trim($peer, '[]');
    $connectHost = strpos($addr, ':') !== false ? '[' . $addr . ']' : $addr;
    return array(
        'connect' => ($https ? 'ssl://' : 'tcp://') . $connectHost . ':' . (int) $port,
        'tls'     => $https,
        'host'    => $host,
        'peer'    => $peer,
        'script'  => $script,
        'dir'     => rtrim(str_replace('\\', '/', dirname($script)), '/'),
    );
}

function hc_write_probe_files($sideDir, $nonce)
{
    $markers = array(
        'control' => 'control-' . hc_random_hex(6),
        'deny'    => 'deny-' . hc_random_hex(6),
        'rewrite' => 'rewrite-' . hc_random_hex(6),
    );
    $nonceHash = hash('sha256', $nonce);
    $deny = "# TiffinDB host check probe (temporary)\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $rewrite = "# TiffinDB host check probe (temporary)\n"
        . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
        . "    RewriteRule ^rewritten\\.txt$ target.txt [L]\n"
        . "    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\n";
    // Prints only whether the header is present, and only to a caller with this run's nonce.
    $authProbe = "<?php\n"
        . "header('Content-Type: text/plain');\n"
        . "\$n = isset(\$_GET['nonce']) && is_string(\$_GET['nonce']) ? \$_GET['nonce'] : '';\n"
        . "if (!hash_equals('" . $nonceHash . "', hash('sha256', \$n))) { http_response_code(403); exit; }\n"
        . "\$p = !empty(\$_SERVER['HTTP_AUTHORIZATION']) || !empty(\$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);\n"
        . "echo \$p ? 'auth=present' : 'auth=absent';\n";

    $files = array(
        'nonce.sha256'       => $nonceHash . "\n",
        'control/probe.txt'  => $markers['control'] . "\n",
        'deny/.htaccess'     => $deny,
        'deny/probe.txt'     => $markers['deny'] . "\n",
        'rewrite/.htaccess'  => $rewrite,
        'rewrite/target.txt' => $markers['rewrite'] . "\n",
        'rewrite/auth.php'   => $authProbe,
    );
    foreach (array('control', 'deny', 'rewrite') as $sub) {
        if (!hc_fs('mkdir', $sideDir . '/' . $sub, 0755)) {
            return null;
        }
        hc_fs('chmod', $sideDir . '/' . $sub, 0755);
    }
    foreach ($files as $rel => $content) {
        if (hc_fs('file_put_contents', $sideDir . '/' . $rel, $content) === false) {
            return null;
        }
        hc_fs('chmod', $sideDir . '/' . $rel, 0644);
    }
    return $markers;
}

// Minimal HTTP/1.0 GET over a socket pinned to the local server address.
// Error text from PHP or the network is never passed through; only fixed
// categories are reported.
function hc_http_get($target, $path, $headers)
{
    $res = array('status' => 0, 'body' => '', 'error' => '');
    $ctx = stream_context_create(array('ssl' => array(
        'peer_name'         => $target['peer'],
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'SNI_enabled'       => true,
    )));
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($target['connect'], $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!is_resource($fp)) {
        $res['error'] = $target['tls'] ? 'connection or TLS handshake failed' : 'connection failed';
        return $res;
    }
    if (function_exists('stream_set_timeout')) {
        stream_set_timeout($fp, 10);
    }
    $req = 'GET ' . $path . " HTTP/1.0\r\n"
        . 'Host: ' . $target['host'] . "\r\n"
        . "User-Agent: TiffinDB-host-check\r\n"
        . "Connection: close\r\n";
    foreach ($headers as $h) {
        $req .= $h . "\r\n";
    }
    $req .= "\r\n";
    if (@fwrite($fp, $req) === false) {
        fclose($fp);
        $res['error'] = 'could not send request';
        return $res;
    }
    $buf = '';
    $timedOut = false;
    while (!feof($fp) && strlen($buf) < 131072) {
        $chunk = @fread($fp, 8192);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                $timedOut = true;
            }
            break;
        }
        $buf .= $chunk;
    }
    fclose($fp);
    $split = strpos($buf, "\r\n\r\n");
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $buf, $m)) {
        $res['status'] = (int) $m[1];
        $res['body'] = $split === false ? '' : substr($buf, $split + 4, 65536);
    } else {
        $res['error'] = $timedOut ? 'timed out' : 'no valid HTTP response';
    }
    return $res;
}
