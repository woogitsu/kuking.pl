/* #713 D6 — pasek górny po zalogowaniu: tryb gotowania i panel moderacji.

   CO TO ZAMYKA. #711 rozszerzył chowanie paska górnego (`data-pasek-przewijany`)
   z gościa na wszystkich. Mechanizm dochodzi do dwóch układów o własnym
   kształcie: trybu gotowania (`cook-topbar` ze stanem kroku pod paskiem) i
   panelu moderacji (`data-tryb-panelu`; dolny pasek `.bottom-nav-panel` na
   węższych oknach). `PasekChowaSieTakzePoZalogowaniuTest` dowodzi
   tylko, że ATRYBUT trafia do znaczników. Ten skrypt mierzy ZACHOWANIE w
   Chromium: pasek chowa się przy zjeździe, wraca przy ruchu w górę i przy
   fokusie, a przy tym niczego nie zasłania.

   URUCHOMIENIE (baza z `DemoSeeder`, własna, nie `kuking`):
     CHROMIUM_PATH=… DB_DATABASE=kuking_713_pomiar node scripts/pasek-uklady.mjs
   Skrypt sam podnosi `php artisan serve`, nadaje moderatorowi demo 2FA i loguje
   go prawdziwym formularzem z kodem TOTP. Hasło demo: `KUKING_DEMO_HASLO`
   przy zasiewie (`migrate:fresh --seed --seeder=DemoSeeder`).

   CZEGO NIE DOWODZI. Fizycznego telefonu, Safari/iOS ani odsłuchu czytnika
   ekranu. To Chromium z emulowanym oknem. */
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { przebieg } from './pasek-przewijany.mjs';
import { wymagajStanu } from './lib/stan-ustalony.mjs';

/* Wysokość 700 px, nie 900: przy `max-height: 40rem` (640 px) belki przestają
   być przypięte (`marka-rama.css`), więc pasek nie chowałby się wcale i nie
   byłoby czego mierzyć. 700 px zostaje ponad tym progiem, a daje więcej
   przewijania niż 900 px na krótkich ekranach panelu. */
const WYSOKOSC = 700;
const SZEROKOSCI = [320, 390, 768, 1440];
const SKALE = [100, 140];

/* Geometria współdziałania, w stronie. Zwraca liczby, decyzję podejmuje Node. */
function geometria({ nazwa }) {
  const bar = document.querySelector('[data-pasek-przewijany]');
  const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, top: r.top, bottom: r.bottom, left: r.left, right: r.right, width: r.width, height: r.height }; };
  const widoczny = (el) => { if (!el) return false; const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
  const stan = { bar: box(bar), schowany: bar?.hasAttribute('data-pasek-schowany') ?? null, scrollY, innerHeight, innerWidth };
  if (nazwa.startsWith('gotowanie')) {
    stan.krok = box(document.querySelector('.cook-topbar'));
    stan.zakoncz = box(document.querySelector('.cook-exit'));
  } else {
    const dolna = document.querySelector('.bottom-nav-panel');
    stan.dolna = widoczny(dolna) ? box(dolna) : null;
  }
  return stan;
}

/* Pasek i element nie mogą się nakładać w pionie, gdy pasek jest widoczny. */
function bezNakladania(nazwa, pasek, element, opis) {
  if (!element) return;
  assert(element.top >= pasek.bottom - 1 || element.bottom <= pasek.top + 1,
    `${nazwa}: ${opis} nachodzi na widoczny pasek górny: pasek ${JSON.stringify(pasek)}, element ${JSON.stringify(element)}`);
}

async function zmierz(p, nazwa) {
  return p.evaluate(geometria, { nazwa });
}

