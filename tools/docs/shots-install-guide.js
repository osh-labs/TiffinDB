// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Takes the screenshots of the install guide (docs/manual/install-guide.html,
// ADR 0020) with Playwright. Every file it writes is named install-*.png.
//
// Usage:
//   node tools/docs/shots-install-guide.js --out docs/manual/img \
//     --app <runnable app copy> --work <scratch folder> --port 8094 --port2 8096 \
//     --base http://127.0.0.1:8091 --state <seed folder>/state.json
//
// Two groups of screenshots, each run only when its arguments are given:
//
// 1. Installer and host-check screenshots (--app, --work, --port, --port2).
//    --app is a runnable copy of the application with vendor/ (any
//    config/local.php and data/ in it are not copied). --work must be an
//    empty or new folder outside the repository. The script makes its own
//    copies of the application in it, starts PHP built-in servers on the two
//    ports (php must be on PATH), and stops them at the end:
//      - work/fresh/tiffindb: a fresh, un-installed copy with no
//        config/local.php, so the installer makes work/fresh/tiffindb-data.
//        First served with PHP started without its ini files and with zip,
//        xmlreader, and dom left out (the requirement failure), then
//        normally (steps 1 and 3, the first Admin, the first sign-in).
//      - work/probe/tiffindb: a copy whose data_dir is its own data/ folder,
//        served with the whole application folder as document root through
//        a small router (written to work/probe/router-probe.php) that stands
//        in for a server that ignores .htaccess files. Without a server
//        address the probe can only give "inconclusive"; with one (the
//        router sets it when PROBE_SET_ADDR=1) the data folder is served
//        and the probe gives "exposed".
//      - work/hc/www/host-check.php: tools/host-check/host-check.php with a
//        random token set for this run only, served over HTTP.
//    The first Admin's password is random and kept in memory only. The setup
//    code is read from setup-code.txt and masked on the page before any
//    screenshot that would show it.
//
// 2. Screenshots of an installed instance (--base, --state): a copy of the
//    seeded demo data (tools/docs/seed-demo.js) served at --base. Navigation
//    comes from state.json; nothing is changed in that instance.
//
// The only network use is the local servers named above.
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('playwright');
const { signIn } = require('./seed-demo.js');

const VIEW = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1.5, colorScheme: 'light' };
const MASK = '••••-••••-••••-••••';
const ADMIN = { name: 'Example Admin', username: 'admin' };
// Extensions loaded for the requirement-failure screenshot (PHP started with -n).
// zip, xmlreader, and dom are left out on purpose.
const FAILING_EXTENSIONS = ['pdo', 'pdo_sqlite', 'mbstring', 'ctype', 'iconv', 'fileinfo', 'intl'];

const ROUTER = `<?php
// Written by tools/docs/shots-install-guide.js for one screenshot run.
// Stands in for a web server whose document root is the whole TiffinDB
// folder and which ignores .htaccess files: probe files under data/ are
// served as static files, and public/ files are served as they would be
// after the rewrite in the root .htaccess. SERVER_ADDR, which the built-in
// server leaves out, is set only when PROBE_SET_ADDR=1.
if (getenv('PROBE_SET_ADDR') === '1') {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/data/probe-[0-9a-f]{16}\\.txt$#', $path) === 1 && is_file(__DIR__ . '/tiffindb' . $path)) {
    return false;
}
$types = ['txt' => 'text/plain', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'png' => 'image/png'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (isset($types[$ext]) && !str_contains($path, '..') && is_file(__DIR__ . '/tiffindb/public' . $path)) {
    header('Content-Type: ' . $types[$ext]);
    readfile(__DIR__ . '/tiffindb/public' . $path);
    return true;
}
return require __DIR__ . '/tiffindb/public/index.php';
`;

/* ---------- arguments and helpers ---------- */

function parseArgs(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i += 2) {
    const key = argv[i];
    const value = argv[i + 1];
    if (!key || !key.startsWith('--') || value === undefined) {
      return null;
    }
    args[key.slice(2)] = value;
  }
  return args.out ? args : null;
}

