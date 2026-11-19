# ADR 0014: Form format 1, stored forms, and how forms are shown before the sanitizer

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the form format that ADR 0003 and ADR 0006 require, adds `_tiffin_forms` in project format version 2, decides how stored form text is shown before the M5 sanitizer exists, amends the layer table of ADR 0012 decision 1 for `Core\Forms`, and applies ADR 0009 row A1 (columns on wide screens). The format itself is documented in `docs/formats/tiffin.md` sections 4.6, 11.1, and 12; the implementation design is `docs/specs/M2-records.md` section 5.

## Context

ADR 0003 stores a form as an HTML fragment plus scoped CSS, with fields bound by attributes, generated for every table, later edited by an Admin, and sanitized against an allowlist. ADR 0006 requires the v1 format to carry grid placement, static elements, and a format version so that the post-v1 editor needs no second format. The sanitizer and the editor are M5; but M2 shows records through forms, and M3's project archive import will bring in form text written elsewhere. Printing stored form text into a page before a sanitizer exists would open a stored cross-site scripting path. ADR 0012 noted that binding records to forms would need `Core\Forms` to depend on `Core\Query`.

## Decisions

### 1. Form format 1

- A form is well-formed XML using a fixed subset of HTML: a root `div data-tiffin-form="1"`; blocks `fieldset` (with optional `legend` and a role word), field slots `div data-tiffin-field="<field key>"` (optional `data-tiffin-label`), `h2`, `h3`, `p`, `hr`, `img`; inline `strong`, `em`, `br`, `a`. Placement attributes `data-tiffin-col`, `data-tiffin-row`, `data-tiffin-span` on any block, on a 12-column grid. The exact vocabulary and limits are in `docs/formats/tiffin.md` section 12.1.
- No `class`, `id`, `style`, event attribute, or any element outside the list. Images refer to attachments only (`src="attachment:<id>"`); no form can name an outside address for an image. Links (`a href`) admit `http` and `https` addresses by the `url` field type's rules; M2 shows link text as plain text. ADR 0009 row E2 (external addresses in forms) is therefore settled for images and left to M5 for links.
- Form CSS (`_tiffin_forms.css`) is reserved: empty in every form TiffinDB writes in M2 and ignored if not empty. Serving and checking form CSS is designed with the M5 sanitizer.
- XML syntax is used because PHP 8.3 has no HTML5 parser, while `XMLReader` (an extension the installer already requires) parses XML strictly and without loading external entities. Elements that HTML treats as non-void are written with end tags, so the text also reads correctly as HTML.

### 2. `_tiffin_forms` and project format version 2

- One row per form: key, table key, name, form format version, HTML, CSS, default flag (at most one per table), a `generated` flag, times. Added by the forward-only step to project format version 2 (`docs/formats/tiffin.md` section 11.1), which creates the table and its indexes and writes no rows.
- A table without a stored default form is shown with the generated layout; its form is stored the next time its fields change. This keeps the upgrade step to constant SQL (the M1 rule for format steps) at the cost of tables in upgraded files having no stored form until then.

### 3. Generation and regeneration

- The generated form is one `Details` section with the table's fields in field order, a `Files` section for attachment fields, and one section per relation field that may link to several records, named after the field. Relation fields limited to one link stay in `Details`. Long text and rating fields span the full width. Legends are English data, like the `Name` field of a new table (M1 provisional decision P45).
- Forms are rewritten inside the same transaction as the schema change that affects them, through a hook that `Core\App` passes to the `SchemaEditor`: a form with `generated` 1 is regenerated; a form with `generated` 0 (edited by a person, from M5) keeps its layout, gains a slot at the end of its first section for each new field, and loses slots for deleted fields.

### 4. Showing a stored form before M5: parse and rebuild, never print

- Stored form text is never written into a page. `Core\Forms\FormParser` reads it into a small node model with `XMLReader`, accepting only the vocabulary of decision 1 and refusing the whole form on anything else (default deny). The web layer builds the record screen from the node model through its ordinary templates, so every piece of text passes through the templates' escaping helper.
- A form that fails to parse, or has an unknown format version, is replaced on screen by the generated layout; the stored text is left as it is and the failure is logged without its content. M3's archive import re-parses every incoming form the same way and regenerates any that fail.
- The typed "safe HTML" value that ADR 0008 decision 4 reserved is **not** introduced in M2. Because the node model is rendered by templates, no markup from outside the templates reaches a page, and a safe-HTML type would only add a path that bypasses the template rule. The M5 design may still add it if the sanitizer needs to emit markup that templates cannot build. This deviates from the session plan's recommendation, which proposed introducing the type now.

### 5. Layers

ADR 0012 decision 1 is amended:

| Namespace | Holds | May depend on |
|---|---|---|
| `TiffinDB\Core\Forms` | Form format (node model, parser, serializer), generator, form storage, binder | Schema, Storage, Error |
| `TiffinDB\Core\App` | (as before) | every Core namespace except Install, now including Forms |

`Core\Forms` does not depend on `Core\Query`. The binder takes field definitions and plain values; `Core\App` reads records through `Core\Query` and passes the values in. This removes the reverse dependency ADR 0012 anticipated.

### 6. Columns on wide screens (ADR 0009 row A1)

The generated form keeps a single-column source order. The default form stylesheet (in the application stylesheet, not in the form) lays out the `Details` section as a grid of columns at least 300 design pixels wide, so the fields flow into two or three columns on wide screens and one on narrow ones; the record screen is then 1120 design pixels wide instead of 800. Plan section 4 ("one accessible single-column layout") is to be amended when the maintainer confirms row A1; until then this is provisional.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Syntax | HTML parsed with `DOMDocument::loadHTML` | Lenient libxml HTML 4 parser with known mis-parses; needs the `dom` extension; harder to make default-deny |
| Syntax | JSON layout | Rejected by ADR 0003 (not hand-editable HTML) |
| Showing forms | Print stored HTML after a quick filter | A filter that keeps markup is a sanitizer; doing it twice (M2 and M5) invites gaps |
| Showing forms | Ignore stored forms in M2 and always generate | Archive imports in M3 would then carry forms nobody checks until M5 |
| Bad form | Drop unknown parts and show the rest | Partial acceptance hides corruption and is the classic sanitizer failure mode |
| Upgrade | Generate forms inside the format step | Format steps are constant SQL; generation needs code |
| Rendering | Introduce the safe-HTML type now | No markup needs it; weakens the template rule (decision 4) |

## Consequences

- No stored form text reaches a browser in M2 or M3; the M5 sanitizer designs the editor and source view against the same node model.
- The form vocabulary is part of the documented project format; changing it means a new form format version.
- A file upgraded from version 1 has no stored forms until each table's fields change. Readers of the file must treat a missing form as the generated layout (`docs/formats/tiffin.md` section 4.6).

## Costly to reverse

The attribute names (`data-tiffin-form`, `data-tiffin-field`, `data-tiffin-section`, `data-tiffin-label`, placement attributes) and the `_tiffin_forms` columns, once written into users' files. The rendering approach and the generator rules are code only.
