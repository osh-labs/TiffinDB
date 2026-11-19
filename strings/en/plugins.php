<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Plugins screens strings (docs/design/m3/plugins.md). Plugin names,
 * versions, and descriptions come from the plugins' description files and
 * are shown as plain text, never as string keys (P133). The reason texts
 * (plugins.reason.*) are chosen by PluginController from a literal table
 * of the core's reason codes.
 */

declare(strict_types=1);

return [
    'plugins.crumb' => 'Plugins',
    'plugins.title' => 'Plugins',
    'plugins.lead' => 'Plugins read and write file formats for importing and exporting. A plugin can read and change everything in this installation, so turn on only plugins you trust.',
    'plugins.import' => 'Import formats',
    'plugins.export' => 'Export formats',
    'plugins.unnamed' => 'Plugin in folder {id}',
    'plugins.row_facts_import' => 'Version {version}. Reads {extensions}.',
    'plugins.row_facts_export' => 'Version {version}. Writes {extension}.',
    'plugins.shipped' => 'Comes with TiffinDB.',
    'plugins.status.enabled' => 'On',
    'plugins.status.disabled' => 'Off',
    'plugins.status.unavailable' => 'Cannot be used',
    'plugins.status.broken' => 'Damaged',
    'plugins.none' => 'None found.',
    'plugins.install_help' => 'To add a plugin, copy its folder into plugins/import or plugins/export on the server. It appears here turned off.',

    'plugins.details' => 'Details',
    'plugins.kind' => 'Kind',
    'plugins.kind.import' => 'Import',
    'plugins.kind.export' => 'Export',
    'plugins.version' => 'Version',
    'plugins.licence' => 'Licence',
    'plugins.files' => 'File types',
    'plugins.folder' => 'Folder',
    'plugins.origin' => 'Origin',
    'plugins.origin.shipped' => 'Comes with TiffinDB',
    'plugins.origin.added' => 'Added to this installation',
    'plugins.disable' => 'Turn off',
    'plugins.disable_help' => 'Imports and exports in progress with this plugin will stop at their next step.',
    'plugins.enable' => 'Turn on',
    'plugins.unavailable_body' => 'This plugin needs PHP extensions that this server does not have: {extensions}. Ask your hosting provider to turn them on.',
    'plugins.broken_body' => 'This plugin\'s description file has a problem, so TiffinDB will not use it.',
    'plugins.back' => 'All plugins',

    'plugins.reason.manifest_missing' => 'The folder has no description file (plugin.json).',
    'plugins.reason.manifest_link' => 'The description file is a link to another file.',
    'plugins.reason.too_large' => 'The description file is too large.',
    'plugins.reason.not_utf8' => 'The description file is not UTF-8 text.',
    'plugins.reason.php_tag' => 'The description file contains PHP code markers.',
    'plugins.reason.duplicate_member' => 'The description file has the same entry twice.',
    'plugins.reason.not_json' => 'The description file is not valid JSON.',
    'plugins.reason.too_deep' => 'The description file is nested too deeply.',
    'plugins.reason.not_an_object' => 'The description file is not a JSON object.',
    'plugins.reason.unknown_member' => 'The description file has an entry TiffinDB does not know.',
    'plugins.reason.missing_member' => 'The description file is missing an entry TiffinDB needs.',
    'plugins.reason.bad_api' => 'The plugin was written for a different version of the plugin interface.',
    'plugins.reason.bad_id' => 'The plugin\'s id is not valid.',
    'plugins.reason.id_mismatch' => 'The plugin\'s id does not match its folder name.',
    'plugins.reason.kind_mismatch' => 'The plugin\'s kind does not match the folder it is in.',
    'plugins.reason.bad_name' => 'The plugin\'s name is empty, too long, or has special characters.',
    'plugins.reason.bad_description' => 'The plugin\'s description is too long or has special characters.',
    'plugins.reason.bad_version' => 'The plugin\'s version is not in the form 1.2.3.',
    'plugins.reason.bad_class' => 'The plugin\'s class name is not valid for its folder.',
    'plugins.reason.bad_licence' => 'The plugin\'s licence is not one TiffinDB accepts.',
    'plugins.reason.bad_extensions' => 'The plugin\'s list of file types is not valid.',
    'plugins.reason.bad_media_type' => 'The plugin\'s file media type is not valid.',
    'plugins.reason.bad_scopes' => 'The plugin\'s list of what it can export is not valid.',
    'plugins.reason.bad_requires_extensions' => 'The plugin\'s list of PHP extensions it needs is not valid.',
    'plugins.reason.src_missing' => 'The plugin has no src folder.',
    'plugins.reason.src_outside' => 'The plugin\'s src folder is outside the plugin\'s folder.',
    'plugins.reason.class_file_missing' => 'The plugin\'s class file is missing from its src folder.',
    'plugins.reason.too_many' => 'There are more plugin folders than TiffinDB reads (100 of each kind).',
    'plugins.reason.namespace_clash' => 'Another plugin uses the same name for its code.',
    'plugins.reason.unreadable' => 'The plugin\'s folder could not be read.',
    'plugins.reason.other' => 'The description file could not be used.',

    'plugins.confirm.title' => 'Turn on {name}?',
    'plugins.confirm.body' => '{name} did not come with TiffinDB. Once it is on, it runs every time someone imports or exports with it, and it can read and change everything in this installation: every project, every account, and every file. Turn it on only if you trust where it came from.',
    'plugins.confirm.yes' => 'Turn it on',
    'plugins.confirm.no' => 'Cancel',

    'notice.plugin_enabled' => '{name} is turned on.',
    'notice.plugin_disabled' => '{name} is turned off.',
];
