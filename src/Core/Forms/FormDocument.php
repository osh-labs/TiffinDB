<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A parsed or built form: the blocks inside the root element. The
 * constructor checks the document-wide rules of form format 1: each field
 * key in at most one slot, at most 5,000 elements (the root, legends, and
 * inline elements included), and at most 8 levels of nesting.
 */
final class FormDocument
{
    /** @var list<Block> */
    public readonly array $blocks;

    /** @param list<Block> $blocks */
    public function __construct(array $blocks)
    {
        if (!array_is_list($blocks)) {
            throw new \InvalidArgumentException('The blocks must be a list.');
        }
        $keys = [];
        $elements = 1;
        $depth = 1;
        foreach ($blocks as $block) {
            if (!$block instanceof Block) {
                throw new \InvalidArgumentException('A form holds blocks.');
            }
            $children = $block instanceof Section ? $block->children : [$block];
            if ($block instanceof Section) {
                $elements += $block->legend === null ? 1 : 2;
                $depth = max($depth, $block->legend === null ? 2 : 3);
            }
            $level = $block instanceof Section ? 3 : 2;
            foreach ($children as $child) {
                if ($child instanceof FieldSlot) {
                    if (isset($keys[$child->fieldKey])) {
                        throw new \InvalidArgumentException('A field key may appear in one slot only.');
                    }
                    $keys[$child->fieldKey] = true;
                }
                $elements++;
                $depth = max($depth, $level);
                if ($child instanceof Heading || $child instanceof Paragraph) {
                    $elements += self::countInlines($child->children);
                    $depth = max($depth, $level + self::inlineDepth($child->children));
                }
            }
        }
        if ($elements > FormFormat::MAX_ELEMENTS) {
            throw new \InvalidArgumentException('A form has at most 5,000 elements.');
        }
        if ($depth > FormFormat::MAX_DEPTH) {
            throw new \InvalidArgumentException('A form has at most 8 levels.');
        }
        $this->blocks = $blocks;
    }

    /** @return list<string> the field keys of every slot, in document order */
    public function fieldKeys(): array
    {
        $keys = [];
        foreach ($this->blocks as $block) {
            foreach ($block instanceof Section ? $block->children : [$block] as $child) {
                if ($child instanceof FieldSlot) {
                    $keys[] = $child->fieldKey;
                }
            }
        }

        return $keys;
    }

    /** @param list<Inline> $inlines */
    private static function countInlines(array $inlines): int
    {
        $count = 0;
        foreach ($inlines as $inline) {
            if ($inline instanceof TextRun) {
                continue;
            }
            $count++;
            if ($inline instanceof Strong || $inline instanceof Emphasis || $inline instanceof Link) {
                $count += self::countInlines($inline->children);
            }
        }

        return $count;
    }

    /** Levels of inline elements below a block (0 when it holds only text). @param list<Inline> $inlines */
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
}
