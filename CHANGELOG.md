# Changelog

All notable changes to TiffinDB are listed here. Versions follow semantic versioning; the version of a copy is in `config/app.php`.

## 1.0.0 (not yet released)

The first release. It holds:

- Foundations: a self-hosted web application in PHP with no build step, served by Apache, with every screen working without JavaScript; a source code link in every page footer.
- Installation and accounts: a setup page that creates the first Admin and checks that the data folder is not reachable from the web; household accounts with Admin and Member roles and per-project access; sign-in with throttling; a command-line password reset.
- Projects and fields: one SQLite file per project; tables with custom fields of many types; record trash, project snapshots, and an audit log from the start.
- Records: list views with search and sort, an automatically made form for each table, adding and editing records by keyboard alone, and per-person appearance settings (typeface, text size, accent colour, contrast).
- Import and export: CSV and XLSX import with a review step; CSV, XLSX, and JSON export; a whole project as one archive file that opens without TiffinDB.
- Relations and files: linked records edited from a form, file attachments with access control, and saved collections.
- Forms: a form editor and a source view for the stored form HTML, which is checked by a sanitizer before it is saved.
- Release: a reproducible release archive with a file manifest and third-party notices, an upgrade path that keeps the data folder, snapshot restore, project deletion, an audit log screen, Admin recovery without a shell, and host and user documentation (`docs/host/`, `docs/user/`).
