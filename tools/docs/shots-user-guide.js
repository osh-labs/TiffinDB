// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Screenshots for docs/manual/user-guide.html (ADR 0020 decision 4).
//
// Usage, against an instance seeded by tools/docs/seed-demo.js (or a copy of
// its data folder):
//   node tools/docs/shots-user-guide.js --base http://127.0.0.1:8093 \
//     --state <folder outside the repository>/state.json --out docs/manual/img
//
// Signs in as the demo Admin and the demo Member with the generated passwords
// from state.json and writes user-*.png files to --out. No password is
// written anywhere, and every password field is empty or masked when it is
// captured.
//
// THIS SCRIPT CHANGES THE INSTANCE, and puts back what it changes:
//   - the Admin's display settings: set to Dark for one shot, then "Back to
//     standard" (the seeded state is the standard settings);
//   - one record (Floor lamp) is moved to the trash for one shot, then
//     restored with "Undo";
//   - the Member gets a temporary password (the one the Admin screen
//     suggests) for the forced-change shot; the script then signs in as the
//     Member and sets the password back to the one in state.json.
// What stays changed: the audit log and the "Last changed" lines of the Floor
// lamp record and the Member record; the Member's earlier sessions are ended;
// one import job (a small generated CSV file that is never imported) is left
// in the staging folder, which TiffinDB removes after a day. That CSV file is
// written to a new temporary folder under the system temporary directory
// (os.tmpdir()), never to --out, and the folder is deleted at the end.
// Run it against a copy of the seeded data, not a real installation.

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
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

const DESKTOP = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1.5 };
const PHONE = { viewport: { width: 390, height: 780 }, deviceScaleFactor: 2 };

/* ---------- helpers ---------- */

