#!/usr/bin/env node
/*
 * =============================================================================
 *  Generator mieszanego ruchu do testu obciążeniowego — #605
 * =============================================================================
 *
 *  DLACZEGO WŁASNY, A NIE k6
 *  W tym środowisku nie ma k6, wrk, hey ani autocannona, a AGENTS.md §3 zabrania
 *  dokładania platform bez zmierzonej potrzeby. Baseline load581 był robiony k6
 *  na innej maszynie; tutaj wystarczy Node 24 z biblioteką standardową. Cały
 *  przyrząd to JEDEN plik bez zależności — da się go przeczytać i sprawdzić
 *  w kwadrans, co przy pomiarze jest ważniejsze niż wygoda API.
 *
 *  CO ROBI INACZEJ NIŻ load581
 *  load581 puszczał JEDEN rodzaj żądania w pętli, osobno dla każdego scenariusza.
 *  #605 wymaga mieszanki jednoczesnej: anonimowe wejścia, zalogowany feed
 *  i discover, wyszukiwanie, strona przepisu, `/zdjecia/*`, komentarze,
 *  „Ugotowałem" i wgrywanie zdjęć — wszystko naraz, z jednego modelu napływu.
 *
 *  MODEL NAPŁYWU JEST OTWARTY (constant arrival rate), nie „N wątków w pętli".
 *  Zamknięta pętla sama się dławi: gdy serwer zwalnia, generator zwalnia razem
 *  z nim i nasycenie nigdy nie wychodzi na jaw. Tutaj żądania startują według
 *  zegara, a rosnąca liczba żądań w locie JEST objawem nasycenia i jest
 *  raportowana (`w_locie_szczyt`).
 *
 *  KOSZT SAMEGO GENERATORA JEST MIERZONY I RAPORTOWANY (`koszt_generatora`).
 *  Generator chodzi na tej samej maszynie co aplikacja, więc bez tej liczby
 *  wynik jest nieinterpretowalny.
 *
 *  UŻYCIE
 *      node scripts/generator-obciazenia-605.mjs przygotuj --manifest <plik> \
 *          --baza http://127.0.0.1:8605 --haslo <haslo-widzow>
 *      node scripts/generator-obciazenia-605.mjs media --manifest <plik> --ile 24
 *      node scripts/generator-obciazenia-605.mjs zbierz-media --manifest <plik>
 *      node scripts/generator-obciazenia-605.mjs seria --manifest <plik> \
 *          --nazwa r020 --rps 20 --czas 120 --wynik <plik.json>
 *
 *  Manifest zawiera CIASTECZKA SESJI — trzymamy go poza repozytorium.
 */

import http from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { readFileSync, realpathSync, statSync } from 'node:fs';
import { basename } from 'node:path';
import { fileURLToPath } from 'node:url';

// -----------------------------------------------------------------------------
// Argumenty
// -----------------------------------------------------------------------------

const [, , komenda, ...reszta] = process.argv;
const opcje = {};
for (let i = 0; i < reszta.length; i += 2) {
  opcje[reszta[i].replace(/^--/, '')] = reszta[i + 1];
}

const BAZA = opcje.baza ?? 'http://127.0.0.1:8605';
const MANIFEST = opcje.manifest ?? '/home/mateusz/kuking-b605-run/manifest.json';

const adres = new URL(BAZA);
if (adres.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(adres.hostname)) {
  // Bezpiecznik jak w harnessie load581: ten przyrząd nigdy nie dotknie
  // niczego poza własną, lokalną instancją. Produkcji się nie obciąża.
  throw new Error('Generator #605 działa wyłącznie przeciwko lokalnemu http://127.0.0.1');
}

const agent = new http.Agent({ keepAlive: true, maxSockets: 4096, maxFreeSockets: 512 });

/** Domyślny cel — własna, lokalna instancja. Testy przyrządu podają swój. */
const CEL_DOMYSLNY = { hostname: adres.hostname, port: adres.port, agent };

export function zamknijAgenta() {
  agent.destroy();
}

// -----------------------------------------------------------------------------
// Warstwa HTTP — cienka, bo każdy takt generatora to takt zabrany aplikacji
// -----------------------------------------------------------------------------

/*
 * DWA LIMITY, BO TO SĄ DWIE RÓŻNE AWARIE — i pomylenie ich zawiesza pomiar.
 *
 * Poprzednia wersja miała jeden `limitMs` wpięty w `req.setTimeout()`. To jest
 * limit BEZCZYNNOŚCI GNIAZDA, nie limit czasu żądania: serwer, który co 75 ms
 * dosyła dwanaście bajtów, resetuje go w nieskończoność. Zmierzone na
 * `0e5d2707` przeciwko lokalnemu serwerowi scenariuszy: przy `limitMs: 200`
 * żądanie kończyło się poprawnym 200 po 905 ms, a przy strumieniu bez końca
 * nie kończyło się wcale.
 *
 * Druga, gorsza dziura: obietnica rozwiązywała się WYŁĄCZNIE w `res.end`
 * albo w `req.error`. Odpowiedź urwana PO NAGŁÓWKACH (`res.destroy()` po
 * kilku bajtach) nie daje ani jednego, ani drugiego — daje `res.aborted`
 * i `close` z `res.complete === false`. Obietnica nie rozwiązywała się nigdy,
 * a `wLocie` w serii nigdy nie wracało do zera. Pod nasyceniem, czyli dokładnie
 * tam, gdzie ten przyrząd ma pracować, zerwane odpowiedzi są spodziewane.
 *
 * Dlatego teraz:
 *   `bezczynnoscMs` — brak ruchu na gnieździe (to, co mierzył stary `limitMs`),
 *   `calkowityMs`   — TWARDY deadline całego żądania, liczony od `hrtime` startu,
 *                     nie do zresetowania przez nic, co robi serwer,
 *   `sygnal`        — anulowanie z zewnątrz (koniec serii, Ctrl+C).
 *
 * Każde wyjście przechodzi przez `skoncz()`, które rozwiązuje DOKŁADNIE RAZ,
 * i przez `zerwij()`, które niszczy gniazdo — żeby zerwane żądanie nie zostawiło
 * po sobie ani deskryptora, ani niezliczonego wyniku.
 */
