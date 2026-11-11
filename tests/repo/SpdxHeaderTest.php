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
 * T-REPO-4: SPDX header convention (M0 spec section 13), closed-world.
 *
 * Every file outside the non-scanned paths must either match a header rule
 * for its file type or match an entry of the exemption register below;
 * anything else fails, naming the file. Covers tools/discovery/ and
 * tools/host-check/ like every other directory.
 *
 * Header lines must be exact, consecutive, in the comment syntax of the file
 * type, and within the first six lines. Positions are checked as the spec
 * table states them. One relaxation is recorded here: the design canvas
 * exports under docs/design/model-ui/canvas-source/ place the header after
 * the `<meta charset>` line, so for HTML in that directory only the checked
 * rule is "starts with a doctype, header within the first six lines".
 * Every other HTML file must have the header immediately after the doctype.
 */
#[CoversNothing]
final class SpdxHeaderTest extends TestCase
{
    private const SPDX = 'SPDX-License-Identifier: AGPL-3.0-or-later';
    private const COPYRIGHT = 'Copyright (C) TiffinDB contributors';
    private const WINDOW = 6;
    private const HTML_RELAXED_DIRECTORY = 'docs/design/model-ui/canvas-source/';

    /**
     * The register of exceptions: exactly the entries of spec section 13.
     * Kinds: `path` is an exact repository path; `glob` is matched against
     * the whole path (`*` does not cross `/`); `name` is matched against the
     * file name in any directory.
     *
     * @var list<array{string, string, string}>
     */
    public const EXEMPTIONS = [
        ['path', 'composer.json', 'JSON has no comments; the licence is in its license field (T-REPO-2).'],
        ['path', 'docs/design/model-ui/canvas-source/canvas.json', 'JSON has no comments; design canvas data.'],
        ['name', '*.woff2', 'Binary third-party font; covered by the LICENSE.txt beside it and docs/dependencies.md.'],
        ['glob', 'public/assets/fonts/*/LICENSE.txt', 'Third-party licence text, reproduced verbatim.'],
        ['path', 'LICENSE', 'Canonical licence text; never edited.'],
        ['name', '*.md', 'Documentation, not source; covered by the repository licence.'],
        ['name', '.gitignore', 'Git control file.'],
        ['name', '.gitattributes', 'Git control file.'],
    ];

    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            self::removeTree($this->temp);
            $this->temp = null;
        }
    }

    // ---------------------------------------------------------------- rules

    /** The header rule that applies to a path, or null when no rule applies. */
    public static function ruleFor(string $path): ?string
    {
        $name = basename($path);
        if ($name === '.htaccess') {
            return 'htaccess';
        }
        $lower = strtolower($name);
        if (str_ends_with($lower, '.xml.dist') || str_ends_with($lower, '.xml')) {
            return 'xml';
        }

        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'css' => 'css',
            'js' => 'js',
            'html' => 'html',
            'svg' => 'svg',
            'sh' => 'shell',
            'yml', 'yaml' => 'yaml',
            default => null,
        };
    }

    /** The exemption-register reason for a path, or null if it is not exempt. */
    public static function exemptionFor(string $path): ?string
    {
        foreach (self::EXEMPTIONS as [$kind, $pattern, $reason]) {
            $matches = match ($kind) {
                'path' => $path === $pattern,
                'glob' => fnmatch($pattern, $path, FNM_PATHNAME),
                'name' => fnmatch($pattern, basename($path)),
                default => throw new RuntimeException("Unknown exemption kind: {$kind}"),
            };
            if ($matches) {
                return $reason;
            }
        }

        return null;
    }

    /** Why a file breaks the convention, or null if it complies. */
    public static function problem(string $path, string $contents): ?string
    {
        if (self::exemptionFor($path) !== null) {
            return null;
        }
        $rule = self::ruleFor($path);
        if ($rule === null) {
            return 'no header rule and no exemption covers this file type (add a rule or an exemption with a reason)';
        }

        $lines = array_slice(preg_split('/\r\n|\n|\r/', $contents) ?: [], 0, self::WINDOW + 1);
        $line = static fn (int $i): string => $lines[$i] ?? '';

        if ($rule === 'php') {
            $start = str_starts_with($line(0), '#!') ? 1 : 0;
            if ($line($start) !== '<?php') {
                return $start === 1
                    ? 'the line after "#!" must be exactly "<?php"'
                    : 'must start with a line that is exactly "<?php" (or "#!" then "<?php")';
            }
            $expected = ['/*', ' * ' . self::SPDX, ' * ' . self::COPYRIGHT];
            foreach ($expected as $offset => $text) {
                if ($line($start + 1 + $offset) !== $text) {
                    return 'the block comment "/*", " * ' . self::SPDX . '", " * ' . self::COPYRIGHT
                        . '" must follow "<?php" immediately';
                }
            }

            return null;
        }

        $wrap = match ($rule) {
            'css' => static fn (string $s): string => "/* {$s} */",
            'js' => static fn (string $s): string => "// {$s}",
            'html', 'svg', 'xml' => static fn (string $s): string => "<!-- {$s} -->",
            'htaccess', 'shell', 'yaml' => static fn (string $s): string => "# {$s}",
        };

        $at = null;
        for ($i = 0; $i + 1 < self::WINDOW; $i++) {
            if ($line($i) === $wrap(self::SPDX) && $line($i + 1) === $wrap(self::COPYRIGHT)) {
                $at = $i;
                break;
            }
        }
        if ($at === null) {
            return 'the lines "' . $wrap(self::SPDX) . '" and "' . $wrap(self::COPYRIGHT)
                . '" must appear, consecutively, within the first ' . self::WINDOW . ' lines';
        }

        $xmlDeclaration = str_starts_with($line(0), '<?xml');

        return match ($rule) {
            'css', 'js', 'htaccess', 'yaml' => $at === 0 ? null : 'the header must be the first two lines',
            'svg' => ($at === 0 || ($at === 1 && $xmlDeclaration))
                ? null
                : 'the header must be the first two lines, or follow the XML declaration immediately',
            'xml' => ($at === 1 && $xmlDeclaration) ? null : 'the header must follow the XML declaration immediately',
            'shell' => ($at === 1 && str_starts_with($line(0), '#!')) ? null : 'the header must follow the "#!" line immediately',
            'html' => match (true) {
                preg_match('/^<!doctype html>$/i', $line(0)) !== 1 => 'the file must start with the doctype line',
                $at === 1, str_starts_with($path, self::HTML_RELAXED_DIRECTORY) => null,
                default => 'the header must follow the doctype line immediately',
            },
        };
    }

    /**
     * Violations among the given files below a root, as "path: reason".
     *
     * @param list<string> $files
     * @return list<string>
     */
    public static function violations(string $root, array $files): array
    {
        $found = [];
        foreach ($files as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            if ($contents === false) {
                throw new RuntimeException("Cannot read: {$relative}");
            }
            $problem = self::problem($relative, $contents);
            if ($problem !== null) {
                $found[] = "{$relative}: {$problem}";
            }
        }

        return $found;
    }

    // ------------------------------------------------------- the repository

    public function testEveryRepositoryFileHasAHeaderOrAnExemption(): void
    {
        $files = RepoFiles::all();
        self::assertNotEmpty($files, 'The repository walk found no files.');

        self::assertSame(
            [],
            self::violations(RepoFiles::root(), $files),
            'Every file needs the SPDX header of its type (M0 spec section 13) or an entry in the exemption register.',
        );
    }

    public function testScanIncludesTheStandaloneToolScripts(): void
    {
        $files = RepoFiles::all();
        foreach (['tools/discovery/discover-structure.php', 'tools/host-check/host-check.php'] as $script) {
            self::assertContains($script, $files, "{$script} must be covered by the SPDX test.");
            self::assertSame('php', self::ruleFor($script));
        }
    }

    public function testExemptionRegisterIsExactlyTheSpecList(): void
    {
        $patterns = array_map(static fn (array $entry): string => $entry[0] . ':' . $entry[1], self::EXEMPTIONS);
        self::assertSame([
            'path:composer.json',
            'path:docs/design/model-ui/canvas-source/canvas.json',
            'name:*.woff2',
            'glob:public/assets/fonts/*/LICENSE.txt',
            'path:LICENSE',
            'name:*.md',
            'name:.gitignore',
            'name:.gitattributes',
        ], $patterns);
        foreach (self::EXEMPTIONS as [, $pattern, $reason]) {
            self::assertNotSame('', trim($reason), "Exemption {$pattern} needs a reason.");
        }
    }

    // ------------------------------------------------- removing any header

    /** @return iterable<string, array{string}> */
    public static function coveredRepositoryFiles(): iterable
    {
        foreach (RepoFiles::all() as $path) {
            if (self::exemptionFor($path) === null && self::ruleFor($path) !== null) {
                yield $path => [$path];
            }
        }
    }

    #[DataProvider('coveredRepositoryFiles')]
    public function testRemovingEitherHeaderLineFromACoveredFileIsDetected(string $path): void
    {
        $original = RepoFiles::read($path);
        self::assertNull(self::problem($path, $original), "{$path} should comply before the mutation.");

        foreach ([self::SPDX, self::COPYRIGHT] as $index => $needle) {
            $lines = preg_split('/(?<=\n)/', $original) ?: [];
            $kept = array_values(array_filter($lines, static fn (string $l): bool => !str_contains($l, $needle)));
            self::assertLessThan(count($lines), count($kept), "{$path} should contain '{$needle}'.");

            $root = $this->tempRoot() . '/case' . $index;
            self::writeFile($root, $path, implode('', $kept));

            $found = self::violations($root, self::listFiles($root));
            self::assertCount(1, $found, "Removing '{$needle}' from {$path} must be detected.");
            self::assertStringStartsWith("{$path}: ", $found[0]);
        }
    }

    // --------------------------------------------- synthetic fixture trees

    private const PHP_HEADER = "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n */\n";

    /** @return array<string, string> */
    private static function compliantFixtures(): array
    {
        $hash = static fn (string $s): string => "# {$s}";
        $xml = static fn (string $s): string => "<!-- {$s} -->";
        $css = static fn (string $s): string => "/* {$s} */";
        $two = static fn (callable $wrap): string => $wrap(self::SPDX) . "\n" . $wrap(self::COPYRIGHT) . "\n";

        return [
            'src/Web/Example.php' => self::PHP_HEADER . "\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Web;\n",
            'src/Web/routes.php' => "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n *\n * Description.\n */\n\ndeclare(strict_types=1);\n\nreturn [];\n",
            'templates/pages/home.php' => self::PHP_HEADER . "\ndeclare(strict_types=1);\n\n?>\n<h1><?= \$this->t('home.title') ?></h1>\n",
            'tools/dev/run.php' => "#!/usr/bin/env php\n" . self::PHP_HEADER . "\ndeclare(strict_types=1);\n",
            'public/assets/css/app.css' => $two($css) . "\n.a { color: inherit; }\n",
            'public/assets/js/app.js' => $two(static fn (string $s): string => "// {$s}") . "\n'use strict';\n",
            'docs/design/page.html' => "<!DOCTYPE html>\n" . $two($xml) . "<html lang=\"en\"></html>\n",
            'docs/design/model-ui/canvas-source/Export.dc.html' => "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n" . $two($xml) . "</head></html>\n",
            'docs/brand/plain.svg' => $two($xml) . "<svg xmlns=\"http://www.w3.org/2000/svg\"/>\n",
            'docs/brand/declared.svg' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $two($xml) . "<svg xmlns=\"http://www.w3.org/2000/svg\"/>\n",
            'phpunit.xml.dist' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $two($xml) . "<phpunit/>\n",
            'config/example.xml' => "<?xml version=\"1.0\"?>\n" . $two($xml) . "<root/>\n",
            '.htaccess' => $two($hash) . "Require all denied\n",
            'public/.htaccess' => $two($hash) . "#\n# Comment.\nRewriteEngine On\n",
            'tools/dev/build.sh' => "#!/bin/sh\n" . $two($hash) . "set -eu\n",
            '.github/workflows/ci.yml' => $two($hash) . "\nname: CI\n",
            'config/example.yaml' => $two($hash) . "key: value\n",
            // Exemptions.
            'composer.json' => "{}\n",
            'docs/design/model-ui/canvas-source/canvas.json' => "{}\n",
            'public/assets/fonts/inter/inter-latin-400-normal.woff2' => "wOF2\x00\x01\x00\x00\xff\xfe",
            'public/assets/fonts/inter/LICENSE.txt' => "Third-party licence text.\n",
            'LICENSE' => "Licence text.\n",
            'README.md' => "# Readme\n",
            'docs/dependencies.md' => "# Dependencies\n",
            '.gitignore' => "/vendor/\n",
            '.gitattributes' => "* text=auto\n",
        ];
    }

    public function testCompliantFixturesOfEveryTypeAndExemptionPass(): void
    {
        $root = $this->tempRoot();
        foreach (self::compliantFixtures() as $path => $contents) {
            self::writeFile($root, $path, $contents);
        }
        $files = self::listFiles($root);
        self::assertCount(count(self::compliantFixtures()), $files);

        self::assertSame([], self::violations($root, $files));
    }

    /** @return iterable<string, array{string, string}> */
    public static function violatingFixtures(): iterable
    {
        $xml = static fn (string $s): string => "<!-- {$s} -->";
        $css = static fn (string $s): string => "/* {$s} */";
        $hash = static fn (string $s): string => "# {$s}";
        $two = static fn (callable $wrap): string => $wrap(self::SPDX) . "\n" . $wrap(self::COPYRIGHT) . "\n";

        // Files of a type that has neither a rule nor an exemption.
        yield 'unlisted type: text file' => ['notes.txt', "Notes.\n"];
        yield 'unlisted type: other JSON' => ['config/settings.json', "{}\n"];
        yield 'unlisted type: script' => ['tools/dev/helper.py', "# SPDX-License-Identifier: AGPL-3.0-or-later\n"];
        yield 'unlisted type: no extension' => ['Makefile', "all:\n"];
        yield 'unlisted type: licence text outside the font directory' => ['docs/LICENSE.txt', "Text.\n"];
        yield 'unlisted type: font licence one level too deep' => ['public/assets/fonts/inter/sub/LICENSE.txt', "Text.\n"];
        yield 'unlisted type: sidecar licence file' => ['public/assets/css/app.css.license', $two($hash)];

        // PHP.
        yield 'php: no header' => ['src/Web/A.php', "<?php\n\ndeclare(strict_types=1);\n"];
        yield 'php: line comments' => ['src/Web/A.php', "<?php\n// " . self::SPDX . "\n// " . self::COPYRIGHT . "\n"];
        yield 'php: header not immediately after the open tag' => ['src/Web/A.php', "<?php\n\n/*\n * " . self::SPDX . "\n * " . self::COPYRIGHT . "\n */\n"];
        yield 'php: open tag not alone on its line' => ['src/Web/A.php', "<?php /*\n * " . self::SPDX . "\n * " . self::COPYRIGHT . "\n */\n"];
        yield 'php: template starting with markup' => ['templates/x.php', "<div>\n" . self::PHP_HEADER];
        yield 'php: copyright with a year' => ['src/Web/A.php', "<?php\n/*\n * " . self::SPDX . "\n * Copyright (C) 2026 TiffinDB contributors\n */\n"];
        yield 'php: copyright naming someone else' => ['src/Web/A.php', "<?php\n/*\n * " . self::SPDX . "\n * Copyright (C) Example Person\n */\n"];
        yield 'php: other licence identifier' => ['src/Web/A.php', "<?php\n/*\n * SPDX-License-Identifier: MIT\n * " . self::COPYRIGHT . "\n */\n"];
        yield 'php: lines in the wrong order' => ['src/Web/A.php', "<?php\n/*\n * " . self::COPYRIGHT . "\n * " . self::SPDX . "\n */\n"];
        yield 'php: shebang without open tag next' => ['tools/dev/run.php', "#!/usr/bin/env php\n\n" . self::PHP_HEADER];

        // CSS and JavaScript.
        yield 'css: no header' => ['public/assets/css/app.css', ".a { color: inherit; }\n"];
        yield 'css: line-comment syntax' => ['public/assets/css/app.css', "// " . self::SPDX . "\n// " . self::COPYRIGHT . "\n"];
        yield 'css: one combined comment' => ['public/assets/css/app.css', "/* " . self::SPDX . "\n   " . self::COPYRIGHT . " */\n"];
        yield 'css: not the first two lines' => ['public/assets/css/app.css', "\n" . $two($css)];
        yield 'js: block-comment syntax' => ['public/assets/js/app.js', $two($css)];
        yield 'js: only the identifier line' => ['public/assets/js/app.js', "// " . self::SPDX . "\n"];

        // Markup.
        yield 'html: header below the head outside the canvas exports' => ['docs/design/model-ui/export.html', "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n" . $two($xml)];
        yield 'html: canvas export without a doctype first' => ['docs/design/model-ui/canvas-source/A.dc.html', "<html>\n" . $two($xml)];
        yield 'html: canvas export header after line six' => ['docs/design/model-ui/canvas-source/A.dc.html', "<!doctype html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title>T</title>\n" . $two($xml)];
        yield 'html: no doctype first' => ['docs/a.html', $two($xml) . "<!DOCTYPE html>\n"];
        yield 'html: header after line six' => ['docs/a.html', "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title>T</title>\n" . $two($xml)];
        yield 'html: lines not consecutive' => ['docs/a.html', "<!DOCTYPE html>\n" . $xml(self::SPDX) . "\n<html>\n" . $xml(self::COPYRIGHT) . "\n"];
        yield 'svg: header after the root element starts' => ['docs/brand/a.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\">\n" . $two($xml)];
        yield 'xml: no XML declaration first' => ['phpunit.xml.dist', $two($xml) . "<phpunit/>\n"];
        yield 'xml: hash-comment syntax' => ['config/a.xml', "<?xml version=\"1.0\"?>\n" . $two($hash)];

        // Hash-comment types.
        yield 'htaccess: no header' => ['public/.htaccess', "RewriteEngine On\n"];
        yield 'htaccess: header below a directive' => ['.htaccess', "Require all denied\n" . $two($hash)];
        yield 'shell: no "#!" line' => ['tools/dev/build.sh', $two($hash) . "set -eu\n"];
        yield 'yaml: no header' => ['.github/workflows/ci.yml', "name: CI\n"];
        yield 'yaml: header below the first key' => ['config/a.yaml', "key: value\n" . $two($hash)];
    }

    #[DataProvider('violatingFixtures')]
    public function testViolationIsDetectedAndNamesTheFile(string $path, string $contents): void
    {
        $root = $this->tempRoot();
        self::writeFile($root, $path, $contents);
        // A compliant neighbour must not be reported.
        self::writeFile($root, 'config/ok.php', self::PHP_HEADER . "\ndeclare(strict_types=1);\n");

        $found = self::violations($root, self::listFiles($root));

        self::assertCount(1, $found, 'Exactly the violating file must be reported.');
        self::assertStringStartsWith("{$path}: ", $found[0]);
    }

    // -------------------------------------------------------------- helpers

    private function tempRoot(): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-spdx-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }

        return $this->temp;
    }

    private static function writeFile(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException("Cannot create the directory for {$relative}.");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write {$relative}.");
        }
    }

    /** @return list<string> */
    private static function listFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private static function removeTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
