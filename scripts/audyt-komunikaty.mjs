/*
 * =============================================================================
 *  Kuking.pl — TREŚĆ komunikatów walidacji
 * =============================================================================
 *
 *  AGENTS.md §5 żąda trzech rzeczy naraz i tylko trzecia jest o treści:
 *   • błąd jest PRZY POLU **oraz** w podsumowaniu na górze formularza,
 *   • poprawnie wpisane dane NIE ZNIKAJĄ (`old()`),
 *   • komunikat mówi CZŁOWIEKOWI, CO MA ZROBIĆ — a nie co się stało.
 *
 *  Trzeciego nie zmierzy automat i ten skrypt tego nie udaje. Robi to, czego
 *  automat nie zrobi źle: WYSYŁA formularze (pusty i z błędnymi danymi),
 *  ZBIERA dosłowne zdania i sprawdza dwie pierwsze reguły liczbowo, żeby
 *  człowiek czytał gotową listę zdań zamiast klikać po dwudziestu polach.
 *
 *  Heurystyka „co zrobić" jest w raporcie osobno i jest tylko PODPOWIEDZIĄ:
 *  zdanie zaczynające się od czasownika w trybie rozkazującym („Wpisz",
 *  „Podaj", „Wybierz") mówi, co zrobić; zdanie w stronie biernej albo
 *  opisowe („Pole jest wymagane", „Nieprawidłowy format") mówi, co się stało.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

const PROBY = [
  { nazwa: 'logowanie — puste', adres: '/login', dane: {} },
  { nazwa: 'logowanie — złe hasło', adres: '/login', dane: { login: KONTO, password: 'zle-haslo-123' } },
  { nazwa: 'rejestracja — puste', adres: '/register', dane: {} },
  {
    nazwa: 'rejestracja — złe dane',
    adres: '/register',
    dane: { email: 'to-nie-jest-adres', password: 'abc', username: 'A' },
  },
  /*
   * PRÓBA NA REGUŁĘ „POPRAWNE DANE NIE ZNIKAJĄ": jedno pole POPRAWNE,
   * drugie puste. Wpisanie samych złych danych niczego o tej regule nie mówi
   * — sprawdza się ją na tym, co człowiek wpisał DOBRZE i czego nie chce
   * pisać drugi raz.
   */
  {
    nazwa: 'rejestracja — poprawna nazwa, zły adres (co zostaje?)',
    adres: '/register',
    dane: { username: 'zenon-z-radomia', email: 'to-nie-jest-adres' },
  },
  {
    nazwa: 'napisz do nas — poprawny temat, pusta treść (co zostaje?)',
    adres: '/napisz-do-nas',
    dane: { name: 'Zenon', email: 'zenon@example.test' },
  },
  { nazwa: 'nie pamiętam hasła — puste', adres: '/nie-pamietam-hasla', dane: {} },
  { nazwa: 'napisz do nas — puste', adres: '/napisz-do-nas', dane: {} },
  { nazwa: 'dodaj zdjęcie — puste', adres: '/dodaj/zdjecie', dane: {}, zalogowany: true },
  { nazwa: 'dodaj przepis — puste', adres: '/dodaj/przepis', dane: {}, zalogowany: true },
  { nazwa: 'zadaj pytanie — puste', adres: '/pytania/zadaj', dane: {}, zalogowany: true },
  { nazwa: 'ustawienia profil — zła nazwa', adres: '/ustawienia/profil', dane: { username: '!!!' }, zalogowany: true },
];

