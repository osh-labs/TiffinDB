# M5 design spec: form editor, source editing, sanitizer: core and work packages

Status: design spec for milestone M5, part 1 of 2 (M5-D1), awaiting spec review (M5-R1). This part covers the sanitizer statement, problem reporting, the stored-text rule, the editor model and its operations, the store writes, the binder changes, the core operations and their authorization, the audit actions, the message keys, the test plan, the corpus catalogue, the provisional decisions, and **all M5 work packages**. Part 2, `docs/specs/M5-screens.md`, covers routes, controllers, templates, strings, the editor's no-script behaviour, the keyboard and visual procedures, and the appearance matrix. Screen designs are under `docs/design/m5/`.

Decisions this spec relies on: ADR 0003, ADR 0006, ADR 0008, ADR 0012, ADR 0014, ADR 0018, and the new ADR 0020 (form editing in v1). Format: `docs/formats/tiffin.md` sections 4.6, 12 (12.4 and the new 12.5), and 13.3, already brought to this design; where this spec and that document differ, stop and report. Everything in the M1 to M4 specs, with their as-built notes and provisional decisions P1 to P188, still applies unless a section below changes it. New provisional decisions are P189 to P207 (section 15).

**Designed against the code as built at M4 close** (integration branch at `1571ed8`). Signatures quoted from the tree are marked "(as built)". Session plan: `docs/build/M5-M7-session-plan.md` ("plan N" below is a section of that document). The maintainer has not answered plan section 3; rows 2 to 5 are applied as their defaults, with the one correction of section 7.3 (hidden fields would come back at the next schema change under the as-built rule).

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals

1. Simple form editor for Admins: reorder, show and hide, width, grouping into sections, labels and section names; static elements kept exactly (ADR 0003, ADR 0020).
2. Source view with validation: problems named by line; save stores the canonical text (ADR 0020 decisions 2 and 3).
3. Download and upload of a form as a file; reset to the standard form through a confirmation that offers the download first.
4. The sanitizer (the default-deny parser) hardened and proven by a test corpus, a fixed-point property, and seeded mutation tests.
5. Links in forms shown as real links; widths shown on the record screen.
6. Authorization for every form operation in core (`forms.edit`, Admins only); audit actions `form.saved`, `form.reset`.

### 1.2 Non-goals for M5

- The WYSIWYG editor and explicit `col` and `row` placement (M8, ADR 0006); form CSS (ADR 0020 decision 5); several forms per table; form history; images created by the editor; a form-owned file store.
- A live preview of the form inside the editor (section 16 item 5).
- Any change to form format 1 or project format version 4.

### 1.3 Exit criteria

| Exit criterion (plan 2) | How M5 meets it |
|---|---|
| Editor output equals hand-editable HTML | Stored-text rule (section 5); T-FORM-12 (fixed point on the corpus and 1,000 seeded forms); T-EDT-1 to T-EDT-12 (every editor operation gives a document the serializer writes and the parser accepts); T-RT-9 (editor, source, file, and upload give byte-identical stored text); T-RT-10 (archive round trip) |
| Sanitizer test corpus passes | Corpus of section 12 (T-SAN-1); mutation tests T-SAN-3 (core) and T-SAN-4 (rendered page); no-content-in-logs T-SAN-5; reviewer's own hostile forms (plan 6.5) |
| Editor output renders correctly under every appearance setting | Appearance matrix of part 2 section 10 (V-30); T-WEB-76 (width classes, no inline style) |
| The editor works by keyboard only (plan 5, continuous) | Structure checks T-KBD-4; scripted run K-4 with JavaScript off (part 2 section 9) |
| Security baseline for the new surface (plan 7) | Authorization in core (T-APP-160 to T-APP-175); hidden draft parsed every time (T-APP-163); CSRF and origin (T-WEB-75); download headers (T-WEB-73); hostile requests (T-WEB-77); links only `http` and `https` (T-SAN-1 group L, T-WEB-74) |

## 2. Code layout

New PHP follows ADR 0008 decision 6. New or changed files (owners in section 14):

```
src/Core/Forms/   FormParser, FormRules, FormRejected, FormFormat, StartTags (new)            (WP1)
                  FormEditor, EditorAction, EditorPath, EditorRow, EditorApplied, FormReport,
                  FormWrite (new); FormStore, FormDocument (helpers), Section, FieldSlot (helpers)  (WP2)
                  FormBinder, BoundField, BoundSection, BoundHeading, BoundParagraph,
                  BoundDivider, BoundImage, BoundForm (span and columns)                      (WP3)
src/Core/Schema/  SchemaEditor (hook passes added field keys)                                  (WP2)
src/Core/App/     FormOperations, FormEditorScreen, FormSourceScreen, FormUploadScreen,
                  FormResetScreen, FormSaved, FormFile (new); Operations (forms())             (WP4)
                  Context (hook closure forwards added field keys)                            (WP2)
src/Core/System/  AuditAction (form.saved, form.reset)                                         (WP4)
src/Web/          routes, ViewScope (formLink), Response (attachmentText), RecordController
                  (node conversion only)                                                       (WP3)
                  Controller/FormController (new)                                              (WP5)
                  Controller/FormSourceController (new)                                        (WP6)
```

### 2.1 Dependency rule

Unchanged (ADR 0014 decision 5, M4 section 2.1). `Core\Forms` depends on Schema, Storage, and Error only; it reads no records and no attachments. `Core\App` decides which images are servable (`Attachments::isServable`, M4) and passes plain facts in. The web layer uses `Core\App` result objects, `Core\Error` values, and the value classes `Core\App` returns, which may include `Core\Forms` value classes named in section 9 (`EditorRow`, `FormReport`) and `Core\Schema\FieldDef`, as M2 to M4 already do for `FieldDef`. `tests/repo/CoreLayerRulesTest.php` and `tests/web/WebLayerRulesTest.php` are extended to allow exactly these (WP4 for the core file; WP3 for the web file, see section 14).

### 2.2 SQL rules

`FormStore` keeps every statement a literal with bound values (as built). No SQL elsewhere in `Core\Forms`.

## 3. The sanitizer (`Core\Forms\FormParser`, M5-WP1) (security-critical)

The sanitizer is the parser plus the serializer. There is no other filter, no stripping, and no safe-HTML type (ADR 0020 decision 1). A form text is either **accepted**, giving a `FormDocument` whose every node belongs to form format 1, or **refused** with one `FormRejected`; nothing in between.

### 3.1 Accepted

Exactly the vocabulary and value rules of `docs/formats/tiffin.md` section 12.1 (as built in M2; restated there). Additions in M5, which do not change the format:

1. A UTF-8 byte order mark (`EF BB BF`) as the first three bytes is removed before any other step (editors add it to saved files). Exactly one; a second one, or one anywhere else, is a character U+FEFF and is refused by the text rules (`Text::hasControl`, class `Cf`).
2. Comments anywhere XML allows them; they are not kept (section 5).
3. After the byte steps of section 3.2 (size, encoding, NUL, declarations, instructions) and before `XMLReader` runs, literal line breaks are normalized: CR LF and a CR not followed by LF become LF. This is XML 1.0 end-of-line handling (section 2.11) done early, so the meaning of the text does not change (a `&#xD;` reference is not a literal CR and is untouched); it makes libxml's error lines agree with section 4.2 (libxml counts only LF: a CR-only text otherwise reports every error on line 1) and gives `StartTags` the same text.

### 3.2 Refused, by class

Each class has its reason code (`FormRejected::<CONSTANT>`, string value in brackets). Steps 1 to 3 run on the bytes before any parser; steps 4 onward inside the `XMLReader` walk (as built, M2 spec section 5.2).

