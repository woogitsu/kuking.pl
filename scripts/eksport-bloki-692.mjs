/* #713 D5 — nowe bloki o brakach w paczce z danymi (#692), na ekranie i w druku.

   CO TO ZAMYKA. Pakiet #692 (PR #712) zamknięto samym PHPUnitem: test buduje
   prawdziwy ZIP i czyta z niego zdania, ale nikt nie zobaczył bloków
   „UWAGA: N zdjęć nie weszło…" na ekranie ani na wydruku i nie zmierzył ich
   przy wąskim oknie, ciemnym motywie systemu i powiększonym piśmie.
   `docs/design/ODBIOR_EKSPORTU_I_DRUKU_492.md` obejmuje starszy pakiet #678 i
   nie zna tych bloków.

   BIERZE prawdziwe paczki zbudowane przez `GenerateUserExport`
   (`scripts/fixtures/eksport-692.php`, trzy konta: 112 odrzuconych obok
   gotowych; odrzucone + skasowane + w drodze naraz; komplet) i otwiera ich
   `index.html` z dysku, tak jak człowiek po rozpakowaniu archiwum.

   MIERZY dla 3 scen × 4 szerokości (320/390/768/1440) × jasny/ciemny motyw
   systemu × pismo 100% i przeglądarka z podwojoną czcionką bazową (CDP
   `Page.setFontSizes`, NIE powiększenie strony):
     - liczba i treść bloków `.uwaga` (odmiana „112 zdjęć nie weszło", „12 …");
     - skasowane zdjęcia idą zwykłym akapitem, nie alarmem `.uwaga`;
     - brak poziomego przewijania, blok mieści się w oknie;
     - pismo w bloku nie mniejsze od 18 px × (czcionka bazowa / 16);
     - kontrast pisma z tłem bloku co najmniej 4,5:1;
     - w druku (`emulateMedia print`): `break-inside: avoid` na bloku i PDF A4
       z marginesem 10 mm faktycznie powstaje (treści PDF nie czytamy).
   Zrzuty do `storage/eksport-692/` (poza repozytorium).

   CZEGO NIE DOWODZI. Fizycznej drukarki, sterowników i natywnego rozpakowania
   archiwum (#678, C3 w rejestrze) ani czytnika ekranu. Kolejność zdań i
   redakcja tekstu to osąd, nie pomiar.

   URUCHOMIENIE (własna baza po `DemoSeeder`):
     CHROMIUM_PATH=… DB_DATABASE=kuking_713_pomiar node scripts/eksport-bloki-692.mjs */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { uruchomPrzegladarke } from './lib/serwer-lokalny.mjs';

const SZEROKOSCI = [320, 390, 768, 1440];
const MOTYWY = ['light', 'dark'];
const PISMA = ['100', 'przegladarka-200'];

