// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Seeds a freshly un-installed TiffinDB instance with demo data for the
// documentation screenshots, by driving the real UI with Playwright: the
// installer, the schema editor, record forms, the link picker, file uploads,
// the CSV import, saved collections, the trash, people, and sharing.
//
// Usage:
//   node tools/docs/seed-demo.js --base http://127.0.0.1:8091 \
//     --app <app root> --data <data folder> --out <folder for state.json>
//
// --data is the folder the installer writes setup-code.txt to (data_dir in
// config/local.php); without it the script looks in <app>/../tiffindb-data
// and <app>/data. The script refuses to run (exit code 1) when the instance
// is already installed, and exits non-zero naming the step when a step fails.
//
// Every value is synthetic and comes from the fixed lists below, so each run
// gives the same records (no random data; only the passwords and the project,
// table, and collection keys differ between runs). Passwords are made at run
// time and written only to <out>/state.json, together with the URL paths of
// everything the script made. The generated items.csv and the attachment
// files are written to <out> too. The only network use is the given base URL.
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ADMIN = { name: 'Example Admin', username: 'admin' };
const MEMBER = { name: 'Example Member', username: 'member' };
const CATEGORIES = ['Appliance', 'Cookware', 'Electronics', 'Furniture', 'Outdoor', 'Tool'];
const VALUE = 'Value (US dollars)';

// Name, floor, notes.
const ROOMS = [
  ['Kitchen', 'Ground floor', 'Small appliances live in the tall cupboard.'],
  ['Living room', 'Ground floor', ''],
  ['Study', 'Upper floor', 'Shelving along the north wall.'],
  ['Bedroom', 'Upper floor', ''],
  ['Hallway', 'Ground floor', ''],
  ['Garage', 'Outside', 'Tools on the pegboard, camping gear on the top rack.'],
];

// Name, category, room, purchased, value, quantity, condition, under warranty, manual, notes.
// The serial number is EX- plus the position in this list: the first four are
// typed into the record form, the rest go through the CSV import.
const ITEMS = [
  ['Stand mixer', 'Appliance', 'Kitchen', '2025-03-14', '329.00', 1, 5, true, 'https://example.com/manuals/stand-mixer', 'Comes with dough hook, whisk, and flat beater. Spare bowl is on the top shelf.'],
  ['Cordless drill', 'Tool', 'Garage', '2023-11-02', '149.00', 1, 4, true, 'https://example.com/manuals/cordless-drill', 'Two batteries and a charger in the case.'],
  ['Bookshelf, oak', 'Furniture', 'Study', '2023-09-18', '240.00', 1, 4, false, '', 'Five shelves. Anchored to the wall.'],
  ['Espresso machine', 'Appliance', 'Kitchen', '2026-01-20', '499.00', 1, 5, true, 'https://example.com/manuals/espresso-machine', 'Descale every three months.'],
  ['Dutch oven', 'Cookware', 'Kitchen', '2024-12-25', '95.00', 1, 5, false, '', 'Enamelled cast iron, 5 litre.'],
  ['Floor lamp', 'Furniture', 'Living room', '2023-06-09', '89.00', 2, 3, false, '', ''],
  ['Monitor, 27 inch', 'Electronics', 'Study', '2024-04-23', '289.00', 1, 4, true, 'https://example.com/manuals/monitor-27', ''],
  ['Robot vacuum', 'Appliance', 'Hallway', '2025-05-06', '379.00', 1, 4, true, 'https://example.com/manuals/robot-vacuum', 'Dock is under the coat rack.'],
  ['Sewing machine', 'Appliance', 'Study', '2023-02-11', '265.00', 1, 3, false, '', 'Needs a service: the bobbin winder sticks.'],
  ['Tent, 4-person', 'Outdoor', 'Garage', '2024-07-01', '310.00', 1, 5, false, '', 'Pegs and repair kit are in the bag.'],
  ['Toaster', 'Appliance', 'Kitchen', '2023-08-15', '45.00', 1, 2, false, '', 'Left slot no longer heats.'],
  ['Cast iron skillet', 'Cookware', 'Kitchen', '2023-03-03', '38.50', 2, 5, false, '', ''],
  ['Kitchen scale', 'Electronics', 'Kitchen', '2025-08-21', '24.99', 1, 4, false, '', ''],
  ['Office chair', 'Furniture', 'Study', '2024-01-12', '185.00', 1, 4, false, '', ''],
  ['Desk lamp', 'Furniture', 'Study', '2025-09-30', '42.00', 1, 5, false, '', ''],
  ['Bluetooth speaker', 'Electronics', 'Living room', '2025-11-28', '79.00', 1, 4, true, 'https://example.com/manuals/bluetooth-speaker', ''],
  ['Television, 43 inch', 'Electronics', 'Living room', '2024-11-29', '429.00', 1, 5, true, 'https://example.com/manuals/television-43', 'Wall bracket bought separately.'],
  ['Sofa, three-seat', 'Furniture', 'Living room', '2023-05-20', '899.00', 1, 3, false, '', 'Covers are machine washable.'],
  ['Step ladder', 'Tool', 'Garage', '2023-04-08', '64.00', 1, 4, false, '', ''],
  ['Lawn mower', 'Outdoor', 'Garage', '2025-04-12', '259.00', 1, 4, true, 'https://example.com/manuals/lawn-mower', 'Blade sharpened in spring 2026.'],
  ['Bicycle pump', 'Outdoor', 'Garage', '2024-06-18', '27.50', 1, 2, false, '', 'Gauge is cracked.'],
  ['Coat rack', 'Furniture', 'Hallway', '2023-10-07', '55.00', 1, 4, false, '', ''],
  ['Wall mirror', 'Furniture', 'Hallway', '2024-02-24', '72.00', 1, 5, false, '', ''],
  ['Bedside table', 'Furniture', 'Bedroom', '2024-09-14', '68.00', 2, 4, false, '', ''],
  ['Air purifier', 'Appliance', 'Bedroom', '2026-02-03', '199.00', 1, 5, true, 'https://example.com/manuals/air-purifier', 'Filter due for replacement in August 2026.'],
  ['Reading light', 'Electronics', 'Bedroom', '2025-12-19', '34.00', 1, 4, false, '', ''],
].map((row, index) => ({
  name: row[0], category: row[1], room: row[2], purchased: row[3], value: row[4], quantity: row[5],
  condition: row[6], warranty: row[7], manual: row[8], notes: row[9],
  serial: 'EX-' + String(index + 1).padStart(4, '0'),
}));
const FORM_ITEMS = ITEMS.slice(0, 4);
const CSV_ITEMS = ITEMS.slice(4);
const TRASHED = ['Toaster', 'Bicycle pump'];

