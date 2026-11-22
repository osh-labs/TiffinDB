# ADR 0020: Form editing in v1

Status: Accepted (provisional, awaiting maintainer confirmation). Settles ADR 0009 row E2 (external addresses in forms), the form CSS position that ADR 0014 decision 1 left to M5, the stored-text rule, links in forms, one form per table, hidden fields, and the P151 open point (form images and attachment life). Narrows ADR 0003 (form CSS) and amends ADR 0014 decision 3 (slots added to edited forms), ADR 0012 decision 4 (audit actions), and the archive rule of `docs/specs/M3-io.md` section 6 step 6. The format is documented in `docs/formats/tiffin.md` sections 4.6, 12, and 13.3; the implementation design is `docs/specs/M5-forms.md` and `docs/specs/M5-screens.md`; screens are in `docs/design/m5/`.

## Context

M5 gives Admins a simple form editor (reorder, show and hide, width, grouping, labels), a source view with validation, download and upload of a form as a file, and a way back to the standard form (plan section 8; ADR 0003). ADR 0014 already decided that stored form text is never printed: a default-deny parser reads it into a node model, and pages are built from the model by templates. Form format 1 already holds links (`a href`), images (by attachment reference), and placement attributes; M2 to M4 showed links as text and ignored most placement. The session plan (`docs/build/M5-M7-session-plan.md` section 3 rows 2 to 5 and section 6.2) proposed defaults for the open points; the maintainer has not answered them, so they apply unless a defect was found. One was: the schema-change rule of ADR 0014 decision 3 as built gives an edited form a slot for **every** field it does not name, so a field an Admin hides would come back at the next schema change and at every archive import (decision 9).

## Decisions

### 1. The sanitizer is the parser: parse and rebuild, never print

- ADR 0014 decision 4 stands for every path: the record screen, the editor, the source view, upload, download, archive import, and a project file edited by hand. `Core\Forms\FormParser` refuses a whole form on the first rule break; nothing is stripped, repaired, or kept from a refused form. There is no second, lenient path.
- The typed "safe HTML" value that ADR 0008 decision 4 reserved is **not** introduced in v1. No markup from outside the templates reaches a page, so the type would only add a way around the template rule. The reservation in ADR 0008 is withdrawn for v1; M8 may reopen it only by a new ADR.
- Form text reaches a page in exactly one place: as the escaped text content of the source view's `textarea`, and of nothing else. It is never placed in an attribute, never in markup, and never in a response other than the download of decision 8.

### 2. Problems are reported with a line, never with content in logs

`FormRejected` gains the line where the problem was found and, where the format allows it, the element or attribute name involved (only names that look like XML names, at most 40 characters). The source view shows "Line {line}: {message}" with a message key per reason. Logs keep the reason code and the table key only, as now. A rejection message, a log line, and an audit entry never hold any part of the form's text.

### 3. Stored-text rule

Every write of a form stores `serialize(parse(text))`: the canonical text of `FormSerializer`. Comments, spacing, attribute quoting, attribute order, character references, and a leading byte order mark are not kept; the source view says so before and after a save. Property: the stored text parses, and serializing it again gives the same bytes. Two forms with the same meaning therefore always have the same stored text, which makes "editor output equals hand-editable HTML" exact: the editor, the source view, and an uploaded file all produce the same bytes for the same form.

### 4. Links (settles ADR 0009 E2 for links)

From M5 an `a` element is shown as a real link: `<a href="..." rel="noopener noreferrer">` with no `target`. The address is the node model's, which the parser accepted only as an `http` or `https` address by the `url` field type's strict rules; the template helper checks the scheme again and escapes it. A link is followed by the person who clicks it; TiffinDB never fetches it, so plan section 4's "no external URLs" holds for everything TiffinDB loads. A link with no text is not shown. Images stay attachment references only (ADR 0014 decision 1): no form can make a browser load an outside address.

### 5. Form CSS stays reserved (narrows ADR 0003)

`_tiffin_forms.css` stays reserved and ignored in v1. TiffinDB writes the empty string in rows it creates, leaves the value of an existing row as it is when it saves that row's form, and never reads, checks, serves, or applies it. Layout comes only from the placement attributes, shown through classes of the application stylesheet. Per-form styling moves to M8, with the token-drawn choices of ADR 0006. Reasons: a CSS sanitizer is a second attack surface (addresses in `url()`, `@import`, selectors that leave the form); the strict CSP forbids style blocks, so form CSS would need its own serving route; the column exists, so adding CSS later needs no format step. ADR 0003's "plus a scoped CSS block" is narrowed to "plus a reserved CSS column" until the maintainer decides otherwise.

### 6. One form per table

TiffinDB shows and edits only each table's default form (`is_default` 1). Other rows of `_tiffin_forms` are kept untouched, are not listed, and are copied by the archive as before. Editing a table that has no default row creates one (`name` `Standard form`).

### 7. Form images and attachment life (closes the P151 open point)

The simple editor does not create images. Source editing and upload may name an attachment; a save reports each image whose file cannot be shown (not a PNG, JPEG, GIF, or WebP linked from a live record). A form's `img` keeps no attachment alive: P151 stands, and a form image whose file is removed simply shows nothing. A file store owned by forms would be new storage design and is out of v1.

### 8. Download and upload

