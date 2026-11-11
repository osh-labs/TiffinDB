<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * discover-structure.php - structure-only inspection of a file, directory or
 * zip archive that may contain SQLite databases.
 *
 * The tool is generic: it knows nothing about any particular product. It
 * reports the container layout and the schema, row counts and per-column
 * storage statistics of every SQLite database it finds. It never prints row
 * values. See README.md in this directory for the rules and the procedure.
 */

declare(strict_types=1);

const TOOL_NAME = 'discover-structure.php';
const TOOL_VERSION = '1.0.0';
const REPORT_FORMAT = 1;

const EXIT_OK = 0;
const EXIT_PROBLEMS = 1;
const EXIT_USAGE = 2;
const EXIT_IO = 3;
const EXIT_ENVIRONMENT = 4;

const HEAD_BYTES = 4096;
const KEY_HEAD_BYTES = 65536;
const MAX_ZIP_DEPTH = 2;
const MAX_EXTRACT_BYTES = 1073741824;
const STATS_CHUNK = 100;
const SHAPE_MAX_CHARS = 40;
const SHAPE_MAX_LISTED = 20;
const SHAPE_MAX_TRACKED = 10000;

/**
 * Words kept verbatim by --redact-identifiers=all: SQL keywords, common
 * declared type names, collations and built-in function and module names.
 */
const KEEP_WORDS = 'abort action add after all alter always analyze and as asc attach autoincrement before begin
 between by cascade case cast check collate column commit conflict constraint create cross current current_date
 current_time current_timestamp database default deferrable deferred delete desc detach distinct do drop each else
 end escape except exclude exclusive exists explain fail filter first following for foreign from full generated glob
 group groups having if ignore immediate in index indexed initially inner insert instead intersect into is isnull
 join key last left like limit match materialized natural no not nothing notnull null nulls of offset on or order
 others outer over partition plan pragma preceding primary query raise range recursive references regexp reindex
 release rename replace restrict returning right rollback row rows savepoint select set table temp temporary then
 ties to transaction trigger unbounded union unique update using vacuum values view virtual when where window with
 without rowid strict true false new old stored
 int integer tinyint smallint mediumint bigint unsigned big int2 int8 character varchar varying nchar native
 nvarchar text clob blob real double precision float numeric decimal boolean bool date datetime timestamp time any
 char string number varbinary binary nocase rtrim
 abs changes coalesce count avg sum total min max group_concat hex ifnull iif instr length lower upper ltrim trim
 nullif printf format quote random randomblob round substr substring typeof unicode zeroblob julianday strftime
 unixepoch json json_extract last_insert_rowid likelihood likely unlikely
 fts3 fts4 fts5 rtree tokenize content x';

/**
 * File and directory name stems that are generic container vocabulary and are
 * shown even when names are redacted. Deliberately small and product-neutral.
 */
const STRUCTURAL_NAMES = 'contents resources info pkginfo .ds_store data database db attachments attachment media
 images image thumbnails thumbnail previews preview files documents templates template library libraries cache
 caches tmp temp backup backups metadata manifest index settings preferences config version icon icons';

final class UsageError extends RuntimeException
{
}

final class Options
{
    public string $input = '';
    public ?string $out = null;
    public bool $json = false;
    public bool $showNames = false;
    /** 'none', 'flagged' or 'all' */
    public string $redactIdentifiers = 'none';
    public bool $applyWal = false;
    /** @var list<string> */
    public array $valueShapes = [];
}

/**
 * Replaces personal-looking file name components with stable placeholders.
 */
final class NameRedactor
{
    /** @var array<string,string> */
    private array $map = [];
    /** @var array<string,int> */
    private array $counters = [];
    /** @var array<string,bool> */
    private array $structural;

    public function __construct(private bool $showNames)
    {
        $this->structural = array_fill_keys(word_list(STRUCTURAL_NAMES), true);
    }

    public function path(string $relative): string
    {
        if ($relative === '') {
            return '';
        }
        $trailing = str_ends_with($relative, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', rtrim($relative, '/')) as $part) {
            $parts[] = $this->component($part);
        }
        return implode('/', $parts) . $trailing;
    }

    public function component(string $name): string
    {
        if ($this->showNames) {
            return printable($name);
        }
        [$stem, $ext] = $this->split($name);
        return $this->stem($stem) . $ext;
    }

    /** @return array{0:string,1:string} stem and extension (with its dot, or '') */
    public function split(string $name): array
    {
        if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,12}(?:-(?:wal|shm|journal))?)$/s', $name, $m) === 1
            && preg_match('/[A-Za-z]/', $m[2]) === 1) {
            return [$m[1], $m[2]];
        }
        return [$name, ''];
    }

    private function stem(string $stem): string
    {
        if (isset($this->structural[strtolower($stem)])) {
            return $stem;
        }
        if (preg_match('/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$/i', $stem) === 1) {
            $class = 'uuid';
        } elseif (preg_match('/^\d+$/', $stem) === 1) {
            $class = 'num';
        } elseif (preg_match('/^[0-9a-f]{8,}$/i', $stem) === 1) {
            $class = 'hex';
        } else {
            $class = 'name';
        }
        $key = $class . "\0" . $stem;
        if (!isset($this->map[$key])) {
            $n = ($this->counters[$class] ?? 0) + 1;
            $this->counters[$class] = $n;
            $this->map[$key] = '<' . $class . '-' . $n . '>';
        }
        return $this->map[$key];
    }
}

/**
 * Flags, and optionally hashes, SQL identifiers; masks literals in DDL.
 */
final class IdentifierRedactor
{
    /** @var array<string,bool> */
    private array $keep;
    /** @var array<string,list<string>> display name => reasons */
    private array $flagged = [];

    public function __construct(private string $mode, private string $key)
    {
        $this->keep = array_fill_keys(word_list(KEEP_WORDS), true);
    }

    /** @return array<string,list<string>> */
    public function takeFlagged(): array
    {
        $flagged = $this->flagged;
        ksort($flagged, SORT_STRING);
        $this->flagged = [];
        return $flagged;
    }

