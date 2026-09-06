/*
 * Generator ikon PWA z jednego źródła — znaku „Uśmiech".
 *
 * DLACZEGO SKRYPT, A NIE RĘCZNIE
 * Ikon jest sześć (dwa SVG + cztery PNG) i wszystkie muszą pokazywać ten sam
 * kształt. Przy ręcznym robieniu pierwsza pomyłka jest niewidoczna do momentu,
 * w którym ktoś doda Kuking do ekranu głównego telefonu i zobaczy poprzednie
 * logo. Geometria jest tu wpisana RAZ.
 *
 * Uruchomienie:  node scripts/generuj-ikony.mjs
 * Wymaga:        npx playwright (Chromium jest w obrazie deweloperskim)
 *
 * Skrypt nie chodzi w CI ani w buildzie — ikony są plikami w repozytorium.
 * Odpalasz go tylko wtedy, gdy zmienia się znak.
 */
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const MARKA = '#B3401F';
const TLO = '#F7F4EE';

/** Kształt znaku w układzie 64×64. Jedno źródło prawdy dla wszystkich ikon. */
const ksztalt = (polysk) => `
  <path d="M18 22 L16 15 L24 19 L32 11 L40 19 L48 15 L46 22 C39 25 25 25 18 22 Z"
        fill="${MARKA}" stroke="${MARKA}" stroke-width="2" stroke-linejoin="round"/>
  <circle cx="16" cy="14" r="3.5" fill="${MARKA}"/>
  <circle cx="32" cy="9" r="3.5" fill="${MARKA}"/>
  <circle cx="48" cy="14" r="3.5" fill="${MARKA}"/>
  <path d="M17 29 H47 V38 C47 49 41 54 32 54 C23 54 17 49 17 38 Z" fill="${MARKA}"/>
  <path d="M17 32 C12 29 9 31 9 36 C9 42 13 44 18 42" fill="none" stroke="${MARKA}"
        stroke-width="5" stroke-linecap="round"/>
  <path d="M47 32 C52 29 55 31 55 36 C55 42 51 44 46 42" fill="none" stroke="${MARKA}"
        stroke-width="5" stroke-linecap="round"/>
  <path d="M24 43 C28 47 36 47 40 41" fill="none" stroke="${polysk}"
        stroke-width="3.5" stroke-linecap="round"/>
  <path d="M18 28 C27 30 37 30 46 28" fill="none" stroke="${polysk}"
        stroke-width="3" stroke-linecap="round"/>
`;

/*
 * `skala` to jedyna różnica między wariantem zwykłym a maskowalnym.
 *
 * Android przycina ikonę maskowalną do dowolnego kształtu — koła, kwadratu
 * z zaokrągleniem, kropli. Bezpieczna jest tylko środkowa część o średnicy
 * 80% krawędzi, więc znak musi się w niej zmieścić Z ZAPASEM. Przy skali 1.0
 * uchwyty garnka wychodzą poza okrąg i telefon obcina je w połowie.
 */
const dokument = (skala) => {
  const bok = 64 / skala;
  const przesuniecie = (bok - 64) / 2;
  return `<?xml version="1.0" encoding="UTF-8"?>
<!-- WYGENEROWANE przez scripts/generuj-ikony.mjs — nie edytuj ręcznie.
     Zmieniasz znak? Popraw kształt w skrypcie i uruchom go ponownie,
     inaczej sześć ikon rozjedzie się między sobą. -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${bok} ${bok}"
     width="512" height="512" role="img" aria-label="Kuking">
  <title>Kuking</title>
  <rect width="${bok}" height="${bok}" fill="${TLO}"/>
  <g transform="translate(${przesuniecie} ${przesuniecie})">${ksztalt(TLO)}</g>
</svg>
`;
};

const warianty = {
  'public/icons/kuking-icon-any.svg': dokument(0.9),
  'public/icons/kuking-icon-maskable.svg': dokument(0.62),
};

