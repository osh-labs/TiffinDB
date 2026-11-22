# M7 screen design: About TiffinDB

Status: design for milestone M7 part 1 (ui-design-plan section 6.2, "About page"; no mock-up). Logic: `docs/specs/M7-release.md` section 10; ADR 0022 decision 6, ADR 0021 decisions 1 and 8. Implemented by M7-WP5. Strings: `strings/en/about.php` (WP5). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| About | `GET /about` | 200 (anyone signed in) |
| Licence text | `GET /about/licences/{id}` | 200; 404 for an unknown id |

Shell: signed-in shell, no breadcrumb, no top-bar tab current, layout `settings`. Reached from the footer's "About TiffinDB".

## 2. About

Page title and `h1`: `about.title` "About TiffinDB".

1. `p.lead` `about.lead` "TiffinDB is free software for keeping your own small databases on your own server."
2. `dl.facts`: `about.version` "Version" with the configured version; `about.licence` "Licence" with `about.licence_value` "GNU Affero General Public License, version 3 or later" and `a` `about.licence_link` "Read the licence" to `/about/licences/tiffindb`; `about.source` "Source code" with `a` `about.source_link` "Get the source code" to the configured `source_url` (`rel="noopener noreferrer"`, as the footer).
3. `p` `about.agpl` "You may use, change, and share TiffinDB under that licence. If you run a changed copy for other people, the licence asks you to offer them its source code; the link above is how this copy does that."
4. `h2` `about.components.title` "Made with"; `p.help` `about.components.lead` "TiffinDB includes these parts made by others, each under its own licence."; `dl.about-list`: per component (not `tiffindb`): `dt` its name; `dd` `about.component.facts` "Version {version}, {licence}" (or `about.component.facts_nover` "{licence}" when the version is empty) and `a` `about.component.licence_link` "Licence text" with `aria-label` `about.component.licence_label` "Licence text of {name}" to `/about/licences/{id}`.
5. `h2` `about.plugins.title` "Plugins"; when none: `p` `about.plugins.none` "No plugins are installed."; else `dl.about-list`: `dt` the plugin's name; `dd` `about.plugin.facts` "{kind}, version {version}, {licence}" with `{kind}` `about.plugin.kind.import` "Import" or `about.plugin.kind.export` "Export", and for Admins `about.plugin.on` "On" or `about.plugin.off` "Off". Members see only plugins that are on. Plugin texts are escaped plain text (P133).
6. Admins only: `h2` `about.server.title` "This server"; `dl.facts`: `about.server.php` "PHP" with the version; `about.server.sqlite` "SQLite" with the version; `p.help` `about.server.note` "Only Admins see this part."

## 3. Licence text

Page title and `h1`: `about.licence.title` "Licence: {name}".

1. `p` with `a` `about.licence.back` "Back to About TiffinDB" to `/about`.
2. For each licence file of the component, in order: when there is more than one, `h2` with the file's name (the last path segment, escaped); then `pre.licence-text` with the text, escaped. A text cut at 256 KiB ends with `about.licence.cut` "(The rest of this file is not shown here. It is in the file {file} of this installation.)".

## 4. Keyboard and screen reader

Tab order: the licence link, the source link, each component's licence link, the back link on a licence page. Headings give the page structure. No script.

## 5. Narrow layout

Facts and lists stack; licence text wraps inside its box (`overflow-wrap: anywhere`); no sideways scroll at 320 px Largest.