    /** @return list<string> */
    public static function flags(string $name): array
    {
        if (stripos($name, 'sqlite_') === 0) {
            return [];
        }
        $reasons = [];
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name) !== 1) {
            $reasons[] = 'not-a-plain-identifier';
        }
        if (preg_match('/\d{2,}/', $name) === 1
            && preg_match('/^(?:u?int|float|real|varchar|char|decimal|numeric|utf|fts)\d+$/i', $name) !== 1) {
            $reasons[] = 'digit-run';
        }
        return $reasons;
    }

    /** Identifier as it should appear in the report. */
    public function ident(string $name): string
    {
        if (preg_match('/^(sqlite_autoindex_)(.+)(_\d+)$/is', $name, $m) === 1) {
            return $m[1] . $this->ident($m[2]) . $m[3];
        }
        $reasons = self::flags($name);
        $hide = false;
        if ($this->mode === 'flagged') {
            $hide = $reasons !== [];
        } elseif ($this->mode === 'all') {
            $hide = !isset($this->keep[strtolower($name)]) && stripos($name, 'sqlite_') !== 0;
        }
        $shown = $hide
            ? 'id_' . substr(hash_hmac('sha256', strtolower($name), $this->key), 0, 10)
            : printable($name);
        if ($reasons !== []) {
            $this->flagged[$shown] = $reasons;
        }
        return $shown;
    }

    /** Declared column type: shown as is when made of known type words only. */
    public function type(string $type): string
    {
        foreach (preg_split('/[^A-Za-z_\x80-\xff]+/', $type) ?: [] as $word) {
            if ($word !== '' && !isset($this->keep[strtolower($word)])) {
                return $this->ident($type);
            }
        }
        return printable($type);
    }

    /**
     * Returns DDL with string literals and comments
     * masked and identifiers passed through ident().
     */
    public function sql(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        $previousWord = '';
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '-' && substr($sql, $i, 2) === '--') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $out .= '-- <comment>';
                $i = $end;
                continue;
            }
            if ($c === '/' && substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= '/* <comment> */';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
                $close = $c === '[' ? ']' : $c;
                $j = $i + 1;
                $body = '';
                while ($j < $len) {
                    if ($sql[$j] === $close) {
                        if ($close !== ']' && $j + 1 < $len && $sql[$j + 1] === $close) {
                            $body .= $close;
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $body .= $sql[$j];
                    $j++;
                }
                $i = $j + 1;
                if ($c === "'" || $previousWord === 'default') {
                    $out .= $c . '<text:' . strlen($body) . '>' . $close;
                } else {
                    $out .= $c . $this->ident($body) . $close;
                }
                $previousWord = '';
                continue;
            }
            if (preg_match('/\G[0-9][0-9A-Za-z_.]*/', $sql, $m, 0, $i) === 1) {
                $out .= $m[0];
                $i += strlen($m[0]);
                $previousWord = '';
                continue;
            }
            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $sql, $m, 0, $i) === 1) {
                $word = $m[0];
                $i += strlen($word);
                $next = $sql[$i] ?? '';
                if (strtolower($word) === 'x' && $next === "'") {
                    $out .= $word;
                } else {
                    $out .= isset($this->keep[strtolower($word)]) ? $word : $this->ident($word);
                }
                $previousWord = strtolower($word);
                continue;
            }
            if (!ctype_space($c)) {
                $previousWord = '';
            }
            $out .= $c;
            $i++;
        }
        return printable($out, true);
    }
}

/** Shared state for one run. */
final class Run
{
    /** @var list<array<string,mixed>> */
    public array $layout = [];
    /** @var list<array<string,mixed>> */
    public array $databases = [];
    /** @var list<string> */
    public array $warnings = [];
    public bool $problems = false;
    public ?string $tempDir = null;
    /** @var array<string,bool> lower-cased --value-shapes tables that matched */
    public array $shapeTablesSeen = [];

    public function __construct(
        public Options $options,
        public NameRedactor $names,
        public IdentifierRedactor $idents,
    ) {
    }

    public function problem(string $message): void
    {
        $this->problems = true;
        $this->warnings[] = $message;
    }
}

/** @return list<string> */
function word_list(string $words): array
{
    $list = preg_split('/\s+/', strtolower(trim($words)));
    return $list === false ? [] : $list;
}

/** Makes a string safe to print on one line (or several, for DDL). */
function printable(string $text, bool $keepNewlines = false): string
{
    if (preg_match('//u', $text) !== 1) {
        $text = (string) preg_replace('/[\x80-\xff]/', '?', $text);
    }
    $pattern = $keepNewlines ? '/[\x00-\x08\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
    return (string) preg_replace($pattern, '?', $text);
}

function usage(): string
{
    return <<<TXT
Usage: php discover-structure.php [options] <path>

  <path>                    Directory (package), SQLite database file, or zip archive.

Options:
  --out <file>              Write the report to <file> instead of stdout. The file
                            must not exist and must not be inside or beside the input.
  --json                    Emit JSON instead of plain text.
  --show-names              Show real file and directory names (default: redacted).
  --redact-identifiers      Replace flagged SQL identifiers with keyed hashes.
  --redact-identifiers=all  Replace every SQL identifier with a keyed hash.
  --apply-wal               If a database has a write-ahead log beside it, analyse a
                            temporary private copy with the log applied.
  --value-shapes <table>    OPT-IN: also print shape-masked patterns of TEXT values
                            for the named table only. May be repeated. Review the
                            result line by line before sharing it.
  --help, --version

Exit codes: 0 ok; 1 finished but problems were reported; 2 usage error;
3 input or output could not be read or written; 4 pdo_sqlite is missing.

TXT;
}

