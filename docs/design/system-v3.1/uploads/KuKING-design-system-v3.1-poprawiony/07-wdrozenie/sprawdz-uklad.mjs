#!/usr/bin/env node
/*
 * sprawdz-uklad.mjs — otwiera każdą makietę w prawdziwej przeglądarce i pilnuje
 * trzech obietnic, których nie da się sprawdzić czytaniem kodu:
 *
 *   1. NIGDY przewijania strony w poziomie — od 320 px w górę,
 *   2. to samo przy skali tekstu 140% i 150%,
 *   3. każdy przycisk, link nawigacji i pole ma co najmniej 48 px wysokości
 *      (obszar klikalny pola zaznaczenia: 44 px).
 *
 *   node 07-wdrozenie/sprawdz-uklad.mjs
 *
 * Kod wyjścia 1, jeśli którakolwiek obietnica jest złamana.
 * Wymaga Playwrighta i Chromium (PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers).
 */
import { readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const KORZEN = join(dirname(fileURLToPath(import.meta.url)), '..');

const SZEROKOSCI = [320, 390, 768, 1024, 1440];
const SKALE = ['100', '140', '150'];

/* Fragmenty i strona przeglądowa z ramkami mają własne reguły — pierwsza nie
   jest stroną, druga celowo pokazuje makietę 1440 px w mniejszym oknie. */
const POMIN = ['podglad/ikony-sprite.html', 'podglad/urzadzenia.html'];

const pliki = [];
(function zbierz(kat) {
  for (const wpis of readdirSync(kat)) {
    if (wpis === 'node_modules' || wpis === '.git') continue;
    const p = join(kat, wpis);
    if (statSync(p).isDirectory()) zbierz(p);
    else if (p.endsWith('.html')) pliki.push(p);
  }
})(KORZEN);

const wzgledna = (p) => relative(KORZEN, p).split('\\').join('/');
const doSprawdzenia = pliki.filter((p) => !POMIN.includes(wzgledna(p))).sort();

/* W tym repozytorium jest `@playwright/test`, a nie samo `playwright` —
   próbujemy obu, żeby skrypt działał też tam, gdzie zainstalowano tylko
   bibliotekę. */
let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
  try {
    ({ chromium } = await import('@playwright/test'));
  } catch { /* obsłużone niżej */ }
}
if (!chromium) {
  console.error('Playwright niedostępny — nie udało się sprawdzić układu w przeglądarce.');
  console.error('To NIE znaczy, że makiety są poprawne. Zainstaluj playwright i uruchom ponownie.');
  process.exit(2);
}

const przegladarka = await chromium.launch();
const bledy = [];
let sprawdzen = 0;

for (const plik of doSprawdzenia) {
  const adres = pathToFileURL(plik).href;
  for (const szerokosc of SZEROKOSCI) {
    const strona = await przegladarka.newPage({ viewport: { width: szerokosc, height: 900 } });
    await strona.goto(adres, { waitUntil: 'load' });

    for (const skala of SKALE) {
      await strona.evaluate((s) => {
        if (s === '100') document.documentElement.removeAttribute('data-text-scale');
        else document.documentElement.setAttribute('data-text-scale', s);
      }, skala);

      const rozjazd = await strona.evaluate(() => ({
        szerokoscDokumentu: document.documentElement.scrollWidth,
        szerokoscOkna: document.documentElement.clientWidth,
      }));
      sprawdzen += 1;
      if (rozjazd.szerokoscDokumentu > rozjazd.szerokoscOkna + 1) {
        bledy.push(
          `${wzgledna(plik)} @ ${szerokosc}px, tekst ${skala}%: strona przewija się w poziomie ` +
            `(${rozjazd.szerokoscDokumentu} > ${rozjazd.szerokoscOkna})`,
        );
      }
    }

    /* Rozmiary kontrolek mierzymy raz na szerokość, przy skali 100% —
       przy większej skali kontrolki tylko rosną. */
    await strona.evaluate(() => document.documentElement.removeAttribute('data-text-scale'));
    const zaMale = await strona.evaluate(() => {
      const wynik = [];
      const widoczny = (el) => el.getClientRects().length > 0;
      for (const el of document.querySelectorAll(
        '.btn, .chip, .side-nav-item, .bottom-nav-item, .field-input, .wybor-zdjecia',
      )) {
        if (!widoczny(el)) continue;
        const h = el.getBoundingClientRect().height;
        if (h < 47.5) wynik.push(`${el.className.split(' ')[0]} „${(el.textContent || '').trim().slice(0, 24)}" ma ${h.toFixed(0)}px`);
      }
      return wynik.slice(0, 5);
    });
    sprawdzen += 1;
    for (const z of zaMale) bledy.push(`${wzgledna(plik)} @ ${szerokosc}px: kontrolka poniżej 48px — ${z}`);

    await strona.close();
  }
}

await przegladarka.close();

console.log(`Sprawdzonych stron: ${doSprawdzenia.length}, pomiarów: ${sprawdzen}.`);
if (bledy.length === 0) {
  console.log('Żadna strona nie przewija się w poziomie i żadna kontrolka nie schodzi poniżej 48 px.');
} else {
  console.log(`\nZnalezione problemy: ${bledy.length}`);
  for (const b of bledy) console.log(`  ${b}`);
}
process.exit(bledy.length === 0 ? 0 : 1);
