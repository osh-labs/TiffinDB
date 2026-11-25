# TiffinDB

TiffinDB is a free, self-hosted application for building and using small personal databases in a web browser: tables with your own fields, forms, lists, search, saved collections, linked records, and attached files. It is inspired by, and meant as a replacement for, a discontinued personal database application for the desktop.

It runs on an ordinary PHP web host (PHP 8.3 or later, Apache, SQLite); no database server is needed. Each project is one folder holding a SQLite file in a documented format (`docs/formats/tiffin.md`), and can be exported as CSV, XLSX, JSON, or a project archive, so your data stays readable without TiffinDB.

## Documentation

- [Host documentation](docs/host/README.md): installing, configuring, upgrading, backing up, and looking after an installation.
- [User documentation](docs/user/README.md): using TiffinDB in the browser.

## Licence

TiffinDB is licensed under the GNU Affero General Public License, version 3 or later (`AGPL-3.0-or-later`); the full text is in `LICENSE`. Third-party components and their licences are listed in `THIRD-PARTY-NOTICES.md` and `docs/dependencies.md`.

## Source code

TiffinDB's pages link to its source code ("Source code" in the footer), as section 13 of the licence requires for software offered over a network. If you run a modified copy for others, point that link at your modified source: it is `source_url` in `config/app.php`.

## For developers

TiffinDB has no build step. With PHP 8.3 and Composer:

```
composer install
composer test
composer lint
```

Tests run offline with synthetic data. `composer serve` starts PHP's built-in server for development; it does not read `.htaccess` files, so it is not a way to host TiffinDB.
