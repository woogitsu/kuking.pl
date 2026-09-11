import { chromium } from 'playwright';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8123';
const EXE = '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';

const SZEROKOSCI = [320, 360, 414, 768, 1024, 1280, 1512];

const przegladarka = await chromium.launch({ executablePath: EXE, headless: true });

async function zmierz({ szerokosc, wysokosc, skala, bezKolazu, wariantWpisow, czcionka }) {
  const kontekst = await przegladarka.newContext({
    viewport: { width: szerokosc, height: wysokosc },
    deviceScaleFactor: 1,
  });
  const strona = await kontekst.newPage();
  await strona.goto(ADRES, { waitUntil: 'networkidle' });

  if (czcionka && czcionka !== 16) {
    await strona.evaluate((px) => { document.documentElement.style.fontSize = px + 'px'; }, czcionka);
  }
  if (skala) {
    await strona.evaluate((s) => document.documentElement.setAttribute('data-text-scale', s), skala);
  }
  if (bezKolazu) {
    await strona.evaluate(() => {
      const s = document.createElement('style');
      s.textContent = '.hero-kolaz-blok{display:none !important}';
      document.head.appendChild(s);
    });
  }
  if (wariantWpisow === 'jedna-kolumna') {
    await strona.evaluate(() => {
      const s = document.createElement('style');
      s.textContent = '@media (min-width:64rem){.landing-wpisy{grid-template-columns:minmax(0,1fr) !important;max-width:44rem;margin-inline:auto}}';
      document.head.appendChild(s);
    });
  }
  if (wariantWpisow === 'kolumny-css') {
    await strona.evaluate(() => {
      const s = document.createElement('style');
      s.textContent = '@media (min-width:64rem){.landing-wpisy{display:block !important;columns:2;column-gap:1.25rem}.landing-wpisy > *{break-inside:avoid;margin-bottom:1.25rem}}';
      document.head.appendChild(s);
    });
  }

  await strona.waitForTimeout(250);

  const wynik = await strona.evaluate(() => {
    const przycisk = [...document.querySelectorAll('.hero-akcje .btn')]
      .find((a) => a.textContent.includes('Zostań'));
    const r = przycisk ? przycisk.getBoundingClientRect() : null;

    const karty = [...document.querySelectorAll('.landing-wpisy > *')].map((k) => {
      const b = k.getBoundingClientRect();
      return { top: Math.round(b.top + window.scrollY), bottom: Math.round(b.bottom + window.scrollY), h: Math.round(b.height) };
    });

    const sekcjaWpisow = document.querySelector('.landing-wpisy');
    const sr = sekcjaWpisow ? sekcjaWpisow.getBoundingClientRect() : null;

    // Puste pole w siatce: powierzchnia prostokąta siatki minus suma
    // powierzchni kart (bez odstępów) — liczona tylko w pionie, per karta.
    let dziury = 0;
    if (sekcjaWpisow && karty.length) {
      const rzedy = {};
      karty.forEach((k) => { (rzedy[k.top] ||= []).push(k); });
      Object.values(rzedy).forEach((rzad) => {
        const max = Math.max(...rzad.map((k) => k.h));
        rzad.forEach((k) => { dziury += max - k.h; });
      });
    }

    return {
      przyciskDol: r ? Math.round(r.bottom + window.scrollY) : null,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      kolazJest: !!document.querySelector('.hero-kolaz'),
      kolazWidoczny: (() => {
        const el = document.querySelector('.hero-kolaz-blok');
        if (!el) return false;
        return getComputedStyle(el).display !== 'none';
      })(),
      heroWysokosc: Math.round(document.querySelector('.hero').getBoundingClientRect().height),
      wpisowNaEkranie: karty.filter((k) => k.bottom <= (sr ? sr.top + window.scrollY : 0) + 0 + window.innerHeight - 0 && k.top >= 0).length,
      wpisow: karty.length,
      wysokoscSekcjiWpisow: sr ? Math.round(sr.height) : null,
      dziuryPx: dziury,
      dokumentWysokosc: document.documentElement.scrollHeight,
    };
  });

  await kontekst.close();
  return wynik;
}

const raport = {};

// 1. Przycisk „Zostań kuKINGiem" na pierwszym ekranie telefonu.
raport.przycisk = [];
for (const [w, h] of [[320, 568], [360, 640], [360, 740], [414, 896]]) {
  for (const skala of [null, '140']) {
    const zKolazem = await zmierz({ szerokosc: w, wysokosc: h, skala });
    const bez = await zmierz({ szerokosc: w, wysokosc: h, skala, bezKolazu: true });
    raport.przycisk.push({
      okno: `${w}x${h}`, skala: skala || '100',
      dolPrzyciskuZKolazem: zKolazem.przyciskDol,
      dolPrzyciskuBezKolazu: bez.przyciskDol,
      mieciSieNaEkranie: zKolazem.przyciskDol <= h,
      kolazWidoczny: zKolazem.kolazWidoczny,
    });
  }
}

// 2. Przewijanie w bok.
raport.uklad = [];
for (const w of SZEROKOSCI) {
  for (const [nazwaCzcionki, px] of [['100%', 16], ['200%', 32]]) {
    const r = await zmierz({ szerokosc: w, wysokosc: 800, czcionka: px });
    raport.uklad.push({
      szerokosc: w, czcionka: nazwaCzcionki,
      scrollWidth: r.scrollWidth, clientWidth: r.clientWidth,
      przewijaWBok: r.scrollWidth > r.clientWidth + 1,
      kolazWidoczny: r.kolazWidoczny,
    });
  }
}

// 3. „Świeżo z Kuking" — trzy warianty na typowym ekranie komputera.
raport.wpisy = [];
for (const wariant of [null, 'jedna-kolumna', 'kolumny-css']) {
  const r = await zmierz({ szerokosc: 1280, wysokosc: 800, wariantWpisow: wariant });
  raport.wpisy.push({
    wariant: wariant || 'dzis (dwie kolumny, rzędy)',
    wysokoscSekcji: r.wysokoscSekcjiWpisow,
    dziuryPx: r.dziuryPx,
    wpisow: r.wpisow,
    dokumentWysokosc: r.dokumentWysokosc,
  });
}

console.log(JSON.stringify(raport, null, 2));
await przegladarka.close();
