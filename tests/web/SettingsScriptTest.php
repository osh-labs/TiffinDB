<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Appearance;

/**
 * T-CF-8 (M4 CF-6, an M2 test gap): a static check of the class names that
 * public/assets/js/settings-preview.js accepts against Appearance. The
 * script's class-name pattern must accept every class Appearance can
 * emit and every data-preview value of the settings page; its groups are
 * the settings form's radio names, which are Appearance's setting
 * columns; its strong-contrast list is Appearance's high-contrast themes.
 */
#[CoversNothing]
final class SettingsScriptTest extends TestCase
{
    private const SCRIPT = 'public/assets/js/settings-preview.js';
    private const TEMPLATE = 'templates/pages/settings.php';

    /** The class-name pattern of the script, as a PHP regular expression. */
    private static function pattern(): string
    {
        $js = RepoFiles::read(self::SCRIPT);
        self::assertSame(1, preg_match_all('#if \((/\^[^/]+\$/)\.test\(value\)\)#', $js, $matches), 'The script has one class-name pattern.');
        $pattern = $matches[1][0];
        self::assertSame('/^[a-z][a-z0-9-]*$/', $pattern, 'Lower-case letters, digits, and hyphens, starting with a letter.');

        return $pattern;
    }

    /**
     * @return list<string>
     */
    private static function scriptList(string $name): array
    {
        $js = RepoFiles::read(self::SCRIPT);
        self::assertSame(1, preg_match('/var ' . $name . ' = \[([^\]]*)\];/', $js, $match), "The script declares {$name}.");
        preg_match_all("/'([^']*)'/", $match[1], $items);

        return $items[1];
    }

    /** @return array<string, array<string, string>> setting => (value => data-preview) */
    private static function previews(): array
    {
        $html = RepoFiles::read(self::TEMPLATE);
        preg_match_all('/<input type="radio" name="([a-z_]+)" value="([a-z-]+)" data-preview="([^"]*)"/', $html, $matches, PREG_SET_ORDER);
        $previews = [];
        foreach ($matches as [, $name, $value, $preview]) {
            $previews[$name][$value] = $preview;
        }
        self::assertSame(substr_count($html, 'data-preview='), array_sum(array_map('count', $previews)), 'Every data-preview is on a radio of this shape.');

        return $previews;
    }

    /** @return array<string, string> query parameter => user_settings column */
    private static function settingColumns(): array
    {
        $columns = (new \ReflectionClassConstant(Appearance::class, 'SETTING_COLUMNS'))->getValue();
        self::assertIsArray($columns);

        return $columns;
    }

    public function testEveryAppearanceClassPassesTheScriptsPattern(): void
    {
        $pattern = self::pattern();
        $count = 0;
        foreach (Appearance::MAP as $parameter => $classes) {
            foreach ($classes as $value => $class) {
                self::assertMatchesRegularExpression($pattern, $class, "{$parameter}={$value}");
                self::assertMatchesRegularExpression($pattern, 'pv-' . $class, "{$parameter}={$value}, preview form");
                $count++;
            }
        }
        self::assertGreaterThan(0, $count);
        self::assertDoesNotMatchRegularExpression($pattern, 'preview body');
        self::assertDoesNotMatchRegularExpression($pattern, 'A-dark');
        self::assertDoesNotMatchRegularExpression($pattern, '1x');
    }

    public function testEveryPreviewValueIsAnAppearanceClassThePatternAccepts(): void
    {
        $pattern = self::pattern();
        $columns = array_flip(self::settingColumns());
        $previews = self::previews();
        self::assertSame(['theme', 'accent', 'text_size', 'line_spacing', 'typeface'], array_keys($previews));
        foreach ($previews as $name => $values) {
            $parameter = $columns[$name] ?? null;
            self::assertIsString($parameter, "{$name} is a setting column of Appearance.");
            $map = Appearance::MAP[$parameter];
            foreach ($values as $value => $preview) {
                if (!isset($map[$value])) {
                    // A stored default: no root class; size and spacing show their standard preview class.
                    self::assertContains($preview, ['', 'pv-s-standard', 'pv-l-standard'], "{$name}={$value}");
                    continue;
                }
                self::assertMatchesRegularExpression($pattern, $preview, "{$name}={$value}");
                $expected = in_array($name, ['text_size', 'line_spacing'], true) ? 'pv-' . $map[$value] : $map[$value];
                self::assertSame($expected, $preview, "{$name}={$value}");
            }
            self::assertSame([], array_diff(array_keys($map), array_keys($values)), "Every {$parameter} value has a radio.");
        }
    }

    public function testTheScriptsGroupsAndStrongContrastThemesFollowAppearance(): void
    {
        $groups = self::scriptList('groups');
        self::assertEqualsCanonicalizing(array_values(self::settingColumns()), $groups, 'The groups are the setting columns.');
        self::assertEqualsCanonicalizing(array_keys(self::previews()), $groups, 'The groups are the radio names of the form.');

        $strong = self::scriptList('strongContrast');
        $high = (new \ReflectionClassConstant(Appearance::class, 'HIGH_CONTRAST'))->getValue();
        self::assertIsArray($high);
        $themes = array_keys(array_filter(Appearance::MAP['theme'], static fn (string $class): bool => in_array($class, $high, true)));
        self::assertEqualsCanonicalizing($themes, $strong);
        foreach (array_keys(Appearance::MAP['theme']) as $theme) {
            // The script drops the accent exactly where Appearance does.
            $classes = Appearance::fromQuery(['theme' => $theme, 'accent' => 'plum'])->rootClasses();
            self::assertSame(!in_array($theme, $strong, true), in_array('a-plum', $classes, true), $theme);
        }
    }
}
