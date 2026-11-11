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
 * makes a few HTTP(S) requests to its own host (the Host header of the current
 * request) to see what the web server serves. It makes no other network calls.
 */

// ---------------------------------------------------------------------------
// Maintainer setting: set a random token of at least 16 characters before
// uploading, then visit host-check.php?token=<the same value>.
// An empty token disables HTTP use entirely. The CLI never needs the token.
// ---------------------------------------------------------------------------
const HOST_CHECK_TOKEN = '';

// ---------------------------------------------------------------------------

const HC_FORMAT = 1;
const HC_MIN_TOKEN_LENGTH = 16;
const HC_MIN_PHP = '8.3.0';
const HC_PREFIX = 'tiffindb-hostcheck-';
const HC_DUMMY_AUTH = 'Bearer tiffindb-hostcheck-dummy-value';

$GLOBALS['hc'] = array(
    'checks'   => array(),
    'masks'    => array(),
    'cleanup'  => array(),
    'last_err' => '',
    'done'     => false,
);

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
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
                exit(0);
            }
            fwrite(STDERR, "Unknown option.\n\n" . hc_usage());
            exit(2);
        }
    } else {
        hc_http_headers();
        hc_http_guard();
        if (isset($_GET['probe']) && $_GET['probe'] === 'auth') {
            echo hc_auth_source() === '' ? 'auth=absent' : 'auth=present';
            $GLOBALS['hc']['done'] = true;
            exit(0);
        }
    }

    // Remember the configured limit before raising it for this run.
    $GLOBALS['hc']['max_execution_time'] = (string) ini_get('max_execution_time');
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    ob_start();
    $selftest = !$isCli && !(isset($_GET['selftest']) && $_GET['selftest'] === '0');
    hc_register_masks($isCli);
    hc_run_checks($isCli, $selftest);
    hc_cleanup_all();
    hc_check_cleanup();

    $report = hc_render($isCli, $selftest);
    ob_end_clean();
    echo $report;
    $GLOBALS['hc']['done'] = true;

    $fail = hc_count('FAIL');
    if ($isCli) {
        exit($fail > 0 ? 1 : 0);
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

function hc_http_guard()
{
    $token = (string) HOST_CHECK_TOKEN;
    if ($token === '' || strlen($token) < HC_MIN_TOKEN_LENGTH) {
        hc_refuse("TiffinDB host check: HTTP use is disabled.\n\n"
            . "To enable it, open host-check.php in a text editor, set HOST_CHECK_TOKEN\n"
            . "near the top to a random value of at least " . HC_MIN_TOKEN_LENGTH . " characters, upload it\n"
            . "again, and visit host-check.php?token=<that value>.\n"
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
// Error handling, masking and cleanup
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
    echo "TiffinDB host check stopped with an internal error: " . $msg . "\n"
        . "Temporary files were removed where possible.\n";
}

function hc_mask_add($value, $label)
{
    if (!is_string($value) || strlen($value) < 2) {
        return;
    }
    if (!isset($GLOBALS['hc']['masks'][$value])) {
        $GLOBALS['hc']['masks'][$value] = $label;
    }
    $real = @realpath($value);
    if (is_string($real) && strlen($real) > 1 && !isset($GLOBALS['hc']['masks'][$real])) {
        $GLOBALS['hc']['masks'][$real] = $label;
    }
}

function hc_register_masks($isCli)
{
    $ctx = hc_context($isCli);
    // Longer strings first so that a parent path does not hide a child label.
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
    foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'LOCAL_ADDR', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_HOST') as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && strlen($_SERVER[$key]) >= 4) {
            hc_mask_add($_SERVER[$key], '<host>');
            $host = preg_replace('/:\d+$/', '', $_SERVER[$key]);
            if (strlen($host) >= 4) {
                hc_mask_add($host, '<host>');
            }
        }
    }
    if (strlen(HOST_CHECK_TOKEN) > 0) {
        hc_mask_add(HOST_CHECK_TOKEN, '<token>');
    }
    uksort($GLOBALS['hc']['masks'], 'hc_len_desc');
}

function hc_len_desc($a, $b)
{
    return strlen($b) - strlen($a);
}

// Mask known paths, hosts and generic absolute paths / IPv4 addresses.
function hc_mask($text)
{
    $text = (string) $text;
    foreach ($GLOBALS['hc']['masks'] as $value => $label) {
        $text = str_replace($value, $label, $text);
    }
    $text = preg_replace('#(?<![\w.<>~-])/(?:[^\s/\'"<>]+/)+[^\s/\'"<>]*#', '<path>', $text);
    $text = preg_replace('~[A-Za-z]:\\\\[^\s\'"<>]+~', '<path>', $text);
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

function hc_unique_name()
{
    try {
        $hex = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $hex = md5(uniqid('', true) . mt_rand());
    }
    return HC_PREFIX . $hex;
}

// Create a uniquely named directory inside $parent and register it for cleanup.
function hc_make_temp_dir($parent, $mode)
{
    if (!is_dir($parent)) {
        return '';
    }
    $dir = rtrim($parent, '/\\') . '/' . hc_unique_name();
    if (!@mkdir($dir, $mode)) {
        return '';
    }
    @chmod($dir, $mode);
    $GLOBALS['hc']['cleanup'][$dir] = $parent;
    return $dir;
}

function hc_rmtree($dir)
{
    if (is_link($dir) || is_file($dir)) {
        return @unlink($dir);
    }
    if (!is_dir($dir)) {
        return true;
    }
    $items = @scandir($dir);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                hc_rmtree($dir . '/' . $item);
            }
        }
    }
    return @rmdir($dir);
}

