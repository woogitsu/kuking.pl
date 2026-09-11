/*
 * Pomiar doraźny do D-122, DRUGI STAN TEGO SAMEGO EKRANU — zalogowany.
 *
 * D-106 i D-099: ten sam adres dla gościa i dla zalogowanego to dwa różne
 * ekrany. Ten skrypt mierzy tę drugą połowę i sprawdza, że zmiana dla gościa
 * nie ruszyła ani o piksel układu zalogowanego.
 *
 * Uruchomienie: node scripts/pomiar-szyny-zalogowany.mjs http://127.0.0.1:8321
 */
import { chromium } from 'playwright-core';

const ADRES = process.argv[2] ?? 'http://127.0.0.1:8321';
const CHROMIUM = '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';

const EKRANY = [
  { nazwa: '/home (z szyną)', adres: '/home' },
  { nazwa: '/napisz-do-nas (z szyną)', adres: '/napisz-do-nas' },
  { nazwa: '/szukaj?q=zupa (z szyną)', adres: '/szukaj?q=zupa' },
  { nazwa: '/@basia (z szyną)', adres: '/@basia' },
  { nazwa: '/powiadomienia (bez szyny)', adres: '/powiadomienia' },
];

const przegladarka = await chromium.launch({ executablePath: CHROMIUM, headless: true });

const kontekst = await przegladarka.newContext({ viewport: { width: 1920, height: 1080 } });
const logowanie = await kontekst.newPage();
await logowanie.goto(`${ADRES}/login`, { waitUntil: 'load' });
await logowanie.fill('input[name="login"]', 'ania@example.test');
await logowanie.fill('input[name="password"]', 'haslo-testowe-123');
await logowanie.click('button[type="submit"]');
await logowanie.waitForLoadState('load');
console.log(`zalogowany na: ${new URL(logowanie.url()).pathname}`);
await logowanie.close();

function pomiar() {
  const zaokr = (x) => (x === null || x === undefined ? null : Math.round(x));
  const r = (el) => (el ? el.getBoundingClientRect() : null);
  const body = document.querySelector('.app-body');
  const rMain = r(document.querySelector('.app-main'));
  const rRail = r(document.querySelector('.app-rail'));
  const rNav = r(document.querySelector('.side-nav'));
  const belka = document.querySelector('.topbar-inner');
  const rBelka = r(belka);
  const styl = belka ? getComputedStyle(belka) : null;

  return {
    klasy: body ? body.className.trim() : null,
    trescSzerokosc: zaokr(rMain?.width),
    trescLewa: zaokr(rMain?.left),
    trescPrawa: zaokr(rMain?.right),
    nawigacjaLewa: zaokr(rNav?.left),
    szynaLewa: zaokr(rRail?.left),
    szynaGora: zaokr(rRail?.top),
    belkaLewa: rBelka && styl ? zaokr(rBelka.left + parseFloat(styl.paddingLeft)) : null,
    belkaPrawa: rBelka && styl ? zaokr(rBelka.right - parseFloat(styl.paddingRight)) : null,
  };
}

for (const szerokosc of [1920, 1280]) {
  console.log(`\n=== okno ${szerokosc} px, ZALOGOWANY ===`);

  for (const ekran of EKRANY) {
    const strona = await kontekst.newPage();
    await strona.setViewportSize({ width: szerokosc, height: 1080 });
    const odp = await strona.goto(`${ADRES}${ekran.adres}`, { waitUntil: 'load' });

    if ((odp?.status() ?? 0) !== 200) {
      console.log(`  ${ekran.nazwa}: HTTP ${odp?.status()}`);
      await strona.close();
      continue;
    }

    const p = await strona.evaluate(pomiar);

    console.log(`  ${ekran.nazwa}`);
    console.log(`      klasy: ${p.klasy}`);
    console.log(`      treść ${p.trescSzerokosc} px (${p.trescLewa}–${p.trescPrawa}), `
      + `nawigacja x=${p.nawigacjaLewa}, szyna x=${p.szynaLewa} y=${p.szynaGora}, `
      + `belka ${p.belkaLewa}–${p.belkaPrawa}`);

    await strona.close();
  }
}

await kontekst.close();
await przegladarka.close();
