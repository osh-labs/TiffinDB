<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) TiffinDB contributors
-->

# Documentation tooling

Scripts that make the demo data and the screenshots for the HTML documentation set in `docs/manual/` (ADR 0020). They are tools for the person who regenerates screenshots. They are not application or test dependencies: `composer install` does not install them and `composer test` does not run them.

## Requirements

- Node.js (22 was used) and Playwright with Chromium (Apache-2.0, recorded in `docs/dependencies.md`). Install them outside this repository; nothing from npm is committed (`/node_modules/`, `/package.json` and `/package-lock.json` are git-ignored).
- PHP 8.3 or later with the `gd` extension, and a `composer install` of this checkout, to run the application with the built-in server and to reduce the screenshots.
- No other network access. Every script refuses requests to any host other than the given base URL.

## Files

| File | Purpose |
|---|---|
| `seed-demo.js` | Installs a fresh, un-installed instance through the setup page, then creates the synthetic demo data through the real screens. Writes `state.json` (URL paths and the generated passwords), `items.csv`, and the attachment files to the `--out` folder |
| `shots-user-guide.js` | Screenshots for `docs/manual/user-guide.html` (prefix `user-`) |
| `shots-install-guide.js` | Screenshots for `docs/manual/install-guide.html` (prefix `install-`); uses a fresh instance for the setup page |
| `shots-handoff.js` | Screenshots for `docs/manual/handoff.html` (prefix `handoff-`) |
| `shots-index.js` | The hero screenshot of `docs/manual/index.html` |
| `reduce-png.php` | Reduces screenshots to an indexed palette before they are committed |

Each script's header comment gives its exact arguments.

## Procedure

Work in a scratch folder outside the repository. `state.json` holds passwords: `seed-demo.js` refuses an `--out` folder inside this checkout.

1. Make a runnable copy of the application, so that the data folder of your checkout is not touched:

   ```bash
   mkdir -p ~/tiffindb-docs/app ~/tiffindb-docs/data ~/tiffindb-docs/seed-out
   git archive HEAD | tar -x -C ~/tiffindb-docs/app
   cp -r vendor ~/tiffindb-docs/app/
   ```

2. Point the copy at its own data folder. Without `config/local.php` the application uses `../tiffindb-data`, which every copy would share.

   ```bash
   printf "<?php return ['data_dir' => '%s'];\n" ~/tiffindb-docs/data > ~/tiffindb-docs/app/config/local.php
   ```

3. Start the copy (leave it running):

   ```bash
   cd ~/tiffindb-docs/app && php -S 127.0.0.1:8091 -t public public/index.php
   ```

4. Seed it (about a minute):

   ```bash
   node tools/docs/seed-demo.js --base http://127.0.0.1:8091 --app ~/tiffindb-docs/app --data ~/tiffindb-docs/data --out ~/tiffindb-docs/seed-out
   ```

5. Run the capture script of each page you changed, for example:

   ```bash
   node tools/docs/shots-user-guide.js --base http://127.0.0.1:8091 --state ~/tiffindb-docs/seed-out/state.json --out docs/manual/img
   ```

   A capture script may change the instance (its header says so). To get back to the seeded state, stop the server, empty the data folder, and repeat steps 3 and 4.

6. Look at every changed PNG before you commit it. A screenshot is permanent in a public history. It may show only the synthetic demo data, and never a password, a setup code, or a session value.

7. Reduce the changed PNG files to an indexed palette (ADR 0020 decision 3). The script uses the GD extension that ships with PHP and rewrites a file only when the result is smaller:

   ```bash
   php tools/docs/reduce-png.php docs/manual/img/*.png
   ```

   Then look at the files again: a palette reduction must not make text hard to read.

8. Check each changed page at desktop and phone width, light and dark, with no sideways scroll and no failed requests. `docs/build/documentation-handoff.md` (appendix) has a small script for that.
