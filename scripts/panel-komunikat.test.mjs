/*
 * Granica, której ten test pilnuje: komunikat o porażce panelu ma powiedzieć,
 * CO padło, i nie powiedzieć ANI SŁOWA z cudzego wyjątku.
 *
 * Obie połowy są potrzebne. Bez pierwszej dziennik CI mówi „sprawdź raport,
 * logowanie lub start serwera" i nic więcej — trzy przyczyny w jednym zdaniu,
 * a dziennik aplikacji na runnerze znika przy następnym `actions/checkout`.
 * Bez drugiej hasło moderatora wychodzi do dziennika, który czyta każdy,
 * kto ma wgląd w CI.
 *
 *   node --test scripts/panel-komunikat.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { komunikatBledu } from './panel-komunikat.mjs';

/* Prawdziwy kształt błędu Playwrighta przy nieudanym `fill`: komunikat niesie
   wartość wpisywaną do pola. To jest hasło moderatora z fixture. */
const HASLO = 'haslo-testowe-123';

function bladZHaslem() {
  const blad = new Error(`locator.fill: Timeout 30000ms exceeded.\nCall log:\n  - waiting for locator('[name=password]')\n  - filling "${HASLO}"`);
  blad.stack = [
    'TimeoutError: locator.fill: Timeout 30000ms exceeded.',
    `  - filling "${HASLO}"`,
    '    at /home/x/repo/node_modules/playwright-core/lib/client/channelOwner.js:146:17',
    '    at /home/x/repo/scripts/panel-marki.mjs:212:9',
    '    at async /home/x/repo/scripts/panel-marki-run.mjs:98:7',
  ].join('\n');
  blad.constructor = { name: 'TimeoutError' };

  return blad;
}

test('komunikat cudzego wyjątku nie wychodzi do dziennika', () => {
  const wynik = komunikatBledu(bladZHaslem());

  assert.ok(!wynik.includes(HASLO), `Hasło wyszło do dziennika: ${wynik}`);
  assert.ok(!wynik.includes('Call log'), 'Treść komunikatu Playwrighta wyszła do dziennika.');
  assert.ok(!wynik.includes('30000ms'), 'Treść komunikatu Playwrighta wyszła do dziennika.');
});

test('komunikat mówi, jaki to był wyjątek i w którym pliku pomiaru', () => {
  const wynik = komunikatBledu(bladZHaslem());

  assert.ok(wynik.startsWith('P581_RUN_FAIL: '), `Zły prefiks: ${wynik}`);
  assert.ok(wynik.includes('TimeoutError'), `Brak nazwy klasy wyjątku: ${wynik}`);
  assert.ok(wynik.includes('scripts/panel-marki.mjs:212'), `Brak ramki z naszego pliku: ${wynik}`);
  // Ramka z `node_modules` nie mówi, który krok pomiaru padł — bierzemy naszą.
  assert.ok(!wynik.includes('channelOwner'), `Wzięta ramka z node_modules: ${wynik}`);
});

test('wiadomości własne skryptu idą w całości — one nic cudzego nie niosą', () => {
  for (const tresc of ['P581_SERWER_TIMEOUT', 'P581_LOGOWANIE', 'P581_RUN_IZOLACJA: wymagane lokalne środowisko.']) {
    assert.equal(komunikatBledu(new Error(tresc)), tresc);
  }
});

/* #713 D1: kod z warstwy pomiaru idzie w całości.
   Do 19 września 2026 `panel-validation.mjs` rzucał `DOMAIN_CHANGED`, a do
   dziennika CI wychodziło `P581_RUN_FAIL: Error` — job `Panel marki` padał
   przerywanie i nie było wiadomo, CO padło. */
test('kod pomiaru bez przedrostka P581_ idzie w całości', () => {
  for (const kod of [
    'DOMAIN_CHANGED',
    'CASE_COUNT',
    'OUTCOME_ERROR_ASSOCIATION',
    'CANDIDATE_FAILED',
    'VALIDATION_ISOLATION',
    'DETAILS_FULL_TEXT_CLIPPED',
  ]) {
    assert.equal(komunikatBledu(new Error(kod)), kod);
  }
});

/* Druga połowa tej samej granicy: przepuszczamy KSZTAŁT, nie treść. Wszystko,
   co w tym repozytorium skleja kod z czymkolwiek z zewnątrz, dokłada spację
   albo dwukropek — i wtedy ma iść przez ogólne `P581_RUN_FAIL`, bo za spacją
   może stać selektor, URL albo tekst strony. */
test('kod sklejony z czymkolwiek NIE idzie w całości', () => {
  const sklejone = [
    'DETAILS_TAB_UNREACHABLE #zakladka-2',                 // panel-details.mjs:28
    'DOMAIN_CHANGED: haslo-testowe-123',
    'KOD I DRUGI',
    'KOD.Z.KROPKA',
    'KOD-Z-MYSLNIKIEM',
    'KOD123',
    'kod_maly',
    'Kod_Mieszany',
    'DOMAIN_CHANGED ',
  ];

  for (const tresc of sklejone) {
    const wynik = komunikatBledu(new Error(tresc));
    assert.ok(wynik.startsWith('P581_RUN_FAIL: '), `Przeszło bez osłony: ${tresc} -> ${wynik}`);
    assert.ok(!wynik.includes(tresc), `Treść cudzego komunikatu wyszła: ${wynik}`);
  }
});


