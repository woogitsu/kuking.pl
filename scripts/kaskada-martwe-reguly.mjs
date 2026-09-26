/*
 * =============================================================================
 *  Kuking.pl — STRAŻNIK REGUŁ PRZYKRYTYCH PRZEZ PÓŹNIEJSZĄ WARSTWĘ
 * =============================================================================
 *
 *  CO TEN STRAŻNIK WYKRYWA
 *  Regułę CSS zadeklarowaną w warstwie WCZEŚNIEJSZEJ i CAŁKOWICIE przykrytą
 *  przez warstwę PÓŹNIEJSZĄ na tej samej własności i tym samym elemencie.
 *  `resources/css/app.css` linia 1 ustawia porządek:
 *
 *      @layer theme, base, components, marka, utilities;
 *
 *  Warstwa późniejsza bije wcześniejszą NIEZALEŻNIE od szczegółowości
 *  selektora i NIEZALEŻNIE od zapytania medialnego. Skutek: w repozytorium
 *  żyją reguły z komentarzami uzasadniającymi konkretne wartości, których
 *  przeglądarka nigdy nie widzi. Komentarz opisuje wtedy stan nieistniejący,
 *  a następny człowiek czyta go jak prawdę.
 *
 *  ══════════════════════════════════════════════════════════════════════
 *   DLACZEGO TO NIE JEST KOLEJNY STRAŻNIK CZYTAJĄCY ARKUSZ
 *  ══════════════════════════════════════════════════════════════════════
 *
 *  Strażnik, który czyta TEKST arkusza, odpowiada na pytanie „co jest
 *  napisane". To jest pytanie o stan repozytorium, nie o stan przeglądarki.
 *  Taki strażnik świeci na zielono, opisując układ, którego nikt nie widzi —
 *  bo w pliku faktycznie stoi to, czego pilnuje. Dokładnie to przydarzyło się
 *  strażnikowi liczb z D-122 i D-125.
 *
 *  Tutaj TEKST ARKUSZA SŁUŻY WYŁĄCZNIE DO ZAWĘŻENIA listy kandydatów, bo
 *  przepytywanie kaskady o każdą regułę byłoby wolne. ROZSTRZYGA POMIAR:
 *
 *      1. usuwamy deklarację z żywej reguły (CSSOM, na wyrenderowanej stronie),
 *      2. porównujemy `getComputedStyle` KAŻDEGO pasującego elementu przed/po,
 *      3. przywracamy deklarację.
 *
 *  Brak różnicy na wszystkich elementach i wszystkich mierzonych szerokościach
 *  znaczy, że ta deklaracja NIE ZMIENIA NIC — czyli jest martwa. To jest
 *  pytanie o WYNIK KASKADY, a nie o zawartość pliku, i nie da się go oszukać
 *  ani komentarzem, ani przeniesieniem reguły w inne miejsce pliku.
 *
 *  ══════════════════════════════════════════════════════════════════════
 *   DLACZEGO TO NIE JEST LISTA TRZECH ZNANYCH PRZYPADKÓW
 *  ══════════════════════════════════════════════════════════════════════
 *
 *  Nigdzie w tym pliku nie ma nazwy warstwy ani nazwy arkusza wpisanej na
 *  sztywno jako element algorytmu:
 *
 *  - KOLEJNOŚĆ WARSTW czytamy z samego arkusza (`CSSLayerStatementRule`),
 *    więc czwarta i piąta warstwa wejdą do pomiaru same z siebie;
 *  - REGUŁY obchodzimy rekurencyjnie przez `@layer`, `@media` i `@supports`,
 *    więc piąty arkusz wchodzi razem z `@import`;
 *  - SZEROKOŚCI to 320 i 1280 (wymagane) PLUS każdy próg `min-width`
 *    znaleziony w arkuszu, bo reguła spod progu, którego nie zmierzyliśmy,
 *    wyglądałaby na martwą.
 *
 *  Jedyna lista nazw w tym pliku to WYJĄTKI — i każdy ma przy sobie powód.
 *
 *  ══════════════════════════════════════════════════════════════════════
 *   CZEGO TEN STRAŻNIK NIE MIERZY (granice narzędzia, nie wynik pozytywny)
 *  ══════════════════════════════════════════════════════════════════════
 *
 *  - Selektory ze stanem interakcji (`:hover`, `:focus`, `:active`, `:target`)
 *    — nie da się ich rozstrzygnąć bez odegrania gestu. Są RAPORTOWANE jako
 *    `niezmierzone`, nigdy po cichu pomijane.
 *  - Selektory, do których na mierzonych stronach nie pasuje ŻADEN element —
 *    to jest „brak nosiciela TUTAJ", a nie dowód martwoty. Też `niezmierzone`.
 *  - Nasze `data-text-scale` z profilu: NIE jest wariantem pomiaru. Skaluje
 *    tokeny tekstu, nie korzeń dokumentu, więc progów zapytań medialnych nie
 *    rusza (potwierdzone pomiarem w docs/design/evidence/kaskada223/).
 *
 *  UŻYCIE
 *    node scripts/kaskada-martwe-reguly.mjs
 *    node scripts/kaskada-martwe-reguly.mjs --szybko    # sam motyw jasny
 *  Wynik: storage/kaskada-martwe-reguly.json + podsumowanie na konsoli.
 *  Kod wyjścia 1, gdy znaleziono martwą regułę spoza listy wyjątków.
 */

