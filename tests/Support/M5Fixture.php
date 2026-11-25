<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\App\Operations;

/**
 * The M5 synthetic data set (M5 spec part 1 section 11, part 2 section
 * 10.2): the M4 data set (M4Fixture, "Home Inventory") with an edited
 * Items form, built through the core operations as the Admin.
 *
 * Before the form is saved, Items gets a text field "Model" (no values),
 * and "Serial number" is made required (its options, help, and values are
 * kept). The form (FORM_TEXT, saved through the source view, so the
 * stored text is its canonical text) holds:
 *
 * - a "Details" section in column mode: Name two thirds, Category one
 *   third, Purchased half, Value half, Condition, Under warranty, and
 *   Manual one third each, Notes (long text) full width, Model automatic;
 *   then a heading and a paragraph with a link to LINK;
 * - a section "Where it is" without widths, holding Room;
 * - "Files" (Receipt, Photo) and "Warranties" (Warranties), as generated;
 * - no slot for "Serial number" (the hidden required field).
 *
 * All names, values, and addresses are synthetic.
 */
final class M5Fixture
{
    public const LINK = 'https://example.com/manual';
    public const LINK_TEXT = 'the manual';
    public const HEADING = 'Care and use';
    public const SECTION = 'Where it is';
    public const HIDDEN = 'Serial number';
    public const ADDED = 'Model';

    /** Details fields in form order => span (null: automatic). */
    public const WIDTHS = [
        'Name' => '8',
        'Category' => '4',
        'Purchased' => '6',
        'Value' => '6',
        'Condition' => '4',
        'Under warranty' => '4',
        'Manual' => '4',
        'Notes' => 'full',
        'Model' => null,
    ];

    /**
     * @param array<string, string> $itemFields Items field name => key, Model included.
     */
    private function __construct(
        public readonly M4Fixture $m4,
        public readonly M2Fixture $m2,
        public readonly array $itemFields,
        public readonly string $source,
        public readonly string $html,
    ) {
    }

    /** Builds the data set in $core with $ops (default: new operations of $core). */
    public static function build(CoreFixture $core, ?Operations $ops = null): self
    {
        $ops ??= $core->operations();
        $m4 = M4Fixture::build($core, $ops);
        $m2 = $m4->m2;
        $admin = $core->actor('admin');
        $p = $m2->project;
        $schema = $ops->schema();

        $fields = $m2->itemFields;
        $fields[self::ADDED] = $schema->addField($admin, $p, $m2->items, self::ADDED, 'text', [], false, '')->key;
        $serial = $schema->field($admin, $p, $m2->items, $fields[self::HIDDEN])->field;
        $schema->updateField($admin, $p, $m2->items, $serial->key, $serial->name, $serial->options, true, $serial->help, 0);

        $source = self::formText($fields);
        $forms = $ops->forms();
        $base = $forms->source($admin, $p, $m2->items)->base;
        $saved = $forms->source($admin, $p, $m2->items, ['text' => $source, 'base' => $base, 'do' => 'save']);
        if ($saved->problems !== [] || $saved->saved === null) {
            throw new \LogicException('The M5 fixture form is accepted.');
        }

        return new self($m4, $m2, $fields, $source, $forms->download($admin, $p, $m2->items)->text);
    }

    /**
     * The fixture form as written by hand (one element per line).
     *
     * @param array<string, string> $f Items field name => key.
     */
    public static function formText(array $f): string
    {
        $slot = static function (string $name, ?string $span) use ($f): string {
            return '<div data-tiffin-field="' . $f[$name] . '"' . ($span === null ? '' : ' data-tiffin-span="' . $span . '"') . '></div>' . "\n";
        };
        $details = '';
        foreach (self::WIDTHS as $name => $span) {
            $details .= $slot($name, $span);
        }

        return '<div data-tiffin-form="1">' . "\n"
            . '<fieldset data-tiffin-section="details"><legend>Details</legend>' . "\n"
            . $details
            . '<h3>' . self::HEADING . '</h3>' . "\n"
            . '<p>See <a href="' . self::LINK . '">' . self::LINK_TEXT . '</a> before cleaning.</p>' . "\n"
            . '</fieldset>' . "\n"
            . '<fieldset><legend>' . self::SECTION . '</legend>' . "\n"
            . $slot('Room', null)
            . '</fieldset>' . "\n"
            . '<fieldset data-tiffin-section="files"><legend>Files</legend>' . "\n"
            . $slot('Receipt', null)
            . $slot('Photo', null)
            . '</fieldset>' . "\n"
            . '<fieldset data-tiffin-section="links"><legend>Warranties</legend>' . "\n"
            . $slot('Warranties', null)
            . '</fieldset>' . "\n"
            . '</div>' . "\n";
    }

    /** The URL path of the Items form editor. */
    public function editorPath(): string
    {
        return $this->m2->tablePath($this->m2->items) . '/form';
    }
}
