# ADR 0013: Record layer: paging, sort, filter, search, lost updates, saving

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the record-layer questions the M2 session plan (`docs/build/M2-M3-session-plan.md` section 6.1) leaves to the M2 design, and section 3 question 3 (search). Implementation design: `docs/specs/M2-records.md` sections 3 and 4 and `docs/specs/M2-screens.md`. Every value below is a constant or a query-string convention, chosen as the option cheapest to reverse (orchestration guide section 5); the provisional decision numbers are listed in `docs/specs/M2-records.md` section 13.

## Context

Plan section 8 gives M2 the list view, record create, read, update, and move to trash, search and sort; plan section 3.1 places record CRUD, search, sort, and filter in `core/query`. No document fixes how many records a page shows, how values sort, which fields a search looks at, what a filter can express, what happens when two people edit the same record, or when a record is saved. The model UI (`list.html`, `record.html`) shows a find box, a sort menu, a filter button, paging, record paging ("Item 9 of 48"), and an "All changes saved" line suggesting autosave.

## Decisions

### 1. Where it lives

Record reading and writing is `TiffinDB\Core\Query\Records`, beside the M1 `Trash`. It takes no actor; `TiffinDB\Core\App\RecordOperations` checks `records.view` or `records.edit` (M1 permission table) and calls it. The web layer never names a `Core\Query` class: view state from the query string is parsed by `Core\App\ListRequest`, so the later MCP adapter reads lists the same way.

### 2. Paging

50 records per page. The page number is the query parameter `page` (from 1); a number past the last page shows the last page. On the record screen, "Previous" and "Next" follow the order, filter, and search of the list the person came from, carried in the record link's query string; the position line reads "Item 9 of 48" in that list. A record that no longer belongs to that list shows no position and no paging links.

### 3. Sort

- One sort at a time, query parameter `sort` with the value `<field key>.asc`, `<field key>.desc`, `added.asc`, `added.desc`, or `changed.desc`. Anything else falls back to the default.
- Default: the table's title field from A to Z; with no title field, the order records were added.
- Every field type sorts except attachment. Text-like values sort without regard to the case of ASCII letters (SQLite `NOCASE`); numbers, money, ratings, durations, and auto-numbers numerically; dates and times chronologically; checkboxes unticked first when ascending; choices in the order the choices are listed in the field; relation fields by the lowest stored title among the linked live records (only when the linked table has a title field).
- Empty values sort last in both directions. Ties are broken by record id, ascending.

### 4. Filter

- One condition at a time, from the query string (`filter=<field key>`, `op`, and one or two values `v`, `v2`), not saved. Saved collections with several conditions are M4 and reuse the same condition object (`Core\Query\Condition`: field, operator, operands).
- Operators by field type in M2: choice `is` or `empty`; checkbox `is` (ticked or not); relation `links-to` a given record or `empty`; date and date-time `between` (either end may be left open) or `empty`. Other types are not offered in M2; the condition object already admits them for M4.

### 5. Search

- Case-insensitive substring match (SQLite `LIKE` with escaped `%`, `_`, `\`), so only ASCII letters match without regard to case. No full-text index, so the project file format does not change.
- Fields searched: text, long text, choice, web address, email, phone, and, for relation fields whose linked table's title field is one of those types, the titles of linked live records. Records in the trash are never found.
- The list's find box (query parameter `q`) searches the current table and combines with the filter. The project search in the top bar searches every table and shows at most 10 matches per table, with a link to that table's list holding the same search.
- A search text is trimmed and holds 1 to 200 characters with no control characters.

### 6. Lost-update guard

- The record screen carries a version token in a hidden input: the first 16 hexadecimal characters of the SHA-256 of the record's stored column values (system columns included) and its link ids, in a fixed order. Saving compares the token with the record's current one inside the write transaction; a difference throws `Core\Error\Conflict` and changes nothing.
- The web layer then shows a conflict page (status 409) listing, for every field shown on the form whose value differs, the saved value and the value the person entered, with "Keep the saved version" and "Save my version anyway"; the second re-posts the person's values with the new token.
- This deviates from the session plan's recommendation to post `_updated_at`: that column has one-second resolution, so a second save within the same second as the first would not be detected. The token detects any change, needs no new column, and is cheap to replace.

### 7. Saving

- Explicit Save only; no autosave in M2. The mock-up line "All changes saved" becomes "Last saved {when}" from `_updated_at`. Autosave (brief 12.21) has no defined behaviour and is not needed for the M2 exit criteria; adding it later changes no stored data.
- Updates are partial: a save changes only the fields the record's form shows. Fields not on the form keep their values.
- Required fields (`_tiffin_fields.required`) are checked on create and on every save for the fields given. Relation and attachment fields are not checked in M2, because they cannot be changed on screen before M4.
- Creating a record assigns each auto-number field the larger of its `next` option and one more than the highest number stored, and advances `next` in the same transaction.
- Every create, update, link change, move to trash, and restore updates the project's `updated_at` and `updated_by` in the same transaction (as M1 provisional decision P73 does for the trash).

### 8. Move to trash

The record screen's "Move to trash" moves the record at once, without a confirmation page, and the next page shows a notice with an "Undo" button that restores it. A record in the trash is reversible, so the M1 rule that destructive actions go through a confirmation page (`docs/specs/M1-screens.md` section 4.5 item 7) does not apply to it. Opening the address of a record in the trash shows a page saying so, with a "Restore" button.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Paging | Mock-up's 10 per page | More clicks for small tables; the plan recommends 50 |
| Paging | Infinite scroll | Needs JavaScript; poor keyboard and screen-reader behaviour |
| Search | SQLite FTS5 index | Changes the file format, may be missing from host builds, and must be kept in step with every write |
| Search | Unicode case folding through a PHP function registered on the connection | The registration API differs between PHP 8.3 and 8.4; slower; not needed for English-only v1 |
| Lost updates | Post `_updated_at` | Misses saves within the same second (decision 6) |
| Lost updates | Integer version column | Changes every user table and the file format |
| Lost updates | Last writer wins | Loses a household member's change without a word |
| Saving | Autosave on every change | Needs JavaScript and conflict handling per keystroke; undefined behaviour |
| Trash | Confirmation page first | One more page for a reversible action; undo is the plan's choice |

## Consequences

- No project-format change for searching, sorting, or the lost-update guard.
- Searches for non-ASCII letters in another case do not match ("Über" does not find "über"); recorded as a known limit of v1.
- The filter and sort models are reused by saved collections in M4; adding operators there needs no change here.

## Costly to reverse

Nothing here is written into users' files. The query-string names (`page`, `sort`, `filter`, `op`, `v`, `v2`, `q`) appear in bookmarks; changing them later breaks old bookmarks but no data.
