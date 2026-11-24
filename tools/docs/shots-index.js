// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Takes the hero screenshot of docs/manual/index.html: the Items list of the
// seeded "Home Inventory" project, signed in as the Admin, light theme.
//
// Usage:
//   node tools/docs/shots-index.js --base <url> --state <state.json> --out <img folder>
//
// Needs an instance seeded by seed-demo.js. Read-only: changes nothing.
'use strict';

const path = require('path');
const fs = require('fs');
const { signIn } = require('./seed-demo.js');

function parseArgs(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i += 2) {
    const key = argv[i].replace(/^--/, '');
    if (!['base', 'state', 'out'].includes(key) || argv[i] === key || argv[i + 1] === undefined) {
      return null;
    }
    args[key] = argv[i + 1];
  }
  return args.base && args.state && args.out ? args : null;
}

async function main(args) {
  const { chromium } = require('playwright');
  const base = args.base.replace(/\/+$/, '');
  const state = JSON.parse(fs.readFileSync(args.state, 'utf8'));
  fs.mkdirSync(args.out, { recursive: true });
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1.5, colorScheme: 'light' });
    context.setDefaultTimeout(20000);
    await context.route((url) => url.origin !== new URL(base).origin, (blocked) => blocked.abort());
    const page = await context.newPage();
    await signIn(page, base, state.admin.username, state.admin.password);

    // index-hero.png: the Items list, first screen.
    await page.goto(base + state.projects.homeInventory.tables.items.path);
    await page.getByRole('heading', { name: 'Items', level: 1 }).waitFor();
    await page.evaluate(() => document.fonts.ready);
    const file = path.join(args.out, 'index-hero.png');
    await page.screenshot({ path: file });
    console.log('wrote ' + file);
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  const args = parseArgs(process.argv.slice(2));
  if (args === null) {
    console.error('Usage: node tools/docs/shots-index.js --base <url> --state <state.json> --out <img folder>');
    process.exit(2);
  }
  main(args).catch((error) => {
    console.error('shots-index: ' + String(error.message).split('\n')[0]);
    process.exit(1);
  });
}
