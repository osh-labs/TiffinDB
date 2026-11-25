<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tools\Release\ArchiveBuilder;
use TiffinDB\Tools\Release\ReleaseRules;
use ZipArchive;

require_once __DIR__ . '/../../tools/release/ReleaseRules.php';
require_once __DIR__ . '/../../tools/release/ArchiveBuilder.php';

/**
 * T-REL-1 to T-REL-10 and T-REL-12 to T-REL-14 (M7 release spec section
 * 16.3): the release build tool against a synthetic fixture tree, a
 * temporary git repository made here, and a fake prepared vendor folder with
 * one package. The tool's three files are copied into the fixture, because
 * build.php works on the repository it sits in.
 */
#[CoversNothing]
final class ReleaseBuildTest extends TestCase
{
    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';
    private const PHP = "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n */\n\ndeclare(strict_types=1);\n";
    private const HASH = "# SPDX-License-Identifier: AGPL-3.0-or-later\n# Copyright (C) TiffinDB contributors\n";

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/tiffindb-release-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0700)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            self::removeTree($this->dir);
        }
    }

    // --------------------------------------------------------- fixtures

    /** The tracked files of the fixture tree, shipped and not shipped. @return array<string, string> */
    private static function treeFiles(string $version = '1.2.3-dev'): array
    {
        $runtimeRow = '| `example/runtime` | `1.2.3` (pinned exactly); installed v1.2.3 | `' . self::COMMIT . '` | Example runtime | MIT | `LICENSE` (MIT) '
            . str_repeat('a', 64) . ' | Yes |';

        return [
            '.htaccess' => self::HASH . "RewriteEngine On\n",
            'public/.htaccess' => self::HASH . "RewriteEngine On\nRewriteRule ^ index.php [L]\n",
            'public/index.php' => self::PHP,
            'public/assets/css/app.css' => "/* SPDX-License-Identifier: AGPL-3.0-or-later */\n/* Copyright (C) TiffinDB contributors */\n.a { color: inherit; }\n",
            'public/assets/fonts/example/LICENSE.txt' => "Example font licence text.\n",
            'public/assets/fonts/example/example-400.woff2' => "wOF2\x00\x01\x00\x00\xff\xfe",
            'src/Example.php' => self::PHP . "\nnamespace TiffinDB;\n",
            'src/Web/Other.php' => self::PHP . "\nnamespace TiffinDB\\Web;\n",
            'templates/page.php' => self::PHP . "?>\n<p>Example</p>\n",
            'strings/en/example.php' => self::PHP . "\nreturn [];\n",
            'plugins/export/example/plugin.json' => "{}\n",
            'config/app.php' => self::PHP . "\nreturn ['version' => '{$version}'];\n",
            'config/notices.json' => json_encode([
                ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
                ['id' => 'runtime', 'name' => 'Example runtime', 'version' => '1.2.3', 'licence' => 'MIT', 'files' => ['vendor/example/runtime/LICENSE']],
                ['id' => 'loader', 'name' => 'Class loader', 'version' => '', 'licence' => 'MIT', 'files' => ['vendor/composer/LICENSE']],
                ['id' => 'example-font', 'name' => 'Example Font', 'version' => '1.0', 'licence' => 'OFL-1.1', 'files' => ['public/assets/fonts/example/LICENSE.txt']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'composer.json' => "{}\n",
            'LICENSE' => "Example licence text.\n",
            'README.md' => "# Example\n",
            'CHANGELOG.md' => "# Changelog\n",
            'THIRD-PARTY-NOTICES.md' => "# Notices\n",
            'tools/admin/tool.php' => self::PHP,
            'tools/host-check/check.php' => self::PHP,
            'docs/host/install.md' => "# Install\n",
            'docs/formats/format.md' => "# Format\n",
            'docs/dependencies.md' => "# Dependencies\n\n## Runtime dependencies (shipped)\n\n"
                . "| Name | Version | Commit | Purpose | Licence | Licence files (SHA-256) | Shipped |\n|---|---|---|---|---|---|---|\n"
                . $runtimeRow . "\n\n## Composer class loader (shipped)\n\n"
                . "| Component | Version | Purpose | Licence (SPDX) | Licence file shipped |\n|---|---|---|---|---|\n"
                . "| Class loader | | Loads classes | MIT | `vendor/composer/LICENSE` |\n\n## Bundled font assets (shipped)\n\n"
                . "| Family | Files (directory) | Source | Licence | Licence file shipped |\n|---|---|---|---|---|\n"
                . "| Example Font | `public/assets/fonts/example/` | Example | OFL-1.1 | `public/assets/fonts/example/LICENSE.txt` |\n\n"
                . "## Development dependencies (not shipped)\n\nNone.\n",
            // Tracked, never shipped.
            'tests/ExampleTest.php' => self::PHP,
            'tools/dev/lint.php' => self::PHP,
            'docs/specs/example.md' => "# Spec\n",
            'CLAUDE.md' => "# Notes\n",
            'phpunit.xml.dist' => "<?xml version=\"1.0\"?>\n<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->\n<!-- Copyright (C) TiffinDB contributors -->\n<phpunit/>\n",
            '.gitignore' => "/build/\n",
        ];
    }

    /** @return array<string, mixed> */
    private static function installedData(): array
    {
        return [
            'root' => ['name' => 'example/app', 'pretty_version' => '1.0.0+no-version-set', 'reference' => null, 'dev' => false],
            'versions' => [
                'example/app' => ['pretty_version' => '1.0.0+no-version-set', 'reference' => null, 'dev_requirement' => false],
                'example/runtime' => ['pretty_version' => 'v1.2.3', 'reference' => self::COMMIT, 'dev_requirement' => false],
            ],
        ];
    }

    /**
     * The fake prepared vendor: one package with test, example, CI, and
     * documentation folders and development files that must not ship.
     *
     * @param array<string, mixed>|null $installed
     * @return array<string, string>
     */
    private static function vendorFiles(?array $installed = null): array
    {
        return [
            'autoload.php' => "<?php\nreturn null;\n",
            'composer/installed.php' => "<?php return " . var_export($installed ?? self::installedData(), true) . ";\n",
            'composer/installed.json' => "{\"packages\": [{\"name\": \"example/runtime\", \"authors\": [{\"name\": \"Example Person\", \"email\": \"user@example.com\"}]}]}\n",
            'composer/LICENSE' => "Example class loader licence text.\n",
            'composer/autoload_psr4.php' => "<?php\nreturn [];\n",
            'example/runtime/LICENSE' => "Example package licence text.\n",
            'example/runtime/NOTICE' => "Example notice.\n",
            'example/runtime/composer.json' => "{}\n",
            'example/runtime/src/Thing.php' => "<?php\nfinal class Thing {}\n",
            'example/runtime/src/Sub/Other.php' => "<?php\nfinal class Other {}\n",
            'example/runtime/tests/ThingTest.php' => "<?php\n",
            'example/runtime/src/test/Fixture.php' => "<?php\n",
            'example/runtime/Tests/Upper.php' => "<?php\n",
            'example/runtime/examples/demo.php' => "<?php\n",
            'example/runtime/.github/workflows/ci.yml' => "name: ci\n",
            'example/runtime/tests/LICENSE' => "Licence text inside a test folder.\n",
            'example/runtime/docs/guide.md' => "# Guide\n",
            'example/runtime/docs/LICENSE-docs' => "Documentation licence text.\n",
            'example/runtime/phpunit.xml' => "<phpunit/>\n",
            'example/runtime/phpunit.xml.dist' => "<phpunit/>\n",
            'example/runtime/.gitattributes' => "* text=auto\n",
            'example/runtime/.gitignore' => "/vendor/\n",
            'example/runtime/.editorconfig' => "root = true\n",
            'example/runtime/composer.lock' => "{}\n",
            'example/runtime/Makefile' => "all:\n",
            'example/runtime/src/Makefile.php' => "<?php\n",
        ];
    }

    /** The vendor archive paths the build must ship from the fake vendor. @var list<string> */
    private const SHIPPED_VENDOR = [
        'vendor/autoload.php',
        'vendor/composer/LICENSE',
        'vendor/composer/autoload_psr4.php',
        'vendor/composer/installed.php',
        'vendor/example/runtime/LICENSE',
        'vendor/example/runtime/NOTICE',
        'vendor/example/runtime/composer.json',
        'vendor/example/runtime/docs/LICENSE-docs',
        'vendor/example/runtime/src/Makefile.php',
        'vendor/example/runtime/src/Sub/Other.php',
        'vendor/example/runtime/src/Thing.php',
    ];

    /** @param array<string, string> $files */
    private static function writeFiles(string $base, array $files): void
    {
        foreach ($files as $relative => $contents) {
            $path = $base . '/' . $relative;
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
                throw new RuntimeException("Cannot create the folder for {$relative}");
            }
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException("Cannot write {$relative}");
            }
        }
    }

    /** A plain fixture root (no git) and vendor; returns [root, vendor]. @return array{string, string} */
    private function plainFixture(): array
    {
        $root = $this->dir . '/tree';
        $vendor = $this->dir . '/vendor';
        self::writeFiles($root, self::treeFiles());
        self::writeFiles($vendor, self::vendorFiles());

        return [$root, $vendor];
    }

    /** The archive list the build would check for the plain fixture. @return list<string> */
    private static function archiveList(string $root, string $vendor): array
    {
        $builder = new ArchiveBuilder($root, $vendor);

        return array_keys($builder->select(array_keys(self::treeFiles()), (string) file_get_contents($root . '/docs/dependencies.md')));
    }

    /** A git fixture with the build tool copied in; returns [root, vendor]. @return array{string, string} */
    private function gitFixture(string $version = '1.2.3-dev'): array
    {
        if (self::which('git') === null) {
            self::markTestSkipped('git is required for the build-tool tests.');
        }
        $root = $this->dir . '/repo';
        $vendor = $this->dir . '/vendor';
        $files = self::treeFiles($version);
        foreach (['build.php', 'ReleaseRules.php', 'ArchiveBuilder.php'] as $tool) {
            $files['tools/release/' . $tool] = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/release/' . $tool);
        }
        self::writeFiles($root, $files);
        self::writeFiles($vendor, self::vendorFiles());
        self::git($root, ['init', '-q']);
        self::git($root, ['add', '-A']);
        $this->commit($root);

        return [$root, $vendor];
    }

    private function commit(string $root): void
    {
        self::git($root, ['-c', 'user.name=Example Person', '-c', 'user.email=user@example.com', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'Fixture']);
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        [$code, $out, $err] = self::runCommand(['git', ...$arguments], $root, []);
        if ($code !== 0) {
            throw new RuntimeException('git failed: ' . $err);
        }

        return $out;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private static function runCommand(array $command, string $cwd, array $env): array
    {
        $environment = getenv();
        foreach ($env as $key => $value) {
            $environment[$key] = $value;
        }
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start ' . $command[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @param list<string> $options
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private function build(string $root, array $options, array $env = []): array
    {
        return self::runCommand([PHP_BINARY, $root . '/tools/release/build.php', ...$options], $this->dir, $env + ['TZ' => 'UTC']);
    }

    private static function which(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }

        return null;
    }

    // ----------------------------------------------- T-REL-1, T-REL-13

    public function testTwoBuildsAreByteIdenticalAcrossTimeZones(): void
    {
        [$root, $vendor] = $this->gitFixture();

        [$code1, $out1, $err1] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out-1', '--test-build']);
        [$code2, , $err2] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out-2', '--test-build'], ['TZ' => 'America/New_York']);
        [$code3, , $err3] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out-3', '--test-build'], ['TZ' => 'Asia/Tokyo']);

        self::assertSame([0, 0, 0], [$code1, $code2, $code3], $err1 . $err2 . $err3);
        self::assertSame('', $err1);
        $zip = 'tiffindb-1.2.3-dev.zip';
        $bytes = (string) file_get_contents($this->dir . '/out-1/' . $zip);
        self::assertSame($bytes, file_get_contents($this->dir . '/out-2/' . $zip));
        self::assertSame($bytes, file_get_contents($this->dir . '/out-3/' . $zip));
        $sha = (string) file_get_contents($this->dir . '/out-1/' . $zip . '.sha256');
        self::assertSame(hash('sha256', $bytes) . '  ' . $zip . "\n", $sha);
        self::assertSame($sha, file_get_contents($this->dir . '/out-2/' . $zip . '.sha256'));

        // Step 10: the hash, the count, the size, and the library versions.
        self::assertStringContainsString(hash('sha256', $bytes) . '  ' . $zip, $out1);
        self::assertStringContainsString('libzip ' . ZipArchive::LIBZIP_VERSION, $out1);
        self::assertStringContainsString('zlib ' . ZLIB_VERSION, $out1);
        self::assertStringNotContainsString($this->dir, $out1);
    }

    public function testEntriesAreFoldersThenSortedFilesThenTheManifestWithFixedTimesAndModes(): void
    {
        [$root, $vendor] = $this->gitFixture();
        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);
        self::assertSame(0, $code, $err);

        $names = [];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->dir . '/out/tiffindb-1.2.3-dev.zip'));
        self::assertSame('', (string) $zip->getArchiveComment());
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            self::assertIsArray($stat);
            $names[] = $stat['name'];
            $folder = str_ends_with($stat['name'], '/');
            self::assertTrue($zip->getExternalAttributesIndex($i, $opsys, $attr));
            self::assertSame(ZipArchive::OPSYS_UNIX, $opsys, $stat['name']);
            self::assertSame(($folder ? 040755 : 0100644) << 16, $attr, $stat['name']);
            self::assertSame(ZipArchive::CM_DEFLATE, $stat['comp_method'], $stat['name']);
            // The DOS date and time fields: 1980-01-01 00:00:00.
            $raw = self::centralDosTime((string) file_get_contents($this->dir . '/out/tiffindb-1.2.3-dev.zip'), $stat['name']);
            self::assertSame([0x0021, 0x0000], $raw, $stat['name']);
        }
        $zip->close();

        $folders = array_values(array_filter($names, static fn (string $n): bool => str_ends_with($n, '/')));
        $files = array_values(array_filter($names, static fn (string $n): bool => !str_ends_with($n, '/')));
        self::assertSame($folders, array_slice($names, 0, count($folders)), 'Folder entries come first.');
        $sortedFolders = $folders;
        usort($sortedFolders, 'strcmp');
        self::assertSame($sortedFolders, $folders);
        self::assertContains('tiffindb-1.2.3-dev/', $folders);
        self::assertSame('tiffindb-1.2.3-dev/MANIFEST.txt', end($files), 'The manifest is the last entry.');
        $content = array_slice($files, 0, -1);
        $sorted = $content;
        usort($sorted, 'strcmp');
        self::assertSame($sorted, $content, 'Files are sorted by byte value.');

        // Every folder holds a file, and every file's folder is listed.
        foreach ($files as $file) {
            self::assertContains(dirname($file) . '/', $folders, $file);
        }

        $expected = [];
        foreach (array_keys(self::treeFiles()) as $path) {
            if (ReleaseRules::included($path)) {
                $expected[] = 'tiffindb-1.2.3-dev/' . $path;
            }
        }
        foreach (self::SHIPPED_VENDOR as $path) {
            $expected[] = 'tiffindb-1.2.3-dev/' . $path;
        }
        usort($expected, 'strcmp');
        self::assertSame($expected, $content, 'Exactly the included tracked files and the filtered vendor files ship.');
    }

    /** The DOS time and date of an entry, read from the central directory. @return array{int, int} */
    private static function centralDosTime(string $bytes, string $name): array
    {
        $offset = 0;
        while (($at = strpos($bytes, "PK\x01\x02", $offset)) !== false) {
            $header = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vnamelen', substr($bytes, $at + 4, 26));
            self::assertIsArray($header);
            if (substr($bytes, $at + 46, $header['namelen']) === $name) {
                return [$header['date'], $header['time']];
            }
            $offset = $at + 4;
        }
        self::fail("No central directory entry for {$name}");
    }

    public function testTheManifestListsEveryOtherEntryWithItsHash(): void
    {
        [$root, $vendor] = $this->gitFixture();
        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);
        self::assertSame(0, $code, $err);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->dir . '/out/tiffindb-1.2.3-dev.zip'));
        $manifest = (string) $zip->getFromName('tiffindb-1.2.3-dev/MANIFEST.txt');
        $lines = explode("\n", rtrim($manifest, "\n"));
        $listed = [];
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}  [\x21-\x7e][\x20-\x7e]*\z/', $line);
            [$hash, $path] = explode('  ', $line, 2);
            self::assertSame(hash('sha256', (string) $zip->getFromName('tiffindb-1.2.3-dev/' . $path)), $hash, $path);
            $listed[] = $path;
        }
        self::assertNotContains('MANIFEST.txt', $listed);
        $sorted = $listed;
        usort($sorted, 'strcmp');
        self::assertSame($sorted, $listed);

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '/') && $name !== 'tiffindb-1.2.3-dev/MANIFEST.txt') {
                $files[] = substr($name, strlen('tiffindb-1.2.3-dev/'));
            }
        }
        $zip->close();
        self::assertSame($files, $listed);
    }

    // ------------------------------------------- T-REL-2 to T-REL-9

    public function testTheCompliantFixturePassesEveryRule(): void
    {
        [$root, $vendor] = $this->plainFixture();

        self::assertSame([], ReleaseRules::violations(self::archiveList($root, $vendor), $root, $vendor));
    }

    /**
     * One bad entry per case; each must give exactly its rule's message.
     * Tree files are written below the fixture root, `vendor/` files below
     * the vendor folder.
     *
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function badEntries(): iterable
    {
        $php = self::PHP;
        $hash = self::HASH;

        // R1: test and development paths.
        foreach (['tests/AnyTest.php', 'tests/Support/Fixture.php', 'tools/dev/run.php', 'tools/discovery/scan.php', 'tools/release/build.php',
            'docs/build/ledger.md', 'docs/specs/M1.md', 'docs/decisions/0001.md', 'docs/design/page.md', 'docs/brand/usage.md',
            'CLAUDE.md', '.gitignore'] as $path) {
            yield "R1 {$path}" => ['R1', $path, [$path => str_ends_with($path, '.php') ? $php : "Text\n"]];
        }
        yield 'R1 .github/workflows/ci.yml' => ['R1', '.github/workflows/ci.yml', ['.github/workflows/ci.yml' => $hash . "name: CI\n"]];
        yield 'R1 phpunit.xml.dist' => ['R1', 'phpunit.xml.dist', ['phpunit.xml.dist' => "<?xml version=\"1.0\"?>\n<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->\n<!-- Copyright (C) TiffinDB contributors -->\n<phpunit/>\n"]];
        foreach (['tests', 'test', 'Tests', 'examples', '.github'] as $segment) {
            $path = "vendor/example/runtime/src/{$segment}/File.php";
            yield "R1 {$path}" => ['R1', $path, [$path => "<?php\n"]];
        }

        // R2: local configuration and data.
        foreach (['config/local.php', 'data/system.php', 'local/notes.php'] as $path) {
            yield "R2 {$path}" => ['R2', $path, [$path => $php]];
        }

        // R3: data patterns (vendor paths, where R5 does not apply, or a
        // name with an SPDX-covered extension).
        foreach (['vendor/example/runtime/system.db', 'vendor/example/runtime/Copy.DB', 'vendor/example/runtime/p.sqlite',
            'vendor/example/runtime/p.sqlite3', 'vendor/example/runtime/p.sqlite-wal', 'vendor/example/runtime/project.tiffin',
            'vendor/example/runtime/export.tiffindb.zip', 'vendor/example/runtime/.env', 'vendor/example/runtime/.env.local'] as $path) {
            yield "R3 {$path}" => ['R3', $path, [$path => "data\n"]];
        }
        yield 'R3 src/backup.sqlite.php' => ['R3', 'src/backup.sqlite.php', ['src/backup.sqlite.php' => $php]];
        yield 'R3 public/.env.php' => ['R3', 'public/.env.php', ['public/.env.php' => $php]];

        // R4: nested .htaccess with a rewrite directive.
        yield 'R4 public/assets/.htaccess' => ['R4', 'public/assets/.htaccess', ['public/assets/.htaccess' => $hash . "RewriteEngine On\n"]];
        yield 'R4 lower case, indented' => ['R4', 'plugins/export/example/.htaccess', ['plugins/export/example/.htaccess' => $hash . "Require all denied\n   rewriterule ^ x [L]\n"]];
        yield 'R4 vendor .htaccess' => ['R4', 'vendor/example/runtime/.htaccess', ['vendor/example/runtime/.htaccess' => "Options +FollowSymLinks\nRewriteBase /\n"]];
        yield 'R4 directive after a comment line' => ['R4', 'src/.htaccess', ['src/.htaccess' => $hash . "# Comment\nRewriteCond %{HTTPS} off\n"]];

        // R5: SPDX header.
        yield 'R5 PHP without a header' => ['R5', 'src/Bad.php', ['src/Bad.php' => "<?php\n\ndeclare(strict_types=1);\n"]];
        yield 'R5 CSS without a header' => ['R5', 'public/assets/css/extra.css', ['public/assets/css/extra.css' => ".a { color: inherit; }\n"]];
        yield 'R5 type with no rule' => ['R5', 'templates/notes.txt', ['templates/notes.txt' => "Notes\n"]];
        yield 'R5 other JSON' => ['R5', 'config/settings.json', ['config/settings.json' => "{}\n"]];

        // R6: vendor packages.
        yield 'R6 unlisted package' => ['R6', 'vendor/other/package/', ['vendor/other/package/src/X.php' => "<?php\n"]];
        yield 'R6 file outside a package folder' => ['R6', 'vendor/bin/tool', ['vendor/bin/tool' => "#!/bin/sh\n"]];
        yield 'R6 installed.json' => ['R6', 'vendor/composer/installed.json', []];

        // R7: notices.
        yield 'R7 unlisted licence file named' => ['R7', 'config/notices.json', ['config/notices.json' => (string) json_encode([
            ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
            ['id' => 'runtime', 'name' => 'Example runtime', 'version' => '1.2.3', 'licence' => 'MIT', 'files' => ['vendor/example/runtime/LICENSE', 'vendor/example/runtime/COPYING']],
            ['id' => 'loader', 'name' => 'Class loader', 'version' => '', 'licence' => 'MIT', 'files' => ['vendor/composer/LICENSE']],
            ['id' => 'example-font', 'name' => 'Example Font', 'version' => '1.0', 'licence' => 'OFL-1.1', 'files' => ['public/assets/fonts/example/LICENSE.txt']],
        ])]];
        yield 'R7 shipped font row without a component' => ['R7', 'Example Font', ['config/notices.json' => (string) json_encode([
            ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
            ['id' => 'runtime', 'name' => 'Example runtime', 'version' => '1.2.3', 'licence' => 'MIT', 'files' => ['vendor/example/runtime/LICENSE']],
            ['id' => 'loader', 'name' => 'Class loader', 'version' => '', 'licence' => 'MIT', 'files' => ['vendor/composer/LICENSE']],
        ])]];
        yield 'R7 class loader row without a component' => ['R7', 'Class loader', ['config/notices.json' => (string) json_encode([
            ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
            ['id' => 'runtime', 'name' => 'Example runtime', 'version' => '1.2.3', 'licence' => 'MIT', 'files' => ['vendor/example/runtime/LICENSE']],
            ['id' => 'example-font', 'name' => 'Example Font', 'version' => '1.0', 'licence' => 'OFL-1.1', 'files' => ['public/assets/fonts/example/LICENSE.txt']],
        ])]];
        yield 'R7 malformed notices' => ['R7', 'config/notices.json', ['config/notices.json' => "[{\"id\": \"tiffindb\"}]\n"]];
        yield 'R7 notices with an extra member' => ['R7', 'config/notices.json', ['config/notices.json' => (string) json_encode([
            ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE'], 'url' => 'https://example.com/'],
        ])]];
        yield 'R7 notices id with upper case' => ['R7', 'config/notices.json', ['config/notices.json' => (string) json_encode([
            ['id' => 'TiffinDB', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
        ])]];

        // R8: unsafe paths.
        foreach (['src/../escape.php', 'src/./here.php', 'src/a\\b.php', "src/nul\0.php", "src/caf\u{e9}.php", "src/tab\t.php", 'src//double.php', '/absolute.php', "src/line\n.php"] as $path) {
            yield 'R8 ' . ReleaseRules::printable($path) => ['R8', $path, []];
        }
    }

    /** @param array<string, string> $files */
    #[DataProvider('badEntries')]
    public function testEachBadEntryGivesExactlyItsRulesMessage(string $rule, string $named, array $files): void
    {
        [$root, $vendor] = $this->plainFixture();
        $paths = self::archiveList($root, $vendor);
        foreach ($files as $path => $contents) {
            if (str_starts_with($path, 'vendor/')) {
                self::writeFiles($vendor, [substr($path, 7) => $contents]);
            } else {
                self::writeFiles($root, [$path => $contents]);
            }
        }
        $paths = array_values(array_unique([...$paths, ...array_keys($files), ...($files === [] ? [$named] : [])]));

        $found = ReleaseRules::violations($paths, $root, $vendor);

        self::assertCount(1, $found, implode("\n", $found));
        self::assertStringStartsWith($rule . ': ', $found[0]);
        self::assertStringContainsString(ReleaseRules::printable($named), $found[0]);
        self::assertStringNotContainsString($this->dir, $found[0], 'Messages hold no absolute path.');
    }

    public function testNestedHtaccessWithoutARewriteDirectivePasses(): void
    {
        [$root, $vendor] = $this->plainFixture();
        self::writeFiles($root, ['public/assets/.htaccess' => self::HASH . "# RewriteEngine is not used here.\nRequire all denied\n"]);
        $paths = [...self::archiveList($root, $vendor), 'public/assets/.htaccess'];

        self::assertSame([], ReleaseRules::violations($paths, $root, $vendor));
    }

    public function testAShippedRowWithNoPackageFilesIsRefused(): void
    {
        [$root, $vendor] = $this->plainFixture();
        $paths = array_values(array_filter(
            self::archiveList($root, $vendor),
            static fn (string $p): bool => !str_starts_with($p, 'vendor/example/runtime/'),
        ));

        $found = ReleaseRules::violations($paths, $root, $vendor);

        self::assertContains('R6: vendor/example/runtime/ is a shipped row of docs/dependencies.md but has no files', $found);
        // The package's licence file is gone too, so R7 also reports it.
        foreach ($found as $message) {
            self::assertMatchesRegularExpression('/^R[67]: /', $message);
        }
    }

    public function testAPackageAtAnotherVersionThanItsRowIsRefusedByR6(): void
    {
        [$root, $vendor] = $this->plainFixture();
        $installed = self::installedData();
        $installed['versions']['example/runtime']['reference'] = str_repeat('f', 40);
        self::writeFiles($vendor, ['composer/installed.php' => '<?php return ' . var_export($installed, true) . ";\n"]);

        self::assertSame(
            ['R6: vendor/example/runtime/ differs from the version or commit of its row in docs/dependencies.md'],
            ReleaseRules::violations(self::archiveList($root, $vendor), $root, $vendor),
        );
    }

    public function testTheBuildRefusesATrackedDataFile(): void
    {
        [$root, $vendor] = $this->gitFixture();
        self::writeFiles($root, ['public/system.db' => "data\n"]);
        self::git($root, ['add', '-A']);
        $this->commit($root);

        [$code, $out, $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString("R3: public/system.db matches the data pattern *.db\n", $err);
        self::assertStringNotContainsString($this->dir, $err);
        self::assertSame([], glob($this->dir . '/out/*') ?: []);
    }

    public function testTheBuildRefusesATrackedSymbolicLink(): void
    {
        [$root, $vendor] = $this->gitFixture();
        symlink('Example.php', $root . '/src/Link.php');
        self::git($root, ['add', '-A']);
        $this->commit($root);

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);

        self::assertSame(1, $code);
        self::assertStringContainsString('src/Link.php', $err);
        self::assertSame([], glob($this->dir . '/out/*') ?: []);
    }

    // -------------------------------------------------------- T-REL-10

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function vendorRefusals(): iterable
    {
        yield 'root dev install' => [static function (array $d): array {
            $d['root']['dev'] = true;

            return $d;
        }, 'vendor: installed.php says the install includes development packages (root dev is not false)'];
        yield 'a development package' => [static function (array $d): array {
            $d['versions']['example/devtool'] = ['pretty_version' => '2.0.0', 'reference' => self::COMMIT, 'dev_requirement' => true];

            return $d;
        }, 'vendor: example/devtool is a development package'];
        yield 'an unlisted package' => [static function (array $d): array {
            $d['versions']['example/extra'] = ['pretty_version' => '2.0.0', 'reference' => self::COMMIT, 'dev_requirement' => false];

            return $d;
        }, 'vendor: example/extra is not a shipped row of docs/dependencies.md'];
        yield 'a version mismatch' => [static function (array $d): array {
            $d['versions']['example/runtime']['pretty_version'] = 'v1.2.4';

            return $d;
        }, 'vendor: example/runtime version v1.2.4 differs from the Version column of docs/dependencies.md'];
        yield 'a longer version that contains the row version' => [static function (array $d): array {
            $d['versions']['example/runtime']['pretty_version'] = 'v1.2.3.1';

            return $d;
        }, 'vendor: example/runtime version v1.2.3.1 differs from the Version column of docs/dependencies.md'];
        yield 'a commit mismatch' => [static function (array $d): array {
            $d['versions']['example/runtime']['reference'] = str_repeat('f', 40);

            return $d;
        }, 'vendor: example/runtime reference ' . str_repeat('f', 40) . ' differs from the Commit column of docs/dependencies.md'];
        yield 'a listed package missing' => [static function (array $d): array {
            unset($d['versions']['example/runtime']);

            return $d;
        }, 'vendor: example/runtime is a shipped row of docs/dependencies.md but is not installed'];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    #[DataProvider('vendorRefusals')]
    public function testVendorRefusals(callable $change, string $expected): void
    {
        [$root, $vendor] = $this->plainFixture();
        self::writeFiles($vendor, ['composer/installed.php' => '<?php return ' . var_export($change(self::installedData()), true) . ";\n"]);

        self::assertSame([$expected], ReleaseRules::vendorProblems($vendor, (string) file_get_contents($root . '/docs/dependencies.md')));
    }

    public function testTheFakeVendorPassesAndAMissingInstalledFileIsRefused(): void
    {
        [$root, $vendor] = $this->plainFixture();
        $record = (string) file_get_contents($root . '/docs/dependencies.md');
        self::assertSame([], ReleaseRules::vendorProblems($vendor, $record));

        unlink($vendor . '/composer/installed.php');
        self::assertSame(['vendor: composer/installed.php is missing or not in the Composer 2 form'], ReleaseRules::vendorProblems($vendor, $record));
    }

    public function testAutoloadFilesMustBeInsideAShippedPackage(): void
    {
        [$root, $vendor] = $this->plainFixture();
        $record = (string) file_get_contents($root . '/docs/dependencies.md');
        self::writeFiles($vendor, [
            'composer/autoload_files.php' => "<?php\n\$vendorDir = dirname(__DIR__);\nreturn ['a' => \$vendorDir . '/example/runtime/src/Thing.php'];\n",
        ]);
        self::assertSame([], ReleaseRules::vendorProblems($vendor, $record));

        self::writeFiles($vendor, [
            'other/package/functions.php' => "<?php\n",
            'composer/autoload_files.php' => "<?php\n\$vendorDir = dirname(__DIR__);\nreturn ['a' => \$vendorDir . '/example/runtime/src/Thing.php', 'b' => \$vendorDir . '/other/package/functions.php'];\n",
        ]);
        self::assertSame(
            ['vendor: composer/autoload_files.php names other/package/functions.php, which is outside a shipped package folder'],
            ReleaseRules::vendorProblems($vendor, $record),
        );
    }

    public function testOnlyTheFilteredVendorFilesShip(): void
    {
        [$root, $vendor] = $this->plainFixture();

        $files = ReleaseRules::vendorFiles($vendor, (string) file_get_contents($root . '/docs/dependencies.md'));

        self::assertSame(self::SHIPPED_VENDOR, $files);
        self::assertNotContains('vendor/composer/installed.json', $files);
    }

    public function testTheBuildRefusesADevelopmentVendor(): void
    {
        [$root, $vendor] = $this->gitFixture();
        $installed = self::installedData();
        $installed['root']['dev'] = true;
        self::writeFiles($vendor, ['composer/installed.php' => '<?php return ' . var_export($installed, true) . ";\n"]);

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);

        self::assertSame(1, $code);
        self::assertStringContainsString('root dev is not false', $err);
        self::assertSame([], glob($this->dir . '/out/*') ?: []);
    }

    // -------------------------------------------------------- T-REL-12

    public function testADevelopmentVersionNeedsTestBuild(): void
    {
        [$root, $vendor] = $this->gitFixture('1.2.3-dev');

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out']);

        self::assertSame(1, $code);
        self::assertStringContainsString('config/app.php version 1.2.3-dev is a development version; use --test-build', $err);
        self::assertSame([], glob($this->dir . '/out/*') ?: []);
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function versions(): iterable
    {
        yield 'release' => ['1.0.0', false, 0];
        yield 'release candidate' => ['1.0.0-rc.1', false, 0];
        yield 'later candidate' => ['2.10.3-rc.12', false, 0];
        yield 'release with --test-build' => ['1.0.0', true, 0];
        yield 'two parts' => ['1.0', true, 1];
        yield 'leading v' => ['v1.0.0', true, 1];
        yield 'candidate zero' => ['1.0.0-rc.0', true, 1];
        yield 'beta' => ['1.0.0-beta.1', true, 1];
        yield 'trailing newline' => ["1.0.0\n", true, 1];
        yield 'path characters' => ['1.0.0/../x', true, 1];
        yield 'empty' => ['', true, 1];
    }

    #[DataProvider('versions')]
    public function testVersionValidation(string $version, bool $testBuild, int $expected): void
    {
        [$root, $vendor] = $this->gitFixture(addcslashes($version, "\\'"));

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', ...($testBuild ? ['--test-build'] : [])]);

        self::assertSame($expected, $code, $err);
        if ($expected === 1) {
            self::assertStringContainsString('is not a release version', $err);
            self::assertSame([], glob($this->dir . '/out/*') ?: []);
        } else {
            self::assertFileExists($this->dir . '/out/tiffindb-' . $version . '.zip');
        }
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        [$root, $vendor] = $this->gitFixture();

        self::assertSame(2, $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--force'])[0]);
        self::assertSame(2, $this->build($root, ['--out=' . $this->dir . '/out'])[0]);
        self::assertSame(2, $this->build($root, [])[0]);
        self::assertSame([], glob($this->dir . '/out') ?: []);
    }

    public function testADirtyIncludedPathIsRefusedUnlessAllowed(): void
    {
        [$root, $vendor] = $this->gitFixture();
        file_put_contents($root . '/src/Example.php', self::PHP . "\n// Changed.\n");

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Uncommitted change to an included path: src/Example.php', $err);

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build', '--allow-dirty']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('not reproducible from a commit', $err);
    }

    public function testAnUntrackedFileInAnIncludedFolderIsADirtyTree(): void
    {
        [$root, $vendor] = $this->gitFixture();
        self::writeFiles($root, ['src/New/Untracked.php' => self::PHP]);

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Uncommitted change to an included path: src/New/Untracked.php', $err);
    }

    public function testAChangeOutsideTheIncludedPathsDoesNotBlockTheBuild(): void
    {
        [$root, $vendor] = $this->gitFixture();
        file_put_contents($root . '/tests/ExampleTest.php', self::PHP . "\n// Changed.\n");
        self::writeFiles($root, ['tests/Untracked.php' => self::PHP]);

        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $this->dir . '/out', '--test-build']);

        self::assertSame(0, $code, $err);
        self::assertSame('', $err);
    }

    // -------------------------------------------------------- T-REL-14

    public function testAnExistingArchiveIsNeverOverwritten(): void
    {
        [$root, $vendor] = $this->gitFixture();
        $out = $this->dir . '/out';
        self::assertSame(0, $this->build($root, ['--vendor=' . $vendor, '--out=' . $out, '--test-build'])[0]);
        $before = (string) file_get_contents($out . '/tiffindb-1.2.3-dev.zip');

        file_put_contents($root . '/src/Example.php', self::PHP . "\n// Changed.\n");
        self::git($root, ['add', '-A']);
        $this->commit($root);
        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $out, '--test-build']);

        self::assertSame(1, $code);
        self::assertStringContainsString('already exists', $err);
        self::assertSame($before, file_get_contents($out . '/tiffindb-1.2.3-dev.zip'));
        self::assertFileDoesNotExist($out . '/tiffindb-1.2.3-dev.zip.part');
    }

    public function testAFailedWriteLeavesNoPartFile(): void
    {
        $out = $this->dir . '/out';
        mkdir($out);
        self::writeFiles($this->dir . '/src', ['a.php' => self::PHP]);
        symlink($this->dir . '/src/a.php', $this->dir . '/src/link.php');

        foreach ([['a.php' => $this->dir . '/src/a.php', 'link.php' => $this->dir . '/src/link.php'], ['missing.php' => $this->dir . '/src/missing.php'], ['folder' => $this->dir . '/src']] as $entries) {
            try {
                ArchiveBuilder::write($entries, $out, '1.0.0');
                self::fail('The write should have been refused.');
            } catch (RuntimeException $e) {
                self::assertStringNotContainsString($this->dir, $e->getMessage());
            }
            self::assertSame([], glob($out . '/*') ?: []);
        }
    }

    public function testAStalePartFileIsReplaced(): void
    {
        $out = $this->dir . '/out';
        mkdir($out);
        self::writeFiles($this->dir . '/src', ['a.php' => self::PHP]);
        file_put_contents($out . '/tiffindb-1.0.0.zip.part', 'stale');

        $result = ArchiveBuilder::write(['src/a.php' => $this->dir . '/src/a.php'], $out, '1.0.0');

        self::assertSame(2, $result['files']);
        self::assertFileDoesNotExist($out . '/tiffindb-1.0.0.zip.part');
        self::assertSame(hash_file('sha256', $out . '/tiffindb-1.0.0.zip'), $result['sha256']);
    }

    public function testTheOutputFolderMustBeOutsideTheRepositoryOrIgnored(): void
    {
        [$root, $vendor] = $this->gitFixture();

        foreach ([$root . '/src/out', $root . '/public/../src/out', $root] as $out) {
            [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $out, '--test-build']);
            self::assertSame(1, $code);
            self::assertStringContainsString('--out folder must', $err);
        }
        self::assertDirectoryDoesNotExist($root . '/src/out');

        // build/ is git-ignored in the fixture, as in the repository (CI uses it).
        [$code, , $err] = $this->build($root, ['--vendor=' . $vendor, '--out=' . $root . '/build/out-1', '--test-build']);
        self::assertSame(0, $code, $err);
        self::assertFileExists($root . '/build/out-1/tiffindb-1.2.3-dev.zip');
    }

    // -------------------------------------------------------------- helpers

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                chmod($path, 0700);
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