const route = (base, to) => base.replace(/\/+$/, '') + to;
const label = (page, text) => page.getByLabel(text, { exact: true });
const press = (page, name) => page.getByRole('button', { name, exact: true }).click();
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function shotPath(args, name) {
  return path.join(args.out, 'install-' + name + '.png');
}

function log(name) {
  console.log('- install-' + name + '.png');
}

/** Copies the application without its local configuration, data, or probe leftovers. */
function copyApp(from, to) {
  fs.mkdirSync(path.dirname(to), { recursive: true });
  fs.cpSync(from, to, {
    recursive: true,
    filter: (src) => {
      const rel = path.relative(from, src);
      return rel !== path.join('config', 'local.php') && rel !== 'data' && !/^public[\\/]probe-.*\.txt$/.test(rel);
    },
  });
}

/** Starts `php -S` and waits until the port answers. */
async function startServer(args, { port, cwd, docroot, router, env = {}, phpArgs = [] }) {
  const child = spawn('php', [...phpArgs, '-S', '127.0.0.1:' + port, '-t', docroot, ...(router ? [router] : [])], {
    cwd,
    env: { ...process.env, ...env },
    stdio: ['ignore', 'ignore', 'ignore'],
  });
  const url = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 50; i++) {
    try {
      await fetch(url + '/', { redirect: 'manual' });
      return { child, url };
    } catch {
      await sleep(100);
    }
  }
  child.kill();
  throw new Error('the server on port ' + port + ' did not start');
}

async function stopServer(server) {
  if (server && server.child.exitCode === null) {
    const gone = new Promise((resolve) => server.child.once('exit', resolve));
    server.child.kill();
    await gone;
  }
}

/**
 * Numbered badges (teal circle, white digit, white ring) just left of each
 * target, centred on it vertically, for the annotated screenshots.
 */
async function badges(page, marks) {
  await page.evaluate((list) => {
    for (const { selector, n, dx = 0, dy = 0 } of list) {
      const target = document.querySelector(selector);
      if (!target) {
        throw new Error('badge target missing: ' + selector);
      }
      const box = target.getBoundingClientRect();
      const badge = document.createElement('div');
      badge.textContent = String(n);
      Object.assign(badge.style, {
        position: 'absolute', zIndex: '1000',
        left: (box.left + window.scrollX - 38 + dx) + 'px', top: (box.top + window.scrollY + box.height / 2 - 14 + dy) + 'px',
        width: '28px', height: '28px', borderRadius: '50%', background: '#084F4A', color: '#FFFFFF',
        font: '700 15px/28px system-ui, sans-serif', textAlign: 'center', boxShadow: '0 0 0 2px #FFFFFF',
      });
      document.body.appendChild(badge);
    }
  }, marks);
}

/** A random code in the setup-code format, unrelated to the real one and never equal to it. */
function randomCode(real) {
  const alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
  for (;;) {
    const chars = Array.from(crypto.randomBytes(16), (byte) => alphabet[byte % alphabet.length]).join('');
    const candidate = chars.match(/.{4}/g).join('-');
    if (candidate !== real) {
      return candidate;
    }
  }
}

/** True when the folder (or its nearest existing parent) lies inside a git work tree. */
function insideGitWorkTree(folder) {
  let probe = folder;
  while (!fs.existsSync(probe) && path.dirname(probe) !== probe) {
    probe = path.dirname(probe);
  }
  const result = spawnSync('git', ['rev-parse', '--show-toplevel'], { cwd: probe, stdio: ['ignore', 'pipe', 'ignore'] });
  return result.status === 0;
}

/** Replaces the typed setup code with bullets, so no image can show it. */
async function maskSetupCode(page) {
  await page.evaluate((mask) => {
    const field = document.getElementById('setup-code');
    if (field) {
      field.value = mask;
    }
  }, MASK);
  const shown = await page.locator('#setup-code').inputValue();
  if (shown !== MASK) {
    throw new Error('the setup code could not be masked');
  }
}

/** Fails when the page text holds the setup code (a last check before any capture). */
async function assertNoCode(page, code) {
  const html = await page.content();
  const bare = code.replace(/-/g, '');
  if (html.includes(code) || html.includes(bare)) {
    throw new Error('the setup code is on the page; nothing captured');
  }
}

/* ---------- group 1: installer and host check ---------- */

