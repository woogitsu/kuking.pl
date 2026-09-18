#!/usr/bin/env node
/*
 * =============================================================================
 *  Regresje PRZYRZĄDU #605 — generator obciążenia
 * =============================================================================
 *
 *  CO TU JEST SPRAWDZANE, A CO NIE
 *  Sprawdzany jest PRZYRZĄD: czy pojedyncze żądanie zawsze się kończy, czy
 *  rozróżnia bezczynność gniazda od całkowitego czasu żądania, czy seria
 *  domyka wszystkie zadania i czy brakująca odpowiedź nie poprawia wyniku.
 *  NIE jest tu sprawdzana wydajność Kukinga i żadna liczba stąd nie jest
 *  wynikiem wydajnościowym portalu — stanowiskiem jest `serwer-scenariuszy-605`,
 *  a nie aplikacja.
 *
 *  Historia: na `0e5d2707` `zadanie()` nie kończyło obietnicy, gdy odpowiedź
 *  została urwana po nagłówkach, a `req.setTimeout()` mierzył bezczynność
 *  gniazda, nie czas żądania — serwer dosyłający fragment co 75 ms trzymał
 *  pomiar bez końca.
 *
 *  KONTROLE UJEMNE SĄ FIZYCZNE (AGENTS.md §10): każda z nich psuje KOPIĘ
 *  generatora, sprawdza, że regresja OBLEWA, i porównuje sumę MD5 oryginału
 *  przed i po. Test, który przechodzi na zepsutym kodzie, nie jest dowodem.
 *
 *  Uruchomienie:  node scripts/przyrzad-605.test.mjs
 */

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { copyFileSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { uruchomSerwer } from './serwer-scenariuszy-605.mjs';

const KATALOG = dirname(fileURLToPath(import.meta.url));
const GENERATOR = join(KATALOG, 'generator-obciazenia-605.mjs');
const md5 = (sciezka) => createHash('md5').update(readFileSync(sciezka)).digest('hex');
const MD5_GENERATORA_NA_WEJSCIU = md5(GENERATOR);

const roboczy = mkdtempSync(join(tmpdir(), 'kuking-605-testy-'));
let zdane = 0;
const powiedz = (co) => { zdane += 1; process.stdout.write(`  ✓ ${co}\n`); };

// =============================================================================
// 1. Pojedyncze żądanie — każdy scenariusz MUSI się skończyć
// =============================================================================

const { zadanie, zamknijAgenta } = await import(pathToFileURL(GENERATOR).href);
const stanowisko = await uruchomSerwer();
const CEL = { hostname: '127.0.0.1', port: stanowisko.port };

/*
 * Każde żądanie jest ścigane z zewnętrznym watchdogiem. Bez niego test na
 * zawieszeniu nie OBLEWAŁBY, tylko wisiał — a wiszący test nie jest wynikiem.
 */
async function zKontrola(cfg, watchdogMs) {
  let wynik = null;
  await Promise.race([
    zadanie({ ...cfg, cel: CEL }).then((w) => { wynik = w; }),
    new Promise((r) => setTimeout(r, watchdogMs)),
  ]);
  assert.ok(wynik, `ZAWIESZONE: ${cfg.sciezka} nie rozwiązało się w ${watchdogMs} ms`);
  return wynik;
}

process.stdout.write('Pojedyncze żądanie:\n');

{
  const w = await zKontrola({ sciezka: '/pelna', bezczynnoscMs: 2000, calkowityMs: 3000 }, 5000);
  assert.equal(w.status, 200);
  assert.equal(w.powod, 'ok');
  assert.ok(w.tresc.includes('stanowisko #605'), 'Treść odpowiedzi ma być zebrana w całości.');
  powiedz('poprawna odpowiedź kończy się statusem 200 i powodem „ok”');
}

{
  const w = await zKontrola({ sciezka: '/brak-odpowiedzi', bezczynnoscMs: 300, calkowityMs: 5000 }, 4000);
  assert.equal(w.powod, 'bezczynnosc', 'Brak odpowiedzi ma padać na limicie bezczynności.');
  assert.equal(w.status, 0);
  powiedz('brak odpowiedzi kończy się limitem bezczynności');
}

{
  // REGRESJA GŁÓWNA: na 0e5d2707 ta obietnica nie rozwiązywała się nigdy.
  const w = await zKontrola({ sciezka: '/urwana', bezczynnoscMs: 5000, calkowityMs: 5000 }, 3000);
  assert.equal(w.powod, 'urwana', 'Odpowiedź urwana po nagłówkach ma być zgłoszona jako urwana.');
  assert.equal(w.status, 0, 'Urwana odpowiedź nie jest odpowiedzią — status 0, nie 200.');
  assert.ok(w.ms < 2000, 'Zerwanie ma być wykryte od razu, nie po limicie.');
  assert.ok(w.bajty > 0, 'Bajty odebrane przed zerwaniem są dowodem, że zerwanie było częściowe.');
  powiedz('odpowiedź urwana po nagłówkach kończy zadanie zamiast je zawieszać');
}

{
  const w = await zKontrola({ sciezka: '/naglowki-bez-konca', bezczynnoscMs: 300, calkowityMs: 5000 }, 4000);
  assert.equal(w.powod, 'bezczynnosc');
  powiedz('nagłówki bez zakończenia body kończą się limitem bezczynności');
}

{
  /*
   * ROZRÓŻNIENIE, O KTÓRE CHODZI: fragment co 60 ms nigdy nie naruszy limitu
   * bezczynności 1000 ms, a mimo to żądanie musi paść na całkowitym deadline.
   */
  const w = await zKontrola(
    { sciezka: '/powolne-bez-konca?co=60&bajty=12', bezczynnoscMs: 1000, calkowityMs: 400 },
    4000,
  );
  assert.equal(w.powod, 'deadline', 'Strumień podtrzymujący połączenie ma padać na całkowitym limicie.');
  assert.ok(w.ms >= 380 && w.ms < 1000, `Deadline ma zadziałać ok. 400 ms, zadziałał po ${Math.round(w.ms)} ms.`);
  powiedz('powolne fragmenty bez końca padają na całkowitym deadline, nie na bezczynności');
}

{
  // Kontrola przeciwna: wolna, ale SKOŃCZONA odpowiedź mieszcząca się w deadline
  // nie może zostać zerwana. Inaczej „poprawka” zamieniłaby się w fałszywe błędy.
  const w = await zKontrola(
    { sciezka: '/powolne?ile=5&co=40&bajty=12', bezczynnoscMs: 1000, calkowityMs: 5000 },
    6000,
  );
  assert.equal(w.powod, 'ok');
  assert.equal(w.status, 200);
  assert.ok(w.ms >= 150, 'Odpowiedź miała być wolna — inaczej ten przypadek niczego nie sprawdza.');
  powiedz('wolna, ale skończona odpowiedź w granicach deadline’u jest poprawna');
}

{
  const przerywacz = new AbortController();
  setTimeout(() => przerywacz.abort(), 100);
  const w = await zKontrola(
    { sciezka: '/powolne-bez-konca?co=60', bezczynnoscMs: 60000, calkowityMs: 60000, sygnal: przerywacz.signal },
    3000,
  );
  assert.equal(w.powod, 'anulowane');
  assert.ok(w.ms < 1500, 'Anulowanie ma być natychmiastowe.');
  powiedz('anulowanie sygnałem kończy żądanie natychmiast');
}

{
  const juzPrzerwany = AbortSignal.abort();
  const w = await zKontrola({ sciezka: '/pelna', sygnal: juzPrzerwany }, 2000);
  assert.equal(w.powod, 'anulowane');
  powiedz('żądanie z już przerwanym sygnałem nie jest w ogóle wysyłane');
}

{
  assert.throws(
    () => zadanie({ sciezka: '/', cel: { hostname: 'kuking.pl', port: 80 } }),
    /wyłącznie przeciwko lokalnemu/,
    'Bezpiecznik adresu ma działać także dla jawnie podanego celu.',
  );
  powiedz('bezpiecznik nie pozwala skierować przyrządu poza localhost');
}

{
  // Sprzątanie gniazd: po wszystkich zerwaniach stanowisko nie może zostać
  // z otwartymi połączeniami po stronie przyrządu.
  for (let i = 0; i < 40 && stanowisko.otwartePolaczenia() > 0; i++) {
    await new Promise((r) => setTimeout(r, 50));
  }
  assert.equal(stanowisko.otwartePolaczenia(), 0,
    `Po zerwanych żądaniach zostało ${stanowisko.otwartePolaczenia()} otwartych gniazd.`);
  powiedz('zerwane żądania nie zostawiają otwartych gniazd na stanowisku');
}

zamknijAgenta();
await stanowisko.zamknij();

// =============================================================================
// 2. Cała seria — domknięcie, liczenie błędów, percentyle
// =============================================================================

function manifestNa(baza, katalogZdjec) {
  return {
    baza,
    utworzony: new Date().toISOString(),
    sesje: Array.from({ length: 4 }, (_, i) => ({
      email: `stanowisko-${i}@example.test`,
      ciasteczka: `kuking_session=stanowisko${i}`,
      token: 'token-stanowiska',
      zeszyt: '00000000-0000-4000-8000-000000000001',
    })),
    cele: {
      // `/przepisy/*` odpowiada poprawnie, `/wpisy/*` urywa po nagłówkach,
      // `/tag/*` sączy fragmenty bez końca — trzy zachowania w jednej serii.
      przepisy: ['/przepisy/pierwszy', '/przepisy/drugi'],
      wpisy: ['/wpisy/00000000-0000-4000-8000-000000000002'],
      tagi: ['/tag/zupy'],
      profile: ['/@kucharz'],
      media: ['/zdjecia/00000000-0000-4000-8000-000000000003/feed'],
    },
    _katalogZdjec: katalogZdjec,
  };
}

function uruchomSerie(args, { sygnalPo = null, limitMs = 60000, skrypt = GENERATOR } = {}) {
  return new Promise((gotowe, blad) => {
    const proces = spawn(process.execPath, [skrypt, 'seria', ...args], { stdio: ['ignore', 'pipe', 'pipe'] });
    let wyjscie = '';
    let bledy = '';
    proces.stdout.on('data', (c) => { wyjscie += c; });
    proces.stderr.on('data', (c) => { bledy += c; });
    const watchdog = setTimeout(() => {
      proces.kill('SIGKILL');
      blad(new Error(`Seria nie zakończyła się w ${limitMs} ms — przyrząd wisi.`));
    }, limitMs);
    if (sygnalPo) setTimeout(() => proces.kill('SIGINT'), sygnalPo);
    proces.on('close', (kod, sygnal) => {
      clearTimeout(watchdog);
      gotowe({ kod, sygnal, wyjscie, bledy, ms: Date.now() });
    });
  });
}

process.stdout.write('Cała seria:\n');

const katalogZdjec = join(roboczy, 'zdjecia');
mkdirSync(katalogZdjec, { recursive: true });
// Przyrząd oczekuje pliku do wgrania; treść jest nieistotna, stanowisko i tak
// nie przetwarza obrazów — istotne jest, że ścieżka istnieje.
writeFileSync(join(katalogZdjec, 'kuking-b605-12mpx.jpg'), Buffer.alloc(2048, 7));

{
  const stanowisko2 = await uruchomSerwer();
  const plikManifestu = join(roboczy, 'manifest.json');
  const plikWyniku = join(roboczy, 'seria.json');
  writeFileSync(plikManifestu, JSON.stringify(manifestNa(stanowisko2.baza, katalogZdjec)));

  const start = Date.now();
  const bieg = await uruchomSerie([
    '--manifest', plikManifestu,
    '--baza', stanowisko2.baza,
    '--nazwa', 'stanowisko',
    '--rps', '40',
    '--czas', '4',
    '--zdjecia', katalogZdjec,
    '--wynik', plikWyniku,
    '--bezczynnosc', '1500',
    '--calkowity', '800',
    '--domkniecie', '1000',
  ], { limitMs: 45000 });
  const trwalo = Date.now() - start;

  assert.equal(bieg.kod, 0, `Seria zakończyła się kodem ${bieg.kod}. stderr: ${bieg.bledy}`);
  assert.ok(trwalo < 20000, `Seria 4-sekundowa trwała ${trwalo} ms — domknięcie nie działa.`);
  assert.ok(!bieg.bledy.includes('nie zakończył się sam'),
    'Proces przyrządu nie zamknął się sam — zostały uchwyty.');

  const w = JSON.parse(readFileSync(plikWyniku, 'utf8'));

  assert.equal(w.razem.w_locie_na_koniec, 0, 'Zostały żądania w locie po zamknięciu serii.');
  assert.ok(w.razem.zadan_wyslanych > 0, 'Seria nie wysłała żadnego żądania.');
  assert.ok(w.razem.nieudanych > 0, 'Stanowisko urywało odpowiedzi — błędy MUSZĄ się pojawić w wyniku.');
  assert.equal(
    w.razem.nieudanych,
    w.razem.zadan_wyslanych + w.razem.porzuconych_przez_limit - w.razem.poprawnych,
    'Rachunek błędów ma się domykać razem z żądaniami porzuconymi.',
  );
  assert.ok(w.razem.blad_procent > 0, 'Zerwane odpowiedzi nie mogą dawać zerowego odsetka błędów.');
  assert.ok(w.uwagi.length > 0, 'Wynik z błędami musi nieść jawne ostrzeżenie o interpretacji percentyli.');
  assert.ok(
    w.uwagi.some((u) => u.includes('p95_z_bledami')),
    'Brakuje ostrzeżenia, że percentyli poprawnych nie wolno cytować jako czasu odpowiedzi.',
  );

  // Urwane `/wpisy/*` mają swój powód, a nie „poprawne 200”.
  assert.ok(w.endpointy.anon_wpis, 'Scenariusz anon_wpis nie trafił do wyniku.');
  assert.equal(w.endpointy.anon_wpis.poprawnych, 0, 'Urwana odpowiedź została policzona jako poprawna.');
  assert.ok((w.endpointy.anon_wpis.powody.urwana ?? 0) > 0, 'Brakuje powodu „urwana” w wyniku serii.');

  // Sączony `/tag/*` ma padać na całkowitym deadline, nie kręcić się bez końca.
  assert.ok((w.endpointy.anon_tag?.powody.deadline ?? 0) > 0,
    'Strumień podtrzymujący połączenie nie został ucięty całkowitym deadline’em.');

  // I najważniejsze dla wiarygodności liczb: percentyl policzony z samych
  // poprawnych jest NIŻSZY niż policzony razem z zerwanymi. Gdyby brakujące
  // odpowiedzi znikały bez śladu, ta różnica byłaby niewidoczna.
  assert.ok(w.razem.p95_z_bledami !== null && w.razem.p95 !== null, 'Brak obu percentyli w wyniku.');
  assert.ok(w.razem.p95_z_bledami >= w.razem.p95,
    'Percentyl liczony z błędami nie może być niższy niż liczony z samych poprawnych.');

  assert.equal(stanowisko2.otwartePolaczenia(), 0,
    `Po serii zostało ${stanowisko2.otwartePolaczenia()} otwartych gniazd na stanowisku.`);
  await stanowisko2.zamknij();
  powiedz('seria z urwanymi i sączonymi odpowiedziami domyka się, liczy błędy i sprząta gniazda');
}

if (process.platform === 'win32') {
  /*
   * Windows nie dostarcza SIGINT do procesu potomnego — `child.kill('SIGINT')`
   * ubija go twardo. Sprawdzenie jest więc POMINIĘTE, a nie zaliczone: cichy
   * sukces na platformie, która nie potrafi wykonać próby, byłby fałszywy.
   * Ten sam test wykonuje się na Linuksie (CI i stanowisko pomiarowe).
   */
  process.stdout.write('  ⊘ POMINIĘTE na Windows: przerwanie serii sygnałem'
    + ' (system nie dostarcza SIGINT do procesu potomnego)\n');
} else {
  // Przerwanie testu: Ctrl+C ma zostawić wynik, nie zwłoki.
  const stanowisko3 = await uruchomSerwer();
  const plikManifestu = join(roboczy, 'manifest-przerwanie.json');
  const plikWyniku = join(roboczy, 'seria-przerwana.json');
  writeFileSync(plikManifestu, JSON.stringify(manifestNa(stanowisko3.baza, katalogZdjec)));

  const bieg = await uruchomSerie([
    '--manifest', plikManifestu,
    '--baza', stanowisko3.baza,
    '--nazwa', 'przerwana',
    '--rps', '20',
    '--czas', '120',
    '--zdjecia', katalogZdjec,
    '--wynik', plikWyniku,
    '--bezczynnosc', '1500',
    '--calkowity', '2000',
    '--domkniecie', '500',
  ], { sygnalPo: 1500, limitMs: 40000 });

  assert.equal(bieg.kod, 0, `Przerwana seria zakończyła się kodem ${bieg.kod}. stderr: ${bieg.bledy}`);
  const w = JSON.parse(readFileSync(plikWyniku, 'utf8'));
  assert.equal(w.przerwana, true, 'Przerwana seria musi być oznaczona jako przerwana.');
  assert.ok(w.trwanie_s < 30, `Przerwana seria raportuje ${w.trwanie_s} s zamiast kilku sekund.`);
  assert.equal(w.razem.w_locie_na_koniec, 0, 'Po przerwaniu zostały żądania w locie.');
  assert.ok(w.uwagi.some((u) => u.includes('przerwana')), 'Brak ostrzeżenia, że wynik jest fragmentem.');
  assert.equal(stanowisko3.otwartePolaczenia(), 0, 'Po przerwaniu zostały otwarte gniazda.');
  await stanowisko3.zamknij();
  powiedz('przerwanie serii sygnałem zapisuje oznaczony wynik i zamyka gniazda');
}

// =============================================================================
// 3. Kontrole ujemne — fizyczne, na kopii generatora
// =============================================================================
//
// Każda pozycja: fragment poprawki do wycięcia z KOPII i scenariusz, który
// po tym wycięciu MUSI się zawiesić albo źle policzyć. Jeżeli po zepsuciu
// kodu sprawdzenie nadal przechodzi, znaczy, że niczego nie pilnuje.

process.stdout.write('Kontrole ujemne (fizyczne):\n');

/*
 * Każda pozycja wycina z KOPII generatora jeden fragment poprawki i powtarza
 * DOKŁADNIE to sprawdzenie, które wyżej przeszło. Kontrola zalicza się wtedy,
 * gdy sprawdzenie na zepsutym kodzie OBLEWA — przez zawieszenie albo przez
 * inną wartość. Kontrola, po której test nadal przechodzi, znaczy, że test
 * niczego nie pilnuje.
 */
const USZKODZENIA = [
  {
    nazwa: 'bez rozpoznania odpowiedzi urwanej po nagłówkach',
    // Zdejmujemy oba sygnały zerwanego strumienia. Zostaje `req.on("close")`,
    // czyli zabezpieczenie ostatniej szansy — i właśnie dlatego ta kontrola
    // pokazuje nie zawieszenie, tylko UTRATĘ POWODU: zerwanie przestaje być
    // odróżnialne od zwykłego błędu gniazda.
    ciecia: [
      ["      res.on('aborted', () => zerwij('urwana', 'odpowiedź urwana po nagłówkach'));", ''],
      ["        if (!res.complete) zerwij('urwana', 'połączenie zamknięte przed końcem odpowiedzi');", ''],
    ],
    zadanie: { sciezka: '/urwana', bezczynnoscMs: 5000, calkowityMs: 5000 },
    watchdogMs: 1500,
    sprawdzenie: (w) => {
      assert.ok(w, 'zawieszenie');
      assert.equal(w.powod, 'urwana');
      assert.ok(w.bajty > 0);
    },
  },
  {
    nazwa: 'bez wszystkich czterech sygnałów zerwanego strumienia (stan z 0e5d2707)',
    // Zdejmujemy komplet: `aborted`, `error` strumienia, niepełny `close`
    // i zabezpieczenie na `req`. Dopiero wtedy żądanie wisi tak, jak wisiało
    // na `0e5d2707` — i to jest miara, ile z tej poprawki naprawdę pracuje.
    ciecia: [
      ["      res.on('aborted', () => zerwij('urwana', 'odpowiedź urwana po nagłówkach'));", ''],
      ["      res.on('error', (e) => zerwij('urwana', `błąd strumienia odpowiedzi: ${e.message}`));", ''],
      ["        if (!res.complete) zerwij('urwana', 'połączenie zamknięte przed końcem odpowiedzi');", ''],
      ["    req.on('close', () => zerwij('blad', 'żądanie zamknięte bez odpowiedzi'));", ''],
    ],
    zadanie: { sciezka: '/urwana', bezczynnoscMs: 5000, calkowityMs: 5000 },
    watchdogMs: 1500,
    sprawdzenie: (w) => { assert.ok(w, 'zawieszenie'); },
  },
  {
    nazwa: 'bez całkowitego deadline’u',
    ciecia: [
      ["      () => zerwij('deadline', `przekroczony całkowity limit żądania ${calkowityMs} ms`),", '      () => {},'],
    ],
    zadanie: { sciezka: '/powolne-bez-konca?co=60&bajty=12', bezczynnoscMs: 5000, calkowityMs: 400 },
    watchdogMs: 1500,
    sprawdzenie: (w) => {
      assert.ok(w, 'zawieszenie');
      assert.equal(w.powod, 'deadline');
    },
  },
  {
    nazwa: 'bez anulowania sygnałem',
    ciecia: [
      ["    if (sygnal) sygnal.addEventListener('abort', naAnulowanie, { once: true });", ''],
    ],
    zadanie: { sciezka: '/powolne-bez-konca?co=60', bezczynnoscMs: 60000, calkowityMs: 60000, anuluj: 100 },
    watchdogMs: 1500,
    sprawdzenie: (w) => {
      assert.ok(w, 'zawieszenie');
      assert.equal(w.powod, 'anulowane');
    },
  },
];

const stanowiskoKU = await uruchomSerwer();

for (const [nr, u] of USZKODZENIA.entries()) {
  const kopia = join(roboczy, `generator-uszkodzony-${nr}.mjs`);
  copyFileSync(GENERATOR, kopia);
  let tekst = readFileSync(kopia, 'utf8');
  for (const [szukaj, zamien] of u.ciecia) {
    assert.ok(tekst.includes(szukaj), `Kontrola ujemna „${u.nazwa}”: nie znalazłem fragmentu do usunięcia.`);
    tekst = tekst.replace(szukaj, zamien);
  }
  writeFileSync(kopia, tekst);

  const { zadanie: zadanieZepsute, zamknijAgenta: zamknijZepsuty } = await import(pathToFileURL(kopia).href);
  const przerywacz = new AbortController();
  if (u.zadanie.anuluj) setTimeout(() => przerywacz.abort(), u.zadanie.anuluj);
  let wynik = null;
  await Promise.race([
    zadanieZepsute({
      ...u.zadanie,
      cel: { hostname: '127.0.0.1', port: stanowiskoKU.port },
      sygnal: u.zadanie.anuluj ? przerywacz.signal : null,
    }).then((w) => { wynik = w; }).catch(() => {}),
    new Promise((r) => setTimeout(r, u.watchdogMs)),
  ]);

  let oblalo = false;
  try {
    u.sprawdzenie(wynik);
  } catch {
    oblalo = true;
  }
  assert.ok(oblalo,
    `Kontrola ujemna „${u.nazwa}” NIE zadziałała: sprawdzenie przeszło mimo wyciętej poprawki`
    + ` (wynik: ${JSON.stringify(wynik)}).`);
  zamknijZepsuty();
  rmSync(kopia);
  powiedz(`kontrola ujemna: ${u.nazwa} → sprawdzenie OBLEWA`);
}

await stanowiskoKU.zamknij();

/*
 * Kontrola ujemna dla KSIĘGOWANIA. Psujemy licznik tak, jak psuł go stary kod:
 * do statystyki trafiają wyłącznie odpowiedzi poprawne. Nieudane i brakujące
 * znikają — i właśnie wtedy `blad_procent` spada do zera, choć stanowisko
 * urywa co drugą odpowiedź. To jest ta pomyłka, która „poprawia” percentyle.
 */
{
  const kopia = join(roboczy, 'generator-uszkodzony-ksiegowanie.mjs');
  copyFileSync(GENERATOR, kopia);
  const szukaj = "    dodaj(scenariusz, odp.ms, odp.powod === 'ok' ? (odp.status || 'blad') : odp.powod, odp.status === 200 || odp.status === 302);";
  let tekst = readFileSync(kopia, 'utf8');
  assert.ok(tekst.includes(szukaj), 'Kontrola ujemna księgowania: nie znalazłem wywołania `dodaj`.');
  tekst = tekst.replace(szukaj, "    if (odp.status === 200 || odp.status === 302) dodaj(scenariusz, odp.ms, odp.status, true);");
  writeFileSync(kopia, tekst);

  const stanowisko4 = await uruchomSerwer();
  const plikManifestu = join(roboczy, 'manifest-ksiegowanie.json');
  const plikWyniku = join(roboczy, 'seria-ksiegowanie.json');
  writeFileSync(plikManifestu, JSON.stringify(manifestNa(stanowisko4.baza, katalogZdjec)));

  const bieg = await uruchomSerie([
    '--manifest', plikManifestu,
    '--baza', stanowisko4.baza,
    '--nazwa', 'ksiegowanie',
    '--rps', '40',
    '--czas', '4',
    '--zdjecia', katalogZdjec,
    '--wynik', plikWyniku,
    '--bezczynnosc', '1500',
    '--calkowity', '800',
    '--domkniecie', '1000',
  ], { limitMs: 45000, skrypt: kopia });

  assert.equal(bieg.kod, 0, `Zepsuta seria zakończyła się kodem ${bieg.kod}. stderr: ${bieg.bledy}`);
  const w = JSON.parse(readFileSync(plikWyniku, 'utf8'));
  let oblalo = false;
  try {
    assert.ok(w.razem.nieudanych > 0);
    assert.ok(w.razem.blad_procent > 0);
  } catch {
    oblalo = true;
  }
  assert.ok(oblalo,
    'Kontrola ujemna księgowania NIE zadziałała: po wycięciu liczenia błędów wynik nadal je pokazuje'
    + ` (nieudanych=${w.razem.nieudanych}, blad_procent=${w.razem.blad_procent}).`);
  await stanowisko4.zamknij();
  rmSync(kopia);
  powiedz('kontrola ujemna: liczenie wyłącznie poprawnych odpowiedzi → zerowy odsetek błędów, sprawdzenie OBLEWA');
}

assert.equal(md5(GENERATOR), MD5_GENERATORA_NA_WEJSCIU,
  'Kontrole ujemne zmieniły oryginalny plik generatora — to byłby fałszywy wynik.');

powiedz('oryginalny generator ma tę samą sumę MD5 co przed kontrolami ujemnymi');

rmSync(roboczy, { recursive: true, force: true });
process.stdout.write(`\nZdane sprawdzenia: ${zdane}\n`);
