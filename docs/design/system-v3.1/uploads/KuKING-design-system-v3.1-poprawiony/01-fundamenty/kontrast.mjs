#!/usr/bin/env node
/*
 * kontrast.mjs — liczy kontrast WCAG 2.x dla par kolorów systemu Kuking.
 *
 * Odpowiednik `agents/ux/contrast.py` z repozytorium serwisu, przepisany na
 * Node, żeby paczka systemu projektowego dała się sprawdzić bez Pythona.
 * Formuła: relatywna luminancja WCAG 2.x, (L1 + 0.05) / (L2 + 0.05).
 *
 *   node 01-fundamenty/kontrast.mjs            # wszystkie pary, wynik na ekran
 *   node 01-fundamenty/kontrast.mjs --tylko-bledy
 *
 * Kod wyjścia 1, jeśli choć jedna para nie osiąga swojego progu — nadaje się
 * do CI.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const hexNaRgb = (hex) => {
  if (typeof hex !== 'string' || !/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(hex.trim())) {
    throw new Error(`Invalid or missing CSS color token: ${hex}`);
  }
  const h = hex.replace('#', '').trim();
  const p = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
  return [0, 2, 4].map((i) => parseInt(p.slice(i, i + 2), 16));
};

const luminancja = (hex) =>
  hexNaRgb(hex)
    .map((v) => v / 255)
    .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((suma, v, i) => suma + v * [0.2126, 0.7152, 0.0722][i], 0);

export const kontrast = (a, b) => {
  const [l1, l2] = [luminancja(a), luminancja(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
};

/* --- Palety ------------------------------------------------------------- */

