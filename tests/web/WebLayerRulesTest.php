<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-WEB-23: what the web layer may name from the core (ADR 0012 decision 1
 * as amended by P58; M1 spec part 2 section 9).
 *
 * src/Web/CoreServices.php, the composition root, may name any
 * TiffinDB\Core class. Every other PHP file under src/Web/ and every
 * template under templates/ may name:
 *
 * - any class in Core\App, Core\Install, and Core\Error;
 * - from Core\Auth, only the value types and enums in AUTH_ALLOWED;
 * - from Core\Schema, only the value types returned by Core\App
 *   (SCHEMA_ALLOWED), and from Core\Storage only SnapshotInfo.
 *
 * Everything else in Core\Storage, Core\System, Core\Schema, Core\Query (or
 * any other Core namespace) is refused, and so is a name that stops at a
 * namespace (`use TiffinDB\Core\Auth as A;`, `use TiffinDB\Core as C;`),
 * since an alias would hide what follows it.
 *
 * Names are read with token_get_all: `use` statements (group use
 * expanded), qualified and fully qualified names in code, and names
 * written in string literals. Comparison ignores case, as PHP does.
 * Comments are not checked. Known limits: names built at run time
 * (concatenation, interpolation) and reflection are not seen; review
 * covers those.
 */
#[CoversNothing]
final class WebLayerRulesTest extends TestCase
{
    public const COMPOSITION_ROOT = 'src/Web/CoreServices.php';

    /** Core namespaces whose every class the web layer may name. */
    public const OPEN_NAMESPACES = ['App', 'Install', 'Error'];

    /** The Core\Auth value types and enums the web layer may name. */
    public const AUTH_ALLOWED = [
        'Actor', 'SignedIn', 'IssuedSession', 'SessionRecord', 'SignInResult', 'SignInOutcome',
        'Permission', 'Role', 'GrantLevel', 'UserRecord', 'Csrf',
    ];

