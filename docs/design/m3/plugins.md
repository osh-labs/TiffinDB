# M3 screen design: plugins

Status: design for milestone M3 (ui-design-plan section 6.2, "Plugins"; no mock-up). Logic: `docs/specs/M3-io.md` section 4 and 13.3; ADR 0015. Implemented by M3-WP10. Strings: `strings/en/plugins.php` (WP10). Example data is synthetic. Notice and reason keys corrected to the as-built screen at the M3 exit audit.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Plugins | `GET /admin/plugins` | 200 |
| Plugin | `GET /admin/plugins/{kind}/{plugin}` | 200; 404 when not found |
| Turn on (confirmation, plugins not shipped with TiffinDB) | `GET /admin/plugins/{kind}/{plugin}/enable` | 200 |
| Turn on, turn off | `POST .../enable`, `POST .../disable` | 303 to the plugin page with a notice |

Shell: signed-in shell, top bar "Plugins" current (`Shell::$nav` `plugins`), breadcrumb (`plugins.crumb` "Plugins", then the plugin name on the plugin pages), `main#main.page.page-io`. Members get the 403 page (the tab is not shown to them).

## 2. Plugins list

Page title: `plugins.title` "Plugins".

1. Breadcrumb; flash.
2. `h1` `plugins.title`.
3. `p.lead` `plugins.lead` "Plugins read and write file formats for importing and exporting. A plugin can read and change everything in this installation, so turn on only plugins you trust."
4. Two sections, `h2` `plugins.import` "Import formats" and `h2` `plugins.export` "Export formats", each `ul.plugin-list` of `li.plugin-row`:
   - `div`: `h3` (`font-size` 20) `a` to the plugin page with the plugin's name (plain; for a broken plugin without a readable name, `plugins.unnamed` "Plugin in folder {id}"); `p.help` `plugins.row_facts_import` "Version {version}. Reads {extensions}." or `plugins.row_facts_export` "Version {version}. Writes {extension}." (as built: two keys); `plugins.shipped` "Comes with TiffinDB." for the five shipped plugins.
   - `span.plugin-status` with the class for its status and the word: `plugins.status.enabled` "On", `plugins.status.disabled` "Off", `plugins.status.unavailable` "Cannot be used", `plugins.status.broken` "Damaged".
5. When a section is empty: `p` `plugins.none` "None found."
6. `p.help` `plugins.install_help` "To add a plugin, copy its folder into plugins/import or plugins/export on the server. It appears here turned off."

## 3. Plugin page

Page title: the plugin's name.

1. Breadcrumb; flash.
2. `h1` the name; status pill.
3. `facts` `plugins.details` "Details": `plugins.kind` "Kind" (`plugins.kind.import` "Import" or `plugins.kind.export` "Export"); `plugins.version` "Version"; `plugins.licence` "Licence" (SPDX identifier); `plugins.files` "File types" (extensions, and for export the media type); `plugins.folder` "Folder" (`plugins/{kind}/{id}`); `plugins.origin` "Origin" (`plugins.origin.shipped` "Comes with TiffinDB" or `plugins.origin.added` "Added to this installation").
4. `p` the manifest's description (plain), when not empty.
5. By status:
   - Enabled: `form` POST `.../disable` with `csrf` and `button.button.button-large` `plugins.disable` "Turn off". `p.help` `plugins.disable_help` "Imports and exports in progress with this plugin will stop at their next step."
   - Disabled, shipped: `form` POST `.../enable` with `button.button.button-primary.button-large` `plugins.enable` "Turn on".
   - Disabled, added: `a.button.button-primary.button-large` `plugins.enable` "Turn on" to the confirmation page.
   - Unavailable: `p.notice` `plugins.unavailable_body` "This plugin needs PHP extensions that this server does not have: {extensions}. Ask your hosting provider to turn them on."
   - Broken: `p.notice` `plugins.broken_body` "This plugin's description file has a problem, so TiffinDB will not use it." and `p.help` with the reason, `plugins.reason.<code>` (for example `plugins.reason.unknown_member` "The description file has an entry TiffinDB does not know.", one string per reason code of `docs/specs/M3-io.md` section 4.1, `too-many`, `php-tag`, and `duplicate-member`; the key replaces `-` in the code with `_`).
6. `a` `plugins.back` "All plugins" to `/admin/plugins`.

Notices after a change (as built, M3-WP10): `notice.plugin_enabled` "{name} is turned on." / `notice.plugin_disabled` "{name} is turned off." Turning on a shipped plugin or turning off any plugin needs no confirmation: both are reversible and change no data.

## 4. Turn on a plugin that did not come with TiffinDB

Page title: `plugins.confirm.title` "Turn on {name}?". The M2 confirmation-page pattern (`docs/design/m2/states.md` section 3):

1. Breadcrumb ending in the plugin name.
2. `h1` `plugins.confirm.title`.
3. `p` `plugins.confirm.body` "{name} did not come with TiffinDB. Once it is on, it runs every time someone imports or exports with it, and it can read and change everything in this installation: every project, every account, and every file. Turn it on only if you trust where it came from."
4. `facts` with Version, Licence, Folder.
5. `form` POST `.../enable`: `csrf`; `button.button.button-danger.button-large` `plugins.confirm.yes` "Turn it on"; `a.button.button-large` `plugins.confirm.no` "Cancel" to the plugin page.

Keyboard and screen reader: ordinary links, buttons, and headings; the status is text, not colour.
