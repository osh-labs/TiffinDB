# Plugins

A plugin reads or writes a file format for importing or exporting. TiffinDB ships these, in `plugins/`:

| Folder | Does |
|---|---|
| `plugins/import/csv/` | Imports CSV files |
| `plugins/import/xlsx/` | Imports XLSX spreadsheets |
| `plugins/export/csv/` | Exports CSV files |
| `plugins/export/xlsx/` | Exports XLSX spreadsheets |
| `plugins/export/json/` | Exports JSON |

Each plugin is a folder `plugins/import/<id>/` or `plugins/export/<id>/` holding a description file `plugin.json` and its PHP code in `src/`.

## What a plugin can do

**A plugin is PHP code that runs inside TiffinDB, with the full privileges of PHP and of the web server's account. There is no sandbox.** A plugin that is turned on can read and change every project and the system database (people, password hashes, sharing, the activity log), read and change any file the web server's account can, and make network connections. TiffinDB's own checks do not limit it.

Add only plugins you trust, from a source you trust, and read their code if you can. Only someone who can write to the server's files can add a plugin; only an Admin can turn one on.

What TiffinDB does check: it reads only `plugin.json` when it looks for plugins, and runs no plugin code until a plugin is turned on and used. It refuses a plugin whose description file is malformed, whose folder layout is wrong, or that needs PHP extensions the server does not have; such a plugin is listed as "Damaged" or "Cannot be used". These checks keep a broken plugin from being loaded by mistake; they are not a protection against a plugin written to do harm.

## Adding, turning on, and turning off

1. Copy the plugin's folder into `plugins/import/` or `plugins/export/` on the server, according to its kind. The folder's name must be the plugin's id.
2. As an Admin, open "Plugins" in the top bar. The new plugin is listed as "Off".
3. Open the plugin, check its details (version, licence, file types, folder), and choose "Turn on".

To stop using a plugin, open it and choose "Turn off". Imports and exports in progress with it stop at their next step. The plugins that come with TiffinDB are on until an Admin turns them off. The setting is kept in the system database.

To remove a plugin you added, turn it off, then delete its folder. Keep plugins you added when upgrading: the upgrade deletes only the plugin folders a release lists in its `MANIFEST.txt` (see [Upgrading](upgrade.md)).

TiffinDB reads at most 100 plugin folders of each kind.