const ZBIERZ_BLEDY = () => {
  const tekst = (el) => (el ? el.innerText.replace(/\s+/g, ' ').trim() : '');

  // Podsumowanie na górze formularza: nasz komponent `error-summary`,
  // albo cokolwiek z rolą `alert` nad pierwszym polem.
  const podsumowanie = document.querySelector(
    '.error-summary, [data-error-summary], [role="alert"]',
  );

  // Błąd przy polu: element powiązany przez `aria-describedby` z polem,
  // albo nasz `.field-error`. Bierzemy OBIE drogi, bo to są dwa różne
  // mechanizmy i tylko jeden z nich widzi czytnik ekranu.
  const przyPolu = [];
  for (const pole of document.querySelectorAll('input, textarea, select')) {
    if (pole.type === 'hidden') continue;
    const opisy = (pole.getAttribute('aria-describedby') || '')
      .split(/\s+/).filter(Boolean)
      .map((id) => document.getElementById(id))
      .filter(Boolean);
    const wlasny = pole.closest('.field, .pole, p, div')?.querySelector('.field-error, .blad-pola, .error');
    const zdania = [...new Set([...opisy.map(tekst), tekst(wlasny)].filter(Boolean))];
    if (zdania.length) {
      przyPolu.push({ pole: pole.name || pole.id || pole.type, zdania });
    }
  }

  // Czy dane zostały. Zbieramy wartości wszystkich pól tekstowych.
  const wartosci = {};
  for (const pole of document.querySelectorAll('input, textarea')) {
    if (['hidden', 'password', 'file'].includes(pole.type)) continue;
    if (pole.name) wartosci[pole.name] = pole.value;
  }

  return {
    podsumowanie: tekst(podsumowanie),
    podsumowanieRola: podsumowanie ? (podsumowanie.getAttribute('role') || '') : null,
    przyPolu,
    wartosci,
    statusTekst: [...document.querySelectorAll('[role="alert"], [role="status"]')].map(tekst).filter(Boolean),
  };
};

async function stanZalogowanego(przegladarka) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();
  await strona.goto(`${ADRES}/login`);
  await strona.fill('input[name="login"]', KONTO);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    strona.click('button[type="submit"]'),
  ]);
  const stan = await kontekst.storageState();
  await kontekst.close();
  return stan;
}