    /** Value types from other Core namespaces returned by Core\App. */
    public const VALUE_TYPES = [
        'Schema' => ['TableDef', 'FieldDef', 'RelationDef', 'Cardinality'],
        'Storage' => ['SnapshotInfo'],
    ];

    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        $this->temp = null;
    }

    // ---------------------------------------------------------------- rules

    /**
     * Every name in the source that starts with `TiffinDB\Core` (group uses
     * expanded, leading backslash removed), in order.
     *
     * @return list<string>
     */
    public static function coreNames(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        $names = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = str_replace('\\\\', '\\', $token[1]);
                if (preg_match_all('/TiffinDB\\\\Core(?:\\\\[A-Za-z0-9_]+)*\\\\?/i', $text, $found) > 0) {
                    array_push($names, ...$found[0]);
                }

                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $brace = $tokens[$i + 2] ?? null;
            if (is_array($next) && $next[0] === T_NS_SEPARATOR && $brace === '{') {
                $prefix = rtrim($token[1], '\\');
                $i += 3;
                for (; $i < $count && $tokens[$i] !== '}'; $i++) {
                    $inner = $tokens[$i];
                    $previous = $tokens[$i - 1];
                    if (
                        is_array($inner)
                        && in_array($inner[0], [T_STRING, T_NAME_QUALIFIED], true)
                        && !(is_array($previous) && $previous[0] === T_AS)
                    ) {
                        $names[] = $prefix . '\\' . $inner[1];
                    }
                }

                continue;
            }
            $names[] = $token[1];
        }

        $core = [];
        foreach ($names as $name) {
            $name = rtrim(ltrim($name, '\\'), '\\');
            if (preg_match('/^TiffinDB\\\\Core(\\\\|\z)/i', $name) === 1) {
                $core[] = $name;
            }
        }

        return $core;
    }

    /** Whether a non-composition-root web file may name this core name. */
    public static function allowed(string $name): bool
    {
        $parts = explode('\\', $name);
        if (count($parts) < 4) {
            return false;
        }
        $area = strtolower($parts[2]);
        foreach (self::OPEN_NAMESPACES as $open) {
            if ($area === strtolower($open)) {
                return true;
            }
        }
        if (count($parts) !== 4) {
            return false;
        }
        $class = strtolower($parts[3]);
        $lists = ['auth' => self::AUTH_ALLOWED];
        foreach (self::VALUE_TYPES as $namespace => $types) {
            $lists[strtolower($namespace)] = $types;
        }

        return in_array($class, array_map('strtolower', $lists[$area] ?? []), true);
    }

    /**
     * Refused names in one file, given its path relative to the root.
     *
     * @return list<string>
     */
    public static function problems(string $relative, string $source): array
    {
        if ($relative === self::COMPOSITION_ROOT) {
            return [];
        }
        $found = [];
        foreach (self::coreNames($source) as $name) {
            if (!self::allowed($name)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Violations below `<root>/src/Web` and `<root>/templates`, as
     * "path: name".
     *
     * @return list<string>
     */
    public static function violations(string $root): array
    {
        $found = [];
        foreach (['src/Web', 'templates'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::problems($relative, (string) file_get_contents($file->getPathname())) as $name) {
                    $found[] = "{$relative}: {$name}";
                }
            }
        }
        sort($found);

        return $found;
    }

    // ---------------------------------------------------------------- the repository

    public function testTheWebLayerNamesOnlyAllowedCoreClasses(): void
    {
        self::assertSame([], self::violations(RepoFiles::root()));
    }

    public function testTheScanReachesTheWebLayer(): void
    {
        // Positive controls: names that are there today and allowed.
        $kernel = self::coreNames(RepoFiles::read('src/Web/Kernel.php'));
        self::assertContains('TiffinDB\\Core\\Auth\\Csrf', $kernel);
        self::assertContains('TiffinDB\\Core\\Error\\Busy', $kernel);
        $root = self::coreNames(RepoFiles::read(self::COMPOSITION_ROOT));
        self::assertContains('TiffinDB\\Core\\Storage\\SystemClock', $root);
        self::assertContains('TiffinDB\\Core\\System\\SystemDatabase', $root);
    }

    // ---------------------------------------------------------------- the rule itself

    /** @return array<string, array{string}> */
    public static function refusedSources(): array
    {
        $php = "<?php\nnamespace TiffinDB\\Web;\n";

        return [
            'use Storage' => [$php . "use TiffinDB\\Core\\Storage\\Clock;\n"],
            'use System' => [$php . "use TiffinDB\\Core\\System\\SystemDatabase;\n"],
            'use Query' => [$php . "use TiffinDB\\Core\\Query\\Trash;\n"],
            'use Schema editor' => [$php . "use TiffinDB\\Core\\Schema\\SchemaEditor;\n"],
            'use Schema type' => [$php . "use TiffinDB\\Core\\Schema\\Type\\TextType;\n"],
            'use Auth repository' => [$php . "use TiffinDB\\Core\\Auth\\Sessions;\n"],
            'use Auth authenticator' => [$php . "use TiffinDB\\Core\\Auth\\Authenticator;\n"],
            'use Auth hasher' => [$php . "use TiffinDB\\Core\\Auth\\PasswordHasher;\n"],
            'unknown namespace' => [$php . "use TiffinDB\\Core\\Other\\Thing;\n"],
            'lower case' => [$php . "use tiffindb\\core\\storage\\clock;\n"],
            'aliased' => [$php . "use TiffinDB\\Core\\Storage\\Clock as Time;\n"],
            'namespace alias of Auth' => [$php . "use TiffinDB\\Core\\Auth as A;\n"],
            'namespace alias of Core' => [$php . "use TiffinDB\\Core as C;\n"],
            'namespace alias of Storage' => [$php . "use TiffinDB\\Core\\Storage;\n"],
            'group use' => [$php . "use TiffinDB\\Core\\{Auth\\Actor, Storage\\Fs};\n"],
            'group use aliased' => [$php . "use TiffinDB\\Core\\{Storage\\Fs as F};\n"],
            'group use of Auth' => [$php . "use TiffinDB\\Core\\Auth\\{Actor, Users};\n"],
            'fully qualified new' => [$php . "\$x = new \\TiffinDB\\Core\\Storage\\SystemClock();\n"],
            'fully qualified static' => [$php . "\\TiffinDB\\Core\\System\\AuditLog::class;\n"],
            'qualified in a template' => ["<?php\n\$x = TiffinDB\\Core\\Storage\\Fs::remove('x');\n"],
            'type declaration' => [$php . "function f(\\TiffinDB\\Core\\Storage\\Database \$d): void {}\n"],
            'string literal' => [$php . "\$c = 'TiffinDB\\\\Core\\\\Storage\\\\Fs';\n"],
            'double-quoted string' => [$php . "\$c = \"TiffinDB\\\\Core\\\\System\\\\AuditLog\";\n"],
            'string with single backslashes' => [$php . "\$c = 'TiffinDB\\Core\\Storage\\Fs';\n"],
            'interpolated string' => [$php . "\$c = \"TiffinDB\\\\Core\\\\Storage\\\\Fs{\$x}\";\n"],
            'use function' => [$php . "use function TiffinDB\\Core\\Storage\\helper;\n"],
            'Auth deeper name' => [$php . "use TiffinDB\\Core\\Auth\\Actor\\Thing;\n"],
            'Snapshots service' => [$php . "use TiffinDB\\Core\\Storage\\Snapshots;\n"],
            'Schema read model' => [$php . "use TiffinDB\\Core\\Schema\\Schema;\n"],
        ];
    }

    #[DataProvider('refusedSources')]
    public function testRefusedNamesAreFound(string $source): void
    {
        self::assertNotSame([], self::problems('src/Web/Example.php', $source));
        self::assertNotSame([], self::problems('templates/pages/example.php', $source));
    }

    /** @return array<string, array{string}> */
    public static function allowedSources(): array
    {
        $php = "<?php\nnamespace TiffinDB\\Web;\n";

        return [
            'App' => [$php . "use TiffinDB\\Core\\App\\Operations;\nuse TiffinDB\\Core\\App\\Results\\ProjectSummary;\n"],
            'Install' => [$php . "use TiffinDB\\Core\\Install\\Installer;\nuse TiffinDB\\Core\\Install\\InstallState;\n"],
            'Error' => [$php . "use TiffinDB\\Core\\Error\\NotFound;\nuse TiffinDB\\Core\\Error\\Problem;\n"],
            'Auth value types' => [$php . "use TiffinDB\\Core\\Auth\\{Actor, SignedIn, IssuedSession, SessionRecord, SignInResult, SignInOutcome, Permission, Role, GrantLevel, UserRecord, Csrf};\n"],
            'Schema value types' => [$php . "use TiffinDB\\Core\\Schema\\TableDef;\nuse TiffinDB\\Core\\Schema\\FieldDef;\nuse TiffinDB\\Core\\Schema\\RelationDef;\nuse TiffinDB\\Core\\Schema\\Cardinality;\n"],
            'SnapshotInfo' => [$php . "use TiffinDB\\Core\\Storage\\SnapshotInfo;\n"],
            'fully qualified' => [$php . "\$r = \\TiffinDB\\Core\\Auth\\Role::Admin;\n"],
            'case differs' => [$php . "use tiffindb\\core\\auth\\actor;\n"],
            'comment only' => [$php . "// TiffinDB\\Core\\Storage\\Fs is not used here.\n/** @see \\TiffinDB\\Core\\System\\AuditLog */\n"],
            'web names' => [$php . "use TiffinDB\\Web\\View;\nuse TiffinDB\\Tests\\Core\\Thing;\n"],
            'closure use' => [$php . "\$f = function () use (\$x) { return \$x; };\n"],
        ];
    }

    #[DataProvider('allowedSources')]
    public function testAllowedNamesPass(string $source): void
    {
        self::assertSame([], self::problems('src/Web/Example.php', $source));
    }

    public function testOnlyTheCompositionRootMayNameEverything(): void
    {
        $source = "<?php\nuse TiffinDB\\Core\\Storage\\Clock;\nuse TiffinDB\\Core\\System\\SystemDatabase;\n";

        self::assertSame([], self::problems(self::COMPOSITION_ROOT, $source));
        self::assertNotSame([], self::problems('src/Web/Controller/CoreServices.php', $source));
        self::assertNotSame([], self::problems('src/Web/Kernel.php', $source));
    }

    public function testViolationsAreFoundInATemporaryTree(): void
    {
        $this->temp = TempDir::create();
        $root = $this->temp->path();
        mkdir($root . '/src/Web/Controller', 0700, true);
        mkdir($root . '/templates/pages', 0700, true);
        file_put_contents($root . '/src/Web/Controller/Bad.php', "<?php\nuse TiffinDB\\Core\\Auth\\Users;\n");
        file_put_contents($root . '/src/Web/Good.php', "<?php\nuse TiffinDB\\Core\\Auth\\Actor;\n");
        file_put_contents($root . '/src/Web/CoreServices.php', "<?php\nuse TiffinDB\\Core\\Storage\\Fs;\n");
        file_put_contents($root . '/templates/pages/bad.php', "<?php\n\\TiffinDB\\Core\\Storage\\Fs::remove('x');\n");
        file_put_contents($root . '/templates/pages/notes.txt', "TiffinDB\\Core\\Storage\\Fs\n");

        self::assertSame(
            [
                'src/Web/Controller/Bad.php: TiffinDB\\Core\\Auth\\Users',
                'templates/pages/bad.php: TiffinDB\\Core\\Storage\\Fs',
            ],
            self::violations($root),
        );
    }
}
