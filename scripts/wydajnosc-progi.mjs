/*
 * =============================================================================
 *  Kuking.pl — progi bramki Lighthouse (`scripts/wydajnosc.mjs`), issue #1029
 * =============================================================================
 *
 *  OSOBNY PLIK, BO TO JEST CZYSTA FUNKCJA. Ocena ekranu nie potrzebuje
 *  przeglądarki ani serwera, więc test (`wydajnosc-progi.test.mjs`) sprawdza ją
 *  na spreparowanych audytach — łącznie z przypadkiem, którego na żywej stronie
 *  nie da się wywołać na zamówienie (LCP ponad budżetem przy Performance ≥ 70).
 *
 *  DLACZEGO SAM WYNIK ZBIORCZY NIE WYSTARCZAŁ
 *  Performance to średnia WAŻONA kilku metryk. Zerowy TBT i niski CLS
 *  kompensują słaby LCP: 39 artefaktów CI z 22–24 września 2026 pokazuje
 *  stronę powitalną z Performance 88–91 i LCP 2,9–3,2 s — PASS za każdym razem.
 *  Zmiana wag w nowym Lighthouse też potrafi przestawić PASS/FAIL bez zmiany
 *  żadnego czasu. Dlatego obok progu zbiorczego stoi osobny budżet LCP.
 *
 *  LICZYMY Z `numericValue`, NIGDY Z `displayValue`. `displayValue` to tekst
 *  prezentacyjny („3.1 s", z twardą spacją, zależny od lokalizacji
 *  i zaokrąglenia) — zostaje w raporcie dla człowieka, ale nie w decyzji.
 * =============================================================================
 */

export const PROG_WYDAJNOSC = 70;
export const PROG_SEO = 98;

/*
 * BUDŻET LCP W CI: 4000 ms. Z SERII POMIARÓW, NIE Z GŁOWY.
 *
 * Seria: 39 artefaktów `wydajnosc.json` z CI (main i gałęzie, 22–24 września
 * 2026, ten sam runner i ta sama konfiguracja mobilna co niżej). LCP na
 * ośmiu ekranach:
 *
 *   ekran                 min    mediana   max
 *   strona powitalna      2,9     3,1      3,2 s
 *   Świeżo z Kuking       2,0     2,7      3,0 s
 *   logowanie, rejestracja, przepis, profil, tag
 *                         2,6     2,9      3,0 s
 *   regulamin             2,1     2,9      2,9 s
 *
 * Rozrzut między przebiegami to ±0,15 s (throttling jest SYMULOWANY, więc
 * szum jest mały). 4000 ms to granica „słabego" LCP w Core Web Vitals
 * (web.dev/articles/lcp) — nie liczba wymyślona pod wynik — i leży 0,8 s
 * nad najgorszym zmierzonym przebiegiem. Łapie regresję rzędu jednego
 * dodatkowego zasobu blokującego render albo zdjęcia pobieranego za późno,
 * a nie wywraca `main` na szumie runnera.
 *
 * #957 (priorytet pierwszego kafla kolażu) tego pomiaru nie przesuwa:
 * Lighthouse mierzy tu telefon 360×640, gdzie kolaż stoi pod pierwszym
 * ekranem. Zmierzone lokalnie przed/po: 2,93–3,01 s / 2,85–3,01 s.
 *
 * CEL PRODUKTU: 2500 ms — granica „dobrego" LCP. Dziś nie spełnia go żaden
 * ekran w medianie, więc NIE jest bramką (wywróciłaby `main` od pierwszego
 * przebiegu). Raport pokazuje go osobno przy każdym ekranie (`lcpCel`), żeby
 * odległość do celu była widoczna, a nie ukryta za zielonym PASS. Droga do
 * 2,5 s: #1000 (fonty), #1001 (zdjęcie wpisu); po nich budżet CI do zacieśnienia.
 */
export const BUDZET_LCP_MS = 4000;
export const CEL_LCP_MS = 2500;

const AUDYTY = {
  lcp: 'largest-contentful-paint',
  tbt: 'total-blocking-time',
  cls: 'cumulative-layout-shift',
  fcp: 'first-contentful-paint',
  si: 'speed-index',
};

/** Surowa wartość, tekst dla człowieka i wynik audytu — dla każdej metryki. */
export function metrykiZAudytow(audyty) {
  return Object.fromEntries(Object.entries(AUDYTY).map(([klucz, id]) => {
    const audyt = audyty?.[id];

    return [klucz, {
      numericValue: typeof audyt?.numericValue === 'number' ? audyt.numericValue : null,
      displayValue: audyt?.displayValue ?? null,
      score: audyt?.score ?? null,
    }];
  }));
}

/**
 * Ocena jednego ekranu. Zwraca `ok` i listę `powody` — każdy powód nazywa
 * ekran, metrykę, zmierzoną wartość i próg.
 */
export function ocenEkran({ nazwa, wydajnosc, seo, seoLiczone, metryki }) {
  const powody = [];

  if (wydajnosc < PROG_WYDAJNOSC) {
    powody.push(`${nazwa}: wydajność ${wydajnosc} poniżej progu ${PROG_WYDAJNOSC}`);
  }

  if (seoLiczone && seo < PROG_SEO) {
    powody.push(`${nazwa}: SEO ${seo} poniżej progu ${PROG_SEO}`);
  }

  const lcp = metryki?.lcp?.numericValue;

  // Brak liczby to awaria pomiaru, nie zaliczenie — tak samo jak `null`
  // w wyniku kategorii (patrz `wydajnosc.mjs`, „JEDNO PONOWIENIE").
  if (typeof lcp !== 'number' || ! Number.isFinite(lcp)) {
    powody.push(`${nazwa}: LCP nie został zmierzony (brak numericValue)`);
  } else if (lcp > BUDZET_LCP_MS) {
    powody.push(`${nazwa}: LCP ${Math.round(lcp)} ms ponad budżetem ${BUDZET_LCP_MS} ms`);
  }

  return {
    ok: powody.length === 0,
    powody,
    lcpCel: typeof lcp === 'number' && lcp <= CEL_LCP_MS,
  };
}
