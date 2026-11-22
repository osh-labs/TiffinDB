# M5 screen design: back to the standard form

Status: design for milestone M5 (confirmation page; plan section 6.2 row 8; no mock-up). Logic: `docs/specs/M5-forms.md` section 9.1 (`resetScreen`, `reset`); ADR 0020 decision 11; P204. Implemented by M5-WP5. Strings: `strings/en/form-editor.php` (WP5). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Confirmation | `GET /projects/{p}/tables/{t}/form/reset` | 200 |
| Reset | `POST /projects/{p}/tables/{t}/form/reset` | 303 to the editor |

Shell: breadcrumb Project / Tables / Items / Form / `forms.reset.crumb` "Standard form"; layout `form-narrow`. Reached from the form tools' "Back to the standard form".

## 2. Confirmation, in reading order (M2 confirmation pattern)

1. `h1` `forms.reset.title` "Go back to the standard form?"
2. Form tools (`forms/tabs`, current `reset`).
3. When the table already has the standard form: `p.notice.notice-muted` `forms.reset.already` "The form for {table} is already the standard form." and only `a.button.button-large` `forms.reset.back` "Back to the form" to the editor. Nothing else.
4. Otherwise:
   1. `p` `forms.reset.body` "The form for {table} will be replaced by the standard form: every field in field order, in the sections TiffinDB makes. Labels, widths, hidden fields, sections, headings, and text you added will be gone. There is no undo."
   2. `p` `forms.reset.download_intro` "To keep a copy you can upload again later, download the current form first."; `a.button` `forms.reset.download` "Download the current form" (the download URL; a secondary button-styled link).
   3. `form` POST `.../form/reset` with `csrf`; `div.form-actions`: `button.button.button-danger.button-large` `forms.reset.submit` "Use the standard form"; `a.button.button-large` `form.cancel` to the editor.

## 3. Results

| Action | Result |
|---|---|
| Use the standard form | 303 to the editor with `notice.form_reset` "The form for {table} is the standard form again." |
| Already standard (someone reset meanwhile, or a repeated post) | 303 to the editor with `notice.form_reset_unchanged` "The form for {table} was already the standard form." |

## 4. Keyboard and screen reader

Tab order: breadcrumb, form tools, "Download the current form", "Use the standard form", "Cancel". The destructive button is styled as such and named by its text. No script.

## 5. Narrow layout

Buttons stack at 390 px; no sideways scroll at 320 px Largest.
