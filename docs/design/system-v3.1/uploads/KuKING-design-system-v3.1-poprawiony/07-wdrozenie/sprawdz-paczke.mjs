#!/usr/bin/env node
/*
 * sprawdz-paczke.mjs — automat pilnujący twardych ograniczeń Kuking.
 *
 * Sprawdza to, czego nie da się dopilnować dobrymi chęciami, a co unieważnia
 * projekt, jeśli przecieknie: skrypt w widoku, atrybut style=, wartość koloru
 * albo rozmiaru napisana z ręki zamiast var(--…), oraz słowo z listy zakazanej
 * w tekście widocznym dla człowieka.
 *
 *   node 07-wdrozenie/sprawdz-paczke.mjs
 *
 * Kod wyjścia 1, jeśli którakolwiek reguła twarda jest złamana — nadaje się do
 * CI. Uwagi „do przejrzenia” nie przewracają wyniku.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const KORZEN = join(dirname(fileURLToPath(import.meta.url)), '..');

/* Pliki, w których wartości „na sztywno" są na miejscu, bo to tam wartości
   MIESZKAJĄ albo są danymi, a nie stylem. */
const WOLNO_WARTOSCI = [
  '01-fundamenty/tokens.css',      // źródło wszystkich wartości
  '01-fundamenty/kontrast.mjs',    // palety do liczenia kontrastu
  '01-fundamenty/zbuduj-tokeny.mjs',
  '01-fundamenty/tokens.json',
  'podglad/podglad.css',           // wygenerowany z tokens.css
  'podglad/ikony-sprite.html',     // geometria ścieżek SVG, nie styl
  '06-marka/znak/',                // znak marki: kolory są jego definicją
];

const POMIN_KATALOGI = new Set(['node_modules', '.git']);

const pliki = [];
(function zbierz(kat) {
  for (const wpis of readdirSync(kat)) {
    if (POMIN_KATALOGI.has(wpis)) continue;
    const p = join(kat, wpis);
    if (statSync(p).isDirectory()) zbierz(p);
    else pliki.push(p);
  }
})(KORZEN);

const sciezka = (p) => relative(KORZEN, p).split('\\').join('/');
const wolnoWartosci = (p) => WOLNO_WARTOSCI.some((w) => sciezka(p).startsWith(w));

const bledy = [];
const uwagi = [];
const zglos = (lista, plik, linia, regula, tresc) =>
  lista.push({ plik: sciezka(plik), linia, regula, tresc: tresc.trim().slice(0, 110) });

/* ------------------------------------------------------------------ */
/* Reguły twarde                                                       */
/* ------------------------------------------------------------------ */

/* Wartości koloru i typografii napisane z ręki. `px` w geometrii (szerokość
   arkusza, wymiar grafiki na media społecznościowe, aspect-ratio) jest
   dozwolony — dlatego łapiemy tylko właściwości, które są STYLEM. */
const HEX = /#[0-9a-fA-F]{3,8}\b/;

/* Wartość wyciągamy i sprawdzamy osobno, a nie przez `lookahead` w jednym
   wyrażeniu: `:\s*(?!var\()` przepuszcza „color: var(…)", bo `\s*` cofa się do
   zera znaków i lookahead patrzy na spację. Ta pułapka kosztowała tu jedno
   fałszywe zgłoszenie na każdą regułę w paczce. */
