# M2 screen design: empty states, error pages, confirmation pages, notices

Status: design for milestone M2 (ui-design-plan section 6.2: "Empty states, error pages, confirmation dialogs"). These are shared patterns; the screens that use them are listed with each. Strings are in the file of the package that renders them; shared strings are in `strings/en/components.php` (WP5).

## 1. Empty states

An empty state replaces content that does not exist yet and says what to do next, in plain words, without a second primary button (the page's one primary action is usually the way out).

Markup (shared partial `empty-state`, WP5): `div.empty-state` holding `p.empty-state-title` (the situation), `p` (what to do), and optionally one `a` (a secondary way out). Data: `title` (key), `titleParams`, `body` (key), `bodyParams`, optional `link` `{label, labelParams, path, query}`.

| Where | Situation | Document |
|---|---|---|
| List view | No records; no fields; nothing matches | `list-and-search.md` section 2.1 |
| Search results | Nothing found; no search text | `list-and-search.md` section 4 |
| Filter | No filterable fields (uses `p.notice`, M1 pattern, because the page has nothing else) | `list-and-search.md` section 3 |
| Record form | Relation with no links, attachment field with no files (`p.static-value`, inline) | `record.md` section 2.1 |
| Project with no tables | Unchanged from M1 (`docs/design/m1/schema-editor.md` section 3); `/projects/{p}` redirects there | `list-and-search.md` section 1 |

## 2. Error pages

M1's error page (`templates/pages/error.php`: title, one sentence, link to the start page, signed-in shell when signed in) stays the pattern for every error without a screen of its own. M2 adds no new generic error page; it adds two specific pages:

| Status | When | Page |
|---|---|---|
| 404 | Unknown or invalid project, table, or record; record in a table of another project; Member without a grant | M1 `error.404.*` page |
| 200 | Record in the trash | `record.md` section 4 (a page of its own, because the record can be restored) |
| 403 | Not used by M2 screens (every record permission is held by every person with access) | M1 |
| 409 | Edit conflict | `record.md` section 5 |
| 422 | Validation on the record form or display settings | the same form with the error summary (M1 section 4.5) |
| 503 | `Busy` (another save is in progress) or `Unavailable` (project file missing, damaged, newer) | M1 `error.busy.*`, `error.unavailable.*` pages, unchanged |

A project file at format version 1 is upgraded when it is first opened in M2 (after a snapshot); this needs no page. If the upgrade fails, the M1 `Unavailable` page applies (`docs/specs/M2-records.md` section 6).

## 3. Confirmation pages

The M1 rule stands: a change that cannot be undone goes through a confirmation page first (`docs/specs/M1-screens.md` section 4.5 item 7). M2's only new change of that kind is "Save my version anyway" on the conflict page, which is itself the confirmation step. Moving a record to the trash can be undone and has no confirmation page (ADR 0013 decision 8).

The pattern every confirmation page follows (already used by M1's delete-table, delete-field, and clear-values pages, and by the conflict page):

1. Breadcrumb ending in the thing affected.
2. `h1` states the consequence as a question or statement ("Delete the table Items?", "This item was changed while you were editing it").
3. One paragraph saying exactly what will happen, with counts where they matter, using the plural placeholder so that "1 value in 1 record" reads correctly.
4. Where a count was shown, the form posts it back, and the core asks again if the real effect is larger (P82 fix, M2-WP8; `docs/specs/M2-records.md` section 7.5).
5. Actions: the destructive button (`.button-danger.button-large`) and a way back (`a.button` or the M1 Cancel link). The safe choice is never the danger style.

## 4. Notices and undo

Flash notices keep the M1 `notice` partial (`div.notice`, `role="status"`). M2 adds one variant, used after a record is moved to the trash:

- Flash data: key `notice.record_trashed`, params `{title}` and `undo`, where `undo` is the path `/projects/<project key>/tables/<table key>/records/<id>/restore` without the base path or query.
- `Shell` accepts `undo` only when it matches `^/projects/[a-z2-7]{12}/tables/[a-z2-7]{12}/records/[1-9][0-9]{0,18}/restore\z`; any other value is dropped (the notice then has no button).
- Markup: `div.notice.notice-row` `role="status"`: `p` the message; `form.notice-form` (`method="post"`, `action` = `$this->url(undo)`): `csrf`; `button.button` `type="submit"` `notice.undo` "Undo" with `aria-label` `notice.undo_label` "Undo: restore {title}".
- The restore redirects to the record page with the notice `notice.record_restored` (`record.md` section 4).

The undo works without JavaScript; the button is reachable by Tab right after the skip link and top bar on the list page.
