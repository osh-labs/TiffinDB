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
 * T-PLG-8 (M3 spec section 2.1, ADR 0015 decisions 3 and 5, P131): every
 * PHP file under plugins/ lies in `plugins/<kind>/<id>/src/`, declares the
 * plugin's namespace (`TiffinDB\Plugin\<Kind>\<Id>`, or a namespace below
 * it), and names only `TiffinDB\Core\Io\Contract\`, its own namespace, PHP
 * built-in classes and functions, `OpenSpout\`, and `TiffinDB\Core\Io\Csv\`
 * (the core CSV reader and writer, ADR 0015 decision 2; P143, provisional). It calls no network
 * function (NETWORK_FUNCTIONS and NETWORK_PREFIXES; no literal URL of a
 * network stream wrapper), no database (no PDO, SQLite3, or other database
 * extension, no SQL statement in a literal: upper-case statements, and
 * lower-case ones only where SQL punctuation shows it is SQL, so that
 * English labels pass), reads no superglobal, and
 * sends no header.
 *
 * Read with token_get_all. Names are checked as written, after the file's
 * `use` imports; group uses are refused, since they are not needed and
 * would complicate the reading. String literals that name a TiffinDB class
 * are checked like code. Known limits: a name built at run time from
 * pieces, and calls made through variables, are not seen (the design-tier
 * review of each plugin reads its code).
 *
 * While no plugin has PHP files (M3-WP2), the rule passes trivially and the
 * data set name says so; the negative cases below always run.
 */
#[CoversNothing]
final class PluginLayerRulesTest extends TestCase
{
    public const PLUGINS = 'plugins';
    public const CONTRACT = 'TiffinDB\\Core\\Io\\Contract\\';
    public const LIBRARY = 'OpenSpout\\';
    /** The core CSV reader and writer (P143): exactly this prefix, not the rest of Core\Io. */
    public const CORE_CSV = 'TiffinDB\\Core\\Io\\Csv\\';

    /** Network functions (no network calls in the application: CLAUDE.md, plan section 7). */
    public const NETWORK_FUNCTIONS = [
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_accept', 'stream_socket_pair',
        'stream_context_create', 'stream_context_set_default', 'get_headers', 'gethostbyname', 'gethostbynamel', 'gethostbyaddr',
        'dns_get_record', 'dns_check_record', 'dns_get_mx', 'checkdnsrr', 'getmxrr', 'mail', 'mb_send_mail', 'http_get', 'http_post_data',
    ];
    public const NETWORK_PREFIXES = ['curl_', 'socket_', 'ftp_', 'ssh2_', 'imap_', 'ldap_', 'snmp', 'http_request'];
    /** Network stream wrappers that may not open a literal URL. */
    public const NETWORK_SCHEMES = '/^\s*(https?|ftps?|tcp|udp|ssl|tls|tlsv[0-9.]+|sslv[23]|unix|udg|ssh2\.[a-z]+):\/\//i';

    public const DATABASE_CLASSES = ['pdo', 'pdostatement', 'pdoexception', 'sqlite3', 'sqlite3stmt', 'sqlite3result', 'mysqli', 'mysqli_stmt', 'mysqli_result'];
    public const DATABASE_PREFIXES = ['mysqli_', 'sqlite_', 'pg_', 'odbc_', 'oci_', 'sqlsrv_', 'db2_', 'dba_', 'mssql_'];
    /** Upper-case SQL statements (case-sensitive, so that English labels such as "Select a sheet from" pass). */
    /** Lower-case (any case) SQL, only in shapes with SQL punctuation or clause order that English labels do not have. */
    public const SQL_ANY_CASE = '/\b(insert\s+(or\s+\w+\s+)?into\s+["`\w.]+\s*(\(|values\b|select\b)|values\s*\(\s*\?'
        . '|create\s+(temp(orary)?\s+|unique\s+)?(table|index|view|trigger)\s+(if\s+not\s+exists\s+)?["`\w.]+\s*(\(|on\b|as\b)'
        . '|drop\s+(table|index|view|trigger)\s+if\s+exists\b|alter\s+table\s+["`\w.]+\s+(add|rename|drop)\b'
        . '|delete\s+from\s+["`\w.]+\s+where\b|update\s+["`\w.]+\s+set\s+["`\w.]+\s*=|select\s+.+?\s+from\s+["`\w.]+\s+(where|order\s+by|group\s+by|limit|join)\b'
        . '|\bwhere\s+["`\w.]+\s*(=|<|>|in\s*\()\s*[?:(]|pragma\s+\w+\s*(=|\(|;|\z)|attach\s+database\b|vacuum\s+into\b)/is';
    public const SQL = '/\b(SELECT\s.*\bFROM\b|INSERT\s+INTO|UPDATE\s+\S+\s+SET\b|DELETE\s+FROM|(CREATE|DROP)\s+(TEMP\s+|TEMPORARY\s+|UNIQUE\s+)?(TABLE|INDEX|VIEW|TRIGGER)|ALTER\s+TABLE|PRAGMA\s|ATTACH\s+DATABASE|VACUUM\b)/s';

    public const HEADER_FUNCTIONS = ['header', 'header_remove', 'setcookie', 'setrawcookie', 'http_response_code', 'header_register_callback'];
    public const SUPERGLOBALS = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION', '$_ENV', '$GLOBALS'];

    /**
     * Problems in one plugin source file, as descriptions. $path is the
     * repository path, `plugins/<kind>/<id>/src/...php`.
     *
     * @return list<string>
     */
    public static function problems(string $path, string $source): array
    {
        if (preg_match('#^plugins/(import|export)/([a-z][a-z0-9-]{1,31})/src/(?:[A-Z][A-Za-z0-9]{0,63}/)*[A-Z][A-Za-z0-9]{0,63}\.php\z#', $path, $m) !== 1) {
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
        $aliases = [];
        $depth = 0;
        $sawNamespace = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            [$id, $text] = $t;

            if ($id === T_NAMESPACE && is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $sawNamespace = true;
                if ($next[1] !== $own && !str_starts_with($next[1], $own . '\\')) {
                    $found[] = 'declares namespace ' . $next[1] . ', not ' . $own;
                }
                $i++;
                continue;
            }
            if ($id === T_USE && $depth === 0 && !($next === '(')) {
                // An import: use [function|const] Name [as Alias] {, Name [as Alias]} ;
                $j = $i + 1;
                if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
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
                    $aliases[$alias] = $full;
                    if (($problem = self::nameProblem($full, $own)) !== null) {
                        $found[] = $problem;
                    }
                    if (($tokens[$j] ?? null) !== ',') {
                        break;
                    }
                    $j++;
                }
                $i = $j;
                continue;
            }

            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            if ($id === T_NAME_FULLY_QUALIFIED) {
                if (($problem = self::nameProblem(ltrim($text, '\\'), $own)) !== null) {
                    $found[] = $problem;
                }
            } elseif ($id === T_NAME_QUALIFIED) {
                $first = strtolower(explode('\\', $text)[0]);
                if (isset($aliases[$first])) {
                    $full = $aliases[$first] . substr($text, strlen($first));
                    if (($problem = self::nameProblem($full, $own)) !== null) {
                        $found[] = $problem;
                    }
                }
            }
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && !$isMember) {
                $call = strtolower(ltrim($text, '\\'));
                $last = substr((string) strrchr('\\' . $call, '\\'), 1);
                $isNew = is_array($prev) && $prev[0] === T_NEW;
                if ($next === '(' && !$isNew && !str_contains($call, '\\')) {
                    foreach (self::functionProblems($call) as $problem) {
                        $found[] = $problem;
                    }
                }
                if (in_array($last, self::DATABASE_CLASSES, true) && ($next !== '(' || (is_array($prev) && $prev[0] === T_NEW))) {
                    $found[] = 'uses the database class ' . $text;
                }
            }
            if ($id === T_VARIABLE && in_array($text, self::SUPERGLOBALS, true)) {
                $found[] = 'reads the superglobal ' . $text;
            }
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $value = $id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
                $value = str_replace('\\\\', '\\', $value);
                if (preg_match(self::NETWORK_SCHEMES, $value) === 1) {
                    $found[] = 'a literal URL of a network stream wrapper';
                }
                if (preg_match(self::SQL, $value) === 1 || preg_match(self::SQL_ANY_CASE, $value) === 1) {
                    $found[] = 'an SQL statement in a literal';
                }
                if (preg_match('/(?<![A-Za-z0-9_])\\\\?(TiffinDB\\\\[A-Za-z0-9_\\\\]+)/', $value, $named) === 1
                    && ($problem = self::nameProblem($named[1], $own)) !== null) {
                    $found[] = $problem . ' (in a string)';
                }
                if (preg_match('/^\\\\?(PDO|SQLite3|mysqli)(\\\\|\z)/i', $value) === 1) {
                    $found[] = 'names a database class in a string';
                }
            }
        }
        if (!$sawNamespace) {
            $found[] = 'declares no namespace';
        }

        return array_values(array_unique($found));
    }

    /** Why a fully qualified name is not allowed, or null when it is. */
    private static function nameProblem(string $name, string $own): ?string
    {
        if (str_starts_with($name, self::CONTRACT) || str_starts_with($name, $own . '\\') || str_starts_with($name, self::LIBRARY)
            || str_starts_with($name, self::CORE_CSV)) {
            return null;
        }
        if (!str_contains($name, '\\') && self::isBuiltIn($name)) {
            return in_array(strtolower($name), self::DATABASE_CLASSES, true) ? 'uses the database class ' . $name : null;
        }

        return 'names ' . $name . ', outside the allowed namespaces';
    }

    private static function isBuiltIn(string $name): bool
    {
        foreach ([
            static fn (): bool => class_exists($name, false) && (new \ReflectionClass($name))->isInternal(),
            static fn (): bool => interface_exists($name, false) && (new \ReflectionClass($name))->isInternal(),
            static fn (): bool => trait_exists($name, false) && (new \ReflectionClass($name))->isInternal(),
            static fn (): bool => enum_exists($name, false) && (new \ReflectionClass($name))->isInternal(),
            static fn (): bool => function_exists($name) && (new \ReflectionFunction($name))->isInternal(),
            static fn (): bool => defined($name) && array_key_exists($name, get_defined_constants(true)['user'] ?? []) === false,
        ] as $check) {
            if ($check()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function functionProblems(string $call): array
    {
        $found = [];
        $matches = static function (string $name, array $names, array $prefixes): bool {
            if (in_array($name, $names, true)) {
                return true;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }

            return false;
        };
        if ($matches($call, self::NETWORK_FUNCTIONS, self::NETWORK_PREFIXES)) {
            $found[] = 'calls the network function ' . $call . '()';
        }
        if ($matches($call, [], self::DATABASE_PREFIXES)) {
            $found[] = 'calls the database function ' . $call . '()';
        }
        if (in_array($call, self::HEADER_FUNCTIONS, true)) {
            $found[] = 'sends a header with ' . $call . '()';
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
            . "\$w = new \\TiffinDB\\Core\\Io\\Csv\\CsvWriter(); \$d = C\\Sub::class; \$n = 'TiffinDB\\\\Core\\\\Io\\\\Csv\\\\CsvDialect';\n"];
        foreach ([
            'Select a sheet from the workbook', 'Delete from the list', 'Update the table', 'Create table from the first sheet',
            'Drop table names that repeat', 'Insert into the project', 'Values are read as text', 'Where the heading is empty, use Column n',
            'Choose where to put the values (one per row)', 'Pragma', 'Select rows from Sheet1 to import', 'Add a column with each record\'s number',
        ] as $label) {
            yield 'English label: ' . $label => [self::HEAD . "\$label = " . var_export($label, true) . ";\n"];
        }
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

    #[DataProvider('refused')]
    public function testRefused(string $source, string $expected): void
    {
        $problems = self::problems(self::PATH, $source);
        self::assertNotSame([], $problems);
        $matched = array_filter($problems, static fn (string $p): bool => str_contains($p, $expected));
        self::assertNotSame([], $matched, implode('; ', $problems));
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
