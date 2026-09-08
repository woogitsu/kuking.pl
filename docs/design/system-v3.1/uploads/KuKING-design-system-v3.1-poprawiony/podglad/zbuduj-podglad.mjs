#!/usr/bin/env node
/*
 * zbuduj-podglad.mjs — składa `podglad/podglad.css` z arkuszy systemu.
 *
 * Po co: `01-fundamenty/tokens.css` jest arkuszem Tailwinda 4 w konfiguracji
 * CSS-first — ma `@import "tailwindcss"` i blok `@theme`, których przeglądarka
 * sama nie rozumie. Makiety w `03-szablony/`, `04-strona-www/` i `05-social-media/`
 * mają się otwierać podwójnym kliknięciem, bez instalowania czegokolwiek.
 *
 * Skrypt robi dokładnie cztery rzeczy i nic więcej:
 *   1. usuwa `@import "tailwindcss";`
 *   2. dokłada w jego miejsce MINIMALNY odpowiednik resetu Tailwinda
 *   3. zamienia `@theme { … }` na `:root { … }`
 *   4. skleja tokeny z komponentami
 *
 * Punkt 2 nie jest ozdobą i kosztował tu jedną rundę poprawek. Arkusz
 * produkcyjny dostaje z Tailwinda `box-sizing: border-box` na wszystkim.
 * Bez tego `.app-body` z `width: 100%` i wcięciem po bokach jest szersze od
 * okna dokładnie o sumę wcięć — i KAŻDA makieta przewijała się w poziomie
 * o 32 px przy 320 px i o 48 px przy 768 px. Wyłapał to
 * `07-wdrozenie/sprawdz-uklad.mjs`, nie oko.
 *
 * Wartości nie są przepisywane ręcznie, więc podgląd nie może się rozjechać
 * z arkuszem produkcyjnym — to była jedna z rzeczy, na których rozbił się
 * kit v2 (makieta mówiła co innego niż kod).
 *
 *   node podglad/zbuduj-podglad.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const katalog = join(dirname(fileURLToPath(import.meta.url)), '..');

const bezTailwinda = (css) =>
  css
    .replace(/^@import\s+["']tailwindcss["'];\s*$/m, '')
    .replace(/^@theme\s*\{/m, ':root {')
    .replace(':root[data-theme="dark"],', ':root[data-theme="dark"],\n:root[data-preview="true"]:has(.motyw-demo-input:checked),');

/* Minimalny odpowiednik `@layer theme, base` Tailwinda — TYLKO dla podglądu.
   W serwisie te reguły przychodzą z `@import "tailwindcss"` i nie ma ich
   w żadnym pliku tej paczki. */
const RESET_ZAMIAST_TAILWINDA = `
@layer base {
  *, *::before, *::after { box-sizing: border-box; }
  * { margin: 0; }
  img, svg, video { display: block; max-width: 100%; }
  img, video { height: auto; }
  button, input, select, textarea { font: inherit; color: inherit; }
  button { background: none; border: 0; }
  ul, ol { list-style: none; padding: 0; }
  table { border-collapse: collapse; }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
}
`;

const czesci = [
  '/* WYGENEROWANE przez podglad/zbuduj-podglad.mjs — nie edytuj tego pliku.\n' +
    '   Źródła: 01-fundamenty/tokens.css + 02-komponenty/komponenty.css */\n',
  RESET_ZAMIAST_TAILWINDA,
  bezTailwinda(readFileSync(join(katalog, '01-fundamenty/tokens.css'), 'utf8')),
  bezTailwinda(readFileSync(join(katalog, '02-komponenty/komponenty.css'), 'utf8')),
];

const wynik = join(katalog, 'podglad/podglad.css');
writeFileSync(wynik, czesci.join('\n'));
console.log(`Zapisano ${wynik} (${czesci.join('\n').length} znaków).`);