// Item, kind, provider, starts, ends, reference, support phone, support email.
const WARRANTIES = [
  ['Stand mixer', 'manufacturer warranty', 'Example Appliances Ltd', '2025-03-14', '2027-03-14', 'W-EX-1001', '555-0101', 'support@example.com'],
  ['Espresso machine', 'extended warranty', 'Example Appliances Ltd', '2026-01-20', '2029-01-20', 'W-EX-1002', '555-0101', 'support@example.com'],
  ['Cordless drill', 'manufacturer warranty', 'Example Tools Co', '2023-11-02', '2026-11-02', 'W-EX-1003', '555-0123', 'service@example.com'],
  ['Robot vacuum', 'manufacturer warranty', 'Example Appliances Ltd', '2025-05-06', '2027-05-06', 'W-EX-1004', '555-0101', 'support@example.com'],
  ['Television, 43 inch', 'extended warranty', 'Example Electronics Ltd', '2024-11-29', '2027-11-29', 'W-EX-1005', '555-0147', 'help@example.com'],
  ['Lawn mower', 'manufacturer warranty', 'Example Garden Supplies', '2025-04-12', '2027-04-12', 'W-EX-1006', '555-0166', 'care@example.com'],
];

// Name, course, prep time, serves, rating, method.
const RECIPES = [
  ['Lentil soup', 'Starter', '0:40', '4', 4, 'Simmer lentils with onion and carrot until soft. Season to taste.'],
  ['Flatbread', 'Baking', '1:15', '6', 5, 'Mix flour, water, yeast, and salt. Rest for an hour, then cook in a hot pan.'],
  ['Tomato sauce', 'Main', '0:30', '4', 4, 'Soften garlic in oil, add chopped tomatoes, and cook down.'],
  ['Oat biscuits', 'Baking', '0:25', '12', 3, 'Rub butter into oats and flour, add sugar, shape, and bake.'],
  ['Fruit salad', 'Dessert', '0:15', '4', 5, 'Chop whatever fruit is in season. Add a squeeze of lemon.'],
];

/* ---------- small helpers ---------- */