const exactly = (text) => new RegExp('^' + text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$');
const label = (page, text) => page.getByLabel(text, { exact: true });
const button = (page, name) => page.getByRole('button', { name, exact: true });
const link = (page, name) => page.getByRole('link', { name, exact: true });

async function settle(page) {
  await page.waitForLoadState('networkidle');
  // Nothing should have focus, so no focus ring shows in a picture.
  await page.evaluate(() => {
    if (document.activeElement && document.activeElement !== document.body) {
      document.activeElement.blur();
    }
  });
}

/**
 * Puts numbered badges on the page (teal circle, white digit, white ring).
 * Each item: { target: Locator, n, at: 'tl'|'tr'|'l'|'r'|'t'|'bl', dx, dy }.
 */
async function callouts(page, items) {
  const boxes = [];
  for (const item of items) {
    const box = await item.target.first().boundingBox();
    if (!box) {
      throw new Error(`Callout ${item.n}: target not visible`);
    }
    boxes.push({ n: item.n, at: item.at || 'tl', dx: item.dx || 0, dy: item.dy || 0, box });
  }
  await page.evaluate((list) => {
    const size = 30;
    for (const c of list) {
      const b = c.box;
      let x = b.x;
      let y = b.y;
      if (c.at === 'tr') { x = b.x + b.width; }
      if (c.at === 'l') { y = b.y + b.height / 2; x = b.x - size / 2 - 4; }
      if (c.at === 'r') { y = b.y + b.height / 2; x = b.x + b.width + size / 2 + 4; }
      if (c.at === 't') { x = b.x + b.width / 2; y = b.y - size / 2 - 2; }
      if (c.at === 'bl') { y = b.y + b.height; }
      const el = document.createElement('span');
      el.textContent = String(c.n);
      el.setAttribute('aria-hidden', 'true');
      el.style.cssText = [
        'position:absolute', 'z-index:99999', `width:${size}px`, `height:${size}px`,
        `left:${x + window.scrollX - size / 2 + c.dx}px`, `top:${y + window.scrollY - size / 2 + c.dy}px`,
        'border-radius:50%', 'background:#084F4A', 'color:#FFFFFF',
        'font:700 16px/30px Inter, system-ui, sans-serif', 'text-align:center',
        'box-shadow:0 0 0 2px #FFFFFF, 0 2px 6px rgba(0,0,0,.35)', 'pointer-events:none',
      ].join(';');
      document.body.appendChild(el);
    }
  }, boxes);
}

/** The region from the top of the page to the bottom of the given element. */
async function clipTo(page, locator, pad = 24, top = 0) {
  const box = await locator.first().boundingBox();
  return { x: 0, y: top, width: page.viewportSize().width, height: Math.ceil(box.y + box.height + pad - top) };
}

/** From the top of the page to the bottom of the last thing in main. */
async function clipMain(page, pad = 24, width = null) {
  const bottom = await page.evaluate(() => Math.max(...Array.from(document.querySelectorAll('main > *'))
    .map((el) => el.getBoundingClientRect().bottom + window.scrollY)));
  return { x: 0, y: 0, width: width || page.viewportSize().width, height: Math.ceil(bottom + pad) };
}

/** Region around an element, inside the viewport width. */
async function around(page, locator, pad = 16) {
  const box = await locator.first().boundingBox();
  const w = page.viewportSize().width;
  const x = Math.max(0, Math.floor(box.x - pad));
  const y = Math.max(0, Math.floor(box.y - pad));
  return { x, y, width: Math.min(w - x, Math.ceil(box.width + 2 * pad)), height: Math.ceil(box.height + 2 * pad) };
}

/* ---------- the screenshots ---------- */

/*
 * Each entry: file, who ('admin', 'member', 'guest', 'phone'), and capture(),
 * which prepares the page and returns Playwright screenshot options
 * (clip, fullPage) or { element: Locator }.
 */
const SHOTS = [
  {
    // Part 2. The sign-in page, empty.
    file: 'user-sign-in.png', who: 'guest',
    async capture(page, base) {
      await page.goto(base + '/sign-in');
      await settle(page);
      return { clip: await clipMain(page, 40) };
    },
  },
  {
    // Part 2. The forced password change at first sign-in (Member).
    file: 'user-password-forced.png', who: 'special',
    async capture(page, base, state, ctx) {
      return ctx.forcedPassword(page);
    },
  },
  {
    // Part 3. The tour: the Items list with numbered callouts.
    file: 'user-tour.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await settle(page);
      const side = page.locator('nav.sidebar');
      await callouts(page, [
        { n: 1, target: page.locator('nav.top-nav'), at: 'tl', dx: -4, dy: 2 },
        { n: 2, target: page.locator('form.top-search'), at: 'tl', dx: 2, dy: 2 },
        { n: 3, target: page.locator('a.top-pill-display'), at: 'tl', dx: 2, dy: 2 },
        { n: 4, target: page.locator('a.user-chip'), at: 'tl', dx: 2, dy: 2 },
        { n: 5, target: side.locator('.sidebar-heading', { hasText: 'Tables' }), at: 'r', dx: -52 },
        { n: 6, target: side.locator('.sidebar-heading', { hasText: 'Collections' }), at: 'r', dx: -52 },
        { n: 7, target: side.locator('.sidebar-heading', { hasText: 'Data' }), at: 'r', dx: -52 },
        { n: 8, target: side.locator('.sidebar-foot'), at: 'tr', dx: -36, dy: 2 },
        { n: 9, target: page.locator('.page-actions a.button-primary'), at: 'l' },
        { n: 10, target: page.locator('form.list-controls'), at: 'tl', dx: -6, dy: 4 },
        { n: 11, target: page.locator('table.data-table thead th').first(), at: 'tl', dx: -4, dy: 4 },
      ]);
      return { clip: await clipTo(page, side.locator('.sidebar-foot'), 28) };
    },
  },
  {
    // Part 4. Your projects (Admin).
    file: 'user-projects.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.projects);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 4. Your projects (Member): only the shared project.
    file: 'user-projects-member.png', who: 'member',
    async capture(page, base, state) {
      await page.goto(base + state.paths.projects);
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 5. Sort and search controls, after a search.
    file: 'user-list-search.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await label(page, 'Find in Items').fill('lamp');
      await label(page, 'Sort by').selectOption({ label: 'Value, highest first' });
      await button(page, 'Apply').click();
      await settle(page);
      return { clip: await clipTo(page, page.locator('table.data-table'), 12) };
    },
  },
  {
    // Part 5. Filter, step 2 (a choice field).
    file: 'user-filter.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await link(page, 'Filter').click();
      await label(page, 'Field').selectOption({ label: 'Category (Choice)' });
      await button(page, 'Next').click();
      await page.waitForLoadState('networkidle');
      await label(page, 'Choice').selectOption({ label: 'Appliance' });
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 5. The list with the filter in use.
    file: 'user-filter-result.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await link(page, 'Filter').click();
      await label(page, 'Field').selectOption({ label: 'Category (Choice)' });
      await button(page, 'Next').click();
      await page.waitForLoadState('networkidle');
      await label(page, 'Choice').selectOption({ label: 'Appliance' });
      await button(page, 'Show matching records').click();
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 5. Project-wide search results.
    file: 'user-search.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await page.locator('#top-search').fill('garage');
      await page.locator('#top-search').press('Enter');
      await page.waitForURL(/\/search/);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 6. A record, with numbered callouts (full page).
    file: 'user-record.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.records.withAttachments.path);
      await settle(page);
      await callouts(page, [
        { n: 1, target: page.locator('nav.breadcrumb'), at: 'tl', dx: -4, dy: 2 },
        { n: 2, target: page.locator('.record-nav'), at: 'tl', dx: -4, dy: 2 },
        { n: 3, target: page.locator('legend', { hasText: exactly('Details') }), at: 'r', dx: 4 },
        { n: 4, target: page.locator('.link-control').first(), at: 'tl', dx: -6, dy: 2 },
        { n: 5, target: page.locator('legend', { hasText: exactly('Files') }), at: 'r', dx: 4 },
        { n: 6, target: page.locator('legend', { hasText: exactly('Warranties') }), at: 'r', dx: 4 },
        { n: 7, target: button(page, 'Save'), at: 'tl', dx: 0, dy: 0 },
        { n: 8, target: button(page, 'Move to trash'), at: 'tl' },
      ]);
      return { fullPage: true };
    },
  },
  {
    // Part 6. A value that does not fit the field: the error summary.
    file: 'user-record-error.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.newRecordPath);
      await label(page, 'Name').fill('Example radio');
      await label(page, 'Value (US dollars)').fill('twelve');
      await label(page, 'Quantity').fill('two');
      await button(page, 'Save').click();
      await page.waitForLoadState('networkidle');
      await settle(page);
      return { clip: await clipTo(page, page.locator('.field', { has: label(page, 'Quantity') }), 24) };
    },
  },
  {
    // Part 7. The link picker for the Room of a record.
    file: 'user-link-picker.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.records.withAttachments.path);
      await link(page, 'Choose a different room').click();
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 7. A room record: its linked items.
    file: 'user-room-links.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.records.withLinks.path);
      await settle(page);
      const group = page.locator('fieldset.record-section', { has: page.locator('legend', { hasText: exactly('Items') }) });
      return { clip: await around(page, group, 12) };
    },
  },
  {
    // Part 8. Add a file.
    file: 'user-file-add.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.records.withPdf.path);
      await link(page, 'Add a file').first().click();
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 8. The page of one attached picture.
    file: 'user-file-page.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.records.withAttachments.path);
      const photo = page.locator('.field', { has: page.locator('p.label', { hasText: exactly('Photo') }) });
      await photo.locator('a.file-open').first().click();
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 9. A saved collection.
    file: 'user-collection.png', who: 'admin',
    async capture(page, base, state, ctx) {
      // A wider window, so that every column of the list fits; put back afterwards.
      await page.setViewportSize({ width: 1440, height: 900 });
      ctx.after = async () => { await page.setViewportSize(DESKTOP.viewport); };
      await page.goto(base + state.projects.homeInventory.collections[1].path);
      await settle(page);
      return { clip: await clipTo(page, page.locator('.table-wrap'), 20) };
    },
  },
  {
    // Part 9. The collection editor, with the preview.
    file: 'user-collection-edit.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.collections[2].editPath);
      // The form holds a hidden default "Preview" button too; both do the same.
      await button(page, 'Preview').last().click();
      await page.waitForLoadState('networkidle');
      await settle(page);
      return { fullPage: true };
    },
  },
  {
    // Part 10. The notice after "Move to trash", with Undo. Restored at once.
    file: 'user-trash-undo.png', who: 'admin',
    async capture(page, base, state, ctx) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await page.getByRole('link', { name: 'Floor lamp', exact: true }).click();
      await button(page, 'Move to trash').click();
      await page.waitForLoadState('networkidle');
      await settle(page);
      ctx.after = async () => {
        await page.getByRole('button', { name: /^Undo/ }).click();
        await page.locator('.notice', { hasText: 'Floor lamp has been restored.' }).waitFor();
      };
      return { clip: await clipTo(page, page.locator('form.list-controls'), 16) };
    },
  },
  {
    // Part 10. The trash (Admin view).
    file: 'user-trash.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.trashPath);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 10. Empty the trash: the confirmation page (not confirmed).
    file: 'user-trash-empty.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.trashPath);
      await link(page, 'Empty the trash').click();
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 11. Import, step 1.
    file: 'user-import-choose.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.importPath);
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 11. Import, step 2: check the fields (a small generated CSV file).
    file: 'user-import-check.png', who: 'admin',
    async capture(page, base, state, ctx) {
      await page.goto(base + state.projects.homeInventory.importPath);
      await label(page, 'File').setInputFiles(ctx.booksCsv);
      await button(page, 'Continue').click();
      await page.getByRole('button', { name: /^Import \d+ rows$/ }).waitFor();
      await settle(page);
      return { fullPage: true };
    },
  },
  {
    // Part 11. The report of the import that the seed made.
    file: 'user-import-report.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.importReportPath);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 12. Export.
    file: 'user-export.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.exportPath);
      await settle(page);
      return { fullPage: true };
    },
  },
  {
    // Part 13. Reading and display, with callouts.
    file: 'user-settings.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.settings);
      await settle(page);
      await callouts(page, [
        { n: 1, target: page.locator('#settings-theme legend'), at: 'r', dx: 4 },
        { n: 2, target: page.locator('#settings-accent legend'), at: 'r', dx: 4 },
        { n: 3, target: page.locator('#settings-size legend'), at: 'r', dx: 4 },
        { n: 4, target: page.locator('#settings-spacing legend'), at: 'r', dx: 4 },
        { n: 5, target: page.locator('#settings-lettering legend'), at: 'r', dx: 4 },
        { n: 6, target: page.locator('#preview-title'), at: 'r', dx: 4 },
        { n: 7, target: button(page, 'Save settings'), at: 'tl' },
      ]);
      return { fullPage: true };
    },
  },
  {
    // Part 13. Theme pair, light: top of the Items list.
    file: 'user-theme-light.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await settle(page);
      return { clip: { x: 0, y: 0, width: 1280, height: 520 } };
    },
  },
  {
    // Part 13. Theme pair, dark: the same view after "Dark" is saved. Put back
    // to standard afterwards.
    file: 'user-theme-dark.png', who: 'admin',
    async capture(page, base, state, ctx) {
      await page.goto(base + state.paths.settings);
      await page.locator('#settings-theme label.radio-card', { has: page.locator('span', { hasText: exactly('Dark') }) }).click();
      await button(page, 'Save settings').click();
      await page.locator('.notice', { hasText: 'Your display settings have been saved.' }).waitFor();
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await settle(page);
      ctx.after = async () => {
        await page.goto(base + state.paths.settings);
        await button(page, 'Back to standard').click();
        await page.locator('.notice', { hasText: 'Your display settings are back to standard.' }).waitFor();
      };
      return { clip: { x: 0, y: 0, width: 1280, height: 520 } };
    },
  },
  {
    // Part 3. Phone width: the top of a table page.
    file: 'user-phone-list.png', who: 'phone',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.path);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 3. Phone width: a record.
    file: 'user-phone-record.png', who: 'phone',
    async capture(page, base, state) {
      await page.goto(base + state.records.withAttachments.path);
      await settle(page);
      return { fullPage: false };
    },
  },
  {
    // Part 14. New project form.
    file: 'user-admin-new-project.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.projects);
      await link(page, 'New project').click();
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 14. The fields of the Items table.
    file: 'user-admin-fields.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.fieldsPath);
      await settle(page);
      return { clip: await clipTo(page, page.locator('main table'), 24) };
    },
  },
  {
    // Part 14. Add a field: the type cards.
    file: 'user-admin-field-types.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.tables.items.fieldsPath);
      await link(page, 'Add a field').click();
      await settle(page);
      return { fullPage: true };
    },
  },
  {
    // Part 14. People.
    file: 'user-admin-people.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.people);
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 14. Add a person: the suggested temporary password is masked.
    file: 'user-admin-add-person.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.people);
      await link(page, 'Add a person').click();
      await page.waitForLoadState('networkidle');
      await label(page, 'Temporary password').evaluate((el) => {
        el.value = '';
        el.setAttribute('placeholder', '');
      });
      await settle(page);
      return { fullPage: true };
    },
  },
  {
    // Part 14. Sharing for a project.
    file: 'user-admin-sharing.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.projects.homeInventory.sharingPath);
      await settle(page);
      return { clip: await clipMain(page) };
    },
  },
  {
    // Part 14. Plugins.
    file: 'user-admin-plugins.png', who: 'admin',
    async capture(page, base, state) {
      await page.goto(base + state.paths.plugins);
      await settle(page);
      return { fullPage: true };
    },
  },
];