export function zadanie({
  sciezka,
  metoda = 'GET',
  ciasteczka = null,
  dane = null,
  naglowki = {},
  bezczynnoscMs = 20000,
  calkowityMs = 30000,
  sygnal = null,
  cel = CEL_DOMYSLNY,
}) {
  if (!['127.0.0.1', 'localhost'].includes(cel.hostname)) {
    throw new Error('Generator #605 działa wyłącznie przeciwko lokalnemu http://127.0.0.1');
  }
  return new Promise((resolve) => {
    const start = process.hrtime.bigint();
    const kawalki = [];
    let bajty = 0;
    let zakonczone = false;
    let zegar = null;
    let req = null;

    const skoncz = (w) => {
      if (zakonczone) return;
      zakonczone = true;
      if (zegar) { clearTimeout(zegar); zegar = null; }
      if (sygnal) sygnal.removeEventListener('abort', naAnulowanie);
      resolve({
        status: 0,
        ms: Number(process.hrtime.bigint() - start) / 1e6,
        bajty,
        tresc: '',
        setCookie: [],
        location: null,
        ...w,
      });
    };

    /*
     * Kolejność jest istotna: najpierw zapisujemy wynik, dopiero potem niszczymy
     * gniazdo. Odwrotnie `req.destroy()` wywołałoby własne `error`, które
     * zameldowałoby „socket hang up" zamiast prawdziwego powodu zerwania.
     */
    const zerwij = (powod, komunikat) => {
      if (zakonczone) return;
      skoncz({ powod, blad: komunikat });
      try { req?.destroy(); } catch { /* gniazdo już zamknięte */ }
    };

    function naAnulowanie() {
      zerwij('anulowane', 'żądanie anulowane przez przyrząd');
    }

    if (sygnal?.aborted) {
      skoncz({ powod: 'anulowane', blad: 'żądanie anulowane przed wysłaniem' });
      return;
    }
    if (sygnal) sygnal.addEventListener('abort', naAnulowanie, { once: true });

    const konfiguracja = {
      host: cel.hostname,
      port: cel.port,
      path: sciezka,
      method: metoda,
      agent: cel.agent ?? agent,
      headers: {
        'accept-encoding': 'identity',
        accept: 'text/html,application/xhtml+xml,image/webp',
        'user-agent': 'kuking-b605-generator',
        ...naglowki,
      },
    };
    if (ciasteczka) konfiguracja.headers.cookie = ciasteczka;

    zegar = setTimeout(
      () => zerwij('deadline', `przekroczony całkowity limit żądania ${calkowityMs} ms`),
      calkowityMs,
    );

    req = http.request(konfiguracja, (res) => {
      res.on('data', (c) => {
        bajty += c.length;
        // Treść zbieramy tylko do 2 MB i tylko po to, żeby wyłuskać token
        // albo adresy; przy zdjęciach trzymanie całości byłoby kosztem
        // generatora dopisanym do wyniku aplikacji.
        if (bajty <= 2_000_000) kawalki.push(c);
      });
      res.on('aborted', () => zerwij('urwana', 'odpowiedź urwana po nagłówkach'));
      res.on('error', (e) => zerwij('urwana', `błąd strumienia odpowiedzi: ${e.message}`));
      // `close` bez `res.complete` to jedyny sygnał, jaki zostaje, gdy druga
      // strona zamknie gniazdo w środku body — `aborted` nie zawsze pada.
      res.on('close', () => {
        if (!res.complete) zerwij('urwana', 'połączenie zamknięte przed końcem odpowiedzi');
      });
      res.on('end', () => skoncz({
        status: res.statusCode,
        tresc: Buffer.concat(kawalki).toString('utf8'),
        setCookie: res.headers['set-cookie'] ?? [],
        location: res.headers.location ?? null,
        powod: 'ok',
      }));
    });
    req.setTimeout(bezczynnoscMs, () => zerwij('bezczynnosc', `brak ruchu na gnieździe przez ${bezczynnoscMs} ms`));
    req.on('error', (e) => zerwij('blad', e.message));
    // Ostatnia deska ratunku: gniazdo zamknięte, zanim w ogóle przyszła odpowiedź.
    req.on('close', () => zerwij('blad', 'żądanie zamknięte bez odpowiedzi'));
    if (dane) req.write(dane);
    req.end();
  });
}

/** Sklejarka ciasteczek — minimalna, bo sesja to dwa ciastka i nic więcej. */
function dolozCiasteczka(obecne, setCookie) {
  const mapa = new Map();
  for (const para of (obecne ?? '').split('; ').filter(Boolean)) {
    const i = para.indexOf('=');
    mapa.set(para.slice(0, i), para.slice(i + 1));
  }
  for (const wiersz of setCookie) {
    const pierwsza = wiersz.split(';')[0];
    const i = pierwsza.indexOf('=');
    if (i > 0) mapa.set(pierwsza.slice(0, i), pierwsza.slice(i + 1));
  }
  return [...mapa].map(([k, v]) => `${k}=${v}`).join('; ');
}

const tokenZeStrony = (html) => html.match(/name="_token"\s+value="([^"]+)"/)?.[1] ?? null;

function mediana(tablica) {
  if (!tablica.length) return null;
  const s = [...tablica].sort((a, b) => a - b);
  return Math.round(s[Math.floor(s.length / 2)] * 10) / 10;
}

