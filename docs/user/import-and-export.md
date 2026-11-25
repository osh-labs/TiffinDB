# Import and export

Importing brings rows from a spreadsheet or CSV file into a project. Exporting takes a copy of a project's records out of TiffinDB, so you can open them in another program or keep them as a backup. Only Admins can import and export; the **Import a file** and **Export** links are in the sidebar under **Data**.

Which formats are offered depends on the plugins an Admin has turned on (see [For Admins](for-admins.md#plugins)). TiffinDB comes with CSV and Excel workbook (.xlsx) for importing, and CSV, Excel workbook, and JSON for exporting.

## Importing a CSV or Excel file

An import has four steps, shown at the top of each page: choose a file, check the fields, import, and read the report. Nothing is saved until the import step, and then the rows are saved all together, or not at all.

### 1. Choose a file

1. Choose **Import a file** in the sidebar.
2. Choose the file on your device. The page says how large it may be.
3. Under "Read the file as", leave "Decide from the file name", or choose the format yourself.
4. Choose **Continue**.

A large file is read in parts. With JavaScript on, the parts follow each other by themselves; without it, choose **Continue** after each part.

The uploaded file stays on the server only while you import: it is removed when the import is finished, or after a day.

### 2. Check the fields

**Check the fields** shows what TiffinDB found: the file, how it was read, how many rows and columns it has.

If the columns or the characters look wrong, choose **Change how the file is read**. There you can change how the file is read, for example whether the first row holds the column names, or which sheet of a workbook to use, and choose **Read the file again**.

Then choose where the rows go:

- "A new table called": type its name. Each column becomes a field. TiffinDB has guessed a name and a type for each column, and shows the first values of each. Change any name or type that looks wrong, and untick a column to leave it out.
- "Add to an existing table": choose the table, then **Check again**. Each column is matched to a field of that table; choose another field for a column, or "Leave out".

Before you import, the page lists anything you should know, for example values that are too long or cannot be read as the chosen type. Those values are left empty and listed in the report. A link column whose names are not yet in the linked table adds them as new records there.

An import takes at most 50,000 rows from one file. Choose **Import 2 rows** (the button gives your own number of rows), or **Start again** to choose a different file.

### 3. Import

TiffinDB checks every row first. A large file is checked in parts; without JavaScript, choose **Continue** after each part. When everything is checked, choose **Save the rows** to save them all together. If you leave before that, the project stays as it was.

Before saving the rows, TiffinDB saves a snapshot of the project, so an Admin can go back to how it was (see [For Admins](for-admins.md#snapshots)).

### 4. Read the report

**Import report** says how many records were added, which table they went to, how many rows were left out (for example empty rows), which values were left empty and in which rows, and how many linked records were added. Row 1 is the first row after the heading row. If records were imported without a value in a required field, the report says so; fill them in when you open the records.

Choose **Open the table** to see the new records, or **Import another file**.

If something goes wrong while the rows are being saved, the page **The import did not finish** says that nothing was saved and the project is exactly as it was. Choose **Start again**.

## Exporting records

1. Choose **Export** in the sidebar.
2. Under "Records, for other programs", choose a format:
   - CSV: one table per file.
   - Excel workbook: one table, or every table as separate sheets.
   - JSON: the whole project in one file.
3. Under "Which records", choose one table, or every table where the format allows it.
4. Choose any options the format offers, for example a column with each record's number.
5. Choose **Download**.

The file is made at once and downloaded straight away; it is not kept on the server. Exporting leaves the project unchanged. Records in the trash are not included.

If you will open the file in a spreadsheet program, choose the Excel workbook: its text is never treated as a formula. The CSV export has an option to protect against spreadsheet formulas, which puts an apostrophe before any text that starts with =, +, -, @, a tab, or a carriage return. The apostrophe then becomes part of the value.

The JSON format is described in the documentation that comes with TiffinDB (`docs/formats/json-export.md`), so the file can be read without TiffinDB.

## Project archives

A project archive is the whole project in one .zip file: every table, record, form, and attached file, including records in the trash. Use it as a backup, or to move a project to another TiffinDB.

### Downloading an archive

On the **Export** page, under "The whole project, for TiffinDB", the page gives the archive's approximate size. Choose **Download the project archive**.

### Importing an archive

Importing an archive makes a new project; your other projects are not changed.

1. On **Your projects**, choose **Import a project archive**.
2. Choose the .zip file made by TiffinDB. The page says how large it may be.
3. Leave the name empty to use the name stored in the archive, or type a new one.
4. Choose **Import**.

TiffinDB checks the archive before using it, and refuses one that it did not make or that is damaged. If a project with the same name exists, it asks for another name. The new project is shared with nobody until you share it.

Sometimes TiffinDB has to change a few things to fit its rules, for example a value that no longer fits its field. The notice after the import lists what was changed.

An archive that is larger than the page allows cannot be uploaded. The person who looks after the server can restore it by hand; the steps are in the project file documentation (`docs/formats/tiffin.md`, section 13.4).
