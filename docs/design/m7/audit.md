# M7 screen design: activity (the audit log)

Status: design for milestone M7 part 1 (no mock-up; P49, P170). Logic: `docs/specs/M7-release.md` section 7; ADR 0022 decision 4. Implemented by M7-WP5. Strings: `strings/en/audit.php` (WP5). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Activity | `GET /admin/activity?person=&project=&action=&page=` | 200 (Admins); 403 (Members) |

Shell: signed-in shell, top-bar tab "Activity" current, no breadcrumb, layout `io`. Read-only: the page has no POST.

## 2. The page

Page title and `h1`: `audit.title` "Activity".

1. `p.lead` `audit.lead` "What has been done in this TiffinDB, newest first. Only Admins can see this page. Nothing here can be changed."
2. `form.audit-filters` GET `/admin/activity` (no CSRF: it changes nothing):
   - `label` for `filter-person` `audit.filter.person` "Person"; `select#filter-person` `name="person"`: `option value=""` `audit.filter.anyone` "Anyone", then every person by display name (a deactivated person's option text is `audit.filter.inactive` "{name} (no longer active)").
   - `label` for `filter-project` `audit.filter.project` "Project"; `select#filter-project` `name="project"`: `audit.filter.any_project` "Any project", every active project by name, then `option value="deleted"` `audit.filter.deleted` "Deleted projects".
   - `label` for `filter-action` `audit.filter.action` "What"; `select#filter-action` `name="action"`: `audit.filter.anything` "Anything", then one option per action with the short words of `audit.action.<action>.short` (below).
   - the `form-actions` partial with `submit` `audit.filter.apply` "Show", `secondary` true (not filled: this page has no primary action), `size` `normal`, and, when any filter is on, `cancel` `/admin/activity` with `cancelLabel` `audit.filter.clear` "Show everything" (no cancel otherwise).
3. `p.help` `audit.count` "{total|# entry|# entries}" (with the filters applied).
4. With no entries: `empty-state` `audit.empty` "Nothing matches these choices."
5. `ol.audit-list`, one `li.audit-entry` per entry:
   - `p.audit-when`: `time datetime="<at>"` with `Format::dateTime`.
   - `p.audit-what`: `audit.action.<action>` with `{who}` and, where the sentence has it, `{project}`. `{who}` is the display name, or `audit.who.none` "TiffinDB" when no person acted. `{project}` is the project's current name, the deleted project's name for `project.deleted`, or `audit.project.unknown` "a deleted project".
   - When the entry has detail values: `dl.audit-detail` with each key (as stored, a short lower-case name) and value (as stored), escaped. No interpretation of values: the log is shown as recorded.
6. `pager` partial (as built), 50 entries per page, keeping the filters.

## 3. Action sentences

Each `audit.action.<action>` has a sentence and a `.short` form for the menu. The table lists every action of `AuditAction::ALL` at the M5 close plus the M7 ones; WP5's test requires a key for every value of `ALL`, so M6's actions add theirs.

| Action | Sentence (`audit.action.<action>`) | Short |
|---|---|---|
| `setup.complete` | {who} finished setting up TiffinDB. | Setup finished |
| `auth.sign_in` | {who} signed in. | Signed in |
| `auth.sign_in_failed` | A sign-in as {who} failed. | Sign-in failed |
| `auth.sign_out` | {who} signed out. | Signed out |
| `auth.password_changed` | {who} changed their password. | Password changed |
| `auth.recovery_started` | Someone started Admin recovery. | Recovery started |
| `auth.recovered` | An Admin password was changed by recovery. | Recovery used |
| `people.created` | {who} added a person. | Person added |
| `people.updated` | {who} changed a person's details. | Person changed |
| `people.password_set` | {who} set a person's password. | Password set |
| `people.deactivated` | {who} deactivated a person. | Person deactivated |
| `people.reactivated` | {who} reactivated a person. | Person reactivated |
| `people.access_changed` | {who} changed which projects a person can use. | Access changed |
| `project.created` | {who} created {project}. | Project created |
| `project.renamed` | {who} renamed {project}. | Project renamed |
| `project.sharing_changed` | {who} changed who can use {project}. | Sharing changed |
| `project.deleted` | {who} deleted {project}. | Project deleted |
| `schema.table_created` | {who} added a table to {project}. | Table added |
| `schema.table_changed` | {who} changed a table in {project}. | Table changed |
| `schema.table_deleted` | {who} deleted a table from {project}. | Table deleted |
| `schema.field_created` | {who} added a field in {project}. | Field added |
| `schema.field_changed` | {who} changed a field in {project}. | Field changed |
| `schema.field_moved` | {who} moved a field in {project}. | Field moved |
| `schema.field_deleted` | {who} deleted a field in {project}. | Field deleted |
| `snapshot.taken` | {who} saved a snapshot of {project}. | Snapshot saved |
| `snapshot.restored` | {who} restored a snapshot of {project}. | Snapshot restored |
| `trash.emptied` | {who} emptied the trash of {project}. | Trash emptied |
| `import.completed` | {who} imported rows into {project}. | Rows imported |
| `export.created` | {who} exported from {project}. | Exported |
| `archive.exported` | {who} downloaded an archive of {project}. | Archive downloaded |
| `archive.imported` | {who} imported a project archive as {project}. | Archive imported |
| `plugin.enabled` | {who} turned on a plugin. | Plugin on |
| `plugin.disabled` | {who} turned off a plugin. | Plugin off |
| `attachment.added` | {who} added a file in {project}. | File added |
| `attachment.removed` | {who} removed a file in {project}. | File removed |
| `collection.saved` | {who} saved a collection in {project}. | Collection saved |
| `collection.deleted` | {who} deleted a collection in {project}. | Collection deleted |
| `form.saved` | {who} saved a form in {project}. | Form saved |
| `form.reset` | {who} put a form back to the standard one in {project}. | Form reset |

The `form.*` rows are M5's actions, confirmed in `AuditAction::ALL` as built at M5 close (M7-D2). When a sentence names `{project}` and the entry has no project (system-wide entries), `audit.project.unknown` is used.

## 4. Keyboard and screen reader

Tab order: the three selects, "Show", "Show everything", each entry has no control, the pager. The list is an ordered list, so a screen reader announces its length. Changing a select does nothing until "Show" is pressed (WCAG 3.2.2, P181). No script.

## 5. Narrow layout

The filters stack under 40em; each entry's detail wraps; long values break anywhere inside their `dd` only. No sideways scroll at 320 px Largest.
