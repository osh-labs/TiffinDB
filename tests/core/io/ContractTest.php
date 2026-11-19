<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\Inspection;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\SettingChoice;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;

/** T-IO-1: the contract values refuse what API 1 does not allow. */
#[CoversClass(Setting::class)]
#[CoversClass(Settings::class)]
#[CoversClass(SettingChoice::class)]
#[CoversClass(SourceTable::class)]
#[CoversClass(SourceFile::class)]
#[CoversClass(Inspection::class)]
#[CoversClass(PluginFailure::class)]
final class ContractTest extends TestCase
{
    private static function choices(): array
    {
        return [new SettingChoice('comma', 'Comma'), new SettingChoice('tab', 'Tab')];
    }

    /** @return iterable<string, array{\Closure}> */
    public static function badSettings(): iterable
    {
        yield 'name with a capital' => [static fn () => new Setting('Delimiter', 'Delimiter', 'boolean', '1')];
        yield 'name starting with a digit' => [static fn () => new Setting('1st', 'First', 'boolean', '1')];
        yield 'name too long' => [static fn () => new Setting(str_repeat('a', 33), 'Long', 'boolean', '1')];
        yield 'name with a hyphen' => [static fn () => new Setting('a-b', 'A', 'boolean', '1')];
        yield 'empty label' => [static fn () => new Setting('a', '', 'boolean', '1')];
        yield 'label too long' => [static fn () => new Setting('a', str_repeat('x', 81), 'boolean', '1')];
        yield 'label with a control character' => [static fn () => new Setting('a', "La\x07bel", 'boolean', '1')];
        yield 'label not UTF-8' => [static fn () => new Setting('a', "\xFF", 'boolean', '1')];
        yield 'help too long' => [static fn () => new Setting('a', 'A', 'boolean', '1', [], str_repeat('h', 201))];
        yield 'unknown kind' => [static fn () => new Setting('a', 'A', 'text', 'x')];
        yield 'boolean value' => [static fn () => new Setting('a', 'A', 'boolean', 'yes')];
        yield 'boolean with choices' => [static fn () => new Setting('a', 'A', 'boolean', '1', self::choices())];
        yield 'choice without choices' => [static fn () => new Setting('a', 'A', 'choice', 'comma')];
        yield 'choice value not offered' => [static fn () => new Setting('a', 'A', 'choice', 'semicolon', self::choices())];
        yield 'duplicate choice values' => [static fn () => new Setting('a', 'A', 'choice', 'tab', [new SettingChoice('tab', 'Tab'), new SettingChoice('tab', 'Tabs')])];
        yield 'choices not a list' => [static fn () => new Setting('a', 'A', 'choice', 'tab', ['x' => new SettingChoice('tab', 'Tab')])];
        yield 'choice not a SettingChoice' => [static fn () => new Setting('a', 'A', 'choice', 'tab', ['tab'])];
        yield 'empty choice value' => [static fn () => new SettingChoice('', 'Empty')];
        yield 'duplicate setting names' => [static fn () => new Settings([new Setting('a', 'A', 'boolean', '1'), new Setting('a', 'B', 'boolean', '0')])];
        yield 'settings holding another value' => [static fn () => new Settings(['a'])];
        yield 'no columns' => [static fn () => new SourceTable('Sheet', [], 1)];
        yield 'too many columns' => [static fn () => new SourceTable('Sheet', array_fill(0, 201, 'c'), 1)];
        yield 'column not text' => [static fn () => new SourceTable('Sheet', [1], 1)];
        yield 'columns not a list' => [static fn () => new SourceTable('Sheet', ['a' => 'A'], 1)];
        yield 'negative row count' => [static fn () => new SourceTable('Sheet', ['A'], -1)];
        yield 'table name too long' => [static fn () => new SourceTable(str_repeat('n', 1025), ['A'], 1)];
        yield 'heading too long' => [static fn () => new SourceTable('Sheet', [str_repeat('h', 80001)], 1)];
        yield 'inspection without tables' => [static fn () => new Inspection([], 'CSV')];
        yield 'inspection holding another value' => [static fn () => new Inspection(['Sheet'], 'CSV')];
        yield 'relative source path' => [static fn () => new SourceFile('upload.bin', 'csv', 1, '/tmp')];
        yield 'extension with a dot' => [static fn () => new SourceFile('/a/upload.bin', '.csv', 1, '/a/tmp')];
        yield 'negative size' => [static fn () => new SourceFile('/a/upload.bin', 'csv', -1, '/a/tmp')];
    }

    #[DataProvider('badSettings')]
    public function testRefused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testValidValuesAndWith(): void
    {
        $settings = new Settings([
            new Setting('delimiter', 'Delimiter', 'choice', 'comma', self::choices(), 'How cells are separated'),
            new Setting('heading', 'First row holds headings', 'boolean', '1'),
        ]);
        self::assertSame(['delimiter' => 'comma', 'heading' => '1'], $settings->values());
        self::assertSame('comma', $settings->value('delimiter'));
        self::assertNull($settings->value('absent'));

        $changed = $settings->with(['delimiter' => 'tab', 'heading' => '0', 'unknown' => 'x']);
        self::assertSame(['delimiter' => 'tab', 'heading' => '0'], $changed->values());
        self::assertSame(['delimiter' => 'comma', 'heading' => '1'], $settings->values(), 'with() returns a copy.');
        // Values a setting does not allow, and values that are not text, are ignored.
        $ignored = $settings->with(['delimiter' => 'pipe', 'heading' => 1, 'unknown' => '1']);
        self::assertSame(['delimiter' => 'comma', 'heading' => '1'], $ignored->values());
        self::assertSame(['delimiter' => 'comma', 'heading' => '1'], $settings->with(['heading' => ['1'], 'delimiter' => null])->values());

        $table = new SourceTable('Sheet 1', array_fill(0, 200, 'Column'), null, true);
        self::assertCount(200, $table->columns);
        self::assertTrue($table->hidden);
        self::assertSame('', (new SourceTable('', ["Line one\nline two"], 0))->name);
        self::assertSame(["Bad \xFF\x00 heading"], (new SourceTable("She\x00et", ["Bad \xFF\x00 heading"], 0))->columns, 'Taken as given; cleaned where used.');
    }

    public function testPluginFailureReasons(): void
    {
        $failure = new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'For the log only');
        self::assertSame('too-large', $failure->reason);
        self::assertSame('For the log only', $failure->getMessage());
        self::assertSame('failed', (new PluginFailure('made-up'))->reason);
    }
}
