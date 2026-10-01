// #2466–#2468: prawdziwe ekrany i formularze w izolowanej bazie PostgreSQL 18.
// Uruchamiać dopiero po migracjach własnej kuking_port_confirm_*; bez seedowania
// i bez pracy na produkcji. Nie używa atrap HTML ani wyłączania ochrony HTTP.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_HOST, '127.0.0.1');
assert.match(process.env.DB_DATABASE ?? '', /^kuking_port_confirm_[a-z0-9_]+$/);
assert.match(process.env.DB_PORT ?? '', /^\d+$/);
assert.notEqual(process.env.DB_PORT, '5432');
assert.equal(process.env.CONFIRM_BROWSER_DB_PORT, process.env.DB_PORT);

const php = process.env.PHP_BINARY || 'php';
const env = {
  ...process.env,
  APP_BASE_PATH: process.cwd(),
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', MAIL_MAILER: 'array',
  TURNSTILE_SITE_KEY: '', TURNSTILE_SECRET_KEY: '',
};
const tmp = mkdtempSync(join(tmpdir(), 'kuking-confirm-'));
let server;
let browser;

function fixture(action, path) {
  return execFileSync(php, ['scripts/fixtures/potwierdzenia-usuwania.php', action, path], {
    env, cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function state(path) { return JSON.parse(readFileSync(path, 'utf8')); }
function rows(path) { return JSON.parse(fixture('sprawdz', path)); }

async function availablePort() {
  const socket = createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  return port;
}

function rootWidth(page) {
  return page.evaluate(() => ({ width: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth }));
}

async function accessibleNameMatchesVisible(summary) {
  const label = await summary.getAttribute('aria-label');
  if (!label) return;
  const visible = await summary.locator('span:visible').allTextContents();
  const action = visible.map(s => s.trim()).find(Boolean) || (await summary.innerText()).trim();
  assert.ok(action && label.includes(action), `Nazwa dostępna "${label}" nie odpowiada widocznej akcji "${action}".`);
}

async function checkScreen(page, path, item, id, statePath) {
  const target = `${path}/${id}`;
  const response = await page.goto(path, { waitUntil: 'load' });
  assert.equal(response?.status(), 200, `Ekran ${item}`);
  const details = page.locator('details.confirm').filter({ has: page.locator(`form[action$="${target}"]`) });
  assert.equal(await details.count(), 1, `Pytanie przy ${item} musi dotyczyć prawdziwego DELETE ${target}.`);
  const summary = details.locator('summary');
  assert.equal(await details.getAttribute('open'), null, `${item}: pytanie ma być początkowo zamknięte.`);
  const before = rows(statePath);
  assert.ok(before[item], `${item}: brakuje rekordu przed testem.`);
  const mutations = [];
  page.on('request', request => {
    if (!['GET', 'HEAD'].includes(request.method())) mutations.push(`${request.method()} ${request.url()}`);
  });

  await summary.focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  assert.equal(await summary.evaluate(el => document.activeElement === el), true, `${item}: fokus nie trafia na pytanie.`);
  const focus = await summary.evaluate(el => ({ visible: el.matches(':focus-visible'),
    outline: getComputedStyle(el).outlineStyle }));
  assert.ok(focus.visible && focus.outline !== 'none', `${item}: brak widocznego fokusu klawiatury.`);
  await page.keyboard.press('Enter');
  assert.notEqual(await details.getAttribute('open'), null, `${item}: Enter nie otworzył pytania.`);
  await accessibleNameMatchesVisible(summary);
  assert.ok((await details.innerText()).includes(before[item].text ?? before[item].name ?? before[item].label));
  assert.deepEqual(rows(statePath), before, `${item}: samo otwarcie zmieniło dane.`);
  await summary.focus();
  await page.keyboard.press('Enter');
  assert.equal(await details.getAttribute('open'), null, `${item}: Enter nie anulował pytania.`);
  await accessibleNameMatchesVisible(summary);
  assert.deepEqual(rows(statePath), before, `${item}: anulowanie zmieniło dane.`);
  assert.deepEqual(mutations, [], `${item}: otwarcie/anulowanie wysłało zapis HTTP.`);

  const box = await summary.boundingBox();
  const font = await summary.evaluate(el => parseFloat(getComputedStyle(el).fontSize));
  assert.ok(box?.height >= 48, `${item}: cel otwarcia ma ${box?.height}px zamiast 48px.`);
  assert.ok(font >= 18, `${item}: tekst otwarcia ma ${font}px zamiast 18px.`);
  const width = await rootWidth(page);
  assert.ok(width.scroll <= width.width + 1, `${item}: poziome przewijanie ${width.scroll} > ${width.width}.`);

  await summary.click();
  const confirm = details.locator(`form[action$="${target}"] button[type="submit"]`);
  const confirmBox = await confirm.boundingBox();
  const confirmFont = await confirm.evaluate(el => parseFloat(getComputedStyle(el).fontSize));
  assert.ok(confirmBox?.height >= 48, `${item}: potwierdzenie ma mniej niż 48px.`);
  assert.ok(confirmFont >= 18, `${item}: potwierdzenie ma mniej niż 18px.`);
  assert.ok((await confirm.innerText()).includes('usuń') || (await confirm.innerText()).includes('Usuń'));
  await confirm.click();
  assert.equal(rows(statePath)[item], null, `${item}: potwierdzenie nie usunęło rekordu.`);
}

try {
  const port = await availablePort();
  const base = `http://127.0.0.1:${port}`;
  env.APP_URL = base;
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    cwd: process.cwd(), env, stdio: 'ignore', windowsHide: true,
  });
  let ready = false;
  for (let i = 0; i < 300; i++) {
    try { if ((await fetch(`${base}/login`)).status === 200) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  assert.ok(ready, 'Lokalny serwer nie uruchomił /login.');
  browser = await chromium.launch({ headless: true });

  for (const font200 of [false, true]) {
    const path = join(tmp, `state-${font200 ? '200' : '100'}.json`);
    fixture('przygotuj', path);
    const data = state(path);
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 850 } });
    const page = await context.newPage();
    if (font200) {
      const cdp = await context.newCDPSession(page);
      await cdp.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    }
    try {
      await page.goto(`${base}/login`);
      await page.locator('input[name="login"]').fill(data.email);
      await page.locator('input[name="password"]').fill(data.password);
      await page.locator('form[action$="/login"] button[type="submit"]').click();
      await page.waitForURL(url => url.pathname !== '/login');
      assert.equal(new URL(page.url()).origin, base, 'Logowanie opuściło lokalny serwer.');
      for (const item of ['zakupy', 'spizarnia', 'planer']) {
        await checkScreen(page, `${base}${data.paths[item]}`, item, data[item], path);
      }
      console.log(`PASS: prawdziwe formularze, bez JS, 320px, czcionka ${font200 ? '200%' : '100%'}.`);
    } finally {
      await context.close();
      fixture('posprzataj', path);
    }
  }
} finally {
  await browser?.close();
  server?.kill();
  rmSync(tmp, { recursive: true, force: true });
}
