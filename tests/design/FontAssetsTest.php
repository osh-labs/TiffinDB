<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Design;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TiffinDB\Tests\Support\CssTokens;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-DES-5 (font files and licences) and T-DES-6 (font declarations and the
 * dependency record). Expected sizes and checksums are those of M0 spec
 * section 10.3; the files are never regenerated, so a mismatch means the
 * file changed and must be investigated, not that the value needs updating.
 */
#[CoversNothing]
final class FontAssetsTest extends TestCase
{
    private const FONT_DIR = 'public/assets/fonts';
    private const CSS_DIR = 'public/assets/css';

    /** Total size ceiling of the font directory (ADR 0009 row B2). */
    private const CEILING_BYTES = 2_097_152;

    private const LICENCE_PHRASE = 'SIL Open Font License, Version 1.1';

    /** Font files: path under the font directory => [bytes, SHA-256]. */
    private const FONT_FILES = [
        'inter/inter-latin-400-normal.woff2' => [23_664, '8909904ab6c872eb994093482a88a28eca2cd95912d7b6fecd72103b0dc07edc'],
        'inter/inter-latin-700-normal.woff2' => [24_356, '6f56409fd3d64bb85f7d070bce20749db2d66b6d63cec586cc22d1c761be2491'],
        'atkinson-hyperlegible/atkinson-hyperlegible-latin-400-normal.woff2' => [17_208, 'd64ba838ef5472bba248620ec4fd8b5aa7cf0db2908e0bb230600caf279ba7bc'],
        'atkinson-hyperlegible/atkinson-hyperlegible-latin-700-normal.woff2' => [17_524, '140e2bd25a7315c8a062508391426b0d8c3297400c947b8d847be28f73a199f0'],
        'lexend/lexend-latin-400-normal.woff2' => [14_476, '0601e0a909219a542cdd581e3f8f1ff8fb208978cbc9bca1c90df02fd8062bb1'],
        'lexend/lexend-latin-700-normal.woff2' => [14_808, '52dcdaa127bac4a5ea4c39ee17a3f454ceacc18d9f2a45cc453eeab68d378588'],
        'source-serif-4/SourceSerif4-Regular.ttf.woff2' => [76_260, '6b053e98f0838afe81f3e784727be4583a7c13bb42f198dc5202ecffee0aaee0'],
        'source-serif-4/SourceSerif4-Bold.ttf.woff2' => [81_540, '6d4fd4c0f08798a478fe34aafea9bf5cfa1cbc6ae4b834193febaee74254d10c'],
    ];

