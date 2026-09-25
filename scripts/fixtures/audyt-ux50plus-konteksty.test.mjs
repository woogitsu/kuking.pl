/*
 * =============================================================================
 *  REGRESJA #89: automat UX 50+ mierzy ekrany gościa JAKO GOŚĆ
 * =============================================================================
 *
 *  `scripts/audyt-ux50plus.mjs` tworzył każdy kontekst z sesją zalogowanego.
 *  Trasy `guest` (`/login`, `/register`, `/nie-pamietam-hasla`) odsyłały na
 *  `/home`, kod 200 przychodził już z celu przekierowania, a pomiar dostawał
 *  etykietę formularza. Raport wyglądał na kompletny, choć formularzy nikt
 *  nie zmierzył.
 *
 *  Test wykonuje PRAWDZIWY `main()` skryptu (źródło bez linii `import`)
 *  na atrapie Playwrighta, która zachowuje się jak `routes/web.php`:
 *  zalogowany na trasie `guest` → `/home`, gość na trasie `auth` → `/login`.
 *  Atrapa zastępuje tylko przeglądarkę, nie logikę wyboru kontekstu.
 *
 *  KONTROLA DODATNIA JEST W ŚRODKU (AGENTS.md §10): ten sam przebieg na
 *  źródle z przywróconym wspólnym `storageState` MUSI paść na przekierowaniu.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const ZRODLO = readFileSync(new URL('../audyt-ux50plus.mjs', import.meta.url), 'utf8');
const ADRES = 'http://kuking.test';

const TRASY_GOSCIA = new Set(['/login', '/register', '/nie-pamietam-hasla']);
const TRASY_AUTH = [/^\/home$/, /^\/dodaj/, /^\/pytania\/zadaj$/, /^\/powiadomienia$/, /^\/zeszyt$/, /^\/ustawienia/];

/* Znaki formularzy: element istnieje tylko na „swojej" ścieżce. */
const ZNAKI = {
  '/login': 'form[action$="/login"] input[name="login"]',
  '/register': 'form[action$="/register"]',
  '/nie-pamietam-hasla': 'h1:text-is("Nie pamiętam hasła")',
};

/**
 * Atrapa Playwrighta. `trasa(sciezka, zalogowany)` zwraca ścieżkę, na której
 * strona wyląduje — domyślnie zasady middleware z `routes/web.php`.
 */
function atrapa(trasa = domyslnaTrasa, znaki = ZNAKI) {
  const pomiary = [];
  let logowan = 0;

  const nowaStrona = (kontekst) => {
    let url = 'about:blank';
    return {
      async goto(docelowy) {
        const u = new URL(docelowy);
        url = ADRES + trasa(u.pathname, kontekst.zalogowany) + u.search;
        return { status: () => 200 };
      },
      url: () => url,
      async fill() {},
      async click() {
        if (new URL(url).pathname === '/login') {
          kontekst.zalogowany = true;
          logowan += 1;
          url = `${ADRES}/home`;
        }
      },
      async waitForURL() {},
      async waitForFunction() {},
      async $(selektor) {
        const sciezka = new URL(url).pathname;
        return znaki[sciezka] === selektor ? {} : null;
      },
      async evaluate(fn, arg) {
        if (arg && typeof arg === 'object' && 'progTekstu' in arg) {
          const sciezka = new URL(url).pathname;
          pomiary.push({ sciezka, zalogowany: kontekst.zalogowany });
          return { strona: sciezka, zalogowany: kontekst.zalogowany };
        }
        if (typeof arg === 'string' && arg.includes('przepisy')) return '/przepisy/zupa-ogorkowa';
        if (typeof arg === 'string' && arg.includes('wpisy')) return '/wpisy/0190-probka';
        return undefined;
      },
    };
  };

  const przegladarka = {
    async newContext(opcje = {}) {
      const kontekst = {
        zalogowany: Boolean(opcje.storageState?.zalogowany),
        async newPage() { return nowaStrona(kontekst); },
        async storageState() { return { zalogowany: kontekst.zalogowany }; },
        async close() {},
      };
      return kontekst;
    },
    async close() {},
  };

  return { chromium: { launch: async () => przegladarka }, pomiary, logowania: () => logowan };
}

function domyslnaTrasa(sciezka, zalogowany) {
  if (zalogowany && TRASY_GOSCIA.has(sciezka)) return '/home';
  if (!zalogowany && TRASY_AUTH.some((w) => w.test(sciezka))) return '/login';
  return sciezka;
}

