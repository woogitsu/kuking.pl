/*
 * Pomiar doraźny do D-122 — NIE jest częścią automatu dostępności.
 *
 * Mierzy szerokość kolumny treści i położenie bloków prawej szyny na tym
 * samym adresie w dwóch stanach: „przed" (klasy układu z tej zmiany zdjęte
 * z DOM-u, czyli dokładnie kształt dokumentu sprzed poprawki) i „po".
 * Ten sam arkusz w obu przebiegach, więc różnica pochodzi wyłącznie z klas.
 *
 * Uruchomienie: node scripts/pomiar-szyny-goscia.mjs http://127.0.0.1:8321
 */
import { chromium } from 'playwright-core';

const ADRES = process.argv[2] ?? 'http://127.0.0.1:8321';
const CHROMIUM = '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';

const EKRANY = [
  { nazwa: '/napisz-do-nas (gość)', adres: '/napisz-do-nas' },
  { nazwa: '/szukaj?q=zupa (gość)', adres: '/szukaj?q=zupa' },
  { nazwa: '/@basia (gość)', adres: '/@basia' },
  { nazwa: '/odkryj (gość, bez szyny)', adres: '/odkryj' },
  { nazwa: '/login (gość, bez szyny)', adres: '/login' },
];

const SZEROKOSCI = [1920, 1280];

const przegladarka = await chromium.launch({ executablePath: CHROMIUM, headless: true });

function pomiar() {
  const zaokr = (x) => (x === null ? null : Math.round(x));
  const body = document.querySelector('.app-body');
  const main = document.querySelector('.app-main');
  const rail = document.querySelector('.app-rail');
  const belka = document.querySelector('.topbar-inner');
  const logotyp = document.querySelector('.wordmark');
  const blok = document.querySelector('.app-rail .szyna-blok, .app-rail > *');
  const r = (el) => (el ? el.getBoundingClientRect() : null);

  const rMain = r(main);
  const rRail = r(rail);
  const rBlok = r(blok);
  const rBelka = r(belka);
  const stylBelki = belka ? getComputedStyle(belka) : null;

  return {
    klasyBody: document.body.className.trim(),
    klasyUkladu: body ? body.className.trim() : null,
    trescSzerokosc: zaokr(rMain?.width ?? null),
    trescLewa: zaokr(rMain?.left ?? null),
    trescPrawa: zaokr(rMain?.right ?? null),
    szynaJest: rail !== null,
    szynaSzerokosc: zaokr(rRail?.width ?? null),
    szynaLewa: zaokr(rRail?.left ?? null),
    szynaGora: zaokr(rRail?.top ?? null),
    // Kluczowa liczba ze zgłoszenia: czy pierwszy blok szyny stoi OBOK
    // treści (ta sama wysokość) czy POD nią.
    blokGora: zaokr(rBlok?.top ?? null),
    blokObokTresci: rBlok !== null && rMain !== null ? rBlok.top < rMain.bottom - 40 : null,
    // Belka: lewa krawędź logotypu kontra lewa krawędź wnętrza belki.
    belkaLewa: rBelka && stylBelki ? zaokr(rBelka.left + parseFloat(stylBelki.paddingLeft)) : null,
    belkaPrawa: rBelka && stylBelki ? zaokr(rBelka.right - parseFloat(stylBelki.paddingRight)) : null,
    logotypLewa: zaokr(r(logotyp)?.left ?? null),
    przewijaWBok: document.documentElement.scrollWidth > window.innerWidth + 1,
  };
}

/** Zdejmuje klasy dodane w D-122 — odtwarza dokument sprzed poprawki. */
function przed() {
  document.body.classList.remove('uklad-solo-z-szyna');
  document.querySelector('.app-body')?.classList.remove('app-body-solo-z-szyna');
}

for (const szerokosc of SZEROKOSCI) {
  console.log(`\n=== okno ${szerokosc} px ===`);

  for (const ekran of EKRANY) {
    const kontekst = await przegladarka.newContext({ viewport: { width: szerokosc, height: 1080 } });
    const strona = await kontekst.newPage();
    const odp = await strona.goto(`${ADRES}${ekran.adres}`, { waitUntil: 'load' });

    if ((odp?.status() ?? 0) !== 200) {
      console.log(`  ${ekran.nazwa}: HTTP ${odp?.status()}`);
      await kontekst.close();
      continue;
    }

    await strona.evaluate(przed);
    const a = await strona.evaluate(pomiar);

    await strona.reload({ waitUntil: 'load' });
    const b = await strona.evaluate(pomiar);

    console.log(`  ${ekran.nazwa}`);
    console.log(`      klasy po:  ${b.klasyUkladu} | body: ${b.klasyBody}`);
    console.log(`      treść     przed ${a.trescSzerokosc} px (${a.trescLewa}–${a.trescPrawa})`
      + `   po ${b.trescSzerokosc} px (${b.trescLewa}–${b.trescPrawa})`);
    console.log(`      szyna     przed ${a.szynaJest ? `${a.szynaSzerokosc} px, x=${a.szynaLewa}, y=${a.szynaGora}` : 'brak'}`
      + `   po ${b.szynaJest ? `${b.szynaSzerokosc} px, x=${b.szynaLewa}, y=${b.szynaGora}` : 'brak'}`);
    console.log(`      1. blok   przed y=${a.blokGora} (obok treści: ${a.blokObokTresci})`
      + `   po y=${b.blokGora} (obok treści: ${b.blokObokTresci})`);
    console.log(`      belka     przed ${a.belkaLewa}–${a.belkaPrawa}, logotyp x=${a.logotypLewa}`
      + `   po ${b.belkaLewa}–${b.belkaPrawa}, logotyp x=${b.logotypLewa}`);
    console.log(`      przewijanie w bok: przed ${a.przewijaWBok} / po ${b.przewijaWBok}`);

    await kontekst.close();
  }
}

await przegladarka.close();
