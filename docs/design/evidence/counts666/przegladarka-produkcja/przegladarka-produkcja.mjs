import { chromium } from 'playwright';
import fs from 'fs';

const URL = 'https://kuking.pl/przepisy/bigos-z-cukinii';
const OUT = '/home/mateusz/kuking-odbior-pasek/out/prod666';
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const browser = await chromium.launch();

for (const theme of ['light', 'dark']) {
  for (const width of [320, 1440]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    // Podpowiedź pierwszej wizyty widgetu "Wygląd" przykrywa treść.
    // resources/js/szybki-wyglad.js l.198: hint.hidden = localStorage['kuking-wyglad-poznany'] === '1'
    await ctx.addInitScript(() => {
      try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch (e) {}
    });
    const page = await ctx.newPage();
    const resp = await page.goto(URL, { waitUntil: 'networkidle' });

    // Ciemny motyw ma DOKŁADNIE JEDNO wejście: atrybut data-theme="dark" na <html>
    // (resources/css/tokens.css, D-019). Serwer bierze go z ciasteczka `motyw`,
    // ale ciasteczka Laravela są szyfrowane, a POST /motyw byłby zapisem.
    // Ustawiamy atrybut w DOM po wczytaniu — produkcja dostaje wyłącznie GET.
    if (theme === 'dark') {
      await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
      await page.waitForTimeout(300);
    }

    const htmlTheme = await page.getAttribute('html', 'data-theme');
    const tloBody = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    const licznik = page.locator('p.pasek-liczb').first();
    const tekst = (await licznik.innerText()).trim();
    const widoczny = await licznik.isVisible();
    await licznik.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    const box = await licznik.boundingBox();
    const naglowek = (await page.locator('#komu-wyszlo').innerText()).trim();
    const body = await page.locator('body').innerText();
    const wersja = (body.match(/Alfa [0-9.]+/) || [null])[0];
    const sha = (body.match(/\b[0-9a-f]{7}\b/) || [null])[0];
    const overflowX = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth);
    const podpowiedz = page.locator('aside.szybki-wyglad-podpowiedz');
    const podpowiedzWidoczna = (await podpowiedz.count()) > 0 ? await podpowiedz.isVisible() : false;
    // czy licznik nie jest przykryty przez widget "Wygląd"
    const przykryty = await page.evaluate(() => {
      const el = document.querySelector('p.pasek-liczb');
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
      return t ? !el.contains(t) && t !== el : null;
    });

    const name = `${theme}-${width}`;
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });

    results.push({
      konfiguracja: name, url: URL, status: resp.status(), htmlDataTheme: htmlTheme,
      bodyBackground: tloBody, naglowekSekcji: naglowek, tekstLicznika: tekst,
      licznikWidoczny: widoczny, licznikPrzykryty: przykryty, boundingBox: box,
      wersjaStopki: wersja, shaStopki: sha, poziomyOverflow: overflowX,
      podpowiedzWygladuWidoczna: podpowiedzWidoczna, zrzut: `${name}.png`,
      PASS: resp.status() === 200 && tekst === '1 wykonanie' && widoczny
            && !overflowX && !podpowiedzWidoczna && przykryty === false,
    });
    await ctx.close();
  }
}
await browser.close();
fs.writeFileSync(`${OUT}/wyniki.json`, JSON.stringify({
  cel: 'Brak #666 nr 3 — oglad w przegladarce zamiast odczytu HTML curl-em',
  kryterium: 'zrzut strony przepisu z widocznym "1 wykonanie" przy 320 i 1440 px w obu motywach, odczytowo, bez logowania i bez zapisu',
  wykonano: new Date().toISOString(),
  tryb: 'wylacznie odczyt: GET bez sesji, bez logowania, bez POST, bez klikniec zapisujacych',
  motywCiemny: 'atrybut data-theme="dark" ustawiony na <html> w DOM po wczytaniu strony — jedyna sciezka aktywacji wg resources/css/tokens.css (D-019). NIE uzyto POST /motyw.',
  podpowiedzWygladu: 'localStorage["kuking-wyglad-poznany"]="1" przed wczytaniem — wg resources/js/szybki-wyglad.js l.198',
  wyniki: results,
}, null, 2));
console.log(results.map(r => `${r.konfiguracja}: PASS=${r.PASS} theme=${r.htmlDataTheme} bg=${r.bodyBackground} tekst="${r.tekstLicznika}" podpowiedz=${r.podpowiedzWygladuWidoczna} przykryty=${r.licznikPrzykryty} overflow=${r.poziomyOverflow} ${r.wersjaStopki}/${r.shaStopki}`).join('\n'));