/** @param list<string> $argv */
function parse_arguments(array $argv): Options
{
    $o = new Options();
    $positional = [];
    $args = array_slice($argv, 1);
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        $value = null;
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$arg, $value] = explode('=', $arg, 2);
        }
        $needValue = static function () use (&$value, &$i, $args, $arg): string {
            if ($value === null) {
                $i++;
                if (!isset($args[$i])) {
                    throw new UsageError("Option $arg needs a value.");
                }
                $value = $args[$i];
            }
            if ($value === '') {
                throw new UsageError("Option $arg needs a non-empty value.");
            }
            return $value;
        };
        switch ($arg) {
            case '--help':
            case '-h':
                fwrite(STDOUT, usage());
                exit(EXIT_OK);
            case '--version':
                fwrite(STDOUT, TOOL_NAME . ' ' . TOOL_VERSION . "\n");
                exit(EXIT_OK);
            case '--json':
                $o->json = true;
                break;
            case '--show-names':
                $o->showNames = true;
                break;
            case '--apply-wal':
                $o->applyWal = true;
                break;
            case '--redact-identifiers':
                if ($value !== null && $value !== 'all' && $value !== 'flagged') {
                    throw new UsageError('--redact-identifiers accepts only "=all" or "=flagged".');
                }
                $o->redactIdentifiers = $value ?? 'flagged';
                break;
            case '--out':
                $o->out = $needValue();
                break;
            case '--value-shapes':
                $o->valueShapes[] = $needValue();
                break;
            case '--':
                array_push($positional, ...array_slice($args, $i + 1));
                $i = count($args);
                break;
            default:
                if (str_starts_with($arg, '-') && $arg !== '-') {
                    throw new UsageError("Unknown option: $arg");
                }
                $positional[] = $value === null ? $arg : $arg . '=' . $value;
        }
    }
    if (count($positional) !== 1) {
        throw new UsageError('Exactly one input path is required.');
    }
    $o->input = $positional[0];
    $o->valueShapes = array_values(array_unique($o->valueShapes));
    sort($o->valueShapes, SORT_STRING);
    return $o;
}

function read_head(string $path, int $bytes): string
{
    $h = @fopen($path, 'rb');
    if ($h === false) {
        return '';
    }
    $data = fread($h, $bytes);
    fclose($h);
    return $data === false ? '' : $data;
}

/** Detects the kind of a file from its first bytes only. */
function detect_kind(string $head, int $size): string
{
    if ($size === 0) {
        return 'empty';
    }
    if (str_starts_with($head, "SQLite format 3\0")) {
        return 'sqlite';
    }
    if (preg_match('/^PK(?:\x03\x04|\x05\x06|\x07\x08)/', $head) === 1) {
        return 'zip';
    }
    if (str_starts_with($head, 'bplist')) {
        return 'plist-binary';
    }
    $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);
    if (str_starts_with($text, '<?xml') || str_starts_with($text, '<!DOCTYPE') || str_starts_with($text, '<plist')) {
        return preg_match('/<!DOCTYPE\s+plist|<plist[\s>]/', $text) === 1 ? 'plist-xml' : 'xml';
    }
    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        return 'image-png';
    }
    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return 'image-jpeg';
    }
    if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
        return 'image-gif';
    }
    if (str_starts_with($head, "II*\0") || str_starts_with($head, "MM\0*")) {
        return 'image-tiff';
    }
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
        return 'image-webp';
    }
    if (substr($head, 4, 4) === 'ftyp' && preg_match('/^(?:heic|heix|heif|mif1|avif)/', substr($head, 8, 4)) === 1) {
        return 'image-heif';
    }
    if (str_starts_with($head, 'BM') && $size >= 26 && unpack('V', substr($head . "\0\0\0\0", 2, 4))[1] === $size) {
        return 'image-bmp';
    }
    if (str_starts_with($head, '%PDF-')) {
        return 'pdf';
    }
    if (str_starts_with($head, "\x1f\x8b")) {
        return 'gzip';
    }
    if (str_starts_with($head, "\x37\x7f\x06\x82") || str_starts_with($head, "\x37\x7f\x06\x83")) {
        return 'sqlite-wal';
    }
    if (preg_match('/^[\x09\x0a\x0d\x20-\x7e\x80-\xff]*$/', $head) === 1
        && (preg_match('//u', $head) === 1 || preg_match('//u', substr($head, 0, -3)) === 1)) {
        return 'text';
    }
    return 'other';
}

/**
 * Lists a directory tree without following symbolic links.
 *
 * @return list<array{rel:string,abs:string,type:string,size:?int}>
 */
function walk(string $root): array
{
    $entries = [];
    $visit = static function (string $abs, string $rel) use (&$visit, &$entries): void {
        $names = @scandir($abs);
        if ($names === false) {
            $entries[] = ['rel' => $rel . '/', 'abs' => $abs, 'type' => 'unreadable-dir', 'size' => null];
            return;
        }
        $names = array_values(array_diff($names, ['.', '..']));
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $childAbs = $abs . DIRECTORY_SEPARATOR . $name;
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            if (is_link($childAbs)) {
                $entries[] = ['rel' => $childRel, 'abs' => $childAbs, 'type' => 'symlink', 'size' => null];
            } elseif (is_dir($childAbs)) {
                $entries[] = ['rel' => $childRel . '/', 'abs' => $childAbs, 'type' => 'dir', 'size' => null];
                $visit($childAbs, $childRel);
            } elseif (is_file($childAbs)) {
                $size = @filesize($childAbs);
                $entries[] = [
                    'rel' => $childRel,
                    'abs' => $childAbs,
                    'type' => 'file',
                    'size' => $size === false ? null : $size,
                ];
            } else {
                $entries[] = ['rel' => $childRel, 'abs' => $childAbs, 'type' => 'special', 'size' => null];
            }
        }
    };
    $visit($root, '');
    return $entries;
}

function temp_dir(Run $run): string
{
    if ($run->tempDir === null) {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tiffindb-discovery-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new RuntimeException('Cannot create a private temporary directory.');
        }
        $run->tempDir = $dir;
    }
    return $run->tempDir;
}

function remove_temp_dir(?string $dir): void
{
    if ($dir === null || !is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            @unlink($dir . DIRECTORY_SEPARATOR . $name);
        }
    }
    @rmdir($dir);
}

function temp_file(Run $run): string
{
    return temp_dir($run) . DIRECTORY_SEPARATOR . 'item-' . bin2hex(random_bytes(8));
}

