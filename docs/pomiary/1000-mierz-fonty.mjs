/*
 * Pomiar do #1000: transfer fontów, czas zastosowania fontu, FCP/LCP i CLS
 * na zimnym cache i ograniczonej sieci. Nie jest częścią CI — narzędzie
 * jednorazowe, opisane w docs/pomiary/1000-fonty-przed-po.md.
 *
 * Użycie:
 *   node docs/pomiary/1000-mierz-fonty.mjs <etykieta>=<adres> [...] > wynik.json
 * Zmienne: PRZEBIEGI (domyślnie 3), SIEC ('slow4g' | 'slow3g'), SCIEZKI
 * (lista po przecinku), CHROME_PATH.
 *
 * Każdy przebieg = NOWY kontekst przeglądarki (pusty cache HTTP, pusty cache
 * fontów), throttling sieci i CPU przez CDP, kolejność wersji przeplatana.
 */
import { chromium } from 'playwright';
import { existsSync } from 'node:fs';

const SIECI = {
  // Odpowiada profilowi „Slow 4G" z Lighthouse: 1,6 Mb/s w dół, 750 kb/s w górę, RTT 150 ms.
  slow4g: { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 },
  // Odpowiada „Slow 3G" z Chrome DevTools: 500 kb/s w dół, 400 kb/s w górę, RTT 400 ms.
  slow3g: { offline: false, latency: 400, downloadThroughput: (500 * 1024) / 8, uploadThroughput: (400 * 1024) / 8 },
};

const wersje = process.argv.slice(2).map((a) => {
  const [nazwa, ...reszta] = a.split('=');
  return { nazwa, adres: reszta.join('=') };
});
const przebiegi = Number(process.env.PRZEBIEGI ?? 3);
const siec = process.env.SIEC ?? 'slow4g';
const sciezki = (process.env.SCIEZKI ?? '/,/przepisy/rosol-babci-zofii,/@basia').split(',');
const chrome = process.env.CHROME_PATH
  ?? (existsSync('/opt/pw-browsers/chromium-1194/chrome-linux/chrome') ? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' : chromium.executablePath());

const wtrysk = () => {
  window.__m = { cls: 0, lcp: null, fcp: null, fontEvents: [], shifts: [] };
  new PerformanceObserver((l) => {
    for (const e of l.getEntries()) {
      if (! e.hadRecentInput) { window.__m.cls += e.value; window.__m.shifts.push([Math.round(e.startTime), e.value]); }
    }
  }).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((l) => {
    for (const e of l.getEntries()) window.__m.lcp = Math.round(e.startTime);
  }).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => {
    for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__m.fcp = Math.round(e.startTime);
  }).observe({ type: 'paint', buffered: true });
  const zapisz = (ev) => window.__m.fontEvents.push([ev, Math.round(performance.now())]);
  document.addEventListener('DOMContentLoaded', () => {
    if (! document.fonts) return;
    document.fonts.addEventListener('loading', () => zapisz('loading'));
    document.fonts.addEventListener('loadingdone', () => zapisz('loadingdone'));
    document.fonts.ready.then(() => zapisz('ready'));
  });
};

const przegladarka = await chromium.launch({ executablePath: chrome });
const wyniki = [];

for (let i = 1; i <= przebiegi; i++) {
  for (const sciezka of sciezki) {
    for (const w of wersje) {
      const kontekst = await przegladarka.newContext({
        viewport: { width: 360, height: 640 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'pl-PL',
      });
      const strona = await kontekst.newPage();
      await strona.addInitScript(wtrysk);
      const cdp = await kontekst.newCDPSession(strona);
      await cdp.send('Network.enable');
      await cdp.send('Network.setCacheDisabled', { cacheDisabled: false });
      await cdp.send('Network.emulateNetworkConditions', SIECI[siec]);
      await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });

      const zadania = new Map();
      const start = { t: null };
      cdp.on('Network.requestWillBeSent', (e) => {
        if (start.t === null) start.t = e.timestamp;
        zadania.set(e.requestId, { url: e.request.url, typ: e.type, t0: e.timestamp });
      });
      cdp.on('Network.responseReceived', (e) => {
        const z = zadania.get(e.requestId);
        if (z) { z.typ = e.type; z.tResp = e.timestamp; z.status = e.response.status; }
      });
      cdp.on('Network.loadingFinished', (e) => {
        const z = zadania.get(e.requestId);
        if (z) { z.t1 = e.timestamp; z.bajty = e.encodedDataLength; }
      });

      const t0 = Date.now();
      await strona.goto(`${w.adres}${sciezka}`, { waitUntil: 'load', timeout: 120000 });
      // Poczekaj, aż fonty się załadują (albo do 20 s), potem daj stronie chwilę na ewentualne przesunięcia.
      await strona.evaluate(() => document.fonts.ready);
      await strona.waitForTimeout(2500);
      const m = await strona.evaluate(() => window.__m);
      const nav = await strona.evaluate(() => {
        const n = performance.getEntriesByType('navigation')[0];
        return { load: Math.round(n.loadEventEnd), ttfb: Math.round(n.responseStart) };
      });

      const fonty = [...zadania.values()].filter((z) => z.typ === 'Font' || /\.woff2?(\?|$)/.test(z.url));
      const wszystko = [...zadania.values()];
      const sumaBajtow = (lista) => lista.reduce((s, z) => s + (z.bajty ?? 0), 0);
      const ostatniaZakonczonaFonta = fonty.length ? Math.max(...fonty.map((z) => z.t1 ?? 0)) : null;
      const zastosowanyFont = m.fontEvents.filter(([n]) => n === 'loadingdone').map(([, t]) => t);

      wyniki.push({
        wersja: w.nazwa,
        przebieg: i,
        sciezka,
        siec,
        fontyZadania: fonty.length,
        fontyBajty: sumaBajtow(fonty),
        fontyPliki: fonty.map((z) => ({ plik: z.url.split('/').pop(), bajty: z.bajty ?? null })),
        wszystkoBajty: sumaBajtow(wszystko),
        wszystkoZadania: wszystko.length,
        // czas od pierwszego żądania do końca ostatniego fontu, ms
        fontKoniecPobieraniaMs: ostatniaZakonczonaFonta === null ? null : Math.round((ostatniaZakonczonaFonta - start.t) * 1000),
        // ostatnie zdarzenie loadingdone z FontFaceSet = font zastosowany, ms od startu nawigacji
        fontZastosowanyMs: zastosowanyFont.length ? Math.max(...zastosowanyFont) : null,
        fcp: m.fcp,
        lcp: m.lcp,
        cls: Number(m.cls.toFixed(4)),
        przesuniecia: m.shifts,
        ttfb: nav.ttfb,
        load: nav.load,
        czasScianyMs: Date.now() - t0,
      });
      console.error(`#${i} ${w.nazwa} ${sciezka}: fonty ${fonty.length} / ${sumaBajtow(fonty)} B, FCP ${m.fcp}, LCP ${m.lcp}, CLS ${m.cls.toFixed(4)}, font zast. ${zastosowanyFont.at(-1) ?? '-'} ms`);
      await kontekst.close();
    }
  }
}

await przegladarka.close();
console.log(JSON.stringify(wyniki, null, 1));
