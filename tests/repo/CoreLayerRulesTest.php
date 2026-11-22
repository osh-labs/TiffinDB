<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-REPO-9: the dependency rule between core areas (ADR 0012 decision 1,
 * M1 spec section 2.1, amended by ADR 0014 decision 5 and M2 spec
 * section 2.1, and by ADR 0015 decision 5 and M3 spec section 2.1, T-PLG-7),
 * and no direct calls to clock or randomness functions under src/Core/
 * (section 3.2).
 *
 * The plugin contract, src/Core/Io/Contract/, uses nothing but PHP and
 * itself: every TiffinDB name in those files is under
 * TiffinDB\Core\Io\Contract\ (M3 spec section 2.1).
 *
 * The core CSV reader and writer, src/Core/Io/Csv/, which plugins may
 * name (P143), use nothing but PHP, themselves, the plugin contract, and
 * the one class TiffinDB\Core\Error\Text, so that what plugins reach
 * through them stays small (M3 spec section 2.1). Only files that exist
 * are checked.
 *
 * For each PHP file under src/Core/<Area>/, every name of the form
 * TiffinDB\Core\<Other>\... must name the area itself or one in its
 * allowed list. Names are read with token_get_all: `use` statements
 * (including group use), qualified and fully qualified names in code, and
 * class names written in string literals. Comments are not checked here
 * (LayerRulesTest checks them for the web layer).
 *
 * The global functions random_bytes, random_int, mt_rand, rand, time, date,
 * and microtime may be called only in src/Core/Storage/SecureRandom.php and
 * src/Core/Storage/SystemClock.php. A call is a T_STRING, or a
 * T_NAME_FULLY_QUALIFIED such as `\time`, followed by `(` and not preceded
 * by `->`, `?->`, `::`, `function`, or `new`.
 *
 * Aliases would let a file reach another area or a forbidden function
 * without naming it, so these are refused as well: any `use` of, or any
 * name equal to, `TiffinDB` or `TiffinDB\Core` without an area after it
 * (for example `use TiffinDB\Core as C;`), and `use function` of a
 * forbidden function, with or without an alias.
 *
 * Known limits: the rule reads tokens, not running code, so it cannot see
 * names built at run time (concatenated or interpolated strings, for
 * example `'TiffinDB\\Core\\' . $area`), string callables such as
 * `call_user_func('time')` or `$f = 'time'; $f();`, or functions reached
 * through reflection. Review covers those.
 */
#[CoversNothing]
final class CoreLayerRulesTest extends TestCase
{
    private const CORE = 'src/Core';

    /** Area => areas it may use (besides itself). */
    public const ALLOWED = [
        'Error' => [],
        'Storage' => ['Error'],
        'System' => ['Storage', 'Error'],
        'Auth' => ['System', 'Storage', 'Error'],
        'Schema' => ['Storage', 'Error'],
        'Query' => ['Schema', 'Storage', 'Error'],
        'Forms' => ['Schema', 'Storage', 'Error'],
        'Install' => ['Auth', 'System', 'Storage', 'Error'],
        'Io' => ['Schema', 'Query', 'Forms', 'Storage', 'Error'],
        'App' => ['Error', 'Storage', 'System', 'Auth', 'Schema', 'Query', 'Forms', 'Io'],
    ];

    /** The plugin contract's folder: names in it stay inside its own namespace. */
    public const CONTRACT = 'src/Core/Io/Contract/';
    public const CONTRACT_NAMESPACE = 'TiffinDB\\Core\\Io\\Contract\\';

    /** The core CSV folder (P143): names in it stay in itself, the contract, and CSV_MAY_USE. */
    public const CSV = 'src/Core/Io/Csv/';
    public const CSV_NAMESPACE = 'TiffinDB\\Core\\Io\\Csv\\';
    public const CSV_MAY_USE = ['TiffinDB\\Core\\Error\\Text'];

    public const FORBIDDEN_FUNCTIONS = ['random_bytes', 'random_int', 'mt_rand', 'rand', 'time', 'date', 'microtime'];

