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
 * Every SQL string in the schema engine and the trash (M1 spec section
 * 3.3, ADR 0010 decision 2) is built only from string literals and from
 * names that come from Identifier. Read with token_get_all, these rules
 * hold in every PHP file under src/Core/Schema/ (except the field types,
 * which build no SQL) and in src/Core/Query/Trash.php:
 *
 * 1. No interpolated strings, heredocs, or nowdocs at all.
 * 2. No `.=`, and no sprintf, vsprintf, printf, implode, join, str_repeat,
 *    strtr, or str_replace: SQL is never assembled by string functions.
 * 3. The whole SQL argument of every `->run(`, `->all(`, `->one(`, and
 *    `->value(` call (up to its top-level `,` or `)`) is a `.` chain whose
 *    every operand is a string literal; a call `Identifier::<method>(...)`,
 *    `Database::placeholders(...)`, or `ColumnDefinition::of(...)` (class
 *    names resolved through the file's imports); or `$v->sql()` where the
 *    scan shows that $v holds a TableDef, FieldDef, or RelationDef: a
 *    parameter of that type, a by-value capture of one, or a local whose
 *    every assignment is a `new`, a static call, or a `->` chain whose
 *    declared types (read by reflection) lead to that class, and which is
 *    never otherwise written, taken by reference, or passed to a function.
 *    The arguments of the allowed calls are not restricted: Identifier
 *    validates them against the key pattern at run time.
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
 */
#[CoversNothing]
final class SqlStringsTest extends TestCase
{
    /** File => loop variables that may be passed as SQL (rule 4). */
    public const ALLOWED_VARIABLES = [
        'src/Core/Schema/ProjectFile.php' => ['$statement'],                 // foreach (self::DDL as $statement)
        'src/Core/Schema/FormatMigrations.php' => ['$statement', '$pragma'], // STEPS and VERSION_PRAGMAS
    ];

    private const SQL_WORD = '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|PRAGMA|VACUUM|FROM|WHERE|INTO|VALUES|INDEX|TABLE|COLUMN|JOIN|ORDER|LIMIT|SET|IN|AND|OR|ON|NULL)\b/i';
    private const STRING_BUILDERS = ['sprintf', 'vsprintf', 'printf', 'implode', 'join', 'str_repeat', 'strtr', 'str_replace'];
    private const QUERY_METHODS = ['run', 'all', 'one', 'value'];
    private const PDO_METHODS = ['prepare', 'exec', 'query'];
    private const SQL_DEFS = ['TableDef.php', 'FieldDef.php', 'RelationDef.php'];
    private const DEF_CLASSES = ['TiffinDB\Core\Schema\TableDef', 'TiffinDB\Core\Schema\FieldDef', 'TiffinDB\Core\Schema\RelationDef'];
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
        $files[] = 'src/Core/Query/Trash.php';
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
        self::assertContains('src/Core/Query/Trash.php', $files);
        self::assertNotContains('src/Core/Schema/FieldTypes.php', $files);
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
        self::assertSame([], self::violations(RepoFiles::read($file), self::ALLOWED_VARIABLES[$file] ?? []));
    }

    public function testOnlyTheDefinitionClassesHaveSqlMethods(): void
    {
        foreach (self::scannedFiles() as $file) {
            $source = RepoFiles::read($file);
            $has = preg_match('/function sql\(\): string\s*\{(.*?)\n    \}/s', $source, $match) === 1;
            if (in_array(basename($file), self::SQL_DEFS, true)) {
                self::assertTrue($has, $file);
                self::assertMatchesRegularExpression('/^\s*(?:if \(!\$this->hasColumn\) \{\s*throw new \\\\LogicException\([^)]*\);\s*\}\s*)?return Identifier::(table|column|joinTable)\(\$this->key\);\s*$/', $match[1], $file);
            } else {
                self::assertStringNotContainsString('function sql(', $source, $file);
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
        yield 'allowed loop variable over a constant entry' => ['$f = static function (Database $db) use ($version): void { foreach (self::STEPS[$version] as $statement) { $db->run($statement); } };'];
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
     * @return list<string> one line per violation
     */
    public static function violations(string $source, array $allowedVariables): array
    {
        $c = self::context($source);
        $tokens = $c['tokens'];
        $violations = [];
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
            if (self::isToken($token, T_STRING) && self::isMethodCall($tokens, $i)) {
                $method = $token[1];
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
        $tokens = $c['tokens'];

        return self::isToken($tokens[$s] ?? null, T_VARIABLE) && self::isToken($tokens[$s + 1] ?? null, T_OBJECT_OPERATOR)
            && self::isToken($tokens[$s + 2] ?? null, T_STRING) && $tokens[$s + 2][1] === 'sql'
            && ($tokens[$s + 3] ?? null) === '(' && ($tokens[$s + 4] ?? null) === ')'
            && in_array(self::typeOfVariable($c, $tokens[$s][1], $s, 0), self::DEF_CLASSES, true);
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
        $resolved = self::resolveClass($c, $class, $at);

        return $resolved === 'TiffinDB\Core\Schema\Identifier'
            || ($resolved === 'TiffinDB\Core\Storage\Database' && $method === 'placeholders')
            || ($resolved === 'TiffinDB\Core\Schema\ColumnDefinition' && $method === 'of');
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