function percentyl(posortowane, p) {
  if (!posortowane.length) return null;
  const i = Math.min(posortowane.length - 1, Math.floor((p / 100) * posortowane.length));
  return Math.round(posortowane[i] * 10) / 10;
}

// -----------------------------------------------------------------------------
// przygotuj — logowanie widzów i odkrycie prawdziwych celów
// -----------------------------------------------------------------------------

async function przygotuj() {
  const haslo = opcje.haslo;
  if (!haslo) throw new Error('Podaj --haslo (wypisał je scripts/dane-obciazenia-605.php).');
  const ilu = Number(opcje.widzowie ?? 60);
  /*
   * ODSTĘP MIĘDZY LOGOWANIAMI — 13 sekund, i to nie jest ostrożność.
   * `POST /login` ma limit `5,1` z `config/kuking.php`, liczony PO ADRESIE
   * (niezalogowany nie ma id użytkownika). Generator stoi na jednym adresie,
   * więc szybsze logowanie kończy się serią 429 i pustym manifestem.
   * Limitu NIE obchodzimy — płacimy go raz, w fazie przygotowania.
   */
  const odstep = Number(opcje.odstep_logowania ?? 13000);

  const sesje = [];
  const czasyLogowania = [];
  for (let i = 0; i < ilu; i++) {
    if (i > 0) await new Promise((r) => setTimeout(r, odstep));
    const email = `b605-widz-${i}@example.test`;
    const formularz = await zadanie({ sciezka: '/login' });
    if (formularz.status !== 200) throw new Error(`/login dał ${formularz.status}`);
    let ciasteczka = dolozCiasteczka('', formularz.setCookie);
    const token = tokenZeStrony(formularz.tresc);
    if (!token) throw new Error('Brak _token na /login');

    const dane = new URLSearchParams({ _token: token, login: email, password: haslo }).toString();
    const start = Date.now();
    const odp = await zadanie({
      sciezka: '/login',
      metoda: 'POST',
      ciasteczka,
      dane,
      naglowki: { 'content-type': 'application/x-www-form-urlencoded', 'content-length': Buffer.byteLength(dane) },
    });
    czasyLogowania.push(Date.now() - start);
    if (odp.status !== 302) throw new Error(`Logowanie ${email} dało ${odp.status}, oczekiwano 302`);
    ciasteczka = dolozCiasteczka(ciasteczka, odp.setCookie);

    const dom = await zadanie({ sciezka: '/home', ciasteczka });
    if (dom.status !== 200) throw new Error(`/home po zalogowaniu dało ${dom.status}`);
    ciasteczka = dolozCiasteczka(ciasteczka, dom.setCookie);

    const zeszyt = await zadanie({ sciezka: '/zeszyt', ciasteczka });
    const idZeszytu = zeszyt.tresc.match(/\/zeszyt\/([0-9a-f-]{36})/)?.[1] ?? null;

    sesje.push({ email, ciasteczka, token: tokenZeStrony(dom.tresc), zeszyt: idZeszytu });
    if (i % 20 === 0) process.stderr.write(`  zalogowano ${i}/${ilu}\n`);
  }

  // Cele bierzemy Z APLIKACJI, nie z bazy: sitemap i listy publiczne oddają
  // dokładnie te adresy, które naprawdę istnieją i naprawdę są publiczne.
  // Dzięki temu żadna seria nie mierzy przypadkiem trasy zwracającej 404.
  // Sitemapa bywa duża i wolna; deadline jest hojny, ale SKOŃCZONY.
  const mapa = await zadanie({ sciezka: '/sitemap.xml', bezczynnoscMs: 30000, calkowityMs: 180000 });
  const sciezki = [...mapa.tresc.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname);

  const strona = await zadanie({ sciezka: '/odkryj' });
  const zOdkryj = [...strona.tresc.matchAll(/(\/wpisy\/[0-9a-f-]{36})/g)].map((m) => m[1]);

  // Sitemapa nie wymienia stron tagów, a `/tag/{tag}` jest jedną z cięższych
  // tras publicznych (kolaże, liczniki) — bierzemy je z `/tagi`.
  const listaTagow = await zadanie({ sciezka: '/tagi' });
  const zTagow = [...new Set([...listaTagow.tresc.matchAll(/(\/tag\/[a-z0-9-]+)/g)].map((m) => m[1]))];

  const manifest = {
    baza: BAZA,
    utworzony: new Date().toISOString(),
    logowanie: { n: czasyLogowania.length, mediana_ms: mediana(czasyLogowania), max_ms: Math.max(...czasyLogowania) },
    sesje,
    cele: {
      przepisy: sciezki.filter((p) => p.startsWith('/przepisy/')).slice(0, 3000),
      wpisy: [...new Set([...sciezki.filter((p) => p.startsWith('/wpisy/')), ...zOdkryj])].slice(0, 3000),
      tagi: [...new Set([...sciezki.filter((p) => p.startsWith('/tag/')), ...zTagow])].slice(0, 500),
      profile: sciezki.filter((p) => p.startsWith('/@')).slice(0, 500),
      media: [],
    },
  };

  await writeFile(MANIFEST, JSON.stringify(manifest, null, 1));
  console.log(JSON.stringify({
    manifest: MANIFEST,
    sesji: sesje.length,
    logowanie: manifest.logowanie,
    cele: Object.fromEntries(Object.entries(manifest.cele).map(([k, v]) => [k, v.length])),
  }, null, 2));
}

// -----------------------------------------------------------------------------
// media — prawdziwe wgrania 12/24/48 Mpx ścieżką produktową
// -----------------------------------------------------------------------------

