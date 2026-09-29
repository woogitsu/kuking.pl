/* #713 C4 (#697) — polityka CSP wobec Turnstile w PRAWDZIWEJ przeglądarce.

   CO TO ZAMYKA. #697: logowanie hasłem umarło na produkcji, bo `connect-src`
   nie zawierało `challenges.cloudflare.com` (błąd Turnstile 600010).
   `PolitykaCspDopuszczaPowrotTurnstileTest` czyta NAGŁÓWEK. Ten skrypt robi
   to, co robi widżet: strona `/login` (i `/register`), wczytana z
   kluczem Turnstile, ładuje skrypt z hosta Cloudflare (`script-src`), wysyła
   POST na ten host (`connect-src`) i otwiera z niego ramkę (`frame-src`).
   Przeglądarka sama orzeka, czy CSP przepuszcza; nasłuchujemy
   `securitypolicyviolation`. Skrypt widżetu i odpowiedzi hosta są ATRAPĄ
   (Playwright `route`) — żadnego połączenia z Cloudflare, żadnego prawdziwego
   wyzwania.

   KONTROLA UJEMNA W TYM SAMYM PLIKU: ta sama strona z nagłówkiem CSP
   pozbawionym hosta w `connect-src` (stan sprzed #697) MUSI dać naruszenie
   `connect-src` — inaczej detektor nic nie mierzy.

   CZEGO NIE DOWODZI. Prawdziwego wyzwania Turnstile, weryfikacji tokenu po
   stronie serwera ani całej ścieżki logowania na PRODUKCJI: to wymaga
   człowieka na kuking.pl (C4 w rejestrze #713 zostaje otwarte). Lokalne
   logowanie hasłem do sesji, bez Turnstile, przechodzi w
   `scripts/pasek-uklady.mjs` i `scripts/lib/serwer-lokalny.mjs`.

   URUCHOMIENIE (własna baza po `DemoSeeder`):
     CHROMIUM_PATH=… DB_DATABASE=kuking_713_pomiar node scripts/turnstile-csp.mjs */
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { uruchomPrzegladarke, uruchomSerwer } from './lib/serwer-lokalny.mjs';

const HOST = 'https://challenges.cloudflare.com';
const CORS = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' };

/* Atrapa `api.js`: robi to, co widżet — POST na host Cloudflare i ramka z niego. */
const SKRYPT_WIDZETU = `
window.__ts = { naruszenia: [], fetch: 'brak', ramka: 'brak' };
document.addEventListener('securitypolicyviolation', (e) => window.__ts.naruszenia.push(e.violatedDirective + ' ' + e.blockedURI));
fetch('${HOST}/cdn-cgi/challenge-platform/h/g/fr', { method: 'POST', body: 'x' })
  .then(() => { window.__ts.fetch = 'ok'; }, () => { window.__ts.fetch = 'zablokowany'; });
const ramka = document.createElement('iframe');
ramka.onload = () => { window.__ts.ramka = 'ok'; };
ramka.src = '${HOST}/cdn-cgi/challenge-platform/h/g/turnstile/if/atrapa/';
document.body.appendChild(ramka);
`;

async function stronaZAtrapa(kontekst, adres, sciezka, { bezHostaWConnect = false } = {}) {
  const p = await kontekst.newPage();
  await p.route(`${HOST}/**`, (route) => {
    const url = route.request().url();
    if (url.endsWith('/turnstile/v0/api.js')) return route.fulfill({ contentType: 'text/javascript', headers: CORS, body: SKRYPT_WIDZETU });
    if (url.includes('/turnstile/if/')) return route.fulfill({ contentType: 'text/html', headers: CORS, body: '<!doctype html><title>atrapa</title>' });
    return route.fulfill({ status: 200, contentType: 'application/json', headers: CORS, body: '{}' });
  });
  if (bezHostaWConnect) {
    await p.route(adres + sciezka, async (route) => {
      const odp = await route.fetch();
      const naglowki = { ...odp.headers() };
      naglowki['content-security-policy'] = naglowki['content-security-policy'].replace(/(connect-src[^;]*?) https:\/\/challenges\.cloudflare\.com/, '$1');
      await route.fulfill({ response: odp, headers: naglowki });
    });
  }
  const odp = await p.goto(adres + sciezka);
  assert.equal(odp.status(), 200, `${sciezka}: HTTP ${odp.status()}`);
  const csp = odp.headers()['content-security-policy'] ?? '';
  return { p, csp };
}

