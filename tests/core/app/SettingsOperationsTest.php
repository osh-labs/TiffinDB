<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\SettingsOperations;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\AccessDenied;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-78: a person's own display settings. */
#[CoversClass(SettingsOperations::class)]
#[CoversClass(Users::class)]
final class SettingsOperationsTest extends AppTestCase
{
    private const DEFAULTS = ['theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter', 'text_size' => 'standard', 'line_spacing' => 'standard', 'reduce_motion' => 0];
    private const POSTED = ['theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter', 'text_size' => 'standard', 'line_spacing' => 'standard', 'reduce_motion' => ''];

    private function auditCount(): int
    {
        return (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log');
    }

    public function testEveryAllowedValueIsSavedAndReadBack(): void
    {
        $settings = $this->ops->settings();
        self::assertSame(self::DEFAULTS, $settings->get($this->member));
        $audit = $this->auditCount();
        foreach (Users::SETTINGS as $column => $allowed) {
            if ($column === 'reduce_motion') {
                continue;
            }
            foreach ($allowed as $value) {
                $settings->save($this->member, [$column => $value] + self::POSTED);
                self::assertSame(array_replace(self::DEFAULTS, [$column => $value]), $settings->get($this->member), $column . ' ' . $value);
            }
        }
        $settings->save($this->member, ['reduce_motion' => '1'] + self::POSTED);
        self::assertSame(1, $settings->get($this->member)['reduce_motion']);
        $settings->save($this->member, ['reduce_motion' => ''] + self::POSTED);
        self::assertSame(0, $settings->get($this->member)['reduce_motion']);
        $settings->save($this->member, ['reduce_motion' => '1'] + self::POSTED);
        $missing = self::POSTED;
        unset($missing['reduce_motion']);
        $settings->save($this->member, $missing);
        self::assertSame(0, $settings->get($this->member)['reduce_motion'], 'A missing checkbox is off.');
        self::assertSame(self::DEFAULTS, $settings->get($this->member2), 'Only the actor\'s own settings change.');
        self::assertSame($audit, $this->auditCount(), 'Display settings are not audited.');
    }

    public function testInvalidValuesGiveEveryProblemAndChangeNothing(): void
    {
        $settings = $this->ops->settings();
        $chosen = ['theme' => 'dark', 'accent' => 'plum', 'typeface' => 'lexend', 'text_size' => 'large', 'line_spacing' => 'roomy', 'reduce_motion' => '1'];
        $settings->save($this->member, $chosen);
        $before = $settings->get($this->member);

        $problems = self::problems(fn () => $settings->save($this->member, [
            'theme' => 'neon', 'typeface' => ['inter'], 'text_size' => 'LARGE', 'line_spacing' => 'roomy', 'reduce_motion' => 'yes',
        ]));
        self::assertSame([
            ['theme', 'problem.options.invalid', []],
            ['accent', 'problem.options.invalid', []],
            ['typeface', 'problem.options.invalid', []],
            ['text_size', 'problem.options.invalid', []],
            ['reduce_motion', 'problem.options.invalid', []],
        ], $problems);
        self::assertSame($before, $settings->get($this->member));
        foreach (['0', 1, 'on', 'true'] as $motion) {
            self::assertSame([['reduce_motion', 'problem.options.invalid', []]], self::problems(fn () => $settings->save($this->member, ['reduce_motion' => $motion] + self::POSTED)));
        }

        $settings->reset($this->member);
        self::assertSame(self::DEFAULTS, $settings->get($this->member));
    }

    public function testADeactivatedActorIsRefused(): void
    {
        $settings = $this->ops->settings();
        $person = $this->fx->person('member');
        $this->fx->system->db()->write(fn () => $this->fx->users->setActive($person->id, false));

        $this->assertThrows(AccessDenied::class, fn () => $settings->save($this->member, self::POSTED));
        $this->assertThrows(AccessDenied::class, fn () => $settings->reset($this->member));
    }

    public function testSaveSettingsRefusesToRunOutsideATransactionOrWithBadValues(): void
    {
        $id = $this->fx->person('member')->id;
        $this->assertThrows(\LogicException::class, fn () => $this->fx->users->saveSettings($id, self::DEFAULTS));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->fx->system->db()->write(fn () => $this->fx->users->saveSettings($id, ['theme' => 'neon'] + self::DEFAULTS)));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->fx->system->db()->write(fn () => $this->fx->users->saveSettings($id, ['reduce_motion' => '1'] + self::DEFAULTS)));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->fx->system->db()->write(fn () => $this->fx->users->saveSettings($id, ['extra' => 'x'] + self::DEFAULTS)));
        $partial = self::DEFAULTS;
        unset($partial['accent']);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->fx->system->db()->write(fn () => $this->fx->users->saveSettings($id, $partial)));

        $this->fx->system->db()->run('DELETE FROM user_settings WHERE user_id = ?', [$id]);
        $this->fx->system->db()->write(fn () => $this->fx->users->saveSettings($id, ['theme' => 'hc-dark'] + self::DEFAULTS));
        self::assertSame(array_replace(self::DEFAULTS, ['theme' => 'hc-dark']), $this->fx->users->settings($id), 'A missing row is created.');
    }
}
