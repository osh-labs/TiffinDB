# M4 screen design: relation picker

Status: design for milestone M4 (ui-design-plan section 6.2, "Relation picker ("Choose a different room", "Link a warranty")"; no mock-up). Logic: `docs/specs/M4-core.md` sections 4.3, 4.4, 10.1; ADR 0019 decisions 9 and 11. Implemented by M4-WP6. Strings: `strings/en/links.php` (WP6). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Picker | `GET /projects/{p}/tables/{t}/records/{r}/links/{f}` with optional `q` (find text) and `page` | 200; 422 after a refused link |
| Link or choose | `POST .../links/{f}/add` with `target`, `back`, `q`, `page` | 303 to the picker (with `q`, `page`) |
| Unlink | `POST .../links/{f}/remove` with `target`, `back` (`picker` or `record`), `q`, `page` | 303 to the picker, or to the record when `back=record` |

Shell: signed-in shell, breadcrumb (project name to `/projects/{p}`; table name to the list; the record's title to the record; `links.crumb` "Choose {items}" where `{items}` is the other table's name), `main#main.page.page-picker`. Open to Admins and granted Members (`records.edit`). A record in the trash, a field that is not a relation field of the table, and an ungranted person: 404.

Page title: `links.title` "{field} for {title}" (for example "Room for Stand mixer", "Warranties for Stand mixer"), where `{field}` is the field's label (a title such as "Choose a room" is not used because the English article cannot be chosen from a table's item name, `components.md` section 3.1).

## 2. Page

In reading order:

1. Breadcrumb; flash notice; error summary at 422 (shared).
2. `div.page-head`: `h1` `links.title`; `p.lead`:
   - one link allowed: `links.lead_one` "Choose one {other item}. Choosing replaces the current one." (`{other item}` the other table's item name);
   - many allowed: `links.lead_many` "Link as many as you need. Each press saves at once."
3. `section.picker-section` `aria-labelledby="pk-now"`: `h2#pk-now` `links.now.heading` "Linked now";
   - with live links: `ul.picker-list`; per linked record `li.picker-row`: `span.picker-title` with `a` to the linked record (its title or "{Item} {id}"); `form.inline-form` POST `.../links/{f}/remove` with `csrf`, hidden `target`, `back=picker`, `q`, `page`, and `button.button.button-plain` `links.unlink` "Unlink", `aria-label` `links.unlink_label` "Unlink {title}".
   - without: `p.static-value` `links.now.none` "Nothing is linked yet."
   - when the record also links records in the trash: `p.help` `links.now.trashed` "{count|# linked record is|# linked records are} in the trash. Links to records in the trash come back when they are restored." For a one-link field this replaces the Choose buttons below with `p.notice.notice-warn` `links.now.trashed_one` "The linked {other item} is in the trash. Restore it and unlink it here, or empty the trash, before choosing another." and the candidates are listed without buttons.
4. `form.picker-find` (GET, `action` this page, no CSRF): `div.field` with `label.label` for `id('f', 'q')` `links.find.label` "Find in {items}" and `input type="search" name="q"` with the current text (`maxlength` 200); `button.button` `links.find.button` "Find"; when `q` is set, `a` `links.find.clear` "Show all" to the page without `q`. A refused search (too long, control characters) shows the problem under the box (422 is not used for a GET: the page is 200 with the problem text, as the M2 list).
5. `section.picker-section` `aria-labelledby="pk-list"`: `h2#pk-list`:
   - without `q`: `links.list.heading` "{items} you can link" with `span.help` `links.list.count` "{count|# record|# records}";
   - with `q`: `links.list.heading_found` "Found in {items}" with the same count.
   - `ul.picker-list`; per candidate `li.picker-row`: `span.picker-title` with `a` to the candidate record (its title or "{Item} {id}") and, for a one-on-the-other-side relation where the candidate is linked elsewhere, `span.picker-taken` `links.taken` "Linked to {title}" in place of a button; otherwise `form.inline-form` POST `.../links/{f}/add` with `csrf`, hidden `target`, `back=picker`, `q`, `page`, and `button.button.button-plain`:
     - one link allowed: `links.choose` "Choose", `aria-label` `links.choose_label` "Choose {title}";
     - many: `links.link` "Link", `aria-label` `links.link_label` "Link {title}".
   - empty list: `p.static-value` `links.list.none` "No {items} left to link." (without `q`), or `links.list.none_found` "Nothing in {items} matches \"{q}\"." (with `q`).
   - `pager` partial (M2) when there is more than one page, keeping `q`.
6. `div.form-actions` (M1): `a.button.button-primary.button-large` to the record (no state): `links.back` "Back to {title}"; `a.button.button-large` to the other table's new-record form with `from={f}-{r}`: one link allowed `links.add_new_one` "Add a new {other item} and choose it", many `links.add_new` "Add a new {other item} and link it". The add link is shown only when the person can edit the other table's records (always true in v1 for Admins and granted Members).

One primary action: "Back to {title}" (every link action already saved).

## 3. Results

| Action | Result | Flash |
|---|---|---|
| Link | 303 to the picker | `notice.link_added` "{title} is now linked." |
| Choose (one link) | 303 to the picker | `notice.link_chosen` "{title} is now chosen." |
| Unlink (`back=picker`) | 303 to the picker | `notice.link_removed` "{title} is no longer linked." (shared with the record page, `records.php`) |
| Link refused | 422, the picker with the error summary | `problem.link.target_taken` (M2), `problem.link.target_missing` (M2), `problem.link.trashed_holds` (M4) |
| Target id not canonical, not a record of the other table | 404 | |

The page and find text are kept on every 303. A candidate linked by the press disappears from the candidate list and appears under "Linked now".

## 4. Add a new record and link it

`GET /projects/{p}/tables/{other}/records/new?from={f}-{r}`: the new-record form of the other table (`record-controls.md` section 6). Saving creates and links in one transaction and answers 303 to this picker with `notice.link_added_new` "{title} has been added and linked." (`records.php`). Cancel returns here.

## 5. Keyboard and screen reader

Tab order: breadcrumb, Unlink buttons of "Linked now", find box, Find, Show all, each candidate's title link then its Link or Choose button, the pager, "Back to {title}", "Add a new ...". After a press, the page reloads at the top with the notice (`role="status"`); the person Tabs on. Every button names its record (section 2). No positive `tabindex`; no script.

## 6. Narrow layout

Rows wrap: the title on its own line and the button under it at 390 px; the find box takes the full width with the button under it. No sideways scroll at 320 px Largest.
