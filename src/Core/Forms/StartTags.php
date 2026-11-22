<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * Byte offsets of the start tags of a form text, and line numbers (M5 spec
 * section 4.2). XMLReader gives no position for a node, so the parser
 * counts elements and looks the n-th start tag up here.
 *
 * The scan is lexical: every `<` that is not the start of an end tag
 * (`</`) or of a comment (`<!--` to the next `-->`) starts a start tag. It
 * is exact while the text is well-formed up to that element, because
 * well-formed text holds no other literal `<` (not in text, not in an
 * attribute value), and the parser has already refused declarations and
 * processing instructions. An unterminated comment ends the scan.
 *
 * @internal Helper of FormParser.
 */
final class StartTags
{
    private function __construct()
    {
    }

    /** @return list<int> the offset of each start tag's `<`, in document order */
    public static function offsets(string $text): array
    {
        $offsets = [];
        $length = strlen($text);
        $at = 0;
        while ($at < $length) {
            $lt = strpos($text, '<', $at);
            if ($lt === false) {
                break;
            }
            if (substr_compare($text, '<!--', $lt, 4) === 0) {
                $end = strpos($text, '-->', $lt + 4);
                if ($end === false) {
                    break;
                }
                $at = $end + 3;

                continue;
            }
            if (($text[$lt + 1] ?? '') !== '/') {
                $offsets[] = $lt;
            }
            $at = $lt + 1;
        }

        return $offsets;
    }

    /**
     * 1 plus the number of line breaks before $offset, where a line break is
     * a line feed, a carriage return not followed by a line feed, or the
     * two together (counted once).
     */
    public static function lineAt(string $text, int $offset): int
    {
        $before = substr($text, 0, max(0, $offset));
        $crlf = substr_count($before, "\r\n");

        return 1 + substr_count($before, "\n") + substr_count($before, "\r") - $crlf;
    }
}
