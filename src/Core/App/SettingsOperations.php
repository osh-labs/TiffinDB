<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;

/**
 * A person's own display settings (M2 spec section 7.3). Any signed-in
 * person may read and change their own; nobody else's. Not audited: a
 * display preference is not a security event.
 */
final class SettingsOperations
{
    /** The columns chosen from a list; reduce_motion is handled apart. */
    private const LISTED = ['theme', 'accent', 'typeface', 'text_size', 'line_spacing'];

    public function __construct(private readonly Context $c)
    {
    }

    /** @return array<string, string|int> the actor's user_settings, column => value */
    public function get(Actor $actor): array
    {
        return $this->c->users->settings($actor->userId);
    }

    /**
     * Validates every value (all problems together, ValidationFailed), then
     * saves the six columns in one system-database write. The acting person
     * must still exist and be active.
     *
     * @param array<mixed> $values column => text as a form posts it
     */
    public function save(Actor $actor, array $values): void
    {
        $this->c->system->db()->write(function () use ($actor, $values): void {
            $person = $this->c->requireCurrentPerson($actor);
            $settings = [];
            $problems = [];
            foreach (self::LISTED as $column) {
                $value = $values[$column] ?? null;
                if (is_string($value) && in_array($value, Users::SETTINGS[$column], true)) {
                    $settings[$column] = $value;
                } else {
                    $problems[] = new Problem($column, 'problem.options.invalid');
                }
            }
            $motion = $values['reduce_motion'] ?? '';
            if ($motion === '1') {
                $settings['reduce_motion'] = 1;
            } elseif ($motion === '') {
                $settings['reduce_motion'] = 0;
            } else {
                $problems[] = new Problem('reduce_motion', 'problem.options.invalid');
            }
            if ($problems !== []) {
                throw new ValidationFailed($problems);
            }
            $this->c->users->saveSettings($person->id, $settings);
        });
    }

    /** Saves the defaults (the first allowed value of each column). */
    public function reset(Actor $actor): void
    {
        $defaults = [];
        foreach (self::LISTED as $column) {
            $defaults[$column] = (string) Users::SETTINGS[$column][0];
        }
        $defaults['reduce_motion'] = '';
        $this->save($actor, $defaults);
    }
}