A form is downloaded as an attachment, `application/octet-stream`, under the sandbox CSP of file responses and `nosniff`, named after the table; it is never shown in a page. Upload takes one file of at most 262,144 bytes (the format's limit), parses it against the table, and stores it under decision 3; nothing is stored when it is refused. Slots that name no field of the table are accepted and reported (`docs/formats/tiffin.md` section 12.4).

### 9. Hidden fields and schema changes (amends ADR 0014 decision 3)

- Hiding a field removes its slot; the editor lists fields with no slot under "Not on this form" with an Add button, and warns when a hidden field is required (requiredness applies only to fields the form shows, P118 and P157).
- When the schema changes, an edited form (`generated` 0) loses slots for fields that no longer exist and **gains a slot only for each field the change itself adds** (a new field, or the new field on each side of a new relation), at the end of its first section. It no longer gains a slot for every field it does not name. `SchemaEditor`'s hook passes the added field keys.
- Archive import (M3) no longer gives an edited default form slots for fields it does not name: a missing slot means "hidden", and an archive round trip keeps it hidden. The report count `forms_extended` keeps its name and now counts edited default forms whose slots for missing fields were removed.

### 10. Widths on the record screen

The simple editor sets `data-tiffin-span` from a short list: automatic (no span), full width (`full`), two thirds (`8`), half (`6`), one third (`4`). Spans are shown through stylesheet classes: a section (or the form's root) that holds a block with a numeric span becomes a 12-column grid, in which each block covers its span and a block without one covers the whole width; when the section is narrower than 40 rem (a container query, so it follows the person's text size) every block covers the whole width. Source order is reading order. `data-tiffin-col` and `data-tiffin-row` are kept and shown in reading order, as before. Sections with no numeric span keep the M2 layout (the `Details` auto-fit grid of P95). No inline style is written (ui-design-plan section 10).

### 11. The `generated` flag and going back to the standard form

A save sets `generated` to 0, unless the saved text equals the generated text for the table's current fields, in which case it stays (or becomes) 1. "Back to the standard form" goes through a confirmation page that offers a download of the current form first, then stores the generated text with `generated` 1. There is no form history in v1 (plan decision 12 gives undo for records only) and no snapshot is taken for form writes.

### 12. Core operations, authorization, audit

- New `Core\App\FormOperations`, reached through `Operations::forms()`. Every method checks `forms.edit` (Admins only, unchanged since M1) before reading anything; a Member is refused in core (`AccessDenied`), an ungranted person and an unknown table get "not found". Controllers make no decision.
- The editor's working copy travels in a hidden field as form text. It is untrusted input: it is parsed by the same parser on every request, and nothing is kept on the server between requests.
- A save carries the hash of the stored text the page started from; when the stored text changed meanwhile, the save is refused once with a plain message, and a second save overwrites.
- Audit actions `form.saved` (detail: table, source `editor`, `source`, or `upload`, and the resulting `generated`) and `form.reset` (detail: table). Neither holds form text. Form writes update the project's `updated_at`. ADR 0012 decision 4 is amended accordingly.

### 13. No format step

Form format 1 and project format version 4 are unchanged. M5 adds no element, attribute, column, or table.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Sanitizer | Strip what is not allowed and keep the rest | The classic sanitizer failure; partial acceptance hides corruption (ADR 0014) |
| Sanitizer | `DOMDocument::loadHTML` plus an allowlist walk | Lenient HTML 4 parser; differs from browsers; needs `dom` |
| Rendering | Introduce the safe-HTML type for the editor preview | No markup needs it; it weakens the template rule |
| Stored text | Keep the text as typed | Two equal forms could differ; "editor output equals source" would not be testable; comments would hold content nobody reviews |
| Problems | Show the offending text in the message | Echoes hostile content into pages and invites logging it; the line and a name are enough |
| Links | Keep showing links as text | ADR 0006 proposes links in text blocks; a link the person follows is not an outside load |
| Links | `target="_blank"` | Opens windows the person did not ask for; `noopener` alone does not make it accessible |
| CSS | A scoped CSS sanitizer in v1 | Second attack surface, own serving route, no v1 need |
| Forms per table | A form chooser | No document describes choosing; nothing in v1 needs it |
| Hidden fields | Keep the slot with a "hidden" attribute | A new attribute is form format 2; a missing slot already means "not shown" (format 12.4) |
| Hidden fields | Leave the M2 rule (every unnamed field gains a slot) | Hidden fields would reappear after any schema change and archive import |
| Editor state | Server-side drafts | New storage, expiry, and cleanup; a hidden copy parsed every time is simpler and stateless |
| Reset | Form history with undo | New storage; plan decision 12 limits undo to records |
| Width | Inline `grid-column` styles | Forbidden by the CSP and ui-design-plan section 10 |
| Width | Media queries on the viewport | `rem` in media queries ignores the person's text size; a container query follows it |

## Consequences

- The parser and the serializer are the whole sanitizer. M5 hardens them with problem positions, a test corpus, a fixed-point property, and seeded mutation tests; their design-tier review covers every entry path.
- Hidden fields survive schema changes and archive round trips. The M3 rebuild test that expected an edited form to gain a slot changes with this decision.
- Admins see real links on the record screen. Members see them too; they cannot change them.
- Form CSS, form history, several forms per table, and a form-owned file store remain open for M8 or later.

## Costly to reverse

The stored-text rule once Admins have saved forms (their comments and spacing are gone), and the meaning of a missing slot. The width classes, the editor's controls, and the audit detail are code only.