/* Jeden układ, cała macierz szerokość × skala tekstu. */
async function sprawdzUklad({ browser, adres, uklad, wyniki, out }) {
  for (const width of SZEROKOSCI) for (const scale of SKALE) {
    const kontekst = await browser.newContext({ storageState: uklad.sesja, viewport: { width, height: WYSOKOSC }, serviceWorkers: 'block' });
    try {
      const p = await kontekst.newPage();
      const odp = await p.goto(adres + uklad.sciezka);
      assert.equal(odp.status(), 200, `${uklad.nazwa}: HTTP ${odp.status()} ${uklad.sciezka}`);
      await p.evaluate((scale) => { document.documentElement.dataset.textScale = String(scale); }, scale);
      await p.evaluate(() => document.fonts.ready);
      const bar = p.locator('[data-pasek-przewijany]');
      assert(await bar.count() > 0, `${uklad.nazwa} ${width}px: układ nie niesie atrybutu data-pasek-przewijany — pasek nie chowa się po zalogowaniu`);
      const sticky = await bar.evaluate((e) => getComputedStyle(e).position === 'sticky');
      const etykieta = `${uklad.nazwa} ${width}px tekst ${scale}%`;
      assert(sticky, `${etykieta}: pasek nie jest przypięty (position != sticky) — nie ma czego chować; sprawdź okno ${WYSOKOSC} px`);
      // Warunek wstępny: strona musi się przewijać, inaczej „pasek się nie schował" nic nie znaczy.
      const zakres = await p.evaluate(() => document.documentElement.scrollHeight - innerHeight);
      assert(zakres > 400, `${etykieta}: za mało treści do przewinięcia (${zakres} px) — pomiar byłby pusty`);

      // Stan początkowy: pasek widoczny i niczego nie zasłania.
      const start = await zmierz(p, uklad.nazwa);
      assert.equal(start.schowany, false, `${etykieta}: pasek schowany na starcie`);
      if (uklad.nazwa.startsWith('gotowanie')) bezNakladania(etykieta, start.bar, start.krok, 'pasek kroku');
      else { bezNakladania(etykieta, start.bar, start.dolna, 'dolna nawigacja panelu'); }

      // Ta sama macierz co dla gościa: zjazd → schowany, ruch w górę → widoczny, fokus → widoczny.
      await przebieg({ p, bar, sticky, out, width, theme: 'light', scale, kto: uklad.nazwa });

      // Po zjeździe elementy stałe układu zostają w oknie, a po powrocie paska nie nachodzą na niego.
      await p.evaluate(() => scrollTo(0, 1000));
      await wymagajStanu(p, {
        opis: `${etykieta}: pasek ma się schować`, limitMs: 10_000,
        warunek: (_, przejscia) => { const b = document.querySelector('[data-pasek-przewijany]'); return b.hasAttribute('data-pasek-schowany') && b.getBoundingClientRect().bottom <= 0 && przejscia(b).length === 0; },
        pomiar: () => ({ scrollY }),
      });
      const zjechany = await zmierz(p, uklad.nazwa);
      if (zjechany.dolna) {
        assert(zjechany.dolna.bottom <= zjechany.innerHeight + 1 && zjechany.dolna.top >= 0,
          `${etykieta}: dolna nawigacja panelu poza oknem po schowaniu paska ${JSON.stringify(zjechany.dolna)}`);
      }
      await p.evaluate(() => scrollBy(0, -90));
      await wymagajStanu(p, {
        opis: `${etykieta}: pasek ma wrócić`, limitMs: 10_000,
        warunek: (_, przejscia) => { const b = document.querySelector('[data-pasek-przewijany]'); return !b.hasAttribute('data-pasek-schowany') && b.getBoundingClientRect().y >= 0 && przejscia(b).length === 0; },
        pomiar: () => ({ scrollY }),
      });
      const wrocil = await zmierz(p, uklad.nazwa);
      if (wrocil.dolna) {
        assert(wrocil.dolna.bottom <= wrocil.innerHeight + 1, `${etykieta}: dolna nawigacja panelu poza oknem po powrocie paska`);
      }
      assert(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${etykieta}: poziome przewijanie`);
      wyniki.push({ uklad: uklad.nazwa, width, scale, sticky, wynik: 'PASS' });
    } finally { await kontekst.close(); }
  }
}

export async function sprawdzPasekWUkladach({ browser, adres, uklady, out = 'storage/pasek-uklady' }) {
  mkdirSync(out, { recursive: true });
  const wyniki = [];
  for (const uklad of uklady) await sprawdzUklad({ browser, adres, uklad, wyniki, out });
  writeFileSync(out + '/wyniki.json', JSON.stringify(wyniki, null, 2));
  console.log(`PASEK_UKLADY_OK ${wyniki.length} konfiguracji w ${uklady.length} układach`);
  return wyniki;
}

/* ------------------------------ przebieg lokalny ------------------------------ */

function totp(sekret) {
  const alfabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bity = '';
  for (const c of sekret.replace(/=+$/, '').toUpperCase()) bity += alfabet.indexOf(c).toString(2).padStart(5, '0');
  const klucz = Buffer.from(bity.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const licznik = Buffer.alloc(8); licznik.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const skrot = createHmac('sha1', klucz).update(licznik).digest();
  return String((skrot.readUInt32BE(skrot[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function wolnyPort() {
  const gniazdo = createServer();
  await new Promise((ok, blad) => { gniazdo.once('error', blad); gniazdo.listen(0, '127.0.0.1', ok); });
  const { port } = gniazdo.address();
  await new Promise((ok) => gniazdo.close(ok));
  return port;
}

async function przebiegLokalny() {
  const { chromium } = await import('playwright');
  const repo = process.cwd();
  const baza = process.env.DB_DATABASE;
  assert(baza && baza !== 'kuking' && baza !== 'kuking_test', 'Podaj własną bazę po DemoSeeder w DB_DATABASE (nie `kuking` ani `kuking_test`).');
  const haslo = process.env.KUKING_DEMO_HASLO || 'haslo-testowe-123';
  // Turnstile wyłączony w tym procesie: pomiar nie łączy się z usługami zewnętrznymi.
  const env = { ...process.env, APP_BASE_PATH: repo, KUKING_DEMO_HASLO: haslo, TURNSTILE_SITE_KEY: '', TURNSTILE_SECRET_KEY: '', MAIL_MAILER: 'array' };
  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
  env.APP_URL = adres;
  const serwer = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: repo, env, detached: true, stdio: 'ignore' });
  const zatrzymaj = () => { try { process.kill(-serwer.pid, 'SIGTERM'); } catch { /* już zamknięty */ } };
  let przegladarka;
  try {
    for (let i = 0; i < 100; i++) {
      try { if ((await fetch(adres + '/health', { signal: AbortSignal.timeout(1000) })).status) break; } catch { /* jeszcze wstaje */ }
      await new Promise((ok) => setTimeout(ok, 200));
    }
    const php = `
      $u = App\\Models\\User::where('email', 'moderacja@example.test')->firstOrFail();
      if (! $u->hasTwoFactorConfirmed()) {
        $s = app(App\\Domain\\Security\\TwoFactorAuthenticator::class)->generateSecret();
        $u->beginTwoFactorSetup($s); $u->confirmTwoFactor([]);
      } else { $s = $u->two_factor_secret; }
      echo 'SEKRET:'.$s;
      $r = App\\Models\\Recipe::where('status', 'published')->where('visibility', 'public')->orderBy('id')->first();
      echo '|PRZEPIS:'.$r->slug;`;
    const odczyt = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: repo, env }).toString();
    const sekret = /SEKRET:([A-Z2-7]+)/.exec(odczyt)?.[1];
    const przepis = /PRZEPIS:([\w-]+)/.exec(odczyt)?.[1];
    assert(sekret && przepis, 'Nie udało się przygotować moderatora z 2FA i przepisu do pomiaru (zasiej bazę DemoSeeder).');
    przegladarka = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH && existsSync(process.env.CHROMIUM_PATH) ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const zaloguj = async (login, kodTotp) => {
      const kontekst = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
      const p = await kontekst.newPage();
      await p.goto(adres + '/login');
      await p.locator('[name=login]').fill(login);
      await p.locator('[name=password]').fill(haslo);
      await p.locator('form.panel-formularza button[type=submit]').click();
      await p.waitForURL((u) => u.pathname !== '/login');
      if (kodTotp) {
        assert(new URL(p.url()).pathname.includes('/logowanie/kod'), 'Brak wyzwania 2FA po haśle moderatora');
        if (Date.now() % 30000 > 27000) await new Promise((ok) => setTimeout(ok, 30000 - (Date.now() % 30000) + 50));
        await p.locator('[name=code]').fill(totp(kodTotp));
        await p.locator('form.panel-formularza button[type=submit]').click();
        await p.waitForURL((u) => !u.pathname.includes('logowanie'));
      }
      const stan = await kontekst.storageState();
      await kontekst.close();
      return stan;
    };
    const sesjaKonta = await zaloguj('ania', null);
    const sesjaModeratora = await zaloguj('moderacja', sekret);
    await sprawdzPasekWUkladach({
      browser: przegladarka, adres,
      uklady: [
        { nazwa: 'gotowanie krok 1', sciezka: `/przepisy/${przepis}/gotuj`, sesja: sesjaKonta },
        { nazwa: 'gotowanie krok 2', sciezka: `/przepisy/${przepis}/gotuj?krok=2`, sesja: sesjaKonta },
        { nazwa: 'panel zgloszenia', sciezka: '/admin/zgloszenia', sesja: sesjaModeratora },
        { nazwa: 'panel sygnaly', sciezka: '/admin/sygnaly', sesja: sesjaModeratora },
      ],
    });
  } finally {
    await przegladarka?.close();
    zatrzymaj();
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  przebiegLokalny().catch((blad) => { console.error(blad.message); process.exitCode = 1; });
}
