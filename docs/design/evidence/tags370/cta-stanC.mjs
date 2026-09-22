// #370 — domknięcie dwóch braków z sekcji „Brakujące dowody #370":
//   nr 2) CTA „Dodaj wpis z tym tagiem" jako RZECZYWISTA interakcja (mysz i dotyk)
//         + pomiar wysokości przycisku w 12 kombinacjach 320/390/1440 × motyw × 100/140%.
//   nr 3) zrzuty stanu C dla light-100-360 i dark-100-390 (te konfiguracje są sporne
//         w pointer-results.json, a zrzuty w repo były z innej skali — 140%).
// Wyłącznie lokalny runtime 127.0.0.1:8091, baza kuking_pasek_370 na 127.0.0.1:55439.
// Na produkcji nie wykonujemy niczego.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8091';
const TAG = BASE + '/tag/odbior-kolaz-5';
const OUT = '/home/mateusz/kuking-odbior-pasek/out/tags370';
const SHOTS = `${OUT}/pointer`;
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch({ headless: true });

// --- 1. Stany przeglądarki: motyw + skala tekstu ustawione PRAWDZIWYM formularzem
//        aplikacji (ciasteczka Laravela są szyfrowane, więc nie da się ich podrobić).
const stany = {};
for (const theme of ['light', 'dark']) {
  for (const scale of ['100', '140']) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(TAG, { waitUntil: 'networkidle' });
    // Widget to <details> — panel trzeba najpierw otworzyć.
    await page.click('.szybki-wyglad > summary');
    await page.waitForSelector('#szybki-motyw', { state: 'visible' });
    await page.selectOption('#szybki-motyw', theme);
    await page.selectOption('#szybka-skala', scale);
    await page.click('.szybki-wyglad button[type=submit]:not([name])');
    await page.waitForTimeout(800);
    await page.goto(TAG, { waitUntil: 'networkidle' });
    const potwierdzenie = {
      dataTheme: await page.getAttribute('html', 'data-theme'),
      dataTextScale: await page.getAttribute('html', 'data-text-scale'),
    };
    const pelny = await ctx.storageState();
    // Zapis wyglądu wywołuje forgetHint() i ustawia kuking-wyglad-poznany=1
    // (resources/js/szybki-wyglad.js l. 32–35). Do stanu C — PIERWSZEJ wizyty —
    // bierzemy więc same ciasteczka motywu/skali, bez localStorage.
    stany[`${theme}-${scale}`] = {
      state: pelny,
      samePliki: { cookies: pelny.cookies, origins: [] },
      potwierdzenie,
    };
    await ctx.close();
  }
}

// --- 2. BRAK nr 2: CTA jako rzeczywista interakcja + wysokość przycisku.
const cta = [];
for (const theme of ['light', 'dark']) {
  for (const scale of ['100', '140']) {
    for (const width of [320, 390, 1440]) {
      for (const input of ['mysz', 'dotyk']) {
        const ctx = await browser.newContext({
          viewport: { width, height: 740 },
          storageState: stany[`${theme}-${scale}`].state,
          hasTouch: input === 'dotyk',
          deviceScaleFactor: 1,
        });
        const page = await ctx.newPage();
        await page.goto(TAG, { waitUntil: 'networkidle' });
        // Stan A: stały bywalec — podpowiedź pierwszej wizyty zamknięta.
        await page.evaluate(() => { try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch {} });
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy === '1');

        const sel = 'a.btn.btn-primary[href*="/dodaj/zdjecie"]';
        const el = page.locator(sel).first();
        await el.scrollIntoViewIfNeeded();
        await page.waitForTimeout(150);

        const pomiar = await page.evaluate((s) => {
          const a = document.querySelector(s);
          if (!a) return null;
          const r = a.getBoundingClientRect();
          const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return {
            href: a.href, tekst: a.textContent.trim(),
            wysokosc: +r.height.toFixed(2), szerokosc: +r.width.toFixed(2),
            x: r.x + r.width / 2, y: r.y + r.height / 2,
            wOknie: r.top >= 0 && r.bottom <= window.innerHeight,
            srodek: !top ? 'poza' : a.contains(top) ? 'self'
              : top.closest('.szybki-wyglad') ? 'wyglad-przycisk'
              : top.closest('[data-wyglad-podpowiedz]') ? 'wyglad-podpowiedz'
              : 'inne:' + top.tagName.toLowerCase(),
          };
        }, sel);

        let trafiono = null, blad = null;
        try {
          if (input === 'dotyk') await page.touchscreen.tap(pomiar.x, pomiar.y);
          else await page.mouse.click(pomiar.x, pomiar.y);
          await page.waitForURL((u) => u.toString() !== TAG, { timeout: 6000 });
          trafiono = page.url();
        } catch (e) { blad = String(e).split('\n')[0].slice(0, 100); }

        const naLogowaniu = trafiono ? new URL(trafiono).pathname === '/login' : false;
        const overflow = await page.evaluate(() =>
          document.documentElement.scrollWidth > window.innerWidth + 1);

        cta.push({
          theme, scale, width, input,
          tekstPrzycisku: pomiar.tekst, hrefPrzycisku: pomiar.href,
          wysokoscPx: pomiar.wysokosc, szerokoscPx: pomiar.szerokosc,
          srodekNiezaslonięty: pomiar.srodek === 'self',
          trafiono, naLogowaniu, poziomyOverflow: overflow, blad,
          PASS: pomiar.wysokosc >= 48 && pomiar.srodek === 'self' && naLogowaniu && !overflow,
        });
        await ctx.close();
      }
    }
  }
}