| Class | Refused | Reason |
|---|---|---|
| Size | More than 262,144 bytes (after the byte order mark is removed) | `TOO_LARGE` (`too-large`) |
| Encoding | Not valid UTF-8 (overlong forms, surrogates, other encodings, UTF-16 with or without a byte order mark) | `ENCODING` (`encoding`) |
| NUL | Any NUL byte | `NUL` (`nul`) |
| Declarations | `<!` not followed by `--`: DOCTYPE, ENTITY, ELEMENT, ATTLIST, NOTATION, CDATA, conditional sections | `DECLARATION` (`declaration`) |
| Processing instructions | `<?` anywhere, including the XML declaration | `PROCESSING_INSTRUCTION` (`processing-instruction`) |
| Not well-formed | Any libxml error: unclosed or mismatched tags, unquoted or duplicate attributes, `<` or `&` unescaped, undefined entities (`&nbsp;`), a character reference to a character XML forbids (`&#0;`, `&#x1F;`, `&#xFFFE;`) or such a character written literally (U+FFFE), `--` inside a comment, `]]>` in text, a second root element, text after the root, a text of only white space (libxml reports these three itself, before the walk sees them) | `MALFORMED` (`malformed`) |
| Other node types | Any node other than element, end element, text, white space, comment (an entity reference node, which cannot occur after step 4) | `NODE_TYPE` (`node-type`) |
| Root | The empty text, a root other than `div`, a root with any attribute other than `data-tiffin-form` (two roots: `MALFORMED`, above; the walk's own second-root check stays as a second line) | `ROOT` (`root`) |
| Version | `data-tiffin-form` not exactly `1` | `FORMAT_VERSION` (`format-version`) |
| Unknown element | Any element name outside section 12.1 (names are case-sensitive: `P`, `DIV` are unknown), any prefixed or namespaced element (`svg:svg`, `math`, `x:p`), and in particular `script`, `style`, `iframe`, `object`, `embed`, `form`, `input`, `button`, `select`, `textarea`, `template`, `svg`, `math`, `base`, `link`, `meta`, `noscript` | `ELEMENT` (`element`) |
| Misplaced element | A known element where 12.1 does not allow it: a block inside inline content, a `fieldset` inside a `fieldset`, a `legend` other than first in a `fieldset`, an `a` inside an `a`, inline elements in the root or a `fieldset` | `MISPLACED` (`misplaced`) |
| Unknown or missing attribute | Any attribute not listed for the element (`class`, `id`, `style`, `on*` in any case, `xmlns`, `xmlns:*`, `xml:lang`, `xml:space`, `xml:base`, `hidden`, `tabindex`, `role`, `aria-*`, `lang`, `dir`, `title`, `target`, `rel`, `srcset`, `formaction`, an upper-case `DATA-TIFFIN-SPAN`), or a required attribute missing | `ATTRIBUTE` (`attribute`) |
| Attribute value | A value that breaks its rule: role word, field key, label (1 to 60 characters after trimming, plain text), alt (0 to 200), column, row, span | `ATTRIBUTE_VALUE` (`attribute-value`) |
| Link address (new code) | An `href` that is not an `http` or `https` address by the `url` field type's strict rules, given exactly: other schemes in any case or disguise (`javascript:`, `JaVaScRiPt:`, `vbscript:`, `data:`, `file:`, `&#106;avascript:`, `java&#x09;script:`), relative and protocol-relative addresses, white space around or inside, user and password parts, non-ASCII characters, ports out of range | `HREF` (`href`) |
| Image source (new code) | A `src` other than `attachment:` followed by an id 1 to 9223372036854775807 written without leading zeros | `SRC` (`src`) |
| Text | Non-white-space text where only blocks may stand (the root, a `fieldset`) | `TEXT` (`text`) |
| Text value | Text with a control character other than tab and line feed (classes `Cc`, `Cf` except U+200C and U+200D, `Zl`, `Zp`: bidirectional controls, zero-width space, soft hyphen, U+FEFF) or a noncharacter (U+FDD0 to U+FDEF and U+nFFFE, U+nFFFF of every plane; new in M5, see below); an empty legend or one over 100 characters. The same character rules apply to labels and alt text (`ATTRIBUTE_VALUE`) | `TEXT_VALUE` (`text-value`) |
| Content | Content inside an element that must be empty (`hr`, `img`, field `div`, `br`) or elements inside a `legend` | `CONTENT` (`content`) |
| Duplicate field | Two field slots with the same key anywhere in the form | `DUPLICATE_FIELD` (`duplicate-field`) |
| Limits | More than 5,000 elements; more than 8 levels | `TOO_MANY_ELEMENTS` (`too-many-elements`), `TOO_DEEP` (`too-deep`) |

`HREF` and `SRC` are split out of `ATTRIBUTE_VALUE` so the source view can explain the address rules and so mutation of the link rule is caught by its own test.

Noncharacters (new rule, `FormRules`, WP1): the parser as built accepts the noncharacters U+FDD0 to U+FDEF and the last two code points of planes 1 to 16 (only U+FFFE and U+FFFF are refused, by libxml; checked on the tree at review). The page escaper `ViewScope::e()` uses `ENT_DISALLOWED` and turns every noncharacter into U+FFFD, so such a form would come back from the source view's `textarea` or the editor's hidden draft with different bytes, and a save would change text the Admin never touched (T-RT-9 and the draft round trip would fail). `FormRules`' character check (`NOT_XML`, used by `isPlainText` and `isRunText`) is widened to every noncharacter. A stored form holding one, which only a hand-edited file can contain, is then refused and shown with the generated layout, as any other refused form. Nothing else in the parser's rules changes. A field key that is valid but not a field of the table is **accepted** (format 12.4); the report of section 10 lists it.

### 3.3 Why this holds

- No parser runs on text holding a declaration or a processing instruction, so no DTD, entity, or external resource can be read or expanded; `XMLReader` also runs with `LIBXML_NONET` and without DTD loading, validation, default attributes, or entity substitution (as built).
- The page is built from nodes, never from text. Every attribute value that reaches a page is either fixed by a template or comes from a node field whose rule allows no markup: a field key, a role word, a plain-text label or legend or alt, a span from a closed list, an attachment id, or an address that passed the `url` rule (re-checked by the template helper, part 2 section 6.3).
- The differential cases (texts an HTML parser and an XML parser read differently, corpus group D) can at worst change which nodes are built, never let a text reach a page as markup, because no text is ever printed except through escaping.

## 4. Problem reporting (M5-WP1)

### 4.1 `FormRejected` (changed)

```php
final class FormRejected extends \RuntimeException
{
    // existing constants, plus:
    public const HREF = 'href';
    public const SRC = 'src';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $line = null,
        public readonly ?string $element = null,
        public readonly ?string $attribute = null,
    );

    /** 'problem.form.' . the reason with '-' replaced by '_', plus '_unnamed' when the reason needs a name that is null (section 4.3). */
    public function messageKey(): string;

    /** ['line' => line] when known, plus 'element' and 'attribute' when set. Never any other text of the form. */
    public function params(): array;
}
```

The exception message stays the constant `The form does not follow form format 1.` (as built). `$element` and `$attribute` are set only when the name matches `^[A-Za-z_][A-Za-z0-9_.:-]{0,39}\z`; any other name (longer, or holding other characters) is passed as null. These two names are the only pieces of a refused form that may reach a page, always escaped; they never reach a log (T-SAN-5).

### 4.2 Lines

A line number is 1 plus the number of line breaks before the problem's offset, where a line break is a line feed, a carriage return not followed by a line feed, or a carriage return and line feed together (counted once). The removed byte order mark does not shift lines.

| Reasons | Line |
|---|---|
| `TOO_LARGE`; empty text (`ROOT`) | null |
| `ENCODING` | The line of the first byte that is not part of a valid UTF-8 sequence: the offset is the length of the longest valid prefix, found with `preg_match('/\A(?:[\x00-\x7F]\|[\xC2-\xDF][\x80-\xBF]\|\xE0[\xA0-\xBF][\x80-\xBF]\|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}\|\xED[\x80-\x9F][\x80-\xBF]\|\xF0[\x90-\xBF][\x80-\xBF]{2}\|[\xF1-\xF3][\x80-\xBF]{3}\|\xF4[\x80-\x8F][\x80-\xBF]{2})*/', ...)`; the lines before it are counted on that prefix only |
| `NUL`, `DECLARATION`, `PROCESSING_INSTRUCTION` | The line of the first match |
| `MALFORMED` | The line of the first libxml error (`LibXMLError::line`) when it is 1 or more, else null. Raised by the walk itself (a duplicate attribute name seen by `attributes()`, an end tag with nothing open): the line of the current element's start tag |
| Element reasons (`FORMAT_VERSION`, `ELEMENT`, `MISPLACED`, `ATTRIBUTE`, `ATTRIBUTE_VALUE`, `HREF`, `SRC`, `CONTENT` for an element in a `legend` or empty element, `DUPLICATE_FIELD`, `TOO_MANY_ELEMENTS`, `TOO_DEEP`) | The line of that element's start tag (`<`) |
| `TEXT`, `TEXT_VALUE`, `CONTENT` for text | The line of the start tag of the innermost element open when the text was read (for text after the root closes: the root's start tag line). This is an approximation (XMLReader gives no position for text) and is documented as such on the page ("near line") |
| `NODE_TYPE` | The line of the most recent element start tag, or null before the root |

Start-tag offsets come from `StartTags::offsets(string $text): list<int>` (new, `@internal`): a lexical scan that records the offset of every `<` that starts a start tag, skipping end tags (`</`) and comments (from `<!--` to the next `-->`; an unterminated comment ends the scan). The walk's element counter (1 for the root) indexes this list. This is exact while the text is well-formed up to that element, which is the only case in which the walk reports an element reason (a malformed prefix gives `MALFORMED` from libxml first). The list is built only when a rejection needs a line (no cost for accepted forms). Lines are counted on the normalized text of section 3.1 item 3, which has the same line breaks as the original.

`XMLReader` reads ahead: on a short text libxml may report a well-formedness error further on before the walk reaches an earlier element rule break, so a text that breaks two rules may be refused with either. Corpus cases therefore break one rule each (T-SAN-2), and the line is the line of the reason given.

### 4.3 Message keys and parameters

English text in `strings/en/problems.php` (owned by M5-WP4), exactly. Strings hold no `<` or `>` and no address (`TemplateRulesTest::testStringsArePlainText`), so element names are written without angle brackets. `{line}` is never inside these texts; the source view prints "Line {line}: " (or "Near line {line}: " for the text reasons) before the message (part 2 section 7).

| Key | Text |
|---|---|
| `problem.form.too_large` | The form is larger than {max} bytes. |
| `problem.form.encoding` | The text is not in UTF-8. Save the file as UTF-8 and try again. |
| `problem.form.nul` | The text holds a NUL character, which forms cannot hold. |
| `problem.form.declaration` | Forms cannot hold DOCTYPE, ENTITY, or CDATA sections. Remove that part. |
| `problem.form.processing_instruction` | Forms cannot hold processing instructions, including an XML declaration as the first line. Remove it. |
| `problem.form.malformed` | The text is not well-formed: check for a missing end tag or quote, and write a less-than sign as &lt; and an ampersand as &amp;. |
| `problem.form.node_type` | The text holds something forms cannot hold. |
| `problem.form.root` | A form is one div element with data-tiffin-form="1", with everything inside it. |
| `problem.form.format_version` | This form is not form format 1. The first element must be a div with data-tiffin-form="1". |
| `problem.form.element` | Forms cannot hold the element {element}. |
| `problem.form.element_unnamed` | The text holds an element that forms cannot hold. |
| `problem.form.misplaced` | The element {element} cannot stand here. |
| `problem.form.misplaced_unnamed` | An element stands where it is not allowed. |
| `problem.form.attribute` | The element {element} cannot have the attribute {attribute}, or is missing one it needs. |
| `problem.form.attribute_unnamed` | An element has an attribute that forms cannot hold, or is missing one it needs. |
| `problem.form.attribute_value` | The value of {attribute} on {element} is not allowed. |
| `problem.form.href` | Links must be full web addresses that start with http:// or https://, with no spaces. |
| `problem.form.src` | Pictures must name a file of this project: src="attachment:" followed by its number. |
| `problem.form.text` | Text must be inside a heading, a paragraph, or a legend. |
| `problem.form.text_value` | The text holds a control character, or a legend is empty or longer than 100 characters. |
| `problem.form.content` | This element must be empty. |
| `problem.form.duplicate_field` | A field appears twice. Each field can appear once. |
| `problem.form.too_many_elements` | The form has more than {max} elements. |
| `problem.form.too_deep` | The form is nested more than {max} levels deep. |
| `problem.form.draft` | The form on this page could not be read, so the saved form is shown again. Your unsaved changes were not kept. |
| `problem.form.changed` | Someone saved this form after you opened it. Save again to replace their version with yours. |
| `problem.form.label` | Use 1 to {max} characters, with no line breaks, or leave it empty to use the field's name. |
| `problem.form.legend` | Use 1 to {max} characters, with no line breaks, or leave it empty for no name. |
| `problem.form.action` | That change could not be made. The form is shown as it was. |
| `problem.form.empty_file` | That file is empty. Choose a form file. |

`{max}` is passed by the core (`FormFormat::MAX_BYTES`, `MAX_ELEMENTS`, `MAX_DEPTH`, `MAX_LABEL`, `MAX_LEGEND`). The `_unnamed` variants exist only for `element`, `misplaced`, and `attribute`. `problem.upload.*` keys of M3 and M4 are reused for upload errors (`missing`, `failed`, `too_large`).

## 5. Stored-text rule (M5-WP1 and WP2)

Every write of a form by generation (as built), `FormStore::saveDefault`, `FormStore::resetDefault`, and `regenerate` when it changes an edited form (as built) stores `FormSerializer::serialize(FormParser::parse($text))` or the serialization of a document built in code. Documented as `docs/formats/tiffin.md` section 12.5.

Exception, as built and unchanged in M5: the archive rebuild (`ProjectRebuilder::copyForm`) inserts an accepted form's text exactly as the archive holds it, and `regenerate` rewrites it only when the adjustment changes the document. An archive made by TiffinDB from M5 holds canonical text, so the round trip (T-RT-10) is byte for byte; a hand-edited archive may leave non-canonical text stored until the next save. Nothing relies on stored text being canonical: the save token (section 7.1) hashes whatever is stored, and every reader parses. Making the rebuild canonical would also need `forms_extended` to compare canonical texts; it is left out of M5 (section 16 item 8).

Properties, both tested (T-FORM-12):

1. For every text `t` the parser accepts, with `s = serialize(parse(t))` and `strlen(s) <= FormFormat::MAX_BYTES`: `parse(s)` succeeds and `serialize(parse(s)) === s` (fixed point after one round).

The canonical text can be longer than the text it came from: a literal `'` or `"` in text becomes a six-byte reference, `<p/>` becomes `<p></p>`, and indentation is added. A text accepted at 262,144 bytes can therefore have a canonical text the parser refuses as too large. Every save path (editor, source, upload) checks `strlen(serialize(document)) <= MAX_BYTES` before storing and otherwise refuses with `problem.form.too_large` (`max`), storing nothing (sections 9.2 step 5, 9.5, 9.1 `upload`); the source view's Check reports the same problem. `FormStore::saveDefault` throws `\LengthException` for an over-long document as a second line. Corpus case Z9 pins this.
2. For every document `d` built in code: `parse(serialize(d)) == d` (as built, T-FORM-3).

The serializer is unchanged unless WP1 finds a case that breaks property 1, in which case it is fixed in the serializer and the case joins the corpus (group F). Known points that hold as built and are pinned by corpus cases: labels and alt text are trimmed by the parser (`" Title "` is stored `Title`); `<p/>` is stored `<p></p>`; `<div .../>` is stored `<div ...></div>`; `&#233;` is stored as the character; `&apos;` stays `&apos;`; a literal `>` in text is stored `&gt;`; tab and line feed inside inline text are kept as they are; a carriage return in text, which XML turns into a line feed, is stored as a line feed.

## 6. Editor model (`Core\Forms`, M5-WP2)

Pure functions over `FormDocument`; no database, no clock. `Core\App` (WP4) wraps them.

### 6.1 Rows and paths

A form is shown to the editor as a flat list of rows in reading order. A **path** names a block: `i` for the i-th block of the root, `i-j` for the j-th child of the section at root position i, both 1-based (`EditorPath`, pattern `^[1-9][0-9]{0,3}(?:-[1-9][0-9]{0,3})?\z`; a path that names no block is "not found").

```php
final class EditorRow
{
    public function __construct(
        public readonly string $path,
        public readonly string $kind,          // 'section', 'field', 'missing' (slot naming no field of the table), 'heading', 'paragraph', 'divider', 'image'
        public readonly int $depth,            // 0 in the root, 1 in a section
        public readonly ?FieldDef $field,      // for 'field'
        public readonly ?string $fieldKey,     // for 'field' and 'missing'
        public readonly ?string $label,        // the slot's own label, for 'field'
        public readonly ?string $legend,       // for 'section'
        public readonly string $width,         // 'auto', 'full', 'two-thirds', 'half', 'one-third', or 'other' (a span the editor does not offer)
        public readonly ?string $span,         // the stored span, for 'other'
        public readonly bool $placed,          // carries data-tiffin-col or data-tiffin-row (kept, shown in reading order)
        public readonly string $summary,       // plain text: heading or paragraph text (text runs joined, br as a space, cut to 80 characters with an ellipsis), image alt, '' otherwise
        public readonly ?int $attachmentId,    // for 'image'
        public readonly int $children,         // for 'section': number of child rows
        public readonly bool $canMoveUp,
        public readonly bool $canMoveDown,
    );
}
```

`FormEditor::rows(FormDocument $document, list<FieldDef> $fields): list<EditorRow>` (fields: `Schema::fields($tableKey)`).

Width tokens map to spans: `auto` no span, `full` `full`, `two-thirds` `8`, `half` `6`, `one-third` `4`; any other span gives `other`. Moving is allowed when the move of section 6.3 would change the document.

### 6.2 Input applied on every request (`FormEditor::apply`)

```php
public function apply(FormDocument $document, list<FieldDef> $fields, array $labels, array $legends, array $widths): EditorApplied
// EditorApplied { FormDocument $document; list<Problem> $problems; array<string, string> $typed }
```

The arrays come from the posted `label[<path>]`, `legend[<path>]`, `width[<path>]` (part 2 section 5.2), keyed by paths of the posted draft. Entries whose key is not a path of the document, whose path names a block of the wrong kind, or whose value is not a string are ignored. Then, for each remaining entry, in document order:

| Input | Rule |
|---|---|
| `labels[path]` on a field row | Trimmed. `''`, or exactly the field's name: the slot has no label. Otherwise plain text of 1 to 60 characters (`FormRules::isPlainText`): the slot's label. Otherwise a problem `Problem('label-' . path, 'problem.form.label', ['max' => 60])`, the slot keeps its label, and `typed[label-path]` holds the trimmed text so the page can show it |
| `legends[path]` on a section row | Trimmed. `''`: no legend. Plain text of 1 to 100: the legend. Otherwise `Problem('legend-' . path, 'problem.form.legend', ['max' => 100])` and `typed[legend-path]` |
| `widths[path]` on a field row | One of the five tokens: the span becomes that token's span, `data-tiffin-col` and `data-tiffin-row` kept (a placement with nothing left becomes null). `other` (only offered when the stored span is not one of the five): unchanged. Any other value: ignored |

The values of every input are applied before the action; paths are those of the posted draft.

### 6.3 Actions (`FormEditor::act`)

```php
public function act(FormDocument $document, EditorAction $action, list<FieldDef> $fields): FormDocument
// \DomainException when the action does not fit the document (Core\App turns it into problem.form.action)
```

`EditorAction::parse(string $value): ?EditorAction` reads the posted `do` value: `apply`, `save`, `add-section`, `add-<field key>` (a valid key), or `<verb>-<path>` with verb `up`, `down`, `hide`, `remove`, `ungroup`. Anything else is null (treated as `apply`).

| Action | On | Result |
|---|---|---|
| `apply` | | Nothing more (inputs are applied) |
| `up` | A section | Swaps with the previous root block |
| `up` | A root block that is not a section | If the previous root block is a section: moves to the end of that section. Otherwise swaps with the previous root block |
| `up` | A section child | First child: moves out of the section to the root, just before it. Otherwise swaps with the previous child |
| `down` | Mirror of `up`: a root block followed by a section moves to the start of that section; a last section child moves out to the root just after its section |
| `hide` | A field row | Removes the slot |
| `remove` | A `missing`, heading, paragraph, divider, or image row | Removes the block |
| `ungroup` | A section | Replaces the section, in place, by its children (its legend, role, and placement are lost) |
| `add-<key>` | A field of the table with no slot | Appends `FieldSlot(key)` at the end of the first section, or of the root when there is none |
| `add-section` | | Appends `Section(null, 'New section', [])` at the end of the root. The legend is English data, like `Details` (P45); the person renames it |
| `save` | | Nothing more here; `Core\App` saves the result |

An action whose path names nothing, or a block of the wrong kind, or that would not change the document (moving the first row up), or that would break a document rule (5,000 elements, depth), throws `\DomainException`. Every action keeps every other block exactly: static blocks, their inline content, placements, roles, and labels are carried as the same objects (T-EDT-2).

`FormEditor::focusAfter(FormDocument $before, EditorAction $action, FormDocument $after): ?array{path: ?string, control: string}` gives the row and control that carry `autofocus` on the returned page (`docs/design/m5/editor.md` section 4), with `control` one of `up`, `down`, `first` (the row's first action button shown), `legend` (a section's name box), or `add-section` (the "Add a section" button, `path` null):

| Action | Focus |
|---|---|
| `up`, `down` | The moved row's new path; the same move control, or the other one when the row reached an edge |
| `hide`, `remove` | The row that now stands at the removed row's place in reading order (the next row, which may be the next row after the section's end), `first`; `add-section` when no row follows |
| `ungroup` | The first former child at its new path, `first`; when the section was empty, as `remove` |
| `add-<key>` | The new slot's path, `up` (`first` when it is the only row) |
| `add-section` | The new section's path, `legend` |
| `apply`, `save` | null |

### 6.4 Report (`FormReport`)

```php
final class FormReport
{
    /** @param list<string> $unknownKeys @param list<FieldDef> $notOnForm @param list<FieldDef> $requiredNotOnForm @param list<int> $images @param int $emptyLinks */
    public static function of(FormDocument $document, list<FieldDef> $fields): self;
    public function withImagesNotShown(list<int> $ids): self;  // Core\App adds the images that cannot be shown (section 9.3)
    // public readonly: unknownKeys (slot keys that are not fields of the table, document order),
    // notOnForm (fields with no slot, field order), requiredNotOnForm (those that are required),
    // images (attachment ids of every img, document order), imagesNotShown (subset), emptyLinks (a elements with no text)
}
```

A field slot naming a field of another table counts as an unknown key. "No text" for a link: no text run with a character other than white space in it or below it.

## 7. Store writes (`Core\Forms\FormStore`, M5-WP2)

### 7.1 New methods

| Method | Does |
|---|---|
| `saveDefault(Schema $schema, string $tableKey, FormDocument $document): FormWrite` | Inside the caller's write (`\LogicException` otherwise). `$schema->table($tableKey)` must exist. `html = serialize(document)`; `\LengthException` when `html` is longer than `FormFormat::MAX_BYTES` (section 5; callers check first); `generated = html === serialize(generator->generate(schema, tableKey))`. No default row: inserts one (new key, `Standard form`, format 1, css `''`, is_default 1, times now). A row: updates `format` to 1, `html`, `generated`, `updated_at` only when `html` or `generated` differ from the row (else nothing written); `name`, `css`, `created_at`, `is_default` untouched. Returns `FormWrite { string $key; string $html; bool $generated; bool $changed }` |
| `resetDefault(Schema $schema, string $tableKey): FormWrite` | As `saveDefault` with the generated document; `generated` is 1 |
| `baseOf(?StoredForm $stored): string` (static) | `hash('sha256', $stored === null ? '' : $stored->html)`, the save token (ADR 0020 decision 12) |
| `regenerate(Schema $schema, array $tableKeys, array $addedFieldKeys = [])` (changed) | As built, except that an edited form (`generated` 0) gains a slot only for each field of the table whose key is in `$addedFieldKeys` and which no slot names, in field order, at the end of the first section (or the root). It still loses slots for keys that are not fields of the table |

`defaultFor`, `effective`, and the key-scope check are unchanged (P182's collection keys included as built).

### 7.2 The schema hook (`Core\Schema\SchemaEditor`, `Core\App\Context`)

`SchemaEditor`'s `$afterChange` closure becomes `\Closure(Database, list<string> $tableKeys, list<string> $addedFieldKeys): void`. `addField` passes `[$field->key]`; `addRelation` passes the new field's key and the reverse field's key; every other method passes `[]`. `Context::editor` forwards the third argument to `FormStore::regenerate`. A closure written for two arguments still works (PHP passes the extra argument), so the change is safe to merge before `Context` changes; both are in WP2.

### 7.3 Archive rebuild (amends M3 spec section 6 step 6; ADR 0020 decision 9)

`ProjectRebuilder::copyForms` keeps calling `regenerate($schema, <every table key>)` with no added keys (code unchanged). An edited default form therefore loses slots whose key is not a field of its table and gains none. `forms_extended` keeps its name and JSON member and counts edited default forms whose text changed. `strings/en/archive.php` `archive.change.forms_extended` becomes `{count|# edited form|# edited forms} lost fields that no longer exist`. `tests/core/io/archive/RebuildFormsTest.php::testFormsAreCheckedAndRegenerated` changes in four places, all spec changes (checked at review by disabling the slot append in a scratch copy: these and the two `FormStoreTest` cases of T-EDT-10 are the only failures in the whole suite): the two `forms_extended` expectations (`$report->formsExtended` and the import-log member) follow the new meaning (the fixture's edited Rooms form omits `roomName` and names no missing field, so 0; WP2 adds a slot for a deleted field to the fixture's form to keep a case that gives 1); the assertion that the edited form gains `roomName`'s slot becomes the assertion that it does not (the field stays hidden); and the closing part, which asserts that the imported record form shows the required `roomName` and refuses a record with it empty, is reversed: the field is not in `editableKeys()`, and a record created through the form without it is accepted (P118 and P157: requiredness applies only to fields the form shows). This reverses the M3 behaviour that made a required field reappear on import; it is the intended consequence of P197, and the editor's "Required. While the form does not show it, records can be saved without it." note is how the Admin learns of it.

Ruled at review (P208): accepted. This plainly reverses the M3 import behaviour: an edited default form no longer regains a hidden field on import, a required one included, and records can then be saved without that field (P118, P157). WP2 owns the changed tests: `RebuildFormsTest` as above and the two `FormStoreTest` cases of T-EDT-10.

Reason for the change: under the as-built rule, a field an Admin hides would reappear at the next schema change of its table and at every archive import, so "an edited form survives archive export and import" (plan 6.3 WP7) could not hold.

## 8. Binder (`Core\Forms`, M5-WP3)

- `BoundField`, `BoundSection`, `BoundHeading`, `BoundParagraph`, `BoundDivider`, `BoundImage` gain `public readonly ?string $span` (the block's `data-tiffin-span`, or null) as their last constructor argument with default null. `spanFull` stays (as built: `span === 'full'`).
- `BoundSection` and `BoundForm` gain `public readonly bool $columns`: true when at least one of its bound child nodes has a span of `1` to `12`. A section with `columns` true is not laid out as the `details` auto-fit grid even when its role is `details` (its `grid` stays as built; the template chooses columns first).
- Inline content stays the node model's own `Inline` objects (as built); the web layer reads `Link::$href` (part 2 section 6.3).
- Nothing else in the binder changes. Slots naming unknown keys are still dropped (as built).

## 9. Core operations (`Core\App\FormOperations`, M5-WP4) (security-critical: authorization boundary)

`Operations` gains `forms(): FormOperations` (the constructor keeps its arguments, P78). Every method takes `Actor $actor` first and begins with `Context::project($actor, Permission::EditForms, $projectKey)`: an ungranted person, an unknown project, and an inactive project are `NotFound`; a granted Member is `AccessDenied` (`forms.edit` is Admin-only, as built). The project is then opened with `ProjectOpener` exactly as `CollectionOperations` does. A `tableKey` that is not a key of a table of the project is `NotFound`. Nothing is read from the form before the permission check.

### 9.1 Methods

| Method (after `Actor, string $projectKey, string $tableKey`) | Returns or does |
|---|---|
| `editor(?array $input = null)` | `FormEditorScreen`. With `$input` null: the stored default form parsed (`state` `stored`), or the generated form when there is no row (`none`), its format is not 1, or it is refused (`refused`, with `storedProblem`). With `$input`: section 9.2. A `do` of `save` saves (section 9.4); the screen then carries `saved` |
| `source(?array $input = null)` | `FormSourceScreen`. With `$input` null: `text` is the stored text exactly as stored (also when refused, so the Admin can fix it; `storedProblem` set), or the generated text when there is no row. With `$input` (`text`, `base`, `do` = `check` or `save`): section 9.5 |
| `uploadScreen()` | `FormUploadScreen { ProjectRecord $project; TableDef $table; int $maxBytes }` (`FormFormat::MAX_BYTES`) |
| `upload(string $uploadPath)` | `FormSaved`. `Fs::size($uploadPath)` first: 0 is `problem.form.empty_file`, over `MAX_BYTES` is `problem.form.too_large` (`max`), both `ValidationFailed` with field `file`, before anything is read; then the bytes are read (at most `MAX_BYTES` + 1) and saved as in section 9.5 without a base check, because an upload is a deliberate replacement of the whole form (P211) (the canonical-size refusal and the `rewritten` line-break rule included); a `FormRejected` becomes `ValidationFailed([Problem('file', $e->messageKey(), $e->params())])`. Audit source `upload` |
| `download()` | `FormFile { string $fileName; string $text }`: the stored `html` exactly (whatever it holds), or the generated text when there is no row; `fileName` is the table's name followed by ` form.html` (the web layer cleans it, M4 P181). Never writes; not audited |
| `resetScreen()` | `FormResetScreen { ProjectRecord $project; TableDef $table; bool $isStandard }` (`isStandard`: a row exists with `generated` 1 and the generated text) |
| `reset()` | `bool` changed. One `Database::write()`: `FormStore::resetDefault`, then `ProjectFile::touch` when changed. Audit `form.reset` when changed |

### 9.2 `editor($input)`

`$input` keys (strings unless said): `draft`, `base`, `do`, and the arrays `label`, `legend`, `width`. Non-string `draft`, `base`, or `do` are read as `''`; non-array arrays as `[]`.

1. `draft` is parsed with `FormParser::parse`. On `FormRejected` (a tampered or broken draft) the screen is built from the stored form as for `$input` null, with `problems` `[Problem(null, 'problem.form.draft')]` and `draftRefused` true; nothing else runs. The draft is never trusted and never stored without this parse.
2. `FormEditor::apply` with the table's fields.
3. When `apply` gave problems: the action is not performed (paths would no longer match the typed text); the screen shows the problems.
4. Otherwise `EditorAction::parse($do)`; `act`; a `\DomainException` gives `problems` `[Problem(null, 'problem.form.action')]` and the document after step 2.
5. Size: when the serialization of the document after step 4 (or after step 2, when step 3 or 4 stopped) is longer than `FormFormat::MAX_BYTES`, the screen carries `problems` `[Problem(null, 'problem.form.too_large', ['max' => MAX_BYTES])]` and the document of step 1; nothing is saved. A form near the limit (written in the source view) grows when labels, sections, or slots are added, so without this check the next request would refuse the draft and drop the Admin's work, and a save would store a form the record screen refuses. `FormStore::saveDefault` also throws `\LengthException` for such a document (a second line; WP2).
6. For `save` (when no step above gave problems), section 9.4.
7. The screen: rows of the resulting document, its canonical text as the new `draft`, `base` as posted (or the current one after a conflict, 9.4), the report, focus.

```php
final class FormEditorScreen
{
    // ProjectRecord $project; TableDef $table;
    // list<EditorRow> $rows; FormReport $report;
    // string $draft (canonical text of the working copy); string $base;
    // string $state ('stored', 'none', 'refused'); ?Problem $storedProblem (the stored form's FormRejected as a Problem, when refused);
    // bool $isStandard (draft equals the generated text);
    // list<Problem> $problems; array<string, string> $typed; bool $draftRefused;
    // ?array{path: string, control: string} $focus; ?FormSaved $saved
}
final class FormSaved { public function __construct(public readonly string $tableKey, public readonly bool $generated, public readonly bool $changed, public readonly bool $rewritten) {} }
```

`rewritten` (source and upload only): the stored text differs from the text as typed or uploaded.

### 9.3 Images in the report

For `editor`, `source` (after a check or a save), and `upload`, `Core\App` takes `FormReport::of($document, $fields)` and adds as `imagesNotShown` each id of `images` for which `Attachments::isServable($id)` is false or the stored media type is not one of the four inline types (the M4 rule of P173).

### 9.4 Saving (editor, source)

One `Database::write()`:

1. Read the default row; `current = FormStore::baseOf($row)`. If `current !== $base` (`hash_equals`): no write; the screen carries `problems` `[Problem(null, 'problem.form.changed')]` and `base` = `current`, so the next save overwrites. Upload has no base and skips this step.
2. `FormStore::saveDefault($schema, $tableKey, $document)`.
3. When changed: `ProjectFile::touch($db, $actor->userKey, clock)`.

After the commit, when changed: audit `form.saved` (section 9.6). An editor save with no change still returns `saved` (`changed` false) and the page says nothing changed.

### 9.5 `source($input)`

`text` (string, else `''`), `base`, `do` (`check` or `save`, else `check`).

- Parse `text`. On `FormRejected`: `problems` `[Problem('source', $e->messageKey(), $e->params())]`, `text` kept as typed (the template escapes it), nothing stored.
- Accepted, but the canonical text is over `MAX_BYTES` (section 5): as a refusal, with `Problem('source', 'problem.form.too_large', ['max' => MAX_BYTES])`.
- Accepted, `check`: the report; `problems` empty; nothing stored.
- Accepted, `save`: section 9.4 with the parsed document; `saved.rewritten` = `serialize(document) !== t`, where `t` is the posted text with CR LF and lone CR replaced by LF (browsers send a `textarea`'s line breaks as CR LF, so comparing the raw text would call every browser save a rewrite); the screen's `text` becomes the stored text.

Texts over `MAX_BYTES` are refused by the parser (`too-large`); the web layer also refuses a request body over its limit (part 2 section 5.3).

```php
final class FormSourceScreen
{
    // ProjectRecord $project; TableDef $table;
    // string $text (the text area's content); string $base;
    // string $state ('stored', 'none', 'refused'); ?Problem $storedProblem (the stored text's refusal, field 'source', with its line);
    // list<Problem> $problems (field 'source'; params from FormRejected::params());
    // ?FormReport $report (after a check or save that was accepted); ?FormSaved $saved
}
```

Whether the source view writes "Line" or "Near line" is decided by the web layer from the message key (`problem.form.text`, `problem.form.text_value`, `problem.form.content`, `problem.form.node_type` are "near"); the core passes only the key and its parameters.

### 9.6 Audit actions (amends ADR 0012 decision 4)

`AuditAction` gains `FORM_SAVED = 'form.saved'` and `FORM_RESET = 'form.reset'` (in `ALL`, so `AuditTrailTest` requires each to be written; the constants land with WP4). Target kind `form`, key the form row's key. Detail: `form.saved` `{table: <table key>, source: 'editor'|'source'|'upload', generated: 0|1}`; `form.reset` `{table: <table key>}`. No entry holds form text, a label, a legend, or a file name. Written after the commit (ADR 0012 decision 4).

### 9.7 Permissions

| Operation | Admin | Granted Member | Ungranted |
|---|---|---|---|
| Every `FormOperations` method | yes | access denied | not found |
| Record screen (links, widths) | as M4 | as M4 | not found |

## 10. Message keys produced by the core (new)

Section 4.3, in `strings/en/problems.php` (WP4). `tests/core/app/MessageKeysTest.php` (WP4) reads this section as it reads M4 section 11, and gains one assertion: for every `FormRejected` reason constant, `messageKey()` and, for `element`, `misplaced`, `attribute`, the `_unnamed` variant, are listed here and in `problems.php`. `tests/web/StringsTest.php` is extended by WP3 for the keys its partials print.

## 11. Test plan

All tests offline and deterministic with `TempDir`, `FixedClock`, `SeededRandom`; synthetic data only. `tests/Support/FormFixture.php` (WP1, `FormFixture::SEED = 20261123`) generates valid `FormDocument`s: 1 to 6 sections and 0 to 4 root blocks, each with 0 to 12 children drawn from every block kind, inline content up to 3 levels from every inline kind, placements from every allowed value, labels and legends from a synthetic word list ("Shelf", "Example label", accented and non-Latin letters, `&`, `<`, quotes), field keys from `Keys` with `SeededRandom`, links from a list of `https://example.com/...`, `http://192.0.2.10:8080/...` addresses. `tests/Support/M5Fixture.php` (WP7, building on `M4Fixture`) adds an edited Items form (section 13 of part 2) to "Home Inventory".

### 11.1 Sanitizer (`tests/core/forms/`, WP1)

| ID | Test | Asserts |
|---|---|---|
| T-SAN-1 | `SanitizerCorpusTest` | Every case of section 12: verdict, reason, line, and names; every accepted case's canonical text (where the catalogue gives one) |
| T-SAN-2 | `SanitizerCorpusTest` | The corpus covers every reason constant at least once and every class of plan 6.2 row 12 (a list of required case ids); for each parser rule (each row of section 3.2 and each value rule) at least one refused case breaks only that rule, so disabling any one rule fails T-SAN-1 (the plan 6.5 mutation property; the reviewer confirms it by editing each rule out in a scratch copy) |
| T-SAN-3 | `FormMutationTest` | Seed 20261124, 2,000 mutations of serialized `FormFixture` documents (insert a hostile element, attribute, character reference, scheme, or byte; delete or duplicate a `<`, `>`, `"`, or tag; swap two bytes; change a name's case): each is refused, or accepted with property 5.1 holding, every `Link::$href` passing `FormRules::isHref` and starting `http://` or `https://` (ASCII case-insensitive), and no `TextRun` holding a character `Text::hasControl` refuses (other than tab and line feed) |
| T-SAN-5 | `FormRejectionPrivacyTest` | For every refused corpus case holding the marker `ZQX-MARKER`: the exception message, `messageKey()`, and every parameter other than `element` and `attribute` lack the marker; `FormStore::effective` on a stored refused form writes one log line (captured through `ini_set('error_log', <temp file>)`) holding only `reason=` and `table_key=`, and no marker; `element` and `attribute` are null whenever the offending name breaks the name pattern |
| T-SAN-6 | `FormParserTest` (extended) | Line rules of section 4.2 for each reason group, with CR, LF, and CRLF texts; a leading byte order mark is removed and does not shift lines; `StartTags::offsets` skips comments holding `<` and end tags |
| T-FORM-12 | `FormSerializerTest` (extended) | Property 5.1 for every accepted corpus case and for 1,000 `FormFixture` documents (seed above), and property 5.2 for the same 1,000 |

T-SAN-4 is the rendered-page mutation test (WP7, section 11.6).

### 11.2 Editor model and store (`tests/core/forms/`, WP2)

| ID | Test | Asserts |
|---|---|---|
| T-EDT-1 | `FormEditorTest` | `rows` for a generated form, an edited form with every block kind, a form with `missing` slots, `other` spans, placed blocks; paths, depths, summaries (80-character cut, `br` as a space), `canMoveUp`/`canMoveDown` |
| T-EDT-2 | `FormEditorTest` | Every action of 6.3 on every applicable row of a `FormFixture` form (seed 20261125, 200 forms): the result serializes, parses, and is a fixed point; every block not named by the action is the same object, in the same relative order |
| T-EDT-3 | `FormEditorTest` | Up and down across section boundaries in both directions (out of a section, into the previous or next section, swapping sections) |
| T-EDT-4 | `FormEditorTest` | `apply`: labels trimmed, empty and equal-to-name clear, 60 and 61 characters, control characters, bidirectional controls refused with `typed` kept; legends 100 and 101; widths for each token, `other` unchanged, col and row kept, unknown token and wrong-kind paths ignored, non-string values ignored |
| T-EDT-5 | `FormEditorTest` | `act` throws `\DomainException` for an unknown path, a wrong kind (hide on a heading, ungroup on a field), a no-op move, adding a field that has a slot or is not of the table, and a result over 5,000 elements |
| T-EDT-6 | `FormEditorTest` | `focusAfter` for moves within, into, and out of sections, and at the edges; every other row of the `focusAfter` table (hide and remove of a last row, of the last child of a section, of the only row; ungroup of an empty section; add; add-section) |
| T-EDT-7 | `FormReportTest` | Unknown keys (including another table's field), not on form, required not on form, images, empty links |
| T-EDT-8 | `FormStoreTest` (extended) | `saveDefault`: inserts for a table with no row; `\LengthException` for a document whose text is over `MAX_BYTES`, nothing written; updates only `format`, `html`, `generated`, `updated_at`; keeps `css` and `name`; no write when nothing differs; `generated` 1 when the saved text equals the generated one, else 0; refuses outside a transaction |
| T-EDT-9 | `FormStoreTest` (extended) | `resetDefault` sets the generated text and `generated` 1; `baseOf` |
| T-EDT-10 | `FormStoreTest` (changed T-FORM-7) | An edited form after `addField`: gains that field only; a hidden field stays hidden after adding another field, renaming, moving, retyping, and deleting fields; after `addRelation`: each side gains its new field; deleted fields lose their slots |
| T-EDT-11 | `SchemaEditorHookTest` (extended T-FORM-8) | The hook receives the added field keys (`addField`, `addRelation` both sides) and `[]` otherwise; a two-argument closure still works |
| T-EDT-12 | `RebuildFormsTest` (changed) | Section 7.3: a hidden field stays hidden through the rebuild; a missing field's slot is removed and counted |

### 11.3 Binder (`tests/core/forms/FormBinderTest.php`, WP3)

| ID | Test | Asserts |
|---|---|---|
| T-FORM-13 | `FormBinderTest` (extended) | `span` on every bound kind; `columns` true exactly when a direct bound child has a numeric span (also when the only such child is a heading; false when that child is a dropped unknown slot) |

### 11.4 Core operations (`tests/core/app/`, WP4)

| ID | Test | Asserts |
|---|---|---|
| T-APP-160 | `M5PermissionsTest` | Data provider over every `FormOperations` method: a granted Member is `AccessDenied` and nothing is read (a seam counts project opens: 0); an ungranted person, an unknown project, an inactive project, and an unknown table key are `NotFound`; an Admin succeeds |
| T-APP-161 | `FormOperationsTest` | `editor(null)` for each `state`; the stored text is never returned except as `draft` (canonical) and as `source` text |
| T-APP-162 | `FormOperationsTest` | `editor` with each action, the problems of 9.2 steps 3, 4, and 5 (a draft near the limit that an added section or label pushes over it: `problem.form.too_large`, the draft unchanged, nothing saved), focus for every row of the `focusAfter` table |
| T-APP-163 | `FormOperationsTest` | Tampered drafts (corpus refused cases, a draft over the limit, a non-string draft, an array): screen from the stored form with `problem.form.draft`, nothing written |
| T-APP-164 | `FormOperationsTest` | Editor save: one write, `generated` 0, then 1 after the edits are undone; `updated_at` of the project changes; one `form.saved` row with the exact detail; no write and no audit when unchanged |
| T-APP-165 | `FormOperationsTest` | Save conflict: a base from before another save is refused once with `problem.form.changed` and the new base; the second save succeeds |
| T-APP-166 | `FormOperationsTest` | `source` check and save: refused text kept as typed with the problem, key, and line; corpus Z9 refused `too-large` by Check and Save; saved text is canonical; `rewritten` (false for canonical text posted with CR LF line breaks); the report with images not shown (an image of a trashed record, of a PDF, of a missing id) |
| T-APP-167 | `FormOperationsTest` | `upload`: empty, over the cap (by `Fs::size`, before reading), refused, corpus Z9 (`too-large`, nothing stored), accepted; source `upload` in the audit |
| T-APP-168 | `FormOperationsTest` | `download`: stored text exactly (also a refused stored text), generated when none; file name |
| T-APP-169 | `FormOperationsTest` | `reset`: changed and unchanged, audit only when changed; `resetScreen` `isStandard` |
| T-APP-170 | `FormOperationsTest` | No log line and no audit detail holds form text, labels, or legends (marker strings) |
| T-APP-171 | `FormOperationsTest` | A table with no stored row: edit and save inserts one; other forms of the table (`is_default` 0) are untouched by every operation |
| T-APP-172 | `FormOperationsTest` | A schema change after an editor save keeps hidden fields hidden and adds the new field (through `Context::editor`) |
| T-APP-173 | `MessageKeysTest` (extended) | Section 10 |
| T-APP-174 | `CoreLayerRulesTest` (extended) | `Core\App` may use `Core\Forms`; nothing in `src/Web` names `Core\Forms` classes other than the value classes of section 2.1 |
| T-APP-175 | `AuditTrailTest` (existing) | `form.saved` and `form.reset` are written by some test |

### 11.5 Web tests (part 2 section 11)

T-WEB-71 to T-WEB-80, T-SCR-5, T-KBD-4, T-E2E-15, T-E2E-16.

### 11.6 Round trips and rendered-page mutation (WP7)

| ID | Test | Asserts |
|---|---|---|
| T-RT-9 | `FormRoundTripTest` | For the M5 fixture form: editor save, then source view text, then download, then upload of that file, then the editor again: the stored bytes are identical at each step; a hand-edited source with comments and odd spacing is stored as the canonical text and then equals the editor's output |
| T-RT-10 | `FormRoundTripTest` | An edited form with a hidden field, widths, a link, and a heading survives project archive export and import byte for byte, and the hidden field stays hidden |
| T-RT-11 | `FormRoundTripTest` | A version 4 project folder written by the M4 code (`M4Fixture`) opens, its forms are edited and saved, and the file still opens with plain SQLite tools and passes `ProjectFile` checks |
| T-SAN-4 | `FormRenderMutationTest` | The accepted mutations of T-SAN-3 (same seed), stored as the Items form and rendered through the record screen with real templates: the form part of the page holds only elements from the record-form partials' set, no attribute starting `on`, no `style` attribute, no `script`, `style`, `iframe`, `object`, `embed`, `svg`, or `math` element, and every `a href` inside the form starts `http://` or `https://` |

### 11.7 Exit criteria to tests

| Criterion | Tests |
|---|---|
| Editor output equals hand-editable HTML | T-FORM-12, T-EDT-2, T-RT-9, T-RT-10 |
| Sanitizer corpus passes | T-SAN-1 to T-SAN-6 |
| Renders under every appearance setting | V-30, T-WEB-76 |
| Keyboard only | T-KBD-4, K-4 |

## 12. Corpus catalogue (`tests/core/forms/corpus/`, WP1)

### 12.1 Files

The corpus is PHP data, so every file carries its SPDX header and no fixture is a bare text file: `tests/core/forms/corpus/<group>.php`, one per group below, each returning `list<array{id: string, text: string, verdict: 'accept'|'refuse', reason?: string, line?: int|null, element?: string|null, attribute?: string|null, canonical?: string}>`. Texts are built in PHP (byte escapes `"\xC0\xAF"`, `str_repeat` for the limits). Every hostile case holds the marker `ZQX-MARKER` where the text allows it (T-SAN-5). Field keys are synthetic (`m4pz7k2qsw3n`, the keys of `docs/formats/tiffin.md` section 12.2). `F` below is the frame `<div data-tiffin-form="1">` ... `</div>`; `S` is `<fieldset><legend>Details</legend>` ... `</fieldset>`.

Expected verdicts and reasons are the design's; where the built parser disagrees on a case marked † (libxml behaviour this design could not run), WP1 records the observed result in the corpus file with a comment and reports it, and the reviewer confirms it is still a refusal or a safe acceptance. A case may never be changed from refuse to accept without the reviewer's agreement.

### 12.2 Cases

**Group A: active content and foreign elements** (`active.php`). Each refused with `element` unless noted; line 2 when written on the second line.

| Id | Text (inside F, S where shown) | Verdict |
|---|---|---|
| A1 | `<script>ZQX-MARKER</script>` | refuse `element`, element `script` |
| A2 | `<p><script>x</script></p>` | refuse `element` |
| A3 | `<style>p{}</style>` | refuse `element` |
| A4 | `<iframe src="https://example.com/"></iframe>`, A5 `<object data="x"></object>`, A6 `<embed src="x"/>`, A7 `<form></form>`, A8 `<input/>`, A9 `<button>x</button>`, A10 `<textarea>x</textarea>`, A11 `<select></select>`, A12 `<template><p>x</p></template>`, A13 `<noscript>x</noscript>`, A14 `<base href="https://example.com/"/>`, A15 `<link rel="stylesheet" href="x"/>`, A16 `<meta http-equiv="refresh" content="0"/>` | refuse `element` |
| A17 | `<svg><script>x</script></svg>`, A18 `<svg xmlns="http://www.w3.org/2000/svg"></svg>`, A19 `<math><mtext>x</mtext></math>` | refuse `element` |
| A20 | `<x:p xmlns:x="http://www.w3.org/1999/xhtml">x</x:p>` | refuse `element` or `attribute` † (namespace declaration on a known-looking element; either refusal is acceptable) |
| A21 | `<P>x</P>`, A22 `<Div data-tiffin-field="m4pz7k2qsw3n"></Div>` | refuse `element` |
| A23 | `<DIV data-tiffin-form="1"></DIV>` as the whole text | refuse `root` |
| A24 | `<p>` + 300 letters `a` as an element name (`<aaa...a>x</aaa...a>`) inside | refuse `element`, element null (name over 40 characters) |

**Group B: attributes** (`attributes.php`). Refused with `attribute` unless noted.

| Id | Text | Verdict |
|---|---|---|
| B1 | `<p onclick="ZQX-MARKER">x</p>`; B2 `ONCLICK`; B3 `onmouseover`; B4 `<img src="attachment:1" alt="" onerror="x"/>` | refuse `attribute`, attribute named |
| B5 | `<p style="color:red">x</p>`; B6 `class="x"`; B7 `id="x"`; B8 `hidden=""`; B9 `tabindex="0"`; B10 `role="button"`; B11 `aria-label="x"`; B12 `lang="en"`; B13 `dir="rtl"`; B14 `title="x"` | refuse `attribute` |
| B15 | `<a href="https://example.com/" target="_blank">x</a>`; B16 `rel="opener"` | refuse `attribute` |
| B17 | `<p xml:lang="en">x</p>`; B18 `xml:space="preserve"`; B19 `xml:base="https://example.com/"` | refuse `attribute` |
| B20 | root `<div data-tiffin-form="1" xmlns="http://www.w3.org/1999/xhtml">` | refuse `root` |
| B21 | `<p xmlns:x="urn:x" x:y="1">x</p>` | refuse `attribute` |
| B22 | `<div data-tiffin-field="m4pz7k2qsw3n" data-tiffin-span="6" DATA-TIFFIN-SPAN="4"></div>` | refuse `attribute` |
| B23 | `<p data-tiffin-span="6" data-tiffin-span="4">x</p>` | refuse `malformed` (duplicate attribute) |
| B24 | `<div data-tiffin-field="m4pz7k2qsw3n" data-tiffin-other="1"></div>` | refuse `attribute` |
| B25 | `<img alt="x"/>` (no src); B26 `<img src="attachment:1"/>` (no alt); B27 `<a>x</a>` (no href); B28 `<div></div>` (no field) | refuse `attribute` |
| B29 | `<p>` with a 41-character unknown attribute name | refuse `attribute`, attribute null |

**Group C: values** (`values.php`).

| Id | Text | Verdict |
|---|---|---|
| C1 | `data-tiffin-label="` 60 characters `"` | accept |
| C2 | 61 characters | refuse `attribute-value` |
| C3 | `data-tiffin-label=" Title "` | accept, canonical `data-tiffin-label="Title"` |
| C4 | `data-tiffin-label=""` | refuse `attribute-value` |
| C5 | label holding `&#x202E;` | refuse `attribute-value` |
| C6 | label holding `&#10;` | refuse `attribute-value` |
| C7 | `data-tiffin-col="0"`, C8 `"13"`, C9 `"01"`, C10 `" 1"`, C11 `"+1"`, C12 `data-tiffin-row="51"`, C13 `data-tiffin-span="FULL"`, C14 `data-tiffin-span="0"` | refuse `attribute-value` |
| C15 | `data-tiffin-span="full"`, C16 `"12"`, C17 `data-tiffin-row="50"` | accept |
| C18 | `data-tiffin-field="M4PZ7K2QSW3N"`, C19 a key of 11 characters, C20 `data-tiffin-section="Details"` | refuse `attribute-value` |
| C21 | `src="attachment:0"`, C22 `"attachment:01"`, C23 `"attachment:9223372036854775808"`, C24 `"https://example.com/a.png"`, C25 `"data:image/png;base64,AAAA"`, C26 `"Attachment:1"`, C27 `"attachment: 1"` | refuse `src` |
| C28 | `src="attachment:9223372036854775807"` | accept |
| C29 | `alt=""` | accept |
| C30 | alt of 201 characters | refuse `attribute-value` |

**Group L: link addresses** (`links.php`). Each as `<p><a href="...">x</a></p>`.

| Id | `href` | Verdict |
|---|---|---|
| L1 | `https://example.com/` | accept |
| L2 | `http://192.0.2.10:8080/path?q=1#top` | accept |
| L3 | `HTTPS://example.com/` | accept, canonical keeps the case |
| L4 | `https://xn--r8jz45g.example/` | accept |
| L5 | `javascript:alert(1)`; L6 `JaVaScRiPt:alert(1)`; L7 `vbscript:x`; L8 `data:text/html,<b>` (written `&lt;b&gt;`); L9 `file:///etc/hosts`; L10 `ftp://example.com/` | refuse `href` |
| L11 | `&#106;avascript:alert(1)`; L12 `&#x6A;avascript:x`; L13 `java&#x09;script:x`; L14 `java&#x0A;script:x`; L15 `&#x20;javascript:x`; L16 `javascript&#x3A;x` | refuse `href` |
| L17 | ` https://example.com/` (leading space); L18 trailing space; L19 `https://exa mple.com/`; L20 `https://example.com/&#x09;` | refuse `href` |
| L21 | `//example.com/`; L22 `/relative`; L23 `example.com`; L24 `https:example.com`; L25 `https:/example.com`; L26 `#top` | refuse `href` |
| L27 | `https://user:pw@example.com/`; L28 `https://user@example.com/` | refuse `href` |
| L29 | `https://例え.example/` | refuse `href` |
| L30 | `https://example.com:0/`; L31 `:65536/`; L32 `https://0x7f.1/` | refuse `href` |
| L33 | `https://example.com/a"b` (written `&quot;`); L34 `https://example.com/&lt;x&gt;`; L35 a backslash | refuse `href` |
| L36 | a 2,049-character address | refuse `href` |
| L37 | `<a href="https://example.com/"><a href="https://example.com/">x</a></a>` | refuse `misplaced` |
| L38 | `<a href="https://example.com/"></a>` | accept (shown as nothing, part 2 section 6.3) |
| L39 | `<a href="https://example.com/"><strong>x</strong></a>` | accept |

**Group T: text and characters** (`text.php`).

| Id | Text | Verdict |
|---|---|---|
| T1 | `<p>&lt;script&gt;ZQX-MARKER&lt;/script&gt;</p>` | accept (text) |
| T2 | `<p>a &amp; b &apos; &quot;</p>` | accept, canonical `a &amp; b &apos; &quot;` |
| T3 | `<p>&nbsp;</p>` | refuse `malformed` |
| T4 | `<p>&#0;</p>` | refuse `malformed` |
| T5 | `<p>&#x1F;</p>` (a character XML 1.0 forbids) | refuse `malformed` |
| T6 | `<p>&#x7F;</p>`; T7 `&#x85;` | refuse `text-value` |
| T8 | `<p>&#x202E;txt</p>`; T9 `&#x2066;`; T10 `&#x200B;`; T11 `&#xAD;`; T12 `&#xFEFF;` | refuse `text-value` |
| T13 | `<p>&#xFFFE;</p>`; T13b the bytes `EF BF BE` in text | refuse `malformed` (XML forbids the character) |
| T14 | `<p>&#x200D;</p>` (zero-width joiner) | accept |
| T15 | `<p>a&#x9;b&#xA;c</p>` | accept, canonical holds a tab and a line feed |
| T16 | `<p>a&#xD;b</p>` | refuse `text-value` |
| T17 | literal carriage return inside text | accept, canonical holds a line feed |
| T18 | `<p>é ü 日本 😀</p>` | accept |
| T19 | text directly in the root: `<div data-tiffin-form="1">x</div>` | refuse `text`, line 1 |
| T20 | text in a `fieldset` after the legend | refuse `text` |
| T21 | `<legend></legend>`; T22 a 101-character legend; T23 a legend with `&#x202E;` | refuse `text-value` |
| T24 | `<legend><strong>x</strong></legend>` | refuse `content` |
| T25 | `<hr>x</hr>`; T26 `<br>x</br>` inside a `p`; T27 `<div data-tiffin-field="m4pz7k2qsw3n">x</div>` | refuse `content` |
| T28 | text after the root's end tag | refuse `malformed` (libxml: extra content) |
| T29 | `<p>a&#xFDD0;b</p>`; T30 `&#x1FFFE;`; T31 `&#x10FFFF;` (noncharacters, section 3.2) | refuse `text-value` |
| T32 | a label `data-tiffin-label="a&#xFDD0;b"`; T33 a legend holding `&#xFDEF;`; T34 alt holding `&#x2FFFF;` | refuse `attribute-value` (T32, T34), `text-value` (T33) |
| T35 | `<p>&#xE000;&#x378;</p>` (private use, unassigned: not noncharacters) | accept; the source view and the hidden draft give the same bytes back (T-WEB-79) |

**Group E: encoding and bytes** (`encoding.php`).

| Id | Bytes | Verdict |
|---|---|---|
| E1 | `EF BB BF` then a valid form | accept, canonical without the mark |
| E2 | two byte order marks | refuse `text` (U+FEFF before the root is text) or `malformed` † |
| E3 | UTF-16LE with its mark; E4 UTF-16BE; E5 ISO-8859-1 `é` (`E9`); E6 overlong `/` (`C0 AF`); E7 surrogate (`ED A0 80`); E8 a lone continuation byte; E9 a truncated 3-byte sequence at the end; E10 `F5 80 80 80` | refuse `encoding`, line where the byte is |
| E11 | NUL inside text; E12 NUL in an attribute | refuse `nul` |
| E13 | CRLF line endings throughout, a problem on line 3 | refuse with line 3 |
| E14 | CR-only line endings, problem on line 3 | refuse with line 3 (also for a `malformed` problem: libxml alone reports line 1 here, section 3.1 item 3) |

**Group X: declarations, entities, instructions** (`declarations.php`).

| Id | Text | Verdict |
|---|---|---|
| X1 | `<!DOCTYPE div [<!ENTITY a "ZQX-MARKER">]>` + a form using `&a;` | refuse `declaration`, line 1 |
| X2 | billion laughs (nine nested entities) | refuse `declaration` |
| X3 | external entity `<!ENTITY x SYSTEM "file:///etc/passwd">` | refuse `declaration` |
| X4 | parameter entity `<!ENTITY % p SYSTEM "https://example.com/x.dtd"> %p;` | refuse `declaration` |
| X5 | `<!DOCTYPE html>` | refuse `declaration` |
| X6 | `<p><![CDATA[<script>x</script>]]></p>` | refuse `declaration` |
| X7 | `<?xml version="1.0" encoding="UTF-8"?>` first line | refuse `processing-instruction`, line 1 |
| X8 | `<?php echo 1; ?>` inside; X9 `<?xml-stylesheet href="x"?>` | refuse `processing-instruction` |
| X10 | `<!ELEMENT p ANY>`; X11 `<!ATTLIST p a CDATA "x">`; X12 `<![INCLUDE[ ]]>` | refuse `declaration` |
| X13 | `<p>&a;</p>` with no declaration | refuse `malformed` |
| X14 | `<!-- <!DOCTYPE x> -->` in a comment | refuse `declaration` (the pre-scan is lexical and does not know comments; kept on purpose: refusing is the safe side) |

**Group D: differential (HTML reads it differently)** (`differential.php`).

| Id | Text | Verdict |
|---|---|---|
| D1 | `<div data-tiffin-field="m4pz7k2qsw3n"/>` | accept, canonical `<div ...></div>` |
| D2 | `<p/>` | accept, canonical `<p></p>` |
| D3 | `<p>x<br>y</p>` | refuse `malformed` |
| D4 | `<p>a</p ` (no `>`) | refuse `malformed` |
| D5 | `<p>a<p>b</p></p>` | refuse `misplaced` |
| D6 | `<p>a</b></p>` | refuse `malformed` |
| D7 | `<p title=x>a</p>` (unquoted) | refuse `malformed` |
| D8 | `<p>a<!-- b -- c -->d</p>` | refuse `malformed` |
| D9 | `<p>a<!--->b</p>` | refuse `malformed` |
| D10 | `<p>a<!-- x --!>b</p>` | refuse `malformed` |
| D11 | `<p>a ]]> b</p>` | refuse `malformed` |
| D12 | `<p>a < b</p>` | refuse `malformed` |
| D13 | `<p>AT&T</p>` | refuse `malformed` |
| D14 | `<!-- <script>ZQX-MARKER</script> -->` between blocks | accept, canonical without the comment |
| D15 | `<p>a<!-- x -->b</p>` | accept, canonical `<p>ab</p>` |
| D16 | `<a href='https://example.com/'>x</a>` (single quotes) | accept, canonical double quotes |
| D17 | `<p>&#x3C;script&#x3E;</p>` | accept (text `<script>`), canonical `&lt;script&gt;` |
| D18 | attribute value holding `&#x3C;` in a label | accept, canonical `&lt;` |
| D19 | a tag with white space before `>` and attributes on several lines | accept |
| D20 | a `textarea`-ending sequence in text: `<p>&lt;/textarea&gt;&lt;script&gt;</p>` | accept (proves the source view escapes; T-WEB-74) |

**Group S: structure** (`structure.php`).

| Id | Text | Verdict |
|---|---|---|
| S1 | `''` | refuse `root`, line null |
| S2 | white space only | refuse `malformed` (libxml: document is empty) |
| S3 | two roots | refuse `malformed` (libxml: extra content), line of the second |
| S4 | `<div data-tiffin-form="2">` | refuse `format-version` |
| S5 | `<div data-tiffin-form="01">` | refuse `format-version` |
| S6 | `<div data-tiffin-form="1" data-tiffin-span="full">` | refuse `root` |
| S7 | a `fieldset` inside a `fieldset` | refuse `misplaced` |
| S8 | a `legend` second in a `fieldset`; S9 two legends; S10 a `legend` in the root | refuse `misplaced` |
| S11 | `<strong>` in the root | refuse `misplaced` |
| S12 | `<p><h2>x</h2></p>` | refuse `misplaced` |
| S13 | the same field key in two sections | refuse `duplicate-field`, line of the second |
| S14 | every element and attribute of 12.1 once | accept |
| S15 | the example of `docs/formats/tiffin.md` 12.2 | accept, canonical equals the example |
| S16 | `<fieldset></fieldset>` (no legend, no children) | accept |
| S17 | a slot naming a valid key that is no field | accept |

**Group Z: limits** (`limits.php`), built with `str_repeat`.

| Id | Text | Verdict |
|---|---|---|
| Z1 | exactly 262,144 bytes (a valid form padded with spaces inside a paragraph) | accept |
| Z2 | 262,145 bytes | refuse `too-large`, line null |
| Z3 | a byte order mark plus 262,144 bytes | accept |
| Z4 | exactly 5,000 elements | accept |
| Z5 | 5,001 elements | refuse `too-many-elements`, line of the 5,001st |
| Z6 | exactly 8 levels (root, `fieldset`, `p`, `strong`, `em`, `a`, `strong`, `em`) | accept |
| Z7 | 9 levels | refuse `too-deep` |
| Z8 | 5,000 elements each on its own line, the last one bad | refuse with that line (lines past 4,096 counted right) |
| Z9 | a valid form of 262,144 bytes whose paragraphs hold literal `'` characters (canonical text about six times longer) | accept (`parse`); its canonical text is over the limit, so T-FORM-12 skips it by the size condition of property 5.1, and T-APP-166 and T-APP-167 assert that Check, source save, and upload refuse it with `too-large` |

## 13. Carried-forward items

| Item (plan 9.3) | Confirmed in the tree | Settled |
|---|---|---|
| Whether form images keep attachments alive (P151) | `Attachments::sweep` and `deleteFiles` read no form (as built, M4 section 4.2.3) | Not kept alive; ADR 0020 decision 7; P195 |
| Archive import stores an accepted form's text as given, not canonical (found at spec review) | `ProjectRebuilder::copyForm` inserts the archive's text; `regenerate` rewrites it only when the adjustment changes it | Kept as built (P209). Carried forward to the M5 exit audit (M5-X): confirm the behaviour, that nothing relies on stored text being canonical, and record it in the as-built notes |

The other items of plan 9.3 belong to M7 and are not touched here.

## 14. Work packages

Tiers per orchestration guide section 2. Owned files may be created or changed only by that package; packages that can run at the same time own disjoint files (check at the end). A file owned in two waves is listed in both with the part each changes. Every package starts with `git merge --ff-only <assigned branch>`, runs `composer test` and `composer lint` before committing and before reporting, and may not weaken an existing test (the changed expectations named in sections 7.3 and 11.2 are spec changes, not weakening). A package may add private helpers inside its own files and must report them.

Changes from plan 6.3, with reasons: `RecordController.php` and `templates/pages/records/form.php` join WP3 (the node conversion and the root loop must carry spans, columns, and links; no screen package touches them); `SchemaEditor.php`, `Context.php`, `ProjectRebuilder.php` (no code change expected, docblock only), `strings/en/archive.php`, and `RebuildFormsTest.php` join WP2 (section 7); `Sidebar.php` leaves WP3 (the entry point is a page-head action on the table's Fields page, part 2 section 4, owned by WP3 through `templates/pages/tables/fields.php`); `TemplateRulesTest.php` gets no `PENDING_FORMS` lists (each screen package owns its page strings in its own file, and WP3's shared strings are printed by WP3's own partials, so no key is pending; P141's mechanism is not needed); `strings/en/problems.php` and `MessageKeysTest.php` are WP4's (section 10). `ViewScope.php`, `Response.php`, and `WebLayerRulesTest.php` join WP3.

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP2, WP3 | M5-R1 merged (the third slot goes to M7-D1 when one of these finishes first, plan 6.4) |
| 2 | WP4 | WP1 and WP2 merged |
| 3 | WP5, WP6 | WP3 and WP4 merged |
| 4 | WP7 | WP5 and WP6 merged |

### M5-WP1: Sanitizer

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (the parser is the sanitizer) |
| Owned files | `src/Core/Forms/FormParser.php`, `FormRules.php`, `FormSerializer.php` (only if a fixed-point case needs it), `FormRejected.php`, `FormFormat.php`, `StartTags.php` (new); `tests/core/forms/FormParserTest.php`, `FormSerializerTest.php`; new `tests/core/forms/corpus/*.php`, `tests/core/forms/SanitizerCorpusTest.php`, `FormMutationTest.php`, `FormRejectionPrivacyTest.php`; new `tests/Support/FormFixture.php` |
| Must not touch | Node classes, `FormStore.php`, `FormBinder.php`, `Bound*.php`, anything under `src/Web`, `templates`, `strings` |
| Scope | Sections 3, 4.1, 4.2, 5, 12 |
| Acceptance | T-SAN-1, T-SAN-2, T-SAN-3, T-SAN-5, T-SAN-6, T-FORM-12 pass; existing T-FORM-1 to T-FORM-4 pass unchanged except reason codes `href` and `src` where T-FORM-4 expected `attribute-value` for an address or source (spec change); every corpus case gives its recorded verdict and reason; a rejection names a line by section 4.2 (CR-only texts included, through the line-break normalization of section 3.1 item 3); noncharacters refused in text, labels, legends, and alt (section 3.2); no rejection message or log line holds form content; cases marked † reported with the observed result; design-tier security review (plan 6.5 row WP1) |

### M5-WP2: Editor model and store writes

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | No (pure functions and literal SQL; the untrusted draft is parsed by WP4 before WP2 code sees it) |
| Owned files | New `src/Core/Forms/FormEditor.php`, `EditorAction.php`, `EditorPath.php`, `EditorRow.php`, `EditorApplied.php`, `FormReport.php`, `FormWrite.php`; `src/Core/Forms/FormStore.php`, `StoredForm.php` (only if needed), `FormDocument.php`, `Section.php`, `FieldSlot.php`, `Placement.php` (helper methods only, for example `withChildren`, `withLabel`, `withSpan`; no rule changes), `FormGenerator.php` (only if needed); `src/Core/Schema/SchemaEditor.php` (hook argument, section 7.2); `src/Core/App/Context.php` (the `editor()` closure only); `src/Core/Io/Archive/ProjectRebuilder.php` (docblock only); `strings/en/archive.php` (`archive.change.forms_extended` only); new `tests/core/forms/FormEditorTest.php`, `FormReportTest.php`; `tests/core/forms/FormStoreTest.php`, `FormNodesTest.php`, `FormGeneratorTest.php`, `SchemaEditorHookTest.php`; `tests/core/io/archive/RebuildFormsTest.php` |
| Must not touch | WP1 files, `FormBinder.php`, `Bound*.php`, `src/Web`, `templates` |
| Scope | Sections 6, 7 |
| Acceptance | T-EDT-1 to T-EDT-12 pass; each edit operation gives a document the serializer writes and the parser accepts; static elements survive every operation unchanged (same objects); save sets `generated` 0 unless the text is the generated one, reset sets 1; `saveDefault` refuses a document whose text is over `MAX_BYTES`; a table with no stored row can be saved; schema changes after an edit still remove slots and add only new fields; existing M2 to M4 tests pass (the changed expectations of sections 7.3 and 11.2 excepted); implementation-tier review |

### M5-WP3: Web components and rendering

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (templates, headers, links) |
| Owned files | `src/Core/Forms/FormBinder.php`, `BindData.php`, `Bound*.php`; `tests/core/forms/FormBinderTest.php`; `src/Web/Controller/RecordController.php` (node conversion: span, columns, link `href`; nothing else); `src/Web/ViewScope.php` (`formLink`); `src/Web/Response.php` (`attachmentText`); `src/Web/routes.php`; `templates/layout.php`; `templates/partials/record-form/*.php` and new `templates/partials/record-form/blocks.php`, `cell.php`; `templates/partials/form-inline.php`; new `templates/partials/forms/*.php`; `templates/pages/records/form.php` (root loop only); `templates/pages/tables/fields.php` (the page-head action only); `public/assets/css/app.css`; new `strings/en/forms.php`; `tests/web/TemplateRulesTest.php` (the `formLink` rule, runtime prefixes), `ComponentsTest.php`, `RouterTest.php`, `ResponseTest.php`, `RecordScreenTest.php` (link and width cases), `StringsTest.php`, `WebLayerRulesTest.php`, `SchemaScreensTest.php` (the new action) |
| Must not touch | `src/Core/App`, WP1 and WP2 files, the new controllers and page templates of WP5 and WP6 |
| Scope | Section 8; part 2 sections 3, 6.1 to 6.4, 7 (shared strings), 8 |
| Acceptance | T-FORM-13, T-WEB-71, T-WEB-72, T-WEB-74, T-WEB-76 pass; widths render through classes with no inline style; a link renders with the fixed `rel` and no `target`; a link with a refused address cannot reach a page (the parser refuses it, the binder carries only `Link` nodes, and `formLink` refuses anything not `http`/`https`); every M5 page has its layout entry and route; hostile label, legend, summary, and text values are escaped; routes for the WP5 and WP6 controllers exist (pending controllers, as M4); design-tier security review (plan 6.5 row WP3) |

### M5-WP4: Core form operations

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP2 |
| Security-critical | Yes (authorization boundary, untrusted draft, upload) |
| Owned files | New `src/Core/App/FormOperations.php`, `FormEditorScreen.php`, `FormSourceScreen.php`, `FormUploadScreen.php`, `FormResetScreen.php`, `FormSaved.php`, `FormFile.php`; `src/Core/App/Operations.php`; `src/Core/App/Context.php` (only if a factory is needed; WP2 changed it in wave 1); `src/Core/System/AuditAction.php`; `strings/en/problems.php`; new `tests/core/app/FormOperationsTest.php`, `M5PermissionsTest.php`; `tests/core/app/MessageKeysTest.php`, `tests/repo/CoreLayerRulesTest.php` |
| Must not touch | `src/Core/Forms`, `src/Core/Schema`, `src/Web`, `templates` |
| Scope | Sections 9, 10 |
| Acceptance | T-APP-160 to T-APP-175 pass; a Member is refused on every method before anything is read; a draft from a hidden field is parsed before use; no save path stores a text over `MAX_BYTES` (section 5); a stale or foreign table key gives not found; one audit row per changing save and reset, none otherwise; nothing is written when the parser refuses; design-tier security review together with WP5 and WP6 (plan 6.5) |

### M5-WP5: Editor screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3, WP4 |
| Security-critical | Yes (state-changing forms, hidden draft) |
| Owned files | New `src/Web/Controller/FormController.php`; new `templates/pages/forms/edit.php`, `reset.php`; new `strings/en/form-editor.php`; new `tests/web/FormEditorScreenTest.php` |
| Must not touch | `app.css`, shared partials, `routes.php`, `layout.php`, `strings/en/forms.php`, WP6 files |
| Scope | Part 2 sections 4 (editor and reset rows), 5, 6.5; `docs/design/m5/editor.md`, `reset.md` |
| Acceptance | T-WEB-75 (editor and reset parts), T-WEB-78 pass; every edit action works as a plain form post; CSRF refused without a token; a tampered draft gives the page with a problem (422), never a 500; focus lands on the moved row (`autofocus`); a component change it needs is reported, not made |

### M5-WP6: Source screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3, WP4 |
| Security-critical | Yes (source text in a page, download headers, upload) |
| Owned files | New `src/Web/Controller/FormSourceController.php`; new `templates/pages/forms/source.php`, `upload.php`; new `strings/en/form-source.php`; new `tests/web/FormSourceScreenTest.php`, `FormHostileRequestsTest.php` |
| Must not touch | As WP5, and WP5 files |
| Scope | Part 2 sections 4 (source, download, upload rows), 5.3, 6.6; `docs/design/m5/source.md` |
| Acceptance | T-WEB-73, T-WEB-75 (source and upload parts), T-WEB-77, T-WEB-79 pass; a refused source shows the problem with its line and keeps the typed text in the box, escaped (corpus D20 cannot leave the `textarea`); download headers are attachment, `application/octet-stream`, `nosniff`, sandbox CSP; upload over the cap or without CSRF stores nothing |

### M5-WP7: End to end, round trips, keyboard, appearance

| | |
|---|---|
| Tier | implementation |
| Depends on | WP5, WP6 |
| Security-critical | No |
| Owned files | New `tests/web/M5EndToEndTest.php`, `tests/roundtrip/FormRoundTripTest.php`, `tests/web/FormRenderMutationTest.php`, `tests/Support/M5Fixture.php`, `tests/Support/AppearanceMatrix.php`; `tests/web/KeyboardStructureTest.php`, `ScreenStructureTest.php`; scripts for K-4 and V-30 in the session scratchpad only (not committed); residue the orchestrator assigns |
| Must not touch | Product code, except assigned residue |
| Scope | Sections 11.6; part 2 sections 9, 10, 11 |
| Acceptance | T-RT-9 to T-RT-11, T-SAN-4, T-E2E-15, T-E2E-16, T-SCR-5, T-KBD-4 pass; editor to source to file to upload gives byte-identical stored text; an edited form survives archive export and import; K-4 (also with JavaScript off) and V-30 recorded in the ledger with counts; visual check against `docs/design/m5/` (V-31 to V-34); implementation-tier review |

### Summary

| ID | Title | Tier | Depends on | Security-critical | Wave |
|---|---|---|---|---|---|
| M5-WP1 | Sanitizer: problem positions, link and source codes, fixed point, corpus, mutation | implementation | none | Yes | 1 |
| M5-WP2 | Editor model and store writes; hook passes added fields | implementation | none | No | 1 |
| M5-WP3 | Web components and rendering: binder spans, links, width classes, routes, layouts, shared strings, entry action | implementation | none | Yes | 1 |
| M5-WP4 | Core form operations, authorization, audit, problem strings | implementation | WP1, WP2 | Yes | 2 |
| M5-WP5 | Editor and reset screens | implementation | WP3, WP4 | Yes | 3 |
| M5-WP6 | Source, download, upload screens | implementation | WP3, WP4 | Yes | 3 |
| M5-WP7 | End to end, round trips, render mutation, keyboard, appearance matrix | implementation | WP5, WP6 | No | 4 |

Design-tier security reviews (orch 2 rule 4; plan 6.5): WP1 (the reviewer's own hostile forms, mutation of each rule, no DTD or entity ever loaded), WP3 (nothing from a form reaches a page except through escaping; `href` only from `Link` nodes through `formLink`; download response headers), WP4 with WP5 and WP6 together (hidden draft, source save, upload, download; Member and cross-project attempts; CSRF and origin refusals). Implementation-tier review: WP2, WP7.

Ownership check. Wave 1: WP1 holds `FormParser`, `FormRules`, `FormSerializer`, `FormRejected`, `FormFormat`, `StartTags`, their tests, the corpus, `FormFixture.php`; WP2 holds the editor classes, `FormStore`, `StoredForm`, the node helper methods (`FormDocument`, `Section`, `FieldSlot`, `Placement`), `FormGenerator`, `SchemaEditor`, `Context` (closure), `ProjectRebuilder` (docblock), `archive.php`, and its tests; WP3 holds `FormBinder`, `BindData`, `Bound*`, `FormBinderTest`, the web files listed, `forms.php`. The node classes are WP2's and are not WP1's (WP1's acceptance reads them only); `FormRules.php` is WP1's, and WP2's helpers call the existing `FormRules` methods without changing them. No file is shared in wave 1. Wave 2: WP4 alone (it may touch `Context.php` after WP2). Wave 3: WP5 and WP6 own their controllers, page templates, strings files, and tests; `routes.php`, `layout.php`, `app.css`, shared partials, `forms.php`, and `TemplateRulesTest.php` are WP3's (merged). Wave 4: WP7. Files touched in two waves: `Context.php` (WP2 closure, then WP4 if needed), `docs/formats/tiffin.md` (this design, then as-built notes by M5-X).

## 15. Provisional decisions (for the ledger, from P189)

Each is the option cheapest to reverse (orchestration guide section 5) and is recorded in ADR 0020 where it is a decision rather than a constant. Plan section 3 rows 2 to 5 were not answered; their defaults are applied (P193, P191, P192, P194).

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P189 | Sanitizer position (plan 6.2 row 1) | Parse and rebuild only; whole-form refusal; no stripping; no safe-HTML type in v1 (ADR 0008 d4 reservation withdrawn for v1); form text reaches a page only as escaped `textarea` text in the source view | ADR 0020 d1 |
| P190 | Problem reporting (row 2) | `FormRejected` gains line, element, attribute (names only when XML-name-like, 40 characters); new reason codes `href`, `src`; message key per reason with `_unnamed` variants; logs keep reason and table key only | ADR 0020 d2; this spec 4 |
| P191 | Stored text (plan 3 row 3) | Every write stores `serialize(parse(text))`; comments, spacing, references, a leading byte order mark not kept; a save whose canonical text exceeds 262,144 bytes is refused; noncharacters refused by the parser; archive import keeps accepted text as given (as built); documented as format 12.5 | ADR 0020 d3 |
| P192 | Links (plan 3 row 4; settles ADR 0009 E2) | Real links, `http`/`https` by the `url` rules, `rel="noopener noreferrer"`, no `target`, empty links not shown; images attachment references only | ADR 0020 d4 |
| P193 | Form CSS (plan 3 row 2) | Reserved and ignored; written `''` on insert, kept as is on update; never served; moves to M8 (narrows ADR 0003) | ADR 0020 d5 |
| P194 | Forms per table (plan 3 row 5) | Default form only; other rows untouched and not listed | ADR 0020 d6 |
| P195 | Form images and attachments (P151 open point) | Not kept alive; reported on save when not showable; the simple editor creates none | ADR 0020 d7 |
| P196 | Widths (row 6) | Tokens auto, full, two thirds (8), half (6), one third (4); a container with a numeric span becomes 12 columns, unspanned blocks full width; one column under a 40 rem container query; `col` and `row` kept, reading order | ADR 0020 d10; part 2 section 8 |
| P197 | Hidden fields and schema changes (row 7; correction) | Hiding removes the slot; schema changes add slots only for fields they add; archive rebuild adds none; `forms_extended` meaning and string changed (amends ADR 0014 d3, M3 section 6 step 6, format 13.3) | ADR 0020 d9; this spec 7.2, 7.3 |
| P198 | `generated` flag | Save sets 0 unless the text equals the generated text (then 1); reset sets 1 | ADR 0020 d11 |
| P199 | Editor without script (row 4) | One page; every control a submit button (`do`); draft as canonical text in a hidden field, parsed every request; labels, names, widths applied on every press before the action; a hidden first Apply button for Enter; no server-side drafts; POST answers 200 with the page, Save 303 | ADR 0020 d12; part 2 section 5 |
| P200 | Moving across sections | Up and down step one row in reading order, entering and leaving sections at their edges; sections move whole | this spec 6.3 |
| P201 | Save conflict | Hash of the stored text as `base`; a mismatch refuses once and hands the new base; the next save overwrites | ADR 0020 d12 |
| P202 | Authorization and audit (row 11) | `FormOperations`, `forms.edit`; `form.saved` (table, source, generated), `form.reset` (table); project `updated_at` touched; no snapshot for form writes (amends ADR 0012 d4) | ADR 0020 d11, d12 |
| P203 | Download and upload (row 9) | Download `application/octet-stream` attachment, sandbox CSP, named "{table} form.html", stored text exactly; upload stores at once when accepted, cap 262,144 bytes checked by size before reading, no base check | ADR 0020 d8 |
| P204 | Reset (row 8) | Confirmation page with the download first; no history; no change and no audit when already standard | ADR 0020 d11 |
| P205 | Entry point | "Edit the form" action on the table's Fields page (Admins); no sidebar or record-screen entry in v1 | part 2 section 4 |
| P206 | Appearance matrix (row 13) | Greedy pairwise set over the five settings (accent pairs with high-contrast themes not required), plus five extreme rows; at 1360 px and 390 px, extremes also at 320 px | part 2 section 10 |
| P207 | Work-package changes from the session plan | Section 14 list (process; ledger only) | this spec 14 |
| P208 | Review ruling (a): P197 accepted, with its consequence for imports | Accepted as designed. The M3 archive-import behaviour that gave an edited default form a slot for every field it did not name, and so brought a hidden required field back, is reversed: after import such a field stays hidden and records can be saved without it (P118, P157). WP2 owns the test changes: `RebuildFormsTest::testFormsAreCheckedAndRegenerated` (section 7.3) and the two `FormStoreTest` cases of T-EDT-10 | ADR 0020 d9; this spec 7.3 |
| P209 | Review ruling (b): canonical text on archive import | Not canonicalized: archive import keeps an accepted form's text as the archive holds it (as built); the next save writes canonical text. Carried forward to the M5 exit audit (section 13) | ADR 0020 d3; this spec 5; format 12.5 |
| P210 | Review ruling (c): noncharacters | Refused in text, labels, legends, and alt (`TEXT_VALUE`, `ATTRIBUTE_VALUE`). Narrows form format 1; only hand-edited files can hold them, and such a stored form falls back to the generated layout | ADR 0020 d3; this spec 3.2; format 12.1 |
| P211 | Review ruling (d): upload and the save token | Upload has no base check: uploading a file is a deliberate replacement of the whole form, made from a page that says so, and it overwrites a save made meanwhile without a conflict message | ADR 0020 d8, d12; this spec 9.1 |
| P212 | Review ruling (e): open points of section 16 item 7 | P198 stands: a save whose text equals the generated text stays (or becomes) standard, `generated` 1 (cheapest to reverse: the flag is computed at each save, so changing the rule later rewrites no stored form). The simple editor offers no explicit `col` and `row` before M8; it keeps them as they are (cheapest: adding controls later is additive and needs no format step). Plan section 3 rows 2 to 5 stay with their defaults (P191 to P194) until the maintainer answers | ADR 0020 d10, d11; this spec 16 |

## 16. Assumptions and open points

1. `XMLReader` exposes no line or offset for nodes; the lexical start-tag table of section 4.2 is assumed exact for well-formed prefixes (verified by T-SAN-6). Text problems report "near line".
2. libxml accepts a document whose first bytes were a byte order mark only after TiffinDB removes it; the design removes it explicitly, so libxml's own handling does not matter. Cases marked † in section 12 depend on libxml behaviour not run during design.
3. Container queries with `rem` thresholds resolve against the root element's font size (CSS Containment Level 3), unlike media queries; the person's text size therefore changes the switch to one column. Verified in V-30 at Largest text. A browser without container queries shows one column, because every span rule sits inside the query (part 2 section 8).
4. PHP keeps dots inside bracketed array keys of form names, but the paths use `-` anyway (`label[2-3]`), so no request parser behaviour matters.
5. A live preview of the edited form in the editor is not designed: the editor's rows say each field's width in words, and the record screen shows the result. A preview would render a bound form with empty values and disabled controls, which may read as a broken form. For the maintainer to decide (M8 may cover it).
6. A hidden draft of up to 262,144 bytes is written escaped into the page (up to about six times larger in the worst case of `&` and `<` characters). Household forms are a few kilobytes; the request size limit is the PHP `post_max_size` (part 2 section 5.3).
7. Missing information for the maintainer (not blocking): plan section 3 rows 2 to 5 (P191 to P194). Resolved provisionally at review (P212): a save that equals the standard form stays "standard" (P198); the editor offers no explicit `col` and `row` before M8.
8. Archive import keeps an accepted form's text as given (section 5, exception; ruled at review, P209). Canonicalizing it in `copyForm` is a small change for a later milestone if wanted; it changes no file format.

## 17. As-built notes

To be appended by the M5 exit audit (M5-X).

Notes from the spec review (M5-R1), before implementation: P197 is accepted (P208) and reverses the M3 import behaviour that brought hidden required fields back; WP2 updates `RebuildFormsTest` and the two `FormStoreTest` cases of T-EDT-10. Archive import keeps accepted text as given (P209), carried forward to M5-X (section 13). Where the merged code differs from the text above, the code, these notes, and the provisional decisions in `docs/build/ledger.md` govern.