const DOZWOLONA_WARTOSC = /^(var\(|currentColor|inherit|transparent|none|initial|unset|clamp\(var|ButtonText|Highlight|calc\(var)/i;
const WLASNOSCI_KOLORU = /(?:^|[\s;{])(color|background-color|border-color|outline-color|fill|stroke)\s*:\s*([^;{}]+)/g;
const WLASNOSC_ROZMIARU = /(?:^|[\s;{])(font-size)\s*:\s*([^;{}]+)/g;

const zlaWartosc = (linia, wyrazenie) => {
  wyrazenie.lastIndex = 0;
  let m;
  while ((m = wyrazenie.exec(linia)) !== null) {
    if (!DOZWOLONA_WARTOSC.test(m[2].trim())) return `${m[1]}: ${m[2].trim()}`;
  }
  return null;
};

for (const p of pliki) {
  const rozsz = extname(p);
  if (!['.html', '.css', '.svg'].includes(rozsz)) continue;
  const tresc = readFileSync(p, 'utf8');
  const linie = tresc.split('\n');

  /* W pliku HTML wartość stylu może siedzieć tylko w bloku <style> — atrybut
     `style=` jest zakazany osobno. Hex w TREŚCI strony to dokumentacja palety
     (galeria wypisuje wartości tokenów), więc nie jest błędem. */
  const wStylu = new Set();
  if (rozsz === '.html' || rozsz === '.svg') {
    let wewnatrz = rozsz === '.svg';
    linie.forEach((l, i) => {
      if (/<style\b/i.test(l)) wewnatrz = true;
      if (wewnatrz) wStylu.add(i + 1);
      if (/<\/style>/i.test(l)) wewnatrz = false;
    });
  }
  const sprawdzacWartosci = (nr) => rozsz === '.css' || wStylu.has(nr);

  linie.forEach((l, i) => {
    const nr = i + 1;

    if (/\sstyle\s*=\s*["']/.test(l)) zglos(bledy, p, nr, 'atrybut style=', l);

    if (/<script\b/i.test(l) && !/type\s*=\s*["']application\/ld\+json["']/i.test(l)) {
      zglos(bledy, p, nr, 'skrypt w widoku', l);
    }
    if (/\son[a-z]+\s*=\s*["']/i.test(l)) zglos(bledy, p, nr, 'obsługa zdarzenia w atrybucie', l);

    if (!wolnoWartosci(p) && sprawdzacWartosci(nr)) {
      if (HEX.test(l)) zglos(bledy, p, nr, 'kolor napisany z ręki', l);
      const zlyKolor = zlaWartosc(l, WLASNOSCI_KOLORU);
      if (zlyKolor) zglos(bledy, p, nr, 'kolor nie z tokenu', zlyKolor);
      const zlyRozmiar = zlaWartosc(l, WLASNOSC_ROZMIARU);
      if (zlyRozmiar) zglos(bledy, p, nr, 'rozmiar tekstu nie z tokenu', zlyRozmiar);
    }
  });

  /* Fragmenty do wklejenia (sprite ikon) nie mają <html> i nie powinny mieć. */
  if (rozsz === '.html' && /<html\b/i.test(tresc) && !/<html\s+lang="pl"/.test(tresc)) {
    zglos(bledy, p, 1, 'brak lang="pl"', '<html>');
  }
}

/* ------------------------------------------------------------------ */
/* Słowa zakazane — wyłącznie w TEKŚCIE WIDOCZNYM                       */
/* ------------------------------------------------------------------ */
/* Sprawdzamy tekst po usunięciu znaczników, stylów, skryptów i komentarzy.
   Powód: `--container-content` i `.karta-tresc` to nazwy w kodzie, a zakaz
   dotyczy napisów dla człowieka (BRAND_EXTENDED.md §2.2 mówi to wprost). */

const ZAKAZANE = [
  'content', 'explore', 'discover', 'engage', 'engagement', 'creator',
  'twórca treści', 'influencer', 'feed', 'tapnij', 'swipe', 'lajkuj',
  'upload', 'stories', 'reels', 'challenge', 'onboarding', 'dashboard',
  'hub', 'community', 'smart', 'AI-powered', 'zoptymalizowany', 'dedykowany',
  'ekosystem', 'synergia', 'wartość dodana', 'must-have', 'game changer',
  'must try', 'foodie', 'foodporn', 'pyszota', 'mniam', 'streak', 'passa',
  'odznaka', 'ranking użytkowników', 'ranking użytkownikow', 'senior', 'seniorzy', 'dla starszych', 'intuicyjny',
  'kulinarne inspiracje', 'zainspiruj się', 'jak u mamy',
];

const tekstZHtml = (html) =>
  html
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<svg[\s\S]*?<\/svg>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/gi, ' ');

for (const p of pliki) {
  const rozsz = extname(p);
  if (!['.html', '.md'].includes(rozsz)) continue;
  const surowe = readFileSync(p, 'utf8');
  const tekst = rozsz === '.html' ? tekstZHtml(surowe) : surowe;

  for (const slowo of ZAKAZANE) {
    const re = new RegExp(`(^|[^\\p{L}])${slowo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}[\\p{L}]{0,4}(?![\\p{L}])`, 'giu');
    const trafienia = tekst.match(re);
    if (!trafienia) continue;
    /* W .html to jest błąd twardy: to jest napis dla człowieka.
       W .md to uwaga — dokumentacja ma prawo cytować listę zakazaną. */
    const lista = rozsz === '.html' ? bledy : uwagi;
    zglos(lista, p, 0, `słowo zakazane: „${slowo}" (${trafienia.length}×)`, trafienia.slice(0, 3).join(' · '));
  }

  /* Wykrzyknik w produkcie jest zakazany twardo; w materiałach drukowanych
     wolno najwyżej jeden na materiał. */
  const wykrzykniki = (tekst.match(/!(?![=\]])/g) || []).length;
  if (rozsz === '.html' && wykrzykniki > 0) {
    const drukowany = sciezka(p).includes('materialy-drukowane');
    if (!drukowany || wykrzykniki > 1) {
      zglos(drukowany ? uwagi : bledy, p, 0, `wykrzykniki: ${wykrzykniki}`, 'zero w produkcie, maks. 1 w druku');
    }
  }
}

/* ------------------------------------------------------------------ */
const wypisz = (naglowek, lista) => {
  console.log(`\n${naglowek}: ${lista.length}`);
  for (const b of lista) console.log(`  ${b.plik}:${b.linia || '-'}  [${b.regula}]  ${b.tresc}`);
};

console.log(`Sprawdzono plików: ${pliki.length}`);
wypisz('BŁĘDY (twarde reguły)', bledy);
wypisz('Do przejrzenia (dokumentacja)', uwagi);
console.log(bledy.length === 0 ? '\nWszystkie twarde reguły przechodzą.' : '\nSą złamane twarde reguły — popraw przed wydaniem.');
process.exit(bledy.length === 0 ? 0 : 1);
