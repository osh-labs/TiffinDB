# M7 components and shell changes

Status: design for milestone M7 part 1. Implemented by M7-WP3 (stylesheet, shell, head) and M7-WP4 (project settings links). Web rules: `docs/specs/M7-screens.md` sections 2, 3, 5, 8.

## 1. Page head

On every page (signed in, signed out, error pages):

- `link rel="icon"` to `assets/icons/favicon.svg` (`image/svg+xml`): the small mark, which follows no theme (it is the brand file, `docs/brand/tiffindb-mark-small.svg`; the brand README names it as the favicon).
- `link rel="manifest"` to `assets/app.webmanifest`, which names the app icon (`docs/brand/tiffindb-app-icon.svg`) for browsers that add a site to a home screen.

Nothing visible changes in the page.

## 2. Top bar: Activity

For Admins, after "Plugins": `a.top-pill` `shell.nav.activity` "Activity" to `/admin/activity`; `aria-current="page"` on the activity page. Members do not see it. On narrow screens it wraps with the other pills (as built).

## 3. Footer: About

Signed-in shell only. Order: licence line `shell.footer.licence` (as built), then `a` `shell.footer.about` "About TiffinDB" to `/about`, then `a` "Source code" (as built). Same link style as "Source code" (44 px minimum height, P83). The plain shell (sign-in, setup, recovery) keeps the licence line and "Source code" only.

## 4. Project settings: more for this project

Admins only (the page is Admin-only, as built). After the stored-files line:

1. `h2` `projects.settings.more` "More for this project".
2. `ul.settings-links` (new class, section 5):
   - `a` `projects.settings.snapshots` "Snapshots" to `/projects/{p}/snapshots`, with `span.help` `projects.settings.snapshots_help` "Saved copies of the project, and going back to one."
   - `a.link-danger` `projects.settings.delete` "Delete this project" to `/projects/{p}/delete`, with `span.help` `projects.settings.delete_help` "Removes the project, its files, and its snapshots for good."

## 5. Stylesheet classes (`app.css`, WP3)

All values from the tokens; sizes in `rem` (px only where the M0 rules allow it); breakpoints in `em`.

| Class | Rule |
|---|---|
| `.snapshot-table` | On a `table.data-table` (as built); columns time, reason, size, action; the size cell `.num` right-aligned; the action cell holds a link, no wrap; inside `div.table-scroll` (as built) |
| `.audit-filters` | `form` laid out as a wrapping flex row, gap 16 px; each `div.field` at least 14 rem wide; the `form-actions` block ("Show", "Show everything") aligned to the bottom of the selects; under 40em one column |
| `.audit-list` | `ol` with no markers; each `li.audit-entry` separated by a 1 px `--line` rule and 12 px padding above and below |
| `.audit-entry .audit-when` | small text (`--text-small` as built), `--ink2` |
| `.audit-entry .audit-what` | body text (strings hold no markup, so the sentence is plain) |
| `.audit-detail` | `dl` in small text, `--ink2`, `dt` and `dd` on one line each pair (`display: inline` with a separator after `dd`), wrapping with `overflow-wrap: anywhere` (values may be long tokens) |
| `.licence-text` | `pre`: `white-space: pre-wrap`, `overflow-wrap: anywhere`, small text, `--sunk` ground, 1 px `--line` border, 12 px radius, 16 px padding, max width 56 rem; the page never scrolls sideways |
| `.about-list` | `dl` of components and plugins: `dt` bold, `dd` with name facts in `--ink2`, 8 px between pairs |
| `.recovery-steps` | `ol` with numbers, 12 px between items, max width 40 rem |
| `.settings-links` | `ul` with no markers; each `li` a link with its `span.help` on the next line, 12 px between items (no such list class exists as built) |
| `.link-danger` | link text in `--warn`, underlined; focus ring as every link |
| `.danger-zone` | As built (M4 trash); reused by the restore and delete pages |

Mid-word breaks: `docs/specs/M7-release.md` section 15.2.
