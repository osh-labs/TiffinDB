<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * Every SQL string in the schema engine, the record layer, and the forms
 * (M1 spec section 3.3, ADR 0010 decision 2, M2 spec section 3.9, ADR
 * 0013 decision 9.10) is built only from string literals and from names
 * that come from Identifier. Read with token_get_all, these rules hold in
 * every PHP file under src/Core/Schema/ (except the field types, which
 * build no SQL), src/Core/Query/, src/Core/Forms/, and every file under
 * src/Core/Io/ at any depth (M3 spec section 2.2), except
 * src/Core/Query/Sql.php, the one file that joins fragment texts (it is
 * exempt by name and read by the design-tier review):
 *
 * 0. Default deny: nothing whose call target or variable cannot be
 *    resolved from the tokens: `->`, `?->`, or `::` followed by `$` or
 *    `{`; `$$` or `${`; extract, parse_str, compact, call_user_func,
 *    call_user_func_array, ::fromCallable; a string naming a query or PDO
 *    method or one of those functions as an array value or call argument;
 *    a first-class callable of a query method; eval; `new` with a class
 *    name that is not written out; and a call of a variable not shown to
 *    hold a Closure, or of any other expression; a two-element array
 *    `[$var, X]` whose X is not one string literal; any Reflection class;
 *    the include and require keywords; a namespace-relative name
 *    (`namespace\X`); closure rebinding (`->call(`, `->bindTo(`, `::bind(`);
 *    and `new Sql` (fragments come only from Sql::of, Sql::join, and
 *    ->wrap()). There are no exceptions.
 *    Method, function, and class names are compared ignoring case.
 * 1. No interpolated strings, heredocs, or nowdocs at all.
 * 2. No `.=`, and no sprintf, vsprintf, printf, implode, join, str_repeat,
 *    strtr, or str_replace: SQL is never assembled by string functions.
 * 3. The whole SQL argument of every `->run(`, `->all(`, `->one(`, and
 *    `->value(` call (up to its top-level `,` or `)`) is a `.` chain whose
 *    every operand is a string literal; a call `Identifier::<method>(...)`,
 *    `Database::placeholders(...)`, or `ColumnDefinition::of(...)` (class
 *    names resolved through the file's imports); or `$v->sql()` where the
 *    scan shows that $v holds a TableDef, FieldDef, RelationDef, or an
 *    Sql fragment (Core\Query\Sql): a parameter of that type, a by-value
 *    capture of one, or a local whose every assignment is a `new`, a static
 *    call (Sql::of, Sql::join), a `->` chain (`->wrap()`, a method declared
 *    to return the class), or a ternary whose two arms are such
 *    expressions of the same class, with declared types read by
 *    reflection, and which is never otherwise written, taken by
 *    reference, or passed to a function. The arguments of the allowed
 *    calls are not restricted: Identifier validates them against the key
 *    pattern at run time.
 *    3a. The first argument of every `Sql::of(` call follows rule 3 (Sql
 *        checks at run time that it holds one `?` per bound value).
 *    3b. `Sql::join(` takes as glue exactly one single-quoted literal
 *        among Sql::GLUES.
 *    3c. Every `->params()` call is `$v->params()` on a variable shown, as
 *        in rule 3, to hold an Sql fragment.
 * 4. The only variable that may be the whole SQL argument is a name listed
 *    in ALLOWED_VARIABLES for the file, and only as the loop variable of an
 *    enclosing `foreach (self::<CONST> as ...)` (or `self::<CONST>[...]`)
 *    in the same function that does not write it.
 * 5. In every other chain of `.` concatenations that holds an SQL keyword
 *    (in any letter case), the operands follow rule 3.
 * 6. No direct PDO calls (`->prepare(`, `->exec(`, `->query(`).
 *
 * Every value therefore reaches SQLite as a bound parameter. The negative
 * cases below check that the scan catches each kind of mistake.
 *
 * One file may use two rule 0 constructs, each exactly once (M3 spec
 * sections 2.2 and 4.3): src/Core/Io/Plugin/PluginLoader.php instantiates
 * the manifest's class (`variable-new`) and its per-plugin autoloader
 * requires the class file (`include`). RULE_EXEMPTIONS lists them by path;
 * every other rule applies to that file in full.
 */
#[CoversNothing]
final class SqlStringsTest extends TestCase
{
    /** File => loop variables that may be passed as SQL (rule 4). */
    public const ALLOWED_VARIABLES = [
        'src/Core/Schema/ProjectFile.php' => ['$statement'],                 // foreach (self::DDL as $statement)
        'src/Core/Schema/FormatMigrations.php' => ['$statement', '$pragma'], // STEPS and VERSION_PRAGMAS
        'src/Core/Io/ImportJob.php' => ['$statement'],                       // foreach (self::SCHEMA as $statement)
    ];

    /** Every rule 0 id. */
    private const RULE_IDS = [
        'dynamic-member', 'variable-variable', 'scope-rewriting', 'callable-invokers', 'callable-literal', 'eval', 'variable-new',
        'variable-call', 'array-callable', 'reflection', 'include', 'namespace-relative', 'closure-rebinding', 'fragment-new',
    ];

    /** File => rule 0 ids it may break, each exactly as often as given. */
    public const RULE_EXEMPTIONS = [
        'src/Core/Io/Plugin/PluginLoader.php' => ['variable-new' => 1, 'include' => 1],
    ];

    /** Functions that rewrite variables or call a callable given as data. */
    private const FORBIDDEN_FUNCTIONS = [
        'scope-rewriting' => ['extract', 'parse_str', 'compact'],
        'callable-invokers' => ['call_user_func', 'call_user_func_array', 'fromcallable'],
    ];
    private const CONTROL_HEADERS = [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CATCH, T_MATCH, T_FUNCTION, T_FN, T_USE, T_DECLARE];

    private const SQL_WORD = '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|PRAGMA|VACUUM|FROM|WHERE|INTO|VALUES|INDEX|TABLE|COLUMN|JOIN|ORDER|LIMIT|SET|IN|AND|OR|ON|NULL)\b/i';
    private const STRING_BUILDERS = ['sprintf', 'vsprintf', 'printf', 'implode', 'join', 'str_repeat', 'strtr', 'str_replace'];
    private const QUERY_METHODS = ['run', 'all', 'one', 'value'];
    private const PDO_METHODS = ['prepare', 'exec', 'query'];
    private const SQL_DEFS = ['TableDef.php', 'FieldDef.php', 'RelationDef.php'];
    private const DEF_CLASSES = ['TiffinDB\Core\Schema\TableDef', 'TiffinDB\Core\Schema\FieldDef', 'TiffinDB\Core\Schema\RelationDef'];
    /** The fragment class (M2 spec section 3.9); its file is the one exempt file. */
    public const SQL_CLASS = 'TiffinDB\Core\Query\Sql';
    public const EXEMPT_FILE = 'src/Core/Query/Sql.php';
    private const USE_SQL = "use TiffinDB\\Core\\Query\\Sql;\n";
    private const ASSIGN_OPERATORS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
        T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
    ];
    private const SYNTHETIC_HEADER = "<?php\nnamespace TiffinDB\\Core\\Schema;\nuse TiffinDB\\Core\\Storage\\Database;\n";

    /** @return list<string> repository-relative paths */
    public static function scannedFiles(): array
    {
        $files = [];
        foreach (glob(RepoFiles::path('src/Core/Schema') . '/*.php') ?: [] as $path) {
            if (basename($path) !== 'FieldTypes.php') {
                $files[] = 'src/Core/Schema/' . basename($path);
            }
        }
        foreach (['src/Core/Query', 'src/Core/Forms'] as $directory) {
            foreach (glob(RepoFiles::path($directory) . '/*.php') ?: [] as $path) {
                if ($directory . '/' . basename($path) !== self::EXEMPT_FILE) {
                    $files[] = $directory . '/' . basename($path);
                }
            }
        }
        if (is_dir(RepoFiles::path('src/Core/Io'))) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(RepoFiles::path('src/Core/Io'), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = 'src/Core/Io/' . str_replace('\\', '/', substr($file->getPathname(), strlen(RepoFiles::path('src/Core/Io')) + 1));
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        foreach (self::scannedFiles() as $file) {
            yield $file => [$file];
        }
    }

    public function testTheScanCoversTheEngine(): void
    {
        $files = self::scannedFiles();
        foreach (['Identifier.php', 'ProjectFile.php', 'FormatMigrations.php', 'Schema.php', 'SchemaEditor.php', 'ColumnDefinition.php', ...self::SQL_DEFS] as $name) {
            self::assertContains('src/Core/Schema/' . $name, $files);
        }
        foreach (['Trash.php', 'Records.php', 'ListSql.php', 'RecordVersion.php'] as $name) {
            self::assertContains('src/Core/Query/' . $name, $files);
        }
        foreach (['Ddl.php'] as $name) {
            self::assertContains('src/Core/Schema/' . $name, $files);
        }
        foreach (['ImportJob.php', 'Committer.php', 'ImportLog.php', 'Titles.php', 'ProjectExportSource.php', 'Contract/Importer.php'] as $name) {
            self::assertContains('src/Core/Io/' . $name, $files);
        }
        self::assertNotContains('src/Core/Schema/FieldTypes.php', $files);
        self::assertNotContains(self::EXEMPT_FILE, $files);
        $forms = glob(RepoFiles::path('src/Core/Forms') . '/*.php') ?: [];
        self::assertCount(count($forms), array_filter($files, static fn (string $f): bool => str_starts_with($f, 'src/Core/Forms/')));
        $sqlLiterals = 0;
        foreach ($files as $file) {
            foreach (self::tokens(RepoFiles::read($file)) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match(self::SQL_WORD, $token[1]) === 1) {
                    $sqlLiterals++;
                }
            }
        }
        self::assertGreaterThan(80, $sqlLiterals, 'The scan should see the SQL of the engine.');
    }

    #[DataProvider('files')]
    public function testSqlIsBuiltOnlyFromLiteralsAndIdentifiers(string $file): void
    {
        $exempt = self::RULE_EXEMPTIONS[$file] ?? [];
        $source = RepoFiles::read($file);
        self::assertSame([], self::violations($source, self::ALLOWED_VARIABLES[$file] ?? [], array_keys($exempt)));
        foreach ($exempt as $rule => $times) {
            self::assertSame($times, self::countRule($source, $rule), $file . ' uses (' . $rule . ') a different number of times.');
        }
    }

    /** How often one rule 0 construct occurs in a source. */
    private static function countRule(string $source, string $rule): int
    {
        $others = array_values(array_diff(self::RULE_IDS, [$rule]));

        return count(array_filter(
            self::violations($source, [], $others),
            static fn (string $v): bool => str_contains($v, 'forbidden construct (' . $rule . ')'),
        ));
    }

    public function testRuleExemptionsAreCountedExactly(): void
    {
        $loader = "<?php\nnamespace TiffinDB\\Core\\Io\\Plugin;\nfinal class L { public function f(string \$class, string \$file): object { require \$file; return new \$class(); } }\n";
        self::assertSame(1, self::countRule($loader, 'variable-new'));
        self::assertSame(1, self::countRule($loader, 'include'));
        $twice = "<?php\nfinal class L { public function f(string \$a, string \$b): array {\n\$x = new \$a();\n\$y = new \$b();\nreturn [\$x, \$y]; } }\n";
        self::assertSame(2, self::countRule($twice, 'variable-new'));
        self::assertSame(['src/Core/Io/Plugin/PluginLoader.php'], array_keys(self::RULE_EXEMPTIONS));
        foreach (self::RULE_EXEMPTIONS as $rules) {
            self::assertSame([], array_diff(array_keys($rules), self::RULE_IDS));
        }
    }

    public function testTheExemptFileOnlyComposesFragments(): void
    {
        // Sql.php is read by the design-tier review, not by the scan. It runs no query, makes no
        // PDO call, and holds no SQL keyword: it only checks, joins, and wraps fragment texts.
        $source = RepoFiles::read(self::EXEMPT_FILE);
        $tokens = self::tokens($source);
        foreach ($tokens as $i => $token) {
            if (self::isToken($token, T_STRING) && self::isMethodCall($tokens, $i)) {
                self::assertNotContains(strtolower($token[1]), [...self::QUERY_METHODS, ...self::PDO_METHODS], self::EXEMPT_FILE);
            }
            if (self::isToken($token, T_CONSTANT_ENCAPSED_STRING)) {
                self::assertDoesNotMatchRegularExpression('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|PRAGMA|FROM|WHERE)\b/i', $token[1], self::EXEMPT_FILE);
            }
        }
        self::assertSame(1, preg_match_all('/\bimplode\(/', $source), 'One join of fragment texts.');
        self::assertStringContainsString("public const GLUES = [' AND ', ' OR ', ', ', ' '];", $source);
        self::assertSame(\TiffinDB\Core\Query\Sql::GLUES, [' AND ', ' OR ', ', ', ' ']);
        // No other file under src/Core/ is exempt.
        foreach (glob(RepoFiles::path('src/Core') . '/*/Sql.php') ?: [] as $path) {
            self::assertSame(RepoFiles::path(self::EXEMPT_FILE), $path);
        }
    }

    public function testOnlyTheDefinitionClassesHaveSqlMethods(): void
    {
        foreach (self::scannedFiles() as $file) {
            $source = RepoFiles::read($file);
            $has = preg_match('/function sql\(\): string\s*\{(.*?)\n    \}/si', $source, $match) === 1;
            if (in_array(basename($file), self::SQL_DEFS, true)) {
                self::assertTrue($has, $file);
                self::assertMatchesRegularExpression('/^\s*(?:if \(!\$this->hasColumn\) \{\s*throw new \\\\LogicException\([^)]*\);\s*\}\s*)?return Identifier::(table|column|joinTable)\(\$this->key\);\s*$/', $match[1], $file);
            } else {
                self::assertStringNotContainsStringIgnoringCase('function sql(', $source, $file);
            }
        }
    }

    public function testNoCoreFunctionTakesAParameterByReference(): void
    {
        // Rule 3 treats passing a definition object to a method or constructor as a read.
        $files = glob(RepoFiles::path('src/Core') . '/*/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $path) {
            foreach (self::context((string) file_get_contents($path))['scopes'] as $scope) {
                foreach ($scope['params'] as $name => $param) {
                    self::assertFalse($param['byRef'], $path . ' ' . $name);
                }
            }
        }
    }

    // ------------------------------------------------------------ the scanner on synthetic code

    /** @return iterable<string, array{string, string}> */
    public static function badSources(): iterable
    {
        yield 'variable concatenated' => ['$db->run(\'SELECT * FROM \' . $name);', 'operand'];
        yield 'variable in the middle' => ['$db->run(\'SELECT \' . Identifier::column($k) . \' FROM t WHERE x = \' . $value);', 'operand'];
        yield 'array element' => ['$db->all(\'SELECT \' . $row[\'name\'] . \' FROM t\');', 'operand'];
        yield 'constant' => ['$db->run(\'SELECT 1 FROM \' . self::TABLE);', 'operand'];
        yield 'method other than sql' => ['$db->run(\'SELECT 1 FROM \' . $table->name());', 'operand'];
        yield 'property' => ['$db->run(\'DELETE FROM \' . $table->name);', 'operand'];
        yield 'other static class' => ['$db->run(\'DROP TABLE \' . Other::table($k));', 'operand'];
        yield 'other Database method' => ['$db->run(\'SELECT \' . Database::quote($k));', 'operand'];
        yield 'function call' => ['$db->run(\'SELECT \' . strtolower($k) . \' FROM t\');', 'operand'];
        yield 'cast' => ['$db->run(\'PRAGMA user_version = \' . (string) $v);', 'operand'];
        yield 'interpolation' => ['$db->run("SELECT * FROM $name");', 'interpolated'];
        yield 'braced interpolation' => ['$db->run("SELECT * FROM {$t->name}");', 'interpolated'];
        yield 'heredoc' => ["\$db->run(<<<SQL\nSELECT 1\nSQL);", 'heredoc'];
        yield 'nowdoc' => ["\$db->run(<<<'SQL'\nSELECT 1\nSQL);", 'heredoc'];
        yield 'variable as SQL' => ['$db->run($sql);', 'variable'];
        yield 'variable as SQL with this' => ['$this->db->value($query, [1]);', 'variable'];
        yield 'concatenate assign' => ['$s = \'SELECT 1\'; $s .= $x;', 'concatenate'];
        yield 'sprintf' => ['$db->run(sprintf(\'SELECT %s\', $x));', 'sprintf'];
        yield 'implode' => ['$db->run(\'SELECT \' . implode(\', \', $cols));', 'implode'];
        yield 'PDO exec' => ['$pdo->exec(\'DROP TABLE x\');', 'PDO'];
        yield 'ternary of variables' => ['$db->run($a ? $x : \'SELECT 1\');', 'variable'];
        // Concatenations whose literals hold no SQL word.
        yield 'empty literal and a variable' => ['$db->run(\'\' . $sql);', 'operand'];
        yield 'space and a variable' => ['$db->run(\' \' . $name);', 'operand'];
        yield 'identifier and a variable' => ['$db->run(Identifier::table($k) . $tail);', 'operand'];
        yield 'empty literal and a call' => ['$db->run(\'\' . array_reduce($parts, fn ($c, $p) => $c . $p, \'\'));', 'operand'];
        // SQL words in any letter case.
        yield 'lower-case SQL' => ['$db->run(\'select * from \' . $t);', 'operand'];
        yield 'lower-case SQL outside a call' => ['$s = \'delete from \' . $t;', 'operand'];
        // sql() only on receivers shown to be definition objects.
        yield 'sql() of an untyped variable' => ['$db->run(\'SELECT * FROM \' . $request->sql());', 'operand'];
        yield 'sql() of another class' => ['function f(Request $request): void { $db->run(\'SELECT * FROM \' . $request->sql()); }', 'operand'];
        yield 'sql() of an untyped parameter' => ['function f($table): void { $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() of a property' => ['function f(TableDef $table): void { $db->run(\'DROP TABLE \' . $this->table->sql()); }', 'operand'];
        yield 'sql() of a reassigned parameter' => ['function f(TableDef $table, $x): void { $table = $x; $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() of a parameter passed by reference' => ['function f(TableDef &$table): void { $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() after a by-reference function' => ['function f(TableDef $table): void { settype($table, \'string\'); $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() of a loop variable' => ['function f(array $tables): void { foreach ($tables as $table) { $db->run(\'DROP TABLE \' . $table->sql()); } }', 'operand'];
        yield 'sql() of an untyped capture' => ['function f($table): void { $g = function () use ($table): void { $db->run(\'DROP TABLE \' . $table->sql()); }; }', 'operand'];
        yield 'sql() of a capture by reference' => ['function f(TableDef $table): void { $g = function () use (&$table): void { $db->run(\'DROP TABLE \' . $table->sql()); }; }', 'operand'];
        yield 'sql() of a local from an untyped call' => ['function f(Schema $schema): void { $table = $schema->tables()[0]; $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() of a local assigned twice' => ['function f(Schema $schema, $x): void { $table = $schema->table(\'k\'); $table = $x; $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'sql() of a destructured local' => ['function f(Schema $schema, $x): void { $table = $schema->table(\'k\'); [$table] = $x; $db->run(\'DROP TABLE \' . $table->sql()); }', 'operand'];
        yield 'aliased Identifier' => ["use Other\\Names as Identifier;\n\$db->run('DROP TABLE ' . Identifier::table(\$k));", 'operand'];
        // The loop variables only as the loop variable of a foreach over a constant.
        yield 'allowed name assigned' => ['$statement = $name; $db->run($statement);', 'variable'];
        yield 'allowed name assigned in a function' => ['function f($name): void { $pragma = $name; $db->run($pragma); }', 'variable'];
        yield 'allowed name over a variable list' => ['foreach ($list as $statement) { $db->run($statement); }', 'variable'];
        yield 'allowed name over a method result' => ['foreach ($this->steps() as $statement) { $db->run($statement); }', 'variable'];
        yield 'allowed name reassigned in the loop' => ['foreach (self::DDL as $statement) { $statement = $x; $db->run($statement); }', 'variable'];
        yield 'allowed name after the loop' => ['foreach (self::DDL as $statement) { } $db->run($statement);', 'variable'];
        yield 'allowed name by reference' => ['foreach (self::DDL as &$statement) { $db->run($statement); }', 'variable'];
        yield 'allowed name rebound by an inner loop' => ['foreach (self::DDL as $statement) { foreach ($x as $statement) { $db->run($statement); } }', 'variable'];
        yield 'allowed name in a nested function' => ['foreach (self::DDL as $statement) { $g = fn () => $db->run($statement); }', 'variable'];
        yield 'other name over a constant' => ['foreach (self::DDL as $sql) { $db->run($sql); }', 'variable'];
        // Method names in any letter case.
        yield 'query method in capitals' => ['$db->RUN($x);', 'variable'];
        yield 'query method in mixed case' => ['$db->Value($x);', 'variable'];
        yield 'query method in capitals with a concatenation' => ['$db->All(\' \' . $x);', 'operand'];
        yield 'PDO method in capitals' => ['$pdo->EXEC(\'DROP TABLE x\');', 'PDO'];
        // Calls the scan cannot resolve (rule 0).
        yield 'method named by a variable' => ['$m = \'run\'; $db->$m($x);', 'dynamic-member'];
        yield 'method named by an expression' => ['$db->{\'run\'}($x);', 'dynamic-member'];
        yield 'array callable to array_map' => ['array_map([$db, \'run\'], $x);', 'callable-literal'];
        yield 'array callable to call_user_func' => ['call_user_func([$db, \'run\'], $x);', 'callable-invokers'];
        yield 'array callable to Closure::fromCallable' => ['\\Closure::fromCallable([$db, \'run\']);', 'callable-invokers'];
        // Variable rewriting that could retype a trusted parameter.
        yield 'extract' => ['function f(TableDef $t, array $a): void { extract($a); $db->run(\'DROP TABLE \' . $t->sql()); }', 'scope-rewriting'];
        yield 'parse_str' => ['function f(TableDef $t, string $q): void { parse_str($q); $db->run(\'DROP TABLE \' . $t->sql()); }', 'scope-rewriting'];
        yield 'variable variable' => ['function f(TableDef $t, string $n, $x): void { $$n = $x; $db->run(\'DROP TABLE \' . $t->sql()); }', 'variable-variable'];
        yield 'array_map with a concatenated method name' => ['array_map([$db, \'ru\' . \'n\'], $statements);', 'array-callable'];
        yield 'usort with a concatenated method name' => ['usort($x, [$db, \'r\'.\'un\']);', 'array-callable'];
        yield 'reflection call' => ['(new \ReflectionMethod($db, \'r\' . \'un\'))->invoke($db, $sql);', 'reflection'];
        yield 'included code' => ['require $path;', 'include'];
        yield 'braced variable variable' => ['function f(TableDef $t, string $k, $x): void { ${$k} = $x; $db->run(\'DROP TABLE \' . $t->sql()); }', 'variable-variable'];
        // Sql fragments (M2 spec section 3.9, T-REC-14).
        yield 'Sql::of with a variable' => [self::USE_SQL . 'function f(string $text): void { $s = Sql::of($text); }', 'Sql::of()'];
        yield 'Sql::of with a variable and values' => [self::USE_SQL . 'function f(string $text): void { $s = Sql::of($text, [1]); }', 'Sql::of()'];
        yield 'Sql::of with a concatenated variable' => [self::USE_SQL . 'function f(string $name): void { $s = Sql::of(\'SELECT * FROM \' . $name); }', 'Sql::of()'];
        yield 'Sql::of with a variable and no SQL word' => [self::USE_SQL . 'function f(string $col): void { $s = Sql::of(\'r.\' . $col . \' = ?\', [1]); }', 'Sql::of()'];
        yield 'Sql::of with a ternary of literals' => [self::USE_SQL . 'function f(bool $d): void { $s = Sql::of($d ? \'DESC\' : \'ASC\'); }', 'Sql::of()'];
        yield 'Sql::of with a constant' => [self::USE_SQL . 'function f(): void { $s = Sql::of(self::ORDER); }', 'Sql::of()'];
        yield 'Sql::of with a cast' => [self::USE_SQL . 'function f(int $n): void { $s = Sql::of(\'LIMIT \' . (string) $n); }', 'Sql::of()'];
        yield 'Sql::of with a string function' => [self::USE_SQL . 'function f(string $n): void { $s = Sql::of(\'LIMIT \' . strtolower($n)); }', 'Sql::of()'];
        yield 'Sql::of with interpolation' => [self::USE_SQL . 'function f(string $n): void { $s = Sql::of("r.$n = ?", [1]); }', 'interpolated'];
        yield 'Sql::of with the text of an untyped variable' => [self::USE_SQL . 'function f($q): void { $s = Sql::of(\'(\' . $q->sql() . \')\'); }', 'Sql::of()'];
        yield 'Sql::of fully qualified' => ['function f(string $t): void { $s = \\TiffinDB\\Core\\Query\\Sql::of($t); }', 'Sql::of()'];
        yield 'sql() of a non-Sql parameter' => [self::USE_SQL . 'function f(Request $r): void { $this->db->all($r->sql(), []); }', 'variable or expression'];
        yield 'sql() of an untyped parameter as the whole query' => [self::USE_SQL . 'function f($q): void { $this->db->all($q->sql(), $q->params()); }', 'variable or expression'];
        yield 'sql() of a fragment property' => [self::USE_SQL . 'function f(): void { $this->db->all($this->where->sql()); }', 'variable or expression'];
        yield 'sql() of a fragment local reassigned' => [self::USE_SQL . 'function f($x): void { $q = Sql::of(\'1\'); $q = $x; $this->db->all($q->sql()); }', 'variable or expression'];
        yield 'sql() of a fragment passed to a function' => [self::USE_SQL . 'function f(): void { $q = Sql::of(\'1\'); g($q); $this->db->all($q->sql()); }', 'variable or expression'];
        yield 'sql() of a fragment loop variable' => [self::USE_SQL . 'function f(array $parts): void { foreach ($parts as $q) { $this->db->all($q->sql()); } }', 'variable or expression'];
        yield 'sql() of a ternary of a fragment and a variable' => [self::USE_SQL . 'function f(Sql $a, $b, bool $x): void { $q = $x ? $a : $b; $this->db->all($q->sql()); }', 'variable or expression'];
        yield 'sql() of a short ternary' => [self::USE_SQL . 'function f(Sql $a, Sql $b): void { $q = $a ?: $b; $this->db->all($q->sql()); }', 'variable or expression'];
        yield 'sql() of a fragment of another class named Sql' => ["use Other\\Fragment as Sql;\nfunction f(): void { \$q = Sql::of('1'); \$this->db->run(\$q->sql()); }", 'variable or expression'];
        yield 'sql() of a fragment concatenated with a variable' => [self::USE_SQL . 'function f(Sql $q, string $x): void { $this->db->all($q->sql() . $x); }', 'operand'];
        yield 'params() of another object' => [self::USE_SQL . 'function f(Sql $q, Request $r): void { $this->db->all($q->sql(), $r->params()); }', 'params()'];
        yield 'params() of an untyped variable' => [self::USE_SQL . 'function f(Sql $q, $r): void { $this->db->all($q->sql(), $r->params()); }', 'params()'];
        yield 'params() of a property' => [self::USE_SQL . 'function f(Sql $q): void { $this->db->all($q->sql(), $this->q->params()); }', 'params()'];
        yield 'params() of a call' => [self::USE_SQL . 'function f(Sql $q): void { $this->db->all($q->sql(), $this->where()->params()); }', 'params()'];
        yield 'params() inside other values' => [self::USE_SQL . 'function f(Sql $q, Request $r): void { $this->db->all($q->sql(), [...$r->params(), 1]); }', 'params()'];
        yield 'disallowed glue' => [self::USE_SQL . 'function f(array $p): void { $s = Sql::join(\' UNION \', $p); }', 'glue'];
        yield 'variable glue' => [self::USE_SQL . 'function f(array $p, string $g): void { $s = Sql::join($g, $p); }', 'glue'];
        yield 'concatenated glue' => [self::USE_SQL . 'function f(array $p): void { $s = Sql::join(\' \' . \'AND \', $p); }', 'glue'];
        yield 'double-quoted glue' => [self::USE_SQL . 'function f(array $p): void { $s = Sql::join(" AND ", $p); }', 'glue'];
        yield 'constant glue' => [self::USE_SQL . 'function f(array $p): void { $s = Sql::join(self::GLUE, $p); }', 'glue'];
    }

    /** @return iterable<string, array{string, string}> code that holds no SQL, and the rule 0 id that refuses it */
    public static function forbiddenSources(): iterable
    {
        yield '->$name' => ['$o->$m();', 'dynamic-member'];
        yield '?->$name' => ['$o?->$p;', 'dynamic-member'];
        yield '->{...}' => ['$o->{\'x\'}();', 'dynamic-member'];
        yield '::$name()' => ['Foo::$m();', 'dynamic-member'];
        yield '::{...}' => ['Foo::{\'m\'}();', 'dynamic-member'];
        yield '$$name' => ['$$n = 1;', 'variable-variable'];
        yield '${...}' => ['${\'n\'} = 1;', 'variable-variable'];
        yield 'extract' => ['extract($a);', 'scope-rewriting'];
        yield 'extract, qualified' => ['\\extract($a);', 'scope-rewriting'];
        yield 'parse_str' => ['parse_str($q, $r);', 'scope-rewriting'];
        yield 'compact' => ['$v = compact(\'a\');', 'scope-rewriting'];
        yield 'call_user_func' => ['call_user_func($f);', 'callable-invokers'];
        yield 'call_user_func_array' => ['CALL_USER_FUNC_ARRAY($f, []);', 'callable-invokers'];
        yield 'Closure::fromCallable' => ['\\Closure::fromCallable($f);', 'callable-invokers'];
        yield 'imported Closure::fromCallable' => ['Closure::FromCallable($f);', 'callable-invokers'];
        yield 'array callable' => ['$c = [$db, \'run\'];', 'callable-literal'];
        yield 'array callable in capitals' => ['$c = [$db, \'Query\'];', 'callable-literal'];
        yield 'long array callable' => ['$c = array($db, \'exec\');', 'callable-literal'];
        yield 'string callable argument' => ['array_map(\'extract\', $x);', 'callable-literal'];
        yield 'first-class callable of a query method' => ['$c = $db->run(...);', 'callable-literal'];
        yield 'eval' => ['eval(\'1;\');', 'eval'];
        yield 'new $class' => ['$o = new $class();', 'variable-new'];
        yield 'new (expression)' => ['$o = new ($name)();', 'variable-new'];
        yield 'untyped variable call' => ['$f($x);', 'variable-call'];
        yield 'first-class callable of a variable' => ['$g = $f(...);', 'variable-call'];
        yield 'call of a parenthesised property' => ['($this->f)($x);', 'variable-call'];
        yield 'call of an array element' => ['$a[0]($x);', 'variable-call'];
        yield 'call of a string' => ['\'strlen\'(\'x\');', 'variable-call'];
        yield 'call of a callable parameter' => ['function f(callable $f): void { $f(1); }', 'variable-call'];
        yield 'first-class callable of a callable parameter' => ['function f(callable $onSnapshot): void { $g = $onSnapshot(...); }', 'variable-call'];
        yield 'array callable with a concatenated name' => ['$c = [$db, \'ru\' . \'n\'];', 'array-callable'];
        yield 'array callable with a variable name' => ['$c = [$db, $name];', 'array-callable'];
        yield 'array callable with a call for a name' => ['$c = [$db, strtolower($m)];', 'array-callable'];
        yield 'array callable with a trailing comma' => ['$c = [$db, $name,];', 'array-callable'];
        yield 'array callable inside bound parameters' => ['$db->all(\'SELECT 1\', array_map([$db, $m], $x));', 'array-callable'];
        yield 'array callable as a returned value in an expression' => ['return f([$db, $m]);', 'array-callable'];
        yield 'long-form array callable' => ['$c = array($db, $name);', 'array-callable'];
        yield 'ReflectionMethod' => ['$r = new \ReflectionMethod($db, $m);', 'reflection'];
        yield 'Reflection class constant' => ['$r = ReflectionClass::class;', 'reflection'];
        yield 'imported Reflection alias' => ["use ReflectionObject as Inspector;\n\$r = new Inspector(\$db);", 'reflection'];
        yield 'include' => ['include $file;', 'include'];
        yield 'include_once' => ['include_once \'x.php\';', 'include'];
        yield 'require' => ['require $file;', 'include'];
        yield 'require_once' => ['require_once __DIR__ . \'/x.php\';', 'include'];
        yield 'namespace-relative static call' => ['$s = namespace\\Sql::of(\'1\');', 'namespace-relative'];
        yield 'namespace-relative function call' => ['$n = namespace\\strlen(\'x\');', 'namespace-relative'];
        yield 'Closure call' => ['$v = $f->call($o);', 'closure-rebinding'];
        yield 'Closure bindTo' => ['$g = $f->bindTo($o, Other::class);', 'closure-rebinding'];
        yield 'Closure bind' => ['$g = \\Closure::bind($f, $o, Other::class);', 'closure-rebinding'];
        yield 'Closure bind in capitals' => ['$g = Closure::BIND($f, $o);', 'closure-rebinding'];
        yield 'nullsafe call' => ['$v = $f?->call($o);', 'closure-rebinding'];
        yield 'new Sql' => [self::USE_SQL . '$q = new Sql(\'1\', []);', 'fragment-new'];
        yield 'new Sql fully qualified' => ['$q = new \\TiffinDB\\Core\\Query\\Sql(\'1\', []);', 'fragment-new'];
    }

    #[DataProvider('forbiddenSources')]
    public function testTheFileRuleAloneRefuses(string $code, string $rule): void
    {
        $source = self::SYNTHETIC_HEADER . $code . "\n";
        $all = self::violations($source, []);
        self::assertNotSame([], $all, $code);
        self::assertStringContainsString('(' . $rule . ')', implode("\n", $all));
        // Nothing but rule 0 refuses it: without that rule the code passes.
        $others = array_values(array_filter($all, static fn (string $v): bool => !str_contains($v, '(' . $rule . ')')));
        self::assertSame($others, self::violations($source, [], [$rule]), $code);
    }

    /** @return iterable<string, array{string, string}> rule 0 id => a bypass that only that rule stops */
    public static function bypassesStoppedOnlyByOneRule(): iterable
    {
        yield 'dynamic-member' => ['dynamic-member', '$m = \'run\'; $db->$m($x);'];
        yield 'variable-variable' => ['variable-variable', 'function f(TableDef $t, string $n, $x): void { $$n = $x; $db->run(\'DROP TABLE \' . $t->sql()); }'];
        yield 'scope-rewriting' => ['scope-rewriting', 'function f(TableDef $t, array $a): void { extract($a); $db->run(\'DROP TABLE \' . $t->sql()); }'];
        yield 'callable-invokers' => ['callable-invokers', 'function f($cb, $sql): void { $run = \\Closure::fromCallable($cb); $run($sql); }'];
        yield 'callable-literal' => ['callable-literal', 'array_map([$db, \'run\'], $statements);'];
        yield 'eval' => ['eval', 'eval($code);'];
        yield 'variable-new' => ['variable-new', '$runner = new $class($db); $runner->go($sql);'];
        yield 'variable-call' => ['variable-call', 'function f($fn, $sql): void { $fn($sql); }'];
        yield 'array-callable' => ['array-callable', 'usort($x, [$db, \'r\' . \'un\']);'];
        yield 'reflection' => ['reflection', '$m = new \ReflectionMethod(Database::class, $name); $m->invoke($db, $sql);'];
        yield 'include' => ['include', 'include $path;'];
        // Security review round 1 (M2-WP2), reproducers A, B, C.
        yield 'namespace-relative' => ['namespace-relative', self::USE_SQL . 'final class X { function f(string $x): void { $this->g(namespace\\Sql::of($x)); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }'];
        yield 'closure-rebinding' => ['closure-rebinding', self::USE_SQL . 'final class X { function f(string $x): void { $this->g($this->forge($x)->call(Sql::of(\'1\'))); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }'];
        yield 'fragment-new' => ['fragment-new', self::USE_SQL . 'final class X { function f(string $x): void { $this->g(new Sql($x, [])); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }'];
    }

    #[DataProvider('bypassesStoppedOnlyByOneRule')]
    public function testEachForbiddenRuleIsNeeded(string $rule, string $code): void
    {
        $source = self::SYNTHETIC_HEADER . $code . "\n";
        self::assertNotSame([], self::violations($source, ['$statement', '$pragma']), $code);
        self::assertSame([], self::violations($source, ['$statement', '$pragma'], [$rule]), 'Without ' . $rule . ' the bypass passes.');
    }

    /** @return iterable<string, array{string, list<string>}> code in the Query namespace, rules that each refuse it */
    public static function reviewReproducers(): iterable
    {
        // The security review's reproducers (M2-WP2 round 1, cases A to C), as written there.
        yield 'A namespace-relative Sql::of' => ['final class X { function f(string $x): void { $this->g(namespace\\Sql::of($x)); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }', ['namespace-relative']];
        yield 'B fragment forged through Closure::call' => ['final class X { function f(string $x): void { $this->g((fn() => new Sql($x, []))->call(Sql::of(\'1\'))); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }', ['closure-rebinding', 'fragment-new']];
        yield 'C new Sql' => ['final class X { function f(string $x): void { $this->g(new Sql($x, [])); } function g(Sql $q): void { $this->db->all($q->sql(), $q->params()); } }', ['fragment-new']];
    }

    /** @param list<string> $rules */
    #[DataProvider('reviewReproducers')]
    public function testReviewReproducersAreRefused(string $code, array $rules): void
    {
        $source = "<?php\nnamespace TiffinDB\\Core\\Query;\nuse TiffinDB\\Core\\Storage\\Database;\n" . $code . "\n";
        $all = implode("\n", self::violations($source, []));
        foreach ($rules as $rule) {
            self::assertStringContainsString('(' . $rule . ')', $all);
        }
        // Without these rules the code scans clean: they are what stops it.
        self::assertSame([], self::violations($source, [], $rules));
    }

    public function testNoConstructIsAllowedByException(): void
    {
        // SchemaEditor takes a Closure (ruling P77), so even its old conversion is refused.
        $source = self::SYNTHETIC_HEADER . 'function f(callable $onSnapshot): void { $g = $onSnapshot(...); }' . "\n";
        self::assertStringContainsString('(variable-call)', implode("\n", self::violations($source, [])));
        self::assertStringNotContainsString('$onSnapshot(...)', RepoFiles::read('src/Core/Schema/SchemaEditor.php'));
    }

    #[DataProvider('badSources')]
    public function testTheScanCatches(string $code, string $expected): void
    {
        $violations = self::violations(self::SYNTHETIC_HEADER . $code . "\n", ['$statement', '$pragma']);
        self::assertNotSame([], $violations, $code);
        self::assertStringContainsString($expected, implode("\n", $violations));
    }

    /** @return iterable<string, array{string}> */
    public static function goodSources(): iterable
    {
        yield 'literal' => ['$db->run(\'DELETE FROM "_tiffin_meta" WHERE "key" = ?\', [$k]);'];
        yield 'identifier' => ['$db->run(\'SELECT * FROM \' . Identifier::table($k) . \' WHERE "id" IN (\' . Database::placeholders(count($ids)) . \')\', $ids);'];
        yield 'definition parameters' => ['function f(TableDef $table, FieldDef $field, FieldType $type): void { $this->db->run(\'ALTER TABLE \' . $table->sql() . \' ADD COLUMN \' . $field->sql() . \' \' . ColumnDefinition::of($type)); }'];
        yield 'nullable definition parameter' => ['function f(?FieldDef $field): void { if ($field !== null) { $this->db->value(\'SELECT COUNT(\' . $field->sql() . \') FROM t\'); } }'];
        yield 'captured by a closure' => ['function f(RelationDef $relation): void { $this->db->write(function () use ($relation): void { $this->db->run(\'DROP TABLE \' . $relation->sql()); }); }'];
        yield 'captured by an arrow function' => ['function f(TableDef $table, array $ids): int { return $this->db->write(fn (Database $db): int => $db->run(\'DELETE FROM \' . $table->sql() . \' WHERE "id" IN (\' . Database::placeholders(count($ids)) . \')\', $ids)); }'];
        yield 'local from a typed method' => ['function f(Schema $schema, string $key): void { $field = $schema->field($key); $table = $schema->table($field->tableKey); $this->db->run(\'DROP TABLE \' . $table->sql()); }'];
        yield 'local from a typed property' => ['function f(FieldUpdate $plan): void { $field = $plan->field; $this->db->run(\'SELECT \' . $field->sql() . \' FROM t\'); }'];
        yield 'local from a constructor' => ['function f(string $key): void { $table = new TableDef($key, \'Items\', \'item\', 1, null); $this->db->run(\'DROP TABLE \' . $table->sql()); }'];
        yield 'index of a name variable' => ['$db->run(\'CREATE INDEX \' . Identifier::index(Identifier::tableName($key), \'trash\') . \' ON \' . Identifier::table($key) . \' ("x")\');'];
        yield 'message' => ['throw new \RuntimeException(\'Give 1 to \' . self::MAX . \' ids.\');'];
        yield 'zero-argument value' => ['$x = $checked->value();'];
        yield 'allowed loop variable' => ['foreach (self::DDL as $statement) { $db->run($statement); }'];
        yield 'allowed loop variable with a key' => ['function f(int $version): void { foreach (self::VERSION_PRAGMAS as $target => $pragma) { if ($target === $version) { $db->run($pragma); } } }'];
        yield 'Closure-typed parameter call' => ['function f(\\Closure $work): void { $work(1); }'];
        yield 'Closure-typed capture call' => ['function f(\\Closure $work): void { $g = function () use ($work): void { $work(1); }; }'];
        yield 'Closure-typed property call' => ['final class SchemaEditor { public function g(): void { $notify = $this->onSnapshot; $notify(1); } }'];
        yield 'destructuring two variables' => ['[$changes, $cleared] = $this->plan();'];
        yield 'swapping two elements' => ['[$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];'];
        yield 'loop destructuring' => ['foreach ($pairs as [$a, $b]) { $n = $a; }'];
        yield 'bound parameters of two variables' => ['$db->run(\'UPDATE t SET "x" = ? WHERE "id" = ?\', [$value, $id]);'];
        yield 'returned pair' => ['function f(): array { return [$changes, $cleared]; }'];
        yield 'pair of a variable and a literal' => ['$c = [$this, \'check\'];'];
        yield 'method named require' => ['final class X { private static function require(bool $ok): void {} public function g(): void { self::require(true); } }'];
        yield 'index of a column named like a method' => ['$v = $row[\'value\'];'];
        yield 'enum case value like a method' => ['enum Cardinality: string { case One = \'one\'; }'];
        yield 'allowed loop variable over a constant entry' => ['$f = static function (Database $db) use ($version): void { foreach (self::STEPS[$version] as $statement) { $db->run($statement); } };'];
        // Sql fragments (M2 spec section 3.9).
        yield 'fragments from literals and names' => [self::USE_SQL . 'function f(TableDef $t, string $k, array $ids): void { $w = Sql::of(\'r.\' . Identifier::column($k) . \' IN (\' . Database::placeholders(count($ids)) . \')\', $ids); $q = Sql::join(\' \', [Sql::of(\'SELECT * FROM \' . $t->sql() . \' AS "r" WHERE\'), $w->wrap()]); $this->db->all($q->sql(), $q->params()); }'];
        yield 'fragment parameter' => [self::USE_SQL . 'function f(Sql $where): int { return (int) $this->db->value(\'SELECT COUNT(*) FROM t WHERE \' . $where->sql(), $where->params()); }'];
        yield 'fragment text inside another fragment' => [self::USE_SQL . 'function f(Sql $e): void { $q = Sql::of(\'(\' . $e->sql() . \' IS NULL)\', $e->params()); $this->db->all($q->sql(), $q->params()); }'];
        yield 'fragment from a definition object' => [self::USE_SQL . 'function f(FieldDef $field, string $label): void { $q = Sql::of(\'r.\' . $field->sql() . \' = ?\', [$label]); }'];
        yield 'wrapped fragment' => [self::USE_SQL . 'function f(): void { $a = Sql::of(\'1\'); $b = $a->wrap(); $this->db->all($b->sql(), $b->params()); }'];
        yield 'ternary of fragments' => [self::USE_SQL . 'function f(bool $d): void { $dir = $d ? Sql::of(\'DESC\') : Sql::of(\'ASC\'); $q = Sql::join(\' \', [Sql::of(\'SELECT 1 ORDER BY 1\'), $dir]); $this->db->all($q->sql(), $q->params()); }'];
        yield 'fragment chosen in if branches' => [self::USE_SQL . 'function f(bool $d): void { if ($d) { $dir = Sql::of(\'DESC\'); } else { $dir = Sql::of(\'ASC\'); } $this->db->all(\'SELECT 1 ORDER BY 1 \' . $dir->sql()); }'];
        yield 'fragment captured by a closure' => [self::USE_SQL . 'function f(Sql $q): void { $this->db->write(function (Database $db) use ($q): void { $db->run($q->sql(), $q->params()); }); }'];
        yield 'every glue' => [self::USE_SQL . 'function f(array $p): void { $a = Sql::join(\' AND \', $p); $b = Sql::join(\' OR \', $p); $c = Sql::join(\', \', $p); $d = Sql::join(\' \', $p); }'];
        yield 'two-element values and parts' => [self::USE_SQL . 'function f(Sql $a, string $x, string $y): void { $q = Sql::join(\' \', [$a, Sql::of(\'= ?\', [$x])]); $r = Sql::of(\'? ?\', [$x, $y]); $j = Sql::join(\' \', [$a, $q]); }'];
    }

    #[DataProvider('goodSources')]
    public function testTheScanAccepts(string $code): void
    {
        self::assertSame([], self::violations(self::SYNTHETIC_HEADER . $code . "\n", ['$statement', '$pragma']));
    }

    // ------------------------------------------------------------ the scanner

    /** @return list<array{0: int, 1: string, 2: int}|string> significant tokens */
    private static function tokens(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param list<string> $allowedVariables
     * @param list<string> $disabledRules forbidden-construct rules to leave out (for the test that each is needed)
     * @return list<string> one line per violation
     */
    public static function violations(string $source, array $allowedVariables, array $disabledRules = []): array
    {
        $c = self::context($source);
        $tokens = $c['tokens'];
        $violations = self::forbiddenConstructs($c, $disabledRules);
        $line = static fn (int $i): int => self::lineOf($tokens, $i);

        foreach ($tokens as $i => $token) {
            if ($token === '"' || (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $violations[] = 'line ' . $line($i) . ': interpolated string';
            }
            if (self::isToken($token, T_START_HEREDOC)) {
                $violations[] = 'line ' . $line($i) . ': heredoc or nowdoc';
            }
            if (self::isToken($token, T_CONCAT_EQUAL)) {
                $violations[] = 'line ' . $line($i) . ': concatenate-assign (.=)';
            }
            if (self::isToken($token, T_STRING) && in_array(strtolower($token[1]), self::STRING_BUILDERS, true)
                && ($tokens[$i + 1] ?? null) === '(' && !self::isMemberName($tokens, $i)) {
                $violations[] = 'line ' . $line($i) . ': string builder ' . strtolower($token[1]) . '()';
            }
            if (self::isFragmentFactory($c, $i)) {
                $factory = strtolower($tokens[$i + 2][1]);
                if ($factory === 'of') {
                    // Rule 3a: Sql::of() text follows rule 3.
                    $problems = ($tokens[$i + 4] ?? null) === ')' ? ['SQL argument of ->%m() is missing'] : self::argumentProblems($c, $i + 4, $allowedVariables);
                    foreach ($problems as $problem) {
                        $violations[] = 'line ' . $line($i) . ': ' . str_replace('->%m()', 'Sql::of()', $problem);
                    }
                } elseif ($factory === 'join' && !self::isAllowedGlue($tokens, $i + 4)) {
                    // Rule 3b.
                    $violations[] = 'line ' . $line($i) . ': Sql::join() glue is not one single-quoted literal among Sql::GLUES';
                }
            }
            if (self::isToken($token, T_STRING) && strtolower($token[1]) === 'params' && self::isMethodCall($tokens, $i)
                && !self::isProvenCall($c, $i - 2, 'params', [self::SQL_CLASS])) {
                // Rule 3c.
                $violations[] = 'line ' . $line($i) . ': params() of an object not shown to be an Sql fragment';
            }
            if (self::isToken($token, T_STRING) && self::isMethodCall($tokens, $i)) {
                $method = strtolower($token[1]);
                if (in_array($method, self::PDO_METHODS, true)) {
                    $violations[] = 'line ' . $line($i) . ': direct PDO call ->' . $method . '()';
                }
                if (in_array($method, self::QUERY_METHODS, true) && ($tokens[$i + 2] ?? null) !== ')') {
                    foreach (self::argumentProblems($c, $i + 2, $allowedVariables) as $problem) {
                        $violations[] = 'line ' . $line($i) . ': ' . str_replace('%m', $method, $problem);
                    }
                }
            }
        }

        // Rule 5, concatenation chains anywhere: union operands joined by '.'.
        $parent = [];
        $kinds = [];
        $find = static function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $x = $parent[$x] = $parent[$parent[$x]];
            }

            return $x;
        };
        foreach ($tokens as $i => $token) {
            if ($token !== '.') {
                continue;
            }
            [$leftStart, $leftKind] = self::backward($c, $i - 1);
            [$rightStart, , $rightKind] = self::forwardWithStart($c, $i + 1);
            foreach ([[$leftStart, $leftKind], [$rightStart, $rightKind]] as [$start, $kind]) {
                $parent[$start] ??= $start;
                $kinds[$start] = $kind;
            }
            $parent[$find($leftStart)] = $find($rightStart);
        }
        $groups = [];
        foreach (array_keys($parent) as $start) {
            $groups[$find($start)][] = $start;
        }
        foreach ($groups as $members) {
            $isSql = false;
            foreach ($members as $start) {
                $token = $tokens[$start];
                if ($kinds[$start] === 'literal' && is_array($token) && preg_match(self::SQL_WORD, $token[1]) === 1) {
                    $isSql = true;
                }
            }
            if (!$isSql) {
                continue;
            }
            foreach ($members as $start) {
                if ($kinds[$start] === 'other') {
                    $violations[] = 'line ' . $line($start) . ': operand of an SQL concatenation is not a literal or an Identifier name';
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Rule 0, default deny: constructs whose call target or variable the
     * scan cannot resolve, refused wherever they appear, so that rules 3
     * and 4 never have to reason about them. Each rule has an id so that
     * testEachForbiddenRuleIsNeeded can leave it out.
     *
     * @param array<string, mixed> $c
     * @param list<string> $disabled rule ids to leave out
     * @return list<string>
     */
    private static function forbiddenConstructs(array $c, array $disabled): array
    {
        $tokens = $c['tokens'];
        $found = [];
        $add = static function (string $rule, int $k, string $what) use (&$found, $disabled, $tokens): void {
            if (!in_array($rule, $disabled, true)) {
                $found[] = 'line ' . self::lineOf($tokens, $k) . ': forbidden construct (' . $rule . '): ' . $what;
            }
        };
        $callableNames = [...self::QUERY_METHODS, ...self::PDO_METHODS, ...array_merge(...array_values(self::FORBIDDEN_FUNCTIONS)), 'call', 'bindto', 'bind'];

        foreach ($tokens as $k => $t) {
            $next = $tokens[$k + 1] ?? null;
            $prev = $tokens[$k - 1] ?? null;

            // ->$name, ->{...}, ::$name, ::{...}, ?->$name
            if (self::isToken($t, T_OBJECT_OPERATOR) || self::isToken($t, T_NULLSAFE_OBJECT_OPERATOR) || self::isToken($t, T_DOUBLE_COLON)) {
                if (self::isToken($next, T_VARIABLE) || $next === '{' || $next === '$') {
                    $add('dynamic-member', $k, 'member named by an expression');
                }
            }
            // $$name, ${...}
            if ($t === '$' || self::isToken($t, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $add('variable-variable', $k, 'variable variable');
            }
            // extract(), parse_str(), compact(), call_user_func(), call_user_func_array(), X::fromCallable()
            if (self::isName($t)) {
                $name = strtolower(ltrim($t[1], '\\'));
                $isMember = self::isToken($prev, T_OBJECT_OPERATOR) || self::isToken($prev, T_NULLSAFE_OBJECT_OPERATOR) || self::isToken($prev, T_FUNCTION);
                foreach (self::FORBIDDEN_FUNCTIONS as $rule => $names) {
                    if (in_array($name, $names, true) && !$isMember && ($name !== 'fromcallable' || self::isToken($prev, T_DOUBLE_COLON))) {
                        $add($rule, $k, $name);
                    }
                }
            }
            if (self::isToken($t, T_EVAL)) {
                $add('eval', $k, 'eval');
            }
            // include, require, and their _once forms, as keywords; `self::require()` and
            // `function require()` lex as T_REQUIRE too, so a member name is not the keyword.
            if (is_array($t) && in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)
                && !self::isMemberName($tokens, $k) && !self::isToken($prev, T_CONST)) {
                $add('include', $k, strtolower($t[1]));
            }
            // Reflection classes call any method by name.
            if (self::isName($t) && !self::isMemberName($tokens, $k)) {
                $written = strtolower(ltrim($t[1], '\\'));
                $resolved = strtolower((string) self::resolveClass($c, $t[1], $k));
                if (str_starts_with($written, 'reflection') || str_starts_with(substr((string) strrchr('\\' . $resolved, '\\'), 1), 'reflection')) {
                    $add('reflection', $k, $t[1]);
                }
            }
            // [$var, X] or array($var, X) where X is not one string literal: a callable with a computed method name.
            if (($t === '[' || ($t === '(' && self::isToken($prev, T_ARRAY))) && self::isArrayCallableShape($c, $k)) {
                $add('array-callable', $k, 'two-element array of a variable and a computed name');
            }
            // new $class, new ($expression)
            if (self::isToken($t, T_NEW) && (self::isToken($next, T_VARIABLE) || $next === '(' || $next === '$')) {
                $add('variable-new', $k, 'new with a class name that is not written out');
            }
            // namespace\Name is resolved by PHP against the file's namespace, not through the imports
            // the scan reads, so a call such as namespace\Sql::of() would escape rule 3a.
            if (self::isToken($t, T_NAME_RELATIVE)) {
                $add('namespace-relative', $k, 'namespace-relative name ' . $t[1]);
            }
            // Closure rebinding runs code as another object, reaching its private members
            // (for example a private constructor): ->call(), ->bindTo(), ::bind().
            if (self::isToken($t, T_STRING) && $next === '(' && ((self::isMethodCall($tokens, $k) && in_array(strtolower($t[1]), ['call', 'bindto'], true))
                || (self::isToken($prev, T_DOUBLE_COLON) && strtolower($t[1]) === 'bind'))) {
                $add('closure-rebinding', $k, 'closure rebinding ' . $t[1] . '()');
            }
            // An Sql fragment is made only by Sql::of(), Sql::join(), and ->wrap(), whose checks
            // `new Sql` would skip (the private constructor refuses it only at run time).
            if (self::isToken($t, T_NEW) && self::isName($next) && strtolower((string) self::resolveClass($c, $next[1], $k)) === strtolower(self::SQL_CLASS)) {
                $add('fragment-new', $k, 'new Sql outside ' . self::EXEMPT_FILE);
            }
            // A string naming a query method or a forbidden function, as an array value or a call argument.
            if (self::isToken($t, T_CONSTANT_ENCAPSED_STRING) && in_array(strtolower(substr($t[1], 1, -1)), $callableNames, true)
                && self::isCallableLiteralPosition($c, $k)) {
                $add('callable-literal', $k, 'callable named by a string ' . $t[1]);
            }
            // First-class callable of a query method: ->run(...)
            if (self::isToken($t, T_STRING) && self::isMethodCall($tokens, $k) && in_array(strtolower($t[1]), [...self::QUERY_METHODS, ...self::PDO_METHODS], true)
                && self::isToken($tokens[$k + 2] ?? null, T_ELLIPSIS) && ($tokens[$k + 3] ?? null) === ')') {
                $add('callable-literal', $k, 'first-class callable of ->' . strtolower($t[1]) . '()');
            }
            // $var(...) unless $var is shown to hold a Closure; (expr)(...), $a[0](...), 'name'(...)
            if ($next === '(') {
                $isNamedElsewhere = false; // ->$m(), ::$m(), new $c(): dynamic-member and variable-new
                foreach ([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW] as $id) {
                    $isNamedElsewhere = $isNamedElsewhere || self::isToken($prev, $id);
                }
                if (self::isToken($t, T_VARIABLE) && !$isNamedElsewhere) {
                    if (strtolower((string) self::typeOfVariable($c, $t[1], $k, 0)) !== 'closure') {
                        $add('variable-call', $k, 'call of ' . $t[1] . ', which is not shown to hold a Closure');
                    }
                } elseif ($t === ']' || $t === '}' || self::isToken($t, T_CONSTANT_ENCAPSED_STRING)) {
                    $add('variable-call', $k, 'call of an expression');
                } elseif ($t === ')') {
                    $before = $tokens[self::matchClose($c, $k) - 1] ?? null;
                    $isHeader = false;
                    foreach (self::CONTROL_HEADERS as $id) {
                        $isHeader = $isHeader || self::isToken($before, $id);
                    }
                    if (!$isHeader) {
                        $add('variable-call', $k, 'call of an expression');
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether the bracket at $k opens an array literal (not an index or a
     * destructuring target) of exactly two elements, the first a plain
     * variable and the second anything but one string literal.
     *
     * @param array<string, mixed> $c
     */
    private static function isArrayCallableShape(array $c, int $k): bool
    {
        $tokens = $c['tokens'];
        $before = $tokens[$k - 1] ?? null;
        $close = self::matchClose($c, $k);
        $isIndex = $tokens[$k] === '[' && (self::isToken($before, T_VARIABLE) || in_array($before, [']', ')', '}'], true) || self::isName($before));
        if ($isIndex || self::isToken($before, T_AS) || ($tokens[$close + 1] ?? null) === '=') {
            return false;
        }
        // Positions where an array is data, never a callable: the bound parameters of a query
        // method (its second argument; Database binds each element as a value), the second
        // argument of Sql::of() (bound values, each checked to be a scalar or null) and of
        // Sql::join() (fragments, each checked to be an Sql), and a returned tuple.
        $p = $c['parent'][$k];
        if (self::isToken($before, T_RETURN) && ($tokens[$close + 1] ?? null) === ';') {
            return false;
        }
        $isDataArgument = $p !== null && ((self::isToken($tokens[$p - 1] ?? null, T_STRING) && self::isMethodCall($tokens, $p - 1)
            && in_array(strtolower($tokens[$p - 1][1]), self::QUERY_METHODS, true))
            || (self::isFragmentFactory($c, $p - 3) && in_array(strtolower($tokens[$p - 1][1]), ['of', 'join'], true)));
        if ($before === ',' && $p !== null && $tokens[$p] === '(' && in_array($tokens[$close + 1] ?? null, [')', ','], true) && $isDataArgument) {
            $commas = 0;
            for ($j = $p + 1; $j < $k; $j++) {
                $commas += ($tokens[$j] === ',' && $c['parent'][$j] === $p) ? 1 : 0;
            }
            if ($commas === 1) {
                return false;
            }
        }
        $commas = [];
        for ($j = $k + 1; $j < $close; $j++) {
            if ($tokens[$j] === ',' && $c['parent'][$j] === $k) {
                $commas[] = $j;
            }
        }
        if ($commas === [] || count($commas) > 2 || (count($commas) === 2 && $commas[1] !== $close - 1)) {
            return false; // not exactly two elements (a trailing comma is allowed)
        }
        $comma = $commas[0];
        $end = count($commas) === 2 ? $commas[1] : $close;
        if (!self::isToken($tokens[$k + 1] ?? null, T_VARIABLE) || $comma !== $k + 2) {
            return false;
        }

        return !($end === $comma + 2 && self::isToken($tokens[$comma + 1], T_CONSTANT_ENCAPSED_STRING));
    }

    /**
     * Whether the literal at $k is a value in an array literal or a direct
     * call argument, where it could serve as a callable.
     *
     * @param array<string, mixed> $c
     */
    private static function isCallableLiteralPosition(array $c, int $k): bool
    {
        $tokens = $c['tokens'];
        $p = $c['parent'][$k];
        if ($p === null || !in_array($tokens[$k - 1] ?? null, ['(', '[', ','], true) || !in_array($tokens[$k + 1] ?? null, [')', ']', ','], true)) {
            return false;
        }
        $before = $tokens[$p - 1] ?? null;
        if ($tokens[$p] === '[') {
            // An array literal, not an index: `$a['run']`, `f()['run']`.
            return !(self::isToken($before, T_VARIABLE) || in_array($before, [']', ')', '}'], true) || self::isName($before));
        }

        return $tokens[$p] === '(';
    }

    /**
     * Rules 3 and 4: the whole first argument of a query method, which starts at $start.
     *
     * @param array<string, mixed> $c
     * @param list<string> $allowedVariables
     * @return list<string> problems, with %m for the method name
     */
    private static function argumentProblems(array $c, int $start, array $allowedVariables): array
    {
        $tokens = $c['tokens'];
        $operands = [];
        $from = $start;
        $k = $start;
        for (; $k < count($tokens); $k++) {
            $t = $tokens[$k];
            if (self::isOpen($t)) {
                $k = self::matchClose($c, $k);
            } elseif (in_array($t, [')', ']', '}', ','], true)) {
                break;
            } elseif ($t === '.') {
                $operands[] = [$from, $k];
                $from = $k + 1;
            }
        }
        $operands[] = [$from, $k];

        if (count($operands) === 1) {
            [$s, $e] = $operands[0];
            if ($e === $s + 1 && self::isToken($tokens[$s], T_VARIABLE)) {
                return self::isConstantLoopVariable($c, $tokens[$s][1], $s, $allowedVariables)
                    ? []
                    : ['SQL argument of ->%m() is a variable, not the loop variable of a foreach over a constant of literal statements'];
            }

            return self::isTrustedOperand($c, $s, $e) ? [] : ['SQL argument of ->%m() is a variable or expression, not literal SQL'];
        }
        $problems = [];
        foreach ($operands as [$s, $e]) {
            if (!self::isTrustedOperand($c, $s, $e)) {
                $problems[] = 'operand of the SQL argument of ->%m() is not a literal, an Identifier name, or the sql() of a definition object';
            }
        }

        return $problems;
    }

    /**
     * Exactly tokens [$s, $e) are a string literal, an allowed static call,
     * or `$v->sql()` on a variable shown to hold a definition object.
     *
     * @param array<string, mixed> $c
     */
    private static function isTrustedOperand(array $c, int $s, int $e): bool
    {
        $tokens = $c['tokens'];
        $t = $tokens[$s] ?? null;
        if ($e === $s + 1 && self::isToken($t, T_CONSTANT_ENCAPSED_STRING)) {
            return true;
        }
        if (self::isName($t) && self::isToken($tokens[$s + 1] ?? null, T_DOUBLE_COLON) && self::isToken($tokens[$s + 2] ?? null, T_STRING)
            && ($tokens[$s + 3] ?? null) === '(') {
            return self::matchClose($c, $s + 3) === $e - 1 && self::allowedStatic($c, $t[1], $tokens[$s + 2][1], $s);
        }

        return $e === $s + 5 && self::isProvenSqlCall($c, $s);
    }

    /**
     * `$v->sql()` at $s, where $v is shown to hold a TableDef, FieldDef, or RelationDef.
     *
     * @param array<string, mixed> $c
     */
    private static function isProvenSqlCall(array $c, int $s): bool
    {
        return self::isProvenCall($c, $s, 'sql', [...self::DEF_CLASSES, self::SQL_CLASS]);
    }

    /**
     * `$v-><method>()` at $s, with no arguments, on a plain variable (not a
     * property or a chain) shown to hold one of $classes.
     *
     * @param array<string, mixed> $c
     * @param list<string> $classes
     */
    private static function isProvenCall(array $c, int $s, string $method, array $classes): bool
    {
        $tokens = $c['tokens'];
        $before = $tokens[$s - 1] ?? null;

        return self::isToken($tokens[$s] ?? null, T_VARIABLE) && self::isToken($tokens[$s + 1] ?? null, T_OBJECT_OPERATOR)
            && self::isToken($tokens[$s + 2] ?? null, T_STRING) && strtolower($tokens[$s + 2][1]) === $method
            && ($tokens[$s + 3] ?? null) === '(' && ($tokens[$s + 4] ?? null) === ')'
            && !self::isToken($before, T_OBJECT_OPERATOR) && !self::isToken($before, T_NULLSAFE_OBJECT_OPERATOR) && !self::isToken($before, T_DOUBLE_COLON)
            && in_array(strtolower((string) self::typeOfVariable($c, $tokens[$s][1], $s, 0)), array_map('strtolower', $classes), true);
    }

    /**
     * `Sql::<name>(` at $i, the class resolved through the file's imports.
     *
     * @param array<string, mixed> $c
     */
    private static function isFragmentFactory(array $c, int $i): bool
    {
        $tokens = $c['tokens'];

        return self::isName($tokens[$i] ?? null) && self::isToken($tokens[$i + 1] ?? null, T_DOUBLE_COLON)
            && self::isToken($tokens[$i + 2] ?? null, T_STRING) && ($tokens[$i + 3] ?? null) === '('
            && strtolower((string) self::resolveClass($c, $tokens[$i][1], $i)) === strtolower(self::SQL_CLASS);
    }

    /**
     * The glue argument at $k is one single-quoted literal (then a comma) whose text is in Sql::GLUES.
     *
     * @param list<mixed> $tokens
     */
    private static function isAllowedGlue(array $tokens, int $k): bool
    {
        $glue = $tokens[$k] ?? null;
        if (!self::isToken($glue, T_CONSTANT_ENCAPSED_STRING) || ($tokens[$k + 1] ?? null) !== ',' || !str_starts_with($glue[1], "'")) {
            return false;
        }

        return in_array(substr($glue[1], 1, -1), [' AND ', ' OR ', ', ', ' '], true);
    }

    /**
     * Whether $variable at $at is the loop variable of an enclosing
     * `foreach (self::CONST as [$key =>] $variable) { ... }` (or over
     * `self::CONST[...]`) in the same function, and is not written, rebound,
     * or captured in the loop body.
     *
     * @param array<string, mixed> $c
     * @param list<string> $allowedVariables
     */
    private static function isConstantLoopVariable(array $c, string $variable, int $at, array $allowedVariables): bool
    {
        if (!in_array($variable, $allowedVariables, true)) {
            return false;
        }
        $tokens = $c['tokens'];
        for ($b = $c['parent'][$at]; $b !== null; $b = $c['parent'][$b]) {
            if ($tokens[$b] !== '{' || ($tokens[$b - 1] ?? null) !== ')') {
                continue;
            }
            $open = $c['match'][$b - 1];
            if (!self::isToken($tokens[$open - 1] ?? null, T_FOREACH)) {
                continue;
            }
            $as = null;
            for ($k = $open + 1; $k < $b - 1; $k++) {
                if (self::isToken($tokens[$k], T_AS) && $c['parent'][$k] === $open) {
                    $as = $k;
                }
            }
            if ($as === null) {
                return false;
            }
            $binds = false;
            for ($k = $as + 1; $k < $b - 1; $k++) {
                if (self::isToken($tokens[$k], T_VARIABLE) && $tokens[$k][1] === $variable) {
                    $binds = true;
                }
            }
            if (!$binds) {
                continue;
            }
            // The header is exactly: self :: CONST ([...])? as ($key =>)? $variable
            $k = $open + 1;
            if (!(self::isToken($tokens[$k], T_STRING) && strtolower($tokens[$k][1]) === 'self'
                && self::isToken($tokens[$k + 1], T_DOUBLE_COLON) && self::isToken($tokens[$k + 2], T_STRING))) {
                return false;
            }
            $k += 3;
            if ($tokens[$k] === '[') {
                $k = self::matchClose($c, $k) + 1;
            }
            if ($k !== $as) {
                return false;
            }
            $k++;
            if (self::isToken($tokens[$k], T_VARIABLE) && self::isToken($tokens[$k + 1] ?? null, T_DOUBLE_ARROW)) {
                $k += 2;
            }
            if (!(self::isToken($tokens[$k], T_VARIABLE) && $tokens[$k][1] === $variable && $k === $b - 2)) {
                return false;
            }
            if (self::scopeAt($c, $open) !== self::scopeAt($c, $at)) {
                return false;
            }
            $end = self::matchClose($c, $b);
            for ($k = $b + 1; $k < $end; $k++) {
                if (self::isToken($tokens[$k], T_VARIABLE) && $tokens[$k][1] === $variable && $k !== $at && self::classify($c, $k) !== 'read') {
                    return false;
                }
            }
            foreach ($c['scopes'] as $scope) {
                if ($scope['start'] > $b && $scope['start'] < $end) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The class of the object $variable holds at $at, when the code shows
     * it: a typed parameter, a by-value capture of one, or a local whose
     * every assignment is a typed expression, never otherwise written.
     * Null when not shown.
     *
     * @param array<string, mixed> $c
     */
    private static function typeOfVariable(array $c, string $variable, int $at, int $depth): ?string
    {
        $index = self::scopeAt($c, $at);
        if ($depth > 8 || $index === null) {
            return null;
        }
        $scope = $c['scopes'][$index];
        if ($variable === '$this') {
            return $scope['class'];
        }
        $assignments = [];
        foreach (self::occurrences($c, $index, $variable) as $k) {
            $kind = self::classify($c, $k);
            if ($kind === 'assign') {
                $assignments[] = $k;
            } elseif ($kind !== 'read') {
                return null;
            }
        }
        foreach ($c['scopes'] as $nested) {
            if ($nested['start'] > $scope['bodyStart'] && $nested['start'] < $scope['bodyEnd'] && ($nested['uses'][$variable] ?? false)) {
                return null; // captured by reference
            }
        }
        if (isset($scope['params'][$variable])) {
            $param = $scope['params'][$variable];

            return $param['byRef'] || $assignments !== [] ? null : $param['type'];
        }
        if ($scope['kind'] === 'closure') {
            if (!isset($scope['uses'][$variable])) {
                return self::typeOfAssignments($c, $assignments, $depth);
            }

            return $scope['uses'][$variable] || $assignments !== [] ? null : self::typeOfVariable($c, $variable, $scope['start'], $depth + 1);
        }
        if ($scope['kind'] === 'arrow') {
            return $assignments !== [] ? null : self::typeOfVariable($c, $variable, $scope['start'], $depth + 1);
        }

        return self::typeOfAssignments($c, $assignments, $depth);
    }

    /**
     * @param array<string, mixed> $c
     * @param list<int> $assignments token indexes of `$v` in `$v = ...`
     */
    private static function typeOfAssignments(array $c, array $assignments, int $depth): ?string
    {
        $type = null;
        foreach ($assignments as $k) {
            $found = self::typeOfExpression($c, $k + 2, self::expressionEnd($c, $k + 2), $depth + 1);
            if ($found === null || ($type !== null && $found !== $type)) {
                return null;
            }
            $type = $found;
        }

        return $type;
    }

    /**
     * The class of tokens [$s, $e) when they are `new C(...)`, or `C::m(...)`
     * or a variable followed by `->m(...)` and `->p` steps, each with a
     * declared class type.
     *
     * @param array<string, mixed> $c
     */
    private static function typeOfExpression(array $c, int $s, int $e, int $depth): ?string
    {
        $tokens = $c['tokens'];
        // `cond ? A : B` at the top level: the class both arms are shown to have.
        $question = null;
        $colon = null;
        for ($k = $s; $k < $e; $k++) {
            if (self::isOpen($tokens[$k])) {
                $k = self::matchClose($c, $k);
            } elseif ($tokens[$k] === '?') {
                if ($question !== null) {
                    return null;
                }
                $question = $k;
            } elseif ($tokens[$k] === ':' && $question !== null) {
                if ($colon !== null) {
                    return null;
                }
                $colon = $k;
            }
        }
        if ($question !== null) {
            if ($colon === null || $colon === $question + 1) {
                return null;
            }
            $then = self::typeOfExpression($c, $question + 1, $colon, $depth + 1);

            return $then !== null && $then === self::typeOfExpression($c, $colon + 1, $e, $depth + 1) ? $then : null;
        }
        $t = $tokens[$s] ?? null;
        if (self::isToken($t, T_NEW)) {
            if (!self::isName($tokens[$s + 1] ?? null) || ($tokens[$s + 2] ?? null) !== '(' || self::matchClose($c, $s + 2) !== $e - 1) {
                return null;
            }

            return self::resolveClass($c, $tokens[$s + 1][1], $s);
        }
        if (self::isName($t) && self::isToken($tokens[$s + 1] ?? null, T_DOUBLE_COLON) && self::isToken($tokens[$s + 2] ?? null, T_STRING)
            && ($tokens[$s + 3] ?? null) === '(') {
            $type = self::returnType(self::resolveClass($c, $t[1], $s), $tokens[$s + 2][1]);
            $k = self::matchClose($c, $s + 3) + 1;
        } elseif (self::isToken($t, T_VARIABLE)) {
            $type = self::typeOfVariable($c, $t[1], $s, $depth + 1);
            $k = $s + 1;
        } else {
            return null;
        }
        while ($type !== null && $k < $e) {
            if (!(self::isToken($tokens[$k], T_OBJECT_OPERATOR) || self::isToken($tokens[$k], T_NULLSAFE_OBJECT_OPERATOR))
                || !self::isToken($tokens[$k + 1] ?? null, T_STRING)) {
                return null;
            }
            if (($tokens[$k + 2] ?? null) === '(') {
                $type = self::returnType($type, $tokens[$k + 1][1]);
                $k = self::matchClose($c, $k + 2) + 1;
            } else {
                $type = self::propertyType($type, $tokens[$k + 1][1]);
                $k += 2;
            }
        }

        return $k === $e ? $type : null;
    }

    private static function returnType(?string $class, string $method): ?string
    {
        if ($class === null || !(class_exists($class) || interface_exists($class)) || !method_exists($class, $method)) {
            return null;
        }

        return self::namedClass((new \ReflectionMethod($class, $method))->getReturnType(), $class);
    }

    private static function propertyType(string $class, string $property): ?string
    {
        if (!class_exists($class) || !property_exists($class, $property)) {
            return null;
        }

        return self::namedClass((new \ReflectionProperty($class, $property))->getType(), $class);
    }

    private static function namedClass(?\ReflectionType $type, string $class): ?string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }
        if (in_array(strtolower($type->getName()), ['self', 'static'], true)) {
            return $class;
        }

        return $type->isBuiltin() ? null : $type->getName();
    }

    /**
     * How the variable token at $k is used: 'assign' (`$v = ...`), 'read',
     * or 'write' (anything else that may change what it holds).
     *
     * @param array<string, mixed> $c
     */
    private static function classify(array $c, int $k): string
    {
        $tokens = $c['tokens'];
        $prev = $tokens[$k - 1] ?? null;
        $next = $tokens[$k + 1] ?? null;
        foreach ([T_INC, T_DEC, T_STATIC, T_GLOBAL, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG] as $id) {
            if (self::isToken($prev, $id)) {
                return 'write';
            }
        }
        if ($prev === '&' || self::isToken($next, T_INC) || self::isToken($next, T_DEC)) {
            return 'write';
        }
        for ($p = $c['parent'][$k]; $p !== null; $p = $c['parent'][$p]) {
            $before = $tokens[$p - 1] ?? null;
            if ($tokens[$p] === '[' && (($tokens[self::matchClose($c, $p) + 1] ?? null) === '=' || self::isToken($before, T_AS))) {
                return 'write'; // destructuring
            }
            if ($tokens[$p] === '(' && (self::isToken($before, T_LIST) || self::isToken($before, T_CATCH) || self::isToken($before, T_UNSET))) {
                return 'write';
            }
            if ($tokens[$p] === '(' && self::isToken($before, T_FOREACH)) {
                for ($j = $p + 1; $j < $k; $j++) {
                    if (self::isToken($tokens[$j], T_AS)) {
                        return 'write';
                    }
                }
            }
        }
        if ($next === '=') {
            return 'assign';
        }
        if (is_array($next) && in_array($next[0], self::ASSIGN_OPERATORS, true)) {
            return 'write';
        }
        $p = $c['parent'][$k];
        if ($p !== null && $tokens[$p] === '(' && in_array($prev, ['(', ','], true) && in_array($next, [')', ','], true)) {
            // A direct call argument: methods, static methods, and constructors take no reference
            // (testNoCoreFunctionTakesAParameterByReference); a plain or dynamic function call may.
            $callee = $tokens[$p - 1] ?? null;
            $before = $tokens[$p - 2] ?? null;
            $isMember = false;
            foreach ([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION, T_FN] as $id) {
                $isMember = $isMember || self::isToken($before, $id);
            }
            if ((self::isName($callee) && !$isMember) || self::isToken($callee, T_VARIABLE) || $callee === ')' || $callee === ']') {
                return 'write';
            }
        }

        return 'read';
    }

    /**
     * Token indexes of $variable in the body of scope $index, outside nested functions.
     *
     * @param array<string, mixed> $c
     * @return list<int>
     */
    private static function occurrences(array $c, int $index, string $variable): array
    {
        $tokens = $c['tokens'];
        $scope = $c['scopes'][$index];
        $nestedEnd = [];
        foreach ($c['scopes'] as $n => $nested) {
            if ($n !== $index) {
                $nestedEnd[$nested['start']] = $nested['bodyEnd'];
            }
        }
        $found = [];
        for ($k = $scope['bodyStart']; $k <= $scope['bodyEnd']; $k++) {
            if (isset($nestedEnd[$k])) {
                $k = $nestedEnd[$k];

                continue;
            }
            if (self::isToken($tokens[$k], T_VARIABLE) && $tokens[$k][1] === $variable) {
                $found[] = $k;
            }
        }

        return $found;
    }

    /**
     * The index just after the expression that starts at $s: the first
     * top-level `;`, `,`, or `=>`, or the bracket that closes around it.
     *
     * @param array<string, mixed> $c
     */
    private static function expressionEnd(array $c, int $s): int
    {
        $tokens = $c['tokens'];
        for ($k = $s; $k < count($tokens); $k++) {
            $t = $tokens[$k];
            if (self::isOpen($t)) {
                $k = self::matchClose($c, $k);
            } elseif (in_array($t, [';', ',', ')', ']', '}'], true) || self::isToken($t, T_DOUBLE_ARROW)) {
                return $k;
            }
        }

        return count($tokens);
    }

    /**
     * Tokens, bracket matches and parents, namespace, imports, classes, and
     * functions (named functions and methods, closures, arrow functions).
     *
     * @return array<string, mixed>
     */
    private static function context(string $source): array
    {
        $tokens = self::tokens($source);
        $match = [];
        $parent = [];
        $stack = [];
        foreach ($tokens as $k => $t) {
            $parent[$k] = $stack === [] ? null : $stack[count($stack) - 1];
            if (self::isOpen($t)) {
                $stack[] = $k;
            } elseif (in_array($t, [')', ']', '}'], true) && $stack !== []) {
                $open = array_pop($stack);
                $match[$open] = $k;
                $match[$k] = $open;
            }
        }
        foreach ($stack as $open) {
            $match[$open] = count($tokens) - 1;
        }
        $c = ['tokens' => $tokens, 'match' => $match, 'parent' => $parent, 'namespace' => '', 'uses' => [], 'classes' => [], 'scopes' => []];

        foreach ($tokens as $k => $t) {
            if (self::isToken($t, T_NAMESPACE) && self::isName($tokens[$k + 1] ?? null)) {
                $c['namespace'] = $tokens[$k + 1][1];
            }
            if (self::isToken($t, T_USE) && $parent[$k] === null && self::isName($tokens[$k + 1] ?? null)) {
                $name = ltrim($tokens[$k + 1][1], '\\');
                $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
                if (self::isToken($tokens[$k + 2] ?? null, T_AS) && self::isToken($tokens[$k + 3] ?? null, T_STRING)) {
                    $alias = $tokens[$k + 3][1];
                }
                $c['uses'][strtolower($alias)] = $name;
            }
            if (self::isToken($t, T_CLASS) && !self::isToken($tokens[$k - 1] ?? null, T_NEW) && !self::isToken($tokens[$k - 1] ?? null, T_DOUBLE_COLON)
                && self::isToken($tokens[$k + 1] ?? null, T_STRING)) {
                $b = $k;
                while ($b < count($tokens) - 1 && $tokens[$b] !== '{') {
                    $b++;
                }
                $c['classes'][] = [ltrim($c['namespace'] . '\\' . $tokens[$k + 1][1], '\\'), $b, self::matchClose($c, $b)];
            }
        }
        foreach ($tokens as $f => $t) {
            if (!self::isToken($t, T_FUNCTION) && !self::isToken($t, T_FN)) {
                continue;
            }
            $j = $f + 1;
            if (in_array($tokens[$j] ?? null, ['&'], true) || self::isToken($tokens[$j] ?? null, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
                $j++;
            }
            // A method name may be a reserved word (list, empty): any token before the parameters.
            $named = is_array($tokens[$j] ?? null) && ($tokens[$j + 1] ?? null) === '(';
            if ($named) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            $paramsClose = self::matchClose($c, $j);
            $params = self::parameters($c, $j, $paramsClose);
            $k = $paramsClose + 1;
            $uses = [];
            if (self::isToken($tokens[$k] ?? null, T_USE) && ($tokens[$k + 1] ?? null) === '(') {
                $usesClose = self::matchClose($c, $k + 1);
                for ($u = $k + 2; $u < $usesClose; $u++) {
                    if (self::isToken($tokens[$u], T_VARIABLE)) {
                        $uses[$tokens[$u][1]] = $tokens[$u - 1] === '&' || self::isToken($tokens[$u - 1], T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG);
                    }
                }
                $k = $usesClose + 1;
            }
            if (self::isToken($t, T_FN)) {
                while ($k < count($tokens) - 1 && !self::isToken($tokens[$k], T_DOUBLE_ARROW)) {
                    $k++;
                }
                $bodyStart = $k + 1;
                $bodyEnd = self::expressionEnd($c, $bodyStart) - 1;
                $kind = 'arrow';
            } else {
                while ($k < count($tokens) - 1 && $tokens[$k] !== '{' && $tokens[$k] !== ';') {
                    $k++;
                }
                if ($tokens[$k] !== '{') {
                    continue; // abstract or interface method
                }
                $bodyStart = $k;
                $bodyEnd = self::matchClose($c, $k);
                $kind = $named ? 'named' : 'closure';
            }
            $class = null;
            foreach ($c['classes'] as [$name, $open, $close]) {
                if ($f > $open && $f < $close) {
                    $class = $name;
                }
            }
            $c['scopes'][] = ['start' => $f, 'kind' => $kind, 'params' => $params, 'uses' => $uses, 'bodyStart' => $bodyStart, 'bodyEnd' => $bodyEnd, 'class' => $class];
        }

        return $c;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, array{type: ?string, byRef: bool}>
     */
    private static function parameters(array $c, int $open, int $close): array
    {
        $tokens = $c['tokens'];
        $params = [];
        $segment = [];
        for ($k = $open + 1; $k <= $close; $k++) {
            if ($k < $close && !($tokens[$k] === ',' && $c['parent'][$k] === $open)) {
                $segment[] = $k;

                continue;
            }
            $type = [];
            $byRef = false;
            $variadic = false;
            foreach ($segment as $j) {
                $t = $tokens[$j];
                if (self::isToken($t, T_VARIABLE)) {
                    $resolved = null;
                    if (!$variadic && count($type) === 1 && self::isName($type[0])) {
                        $resolved = self::resolveClass($c, $type[0][1], $j);
                    } elseif (!$variadic && count($type) === 2 && $type[0] === '?' && self::isName($type[1])) {
                        $resolved = self::resolveClass($c, $type[1][1], $j);
                    }
                    $params[$t[1]] = ['type' => $resolved, 'byRef' => $byRef];

                    break;
                }
                if ($t === '&' || self::isToken($t, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) || self::isToken($t, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
                    $byRef = true;
                } elseif (self::isToken($t, T_ELLIPSIS)) {
                    $variadic = true;
                } elseif (!(is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true))) {
                    $type[] = $t;
                }
            }
            $segment = [];
        }

        return $params;
    }

    /** @param array<string, mixed> $c */
    private static function resolveClass(array $c, string $name, int $at): ?string
    {
        $lower = strtolower($name);
        if ($lower === 'self' || $lower === 'static') {
            $index = self::scopeAt($c, $at);

            return $index === null ? null : $c['scopes'][$index]['class'];
        }
        if (in_array($lower, ['string', 'int', 'float', 'bool', 'array', 'mixed', 'callable', 'iterable', 'object', 'void', 'null', 'never', 'false', 'true', 'parent'], true)) {
            return null;
        }
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = explode('\\', $name)[0];
        if (isset($c['uses'][strtolower($first)])) {
            return $c['uses'][strtolower($first)] . substr($name, strlen($first));
        }

        return ltrim($c['namespace'] . '\\' . $name, '\\');
    }

    /** @param array<string, mixed> $c */
    private static function scopeAt(array $c, int $at): ?int
    {
        $best = null;
        foreach ($c['scopes'] as $index => $scope) {
            if ($at >= $scope['bodyStart'] && $at <= $scope['bodyEnd']
                && ($best === null || $scope['bodyStart'] >= $c['scopes'][$best]['bodyStart'])) {
                $best = $index;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $c */
    private static function allowedStatic(array $c, string $class, string $method, int $at): bool
    {
        $resolved = strtolower((string) self::resolveClass($c, $class, $at));
        $method = strtolower($method);

        return $resolved === 'tiffindb\core\schema\identifier'
            || ($resolved === 'tiffindb\core\storage\database' && $method === 'placeholders')
            || ($resolved === 'tiffindb\core\schema\columndefinition' && $method === 'of');
    }

    /**
     * Rule 5 operand that starts at $i: start, index after, and 'literal', 'allowed', or 'other'.
     *
     * @param array<string, mixed> $c
     * @return array{0: int, 1: int, 2: string}
     */
    private static function forwardWithStart(array $c, int $i): array
    {
        $tokens = $c['tokens'];
        $token = $tokens[$i] ?? null;
        if (self::isToken($token, T_CONSTANT_ENCAPSED_STRING)) {
            return [$i, $i + 1, 'literal'];
        }
        if (self::isName($token) && self::isToken($tokens[$i + 1] ?? null, T_DOUBLE_COLON)
            && self::isToken($tokens[$i + 2] ?? null, T_STRING) && ($tokens[$i + 3] ?? null) === '(') {
            return [$i, self::matchClose($c, $i + 3) + 1, self::allowedStatic($c, $token[1], $tokens[$i + 2][1], $i) ? 'allowed' : 'other'];
        }
        if (self::isToken($token, T_VARIABLE)) {
            if (self::isProvenSqlCall($c, $i)) {
                return [$i, $i + 5, 'allowed'];
            }
            $j = $i + 1;
            while (self::isToken($tokens[$j] ?? null, T_OBJECT_OPERATOR) && self::isToken($tokens[$j + 1] ?? null, T_STRING)) {
                $j += 2;
            }

            return [$i, $j, 'other'];
        }

        return [$i, $i + 1, 'other'];
    }

    /**
     * Rule 5 operand that ends at $j: its start index and kind.
     *
     * @param array<string, mixed> $c
     * @return array{0: int, 1: string}
     */
    private static function backward(array $c, int $j): array
    {
        $tokens = $c['tokens'];
        $token = $tokens[$j] ?? null;
        if (self::isToken($token, T_CONSTANT_ENCAPSED_STRING)) {
            return [$j, 'literal'];
        }
        if ($token !== ')') {
            return [$j, 'other'];
        }
        $open = self::matchClose($c, $j);
        $name = $tokens[$open - 1] ?? null;
        $before = $tokens[$open - 2] ?? null;
        if (self::isToken($name, T_STRING) && self::isToken($before, T_DOUBLE_COLON) && self::isName($tokens[$open - 3] ?? null)) {
            return [$open - 3, self::allowedStatic($c, $tokens[$open - 3][1], $name[1], $open - 3) ? 'allowed' : 'other'];
        }
        if (self::isToken($name, T_STRING) && self::isToken($before, T_OBJECT_OPERATOR)) {
            if ($open + 1 === $j && self::isProvenSqlCall($c, $open - 3)) {
                return [$open - 3, 'allowed'];
            }
            $k = $open - 2;
            while (self::isToken($tokens[$k] ?? null, T_OBJECT_OPERATOR) && is_array($tokens[$k - 1] ?? null)) {
                if ($tokens[$k - 1][0] === T_VARIABLE) {
                    return [$k - 1, 'other'];
                }
                if ($tokens[$k - 1][0] !== T_STRING) {
                    break;
                }
                $k -= 2;
            }
        }

        return [$open, 'other'];
    }

    /** @param list<mixed> $tokens */
    private static function lineOf(array $tokens, int $i): int
    {
        for ($j = $i; $j >= 0; $j--) {
            if (is_array($tokens[$j] ?? null)) {
                return $tokens[$j][2];
            }
        }

        return 0;
    }

    private static function isToken(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    private static function isName(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    private static function isOpen(mixed $token): bool
    {
        return in_array($token, ['(', '[', '{'], true) || self::isToken($token, T_CURLY_OPEN) || self::isToken($token, T_DOLLAR_OPEN_CURLY_BRACES);
    }

    /** @param list<mixed> $tokens */
    private static function isMethodCall(array $tokens, int $i): bool
    {
        $before = $tokens[$i - 1] ?? null;

        return (self::isToken($before, T_OBJECT_OPERATOR) || self::isToken($before, T_NULLSAFE_OBJECT_OPERATOR)) && ($tokens[$i + 1] ?? null) === '(';
    }

    /** @param list<mixed> $tokens */
    private static function isMemberName(array $tokens, int $i): bool
    {
        $before = $tokens[$i - 1] ?? null;

        return is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    /** @param array<string, mixed> $c */
    private static function matchClose(array $c, int $bracket): int
    {
        return $c['match'][$bracket] ?? count($c['tokens']) - 1;
    }
}