function multipart(pola, plik) {
  const granica = '----kuking605' + Math.random().toString(36).slice(2);
  const czesci = [];
  for (const [k, v] of Object.entries(pola)) {
    czesci.push(Buffer.from(`--${granica}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`));
  }
  czesci.push(Buffer.from(
    `--${granica}\r\nContent-Disposition: form-data; name="photos[]"; filename="${basename(plik)}"\r\n`
    + 'Content-Type: image/jpeg\r\n\r\n',
  ));
  czesci.push(readFileSync(plik));
  czesci.push(Buffer.from(`\r\n--${granica}--\r\n`));
  return { dane: Buffer.concat(czesci), typ: `multipart/form-data; boundary=${granica}` };
}

async function media() {
  const manifest = JSON.parse(await readFile(MANIFEST, 'utf8'));
  const katalog = opcje.zdjecia ?? '/home/mateusz/kuking-b605-run/zdjecia';
  /*
   * `--rozmiar` służy pomiarowi z `docs/MEDIA_PIPELINE.md`: żeby sprawdzić
   * szczyt RSS i czas dla JEDNEGO rozmiaru, zdjęcia tego rozmiaru muszą iść
   * pojedynczo i oddzielnie od pozostałych. Domyślnie (`wszystkie`) mieszamy
   * trzy rozmiary po kolei — to jest rozgrzewka przed serią, nie pomiar.
   */
  const rozmiary = (opcje.rozmiar && opcje.rozmiar !== 'wszystkie') ? [opcje.rozmiar] : ['12mpx', '24mpx', '48mpx'];
  const pliki = rozmiary.map((n) => `${katalog}/kuking-b605-${n}.jpg`);
  const ile = Number(opcje.ile ?? 24);
  const odstepMs = Number(opcje.odstep ?? 0);

  const wyniki = [];
  for (let i = 0; i < ile; i++) {
    const sesja = manifest.sesje[i % manifest.sesje.length];
    const plik = pliki[i % pliki.length];
    const { dane, typ } = multipart(
      { _token: sesja.token, visibility: 'public', body: `Zdjęcie pomiarowe #605 nr ${i}` },
      plik,
    );
    const odp = await zadanie({
      sciezka: '/dodaj/zdjecie',
      metoda: 'POST',
      ciasteczka: sesja.ciasteczka,
      dane,
      naglowki: { 'content-type': typ, 'content-length': dane.length },
      // Przetwarzanie 48 Mpx trwa, więc bezczynność gniazda musi być hojna —
      // ale całkowity deadline i tak zamyka wgranie, które utknęło.
      bezczynnoscMs: 180000,
      calkowityMs: 300000,
    });
    wyniki.push({
      nr: i,
      plik: basename(plik),
      bajty: statSync(plik).size,
      status: odp.status,
      ms: Math.round(odp.ms),
      cel: odp.location ?? null,
      blad: odp.blad ?? null,
    });
    process.stderr.write(`  wgranie ${i + 1}/${ile} ${basename(plik)} → ${odp.status} w ${Math.round(odp.ms)} ms\n`);
    if (odstepMs) await new Promise((r) => setTimeout(r, odstepMs));
  }

  console.log(JSON.stringify({ wgrania: wyniki }, null, 1));
}

/**
 * zbierz-media — po opróżnieniu kolejki wyciąga z utworzonych wpisów prawdziwe
 * adresy `/zdjecia/{uuid}/{wariant}` i dopisuje je do manifestu. Bez tego kroku
 * seria mierzyłaby na tej trasie 404, a nie oddawanie pliku.
 */
async function zbierzMedia() {
  const manifest = JSON.parse(await readFile(MANIFEST, 'utf8'));
  const sesja = manifest.sesje[0];
  const strona = await zadanie({ sciezka: '/odkryj', ciasteczka: sesja.ciasteczka });
  const wpisy = [...new Set([...strona.tresc.matchAll(/(\/wpisy\/[0-9a-f-]{36})/g)].map((m) => m[1]))];

  const media = new Set();
  for (const wpis of wpisy.slice(0, 80)) {
    const html = await zadanie({ sciezka: wpis, ciasteczka: sesja.ciasteczka });
    for (const m of html.tresc.matchAll(/(\/zdjecia\/[0-9a-f-]{36}\/[a-z]+)/g)) media.add(m[1]);
  }

  manifest.cele.media = [...media];
  await writeFile(MANIFEST, JSON.stringify(manifest, null, 1));
  console.log(JSON.stringify({ media: manifest.cele.media.length, przyklad: manifest.cele.media.slice(0, 5) }, null, 1));
}

// -----------------------------------------------------------------------------
// seria — mieszanka ruchu przy zadanym napływie
// -----------------------------------------------------------------------------

/*
 * WAGI MIESZANKI. To jedyna arbitralna liczba w całym przyrządzie i dlatego
 * stoi tu jawnie, a nie w środku pętli. Układ odzwierciedla listę z #605
 * („anonimowe wejścia SEO, zalogowany discover/following feed, wyszukiwanie,
 * otwieranie przepisu, /zdjecia/*, komentarze/Ugotowałem, upload") z proporcjami
 * typowymi dla serwisu treściowego: przewaga odczytów, zdjęcia jako druga co do
 * wielkości grupa, zapisy jako kilka procent.
 * Zmieniasz wagi — zmieniasz pomiar; zapisz to wtedy w opisie serii.
 */
const MIESZANKA = [
  ['anon_landing', 8],
  ['anon_przepis', 14],
  ['anon_wpis', 7],
  ['anon_tag', 4],
  ['anon_profil', 2],
  ['zal_feed', 14],
  ['zal_feed_str2', 4],
  ['zal_discover', 4],
  ['zal_zeszyt', 3],
  ['szukaj', 8],
  ['zdjecie', 25],
  ['komentarz', 4],
  ['ugotowalem', 2],
  ['upload', 1],
];

