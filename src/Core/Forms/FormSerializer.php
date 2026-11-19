<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * Writes the canonical text of a form (M2 spec section 5.3): the root on
 * its own line, each block on its own line indented two spaces per level,
 * inline content on one line as given, attributes in a fixed order, field
 * slots with an end tag, `hr`, `br`, and `img` self-closed, all text
 * escaped for XML, and a final newline.
 *
 * parse(serialize(d)) equals d, and serialize(parse(t)) is a fixed point
 * after one round (T-FORM-3).
 */
final class FormSerializer
{
    private const INDENT = ['', '  ', '    '];

    public function serialize(FormDocument $document): string
    {
        $out = '<div data-tiffin-form="1">' . "\n";
        foreach ($document->blocks as $block) {
            $out = $out . $this->block($block, 1);
        }

        return $out . '</div>' . "\n";
    }

    private function block(Block $block, int $level): string
    {
        $indent = self::INDENT[$level];
        if ($block instanceof Section) {
            $out = $indent . '<fieldset' . self::attribute('data-tiffin-section', $block->role) . self::placement($block->placement) . '>' . "\n";
            if ($block->legend !== null) {
                $out = $out . self::INDENT[$level + 1] . '<legend>' . self::escape($block->legend) . '</legend>' . "\n";
            }
            foreach ($block->children as $child) {
                $out = $out . $this->block($child, $level + 1);
            }

            return $out . $indent . '</fieldset>' . "\n";
        }
        $line = match (true) {
            $block instanceof FieldSlot => '<div' . self::attribute('data-tiffin-field', $block->fieldKey)
                . self::attribute('data-tiffin-label', $block->label) . self::placement($block->placement) . '></div>',
            $block instanceof Heading => self::wrap($block->level === 2 ? 'h2' : 'h3', self::placement($block->placement), $this->inlines($block->children)),
            $block instanceof Paragraph => self::wrap('p', self::placement($block->placement), $this->inlines($block->children)),
            $block instanceof Divider => '<hr' . self::placement($block->placement) . '/>',
            $block instanceof Image => '<img' . self::placement($block->placement)
                . self::attribute('src', 'attachment:' . (string) $block->attachmentId) . self::attribute('alt', $block->alt) . '/>',
            default => throw new \InvalidArgumentException('Unknown block.'),
        };

        return $indent . $line . "\n";
    }

    /** @param list<Inline> $inlines */
    private function inlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $inline) {
            $out = $out . match (true) {
                $inline instanceof TextRun => self::escape($inline->text),
                $inline instanceof Strong => self::wrap('strong', '', $this->inlines($inline->children)),
                $inline instanceof Emphasis => self::wrap('em', '', $this->inlines($inline->children)),
                $inline instanceof LineBreak => '<br/>',
                $inline instanceof Link => self::wrap('a', self::attribute('href', $inline->href), $this->inlines($inline->children)),
                default => throw new \InvalidArgumentException('Unknown inline node.'),
            };
        }

        return $out;
    }

    private static function wrap(string $element, string $attributes, string $content): string
    {
        return '<' . $element . $attributes . '>' . $content . '</' . $element . '>';
    }

    /** Placement attributes in the canonical order: column, row, span. */
    private static function placement(?Placement $placement): string
    {
        if ($placement === null) {
            return '';
        }

        return self::attribute('data-tiffin-col', $placement->col === null ? null : (string) $placement->col)
            . self::attribute('data-tiffin-row', $placement->row === null ? null : (string) $placement->row)
            . self::attribute('data-tiffin-span', $placement->span);
    }

    private static function attribute(string $name, ?string $value): string
    {
        return $value === null ? '' : ' ' . $name . '="' . self::escape($value) . '"';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