/* ---------- the forced password change ---------- */

/**
 * Gives the Member a temporary password (the one the screen suggests), signs
 * in with it, captures the forced-change page with empty fields, then changes
 * the password back to the one in state.json.
 */
function forcedPassword(browser, base, state) {
  return async (page) => {
    const admin = await browser.newContext(DESKTOP);
    const adminPage = await admin.newPage();
    await signIn(adminPage, base, state.admin.username, state.admin.password);
    await adminPage.goto(base + state.member.path);
    const temporary = await label(adminPage, 'Temporary password').inputValue();
    await button(adminPage, 'Set temporary password').click();
    await adminPage.locator('.notice', { hasText: 'now has a temporary password' }).waitFor();
    await admin.close();

    await signIn(page, base, state.member.username, temporary);
    await page.locator('main h1', { hasText: 'Change your password' }).waitFor();
    await settle(page);
    const clip = await clipTo(page, page.locator('main form'), 24);
    return {
      clip,
      after: async () => {
        await label(page, 'Current password').fill(temporary);
        await label(page, 'New password').fill(state.member.password);
        await label(page, 'Type the new password again').fill(state.member.password);
        await button(page, 'Change password').click();
        await page.locator('.notice', { hasText: 'Your password has been changed.' }).waitFor();
      },
    };
  };
}

