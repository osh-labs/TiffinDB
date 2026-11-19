# M2 screen design: list view, filter, search results

Status: design for milestone M2. The list view follows `docs/design/model-ui/list.html`; the filter page and search results have no mock-up (ui-design-plan section 6.2). Logic: `docs/specs/M2-records.md` sections 3, 4, and 7. Implemented by M2-WP6 with the components of M2-WP5. Strings: `strings/en/lists.php` (WP6) unless marked as shared (`strings/en/components.php`, WP5). Example data is synthetic.

## 1. Overview

| # | Screen | URL | Who | Template |
|---|---|---|---|---|
| 2 | List view | `GET /projects/{p}/tables/{t}` with `<state>` | Admin; Member with a grant | `templates/pages/records/list.php` |
| 3 | Filter | `GET /projects/{p}/tables/{t}/filter` with `<state>` and optionally `field` | the same | `templates/pages/records/filter.php` |
| 4 | Search results | `GET /projects/{p}/search?q=` | the same | `templates/pages/search.php` |

`GET /projects/{p}` now redirects to the list view of the project's first table (by position), carrying no state; with no tables it redirects to `/projects/{p}/tables` as in M1 (completes M1 provisional decision P43).

Shell: signed-in shell with the project search in the top bar (shared partial `top-search`, section 5) and the sidebar (section 6). `nav` is `''` (no top-nav item is current). Permissions: every screen needs `records.view`; a Member without a grant gets 404, as in M1.

## 2. List view

Page title (`<title>`): `lists.page_title` "{table}, {project}".

`main#main.page.page-list`, in reading order:

