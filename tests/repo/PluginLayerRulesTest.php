<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Plugin\Manifest;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-PLG-8 (M3 spec section 2.1, ADR 0015 decisions 3 and 5, P131, P143).
 *
 * Files: under plugins/ there are only `plugins/<kind>/<id>/plugin.json`,
 * PHP class files `plugins/<kind>/<id>/src/**\/<Class>.php`, and plain
 * documentation (README*, LICENSE*, LICENCE*, NOTICE*, COPYING*, `*.md`,
 * `*.txt`); any other file (`.inc`, `.phtml`, `.phar`, `.php5`, no
 * extension, hidden files) fails, so no PHP can hide in a file the rules
 * below do not read.
 *
 * Names: every PHP file declares the plugin's namespace
 * (`TiffinDB\Plugin\<Kind>\<Id>`, or one below it) and names only
 * `TiffinDB\Core\Io\Contract\`, its own namespace, `OpenSpout\`, exactly the
 * classes of CORE_CSV_CLASSES (P143, a class list, not a prefix), and
 * global PHP names that are not on the fixed DENIED_CLASSES list (fixed, so
 * the result does not depend on which extensions are installed). Names are
 * compared ignoring case, as PHP does, in code (after `use` imports, with
 * `use function` and `use const` aliases resolved) and in string literals.
 * Group uses are refused.
 *
 * Calls: no network function (no literal URL of a network stream wrapper,
 * anywhere in a literal, also behind `compress.zlib://` or `php://filter`),
 * no database (no database class or function, no SQL statement in the
 * literals of one statement taken together), no code execution (eval,
 * include and require, backticks, the EXEC functions, FFI, Reflection, a
 * call of a variable or expression, `new $class`, a member called by a
 * computed name, variable variables), no request state (superglobals,
 * filter_input, getenv, getallheaders, session functions), and no headers.
 * A denied function name is refused as a call, as an import (with or
 * without an alias), and as a string literal (a callable string).
 *
 * Read with token_get_all. Known limits: names and SQL built at run time
 * from pieces that are not literals are not seen; the design-tier review of
 * each plugin reads its code. Calling a closure held in a variable is
 * written `$f->__invoke(...)`, which a string cannot do.
 *
 * While no plugin has PHP files (M3-WP2), the repository rule passes
 * trivially and the data set name says so; the negative cases always run.
 */
#[CoversNothing]
final class PluginLayerRulesTest extends TestCase
{
    public const PLUGINS = 'plugins';
    public const CONTRACT = 'TiffinDB\\Core\\Io\\Contract\\';
    public const LIBRARY = 'OpenSpout\\';
    /** The core CSV classes plugins may name (P143): exactly these, nothing else under Core\Io\Csv. */
    public const CORE_CSV_CLASSES = [
        'TiffinDB\\Core\\Io\\Csv\\Charset', 'TiffinDB\\Core\\Io\\Csv\\CsvDetector', 'TiffinDB\\Core\\Io\\Csv\\CsvReader',
        'TiffinDB\\Core\\Io\\Csv\\CsvWriter', 'TiffinDB\\Core\\Io\\Csv\\CsvDialect',
    ];

    /** Global classes plugins may not name (lower case; an entry ending in `*` is a prefix). */
    public const DENIED_CLASSES = [
        'pdo*', 'sqlite3*', 'mysqli*', 'soap*', 'curl*', 'ffi', 'reflection*', 'phar', 'phardata', 'pharfileinfo',
        'com', 'dotnet', 'variant', 'composer*', 'sessionhandler*', 'php_user_filter', 'sqlite*', 'pgsql*', 'odbc*',
    ];

    /** Denied functions, lower case; an entry ending in `*` is a prefix. Kind => names. */
    public const DENIED_FUNCTIONS = [
        'network' => [
            'fsockopen', 'pfsockopen', 'stream_socket_*', 'stream_context_create', 'stream_context_set_default', 'get_headers',
            'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_*', 'checkdnsrr', 'getmxrr', 'mail', 'mb_send_mail',
            'curl_*', 'socket_*', 'ftp_*', 'ssh2_*', 'imap_*', 'ldap_*', 'snmp*', 'http_*', 'fsockopen',
        ],
        'database' => ['mysqli_*', 'sqlite_*', 'pg_*', 'odbc_*', 'oci_*', 'sqlsrv_*', 'db2_*', 'dba_*', 'mssql_*'],
        'code execution' => [
            'exec', 'shell_exec', 'system', 'passthru', 'proc_*', 'popen', 'pcntl_*', 'posix_*', 'dl', 'ini_set', 'ini_alter',
            'ini_restore', 'set_include_path', 'stream_wrapper_*', 'stream_register_wrapper', 'stream_filter_register', 'unserialize',
            'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'create_function', 'assert',
            'extract', 'parse_str', 'putenv', 'apache_*', 'class_alias', 'spl_autoload_register', 'register_tick_function',
            'register_shutdown_function', 'opcache_*', 'get_defined_vars',
        ],
        'request' => ['filter_input', 'filter_input_array', 'filter_has_var', 'getenv', 'getallheaders', 'session_*'],
        'header' => ['header', 'header_remove', 'headers_list', 'setcookie', 'setrawcookie', 'http_response_code', 'header_register_callback'],
    ];

