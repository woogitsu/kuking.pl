import { chromium } from 'playwright';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8123';
const EXE = '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';
const przegladarka = await chromium.launch({ executablePath: EXE, headless: true });

/* CSP (`style-src 'self' 'nonce-…'`) blokuje wstrzyknięcie <style>, więc
   reguły dokładamy przez CSSOM do arkusza, który strona już załadowała —
   tego CSP nie dotyczy. */
async function zmierz({ szerokosc, wysokosc, skala, bezKolazu, reguly = [], czcionka }) {
  const kontekst = await przegladarka.newContext({ viewport: { width: szerokosc, height: wysokosc }, deviceScaleFactor: 1 });
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
      const el = document.querySelector('.hero-kolaz-blok');
      if (el) el.style.setProperty('display', 'none', 'important');
    });
  }
  if (reguly.length) {
    const ok = await strona.evaluate((r) => {
      const ark = [...document.styleSheets].find((a) => { try { return a.cssRules.length > 0; } catch { return false; } });
      if (!ark) return false;
      r.forEach((regula) => ark.insertRule(regula, ark.cssRules.length));
      return true;
    }, reguly);
    if (!ok) throw new Error('Nie udało się dołożyć reguł — pomiar byłby fałszywy.');
  }

  await strona.waitForTimeout(250);

  const wynik = await strona.evaluate(() => {
    const przycisk = [...document.querySelectorAll('.hero-akcje .btn')].find((a) => a.textContent.includes('Zostań'));
    const r = przycisk ? przycisk.getBoundingClientRect() : null;

    const siatka = document.querySelector('.landing-wpisy');
    const karty = siatka ? [...siatka.children].map((k) => {
      const b = k.getBoundingClientRect();
      return { top: Math.round(b.top + window.scrollY), bottom: Math.round(b.bottom + window.scrollY), h: Math.round(b.height) };
    }) : [];

    let dziury = 0;
    const rzedy = {};
    karty.forEach((k) => { (rzedy[k.top] ||= []).push(k); });
    Object.values(rzedy).forEach((rzad) => {
      const max = Math.max(...rzad.map((k) => k.h));
      rzad.forEach((k) => { dziury += max - k.h; });
    });

    const sr = siatka ? siatka.getBoundingClientRect() : null;
    const gornaKrawedzSekcji = sr ? Math.round(sr.top + window.scrollY) : 0;

    return {
      przyciskDol: r ? Math.round(r.bottom + window.scrollY) : null,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      kolazWidoczny: (() => {
        const el = document.querySelector('.hero-kolaz-blok');
        return el ? getComputedStyle(el).display !== 'none' : false;
      })(),
      kolumny: siatka ? getComputedStyle(siatka).gridTemplateColumns : null,
      heroWysokosc: Math.round(document.querySelector('.hero').getBoundingClientRect().height),
      wpisow: karty.length,
      // ile KART mieści się w całości na jednym ekranie liczonym od góry sekcji
      wpisowNaEkran: karty.filter((k) => k.bottom - gornaKrawedzSekcji <= window.innerHeight).length,
      wysokoscSiatki: sr ? Math.round(sr.height) : null,
      dziuryPx: dziury,
      dokumentWysokosc: document.documentElement.scrollHeight,
    };
  });

  await kontekst.close();
  return wynik;
}

const raport = { przycisk: [], uklad: [], wpisy: [] };

for (const [w, h] of [[320, 568], [360, 640], [360, 740], [414, 896]]) {
  for (const skala of [null, '140']) {
    const z = await zmierz({ szerokosc: w, wysokosc: h, skala });
    const bez = await zmierz({ szerokosc: w, wysokosc: h, skala, bezKolazu: true });
    raport.przycisk.push({
      okno: `${w}x${h}`, skala: skala || '100',
      dolZKolazem: z.przyciskDol, dolBezKolazu: bez.przyciskDol,
      mieciSie: z.przyciskDol <= h, kolazWidoczny: z.kolazWidoczny, hero: z.heroWysokosc,
    });
  }
}

for (const w of [320, 360, 414, 768, 900, 1024, 1280, 1512]) {
  for (const [nazwa, px] of [['100%', 16], ['200% (CSSOM)', 32]]) {
    const r = await zmierz({ szerokosc: w, wysokosc: 800, czcionka: px });
    raport.uklad.push({ szerokosc: w, czcionka: nazwa, scrollWidth: r.scrollWidth, clientWidth: r.clientWidth, przewijaWBok: r.scrollWidth > r.clientWidth + 1, kolazWidoczny: r.kolazWidoczny });
  }
}

const WARIANTY = {
  'dzis — dwie kolumny, rzędy': [],
  'jedna kolumna (pełna szerokość pasa)': ['@media (min-width:64rem){.landing-wpisy{grid-template-columns:minmax(0,1fr) !important}}'],
  'jedna kolumna, węższa (44rem)': ['@media (min-width:64rem){.landing-wpisy{grid-template-columns:minmax(0,1fr) !important;max-width:44rem;margin-inline:auto}}'],
  'dwie kolumny CSS (columns), niezależne': ['@media (min-width:64rem){.landing-wpisy{display:block !important;columns:2;column-gap:1.25rem}}', '@media (min-width:64rem){.landing-wpisy > *{break-inside:avoid;margin-bottom:1.25rem}}'],
};

for (const [nazwa, reguly] of Object.entries(WARIANTY)) {
  const r = await zmierz({ szerokosc: 1280, wysokosc: 800, reguly });
  raport.wpisy.push({ wariant: nazwa, kolumny: r.kolumny, wysokoscSiatki: r.wysokoscSiatki, dziuryPx: r.dziuryPx, wpisowNaEkran: r.wpisowNaEkran, wpisow: r.wpisow, dokument: r.dokumentWysokosc });
}

console.log(JSON.stringify(raport, null, 2));
await przegladarka.close();