1. Flash notice (shared `notice` partial; the trash notice carries an Undo button, `states.md` section 4).
2. `div.page-head`:
   - `div`: `h1` the table name; `p.lead`:
     - no search and no filter: `lists.lead` "{count|# record|# records}, sorted by {sort}", where `{sort}` is the label of the current sort option exactly as in the menu (for example "48 records, sorted by Name, A to Z"; the mock-up's shorter "sorted by name" is deviation D-2);
     - with a search or a filter: `lists.lead_matching` "{count|# record matches|# records match} out of {total}, sorted by {sort}".
   - `div.page-actions`: `a.button.button-primary` to `.../records/new?<state>` holding the `button-icon` "+" SVG and `lists.add` "Add {item}". Absent when the table has no fields at all.
3. `form.list-controls` (`method="get"`, `action` = the list URL), only when the table has at least one live record:
   - Hidden inputs carrying the current filter (`filter`, `op`, `v`, `v2`) when one is active. `page` is never carried (a new search starts at page 1).
   - `div.list-find`: `label.label.label-small` for `find`: `lists.find.label` "Find in {table}"; `input#find.input.input-compact` `type="search"` `name="q"`, value = the current search, `placeholder` `lists.find.placeholder` "Type part of a name or other text", `maxlength="200"`.
   - `div.list-sort`: `label.label.label-small` for `sort`: `lists.sort.label` "Sort by"; `select#sort.input.input-compact` `name="sort"`: the sort options of `docs/specs/M2-screens.md` section 5.3, the current one `selected`.
   - `button.button` `type="submit"`: `lists.apply` "Apply".
   - `a.button` to `.../filter?<state>`: `lists.filter.open` "Filter".
4. With an active filter: `div.notice.filter-bar` holding `p` `lists.filter.active` "Showing only records where {condition}." and `a` to the list URL without the filter (search and sort kept): `lists.filter.remove` "Remove the filter". `{condition}` is one of:

| Operator | Key | Text |
|---|---|---|
| choice `is` | `lists.cond.is` | {field} is {value} |
| any `empty` | `lists.cond.empty` | {field} is empty |
| checkbox `is` 1 | `lists.cond.ticked` | {field} is ticked |
| checkbox `is` 0 | `lists.cond.not_ticked` | {field} is not ticked |
| relation `links-to` | `lists.cond.links_to` | {field} is {title} |
| date `between`, both ends | `lists.cond.between` | {field} is from {from} to {to} |
| date `between`, start only | `lists.cond.from` | {field} is on or after {from} |
| date `between`, end only | `lists.cond.to` | {field} is on or before {to} |

   Dates in conditions use the display format ("14 Mar 2025"). A filter the core could not use (`docs/specs/M2-records.md` section 4.3) shows instead `div.notice.notice-warn` with `lists.filter.invalid` "The filter could not be used, so every record is shown." followed by the problem text, and the list is unfiltered.
5. A search text the core refused (too long, control characters) shows `p.error-text#find-error` under the find box with the problem text, `aria-describedby="find-error"` and `aria-invalid="true"` on the find box, and the list unsearched.
6. With matching records: `div.table-wrap` > `table.data-table.data-table-plain` (shared `record-table` partial):
   - `caption.visually-hidden`: `lists.caption` "{table}, {from} to {to} of {total} shown".
   - `thead`: one `th scope="col"` per list column (`docs/specs/M2-records.md` section 7.2: the title field first, then up to six more fields). The sorted column has `aria-sort="ascending"` or `"descending"` and its text is `lists.col.sorted` "{field} ({direction})", where `{direction}` is the short direction word of section 5.3 of the screens spec ("A to Z", "newest first"). Columns of numbers, money, durations, and auto-numbers are `th.num`.
   - `tbody`: one row per record. First cell `th scope="row"` holding `a.cell-title` to `.../records/{r}?<state>` with the record's title (display text) or, when the table has no title field or the title is empty, `lists.untitled` "{Item} {id}". Other cells `td` (`td.num` for the numeric types) with the shared `field-value` partial in `cell` mode (`docs/specs/M2-screens.md` section 5); empty attachment and relation cells show `span.cell-none` `value.none` "None"; other empty cells are empty.
   - Without a title field the first column's header is `lists.col.record` "{Item}" and its cells read "Item 12".
7. `nav.pager` with `aria-label` `pager.label` "Pages of records" (shared), when there is at least one match: `span.pager-status` `pager.showing` "Showing {from} to {to} of {total}" (shared); `div.pager-links`: "Previous" and "Next" (`pager.previous`, `pager.next`, shared). A link exists when there is such a page: `a.button.pager-link` with `rel="prev"` or `rel="next"` to the same URL with `page` changed. Otherwise `span.button.pager-link` with `aria-disabled="true"` (not focusable; the dashed disabled style of M1). On a single page both are disabled, as in the mock-up's first page.

### 2.1 Empty states

| State | Shown instead of items 3 to 7 | Strings |
|---|---|---|
| Table has fields but no live records | `div.empty-state`: `p.empty-state-title` and `p` | `lists.empty.title` "No records yet"; `lists.empty.body` "Select Add {item} to add the first one." |
| Table has no fields | `div.empty-state`; for an Admin, `a` to `.../tables/{t}/fields/new` | `lists.nofields.title` "This table has no fields yet"; `lists.nofields.admin` "Add fields to say what each record holds."; `lists.nofields.link` "Add a field"; `lists.nofields.member` "An Admin needs to add fields before records can be added." |
| Search or filter matches nothing (items 3 to 5 stay) | `div.empty-state` after the controls | `lists.none.title` "Nothing matches"; `lists.none.search` "No records match “{q}”."; `lists.none.filter` "No records match the filter."; `lists.none.both` "No records match “{q}” with this filter."; `a` to the list URL with only `sort` kept: `lists.none.clear` "Show every record" |

With records in the trash only, the table counts as having no live records (first row); the sidebar's trash count shows that they exist.

### 2.2 Keyboard and screen reader

- Tab order: skip link, top bar (brand, Projects, project search, Display, user chip, Sign out), sidebar links, Add item, find box, sort menu, Apply, Filter, Remove the filter, title links row by row, Previous, Next, footer. No positive `tabindex`.
- Enter in the find box submits the controls form (implicit submission). The sort menu changes nothing until Apply (no script submits on change; WCAG 3.2.2).
- The table caption names the table and the shown range; the sorted header carries `aria-sort`.
- After Apply or paging the page reloads with focus at the top, as for every M1 page.

### 2.3 Narrow screens

At 390 px the sidebar is a full-width block above `main`; the controls wrap (find box, then sort menu, then the two buttons); the table scrolls inside `div.table-wrap`; the pager wraps. At 320 px with the Largest text size nothing scrolls the page sideways.

## 3. Filter

`main#main.page` (no sidebar; breadcrumb instead, ADR 0009 row A3). Page title `lists.filter.title` "Filter {table}".

1. Breadcrumb (shared partial): project name (to `/projects/{p}`), table name (to the list with `<state>`), `lists.filter.crumb` "Filter" (current).
2. `div`: `h1` `lists.filter.title` "Filter {table}"; `p.lead` `lists.filter.lead` "Show only the records that match one condition."
3. When the table has no filterable field (choice, checkbox, relation, date, date and time): `p.notice` `lists.filter.none` "Filters work on choice, checkbox, link, date, and date-and-time fields. This table has none yet." and `a` to the list with `<state>`: `lists.back` "Back to the list". Nothing else.
4. Step 1, when `field` is missing or not a filterable field of the table: `form.form.form-card.form-wide` (`method="get"`, `action` = this page): hidden `q` and `sort` from the state; field `filter-field`: `label` `lists.filter.field` "Field"; `select#filter-field.input` `name="field"`: one option per filterable field in field order, text `lists.filter.field_option` "{field} ({type})" with the type label (`type.<id>`, M1); the field of an active filter is selected. `div.form-actions` (shared `form-actions`): primary `lists.filter.next` "Next"; Cancel to the list with `<state>`.
5. Step 2, when `field` is a filterable field: `form.form.form-card.form-wide` (`method="get"`, `action` = the list URL): hidden `q`, `sort`, and `filter` = the field key; then `fieldset` with `legend.legend-plain` `lists.filter.where` "Show records where {field}":

| Field type | Controls (ids literal; at most one step-2 form per page) |
|---|---|
| choice | `label.check`: `input#filter-op-is type="radio" name="op" value="is"` (checked unless the active filter is `empty`) and `lists.filter.op.is` "is"; `div.field` with `label` for `filter-value` `lists.filter.value` "Choice" and `select#filter-value.input name="v"` listing the choices in order; `label.check`: radio `op` = `empty`, `lists.filter.op.empty` "is empty" |
| checkbox | hidden `op` = `is`; `label.check` radio `v` = `1` `lists.filter.op.ticked` "is ticked" (checked by default); `label.check` radio `v` = `0` `lists.filter.op.not_ticked` "is not ticked" |
| relation | radio `op` = `links-to` `lists.filter.op.links_to` "links to"; `div.field` with `label` for `filter-value` `lists.filter.record` "Record" and `select#filter-value.input name="v"` listing live records of the linked table by title (option value the record id; untitled records as "{Item} {id}"), at most 500, then, when there are more, `p.help` `lists.filter.record_limit` "Only the first 500 are listed."; radio `op` = `empty` `lists.filter.op.no_links` "has no link" |
| date, date and time | radio `op` = `between` `lists.filter.op.between` "is in this range"; two `div.field`s: `label` for `filter-from` `lists.filter.from` "From" with `input#filter-from.input.input-short type="date" name="v"`, `label` for `filter-to` `lists.filter.to` "To" with `input#filter-to.input.input-short type="date" name="v2"`; `p.help` `lists.filter.range_help` "Leave one empty for an open range."; radio `op` = `empty` "is empty" |

   For date-and-time fields the range is by day: `v` and `v2` are dates and cover whole days. `div.form-actions`: primary `lists.filter.submit` "Show matching records"; Cancel to the list with `<state>`. Below, `a` to this page without `field`: `lists.filter.other` "Choose another field".
6. Values of an active filter on the same field are pre-selected.

The filter page never changes anything; both steps are GET. Without JavaScript both steps work; there is no script on this page. Errors in step 2 (for example a range with both ends empty) are reported on the list (section 2 item 4), because the list receives the request.

## 4. Search results

`main#main.page.page-list` with the sidebar (no table current). The top bar's search box holds the search text. Page title `search.page_title` "Search results, {project}".

1. `div.page-head` > `div`: `h1` `search.title` "Search results"; `p.lead`:
   - with matches: `search.lead` "{count|# record matches|# records match} “{q}”";
   - with no search text: `search.lead_empty` "Type a word in the search box at the top of the page to look through every table in {project}."
2. A refused search text: `div.notice.notice-warn` with the problem text; no results.
3. For each table with at least one match, in table order: `section.result-group` with `aria-labelledby` = `$this->id('group', <table key>)`: `h2.section-title` with that id: `search.group` "{table}: {count|# match|# matches}"; `ol.result-list`, at most 10 items: `li.result` > `a.result-title` to `/projects/{p}/tables/{t}/records/{r}?q=<q>` with the title (or "{Item} {id}"), then `p.result-match` `search.match` "{field}: {excerpt}" for the first matching field that is not the title field (absent when only the title matched). `{excerpt}`: the matching value as one line (line breaks become spaces), at most 120 characters: when longer, cut to start up to 40 characters before the match, with "…" at a cut end. When the table has more matches than shown: `a.result-more` to the table's list with `q`: `search.more` "Show all {count} in {table}".
4. No matches: `div.empty-state`: `p.empty-state-title` `search.none.title` "Nothing found"; `p` `search.none.body` "No records in {project} match “{q}”. Check the spelling, or try a shorter word."

Keyboard: the result links in order, then "Show all" links; headings let a screen-reader user jump between tables. Without JavaScript: unchanged. Narrow screens: the sidebar stacks above; results are full width.

## 5. Project search in the top bar (shared partial, WP5)

On the list view, the filter page, and search results only (ui-design-plan section 5). The mock-up has no main navigation on the list view; the application keeps M1's `nav.top-nav` after the brand link, so the order is brand, Projects (and People for Admins), search, account navigation (deviation D-1 in `docs/specs/M2-screens.md` section 11.3).

`form.top-search` `role="search"` (`method="get"`, `action` = `/projects/{p}/search`): the magnifier SVG (`span.top-search-icon` wrapper not needed; SVG with `aria-hidden="true"` and class `top-search-icon`); `input.top-search-input` `type="search"` `name="q"` `maxlength="200"`, `aria-label` `shell.search.label` "Search all tables in {project}", `placeholder` `shell.search.placeholder` "Search {project}", value = the current search on the results page only. No submit button: Enter submits (single text input). The `id` is literal `top-search`.

## 6. Sidebar (shared partial, WP5)

`nav.sidebar` with `aria-label` `sidebar.label` "Project", in order:

1. `div.sidebar-project`: `span.sidebar-label` `sidebar.project` "Project"; `span.sidebar-project-name` the project name; `a.sidebar-switch` to `/projects`: `sidebar.switch` "Switch project".
2. `div.sidebar-group`: `h2.sidebar-heading` `sidebar.tables` "Tables"; one `a.sidebar-link` per table in table order, to its list (no state), holding `span` the table name and `span.sidebar-count` the live record count (`Format::number`); the current table has `aria-current="page"` (on the list view and the filter page). Each link's accessible name is its text ("Items 48").
3. `div.sidebar-foot`: `a.sidebar-link` to `/projects/{p}/tables`: `sidebar.schema` "Tables and fields"; `div.sidebar-item` holding `span` `sidebar.trash` "In the trash" and `span.sidebar-count` the number of records in the trash across the project.

Not in M2, and absent: the mock-up's Collections group and "New collection" (M4), and Import and Export (M3). The trash count is not a link until the trash screen exists (M4).