export async function sprawdzTurnstileCsp({ browser, adres }) {
  let ile = 0;
  for (const sciezka of ['/login', '/register']) {
    const kontekst = await browser.newContext({ serviceWorkers: 'block' });
    try {
      const { p, csp } = await stronaZAtrapa(kontekst, adres, sciezka);
      assert(await p.locator('.cf-turnstile').count() > 0, `${sciezka}: brak widżetu Turnstile (klucz testowy nie włączył go na tym formularzu)`);
      for (const dyrektywa of ['script-src', 'connect-src', 'frame-src']) {
        const wartosc = new RegExp(`${dyrektywa}[^;]*`).exec(csp)?.[0] ?? '';
        // Całe źródło jako osobny token dyrektywy, nie podciąg (CodeQL: incomplete URL substring sanitization).
        assert(wartosc.split(/\s+/).includes(HOST), `${sciezka}: nagłówek CSP nie ma hosta Turnstile w ${dyrektywa}: ${wartosc}`);
      }
      await p.waitForFunction(() => window.__ts && window.__ts.fetch !== 'brak' && window.__ts.ramka !== 'brak', null, { timeout: 15_000 }).catch(() => {});
      const stan = await p.evaluate(() => window.__ts ?? null);
      assert(stan, `${sciezka}: skrypt widżetu nie wykonał się (script-src zablokował api.js?)`);
      assert.deepEqual(stan.naruszenia, [], `${sciezka}: przeglądarka zgłosiła naruszenia CSP: ${JSON.stringify(stan.naruszenia)}`);
      assert.equal(stan.fetch, 'ok', `${sciezka}: POST na host Turnstile: ${stan.fetch}`);
      assert.equal(stan.ramka, 'ok', `${sciezka}: ramka z hosta Turnstile: ${stan.ramka}`);
      ile++;
    } finally { await kontekst.close(); }
  }

  // Kontrola ujemna: stan sprzed #697 — detektor MUSI zobaczyć naruszenie connect-src.
  const kontekst = await browser.newContext({ serviceWorkers: 'block' });
  try {
    const { p } = await stronaZAtrapa(kontekst, adres, '/login', { bezHostaWConnect: true });
    await p.waitForFunction(() => window.__ts && window.__ts.fetch !== 'brak', null, { timeout: 15_000 }).catch(() => {});
    const stan = await p.evaluate(() => window.__ts ?? null);
    assert(stan && stan.fetch === 'zablokowany' && stan.naruszenia.some((n) => n.startsWith('connect-src')),
      `KONTROLA UJEMNA nie wykryła braku hosta w connect-src: ${JSON.stringify(stan)}`);
  } finally { await kontekst.close(); }

  console.log(`TURNSTILE_CSP_OK ${ile} formularzy bez naruszeń; kontrola ujemna (bez hosta w connect-src) wykryta`);
  return ile;
}

async function przebiegLokalny() {
  // Klucze testowe Cloudflare (publiczne, z dokumentacji): widżet pokazuje się w
  // znacznikach, a serwer nie łączy się z niczym przy samym renderze strony.
  const { adres, zamknij } = await uruchomSerwer({
    TURNSTILE_SITE_KEY: '1x00000000000000000000AA',
    TURNSTILE_SECRET_KEY: '1x0000000000000000000000000000000AA',
  });
  let przegladarka;
  try {
    przegladarka = await uruchomPrzegladarke();
    await sprawdzTurnstileCsp({ browser: przegladarka, adres });
  } finally {
    await przegladarka?.close();
    zamknij();
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  przebiegLokalny().catch((blad) => { console.error(blad.message); process.exitCode = 1; });
}