async function failingCheck(browser, args, work) {
  const app = path.join(work, 'fresh', 'tiffindb');
  const phpArgs = ['-n', ...FAILING_EXTENSIONS.flatMap((name) => ['-d', 'extension=' + name])];
  const server = await startServer(args, { port: args.port2, cwd: app, docroot: 'public', router: 'public/index.php', phpArgs });
  try {
    const page = await (await browser.newContext(VIEW)).newPage();
    await page.goto(route(server.url, '/setup'));
    await page.getByRole('link', { name: 'Check again', exact: true }).waitFor();
    await badges(page, [
      { selector: 'tbody tr:nth-child(3) th', n: 1 },
      { selector: 'tbody tr:nth-child(5) th', n: 2 },
      { selector: 'main form button, main .button', n: 3 },
    ]);
    await page.screenshot({ path: shotPath(args, 'setup-check-failed'), fullPage: true });
    log('setup-check-failed');
    await page.context().close();
  } finally {
    await stopServer(server);
  }
}

async function installerSteps(browser, args, work) {
  const app = path.join(work, 'fresh', 'tiffindb');
  const codeFile = path.join(work, 'fresh', 'tiffindb-data', 'setup-code.txt');
  const server = await startServer(args, { port: args.port, cwd: app, docroot: 'public', router: 'public/index.php' });
  try {
    const context = await browser.newContext(VIEW);
    const page = await context.newPage();

    // Step 1: the requirement check, annotated.
    await page.goto(route(server.url, '/'));
    await page.waitForURL((url) => url.pathname === '/setup');
    await page.getByRole('button', { name: 'Make the data folder', exact: true }).waitFor();
    await badges(page, [
      { selector: 'ol.steps', n: 1 },
      { selector: 'tbody tr:nth-child(10) th', n: 2 },
      { selector: 'tbody tr:nth-child(11) th', n: 3 },
      { selector: 'main form button', n: 4 },
    ]);
    await page.screenshot({ path: shotPath(args, 'setup-check'), fullPage: true });
    log('setup-check');

    // Step 2 runs without a page of its own when the data folder is outside
    // the document root; step 3 follows at once.
    await page.reload();
    await press(page, 'Make the data folder');
    await label(page, 'Setup code').waitFor();
    const code = fs.readFileSync(codeFile, 'utf8').trim();
    await assertNoCode(page, code);
    await badges(page, [
      { selector: 'main .notice, main [role="status"]', n: 1 },
      { selector: '#setup-code', n: 2 },
      { selector: '#setup-name', n: 3 },
      { selector: 'main form button[type="submit"]', n: 4 },
    ]);
    await page.screenshot({ path: shotPath(args, 'setup-admin'), fullPage: true });
    log('setup-admin');

    // A wrong setup code: the form comes back with an error summary. The
    // wrong value is a random code in the same format, never the real one.
    const password = 'Doc-' + crypto.randomBytes(15).toString('base64url');
    await page.reload();
    const wrong = randomCode(code);
    await label(page, 'Setup code').fill(wrong);
    await label(page, 'Your name').fill(ADMIN.name);
    await label(page, 'Username').fill(ADMIN.username);
    await label(page, 'Password').fill(password);
    await label(page, 'Type the password again').fill(password);
    await press(page, 'Create Admin and finish');
    await page.getByText('That setup code is not right.').first().waitFor();
    await assertNoCode(page, code);
    await assertNoCode(page, wrong);
    const codeField = await page.locator('#setup-code').boundingBox();
    const scroll = await page.evaluate(() => window.scrollY);
    await page.screenshot({ path: shotPath(args, 'setup-admin-error'), fullPage: true, clip: { x: 0, y: 0, width: 1280, height: Math.ceil(codeField.y + scroll + codeField.height + 32) } });
    log('setup-admin-error');

    // A clean form with the real code, masked before the screenshot.
    await page.goto(route(server.url, '/setup'));
    await label(page, 'Setup code').fill(code);
    await label(page, 'Your name').fill(ADMIN.name);
    await label(page, 'Username').fill(ADMIN.username);
    await label(page, 'Password').fill(password);
    await label(page, 'Type the password again').fill(password);
    await page.locator('#setup-code').blur();
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    const typed = await page.locator('#setup-code').inputValue();
    await maskSetupCode(page);
    await assertNoCode(page, code);
    await page.locator('main form').screenshot({ path: shotPath(args, 'setup-admin-filled') });
    log('setup-admin-filled');
    await label(page, 'Setup code').fill(typed);
    await press(page, 'Create Admin and finish');
    await page.waitForURL((url) => url.pathname === '/projects');
    await page.locator('.notice[role="status"]').filter({ hasText: 'TiffinDB is ready.' }).first().waitFor();
    await page.screenshot({ path: shotPath(args, 'setup-done'), fullPage: true });
    log('setup-done');
    if (fs.existsSync(codeFile)) {
      throw new Error('setup-code.txt is still there after setup');
    }

    // Sign out, then the sign-in page that every later visit starts from.
    await press(page, 'Sign out').catch(async () => {
      await page.getByRole('button', { name: /Example Admin|Account|Menu/ }).first().click();
      await press(page, 'Sign out');
    });
    await page.waitForURL((url) => url.pathname === '/sign-in');
    await label(page, 'Username').fill(ADMIN.username);
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.screenshot({ path: shotPath(args, 'sign-in'), fullPage: true });
    log('sign-in');
    await context.close();
  } finally {
    await stopServer(server);
  }
}

