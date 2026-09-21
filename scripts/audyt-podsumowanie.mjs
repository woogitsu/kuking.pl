/*
 * Kuking.pl — podsumowanie pomiaru UX 50+ w tabelach z liczbami.
 * Czyta storage/audyt-ux50plus.json. Nic nie mierzy — samo liczy i sortuje,
 * żeby raport dało się odtworzyć z surowych danych bez powtarzania przebiegu.
 */
import { readFileSync } from 'node:fs';

const d = JSON.parse(readFileSync('storage/audyt-ux50plus.json', 'utf8'));
const { PROG_TEKSTU, PROG_CELU } = d.progi;
const dobre = d.wyniki.filter((w) => !w.blad);

const pad = (s, n) => String(s).padEnd(n);
const lp = (s, n) => String(s).padStart(n);

console.log(`Pomiar: ${d.kiedy}`);
console.log(`Macierz: ${d.macierz.SZEROKOSCI.join('/')} px × ${d.macierz.MOTYWY.join('/')} × ${d.macierz.SKALE.join('/')}%`);
console.log(`Pomiarów: ${d.wyniki.length}, w tym błędów: ${d.wyniki.length - dobre.length}`);
for (const w of d.wyniki.filter((x) => x.blad)) {
  console.log(`  BŁĄD ${w.ekran} ${w.szerokosc}/${w.motyw}/${w.skala}: ${w.blad}`);
}

// ---------------------------------------------------------------- 1. PRZEPEŁNIENIE
console.log('\n\n== 1. PRZEWIJANIE W POZIOMIE ==');
const przepelnione = dobre.filter((w) => w.przepelnienie.scrollWidth > w.przepelnienie.clientWidth + 1);
console.log(`Kombinacji z przewijaniem w bok: ${przepelnione.length} / ${dobre.length}`);
if (przepelnione.length) {
  console.log(`${pad('ekran', 26)}${lp('okno', 6)}${pad('  motyw', 8)}${lp('skala', 6)}${lp('scrollW', 9)}${lp('clientW', 9)}${lp('nadmiar', 9)}`);
  for (const w of przepelnione.sort((a, b) => (b.przepelnienie.scrollWidth - b.przepelnienie.clientWidth) - (a.przepelnienie.scrollWidth - a.przepelnienie.clientWidth))) {
    const p = w.przepelnienie;
    console.log(`${pad(w.ekran, 26)}${lp(w.szerokosc, 6)}${pad(`  ${w.motyw}`, 8)}${lp(`${w.skala}%`, 6)}${lp(p.scrollWidth, 9)}${lp(p.clientWidth, 9)}${lp(p.scrollWidth - p.clientWidth, 9)}`);
    for (const c of p.winowajcy.slice(0, 3)) {
      console.log(`      → ${c.sciezka}  left=${c.left} right=${c.right} w=${c.szerokosc}  „${c.probka}"`);
    }
  }
}

// ---------------------------------------------------------------- 2. TEKST
console.log('\n\n== 2. ROZMIAR TEKSTU (próg ' + PROG_TEKSTU + ' px) ==');
const zaMalyTekst = new Map();
for (const w of dobre) {
  for (const t of w.tekst) {
    if (t.px >= PROG_TEKSTU) continue;
    const klucz = `${t.sciezka}|${t.px}`;
    if (!zaMalyTekst.has(klucz)) {
      zaMalyTekst.set(klucz, { ...t, ekrany: new Set(), skale: new Set(), szerokosci: new Set() });
    }
    const wpis = zaMalyTekst.get(klucz);
    wpis.ekrany.add(w.ekran);
    wpis.skale.add(w.skala);
    wpis.szerokosci.add(w.szerokosc);
  }
}
const listaTekstu = [...zaMalyTekst.values()].sort((a, b) => a.px - b.px || b.ekrany.size - a.ekrany.size);
console.log(`Różnych elementów poniżej progu: ${listaTekstu.length}`);
console.log(`${lp('px', 7)}  ${pad('ekranów', 8)}${pad('skale', 10)}${pad('ścieżka', 62)}próbka`);
for (const t of listaTekstu.slice(0, 60)) {
  console.log(`${lp(t.px, 7)}  ${pad(t.ekrany.size, 8)}${pad([...t.skale].join('/'), 10)}${pad(t.sciezka.slice(0, 60), 62)}„${t.probka.slice(0, 40)}"`);
}

