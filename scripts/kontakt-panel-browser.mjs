// Rzeczywiste formularze, logowanie i 2FA; poczta wyłącznie array.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';

assert.equal(process.env.DB_DATABASE, 'kuking_flota_gpt-kontakt-panel');
assert.equal(process.env.DB_PORT, '55439');
assert.equal(process.env.MAIL_MAILER, 'array');
assert.equal(process.env.APP_ENV, 'testing');
const dir = mkdtempSync(join(tmpdir(), 'kuking-kontakt-'));
const statePath = join(dir, 'fixture.json');
const php = process.env.PHP_BINARY || 'php';
let browser, server, page, zoomContext, fixtureReady = false;
let step = 'start';
const env = { ...process.env, APP_BASE_PATH: process.cwd(), SESSION_DRIVER: 'file', CACHE_STORE: 'array', TURNSTILE_SITE_KEY: '', TURNSTILE_SECRET_KEY: '', LOG_CHANNEL: 'null' };
function fixture(action) { execFileSync(php, ['scripts/fixtures/kontakt-panel.php', action, statePath], { env, stdio: 'pipe' }); }
function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map(c => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', key).update(counter).digest();
  return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
try {
  fixture('create'); fixtureReady = true;
  const state = JSON.parse(readFileSync(statePath, 'utf8'));
  const socket = createServer();
  await new Promise(ok => socket.listen(0, '127.0.0.1', ok));
  const port = socket.address().port;
  await new Promise(ok => socket.close(ok));
  const base = `http://127.0.0.1:${port}`;
  env.APP_URL = base;
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', '.', join(process.cwd(), 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: join(process.cwd(), 'public'), env, stdio: 'ignore' });
  for (let i = 0; i < 80; i++) {
    try { if ((await fetch(`${base}/login`)).status === 200) break; } catch {}
    await new Promise(ok => setTimeout(ok, 100));
  }
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  page = await context.newPage();
  step = 'logowanie';
  await page.goto(`${base}/login`);
  await page.locator('[name=login]').fill(state.email);
  await page.locator('[name=password]').fill(state.password);
  await page.locator('form.panel-formularza button[type=submit]').click();
  step = 'przejscie-do-2fa';
  await page.waitForURL(u => u.pathname.includes('/logowanie/kod'));
  step = 'kod-2fa';
  if (Date.now() % 30000 > 27000) await new Promise(ok => setTimeout(ok, 3100));
  await page.locator('[name=code]').fill(totp(state.secret));
  await page.locator('form.panel-formularza button[type=submit]').click();
  await page.waitForURL(u => !u.pathname.includes('logowanie'));
  const url = `${base}/admin/wiadomosci/${state.message}`;
  step = 'odpowiedz-przy-zapisie';
  assert.equal((await page.goto(url)).status(), 200);
  await page.locator('[name=odpowiedz]').fill('Szkic odpowiedzi 845.');
  await page.locator('[name=status][value=in_progress]').check();
  await page.getByRole('button', { name: 'Zapisz', exact: true }).click();
  await page.waitForLoadState();
  assert.equal(await page.locator('[name=odpowiedz]').inputValue(), 'Szkic odpowiedzi 845.');
  assert.equal(await page.locator('article.wizard-row').count(), 0);
  step = 'notatka-przy-wysylce';
  const firstKey = await page.locator('input[name=reply_key]').inputValue();
  await page.locator('[name=handler_note]').fill('Szkic notatki 845.');
  await page.getByRole('button', { name: 'Wyślij odpowiedź', exact: true }).click();
  await page.waitForLoadState();
  assert.equal(await page.locator('[name=handler_note]').inputValue(), 'Szkic notatki 845.');
  assert.equal(await page.locator('[name=odpowiedz]').inputValue(), '');
  assert.equal(await page.locator('article.wizard-row').count(), 1);
  step = 'nowa-odpowiedz-po-starym-formularzu';
  // Odtworzenie starego formularza po utracie odpowiedzi HTTP.
  await page.locator('input[name=reply_key]').evaluate((el, key) => { el.value = key; }, firstKey);
  await page.locator('[name=odpowiedz]').fill('Drugi świadomy list.');
  await page.getByRole('button', { name: 'Wyślij odpowiedź', exact: true }).click();
  await page.waitForLoadState();
  assert.equal(await page.locator('article.wizard-row').count(), 1);
  await page.getByRole('button', { name: 'Wyślij jako nową odpowiedź', exact: true }).click();
  await page.waitForLoadState();
  assert.equal(await page.locator('article.wizard-row').count(), 2);
  step = 'dwie-karty';
  const stale = await context.newPage();
  await stale.goto(url);
  await page.locator('[name=handler_note]').fill('Nowsza notatka 846.');
  await page.locator('[name=status][value=done]').check();
  await page.getByRole('button', { name: 'Zapisz', exact: true }).click();
  await page.waitForLoadState();
  await stale.locator('[name=handler_note]').fill('Starsza notatka 846.');
  await stale.getByRole('button', { name: 'Zapisz', exact: true }).click();
  await stale.waitForLoadState();
  assert.ok((await stale.locator('#f-version').innerText()).includes('Nowsza notatka 846.'));
  assert.equal(await stale.locator('[name=handler_note]').inputValue(), 'Starsza notatka 846.');
  await stale.locator('[name=status][value=done]').check();
  await stale.getByRole('button', { name: 'Zapisz', exact: true }).click();
  await stale.waitForLoadState();
  assert.equal(await stale.locator('.error-summary').count(), 0);
  step = 'telefon-klawiatura';
  await stale.setViewportSize({ width: 320, height: 800 });
  await stale.locator('[name=odpowiedz]').fill('Szkic klawiaturą.');
  const save = stale.getByRole('button', { name: 'Zapisz', exact: true });
  await save.focus(); await stale.keyboard.press('Enter'); await stale.waitForLoadState();
  assert.equal(await stale.locator('[name=odpowiedz]').inputValue(), 'Szkic klawiaturą.');
  assert.ok(await stale.locator('[name=odpowiedz]').evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 18));
  assert.ok((await save.boundingBox()).height >= 48);
  assert.ok(await stale.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  mkdirSync('output/playwright', { recursive: true });
  await stale.screenshot({ path: 'output/playwright/kontakt-panel-320.png', fullPage: true });
  step = 'prawdziwy-zoom-200';
  const extension = join(dir, 'zoom-extension'); mkdirSync(extension);
  writeFileSync(join(extension, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Pomiar kontaktu', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
  writeFileSync(join(extension, 'worker.js'), 'chrome.runtime.onInstalled.addListener(() => {});');
  zoomContext = await chromium.launchPersistentContext(join(dir, 'zoom-profile'), {
    executablePath: chromium.executablePath(), headless: true, viewport: { width: 640, height: 1600 },
    args: ['--disable-extensions-except=' + extension, '--load-extension=' + extension],
  });
  await zoomContext.addCookies(await context.cookies());
  const zoomPage = await zoomContext.newPage(); await zoomPage.goto(url);
  const worker = zoomContext.serviceWorkers()[0] || await zoomContext.waitForEvent('serviceworker');
  assert.equal(await worker.evaluate(async url => {
    const tab = (await chrome.tabs.query({})).find(t => t.url === url);
    await chrome.tabs.setZoom(tab.id, 2);
    return chrome.tabs.getZoom(tab.id);
  }, url), 2);
  await zoomPage.waitForFunction(() => innerWidth === 320 && devicePixelRatio === 2);
  await zoomPage.locator('[name=odpowiedz]').fill('Szkic przy zoomie 200%.');
  await zoomPage.getByRole('button', { name: 'Zapisz', exact: true }).click(); await zoomPage.waitForLoadState();
  assert.equal(await zoomPage.locator('[name=odpowiedz]').inputValue(), 'Szkic przy zoomie 200%.');
  assert.ok(await zoomPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await zoomPage.screenshot({ path: 'output/playwright/kontakt-panel-zoom200.png', fullPage: true });
  console.log('PASS: szkice, nowy list po starym formularzu, dwie karty, klawiatura 320 px, rzeczywisty zoom 200% i brak przewijania w bok.');
} catch (error) {
  console.error(`FAIL: ${step}; ${error.constructor.name}; ${page ? new URL(page.url()).pathname : "brak strony"}`);
  if (page) console.error(await page.locator('h1, .error-summary').allTextContents());
  process.exitCode = 1;
} finally {
  await zoomContext?.close();
  await browser?.close();
  server?.kill();
  if (fixtureReady) fixture('cleanup');
  rmSync(dir, { recursive: true, force: true });
}