import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';

/* Osobna baza pomiarowa. Ten skrypt NIE migruje i NIE zasiewa — czyta tylko
   strony — ale wskazanie `kuking` i tak byłoby wskazaniem cudzej pracy.
   Port 5432 jest zakazany świadomie (AGENTS.md §10). */
const BAZA_DOMYSLNA = 'kuking_audyt_kaskada';
const PORT_BAZY_ZAKAZANY = '5432';

const SZYBKO = process.argv.includes('--szybko');

/* `--tylko <fragment>` zawęża RAPORT do reguł, których selektor zawiera ten
   fragment. Pomiar biegnie bez zmian — zawężenie dotyczy wyłącznie tego, co
   wchodzi do werdyktu.

   PO CO TO JEST: repozytorium ma dziś duży zmierzony zaległy zbiór deklaracji
   przykrytych (liczba w raporcie). Dopóki właściciel nie rozstrzygnie, co z nim
   zrobić — a usuwanie martwych reguł jest OSOBNĄ decyzją, bo zmienia zachowanie
   na wypadek zniknięcia warstwy — strażnik nie może być bramką na całym
   repozytorium. Może natomiast pilnować WSKAZANEGO obszaru, i w takim
   zawężeniu robi się też uczciwa kontrola ujemna.

   To NIE JEST lista wyjątków tylnymi drzwiami: zawężenie podaje się jawnie
   w wierszu poleceń, widać je w raporcie i nie da się go włączyć przypadkiem. */
const iTylko = process.argv.indexOf('--tylko');
const TYLKO = iTylko !== -1 ? process.argv[iTylko + 1] : null;

/* Wymagane zleceniem. Reszta progów dochodzi z arkusza, w `zbierzProgi`. */
const SZEROKOSCI_WYMAGANE = [320, 1280];
const MOTYWY = SZYBKO ? ['light'] : ['light', 'dark'];

/* Strony gościa. Przepis dokładamy po slugu z bazy — `/odkryj` i `/` nie
   wystawiają gościowi linku do przepisu, więc szukanie go w DOM-ie dawało
   pustkę (sprawdzone). */
const SCIEZKI = [
  { nazwa: 'strona-glowna', adres: '/' },
  { nazwa: 'odkryj', adres: '/odkryj' },
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'tagi', adres: '/tagi' },
  { nazwa: 'szukaj', adres: '/szukaj?sekcja=przepisy' },
  { nazwa: 'przepis', adres: '/przepisy/rosol-babci-zofii' },
];

/* ═══════════════════════════════════════════════════════════════════════
 *  WYJĄTKI — PRZYKRYCIA ZAMIERZONE
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  To NIE JEST niema lista. Każdy wpis ma `powod`, bo wyjątek bez powodu
 *  jest tylko wyciszeniem strażnika, a wyciszony strażnik nie jest
 *  strażnikiem. Wpis obowiązuje na PARĘ (selektor, własność) — nie na cały
 *  selektor — żeby wyjątek na jedną własność nie uciszał wszystkich
 *  pozostałych na tym samym elemencie.
 *
 *  Dopisując tu cokolwiek, odpowiedz w `powod` na jedno pytanie: DLACZEGO
 *  martwa reguła ma zostać w repozytorium, zamiast zniknąć. Sama informacja
 *  „wiemy o tym" nie jest powodem.
 */
const WYJATKI = [
  {
    selektor: '.hero-kolaz-blok',
    wlasnosc: 'display',
    powod:
      'Opisane i rozstrzygnięte osobno. `strony-publiczne.css` (warstwa `components`) '
      + 'chowa kolaż i pokazuje go od 64rem; `marka-ekrany.css` (warstwa `marka`) '
      + 'pokazuje go bezwarunkowo i to ona wygrywa. Skutek jest ZAMIERZONY — kolaż '
      + 'ma być widoczny — a reguła spod spodu zostaje, bo jej usunięcie jest osobną '
      + 'decyzją o zachowaniu przy zniknięciu warstwy `marka`.',
  },
];

const wolnyPort = () => new Promise((resolve, reject) => {
  const g = createServer();
  g.on('error', reject);
  g.listen(0, '127.0.0.1', () => { const { port } = g.address(); g.close(() => resolve(port)); });
});

function sprawdzBaze() {
  const baza = process.env.DB_DATABASE || BAZA_DOMYSLNA;
  if ((process.env.DB_PORT || '') === PORT_BAZY_ZAKAZANY) {
    throw new Error(`DB_PORT=${PORT_BAZY_ZAKAZANY} jest zakazany dla pomiarów — użyj izolowanej instancji (AGENTS.md §10).`);
  }
  return baza;
}