function luminancja(rgb) {
  const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const kontrast = (a, b) => { const [x, y] = [luminancja(a), luminancja(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };

function pomiar() {
  const bloki = [...document.querySelectorAll('.uwaga')].map((el) => {
    const r = el.getBoundingClientRect();
    const p = el.querySelector('p');
    const s = getComputedStyle(p);
    return { tekst: el.textContent.replace(/\s+/g, ' ').trim(), lewa: r.left, prawa: r.right, font: parseFloat(s.fontSize), kolor: s.color, tlo: getComputedStyle(el).backgroundColor, ramkaLewa: parseFloat(getComputedStyle(el).borderLeftWidth) };
  });
  const akapity = [...document.querySelectorAll('p')].filter((p) => !p.closest('.uwaga')).map((p) => p.textContent.replace(/\s+/g, ' ').trim());
  return { bloki, akapity, scroll: document.documentElement.scrollWidth, okno: innerWidth, rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize) };
}

/* Reguły druku bloku `.uwaga` w emulacji `print`. */
async function regulyDruku(p) {
  return p.evaluate(() => [...document.querySelectorAll('.uwaga')].map((el) => ({ avoid: getComputedStyle(el).breakInside })));
}

export async function sprawdzBlokiEksportu({ browser, sceny, out }) {
  mkdirSync(out, { recursive: true });
  const oczekiwane = {
    'setki-odrzuconych': { uwagi: [/112 zdjęć nie weszło do tej paczki i nie wejdzie do żadnej następnej/], skasowane: null },
    mieszane: { uwagi: [/2 zdjęcia nie zmieściły się w tej paczce/, /12 zdjęć nie weszło do tej paczki i nie wejdzie do żadnej następnej/], skasowane: /3 zdjęcia, które skasowano z Kuking, nie weszły do tej paczki i nie wejdą do żadnej następnej/ },
    komplet: { uwagi: [], skasowane: null },
  };
  let ile = 0;
  for (const [nazwa, sciezki] of Object.entries(sceny)) {
    const adres = pathToFileURL(sciezki.index).href;
    for (const width of SZEROKOSCI) for (const motyw of MOTYWY) for (const pismo of PISMA) {
      const kontekst = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: motyw, javaScriptEnabled: true });
      try {
        const p = await kontekst.newPage();
        if (pismo === 'przegladarka-200') await (await kontekst.newCDPSession(p)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
        await p.goto(adres);
        await p.evaluate(() => document.fonts.ready);
        const m = await p.evaluate(pomiar);
        const et = `${nazwa} ${width}px ${motyw} pismo ${pismo}`;
        const o = oczekiwane[nazwa];
        assert.equal(m.bloki.length, o.uwagi.length, `${et}: liczba bloków UWAGA ${m.bloki.length}, oczekiwano ${o.uwagi.length}`);
        o.uwagi.forEach((wzor, i) => assert(wzor.test(m.bloki[i].tekst), `${et}: blok ${i + 1} nie zawiera oczekiwanego zdania ${wzor}: „${m.bloki[i].tekst}"`));
        if (o.skasowane) assert(m.akapity.some((t) => o.skasowane.test(t)), `${et}: brak zwykłego akapitu o skasowanych albo trafił do bloku UWAGA`);
        assert(m.scroll <= m.okno + 1, `${et}: poziome przewijanie ${m.scroll} > ${m.okno}`);
        for (const b of m.bloki) {
          assert(b.lewa >= -1 && b.prawa <= m.okno + 1, `${et}: blok wystaje z okna ${JSON.stringify({ lewa: b.lewa, prawa: b.prawa, okno: m.okno })}`);
          assert(b.font >= 18 * (m.rootFont / 16) - 0.5, `${et}: pismo w bloku ${b.font} px, minimum ${18 * (m.rootFont / 16)} px`);
          const k = kontrast(b.kolor, b.tlo);
          assert(k >= 4.5, `${et}: kontrast bloku ${k.toFixed(2)}:1 (${b.kolor} na ${b.tlo})`);
          // Kolor to dodatek: gruba lewa krawędź niesie znaczenie także bez koloru.
          assert(b.ramkaLewa >= 8 - 0.5, `${et}: brak grubej lewej krawędzi bloku (${b.ramkaLewa} px)`);
        }
        if (width === 320 && pismo === 'przegladarka-200' && motyw === 'light' && o.uwagi.length) await p.screenshot({ path: join(out, `${nazwa}-320-200.png`), fullPage: true });
        if (width === 1440 && pismo === '100' && motyw === 'dark' && o.uwagi.length) await p.screenshot({ path: join(out, `${nazwa}-1440-ciemny.png`), fullPage: true });
        ile++;
      } finally { await kontekst.close(); }
    }

    // Druk: emulacja `print` (bez sterowników i bez „dopasuj do strony") oraz PDF.
    if (oczekiwane[nazwa].uwagi.length) {
      const kontekst = await browser.newContext({ viewport: { width: 794, height: 1123 } });
      try {
        const p = await kontekst.newPage();
        await p.goto(adres);
        await p.emulateMedia({ media: 'print' });
        for (const b of await regulyDruku(p)) {
          assert.equal(b.avoid, 'avoid', `${nazwa}: w druku blok UWAGA nie ma break-inside: avoid (${b.avoid})`);
        }
        const pdf = await p.pdf({ format: 'A4', margin: { top: '10mm', bottom: '10mm', left: '10mm', right: '10mm' }, printBackground: false });
        assert(pdf.length > 1000 && pdf.subarray(0, 4).toString() === '%PDF', `${nazwa}: wydruk PDF nie powstał`);
        ile++;
      } finally { await kontekst.close(); }
    }
  }
  console.log(`EKSPORT_BLOKI_OK ${ile} konfiguracji na ${Object.keys(sceny).length} paczkach`);
  return ile;
}

async function przebiegLokalny() {
  assert(process.env.DB_DATABASE && !['kuking', 'kuking_test'].includes(process.env.DB_DATABASE), 'Podaj własną bazę w DB_DATABASE (nie `kuking` ani `kuking_test`).');
  const katalog = mkdtempSync(join(tmpdir(), 'kuking-eksport692-'));
  let przegladarka;
  try {
    const sceny = JSON.parse(execFileSync('php', ['scripts/fixtures/eksport-692.php', katalog], { cwd: process.cwd(), env: { ...process.env, APP_BASE_PATH: process.cwd() } }).toString().trim().split('\n').pop());
    for (const s of Object.values(sceny)) assert(readFileSync(s.index, 'utf8').includes('<html'), 'Paczka nie zawiera index.html');
    przegladarka = await uruchomPrzegladarke();
    await sprawdzBlokiEksportu({ browser: przegladarka, sceny, out: 'storage/eksport-692' });
  } finally {
    await przegladarka?.close();
    rmSync(katalog, { recursive: true, force: true });
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  przebiegLokalny().catch((blad) => { console.error(blad.message); process.exitCode = 1; });
}