    /** A URL of a network (or wrapper-chaining) stream wrapper anywhere in a literal. */
    public const NETWORK_URL = '/(?<![a-z0-9+.\-])(https?|ftps?|tcp|udp|ssl|tls|tlsv[0-9.]+|sslv[23]|unix|udg|ssh2\.[a-z]+|phar|expect|compress\.zlib|compress\.bzip2|zlib|rar|ogg)\s*:\s*\/\//i';
    /** A literal that is only a network scheme, or starts with `://`: a URL put together from pieces. */
    public const URL_PIECE = '/^\s*((https?|ftps?|tcp|udp|ssl|tls|unix|udg)\s*:?\s*|:\/\/.*)\z/is';

    public const DATABASE_STRING = '/(?<![A-Za-z0-9_])\\\\?(PDO|SQLite3|mysqli)(\\\\|::|\z)/i';
    /** Upper-case SQL statements (case-sensitive, so that English labels such as "Select a sheet from" pass). */
    public const SQL = '/\b(SELECT\s.*\bFROM\b|INSERT\s+INTO|UPDATE\s+\S+\s+SET\b|DELETE\s+FROM|(CREATE|DROP)\s+(TEMP\s+|TEMPORARY\s+|UNIQUE\s+)?(TABLE|INDEX|VIEW|TRIGGER)|ALTER\s+TABLE|PRAGMA\s|ATTACH\s+DATABASE|VACUUM\b)/s';
    /** SQL in any letter case, only in shapes with SQL punctuation or clause order that English labels do not have. */
    public const SQL_ANY_CASE = '/\b(insert\s+(or\s+\w+\s+)?into\s+["`\w.]+\s*(\(|values\b|select\b)|values\s*\(\s*\?'
        . '|create\s+(temp(orary)?\s+|unique\s+)?(table|index|view|trigger)\s+(if\s+not\s+exists\s+)?["`\w.]+\s*(\(|on\b|as\b)'
        . '|drop\s+(table|index|view|trigger)\s+if\s+exists\b|alter\s+table\s+["`\w.]+\s+(add|rename|drop)\b'
        . '|delete\s+from\s+["`\w.]+\s+where\b|update\s+["`\w.]+\s+set\s+["`\w.]+\s*='
        . '|select\s+.+?\s+from\s+["`\w.]+\s+(where|order\s+by|group\s+by|limit|join)\b'
        . '|select\s+(\*|distinct\b|count\s*\(|["`\w.]+\s*,\s*["`\w.]+).*?\bfrom\s+["`\w.]+'
        . '|\bwhere\s+["`\w.]+\s*(=|<|>|in\s*\()\s*[?:(]|pragma\s+\w+\s*(=|\(|;|\z)|attach\s+database\b|vacuum\s+into\b)/is';

    public const SUPERGLOBALS = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION', '$_ENV', '$GLOBALS'];

    /** A class name segment, as the plugin loader reads it. */
    private const SEGMENT = '[A-Z][A-Za-z0-9]{0,63}';
    private const ID = '[a-z][a-z0-9-]{1,31}';