// --- 3. BRAK nr 3: zrzuty stanu C dla spornych konfiguracji 100%.
//        Pierwsza wizyta = BRAK klucza kuking-wyglad-poznany, bez przewijania.
const stanC = [];
for (const [theme, scale, width] of [['light', '100', 360], ['dark', '100', 390]]) {
  const ctx = await browser.newContext({
    viewport: { width, height: 740 },
    storageState: stany[`${theme}-${scale}`].samePliki,
    hasTouch: true, deviceScaleFactor: 1,
  });
  const page = await ctx.newPage();
  await page.goto(TAG, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy === '1');

  const miara = await page.evaluate(() => {
    const hint = document.querySelector('[data-wyglad-podpowiedz]');
    const h = hint && !hint.hidden ? hint.getBoundingClientRect() : null;
    const kafle = [...document.querySelectorAll('.tag-welcome [data-tag-collage] a')];
    const przeciecie = (r, o) => {
      if (!o) return 0;
      const w = Math.max(0, Math.min(r.right, o.right) - Math.max(r.left, o.left));
      const q = Math.max(0, Math.min(r.bottom, o.bottom) - Math.max(r.top, o.top));
      return Math.round(w * q);
    };
    const klasa = (x, y, el) => {
      const t = document.elementFromPoint(x, y);
      if (!t) return 'poza';
      if (el.contains(t)) return 'self';
      if (t.closest('.szybki-wyglad')) return 'wyglad-przycisk';
      if (t.closest('[data-wyglad-podpowiedz]')) return 'wyglad-podpowiedz';
      return 'inne:' + t.tagName.toLowerCase();
    };
    return {
      podpowiedzWidoczna: !!h,
      podpowiedzTekst: hint ? hint.textContent.trim().slice(0, 80) : null,
      podpowiedzRect: h ? { x: Math.round(h.x), y: Math.round(h.y), w: Math.round(h.width), h: Math.round(h.height) } : null,
      kafle: kafle.map((el) => {
        const r = el.getBoundingClientRect();
        const pts = [];
        for (const fx of [0.15, 0.5, 0.85]) for (const fy of [0.15, 0.5, 0.85]) pts.push(klasa(r.x + r.width * fx, r.y + r.height * fy, el));
        return {
          etykieta: el.getAttribute('aria-label'),
          rect: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) },
          wOknie: r.top >= 0 && r.bottom <= window.innerHeight && r.height > 0,
          zPodpowiedzia: przeciecie(r, h),
          osiagalny: pts.some((p) => p === 'self'),
          trafienia: pts,
        };
      }),
    };
  });

  const nazwa = `C-${theme}-${scale}-${width}`;
  await page.screenshot({ path: `${SHOTS}/${nazwa}.png` });
  const zakryte = miara.kafle.filter((k) => k.wOknie && !k.osiagalny);
  stanC.push({
    konfiguracja: nazwa, theme, scale, width,
    dataTheme: await page.getAttribute('html', 'data-theme'),
    dataTextScale: await page.getAttribute('html', 'data-text-scale'),
    ...miara,
    kafliNieosiagalnych: zakryte.length,
    nieosiagalneEtykiety: zakryte.map((k) => k.etykieta),
    maxPoleWspolne: Math.max(0, ...miara.kafle.map((k) => k.zPodpowiedzia)),
    zrzut: `${nazwa}.png`,
    // Kryterium z dokumentu: zrzut ma POKAZYWAĆ przykrycie kafla przez podpowiedź.
    PASS: miara.podpowiedzWidoczna && zakryte.length >= 1,
  });
  await ctx.close();
}

await browser.close();

const wynik = {
  cel: 'Braki #370 nr 2 (CTA jako interakcja) i nr 3 (zrzuty stanu C przy skali 100%)',
  runtime: BASE, baza: 'kuking_pasek_370 @ 127.0.0.1:55439',
  tag: TAG, wykonano: new Date().toISOString(),
  uwaga: 'Wylacznie lokalnie. Zdjecia w kolazu sa rysowanymi danymi testowymi, nie zdjeciami uzytkownikow.',
  stanyPrzegladarki: Object.fromEntries(Object.entries(stany).map(([k, v]) => [k, v.potwierdzenie])),
  brak2_CTA: { konfiguracji: cta.length, PASS: cta.every((c) => c.PASS), wyniki: cta },
  brak3_stanC: { konfiguracji: stanC.length, PASS: stanC.every((c) => c.PASS), wyniki: stanC },
};
fs.writeFileSync(`${OUT}/cta-results.json`, JSON.stringify(wynik.brak2_CTA, null, 1));
fs.writeFileSync(`${OUT}/stanC-results.json`, JSON.stringify({ ...wynik, brak2_CTA: undefined }, null, 1));
fs.writeFileSync(`${OUT}/wyniki.json`, JSON.stringify(wynik, null, 1));

console.log('stany:', JSON.stringify(wynik.stanyPrzegladarki));
console.log('CTA konfiguracji:', cta.length, 'PASS:', cta.filter((c) => c.PASS).length);
console.log('min wysokosc CTA:', Math.min(...cta.map((c) => c.wysokoscPx)));
console.log('nie na /login:', cta.filter((c) => !c.naLogowaniu).map((c) => `${c.theme}/${c.scale}/${c.width}/${c.input}->${c.trafiono}`));
for (const c of stanC) console.log(`${c.konfiguracja}: theme=${c.dataTheme} scale=${c.dataTextScale} podpowiedz=${c.podpowiedzWidoczna} rect=${JSON.stringify(c.podpowiedzRect)} nieosiagalnych=${c.kafliNieosiagalnych} pole=${c.maxPoleWspolne} PASS=${c.PASS}`);
