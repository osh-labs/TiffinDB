<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Reading and display settings (docs/design/m2/settings.md; M2 spec part
 * 1 section 7.3). Every signed-in person reads and changes only their
 * own; SettingsOperations checks the values and that the person is still
 * active. Success redirects to /settings, so the next page is drawn with
 * the new root classes.
 *
 * Posted values are passed to the core as text and never printed: at 422
 * a radio stays checked only when its posted value is one of the allowed
 * values, otherwise the saved value is checked.
 */
final class SettingsController
{
    /** The posted names, which are also the core's columns and problem fields. */
    private const COLUMNS = ['theme', 'accent', 'typeface', 'text_size', 'line_spacing', 'reduce_motion'];

    /** Core problem field => fieldset id (settings.md section 2). */
    private const FIELDSETS = [
        'theme' => 'settings-theme',
        'accent' => 'settings-accent',
        'text_size' => 'settings-size',
        'line_spacing' => 'settings-spacing',
        'typeface' => 'settings-lettering',
        'reduce_motion' => 'settings-lettering',
    ];

    /** The fieldsets in page order, for the error summary. */
    private const ORDER = ['settings-theme', 'settings-accent', 'settings-size', 'settings-spacing', 'settings-lettering'];

    /** The allowed values of each listed column (M1 spec part 1 section 5.5; Users::SETTINGS). */
    private const ALLOWED = [
        'theme' => ['light', 'dark', 'hc-light', 'hc-dark'],
        'accent' => ['teal', 'indigo', 'plum', 'graphite'],
        'typeface' => ['inter', 'atkinson', 'lexend', 'source-serif', 'device-sans', 'device-serif'],
        'text_size' => ['standard', 'small', 'large', 'larger', 'largest'],
        'line_spacing' => ['standard', 'roomy', 'very-roomy'],
    ];

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /settings. */
    public function form(Request $request): Response
    {
        return $this->render($this->saved(), [], 200);
    }

    /** POST /settings. */
    public function save(Request $request): Response
    {
        $posted = [];
        foreach (self::COLUMNS as $column) {
            $posted[$column] = $request->input($column);
        }
        try {
            $this->context->services()->operations()->settings()->save($this->context->actor(), $posted);
        } catch (ValidationFailed $failed) {
            $values = $this->saved();
            foreach (self::ALLOWED as $column => $allowed) {
                if (in_array($posted[$column], $allowed, true)) {
                    $values[$column] = $posted[$column];
                }
            }
            if ($posted['reduce_motion'] === '1' || $posted['reduce_motion'] === '') {
                $values['reduce_motion'] = $posted['reduce_motion'];
            }

            return $this->render($values, $failed->problems, 422);
        }
        $this->context->flash('notice.settings_saved');

        return $this->context->redirect('/settings');
    }

    /** POST /settings/reset (the form's "Back to standard" button). */
    public function reset(Request $request): Response
    {
        $this->context->services()->operations()->settings()->reset($this->context->actor());
        $this->context->flash('notice.settings_reset');

        return $this->context->redirect('/settings');
    }

    /**
     * The person's saved settings as the template compares them: the five
     * listed columns as stored, reduce_motion as '1' or ''.
     *
     * @return array<string, string>
     */
    private function saved(): array
    {
        $stored = $this->context->services()->operations()->settings()->get($this->context->actor());
        $values = [];
        foreach (self::ALLOWED as $column => $allowed) {
            $value = $stored[$column] ?? null;
            $values[$column] = is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
        }
        $values['reduce_motion'] = ($stored['reduce_motion'] ?? 0) === 1 ? '1' : '';

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<Problem>         $problems
     */
    private function render(array $values, array $problems, int $status): Response
    {
        $format = $this->context->view()->format();
        $byFieldset = [];
        foreach ($problems as $problem) {
            $id = self::FIELDSETS[$problem->field ?? ''] ?? null;
            $byFieldset[$id ?? ''][] = $format->problem($problem);
        }
        $summary = [];
        $errors = [];
        foreach ([...self::ORDER, ''] as $id) {
            foreach ($byFieldset[$id] ?? [] as $text) {
                $summary[] = ['field' => $id === '' ? null : $id, 'text' => $text];
                if ($id !== '' && !isset($errors[$id])) {
                    $errors[$id] = $text;
                }
            }
        }

        return $this->context->page('settings', [
            'title' => $this->context->view()->text('settings.title'),
            'layout' => 'settings',
            'script' => 'settings-preview',
            'problems' => $summary,
            'errors' => $errors,
            'values' => $values,
        ], $status, 'settings');
    }
}
