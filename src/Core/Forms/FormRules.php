<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\Type\UrlType;

/**
 * The value rules of form format 1 (`docs/formats/tiffin.md` section
 * 12.1), shared by the node constructors and FormParser so that the parser
 * accepts exactly the documents that can be built in code.
 *
 * @internal Helper of Core\Forms.
 */
final class FormRules
{
    public const ROLE = '/^[a-z][a-z0-9-]{0,31}\z/';
    public const COLUMN = '/^(?:[1-9]|1[0-2])\z/';
    public const ROW = '/^(?:[1-9]|[1-4][0-9]|50)\z/';

    /**
     * Characters Text::hasControl lets through that a form may not hold:
     * every Unicode noncharacter (U+FDD0 to U+FDEF and the last two code
     * points of each plane; M5 spec section 3.2). XML 1.0 itself forbids
     * U+FFFE and U+FFFF; the others are refused because the page escaper
     * turns them into U+FFFD, so a form holding one would not survive the
     * source view or the editor's draft byte for byte.
     */
    private const NOT_XML = '/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}'
        . '\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}'
        . '\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}'
        . '\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u';

    private function __construct()
    {
    }

    public static function isRole(string $role): bool
    {
        return preg_match(self::ROLE, $role) === 1;
    }

    /** '1' to '12' written without sign or leading zero, or 'full'. */
    public static function isSpan(string $span): bool
    {
        return $span === 'full' || preg_match(self::COLUMN, $span) === 1;
    }

    /**
     * One line of text that is already trimmed, $min to $max code points
     * long, with no control character and only characters XML allows.
     */
    public static function isPlainText(string $text, int $min, int $max): bool
    {
        if (!Text::isUtf8($text) || Text::trim($text) !== $text || Text::hasControl($text)) {
            return false;
        }
        if (preg_match(self::NOT_XML, $text) === 1) {
            return false;
        }
        $length = Text::length($text);

        return $length >= $min && $length <= $max;
    }

    /** Text of a run: valid UTF-8, no control character except tab and line feed. */
    public static function isRunText(string $text): bool
    {
        return !Text::hasControl($text, true, true) && preg_match(self::NOT_XML, $text) !== 1;
    }

    /**
     * An address the url field type accepts by its strict rules, given
     * exactly (no white space around it), and holding no apostrophe: the url
     * type allows `'`, but a form link may not hold one (provisional
     * decision P242), so every address a form can hold is one the page's
     * form-link helper prints.
     */
    public static function isHref(string $href): bool
    {
        if ($href === '' || !Text::isUtf8($href) || Text::trim($href) !== $href || str_contains($href, "'")) {
            return false;
        }
        $checked = (new UrlType())->parse($href, [], false);

        return $checked->isOk() && $checked->value() !== null;
    }

    /**
     * Checks a list of inline nodes: a list, only Inline nodes, and no two
     * text runs next to each other.
     *
     * @param array<mixed> $children
     * @return list<Inline>
     */
    public static function inlines(array $children): array
    {
        if (!array_is_list($children)) {
            throw new \InvalidArgumentException('Inline content must be a list.');
        }
        $previousText = false;
        foreach ($children as $child) {
            if (!$child instanceof Inline) {
                throw new \InvalidArgumentException('Inline content holds only inline nodes.');
            }
            $isText = $child instanceof TextRun;
            if ($isText && $previousText) {
                throw new \InvalidArgumentException('Adjacent text runs must be one run.');
            }
            $previousText = $isText;
        }

        return $children;
    }

    /** @param list<Inline> $children */
    public static function holdsLink(array $children): bool
    {
        foreach ($children as $child) {
            if ($child instanceof Link) {
                return true;
            }
            if (($child instanceof Strong || $child instanceof Emphasis) && self::holdsLink($child->children)) {
                return true;
            }
        }

        return false;
    }
}