// Tekst, który NIE reaguje na ustawienie „powiększ tekst".
console.log('\n-- tekst nieskalujący się przy 140% (ten sam piksel co przy 100%) --');
const po100 = new Map();
const po140 = new Map();
for (const w of dobre) {
  const cel = w.skala === 100 ? po100 : po140;
  for (const t of w.tekst) cel.set(`${w.ekran}|${t.sciezka}|${t.probka}`, t.px);
}
const nieskaluje = new Map();
for (const [k, px] of po100) {
  if (po140.get(k) === px) {
    const sciezka = k.split('|')[1];
    if (!nieskaluje.has(`${sciezka}|${px}`)) nieskaluje.set(`${sciezka}|${px}`, { sciezka, px, ile: 0 });
    nieskaluje.get(`${sciezka}|${px}`).ile += 1;
  }
}
const listaNieskal = [...nieskaluje.values()].sort((a, b) => b.ile - a.ile);
console.log(`Różnych elementów: ${listaNieskal.length}`);
for (const n of listaNieskal.slice(0, 25)) {
  console.log(`${lp(n.px, 7)} px  ×${lp(n.ile, 4)}  ${n.sciezka}`);
}

// ---------------------------------------------------------------- 3. CELE
console.log('\n\n== 3. CELE DOTKNIĘCIA (próg ' + PROG_CELU + ' × ' + PROG_CELU + ' px) ==');
const zaMaleCele = new Map();
for (const w of dobre) {
  for (const c of w.cele) {
    if (c.h >= PROG_CELU && c.w >= PROG_CELU) continue;
    const klucz = `${c.sciezka}|${c.typ}|${c.h}|${c.w}`;
    if (!zaMaleCele.has(klucz)) {
      zaMaleCele.set(klucz, { ...c, ekrany: new Set(), szerokosci: new Set(), skale: new Set() });
    }
    const wpis = zaMaleCele.get(klucz);
    wpis.ekrany.add(w.ekran);
    wpis.szerokosci.add(w.szerokosc);
    wpis.skale.add(w.skala);
  }
}
const lista = [...zaMaleCele.values()];
const wazne = lista.filter((c) => !c.wAkapicie);
const wProzie = lista.filter((c) => c.wAkapicie);
console.log(`Różnych celów poniżej progu: ${lista.length}  (poza prozą: ${wazne.length}, odnośniki w prozie: ${wProzie.length})`);
console.log('\n-- poza prozą (to są „ważne przyciski" z AGENTS.md) --');
console.log(`${lp('h', 7)}${lp('w', 8)}  ${pad('typ', 18)}${pad('ekranów', 8)}${pad('ścieżka', 56)}nazwa`);
for (const c of wazne.sort((a, b) => a.h - b.h || a.w - b.w).slice(0, 60)) {
  console.log(`${lp(c.h, 7)}${lp(c.w, 8)}  ${pad(c.typ, 18)}${pad(c.ekrany.size, 8)}${pad(c.sciezka.slice(0, 54), 56)}„${c.nazwa.slice(0, 32)}"`);
}
console.log('\n-- odnośniki w prozie (osobna kategoria, nie „przycisk") --');
for (const c of wProzie.sort((a, b) => a.h - b.h).slice(0, 15)) {
  console.log(`${lp(c.h, 7)}${lp(c.w, 8)}  ${pad(c.typ, 18)}${pad(c.ekrany.size, 8)}${c.sciezka.slice(0, 54)} „${c.nazwa.slice(0, 32)}"`);
}

// ---------------------------------------------------------------- 4. NAJGORSZE EKRANY
console.log('\n\n== 4. EKRANY WEDŁUG LICZBY NARUSZEŃ ==');
const wgEkranu = new Map();
for (const w of dobre) {
  if (!wgEkranu.has(w.ekran)) wgEkranu.set(w.ekran, { ekran: w.ekran, tekst: 0, cele: 0, przepelnienia: 0, pomiarow: 0 });
  const e = wgEkranu.get(w.ekran);
  e.pomiarow += 1;
  e.tekst += w.tekst.filter((t) => t.px < PROG_TEKSTU).length;
  e.cele += w.cele.filter((c) => (c.h < PROG_CELU || c.w < PROG_CELU) && !c.wAkapicie).length;
  if (w.przepelnienie.scrollWidth > w.przepelnienie.clientWidth + 1) e.przepelnienia += 1;
}
console.log(`${pad('ekran', 26)}${lp('pomiarów', 10)}${lp('tekst<18', 10)}${lp('cel<48', 9)}${lp('przewijań', 11)}`);
for (const e of [...wgEkranu.values()].sort((a, b) => (b.tekst + b.cele + b.przepelnienia * 10) - (a.tekst + a.cele + a.przepelnienia * 10))) {
  console.log(`${pad(e.ekran, 26)}${lp(e.pomiarow, 10)}${lp(e.tekst, 10)}${lp(e.cele, 9)}${lp(e.przepelnienia, 11)}`);
}
