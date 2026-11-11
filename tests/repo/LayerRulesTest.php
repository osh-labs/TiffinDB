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

/**
 * T-REPO-7: no file under src/Core/ mentions the TiffinDB\Web or
 * TiffinDB\Mcp namespaces, or reads request superglobals (ADR 0008
 * decision 1). Mentions count anywhere in the file, comments included.
 *
 * While src/Core/ does not exist (M0), the rule passes trivially and the
 * data set name says so; the test is never skipped.
 */
#[CoversNothing]
final class LayerRulesTest extends TestCase
{
    private const CORE = 'src/Core';

    /** Pattern => description of what it forbids. */
    private const FORBIDDEN = [
        '/TiffinDB\\\\{1,2}(?:Web|Mcp)(?![A-Za-z0-9_])/i' => 'a reference to TiffinDB\\Web or TiffinDB\\Mcp',
        '/TiffinDB\\\\{1,2}\{(?:[^}]*,)?\s*(?:Web|Mcp)(?![A-Za-z0-9_])/i' => 'a group use of TiffinDB\\Web or TiffinDB\\Mcp',
        '/\$(?:_GET|_POST|_SERVER|_COOKIE|_FILES|_REQUEST|_SESSION|_ENV|GLOBALS)(?![A-Za-z0-9_])/' => 'a superglobal',
        '/\bfilter_input(?:_array)?\s*\(/i' => 'reading request input through filter_input',
    ];

    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                /** @var SplFileInfo $entry */
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temp);
            $this->temp = null;
        }
    }

    // ---------------------------------------------------------------- rules

    /**
     * What a file's contents break, as a list of descriptions.
     *
     * @return list<string>
     */
    public static function problems(string $contents): array
    {
        $found = [];
        foreach (self::FORBIDDEN as $pattern => $description) {
            if (preg_match($pattern, $contents) === 1) {
                $found[] = $description;
            }
        }

        return $found;
    }

    /**
     * Violations in every file below `<root>/src/Core`, as "path: reason".
     *
     * @return list<string>
     */
    public static function violationsBelow(string $root): array
    {
        $core = $root . '/' . self::CORE;
        if (!is_dir($core)) {
            return [];
        }
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException("Cannot read: {$path}");
            }
            foreach (self::problems($contents) as $problem) {
                $found[] = substr($path, strlen($root) + 1) . ": {$problem}";
            }
        }

        return $found;
    }

    // ------------------------------------------------------- the repository

    /** @return iterable<string, array{?string}> */
    public static function coreFiles(): iterable
    {
        if (!is_dir(RepoFiles::path(self::CORE))) {
            yield self::CORE . '/ does not exist yet: the rule passes trivially' => [null];

            return;
        }
        $files = RepoFiles::all(self::CORE);
        if ($files === []) {
            yield self::CORE . '/ contains no files yet: the rule passes trivially' => [null];

            return;
        }
        foreach ($files as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('coreFiles')]
    public function testCoreDoesNotDependOnWebOrMcpOrSuperglobals(?string $path): void
    {
        if ($path === null) {
            self::assertSame(
                [],
                RepoFiles::all(self::CORE),
                self::CORE . '/ has no files yet, so there is nothing to check.',
            );

            return;
        }

        self::assertSame(
            [],
            self::problems(RepoFiles::read($path)),
            "{$path} breaks the core layer rule (ADR 0008 decision 1).",
        );
    }

    // ------------------------------------------------------------ fixtures

    /** @return iterable<string, array{string, list<string>}> */
    public static function sources(): iterable
    {
        $head = "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Core\\Storage;\n\n";
        $web = 'a reference to TiffinDB\\Web or TiffinDB\\Mcp';

        yield 'clean' => [$head . "use TiffinDB\\Core\\Schema\\Field;\n\nfinal class Store\n{\n    private array \$server = [];\n}\n", []];
        yield 'similar names are fine' => [$head . "use TiffinDB\\Core\\Webhooks\\Hook;\n// TiffinDB\\WebKit is not a layer.\n\$_GETTER = 1;\n", []];
        yield 'use of the web layer' => [$head . "use TiffinDB\\Web\\View;\n", [$web]];
        yield 'fully qualified web name' => [$head . "\$x = new \\TiffinDB\\Web\\Request();\n", [$web]];
        yield 'web name in a string' => [$head . "\$class = 'TiffinDB\\\\Web\\\\Kernel';\n", [$web]];
        yield 'web name in a comment' => [$head . "// Called from TiffinDB\\Web.\n", [$web]];
        yield 'mcp layer, any case' => [$head . "use tiffindb\\mcp\\Server;\n", [$web]];
        yield 'group use' => [$head . "use TiffinDB\\{Web\\View, Core\\Query\\Q};\n", ['a group use of TiffinDB\\Web or TiffinDB\\Mcp']];
        yield 'group use, web not first' => [$head . "use TiffinDB\\{Core\\Query\\Q, Web\\View};\n", ['a group use of TiffinDB\\Web or TiffinDB\\Mcp']];
        yield 'group use, mcp across lines' => [$head . "use TiffinDB\\{\n    Core\\Query\\Q,\n    Mcp\\Server,\n};\n", ['a group use of TiffinDB\\Web or TiffinDB\\Mcp']];
        yield 'group use of core only' => [$head . "use TiffinDB\\{Core\\Query\\Q, Core\\Schema\\Web};\n", []];
        yield 'server superglobal' => [$head . "\$m = \$_SERVER['REQUEST_METHOD'];\n", ['a superglobal']];
        yield 'globals array' => [$head . "\$g = \$GLOBALS['x'];\n", ['a superglobal']];
        yield 'session superglobal' => [$head . "\$_SESSION['k'] = 1;\n", ['a superglobal']];
        yield 'filter_input' => [$head . "\$q = filter_input(INPUT_GET, 'q');\n", ['reading request input through filter_input']];
    }

    /** @param list<string> $expected */
    #[DataProvider('sources')]
    public function testForbiddenReferencesAreDetected(string $source, array $expected): void
    {
        self::assertSame($expected, self::problems($source));
    }

    public function testViolationsInATemporaryCoreTreeAreReportedByPath(): void
    {
        $root = $this->tempRoot();
        self::assertSame([], self::violationsBelow($root), 'No src/Core/ directory: nothing to report.');

        self::write($root, 'src/Core/Storage/Clean.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Core\\Storage;\n");
        self::write($root, 'src/Core/Query/Leaky.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Core\\Query;\n\nuse TiffinDB\\Web\\Request;\n\n\$p = \$_GET['p'] ?? null;\n");
        self::write($root, 'src/Web/Allowed.php', "<?php\n\ndeclare(strict_types=1);\n\n\$m = \$_SERVER['REQUEST_METHOD'];\n");

        self::assertSame([
            'src/Core/Query/Leaky.php: a reference to TiffinDB\\Web or TiffinDB\\Mcp',
            'src/Core/Query/Leaky.php: a superglobal',
        ], self::violationsBelow($root));
    }

    // -------------------------------------------------------------- helpers

    private function tempRoot(): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-layer-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }

        return $this->temp;
    }

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
