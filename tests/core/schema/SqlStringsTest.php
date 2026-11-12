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
 * 3. In every chain of `.` concatenations that holds an SQL keyword, each
 *    operand is a string literal, a call `Identifier::<method>(...)`,
 *    `Database::placeholders(...)`, `ColumnDefinition::of(...)`, or a call
 *    `$object->sql()` (and the only sql() methods are those of TableDef,
 *    FieldDef, and RelationDef, each returning an Identifier call). The
 *    arguments of those calls are not restricted: Identifier validates
 *    them against the key pattern at run time.
 * 4. The SQL argument of every `->run(`, `->all(`, `->one(`, and
 *    `->value(` call starts with one of those operands, never a variable,
 *    except the named loop variables in ALLOWED_VARIABLES, which iterate
 *    over constant lists of literal statements.
 * 5. No direct PDO calls (`->prepare(`, `->exec(`, `->query(`).
 *
 * Every value therefore reaches SQLite as a bound parameter. The negative
 * cases below check that the scan catches each kind of mistake.
 */
#[CoversNothing]
final class SqlStringsTest extends TestCase
{
    /** File => variables that may be passed as SQL, each a loop over constant literal statements. */
    public const ALLOWED_VARIABLES = [
        'src/Core/Schema/ProjectFile.php' => ['$statement'],          // foreach (self::DDL as $statement)
        'src/Core/Schema/FormatMigrations.php' => ['$statement', '$pragma'], // steps and VERSION_PRAGMAS
    ];