function hc_cleanup_all()
{
    foreach ($GLOBALS['hc']['cleanup'] as $dir => $parent) {
        // Only ever delete directories this run created, by exact name.
        if (strpos(basename($dir), HC_PREFIX) === 0 && dirname($dir) === rtrim($parent, '/\\')) {
            hc_rmtree($dir);
        }
    }
}

function hc_check_cleanup()
{
    $left = array();
    foreach ($GLOBALS['hc']['cleanup'] as $dir => $parent) {
        if (file_exists($dir)) {
            $left[] = hc_mask($dir);
        }
    }
    if (count($GLOBALS['hc']['cleanup']) === 0) {
        hc_record('fs.cleanup', 'INFO', 'No temporary files were created.');
    } elseif (count($left) === 0) {
        hc_record('fs.cleanup', 'PASS', 'All temporary files and directories created by this check were removed.');
    } else {
        hc_record('fs.cleanup', 'FAIL', 'Could not remove: ' . implode(', ', $left) . '. Delete these by hand.', $left);
    }
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

function hc_record($id, $status, $message, $value = null)
{
    $GLOBALS['hc']['checks'][$id] = array(
        'status'  => $status,
        'message' => hc_mask($message),
        'value'   => is_string($value) ? hc_mask($value) : $value,
    );
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
        'php'    => 'PHP',
        'ext'    => 'Extensions',
        'crypto' => 'Extensions',
        'sqlite' => 'SQLite',
        'limit'  => 'Limits',
        'fs'     => 'Filesystem',
        'web'    => 'Web server',
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
    $scriptDir = (string) @realpath(__DIR__);
    if ($scriptDir === '') {
        $scriptDir = __DIR__;
    }
    $docroot = '';
    if (!$isCli && isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
        $real = @realpath($_SERVER['DOCUMENT_ROOT']);
        if (is_string($real) && $real !== '' && ($scriptDir === $real || strpos($scriptDir . '/', rtrim($real, '/') . '/') === 0)) {
            $docroot = $real;
        }
    }
    if ($docroot !== '') {
        $rel = ltrim(substr($scriptDir, strlen($docroot)), '/');
        $scriptLabel = '<docroot>' . ($rel === '' ? '' : '/' . $rel);
        $outside = dirname($docroot);
        $outsideLabel = '<docroot>/..';
    } else {
        $scriptLabel = '<script-dir>';
        $outside = dirname($scriptDir);
        $outsideLabel = '<script-dir>/..';
    }
    $ctx = array(
        'docroot'       => $docroot,
        'script_dir'    => $scriptDir,
        'script_label'  => $scriptLabel,
        'outside'       => $outside,
        'outside_label' => $outsideLabel,
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
    $required = array('fopen', 'flock', 'mkdir', 'rename', 'unlink', 'rmdir', 'file_put_contents', 'tempnam',
        'header', 'setcookie', 'session_start', 'password_hash', 'password_verify', 'hash_equals', 'random_bytes',
        'scandir', 'realpath', 'is_writable');
    $optional = array('ini_set', 'set_time_limit', 'ignore_user_abort', 'finfo_open', 'mb_strlen', 'curl_exec',
        'fsockopen', 'stream_socket_client', 'opcache_invalidate');

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
    if (count($missingReq) > 0) {
        hc_record('php.disable_functions', 'FAIL', 'Required functions disabled or missing: ' . implode(', ', $missingReq)
            . (count($missingOpt) ? '; optional: ' . implode(', ', $missingOpt) : '') . '.', array('required' => $missingReq, 'optional' => $missingOpt));
    } elseif (count($missingOpt) > 0) {
        hc_record('php.disable_functions', 'WARN', 'Optional functions disabled or missing: ' . implode(', ', $missingOpt) . '.', array('required' => array(), 'optional' => $missingOpt));
    } else {
        hc_record('php.disable_functions', 'PASS', 'No commonly needed functions are disabled.', array('required' => array(), 'optional' => array()));
    }
    $basedir = (string) ini_get('open_basedir');
    hc_record('php.open_basedir', 'INFO', 'open_basedir is ' . ($basedir === '' ? 'not set' : 'set (value not shown)') . '.', $basedir !== '');
    hc_record('php.allow_url_fopen', 'INFO', 'allow_url_fopen is ' . (ini_get('allow_url_fopen') ? 'on' : 'off') . ' (only used by this check\'s self-requests).', (bool) ini_get('allow_url_fopen'));
}

function hc_check_filesystem($ctx, $isCli)
{
    $label = $ctx['outside_label'];

    // 1. Directory outside the web root.
    $outDir = hc_make_temp_dir($ctx['outside'], 0700);
    if ($outDir !== '') {
        hc_record('fs.outside_webroot_writable', 'PASS', 'Can create a directory in ' . $label . ' (outside the web root).', true);
    } else {
        $err = hc_last_error();
        hc_record('fs.outside_webroot_writable', 'WARN', 'Cannot create a directory in ' . $label . ($err !== '' ? ' (' . $err . ')' : '')
            . '; TiffinDB would have to keep data/ inside the web root behind a deny-all .htaccess.', false);
    }

    // 2. Directory beside the script (used for the .htaccess probes and as a fallback).
    $sideDir = hc_make_temp_dir($ctx['script_dir'], 0755);
    $GLOBALS['hc']['side_dir'] = $sideDir;
    if ($sideDir !== '') {
        hc_record('fs.beside_script_writable', 'PASS', 'Can create a directory in ' . $ctx['script_label'] . '.', true);
    } else {
        $err = hc_last_error();
        hc_record('fs.beside_script_writable', 'WARN', 'Cannot create a directory in ' . $ctx['script_label'] . ($err !== '' ? ' (' . $err . ')' : '') . '.', false);
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
    $upOk = $up !== '' && @is_dir($up) && @is_writable($up);
    hc_record('fs.upload_tmp_writable', $upOk ? 'PASS' : 'WARN', $upOk ? 'Upload temporary directory is writable.' : 'Upload temporary directory is not writable or not visible (open_basedir?); uploads may fail.', $upOk);
}

function hc_check_sqlite_file($dir, $label)
{
    $file = $dir . '/check.sqlite';
    $marker = 'tiffindb-' . substr(hc_unique_name(), -8);
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
        if (file_exists($file . $suffix)) {
            @unlink($file . $suffix);
        }
    }
}

function hc_check_flock($dir, $label)
{
    $file = $dir . '/lock-test';
    $a = @fopen($file, 'c+');
    if ($a === false) {
        hc_record('fs.flock', 'FAIL', 'Cannot open a lock file in ' . $label . '.', false);
        return;
    }
    $got = function_exists('flock') && @flock($a, LOCK_EX | LOCK_NB);
    if (!$got) {
        fclose($a);
        @unlink($file);
        hc_record('fs.flock', 'FAIL', 'flock() does not work in ' . $label . '.', false);
        return;
    }
    $b = @fopen($file, 'c+');
    $wouldBlock = 0;
    $excluded = $b !== false && !@flock($b, LOCK_EX | LOCK_NB, $wouldBlock);
    if ($b !== false) {
        fclose($b);
    }
    flock($a, LOCK_UN);
    fclose($a);
    @unlink($file);
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

    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    hc_record('web.https', $https ? 'PASS' : 'WARN', $https ? 'Request arrived over HTTPS.' : 'Request arrived over plain HTTP; use HTTPS for TiffinDB (Secure cookies).', $https);

    $sideDir = isset($GLOBALS['hc']['side_dir']) ? $GLOBALS['hc']['side_dir'] : '';
    $probe = $sideDir !== '' ? hc_write_probe_files($sideDir) : null;
    if ($probe === null) {
        hc_record('web.htaccess_files', 'WARN', 'Could not write temporary .htaccess probe files beside the script.');
    }

    if (!$selftest) {
        hc_record('web.selftest', 'INFO', 'Self-requests skipped (selftest=0); .htaccess and Authorization checks not run.');
        return;
    }
    if (PHP_SAPI === 'cli-server') {
        $workers = function_exists('getenv') ? (int) getenv('PHP_CLI_SERVER_WORKERS') : 0;
        if ($workers < 2) {
            hc_record('web.selftest', 'WARN', 'Self-requests skipped: the PHP built-in server is single-threaded (set PHP_CLI_SERVER_WORKERS=2 or more).');
            return;
        }
    }
    $base = hc_self_base($https);
    if ($base === '') {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: the request host or script path is not in an expected format.');
        return;
    }
    $client = (function_exists('curl_init') && function_exists('curl_exec')) ? 'curl' : (ini_get('allow_url_fopen') ? 'stream' : '');
    if ($client === '') {
        hc_record('web.selftest', 'WARN', 'Self-requests skipped: neither curl nor allow_url_fopen is available.');
        return;
    }
    hc_record('web.selftest', 'INFO', 'Self-requests to this host enabled (client: ' . $client . ').', $client);

    $tokenQ = 'token=' . rawurlencode(HOST_CHECK_TOKEN);
    $scriptUrl = $base['script'];
    $dirUrl = $base['dir'];

    // .htaccess probes
    if ($probe !== null) {
        $rel = rawurlencode(basename($sideDir));
        $control = hc_http_get($dirUrl . '/' . $rel . '/control/probe.txt', array());
        $controlOk = $control['status'] === 200 && strpos($control['body'], $probe['control']) !== false;
        if (!$controlOk) {
            hc_record('web.htaccess_deny', 'WARN', 'Inconclusive: could not fetch an unprotected control file (' . hc_status_text($control) . ').');
            hc_record('web.htaccess_rewrite', 'WARN', 'Inconclusive: control request failed.');
        } else {
            $deny = hc_http_get($dirUrl . '/' . $rel . '/deny/probe.txt', array());
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

            $rw = hc_http_get($dirUrl . '/' . $rel . '/rewrite/rewritten.txt', array());
            if ($rw['status'] === 200 && strpos($rw['body'], $probe['rewrite']) !== false) {
                hc_record('web.htaccess_rewrite', 'PASS', 'mod_rewrite rules in .htaccess work.', true);
            } else {
                $why = $rw['status'] === 500 ? 'rewrite directives rejected (AllowOverride FileInfo or Options FollowSymLinks missing?)' : 'rewrite rule not applied';
                hc_record('web.htaccess_rewrite', $htaccessApplies ? 'FAIL' : 'WARN', 'mod_rewrite test failed: ' . $why . ' (' . hc_status_text($rw) . ').', false);
            }
        }
    }

    // Authorization header
    $hdr = array('Authorization: ' . HC_DUMMY_AUTH);
    $direct = hc_http_get($scriptUrl . '?' . $tokenQ . '&probe=auth', $hdr);
    $viaRewrite = null;
    if ($probe !== null) {
        $viaRewrite = hc_http_get($dirUrl . '/' . rawurlencode(basename($sideDir)) . '/rewrite/auth.php?' . $tokenQ, $hdr);
    }
    $directOk = $direct['status'] === 200 && trim($direct['body']) === 'auth=present';
    $rewriteOk = $viaRewrite !== null && $viaRewrite['status'] === 200 && trim($viaRewrite['body']) === 'auth=present';
    if ($directOk) {
        hc_record('web.authorization_header', 'PASS', 'Authorization header reaches PHP without extra configuration (value not shown).', 'direct');
    } elseif ($rewriteOk) {
        hc_record('web.authorization_header', 'PASS', 'Authorization header reaches PHP only with a RewriteRule E=HTTP_AUTHORIZATION line in .htaccess.', 'via-rewrite');
    } elseif ($direct['status'] === 200) {
        hc_record('web.authorization_header', 'WARN', 'Authorization header does not reach PHP'
            . ($viaRewrite !== null ? ', even with the RewriteRule workaround (' . hc_status_text($viaRewrite) . ')' : '')
            . '; the post-v1 MCP server would not work.', 'absent');
    } else {
        hc_record('web.authorization_header', 'WARN', 'Inconclusive: self-request failed (' . hc_status_text($direct) . ').', 'unknown');
    }
}

function hc_status_text($res)
{
    if ($res['status'] === 0) {
        return 'no response' . ($res['error'] !== '' ? ': ' . $res['error'] : '');
    }
    return 'HTTP ' . $res['status'];
}

// Build self URLs from the request's own Host header and script path only.
function hc_self_base($https)
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        return '';
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    if (!preg_match('#^/[A-Za-z0-9._~/-]*$#', $script) || strpos($script, '..') !== false) {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $origin = ($https ? 'https' : 'http') . '://' . $host;
    return array('script' => $origin . $script, 'dir' => $origin . $dir);
}

function hc_write_probe_files($sideDir)
{
    $markers = array(
        'control' => 'control-' . substr(hc_unique_name(), -12),
        'deny'    => 'deny-' . substr(hc_unique_name(), -12),
        'rewrite' => 'rewrite-' . substr(hc_unique_name(), -12),
    );
    $deny = "# TiffinDB host check probe (temporary)\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $rewrite = "# TiffinDB host check probe (temporary)\n"
        . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
        . "    RewriteRule ^rewritten\\.txt$ target.txt [L]\n"
        . "    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\n";
    // Prints only whether the header is present, and only to a caller with the token.
    $authProbe = "<?php\n"
        . "header('Content-Type: text/plain');\n"
        . "\$t = isset(\$_GET['token']) && is_string(\$_GET['token']) ? \$_GET['token'] : '';\n"
        . "if (!hash_equals('" . hash('sha256', HOST_CHECK_TOKEN) . "', hash('sha256', \$t))) { http_response_code(403); exit; }\n"
        . "\$p = !empty(\$_SERVER['HTTP_AUTHORIZATION']) || !empty(\$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);\n"
        . "echo \$p ? 'auth=present' : 'auth=absent';\n";

    $files = array(
        'control/probe.txt'  => $markers['control'] . "\n",
        'deny/.htaccess'     => $deny,
        'deny/probe.txt'     => $markers['deny'] . "\n",
        'rewrite/.htaccess'  => $rewrite,
        'rewrite/target.txt' => $markers['rewrite'] . "\n",
        'rewrite/auth.php'   => $authProbe,
    );
    foreach (array('control', 'deny', 'rewrite') as $sub) {
        if (!@mkdir($sideDir . '/' . $sub, 0755)) {
            return null;
        }
    }
    foreach ($files as $rel => $content) {
        if (@file_put_contents($sideDir . '/' . $rel, $content) === false) {
            return null;
        }
        @chmod($sideDir . '/' . $rel, 0644);
    }
    return $markers;
}

function hc_http_get($url, $headers)
{
    $res = array('status' => 0, 'body' => '', 'error' => '');
    $headers[] = 'User-Agent: TiffinDB-host-check';
    if (function_exists('curl_init') && function_exists('curl_exec')) {
        $ch = curl_init();
        $opts = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROXY          => '', // never route self-requests through a proxy
        );
        if (defined('CURLOPT_NOPROXY')) {
            $opts[CURLOPT_NOPROXY] = '*';
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $res['error'] = hc_clean('curl: ' . curl_error($ch));
        } else {
            $res['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $res['body'] = substr((string) $body, 0, 65536);
        }
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        $ch = null;
        return $res;
    }
    if (!ini_get('allow_url_fopen')) {
        $res['error'] = 'no HTTP client available';
        return $res;
    }
    $ctx = stream_context_create(array(
        'http' => array(
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'ignore_errors'   => true,
            'follow_location' => 0,
            'timeout'         => 10,
        ),
    ));
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        $res['error'] = hc_last_error();
        return $res;
    }
    $meta = stream_get_meta_data($fp);
    $res['body'] = (string) stream_get_contents($fp, 65536);
    fclose($fp);
    $lines = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
    foreach ($lines as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $line, $m)) {
            $res['status'] = (int) $m[1];
            break;
        }
    }
    return $res;
}
