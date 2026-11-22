<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldDef;

/**
 * The simple form editor's model (M5 spec section 6, ADR 0020): rows in
 * reading order, posted labels, legends, and widths, and the editor's
 * actions. Pure functions over FormDocument; no database and no clock.
 *
 * Every action keeps every block it does not name as the same object, in
 * the same relative order; only the sections that hold a changed child are
 * new objects (with the same role, legend, and placement).
 */
final class FormEditor
{
    /** Width tokens and the spans they stand for (null: no span). */
    public const WIDTHS = ['auto' => null, 'full' => 'full', 'two-thirds' => '8', 'half' => '6', 'one-third' => '4'];

    public const OTHER_WIDTH = 'other';
    public const NEW_SECTION = 'New section';
    public const SUMMARY_LENGTH = 80;

    /**
     * The rows of $document in reading order: each root block, and after
     * each section its children.
     *
     * @param list<FieldDef> $fields the fields of the form's table
     * @return list<EditorRow>
     */
    public function rows(FormDocument $document, array $fields): array
    {
        $byKey = self::byKey($fields);
        $rows = [];
        foreach ($document->blocks as $i => $block) {
            $rows[] = $this->row($document, new EditorPath($i + 1), $block, $byKey);
            if ($block instanceof Section) {
                foreach ($block->children as $j => $child) {
                    $rows[] = $this->row($document, new EditorPath($i + 1, $j + 1), $child, $byKey);
                }
            }
        }

        return $rows;
    }

    /**
     * Applies the posted inputs (M5 spec section 6.2), keyed by paths of
     * $document. Entries for paths that name no block, or a block of
     * another kind, and values that are not strings are ignored.
     *
     * @param list<FieldDef> $fields
     * @param array<mixed> $labels
     * @param array<mixed> $legends
     * @param array<mixed> $widths
     */
    public function apply(FormDocument $document, array $fields, array $labels, array $legends, array $widths): EditorApplied
    {
        $byKey = self::byKey($fields);
        $problems = [];
        $typed = [];
        $blocks = [];
        $changed = false;
        foreach ($document->blocks as $i => $block) {
            $path = (string) ($i + 1);
            if ($block instanceof Section) {
                $new = $block;
                $value = self::posted($legends, $path);
                if ($value !== null) {
                    $legend = Text::trim($value);
                    if ($legend === '') {
                        $new = $new->withLegend(null);
                    } elseif (FormRules::isPlainText($legend, 1, FormFormat::MAX_LEGEND)) {
                        $new = $new->withLegend($legend);
                    } else {
                        $problems[] = new Problem('legend-' . $path, 'problem.form.legend', ['max' => FormFormat::MAX_LEGEND]);
                        $typed['legend-' . $path] = self::typed($legend);
                    }
                }
                $children = [];
                $childChanged = false;
                foreach ($block->children as $j => $child) {
                    [$applied, $slotProblems, $slotTyped] = $this->applySlot($child, $path . '-' . ($j + 1), $byKey, $labels, $widths);
                    $problems = [...$problems, ...$slotProblems];
                    $typed = [...$typed, ...$slotTyped];
                    $childChanged = $childChanged || $applied !== $child;
                    $children[] = $applied;
                }
                if ($childChanged) {
                    $new = $new->withChildren($children);
                }
            } else {
                [$new, $slotProblems, $slotTyped] = $this->applySlot($block, $path, $byKey, $labels, $widths);
                $problems = [...$problems, ...$slotProblems];
                $typed = [...$typed, ...$slotTyped];
            }
            $changed = $changed || $new !== $block;
            $blocks[] = $new;
        }

        return new EditorApplied($changed ? new FormDocument($blocks) : $document, $problems, $typed);
    }

    /**
     * The document after $action (M5 spec section 6.3).
     *
     * @param list<FieldDef> $fields
     * @throws \DomainException when the action does not fit the document
     */
    public function act(FormDocument $document, EditorAction $action, array $fields): FormDocument
    {
        return $this->perform($document, $action, $fields)[0];
    }