    private const SQL_WORD = '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|PRAGMA|VACUUM|FROM|WHERE|INTO|VALUES|INDEX|TABLE|COLUMN|JOIN|ORDER|LIMIT|SET|IN|AND|OR|ON|NULL)\b/';
    private const STRING_BUILDERS = ['sprintf', 'vsprintf', 'printf', 'implode', 'join', 'str_repeat', 'strtr', 'str_replace'];
    private const QUERY_METHODS = ['run', 'all', 'one', 'value'];
    private const PDO_METHODS = ['prepare', 'exec', 'query'];
    private const SQL_DEFS = ['TableDef.php', 'FieldDef.php', 'RelationDef.php'];

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
    }

    #[DataProvider('badSources')]
    public function testTheScanCatches(string $code, string $expected): void
    {
        $violations = self::violations("<?php\n" . $code . "\n", []);
        self::assertNotSame([], $violations, $code);
        self::assertStringContainsString($expected, implode("\n", $violations));
    }

    /** @return iterable<string, array{string}> */
    public static function goodSources(): iterable
    {
        yield 'literal' => ['$db->run(\'DELETE FROM "_tiffin_meta" WHERE "key" = ?\', [$k]);'];
        yield 'identifier' => ['$db->run(\'SELECT * FROM \' . Identifier::table($k) . \' WHERE "id" IN (\' . Database::placeholders(count($ids)) . \')\', $ids);'];
        yield 'definition objects' => ['$this->db->run(\'ALTER TABLE \' . $table->sql() . \' ADD COLUMN \' . $field->sql() . \' \' . ColumnDefinition::of($type));'];
        yield 'index of a name variable' => ['$db->run(\'CREATE INDEX \' . Identifier::index(Identifier::tableName($key), \'trash\') . \' ON \' . Identifier::table($key) . \' ("x")\');'];
        yield 'message' => ['throw new \RuntimeException(\'Give 1 to \' . self::MAX . \' ids.\');'];
        yield 'zero-argument value' => ['$x = $checked->value();'];
        yield 'allowed loop variable' => ['foreach (self::DDL as $statement) { $db->run($statement); }'];
    }

    #[DataProvider('goodSources')]
    public function testTheScanAccepts(string $code): void
    {
        self::assertSame([], self::violations("<?php\n" . $code . "\n", ['$statement']));
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
        $tokens = self::tokens($source);
        $violations = [];
        $line = static fn (int $i): int => self::lineOf($tokens, $i);

        foreach ($tokens as $i => $token) {
            if ($token === '"' || (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $violations[] = 'line ' . $line($i) . ': interpolated string';
            }
            if (is_array($token) && in_array($token[0], [T_START_HEREDOC], true)) {
                $violations[] = 'line ' . $line($i) . ': heredoc or nowdoc';
            }
            if (is_array($token) && $token[0] === T_CONCAT_EQUAL) {
                $violations[] = 'line ' . $line($i) . ': concatenate-assign (.=)';
            }
            if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), self::STRING_BUILDERS, true)
                && ($tokens[$i + 1] ?? null) === '(' && !self::isMemberName($tokens, $i)) {
                $violations[] = 'line ' . $line($i) . ': string builder ' . strtolower($token[1]) . '()';
            }
            if (is_array($token) && $token[0] === T_STRING && self::isMethodCall($tokens, $i)) {
                $method = $token[1];
                if (in_array($method, self::PDO_METHODS, true)) {
                    $violations[] = 'line ' . $line($i) . ': direct PDO call ->' . $method . '()';
                }
                if (in_array($method, self::QUERY_METHODS, true) && ($tokens[$i + 2] ?? null) !== ')') {
                    [, $kind] = self::forward($tokens, $i + 2);
                    $first = $tokens[$i + 2];
                    if ($kind === 'other' && !(is_array($first) && $first[0] === T_VARIABLE && in_array($first[1], $allowedVariables, true)
                        && in_array($tokens[$i + 3] ?? null, [')', ','], true))) {
                        $violations[] = 'line ' . $line($i) . ': SQL argument of ->' . $method . '() is a variable or expression, not literal SQL';
                    }
                }
            }
        }

        // Concatenation chains: union operands joined by '.'.
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
            [$leftStart, $leftKind] = self::backward($tokens, $i - 1);
            [$rightStart, , $rightKind] = [...self::forwardWithStart($tokens, $i + 1)];
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

    /** @param list<mixed> $tokens */
    private static function isMethodCall(array $tokens, int $i): bool
    {
        $before = $tokens[$i - 1] ?? null;

        return is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && ($tokens[$i + 1] ?? null) === '(';
    }

    /** @param list<mixed> $tokens */
    private static function isMemberName(array $tokens, int $i): bool
    {
        $before = $tokens[$i - 1] ?? null;

        return is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: int, 1: string} index after the operand, and 'literal', 'allowed', or 'other'
     */
    private static function forward(array $tokens, int $i): array
    {
        $result = self::forwardWithStart($tokens, $i);

        return [$result[1], $result[2]];
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: int, 1: int, 2: string} start, index after, kind
     */
    private static function forwardWithStart(array $tokens, int $i): array
    {
        $token = $tokens[$i] ?? null;
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [$i, $i + 1, 'literal'];
        }
        if (is_array($token) && $token[0] === T_STRING && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING && ($tokens[$i + 3] ?? null) === '(') {
            $close = self::matchForward($tokens, $i + 3);
            $kind = self::allowedStatic($token[1], $tokens[$i + 2][1]) ? 'allowed' : 'other';

            return [$i, $close + 1, $kind];
        }
        if (is_array($token) && $token[0] === T_VARIABLE) {
            $j = $i + 1;
            $last = null;
            while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_OBJECT_OPERATOR && is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_STRING) {
                $last = $tokens[$j + 1][1];
                $j += 2;
            }
            if ($last === 'sql' && ($tokens[$j] ?? null) === '(' && ($tokens[$j + 1] ?? null) === ')') {
                return [$i, $j + 2, 'allowed'];
            }

            return [$i, $j, 'other'];
        }

        return [$i, $i + 1, 'other'];
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: int, 1: string} start index of the operand ending at $j, and its kind
     */
    private static function backward(array $tokens, int $j): array
    {
        $token = $tokens[$j] ?? null;
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [$j, 'literal'];
        }
        if ($token === ')') {
            $open = self::matchBackward($tokens, $j);
            $name = $tokens[$open - 1] ?? null;
            $before = $tokens[$open - 2] ?? null;
            if (is_array($name) && $name[0] === T_STRING && is_array($before) && $before[0] === T_DOUBLE_COLON
                && is_array($tokens[$open - 3] ?? null) && $tokens[$open - 3][0] === T_STRING) {
                return [$open - 3, self::allowedStatic($tokens[$open - 3][1], $name[1]) ? 'allowed' : 'other'];
            }
            if (is_array($name) && $name[0] === T_STRING && is_array($before) && $before[0] === T_OBJECT_OPERATOR) {
                $k = $open - 2;
                while (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_OBJECT_OPERATOR && is_array($tokens[$k - 1] ?? null)) {
                    if ($tokens[$k - 1][0] === T_VARIABLE) {
                        $start = $k - 1;
                        $empty = $open + 1 === $j;

                        return [$start, $name[1] === 'sql' && $empty ? 'allowed' : 'other'];
                    }
                    if ($tokens[$k - 1][0] !== T_STRING) {
                        break;
                    }
                    $k -= 2;
                }
            }

            return [$open, 'other'];
        }

        return [$j, 'other'];
    }

    private static function allowedStatic(string $class, string $method): bool
    {
        return $class === 'Identifier'
            || ($class === 'Database' && $method === 'placeholders')
            || ($class === 'ColumnDefinition' && $method === 'of');
    }

    /** @param list<mixed> $tokens */
    private static function matchForward(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open; $k < count($tokens); $k++) {
            $depth += match ($tokens[$k]) { '(' => 1, ')' => -1, default => 0 };
            if ($depth === 0) {
                return $k;
            }
        }

        return count($tokens) - 1;
    }

    /** @param list<mixed> $tokens */
    private static function matchBackward(array $tokens, int $close): int
    {
        $depth = 0;
        for ($k = $close; $k >= 0; $k--) {
            $depth += match ($tokens[$k]) { ')' => 1, '(' => -1, default => 0 };
            if ($depth === 0) {
                return $k;
            }
        }

        return 0;
    }
}
