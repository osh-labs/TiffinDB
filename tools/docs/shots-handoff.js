// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Screenshots for docs/manual/handoff.html (ADR 0020 decision 4).
//
// Usage, against an instance seeded by tools/docs/seed-demo.js:
//   node tools/docs/shots-handoff.js --base http://127.0.0.1:8095 \
//     --state <folder outside the repository>/state.json --out docs/manual/img
//
// Signs in as the demo Admin with the generated password from state.json and
// writes handoff-*.png files to --out. No password is written anywhere.

'use strict';

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const { signIn } = require('./seed-demo.js');

function args() {
  const out = {};
  const argv = process.argv.slice(2);
  for (let i = 0; i < argv.length; i += 2) {
    if (!argv[i].startsWith('--') || argv[i + 1] === undefined) {
      throw new Error('Usage: --base <url> --state <state.json> --out <img folder>');
    }
    out[argv[i].slice(2)] = argv[i + 1];
  }
  for (const key of ['base', 'state', 'out']) {
    if (!out[key]) {
      throw new Error(`Missing --${key}`);
    }
  }
  return out;
}

const VIEWPORT = { width: 1280, height: 800 };
const SCALE = 1.5;

/** One entry per screenshot: file name and what to do before capturing. */
const SHOTS = [
  {
    // The running application at a glance: the Items list of Home Inventory.
    file: 'handoff-app-list.png',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await page.waitForLoadState('networkidle');
      return { fullPage: false };
    },
  },
  {
    // The schema editor: the fields of the Items table.
    file: 'handoff-schema-editor.png',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.fieldsPath);
      await page.waitForLoadState('networkidle');
      return { fullPage: false };
    },
  },
];

(async () => {
  const opts = args();
  const state = JSON.parse(fs.readFileSync(opts.state, 'utf8'));
  const base = opts.base.replace(/\/+$/, '');
  fs.mkdirSync(opts.out, { recursive: true });

  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: SCALE, colorScheme: 'light' });
    const page = await context.newPage();
    await signIn(page, base, state.admin.username, state.admin.password);
    for (const shot of SHOTS) {
      const options = await shot.capture(page, base, state);
      // No focus ring or hover state in the picture.
      await page.evaluate(() => document.activeElement && document.activeElement.blur());
      await page.mouse.move(0, 0);
      const file = path.join(opts.out, shot.file);
      await page.screenshot({ path: file, ...options });
      console.log('Wrote ' + file);
    }
    await context.close();
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