    /** Why a file may not be under plugins/, or null when it may. $path is the repository path. */
    public static function fileProblem(string $path): ?string
    {
        $base = '#^plugins/(import|export)/' . self::ID . '/';
        if (preg_match($base . 'plugin\.json\z#', $path) === 1) {
            return null;
        }
        if (preg_match($base . 'src/(' . self::SEGMENT . '/)*' . self::SEGMENT . '\.php\z#', $path) === 1) {
            return null;
        }
        $segments = explode('/', $path);
        $name = (string) end($segments);
        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return 'a hidden file or folder under plugins/';
            }
        }
        if (preg_match('/^(README|LICENSE|LICENCE|NOTICE|COPYING)([.-][A-Za-z0-9.-]*)?\z/', $name) === 1
            && !preg_match('/\.(php[0-9s]?|phtml|phar|inc|pht)(\.|\z)/i', $name)) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.(md|txt)\z/', $name) === 1) {
            return null;
        }

        return 'a file that is not plugin.json, a class file in src/, or documentation';
    }

    /**
     * Problems in one plugin source file, as descriptions. $path is the
     * repository path, `plugins/<kind>/<id>/src/...php`.
     *
     * @return list<string>
     */
    public static function problems(string $path, string $source): array
    {
        if (preg_match('#^plugins/(import|export)/(' . self::ID . ')/src/(?:' . self::SEGMENT . '/)*' . self::SEGMENT . '\.php\z#', $path, $m) !== 1) {
            return ['PHP outside plugins/<kind>/<id>/src/, or a file name that is not a class name'];
        }
        $own = Manifest::namespaceFor($m[1], $m[2]);

        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
            }
        }
        $found = [];
        $aliases = [];          // class and namespace aliases, lower case => full name
        $functionAliases = [];  // `use function` aliases, lower case => full name
        $depth = 0;
        $sawNamespace = false;
        $inString = false;      // inside a double-quoted string or heredoc (interpolation braces do not end a statement)
        $statementText = [];    // literal pieces of the current statement, for SQL split over pieces
        $count = count($tokens);
        $check = static function (?string $problem) use (&$found): void {
            if ($problem !== null) {
                $found[] = $problem;
            }
        };
        $flushSql = static function () use (&$statementText, &$found): void {
            $joined = implode(' ', $statementText);
            if ($statementText !== [] && (preg_match(self::SQL, $joined) === 1 || preg_match(self::SQL_ANY_CASE, $joined) === 1)) {
                $found[] = 'an SQL statement in a literal';
            }
            $statementText = [];
        };
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($t === '"' || (is_array($t) && in_array($t[0], [T_START_HEREDOC, T_END_HEREDOC], true))) {
                $inString = !(is_array($t) && $t[0] === T_END_HEREDOC) && !($t === '"' && $inString);
            }
            if (!$inString && ($t === ';' || $t === '{' || $t === '}')) {
                $flushSql();
            }
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            }
            if ($t === '}') {
                $depth--;
            }
            // Code execution and calls whose target cannot be read.
            if ($t === '`') {
                $found[] = 'code execution: backticks';
            }
            if ($t === '$' || (is_array($t) && $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $found[] = 'a variable variable';
            }
            if (in_array($t, [')', ']', '}'], true) && $next === '(') {
                $found[] = 'a call of an expression';
            }
            if (!is_array($t)) {
                continue;
            }
            [$id, $text] = $t;

            if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $found[] = 'code execution: ' . strtolower($text);
            }
            if ($id === T_NEW && (is_array($next) && $next[0] === T_VARIABLE || $next === '(' || $next === '$')) {
                $found[] = 'new with a class name that is not written out';
            }
            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                && ($next === '{' || (is_array($next) && $next[0] === T_VARIABLE && ($tokens[$i + 2] ?? null) === '('))) {
                $found[] = 'a member called by a computed name';
            }
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            if ($id === T_VARIABLE && $next === '(' && !$isMember && !(is_array($prev) && $prev[0] === T_NEW)) {
                $found[] = 'a call of the variable ' . $text;
            }

            if ($id === T_NAMESPACE && is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $sawNamespace = true;
                if (strcasecmp($next[1], $own) !== 0 && stripos($next[1], $own . '\\') !== 0) {
                    $found[] = 'declares namespace ' . $next[1] . ', not ' . $own;
                }
                $i++;
                continue;
            }
            if ($id === T_USE && $depth === 0 && $next !== '(') {
                // An import: use [function|const] Name [as Alias] {, Name [as Alias]} ;
                $j = $i + 1;
                $kind = 'class';
                if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
                    $kind = $tokens[$j][0] === T_FUNCTION ? 'function' : 'const';
                    $j++;
                }
                while ($j < $count) {
                    $name = $tokens[$j];
                    if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $found[] = 'an import that cannot be read';
                        break;
                    }
                    if (($tokens[$j + 1] ?? null) === '{' || (is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_NS_SEPARATOR)) {
                        $found[] = 'a group use';
                        break;
                    }
                    $full = ltrim($name[1], '\\');
                    $parts = explode('\\', $full);
                    $alias = strtolower((string) end($parts));
                    $j++;
                    if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_AS && is_array($tokens[$j + 1] ?? null)) {
                        $alias = strtolower($tokens[$j + 1][1]);
                        $j += 2;
                    }
                    if ($kind === 'function') {
                        $functionAliases[$alias] = $full;
                        foreach (self::functionProblems($full) as $problem) {
                            $found[] = $problem . ' (imported with use function)';
                        }
                    } elseif ($kind === 'class') {
                        $aliases[$alias] = $full;
                    }
                    $check(self::nameProblem($full, $own, $kind));
                    if (($tokens[$j] ?? null) !== ',') {
                        break;
                    }
                    $j++;
                }
                $i = $j;
                if (($tokens[$j] ?? null) === ';') {
                    $flushSql();
                }
                continue;
            }

            // Names in code.
            if ($id === T_NAME_FULLY_QUALIFIED) {
                $isCall = $next === '(' && !(is_array($prev) && $prev[0] === T_NEW);
                $check(self::nameProblem(ltrim($text, '\\'), $own, $isCall ? 'function' : 'class'));
            } elseif ($id === T_NAME_QUALIFIED) {
                $first = strtolower(explode('\\', $text)[0]);
                if (isset($aliases[$first])) {
                    $check(self::nameProblem($aliases[$first] . substr($text, strlen($first)), $own, 'class'));
                }
            } elseif ($id === T_STRING && !$isMember && $next !== '(' && isset($aliases[strtolower($text)])) {
                $check(self::nameProblem($aliases[strtolower($text)], $own, 'class'));
            }
            // Function calls: unqualified (own namespace, then global), fully qualified, namespace-relative, or aliased.
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) && !$isMember && $next === '('
                && !(is_array($prev) && $prev[0] === T_NEW)) {
                $call = strtolower(ltrim($text, '\\'));
                $resolved = $id === T_STRING && isset($functionAliases[$call]) ? strtolower($functionAliases[$call]) : $call;
                $last = substr((string) strrchr('\\' . $resolved, '\\'), 1);
                foreach (self::functionProblems($last) as $problem) {
                    $found[] = $problem;
                }
            }
            if ($id === T_STRING && !$isMember && $next !== '(') {
                $check(self::globalClassProblem($text));
            }
            if ($id === T_VARIABLE && in_array($text, self::SUPERGLOBALS, true)) {
                $found[] = 'reads the superglobal ' . $text;
            }
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $value = $id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
                $value = str_replace('\\\\', '\\', $value);
                $statementText[] = $value;
                if (preg_match(self::NETWORK_URL, $value) === 1 || preg_match(self::URL_PIECE, $value) === 1) {
                    $found[] = 'a literal URL of a network stream wrapper';
                }
                if (preg_match_all('/(?<![A-Za-z0-9_])\\\\?(tiffindb\\\\[A-Za-z0-9_\\\\]+)/i', $value, $named) > 0) {
                    foreach ($named[1] as $one) {
                        $problem = self::nameProblem($one, $own, 'class');
                        if ($problem !== null) {
                            $found[] = $problem . ' (in a string)';
                        }
                    }
                }
                if (preg_match(self::DATABASE_STRING, $value) === 1) {
                    $found[] = 'names a database class in a string';
                }
                $callable = strtolower(ltrim(trim($value), '\\'));
                if (preg_match('/^[a-z_][a-z0-9_]*\z/', $callable) === 1) {
                    foreach (self::functionProblems($callable) as $problem) {
                        $found[] = $problem . ' (named in a string)';
                    }
                }
            }
        }
        $flushSql();
        if (!$sawNamespace) {
            $found[] = 'declares no namespace';
        }

        return array_values(array_unique($found));
    }

    /** Why a fully qualified name is not allowed, or null when it is. $kind: class, function, or const. */
    private static function nameProblem(string $name, string $own, string $kind): ?string
    {
        if (stripos($name, self::CONTRACT) === 0 || stripos($name, $own . '\\') === 0 || stripos($name, self::LIBRARY) === 0) {
            return null;
        }
        foreach (self::CORE_CSV_CLASSES as $class) {
            if (strcasecmp($name, $class) === 0) {
                return null;
            }
        }
        if (!str_contains($name, '\\')) {
            return $kind === 'class' ? self::globalClassProblem($name) : null;
        }

        return 'names ' . $name . ', outside the allowed namespaces';
    }

    /** Why a global class name is denied, or null. */
    private static function globalClassProblem(string $name): ?string
    {
        $lower = strtolower(ltrim($name, '\\'));
        if (self::matchesPattern($lower, self::DENIED_CLASSES)) {
            return in_array($lower, ['pdo', 'sqlite3', 'mysqli'], true) || preg_match('/^(pdo|sqlite3|mysqli)/', $lower) === 1
                ? 'uses the database class ' . $name
                : 'uses the denied class ' . $name;
        }

        return null;
    }

    /** @param list<string> $patterns lower case; `*` at the end is a prefix */
    private static function matchesPattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($name, substr($pattern, 0, -1)) : $name === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function functionProblems(string $function): array
    {
        $name = strtolower(ltrim($function, '\\'));
        if (str_contains($name, '\\')) {
            $name = substr((string) strrchr($name, '\\'), 1);
        }
        $found = [];
        $labels = ['network' => 'calls the network function', 'database' => 'calls the database function',
            'code execution' => 'code execution:', 'request' => 'reads request state with', 'header' => 'sends a header with'];
        foreach (self::DENIED_FUNCTIONS as $kind => $patterns) {
            if (self::matchesPattern($name, $patterns)) {
                $found[] = $labels[$kind] . ' ' . $name . '()';
            }
        }

        return $found;
    }

    // ------------------------------------------------------- the repository

    /** @return list<string> repository paths of every PHP file under plugins/ */
    public static function pluginPhpFiles(): array
    {
        return array_values(array_filter(
            RepoFiles::all(self::PLUGINS),
            static fn (string $path): bool => str_ends_with(strtolower($path), '.php'),
        ));
    }

    /** @return iterable<string, array{?string}> */
    public static function pluginFiles(): iterable
    {
        $files = self::pluginPhpFiles();
        if ($files === []) {
            yield 'no plugin has PHP files yet: the rule passes trivially' => [null];

            return;
        }
        foreach ($files as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('pluginFiles')]
    public function testPluginFilesKeepToTheirLayer(?string $path): void
    {
        if ($path === null) {
            self::assertSame([], self::pluginPhpFiles());

            return;
        }
        self::assertSame([], self::problems($path, RepoFiles::read($path)));
    }

    public function testTheRepositoryHasThePluginsDirectory(): void
    {
        $manifests = array_values(array_filter(RepoFiles::all(self::PLUGINS), static fn (string $p): bool => basename($p) === 'plugin.json'));
        self::assertCount(5, $manifests);
    }

    // ---------------------------------------------------- the rule itself

    private const HEAD = "<?php\ndeclare(strict_types=1);\nnamespace TiffinDB\\Plugin\\Import\\Example;\n";
    private const PATH = 'plugins/import/example/src/Reader.php';

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'contract, own, library, built-ins' => [self::HEAD
            . "use TiffinDB\\Core\\Io\\Contract\\Importer;\nuse TiffinDB\\Core\\Io\\Contract\\PluginFailure as Failure;\n"
            . "use OpenSpout\\Reader\\XLSX\\Reader as Xlsx;\nuse RuntimeException;\nuse function strlen;\nuse const PHP_EOL;\n"
            . "final class Reader implements Importer {\n"
            . "    use \\TiffinDB\\Plugin\\Import\\Example\\Helpers;\n"
            . "    public function f(): void { \$x = new \\ZipArchive(); \$y = new Failure(Failure::REASON_FAILED); \$z = new Sub\\Thing();\n"
            . "        \$s = \\strlen('x') . PHP_EOL; \$c = static function () use (\$s): string { return \$s; };\n"
            . "        \$e = new \\TiffinDB\\Core\\Io\\Contract\\Settings([]); \$o = new \\OpenSpout\\Common\\Entity\\Row([]);\n"
            . "        \$h = fopen('php://temp', 'w+b'); \$t = \$this->header(); \$label = 'Select a sheet from the workbook';\n"
            . "        \$name = 'TiffinDB\\\\Plugin\\\\Import\\\\Example\\\\Sub'; \$i = \\DateTimeImmutable::class; \$q = \\JSON_THROW_ON_ERROR; } }\n"];
        yield 'core csv reader and writer (P143)' => [self::HEAD
            . "use TiffinDB\\Core\\Io\\Csv\\CsvReader;\nuse TiffinDB\\Core\\Io\\Csv\\Charset as C;\n"
            . "use TiffinDB\\Core\\Io\\Csv\\CsvDetector;\n"
            . "\$w = new \\TiffinDB\\Core\\Io\\Csv\\CsvWriter(); \$d = C::detect(''); \$n = 'tiffindb\\\\core\\\\io\\\\csv\\\\CsvDialect';\n"];
        foreach ([
            'Select a sheet from the workbook', 'Delete from the list', 'Update the table', 'Create table from the first sheet',
            'Drop table names that repeat', 'Insert into the project', 'Values are read as text', 'Where the heading is empty, use Column n',
            'Choose where to put the values (one per row)', 'Pragma', 'Select rows from Sheet1 to import', 'Add a column with each record\'s number',
        ] as $label) {
            yield 'English label: ' . $label => [self::HEAD . "\$label = " . var_export($label, true) . ";\n"];
        }
        yield 'closures, static properties, ordinary calls' => [self::HEAD
            . "final class R { private static array \$cache = []; private \\Closure \$f;\n"
            . "  public function g(\\Closure \$decode, string \$s): string { self::\$cache['a'] = 1; \$x = static::\$cache;\n"
            . "    \$v = \$decode->__invoke(\$s, false); \$w = (\$this->f)->__invoke(); \$h = fopen('php://memory', 'w+b');\n"
            . "    \$m = array_map(static fn (string \$c): string => trim(\$c), [\$s]); \$n = new self(); \$o = new static();\n"
            . "    \$j = json_encode(\$m, \\JSON_THROW_ON_ERROR); \$z = new \\ZipArchive(); \$q = \"Row {\$s} of {\$v}\";\n"
            . "    return sprintf('%s', \$j) . strtolower('A'); } }\n"];
        yield 'own namespace in another letter case' => [self::HEAD . "use TiffinDB\\Plugin\\Import\\EXAMPLE\\Y;\n"];
        yield 'local file names and English words' => [self::HEAD . "\$a = ['zip', 'xlsx', 'heading', 'delimiter', 'Header row', 'System default', 'php://temp'];\n"];
        yield 'sub-namespace' => ["<?php\nnamespace TiffinDB\\Plugin\\Import\\Example\\Sub;\nfinal class Thing {}\n"];
        yield 'method named like a forbidden function' => [self::HEAD . "final class R { public function header(): string { return \$this->mail(); } public function mail(): string { return ''; } }\n"];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $source): void
    {
        self::assertSame([], self::problems(self::PATH, $source));
    }

    /** @return iterable<string, array{string, string}> source, expected problem (substring) */
    public static function refused(): iterable
    {
        // Review M3-WP2 item 1: aliases of denied functions.
        yield 'use function curl alias' => [self::HEAD . "use function curl_init as ci;\nci('x');\n", 'calls the network function curl_init()'];
        yield 'use function header alias' => [self::HEAD . "use function header as h;\nh('x');\n", 'sends a header with header()'];
        yield 'use function exec, no alias' => [self::HEAD . "use function exec;\n", 'code execution: exec()'];
        yield 'use function fully qualified' => [self::HEAD . "use function \\shell_exec as run;\nrun('x');\n", 'code execution: shell_exec()'];
        // Item 2: code execution.
        yield 'eval' => [self::HEAD . "eval(\$x);\n", 'code execution: eval'];
        yield 'require' => [self::HEAD . "require __DIR__ . '/../plugin.json';\n", 'code execution: require'];
        yield 'include_once' => [self::HEAD . "include_once 'x.php';\n", 'code execution: include_once'];
        yield 'backticks' => [self::HEAD . "\$o = `ls`;\n", 'code execution: backticks'];
        foreach (['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'dl', 'ini_set', 'ini_alter', 'set_include_path',
            'stream_wrapper_register', 'unserialize', 'call_user_func', 'call_user_func_array', 'assert', 'class_alias', 'spl_autoload_register'] as $function) {
            yield 'call ' . $function => [self::HEAD . $function . "('x');\n", 'code execution: ' . $function . '()'];
        }
        yield 'fully qualified system' => [self::HEAD . "\\system('x');\n", 'code execution: system()'];
        yield 'namespace-relative header' => [self::HEAD . "namespace\\header('x');\n", 'sends a header with header()'];
        yield 'callable string' => [self::HEAD . "\$r = array_map('curl_init', \$a);\n", 'calls the network function curl_init() (named in a string)'];
        yield 'callable string, upper case' => [self::HEAD . "\$r = array_map('\\\\Shell_Exec', \$a);\n", 'code execution: shell_exec() (named in a string)'];
        yield 'variable call' => [self::HEAD . "\$f = 'fsock' . 'open';\n\$f('192.0.2.1', 80);\n", 'a call of the variable $f'];
        yield 'call of an expression' => [self::HEAD . "('sys' . 'tem')('x');\n", 'a call of an expression'];
        yield 'call of an array element' => [self::HEAD . "\$a[0]('x');\n", 'a call of an expression'];
        yield 'new with a variable' => [self::HEAD . "\$o = new \$cls();\n", 'new with a class name that is not written out'];
        yield 'new with an expression' => [self::HEAD . "\$o = new ('P' . 'DO')('x');\n", 'new with a class name that is not written out'];
        yield 'method by a variable' => [self::HEAD . "\$o->\$m('x');\n", 'a member called by a computed name'];
        yield 'static method by a variable' => [self::HEAD . "X::\$m('x');\n", 'a member called by a computed name'];
        yield 'member by an expression' => [self::HEAD . "\$o->{'ex' . 'ec'}('x');\n", 'a member called by a computed name'];
        yield 'FFI' => [self::HEAD . "\$f = \\FFI::cdef('int system(const char*);');\n", 'uses the denied class FFI'];
        yield 'reflection' => [self::HEAD . "\$r = new \\ReflectionFunction('x');\n", 'uses the denied class ReflectionFunction'];
        yield 'imported reflection' => [self::HEAD . "use ReflectionClass;\n", 'uses the denied class ReflectionClass'];
        yield 'soap client' => [self::HEAD . "\$s = new \\SoapClient(null, []);\n", 'uses the denied class SoapClient'];
        yield 'curl file' => [self::HEAD . "\$s = new \\CURLFile('x');\n", 'uses the denied class CURLFile'];
        yield 'phar' => [self::HEAD . "\$p = new \\Phar('x.phar');\n", 'uses the denied class Phar'];
        // Item 4: request state.
        yield 'variable variable superglobal' => [self::HEAD . "\$x = \${'_GET'};\n", 'a variable variable'];
        yield 'variable variable' => [self::HEAD . "\$n = '_GET'; \$x = \$\$n;\n", 'a variable variable'];
        foreach (['filter_input', 'filter_input_array', 'getenv', 'getallheaders', 'session_start', 'session_id'] as $function) {
            yield 'call ' . $function => [self::HEAD . "\$x = " . $function . "();\n", 'reads request state with ' . $function . '()'];
        }
        yield 'header callback' => [self::HEAD . "header_register_callback(\$c);\n", 'sends a header with header_register_callback()'];
        yield 'superglobal in a string' => [self::HEAD . "\$x = \"a {\$_SERVER['HTTP_HOST']}\";\n", 'reads the superglobal $_SERVER'];
        // Item 5: strings, URLs, SQL.
        yield 'lower-case class name in a string' => [self::HEAD . "\$c = 'tiffindb\\\\core\\\\storage\\\\Fs';\n", 'names tiffindb\\core\\storage\\Fs'];
        yield 'url behind compress.zlib' => [self::HEAD . "\$h = fopen('compress.zlib://http://192.0.2.1/', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'url behind php filter' => [self::HEAD . "\$h = fopen('php://filter/resource=http://192.0.2.1/', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'url with leading space and capitals' => [self::HEAD . "\$h = fopen(' HTTP://192.0.2.1', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'url from pieces' => [self::HEAD . "\$h = file_get_contents('http' . '://192.0.2.1/');\n", 'a literal URL of a network stream wrapper'];
        yield 'phar url' => [self::HEAD . "\$h = fopen('phar://x.phar/y', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'lower-case select star' => [self::HEAD . "\$q = 'select * from users';\n", 'an SQL statement in a literal'];
        yield 'concatenated sql' => [self::HEAD . "\$q = 'SELECT * ' . 'FROM users';\n", 'an SQL statement in a literal'];
        yield 'concatenated sql with a variable' => [self::HEAD . "\$q = 'DELETE ' . \$x . ' FROM users';\n", 'an SQL statement in a literal'];
        yield 'heredoc sql' => [self::HEAD . "\$q = <<<SQL\nSELECT * FROM users\nSQL;\n", 'an SQL statement in a literal'];
        yield 'heredoc sql with interpolation' => [self::HEAD . "\$q = <<<SQL\nSELECT {\$a} FROM users\nSQL;\n", 'an SQL statement in a literal'];
        yield 'nowdoc sql' => [self::HEAD . "\$q = <<<'SQL'\nDELETE FROM users\nSQL;\n", 'an SQL statement in a literal'];
        yield 'database class in a lower-case string' => [self::HEAD . "\$p = 'pdo';\n", 'names a database class in a string'];
        // Item 7: only the named core CSV classes.
        yield 'other core csv class' => [self::HEAD . "use TiffinDB\\Core\\Io\\Csv\\Internal;\n", 'names TiffinDB\\Core\\Io\\Csv\\Internal'];
        yield 'core csv sub-namespace' => [self::HEAD . "\$x = new \\TiffinDB\\Core\\Io\\Csv\\CsvReader\\X();\n", 'names TiffinDB\\Core\\Io\\Csv\\CsvReader\\X'];
        yield 'aliased core csv class as a namespace' => [self::HEAD . "use TiffinDB\\Core\\Io\\Csv\\Charset as C;\n\$d = C\\Sub::class;\n", 'names TiffinDB\\Core\\Io\\Csv\\Charset\\Sub'];
        yield 'core csv look-alike class' => [self::HEAD . "use TiffinDB\\Core\\Io\\Csv\\CsvReaderEvil;\n", 'names TiffinDB\\Core\\Io\\Csv\\CsvReaderEvil'];
        yield 'core io archive' => [self::HEAD . "use TiffinDB\\Core\\Io\\Archive\\X;\n", 'names TiffinDB\\Core\\Io\\Archive\\X'];
        yield 'core io top level' => [self::HEAD . "\$x = new \\TiffinDB\\Core\\Io\\ImportJob();\n", 'names TiffinDB\\Core\\Io\\ImportJob'];
        yield 'core io plugin' => [self::HEAD . "use TiffinDB\\Core\\Io\\Plugin\\PluginLoader;\n", 'names TiffinDB\\Core\\Io\\Plugin\\PluginLoader'];
        yield 'csv look-alike' => [self::HEAD . "use TiffinDB\\Core\\Io\\CsvEvil\\X;\n", 'names TiffinDB\\Core\\Io\\CsvEvil\\X'];
        yield 'csv namespace itself' => [self::HEAD . "use TiffinDB\\Core\\Io\\Csv;\n", 'names TiffinDB\\Core\\Io\\Csv,'];
        yield 'csv look-alike in a string' => [self::HEAD . "\$c = 'TiffinDB\\\\Core\\\\Io\\\\Csvx\\\\Y';\n", 'names TiffinDB\\Core\\Io\\Csvx\\Y'];
        yield 'aliased csv parent' => [self::HEAD . "use TiffinDB\\Core\\Io as I;\n\$x = new I\\Csv\\CsvReader();\n", 'names TiffinDB\\Core\\Io,'];
        yield 'query records' => [self::HEAD . "use TiffinDB\\Core\\Query\\Records;\n", 'names TiffinDB\\Core\\Query\\Records'];
        yield 'core storage' => [self::HEAD . "use TiffinDB\\Core\\Storage\\Fs;\n", 'names TiffinDB\\Core\\Storage\\Fs'];
        yield 'core io outside the contract' => [self::HEAD . "\$x = new \\TiffinDB\\Core\\Io\\ImportJob();\n", 'names TiffinDB\\Core\\Io\\ImportJob'];
        yield 'aliased namespace' => [self::HEAD . "use TiffinDB\\Core as C;\n\$x = new C\\System\\AuditLog();\n", 'names TiffinDB\\Core'];
        yield 'web layer' => [self::HEAD . "\$x = \\TiffinDB\\Web\\Request::class;\n", 'names TiffinDB\\Web\\Request'];
        yield 'another plugin' => [self::HEAD . "use TiffinDB\\Plugin\\Import\\Csv\\CsvImporter;\n", 'names TiffinDB\\Plugin\\Import\\Csv\\CsvImporter'];
        yield 'own prefix without separator' => [self::HEAD . "use TiffinDB\\Plugin\\Import\\ExampleOther\\X;\n", 'names TiffinDB\\Plugin\\Import\\ExampleOther\\X'];
        yield 'a vendor package' => [self::HEAD . "use PHPUnit\\Framework\\TestCase;\n", 'names PHPUnit\\Framework\\TestCase'];
        yield 'a non-built-in global class' => [self::HEAD . "\$x = new \\Composer\\Autoload\\ClassLoader();\n", 'names Composer\\Autoload\\ClassLoader'];
        yield 'a class named in a string' => [self::HEAD . "\$c = 'TiffinDB\\\\Core\\\\Storage\\\\Fs';\n", 'names TiffinDB\\Core\\Storage\\Fs, outside the allowed namespaces (in a string)'];
        yield 'group use' => [self::HEAD . "use TiffinDB\\Core\\Io\\Contract\\{Importer, Settings};\n", 'a group use'];
        yield 'wrong namespace' => ["<?php\nnamespace TiffinDB\\Plugin\\Import\\Other;\n", 'declares namespace TiffinDB\\Plugin\\Import\\Other'];
        yield 'namespace of the other kind' => ["<?php\nnamespace TiffinDB\\Plugin\\Export\\Example;\n", 'declares namespace'];
        yield 'no namespace' => ["<?php\nfinal class Reader {}\n", 'declares no namespace'];
        yield 'curl' => [self::HEAD . "\$h = curl_init('x');\n", 'calls the network function curl_init()'];
        yield 'fully qualified curl' => [self::HEAD . "\$h = \\curl_exec(\$c);\n", 'calls the network function curl_exec()'];
        yield 'fsockopen' => [self::HEAD . "\$h = fsockopen('192.0.2.1', 80);\n", 'calls the network function fsockopen()'];
        yield 'stream socket' => [self::HEAD . "\$h = stream_socket_client('x');\n", 'calls the network function stream_socket_client()'];
        yield 'socket' => [self::HEAD . "\$h = socket_create(AF_INET, SOCK_STREAM, 0);\n", 'calls the network function socket_create()'];
        yield 'dns' => [self::HEAD . "\$h = gethostbyname('example.com');\n", 'calls the network function gethostbyname()'];
        yield 'mail' => [self::HEAD . "mail('user@example.com', 's', 'b');\n", 'calls the network function mail()'];
        yield 'url in fopen' => [self::HEAD . "\$h = fopen('https://example.com/x', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'ftp url' => [self::HEAD . "\$h = file_get_contents(\"ftp://example.com/x\");\n", 'a literal URL of a network stream wrapper'];
        yield 'tcp url' => [self::HEAD . "\$h = fopen('tcp://192.0.2.1:80', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'unix socket url' => [self::HEAD . "\$h = fopen('unix:///run/x.sock', 'r');\n", 'a literal URL of a network stream wrapper'];
        yield 'pdo' => [self::HEAD . "\$db = new \\PDO('sqlite::memory:');\n", 'uses the database class PDO'];
        yield 'imported pdo' => [self::HEAD . "use PDO;\n\$db = new PDO('sqlite::memory:');\n", 'uses the database class PDO'];
        yield 'sqlite3' => [self::HEAD . "\$db = new \\SQLite3(':memory:');\n", 'uses the database class SQLite3'];
        yield 'mysqli function' => [self::HEAD . "\$db = mysqli_connect();\n", 'calls the database function mysqli_connect()'];
        yield 'sql literal' => [self::HEAD . "\$sql = 'SELECT * FROM users';\n", 'an SQL statement in a literal'];
        yield 'sql literal in double quotes' => [self::HEAD . "\$sql = \"DELETE FROM sessions\";\n", 'an SQL statement in a literal'];
        foreach ([
            'select * from users where id = ?', 'insert into users (id) values (?)', 'insert or replace into meta values (1)',
            'update users set role = ?', 'delete from sessions where token_hash = ?', 'create table t (x)',
            'create unique index t_x on t (x)', 'drop table if exists t', 'alter table users add column x', 'pragma user_version = 3',
            'pragma table_info(users)', 'select name from sqlite_master order by name', 'attach database :f as other', 'vacuum into ?',
            'select a, b from t join u on t.id = u.id',
        ] as $sql) {
            yield 'lower-case sql: ' . $sql => [self::HEAD . "\$sql = " . var_export($sql, true) . ";\n", 'an SQL statement in a literal'];
        }
        yield 'pragma literal' => [self::HEAD . "\$sql = 'PRAGMA user_version';\n", 'an SQL statement in a literal'];
        yield 'create table literal' => [self::HEAD . "\$sql = 'CREATE TABLE t (x)';\n", 'an SQL statement in a literal'];
        yield 'superglobal' => [self::HEAD . "\$x = \$_GET['a'];\n", 'reads the superglobal $_GET'];
        yield 'globals' => [self::HEAD . "\$x = \$GLOBALS['a'];\n", 'reads the superglobal $GLOBALS'];
        yield 'server' => [self::HEAD . "\$x = \$_SERVER['HTTP_HOST'];\n", 'reads the superglobal $_SERVER'];
        yield 'header' => [self::HEAD . "header('X-Example: 1');\n", 'sends a header with header()'];
        yield 'fully qualified header' => [self::HEAD . "\\header('X-Example: 1');\n", 'sends a header with header()'];
        yield 'cookie' => [self::HEAD . "setcookie('a', 'b');\n", 'sends a header with setcookie()'];
        yield 'response code' => [self::HEAD . "http_response_code(500);\n", 'sends a header with http_response_code()'];
    }

    public function testCallsThroughFunctionAliasesAreResolved(): void
    {
        // The import itself is refused, and so is each call through the alias (item 1 of the WP2 review).
        $problems = self::problems(self::PATH, self::HEAD . "use function curl_init as ci;\nuse function header as h;\nci('x');\nh('x');\n");
        self::assertContains('calls the network function curl_init() (imported with use function)', $problems);
        self::assertContains('calls the network function curl_init()', $problems);
        self::assertContains('sends a header with header() (imported with use function)', $problems);
        self::assertContains('sends a header with header()', $problems);
        $own = self::problems(self::PATH, self::HEAD . "use function TiffinDB\\Plugin\\Import\\Example\\helper as exec;\nexec('x');\n");
        self::assertSame([], $own, 'A call is checked as the function the alias names, not as the alias.');
    }

    #[DataProvider('refused')]
    public function testRefused(string $source, string $expected): void
    {
        $problems = self::problems(self::PATH, $source);
        self::assertNotSame([], $problems);
        $matched = array_filter($problems, static fn (string $p): bool => str_contains($p, $expected));
        self::assertNotSame([], $matched, implode('; ', $problems));
    }

    public function testEveryFileUnderPluginsIsOfAnAllowedKind(): void
    {
        $files = RepoFiles::all(self::PLUGINS);
        self::assertNotSame([], $files);
        foreach ($files as $path) {
            self::assertNull(self::fileProblem($path), $path);
        }
    }

    /** @return iterable<string, array{string, bool}> path, allowed */
    public static function filePaths(): iterable
    {
        foreach ([
            'plugins/import/example/plugin.json', 'plugins/export/ex-out/plugin.json', 'plugins/import/example/src/Reader.php',
            'plugins/import/example/src/Sub/Deep.php', 'plugins/import/example/README.md', 'plugins/import/example/LICENSE',
            'plugins/import/example/LICENSE.txt', 'plugins/import/example/NOTICE', 'plugins/import/example/docs/usage.md',
            'plugins/import/example/CHANGES.txt', 'plugins/README.md',
        ] as $path) {
            yield 'allowed ' . $path => [$path, true];
        }
        foreach ([
            'plugins/import/example/src/helpers.inc', 'plugins/import/example/src/View.phtml', 'plugins/import/example/lib.phar',
            'plugins/import/example/src/Old.php5', 'plugins/import/example/src/Old.php7', 'plugins/import/example/src/Reader',
            'plugins/import/example/.htaccess', 'plugins/import/example/src/.Hidden.php', 'plugins/import/.hidden/plugin.json',
            'plugins/import/example/bootstrap.php', 'plugins/import/example/src/reader.php', 'plugins/import/example/data.json',
            'plugins/import/example/other.json', 'plugins/import/example/plugin.json.php', 'plugins/import/example/README.php',
            'plugins/import/example/LICENSE.phtml', 'plugins/import/example/notes.md.php', 'plugins/other/example/plugin.json',
            'plugins/import/Example/plugin.json', 'plugins/import/example/src/Reader.PHP', 'plugins/import/example/image.png',
            'plugins/import/example/script.js', 'plugins/import/example/src/x.txt.inc',
        ] as $path) {
            yield 'refused ' . $path => [$path, false];
        }
    }

    #[DataProvider('filePaths')]
    public function testFileKinds(string $path, bool $allowed): void
    {
        self::assertSame($allowed, self::fileProblem($path) === null, (string) self::fileProblem($path));
    }

    /** @return iterable<string, array{string}> */
    public static function misplaced(): iterable
    {
        yield 'outside src' => ['plugins/import/example/boot.php'];
        yield 'at the plugins root' => ['plugins/helper.php'];
        yield 'unknown kind' => ['plugins/other/example/src/Reader.php'];
        yield 'bad id' => ['plugins/import/Example/src/Reader.php'];
        yield 'lower-case file name' => ['plugins/import/example/src/reader.php'];
        yield 'upper-case extension' => ['plugins/import/example/src/Reader.PHP'];
    }

    #[DataProvider('misplaced')]
    public function testPhpOutsideSrcIsRefused(string $path): void
    {
        self::assertNotSame([], self::problems($path, self::HEAD));
    }
}
