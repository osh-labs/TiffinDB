<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tools\Release\ReleaseRules;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/../../tools/release/ReleaseRules.php';

/**
 * The host documentation (M7 release spec sections 14.1 and 14.3, M7-WP8).
 *
 * - Every backtick span in `docs/host/*.md` and `README.md` that starts
 *   with a shipped top folder, and every `php tools/...` command (in a
 *   span or a fenced block), names a path that exists in the tree and is
 *   shipped in the release archive; only paths that exist on an
 *   installation alone are allowed without existing (INSTALLATION_ONLY).
 *   A `<placeholder>` part ends the checked path at the folder before it.
 * - Every `config/local.php` key named (a `### \`key\`` heading of
 *   configuration.md, or a `'key' =>` in a PHP block) is in
 *   Kernel::LOCAL_KEYS, and every key there has its heading.
 * - Relative links between the chapters, and their `#anchors`, resolve.
 *   Links into `docs/user/` are left to M7-WP9's UserDocsTest.
 * - The README lists every chapter; the reserved place for the importer
 *   chapter is the fixed comment, once, and nothing else names it.
 * - Examples are synthetic: no address but user@example.com, no IPv4
 *   address outside 192.0.2.0/24, no name of the legacy product.
 * - plugins.md states the plugins' privileges plainly (plan section 6.2).
 */
#[CoversNothing]
final class HostDocsTest extends TestCase
{
    /** Top folders whose paths must exist (spec 14.3, plus the other two shipped folders). */
    private const CHECKED_PREFIXES = ['public/', 'src/', 'templates/', 'strings/', 'tools/', 'config/', 'docs/', 'plugins/', 'vendor/'];

    /** Paths that exist only on an installation. */
    private const INSTALLATION_ONLY = ['config/local.php'];

    /** Link targets owned by M7-WP9 (checked by UserDocsTest). */
    private const USER_DOCS = 'docs/user/';

    public const RESERVED = '<!-- reserved for a later chapter; see docs/specs/M7-release.md section 14.3 -->';

    /** @return array<string, string> relative path => contents */
    private static function documents(): array
    {
        $files = glob(RepoFiles::path('docs/host') . '/*.md');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'docs/host holds no chapters.');
        sort($files, SORT_STRING);
        $docs = [];
        foreach ($files as $file) {
            $docs['docs/host/' . basename($file)] = RepoFiles::read('docs/host/' . basename($file));
        }
        $docs['README.md'] = RepoFiles::read('README.md');