for (const [sciezka, tresc] of Object.entries(warianty)) {
  writeFileSync(sciezka, tresc);
  console.log('zapisano', sciezka);
}

// Obraz deweloperski ma Chromium pod stałą ścieżką i NIE ma tej wersji,
// której akurat szuka paczka playwright. Wskazujemy binarkę wprost —
// inaczej skrypt każe pobierać przeglądarkę, której nie da się pobrać.
const przegladarka = await chromium.launch({
  executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
});
const strona = await przegladarka.newPage();

for (const [zrodlo, nazwa] of [
  ['public/icons/kuking-icon-any.svg', 'kuking-icon-%d.png'],
  ['public/icons/kuking-icon-maskable.svg', 'kuking-icon-%d-maskable.png'],
]) {
  for (const rozmiar of [192, 512]) {
    await strona.setViewportSize({ width: rozmiar, height: rozmiar });
    await strona.setContent(
      `<style>html,body{margin:0;padding:0}img{display:block;width:${rozmiar}px;height:${rozmiar}px}</style>` +
      `<img src="data:image/svg+xml;base64,${Buffer.from(warianty[zrodlo]).toString('base64')}">`,
    );
    const plik = 'public/icons/' + nazwa.replace('%d', String(rozmiar));
    await strona.screenshot({ path: plik, omitBackground: false });
    console.log('zapisano', plik);
  }
}

/*
 * KARTA DO UDOSTĘPNIANIA (issue #14) — 1200×630.
 *
 * Trafia jako `og:image` na strony bez własnego zdjęcia. MUSI być PNG:
 * Facebook, WhatsApp i Signal nie renderują SVG i pokazują wtedy pustą
 * ramkę zamiast karty.
 *
 * 1200×630 to nie jest liczba wzięta z sufitu — na tym formacie wszystkie
 * te serwisy pokazują duży podgląd. Przy innych proporcjach przycinają
 * obrazek po swojemu i napis wychodzi poza kadr.
 */
await strona.setViewportSize({ width: 1200, height: 630 });
await strona.setContent(`
  <style>
    html,body{margin:0;padding:0}
    .karta{
      width:1200px;height:630px;background:${TLO};
      display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;
      font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
    }
    .nazwa{font-size:92px;font-weight:850;letter-spacing:-3px;color:#2B241D}
    .nazwa span{color:${MARKA}}
    .haslo{font-size:34px;color:#5C5347}
  </style>
  <div class="karta">
    <svg width="180" height="180" viewBox="0 0 64 64" fill="none">${ksztalt(TLO)}</svg>
    <div class="nazwa">KuKing<span>.pl</span></div>
    <div class="haslo">Pokaż, co dziś ugotowałeś</div>
  </div>
`);
await strona.screenshot({ path: 'public/icons/kuking-udostepnianie.png' });
console.log('zapisano public/icons/kuking-udostepnianie.png');

/*
 * favicon.ico — 16×16 i 32×32 w jednym pliku (issue #5).
 *
 * PO CO TO JESZCZE ISTNIEJE, SKORO JEST FAVIKONA SVG
 * Przeglądarki od lat wolą `<link rel="icon" type="image/svg+xml">`, ale
 * `/favicon.ico` jest pobierany BEZWARUNKOWO i przez rzeczy, które o naszym
 * <head> nic nie wiedzą: wyniki wyszukiwania, czytniki kanałów, podglądy
 * w komunikatorach, starsze przeglądarki. Plik w repozytorium już był —
 * i po zmianie znaku pokazywałby poprzednie logo w miejscach, w których
 * nikt by tego nie szukał.
 *
 * Tło kremowe, nie przezroczyste: ikona 16 px na ciemnym pasku zakładek
 * z przezroczystym tłem gubi kontury.
 */
const ikonyIco = [];
for (const rozmiar of [16, 32]) {
  await strona.setViewportSize({ width: rozmiar, height: rozmiar });
  await strona.setContent(
    `<style>html,body{margin:0;padding:0}img{display:block;width:${rozmiar}px;height:${rozmiar}px}</style>` +
    `<img src="data:image/svg+xml;base64,${Buffer.from(warianty['public/icons/kuking-icon-any.svg']).toString('base64')}">`,
  );
  ikonyIco.push({ rozmiar, png: await strona.screenshot() });
}