async function main() {
  const przegladarka = await chromium.launch();
  const stan = await stanZalogowanego(przegladarka);
  /*
   * DWA KONTEKSTY, NIE JEDEN — I TO JEST POPRAWKA FAŁSZYWEGO WYNIKU.
   *
   * `/login`, `/register` i `/nie-pamietam-hasla` stoją za `middleware('guest')`.
   * Wejście na nie z ciasteczkiem sesji kończy się przekierowaniem na `/home`,
   * więc pierwszy przebieg mierzył formularze, których tam nie ma: raz „zero
   * komunikatów" (bo strona była tablicą), raz timeout na niewidocznym
   * przycisku. Ekran gościa mierzymy jako gość.
   */
  const kontekstGosc = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
  const kontekstKonto = await przegladarka.newContext({
    viewport: { width: 390, height: 844 },
    storageState: stan,
  });
  const stronaGosc = await kontekstGosc.newPage();
  const stronaKonto = await kontekstKonto.newPage();

  const raport = [];
  for (const proba of PROBY) {
    const strona = proba.zalogowany ? stronaKonto : stronaGosc;
    try {
      const odp = await strona.goto(ADRES + proba.adres, { waitUntil: 'networkidle', timeout: 30000 });
      if (odp && !new URL(strona.url()).pathname.startsWith(proba.adres.split('?')[0])) {
        raport.push({ proba: proba.nazwa, blad: `przekierowanie na ${new URL(strona.url()).pathname}` });
        continue;
      }
      if (!odp || odp.status() >= 400) {
        raport.push({ proba: proba.nazwa, blad: `HTTP ${odp && odp.status()}` });
        continue;
      }

      const wpisane = {};
      for (const [pole, wartosc] of Object.entries(proba.dane)) {
        const sel = `[name="${pole}"]`;
        if (await strona.$(sel)) {
          await strona.fill(sel, wartosc);
          wpisane[pole] = wartosc;
        }
      }

      // Wyłączamy walidację przeglądarki: mierzymy NASZE komunikaty,
      // a nie dymki Chromium, których i tak nie da się przetłumaczyć.
      await strona.evaluate(() => {
        document.querySelectorAll('form').forEach((f) => f.setAttribute('novalidate', ''));
      });

      /*
       * PRZYCISK BIERZEMY Z TREŚCI STRONY I TYLKO WIDOCZNY.
       *
       * `form button[type=submit]` łapało PIERWSZY w dokumencie, a tym jest
       * przycisk w schowanym `<details>` konta w belce (albo przełącznik
       * motywu w stopce). Kliknięcie czekało 30 s na coś, czego nie widać,
       * i cały pomiar kończył się dziesięcioma timeoutami zamiast
       * dziesięcioma kompletami komunikatów.
       */
      const przycisk = await strona.evaluateHandle(() => {
        const widoczny = (el) => {
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          if (r.width === 0 || r.height === 0) return false;
          if (s.visibility === 'hidden' || s.display === 'none') return false;
          /*
           * Przycisk w ZWINIĘTYM `<details>` ma niezerowy prostokąt i styl
           * „widoczny" — przeglądarka po prostu go nie rysuje. Pierwszy
           * przebieg brał przez to przycisk „1" z panelu kolejności zdjęć
           * i czekał 30 s na kliknięcie czegoś, czego nie widać.
           */
          return !el.closest('[hidden], details:not([open]), dialog:not([open])');
        };
        const zakres = document.querySelector('main') || document.body;
        return [...zakres.querySelectorAll('form button[type="submit"], form button:not([type]), form input[type="submit"]')]
          .find(widoczny) || null;
      }).then((h) => h.asElement());
      if (!przycisk) {
        raport.push({ proba: proba.nazwa, blad: 'brak widocznego przycisku wysyłającego w treści strony' });
        continue;
      }
      await Promise.all([
        strona.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {}),
        przycisk.click(),
      ]);
      await strona.waitForTimeout(600);

      const zebrane = await strona.evaluate(ZBIERZ_BLEDY);
      const zachowane = Object.entries(wpisane)
        .filter(([k]) => k !== 'password')
        .map(([k, v]) => ({ pole: k, wpisano: v, zostalo: zebrane.wartosci[k] ?? '(brak pola)' }));

      raport.push({
        proba: proba.nazwa,
        adres: proba.adres,
        podsumowanieNaGorze: zebrane.podsumowanie,
        rolaPodsumowania: zebrane.podsumowanieRola,
        bledyPrzyPolach: zebrane.przyPolu,
        maPodsumowanie: !!zebrane.podsumowanie,
        maBledyPrzyPolach: zebrane.przyPolu.length > 0,
        daneZachowane: zachowane,
        inneKomunikaty: zebrane.statusTekst,
      });
    } catch (e) {
      raport.push({ proba: proba.nazwa, blad: String(e.message).slice(0, 200) });
    }
  }

  await kontekstGosc.close();
  await kontekstKonto.close();
  await przegladarka.close();
  mkdirSync('storage', { recursive: true });
  writeFileSync('storage/audyt-komunikaty.json', JSON.stringify(raport, null, 1));

  for (const r of raport) {
    console.log(`\n=== ${r.proba}`);
    if (r.blad) { console.log(`    BŁĄD: ${r.blad}`); continue; }
    console.log(`    podsumowanie na górze: ${r.maPodsumowanie ? `TAK (role=${r.rolaPodsumowania || 'brak'})` : 'NIE'}`);
    if (r.podsumowanieNaGorze) console.log(`      „${r.podsumowanieNaGorze.slice(0, 300)}"`);
    console.log(`    błędy przy polach: ${r.bledyPrzyPolach.length}`);
    for (const p of r.bledyPrzyPolach) console.log(`      [${p.pole}] ${p.zdania.join(' | ')}`);
    for (const d of r.daneZachowane) {
      console.log(`    dane: ${d.pole}: wpisano „${d.wpisano}" → zostało „${d.zostalo}"`);
    }
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