const HASLA = ['pierogi', 'rosol', 'sernik', 'bigos', 'placki', 'zurek', 'szarlotka', 'kluski'];

function losujScenariusz(suma) {
  let prog = Math.random() * suma;
  for (const [nazwa, waga] of MIESZANKA) {
    prog -= waga;
    if (prog <= 0) return nazwa;
  }
  return MIESZANKA[0][0];
}

async function seria() {
  const manifest = JSON.parse(await readFile(MANIFEST, 'utf8'));
  const rps = Number(opcje.rps ?? 10);
  const sekundy = Number(opcje.czas ?? 60);
  const nazwa = opcje.nazwa ?? `r${rps}`;
  const wynikPlik = opcje.wynik ?? `/home/mateusz/kuking-b605-run/seria-${nazwa}.json`;
  const maksWLocie = Number(opcje.maks_w_locie ?? 3000);
  // Ile czekamy po zakończeniu napływu, zanim zerwiemy to, co zostało w locie.
  const domkniecieMs = Number(opcje.domkniecie ?? 30000);
  /*
   * Limity pojedynczego żądania w serii. `bezczynnosc` to brak ruchu na
   * gnieździe, `calkowity` to twardy deadline całego żądania. Obie liczby lądują
   * w wyniku, bo każde żądanie zerwane deadline'em jest błędem tego pomiaru
   * i czytający musi wiedzieć, od jakiego progu.
   */
  const bezczynnoscSerii = Number(opcje.bezczynnosc ?? 20000);
  const calkowitySerii = Number(opcje.calkowity ?? 30000);
  const katalog = opcje.zdjecia ?? '/home/mateusz/kuking-b605-run/zdjecia';
  // Do uploadu w serii świadomie najmniejszy plik: 48 Mpx przy każdym wgraniu
  // zamieniłby test mieszany w test jednego zadania w tle.
  const plikUpload = `${katalog}/kuking-b605-12mpx.jpg`;

  const cele = manifest.cele;
  if (!cele.media.length) {
    process.stderr.write('UWAGA: manifest nie ma adresów /zdjecia/* — ta część mieszanki nie zostanie zmierzona.\n');
  }

  const suma = MIESZANKA.reduce((a, [, w]) => a + w, 0);
  const stat = new Map();
  /*
   * KSIĘGOWANIE. `ms` to czasy odpowiedzi POPRAWNYCH, `msWszystkie` — wszystkich
   * doprowadzonych do końca, razem z błędami i zerwaniami. Percentyl liczony
   * wyłącznie z `ms` jest prawdziwy tylko przy zerowym `blad_procent`: gdy serwis
   * zaczyna zrywać albo przekraczać deadline, najdłuższe żądania wypadają
   * z próbki i p95 SPADA, choć serwis działa gorzej. Dlatego raportujemy oba,
   * a mianownik błędu obejmuje też żądania nigdy niewysłane.
   */
  const dodaj = (klucz, ms, status, ok) => {
    let s = stat.get(klucz);
    if (!s) { s = { n: 0, ok: 0, ms: [], msWszystkie: [], statusy: {}, powody: {} }; stat.set(klucz, s); }
    s.n += 1;
    s.msWszystkie.push(ms);
    if (ok) { s.ok += 1; s.ms.push(ms); }
    s.statusy[status] = (s.statusy[status] ?? 0) + 1;
  };
  const dodajPowod = (klucz, powod) => {
    const s = stat.get(klucz);
    if (s) s.powody[powod] = (s.powody[powod] ?? 0) + 1;
  };
  /*
   * Żądania, które w ogóle nie poszły — przez limit żądań w locie albo przez
   * brak celu w manifeście. Stara wersja gubiła je bez śladu: nie było ich ani
   * w liczniku żądań, ani w błędach, więc brakująca odpowiedź POPRAWIAŁA wynik.
   */
  let porzucone = 0;
  const pominiete = new Map();

  let wLocie = 0;
  let wLocieSzczyt = 0;
  const probkiWLocie = [];
  // Jeden sygnał dla całej serii: zamyka wszystko, co zostało w locie.
  const przerywacz = new AbortController();
  let anulowanePoSerii = 0;
  let przerwanaRecznie = false;
  // Wyjątki samego przyrządu (np. brak pliku do wgrania) — osobno od błędów
  // serwisu, żeby usterki narzędzia nie wyglądały jak degradacja portalu.
  const bledyPrzyrzadu = [];
  const cpuStart = process.cpuUsage();
  const start = Date.now();
  const koniec = start + sekundy * 1000;
  let nr = 0;

  const jedno = async () => {
    const i = nr++;
    const sesja = manifest.sesje[i % manifest.sesje.length];
    const scenariusz = losujScenariusz(suma);
    // Pusta lista celów znaczy, że manifest powstał na niekompletnym zbiorze —
    // lepiej pominąć scenariusz niż wysłać żądanie na `undefined`.
    const wybierz = (t) => (t.length ? t[(i * 7919) % t.length] : null);

    let cfg;
    switch (scenariusz) {
      case 'anon_landing': cfg = { sciezka: '/' }; break;
      case 'anon_przepis': cfg = { sciezka: wybierz(cele.przepisy) }; break;
      case 'anon_wpis': cfg = { sciezka: wybierz(cele.wpisy) }; break;
      case 'anon_tag': cfg = { sciezka: wybierz(cele.tagi) }; break;
      case 'anon_profil': cfg = { sciezka: wybierz(cele.profile) }; break;
      case 'zal_feed': cfg = { sciezka: '/home', ciasteczka: sesja.ciasteczka }; break;
      case 'zal_feed_str2': cfg = { sciezka: '/home?page=2', ciasteczka: sesja.ciasteczka }; break;
      case 'zal_discover': cfg = { sciezka: '/odkryj', ciasteczka: sesja.ciasteczka }; break;
      case 'zal_zeszyt': cfg = { sciezka: sesja.zeszyt ? `/zeszyt/${sesja.zeszyt}` : '/zeszyt', ciasteczka: sesja.ciasteczka }; break;
      /*
       * WYSZUKIWANIE I `/zdjecia/*` IDĄ Z SESJĄ, CHOĆ OBIE TRASY SĄ PUBLICZNE.
       *
       * Powód jest pomiarowy, nie produktowy: `search` ma limit 60/min,
       * a `zdjecie` 600/min — dla ruchu anonimowego liczone PO ADRESIE.
       * Cały generator stoi na jednym adresie, więc bez sesji obie te trasy
       * zaczęłyby oddawać 429 już przy 1 i 10 żądaniach na sekundę i pomiar
       * dotyczyłby wyłącznie własnej konfiguracji limitów. Z sesją limit jest
       * liczony po id użytkownika, czyli 60 kont × limit.
       *
       * DO ZAPISANIA W RAPORCIE: ten test nie mówi, ile serwis odda zdjęć
       * jednemu adresowi — tam pierwszy zadziała throttle `zdjecie`.
       */
      case 'szukaj': cfg = { sciezka: `/szukaj?q=${encodeURIComponent(HASLA[i % HASLA.length])}`, ciasteczka: sesja.ciasteczka }; break;
      case 'zdjecie': cfg = cele.media.length ? { sciezka: wybierz(cele.media), ciasteczka: sesja.ciasteczka } : null; break;
      case 'komentarz': {
        const przepis = wybierz(cele.przepisy);
        if (!przepis) { cfg = null; break; }
        const dane = new URLSearchParams({ _token: sesja.token, body: `Komentarz z serii ${nazwa} nr ${i}` }).toString();
        cfg = {
          sciezka: `${przepis}/komentarz`, metoda: 'POST', ciasteczka: sesja.ciasteczka, dane,
          naglowki: { 'content-type': 'application/x-www-form-urlencoded', 'content-length': Buffer.byteLength(dane) },
        };
        break;
      }
      case 'ugotowalem': {
        const przepis = wybierz(cele.przepisy);
        if (!przepis) { cfg = null; break; }
        const dane = new URLSearchParams({ _token: sesja.token, note: `Ugotowane w serii ${nazwa} nr ${i}`, would_make_again: '1' }).toString();
        cfg = {
          sciezka: `${przepis}/ugotowalem`, metoda: 'POST', ciasteczka: sesja.ciasteczka, dane,
          naglowki: { 'content-type': 'application/x-www-form-urlencoded', 'content-length': Buffer.byteLength(dane) },
        };
        break;
      }
      case 'upload': {
        const { dane, typ } = multipart({ _token: sesja.token, visibility: 'public', body: `Wpis z serii ${nazwa} nr ${i}` }, plikUpload);
        cfg = {
          sciezka: '/dodaj/zdjecie', metoda: 'POST', ciasteczka: sesja.ciasteczka, dane,
          bezczynnoscMs: 60000, calkowityMs: 90000,
          naglowki: { 'content-type': typ, 'content-length': dane.length },
        };
        break;
      }
      default: cfg = { sciezka: '/' };
    }
    if (!cfg || !cfg.sciezka) {
      pominiete.set(scenariusz, (pominiete.get(scenariusz) ?? 0) + 1);
      return;
    }
    // Limity serii są jawne i zapisane w wyniku. Scenariusz może je podnieść
    // (wgranie zdjęcia), ale żaden nie może zostać bez całkowitego deadline'u.
    cfg = { bezczynnoscMs: bezczynnoscSerii, calkowityMs: calkowitySerii, ...cfg };

    wLocie += 1;
    if (wLocie > wLocieSzczyt) wLocieSzczyt = wLocie;
    let odp;
    try {
      odp = await zadanie({ ...cfg, sygnal: przerywacz.signal });
    } finally {
      // `finally`, bo licznik żądań w locie musi wrócić do zera także wtedy,
      // gdy `zadanie()` rzuci — inaczej seria domyka się w nieskończoność.
      wLocie -= 1;
    }
    if (odp.powod === 'anulowane') anulowanePoSerii += 1;

    // Poprawna odpowiedź to 200 dla odczytów i 302 dla zapisów (przekierowanie
    // po zapisie). 429 NIE jest awarią serwera, tylko zadziałaniem limitu —
    // ale w `blad_procent` i tak jest błędem, bo nie jest odpowiedzią na pytanie
    // „ile serwis obsłużył". Rozbicie na `statusy` pokazuje, ile z błędów to 429.
    dodaj(scenariusz, odp.ms, odp.powod === 'ok' ? (odp.status || 'blad') : odp.powod, odp.status === 200 || odp.status === 302);
    dodajPowod(scenariusz, odp.powod ?? 'blad');
  };

  /*
   * PRZERWANIE Z KLAWIATURY. Ctrl+C w starej wersji zabijał proces w środku
   * serii i zostawiał pomiar bez pliku wyniku oraz z otwartymi gniazdami.
   * Teraz pierwszy sygnał kończy napływ i anuluje to, co w locie; wynik
   * powstaje, oznaczony `przerwana: true`. Drugi sygnał zabija natychmiast.
   */
  let naSygnal = null;
  const odepnijSygnaly = () => {
    if (!naSygnal) return;
    for (const s of ['SIGINT', 'SIGTERM']) process.off(s, naSygnal);
    naSygnal = null;
  };

  // Zegar napływu: tik co 5 ms, ułamki żądań kumulowane w `dlug`.
  await new Promise((resolve) => {
    let dlug = 0;
    let poprzedni = Date.now();
    let zakonczony = false;
    const zakoncz = () => {
      if (zakonczony) return;
      zakonczony = true;
      clearInterval(tik);
      resolve();
    };
    naSygnal = () => {
      if (przerwanaRecznie) { process.exit(130); }
      przerwanaRecznie = true;
      process.stderr.write('\nPrzerwano — kończę napływ i anuluję żądania w locie. Wynik zostanie zapisany.\n');
      zakoncz();
    };
    for (const s of ['SIGINT', 'SIGTERM']) process.on(s, naSygnal);

    const tik = setInterval(() => {
      const teraz = Date.now();
      dlug += ((teraz - poprzedni) / 1000) * rps;
      poprzedni = teraz;
      while (dlug >= 1) {
        dlug -= 1;
        if (wLocie >= maksWLocie) { porzucone += 1; continue; }
        // Bez `catch` wyjątek z przygotowania żądania (np. brak pliku do
        // wgrania) byłby nieobsłużoną odrzuconą obietnicą i zabiłby serię.
        jedno().catch((e) => {
          bledyPrzyrzadu.push(String(e?.message ?? e));
        });
      }
      probkiWLocie.push(wLocie);
      if (teraz >= koniec) zakoncz();
    }, 5);
  });

  /*
   * DOMKNIĘCIE JEST OGRANICZONE I ZAWSZE SIĘ KOŃCZY.
   * Najpierw łaska: czekamy `domkniecieMs` na żądania, które już poszły.
   * Potem sygnał anulujący zrywa wszystko, co zostało — bo seria, która czeka
   * na serwer w nieskończoność, nie jest pomiarem, tylko zawieszeniem.
   * Pętla po anulowaniu też ma limit, żeby żadna ścieżka nie została bez wyjścia.
   */
  const domkniecie = Date.now();
  // Czas NAPŁYWU, osobno od czasu całego biegu. Przepustowość liczy się z tego
  // pierwszego: domykanie potrafi trwać dziesiątki sekund i zaniżałoby wynik.
  const trwanieNaplywu = (domkniecie - start) / 1000;
  while (wLocie > 0 && !przerwanaRecznie && Date.now() - domkniecie < domkniecieMs) {
    await new Promise((r) => setTimeout(r, 50));
  }
  if (wLocie > 0) {
    process.stderr.write(`Domknięcie: ${wLocie} żądań nadal w locie — anuluję.\n`);
    przerywacz.abort();
    const twarde = Date.now();
    while (wLocie > 0 && Date.now() - twarde < 5000) {
      await new Promise((r) => setTimeout(r, 20));
    }
  }
  odepnijSygnaly();
  const wLocieNaKoniec = wLocie;

  const trwanie = (Date.now() - start) / 1000;
  const cpu = process.cpuUsage(cpuStart);
  const zasoby = process.resourceUsage();

  const endpointy = {};
  let wszystkieN = 0;
  let wszystkieOk = 0;
  const wszystkieMs = [];
  const wszystkieMsZBledami = [];
  for (const [klucz, s] of [...stat].sort()) {
    const posortowane = [...s.ms].sort((a, b) => a - b);
    const zBledami = [...s.msWszystkie].sort((a, b) => a - b);
    const pominietychTu = pominiete.get(klucz) ?? 0;
    wszystkieN += s.n;
    wszystkieOk += s.ok;
    wszystkieMs.push(...s.ms);
    wszystkieMsZBledami.push(...s.msWszystkie);
    endpointy[klucz] = {
      zadan: s.n,
      poprawnych: s.ok,
      pominietych_brak_celu: pominietychTu,
      blad_procent: Math.round(((s.n - s.ok) / s.n) * 1000) / 10,
      p50: percentyl(posortowane, 50),
      p95: percentyl(posortowane, 95),
      p99: percentyl(posortowane, 99),
      // Ten sam percentyl policzony RAZEM z odpowiedziami nieudanymi. Gdy
      // różni się od `p95`, znaczy, że najdłuższe żądania wypadły z próbki
      // poprawnych — i samego `p95` nie wolno podawać jako czasu odpowiedzi.
      p95_z_bledami: percentyl(zBledami, 95),
      max: posortowane.length ? Math.round(posortowane[posortowane.length - 1]) : null,
      statusy: s.statusy,
      powody: s.powody,
    };
  }
  for (const [klucz, ile] of pominiete) {
    if (!endpointy[klucz]) {
      endpointy[klucz] = {
        zadan: 0, poprawnych: 0, pominietych_brak_celu: ile, blad_procent: null,
        p50: null, p95: null, p99: null, p95_z_bledami: null, max: null, statusy: {}, powody: {},
      };
    }
  }
  wszystkieMs.sort((a, b) => a - b);
  wszystkieMsZBledami.sort((a, b) => a - b);

  const pominietychRazem = [...pominiete.values()].reduce((a, b) => a + b, 0);
  /*
   * MIANOWNIK BŁĘDU. Żądanie porzucone przez limit żądań w locie nigdy nie
   * dostało odpowiedzi — i właśnie dlatego MUSI być w mianowniku. Gdyby go tam
   * nie było, nasycony serwis, przy którym generator porzuca połowę napływu,
   * pokazywałby niższy odsetek błędów niż serwis zdrowy. Pominięcia z braku celu
   * są usterką PRZYRZĄDU (niekompletny manifest), nie serwisu — stoją osobno,
   * ale zawsze są widoczne w wyniku.
   */
  const mianownik = wszystkieN + porzucone;
  const nieudanych = mianownik - wszystkieOk;

  const uwagi = [];
  if (wszystkieOk < mianownik) {
    uwagi.push('p50/p95/p99 liczone są z odpowiedzi POPRAWNYCH; przy niezerowym blad_procent'
      + ' nie wolno ich cytować jako czasu odpowiedzi serwisu — porównaj z p95_z_bledami.');
  }
  if (porzucone > 0) {
    uwagi.push(`Porzucono ${porzucone} żądań przez limit maks_w_locie=${maksWLocie}:`
      + ' napływ nie został zrealizowany w całości, a przepustowość jest zaniżona względem zadanego RPS.');
  }
  if (pominietychRazem > 0) {
    uwagi.push(`Pominięto ${pominietychRazem} żądań z braku celów w manifeście —`
      + ' to ograniczenie przyrządu (niekompletny manifest), nie wynik serwisu.');
  }
  if (anulowanePoSerii > 0) {
    uwagi.push(`Anulowano ${anulowanePoSerii} żądań przy domykaniu serii po ${domkniecieMs} ms —`
      + ' policzone jako błędy, bo odpowiedzi nie było.');
  }
  if (wLocieNaKoniec > 0) {
    uwagi.push(`UWAGA: ${wLocieNaKoniec} żądań nie zakończyło się nawet po anulowaniu —`
      + ' wynik jest niepełny, zgłoś to jako usterkę przyrządu.');
  }
  if (bledyPrzyrzadu.length) {
    uwagi.push(`Przyrząd zgłosił ${bledyPrzyrzadu.length} własnych wyjątków — patrz bledy_przyrzadu.`);
  }
  if (przerwanaRecznie) {
    uwagi.push('Seria przerwana sygnałem — wynik jest fragmentem, nie pomiarem zadanego czasu.');
  }

  const wynik = {
    seria: nazwa,
    baza: BAZA,
    start: new Date(start).toISOString(),
    zadany_rps: rps,
    czas_s: sekundy,
    trwanie_s: Math.round(trwanie * 10) / 10,
    trwanie_naplywu_s: Math.round(trwanieNaplywu * 10) / 10,
    przerwana: przerwanaRecznie,
    razem: {
      zadan_wyslanych: wszystkieN,
      poprawnych: wszystkieOk,
      nieudanych: nieudanych,
      porzuconych_przez_limit: porzucone,
      pominietych_brak_celu: pominietychRazem,
      anulowanych_przy_domykaniu: anulowanePoSerii,
      w_locie_na_koniec: wLocieNaKoniec,
      przepustowosc_rps: Math.round((wszystkieOk / trwanieNaplywu) * 100) / 100,
      blad_procent: mianownik ? Math.round((nieudanych / mianownik) * 1000) / 10 : 0,
      p50: percentyl(wszystkieMs, 50),
      p95: percentyl(wszystkieMs, 95),
      p99: percentyl(wszystkieMs, 99),
      p95_z_bledami: percentyl(wszystkieMsZBledami, 95),
      p99_z_bledami: percentyl(wszystkieMsZBledami, 99),
    },
    uwagi,
    bledy_przyrzadu: bledyPrzyrzadu.slice(0, 20),
    limity_ms: {
      bezczynnosc_zadania: bezczynnoscSerii,
      calkowity_zadania: calkowitySerii,
      domkniecie: domkniecieMs,
      maks_w_locie: maksWLocie,
    },
    w_locie_szczyt: wLocieSzczyt,
    w_locie_mediana: mediana(probkiWLocie),
    koszt_generatora: {
      cpu_user_s: Math.round(cpu.user / 1e4) / 100,
      cpu_system_s: Math.round(cpu.system / 1e4) / 100,
      // Licznik to CPU CAŁEGO biegu, mianownik — sam czas napływu. Zaokrągla
      // to własny koszt generatora W GÓRĘ, i tak ma być: zaniżony koszt
      // przyrządu jest groźniejszy dla wniosków niż zawyżony.
      cpu_rdzenie_srednio: Math.round(((cpu.user + cpu.system) / 1e6 / trwanieNaplywu) * 1000) / 1000,
      maxrss_mb: Math.round(zasoby.maxRSS / 1024),
    },
    endpointy,
    mieszanka: Object.fromEntries(MIESZANKA),
  };

  await writeFile(wynikPlik, JSON.stringify(wynik, null, 1));
  console.log(JSON.stringify(wynik, null, 1));
}