/** Processes one regular file: records its kind and analyses it if possible. */
function process_file(Run $run, string $abs, string $shownPath, ?int $size, int $zipDepth, bool $onDisk): void
{
    $head = read_head($abs, HEAD_BYTES);
    $size ??= 0;
    $kind = detect_kind($head, $size);
    $row = ['path' => $shownPath, 'type' => 'file', 'size' => $size, 'kind' => $kind];
    if ($kind !== 'sqlite' && preg_match('/\.(?:sqlite3?|db3?|sqlitedb|database)$/i', $abs) === 1 && $onDisk) {
        $row['note'] = 'database-like extension but no SQLite header (encrypted or another format?)';
    }
    $run->layout[] = $row;
    if ($kind === 'sqlite') {
        $run->databases[] = analyse_database($run, $abs, $shownPath, $onDisk);
    } elseif ($kind === 'zip') {
        process_zip($run, $abs, $shownPath, $zipDepth);
    }
}

function process_zip(Run $run, string $abs, string $shownPath, int $depth): void
{
    $last = array_key_last($run->layout);
    if (!class_exists(ZipArchive::class)) {
        $run->layout[$last]['note'] = 'contents not listed: the PHP zip extension is not available';
        $run->warnings[] = "Zip archive $shownPath was not opened: the PHP zip extension is not available.";
        return;
    }
    if ($depth >= MAX_ZIP_DEPTH) {
        $run->layout[$last]['note'] = 'contents not listed: nesting limit reached';
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($abs, ZipArchive::RDONLY) !== true) {
        $run->layout[$last]['note'] = 'could not be opened as a zip archive';
        $run->problem("Zip archive $shownPath could not be opened.");
        return;
    }
    $items = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat !== false) {
            $items[] = ['index' => $i, 'name' => (string) $stat['name'], 'size' => (int) $stat['size']];
        }
    }
    usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']) ?: $a['index'] <=> $b['index']);
    foreach ($items as $item) {
        $inner = $shownPath . '!/' . $run->names->path($item['name']);
        if (str_ends_with($item['name'], '/')) {
            $run->layout[] = ['path' => $inner, 'type' => 'dir', 'size' => null, 'kind' => 'directory'];
            continue;
        }
        $stream = @$zip->getStream($zip->getNameIndex($item['index']) ?: $item['name']);
        if ($stream === false) {
            $run->layout[] = [
                'path' => $inner, 'type' => 'file', 'size' => $item['size'], 'kind' => 'unreadable',
                'note' => 'entry could not be read (encrypted or damaged)',
            ];
            continue;
        }
        $head = (string) stream_get_contents($stream, HEAD_BYTES);
        $kind = detect_kind($head, $item['size']);
        if ($kind !== 'sqlite' && $kind !== 'zip') {
            fclose($stream);
            $run->layout[] = ['path' => $inner, 'type' => 'file', 'size' => $item['size'], 'kind' => $kind];
            continue;
        }
        if ($item['size'] > MAX_EXTRACT_BYTES) {
            fclose($stream);
            $run->layout[] = [
                'path' => $inner, 'type' => 'file', 'size' => $item['size'], 'kind' => $kind,
                'note' => 'not analysed: larger than the extraction limit',
            ];
            $run->warnings[] = "Entry $inner was not analysed: larger than the extraction limit.";
            continue;
        }
        $tmp = temp_file($run);
        $target = fopen($tmp, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Cannot write to the private temporary directory.');
        }
        fwrite($target, $head);
        stream_copy_to_stream($stream, $target, MAX_EXTRACT_BYTES);
        fclose($target);
        fclose($stream);
        try {
            process_file($run, $tmp, $inner, $item['size'], $depth + 1, false);
        } finally {
            @unlink($tmp);
        }
    }
    $zip->close();
}

/** @return array<string,mixed> */
function parse_sqlite_header(string $head): array
{
    if (strlen($head) < 100) {
        return ['truncated' => true];
    }
    $u16 = static fn (int $o): int => unpack('n', substr($head, $o, 2))[1];
    $u32 = static fn (int $o): int => unpack('N', substr($head, $o, 4))[1];
    $pageSize = $u16(16);
    $userVersion = $u32(60);
    if ($userVersion >= 0x80000000) {
        $userVersion -= 0x100000000;
    }
    $encodings = [1 => 'UTF-8', 2 => 'UTF-16le', 3 => 'UTF-16be'];
    $applicationId = $u32(68);
    return [
        'page_size' => $pageSize === 1 ? 65536 : $pageSize,
        'page_count' => $u32(28),
        'encoding' => $encodings[$u32(56)] ?? 'unknown(' . $u32(56) . ')',
        'user_version' => $userVersion,
        'application_id' => $applicationId,
        'application_id_hex' => sprintf('0x%08X', $applicationId),
        'schema_format' => $u32(44),
        'journal_mode_hint' => ord($head[18]) === 2 || ord($head[19]) === 2 ? 'wal' : 'rollback',
        'written_by_sqlite_version_number' => $u32(96),
    ];
}

function quote_identifier(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function sqlite_uri(string $path, bool $immutable): string
{
    $real = realpath($path);
    $real = $real === false ? $path : $real;
    $encoded = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $real))));
    if (!str_starts_with($encoded, '/')) {
        $encoded = '/' . $encoded;
    }
    return 'sqlite:file:' . $encoded . '?mode=ro' . ($immutable ? '&immutable=1' : '');
}

/** Describes a failure without echoing SQLite's message, which can contain names. */
function describe_error(Throwable $e, Run $run): string
{
    $names = [1 => 'SQLITE_ERROR', 10 => 'SQLITE_IOERR', 11 => 'SQLITE_CORRUPT', 14 => 'SQLITE_CANTOPEN',
        23 => 'SQLITE_AUTH', 26 => 'SQLITE_NOTADB'];
    $code = $e instanceof PDOException && isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    $text = $code > 0 ? 'SQLite error ' . $code . (isset($names[$code]) ? ' (' . $names[$code] . ')' : '') : 'error';
    if ($run->options->showNames) {
        $text .= ': ' . printable($e->getMessage());
    }
    return $text;
}

