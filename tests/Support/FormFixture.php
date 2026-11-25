<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\Forms\Block;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\Inline;
use TiffinDB\Core\Forms\LineBreak;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Storage\Keys;

/**
 * Seeded valid form documents for the sanitizer tests (M5 spec section
 * 11): 1 to 6 sections and 0 to 4 root blocks in a shuffled order, each
 * section with 0 to 12 children drawn from every block kind, inline content
 * up to 3 levels deep from every inline kind, placements from every allowed
 * value, labels and legends from a synthetic word list, field keys from
 * Keys over SeededRandom (unique within a document), and links from a list
 * of reserved example addresses. The same seed gives the same documents.
 * All text is synthetic.
 */
final class FormFixture
{
    public const SEED = 20261123;

    /** Synthetic words: plain, accented, non-Latin, and characters the serializer escapes. */
    public const WORDS = [
        'Shelf', 'Example label', 'Room 5', "Caf\u{E9}", "\u{C5}ngstr\u{F6}m", "\u{65E5}\u{672C}", "\u{41C}\u{438}\u{440}",
        "\u{1F600}", 'a & b', '<tag>', '"quoted"', "it's", ']]>', '&amp;', '--', "\u{200D}",
    ];

    /** Pieces of inline text that only runs may hold: white space, tab, and line feed. */
    private const RUN_EXTRAS = [' ', "\t", "\n", '  '];

    public const HREFS = [
        'https://example.com/', 'https://example.com/a?b=1&c=2#d', 'http://192.0.2.10:8080/path', 'HTTPS://example.org/x',
        'https://xn--r8jz45g.example/', 'https://example.com/it%27s',
    ];

    private const ROLES = ['details', 'files', 'links', 'my-role-1', 'a', null];
    private const SPANS = ['full', '1', '4', '6', '8', '12'];

    private readonly SeededRandom $random;
    private readonly Keys $keys;
    /** @var array<string, true> */
    private array $used = [];

    public function __construct(int|string $seed = self::SEED)
    {
        $this->random = new SeededRandom('form-fixture-' . (string) $seed);
        $this->keys = new Keys(new SeededRandom('form-fixture-keys-' . (string) $seed));
    }

    /** A number from 0 to $max inclusive. */
    public function int(int $max): int
    {
        $value = unpack('N', $this->random->bytes(4));

        return ($value === false ? 0 : (int) $value[1]) % ($max + 1);
    }

    public function document(): FormDocument
    {
        $this->used = [];
        $blocks = [];
        $sections = $this->int(5) + 1;
        for ($i = 0; $i < $sections; $i++) {
            $blocks[] = $this->section();
        }
        $loose = $this->int(4);
        for ($i = 0; $i < $loose; $i++) {
            array_splice($blocks, $this->int(count($blocks)), 0, [$this->block()]);
        }

        return new FormDocument($blocks);
    }

    private function section(): Section
    {
        $children = [];
        $count = $this->int(12);
        for ($i = 0; $i < $count; $i++) {
            $children[] = $this->block();
        }

        return new Section(self::ROLES[$this->int(count(self::ROLES) - 1)], $this->int(3) === 0 ? null : $this->plain(100), $children, $this->placement());
    }

    /** A block other than a section. */
    private function block(): Block
    {
        return match ($this->int(6)) {
            0 => new FieldSlot($this->key(), $this->int(1) === 0 ? $this->plain(60) : null, $this->placement()),
            1 => new FieldSlot($this->key()),
            2 => new Heading(2 + $this->int(1), $this->inlines(0, false), $this->placement()),
            3 => new Paragraph($this->inlines(0, false), $this->placement()),
            4 => new Divider($this->placement()),
            default => new Image($this->attachmentId(), $this->int(2) === 0 ? '' : $this->plain(200), $this->placement()),
        };
    }

    private function key(): string
    {
        do {
            $key = $this->keys->generate();
        } while (isset($this->used[$key]));
        $this->used[$key] = true;

        return $key;
    }

    private function attachmentId(): int
    {
        return match ($this->int(3)) {
            0 => 1,
            1 => PHP_INT_MAX,
            default => $this->int(100000) + 1,
        };
    }

    private function placement(): ?Placement
    {
        if ($this->int(2) !== 0) {
            return null;
        }
        $col = $this->int(1) === 0 ? $this->int(11) + 1 : null;
        $row = $this->int(1) === 0 ? $this->int(49) + 1 : null;
        $span = $this->int(1) === 0 ? self::SPANS[$this->int(count(self::SPANS) - 1)] : null;

        return $col === null && $row === null && $span === null ? new Placement(null, null, 'full') : new Placement($col, $row, $span);
    }

    /** One line of text, trimmed, 1 to $max code points: words joined by spaces. */
    private function plain(int $max): string
    {
        $text = '';
        $words = $this->int(3) + 1;
        for ($i = 0; $i < $words; $i++) {
            $next = trim(($text === '' ? '' : $text . ' ') . self::WORDS[$this->int(count(self::WORDS) - 1)]);
            if (preg_match_all('/./su', $next) > $max) {
                break;
            }
            $text = $next;
        }

        return $text === '' || $text === "\u{200D}" ? 'Shelf' : $text;
    }

    private function runText(): string
    {
        $text = '';
        $pieces = $this->int(3) + 1;
        for ($i = 0; $i < $pieces; $i++) {
            $text .= $this->int(3) === 0
                ? self::RUN_EXTRAS[$this->int(count(self::RUN_EXTRAS) - 1)]
                : self::WORDS[$this->int(count(self::WORDS) - 1)];
        }

        return $text;
    }

    /** @return list<Inline> */
    private function inlines(int $depth, bool $inLink): array
    {
        $inlines = [];
        $count = $this->int(5);
        for ($i = 0; $i < $count; $i++) {
            $choice = $depth >= 3 ? $this->int(1) : $this->int(5);
            $node = match ($choice) {
                0 => new TextRun($this->runText()),
                1 => new LineBreak(),
                2 => new Strong($this->inlines($depth + 1, $inLink)),
                3 => new Emphasis($this->inlines($depth + 1, $inLink)),
                default => $inLink
                    ? new Strong($this->inlines($depth + 1, true))
                    : new Link(self::HREFS[$this->int(count(self::HREFS) - 1)], $this->inlines($depth + 1, true)),
            };
            if ($node instanceof TextRun && $inlines !== [] && $inlines[count($inlines) - 1] instanceof TextRun) {
                continue;
            }
            $inlines[] = $node;
        }

        return $inlines;
    }
}
