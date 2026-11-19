<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * Reads the `<row>` elements of a worksheet's `sheetData` in order, one at
 * a time, either parsing the row's cells or skipping it without looking at
 * its cells (a skipped row costs the parser's time only).
 *
 * Checks: row numbers (the `r` attribute, else one more than the last) must
 * increase and stay within the format's 1,048,576 rows; cell references
 * (the `r` attribute, else the next column) must name a column within the
 * format's 16,384; a row may hold at most 16,384 cell elements. Anything
 * else is refused as unreadable.
 *
 * Cell values become text (CellText, M3 spec section 9.2): a formula's
 * cached value is taken as the value of its type and the formula itself is
 * never read or evaluated (no cached value: no text); error cells,
 * booleans, shared and inline strings (rich text joined), ISO date cells,
 * and numbers with their cell format's date, time, or duration meaning.
 * Hyperlinks, comments, merged ranges, and everything outside `sheetData`
 * are not read.
 *
 * Call every method inside Xml::run().
 *
 * @internal
 */
final class SheetCursor
{
    private \XMLReader $reader;
    /** Depth of `sheetData`'s children; -1 before it is found, -2 at the end. */
    private int $rowDepth = -1;
    /** Whether the reader is on a node not yet looked at. */
    private bool $pending = false;
    private int $lastRow = 0;

    public function __construct(private readonly Workbook $workbook, string $part)
    {
        $this->reader = Xml::open($workbook->path, $part);
    }

    public function close(): void
    {
        $this->reader->close();
        $this->rowDepth = -2;
    }

    /**
     * Moves to the next row element and returns its row number (1-based), or
     * null at the end of the sheet. Call row() or skip() before calling it
     * again.
     */
    public function next(): ?int
    {
        if ($this->rowDepth === -2) {
            return null;
        }
        $reader = $this->reader;
        if ($this->rowDepth === -1) {
            while (true) {
                if (!Xml::read($reader)) {
                    return $this->end();
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'sheetData') {
                    if ($reader->isEmptyElement) {
                        return $this->end();
                    }
                    $this->rowDepth = $reader->depth + 1;
                    break;
                }
            }
        }
        while (true) {
            if (!$this->pending && !Xml::read($reader)) {
                return $this->end();
            }
            $this->pending = false;
            if ($reader->depth < $this->rowDepth) {
                // The end of sheetData.
                return $this->end();
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName !== 'row' || $reader->depth !== $this->rowDepth) {
                $this->pending = Xml::next($reader);
                if (!$this->pending) {
                    return $this->end();
                }
                continue;
            }
            $number = $reader->getAttribute('r');
            if ($number === null) {
                $row = $this->lastRow + 1;
            } elseif (preg_match('/^\d{1,7}\z/', $number) === 1) {
                $row = (int) $number;
            } else {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A row number is not valid.');
            }
            if ($row <= $this->lastRow || $row > Limits::MAX_SHEET_ROW) {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The row numbers do not increase.');
            }
            $this->lastRow = $row;

            return $row;
        }
    }

    /** Leaves the current row without reading its cells. */
    public function skip(): void
    {
        $this->pending = Xml::next($this->reader);
    }

    /**
     * The cells of the current row: column index (0-based) => text, only
     * cells with text.
     *
     * @return array<int, string>
     */
    public function row(): array
    {
        $reader = $this->reader;
        $cells = [];
        if ($reader->isEmptyElement) {
            return $cells;
        }
        $depth = $reader->depth;
        $column = -1;
        $elements = 0;
        $more = Xml::read($reader);
        while ($more && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                $more = Xml::read($reader);
                continue;
            }
            if ($reader->localName !== 'c' || $reader->depth !== $depth + 1) {
                $more = Xml::next($reader);
                continue;
            }
            if (++$elements > Limits::MAX_SHEET_COLUMN) {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A row has more cells than the format allows.');
            }
            $column = self::column($reader->getAttribute('r'), $column);
            $text = $this->cell($reader);
            if ($text !== null) {
                $cells[$column] = $text;
            } else {
                unset($cells[$column]);
            }
            $more = Xml::next($reader);
        }
        $this->pending = false;

        return $cells;
    }

    private function end(): ?int
    {
        $this->rowDepth = -2;

        return null;
    }

    /** The 0-based column of cell reference $reference (`B7`), or the one after $previous when absent. */
    private static function column(?string $reference, int $previous): int
    {
        if ($reference === null) {
            $column = $previous + 1;
        } elseif (preg_match('/^([A-Z]{1,3})\d{0,7}\z/', $reference, $m) === 1) {
            $column = 0;
            foreach (str_split($m[1]) as $letter) {
                $column = $column * 26 + ord($letter) - 64;
            }
            $column--;
        } else {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A cell reference is not valid.');
        }
        if ($column >= Limits::MAX_SHEET_COLUMN) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A cell is outside the columns of the format.');
        }

        return $column;
    }

    /** The text of the cell element the reader is on, or null. Leaves the reader on the element or its end. */
    private function cell(\XMLReader $reader): ?string
    {
        $type = $reader->getAttribute('t') ?? 'n';
        $style = $reader->getAttribute('s');
        if ($reader->isEmptyElement) {
            return null;
        }
        $depth = $reader->depth;
        $value = null;
        $inline = null;
        $formula = false;
        $more = Xml::read($reader);
        while ($more && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === $depth + 1) {
                $name = $reader->localName;
                if ($name === 'v') {
                    $value = Xml::cut(Xml::content($reader));
                } elseif ($name === 'is') {
                    $inline = Xml::text($reader);
                } elseif ($name === 'f') {
                    $formula = true;
                }
                $more = Xml::next($reader);
                continue;
            }
            $more = Xml::read($reader);
        }
        if ($formula && ($value === null || $value === '')) {
            // A formula without a cached value: never evaluated.
            return null;
        }

        return match ($type) {
            's' => $value !== null && preg_match('/^\s*\d{1,10}\s*\z/', $value) === 1
                ? CellText::string((string) $this->workbook->strings()->get((int) trim($value)))
                : null,
            'inlineStr' => $inline === null ? null : CellText::string($inline),
            'str' => $value === null ? null : CellText::string($value),
            'b' => $value === null ? null : CellText::boolean($value),
            'd' => $value === null ? null : CellText::isoDate($value),
            'n' => $value === null ? null : CellText::number(
                $value,
                $style !== null && preg_match('/^\d{1,9}\z/', $style) === 1 ? $this->workbook->styles()->kind((int) $style) : Styles::NUMBER,
                $this->workbook->date1904,
            ),
            // 'e' (an error such as #N/A) and anything unknown.
            default => null,
        };
    }
}
