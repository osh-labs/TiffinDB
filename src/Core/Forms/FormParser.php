<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;

/**
 * Reads stored form text into a FormDocument (M2 spec section 5.2, ADR
 * 0014 decision 4). Security-critical: this is the only gate between form
 * text written elsewhere and the record screen.
 *
 * Default deny: the text is accepted only when every node, element,
 * attribute, and value is one that `docs/formats/tiffin.md` section 12.1
 * lists; the first violation rejects the whole form with a reason code.
 * Nothing is dropped or repaired.
 *
 * External entities and DTDs: before any parser runs, text holding `<!`
 * (other than a comment's `<!--`) or `<?` is refused, which rules out
 * DOCTYPE, entity declarations, CDATA, processing instructions, and the
 * XML declaration. XMLReader then runs with LIBXML_NONET, without loading
 * or validating a DTD, without default attributes, and without entity
 * substitution, so it neither fetches nor expands anything; any libxml
 * error rejects, as does any entity-reference node.
 */
final class FormParser
{
    /** Every element name of form format 1. */
    private const ELEMENTS = ['div', 'fieldset', 'legend', 'h2', 'h3', 'p', 'hr', 'img', 'strong', 'em', 'br', 'a'];

    /** Elements allowed in the root, and (with legend) in a section. */
    private const BLOCKS = ['fieldset', 'div', 'h2', 'h3', 'p', 'hr', 'img'];
    private const SECTION_BLOCKS = ['legend', 'div', 'h2', 'h3', 'p', 'hr', 'img'];
    private const INLINES = ['strong', 'em', 'br', 'a'];

    private const PLACEMENT = ['data-tiffin-col', 'data-tiffin-row', 'data-tiffin-span'];

    /** Attributes each element may have (besides placement where marked), and those it must have. */
    private const ATTRIBUTES = [
        'fieldset' => ['data-tiffin-section'],
        'div' => ['data-tiffin-field', 'data-tiffin-label'],
        'h2' => [],
        'h3' => [],
        'p' => [],
        'hr' => [],
        'img' => ['src', 'alt'],
        'legend' => [],
        'strong' => [],
        'em' => [],
        'br' => [],
        'a' => ['href'],
    ];
    private const REQUIRED = ['div' => ['data-tiffin-field'], 'img' => ['src', 'alt'], 'a' => ['href']];
    private const WITH_PLACEMENT = ['fieldset', 'div', 'h2', 'h3', 'p', 'hr', 'img'];

    /** Frame contexts while walking. */
    private const CTX_ROOT = 'root';
    private const CTX_SECTION = 'section';
    private const CTX_INLINE = 'inline';
    private const CTX_LEGEND = 'legend';
    private const CTX_EMPTY = 'empty';

    private const SRC = '/^attachment:([1-9][0-9]{0,18})\z/';

    public function __construct(private readonly FieldTypes $types)
    {
        // The url rule of `a href` is the url field type's; FormRules applies the same type.
        $this->types->get('url');
    }

