/* Lokalny serwer do jednorazowych pomiarów w Chromium (#713).

   Wspólny szkielet skryptów, które NIE należą do `port-projektu.mjs` ani
   `panel-marki-run.mjs`, a potrzebują działającej aplikacji na własnej bazie
   po `migrate:fresh --seed --seeder=DemoSeeder`: podnosi `php artisan serve
   --no-reload` (powód flagi: komentarz w `port-projektu.mjs`), wyłącza
   Turnstile w tym procesie (żadnych połączeń z usługami zewnętrznymi) i
   loguje prawdziwym formularzem. Baza `kuking` i `kuking_test` są odrzucane. */
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { execFileSync, spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { createServer } from 'node:net';

export const HASLO_DEMO = process.env.KUKING_DEMO_HASLO || 'haslo-testowe-123';

export async function wolnyPort() {
  const gniazdo = createServer();
  await new Promise((ok, blad) => { gniazdo.once('error', blad); gniazdo.listen(0, '127.0.0.1', ok); });
  const { port } = gniazdo.address();
  await new Promise((ok) => gniazdo.close(ok));
  return port;
}

export function totp(sekret) {
  const alfabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bity = '';
  for (const c of sekret.replace(/=+$/, '').toUpperCase()) bity += alfabet.indexOf(c).toString(2).padStart(5, '0');
  const klucz = Buffer.from(bity.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const licznik = Buffer.alloc(8); licznik.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const skrot = createHmac('sha1', klucz).update(licznik).digest();
  return String((skrot.readUInt32BE(skrot[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

/* Zwraca { adres, env, zamknij }. `dodatkoweEnv` np. { KUKING_QUESTIONS_ENABLED: 'true' }. */
export async function uruchomSerwer(dodatkoweEnv = {}) {
  const repo = process.cwd();
  const baza = process.env.DB_DATABASE;
  assert(baza && baza !== 'kuking' && baza !== 'kuking_test',
    'Podaj własną bazę po DemoSeeder w DB_DATABASE (nie `kuking` ani `kuking_test`).');
  const env = { ...process.env, APP_BASE_PATH: repo, KUKING_DEMO_HASLO: HASLO_DEMO, TURNSTILE_SITE_KEY: '', TURNSTILE_SECRET_KEY: '', MAIL_MAILER: 'array', ...dodatkoweEnv };
  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
  env.APP_URL = adres;
  const serwer = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: repo, env, detached: true, stdio: 'ignore' });
  const zamknij = () => { try { process.kill(-serwer.pid, 'SIGTERM'); } catch { /* już zamknięty */ } };
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(adres + '/health', { signal: AbortSignal.timeout(1000) })).status) return { adres, env, zamknij }; } catch { /* jeszcze wstaje */ }
    await new Promise((ok) => setTimeout(ok, 200));
  }
  zamknij();
  throw new Error('Serwer lokalny nie wstał w 20 s.');
}

/* Kod PHP w tinkerze na tej samej bazie; zwraca standardowe wyjście. */
export function tinker(env, kodPhp) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', kodPhp], { cwd: process.cwd(), env }).toString();
}

export async function uruchomPrzegladarke() {
  const { chromium } = await import('playwright');
  const sciezka = process.env.CHROMIUM_PATH;
  return chromium.launch({ headless: true, ...(sciezka && existsSync(sciezka) ? { executablePath: sciezka } : {}) });
}

/* Logowanie formularzem; przy `sekretTotp` także drugim składnikiem. Zwraca storageState. */
export async function zaloguj(przegladarka, adres, login, sekretTotp = null) {
  const kontekst = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
  try {
    const p = await kontekst.newPage();
    await p.goto(adres + '/login');
    await p.locator('[name=login]').fill(login);
    await p.locator('[name=password]').fill(HASLO_DEMO);
    await p.locator('form.panel-formularza button[type=submit]').click();
    await p.waitForURL((u) => u.pathname !== '/login');
    if (sekretTotp) {
      assert(new URL(p.url()).pathname.includes('/logowanie/kod'), 'Brak wyzwania 2FA po haśle');
      if (Date.now() % 30000 > 27000) await new Promise((ok) => setTimeout(ok, 30000 - (Date.now() % 30000) + 50));
      await p.locator('[name=code]').fill(totp(sekretTotp));
      await p.locator('form.panel-formularza button[type=submit]').click();
      await p.waitForURL((u) => !u.pathname.includes('logowanie'));
    }
    return await kontekst.storageState();
  } finally { await kontekst.close(); }
}
