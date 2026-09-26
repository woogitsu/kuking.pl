/*
 * =============================================================================
 *  ODCISK KAFLA LICZB — dowód, że usunięcie martwych deklaracji jest NIEWIDOCZNE
 * =============================================================================
 *
 *  CO TO ROBI
 *  Zdejmuje „odcisk palca" kafli `.przepis-liczba` na wyrenderowanej stronie:
 *  KOMPLET własności `getComputedStyle` (nie wybrane, bo wybrane odpowiadałyby
 *  na pytanie, które sam sobie zadałem) plus geometrię co do setnej piksela.
 *  Zdejmowany przed usunięciem deklaracji i po nim, porównywany wartość
 *  w wartość.
 *
 *  DLACZEGO KOMPLET WŁASNOŚCI, A NIE `padding`/`border-radius`/`font-size`
 *  Usunięcie deklaracji może ruszyć coś, czego nie przewidziałem — skrótowce
 *  (`padding` rozwija się na cztery), dziedziczenie (`font-size` dziecka liczone
 *  z rodzica), `em`/`ch` liczone z font-size. Pomiar zawężony do trzech własności
 *  przeszedłby na zielono, gdyby zmieniła się czwarta. Zawężenie tutaj byłoby
 *  zerem przebranym za dowód (docs/PULAPKI_TESTOW.md §2).
 *
 *  UŻYCIE (runtime WSL, własna baza, port 55439 — nigdy 5432)
 *      npx vite build
 *      node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs przed.json
 *      # … usunięcie deklaracji, ponowny `npx vite build` …
 *      node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs po.json
 *      node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs --porownaj przed.json po.json
 *
 *  Kod wyjścia: 0 — odciski identyczne; 1 — jest różnica; 2 — BŁĄD PRZYRZĄDU
 *  (zero zmierzonych kafli to NIE jest wynik pozytywny — skan, który nie
 *  znalazł nosiciela, przechodzi zawsze).
 */

import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { writeFileSync, readFileSync } from 'node:fs';

const PORT_BAZY_ZAKAZANY = '5432';

/* ── Tryb porównania: nie stawia serwera, tylko czyta dwa odciski ───────── */
if (process.argv[2] === '--porownaj') {
  const [a, b] = [process.argv[3], process.argv[4]].map((p) => JSON.parse(readFileSync(p, 'utf8')));
  if (!a.odciski.length || !b.odciski.length) {
    console.error('BŁĄD PRZYRZĄDU: któryś odcisk jest pusty. Cisza nie jest dowodem.');
    process.exit(2);
  }
  const mapa = (o) => new Map(o.odciski.map((x) => [x.klucz, x]));
  const ma = mapa(a);
  const mb = mapa(b);
  const roznice = [];
  let wezlowPorownanych = 0;
  let wartosciPorownanych = 0;
  for (const klucz of new Set([...ma.keys(), ...mb.keys()])) {
    const x = ma.get(klucz);
    const y = mb.get(klucz);
    if (!x || !y) { roznice.push(`${klucz}: obecny tylko w ${x ? 'PRZED' : 'PO'}`); continue; }
    if (x.przewijaniePoziome !== y.przewijaniePoziome) {
      roznice.push(`${klucz}: przewijanie poziome ${x.przewijaniePoziome} → ${y.przewijaniePoziome}`);
    }
    for (const [i, we] of x.wezly.entries()) {
      const wp = y.wezly[i];
      if (!wp) { roznice.push(`${klucz} [${i}] ${we.opis}: węzeł zniknął`); continue; }
      if (we.opis !== wp.opis) { roznice.push(`${klucz} [${i}]: inny węzeł (${we.opis} → ${wp.opis})`); continue; }
      wezlowPorownanych += 1;
      if (JSON.stringify(we.geometria) !== JSON.stringify(wp.geometria)) {
        roznice.push(`${klucz} [${i}] ${we.opis}: geometria ${JSON.stringify(we.geometria)} → ${JSON.stringify(wp.geometria)}`);
      }
      for (const wlasnosc of new Set([...Object.keys(we.styl), ...Object.keys(wp.styl)])) {
        wartosciPorownanych += 1;
        if (we.styl[wlasnosc] !== wp.styl[wlasnosc]) {
          roznice.push(`${klucz} [${i}] ${we.opis}: ${wlasnosc}: "${we.styl[wlasnosc]}" → "${wp.styl[wlasnosc]}"`);
        }
      }
    }
    if (y.wezly.length > x.wezly.length) roznice.push(`${klucz}: doszło ${y.wezly.length - x.wezly.length} węzłów`);
  }
  console.log(`Konfiguracji w odcisku PRZED: ${a.odciski.length}, PO: ${b.odciski.length}`);
  console.log(`Węzłów porównanych: ${wezlowPorownanych}. Wartości wyliczonych porównanych: ${wartosciPorownanych}.`);
  if (!wezlowPorownanych) {
    console.error('BŁĄD PRZYRZĄDU: nie porównano ANI JEDNEGO węzła. Cisza nie jest dowodem.');
    process.exit(2);
  }
  if (roznice.length) {
    console.error(`\n✗ RÓŻNICA — usunięcie NIE jest niewidoczne (${roznice.length}):`);
    for (const r of roznice.slice(0, 60)) console.error(`  ${r}`);
    if (roznice.length > 60) console.error(`  … i ${roznice.length - 60} więcej`);
    process.exit(1);
  }
  console.log('\n✓ ODCISKI IDENTYCZNE — ta sama geometria i te same wartości wyliczone przed i po.');
  process.exit(0);
}

