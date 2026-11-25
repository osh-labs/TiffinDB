<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;

/**
 * Joins a form with the table's field definitions and plain values (M2
 * spec section 5.7). Core\Forms does not read records: Core\App reads them
 * through Core\Query and passes the values in (ADR 0014 decision 5).
 *
 * Slots naming a key that is not a field of the table are dropped. Each
 * image becomes a BoundImage, servable only when BindData lists its
 * attachment id (P173); a template prints nothing for one that is not.
 *
 * Every bound node carries its block's span (`data-tiffin-span`, or null);
 * a section and the form are in column mode (`columns`) when a direct
 * bound child has a span of 1 to 12 (M5 spec section 8).
 */
final class FormBinder
{
    private const NOT_EDITABLE = ['relation', 'attachment'];

    /** @param list<FieldDef> $fields Schema::fields($tableKey) */
    public function bind(FormDocument $form, string $tableKey, array $fields, FieldTypes $types, BindData $data): BoundForm
    {
        $byKey = [];
        foreach ($fields as $field) {
            if ($field instanceof FieldDef && $field->tableKey === $tableKey) {
                $byKey[$field->key] = $field;
            }
        }
        $nodes = [];
        foreach ($form->blocks as $block) {
            $node = $this->node($block, $byKey, $types, $data, false);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return new BoundForm($nodes, self::hasColumns($nodes));
    }

    /** @param array<string, FieldDef> $byKey */
    private function node(Block $block, array $byKey, FieldTypes $types, BindData $data, bool $labelHidden): ?BoundNode
    {
        $span = $block->placement()?->span;
        $spanFull = $span === 'full';
        if ($block instanceof Section) {
            $lone = $block->role === 'links' && count($block->children) === 1;
            $children = [];
            foreach ($block->children as $child) {
                $node = $this->node($child, $byKey, $types, $data, $lone);
                if ($node !== null) {
                    $children[] = $node;
                }
            }

            return new BoundSection($block->role, $block->legend, $children, $block->role === 'details', $spanFull, self::hasColumns($children), $span);
        }
        if ($block instanceof FieldSlot) {
            $field = $byKey[$block->fieldKey] ?? null;

            return $field === null ? null : self::field($block, $field, $types, $data, $span, $labelHidden);
        }

        return match (true) {
            $block instanceof Heading => new BoundHeading($block->level, $block->children, $span),
            $block instanceof Paragraph => new BoundParagraph($block->children, $span),
            $block instanceof Divider => new BoundDivider($span),
            $block instanceof Image => new BoundImage(
                $block->attachmentId,
                $block->alt,
                in_array($block->attachmentId, $data->servableImages, true),
                $block->placement,
                $span,
            ),
            default => null,
        };
    }

    /**
     * True when at least one bound node has a numeric span (1 to 12): its
     * container is laid out in twelve columns (M5 spec section 8). Dropped
     * slots are not bound nodes, so they never count.
     *
     * @param list<BoundNode> $nodes
     */
    private static function hasColumns(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $span = $node->span();
            if ($span !== null && $span !== 'full') {
                return true;
            }
        }

        return false;
    }

    private static function field(FieldSlot $slot, FieldDef $field, FieldTypes $types, BindData $data, ?string $span, bool $labelHidden): BoundField
    {
        $type = $types->get($field->type);
        $value = $data->values[$field->key] ?? null;
        $submitted = array_key_exists($field->key, $data->submitted) && is_string($data->submitted[$field->key]);
        if ($submitted) {
            $inputText = $data->submitted[$field->key];
        } elseif ($value === null) {
            $inputText = '';
        } else {
            $inputText = $type->toText($value, $field->options);
        }
        $isRelation = $field->type === 'relation';
        $isAttachment = $field->type === 'attachment';

        return new BoundField(
            $field,
            $slot->label ?? $field->name,
            $inputText,
            $submitted,
            $value,
            array_values($data->problems[$field->key] ?? []),
            $type->isEditable() && !in_array($field->type, self::NOT_EDITABLE, true),
            $isRelation ? array_values($data->links[$field->key] ?? []) : [],
            $isAttachment ? array_values($data->files[$field->key] ?? []) : [],
            $span === 'full',
            $labelHidden,
            $data->isNew,
            $span,
        );
    }
}
