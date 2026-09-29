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
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { przebieg } from './pasek-przewijany.mjs';
import { wymagajStanu } from './lib/stan-ustalony.mjs';
import { tinker, uruchomPrzegladarke, uruchomSerwer, zaloguj } from './lib/serwer-lokalny.mjs';

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

async function przebiegLokalny() {
  const { adres, env, zamknij } = await uruchomSerwer();
  let przegladarka;
  try {
    const odczyt = tinker(env, `
      $u = App\\Models\\User::where('email', 'moderacja@example.test')->firstOrFail();
      $s = app(App\\Domain\\Security\\TwoFactorAuthenticator::class)->generateSecret();
      $u->beginTwoFactorSetup($s); $u->confirmTwoFactor([]);
      echo 'SEKRET:'.$s;
      $r = App\\Models\\Recipe::where('status', 'published')->where('visibility', 'public')->orderBy('id')->first();
      echo '|PRZEPIS:'.$r->slug;`);
    const sekret = /SEKRET:([A-Z2-7]+)/.exec(odczyt)?.[1];
    const przepis = /PRZEPIS:([\w-]+)/.exec(odczyt)?.[1];
    assert(sekret && przepis, 'Nie udało się przygotować moderatora z 2FA i przepisu do pomiaru (zasiej bazę DemoSeeder).');
    przegladarka = await uruchomPrzegladarke();
    const sesjaKonta = await zaloguj(przegladarka, adres, 'ania');
    const sesjaModeratora = await zaloguj(przegladarka, adres, 'moderacja', sekret);
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
    zamknij();
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  przebiegLokalny().catch((blad) => { console.error(blad.message); process.exitCode = 1; });
}
