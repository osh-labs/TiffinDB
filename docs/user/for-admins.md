# For Admins

Admins can do everything Members can, in every project, and also look after the people, projects, tables, and plugins of their TiffinDB. This chapter covers the Admin screens in the browser. Installing, upgrading, and backing up the server are in the host documentation.

## People

Choose **People** in the top bar. The list shows everyone who can sign in, with their username, role, the projects they can use, and whether they can sign in at the moment.

There must always be at least one Admin who can sign in. Two Admins are safer: if one forgets their password, the other can set a temporary one.

### Adding a person

1. Choose **Add a person**.
2. Fill in:
   - their name, shown to others, for example Example Member;
   - a username, used to sign in: letters, numbers, dots, dashes, and underscores;
   - their role: Member (can view and change records in the projects shared with them) or Admin (can also add people, create and share projects, and change tables and fields);
   - a temporary password. The page suggests one, and you can type your own instead;
   - for a Member, the projects they can use. Admins can use every project.
3. Choose **Add person**.

Give the person their username and temporary password yourself. TiffinDB sends no email. The first time they sign in, they choose their own password.

### Changing a person

Choose the person's name in the list. Their page has four parts:

- **Details**: their name, username, and role. Choose **Save details**.
- **Projects they can use**: tick the projects. Choose **Save projects**.
- **Password**: if they have forgotten their password, choose **Set temporary password**. The page suggests a temporary password, and you can type your own instead. They are signed out everywhere and choose a new password when they next sign in. If you do this for your own account, you are signed out too.
- **Signing in**: choose **Stop Example Member from signing in** (with the person's name) to stop them. A confirmation page explains that they are signed out at once and cannot sign in; nothing they made is deleted, and their name stays on the changes they made. Later, **Let Example Member sign in again** lets them back in.

You cannot stop yourself from signing in, and you cannot stop the only Admin who can sign in.

People are never deleted, so the records and the activity log keep the names of the people who made changes.

## Projects and sharing

### Making a project

1. On **Your projects**, choose **New project**.
2. Type the project's name, for example Home Inventory. Optionally type the name of a first table, for example Items; TiffinDB gives it a Name field to start with.
3. Choose **Create project**.

A new project is shared with nobody yet.

### Sharing a project

Choose **Sharing** beside the project on **Your projects**, or on its tables page. Tick the Members who may use the project, and choose **Save sharing**. Members who are shared a project can view and change its records; Admins can always use every project.

### Project settings

Choose **Tables and fields** in the sidebar, then **Project settings**. Here you can rename the project, and see how many files it stores and how much space they take. The settings page also leads to the project's snapshots and to deleting the project (below).

## Tables and fields

Choose **Tables and fields** in the sidebar. The page lists the project's tables, with how many fields and records each has. Members can see this page but not change anything on it.

### Adding a table

1. Choose **Add a table**.
2. Type a name; a plural word works well, for example Items or Recipes. Leave "Start with a Name field" ticked unless you want to add every field yourself.
3. Choose **Create table**.

### The fields of a table

Choose a table's name to open its fields. The page lists the fields in the order they appear, with their type and whether they must be filled in.

- **Up** and **Down** move a field. The order is the order of the standard form and the list's columns.
- Choose a field's name to change it.
- **Add a field** adds one.
- **Edit the form** opens the form tools (see [Forms](forms.md)).

Under "Table settings" you can rename the table, say what one record is called (used on buttons, for example **Add item**), and choose the field shown as each record's title in lists and links. Choose **Save table settings**.

### Adding a field

1. Choose **Add a field**.
2. Choose what the field will hold, and choose **Next**. The types are grouped as words; numbers and money; dates and times; yes or no, and choices; contact and web; and files and links.
3. On **New field: Choice** (or the type you chose), type the field's name, an optional hint shown under the field, and whether people must fill it in. Fill in the settings the type has, for example the choices of a choice field, one per line.
4. Choose **Add field**.

A link field links to records in another table, or in the same table. You choose whether each record can link to one record or several, whether each record in the linked table can link to one record or several, and the name of the matching field that TiffinDB adds to the linked table, so the link shows there too.

### Changing a field

On **Change the field Room** (with your field's name), you can rename the field, change its hint and settings, and choose whether it must be filled in. Choose **Save field**. For a choice field, changing a choice renames it everywhere it is used, and emptying a box removes that choice.

To change what the field holds, choose **Change the type**, choose the new type, and choose **Change type**. TiffinDB converts the values it can. If some values cannot be converted, it says how many first, on **Clear 3 values?** (with your own number): choose **Clear 3 values and save**, or **Go back without saving**. TiffinDB saves a snapshot of the project before it changes the type. Some types cannot be changed.

### Deleting a field or a table

- To delete a field, open it and choose **Delete field**, then confirm with **Delete field**. Its values in every record are deleted. A link field's matching field in the other table is deleted too.
- To delete a table, open its fields and choose **Delete table**. The page says how many records will be deleted and which link fields in other tables will be deleted with it. Tick the box to confirm, and choose **Delete table**.

TiffinDB saves a snapshot of the project before it deletes a field or a table, so you can go back to it (see [Snapshots](#snapshots)).

## Plugins

Plugins read and write the file formats used for importing and exporting. Choose **Plugins** in the top bar to see the import formats and export formats, each marked "On", "Off", "Cannot be used", or "Damaged".

Choose a plugin to see its details: its kind, version, licence, file types, folder, and whether it came with TiffinDB. Then:

- **Turn off** stops it being offered. Imports and exports in progress with it stop at their next step.
- **Turn on** offers it again. For a plugin that did not come with TiffinDB, a page asks you first: once it is on, it runs every time someone imports or exports with it, and it can read and change everything in this installation, every project, every account, and every file. Choose **Turn it on** only if you trust where the plugin came from.

A plugin that cannot be used needs a PHP extension the server does not have; a damaged one has a problem in its description file. New plugins are added on the server, by copying their folder into `plugins/import` or `plugins/export`; they appear here turned off.

## Snapshots

A snapshot is a saved copy of a project's records, tables, forms, and collections. TiffinDB saves one before every risky change: before a table or field is deleted, before a field's type or options are changed, before the trash is emptied, before rows are imported, and before an upgrade. It keeps the 20 newest of those. Snapshots you take yourself are kept until someone removes them from the data folder on the server.

Snapshots do not hold the files attached to records.

### Seeing and taking snapshots

Open **Project settings** and choose **Snapshots**. The **Snapshots** page lists the project's snapshots, newest first, with when each was saved, why, and how large it is.

To save one now, for example before you rearrange a table, choose **Take a snapshot now**.

### Going back to a snapshot

1. Choose **Restore…** beside the snapshot.
2. The page **Restore this snapshot?** says when it was saved, why, and how many records it holds compared with now. It also says how many stored files were added since then and will be deleted, and how many files the snapshot names that are no longer stored and will show as missing.
3. Choose **Restore this snapshot**, or **Cancel**.

The project goes back to how it was when the snapshot was saved. Everything changed since then is undone: records, tables and fields, forms, collections, and the trash. Before it restores, TiffinDB saves a snapshot of the project as it is now, at the top of the list, so you can come back to it with one more restore.

People using the project at that moment may need to reload their page. If someone is saving a change just then, TiffinDB asks you to try again.

If the server cannot lock files, restoring in TiffinDB is turned off, and the **Snapshots** page says so. The host documentation describes restoring by hand.

## Deleting a project

Deleting a project removes it for good: its records, tables, forms, collections, stored files, and snapshots are removed from the server, and the people it was shared with lose access. It cannot be undone in TiffinDB. The activity log keeps a line saying it was deleted.

1. Open **Project settings** and choose **Delete this project**.
2. The page says how many records, records in the trash, stored files, and snapshots will go, and how many Members it is shared with.
3. If you may want the project again, keep a copy first: choose **Go to Export** and download a project archive (see [Import and export](import-and-export.md#project-archives)). An archive can be imported again later.
4. Type the project's name exactly, to confirm.
5. Choose **Delete for good**, or **Cancel**.

If the name is not typed exactly, nothing is deleted and the page says so. If records were added while you were looking, the page shows the new numbers and asks you to check them. TiffinDB then goes back to **Your projects**, and the notice says the project has been deleted.

## Activity

Choose **Activity** in the top bar. The page lists what has been done in this TiffinDB, newest first: who signed in, who added people or projects, who changed tables and fields, who emptied the trash, imported, exported, restored a snapshot, and so on. Only Admins can see it, and nothing on it can be changed.

Each line says when it happened and, in a sentence, who did what, for example "Example Admin emptied the trash of Home Inventory." Some lines have extra details, shown as they were recorded.

To narrow the list, choose a person, a project (or deleted projects), and what kind of action, then choose **Show**. **Show everything** clears those choices. The list shows 50 lines at a time, with **Previous** and **Next** to move between pages.

Adding, changing, and moving records to the trash are not listed.
