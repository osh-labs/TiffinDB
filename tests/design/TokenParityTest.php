<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Design;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\CssTokens;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-DES-1: the application token stylesheet keeps every colour of the
 * design reference. Every selector block of docs/design/tokens.css exists in
 * public/assets/css/tokens.css, every hex-colour custom property has the same
 * value in both, and neither file has a colour property the other lacks.
 */
#[CoversNothing]
final class TokenParityTest extends TestCase
{
    private const DESIGN = 'docs/design/tokens.css';
    private const APP = 'public/assets/css/tokens.css';

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
            $this->tempDir = null;
        }
    }

    /**
     * Every difference between the two files, as readable messages.
     *
     * @return list<string>
     */
    public static function differences(string $designCss, string $appCss): array
    {
        $designBlocks = CssTokens::customProperties($designCss);
        $appBlocks = CssTokens::customProperties($appCss);
        $designColours = CssTokens::colourProperties($designCss);
        $appColours = CssTokens::colourProperties($appCss);

        $problems = [];
        foreach (array_keys($designBlocks) as $selector) {
            if (!array_key_exists($selector, $appBlocks)) {
                $problems[] = "Selector block '{$selector}' of the design file is missing from the application file.";
            }
        }
        foreach ($designColours as $selector => $properties) {
            foreach ($properties as $property => $value) {
                $other = $appColours[$selector][$property] ?? null;
                if ($other === null) {
                    $problems[] = "'{$selector}' {$property} is a colour in the design file only.";
                } elseif ($other !== $value) {
                    $problems[] = "'{$selector}' {$property} is {$value} in the design file but {$other} in the application file.";
                }
            }
        }
        foreach ($appColours as $selector => $properties) {
            foreach ($properties as $property => $value) {
                if (!isset($designColours[$selector][$property])) {
                    $problems[] = "'{$selector}' {$property} is a colour in the application file only.";
                }
            }
        }

        return $problems;
    }

    public function testDesignFileHasColourTokensToCompare(): void
    {
        $colours = CssTokens::colourProperties(RepoFiles::read(self::DESIGN));

        // Guards against a parser regression that would make the parity check vacuous.
        self::assertArrayHasKey(':root', $colours);
        self::assertArrayHasKey('.t-dark', $colours);
        self::assertGreaterThan(10, count($colours[':root']));
    }

    public function testColoursAreIdenticalInBothFiles(): void
    {
        self::assertSame(
            [],
            self::differences(RepoFiles::read(self::DESIGN), RepoFiles::read(self::APP)),
            'A colour token change must be made in both ' . self::DESIGN . ' and ' . self::APP . '.',
        );
    }

    /** @return iterable<string, array{string, callable(string): string}> */
    public static function mutations(): iterable
    {
        $changeFirstColour = static fn (string $css): string => self::replaceOnce(
            '/(--[\w-]+:\s*#[0-9A-Fa-f]{5})([0-9A-Fa-f])/',
            static fn (array $m): string => $m[1] . (strtoupper($m[2]) === '0' ? '1' : '0'),
            $css,
        );
        $removeFirstColour = static fn (string $css): string => self::replaceOnce(
            '/--[\w-]+:\s*#[0-9A-Fa-f]{3,6}\s*;/',
            static fn (array $m): string => '',
            $css,
        );
        $addColour = static fn (string $css): string => self::replaceOnce(
            '/\{/',
            static fn (array $m): string => '{ --parity-extra: #123456;',
            $css,
        );
        $removeLastBlock = static fn (string $css): string => self::replaceOnce(
            '/\.t-hcd\s*\{[^}]*\}/',
            static fn (array $m): string => '',
            $css,
        );

        yield 'one colour changed in the design copy' => [self::DESIGN, $changeFirstColour];
        yield 'one colour changed in the application copy' => [self::APP, $changeFirstColour];
        yield 'one colour removed from the design copy' => [self::DESIGN, $removeFirstColour];
        yield 'one colour removed from the application copy' => [self::APP, $removeFirstColour];
        yield 'one colour added to the application copy' => [self::APP, $addColour];
        yield 'one selector block removed from the application copy' => [self::APP, $removeLastBlock];
    }

    /** @param callable(string): string $mutate */
    #[DataProvider('mutations')]
    public function testDifferenceInATemporaryCopyIsDetected(string $mutated, callable $mutate): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tiffindb-parity-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tempDir, 0700)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }

        $copies = [];
        foreach ([self::DESIGN, self::APP] as $file) {
            $contents = RepoFiles::read($file);
            if ($file === $mutated) {
                $changed = $mutate($contents);
                self::assertNotSame($contents, $changed, 'The mutation must change the copy.');
                $contents = $changed;
            }
            $copy = $this->tempDir . '/' . basename(dirname($file)) . '-' . basename($file);
            file_put_contents($copy, $contents);
            $copies[$file] = $copy;
        }

        $problems = self::differences(
            (string) file_get_contents($copies[self::DESIGN]),
            (string) file_get_contents($copies[self::APP]),
        );
        self::assertNotSame([], $problems, 'The parity check must report the difference.');
    }

    /** @param callable(array<int, string>): string $replace */
    private static function replaceOnce(string $pattern, callable $replace, string $subject): string
    {
        return (string) preg_replace_callback($pattern, $replace, $subject, 1);
    }
}