async function probeOutcome(browser, args, work, name, exposed) {
  const probe = path.join(work, 'probe');
  const app = path.join(probe, 'tiffindb');
  fs.rmSync(path.join(app, 'data'), { recursive: true, force: true });
  const server = await startServer(args, {
    port: args.port2, cwd: app, docroot: '.', router: path.join(probe, 'router-probe.php'),
    env: { PHP_CLI_SERVER_WORKERS: '3', PROBE_SET_ADDR: exposed ? '1' : '0' },
  });
  try {
    const page = await (await browser.newContext(VIEW)).newPage();
    await page.goto(route(server.url, '/setup'));
    await press(page, 'Make the data folder');
    await page.getByRole('button', { name: 'Test again', exact: true }).waitFor();
    const heading = exposed ? 'Your data folder can be opened from the web' : 'TiffinDB could not test the data folder';
    await page.getByText(heading).first().waitFor();
    await page.screenshot({ path: shotPath(args, name), fullPage: true });
    log(name);
    await page.context().close();
  } finally {
    await stopServer(server);
  }
}

async function hostCheck(browser, args, work) {
  const root = path.join(work, 'hc', 'www');
  fs.mkdirSync(root, { recursive: true });
  const token = crypto.randomBytes(18).toString('hex');
  const source = fs.readFileSync(path.join(args.app, 'tools', 'host-check', 'host-check.php'), 'utf8');
  const withToken = source.replace("const HOST_CHECK_TOKEN = '';", "const HOST_CHECK_TOKEN = '" + token + "';");
  if (withToken === source) {
    throw new Error('the token line was not found in host-check.php');
  }
  fs.writeFileSync(path.join(root, 'host-check.php'), withToken);
  const server = await startServer(args, {
    port: args.port2, cwd: root, docroot: '.', router: null, env: { PHP_CLI_SERVER_WORKERS: '2' },
  });
  try {
    const page = await (await browser.newContext(VIEW)).newPage();
    await page.goto(route(server.url, '/host-check.php?token=' + token));
    await page.getByText('Result:').first().waitFor();
    // Only the section that the built-in server cannot pass, and the summary.
    await page.evaluate(() => {
      // The report is plain text: keep the PHP section and from "== Web server ==" to the result line.
      const text = document.body.innerText;
      const lines = text.split('\n');
      const keep = [];
      let mode = 'head';
      for (const line of lines) {
        if (line.startsWith('== Extensions ==')) { mode = 'skip'; keep.push('[...]', ''); }
        if (line.startsWith('== Web server ==')) { mode = 'keep'; }
        if (line.startsWith('----- BEGIN')) { break; }
        if (mode !== 'skip') { keep.push(line); }
      }
      document.body.innerHTML = '';
      const pre = document.createElement('pre');
      pre.textContent = keep.join('\n').trimEnd();
      Object.assign(pre.style, { margin: '0', padding: '20px 24px', font: '13px/1.45 ui-monospace, monospace', color: '#111A18' });
      document.body.style.margin = '0';
      document.body.style.background = '#FFFFFF';
      document.body.appendChild(pre);
    });
    const box = await page.locator('pre').boundingBox();
    await page.screenshot({ path: shotPath(args, 'host-check'), clip: { x: 0, y: 0, width: Math.min(1280, Math.ceil(box.width)), height: Math.ceil(box.height) } });
    log('host-check');
    await page.context().close();
  } finally {
    await stopServer(server);
    fs.rmSync(path.join(root, 'host-check.php'), { force: true });
  }
}

