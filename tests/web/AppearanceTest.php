<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/fixtures/Fixtures.php';

/** T-WEB-7: the appearance allowlist and root classes. */
#[CoversClass(Appearance::class)]
#[CoversClass(Kernel::class)]
final class AppearanceTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function allowlisted(): array
    {
        $cases = [];
        foreach (
            [
                ['theme', 'dark', 't-dark'], ['theme', 'hc-light', 't-hcl'], ['theme', 'hc-dark', 't-hcd'],
                ['accent', 'indigo', 'a-indigo'], ['accent', 'plum', 'a-plum'], ['accent', 'graphite', 'a-graphite'],
                ['font', 'atkinson', 'f-atk'], ['font', 'lexend', 'f-lexend'], ['font', 'source-serif', 'f-serif4'],
                ['font', 'device-sans', 'f-sans'], ['font', 'device-serif', 'f-serif'],
                ['size', 'small', 's-small'], ['size', 'large', 's-large'], ['size', 'larger', 's-larger'],
                ['size', 'largest', 's-largest'],
                ['spacing', 'roomy', 'l-roomy'], ['spacing', 'very-roomy', 'l-very-roomy'],
                ['motion', 'reduce', 'm-reduce'],
            ] as [$parameter, $value, $class]
        ) {
            $cases["{$parameter}={$value}"] = [$parameter, $value, $class];
        }

        return $cases;
    }

    #[DataProvider('allowlisted')]
    public function testEveryAllowlistedValueMapsToItsClass(string $parameter, string $value, string $class): void
    {
        self::assertSame([$class], Appearance::fromQuery([$parameter => $value])->rootClasses());
    }

    public function testTheTableIsExactlyTheSpecifiedOne(): void
    {
        $count = array_sum(array_map('count', Appearance::MAP));
        self::assertSame(count(self::allowlisted()), $count);
        self::assertSame(['theme', 'accent', 'font', 'size', 'spacing', 'motion'], array_keys(Appearance::MAP));
    }

    public function testDefaultsEmitNoClass(): void
    {
        self::assertSame([], Appearance::default()->rootClasses());
        self::assertSame([], Appearance::fromQuery([])->rootClasses());
        self::assertSame('light', Appearance::default()->theme());
    }

    public function testClassOrderIsFixed(): void
    {
        $query = [
            'motion' => 'reduce', 'spacing' => 'roomy', 'size' => 'large',
            'font' => 'lexend', 'accent' => 'plum', 'theme' => 'dark',
        ];

        self::assertSame(
            ['t-dark', 'a-plum', 'f-lexend', 's-large', 'l-roomy', 'm-reduce'],
            Appearance::fromQuery($query)->rootClasses(),
        );
    }

    /** @return array<string, array{mixed}> */
    public static function rejected(): array
    {
        return [
            'unknown' => ['purple'],
            'empty' => [''],
            'array' => [['dark']],
            'nested array' => [['x' => ['dark']]],
            'upper case' => ['DARK'],
            'padded' => [' dark'],
            'class name' => ['t-dark'],
            'integer' => [1],
            'null' => [null],
            'injection' => ['"><script>alert(1)</script>'],
            'NUL' => ["dark\0"],
        ];
    }

    #[DataProvider('rejected')]
    public function testUnlistedValuesGiveDefaults(mixed $value): void
    {
        $query = [];
        foreach (array_keys(Appearance::MAP) as $parameter) {
            $query[$parameter] = $value;
        }

        self::assertSame([], Appearance::fromQuery($query)->rootClasses());
        self::assertSame('light', Appearance::fromQuery($query)->theme());
    }

    public function testUnknownParametersAreIgnored(): void
    {
        self::assertSame(['t-dark'], Appearance::fromQuery(['theme' => 'dark', 'class' => 'x', 'colour' => 'red'])->rootClasses());
    }

    /** @return array<string, array{string, string}> */
    public static function highContrast(): array
    {
        return ['hc-light' => ['hc-light', 't-hcl'], 'hc-dark' => ['hc-dark', 't-hcd']];
    }

    #[DataProvider('highContrast')]
    public function testAccentIsOmittedUnderHighContrast(string $theme, string $class): void
    {
        foreach (['indigo', 'plum', 'graphite'] as $accent) {
            self::assertSame(
                [$class, 'f-atk'],
                Appearance::fromQuery(['theme' => $theme, 'accent' => $accent, 'font' => 'atkinson'])->rootClasses(),
            );
        }
        self::assertSame($theme, Appearance::fromQuery(['theme' => $theme])->theme());
    }

    public function testAccentIsKeptWithLightAndDark(): void
    {
        self::assertSame(['a-indigo'], Appearance::fromQuery(['accent' => 'indigo'])->rootClasses());
        self::assertSame(['t-dark', 'a-graphite'], Appearance::fromQuery(['theme' => 'dark', 'accent' => 'graphite'])->rootClasses());
    }

    public function testKernelWritesClassesOnTheRootElement(): void
    {
        $query = ['theme' => 'dark', 'accent' => 'plum', 'font' => 'lexend'];
        [$response] = Fixtures::handle(Fixtures::kernel(), Fixtures::request('GET', '/?theme=dark', $query));

        self::assertStringContainsString('<html lang="en" class="t-dark a-plum f-lexend">', $response->body);
    }

    public function testKernelOmitsTheClassAttributeByDefault(): void
    {
        [$response] = Fixtures::handle(Fixtures::kernel(), Fixtures::request('GET', '/'));

        self::assertStringContainsString('<html lang="en">', $response->body);
    }

    public function testPreviewFlagOffIgnoresParameters(): void
    {
        $query = ['theme' => 'dark', 'accent' => 'plum', 'motion' => 'reduce'];
        [$response] = Fixtures::handle(Fixtures::kernel(Fixtures::config(false)), Fixtures::request('GET', '/', $query));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<html lang="en">', $response->body);
        self::assertStringNotContainsString('t-dark', $response->body);
        // The preview links are not offered either.
        self::assertStringNotContainsString('theme=', $response->body);
    }

    public function testInjectedValuesNeverReachTheOutput(): void
    {
        $payload = '"><script>alert(1)</script>';
        $query = [];
        foreach (array_keys(Appearance::MAP) as $parameter) {
            $query[$parameter] = $payload;
        }
        $query['other'] = $payload;

        foreach (['/', '/nope', '/throws'] as $path) {
            [$response, $log] = Fixtures::handle(
                Fixtures::kernel(null, Fixtures::routes()),
                Fixtures::request('GET', $path, $query),
            );
            self::assertStringNotContainsString('<script', $response->body, $path);
            self::assertStringNotContainsString('alert(1)', $response->body, $path);
            self::assertStringNotContainsString('alert(1)', $log, $path);
            foreach ($response->headers as $value) {
                self::assertStringNotContainsString('alert(1)', $value, $path);
            }
            self::assertStringContainsString('<html lang="en">', $response->body, $path);
        }
    }

    public function testCurrentThemeIsMarkedInPreviewLinks(): void
    {
        [$response] = Fixtures::handle(Fixtures::kernel(), Fixtures::request('GET', '/', ['theme' => 'hc-dark']));

        self::assertStringContainsString('<a href="/?theme=hc-dark" aria-current="true">', $response->body);
        self::assertSame(1, substr_count($response->body, 'aria-current'));
        foreach (['href="/"', 'href="/?theme=dark"', 'href="/?theme=hc-light"'] as $link) {
            self::assertStringContainsString($link, $response->body);
        }
    }
}