/* Kod WŁASNY sklejony z danymi: przechodzi SAMA NAZWA PRZYCZYNY, ani jeden
   znak danych. To jest różnica między „kształt kontra treść" a „wszystko albo
   nic": do 19 września 2026 taka wiadomość szła przez ogólne `P581_RUN_FAIL`,
   więc job „Panel marki" chodził kilkanaście minut i zostawiał jeden wiersz
   bez przyczyny. Dane nadal nie wychodzą — sprawdza to druga asercja. */
test('kod własny sklejony z danymi oddaje sam kod, bez danych', () => {
  const przypadki = [
    ['P581_ZOOM_GEOMETRIA {"width":320}', 'P581_ZOOM_GEOMETRIA'],
    ['P581_DOMAIN_CHANGED {"adres":"https://przyklad/tajne?token=abc"}', 'P581_DOMAIN_CHANGED'],
    ['P581_CASE_COUNT 7 z 9', 'P581_CASE_COUNT'],
  ];

  for (const [tresc, oczekiwany] of przypadki) {
    const wynik = komunikatBledu(new Error(tresc));
    assert.equal(wynik, oczekiwany, `Kod przyczyny nie wyszedł: ${tresc} -> ${wynik}`);
    const dane = tresc.slice(oczekiwany.length).trim();
    assert.ok(dane !== '' && !wynik.includes(dane), `Dane wyszły do dziennika: ${wynik}`);
  }
});

/* KOTWICA POCZĄTKU jest częścią gwarancji, nie ozdobą. Bez niej dowolny
   komunikat ZAWIERAJĄCY gdzieś `P581_KOD ` zostałby zameldowany jako ta
   przyczyna — a to gorsze niż brak przyczyny, bo myli przy diagnozie.
   Wykryte przyrządem kontroli ujemnych: zdjęcie `^` nie psuło żadnego
   testu, czyli gwarancja nie była strzeżona. */
test('kod w środku komunikatu nie udaje przyczyny', () => {
  for (const tresc of [
    'Nie udało się: P581_DOMAIN_CHANGED {"a":1}',
    'at wymagaj — P581_CASE_COUNT 7',
    ' P581_ZOOM_GEOMETRIA {"width":320}',
  ]) {
    const wynik = komunikatBledu(new Error(tresc));
    assert.ok(wynik.startsWith('P581_RUN_FAIL: '),
      `Kod ze środka udał przyczynę: ${tresc} -> ${wynik}`);
  }
});

/* KONTROLA ODWROTNA: sama spacja nie wystarczy — bez przedrostka `P581_`
   napis idzie przez osłonę, bo wtedy nie wiadomo, czy to nasz kod. */
test('spacja bez przedrostka P581_ nie otwiera furtki', () => {
  for (const tresc of ['DOMAIN_CHANGED haslo-123', 'KOD I DRUGI', 'P581 COS']) {
    const wynik = komunikatBledu(new Error(tresc));
    assert.ok(wynik.startsWith('P581_RUN_FAIL: '), `Przeszło bez osłony: ${tresc} -> ${wynik}`);
  }
});

/* Pusty komunikat nie jest kodem — inaczej `new Error()` dałby pustą linię
   w dzienniku zamiast wskazówki. */
test('pusty komunikat nie przechodzi jako kod', () => {
  const wynik = komunikatBledu(new Error(''));
  assert.ok(wynik.startsWith('P581_RUN_FAIL: '), wynik);
});

/* Kształt, który przechodzi, nie ma jak unieść poświadczenia: wzorzec nie
   dopuszcza ani cyfry, ani znaku spoza A–Z i `_`. Błąd Playwrighta z hasłem
   dalej się przez niego nie przeciśnie. */
test('błąd Playwrighta z hasłem nie przechodzi nową furtką', () => {
  const wynik = komunikatBledu(bladZHaslem());

  assert.ok(!wynik.includes(HASLO), `Hasło wyszło do dziennika: ${wynik}`);
  assert.ok(wynik.startsWith('P581_RUN_FAIL: '), wynik);
});

test('brak stosu nie wywraca komunikatu', () => {
  const goly = new Error('cokolwiek');
  goly.stack = undefined;

  const wynik = komunikatBledu(goly);
  assert.ok(wynik.startsWith('P581_RUN_FAIL: Error'), wynik);
  assert.ok(!wynik.includes('cokolwiek'), 'Treść cudzego komunikatu wyszła mimo braku stosu.');
});

test('rzucona rzecz, która nie jest błędem, też nie wywraca komunikatu', () => {
  for (const cos of [undefined, null, 'goły napis', 42]) {
    const wynik = komunikatBledu(cos);
    assert.ok(wynik.startsWith('P581_RUN_FAIL: '), `Zły komunikat dla ${String(cos)}: ${wynik}`);
  }
});
