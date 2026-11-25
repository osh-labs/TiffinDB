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

/**
 * The user documentation under docs/user/ (M7 release spec sections 14.2
 * and 14.3, M7-WP9).
 *
 * - The chapter files of section 14.2 exist, and README.md links to each.
 * - Every bold span (`**...**`) in docs/user/*.md equals, character for
 *   character, the English value of a key in strings/en/*.php; a value
 *   with placeholders matches with each `{...}` standing for any text
 *   (a value with no letter outside its placeholders matches nothing).
 * - README.md holds the reserved place for the importer chapter (one HTML
 *   comment, naming nothing), and no file links to a chapter that does not
 *   exist.
 * - No images (no screenshots in v1, plan 8.2 row 9), and email addresses
 *   and IP addresses are synthetic only.
 */
#[CoversNothing]
final class UserDocsTest extends TestCase
{
    private const DIRECTORY = 'docs/user';

    /** The chapter files of section 14.2. */
    private const CHAPTERS = [
        'README.md',
        'first-steps.md',
        'records.md',
        'lists-and-search.md',
        'collections.md',
        'files.md',
        'trash.md',
        'forms.md',
        'import-and-export.md',
        'display-settings.md',
        'for-admins.md',
    ];

    /** The reserved place for the importer chapter (sections 14.1 to 14.3; replaced by M7-WP11). */
    public const RESERVED = '<!-- reserved for a later chapter; see docs/specs/M7-release.md section 14.3 -->';


    // ---------------------------------------------------------------- rules

    /**
     * The bold spans of a Markdown text, in order.
     *
     * @return list<string>
     */
    public static function boldSpans(string $markdown): array
    {
        // Code spans and fenced blocks are not prose; drop them first.
        $text = (string) preg_replace('/^```.*?^```/ms', '', $markdown);
        $text = (string) preg_replace('/`[^`\n]*`/', '', $text);
        preg_match_all('/\*\*(.+?)\*\*/s', $text, $matches);

        return array_values(array_map(static fn (string $span): string => $span, $matches[1]));
    }

    /**
     * Whether a bold span equals a string value (placeholders match any text).
     *
     * @param array<string, string> $strings
     */
    public static function matchesSomeString(string $span, array $strings): bool
    {
        foreach ($strings as $value) {
            if ($value === $span) {
                return true;
            }
            if (!str_contains($value, '{')) {
                continue;
            }
            $parts = preg_split('/\{[^{}]*\}/', $value);
            // A value with no letter outside its placeholders ("{message}",
            // "{Item} {id}") would match any text, so it matches nothing.
            if ($parts === false || preg_match('/\pL/u', implode('', $parts)) !== 1) {
                continue;
            }
            $pattern = '/^' . implode('.+?', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts)) . '$/su';
            if (preg_match($pattern, $span) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> file name => text */
    private static function documents(): array
    {
        $documents = [];
        foreach (glob(RepoFiles::path(self::DIRECTORY) . '/*.md') ?: [] as $path) {
            $documents[basename($path)] = (string) file_get_contents($path);
        }
        ksort($documents, SORT_STRING);

        return $documents;
    }

    // ---------------------------------------------------------------- tests

    public function testEveryChapterExistsAndTheReadmeLinksToIt(): void
    {
        $documents = self::documents();
        foreach (self::CHAPTERS as $chapter) {
            self::assertArrayHasKey($chapter, $documents, "docs/user/{$chapter} is missing (M7 release spec section 14.2).");
        }
        self::assertSame([], array_values(array_diff(array_keys($documents), self::CHAPTERS)), 'docs/user/ holds only the chapters of section 14.2.');
        foreach (self::CHAPTERS as $chapter) {
            if ($chapter !== 'README.md') {
                self::assertStringContainsString('](' . $chapter . ')', $documents['README.md'], "README.md must link to {$chapter}.");
            }
        }
    }

    public function testEveryBoldSpanIsAStringOfTheInterface(): void
    {
        /** @var array<string, string> $strings */
        $strings = require RepoFiles::path('strings/en.php');
        $unknown = [];
        $count = 0;
        foreach (self::documents() as $name => $text) {
            foreach (self::boldSpans($text) as $span) {
                $count++;
                if (!self::matchesSomeString($span, $strings)) {
                    $unknown[] = "{$name}: **{$span}**";
                }
            }
        }
        self::assertGreaterThan(100, $count, 'The chapters name the buttons and screens they describe in bold.');
        self::assertSame([], $unknown, 'Bold is only for button labels and screen names, written exactly as in strings/en/.');
    }

    public function testTheReservedPlaceIsPresentAndNamesNothing(): void
    {
        $documents = self::documents();
        self::assertSame(1, substr_count($documents['README.md'], self::RESERVED), 'README.md holds the reserved place once.');
        foreach ($documents as $name => $text) {
            if ($name !== 'README.md') {
                self::assertStringNotContainsString(self::RESERVED, $text, "Only README.md holds the reserved place ({$name}).");
            }
            preg_match_all('/<!--(.*?)-->/s', $text, $comments);
            foreach ($comments[0] as $comment) {
                self::assertSame(self::RESERVED, $comment, "{$name}: the only HTML comment is the reserved place.");
            }
        }
    }

    public function testLinksBetweenChaptersResolve(): void
    {
        $root = RepoFiles::root();
        foreach (self::documents() as $name => $text) {
            preg_match_all('/\]\(([^)\s#]+)(#[^)\s]*)?\)/', $text, $links);
            foreach ($links[1] as $target) {
                if (preg_match('#^[a-z]+:#', $target) === 1) {
                    continue;
                }
                $path = $root . '/' . self::DIRECTORY . '/' . $target;
                self::assertFileExists($path, "{$name} links to {$target}, which does not exist.");
            }
        }
    }

    public function testNoScreenshotsAndOnlySyntheticAddresses(): void
    {
        foreach (self::documents() as $name => $text) {
            self::assertDoesNotMatchRegularExpression('/!\[[^\]]*\]\(|<img\b/i', $text, "{$name}: no images or screenshots in v1 (plan 8.2 row 9).");
            preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $text, $emails);
            foreach ($emails[1] as $domain) {
                self::assertContains(strtolower($domain), ['example.com', 'example.org', 'example.net'], "{$name}: email addresses use a reserved example domain.");
            }
            preg_match_all('/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/', $text, $ips, PREG_SET_ORDER);
            foreach ($ips as $ip) {
                $prefix = $ip[1] . '.' . $ip[2] . '.' . $ip[3];
                self::assertContains($prefix, ['192.0.2', '198.51.100', '203.0.113'], "{$name}: IP addresses use a documentation range.");
            }
        }
    }

    public function testTheMatcherTreatsPlaceholdersAsAnyText(): void
    {
        $strings = ['a' => 'Add {item}', 'b' => 'Save', 'c' => '{count|# record|# records} in {table}', 'd' => '{field} {value}'];
        self::assertTrue(self::matchesSomeString('Add item', $strings));
        self::assertTrue(self::matchesSomeString('Save', $strings));
        self::assertTrue(self::matchesSomeString('3 records in Items', $strings));
        self::assertFalse(self::matchesSomeString('save', $strings));
        self::assertFalse(self::matchesSomeString('Add', $strings));
        self::assertFalse(self::matchesSomeString('Save the rows', $strings));
        self::assertFalse(self::matchesSomeString('Anything at all', $strings));
        self::assertSame(['Save', 'Move to trash'], self::boldSpans("Choose **Save**, then `**x**` and **Move to trash**.\n```\n**y**\n```\n"));
    }
}