/*
 * Format ICO: 6-bajtowy nagłówek, po 16 bajtów na wpis katalogu, potem dane.
 * Nowoczesne ICO wolno wypełnić PNG-ami zamiast bitmap BMP — to jest
 * wspierane od Visty i pozwala uniknąć ręcznego składania BMP z maską
 * przezroczystości.
 */
const naglowek = Buffer.alloc(6);
naglowek.writeUInt16LE(0, 0);                 // zarezerwowane
naglowek.writeUInt16LE(1, 2);                 // typ: 1 = ikona
naglowek.writeUInt16LE(ikonyIco.length, 4);   // liczba obrazków

let offset = 6 + ikonyIco.length * 16;
const katalog = [];
for (const { rozmiar, png } of ikonyIco) {
  const wpis = Buffer.alloc(16);
  wpis.writeUInt8(rozmiar, 0);        // szerokość
  wpis.writeUInt8(rozmiar, 1);        // wysokość
  wpis.writeUInt8(0, 2);              // liczba kolorów palety (0 = pełny kolor)
  wpis.writeUInt8(0, 3);              // zarezerwowane
  wpis.writeUInt16LE(1, 4);           // płaszczyzny kolorów
  wpis.writeUInt16LE(32, 6);          // bitów na piksel
  wpis.writeUInt32LE(png.length, 8);  // rozmiar danych
  wpis.writeUInt32LE(offset, 12);     // przesunięcie danych
  katalog.push(wpis);
  offset += png.length;
}

writeFileSync('public/favicon.ico', Buffer.concat([
  naglowek, ...katalog, ...ikonyIco.map((i) => i.png),
]));
console.log('zapisano public/favicon.ico (16 i 32 px)');

/*
 * Wariant jednokolorowy — do naklejek, ulotek i wszystkiego, co idzie
 * na drukarkę jednobarwną (koła gospodyń, biblioteki, UTW). Czytelny
 * od 20 mm, bo to jest realna wielkość naklejki na słoiku.
 *
 * `currentColor` jest tu POPRAWNY, w odróżnieniu od kuking-mark.svg:
 * ten plik idzie do programów graficznych i na wydruk, gdzie kolor i tak
 * ustawia człowiek, a nie przeglądarka.
 */
writeFileSync('public/icons/kuking-mark-mono.svg', `<?xml version="1.0" encoding="UTF-8"?>
<!-- WYGENEROWANE przez scripts/generuj-ikony.mjs — nie edytuj ręcznie.
     Wariant jednobarwny do druku. Bez połysku i uśmiechu: przy jednym
     kolorze te linie zlewają się z tłem garnka i znak robi się plamą. -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"
     role="img" aria-label="Kuking" fill="none">
  <title>Kuking</title>
  <path d="M18 22 L16 15 L24 19 L32 11 L40 19 L48 15 L46 22 C39 25 25 25 18 22 Z"
        fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
  <circle cx="16" cy="14" r="3.5" fill="currentColor"/>
  <circle cx="32" cy="9" r="3.5" fill="currentColor"/>
  <circle cx="48" cy="14" r="3.5" fill="currentColor"/>
  <path d="M17 29 H47 V38 C47 49 41 54 32 54 C23 54 17 49 17 38 Z" fill="currentColor"/>
  <path d="M17 32 C12 29 9 31 9 36 C9 42 13 44 18 42" stroke="currentColor"
        stroke-width="5" stroke-linecap="round"/>
  <path d="M47 32 C52 29 55 31 55 36 C55 42 51 44 46 42" stroke="currentColor"
        stroke-width="5" stroke-linecap="round"/>
</svg>
`);
console.log('zapisano public/icons/kuking-mark-mono.svg');

await przegladarka.close();
