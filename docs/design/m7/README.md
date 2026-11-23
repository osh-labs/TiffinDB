# M7 screen designs

Status: designs for milestone M7 part 1 (M7-D1), reviewed (M7-R1) and reconciled with the M5 as-built code (M7-D2): every action row uses the `form-actions` partial, which alone prints `form.cancel` (as built). They design the M7 screens that have no mock-up: the About page (ui-design-plan section 6.2), and, from P49 and P170, snapshots and restore, project deletion, the activity (audit) log, and Admin recovery; plus where the favicon, the app icon, and the new links appear in the shell.

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes added to `app.css` for M7, the top-bar Activity tab, the footer About link, the page-head icons, the project settings links |
| `snapshots.md` | Snapshots list, take a snapshot, restore confirmation, results |
| `project-delete.md` | Delete a project (confirmation with the typed name), results |
| `audit.md` | Activity: entries, filters, paging |
| `about.md` | About TiffinDB, a licence text page |
| `recovery.md` | Admin recovery (explain and start; enter the code and a new password), the sign-in page link and notice |

How to read them: as `docs/design/m1/README.md` to `docs/design/m5/README.md`. Every screen lists its elements in reading order, every string as a key with its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the narrow layout. The web-layer rules are in `docs/specs/M7-screens.md`; the core behaviour behind each action is in `docs/specs/M7-release.md`; the decisions are ADR 0021 and ADR 0022.

Conventions: `{p}` is a project key; `{s}` a snapshot id (its file name without `.tiffin`); "Home Inventory" stands for the project's name. Strings with a count use the plural placeholder (`{count|# record|# records}`). Dates use `Format::relativeDate` and times `Format::dateTime` (as built). Strings hold no `<` or `>` and no web address (`TemplateRulesTest`).

Every screen here works without JavaScript; M7 adds no script. Every form that changes data carries the CSRF field; no form is nested; every POST answers with 303, except a refusal (422), a grown count (200 with a notice), a busy project (503), and a throttled recovery attempt (429).

Permissions: snapshots, restore, and project deletion are for Admins (a granted Member gets the 403 page, an ungranted person and an unknown project 404); the activity log is for Admins (a Member gets 403); the About page is for everyone signed in, with server facts for Admins only; recovery is for signed-out visitors and does not exist when the operator turns it off.

No static wireframes are included, for the reason given in the M1 README. All example names and data are synthetic ("Home Inventory", "Example Admin", "Example Member", `20261112T031500Z-manual-k3m7`).
