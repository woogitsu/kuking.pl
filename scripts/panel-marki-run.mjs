/* #581: osobna baza, dwie fazy i prawdziwe logowanie z TOTP.
 * Sesje i poświadczenia nie trafiają do katalogu dowodów ani stdout.
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { createHmac } from 'node:crypto';
import { mkdtempSync, chmodSync, readFileSync, writeFileSync, rmSync, realpathSync, mkdirSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, relative, isAbsolute } from 'node:path';
import { sprawdzPanelMarki, sprawdzKompletnoscPaneluMarki, sprawdzDodatkoweStanyMenu } from './panel-marki.mjs';
import { sprawdzZoomMenu } from './panel-menu-zoom.mjs';
import { sprawdzNegatywyMenu } from './panel-menu-negative.mjs';
import { sprawdzDetailsPanelu } from './panel-details.mjs';
import { sprawdzZoomDetails } from './panel-details-zoom.mjs';
import { sprawdzNegatywyDetails } from './panel-details-negative.mjs';

const repo = realpathSync(process.cwd());
const ci = process.env.GITHUB_ACTIONS === 'true';
const tylkoMenu = process.argv.includes('--menu-only');
const negatywyDetails = process.argv.includes('--negative-details');
if (negatywyDetails && (ci || tylkoMenu)) throw new Error('P581_NEGATYW_DETAILS_ZAKRES');
if (tylkoMenu && ci) throw new Error('P581_RUN_CI_PELNY_ZAKRES');
if (!['local', 'testing'].includes(process.env.APP_ENV) || process.env.DB_HOST !== '127.0.0.1'
  || !/^\d+$/.test(process.env.DB_PORT || '') || process.env.MAIL_MAILER !== 'array'
  || (ci ? process.env.DB_DATABASE !== 'kuking_port_panel' : !['kuking_581_browser', 'kuking_581_acceptance', 'kuking_581_menu', 'kuking_581_menu_extra', 'kuking_581_details'].includes(process.env.DB_DATABASE) || process.env.DB_PORT !== '55439')) {
  throw new Error('P581_RUN_IZOLACJA: wymagane lokalne środowisko, wydzielona baza, jawny port i mailer array.');
}
process.umask(0o077);
const outputDir = resolve(repo, tylkoMenu ? 'storage/port-panelu-menu' : 'storage/port-panelu');
mkdirSync(outputDir, { recursive: true });
if (readdirSync(outputDir).length !== 0) {
  throw new Error('P581_RUN_STARE_DOWODY: storage/port-panelu nie jest pusty. Zachowaj wcześniejsze dowody w innym katalogu przed ponownym uruchomieniem.');
}
const prywatne = mkdtempSync(resolve(tmpdir(), 'kuking-panel581-'));
chmodSync(prywatne, 0o700);
const rel = relative(repo, prywatne);
if (!rel.startsWith('..') && !isAbsolute(rel)) throw new Error('P581_RUN_STAN_W_REPO');
const statePath = resolve(prywatne, 'fixture.json');
const php = process.env.PHP_BINARY || 'php';
const env = { ...process.env, APP_BASE_PATH: repo };
let server, browser;
const stop = () => { if (server?.pid && server.exitCode === null) { try { process.kill(-server.pid, 'SIGTERM'); } catch {} } };
const signal = () => { stop(); process.exitCode = 1; void browser?.close(); };
process.on('SIGTERM', signal); process.on('SIGINT', signal);

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret.replace(/=+$/, '').toUpperCase()) {
    const n = alphabet.indexOf(c); if (n < 0) throw new Error('P581_TOTP_FORMAT');
    bits += n.toString(2).padStart(5, '0');
  }
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', key).update(counter).digest();
  return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

function fixture(phase) {
  try { execFileSync(php, ['scripts/fixtures/panel-marki.php', phase, statePath], { cwd: repo, env, stdio: 'pipe' }); }
  catch { throw new Error(`P581_FIXTURE_${phase}: przerwana; szczegóły poświadczeń nie są publikowane.`); }
  chmodSync(statePath, 0o600);
  return JSON.parse(readFileSync(statePath, 'utf8'));
}

try {
  const socket = createServer();
  await new Promise((ok, fail) => { socket.once('error', fail); socket.listen(0, '127.0.0.1', ok); });
  const port = socket.address().port;
  await new Promise(ok => socket.close(ok));
  const adres = `http://127.0.0.1:${port}`;
  env.APP_URL = adres;
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: repo, env, detached: true, stdio: 'ignore' });
  let serverError = false;
  server.on('error', () => { serverError = true; });
  let ready = false;
  for (let i = 0; i < 100; i++) {
    if (serverError || server.exitCode !== null) throw new Error('P581_SERWER_START');
    try { const r = await fetch(`${adres}/login`, { signal: AbortSignal.timeout(1000) }); ready = r.status === 200; } catch {}
    if (ready) break;
    await new Promise(ok => setTimeout(ok, 100));
  }
  if (!ready) throw new Error('P581_SERWER_TIMEOUT');
  const empty = fixture('pusty');
  browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  const sesje = {};
  for (const alias of ['konto', 'bramka']) {
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await page.goto(`${adres}/login`);
      await page.locator('[name=login]').fill(empty[alias].email);
      await page.locator('[name=password]').fill(empty[alias].password);
      await page.locator('form.panel-formularza button[type=submit]').click();
      await page.waitForURL(u => u.pathname !== '/login');
      if (alias === 'konto') {
        if (!new URL(page.url()).pathname.includes('/logowanie/kod')) throw new Error('P581_BRAK_WYZWANIA_2FA');
        // Nie wysyłamy kodu na granicy jego ważności.
        if (Date.now() % 30000 > 27000) await new Promise(ok => setTimeout(ok, 30000 - Date.now() % 30000 + 50));
        await page.locator('[name=code]').fill(totp(empty.konto.totpSecret));
        await page.locator('form.panel-formularza button[type=submit]').click();
        await page.waitForURL(u => !u.pathname.includes('logowanie'));
      }
      const response = await page.goto(`${adres}/admin/zgloszenia`);
      if (response.status() !== (alias === 'konto' ? 200 : 403)) throw new Error('P581_LOGOWANIE');
      if (await page.locator('[data-wyglad-pomin]').isVisible()) await page.locator('[data-wyglad-pomin]').click();
      sesje[alias] = resolve(prywatne, `${alias}-session.json`);
      await context.storageState({ path: sesje[alias] }); chmodSync(sesje[alias], 0o600);
    } finally { await context.close(); }
  }
  const zmierz = f => sprawdzPanelMarki({ browser, adres, phase: f.phase, outputDir, scenariusze: f.scenariusze.map(s => {
    if (!sesje[s.sesja]) throw new Error('P581_ALIAS_SESJI');
    return { ...s, sesja: sesje[s.sesja] };
  }) });
  let agregat;
  if (!tylkoMenu) {
    const pusty = await zmierz(empty);
    const pelnaFixture = fixture('pelny');
    const pelny = await zmierz(pelnaFixture);
    agregat = sprawdzKompletnoscPaneluMarki({ pusty, pelny });
    await sprawdzDetailsPanelu({ browser, adres, sesja: sesje.konto, fixture: pelnaFixture, outputDir: resolve(outputDir, 'details') });
    await sprawdzZoomDetails({ chromium, adres, sesja: sesje.konto, fixture: pelnaFixture, outputDir: resolve(outputDir, 'details-zoom'), executablePath: process.env.CHROMIUM_PATH });
    if (negatywyDetails) await sprawdzNegatywyDetails({ browser, adres, sesja: sesje.konto, fixture: pelnaFixture, outputDir: resolve(outputDir, 'details-negative') });
  }
  const menu = await sprawdzDodatkoweStanyMenu({ browser, adres, sesja: sesje.konto, outputDir });
  writeFileSync(resolve(outputDir, 'menu-dodatkowe.json'), JSON.stringify(menu, null, 2));
  await sprawdzZoomMenu({ chromium, adres, sesja: sesje.konto, outputDir });
  if (process.argv.includes('--negative-menu')) await sprawdzNegatywyMenu({ browser, adres, sesja: sesje.konto, outputDir });
  if (agregat) {
    writeFileSync(resolve(outputDir, 'agregat.json'), JSON.stringify(agregat, null, 2));
    console.log(`P581 PASS: pusty ${agregat.pusty}, pelny ${agregat.pelny}, razem ${agregat.razem}. Dodatkowo menu i jego zoom 200%; bez kontroli ujemnych w tym jobie.`);
  } else {
    console.log(`P581 MENU PASS: ${menu.length} dodatkowych scenariuszy. Nie jest to pełny odbiór panelu.`);
  }
} catch (error) {
  // Błąd Playwright może zawierać tekst wpisywanych poświadczeń.
  console.error(/^P581_[A-Z_]+(?::|$)/.test(error.message) ? error.message : 'P581_RUN_FAIL: sprawdź bezpieczny raport miernika, logowanie lub start serwera.');
  process.exitCode = 1;
} finally {
  try { await browser?.close(); } finally { stop(); rmSync(prywatne, { recursive: true, force: true }); }
  process.off('SIGTERM', signal); process.off('SIGINT', signal);
}
