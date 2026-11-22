<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A form text that breaks a rule of form format 1 (M5 spec section 4.1).
 *
 * The reason code is for logs and the message key; the exception message is
 * a constant. Besides the reason, a rejection carries the line of the
 * problem (section 4.2) and, where the reason concerns one, the name of the
 * element and attribute. A name is kept only when it looks like an XML name
 * of at most 40 characters; anything else becomes null. These names are the
 * only pieces of a refused form that may reach a page (always escaped), and
 * they never reach a log.
 *
 * The line is the property `formLine`, not `line` as the spec writes it:
 * \Exception already declares `protected int $line` (the PHP source line),
 * which a nullable readonly property cannot redeclare. params() gives it
 * under the key `line`.
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
    public const HREF = 'href';
    public const SRC = 'src';
    public const TEXT = 'text';
    public const TEXT_VALUE = 'text-value';
    public const CONTENT = 'content';
    public const DUPLICATE_FIELD = 'duplicate-field';
    public const TOO_MANY_ELEMENTS = 'too-many-elements';
    public const TOO_DEEP = 'too-deep';

    /** Every reason code, in the order of section 3.2. */
    public const REASONS = [
        self::TOO_LARGE, self::ENCODING, self::NUL, self::DECLARATION, self::PROCESSING_INSTRUCTION,
        self::MALFORMED, self::NODE_TYPE, self::ROOT, self::FORMAT_VERSION, self::ELEMENT, self::MISPLACED,
        self::ATTRIBUTE, self::ATTRIBUTE_VALUE, self::HREF, self::SRC, self::TEXT, self::TEXT_VALUE,
        self::CONTENT, self::DUPLICATE_FIELD, self::TOO_MANY_ELEMENTS, self::TOO_DEEP,
    ];

    /** A name that may be shown: XML-name-like, 1 to 40 characters. */
    public const NAME = '/^[A-Za-z_][A-Za-z0-9_.:-]{0,39}\z/';

    /** Reasons whose message names the element (and, for attribute, the attribute); a null name gives `_unnamed`. */
    private const NAMED = [self::ELEMENT, self::MISPLACED, self::ATTRIBUTE];

    public readonly ?string $element;
    public readonly ?string $attribute;

    public function __construct(
        public readonly string $reason,
        public readonly ?int $formLine = null,
        ?string $element = null,
        ?string $attribute = null,
    ) {
        parent::__construct('The form does not follow form format 1.');
        $this->element = self::shownName($element);
        $this->attribute = self::shownName($attribute);
    }

    /**
     * 'problem.form.' and the reason with '-' replaced by '_', plus
     * '_unnamed' when the reason's message needs a name that is null.
     */
    public function messageKey(): string
    {
        $key = 'problem.form.' . preg_replace('/-/', '_', $this->reason);
        if (in_array($this->reason, self::NAMED, true)
            && ($this->element === null || ($this->reason === self::ATTRIBUTE && $this->attribute === null))
        ) {
            return $key . '_unnamed';
        }

        return $key;
    }

    /**
     * The line when known, and the element and attribute names when set.
     * Never any other text of the form.
     *
     * @return array<string, int|string>
     */
    public function params(): array
    {
        $params = [];
        if ($this->formLine !== null) {
            $params['line'] = $this->formLine;
        }
        if ($this->element !== null) {
            $params['element'] = $this->element;
        }
        if ($this->attribute !== null) {
            $params['attribute'] = $this->attribute;
        }

        return $params;
    }

    private static function shownName(?string $name): ?string
    {
        return $name !== null && preg_match(self::NAME, $name) === 1 ? $name : null;
    }
}
