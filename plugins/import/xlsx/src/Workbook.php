<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * A checked workbook's structure: its worksheets in workbook order (name,
 * hidden state, part), the date system, and, read on first use, its cell
 * formats and shared strings.
 *
 * Parts are found through the package relationships (`_rels/.rels`, then
 * the workbook's own `.rels`), by the last segment of the relationship
 * type, so transitional and strict workbooks are read alike. Targets are
 * resolved against the source part's folder, may not leave the package,
 * and must name an entry (compared ignoring case, as package part names
 * are); external targets (`TargetMode="External"`, such as external links
 * and hyperlinks) are never followed. Only worksheets are tables: chart
 * sheets, dialog sheets, and macro sheets are left out. Two sheets naming
 * one part are refused as unreadable, more than MAX_SHEETS worksheets as
 * too large.
 *
 * @internal
 */
final class Workbook
{
    private ?Styles $styles = null;
    private ?SharedStrings $strings = null;

    /**
     * @param list<array{name: string, hidden: bool, part: string}> $sheets
     */
    private function __construct(
        public readonly string $path,
        public readonly array $sheets,
        public readonly bool $date1904,
        private readonly ?string $stylesPart,
        private readonly ?string $stringsPart,
    ) {
    }

    /** Checks the workbook at $path (WorkbookCheck) and reads its structure. */
    public static function open(string $path): self
    {
        $entries = WorkbookCheck::check($path);
        $lower = [];
        foreach (array_keys($entries) as $name) {
            $lower[strtolower((string) $name)] ??= (string) $name;
        }

        return Xml::run(static function () use ($path, $lower): self {
            $workbookPart = WorkbookCheck::WORKBOOK;
            foreach (self::relationships($path, '_rels/.rels', '', $lower) as [$type, $target]) {
                if ($type === 'officeDocument' && $target !== null) {
                    $workbookPart = $target;
                    break;
                }
            }
            $folder = str_contains($workbookPart, '/') ? substr($workbookPart, 0, (int) strrpos($workbookPart, '/') + 1) : '';
            $relsPart = $folder . '_rels/' . substr($workbookPart, strlen($folder)) . '.rels';
            $targets = [];
            $stylesPart = null;
            $stringsPart = null;
            foreach (self::relationships($path, $relsPart, $folder, $lower) as $id => [$type, $target]) {
                if ($target === null) {
                    continue;
                }
                if ($type === 'worksheet') {
                    $targets[$id] = $target;
                } elseif ($type === 'styles') {
                    $stylesPart ??= $target;
                } elseif ($type === 'sharedStrings') {
                    $stringsPart ??= $target;
                }
            }
            [$sheets, $date1904] = self::readWorkbook($path, $workbookPart, $targets);
            if ($sheets === []) {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The workbook has no worksheet.');
            }

            return new self($path, $sheets, $date1904, $stylesPart, $stringsPart);
        });
    }

    public function styles(): Styles
    {
        if ($this->styles === null) {
            $part = $this->stylesPart;
            $path = $this->path;
            $this->styles = $part === null ? Styles::none() : Xml::run(static fn (): Styles => Styles::read($path, $part));
        }

        return $this->styles;
    }

    public function strings(): SharedStrings
    {
        if ($this->strings === null) {
            $part = $this->stringsPart;
            $path = $this->path;
            $this->strings = $part === null ? SharedStrings::none() : Xml::run(static fn (): SharedStrings => SharedStrings::read($path, $part));
        }

        return $this->strings;
    }

    /**
     * The relationships of part $relsPart: id => [type (last segment), the
     * resolved entry name or null when external, missing, or outside].
     *
     * @param array<string, string> $lower lower-case entry name => entry name
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function relationships(string $path, string $relsPart, string $folder, array $lower): array
    {
        $entry = $lower[strtolower($relsPart)] ?? null;
        if ($entry === null) {
            return [];
        }
        $reader = Xml::open($path, $entry);
        $found = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship' || count($found) >= Limits::MAX_ENTRIES) {
                    continue;
                }
                $id = $reader->getAttribute('Id');
                $type = $reader->getAttribute('Type');
                $target = $reader->getAttribute('Target');
                if ($id === null || $type === null || $target === null || isset($found[$id])) {
                    continue;
                }
                $slash = strrpos($type, '/');
                $last = $slash === false ? $type : substr($type, $slash + 1);
                $external = strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0;
                $found[$id] = [$last, $external ? null : self::resolve($target, $folder, $lower)];
            }
        } finally {
            $reader->close();
        }

        return $found;
    }

    /**
     * The entry named by relationship target $target from folder $folder,
     * or null.
     *
     * @param array<string, string> $lower
     */
    private static function resolve(string $target, string $folder, array $lower): ?string
    {
        if ($target === '' || str_contains($target, '\\') || str_contains($target, "\0") || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $target) === 1) {
            return null;
        }
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $target = substr($target, 0, $hash);
        }
        $full = str_starts_with($target, '/') ? substr($target, 1) : $folder . $target;
        $segments = [];
        foreach (explode('/', $full) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $name = implode('/', $segments);

        return $lower[strtolower($name)] ?? $lower[strtolower(rawurldecode($name))] ?? null;
    }

    /**
     * The worksheets listed by the workbook part, and its date system.
     *
     * @param array<string, string> $targets relationship id => worksheet entry
     * @return array{0: list<array{name: string, hidden: bool, part: string}>, 1: bool}
     */
    private static function readWorkbook(string $path, string $part, array $targets): array
    {
        $reader = Xml::open($path, $part);
        $sheets = [];
        $parts = [];
        $date1904 = false;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                $name = $reader->localName;
                if ($name === 'workbookPr') {
                    $value = strtolower(trim((string) $reader->getAttribute('date1904')));
                    $date1904 = $value === '1' || $value === 'true';
                } elseif ($name === 'sheet') {
                    $id = Xml::prefixedAttribute($reader, 'id');
                    $target = $id === null ? null : ($targets[$id] ?? null);
                    if ($target === null) {
                        continue;
                    }
                    if (isset($parts[$target])) {
                        throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'Two sheets name the same part.');
                    }
                    if (count($sheets) >= Limits::MAX_SHEETS) {
                        throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The workbook has more worksheets than the limit.');
                    }
                    $parts[$target] = true;
                    $state = (string) $reader->getAttribute('state');
                    $sheets[] = [
                        'name' => (string) $reader->getAttribute('name'),
                        'hidden' => $state === 'hidden' || $state === 'veryHidden',
                        'part' => $target,
                    ];
                }
            }
        } finally {
            $reader->close();
        }

        return [$sheets, $date1904];
    }
}