const WYJSCIE = process.argv[2];
if (!WYJSCIE) {
  console.error('Podaj plik wyjściowy albo: --porownaj przed.json po.json');
  process.exit(2);
}

if ((process.env.DB_PORT || '') === PORT_BAZY_ZAKAZANY) {
  console.error(`DB_PORT=${PORT_BAZY_ZAKAZANY} jest zakazany dla pomiarów (AGENTS.md §10).`);
  process.exit(2);
}

/* Dwa przepisy: `rosol-babci-zofii` ma wszystkie trzy kafle (Czas, Ilość,
   Poziom), drugi jest kontrolą, że to nie jest cecha jednej strony. */
const SLUGI = ['rosol-babci-zofii', 'chleb-pszenno-zytni-na-zakwasie'];
/* Progi z `marka-ekrany.css` (30rem, 48rem, 64rem) i po obu ich stronach. */
const SZEROKOSCI = [320, 360, 480, 481, 768, 769, 1024, 1280];
const MOTYWY = ['light', 'dark'];
/* Dwa RÓŻNE mechanizmy powiększania pisma — mylenie ich to pułapka opisana
   w docs/PULAPKI_TESTOW.md. UX 50+: gdyby usunięcie `font-size` cokolwiek
   ZMNIEJSZYŁO, widać to tutaj, a nie dopiero w oku czytelnika. */
const PISMA = [
  { nazwa: 'bez', rodzaj: 'brak' },
  { nazwa: 'nasze-140', rodzaj: 'nasze', wartosc: '140' },
  { nazwa: 'korzen-150', rodzaj: 'korzen', wartosc: 24 },
];

const wolnyPort = () => new Promise((resolve, reject) => {
  const g = createServer();
  g.on('error', reject);
  g.listen(0, '127.0.0.1', () => { const { port } = g.address(); g.close(() => resolve(port)); });
});

async function podniesSerwer() {
  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
  const dziennik = [];
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { stdio: ['ignore', 'pipe', 'pipe'] });
  proces.stdout.on('data', (b) => dziennik.push(String(b)));
  proces.stderr.on('data', (b) => dziennik.push(String(b)));
  for (let i = 0; i < 60; i++) {
    try {
      const o = await fetch(`${adres}/health`);
      if (o.ok) return { adres, zamknij: () => proces.kill('SIGTERM') };
    } catch { /* wstaje */ }
    await new Promise((r) => setTimeout(r, 500));
  }
  proces.kill('SIGKILL');
  throw new Error(`serwer nie wstał\n${dziennik.join('')}`);
}