    /**
     * The row and control that take the focus on the page returned after
     * $action turned $before into $after (M5 spec section 6.3), or null.
     *
     * @return array{path: ?string, control: string}|null
     */
    public function focusAfter(FormDocument $before, EditorAction $action, FormDocument $after): ?array
    {
        switch ($action->verb) {
            case EditorAction::UP:
            case EditorAction::DOWN:
                assert($action->path !== null);
                $direction = $action->verb === EditorAction::UP ? -1 : 1;
                try {
                    [, $moved] = $this->move($before, $action->path, $direction);
                } catch (\DomainException) {
                    return null;
                }
                $other = $direction < 0 ? EditorAction::DOWN : EditorAction::UP;

                return ['path' => $moved->value(), 'control' => self::canMove($after, $moved, $direction) ? $action->verb : $other];
            case EditorAction::HIDE:
            case EditorAction::REMOVE:
                assert($action->path !== null);

                return self::focusAtPlace($before, $action->path, $after);
            case EditorAction::UNGROUP:
                assert($action->path !== null);
                $section = $action->path->in($before);
                if ($section instanceof Section && $section->children !== []) {
                    return ['path' => $action->path->value(), 'control' => 'first'];
                }

                return self::focusAtPlace($before, $action->path, $after);
            case EditorAction::ADD:
                $paths = self::paths($after);
                foreach ($paths as [$path, $block]) {
                    if ($block instanceof FieldSlot && $block->fieldKey === $action->fieldKey) {
                        return ['path' => $path->value(), 'control' => count($paths) === 1 ? 'first' : EditorAction::UP];
                    }
                }

                return null;
            case EditorAction::ADD_SECTION:
                return $after->blocks === [] ? null : ['path' => (string) count($after->blocks), 'control' => 'legend'];
            default:
                return null;
        }
    }

    // ------------------------------------------------------------------ rows

    /** @param array<string, FieldDef> $byKey */
    private function row(FormDocument $document, EditorPath $path, Block $block, array $byKey): EditorRow
    {
        $field = $block instanceof FieldSlot ? ($byKey[$block->fieldKey] ?? null) : null;
        $kind = match (true) {
            $block instanceof FieldSlot => $field === null ? 'missing' : 'field',
            default => $block->kind(),
        };
        $span = $block->placement()?->span;
        $width = self::widthOf($span);
        $summary = match (true) {
            $block instanceof Heading, $block instanceof Paragraph => self::summary($block->children),
            $block instanceof Image => $block->alt,
            default => '',
        };

        return new EditorRow(
            $path->value(),
            $kind,
            $path->child === null ? 0 : 1,
            $field,
            $block instanceof FieldSlot ? $block->fieldKey : null,
            $block instanceof FieldSlot && $field !== null ? $block->label : null,
            $block instanceof Section ? $block->legend : null,
            $width,
            $width === self::OTHER_WIDTH ? $span : null,
            $block->placement()?->isPlaced() ?? false,
            $summary,
            $block instanceof Image ? $block->attachmentId : null,
            $block instanceof Section ? count($block->children) : 0,
            self::canMove($document, $path, -1),
            self::canMove($document, $path, 1),
        );
    }

    /** The width token of a span: a token of WIDTHS, or 'other'. */
    public static function widthOf(?string $span): string
    {
        $token = array_search($span, self::WIDTHS, true);

        return is_string($token) ? $token : self::OTHER_WIDTH;
    }

    /**
     * Plain text of inline content: text runs joined, a line break as a
     * space, white space collapsed to single spaces, cut to 80 characters
     * (the last one an ellipsis) when longer.
     *
     * @param list<Inline> $inlines
     */
    private static function summary(array $inlines): string
    {
        $text = Text::trim((string) preg_replace('/\s+/u', ' ', self::plainText($inlines)));
        if (Text::length($text) <= self::SUMMARY_LENGTH) {
            return $text;
        }
        preg_match('/^.{' . (self::SUMMARY_LENGTH - 1) . '}/su', $text, $match);

        return Text::trim($match[0]) . "\u{2026}";
    }

    /** @param list<Inline> $inlines */
    private static function plainText(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            $text = $text . match (true) {
                $inline instanceof TextRun => $inline->text,
                $inline instanceof LineBreak => ' ',
                $inline instanceof Strong, $inline instanceof Emphasis, $inline instanceof Link => self::plainText($inline->children),
                default => '',
            };
        }