async function podniesSerwer(baza) {
  const bledy = [];
  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, DB_DATABASE: baza },
    });
    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));
    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });
    for (let i = 0; i < 60 && umarl === null; i++) {
      try { const o = await fetch(`${adres}/health`); if (o.ok) return { adres, zamknij: () => proces.kill('SIGTERM') }; } catch { /* wstaje */ }
      await new Promise((r) => setTimeout(r, 500));
    }
    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: ${umarl !== null ? `kod ${umarl}` : 'brak /health przez 30 s'}\n${dziennik.join('')}`);
  }
  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/* ═══════════════════════════════════════════════════════════════════════
 *  POMIAR — cały kod poniżej biegnie W PRZEGLĄDARCE, na wyrenderowanej stronie
 * ═══════════════════════════════════════════════════════════════════════ */

/* Zbiera progi `min-width` WPROST z arkusza. Dzięki temu reguła schowana pod
   progiem, o którym autor tego skryptu nie wiedział, i tak zostanie zmierzona
   po właściwej stronie progu — zamiast wyglądać na martwą. */
const ZBIERZ_PROGI = () => {
  const progi = new Set();
  const korzenPx = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
  const dodaj = (liczba, jednostka) => {
    const v = parseFloat(liczba);
    if (Number.isFinite(v)) progi.add(Math.ceil(jednostka === 'px' ? v : v * korzenPx));
  };
  const chodz = (reguly) => {
    for (const r of reguly) {
      const warunek = r.conditionText || (r.media && r.media.mediaText) || '';
      if (warunek) {
        /* DWIE SKŁADNIE, obie realnie występują w zbudowanym arkuszu.
           Tailwind 4 / Lightning CSS emituje ZAKRESOWĄ `(width >= 48rem)`,
           a nie `(min-width: 48rem)`. Strażnik szukający tylko `min-width`
           znajdował ZERO progów i wyglądał wtedy na zielony — bo mierzył
           wyłącznie 320 i 1280, a każdą regułę spod progu brał za żywą. */
        for (const m of warunek.matchAll(/min-width:\s*([\d.]+)(px|rem|em)/g)) dodaj(m[1], m[2]);
        for (const m of warunek.matchAll(/width\s*>=?\s*([\d.]+)(px|rem|em)/g)) dodaj(m[1], m[2]);
      }
      if (r.cssRules) { try { chodz(r.cssRules); } catch { /* arkusz z innego źródła */ } }
    }
  };
  for (const a of document.styleSheets) { try { chodz(a.cssRules); } catch { /* CORS */ } }
  return [...progi].sort((x, y) => x - y);
};

/*
 *  GŁÓWNY POMIAR.
 *
 *  Zwraca listę deklaracji, które NIE ZMIENIAJĄ NIC na tej stronie przy tej
 *  szerokości i tym motywie — wraz z informacją, kto je przykrywa.
 */
const POMIAR = () => {
  const wynik = { martwe: [], niezmierzone: [], zbadane: [], zNosicielem: [], bledyPrzywrocenia: [], regulSprawdzonych: 0, deklaracjiSprawdzonych: 0 };

  /* --- 1. KOLEJNOŚĆ WARSTW TAK, JAK USTALA JĄ PRZEGLĄDARKA ---------------
     Warstwa zajmuje miejsce w kaskadzie przy PIERWSZYM wystąpieniu — obojętne,
     czy przez `@layer a, b, c;` (CSSLayerStatementRule, `nameList`), czy przez
     blok `@layer a { … }` (CSSLayerBlockRule, `name`). Liczymy OBA i w
     kolejności dokumentu.

     DLACZEGO NIE SAM `@layer a, b, c;`: w ŹRÓDLE taka instrukcja stoi
     (app.css linia 1), ale w ZBUDOWANYM arkuszu JEJ NIE MA — Tailwind 4 /
     Lightning CSS ją zjada i zostawia same bloki. Strażnik czytający tylko
     instrukcję dostawał pustą kolejność, wszystkim reguł przypisywał ten sam
     numer warstwy, nie znajdował ANI JEDNEGO kandydata i meldował zieleń.
     To była dokładnie ta choroba, którą ten plik ma leczyć — złapana na
     samym sobie. Kolejność zmierzona w przeglądarce różni się zresztą od
     źródłowej: dochodzi wewnętrzna warstwa Tailwinda `properties`, PRZED
     `theme`. Gdyby czytać źródło, nie byłoby o niej wiadomo. */
  const kolejnosc = [];
  const JAK_WARSTWA = /^CSSLayer(Block|Statement)Rule$/;   // NIE @keyframes, NIE @property
  const zbierzKolejnosc = (reguly) => {
    for (const r of reguly) {
      if (r.nameList) {
        for (const n of r.nameList) if (!kolejnosc.includes(n)) kolejnosc.push(n);
      } else if (JAK_WARSTWA.test(r.constructor.name) && r.name && !kolejnosc.includes(r.name)) {
        kolejnosc.push(r.name);
      }
      if (r.cssRules) { try { zbierzKolejnosc(r.cssRules); } catch { /* CORS */ } }
    }
  };
  for (const a of document.styleSheets) { try { zbierzKolejnosc(a.cssRules); } catch { /* CORS */ } }

  /* Warstwa nienazwana w `@layer {}` oraz kod spoza warstw są PÓŹNIEJSZE niż
     każda warstwa nazwana — tak mówi specyfikacja kaskady. Dajemy im numer
     większy od wszystkich nazwanych. */
  const numerWarstwy = (sciezka) => {
    if (!sciezka.length) return kolejnosc.length + 1;      // poza warstwami
    const i = kolejnosc.indexOf(sciezka[0]);
    return i === -1 ? kolejnosc.length + 1 : i;
  };

  /* --- 2. OBCHÓD WSZYSTKICH REGUŁ ---------------------------------------
     Rekurencyjnie, przez @layer / @media / @supports / @container. */
  const reguly = [];
  const chodz = (lista, warstwa, warunki) => {
    for (const r of lista) {
      const mojaWarstwa = r.name !== undefined && r.cssRules ? [...warstwa, r.name] : warstwa;
      const mojeWarunki = r.conditionText ? [...warunki, r.conditionText] : warunki;
      if (r.selectorText && r.style) {
        reguly.push({ regula: r, selektor: r.selectorText, warstwa: mojaWarstwa, warunki: mojeWarunki });
      }
      if (r.cssRules) { try { chodz(r.cssRules, mojaWarstwa, mojeWarunki); } catch { /* CORS */ } }
    }
  };
  for (const a of document.styleSheets) { try { chodz(a.cssRules, [], []); } catch { /* CORS */ } }

  /* --- 3. ROZWINIĘCIE SKRÓTÓW DO WŁASNOŚCI SKŁADOWYCH --------------------
     `padding` przykryte przez `padding-left` to przykrycie CZĘŚCIOWE, nie
     całkowite. Bez rozwinięcia skrótów przesiew albo by to zgubił, albo
     zgłaszał na wyrost. Rozwijamy tak, jak rozwija sama przeglądarka. */
  const probna = document.createElement('div');
  const pamiecSkrotow = new Map();
  const rozwin = (wlasnosc, wartosc) => {
    if (wlasnosc.startsWith('--')) return [wlasnosc];
    if (pamiecSkrotow.has(wlasnosc)) return pamiecSkrotow.get(wlasnosc);
    probna.style.cssText = '';
    try { probna.style.setProperty(wlasnosc, wartosc); } catch { /* nieznana */ }
    let lista = [...probna.style];
    if (!lista.length) lista = [wlasnosc];
    pamiecSkrotow.set(wlasnosc, lista);
    return lista;
  };

  /* --- 4. PASUJĄCE ELEMENTY ---------------------------------------------
     Pseudoelementy odcinamy (mierzymy element nosiciela). Selektory ze
     STANEM interakcji zwracamy jako niezmierzone — bez odegrania gestu nie
     da się ich rozstrzygnąć, a ciche pominięcie byłoby fałszywą zielenią. */
  const STAN = /:(hover|focus|focus-within|focus-visible|active|target|visited)\b/;
  const elementyDla = (selektor) => {
    const czesci = selektor.split(',').map((s) => s.trim()).filter(Boolean);
    if (czesci.some((c) => STAN.test(c))) return { stan: true, elementy: [] };
    const bezPseudo = czesci.map((c) => c.replace(/::[a-zA-Z-]+(\([^)]*\))?/g, '')).filter(Boolean).join(', ');
    if (!bezPseudo) return { stan: true, elementy: [] };
    try { return { stan: false, elementy: [...document.querySelectorAll(bezPseudo)] }; } catch { return { stan: true, elementy: [] }; }
  };

  /* --- 5. PRZESIEW (tani, tekstowy — TYLKO ZAWĘŻA, NIE ROZSTRZYGA) -------
     Kandydatem jest deklaracja z warstwy W1, dla której istnieje deklaracja
     tej samej własności składowej w warstwie PÓŹNIEJSZEJ, na elemencie,
     do którego pasują OBIE reguły. */
  /* GRANICA ZASIĘGU. Tailwind trzyma w warstwie `properties` reguły typu
     `*, :before, :after`, które pasują do KAŻDEGO elementu strony. Zbudowanie
     dla nich mapy pokrycia to kilkadziesiąt tysięcy wpisów na regułę i pomiar
     przestaje się kończyć. Takie reguły RAPORTUJEMY jako niezmierzone —
     nigdy nie pomijamy po cichu, bo cisza wyglądałaby jak wynik pozytywny. */
  const LIMIT_ELEMENTOW = 300;

  const wzbogacone = reguly.map((w) => {
    const m = elementyDla(w.selektor);
    if (!m.stan && m.elementy.length > LIMIT_ELEMENTOW) {
      return { ...w, stan: false, masowa: true, elementy: [], deklaracje: [], numer: numerWarstwy(w.warstwa) };
    }
    /* DEKLARACJE BIERZEMY Z `cssText`, A NIE Z `[...style]`.

       Iteracja po `CSSStyleDeclaration` zwraca WŁASNOŚCI SKŁADOWE: jedno
       autorskie `border: 2px solid buttontext` rozsypuje się na siedemnaście
       pozycji (`border-top-width`, `border-image-slice`, …). Raport robił się
       wtedy siedemnastokrotnie dłuższy, niż jest problemów, a część pozycji
       miała PUSTĄ wartość, bo `getPropertyValue` nie potrafi odtworzyć
       składowej skrótu zapisanego przez `var()`. To są artefakty odczytu,
       nie znaleziska.

       `cssText` serializuje regułę tak, jak ją zapisał autor — po jednej
       pozycji na deklarację. Werdykt i tak zapada na WSZYSTKICH składowych
       (`skladowe`), więc przykrycie częściowe nadal nie przejdzie za pełne. */
    const deklaracje = [];
    for (const kawalek of w.regula.style.cssText.split(';')) {
      const i = kawalek.indexOf(':');
      if (i < 1) continue;
      const wlasnosc = kawalek.slice(0, i).trim();
      if (!wlasnosc) continue;
      const wartosc = w.regula.style.getPropertyValue(wlasnosc);
      deklaracje.push({
        wlasnosc,
        wartosc,
        waznosc: w.regula.style.getPropertyPriority(wlasnosc),
        skladowe: rozwin(wlasnosc, wartosc || kawalek.slice(i + 1).trim()),
      });
    }
    return { ...w, ...m, deklaracje, numer: numerWarstwy(w.warstwa) };
  });

  /* Mapa: element → lista (numer warstwy, własność składowa), żeby przesiew
     był liniowy zamiast kwadratowy po regułach. */
  const pokrycie = new Map();
  for (const w of wzbogacone) {
    for (const el of w.elementy) {
      let wpis = pokrycie.get(el);
      if (!wpis) { wpis = []; pokrycie.set(el, wpis); }
      for (const d of w.deklaracje) for (const s of d.skladowe) wpis.push({ numer: w.numer, skladowa: s, zrodlo: w });
    }
  }

  /* --- 6. WERDYKT — POMIAR NA ŻYWEJ KASKADZIE ---------------------------
     Usuwamy deklarację, porównujemy `getComputedStyle` pasujących elementów
     przed i po, przywracamy. Porównujemy WSZYSTKIE własności składowe tej
     deklaracji; dla własności własnych (`--x`) porównujemy cały styl, bo
     `var()` może je przenieść w dowolne miejsce. */
  const stylOf = (el, wlasnosci) => {
    const s = getComputedStyle(el);
    return wlasnosci.map((p) => s.getPropertyValue(p)).join('');
  };
  const WSZYSTKIE = (() => { const s = getComputedStyle(document.body); return [...s]; })();

  for (const w of wzbogacone) {
    if (w.masowa) {
      wynik.niezmierzone.push({ selektor: w.selektor.slice(0, 60), warstwa: w.warstwa.join('.'), powod: `zasięg masowy (>${LIMIT_ELEMENTOW} elementów)` });
      continue;
    }
    if (w.stan) {
      wynik.niezmierzone.push({ selektor: w.selektor, warstwa: w.warstwa.join('.'), powod: 'selektor ze stanem interakcji' });
      continue;
    }
    if (!w.elementy.length) {
      wynik.niezmierzone.push({ selektor: w.selektor, warstwa: w.warstwa.join('.'), powod: 'brak pasującego elementu na tej stronie' });
      continue;
    }
    wynik.regulSprawdzonych++;
    /* Selektory, dla których NA TEJ STRONIE stał realny nosiciel. Służą
       wyłącznie samokontroli zawężenia (`--tylko`) — patrz `main`. */
    wynik.zNosicielem.push(w.selektor);
    for (const d of w.deklaracje) {
      // PRZESIEW: czy ktokolwiek późniejszy dotyka tej samej składowej
      // na którymkolwiek z naszych elementów?
      let przykrywacz = null;
      for (const el of w.elementy) {
        for (const p of (pokrycie.get(el) || [])) {
          if (p.numer > w.numer && d.skladowe.includes(p.skladowa)) { przykrywacz = p; break; }
        }
        if (przykrywacz) break;
      }
      if (!przykrywacz) continue;
      wynik.deklaracjiSprawdzonych++;
      const klucz = `${w.warstwa.join('.') || '(poza warstwami)'} | ${w.selektor} | ${d.wlasnosc}`;
      wynik.zbadane.push(klucz);

      /* WERDYKT: zdejmij i porównaj.

         PRZYWRACAMY PRZEZ `cssText`, NIE PRZEZ `setProperty`. Odtwarzanie
         deklaracji z `getPropertyValue` gubi skróty: dla `padding` o różnych
         składowych ta funkcja zwraca PUSTY łańcuch, więc „przywrócenie"
         zostawiałoby regułę trwale okaleczoną, a każdy następny pomiar na tej
         stronie biegłby po zepsutym arkuszu. `cssText` jest wierną kopią. */
      const porownywane = d.wlasnosc.startsWith('--') ? WSZYSTKIE : d.skladowe;
      const zapis = w.regula.style.cssText;
      const przed = w.elementy.map((el) => stylOf(el, porownywane));
      w.regula.style.removeProperty(d.wlasnosc);
      const po = w.elementy.map((el) => stylOf(el, porownywane));
      w.regula.style.cssText = zapis;
      if (w.regula.style.cssText !== zapis) {
        wynik.bledyPrzywrocenia.push({ selektor: w.selektor, wlasnosc: d.wlasnosc });
      }

      const zmienilo = przed.some((v, i) => v !== po[i]);
      if (!zmienilo) {
        wynik.martwe.push({
          selektor: w.selektor,
          wlasnosc: d.wlasnosc,
          wartosc: d.wartosc.trim().slice(0, 80),
          warstwa: w.warstwa.join('.') || '(poza warstwami)',
          warunki: w.warunki,
          przykrywaWarstwa: przykrywacz.zrodlo.warstwa.join('.') || '(poza warstwami)',
          przykrywaSelektor: przykrywacz.zrodlo.selektor,
          przykrywaWartosc: (przykrywacz.zrodlo.regula.style.getPropertyValue(przykrywacz.skladowa) || '').trim().slice(0, 80),
          elementow: w.elementy.length,
        });
      }
    }
  }
  wynik.kolejnoscWarstw = kolejnosc;
  return wynik;
};

/* ═══════════════════════════════════════════════════════════════════════ */

async function main() {
  const baza = sprawdzBaze();
  console.log(`Baza pomiarowa: ${baza} na ${process.env.DB_HOST || '?'}:${process.env.DB_PORT || '?'}\n`);
  const serwer = await podniesSerwer(baza);
  const przegladarka = await chromium.launch();

  /* Klucz → znalezisko. Martwa jest tylko ta deklaracja, która nie zmieniła
     NIC w KAŻDEJ mierzonej konfiguracji. Wystarczy jedna szerokość albo jeden
     motyw, w którym coś zmienia, żeby reguła była żywa. */
  const wszedzieMartwe = new Map();
  const zbadane = new Map();          // klucz → ile razy deklaracja była W OGÓLE badana
  const niezmierzone = new Map();
  let bledyPrzywrocenia = 0;
  let konfiguracji = 0;
  let kolejnoscWarstw = [];
  let deklaracjiSprawdzonych = 0;
  let regulSprawdzonych = 0;
  /* Selektory, pod którymi na którejkolwiek mierzonej stronie stał realny
     nosiciel. Potrzebne WYŁĄCZNIE do samokontroli zawężenia `--tylko`. */
  const selektoryZNosicielem = new Set();

  try {
    // Progi z arkusza — raz, na dowolnej stronie.
    const k0 = await przegladarka.newContext({ viewport: { width: 1280, height: 900 } });
    const s0 = await k0.newPage();
    await s0.goto(`${serwer.adres}/`, { waitUntil: 'networkidle' });
    const progi = await s0.evaluate(ZBIERZ_PROGI);
    await k0.close();

    /* Mierzymy POWYŻEJ każdego progu (próg+1). Strony poniżej progu nie trzeba
       dokładać osobno: 320 px leży poniżej każdego progu w tym arkuszu, więc
       „stan spod progu" i tak jest zmierzony. Bez tego reguła schowana pod
       `@media (width >= 768px)` wyglądałaby na martwą tylko dlatego, że nigdy
       nie weszliśmy na jej stronę progu. */
    const szerokosci = [...new Set([
      ...SZEROKOSCI_WYMAGANE,
      ...progi.map((p) => p + 1),
    ])].sort((a, b) => a - b);
    console.log(`Progi szerokości z arkusza: ${progi.join(', ') || '(brak)'}`);
    console.log(`Mierzone szerokości: ${szerokosci.join(', ')}`);
    console.log(`Motywy: ${MOTYWY.join(', ')}\n`);

    for (const motyw of MOTYWY) {
      for (const szer of szerokosci) {
        const ctx = await przegladarka.newContext({ viewport: { width: szer, height: 900 }, colorScheme: motyw });
        for (const sciezka of SCIEZKI) {
          const strona = await ctx.newPage();
          const odp = await strona.goto(serwer.adres + sciezka.adres, { waitUntil: 'networkidle' });
          if (!odp || !odp.ok()) {
            console.log(`  (pominięto) ${sciezka.nazwa} @${szer}/${motyw}: HTTP ${odp ? odp.status() : '—'}`);
            await strona.close();
            continue;
          }
          const zegar = Date.now();
          const p = await strona.evaluate(POMIAR);
          konfiguracji++;
          console.log(`  ${`${sciezka.nazwa}/${szer}/${motyw}`.padEnd(30)} reguł:${String(p.regulSprawdzonych).padStart(5)} deklaracji:${String(p.deklaracjiSprawdzonych).padStart(5)} martwych:${String(p.martwe.length).padStart(3)}  ${Date.now() - zegar} ms`);
          kolejnoscWarstw = p.kolejnoscWarstw;
          deklaracjiSprawdzonych += p.deklaracjiSprawdzonych;
          regulSprawdzonych += p.regulSprawdzonych;
          const gdzie = `${sciezka.nazwa}/${szer}/${motyw}`;
          for (const k of p.zbadane) zbadane.set(k, (zbadane.get(k) || 0) + 1);
          for (const s of p.zNosicielem) selektoryZNosicielem.add(s);
          for (const m of p.martwe) {
            const klucz = `${m.warstwa} | ${m.selektor} | ${m.wlasnosc}`;
            if (!wszedzieMartwe.has(klucz)) wszedzieMartwe.set(klucz, { ...m, gdzie: [] });
            wszedzieMartwe.get(klucz).gdzie.push(gdzie);
          }
          bledyPrzywrocenia += p.bledyPrzywrocenia.length;
          for (const n of p.niezmierzone) {
            const klucz = `${n.warstwa} | ${n.selektor}`;
            if (!niezmierzone.has(klucz)) niezmierzone.set(klucz, { ...n, ile: 0 });
            niezmierzone.get(klucz).ile++;
          }
          await strona.close();
        }
        await ctx.close();
      }
    }

  } finally {
    await przegladarka.close();
    serwer.zamknij();
  }

  /* Wyjątki odcinamy PO pomiarze, nie przed — żeby raport pokazywał, że
     zostały zmierzone i uznane za zamierzone, a nie że ich nie widzieliśmy. */
  const pasuje = (m, w) => m.selektor.trim() === w.selektor.trim() && m.wlasnosc === w.wlasnosc;

  /* ─── CO ZNACZY „MARTWA" ───────────────────────────────────────────────
     Deklaracja przykryta na JEDNEJ stronie przy JEDNEJ szerokości to zwykły
     CSS, nie usterka: tak właśnie działa nadpisywanie i po to są warstwy.
     Reset z `base` nadpisany przez komponent jest przykryty i ma być
     przykryty.

     Usterką jest dopiero deklaracja przykryta W KAŻDEJ konfiguracji, w której
     dało się ją zbadać — czyli taka, która NIGDZIE nic nie zmienia. To
     dopiero znaczy, że komentarz przy niej opisuje stan nieistniejący.

     Bez tego rozróżnienia strażnik zgłaszał ~250 „martwych" reguł na stronę,
     w większości zwykłych nadpisań, i nie nadawał się na bramkę. */
  const wszystkie = [...wszedzieMartwe.values()].filter((m) => {
    if (TYLKO && !m.selektor.includes(TYLKO)) return false;
    const klucz = `${m.warstwa} | ${m.selektor} | ${m.wlasnosc}`;
    const ileBadano = zbadane.get(klucz) || 0;
    m.martwaW = m.gdzie.length;
    m.badanaW = ileBadano;
    return ileBadano > 0 && m.gdzie.length === ileBadano;
  });
  const zamierzone = wszystkie.filter((m) => WYJATKI.some((w) => pasuje(m, w)));
  const nowe = wszystkie.filter((m) => !WYJATKI.some((w) => pasuje(m, w)));

  mkdirSync('storage', { recursive: true });
  writeFileSync('storage/kaskada-martwe-reguly.json', JSON.stringify({
    data: new Date().toISOString(),
    kolejnoscWarstw,
    konfiguracji,
    martweRegul: new Set(nowe.map((m) => `${m.warstwa} | ${m.selektor}`)).size,
    martwe: nowe,
    zamierzone,
    wyjatki: WYJATKI,
    niezmierzone: [...niezmierzone.values()],
  }, null, 2));

  console.log(`Kolejność warstw zmierzona w przeglądarce: ${kolejnoscWarstw.join(' < ')} < (kod poza warstwami)`);
  console.log(`Konfiguracji zmierzonych: ${konfiguracji}`);
  if (TYLKO) console.log(`ZAWĘŻENIE RAPORTU: tylko selektory zawierające "${TYLKO}" (pomiar biegł na całym arkuszu)`);
  console.log(`Reguł z nosicielem: ${regulSprawdzonych}. Deklaracji przepytanych kaskadowo: ${deklaracjiSprawdzonych}\n`);

  /* ─── SAMOKONTROLA ─────────────────────────────────────────────────────
     docs/PULAPKI_TESTOW.md §5: narzędzie może zameldować sukces, nie robiąc
     nic. Ten strażnik PRZEŻYŁ TO NA SOBIE — przy pierwszym uruchomieniu
     czytał kolejność warstw z instrukcji `@layer a, b, c;`, której zbudowany
     arkusz nie zawiera. Dostawał pustą kolejność, nie znajdował żadnego
     kandydata i wypisywał „✓ żadna reguła nie jest przykryta". Zieleń była
     prawdziwa składniowo i bezwartościowa merytorycznie.

     Dlatego brak warstw i brak kandydatów to BŁĄD PRZYRZĄDU (kod 2), a nie
     wynik pozytywny. Milczenie nie jest dowodem. */
  if (konfiguracji === 0) {
    console.error('\nBŁĄD PRZYRZĄDU: nie zmierzono ANI JEDNEJ konfiguracji. To nie jest wynik pozytywny.');
    process.exit(2);
  }
  if (kolejnoscWarstw.length === 0) {
    console.error('\nBŁĄD PRZYRZĄDU: nie wykryto ŻADNEJ warstwy CSS. Bez kolejności warstw ten strażnik');
    console.error('nie ma czego porównywać i jego zieleń nic nie znaczy. Sprawdź, czy zbudowany arkusz');
    console.error('(public/build/assets/app-*.css) nadal zawiera `@layer`.');
    process.exit(2);
  }
  if (bledyPrzywrocenia > 0) {
    console.error(`\nBŁĄD PRZYRZĄDU: ${bledyPrzywrocenia} deklaracji NIE WRÓCIŁO do stanu sprzed pomiaru.`);
    console.error('Pomiar biegł po arkuszu, który sam sobie zepsuł — wynik jest nieważny w całości.');
    process.exit(2);
  }
  if (deklaracjiSprawdzonych === 0) {
    console.error('\nBŁĄD PRZYRZĄDU: przesiew nie wskazał ANI JEDNEJ deklaracji do przepytania kaskady.');
    console.error('Przy sześciu warstwach i ponad tysiącu reguł to znaczy, że przesiew jest zepsuty,');
    console.error('a nie że repozytorium jest czyste.');
    process.exit(2);
  }

  /* ─── SAMOKONTROLA ZAWĘŻENIA (`--tylko`) ───────────────────────────────
     docs/PULAPKI_TESTOW.md §2: skan, który nie znajduje ŻADNEGO pliku,
     przechodzi. Samokontrole wyżej pilnują pomiaru JAKO CAŁOŚCI i dlatego
     nie widzą tej dziury: przy `--tylko` werdykt zapada na garstce reguł,
     a całość mierzy się dalej poprawnie. Literówka w zawężeniu
     (`--tylko .przepis-liczbа` z cyrylicznym „а", `--tylko .przepis-liczby`
     po zmianie nazwy klasy) dawała wtedy pełną zieleń z pełnym pomiarem
     pod spodem — najbardziej przekonujący możliwy wariant zera.

     Zawężenie, pod które na ŻADNEJ mierzonej stronie nie podpadła ani jedna
     reguła z nosicielem, jest więc BŁĘDEM PRZYRZĄDU, a nie wynikiem
     pozytywnym. Liczymy reguły Z NOSICIELEM, nie same dopasowania tekstowe:
     selektor obecny w arkuszu, ale bez elementu na mierzonych stronach, też
     niczego nie dowodzi — a to jest właśnie różnica między „zmierzone
     i czyste" a „niezmierzone". */
  if (TYLKO) {
    const trafione = [...selektoryZNosicielem].filter((s) => s.includes(TYLKO));
    if (trafione.length === 0) {
      console.error(`\nBŁĄD PRZYRZĄDU: zawężenie --tylko "${TYLKO}" nie objęło ANI JEDNEJ reguły z nosicielem`);
      console.error('na mierzonych stronach. Werdykt zapadłby na pustym zbiorze, a pusty zbiór nie ma');
      console.error('martwych reguł zawsze — niezależnie od tego, co jest w arkuszu.');
      console.error('Sprawdź pisownię zawężenia albo to, czy selektor ma nosiciela na stronach z `SCIEZKI`.');
      process.exit(2);
    }
    console.log(`Zawężenie objęło ${trafione.length} reguł z nosicielem, m.in.: ${trafione.slice(0, 3).join(' / ')}`);
  }

  if (zamierzone.length) {
    console.log('PRZYKRYCIA ZAMIERZONE (wyjątki, każdy z powodem):');
    for (const m of zamierzone) {
      const w = WYJATKI.find((x) => pasuje(m, x));
      console.log(`  • ${m.selektor} { ${m.wlasnosc} } — warstwa ${m.warstwa} pod ${m.przykrywaWarstwa}`);
      console.log(`    powód: ${w.powod}`);
    }
    console.log('');
  }

  const bezNosiciela = [...niezmierzone.values()].filter((n) => n.powod.startsWith('brak')).length;
  const zeStanem = [...niezmierzone.values()].filter((n) => !n.powod.startsWith('brak')).length;
  console.log(`Niezmierzone (granica narzędzia, NIE wynik pozytywny): ${bezNosiciela} selektorów bez nosiciela na mierzonych stronach, ${zeStanem} ze stanem interakcji.`);

  /* GRUPUJEMY PO REGULE, NIE PO WŁASNOŚCI SKŁADOWEJ.

     Gdy skrót zawiera `var()` (a w tym repozytorium prawie każdy zawiera, bo
     wszystkie wartości idą przez tokeny), przeglądarka nie potrafi złożyć go
     z powrotem i wystawia same składowe — jedno autorskie `padding:
     var(--spacing-4)` widać jako cztery pozycje z PUSTĄ wartością. Liczenie
     ich osobno nadmuchuje raport kilkukrotnie i sugeruje więcej problemów,
     niż jest miejsc do poprawienia.

     Jednostką raportu jest więc REGUŁA (warstwa + selektor), a własności są
     jej wyliczeniem. Werdykt nadal zapada osobno dla każdej deklaracji. */
  const grupy = new Map();
  for (const m of nowe) {
    const k = `${m.warstwa} | ${m.selektor}`;
    if (!grupy.has(k)) grupy.set(k, { ...m, wlasnosci: [] });
    grupy.get(k).wlasnosci.push(m.wlasnosc);
  }

  if (nowe.length) {
    console.log(`\n✗ MARTWE REGUŁY — zadeklarowane i całkowicie przykryte przez późniejszą warstwę`);
    console.log(`   reguł: ${grupy.size}, a w nich deklaracji: ${nowe.length}\n`);
    for (const m of grupy.values()) {
      console.log(`  ${m.selektor.slice(0, 90)}`);
      console.log(`      warstwa ${m.warstwa}${m.warunki.length ? ` @${m.warunki.join(' @')}` : ''} — martwe własności: ${m.wlasnosci.join(', ')}`);
      console.log(`      przykrywa: warstwa ${m.przykrywaWarstwa}, \`${m.przykrywaSelektor.slice(0, 70)}\``);
      console.log(`      martwa w ${m.martwaW}/${m.badanaW} zbadanych konfiguracjach, m.in.: ${m.gdzie.slice(0, 3).join(', ')}`);
    }
    console.log('\nPełny wynik: storage/kaskada-martwe-reguly.json');
    console.log('\nCo z tym zrobić: napraw wartość TAM, GDZIE ONA OBOWIĄZUJE (warstwa późniejsza),');
    console.log('albo dopisz wyjątek z POWODEM w `WYJATKI` w tym pliku. Nie poprawiaj martwej reguły —');
    console.log('poprawka w niej nie dojdzie do przeglądarki.');
    process.exit(1);
  }

  console.log('\n✓ Żadna reguła z wcześniejszej warstwy nie jest całkowicie przykryta przez późniejszą (poza wyjątkami).');
  console.log('Pełny wynik: storage/kaskada-martwe-reguly.json');
}

main().catch((blad) => { console.error(blad.message); process.exit(1); });
