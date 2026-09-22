// #370 — mysz i dotyk na kolażu tagu. Trzy scenariusze:
//  A) stały bywalec (podpowiedź wyglądu zamknięta), kolaż w naturalnym położeniu po wejściu,
//  B) skrajne przewinięcie: dolna krawędź kolażu przy dolnej krawędzi okna,
//  C) pierwsza wizyta: widoczna podpowiedź „Dopasuj rozmiar tekstu i wygląd strony”.
// Wyłącznie lokalny runtime 127.0.0.1:8074, baza kuking_d_a_tests.
import { chromium } from '/home/mateusz/kuking-DA-tagi/node_modules/playwright/index.mjs';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8074';
const TAG = BASE + '/tag/odbior-kolaz-5';
const STATE = (t, s) => `/home/mateusz/kuking-370-browser/.state-${t}-${s}.json`;
const OUT = '/home/mateusz/kuking-DA-tagi/docs/design/evidence/tags370';
const SHOTS = `${OUT}/pointer`;
fs.mkdirSync(SHOTS, { recursive: true });

const widths = [320, 360, 390, 414, 768, 1440];
const realne = new Set([320, 1440]); // tu wykonujemy rzeczywiste kliknięcia/dotknięcia każdego kafla

const probe = () => {
  const links = [...document.querySelectorAll('.tag-welcome [data-tag-collage] a')];
  const sum = document.querySelector('.szybki-wyglad summary');
  const hint = document.querySelector('[data-wyglad-podpowiedz]');
  const b = sum && sum.getBoundingClientRect();
  const h = hint && !hint.hidden && hint.getBoundingClientRect();
  const cross = (r, o) => {
    if (!o) return 0;
    const w = Math.max(0, Math.min(r.right, o.right) - Math.max(r.left, o.left));
    const q = Math.max(0, Math.min(r.bottom, o.bottom) - Math.max(r.top, o.top));
    return Math.round(w * q);
  };
  const klasa = (x, y, el) => {
    const top = document.elementFromPoint(x, y);
    if (!top) return 'poza';
    if (el.contains(top)) return 'self';
    if (top.closest('.szybki-wyglad')) return 'wyglad-przycisk';
    if (top.closest('[data-wyglad-podpowiedz]')) return 'wyglad-podpowiedz';
    return 'inne:' + top.tagName.toLowerCase();
  };
  return {
    innerWidth: window.innerWidth, innerHeight: window.innerHeight,
    podpowiedzWidoczna: !!h,
    linki: links.map((el) => {
      const r = el.getBoundingClientRect();
      const pts = [];
      for (const fx of [0.15, 0.5, 0.85]) for (const fy of [0.15, 0.5, 0.85]) pts.push(klasa(r.x + r.width * fx, r.y + r.height * fy, el));
      return {
        href: el.href,
        rect: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) },
        wOknie: r.top >= 0 && r.bottom <= window.innerHeight && r.height > 0,
        zPrzyciskiem: cross(r, b), zPodpowiedzia: cross(r, h),
        srodek: klasa(r.x + r.width / 2, r.y + r.height / 2, el),
        trafienia: pts,
        czysty: pts.every((p) => p === 'self'),
        osiagalny: pts.some((p) => p === 'self'),
      };
    }),
  };
};

const zamknijPodpowiedz = async (page) => {
  await page.evaluate(() => { try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch {} });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy === '1');
};

const doDolu = (page) => page.evaluate(() => {
  const k = document.querySelector('.tag-welcome [data-tag-collage]');
  const r = k.getBoundingClientRect();
  window.scrollTo(0, Math.max(0, window.scrollY + r.bottom - window.innerHeight + 8));
});

const wyniki = [];
const problemy = [];
const browser = await chromium.launch({ headless: true });