// -----------------------------------------------------------------------------

const komendy = { przygotuj, media, 'zbierz-media': zbierzMedia, seria };

/*
 * Plik jest i narzędziem, i modułem: `scripts/przyrzad-605.test.mjs` importuje
 * z niego `zadanie()` i liczy na nim regresje. Dlatego rozdział poleceń dzieje
 * się TYLKO przy uruchomieniu wprost — import nie może nic wystartować ani
 * zakończyć procesu.
 */
const uruchomionyWprost = (() => {
  if (!process.argv[1]) return false;
  try {
    return realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
})();

if (uruchomionyWprost) {
  if (!komendy[komenda]) {
    console.error('Komendy: przygotuj | media | zbierz-media | seria');
    process.exit(2);
  }
  await komendy[komenda]();
  /*
   * Stare `process.exit(0)` kończyło proces NIEZALEŻNIE od tego, co jeszcze
   * żyło — i tym samym ukrywało każdy wyciek gniazd czy zegarów. Teraz
   * zwalniamy agenta i pozwalamy procesowi zamknąć się samemu; jeżeli po
   * dwóch sekundach nadal żyje, mówimy to głośno i dopiero wtedy wychodzimy.
   * Sprzątanie przyrządu jest częścią pomiaru, nie szczegółem.
   */
  zamknijAgenta();
  process.exitCode = 0;
  setTimeout(() => {
    console.error('UWAGA: proces nie zakończył się sam — zostały aktywne uchwyty przyrządu.');
    process.exit(4);
  }, 2000).unref();
}