    /** Files that may call the forbidden functions. */
    public const FUNCTION_EXEMPT = ['src/Core/Storage/SecureRandom.php', 'src/Core/Storage/SystemClock.php'];

    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        $this->temp = null;
    }

    // ---------------------------------------------------------------- rules

    /**
     * Every TiffinDB\Core\<Area> name in the source, as the area names
     * (first segment after Core), in order of appearance, duplicates kept.
     *
     * @return list<string>
     */
    public static function coreAreasReferenced(string $source): array
    {
        $areas = [];
        foreach (self::namesIn($source) as $name) {
            if (preg_match('/^\\\\?TiffinDB\\\\Core\\\\([A-Za-z_][A-Za-z0-9_]*)/i', $name, $m) === 1) {
                $areas[] = $m[1];
            }
        }

        return $areas;
    }

    /**
     * Fully expanded names in code (group uses expanded) and in string literals.
     *
     * @return list<string>
     */
    public static function namesIn(string $source): array
    {
        $tokens = self::significantTokens($source);
        $names = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = str_replace('\\\\', '\\', $token[1]);
                if (preg_match_all('/TiffinDB\\\\Core\\\\[A-Za-z0-9_\\\\]+/i', $text, $found) > 0) {
                    array_push($names, ...$found[0]);
                }

                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // Group use: <prefix> \ { a, b\c, ... }
            $next = $tokens[$i + 1] ?? null;
            $brace = $tokens[$i + 2] ?? null;
            if (is_array($next) && $next[0] === T_NS_SEPARATOR && $brace === '{') {
                $prefix = rtrim($token[1], '\\');
                $i += 3;
                for (; $i < $count && $tokens[$i] !== '}'; $i++) {
                    $inner = $tokens[$i];
                    if (is_array($inner) && in_array($inner[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $previous = $tokens[$i - 1];
                        // An alias after `as` is not a name to check.
                        if (!(is_array($previous) && $previous[0] === T_AS)) {
                            $names[] = $prefix . '\\' . $inner[1];
                        }
                    }
                }

                continue;
            }
            $names[] = $token[1];
        }

        return $names;
    }

    /**
     * Names in code equal to `TiffinDB` or `TiffinDB\Core` (any case,
     * optional leading backslash), normalised, unique, in order.
     *
     * @return list<string>
     */
    public static function bareNamespaces(string $source): array
    {
        $found = [];
        foreach (self::namesIn($source) as $name) {
            if (preg_match('/^\\\\?TiffinDB(\\\\Core)?\\\\?$/i', $name, $m) === 1) {
                $bare = isset($m[1]) && $m[1] !== '' ? 'TiffinDB\\Core' : 'TiffinDB';
                if (!in_array($bare, $found, true)) {
                    $found[] = $bare;
                }
            }
        }

        return $found;
    }

    /**
     * Forbidden global functions imported with `use function`, aliased or
     * not, as lower-case names in order. Names inside a group
     * (`use function A\{b}`) are namespaced, not global, and are skipped.
     *
     * @return list<string>
     */
    public static function forbiddenFunctionImports(string $source): array
    {
        $tokens = self::significantTokens($source);
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if (!is_array($token) || $token[0] !== T_USE || !is_array($next) || $next[0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 2; $j < $count && $tokens[$j] !== ';'; $j++) {
                if ($tokens[$j] === '{') {
                    break;
                }
                $name = $tokens[$j];
                $previous = $tokens[$j - 1];
                if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    || (is_array($previous) && $previous[0] === T_AS)) {
                    continue;
                }
                $function = strtolower(ltrim($name[1], '\\'));
                if (in_array($function, self::FORBIDDEN_FUNCTIONS, true)) {
                    $found[] = $function;
                }
            }
            $i = $j;
        }

        return $found;
    }

    /**
     * Calls to forbidden global functions, as lower-case names in order.
     *
     * @return list<string>
     */
    public static function forbiddenCalls(string $source): array
    {
        $tokens = self::significantTokens($source);
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            if (!in_array($name, self::FORBIDDEN_FUNCTIONS, true) || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array(
                $previous[0],
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW],
                true,
            )) {
                continue;
            }
            $found[] = $name;
        }

        return $found;
    }

    /**
     * Problems in one file, given its path relative to the root.
     *
     * @return list<string>
     */
    public static function problems(string $relative, string $source): array
    {
        $parts = explode('/', $relative);
        if (count($parts) < 4 || $parts[0] . '/' . $parts[1] !== self::CORE) {
            return ['is not inside an area folder under ' . self::CORE . '/'];
        }
        $area = $parts[2];
        if (!array_key_exists($area, self::ALLOWED)) {
            return ["is in an area the dependency table does not list ({$area})"];
        }
        $found = [];
        $allowed = array_map('strtolower', [$area, ...self::ALLOWED[$area]]);
        foreach (array_unique(self::coreAreasReferenced($source)) as $other) {
            if (!in_array(strtolower($other), $allowed, true)) {
                $found[] = "uses TiffinDB\\Core\\{$other}, which {$area} may not use";
            }
        }
        foreach (self::bareNamespaces($source) as $bare) {
            $found[] = "names {$bare} without an area";
        }
        if (str_starts_with($relative, self::CONTRACT)) {
            foreach (array_unique(self::namesIn($source)) as $name) {
                $plain = ltrim($name, '\\');
                $inside = stripos($plain, self::CONTRACT_NAMESPACE) === 0 || strcasecmp($plain, rtrim(self::CONTRACT_NAMESPACE, '\\')) === 0;
                if (stripos($plain, 'TiffinDB\\') === 0 && !$inside) {
                    $found[] = "names {$plain} outside the plugin contract";
                }
            }
        }
        if (str_starts_with($relative, self::CSV)) {
            foreach (array_unique(self::namesIn($source)) as $name) {
                $plain = ltrim($name, '\\');
                $inside = false;
                foreach ([self::CSV_NAMESPACE, self::CONTRACT_NAMESPACE] as $namespace) {
                    $inside = $inside || stripos($plain, $namespace) === 0 || strcasecmp($plain, rtrim($namespace, '\\')) === 0;
                }
                foreach (self::CSV_MAY_USE as $class) {
                    $inside = $inside || strcasecmp($plain, $class) === 0;
                }
                if (stripos($plain, 'TiffinDB\\') === 0 && !$inside) {
                    $found[] = "names {$plain}, which the core CSV classes may not use";
                }
            }
        }
        if (!in_array($relative, self::FUNCTION_EXEMPT, true)) {
            foreach (array_unique(self::forbiddenCalls($source)) as $function) {
                $found[] = "calls {$function}() directly";
            }
            foreach (array_unique(self::forbiddenFunctionImports($source)) as $function) {
                $found[] = "imports {$function}() with use function";
            }
        }

        return $found;
    }

    /**
     * Violations in every PHP file below `<root>/src/Core`, as "path: reason".
     *
     * @return list<string>
     */
    public static function violationsBelow(string $root): array
    {
        $core = $root . '/' . self::CORE;
        if (!is_dir($core)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($files, SORT_STRING);
        $found = [];
        foreach ($files as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            if ($source === false) {
                throw new RuntimeException("Cannot read: {$relative}");
            }
            foreach (self::problems($relative, $source) as $problem) {
                $found[] = "{$relative}: {$problem}";
            }
        }

        return $found;
    }

    /**
     * Tokens without white space and comments.
     *
     * @return list<array{int, string, int}|string>
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    // ------------------------------------------------------- the repository

    public function testRepositoryCoreFollowsTheRules(): void
    {
        self::assertDirectoryExists(RepoFiles::path(self::CORE), 'src/Core/ exists from M1.');
        self::assertSame([], self::violationsBelow(RepoFiles::root()));
    }

    public function testExemptFilesExist(): void
    {
        foreach (self::FUNCTION_EXEMPT as $path) {
            self::assertFileExists(RepoFiles::path($path));
        }
    }

    // ------------------------------------------------------------ fixtures

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function sources(): iterable
    {
        $head = static fn (string $area): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Core\\{$area};\n\n";

        yield 'storage uses error' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core\\Error\\Busy;\n", []];
        yield 'storage uses itself' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core\\Storage\\Keys;\n", []];
        yield 'error uses nothing' => ['src/Core/Error/A.php', $head('Error') . "final class A {}\n", []];
        yield 'error uses storage' => ['src/Core/Error/A.php', $head('Error') . "use TiffinDB\\Core\\Storage\\Keys;\n", ['uses TiffinDB\\Core\\Storage, which Error may not use']];
        yield 'storage uses schema' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core\\Schema\\Identifier;\n", ['uses TiffinDB\\Core\\Schema, which Storage may not use']];
        yield 'fully qualified in code' => ['src/Core/Schema/A.php', $head('Schema') . "\$x = new \\TiffinDB\\Core\\Auth\\Actor();\n", ['uses TiffinDB\\Core\\Auth, which Schema may not use']];
        yield 'qualified in code' => ['src/Core/Schema/A.php', $head('Schema') . "\$x = TiffinDB\\Core\\System\\AuditLog::class;\n", ['uses TiffinDB\\Core\\System, which Schema may not use']];
        yield 'class name in a string' => ['src/Core/Query/A.php', $head('Query') . "\$c = 'TiffinDB\\\\Core\\\\App\\\\Operations';\n", ['uses TiffinDB\\Core\\App, which Query may not use']];
        yield 'group use' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core\\{Error\\Busy, Auth\\Actor};\n", ['uses TiffinDB\\Core\\Auth, which Storage may not use']];
        yield 'group use from the root' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\{Core\\Error\\Busy, Core\\System\\Meta};\n", ['uses TiffinDB\\Core\\System, which Storage may not use']];
        yield 'group use with alias' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core\\{Error\\Busy as Install};\n", []];
        yield 'comment is not checked here' => ['src/Core/Storage/A.php', $head('Storage') . "// See TiffinDB\\Core\\App\\Operations.\n", []];
        yield 'app uses everything allowed' => ['src/Core/App/A.php', $head('App') . "use TiffinDB\\Core\\Error\\A;\nuse TiffinDB\\Core\\Storage\\B;\nuse TiffinDB\\Core\\System\\C;\nuse TiffinDB\\Core\\Auth\\D;\nuse TiffinDB\\Core\\Schema\\E;\nuse TiffinDB\\Core\\Query\\F;\n", []];
        yield 'app uses install' => ['src/Core/App/A.php', $head('App') . "use TiffinDB\\Core\\Install\\Installer;\n", ['uses TiffinDB\\Core\\Install, which App may not use']];
        yield 'app uses forms' => ['src/Core/App/A.php', $head('App') . "use TiffinDB\\Core\\Forms\\FormStore;\n", []];
        // M5 spec section 2.1 (T-APP-174): the form operations use the parser, serializer, editor, and report.
        yield 'app uses the form editor' => ['src/Core/App/A.php', $head('App') . "use TiffinDB\\Core\\Forms\\FormParser;\nuse TiffinDB\\Core\\Forms\\FormEditor;\nuse TiffinDB\\Core\\Forms\\FormReport;\n", []];
        yield 'forms uses system' => ['src/Core/Forms/A.php', $head('Forms') . "use TiffinDB\\Core\\System\\AuditAction;\n", ['uses TiffinDB\\Core\\System, which Forms may not use']];
        yield 'forms uses schema, storage, error' => ['src/Core/Forms/A.php', $head('Forms') . "use TiffinDB\\Core\\Schema\\Schema;\nuse TiffinDB\\Core\\Storage\\Database;\nuse TiffinDB\\Core\\Error\\Text;\n", []];
        yield 'forms uses query' => ['src/Core/Forms/A.php', $head('Forms') . "use TiffinDB\\Core\\Query\\Records;\n", ['uses TiffinDB\\Core\\Query, which Forms may not use']];
        yield 'forms uses app' => ['src/Core/Forms/A.php', $head('Forms') . "\$x = \\TiffinDB\\Core\\App\\Context::class;\n", ['uses TiffinDB\\Core\\App, which Forms may not use']];
        yield 'query uses forms' => ['src/Core/Query/A.php', $head('Query') . "use TiffinDB\\Core\\Forms\\FormDocument;\n", ['uses TiffinDB\\Core\\Forms, which Query may not use']];
        yield 'schema uses forms' => ['src/Core/Schema/A.php', $head('Schema') . "use TiffinDB\\Core\\Forms\\FormStore;\n", ['uses TiffinDB\\Core\\Forms, which Schema may not use']];
        yield 'io uses schema, query, forms, storage, error' => ['src/Core/Io/A.php', $head('Io') . "use TiffinDB\\Core\\Schema\\Ddl;\nuse TiffinDB\\Core\\Query\\Records;\nuse TiffinDB\\Core\\Forms\\FormStore;\nuse TiffinDB\\Core\\Storage\\Fs;\nuse TiffinDB\\Core\\Error\\Text;\n", []];
        yield 'io uses system' => ['src/Core/Io/A.php', $head('Io') . "use TiffinDB\\Core\\System\\AuditLog;\n", ['uses TiffinDB\\Core\\System, which Io may not use']];
        yield 'io uses auth' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "use TiffinDB\\Core\\Auth\\Actor;\n", ['uses TiffinDB\\Core\\Auth, which Io may not use', 'names TiffinDB\\Core\\Auth\\Actor, which the core CSV classes may not use']];
        yield 'io uses app' => ['src/Core/Io/Archive/A.php', $head('Io\\Archive') . "\$x = \\TiffinDB\\Core\\App\\Context::class;\n", ['uses TiffinDB\\Core\\App, which Io may not use']];
        yield 'io uses install' => ['src/Core/Io/Plugin/A.php', $head('Io\\Plugin') . "use TiffinDB\\Core\\Install\\Installer;\n", ['uses TiffinDB\\Core\\Install, which Io may not use']];
        yield 'app uses io' => ['src/Core/App/A.php', $head('App') . "use TiffinDB\\Core\\Io\\ImportJob;\n", []];
        yield 'query uses io' => ['src/Core/Query/A.php', $head('Query') . "use TiffinDB\\Core\\Io\\ImportJob;\n", ['uses TiffinDB\\Core\\Io, which Query may not use']];
        yield 'system uses io' => ['src/Core/System/A.php', $head('System') . "use TiffinDB\\Core\\Io\\Plugin\\PluginInfo;\n", ['uses TiffinDB\\Core\\Io, which System may not use']];
        yield 'csv uses itself, the contract, and Text' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "use TiffinDB\\Core\\Io\\Csv\\CsvDialect;\nuse TiffinDB\\Core\\Io\\Contract\\PluginFailure;\nuse TiffinDB\\Core\\Error\\Text;\n", []];
        yield 'csv uses another io class' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "use TiffinDB\\Core\\Io\\IoLimits;\n", ['names TiffinDB\\Core\\Io\\IoLimits, which the core CSV classes may not use']];
        yield 'csv uses the plugin loader' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "\$x = \\TiffinDB\\Core\\Io\\Plugin\\PluginLoader::class;\n", ['names TiffinDB\\Core\\Io\\Plugin\\PluginLoader, which the core CSV classes may not use']];
        yield 'csv uses storage' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "use TiffinDB\\Core\\Storage\\Fs;\n", ['names TiffinDB\\Core\\Storage\\Fs, which the core CSV classes may not use']];
        yield 'csv uses another error class' => ['src/Core/Io/Csv/A.php', $head('Io\\Csv') . "use TiffinDB\\Core\\Error\\Problem;\n", ['names TiffinDB\\Core\\Error\\Problem, which the core CSV classes may not use']];
        yield 'csv look-alike folder is not the csv rule' => ['src/Core/Io/CsvEvil/A.php', $head('Io\\CsvEvil') . "use TiffinDB\\Core\\Storage\\Fs;\n", []];
        yield 'contract uses itself' => ['src/Core/Io/Contract/A.php', $head('Io\\Contract') . "use TiffinDB\\Core\\Io\\Contract\\Settings;\n\$e = new \\RuntimeException();\n", []];
        yield 'contract uses io' => ['src/Core/Io/Contract/A.php', $head('Io\\Contract') . "use TiffinDB\\Core\\Io\\ImportJob;\n", ['names TiffinDB\\Core\\Io\\ImportJob outside the plugin contract']];
        yield 'contract uses error' => ['src/Core/Io/Contract/A.php', $head('Io\\Contract') . "\$x = \\TiffinDB\\Core\\Error\\Text::class;\n", ['names TiffinDB\\Core\\Error\\Text outside the plugin contract']];
        yield 'contract names a plugin in a string' => ['src/Core/Io/Contract/A.php', $head('Io\\Contract') . "\$c = 'TiffinDB\\\\Core\\\\Storage\\\\Fs';\n", ['names TiffinDB\\Core\\Storage\\Fs outside the plugin contract']];
        yield 'unknown area' => ['src/Core/Reports/A.php', $head('Reports'), ['is in an area the dependency table does not list (Reports)']];
        yield 'file directly in core' => ['src/Core/A.php', "<?php\n", ['is not inside an area folder under src/Core/']];

        yield 'time call' => ['src/Core/System/A.php', $head('System') . "\$t = time();\n", ['calls time() directly']];
        yield 'fully qualified date call' => ['src/Core/System/A.php', $head('System') . "\$d = \\date('Y');\n", ['calls date() directly']];
        yield 'spaced call' => ['src/Core/System/A.php', $head('System') . "\$r = random_bytes (16);\n", ['calls random_bytes() directly']];
        yield 'upper-case call' => ['src/Core/System/A.php', $head('System') . "\$r = MT_RAND();\n", ['calls mt_rand() directly']];
        yield 'every forbidden function' => ['src/Core/Auth/A.php', $head('Auth') . "random_bytes(1); random_int(1, 2); mt_rand(); rand(); time(); date('c'); microtime(true);\n",
            ['calls random_bytes() directly', 'calls random_int() directly', 'calls mt_rand() directly', 'calls rand() directly', 'calls time() directly', 'calls date() directly', 'calls microtime() directly']];
        yield 'method calls are fine' => ['src/Core/Auth/A.php', $head('Auth') . "\$users->update(); \$x->date(); \$x?->time(); Users::date(); \$y = new Date();\n", []];
        yield 'method declaration is fine' => ['src/Core/Auth/A.php', $head('Auth') . "final class A { public function date(): void {} public static function time(): int { return 1; } }\n", []];
        yield 'similar names are fine' => ['src/Core/Auth/A.php', $head('Auth') . "validate(); update(); dates(); timeout(); \$time = 1; \$date(1);\n", []];
        yield 'constant without call' => ['src/Core/Auth/A.php', $head('Auth') . "\$x = ['time' => 1, 'date' => 2]; \$f = 'time';\n", []];
        yield 'namespaced function is not global' => ['src/Core/Auth/A.php', $head('Auth') . "\$t = Clock\\time();\n", []];
        yield 'alias of the core namespace' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core as C;\n\$a = new C\\Auth\\Actor();\n", ['names TiffinDB\\Core without an area']];
        yield 'alias of the root namespace' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB as T;\n\$a = new T\\Core\\Auth\\Actor();\n", ['names TiffinDB without an area']];
        yield 'use of the core namespace without alias' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\Core;\n", ['names TiffinDB\\Core without an area']];
        yield 'fully qualified bare core, any case' => ['src/Core/Storage/A.php', $head('Storage') . "use \\tiffindb\\core as C;\n", ['names TiffinDB\\Core without an area']];
        yield 'group use of the core namespace' => ['src/Core/Storage/A.php', $head('Storage') . "use TiffinDB\\{Core as C};\n", ['names TiffinDB\\Core without an area']];
        yield 'use function with alias' => ['src/Core/Auth/A.php', $head('Auth') . "use function time as now;\n\$t = now();\n", ['imports time() with use function']];
        yield 'use function without alias' => ['src/Core/Auth/A.php', $head('Auth') . "use function \\random_bytes;\n", ['imports random_bytes() with use function']];
        yield 'use function list' => ['src/Core/Auth/A.php', $head('Auth') . "use function strlen, date as d, MICROTIME;\n", ['imports date() with use function', 'imports microtime() with use function']];
        yield 'use function of a namespaced function' => ['src/Core/Auth/A.php', $head('Auth') . "use function TiffinDB\\Core\\Auth\\time;\nuse function TiffinDB\\Core\\Auth\\{date};\n", []];
        yield 'use function of an allowed function' => ['src/Core/Auth/A.php', $head('Auth') . "use function strlen as len;\n", []];
        yield 'closure use is not an import' => ['src/Core/Auth/A.php', $head('Auth') . "\$f = function () use (\$time) { return \$time; };\n", []];
        yield 'exempt file' => ['src/Core/Storage/SecureRandom.php', $head('Storage') . "\$b = random_bytes(8);\n", []];
        yield 'exempt clock' => ['src/Core/Storage/SystemClock.php', $head('Storage') . "\$t = time();\n", []];
        yield 'exemption is by path' => ['src/Core/System/SecureRandom.php', $head('System') . "\$b = random_bytes(8);\n", ['calls random_bytes() directly']];
    }

    /** @param list<string> $expected */
    #[DataProvider('sources')]
    public function testRulesDetectViolations(string $relative, string $source, array $expected): void
    {
        self::assertSame($expected, self::problems($relative, $source));
    }

    public function testViolationsInATemporaryCoreTreeAreReportedByPath(): void
    {
        $this->temp = TempDir::create('tiffindb-core-layer-');
        $root = $this->temp->path();
        self::assertSame([], self::violationsBelow($root), 'No src/Core/ directory: nothing to report.');

        self::write($root, 'src/Core/Storage/Clean.php', "<?php\n\nnamespace TiffinDB\\Core\\Storage;\n\nuse TiffinDB\\Core\\Error\\Busy;\n");
        self::write($root, 'src/Core/Storage/SystemClock.php', "<?php\n\nnamespace TiffinDB\\Core\\Storage;\n\n\$t = time();\n");
        self::write($root, 'src/Core/Schema/Leaky.php', "<?php\n\nnamespace TiffinDB\\Core\\Schema;\n\nuse TiffinDB\\Core\\Auth\\Actor;\n\n\$n = microtime(true);\n");
        self::write($root, 'src/Core/Error/Upward.php', "<?php\n\nnamespace TiffinDB\\Core\\Error;\n\n\$k = new \\TiffinDB\\Core\\Storage\\Keys();\n");
        self::write($root, 'src/Core/Schema/notes.txt', "TiffinDB\\Core\\App is not PHP and not checked.\n");
        self::write($root, 'src/Web/Allowed.php', "<?php\n\nuse TiffinDB\\Core\\App\\Operations;\n\$t = time();\n");

        self::assertSame([
            'src/Core/Error/Upward.php: uses TiffinDB\\Core\\Storage, which Error may not use',
            'src/Core/Schema/Leaky.php: uses TiffinDB\\Core\\Auth, which Schema may not use',
            'src/Core/Schema/Leaky.php: calls microtime() directly',
        ], self::violationsBelow($root));
    }

    // -------------------------------------------------------------- helpers

    private static function write(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException("Cannot create the directory for {$relative}.");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write {$relative}.");
        }
    }
}