async function installerGroup(browser, args) {
  const work = path.resolve(args.work);
  const repo = path.resolve(__dirname, '..', '..');
  if (work === repo || work.startsWith(repo + path.sep) || insideGitWorkTree(work)) {
    throw new Error('--work must be outside the repository and outside any git work tree');
  }
  const out = path.resolve(args.out);
  if (out === work || out.startsWith(work + path.sep)) {
    throw new Error('--out must be outside --work');
  }
  if (fs.existsSync(work) && fs.readdirSync(work).length > 0) {
    throw new Error('--work must be an empty or new folder');
  }
  copyApp(path.resolve(args.app), path.join(work, 'fresh', 'tiffindb'));
  copyApp(path.resolve(args.app), path.join(work, 'probe', 'tiffindb'));
  const probeData = path.join(work, 'probe', 'tiffindb', 'data');
  fs.writeFileSync(path.join(work, 'probe', 'tiffindb', 'config', 'local.php'), "<?php return ['data_dir' => " + JSON.stringify(probeData).replace(/"/g, "'") + '];\n');
  fs.writeFileSync(path.join(work, 'probe', 'router-probe.php'), ROUTER);

  await failingCheck(browser, args, work);
  await installerSteps(browser, args, work);
  await probeOutcome(browser, args, work, 'setup-inconclusive', false);
  await probeOutcome(browser, args, work, 'setup-exposed', true);
  await hostCheck(browser, args, work);
}

/* ---------- group 2: an installed instance ---------- */

async function installedGroup(browser, args) {
  const state = JSON.parse(fs.readFileSync(args.state, 'utf8'));
  const base = args.base;
  const inventory = state.projects.homeInventory;
  const context = await browser.newContext(VIEW);
  const page = await context.newPage();
  await signIn(page, base, state.admin.username, state.admin.password);

  // Backups: the project archive on the Export page.
  await page.goto(route(base, inventory.exportPath));
  const archive = page.getByRole('heading', { name: 'The whole project, for TiffinDB' });
  await archive.waitFor();
  const section = archive.locator('xpath=ancestor::section[1]');
  await section.screenshot({ path: shotPath(args, 'export-archive') });
  log('export-archive');

  // An Admin sets a temporary password for another person.
  await page.goto(route(base, state.member.path));
  const pw = page.locator('fieldset', { has: page.locator('legend', { hasText: /^Password$/ }) }).first();
  await pw.waitFor();
  // The form suggests a random temporary password: blank it, so no image shows one.
  await pw.locator('input[type="text"], input[type="password"]').evaluateAll((inputs) => inputs.forEach((input) => { input.value = ''; }));
  await pw.locator('xpath=ancestor::form[1]').screenshot({ path: shotPath(args, 'person-password') });
  log('person-password');

  await context.close();
}

/* ---------- main ---------- */

async function main(args) {
  fs.mkdirSync(args.out, { recursive: true });
  const browser = await chromium.launch();
  try {
    if (args.app && args.work && args.port && args.port2) {
      await installerGroup(browser, args);
    }
    if (args.base && args.state) {
      await installedGroup(browser, args);
    }
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  const args = parseArgs(process.argv.slice(2));
  if (args === null) {
    console.error('Usage: node tools/docs/shots-install-guide.js --out <img folder> [--app <app copy> --work <scratch folder> --port <n> --port2 <n>] [--base <url> --state <state.json>]');
    process.exit(2);
  }
  main(args).then(() => console.log('Done.')).catch((error) => {
    console.error('shots-install-guide: ' + String(error.message).split('\n')[0]);
    process.exit(1);
  });
}