/** @return array<string,mixed> */
function analyse_database(Run $run, string $abs, string $shownPath, bool $onDisk): array
{
    $db = ['path' => $shownPath, 'header' => parse_sqlite_header(read_head($abs, 100))];
    $sidecars = [];
    $walBytes = 0;
    if ($onDisk) {
        foreach (['wal', 'shm', 'journal'] as $suffix) {
            if (is_file($abs . '-' . $suffix)) {
                $sidecars[$suffix . '_bytes'] = (int) filesize($abs . '-' . $suffix);
            }
        }
        $walBytes = $sidecars['wal_bytes'] ?? 0;
    }
    $db['sidecars'] = $sidecars;
    $openPath = $abs;
    $immutable = true;
    $copies = [];
    try {
        if ($walBytes > 0 && $run->options->applyWal) {
            $openPath = temp_file($run);
            $copies = [$openPath, $openPath . '-wal', $openPath . '-shm'];
            if (!@copy($abs, $openPath) || !@copy($abs . '-wal', $openPath . '-wal')) {
                throw new RuntimeException('Cannot copy the database to the private temporary directory.');
            }
            $immutable = false;
            $db['wal'] = 'applied on a temporary private copy';
        } elseif ($walBytes > 0) {
            $db['wal'] = 'present but NOT applied; recent changes may be missing (see --apply-wal)';
            $run->warnings[] = "Database $shownPath has a write-ahead log that was not applied.";
        }
        $pdo = new PDO(sqlite_uri($openPath, $immutable), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        $db += read_schema($run, $pdo);
        $pdo = null;
    } catch (Throwable $e) {
        $db['error'] = describe_error($e, $run);
        $run->problem("Database $shownPath could not be read completely: " . $db['error'] . '.');
    } finally {
        foreach ($copies as $copy) {
            @unlink($copy);
        }
    }
    $db['flagged_identifiers'] = $run->idents->takeFlagged();
    return $db;
}

/** @return array<string,mixed> */
function read_schema(Run $run, PDO $pdo): array
{
    $objects = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
    usort($objects, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
    $idents = $run->idents;
    $out = ['tables' => [], 'views' => [], 'indexes' => [], 'triggers' => []];
    $wanted = array_map('strtolower', $run->options->valueShapes);
    foreach ($objects as $object) {
        $name = (string) $object['name'];
        $entry = [
            'name' => $idents->ident($name),
            'sql' => $object['sql'] === null ? null : $idents->sql((string) $object['sql']),
        ];
        switch ($object['type']) {
            case 'table':
                $entry['virtual'] = preg_match('/^\s*CREATE\s+VIRTUAL\s/i', (string) $object['sql']) === 1;
                $entry += read_table($run, $pdo, $name);
                if (in_array(strtolower($name), $wanted, true)) {
                    $run->shapeTablesSeen[strtolower($name)] = true;
                    $entry['value_shapes'] = read_value_shapes($run, $pdo, $name);
                }
                $out['tables'][] = $entry;
                break;
            case 'index':
                $entry['table'] = $idents->ident((string) $object['tbl_name']);
                try {
                    $columns = $pdo->query('PRAGMA index_info(' . quote_identifier($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
                    $entry['columns'] = array_map(
                        static fn (array $c): string => $c['name'] === null ? '<expression>' : $idents->ident((string) $c['name']),
                        $columns
                    );
                } catch (Throwable $e) {
                    $entry['error'] = describe_error($e, $run);
                    $run->problems = true;
                }
                $out['indexes'][] = $entry;
                break;
            case 'view':
                $out['views'][] = $entry;
                break;
            case 'trigger':
                $entry['table'] = $idents->ident((string) $object['tbl_name']);
                $out['triggers'][] = $entry;
                break;
        }
    }
    return $out;
}

/** Describes a column default without disclosing a non-numeric literal. */
function describe_default(mixed $default): string
{
    if ($default === null) {
        return 'none';
    }
    $text = trim((string) $default);
    if (preg_match('/^[+-]?(?:\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+(?:[eE][+-]?\d+)?|0[xX][0-9a-fA-F]+)$/', $text) === 1) {
        return $text;
    }
    if (preg_match('/^(?:NULL|TRUE|FALSE|CURRENT_TIME|CURRENT_DATE|CURRENT_TIMESTAMP)$/i', $text) === 1) {
        return strtoupper($text);
    }
    return 'present (non-numeric, hidden)';
}

/** @return array<string,mixed> */
function read_table(Run $run, PDO $pdo, string $table): array
{
    $idents = $run->idents;
    $qt = quote_identifier($table);
    $out = ['row_count' => null, 'columns' => [], 'foreign_keys' => []];
    $realColumns = [];
    try {
        foreach ($pdo->query("PRAGMA table_info($qt)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $realColumns[] = (string) $c['name'];
            $out['columns'][] = [
                'name' => $idents->ident((string) $c['name']),
                'declared_type' => $idents->type((string) $c['type']),
                'notnull' => (int) $c['notnull'] !== 0,
                'pk' => (int) $c['pk'],
                'default' => describe_default($c['dflt_value']),
            ];
        }
        foreach ($pdo->query("PRAGMA foreign_key_list($qt)")->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            $out['foreign_keys'][] = [
                'id' => (int) $fk['id'],
                'seq' => (int) $fk['seq'],
                'from' => $idents->ident((string) $fk['from']),
                'to_table' => $idents->ident((string) $fk['table']),
                'to_column' => $fk['to'] === null ? null : $idents->ident((string) $fk['to']),
                'on_update' => (string) $fk['on_update'],
                'on_delete' => (string) $fk['on_delete'],
            ];
        }
        usort($out['foreign_keys'], static fn (array $a, array $b): int => [$a['id'], $a['seq']] <=> [$b['id'], $b['seq']]);
        $out['row_count'] = (int) $pdo->query("SELECT COUNT(*) FROM $qt")->fetchColumn();
        foreach (array_chunk(array_keys($realColumns), STATS_CHUNK) as $chunk) {
            try {
                $stats = column_statistics($pdo, $qt, array_map(static fn (int $i): string => $realColumns[$i], $chunk));
            } catch (Throwable) {
                $stats = [];
                foreach ($chunk as $i) {
                    try {
                        $stats[] = column_statistics($pdo, $qt, [$realColumns[$i]])[0];
                    } catch (Throwable $e) {
                        $stats[] = ['error' => describe_error($e, $run)];
                        $run->problems = true;
                    }
                }
            }
            foreach ($chunk as $n => $i) {
                $out['columns'][$i]['statistics'] = $stats[$n];
            }
        }
    } catch (Throwable $e) {
        $out['error'] = describe_error($e, $run);
        $run->problem('A table could not be read completely: ' . $out['error'] . '.');
    }
    return $out;
}

/**
 * Counts storage classes and byte lengths for a set of columns in one scan.
 *
 * @param list<string> $columns
 * @return list<array<string,mixed>>
 */
function column_statistics(PDO $pdo, string $quotedTable, array $columns): array
{
    $classes = ['null', 'integer', 'real', 'text', 'blob'];
    $expressions = [];
    foreach ($columns as $column) {
        $q = quote_identifier($column);
        foreach ($classes as $class) {
            $expressions[] = "SUM(typeof($q) = '$class')";
        }
        foreach (['text', 'blob'] as $class) {
            $length = "CASE WHEN typeof($q) = '$class' THEN length(CAST($q AS BLOB)) END";
            array_push($expressions, "MIN($length)", "MAX($length)", "SUM($length)");
        }
    }
    $row = $pdo->query('SELECT ' . implode(', ', $expressions) . " FROM $quotedTable")->fetch(PDO::FETCH_NUM);
    $result = [];
    foreach ($columns as $n => $_) {
        $v = array_map(static fn (mixed $x): int => (int) $x, array_slice($row, $n * 11, 11));
        $stat = [
            'null' => $v[0],
            'non_null' => $v[1] + $v[2] + $v[3] + $v[4],
            'storage_classes' => ['integer' => $v[1], 'real' => $v[2], 'text' => $v[3], 'blob' => $v[4]],
        ];
        if ($v[3] > 0) {
            $stat['text_bytes'] = ['min' => $v[5], 'max' => $v[6], 'avg' => round($v[7] / $v[3], 1)];
        }
        if ($v[4] > 0) {
            $stat['blob_bytes'] = ['min' => $v[8], 'max' => $v[9], 'avg' => round($v[10] / $v[4], 1)];
        }
        $result[] = $stat;
    }
    return $result;
}

/** Masks a text value: letters to a/A, digits to 9, ASCII punctuation kept. */
function shape_mask(string $value): string
{
    if (preg_match('//u', $value) !== 1) {
        $value = (string) preg_replace('/[\x80-\xff]/', '?', $value);
    }
    preg_match('/^.{0,' . SHAPE_MAX_CHARS . '}/us', $value, $m);
    $cut = $m[0] ?? '';
    $mask = (string) preg_replace_callback('/./us', static function (array $c): string {
        $ch = $c[0];
        if (preg_match('/^[\p{Lu}\p{Lt}]$/u', $ch) === 1) {
            return 'A';
        }
        if (preg_match('/^\p{L}$/u', $ch) === 1) {
            return 'a';
        }
        if (preg_match('/^\p{N}$/u', $ch) === 1) {
            return '9';
        }
        return preg_match('/^[\x20-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]$/', $ch) === 1 ? $ch : '?';
    }, $cut);
    return strlen($cut) < strlen($value) ? $mask . '...' : $mask;
}

/**
 * Opt-in: shape-masked patterns of TEXT values, per column, for one table.
 *
 * @return list<array<string,mixed>>
 */
function read_value_shapes(Run $run, PDO $pdo, string $table): array
{
    $qt = quote_identifier($table);
    $result = [];
    try {
        $columns = $pdo->query("PRAGMA table_info($qt)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $c) {
            $qc = quote_identifier((string) $c['name']);
            $shapes = [];
            $untracked = 0;
            $total = 0;
            $statement = $pdo->query("SELECT $qc FROM $qt WHERE typeof($qc) = 'text'");
            while (($value = $statement->fetchColumn()) !== false) {
                $total++;
                $shape = shape_mask((string) $value);
                if (isset($shapes[$shape])) {
                    $shapes[$shape]++;
                } elseif (count($shapes) < SHAPE_MAX_TRACKED) {
                    $shapes[$shape] = 1;
                } else {
                    $untracked++;
                }
            }
            if ($total === 0) {
                continue;
            }
            $list = [];
            foreach ($shapes as $shape => $count) {
                $list[] = ['shape' => (string) $shape, 'count' => $count];
            }
            usort($list, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['shape'], $b['shape']));
            $result[] = [
                'column' => $run->idents->ident((string) $c['name']),
                'text_values' => $total,
                'distinct_shapes' => count($shapes),
                'distinct_shapes_is_lower_bound' => $untracked > 0,
                'shapes' => array_slice($list, 0, SHAPE_MAX_LISTED),
            ];
        }
    } catch (Throwable $e) {
        $run->problem('Value shapes could not be read: ' . describe_error($e, $run) . '.');
    }
    return $result;
}

/**
 * @param array<string,mixed> $report
 */
function render_text(array $report): string
{
    $l = [];
    $l[] = '# TiffinDB structure discovery report';
    $l[] = '# tool: ' . $report['tool'] . ' ' . $report['version'] . ' (report format ' . $report['report_format'] . ')';
    foreach ($report['statements'] as $statement) {
        $l[] = '# ' . $statement;
    }
    $o = $report['options'];
    $l[] = '# options: file-names=' . $o['file_names'] . ' identifiers=' . $o['identifiers']
        . ' value-shapes=' . ($o['value_shapes'] === [] ? 'none' : implode(',', $o['value_shapes']))
        . ' apply-wal=' . ($o['apply_wal'] ? 'yes' : 'no');
    $l[] = '';
    $l[] = '== Container layout ==';
    $l[] = 'input kind: ' . $report['input_kind'];
    $l[] = sprintf('%-13s %12s  %s', 'kind', 'bytes', 'path');
    foreach ($report['layout'] as $row) {
        $l[] = rtrim(sprintf('%-13s %12s  %s', $row['kind'], $row['size'] === null ? '-' : (string) $row['size'], $row['path'])
            . (isset($row['note']) ? '   [' . $row['note'] . ']' : ''));
    }
    $l[] = '';
    $l[] = 'kind summary:';
    foreach ($report['kind_summary'] as $kind => $count) {
        $l[] = sprintf('  %-13s %d', $kind, $count);
    }
    foreach ($report['databases'] as $n => $db) {
        $l[] = '';
        $l[] = '== Database ' . ($n + 1) . ': ' . $db['path'] . ' ==';
        $header = [];
        foreach ($db['header'] as $key => $value) {
            $header[] = $key . '=' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        }
        $l[] = 'header: ' . implode(' ', $header);
        $sidecars = [];
        foreach ($db['sidecars'] as $key => $value) {
            $sidecars[] = $key . '=' . $value;
        }
        $l[] = 'sidecar files: ' . ($sidecars === [] ? 'none' : implode(' ', $sidecars));
        if (isset($db['wal'])) {
            $l[] = 'write-ahead log: ' . $db['wal'];
        }
        if (isset($db['error'])) {
            $l[] = 'ERROR: ' . $db['error'];
        }
        if (!isset($db['tables'])) {
            continue;
        }
        $l[] = sprintf(
            'objects: tables=%d indexes=%d views=%d triggers=%d',
            count($db['tables']),
            count($db['indexes']),
            count($db['views']),
            count($db['triggers'])
        );
        foreach ($db['tables'] as $t) {
            $l[] = '';
            $l[] = '-- table ' . $t['name'] . ($t['virtual'] ? ' (virtual)' : '')
                . ' rows: ' . ($t['row_count'] === null ? 'unknown' : $t['row_count']);
            if (isset($t['error'])) {
                $l[] = '   ERROR: ' . $t['error'];
            }
            $l[] = '   sql: ' . indent_sql($t['sql']);
            foreach ($t['columns'] as $i => $c) {
                $l[] = sprintf(
                    '   column %d: %s | type=%s | notnull=%s | pk=%d | default=%s',
                    $i + 1,
                    $c['name'],
                    $c['declared_type'] === '' ? '(none)' : $c['declared_type'],
                    $c['notnull'] ? 'yes' : 'no',
                    $c['pk'],
                    $c['default']
                );
                $s = $c['statistics'] ?? null;
                if ($s === null) {
                    continue;
                }
                if (isset($s['error'])) {
                    $l[] = '      ERROR: ' . $s['error'];
                    continue;
                }
                $line = sprintf(
                    '      null=%d non-null=%d | integer=%d real=%d text=%d blob=%d',
                    $s['null'],
                    $s['non_null'],
                    $s['storage_classes']['integer'],
                    $s['storage_classes']['real'],
                    $s['storage_classes']['text'],
                    $s['storage_classes']['blob']
                );
                foreach (['text_bytes', 'blob_bytes'] as $key) {
                    if (isset($s[$key])) {
                        $line .= sprintf(' | %s min=%d max=%d avg=%s', $key, $s[$key]['min'], $s[$key]['max'], $s[$key]['avg']);
                    }
                }
                $l[] = $line;
            }
            foreach ($t['foreign_keys'] as $fk) {
                $l[] = sprintf(
                    '   foreign key %d.%d: %s -> %s(%s) on update %s, on delete %s',
                    $fk['id'],
                    $fk['seq'],
                    $fk['from'],
                    $fk['to_table'],
                    $fk['to_column'] ?? '<primary key>',
                    $fk['on_update'],
                    $fk['on_delete']
                );
            }
            if (isset($t['value_shapes'])) {
                $l[] = '   VALUE SHAPES (opt-in; masked patterns of TEXT values; REVIEW LINE BY LINE):';
                if ($t['value_shapes'] === []) {
                    $l[] = '      no TEXT values in this table';
                }
                foreach ($t['value_shapes'] as $vs) {
                    $l[] = sprintf(
                        '      column %s: text values=%d, distinct shapes=%s%d, listed=%d',
                        $vs['column'],
                        $vs['text_values'],
                        $vs['distinct_shapes_is_lower_bound'] ? 'more than ' : '',
                        $vs['distinct_shapes'],
                        count($vs['shapes'])
                    );
                    foreach ($vs['shapes'] as $shape) {
                        $l[] = sprintf('         %8d x  [%s]', $shape['count'], $shape['shape']);
                    }
                }
            }
        }
        foreach (['indexes' => 'index', 'views' => 'view', 'triggers' => 'trigger'] as $key => $label) {
            foreach ($db[$key] as $object) {
                $l[] = '';
                $l[] = '-- ' . $label . ' ' . $object['name'] . (isset($object['table']) ? ' on ' . $object['table'] : '')
                    . (isset($object['columns']) ? ' (' . implode(', ', $object['columns']) . ')' : '');
                if (isset($object['error'])) {
                    $l[] = '   ERROR: ' . $object['error'];
                }
                $l[] = '   sql: ' . indent_sql($object['sql']);
            }
        }
        if ($db['flagged_identifiers'] !== []) {
            $l[] = '';
            $l[] = 'identifiers flagged as possibly generated or user-chosen (review before sharing):';
            foreach ($db['flagged_identifiers'] as $name => $reasons) {
                $l[] = '   ' . $name . '  [' . implode(', ', $reasons) . ']';
            }
        }
    }
    $l[] = '';
    $l[] = '== Notes ==';
    if ($report['warnings'] === []) {
        $l[] = 'none';
    }
    foreach ($report['warnings'] as $warning) {
        $l[] = '- ' . $warning;
    }
    return implode("\n", $l) . "\n";
}

function indent_sql(?string $sql): string
{
    return $sql === null ? '(none; created automatically)' : str_replace("\n", "\n        ", $sql);
}

function is_inside(string $path, string $directory): bool
{
    $directory = rtrim($directory, '/\\');
    return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
}

/** @param list<string> $argv */
function main(array $argv): int
{
    try {
        $options = parse_arguments($argv);
    } catch (UsageError $e) {
        fwrite(STDERR, $e->getMessage() . "\n\n" . usage());
        return EXIT_USAGE;
    }
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "The pdo_sqlite PHP extension is required. See the README for the sqlite3 shell fallback.\n");
        return EXIT_ENVIRONMENT;
    }
    $input = realpath($options->input);
    if ($input === false || !is_readable($input) || (!is_dir($input) && !is_file($input))) {
        fwrite(STDERR, "Input path does not exist or cannot be read.\n");
        return EXIT_IO;
    }
    $isDir = is_dir($input);
    $guarded = $isDir ? $input : dirname($input);
    if ($options->out !== null) {
        $outDir = realpath(dirname($options->out));
        if ($outDir === false || !is_dir($outDir)) {
            fwrite(STDERR, "The directory for --out does not exist.\n");
            return EXIT_IO;
        }
        if (is_inside($outDir, $guarded)) {
            fwrite(STDERR, "Refusing to write the report inside or beside the input. Choose another directory.\n");
            return EXIT_IO;
        }
        if (file_exists($outDir . DIRECTORY_SEPARATOR . basename($options->out))) {
            fwrite(STDERR, "The --out file already exists; refusing to overwrite it.\n");
            return EXIT_IO;
        }
    }
    $systemTemp = realpath(sys_get_temp_dir());
    if ($systemTemp !== false && is_inside($systemTemp, $guarded)) {
        fwrite(STDERR, "The system temporary directory is inside or beside the input; move the input elsewhere.\n");
        return EXIT_IO;
    }

    if ($isDir) {
        $entries = walk($input);
    } else {
        $size = filesize($input);
        $entries = [['rel' => basename($input), 'abs' => $input, 'type' => 'file', 'size' => $size === false ? null : $size]];
    }

    // Key for identifier hashes: depends on the input only, so the same input
    // always gives the same report, but hashes cannot be reversed by guessing.
    $keyContext = hash_init('sha256');
    foreach ($entries as $entry) {
        hash_update($keyContext, $entry['rel'] . "\0" . ($entry['size'] ?? -1) . "\0");
        if ($entry['type'] === 'file') {
            hash_update($keyContext, hash('sha256', read_head($entry['abs'], KEY_HEAD_BYTES), true));
        }
    }

    $run = new Run(
        $options,
        new NameRedactor($options->showNames),
        new IdentifierRedactor($options->redactIdentifiers, hash_final($keyContext, true))
    );
    register_shutdown_function(static function () use ($run): void {
        remove_temp_dir($run->tempDir);
    });

    try {
        foreach ($entries as $entry) {
            if ($isDir) {
                $shown = $run->names->path($entry['rel']);
            } elseif ($options->showNames) {
                $shown = printable($entry['rel']);
            } else {
                $shown = '<input>' . $run->names->split($entry['rel'])[1];
            }
            switch ($entry['type']) {
                case 'file':
                    process_file($run, $entry['abs'], $shown, $entry['size'], 0, true);
                    break;
                case 'dir':
                    $run->layout[] = ['path' => $shown, 'type' => 'dir', 'size' => null, 'kind' => 'directory'];
                    break;
                case 'symlink':
                    $run->layout[] = ['path' => $shown, 'type' => 'symlink', 'size' => null, 'kind' => 'symlink',
                        'note' => 'not followed'];
                    break;
                case 'unreadable-dir':
                    $run->layout[] = ['path' => $shown, 'type' => 'dir', 'size' => null, 'kind' => 'unreadable'];
                    $run->problem('A directory could not be listed.');
                    break;
                default:
                    $run->layout[] = ['path' => $shown, 'type' => 'special', 'size' => null, 'kind' => 'special'];
            }
        }
    } catch (Throwable $e) {
        remove_temp_dir($run->tempDir);
        fwrite(STDERR, 'Stopped: ' . printable($e->getMessage()) . "\n");
        return EXIT_IO;
    }
    remove_temp_dir($run->tempDir);

    foreach ($options->valueShapes as $table) {
        if (!isset($run->shapeTablesSeen[strtolower($table)])) {
            $run->problem('A table named with --value-shapes was not found in any database.');
        }
    }

    $summary = [];
    foreach ($run->layout as $row) {
        $summary[$row['kind']] = ($summary[$row['kind']] ?? 0) + 1;
    }
    ksort($summary, SORT_STRING);

    $statements = [
        'This report describes structure only. It contains no row values, no samples and no default literals.',
        'Review it before sharing: table, column and (if shown) file names can themselves be personal.',
    ];
    if ($options->valueShapes !== []) {
        $statements[] = 'WARNING: --value-shapes was used. This report ALSO contains shape-masked patterns of TEXT '
            . 'values for the named table(s). Review those lines one by one. Never commit this output unreviewed.';
    }
    $report = [
        'tool' => TOOL_NAME,
        'version' => TOOL_VERSION,
        'report_format' => REPORT_FORMAT,
        'statements' => $statements,
        'options' => [
            'file_names' => $options->showNames ? 'shown' : 'redacted',
            'identifiers' => ['none' => 'verbatim', 'flagged' => 'flagged-hashed', 'all' => 'all-hashed'][$options->redactIdentifiers],
            'value_shapes' => array_map(static fn (string $t): string => $run->idents->ident($t), $options->valueShapes),
            'apply_wal' => $options->applyWal,
        ],
        'input_kind' => $isDir ? 'directory' : ($run->layout[0]['kind'] ?? 'unknown'),
        'layout' => $run->layout,
        'kind_summary' => $summary,
        'databases' => $run->databases,
        'warnings' => $run->warnings,
    ];

    $text = $options->json
        ? json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
        : render_text($report);

    if ($options->out === null) {
        fwrite(STDOUT, $text);
    } elseif (@file_put_contents($options->out, $text, LOCK_EX) === false) {
        fwrite(STDERR, "The report could not be written to the --out file.\n");
        return EXIT_IO;
    }
    return $run->problems ? EXIT_PROBLEMS : EXIT_OK;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}