    /** @throws FormRejected with a reason code (for logs; never the content) */
    public function parse(string $html): FormDocument
    {
        self::preScan($html);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $this->walk($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Step 1: size, encoding, NUL, and markup declarations, before any parser runs. */
    private static function preScan(string $html): void
    {
        if (strlen($html) > FormFormat::MAX_BYTES) {
            throw new FormRejected(FormRejected::TOO_LARGE);
        }
        if (!Text::isUtf8($html)) {
            throw new FormRejected(FormRejected::ENCODING);
        }
        if (str_contains($html, "\0")) {
            throw new FormRejected(FormRejected::NUL);
        }
        if (preg_match('/<!(?!--)/', $html) === 1) {
            throw new FormRejected(FormRejected::DECLARATION);
        }
        if (str_contains($html, '<?')) {
            throw new FormRejected(FormRejected::PROCESSING_INSTRUCTION);
        }
    }

    private function walk(string $html): FormDocument
    {
        if ($html === '') {
            throw new FormRejected(FormRejected::ROOT);
        }
        $reader = \XMLReader::XML($html, 'UTF-8', LIBXML_NONET);
        if (!$reader instanceof \XMLReader) {
            throw new FormRejected(FormRejected::MALFORMED);
        }
        try {
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::DEFAULTATTRS, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);

            return $this->walkNodes($reader);
        } finally {
            $reader->close();
        }
    }

    private function walkNodes(\XMLReader $reader): FormDocument
    {
        /** @var list<array<string, mixed>> $stack */
        $stack = [];
        $document = null;
        $rootSeen = false;
        $elements = 0;
        $slotKeys = [];

        while (true) {
            $more = $reader->read();
            if (libxml_get_errors() !== []) {
                throw new FormRejected(FormRejected::MALFORMED);
            }
            if (!$more) {
                break;
            }
            $type = $reader->nodeType;
            if ($type === \XMLReader::COMMENT) {
                continue;
            }
            if ($type === \XMLReader::TEXT || $type === \XMLReader::WHITESPACE || $type === \XMLReader::SIGNIFICANT_WHITESPACE) {
                $stack = self::text($stack, $reader->value);

                continue;
            }
            if ($type === \XMLReader::END_ELEMENT) {
                [$stack, $document] = $this->close($stack, $document);

                continue;
            }
            if ($type !== \XMLReader::ELEMENT) {
                throw new FormRejected(FormRejected::NODE_TYPE);
            }

            $elements++;
            if ($elements > FormFormat::MAX_ELEMENTS) {
                throw new FormRejected(FormRejected::TOO_MANY_ELEMENTS);
            }
            if ($reader->depth + 1 > FormFormat::MAX_DEPTH) {
                throw new FormRejected(FormRejected::TOO_DEEP);
            }
            $name = $reader->name;
            $isEmpty = $reader->isEmptyElement;
            $namespace = $reader->namespaceURI;
            $attributes = self::attributes($reader);
            if ($stack === []) {
                if ($rootSeen) {
                    throw new FormRejected(FormRejected::ROOT);
                }
                $rootSeen = true;
                $stack[] = self::openRoot($name, $namespace, $attributes);
            } else {
                $frame = $this->open($stack[count($stack) - 1], $name, $namespace, $attributes);
                $stack[count($stack) - 1]['seen'] = true;
                if ($frame['name'] === 'div') {
                    $key = $frame['attributes']['data-tiffin-field'];
                    if (isset($slotKeys[$key])) {
                        throw new FormRejected(FormRejected::DUPLICATE_FIELD);
                    }
                    $slotKeys[$key] = true;
                }
                $stack = self::flush($stack);
                $stack[] = $frame;
            }
            if ($isEmpty) {
                [$stack, $document] = $this->close($stack, $document);
            }
        }

        if (!$document instanceof FormDocument || $stack !== []) {
            throw new FormRejected(FormRejected::ROOT);
        }

        return $document;
    }

    /**
     * The element's attributes by qualified name; the reader is left on the element.
     *
     * @return array<string, string>
     */
    private static function attributes(\XMLReader $reader): array
    {
        $attributes = [];
        if ($reader->hasAttributes && $reader->moveToFirstAttribute()) {
            do {
                if (array_key_exists($reader->name, $attributes)) {
                    throw new FormRejected(FormRejected::MALFORMED);
                }
                $attributes[$reader->name] = $reader->value;
            } while ($reader->moveToNextAttribute());
            $reader->moveToElement();
        }

        return $attributes;
    }

    /**
     * Step 4: one `div` whose only attribute is `data-tiffin-form="1"`.
     *
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    private static function openRoot(string $name, string $namespace, array $attributes): array
    {
        if ($name !== 'div' || $namespace !== '' || array_keys($attributes) !== ['data-tiffin-form']) {
            throw new FormRejected(FormRejected::ROOT);
        }
        if ($attributes['data-tiffin-form'] !== (string) FormFormat::VERSION) {
            throw new FormRejected(FormRejected::FORMAT_VERSION);
        }

        return self::frame('root', self::CTX_ROOT, $attributes, false);
    }

    /**
     * Step 5: an element's name, place, and attributes.
     *
     * @param array<string, mixed> $parent
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    private function open(array $parent, string $name, string $namespace, array $attributes): array
    {
        if (!in_array($name, self::ELEMENTS, true)) {
            throw new FormRejected(FormRejected::ELEMENT);
        }
        $context = $parent['context'];
        $allowed = match ($context) {
            self::CTX_ROOT => self::BLOCKS,
            self::CTX_SECTION => $parent['seen'] ? array_values(array_diff(self::SECTION_BLOCKS, ['legend'])) : self::SECTION_BLOCKS,
            self::CTX_INLINE => $parent['inLink'] ? array_values(array_diff(self::INLINES, ['a'])) : self::INLINES,
            default => throw new FormRejected(FormRejected::CONTENT),
        };
        if (!in_array($name, $allowed, true)) {
            throw new FormRejected(FormRejected::MISPLACED);
        }

        $known = [...self::ATTRIBUTES[$name], ...(in_array($name, self::WITH_PLACEMENT, true) ? self::PLACEMENT : [])];
        foreach (array_keys($attributes) as $attribute) {
            if (!in_array($attribute, $known, true)) {
                throw new FormRejected(FormRejected::ATTRIBUTE);
            }
        }
        foreach (self::REQUIRED[$name] ?? [] as $attribute) {
            if (!array_key_exists($attribute, $attributes)) {
                throw new FormRejected(FormRejected::ATTRIBUTE);
            }
        }
        if ($namespace !== '') {
            // Only reachable through a namespace declaration, which is itself refused above.
            throw new FormRejected(FormRejected::ELEMENT);
        }
        $values = $this->values($attributes);

        $childContext = match ($name) {
            'fieldset' => self::CTX_SECTION,
            'legend' => self::CTX_LEGEND,
            'h2', 'h3', 'p', 'strong', 'em', 'a' => self::CTX_INLINE,
            default => self::CTX_EMPTY,
        };

        return self::frame($name, $childContext, $values, $parent['inLink'] || $name === 'a');
    }

    /**
     * Validates attribute values (step 5) and returns them in the form the
     * node constructors take: labels and alt text trimmed.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function values(array $attributes): array
    {
        $values = [];
        foreach ($attributes as $attribute => $value) {
            $ok = match ($attribute) {
                'data-tiffin-section' => FormRules::isRole($value),
                'data-tiffin-field' => Keys::isValid($value),
                'data-tiffin-label' => FormRules::isPlainText(Text::trim($value), 1, FormFormat::MAX_LABEL),
                'data-tiffin-col' => preg_match(FormRules::COLUMN, $value) === 1,
                'data-tiffin-row' => preg_match(FormRules::ROW, $value) === 1,
                'data-tiffin-span' => FormRules::isSpan($value),
                'src' => self::attachmentId($value) !== null,
                'alt' => FormRules::isPlainText(Text::trim($value), 0, FormFormat::MAX_ALT),
                'href' => FormRules::isHref($value) && $this->types->get('url')->parse($value, [])->isOk(),
                default => false,
            };
            if (!$ok) {
                throw new FormRejected(FormRejected::ATTRIBUTE_VALUE);
            }
            $values[$attribute] = in_array($attribute, ['data-tiffin-label', 'alt'], true) ? Text::trim($value) : $value;
        }

        return $values;
    }

    /** The id of `attachment:<id>`, or null when the value breaks the rule or does not fit an integer. */
    private static function attachmentId(string $src): ?int
    {
        if (preg_match(self::SRC, $src, $match) !== 1) {
            return null;
        }
        $digits = $match[1];
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    private static function frame(string $name, string $context, array $attributes, bool $inLink): array
    {
        return [
            'name' => $name,
            'context' => $context,
            'attributes' => $attributes,
            'children' => [],
            'pending' => '',
            'legend' => null,
            'seen' => false,
            'inLink' => $inLink,
        ];
    }

    /**
     * Step 6: text. Outside inline content and legends only white space is
     * allowed (and ignored); empty elements hold nothing at all.
     *
     * @param list<array<string, mixed>> $stack
     * @return list<array<string, mixed>>
     */
    private static function text(array $stack, string $value): array
    {
        $whitespace = strspn($value, " \t\r\n") === strlen($value);
        if ($stack === []) {
            if (!$whitespace) {
                throw new FormRejected(FormRejected::TEXT);
            }

            return $stack;
        }
        $top = count($stack) - 1;
        $context = $stack[$top]['context'];
        if ($context === self::CTX_EMPTY) {
            throw new FormRejected(FormRejected::CONTENT);
        }
        if ($context === self::CTX_INLINE || $context === self::CTX_LEGEND) {
            $stack[$top]['pending'] = $stack[$top]['pending'] . $value;

            return $stack;
        }
        if (!$whitespace) {
            throw new FormRejected(FormRejected::TEXT);
        }

        return $stack;
    }

    /**
     * Turns the pending text of the top inline frame into one text run
     * (step 8: consecutive text and white space nodes are merged).
     *
     * @param list<array<string, mixed>> $stack
     * @return list<array<string, mixed>>
     */
    private static function flush(array $stack): array
    {
        $top = count($stack) - 1;
        if ($stack[$top]['context'] !== self::CTX_INLINE || $stack[$top]['pending'] === '') {
            return $stack;
        }
        if (!FormRules::isRunText($stack[$top]['pending'])) {
            throw new FormRejected(FormRejected::TEXT_VALUE);
        }
        $stack[$top]['children'][] = new TextRun($stack[$top]['pending']);
        $stack[$top]['pending'] = '';

        return $stack;
    }

    /**
     * Ends the top element: builds its node and gives it to the parent (or
     * makes the document, for the root).
     *
     * @param list<array<string, mixed>> $stack
     * @return array{0: list<array<string, mixed>>, 1: ?FormDocument}
     */
    private function close(array $stack, ?FormDocument $document): array
    {
        if ($stack === []) {
            throw new FormRejected(FormRejected::MALFORMED);
        }
        $stack = self::flush($stack);
        $frame = array_pop($stack);
        try {
            $node = self::build($frame);
        } catch (\InvalidArgumentException) {
            // The checks above mirror the constructors; this is a second line only.
            throw new FormRejected(FormRejected::ATTRIBUTE_VALUE);
        }
        if ($node instanceof FormDocument) {
            return [$stack, $node];
        }
        $top = count($stack) - 1;
        if (is_string($node)) {
            $stack[$top]['legend'] = $node;
        } else {
            $stack[$top]['children'][] = $node;
        }

        return [$stack, $document];
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function build(array $frame): FormDocument|Block|Inline|string
    {
        $a = $frame['attributes'];
        $children = $frame['children'];

        return match ($frame['name']) {
            'root' => new FormDocument($children),
            'fieldset' => new Section($a['data-tiffin-section'] ?? null, $frame['legend'], $children, self::placement($a)),
            'legend' => self::legend($frame['pending']),
            'div' => new FieldSlot($a['data-tiffin-field'], $a['data-tiffin-label'] ?? null, self::placement($a)),
            'h2' => new Heading(2, $children, self::placement($a)),
            'h3' => new Heading(3, $children, self::placement($a)),
            'p' => new Paragraph($children, self::placement($a)),
            'hr' => new Divider(self::placement($a)),
            'img' => new Image((int) self::attachmentId($a['src']), $a['alt'], self::placement($a)),
            'strong' => new Strong($children),
            'em' => new Emphasis($children),
            'br' => new LineBreak(),
            'a' => new Link($a['href'], $children),
            default => throw new FormRejected(FormRejected::ELEMENT),
        };
    }

    /** A legend's text, trimmed: 1 to 100 characters of plain text. */
    private static function legend(string $text): string
    {
        $legend = Text::trim($text);
        if (!FormRules::isPlainText($legend, 1, FormFormat::MAX_LEGEND)) {
            throw new FormRejected(FormRejected::TEXT_VALUE);
        }

        return $legend;
    }

    /** @param array<string, string> $attributes */
    private static function placement(array $attributes): ?Placement
    {
        $col = $attributes['data-tiffin-col'] ?? null;
        $row = $attributes['data-tiffin-row'] ?? null;
        $span = $attributes['data-tiffin-span'] ?? null;
        if ($col === null && $row === null && $span === null) {
            return null;
        }

        return new Placement($col === null ? null : (int) $col, $row === null ? null : (int) $row, $span);
    }
}
