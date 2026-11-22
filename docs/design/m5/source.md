# M5 screen design: source view, download, upload

Status: design for milestone M5 (ui-design-plan section 6.2, "source view"; no mock-up). Logic: `docs/specs/M5-forms.md` sections 4, 5, 9.1, 9.5; ADR 0020 decisions 1 to 3 and 8; P190, P191, P203. Implemented by M5-WP6 (pages) on the components of M5-WP3. Page strings: `strings/en/form-source.php` (WP6). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Source view | `GET /projects/{p}/tables/{t}/form/source` | 200 |
| Check | `POST .../form/source` with `do=check` | 200; 422 when refused |
| Save | `POST .../form/source` with `do=save` | 303 to the source view; 422 when refused or changed meanwhile |
| Download | `GET .../form/download` | 200 file |
| Upload page | `GET .../form/upload` | 200 |
| Upload | `POST .../form/upload` (multipart, `file`) | 303 to the source view; 422 when refused |

Shell: breadcrumb Project / Tables / Items / Form (to the editor) / `forms.source.crumb` "Source" (or `forms.upload.crumb` "Upload"); layout `form` (source) and `form-narrow` (upload).

## 2. Source view, in reading order

1. Page head: `h1` `forms.source.title` "Form source for {table}"; `p.lead` `forms.source.lead` "The form as text, in TiffinDB's form format (described in the format documentation, section 12). Check finds problems; Save uses the text. TiffinDB keeps its own tidy copy of what you save: comments and spacing are not kept."
2. Form tools (`forms/tabs`, current `source`).
3. State note, one of:
   - No stored form yet: `p.notice.notice-muted` `forms.source.generated` "This is the standard form, made from the table's fields. It is stored the first time you save."
   - Stored form refused: `div.notice.notice-warn`: `p.notice-title` `forms.source.refused_title` "The saved text has a problem"; `p` the problem as "Line {line}: {message}" (section 4); `p` `forms.source.refused` "Records use the standard layout until it is fixed. Fix the text below and save."
4. `form#form-source` (POST, `csrf`, hidden `base`):
   1. `label.label` `for="source"` `forms.source.label` "Form source"; `p.help#source-help` `forms.source.help` "Up to 256 KB. Element and attribute names are lower case. Links must start with http:// or https://."
   2. `textarea#source.input.source-input` `name="text"` `rows="24"` `spellcheck="false"` `autocapitalize="off"` `autocomplete="off"` `wrap="off"` `dir="ltr"` `aria-describedby` `source-help` and, when refused, `source-error`; content: the text, escaped (stored text, generated text, or the typed text after a refusal or a check). With a refusal: `aria-invalid="true"` and `p.field-error#source-error` with the problem line (section 4).
   3. `div.form-actions`: `button.button.button-primary.button-large` `do=save` `forms.source.save` "Save"; `button.button.button-large` `do=check` `forms.source.check` "Check"; `a.button.button-large` `form.cancel` to the editor.
5. After Check with no problem, or after Save: `div.notice` `role="status"` `forms.source.checked` "No problems found." then the report (`forms/report`, the full version including the fields not shown).

The Save button comes first because it is the page's one primary action; Check is offered beside it. Enter in the text area inserts a line break (no implicit submission).

## 3. Results

| Action | Result |
|---|---|
| Check, accepted | 200; "No problems found."; the report; the text as typed |
| Check or Save, refused | 422; layout error summary with one item, "Line {line}: {message}" linking to `#source`; the same text under the box; the text as typed; nothing stored |
| Save, accepted | 303 to the source view with `notice.form_source_saved` "The form for {table} has been saved." or, when the stored text differs from the typed one, `notice.form_source_rewritten` "The form for {table} has been saved. TiffinDB rewrote the text in its own layout: comments and spacing were not kept." (or `notice.form_source_unchanged` "Nothing had changed, so the form was not saved again." when nothing changed) |
| Save after someone else saved | 422; summary `problem.form.changed`; the typed text kept; the next Save replaces |

## 4. How a problem is written

The core gives a message key, its parameters, and a line (`docs/specs/M5-forms.md` section 4). The controller writes:

| Key | Text |
|---|---|
| `forms.source.at_line` | Line {line}: {message} |
| `forms.source.near_line` | Near line {line}: {message} |
| `forms.source.no_line` | {message} |

`near_line` for the reasons `text`, `text-value` (text), `content` (text), `node-type`; `no_line` when the line is null (too large, empty). `{message}` is the problem text with its parameters (`{element}`, `{attribute}`, `{max}`); the element and attribute names are escaped like every value. Example: "Line 7: The element p cannot have the attribute onclick, or is missing one it needs."

The page never repeats any other part of the refused text outside the text area.

## 5. Download

The "Download the form" link of the form tools. Response: `200`, `Content-Type: application/octet-stream`, `Content-Disposition: attachment; filename="Items form.html"` (cleaned by `Response::contentDisposition`, M4), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, the sandbox CSP of file responses. Body: the stored text exactly (also when it is refused), or the standard form's text when there is none. No page is shown; the browser saves the file.

## 6. Upload page, in reading order

1. Page head: `h1` `forms.upload.title` "Upload a form file for {table}"; `p.lead` `forms.upload.lead` "If TiffinDB can read the file, it replaces the form for {table} at once. Download the current form first if you may want it back."
2. Form tools (`forms/tabs`, current `upload`).
3. `form#form-upload` (POST, multipart, `csrf`): `label.label` `for="file"` `forms.upload.file` "Form file"; `p.help#file-help` `forms.upload.help` "One file of at most 256 KB, in TiffinDB's form format, saved as UTF-8."; `input#file.input` `type="file"` `name="file"` `accept=".html,.htm,.txt"` `aria-describedby` `file-help` (and the error id); `div.form-actions`: `button.button.button-primary.button-large` `forms.upload.submit` "Upload and use it"; `a.button.button-large` `form.cancel` to the editor.

| Result | |
|---|---|
| Accepted | 303 to the source view with `notice.form_uploaded` "The file is now the form for {table}." or `notice.form_uploaded_rewritten` "The file is now the form for {table}. TiffinDB rewrote it in its own layout: comments and spacing were not kept." |
| No file, upload error, empty, over 256 KB | 422; the error summary and the field error (`problem.upload.missing`, `problem.upload.failed`, `problem.form.empty_file`, `problem.form.too_large`); `p` `forms.upload.nothing_changed` "Nothing was changed." |
| Refused by the form rules | 422; "Line {line}: {message}" as section 4, tied to the file input; "Nothing was changed." The file's text is not shown |

## 7. Keyboard and screen reader

Source view: Tab order breadcrumb, form tools, the text area (Tab inside the text area moves focus on, as browsers do; the help says nothing else), Save, Check, Cancel. After a refusal the error summary is focused (layout, M2) and its link moves focus to the text area. The text area's name is "Form source"; its problem is in its description. Upload: as the M4 upload page; the file dialog is the browser's.

## 8. Narrow layout

The text area keeps fixed-width text and scrolls sideways inside itself; the page does not. Buttons wrap. At 320 px Largest the text area is the full width and at least 20 lines high.