try {
  for (const theme of ['light', 'dark']) for (const scale of ['100', '140']) for (const width of widths) for (const input of ['mysz', 'dotyk']) {
    const ctx = await browser.newContext({ viewport: { width, height: 740 }, storageState: STATE(theme, scale), hasTouch: input === 'dotyk', deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.route('**/*', (r) => (new URL(r.request().url()).origin === BASE ? r.continue() : r.abort()));
    await page.goto(TAG, { waitUntil: 'networkidle' });

    // C) pierwsza wizyta — podpowiedź widoczna, bez przewijania.
    const C = await page.evaluate(probe);

    // A) stały bywalec — podpowiedź zamknięta, kolaż w położeniu po wejściu.
    await zamknijPodpowiedz(page);
    const A = await page.evaluate(probe);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);

    // A) rzeczywiste kliknięcia/dotknięcia każdego kafla (wybrane szerokości).
    const realizacje = [];
    if (realne.has(width)) {
      for (let i = 0; i < A.linki.length; i++) {
        await page.goto(TAG, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy === '1');
        // Kafel musi być w oknie: użytkownik myszy/dotyku najpierw do niego przewija.
        await page.evaluate((idx) => {
          [...document.querySelectorAll('.tag-welcome [data-tag-collage] a')][idx].scrollIntoView({ block: 'center' });
        }, i);
        await page.waitForTimeout(120);
        const c = await page.evaluate((idx) => {
          const el = [...document.querySelectorAll('.tag-welcome [data-tag-collage] a')][idx];
          const r = el.getBoundingClientRect();
          const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return {
            x: r.x + r.width / 2, y: r.y + r.height / 2, href: el.href,
            w: Math.round(r.width), h: Math.round(r.height),
            wOknie: r.top >= 0 && r.bottom <= window.innerHeight,
            srodek: !top ? 'poza' : el.contains(top) ? 'self' : top.closest('.szybki-wyglad') ? 'wyglad-przycisk' : top.closest('[data-wyglad-podpowiedz]') ? 'wyglad-podpowiedz' : 'inne:' + top.tagName.toLowerCase(),
          };
        }, i);
        let ok = false, trafiono = page.url(), blad = null;
        try {
          if (input === 'dotyk') await page.touchscreen.tap(c.x, c.y); else await page.mouse.click(c.x, c.y);
          await page.waitForURL((u) => u.toString() !== TAG, { timeout: 5000 });
          trafiono = page.url(); ok = trafiono === c.href;
        } catch (e) { blad = String(e).split('\n')[0].slice(0, 90); }
        realizacje.push({ i, cel: c.href, trafiono, ok, celPx: `${c.w}x${c.h}`, wOknie: c.wOknie, srodek: c.srodek, blad });
        if (!ok) problemy.push(`A_BRAK_NAWIGACJI ${theme}/${scale}/${width}/${input} kafel ${i + 1}`);
      }
    }

    // B) skrajne przewinięcie.
    await page.goto(TAG, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy === '1');
    await doDolu(page);
    await page.waitForTimeout(120);
    const B = await page.evaluate(probe);
    // Czy niewielkie doprzewinięcie przywraca dostęp?
    const ratunek = await page.evaluate(() => {
      const przed = window.scrollY;
      window.scrollBy(0, -120);
      return { przesuniecie: przed - window.scrollY };
    });
    await page.waitForTimeout(120);
    const Bpo = await page.evaluate(probe);

    const zlicz = (m) => ({
      wOknie: m.linki.filter((l) => l.wOknie).length,
      zasloniontySrodek: m.linki.filter((l) => l.wOknie && l.srodek !== 'self').length,
      nieosiagalne: m.linki.filter((l) => l.wOknie && !l.osiagalny).length,
      maxPrzycisk: Math.max(0, ...m.linki.map((l) => l.zPrzyciskiem)),
      maxPodpowiedz: Math.max(0, ...m.linki.map((l) => l.zPodpowiedzia)),
    });

    const a = zlicz(A), b = zlicz(B), bp = zlicz(Bpo), c = zlicz(C);
    if (overflow) problemy.push(`OVERFLOW ${theme}/${scale}/${width}/${input}`);
    if (a.zasloniontySrodek) problemy.push(`A_ZASLONIETY ${theme}/${scale}/${width}/${input}: ${a.zasloniontySrodek}`);
    if (b.nieosiagalne) problemy.push(`B_NIEOSIAGALNY ${theme}/${scale}/${width}/${input}: ${b.nieosiagalne}`);
    if (bp.nieosiagalne) problemy.push(`B_PO_DOPRZEWINIECIU ${theme}/${scale}/${width}/${input}: ${bp.nieosiagalne}`);
    if (c.nieosiagalne) problemy.push(`C_PODPOWIEDZ_NIEOSIAGALNY ${theme}/${scale}/${width}/${input}: ${c.nieosiagalne}`);

    wyniki.push({ theme, scale, width, input, innerWidth: A.innerWidth, overflowPoziomy: overflow, A: a, B: b, Bpo: bp, C: c, ratunek, realizacje, szczegolyA: A.linki, szczegolyB: B.linki, szczegolyC: C.linki });
    await ctx.close();
  }

  // Zrzuty poglądowe dla skrajnych ustawień.
  for (const [theme, scale, width, faza] of [['light', '140', 320, 'B'], ['dark', '140', 320, 'C'], ['light', '100', 1440, 'A'], ['dark', '140', 390, 'C']]) {
    const ctx = await browser.newContext({ viewport: { width, height: 740 }, storageState: STATE(theme, scale), hasTouch: true, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.route('**/*', (r) => (new URL(r.request().url()).origin === BASE ? r.continue() : r.abort()));
    await page.goto(TAG, { waitUntil: 'networkidle' });
    if (faza !== 'C') await zamknijPodpowiedz(page);
    if (faza === 'B') { await doDolu(page); await page.waitForTimeout(150); }
    await page.screenshot({ path: `${SHOTS}/${faza}-${theme}-${scale}-${width}.png` });
    await ctx.close();
  }
} finally { await browser.close(); }

fs.writeFileSync(`${OUT}/pointer-results.json`, JSON.stringify({ base: BASE, tag: TAG, konfiguracji: wyniki.length, problemy, wyniki }, null, 1));
console.log('konfiguracji:', wyniki.length);
console.log('problemow:', problemy.length);
const rodzaje = {};
for (const p of problemy) { const k = p.split(' ')[0]; rodzaje[k] = (rodzaje[k] || 0) + 1; }
console.log(JSON.stringify(rodzaje, null, 1));
