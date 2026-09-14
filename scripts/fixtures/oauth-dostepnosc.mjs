import { AxeBuilder } from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, existsSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { sprawdzTab } from '../zoom-marki.mjs';

export const EKRANY_OAUTH = [
  { nazwa: 'Google — domknięcie', adres: '/wejdz/google/domknij' },
  { nazwa: 'Google — połączenie', adres: '/wejdz/google/polacz' },
  { nazwa: 'Facebook — domknięcie', adres: '/wejdz/facebook/domknij' },
  { nazwa: 'Facebook — połączenie', adres: '/wejdz/facebook/polacz' },
  { nazwa: 'Facebook — bez adresu', adres: '/wejdz/facebook/wroc', stan: 'bez-adresu' },
];

export const WARIANTY_OAUTH = [
  { wariant: 'jasny', dark: false, width: 320, tekst: 140 },
  { wariant: 'ciemny', dark: true, width: 320, tekst: 140 },
];

export async function zmierzOauth({ przegladarka, wyniki }) {
  if (process.env.APP_ENV && !['local', 'testing'].includes(process.env.APP_ENV)) throw Error('OAUTH345_SRODOWISKO');
  const katalog = mkdtempSync(tmpdir() + '/kuking-oauth345-');
  const snapshot = katalog + '/stan.json';
  const sesje = katalog + '/sesje';
  mkdirSync(sesje);
  const out = 'storage/dostepnosc/oauth345';
  mkdirSync(out, { recursive: true });
  const env = { ...process.env, APP_ENV: 'local', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync',
    OAUTH345_STATE: snapshot, OAUTH345_SESSIONS: sesje };
  const fixture = (mode) => execFileSync('php', ['scripts/fixtures/oauth-stan.php', mode, snapshot], { env, stdio: 'pipe' });
  let serwer;
  let context;
  const rekordy = [];
  const start = Date.now();
  try {
    fixture('utworz');
    const state = JSON.parse(readFileSync(snapshot, 'utf8'));
    const socket = createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const adres = `http://127.0.0.1:${port}`;
    env.APP_URL = adres;
    serwer = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'scripts/fixtures/oauth-router.php'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let serverLog = '';
    serwer.stdout.on('data', b => { serverLog += b; });
    serwer.stderr.on('data', b => { serverLog += b; });
    let ready = false;
    for (let i = 0; i < 100; i++) {
      try { const r = await fetch(adres + '/login'); if (r.status === 200) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!ready) throw Error('OAUTH345_SERWER ' + serverLog.slice(-2000));
    for (const ekran of EKRANY_OAUTH) {
      for (const { dark, wariant } of WARIANTY_OAUTH) {
        const rekord = { nazwa: ekran.nazwa, adres: ekran.adres, stan: ekran.stan ?? null, wariant, status: 'started' };
        wyniki.push(rekord);
        context = await przegladarka.newContext({ viewport: { width: 320, height: 740 } });
        // Nawigacja i zasoby przeglądarki również nigdy nie opuszczają localhost.
        await context.route('**/*', route => new URL(route.request().url()).origin === adres ? route.continue() : route.abort());
        const page = await context.newPage();
        const provider = ekran.adres.includes('/google/') ? 'google' : 'facebook';
        const scenario = ekran.stan || ekran.adres.split('/').at(-1);
        if (provider === 'facebook' && scenario === 'polacz') {
          await page.goto(adres + '/login', { waitUntil: 'load' });
          await page.fill('[name=login]', state.email);
          await page.fill('[name=password]', state.password);
          await Promise.all([page.waitForURL(u => u.pathname !== '/login'), page.locator('main form[action$="/login"] button[type=submit]').click()]);
        }
        const begin = await context.request.get(adres + '/wejdz/' + provider, { maxRedirects: 0 });
        if (begin.status() !== 302) throw Error('OAUTH345_START ' + begin.status());
        const location = new URL(begin.headers().location);
        if (!location.searchParams.get('state')) throw Error('OAUTH345_STATE');
        const code = Buffer.from(JSON.stringify({ scenario, nonce: location.searchParams.get('nonce') })).toString('base64');
        const callback = `${adres}/wejdz/${provider}/wroc?` + new URLSearchParams({ state: location.searchParams.get('state'), code });
        const response = await page.goto(callback, { waitUntil: 'load' });
        if (response.status() !== 200 || new URL(page.url()).pathname !== ekran.adres) throw Error('OAUTH345_EKRAN ' + ekran.adres + ' => ' + new URL(page.url()).pathname);
        await page.evaluate(dark => {
          document.documentElement.dataset.theme = dark ? 'dark' : 'light';
          document.documentElement.dataset.textScale = '140';
        }, dark);
        await page.evaluate(() => document.fonts.ready);
        await page.waitForFunction(dark => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - 25.2) < .15 && getComputedStyle(document.body).color === (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)'), dark);
        const main = page.locator('main');
        if (scenario === 'domknij') {
          for (const [name, text] of [['display_name', 'Jak mamy Cię nazywać?'], ['username', 'Nazwa, która będzie w adresie Twojego profilu']]) {
            const input = main.locator(`[name=${name}]`);
            if (await input.count() !== 1) throw Error('OAUTH345_FORMULARZ ' + name);
            const label = await input.evaluate((e, text) => [...e.labels].some(l => {
              const r = l.getBoundingClientRect();
              const style = getComputedStyle(l);
              return l.textContent.replace(/\s+/g, ' ').includes(text) && r.width > 0 && r.height > 0 && style.visibility === 'visible' && style.display !== 'none';
            }), text);
            if (!label) throw Error('OAUTH345_ETYKIETA ' + name);
          }
          if (await main.locator('input[type=checkbox]').count() < 2) throw Error('OAUTH345_OSWIADCZENIA');
        } else if (scenario === 'polacz') {
          if (await main.locator(`form[action$="/${provider}/polacz"] button[type=submit]`).count() !== 1) throw Error('OAUTH345_POLACZENIE');
        } else if (!(await main.innerText()).includes('adres')) throw Error('OAUTH345_BRAK_ADRESU');
        await page.evaluate(() => scrollTo(0, 0));
        const overflow = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
        if (overflow.scroll > overflow.width + 1) throw Error('OAUTH345_OVERFLOW ' + JSON.stringify(overflow));
        await page.screenshot({ path: `${out}/${provider}-${scenario}-${dark ? 'ciemny' : 'jasny'}.png`, fullPage: true });
        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        if (axe.violations.length) throw Error('OAUTH345_AXE ' + JSON.stringify(axe.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))));
        const tab = await sprawdzTab(page, ekran.adres);
        await page.screenshot({ path: `${out}/${provider}-${scenario}-${dark ? 'ciemny' : 'jasny'}-tab.png` });
        Object.assign(rekord, { status: 'success', dark, width: 320, tekst: 140, axe: 0, overflow, tab });
        rekordy.push(rekord);
        console.log('OAUTH345_OK ' + ekran.nazwa + ' ' + (dark ? 'ciemny' : 'jasny'));
        await context.close();
        context = null;
      }
    }
    const raport = { rekordy, czasMs: Date.now() - start };
    writeFileSync(out + '/wyniki.json', JSON.stringify(raport, null, 2));

    return raport;
  } catch (error) {
    const partial = wyniki.at(-1);
    if (partial?.status === 'started') { partial.status = 'error'; partial.blad = error.message; }
    throw error;
  } finally {
    try { if (context) await context.close(); }
    finally {
      try {
        if (serwer && serwer.exitCode === null) {
          const stopped = new Promise(resolve => serwer.once('exit', resolve));
          serwer.kill('SIGTERM');
          await stopped;
        }
      } finally {
        if (existsSync(snapshot)) fixture('usun');
        rmSync(katalog, { recursive: true, force: true });
      }
    }
  }
}