/* Biegnie W PRZEGLĄDARCE. Zbiera nosiciela siatki, kafle i wszystkie ich dzieci. */
const ODCISK = () => {
  const ul = document.querySelector('.przepis-liczby');
  if (!ul) return { wezly: [], przewijaniePoziome: 0 };
  const cele = [{ el: ul, opis: 'ul.przepis-liczby' }];
  [...ul.querySelectorAll('.przepis-liczba')].forEach((li, i) => {
    cele.push({ el: li, opis: `li.przepis-liczba[${i}]` });
    [...li.querySelectorAll('*')].forEach((d, j) => {
      cele.push({ el: d, opis: `li[${i}] > ${d.tagName.toLowerCase()}[${j}]` });
    });
  });
  return {
    wezly: cele.map(({ el, opis }) => {
      const s = getComputedStyle(el);
      const styl = {};
      for (let i = 0; i < s.length; i += 1) styl[s[i]] = s.getPropertyValue(s[i]);
      const r = el.getBoundingClientRect();
      return {
        opis,
        tekst: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
        geometria: {
          x: Math.round(r.left * 100) / 100,
          y: Math.round(r.top * 100) / 100,
          szer: Math.round(r.width * 100) / 100,
          wys: Math.round(r.height * 100) / 100,
          przepelnienieX: el.scrollWidth - el.clientWidth,
          przepelnienieY: el.scrollHeight - el.clientHeight,
        },
        styl,
      };
    }),
    przewijaniePoziome: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  };
};

const serwer = await podniesSerwer();
const przegladarka = await chromium.launch();
const odciski = [];
try {
  for (const slug of SLUGI) {
    for (const szer of SZEROKOSCI) {
      for (const motyw of MOTYWY) {
        for (const pismo of PISMA) {
          const ctx = await przegladarka.newContext({ viewport: { width: szer, height: 900 }, colorScheme: motyw });
          const strona = await ctx.newPage();
          const odp = await strona.goto(`${serwer.adres}/przepisy/${slug}`, { waitUntil: 'networkidle' });
          if (!odp || !odp.ok()) {
            console.log(`  (pominięto) ${slug}/${szer}/${motyw}: HTTP ${odp ? odp.status() : '—'}`);
            await ctx.close();
            continue;
          }
          if (pismo.rodzaj === 'nasze') await strona.evaluate((v) => document.documentElement.setAttribute('data-text-scale', v), pismo.wartosc);
          if (pismo.rodzaj === 'korzen') await strona.evaluate((v) => { document.documentElement.style.fontSize = `${v}px`; }, pismo.wartosc);
          await strona.waitForTimeout(150);
          const wynik = await strona.evaluate(ODCISK);
          const klucz = `${slug.slice(0, 20)}/${szer}/${motyw}/${pismo.nazwa}`;
          if (!wynik.wezly.length) {
            console.log(`  (brak nosiciela) ${klucz}`);
            await ctx.close();
            continue;
          }
          odciski.push({ klucz, ...wynik });
          const li = wynik.wezly.find((w) => w.opis === 'li.przepis-liczba[0]');
          console.log(`  ${klucz.padEnd(46)} węzłów:${String(wynik.wezly.length).padStart(3)}  kafel0 ${li.geometria.szer}x${li.geometria.wys} pad=${li.styl['padding-top']} r=${li.styl['border-top-left-radius']}`);
          await ctx.close();
        }
      }
    }
  }
} finally {
  await przegladarka.close();
  serwer.zamknij();
}

/* SAMOKONTROLA: skan bez nosiciela przechodzi zawsze. Zero kafli to błąd
   przyrządu, nie wynik pozytywny (docs/PULAPKI_TESTOW.md §2). */
if (!odciski.length) {
  console.error('\nBŁĄD PRZYRZĄDU: nie zmierzono ANI JEDNEGO kafla `.przepis-liczba`.');
  process.exit(2);
}
const wlasnosciNaWezel = Object.keys(odciski[0].wezly[0].styl).length;
if (wlasnosciNaWezel < 100) {
  console.error(`\nBŁĄD PRZYRZĄDU: getComputedStyle dał tylko ${wlasnosciNaWezel} własności — to nie jest komplet.`);
  process.exit(2);
}
writeFileSync(WYJSCIE, JSON.stringify({ data: new Date().toISOString(), odciski }, null, 2));
console.log(`\nKonfiguracji: ${odciski.length}. Węzłów: ${odciski.reduce((s, o) => s + o.wezly.length, 0)}. Własności na węzeł: ${wlasnosciNaWezel}.`);
console.log(`Odcisk: ${WYJSCIE}`);