/** Wykonuje `main()` z podanego źródła; zwraca raport, błędy i kod wyjścia. */
async function przebieg(zrodlo, playwright = atrapa()) {
  const bezImportow = zrodlo
    .split('\n')
    .filter((linia) => !linia.startsWith('import '))
    .join('\n')
    .replace(/\nmain\(\)\.catch\([^\n]*\n?$/, '\n');
  assert.ok(bezImportow.includes('async function main()'), 'Automat UX 50+ nie ma już `main()` — test nie wykonałby niczego.');

  const bledy = [];
  let raport = null;
  const proces = { env: { ADRES }, argv: ['node', 'audyt-ux50plus.mjs', '--szybko'], exitCode: 0 };
  const kontekst = vm.createContext({
    chromium: playwright.chromium,
    process: proces,
    URL,
    Map,
    Set,
    Promise,
    setTimeout,
    console: { log() {}, error: (t) => bledy.push(String(t)) },
    mkdirSync() {},
    writeFileSync(nazwa, json) {
      assert.equal(nazwa, 'storage/audyt-ux50plus.json');
      raport = JSON.parse(json);
    },
  });
  vm.runInContext(bezImportow, kontekst);
  await vm.runInContext('main()', kontekst);

  return { raport, bledy, kod: proces.exitCode, pomiary: playwright.pomiary, logowania: playwright.logowania() };
}

const wynikDla = (raport, nazwa) => raport.wyniki.filter((w) => w.ekran === nazwa);

test('formularze gościa są mierzone na swoich stronach, bez zalogowania', async () => {
  const { raport, bledy, kod } = await przebieg(ZRODLO);

  assert.equal(kod, 0, `Przebieg na poprawnych trasach nie może paść: ${bledy.join(' | ')}`);
  assert.deepEqual(raport.przekierowania, []);

  for (const [nazwa, sciezka] of [['logowanie', '/login'], ['rejestracja', '/register'], ['nie pamiętam hasła', '/nie-pamietam-hasla']]) {
    const wyniki = wynikDla(raport, nazwa);
    assert.ok(wyniki.length > 0, `Brak pomiaru ekranu „${nazwa}".`);
    for (const w of wyniki) {
      assert.equal(w.blad, undefined, `„${nazwa}": ${w.blad}`);
      assert.equal(w.strona, sciezka, `„${nazwa}" zmierzono na ${w.strona}.`);
      assert.equal(w.zalogowany, false, `„${nazwa}" zmierzono jako zalogowany.`);
    }
  }
});

test('ekrany z flagą `zalogowany` są mierzone po zalogowaniu, jednym logowaniem', async () => {
  const { raport, logowania } = await przebieg(ZRODLO);

  const tablica = wynikDla(raport, 'tablica startowa');
  assert.ok(tablica.length > 0);
  for (const w of tablica) {
    assert.equal(w.strona, '/home');
    assert.equal(w.zalogowany, true);
  }
  // Limit to pięć logowań na minutę — dwa konteksty nie mogą go mnożyć.
  assert.equal(logowania, 1);
});

test('ekran gościa odesłany na /home kończy przebieg błędem z adresem zamówionym i otrzymanym', async () => {
  const trasa = (sciezka, zalogowany) => (sciezka === '/register' ? '/home' : domyslnaTrasa(sciezka, zalogowany));
  const { raport, bledy, kod } = await przebieg(ZRODLO, atrapa(trasa));

  assert.equal(kod, 1);
  assert.ok(bledy.some((b) => b.includes('rejestracja') && b.includes('/register') && b.includes('/home')), bledy.join(' | '));
  assert.ok(wynikDla(raport, 'rejestracja').every((w) => w.blad && w.strona === undefined), 'Przekierowany ekran nie może mieć pomiaru.');
});

test('ekran pod właściwym adresem, ale bez swojego formularza, kończy przebieg błędem', async () => {
  const { bledy, kod } = await przebieg(ZRODLO, atrapa(domyslnaTrasa, { ...ZNAKI, '/login': 'coś innego' }));

  assert.equal(kod, 1);
  assert.ok(bledy.some((b) => b.includes('logowanie') && b.includes('brak znaku ekranu')), bledy.join(' | '));
});

test('KONTROLA DODATNIA: wspólny kontekst z sesją pada na przekierowaniu, nie na atrapie', async () => {
  const wzor = 'const kontekstGoscia = await przegladarka.newContext(ustawienia);';
  assert.equal(ZRODLO.split(wzor).length, 2, 'Przeniesiono tworzenie kontekstu gościa — zaktualizuj mutację.');
  const zepsute = ZRODLO.replace(wzor, 'const kontekstGoscia = await przegladarka.newContext({ ...ustawienia, storageState: stan });');

  const { bledy, kod, raport } = await przebieg(zepsute);

  assert.equal(kod, 1, 'Przywrócony wspólny storageState przeszedł na zielono.');
  for (const [nazwa, sciezka] of [['logowanie', '/login'], ['rejestracja', '/register']]) {
    assert.ok(
      bledy.some((b) => b.includes(`„${nazwa}"`) && b.includes(sciezka) && b.includes('odesłał na /home')),
      `Brak błędu przekierowania dla „${nazwa}": ${bledy.join(' | ')}`,
    );
  }
  assert.ok(raport.przekierowania.every((p) => p.otrzymana === '/home'), 'Błąd powstał z innego powodu niż przekierowanie.');
});