let currentStep = 'starting';
function step(name) {
  currentStep = name;
  console.log('- ' + name);
}

const route = (base, to) => base.replace(/\/+$/, '') + to;
const pathOf = (page) => new URL(page.url()).pathname;
const exactly = (text) => new RegExp('^' + text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$');
const label = (page, text) => page.getByLabel(text, { exact: true });
const press = (page, name) => page.getByRole('button', { name, exact: true }).click();
const password = () => 'Demo-' + crypto.randomBytes(12).toString('base64url');

/** Waits for the page's status notice to show the given text. */
async function notice(page, text) {
  await page.locator('.notice[role="status"]').filter({ hasText: text }).first().waitFor();
}

/** The block of a link or file field on a saved record, found by its label. */
function fieldBlock(page, name) {
  return page.locator('.field', { has: page.locator('p.label', { hasText: exactly(name) }) });
}

/** Signs in through /sign-in. Exported for the screenshot scripts. */
async function signIn(page, base, username, pass) {
  await page.goto(route(base, '/sign-in'));
  await label(page, 'Username').fill(username);
  await label(page, 'Password').fill(pass);
  await press(page, 'Sign in');
  await page.waitForURL((url) => !url.pathname.endsWith('/sign-in'));
}

/* ---------- generated files ---------- */

function csvText(rows) {
  const cell = (value) => (/[",\r\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value);
  return rows.map((row) => row.map((value) => cell(String(value))).join(',')).join('\r\n') + '\r\n';
}

function itemsCsv() {
  const head = ['Name', 'Category', 'Room', 'Purchased', 'Value', 'Quantity', 'Condition', 'Serial number', 'Under warranty', 'Manual', 'Notes'];
  const rows = CSV_ITEMS.map((i) => [i.name, i.category, i.room, i.purchased, i.value, i.quantity, i.condition, i.serial, i.warranty ? 'Yes' : 'No', i.manual, i.notes]);
  return csvText([head, ...rows]);
}

/** A minimal one-page PDF holding the given ASCII lines. */
function pdfText(lines) {
  const text = lines.map((line, i) => `BT /F1 12 Tf 50 ${780 - i * 20} Td (${line.replace(/[()\\]/g, '\\$&')}) Tj ET`).join('\n');
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${text.length} >>\nstream\n${text}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let pdf = '%PDF-1.4\n';
  const offsets = objects.map((object, i) => {
    const at = pdf.length;
    pdf += `${i + 1} 0 obj\n${object}\nendobj\n`;
    return at;
  });
  const xref = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  pdf += offsets.map((at) => String(at).padStart(10, '0') + ' 00000 n \n').join('');
  return pdf + `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
}

/** Writes items.csv and the three attachment files into the out folder. */
async function writeFiles(context, out) {
  step('write the generated files');
  const files = {
    csv: path.join(out, 'items.csv'),
    receiptText: path.join(out, 'receipt-EX-0001.txt'),
    receiptPdf: path.join(out, 'receipt-EX-0004.pdf'),
    photo: path.join(out, 'photo-EX-0001.png'),
  };
  const receipt = (item) => ['Example receipt (sample data)', 'Seller: Example Appliances Ltd', `Item: ${item.name}`,
    `Serial number: ${item.serial}`, `Date: ${item.purchased}`, `Total: USD ${item.value}`];
  fs.writeFileSync(files.csv, itemsCsv());
  fs.writeFileSync(files.receiptText, receipt(ITEMS[0]).join('\n') + '\n');
  fs.writeFileSync(files.receiptPdf, pdfText(receipt(ITEMS[3])));
  // The photo is a drawn placeholder, rendered locally (no network).
  const page = await context.newPage();
  await page.setViewportSize({ width: 480, height: 320 });
  await page.setContent('<body style="margin:0;height:100vh;display:grid;place-items:center;background:#dceeeb;'
    + 'color:#084f4a;font:600 26px sans-serif"><div style="text-align:center"><div style="width:120px;height:120px;'
    + 'margin:0 auto 16px;border:6px solid #084f4a;border-radius:24px 24px 60px 60px"></div>Example photo: stand mixer</div></body>');
  fs.writeFileSync(files.photo, await page.screenshot());
  await page.close();
  return files;
}

/* ---------- UI building blocks ---------- */

/** Fills a record form. Values: text, true/false (checkbox), {choice}, or {rating}. */
async function fillRecord(page, values) {
  for (const [name, value] of Object.entries(values)) {
    if (typeof value === 'boolean') {
      await label(page, name).setChecked(value);
    } else if (value.choice !== undefined) {
      await label(page, name).selectOption({ label: value.choice });
    } else if (value.rating !== undefined) {
      await page.getByRole('group', { name, exact: true }).getByLabel(new RegExp(`^${value.rating}(,|$)`)).check();
    } else {
      await label(page, name).fill(String(value));
    }
  }
}

/** Adds a record through the list's Add button and the record form; returns its path. */
async function addRecord(page, table, values) {
  step(`add ${table.item} "${values.Name}" to ${table.name}`);
  await page.goto(route(table.base, table.path));
  await page.getByRole('link', { name: `Add ${table.item}`, exact: true }).click();
  await fillRecord(page, values);
  await press(page, 'Save');
  await notice(page, `The ${table.item} has been added.`);
  return pathOf(page);
}

/** Opens a record's link picker for one field, finds the target, and links it. */
async function linkRecord(page, base, recordPath, fieldName, title) {
  step(`link "${title}" in ${fieldName} of ${recordPath}`);
  await page.goto(route(base, recordPath));
  await fieldBlock(page, fieldName).getByRole('link', { name: /^(Choose a record|Choose a different|Link a record|Link another)/ }).click();
  await page.getByRole('searchbox', { name: /^Find in / }).fill(title);
  await press(page, 'Find');
  await page.getByRole('button', { name: new RegExp(`^(Choose|Link) ${exactly(title).source.slice(1)}`) }).click();
  await notice(page, `${title} is now`);
}

/** Uploads one file to a file field of a saved record. */
async function addFile(page, base, recordPath, fieldName, file) {
  step(`upload ${path.basename(file)} to ${fieldName} of ${recordPath}`);
  await page.goto(route(base, recordPath));
  await fieldBlock(page, fieldName).getByRole('link', { name: /^Add (a file|an image)$/ }).click();
  await label(page, 'File').setInputFiles(file);
  await press(page, 'Add the file');
  await notice(page, `${path.basename(file)} has been added.`);
}

/** Creates a project with a first table; returns {name, path, tablesPath}. */
async function createProject(page, base, name, firstTable) {
  step(`create project ${name}`);
  await page.goto(route(base, '/projects'));
  await page.getByRole('link', { name: 'New project', exact: true }).click();
  await label(page, 'Project name').fill(name);
  await page.getByLabel(/^First table/).fill(firstTable);
  await press(page, 'Create project');
  await notice(page, `${name} has been created, with the table ${firstTable}.`);
  const tablesPath = pathOf(page);
  return { name, base, tablesPath, path: tablesPath.replace(/\/tables$/, ''), tables: {}, collections: [] };
}

/** Reads a table's paths from the project's "Tables and fields" page. */
async function tableInfo(page, project, name, item) {
  await page.goto(route(project.base, project.tablesPath));
  const fieldsPath = await page.getByRole('link', { name: `Change the fields of ${name}`, exact: true }).getAttribute('href');
  return { name, item, base: project.base, fieldsPath, path: fieldsPath.replace(/\/fields$/, '') };
}

/** Adds a table with a Name field through "Add a table". */
async function addTable(page, project, name, item) {
  step(`add table ${name}`);
  await page.goto(route(project.base, project.tablesPath));
  await page.getByRole('link', { name: 'Add a table', exact: true }).click();
  await label(page, 'Table name').fill(name);
  await label(page, 'One record is called').fill(item);
  await press(page, 'Create table');
  await notice(page, `The table ${name} has been created.`);
  return tableInfo(page, project, name, item);
}

/** Sets what one record of a table is called (the first table of a project starts as "item"). */
async function nameItem(page, project, name, item) {
  step(`call one record of ${name} "${item}"`);
  const table = await tableInfo(page, project, name, item);
  await page.goto(route(project.base, table.fieldsPath));
  await label(page, 'One record is called').fill(item);
  await press(page, 'Save table settings');
  await notice(page, 'The table settings have been saved.');
  return table;
}

/** Adds a field: picks the type by its exact UI name, then fills the options page. */
async function addField(page, table, type, name, options = async () => {}) {
  step(`add ${type} field ${name} to ${table.name}`);
  await page.goto(route(table.base, table.fieldsPath));
  await page.getByRole('link', { name: 'Add a field', exact: true }).click();
  await page.locator('label.radio-card', { has: page.getByText(type, { exact: true }) }).getByRole('radio').check();
  await press(page, 'Next');
  await label(page, 'Field name').fill(name);
  await options(page);
  await press(page, 'Add field');
  await notice(page, `The field ${name} has been added.`);
}

const choices = (list) => (page) => label(page, 'Choices, one per line').fill(list.join('\n'));
/** Options of a link field: each record links to one record of `target`, which can hold several. */
const linkTo = (target, reverseName) => async (page) => {
  await label(page, 'Link to records in').selectOption({ label: target });
  await page.getByRole('group', { name: /^Each \S+ can link to$/ }).getByLabel('One record', { exact: true }).check();
  await page.getByRole('group', { name: 'Each record in the linked table can link to', exact: true }).getByLabel('Several of these', { exact: true }).check();
  await label(page, 'Name of the matching field in the linked table').fill(reverseName);
};

/** Finds a record in a table's list by its title and returns the link to it. */
async function findRecord(page, table, title) {
  await page.goto(route(table.base, table.path));
  await page.getByRole('searchbox', { name: `Find in ${table.name}`, exact: true }).fill(title);
  await press(page, 'Apply');
  return page.getByRole('main').getByRole('link', { name: title, exact: true });
}

/** Saves a collection. Rules: {field, test, kind, value} with value typed or picked by label. */
async function addCollection(page, project, table, name, rules, sort) {
  step(`save collection "${name}"`);
  await page.goto(route(table.base, table.path));
  await page.getByRole('link', { name: 'New collection', exact: true }).click();
  await label(page, 'Collection name').fill(name);
  const preview = page.locator('button[data-preview]');
  for (const [index, rule] of rules.entries()) {
    const n = index + 1;
    if (n > 1) {
      await press(page, 'Add a rule');
    }
    // Without script, Preview redisplays the form with the controls that fit the field.
    await label(page, `Field for rule ${n}`).selectOption({ label: rule.field });
    await preview.click();
    await label(page, `Test for rule ${n}`).selectOption({ label: rule.test });
    if (rule.pick !== undefined) {
      await label(page, `${rule.kind} for rule ${n}`).selectOption({ label: rule.pick });
    } else if (rule.type !== undefined) {
      await label(page, `${rule.kind} for rule ${n}`).fill(rule.type);
    }
  }
  await label(page, 'Sort by').selectOption({ label: sort });
  await press(page, 'Save collection');
  await notice(page, 'The collection has been saved.');
  project.collections.push({ name, table: table.name, path: pathOf(page), editPath: pathOf(page) + '/edit' });
}

/* ---------- stages ---------- */

/** Refuses an installed instance, then completes the three installer steps. */
async function install(page, base, args, adminPassword) {
  step('open the installer');
  const response = await page.goto(route(base, '/setup'));
  if (response === null || response.status() !== 200 || !pathOf(page).endsWith('/setup')) {
    throw new Error('ALREADY_INSTALLED');
  }
  if (await page.getByRole('button', { name: 'Check again', exact: true }).count() > 0) {
    throw new Error('the server check (installer step 1) reports something that needs fixing');
  }
  const makeFolder = page.getByRole('button', { name: 'Make the data folder', exact: true });
  if (await makeFolder.count() > 0) {
    step('installer step 2: make the data folder');
    await makeFolder.click();
  }
  step('installer step 3: create the first Admin');
  await label(page, 'Setup code').waitFor();
  const candidates = [args.data, args.app && path.join(args.app, '..', 'tiffindb-data'), args.app && path.join(args.app, 'data')]
    .filter(Boolean).map((folder) => path.join(folder, 'setup-code.txt'));
  const codeFile = candidates.find((file) => fs.existsSync(file));
  if (codeFile === undefined) {
    throw new Error('setup-code.txt not found; looked in ' + candidates.join(', '));
  }
  await label(page, 'Setup code').fill(fs.readFileSync(codeFile, 'utf8').trim());
  await label(page, 'Your name').fill(ADMIN.name);
  await label(page, 'Username').fill(ADMIN.username);
  await label(page, 'Password').fill(adminPassword);
  await label(page, 'Type the password again').fill(adminPassword);
  await press(page, 'Create Admin and finish');
  await notice(page, 'TiffinDB is ready.');
}

/** Home Inventory: the Rooms, Items, and Warranties tables and their fields. */
async function buildInventorySchema(page, base) {
  const project = await createProject(page, base, 'Home Inventory', 'Rooms');
  const rooms = await nameItem(page, project, 'Rooms', 'room');
  await addField(page, rooms, 'Choice', 'Floor', choices(['Ground floor', 'Upper floor', 'Outside']));
  await addField(page, rooms, 'Long text', 'Notes');

  const items = await addTable(page, project, 'Items', 'item');
  await addField(page, items, 'Link to another table', 'Room', linkTo('Rooms', 'Items'));
  await addField(page, items, 'Choice', 'Category', choices(CATEGORIES));
  await addField(page, items, 'Date', 'Purchased');
  await addField(page, items, 'Currency', 'Value');
  await addField(page, items, 'Rating', 'Condition');
  await addField(page, items, 'Number', 'Quantity');
  await addField(page, items, 'Text', 'Serial number', (p) => p.getByLabel(/^Hint shown under the field/).fill('As printed on the label, for example EX-0001.'));
  await addField(page, items, 'Checkbox', 'Under warranty');
  await addField(page, items, 'Web address', 'Manual');
  await addField(page, items, 'Long text', 'Notes');
  await addField(page, items, 'File or image', 'Receipt');
  await addField(page, items, 'File or image', 'Photo', (p) => label(p, 'Only pictures').check());
  await addField(page, items, 'Auto-number', 'Item number');

  const warranties = await addTable(page, project, 'Warranties', 'warranty');
  await addField(page, warranties, 'Link to another table', 'Item', linkTo('Items', 'Warranties'));
  await addField(page, warranties, 'Text', 'Provider');
  await addField(page, warranties, 'Date', 'Starts');
  await addField(page, warranties, 'Date', 'Ends');
  await addField(page, warranties, 'Text', 'Reference');
  await addField(page, warranties, 'Phone number', 'Support phone');
  await addField(page, warranties, 'Email address', 'Support email');

  project.tables = { rooms, items, warranties };
  return project;
}

/** Rooms and the first four items through the record form, with their room links. */
async function addFormRecords(page, project) {
  const { rooms, items } = project.tables;
  const paths = {};
  for (const [name, floor, notes] of ROOMS) {
    paths[name] = await addRecord(page, rooms, { Name: name, Floor: { choice: floor }, Notes: notes });
  }
  for (const item of FORM_ITEMS) {
    paths[item.name] = await addRecord(page, items, {
      Name: item.name, Category: { choice: item.category }, Purchased: item.purchased, [VALUE]: item.value,
      Condition: { rating: item.condition }, Quantity: item.quantity, 'Serial number': item.serial,
      'Under warranty': item.warranty, Manual: item.manual, Notes: item.notes,
    });
    await linkRecord(page, project.base, paths[item.name], 'Room', item.room);
  }
  return paths;
}

/** The rest of the items through the CSV import, into the existing Items table. */
async function importItems(page, project, csvFile) {
  step('import items.csv: choose the file');
  await page.goto(route(project.base, project.tables.items.path));
  await page.getByRole('link', { name: 'Import a file', exact: true }).click();
  await label(page, 'File').setInputFiles(csvFile);
  await press(page, 'Continue');
  step('import items.csv: check the fields');
  await label(page, 'Add to an existing table').check();
  await label(page, 'Existing table').selectOption({ label: 'Items' });
  await press(page, 'Check again');
  for (const column of ['Name', 'Category', 'Room', 'Purchased', 'Value', 'Quantity', 'Condition', 'Serial number', 'Under warranty', 'Manual', 'Notes']) {
    const chosen = await label(page, `Field for ${column}`).locator('option:checked').innerText();
    if (!chosen.startsWith(column + ' (')) {
      throw new Error(`column ${column} was matched to "${chosen}"`);
    }
  }
  if (await page.locator('.warning-panel').count() > 0) {
    throw new Error('the import review shows warnings: ' + (await page.locator('.warning-panel').innerText()));
  }
  step('import items.csv: import and save the rows');
  await press(page, `Import ${CSV_ITEMS.length} rows`);
  // Reading and checking continue by themselves; saving always waits for a press.
  await press(page, 'Save the rows');
  await notice(page, `${CSV_ITEMS.length} rows were imported into Items.`);
  project.importReportPath = pathOf(page);
}

/** Six warranties through the record form, each linked to its item. */
async function addWarranties(page, project) {
  const paths = {};
  for (const [item, kind, provider, starts, ends, reference, phone, email] of WARRANTIES) {
    const name = `${item}, ${kind}`;
    paths[name] = await addRecord(page, project.tables.warranties, {
      Name: name, Provider: provider, Starts: starts, Ends: ends, Reference: reference, 'Support phone': phone, 'Support email': email,
    });
    await linkRecord(page, project.base, paths[name], 'Item', item);
  }
  return paths;
}

async function addCollections(page, project) {
  const items = project.tables.items;
  await addCollection(page, project, items, 'Kitchen', [{ field: 'Room', test: 'links to', kind: 'Record', pick: 'Kitchen' }], 'Name, A to Z');
  await addCollection(page, project, items, 'Worth over $200', [{ field: 'Value', test: 'is more than', kind: 'Amount', type: '200' }], 'Value, highest first');
  await addCollection(page, project, items, 'Under warranty', [
    { field: 'Under warranty', test: 'is ticked' },
    { field: 'Purchased', test: 'is after', kind: 'Date', type: '2023-12-31' },
  ], 'Purchased, newest first');
}

/** Moves two imported items to the trash; returns their paths. */
async function trashItems(page, project) {
  const paths = {};
  for (const title of TRASHED) {
    step(`move "${title}" to the trash`);
    await (await findRecord(page, project.tables.items, title)).click();
    paths[title] = pathOf(page);
    await press(page, 'Move to trash');
    await notice(page, `${title} has been moved to the trash.`);
  }
  return paths;
}

/** Recipe Box: a second, small project that stays with the Admins. */
async function buildRecipeBox(page, base) {
  const project = await createProject(page, base, 'Recipe Box', 'Recipes');
  const recipes = await nameItem(page, project, 'Recipes', 'recipe');
  await addField(page, recipes, 'Choice', 'Course', choices(['Starter', 'Main', 'Dessert', 'Baking']));
  await addField(page, recipes, 'Duration', 'Prep time');
  await addField(page, recipes, 'Number', 'Serves');
  await addField(page, recipes, 'Rating', 'Rating');
  await addField(page, recipes, 'Long text', 'Method');
  for (const [name, course, prep, serves, rating, method] of RECIPES) {
    await addRecord(page, recipes, { Name: name, Course: { choice: course }, 'Prep time': prep, Serves: serves, Rating: { rating }, Method: method });
  }
  project.tables = { recipes };
  return project;
}

/** Adds the Member, completes their forced password change, and shares Home Inventory. */
async function addMember(page, browser, base, project, memberPassword, guard) {
  step('add the Member');
  const temporary = password();
  await page.goto(route(base, '/admin/people'));
  await page.getByRole('link', { name: 'Add a person', exact: true }).click();
  await label(page, 'Name').fill(MEMBER.name);
  await label(page, 'Username').fill(MEMBER.username);
  await label(page, 'Temporary password').fill(temporary);
  await press(page, 'Add person');
  await notice(page, `${MEMBER.name} has been added.`);
  const personPath = pathOf(page);

  step(`share ${project.name} with the Member`);
  await page.goto(route(base, '/projects'));
  await page.getByRole('link', { name: `Sharing for ${project.name}`, exact: true }).click();
  project.sharingPath = pathOf(page);
  await page.getByRole('group', { name: 'Members', exact: true }).getByRole('checkbox', { name: new RegExp('^' + MEMBER.name) }).check();
  await press(page, 'Save sharing');
  await notice(page, `Sharing for ${project.name} has been saved.`);

  step('first sign-in of the Member: forced password change');
  const context = await browser.newContext();
  await guard(context);
  const memberPage = await context.newPage();
  await signIn(memberPage, base, MEMBER.username, temporary);
  await label(memberPage, 'Current password').fill(temporary);
  await label(memberPage, 'New password').fill(memberPassword);
  await label(memberPage, 'Type the new password again').fill(memberPassword);
  await press(memberPage, 'Change password');
  await notice(memberPage, 'Your password has been changed.');
  await memberPage.getByRole('link', { name: `Open ${project.name}`, exact: true }).waitFor();
  await context.close();
  return personPath;
}

/* ---------- main ---------- */

function parseArgs(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i += 2) {
    const key = argv[i].replace(/^--/, '');
    if (!['base', 'app', 'data', 'out'].includes(key) || argv[i] === key || argv[i + 1] === undefined) {
      return null;
    }
    args[key] = argv[i + 1];
  }
  return args.base && args.out && (args.data || args.app) ? args : null;
}

async function seed(args) {
  const { chromium } = require('playwright');
  const base = args.base.replace(/\/+$/, '');
  const out = path.resolve(args.out);
  fs.mkdirSync(out, { recursive: true });
  const secrets = { admin: password(), member: password() };

  const browser = await chromium.launch();
  // Nothing but the instance itself may be requested.
  const guard = (context) => context.route((url) => url.origin !== new URL(base).origin, (blocked) => blocked.abort());
  try {
    const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
    context.setDefaultTimeout(20000);
    await guard(context);
    const page = await context.newPage();

    await install(page, base, args, secrets.admin);
    const files = await writeFiles(context, out);
    const inventory = await buildInventorySchema(page, base);
    const records = await addFormRecords(page, inventory);
    await importItems(page, inventory, files.csv);
    const warranties = await addWarranties(page, inventory);
    await addFile(page, base, records['Stand mixer'], 'Receipt', files.receiptText);
    await addFile(page, base, records['Stand mixer'], 'Photo', files.photo);
    await addFile(page, base, records['Espresso machine'], 'Receipt', files.receiptPdf);
    await addCollections(page, inventory);
    const trashed = await trashItems(page, inventory);
    const recipeBox = await buildRecipeBox(page, base);
    const memberPath = await addMember(page, browser, base, inventory, secrets.member, guard);

    step('write state.json');
    const imported = (await (await findRecord(page, inventory.tables.items, 'Television, 43 inch')).getAttribute('href')).split('?')[0];
    const tables = (project) => Object.fromEntries(Object.entries(project.tables).map(([key, t]) =>
      [key, { name: t.name, item: t.item, path: t.path, fieldsPath: t.fieldsPath, newRecordPath: t.path + '/records/new' }]));
    const projectState = (project) => ({
      name: project.name, path: project.path, tablesPath: project.tablesPath, settingsPath: project.path + '/settings',
      sharingPath: project.path + '/sharing', importPath: project.path + '/import', exportPath: project.path + '/export',
      trashPath: project.path + '/trash', searchPath: project.path + '/search', tables: tables(project), collections: project.collections,
    });
    const state = {
      base,
      admin: { ...ADMIN, password: secrets.admin },
      member: { ...MEMBER, password: secrets.member, path: memberPath },
      paths: { signIn: '/sign-in', projects: '/projects', people: '/admin/people', plugins: '/admin/plugins', settings: '/settings', changePassword: '/account/password' },
      projects: {
        homeInventory: { ...projectState(inventory), sharedWith: [MEMBER.username], importReportPath: inventory.importReportPath },
        recipeBox: { ...projectState(recipeBox), sharedWith: [] },
      },
      records: {
        withAttachments: { title: 'Stand mixer', path: records['Stand mixer'], note: 'Receipt (text file), Photo (image), Room link, one linked warranty' },
        withPdf: { title: 'Espresso machine', path: records['Espresso machine'], note: 'Receipt is a PDF' },
        withLinks: { title: 'Kitchen', path: records.Kitchen, note: 'room linked from several items' },
        warranty: { title: 'Stand mixer, manufacturer warranty', path: warranties['Stand mixer, manufacturer warranty'], note: 'linked to one item' },
        imported: { title: 'Television, 43 inch', path: imported, note: 'came from items.csv' },
        trashed: TRASHED.map((title) => ({ title, path: trashed[title] })),
      },
      files: Object.fromEntries(Object.entries(files).map(([key, file]) => [key, path.basename(file)])),
      counts: { rooms: ROOMS.length, items: ITEMS.length - TRASHED.length, itemsInTrash: TRASHED.length, warranties: WARRANTIES.length, recipes: RECIPES.length },
    };
    fs.writeFileSync(path.join(out, 'state.json'), JSON.stringify(state, null, 2) + '\n', { mode: 0o600 });
    console.log('Seeded. State written to ' + path.join(out, 'state.json'));
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  const args = parseArgs(process.argv.slice(2));
  if (args === null) {
    console.error('Usage: node tools/docs/seed-demo.js --base <url> --app <app root> --data <data folder> --out <folder for state.json>');
    process.exit(2);
  }
  seed(args).catch((error) => {
    if (error.message === 'ALREADY_INSTALLED') {
      console.error(`seed-demo: ${args.base} is already installed. This script only seeds a fresh instance: empty its data folder first.`);
    } else {
      console.error(`seed-demo: failed at step "${currentStep}": ${String(error.message).split('\n')[0]}`);
    }
    process.exit(1);
  });
}

module.exports = { signIn };