        return $text;
    }

    /**
     * True when moving the block at $path one place in $direction (-1 up,
     * 1 down) would change the document and keep its rules.
     */
    private static function canMove(FormDocument $document, EditorPath $path, int $direction): bool
    {
        $block = $path->in($document);
        if ($block === null) {
            return false;
        }
        if ($path->child !== null) {
            return true;
        }
        $neighbour = $document->blocks[$path->root - 1 + $direction] ?? null;
        if ($neighbour === null) {
            return false;
        }

        return $block instanceof Section || !$neighbour instanceof Section || self::fitsInSection($block);
    }

    /** True when $block stays within the depth limit inside a section. */
    private static function fitsInSection(Block $block): bool
    {
        if ($block instanceof Heading || $block instanceof Paragraph) {
            return 3 + self::inlineDepth($block->children) <= FormFormat::MAX_DEPTH;
        }

        return true;
    }

    /** @param list<Inline> $inlines */
    private static function inlineDepth(array $inlines): int
    {
        $depth = 0;
        foreach ($inlines as $inline) {
            if ($inline instanceof TextRun) {
                continue;
            }
            $below = $inline instanceof Strong || $inline instanceof Emphasis || $inline instanceof Link
                ? self::inlineDepth($inline->children)
                : 0;
            $depth = max($depth, 1 + $below);
        }

        return $depth;
    }

    // ------------------------------------------------------------------ inputs

    /**
     * @param array<string, FieldDef> $byKey
     * @param array<mixed> $labels
     * @param array<mixed> $widths
     * @return array{0: Block, 1: list<Problem>, 2: array<string, string>} the block, its problems, and the refused texts
     */
    private function applySlot(Block $block, string $path, array $byKey, array $labels, array $widths): array
    {
        $problems = [];
        $typed = [];
        if (!$block instanceof FieldSlot || !isset($byKey[$block->fieldKey])) {
            return [$block, $problems, $typed];
        }
        $field = $byKey[$block->fieldKey];
        $slot = $block;
        $value = self::posted($labels, $path);
        if ($value !== null) {
            $label = Text::trim($value);
            if ($label === '' || $label === $field->name) {
                $slot = $slot->withLabel(null);
            } elseif (FormRules::isPlainText($label, 1, FormFormat::MAX_LABEL)) {
                $slot = $slot->withLabel($label);
            } else {
                $problems[] = new Problem('label-' . $path, 'problem.form.label', ['max' => FormFormat::MAX_LABEL]);
                $typed['label-' . $path] = self::typed($label);
            }
        }
        $token = self::posted($widths, $path);
        if ($token !== null && array_key_exists($token, self::WIDTHS)) {
            $slot = $slot->withSpan(self::WIDTHS[$token]);
        }

        return [$slot, $problems, $typed];
    }

    /** The posted string for $path, or null when there is none or it is not a string. @param array<mixed> $values */
    private static function posted(array $values, string $path): ?string
    {
        $value = $values[$path] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Refused text as the page may show it again: invalid UTF-8 replaced. */
    private static function typed(string $text): string
    {
        return Text::isUtf8($text) ? $text : mb_scrub($text, 'UTF-8');
    }

    // ------------------------------------------------------------------ actions

    /**
     * @param list<FieldDef> $fields
     * @return array{0: FormDocument, 1: ?EditorPath}
     */
    private function perform(FormDocument $document, EditorAction $action, array $fields): array
    {
        switch ($action->verb) {
            case EditorAction::APPLY:
            case EditorAction::SAVE:
                return [$document, null];
            case EditorAction::UP:
            case EditorAction::DOWN:
                assert($action->path !== null);

                return $this->move($document, $action->path, $action->verb === EditorAction::UP ? -1 : 1);
            case EditorAction::HIDE:
            case EditorAction::REMOVE:
                assert($action->path !== null);
                $block = $action->path->in($document);
                $isField = $block instanceof FieldSlot && isset(self::byKey($fields)[$block->fieldKey]);
                $fits = $action->verb === EditorAction::HIDE
                    ? $isField
                    : $block !== null && !$block instanceof Section && !$isField;
                if (!$fits) {
                    throw new \DomainException('The action does not fit the row.');
                }

                return [self::without($document, $action->path), null];
            case EditorAction::UNGROUP:
                assert($action->path !== null);
                $section = $action->path->in($document);
                if (!$section instanceof Section) {
                    throw new \DomainException('Only a section can be ungrouped.');
                }
                $blocks = $document->blocks;
                array_splice($blocks, $action->path->root - 1, 1, $section->children);

                return [self::document($blocks), $section->children === [] ? null : new EditorPath($action->path->root)];
            case EditorAction::ADD:
                assert($action->fieldKey !== null);
                if (!isset(self::byKey($fields)[$action->fieldKey]) || in_array($action->fieldKey, $document->fieldKeys(), true)) {
                    throw new \DomainException('The field is not one the form can gain.');
                }

                return self::append($document, new FieldSlot($action->fieldKey));
            case EditorAction::ADD_SECTION:
                $blocks = [...$document->blocks, new Section(null, self::NEW_SECTION, [])];

                return [self::document($blocks), new EditorPath(count($blocks))];
            default:
                throw new \DomainException('Unknown action.');
        }
    }

    /**
     * Moves the block at $path one place up (-1) or down (1). Returns the
     * new document and the block's new path.
     *
     * @return array{0: FormDocument, 1: EditorPath}
     */
    private function move(FormDocument $document, EditorPath $path, int $direction): array
    {
        $block = $path->in($document);
        if ($block === null) {
            throw new \DomainException('The path names no row.');
        }
        $blocks = $document->blocks;
        $i = $path->root - 1;
        if ($path->child === null) {
            $k = $i + $direction;
            $neighbour = $blocks[$k] ?? null;
            if ($neighbour === null) {
                throw new \DomainException('The row cannot move further.');
            }
            if (!$block instanceof Section && $neighbour instanceof Section) {
                $children = $neighbour->children;
                if ($direction < 0) {
                    $children[] = $block;
                    $child = count($children);
                } else {
                    array_unshift($children, $block);
                    $child = 1;
                }
                $blocks[$k] = $neighbour->withChildren($children);
                array_splice($blocks, $i, 1);

                return [self::document($blocks), new EditorPath(($direction < 0 ? $k : $i) + 1, $child)];
            }
            $blocks[$i] = $neighbour;
            $blocks[$k] = $block;

            return [self::document($blocks), new EditorPath($k + 1)];
        }

        $section = $blocks[$i];
        assert($section instanceof Section);
        $children = $section->children;
        $j = $path->child - 1;
        $k = $j + $direction;
        if (isset($children[$k])) {
            $children[$j] = $children[$k];
            $children[$k] = $block;
            $blocks[$i] = $section->withChildren($children);

            return [self::document($blocks), new EditorPath($path->root, $k + 1)];
        }
        array_splice($children, $j, 1);
        $blocks[$i] = $section->withChildren($children);
        $at = $direction < 0 ? $i : $i + 1;
        array_splice($blocks, $at, 0, [$block]);

        return [self::document($blocks), new EditorPath($at + 1)];
    }

    /** $document without the block at $path, which names a block. */
    private static function without(FormDocument $document, EditorPath $path): FormDocument
    {
        $blocks = $document->blocks;
        if ($path->child === null) {
            array_splice($blocks, $path->root - 1, 1);

            return self::document($blocks);
        }
        $section = $blocks[$path->root - 1];
        assert($section instanceof Section);
        $children = $section->children;
        array_splice($children, $path->child - 1, 1);
        $blocks[$path->root - 1] = $section->withChildren($children);

        return self::document($blocks);
    }

    /**
     * $document with $slot at the end of the first section, or of the root
     * when there is none.
     *
     * @return array{0: FormDocument, 1: EditorPath}
     */
    private static function append(FormDocument $document, FieldSlot $slot): array
    {
        $blocks = $document->blocks;
        foreach ($blocks as $i => $block) {
            if ($block instanceof Section) {
                $blocks[$i] = $block->withChildren([...$block->children, $slot]);

                return [self::document($blocks), new EditorPath($i + 1, count($block->children) + 1)];
            }
        }
        $blocks[] = $slot;

        return [self::document($blocks), new EditorPath(count($blocks))];
    }

    /**
     * The focus after a row was removed: the row that now stands at its
     * place in reading order, or the "Add a section" button when none does.
     *
     * @return array{path: ?string, control: string}
     */
    private static function focusAtPlace(FormDocument $before, EditorPath $removed, FormDocument $after): array
    {
        $place = null;
        foreach (self::paths($before) as $n => [$path]) {
            if ($path->value() === $removed->value()) {
                $place = $n;
            }
        }
        $rows = self::paths($after);
        if ($place === null || !isset($rows[$place])) {
            return ['path' => null, 'control' => EditorAction::ADD_SECTION];
        }

        return ['path' => $rows[$place][0]->value(), 'control' => 'first'];
    }

    /** @return list<array{0: EditorPath, 1: Block}> every block with its path, in reading order */
    private static function paths(FormDocument $document): array
    {
        $paths = [];
        foreach ($document->blocks as $i => $block) {
            $paths[] = [new EditorPath($i + 1), $block];
            if ($block instanceof Section) {
                foreach ($block->children as $j => $child) {
                    $paths[] = [new EditorPath($i + 1, $j + 1), $child];
                }
            }
        }

        return $paths;
    }

    /**
     * A document of $blocks; \DomainException when it would break a rule of
     * form format 1 (element count or depth).
     *
     * @param list<Block> $blocks
     */
    private static function document(array $blocks): FormDocument
    {
        try {
            return new FormDocument(array_values($blocks));
        } catch (\InvalidArgumentException $broken) {
            throw new \DomainException('The result would break a form rule.', 0, $broken);
        }
    }

    /**
     * @param list<FieldDef> $fields
     * @return array<string, FieldDef>
     */
    private static function byKey(array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        return $byKey;
    }
}
