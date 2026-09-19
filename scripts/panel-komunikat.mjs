/*
 * Jak `scripts/panel-marki-run.mjs` opowiada o porażce, nie wydając
 * poświadczeń.
 *
 * DLACZEGO TO W OGÓLE JEST OSOBNYM PLIKIEM
 * Żeby dało się to sprawdzić testem. `panel-marki-run.mjs` jest skryptem
 * wykonywalnym — wykonuje pomiar już przy zaimportowaniu, więc nie da się go
 * wciągnąć do testu, żeby zapytać o samo składanie komunikatu. A to jest
 * dokładnie ta część, przy której pomyłka kosztuje najwięcej: albo wyda
 * hasło, albo nie powie nic.
 *
 * CO TU JEST PILNOWANE
 * Komunikat cudzego wyjątku NIE idzie do dziennika. Błąd Playwrighta potrafi
 * nieść w sobie tekst wpisywany do pola — czyli także hasło moderatora — a ten
 * dziennik czyta każdy, kto ma wgląd w CI. Ta granica była tu od początku
 * i tak zostaje.
 *
 * CO SIĘ ZMIENIŁO (19 września 2026)
 * Do tego dnia nie wychodziło stąd NIC poza zdaniem „sprawdź bezpieczny
 * raport miernika, logowanie lub start serwera" — trzy różne przyczyny
 * w jednym komunikacie i żadnej wskazówki, która z nich zaszła. Przy
 * nieudanym przebiegu w CI zostawało zgadywanie, a dziennik aplikacji na
 * runnerze znika przy następnym `actions/checkout`, więc po fakcie nie ma
 * już czego czytać.
 *
 * Nazwa klasy wyjątku i pierwsza ramka stosu z `scripts/` nie niosą ani
 * hasła, ani tekstu strony: to są `TimeoutError`
 * i `…/scripts/panel-marki.mjs:123:45`. Wystarczają, żeby odróżnić „serwer
 * nie wstał" od „logowanie nie przeszło" i od „asercja układu oblała".
 */

/** Wiadomości własne skryptu zaczynają się od `P581_` i wolno je podać w całości. */
const WLASNY = /^P581_[A-Z_]+(?::|$)/;

/* Kod z warstwy pomiaru: `DOMAIN_CHANGED`, `CASE_COUNT`,
   `OUTCOME_ERROR_ASSOCIATION`, `DETAILS_FULL_TEXT_CLIPPED`, `VALIDATION_ISOLATION`.
   Tych nazw `panel-validation.mjs` i sąsiedzi nie opatrują przedrostkiem
   `P581_`, więc do 19 września 2026 wychodziło stąd zamiast nich ogólne
   `P581_RUN_FAIL: Error` — a to jest dokładnie ta przyczyna porażki, o którą
   chodzi (#713 D1).

   DLACZEGO TO NIE ŁAMIE GRANICY Z NAGŁÓWKA PLIKU
   Ten wzorzec jest zakotwiczony z obu stron i dopuszcza WYŁĄCZNIE wielkie
   litery A–Z i podkreślenie: ani jednej cyfry, spacji, kropki, ukośnika,
   dwukropka, cudzysłowu ani nawiasu. Token, adres, fragment HTML-a i treść
   pola nie da się w takim napisie zmieścić. W samym repozytorium wszystkie
   takie kody są STAŁYMI wpisanymi w źródło (`requireThat(..., 'DOMAIN_CHANGED')`);
   wszędzie tam, gdzie kod jest z czymkolwiek sklejany, sklejenie dokłada
   spację albo dwukropek — `DETAILS_TAB_UNREACHABLE ' + selector`,
   `P581_${kod} ${JSON.stringify(dane)}` — i wtedy wzorzec NIE pasuje,
   więc taka wiadomość dalej idzie przez ogólne `P581_RUN_FAIL`. */
const KOD_POMIARU = /^[A-Z_]+$/;

export function komunikatBledu(error) {
  const tresc = String(error?.message ?? '');

  if (WLASNY.test(tresc)) return tresc;
  if (KOD_POMIARU.test(tresc)) return tresc;

  /* Bierzemy pierwszą ramkę wskazującą NASZ plik, a nie szczyt stosu:
     szczyt siedzi zwykle w `node_modules/playwright`, co nie mówi nic
     o tym, który krok pomiaru padł. */
  const ramka = String(error?.stack ?? '')
    .split('\n')
    .map((wiersz) => wiersz.trim())
    .find((wiersz) => wiersz.includes('/scripts/') || wiersz.includes('\\scripts\\'));

  const klasa = error?.constructor?.name ?? 'Error';

  return `P581_RUN_FAIL: ${klasa}${ramka ? ` w ${ramka}` : ''}`
    + ' — sprawdź bezpieczny raport miernika, logowanie lub start serwera.';
}
