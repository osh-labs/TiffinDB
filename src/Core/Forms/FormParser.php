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
 *
 * M5 additions (M5 spec sections 3 and 4): one leading byte order mark is
 * removed first (a second one is refused); after the byte checks, CR LF
 * and lone CR become LF, so libxml's lines are right; a rejection carries
 * the line of the problem (via StartTags, built only on rejection) and the
 * element and attribute names; a bad link address is HREF and a bad image
 * source SRC. Text outside the root and the walk's own MALFORMED checks
 * are second lines: libxml refuses those texts first.
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

    /** The byte order mark removed from the start of a text (section 3.1 item 1). */
    private const BOM = "\xEF\xBB\xBF";

    /** The longest prefix of valid UTF-8 (section 4.2); bytes, not the `u` flag. */
    private const UTF8_PREFIX = '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

    /** The text being walked (line breaks normalized), for start-tag lines. */
    private string $text = '';

    /** @var list<int>|null start-tag offsets of $text, built only when a rejection needs a line */
    private ?array $offsets = null;

    /** The number of the element most recently read (1 for the root), 0 before the root. */
    private int $current = 0;

    /** @throws FormRejected with a reason code, a line, and names (never the content) */
    public function parse(string $html): FormDocument
    {
        if (str_starts_with($html, self::BOM)) {
            $html = substr($html, strlen(self::BOM));
        }
        self::preScan($html);
        if (str_starts_with($html, self::BOM)) {
            // A second mark is the character U+FEFF before the root. libxml would skip it as a mark, so it is refused here.
            throw new FormRejected(FormRejected::TEXT, 1);
        }
        // XML 1.0 end-of-line handling, done before the reader so that libxml's lines (it counts only LF) are right.
        $html = (string) preg_replace('/\r\n?/', "\n", $html);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $this->walk($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $this->text = '';
            $this->offsets = null;
        }
    }

    /** Steps 1 to 3: size, encoding, NUL, declarations, and instructions, before any parser runs. */
    private static function preScan(string $html): void
    {
        if (strlen($html) > FormFormat::MAX_BYTES) {
            throw new FormRejected(FormRejected::TOO_LARGE);
        }
        if (!Text::isUtf8($html)) {
            $line = null;
            if (preg_match(self::UTF8_PREFIX, $html, $match) === 1) {
                $line = StartTags::lineAt($match[0], strlen($match[0]));
            }
            throw new FormRejected(FormRejected::ENCODING, $line);
        }
        $nul = strpos($html, "\0");
        if ($nul !== false) {
            throw new FormRejected(FormRejected::NUL, StartTags::lineAt($html, $nul));
        }
        if (preg_match('/<!(?!--)/', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            throw new FormRejected(FormRejected::DECLARATION, StartTags::lineAt($html, $match[0][1]));
        }
        $instruction = strpos($html, '<?');
        if ($instruction !== false) {
            throw new FormRejected(FormRejected::PROCESSING_INSTRUCTION, StartTags::lineAt($html, $instruction));
        }
    }

    private function walk(string $html): FormDocument
    {
        $this->text = $html;
        $this->offsets = null;
        $this->current = 0;
        if ($html === '') {
            throw new FormRejected(FormRejected::ROOT);
        }
        $reader = \XMLReader::XML($html, 'UTF-8', LIBXML_NONET | LIBXML_BIGLINES);
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

    /** The line of the start tag of element number $index (1 for the root), or null when unknown. */
    private function line(int $index): ?int
    {
        if ($index < 1) {
            return null;
        }
        $this->offsets ??= StartTags::offsets($this->text);
        $offset = $this->offsets[$index - 1] ?? null;

        return $offset === null ? null : StartTags::lineAt($this->text, $offset);
    }

    private function reject(string $reason, int $index, ?string $element = null, ?string $attribute = null): FormRejected
    {
        return new FormRejected($reason, $this->line($index), $element, $attribute);
    }

    /** MALFORMED at the first libxml error's line, when libxml gives one. */
    private static function malformed(\LibXMLError $error): FormRejected
    {
        return new FormRejected(FormRejected::MALFORMED, $error->line >= 1 ? $error->line : null);
    }

    private function walkNodes(\XMLReader $reader): FormDocument
    {
        /** @var list<array<string, mixed>> $stack */
        $stack = [];
        $document = null;
        $rootSeen = false;
        $slotKeys = [];

        while (true) {
            $more = $reader->read();
            $errors = libxml_get_errors();
            if ($errors !== []) {
                throw self::malformed($errors[0]);
            }
            if (!$more) {
                break;
            }
            $type = $reader->nodeType;
            if ($type === \XMLReader::COMMENT) {
                continue;
            }
            if ($type === \XMLReader::TEXT || $type === \XMLReader::WHITESPACE || $type === \XMLReader::SIGNIFICANT_WHITESPACE) {
                $stack = $this->text($stack, $reader->value, $rootSeen);

                continue;
            }
            if ($type === \XMLReader::END_ELEMENT) {
                [$stack, $document] = $this->close($stack, $document);

                continue;
            }
            if ($type !== \XMLReader::ELEMENT) {
                throw $this->reject(FormRejected::NODE_TYPE, $this->current);
            }

            $this->current++;
            $index = $this->current;
            $name = $reader->name;
            if ($index > FormFormat::MAX_ELEMENTS) {
                throw $this->reject(FormRejected::TOO_MANY_ELEMENTS, $index, $name);
            }
            if ($reader->depth + 1 > FormFormat::MAX_DEPTH) {
                throw $this->reject(FormRejected::TOO_DEEP, $index, $name);
            }
            $isEmpty = $reader->isEmptyElement;
            $namespace = $reader->namespaceURI;
            $attributes = $this->attributes($reader, $index);
            if ($stack === []) {
                if ($rootSeen) {
                    throw $this->reject(FormRejected::ROOT, $index, $name);
                }
                $rootSeen = true;
                $stack[] = $this->openRoot($name, $namespace, $attributes, $index);
            } else {
                $frame = $this->open($stack[count($stack) - 1], $name, $namespace, $attributes, $index);
                $stack[count($stack) - 1]['seen'] = true;
                if ($frame['name'] === 'div') {
                    $key = $frame['attributes']['data-tiffin-field'];
                    if (isset($slotKeys[$key])) {
                        throw $this->reject(FormRejected::DUPLICATE_FIELD, $index, 'div', 'data-tiffin-field');
                    }
                    $slotKeys[$key] = true;
                }
                $stack = $this->flush($stack);
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
    private function attributes(\XMLReader $reader, int $index): array
    {
        $attributes = [];
        if ($reader->hasAttributes && $reader->moveToFirstAttribute()) {
            do {
                if (array_key_exists($reader->name, $attributes)) {
                    throw $this->reject(FormRejected::MALFORMED, $index);
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
    private function openRoot(string $name, string $namespace, array $attributes, int $index): array
    {
        if ($name !== 'div' || $namespace !== '' || array_keys($attributes) !== ['data-tiffin-form']) {
            $other = array_values(array_diff(array_keys($attributes), ['data-tiffin-form']));
            throw $this->reject(FormRejected::ROOT, $index, $name, $other[0] ?? null);
        }
        if ($attributes['data-tiffin-form'] !== (string) FormFormat::VERSION) {
            throw $this->reject(FormRejected::FORMAT_VERSION, $index, $name, 'data-tiffin-form');
        }

        return self::frame('root', self::CTX_ROOT, $attributes, false, $index);
    }

    /**
     * Step 5: an element's name, place, and attributes.
     *
     * @param array<string, mixed> $parent
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    private function open(array $parent, string $name, string $namespace, array $attributes, int $index): array
    {
        if (!in_array($name, self::ELEMENTS, true)) {
            throw $this->reject(FormRejected::ELEMENT, $index, $name);
        }
        $context = $parent['context'];
        $allowed = match ($context) {
            self::CTX_ROOT => self::BLOCKS,
            self::CTX_SECTION => $parent['seen'] ? array_values(array_diff(self::SECTION_BLOCKS, ['legend'])) : self::SECTION_BLOCKS,
            self::CTX_INLINE => $parent['inLink'] ? array_values(array_diff(self::INLINES, ['a'])) : self::INLINES,
            default => throw $this->reject(FormRejected::CONTENT, $index, $name),
        };
        if (!in_array($name, $allowed, true)) {
            throw $this->reject(FormRejected::MISPLACED, $index, $name);
        }

        $known = [...self::ATTRIBUTES[$name], ...(in_array($name, self::WITH_PLACEMENT, true) ? self::PLACEMENT : [])];
        foreach (array_keys($attributes) as $attribute) {
            if (!in_array($attribute, $known, true)) {
                throw $this->reject(FormRejected::ATTRIBUTE, $index, $name, $attribute);
            }
        }
        foreach (self::REQUIRED[$name] ?? [] as $attribute) {
            if (!array_key_exists($attribute, $attributes)) {
                throw $this->reject(FormRejected::ATTRIBUTE, $index, $name, $attribute);
            }
        }
        if ($namespace !== '') {
            // Only reachable through a namespace declaration, which is itself refused above.
            throw $this->reject(FormRejected::ELEMENT, $index, $name);
        }
        $values = $this->values($name, $attributes, $index);

        $childContext = match ($name) {
            'fieldset' => self::CTX_SECTION,
            'legend' => self::CTX_LEGEND,
            'h2', 'h3', 'p', 'strong', 'em', 'a' => self::CTX_INLINE,
            default => self::CTX_EMPTY,
        };

        return self::frame($name, $childContext, $values, $parent['inLink'] || $name === 'a', $index);
    }

    /**
     * Validates attribute values (step 5) and returns them in the form the
     * node constructors take: labels and alt text trimmed. A link address
     * that breaks the rules is HREF, an image source SRC, any other value
     * ATTRIBUTE_VALUE.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function values(string $element, array $attributes, int $index): array
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
                $reason = match ($attribute) {
                    'href' => FormRejected::HREF,
                    'src' => FormRejected::SRC,
                    default => FormRejected::ATTRIBUTE_VALUE,
                };
                throw $this->reject($reason, $index, $element, $attribute);
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
    private static function frame(string $name, string $context, array $attributes, bool $inLink, int $index): array
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
            'index' => $index,
        ];
    }

    /**
     * Step 6: text. Outside inline content and legends only white space is
     * allowed (and ignored); empty elements hold nothing at all. A text
     * problem is reported at the start tag of the innermost open element
     * (the root's, for text after the root has closed).
     *
     * @param list<array<string, mixed>> $stack
     * @return list<array<string, mixed>>
     */
    private function text(array $stack, string $value, bool $rootSeen): array
    {
        $whitespace = strspn($value, " \t\r\n") === strlen($value);
        if ($stack === []) {
            if (!$whitespace) {
                throw $this->reject(FormRejected::TEXT, $rootSeen ? 1 : 0);
            }

            return $stack;
        }
        $top = count($stack) - 1;
        $context = $stack[$top]['context'];
        if ($context === self::CTX_EMPTY) {
            throw $this->reject(FormRejected::CONTENT, $stack[$top]['index'], $stack[$top]['name']);
        }
        if ($context === self::CTX_INLINE || $context === self::CTX_LEGEND) {
            $stack[$top]['pending'] = $stack[$top]['pending'] . $value;

            return $stack;
        }
        if (!$whitespace) {
            throw $this->reject(FormRejected::TEXT, $stack[$top]['index']);
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
    private function flush(array $stack): array
    {
        $top = count($stack) - 1;
        if ($stack[$top]['context'] !== self::CTX_INLINE || $stack[$top]['pending'] === '') {
            return $stack;
        }
        if (!FormRules::isRunText($stack[$top]['pending'])) {
            throw $this->reject(FormRejected::TEXT_VALUE, $stack[$top]['index']);
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
            throw $this->reject(FormRejected::MALFORMED, $this->current);
        }
        $stack = $this->flush($stack);
        $frame = array_pop($stack);
        if ($frame['name'] === 'legend') {
            $legend = Text::trim($frame['pending']);
            if (!FormRules::isPlainText($legend, 1, FormFormat::MAX_LEGEND)) {
                throw $this->reject(FormRejected::TEXT_VALUE, $frame['index']);
            }
            $stack[count($stack) - 1]['legend'] = $legend;

            return [$stack, $document];
        }
        try {
            $node = self::build($frame);
        } catch (\InvalidArgumentException) {
            // The checks above mirror the constructors; this is a second line only.
            throw $this->reject(FormRejected::ATTRIBUTE_VALUE, $frame['index'], $frame['name'] === 'root' ? 'div' : $frame['name']);
        }
        if ($node instanceof FormDocument) {
            return [$stack, $node];
        }
        $stack[count($stack) - 1]['children'][] = $node;

        return [$stack, $document];
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function build(array $frame): FormDocument|Block|Inline
    {
        $a = $frame['attributes'];
        $children = $frame['children'];

        return match ($frame['name']) {
            'root' => new FormDocument($children),
            'fieldset' => new Section($a['data-tiffin-section'] ?? null, $frame['legend'], $children, self::placement($a)),
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
            default => throw new \InvalidArgumentException('Unknown element.'),
        };
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