// Read the actual stylesheet; the validator must not check a stale copy.
const css = readFileSync(new URL('./tokens.css', import.meta.url), 'utf8');
function paletaZCss(selector) {
  const body = css.match(selector)?.[1];
  if (!body) throw new Error('Missing palette in tokens.css');
  const entries = [...body.matchAll(/--(?:color-)?([a-z][a-z0-9-]*):\s*(#[0-9a-f]{3,6})\s*;/gi)]
    .map((m) => [m[1], m[2]]);
  const palette = Object.fromEntries(entries);
  if (!palette.surface || !palette.ink || !palette['scrim-ink']) {
    throw new Error('Incomplete palette in tokens.css');
  }
  return Object.freeze(palette);
}
export const JASNY = paletaZCss(/@theme\s*\{([\s\S]*?)\n\}/);
export const CIEMNY = paletaZCss(/:root\[data-theme="dark"\],\s*\.blok-ciemny\s*\{([\s\S]*?)\n\}/);

/* --- Pary do sprawdzenia ------------------------------------------------
   [ przód, tło, próg, opis ]
   Próg 4.5 = tekst; 3.0 = duży tekst (>=24px lub >=18.66px bold) i elementy UI.
   ---------------------------------------------------------------------- */

const PARY = [
  ['ink', 'surface', 4.5, 'tekst podstawowy na tle strony'],
  ['ink', 'surface-raised', 4.5, 'tekst podstawowy na karcie'],
  ['ink', 'surface-sunken', 4.5, 'tekst w polu formularza'],
  ['ink', 'surface-brand-wash', 4.5, 'tekst na tle sekcji marki (hero, pasek zachęty)'],
  ['ink-muted', 'surface', 4.5, 'metadane na tle strony'],
  ['ink-muted', 'surface-raised', 4.5, 'metadane na karcie'],
  ['ink-muted', 'surface-sunken', 4.5, 'plakietka cicha: tekst na wgłębionym tle'],
  ['ink-muted', 'surface-brand-wash', 4.5, 'metadane na tle sekcji marki'],
  ['brand', 'surface', 4.5, 'link i tekst marki na tle strony'],
  ['brand', 'surface-raised', 4.5, 'link i tekst marki na karcie'],
  ['brand', 'surface-sunken', 4.5, 'link marki w polu/wgłębieniu'],
  ['brand', 'surface-brand-wash', 4.5, 'link marki na tle sekcji marki'],
  ['ink-inverse', 'brand-solid', 4.5, 'biały napis na przycisku głównym'],
  ['ink-inverse', 'brand-solid-hover', 4.5, 'biały napis na przycisku głównym, najechanie'],
  ['brand-solid', 'surface', 3.0, 'obrys przycisku głównego wobec tła strony'],
  ['brand-tint-ink', 'brand-tint', 4.5, 'bieżąca pozycja nawigacji'],
  ['accent', 'surface', 4.5, 'tekst akcentu na tle strony'],
  ['accent', 'surface-raised', 4.5, 'tekst akcentu na karcie'],
  ['accent-tint-ink', 'accent-tint', 4.5, 'plakietka Ugotowałem'],
  ['danger', 'surface', 4.5, 'tekst błędu na tle strony'],
  ['danger', 'surface-raised', 4.5, 'tekst błędu na karcie'],
  ['ink-inverse', 'danger-solid', 4.5, 'biały napis na przycisku Usuń'],
  ['ink-inverse', 'danger-solid-hover', 4.5, 'biały napis na przycisku Usuń, najechanie'],
  ['danger-solid', 'surface', 3.0, 'obrys przycisku Usuń wobec tła strony'],
  ['danger-tint-ink', 'danger-tint', 4.5, 'podsumowanie błędów'],
  ['danger', 'surface-sunken', 3.0, 'ramka pola z błędem wobec tła pola'],
  ['success', 'surface', 4.5, 'tekst potwierdzenia na tle strony'],
  ['success-tint-ink', 'success-tint', 4.5, 'plakietka Szkic zapisany'],
  ['border-strong', 'surface', 3.0, 'obwódka pola i przycisku wtórnego na tle strony'],
  ['border-strong', 'surface-raised', 3.0, 'obwódka pola i przycisku wtórnego na karcie'],
  ['border-strong', 'surface-sunken', 3.0, 'obwódka pola wobec jego własnego tła'],
  ['focus', 'surface', 3.0, 'pierścień fokusu na tle strony'],
  ['focus', 'surface-raised', 3.0, 'pierścień fokusu na karcie'],
  ['focus', 'surface-sunken', 3.0, 'pierścień fokusu na polu formularza'],
  ['ink-inverse', 'scrim-ink', 4.5, 'biały napis na przyciemnieniu zdjęcia (podkład)'],
];

const format = (n) => `${n.toFixed(2)}:1`;

const sprawdz = (nazwaPalety, paleta) => {
  const wiersze = PARY.map(([przod, tlo, prog, opis]) => {
    const wartosc = kontrast(paleta[przod], paleta[tlo]);
    return { przod, tlo, prog, opis, wartosc, ok: wartosc >= prog };
  });
  return { nazwaPalety, wiersze };
};

// A portable main-module check works on Node 18/20/22, unlike import.meta.main
// before Node 22.18. Imports by zbuduj-tokeny.mjs remain side-effect-free.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const tylkoBledy = process.argv.includes('--tylko-bledy');
  let bledy = 0;

  for (const { nazwaPalety, wiersze } of [sprawdz('JASNY', JASNY), sprawdz('CIEMNY', CIEMNY)]) {
    console.log(`\n### Tryb ${nazwaPalety.toLowerCase()}\n`);
    console.log('| Para | Kontrast | Próg | Wynik | Gdzie |');
    console.log('|---|---|---|---|---|');
    for (const w of wiersze) {
      if (!w.ok) bledy += 1;
      if (tylkoBledy && w.ok) continue;
      console.log(
        `| ${w.przod} / ${w.tlo} | ${format(w.wartosc)} | ${w.prog.toFixed(1)}:1 | ${w.ok ? 'przechodzi' : 'NIE PRZECHODZI'} | ${w.opis} |`,
      );
    }
  }

  console.log(`\nSprawdzonych par: ${PARY.length * 2}. Nie przechodzi: ${bledy}.`);
  process.exit(bledy === 0 ? 0 : 1);
}