    /** Licence files: path under the font directory => SHA-256. */
    private const LICENCE_FILES = [
        'inter/LICENSE.txt' => '262481e844521b326f5ecd053e59b98c8b2da78c8ee1bdbb6e8174305e54935a',
        'atkinson-hyperlegible/LICENSE.txt' => 'f32d22b3908fcad2c86a74000614ec22e6a7f66ea7e867e616026a27aebdc143',
        'lexend/LICENSE.txt' => '5da8505887d0fa7fe963445fd58852707fda34adfeb65af25c99d152bab285bd',
        'source-serif-4/LICENSE.txt' => '75784a295293a8992f5a8d99210566e0064a012e6dab6731305e3787f15896c7',
    ];

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeTree($this->tempDir);
            $this->tempDir = null;
        }
    }

    /**
     * Problems with the font directory: missing or changed files, licence
     * files without the OFL statement, unexpected files, size ceiling.
     *
     * @return list<string>
     */
    public static function fontDirectoryProblems(string $dir): array
    {
        $problems = [];
        foreach (self::FONT_FILES as $relative => [$bytes, $sha256]) {
            $path = $dir . '/' . $relative;
            if (!is_file($path)) {
                $problems[] = "missing: {$relative}";
                continue;
            }
            if (filesize($path) !== $bytes) {
                $problems[] = "size: {$relative} is " . filesize($path) . " bytes, expected {$bytes}";
            }
            if (hash_file('sha256', $path) !== $sha256) {
                $problems[] = "checksum: {$relative} does not match";
            }
        }
        foreach (self::LICENCE_FILES as $relative => $sha256) {
            $path = $dir . '/' . $relative;
            if (!is_file($path)) {
                $problems[] = "missing: {$relative}";
                continue;
            }
            if (hash_file('sha256', $path) !== $sha256) {
                $problems[] = "checksum: {$relative} does not match";
            }
            if (!str_contains((string) file_get_contents($path), self::LICENCE_PHRASE)) {
                $problems[] = "licence: {$relative} does not contain '" . self::LICENCE_PHRASE . "'";
            }
        }

        $expected = [...array_keys(self::FONT_FILES), ...array_keys(self::LICENCE_FILES)];
        $total = 0;
        foreach (self::listFiles($dir) as $relative => $size) {
            $total += $size;
            if (!in_array($relative, $expected, true)) {
                $problems[] = "unexpected: {$relative}";
            }
        }
        if ($total > self::CEILING_BYTES) {
            $problems[] = "ceiling: the font directory holds {$total} bytes, more than " . self::CEILING_BYTES;
        }

        return $problems;
    }

    /**
     * Problems with the @font-face rules of fonts.css.
     *
     * @return list<string>
     */
    public static function fontFaceProblems(string $fontsCss, string $tokensCss, string $cssDir, string $fontDir): array
    {
        $problems = [];
        $faces = self::fontFaces($fontsCss);
        if (count($faces) !== 8) {
            $problems[] = 'count: ' . count($faces) . ' @font-face rules, expected 8';
        }

        $families = self::tokenFamilies($tokensCss);
        $fontRoot = realpath($fontDir);
        $references = [];
        foreach ($faces as $index => $face) {
            $label = "@font-face #" . ($index + 1);
            if (($face['font-display'] ?? null) !== 'swap') {
                $problems[] = "display: {$label} lacks font-display: swap";
            }
            if (!in_array($face['font-weight'] ?? null, ['400', '700'], true)) {
                $problems[] = "weight: {$label} has weight '" . ($face['font-weight'] ?? '') . "'";
            }
            $family = self::unquote($face['font-family'] ?? '');
            if (!in_array($family, $families, true)) {
                $problems[] = "family: {$label} family '{$family}' is not used by tokens.css";
            }

            preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $face['src'] ?? '', $urls);
            if (count($urls[2]) !== 1) {
                $problems[] = "url: {$label} has " . count($urls[2]) . ' url() sources, expected 1';
                continue;
            }
            $url = $urls[2][0];
            if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '\\') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1 || str_contains($url, '?') || str_contains($url, '#')) {
                $problems[] = "url: {$label} url '{$url}' is not a plain relative path";
                continue;
            }
            $resolved = realpath($cssDir . '/' . $url);
            if ($resolved === false || !is_file($resolved) || $fontRoot === false || !str_starts_with($resolved, $fontRoot . DIRECTORY_SEPARATOR)) {
                $problems[] = "url: {$label} url '{$url}' does not resolve to a file in the font directory";
                continue;
            }
            $references[$resolved] = ($references[$resolved] ?? 0) + 1;
        }

        if ($fontRoot !== false) {
            foreach (array_keys(self::listFiles($fontRoot)) as $relative) {
                if (!str_ends_with($relative, '.woff2')) {
                    continue;
                }
                $count = $references[realpath($fontRoot . '/' . $relative)] ?? 0;
                if ($count !== 1) {
                    $problems[] = "reference: {$relative} is referenced {$count} times, expected once";
                }
            }
        }

        return $problems;
    }

    /**
     * Family names and font file names missing from the dependency record.
     *
     * @param list<string> $families
     * @param list<string> $fileNames
     * @return list<string>
     */
    public static function dependencyRecordProblems(string $markdown, array $families, array $fileNames): array
    {
        $problems = [];
        foreach ([...$families, ...$fileNames] as $name) {
            if (!str_contains($markdown, $name)) {
                $problems[] = "docs/dependencies.md does not mention '{$name}'";
            }
        }

        return $problems;
    }

    public function testFontFilesAndLicencesAreAsRecorded(): void
    {
        self::assertSame([], self::fontDirectoryProblems(RepoFiles::path(self::FONT_DIR)));
    }

    public function testFontFaceDeclarations(): void
    {
        self::assertSame([], self::fontFaceProblems(
            RepoFiles::read(self::CSS_DIR . '/fonts.css'),
            RepoFiles::read(self::CSS_DIR . '/tokens.css'),
            RepoFiles::path(self::CSS_DIR),
            RepoFiles::path(self::FONT_DIR),
        ));
    }

    public function testDependencyRecordMentionsEveryFamilyAndFile(): void
    {
        $families = array_values(array_unique(array_map(
            static fn (array $face): string => self::unquote($face['font-family'] ?? ''),
            self::fontFaces(RepoFiles::read(self::CSS_DIR . '/fonts.css')),
        )));
        self::assertCount(4, $families);
        $fileNames = array_map('basename', array_keys(self::FONT_FILES));

        self::assertSame([], self::dependencyRecordProblems(RepoFiles::read('docs/dependencies.md'), $families, $fileNames));
    }

    public function testDependencyRecordOmissionIsDetected(): void
    {
        $problems = self::dependencyRecordProblems('Inter: inter-latin-400-normal.woff2', ['Inter', 'Lexend'], ['inter-latin-400-normal.woff2', 'lexend-latin-400-normal.woff2']);

        self::assertCount(2, $problems);
    }

    /** @return iterable<string, array{callable(string): void, string}> */
    public static function directoryMutations(): iterable
    {
        $first = array_key_first(self::FONT_FILES);
        $firstLicence = array_key_first(self::LICENCE_FILES);

        yield 'font file changed by one byte' => [
            static function (string $dir) use ($first): void {
                $path = "{$dir}/{$first}";
                $bytes = (string) file_get_contents($path);
                $bytes[100] = chr(ord($bytes[100]) ^ 1);
                file_put_contents($path, $bytes);
            },
            'checksum:',
        ];
        yield 'font file extended' => [
            static fn (string $dir) => file_put_contents("{$dir}/{$first}", "\0", FILE_APPEND),
            'size:',
        ];
        yield 'font file removed' => [static fn (string $dir) => unlink("{$dir}/{$first}"), 'missing:'];
        yield 'licence file removed' => [static fn (string $dir) => unlink("{$dir}/{$firstLicence}"), 'missing:'];
        yield 'licence without the OFL statement' => [
            static fn (string $dir) => file_put_contents("{$dir}/{$firstLicence}", 'All rights reserved.'),
            'licence:',
        ];
        yield 'extra file' => [static fn (string $dir) => file_put_contents("{$dir}/inter/inter-latin-400-italic.woff2", 'x'), 'unexpected:'];
        yield 'extra hidden file' => [static fn (string $dir) => file_put_contents("{$dir}/.DS_Store", 'x'), 'unexpected:'];
        yield 'extra directory' => [
            static function (string $dir): void {
                mkdir("{$dir}/other");
                file_put_contents("{$dir}/other/other-400.woff2", 'x');
            },
            'unexpected:',
        ];
        yield 'over the 2 MB ceiling' => [
            static function (string $dir): void {
                $handle = fopen("{$dir}/large.woff2", 'wb');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create a temporary file.');
                }
                ftruncate($handle, self::CEILING_BYTES);
                fclose($handle);
            },
            'ceiling:',
        ];
    }

    /** @param callable(string): void $mutate */
    #[DataProvider('directoryMutations')]
    public function testFontDirectoryProblemIsDetected(callable $mutate, string $expectedPrefix): void
    {
        $copy = $this->copyFontDirectory();
        self::assertSame([], self::fontDirectoryProblems($copy), 'The unmodified copy must pass.');

        $mutate($copy);

        $matching = array_filter(
            self::fontDirectoryProblems($copy),
            static fn (string $problem): bool => str_starts_with($problem, $expectedPrefix),
        );
        self::assertNotSame([], $matching, "Expected a '{$expectedPrefix}' problem.");
    }

    /** @return iterable<string, array{callable(string): string, string}> */
    public static function declarationMutations(): iterable
    {
        $once = static fn (string $pattern, string $replacement): callable =>
            static fn (string $css): string => (string) preg_replace($pattern, $replacement, $css, 1);

        yield 'one rule removed' => [$once('/@font-face\s*\{[^}]*\}/', ''), 'count:'];
        yield 'one rule added' => [static fn (string $css): string => $css . "\n@font-face { font-family: 'Inter'; font-weight: 400; font-display: swap; src: url('../fonts/inter/inter-latin-400-normal.woff2') format('woff2'); }", 'count:'];
        yield 'font-display not swap' => [$once('/font-display:\s*swap/', 'font-display: block'), 'display:'];
        yield 'font-display missing' => [$once('/font-display:\s*swap;/', ''), 'display:'];
        yield 'weight 500' => [$once('/font-weight:\s*400/', 'font-weight: 500'), 'weight:'];
        yield 'family not used by tokens' => [$once("/font-family:\s*'Inter'/", "font-family: 'Inter Display'"), 'family:'];
        yield 'root-relative url' => [$once("/url\('\.\.\//", "url('/assets/"), 'url:'];
        yield 'network url' => [$once("/url\('\.\.\//", "url('https://fonts.example.com/"), 'url:'];
        yield 'protocol-relative url' => [$once("/url\('\.\.\//", "url('//fonts.example.com/"), 'url:'];
        yield 'two sources' => [$once("/format\('woff2'\)/", "format('woff2'), url('../fonts/inter/inter-latin-700-normal.woff2') format('woff2')"), 'url:'];
        yield 'url to a missing file' => [$once('/-latin-400-normal\.woff2/', '-latin-500-normal.woff2'), 'url:'];
        yield 'url outside the font directory' => [$once("/url\('\.\.\/fonts\/[^']*'\)/", "url('tokens.css')"), 'url:'];
        yield 'file referenced twice' => [$once('/-latin-700-normal\.woff2/', '-latin-400-normal.woff2'), 'reference:'];
    }

    /** @param callable(string): string $mutate */
    #[DataProvider('declarationMutations')]
    public function testFontFaceProblemIsDetected(callable $mutate, string $expectedPrefix): void
    {
        $css = RepoFiles::read(self::CSS_DIR . '/fonts.css');
        $mutated = $mutate($css);
        self::assertNotSame($css, $mutated, 'The mutation must change the stylesheet.');

        $matching = array_filter(
            self::fontFaceProblems($mutated, RepoFiles::read(self::CSS_DIR . '/tokens.css'), RepoFiles::path(self::CSS_DIR), RepoFiles::path(self::FONT_DIR)),
            static fn (string $problem): bool => str_starts_with($problem, $expectedPrefix),
        );
        self::assertNotSame([], $matching, "Expected a '{$expectedPrefix}' problem.");
    }

    /**
     * The @font-face rules of a stylesheet as property => value maps.
     *
     * @return list<array<string, string>>
     */
    private static function fontFaces(string $css): array
    {
        $faces = [];
        foreach (CssTokens::parse($css) as $node) {
            if ($node['kind'] === 'at' && $node['name'] === 'font-face') {
                $face = [];
                foreach ($node['declarations'] ?? [] as [$property, $value]) {
                    $face[$property] = $value;
                }
                $faces[] = $face;
            }
        }

        return $faces;
    }

    /**
     * Every family name listed in a `--font-*` custom property of tokens.css.
     *
     * @return list<string>
     */
    private static function tokenFamilies(string $tokensCss): array
    {
        $families = [];
        foreach (CssTokens::customProperties($tokensCss) as $properties) {
            foreach ($properties as $name => $value) {
                if (str_starts_with($name, '--font-')) {
                    foreach (explode(',', $value) as $family) {
                        $families[] = self::unquote($family);
                    }
                }
            }
        }

        return array_values(array_unique($families));
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Every regular file below a directory, hidden files included.
     *
     * @return array<string, int> relative path => size, sorted by path
     */
    private static function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $files[$relative] = (int) $file->getSize();
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function copyFontDirectory(): string
    {
        $this->tempDir = sys_get_temp_dir() . '/tiffindb-fonts-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tempDir, 0700)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }
        $source = RepoFiles::path(self::FONT_DIR);
        foreach (array_keys(self::listFiles($source)) as $relative) {
            $target = $this->tempDir . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            if (!copy($source . '/' . $relative, $target)) {
                throw new RuntimeException('Cannot copy a font file.');
            }
        }

        return $this->tempDir;
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