/* ---------- run ---------- */

function booksCsv() {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'tiffindb-shots-')), 'books.csv');
  const rows = [
    ['Title', 'Shelf', 'Pages', 'Finished', 'Read'],
    ['Example Novel One', 'Fiction', '320', '2025-02-11', 'Yes'],
    ['Example Atlas', 'Reference', '96', '', 'No'],
    ['Example Cookbook', 'Cooking', '210', '2024-10-03', 'Yes'],
    ['Example Field Guide', 'Reference', '184', '', 'No'],
    ['Example Novel Two', 'Fiction', '275', '2026-06-30', 'Yes'],
  ];
  fs.writeFileSync(file, rows.map((r) => r.join(',')).join('\r\n') + '\r\n');
  return file;
}

async function main() {
  const opt = args();
  const base = opt.base.replace(/\/+$/, '');
  const state = JSON.parse(fs.readFileSync(opt.state, 'utf8'));
  fs.mkdirSync(opt.out, { recursive: true });
  const only = opt.only ? opt.only.split(',') : null;

  const browser = await chromium.launch();
  const sessions = {};
  async function pageFor(who) {
    if (who === 'guest' || who === 'special') {
      const ctx = await browser.newContext(DESKTOP);
      return { page: await ctx.newPage(), close: () => ctx.close() };
    }
    if (!sessions[who]) {
      const ctx = await browser.newContext(who === 'phone' ? PHONE : DESKTOP);
      const page = await ctx.newPage();
      const person = who === 'member' ? state.member : state.admin;
      await signIn(page, base, person.username, person.password);
      sessions[who] = { ctx, page };
    }
    return { page: sessions[who].page, close: async () => {} };
  }

  const ctx = { booksCsv: booksCsv(), forcedPassword: forcedPassword(browser, base, state) };
  try {
    for (const shot of SHOTS) {
      if (only && !only.includes(shot.file)) {
        continue;
      }
      // The forced change ends the Member's other sessions: sign in again after it.
      if (shot.who === 'special') {
        if (sessions.member) {
          await sessions.member.ctx.close();
          delete sessions.member;
        }
      }
      const { page, close } = await pageFor(shot.who);
      ctx.after = null;
      const result = await shot.capture(page, base, state, ctx);
      const target = path.join(opt.out, shot.file);
      if (result.element) {
        await result.element.screenshot({ path: target });
      } else {
        await page.screenshot({ path: target, clip: result.clip, fullPage: result.fullPage === true || Boolean(result.clip) });
      }
      console.log('wrote ' + shot.file);
      if (result.after) {
        await result.after();
      }
      if (ctx.after) {
        await ctx.after();
      }
      await close();
    }
  } finally {
    await browser.close();
    fs.rmSync(path.dirname(ctx.booksCsv), { recursive: true, force: true });
  }
}

main().catch((error) => {
  console.error('shots-user-guide: ' + String(error.message).split('\n')[0]);
  process.exit(1);
});