        return $docs;
    }

    /** The text with fenced code blocks removed. */
    private static function prose(string $markdown): string
    {
        return (string) preg_replace('/^```[^\n]*\n.*?^```[ \t]*$/ms', '', $markdown);
    }

    /** @return list<string> the contents of every fenced code block */
    private static function fences(string $markdown): array
    {
        preg_match_all('/^```([^\n]*)\n(.*?)^```[ \t]*$/ms', $markdown, $m);

        return $m[2];
    }

    /** @return list<string> inline backtick spans outside fenced blocks */
    public static function spans(string $markdown): array
    {
        preg_match_all('/`([^`\n]+)`/', self::prose($markdown), $m);

        return $m[1];
    }

    /**
     * The tree path a span or command line names, or null when it names
     * none: a `php tools/...` command gives its script; a path under a
     * checked prefix gives itself, cut before any `<placeholder>`,
     * `[option]`, or space, without a trailing slash or `#anchor`.
     */
    public static function namedPath(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('#^php\s+(tools/\S+)#', $text, $m) === 1) {
            return $m[1];
        }
        foreach (self::CHECKED_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $path = (string) preg_split('/[<\[\s#]/', $text, 2)[0];
                if (str_contains($text, '<')) {
                    // A placeholder: check the folder that holds it.
                    $path = substr($path, 0, (int) strrpos(rtrim($path, '/') . '/', '/'));
                }

                return rtrim($path, '/');
            }
        }

        return null;
    }

    /** Whether the release archive holds $path (a file or folder of the tree). */
    public static function shipped(string $path): bool
    {
        if ($path === 'vendor' || str_starts_with($path, 'vendor/')) {
            return true;
        }
        foreach (ReleaseRules::INCLUDE as $entry) {
            if (str_ends_with($entry, '/')
                ? ($path . '/' === $entry || str_starts_with($path . '/', $entry) || str_starts_with($entry, $path . '/'))
                : ($path === $entry || str_starts_with($entry, $path . '/'))) {
                return true;
            }
        }

        return false;
    }

    /** GitHub-style heading anchors of a Markdown text. @return list<string> */
    public static function anchors(string $markdown): array
    {
        preg_match_all('/^#{1,6}[ \t]+(.+?)[ \t]*$/m', self::prose($markdown), $m);
        $anchors = [];
        foreach ($m[1] as $heading) {
            $slug = strtolower((string) preg_replace('/[^\p{L}\p{N} _-]/u', '', $heading));
            $anchors[] = str_replace(' ', '-', $slug);
        }

        return $anchors;
    }

    // ---------------------------------------------------------- the tests

    public function testEveryNamedPathAndCommandExistsAndShips(): void
    {
        $problems = [];
        $checked = 0;
        foreach (self::documents() as $file => $text) {
            $candidates = self::spans($text);
            foreach (self::fences($text) as $block) {
                foreach (explode("\n", $block) as $line) {
                    if (str_starts_with(trim($line), 'php ')) {
                        $candidates[] = $line;
                    }
                }
            }
            foreach ($candidates as $candidate) {
                $path = self::namedPath($candidate);
                if ($path === null || $path === '') {
                    continue;
                }
                $checked++;
                if (in_array($path, self::INSTALLATION_ONLY, true)) {
                    continue;
                }
                if (!file_exists(RepoFiles::path($path))) {
                    $problems[] = "{$file}: `{$candidate}` names {$path}, which does not exist";
                } elseif (!self::shipped($path)) {
                    $problems[] = "{$file}: `{$candidate}` names {$path}, which the release archive does not hold";
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'The documentation should name the files it describes.');
        self::assertSame([], $problems);
    }

    public function testEveryLocalConfigurationKeyNamedIsAKernelKey(): void
    {
        $docs = self::documents();
        $configuration = $docs['docs/host/configuration.md'];
        preg_match_all('/^### `([^`]+)`$/m', $configuration, $m);
        $headed = $m[1];

        $inCode = [];
        foreach ($docs as $text) {
            foreach (self::fences($text) as $block) {
                if (str_contains($block, 'return [')) {
                    preg_match_all("/^\\s*'([^']+)'\\s*=>/m", $block, $k);
                    array_push($inCode, ...$k[1]);
                }
            }
        }

        self::assertSame(Kernel::LOCAL_KEYS, $headed, 'configuration.md has one heading per key, in the kernel\'s order.');
        self::assertNotEmpty($inCode);
        foreach ($inCode as $key) {
            self::assertContains($key, Kernel::LOCAL_KEYS, "The example names {$key}, which config/local.php may not set.");
        }
    }

    public function testRelativeLinksAndAnchorsResolve(): void
    {
        $problems = [];
        foreach (self::documents() as $file => $text) {
            preg_match_all('/\]\(([^)\s]+)\)/', self::prose($text), $m);
            foreach ($m[1] as $target) {
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                    $problems[] = "{$file}: links outside the repository ({$target})";
                    continue;
                }
                [$relative, $anchor] = array_pad(explode('#', $target, 2), 2, null);
                $path = $relative === '' ? $file : self::resolve(dirname($file), (string) $relative);
                if (str_starts_with($path, self::USER_DOCS)) {
                    continue;
                }
                if (!is_file(RepoFiles::path($path))) {
                    $problems[] = "{$file}: link to {$target} does not resolve";
                    continue;
                }
                if (!self::shipped($path)) {
                    $problems[] = "{$file}: link to {$target}, which the release archive does not hold";
                }
                if ($anchor !== null && !in_array($anchor, self::anchors(RepoFiles::read($path)), true)) {
                    $problems[] = "{$file}: no heading for #{$anchor} in {$path}";
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testTheReadmeListsEveryChapterAndKeepsTheReservedPlace(): void
    {
        $docs = self::documents();
        $index = $docs['docs/host/README.md'];
        foreach (array_keys($docs) as $file) {
            if (str_starts_with($file, 'docs/host/') && $file !== 'docs/host/README.md') {
                self::assertStringContainsString('](' . basename($file) . ')', $index, "The chapter list links {$file}.");
            }
        }
        foreach ($docs as $file => $text) {
            self::assertSame($file === 'docs/host/README.md' ? 1 : 0, substr_count($text, self::RESERVED), "{$file}: the reserved place");
            self::assertSame($file === 'docs/host/README.md' ? 1 : 0, substr_count($text, '<!--'), "{$file}: no other comment");
        }
        self::assertStringContainsString('](docs/host/README.md)', $docs['README.md']);
        self::assertStringContainsString('](docs/user/README.md)', $docs['README.md']);
    }

    public function testExamplesAreSynthetic(): void
    {
        foreach (self::documents() as $file => $text) {
            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', $text, $emails);
            foreach ($emails[0] as $email) {
                self::assertSame('user@example.com', $email, "{$file}: only user@example.com may appear");
            }
            preg_match_all('/(?<![\d.])\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(?![\d.])/', $text, $addresses);
            foreach ($addresses[0] as $address) {
                self::assertStringStartsWith('192.0.2.', $address, "{$file}: addresses come from 192.0.2.0/24");
            }
            self::assertDoesNotMatchRegularExpression('/bento/i', $text, "{$file} names no legacy product.");
            self::assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, "{$file}: no emoji");
        }
    }

    public function testPluginPrivilegesAreStatedPlainly(): void
    {
        $plugins = self::documents()['docs/host/plugins.md'];

        self::assertStringContainsString('full privileges of PHP and of the web server\'s account', $plugins);
        self::assertStringContainsString('There is no sandbox.', $plugins);
        self::assertStringContainsString('every project and the system database', $plugins);
        self::assertStringContainsString('Add only plugins you trust', $plugins);
    }

    public function testTheUpgradeChapterHasTheDeletionList(): void
    {
        $upgrade = self::documents()['docs/host/upgrade.md'];
        foreach (['public/assets/', 'src/', 'templates/', 'strings/', 'vendor/', 'tools/', 'docs/'] as $folder) {
            self::assertStringContainsString("- `{$folder}`\n", $upgrade, "The deletion list names {$folder}.");
        }
        self::assertStringContainsString('MANIFEST.txt', $upgrade);
        self::assertStringContainsString('Keep `config/local.php`, the data folder, and plugin folders you added yourself.', $upgrade);
    }

    // ------------------------------------------- the helpers catch mistakes

    public function testTheHelpersFindWhatTheyShould(): void
    {
        $sample = "Run `php tools/admin/nope.php <user>` and see `src/Missing/`.\n\n```\nphp tools/admin/reset-password.php x\n```\n## A `key` heading\n";

        self::assertSame(['php tools/admin/nope.php <user>', 'src/Missing/', 'key'], self::spans($sample));
        self::assertSame(["php tools/admin/reset-password.php x\n"], self::fences($sample));
        self::assertSame('tools/admin/nope.php', self::namedPath('php tools/admin/nope.php <user>'));
        self::assertSame('src/Missing', self::namedPath('src/Missing/'));
        self::assertSame('plugins/import', self::namedPath('plugins/import/<id>/'));
        self::assertSame('docs/formats/tiffin.md', self::namedPath('docs/formats/tiffin.md'));
        self::assertNull(self::namedPath('data/'));
        self::assertNull(self::namedPath('system.sqlite'));
        self::assertFalse(file_exists(RepoFiles::path('tools/admin/nope.php')));

        self::assertTrue(self::shipped('tools/admin/reset-password.php'));
        self::assertTrue(self::shipped('plugins/import'));
        self::assertTrue(self::shipped('docs'));
        self::assertFalse(self::shipped('docs/specs/M7-release.md'));
        self::assertFalse(self::shipped('tools/release/build.php'));

        self::assertSame(['a-key-heading', 'secure_cookies', '1-check-the-download'], self::anchors("## A `key` heading\n### `secure_cookies`\n## 1. Check the download\n"));
    }

    private static function resolve(string $dir, string $relative): string
    {
        $parts = $dir === '.' ? [] : explode('/', $dir);
        foreach (explode('/', $relative) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
