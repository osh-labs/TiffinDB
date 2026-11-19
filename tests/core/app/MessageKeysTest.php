<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * M1 spec section 12: every problem key the core can produce is one of
 * the keys listed there (whose English text lives in the strings file),
 * and every key the core operations produce is produced somewhere.
 * Keys are found as whole string literals in the PHP tokens.
 */
#[CoversNothing]
final class MessageKeysTest extends TestCase
{
    /** Keys Core\App produces itself (sections 9.2 to 9.4). */
    private const APP_KEYS = [
        'problem.password.current_wrong',
        'problem.throttled',
        'problem.people.last_admin',
        'problem.people.self_deactivate',
        'problem.people.admin_has_all',
        'problem.username.taken',
        'problem.project_name.taken',
    ];

    /**
     * Keys the core produces that a spec section does not list yet: the
     * orchestrator adds each to M3 spec section 14 and its text to
     * strings/en/problems.php (owned by M3-WP3), then removes it here.
     */
    private const PENDING_SPEC_KEYS = [
        'problem.import.too_many_links', // P142, M3-WP1b
    ];

    public function testEveryCoreProblemKeyIsListedInTheSpec(): void
    {
        // M1 spec section 12, M2 spec section 8, and M3 spec section 14; the strings file test stays M1-only.
        $listed = [...self::specKeys(), ...self::m2Keys(), ...self::m3Keys(), ...self::PENDING_SPEC_KEYS];
        foreach (self::PENDING_SPEC_KEYS as $pending) {
            self::assertNotContains($pending, self::m3Keys(), $pending . ' is listed now: remove it from PENDING_SPEC_KEYS.');
        }
        $used = self::coreKeys('src/Core');
        self::assertNotEmpty($used);
        self::assertSame([], array_values(array_diff(array_keys($used), $listed)), 'Keys used by the core but missing from M1 spec section 12, M2 spec section 8, and M3 spec section 14.');
    }

    /** @return list<string> keys listed in `docs/specs/M3-io.md` section 14 */
    private static function m3Keys(): array
    {
        $spec = (string) file_get_contents(RepoFiles::path('docs/specs/M3-io.md'));
        $start = strpos($spec, "\n## 14. Message keys produced by the core");
        $end = strpos($spec, "\n## 15.", (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        preg_match_all('/^\| `(problem\.[a-z_.]+)` \|/m', substr($spec, $start, $end - $start), $matches);
        self::assertGreaterThanOrEqual(31, count($matches[1]));
        self::assertContains('problem.import.target_changed', $matches[1]);

        return $matches[1];
    }

    public function testTheImportPipelineProducesItsKeys(): void
    {
        $io = self::coreKeys('src/Core/Io');
        foreach (['problem.import.nothing_included', 'problem.import.type_invalid', 'problem.import.field_twice', 'problem.import.too_many_choices', 'problem.import.target_changed'] as $key) {
            self::assertContains($key, self::m3Keys());
            self::assertArrayHasKey($key, $io, $key . ' is produced by Core\Io.');
        }
    }

    /** @return list<string> keys listed in `docs/specs/M2-records.md` section 8 */
    private static function m2Keys(): array
    {
        $spec = (string) file_get_contents(RepoFiles::path('docs/specs/M2-records.md'));
        $start = strpos($spec, "\n## 8. Message keys produced by the core");
        $end = strpos($spec, "\n## 9.", (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        preg_match_all('/^\| `(problem\.[a-z_.]+)` \|/m', substr($spec, $start, $end - $start), $matches);
        self::assertNotEmpty($matches[1]);

        return $matches[1];
    }

    public function testTheOperationsProduceTheirKeys(): void
    {
        $app = self::coreKeys('src/Core/App');
        foreach (self::APP_KEYS as $key) {
            self::assertContains($key, self::specKeys());
            self::assertArrayHasKey($key, $app, $key . ' is produced by Core\App.');
        }
    }

    public function testTheStringsFileHasEveryListedKey(): void
    {
        $strings = require RepoFiles::path('strings/en/problems.php');
        self::assertIsArray($strings);
        self::assertSame([], array_values(array_diff(self::specKeys(), array_keys($strings))));
    }

    /** @return list<string> */
    private static function specKeys(): array
    {
        $spec = (string) file_get_contents(RepoFiles::path('docs/specs/M1-foundation.md'));
        $start = strpos($spec, "\n## 12. Message keys produced by the core");
        $end = strpos($spec, "\n## 13.", (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        preg_match_all('/^\| `(problem\.[a-z_.]+)` \|/m', substr($spec, $start, $end - $start), $matches);

        return $matches[1];
    }

    /** @return array<string, true> problem keys written as whole string literals under $dir */
    private static function coreKeys(string $dir): array
    {
        $keys = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RepoFiles::path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = substr($token[1], 1, -1);
                    if (preg_match('/^problem\.[a-z_]+(?:\.[a-z_]+)*\z/', $value) === 1) {
                        $keys[$value] = true;
                    }
                }
            }
        }

        return $keys;
    }
}
