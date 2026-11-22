<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldDef;

/**
 * What the form editor and source view report about a form (M5 spec
 * section 6.4): slot keys that are not fields of the table, fields with no
 * slot, the required ones among them, the pictures, and links with no text.
 */
final class FormReport
{
    /**
     * @param list<string> $unknownKeys slot keys that are not fields of the table, in document order
     * @param list<FieldDef> $notOnForm fields with no slot, in field order
     * @param list<FieldDef> $requiredNotOnForm those of $notOnForm that are required
     * @param list<int> $images attachment ids of every picture, in document order
     * @param list<int> $imagesNotShown the pictures that cannot be shown (set by Core\App)
     * @param int $emptyLinks links with no text
     */
    private function __construct(
        public readonly array $unknownKeys,
        public readonly array $notOnForm,
        public readonly array $requiredNotOnForm,
        public readonly array $images,
        public readonly array $imagesNotShown,
        public readonly int $emptyLinks,
    ) {
    }

    /** @param list<FieldDef> $fields the fields of the form's table */
    public static function of(FormDocument $document, array $fields): self
    {
        $known = [];
        foreach ($fields as $field) {
            $known[$field->key] = true;
        }
        $named = [];
        $unknown = [];
        $images = [];
        $emptyLinks = 0;
        foreach ($document->blocks as $block) {
            foreach ($block instanceof Section ? $block->children : [$block] as $child) {
                if ($child instanceof FieldSlot) {
                    $named[$child->fieldKey] = true;
                    if (!isset($known[$child->fieldKey])) {
                        $unknown[] = $child->fieldKey;
                    }
                } elseif ($child instanceof Image) {
                    $images[] = $child->attachmentId;
                } elseif ($child instanceof Heading || $child instanceof Paragraph) {
                    $emptyLinks += self::emptyLinks($child->children);
                }
            }
        }
        $notOnForm = [];
        $required = [];
        foreach ($fields as $field) {
            if (!isset($named[$field->key])) {
                $notOnForm[] = $field;
                if ($field->required) {
                    $required[] = $field;
                }
            }
        }

        return new self($unknown, $notOnForm, $required, $images, [], $emptyLinks);
    }

    /**
     * This report with the pictures that cannot be shown (M5 spec section
     * 9.3): the ids of $images that are in $ids, in document order, once each.
     *
     * @param list<int> $ids
     */
    public function withImagesNotShown(array $ids): self
    {
        $hidden = array_values(array_unique(array_filter($this->images, static fn (int $id): bool => in_array($id, $ids, true))));

        return new self($this->unknownKeys, $this->notOnForm, $this->requiredNotOnForm, $this->images, $hidden, $this->emptyLinks);
    }

    /** @param list<Inline> $inlines */
    private static function emptyLinks(array $inlines): int
    {
        $count = 0;
        foreach ($inlines as $inline) {
            if ($inline instanceof Link) {
                $count += self::hasText($inline->children) ? 0 : 1;
            } elseif ($inline instanceof Strong || $inline instanceof Emphasis) {
                $count += self::emptyLinks($inline->children);
            }
        }

        return $count;
    }

    /** True when a text run in or below $inlines has a character other than white space. @param list<Inline> $inlines */
    private static function hasText(array $inlines): bool
    {
        foreach ($inlines as $inline) {
            if ($inline instanceof TextRun && Text::trim($inline->text) !== '') {
                return true;
            }
            if (($inline instanceof Strong || $inline instanceof Emphasis || $inline instanceof Link) && self::hasText($inline->children)) {
                return true;
            }
        }

        return false;
    }
}
