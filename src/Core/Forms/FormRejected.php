<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A stored form that breaks a rule of form format 1. The reason code is
 * for logs; it never carries the form's content.
 */
final class FormRejected extends \RuntimeException
{
    public const TOO_LARGE = 'too-large';
    public const ENCODING = 'encoding';
    public const NUL = 'nul';
    public const DECLARATION = 'declaration';
    public const PROCESSING_INSTRUCTION = 'processing-instruction';
    public const MALFORMED = 'malformed';
    public const NODE_TYPE = 'node-type';
    public const ROOT = 'root';
    public const FORMAT_VERSION = 'format-version';
    public const ELEMENT = 'element';
    public const MISPLACED = 'misplaced';
    public const ATTRIBUTE = 'attribute';
    public const ATTRIBUTE_VALUE = 'attribute-value';
    public const TEXT = 'text';
    public const TEXT_VALUE = 'text-value';
    public const CONTENT = 'content';
    public const DUPLICATE_FIELD = 'duplicate-field';
    public const TOO_MANY_ELEMENTS = 'too-many-elements';
    public const TOO_DEEP = 'too-deep';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The form does not follow form format 1.');
    }
}
