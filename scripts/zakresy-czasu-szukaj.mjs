/*
 * Kuking.pl — progi czasu w wyszukiwarce na małym ekranie (#1997).
 *
 * Wiersz „Ile masz czasu?" (Bez limitu czasu / Do 15 minut / Do 30 minut /
 * Do godziny) stoi pod zakresami wyszukiwania. PHPUnit sprawdza adresy
 * i filtr; ten skrypt mierzy to, czego PHPUnit nie składa: ułożoną stronę.
 *
 * Warunki na 320/360/414 px, przy czcionce przeglądarki 100% i 200% (CDP
 * `Page.setFontSizes`, jak w `scripts/dostepnosc.mjs`) oraz „Większy tekst"
 * 140% w aplikacji: brak poziomego przewijania, każdy chip ≥ 48 px wysokości
 * i szerokości, tekst chipa ≥ 18 px, chip mieści się w oknie, dokładnie
 * jeden wybrany (`aria-current="page"`) w wierszu czasu.
 *
 * Zachowanie: z klawiatury (Tab do „Do 15 minut" + Enter) wybór trafia do
 * adresu, przeżywa odświeżenie i działa też z wyłączonym JavaScriptem.
 *
 * KONTROLA UJEMNA w tym samym przebiegu: arkusz z `.chipsy { flex-wrap:
 * nowrap }` musi przy 320 px dać PRZEPELNIENIE, a `.chip { min-height: 0;
 * padding: 0 }` — CEL_MALY. Jeśli nie da, pomiar niczego nie mierzy.
 *
 * Uruchomienie (serwer z `DemoSeeder` musi już działać; tylko adres lokalny):
 *     ADRES=http://127.0.0.1:8123 node scripts/zakresy-czasu-szukaj.mjs
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { existsSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const WIERSZ = 'nav[aria-labelledby="czas-przepisu-etykieta"]';
const SCIEZKA = '/szukaj?q=chleb&sekcja=przepisy&czas=30';

function pomiar(wiersz) {
  const bledy = [];
  const doc = document.documentElement;
  if (doc.scrollWidth > doc.clientWidth + 1) bledy.push(`PRZEPELNIENIE ${doc.scrollWidth} > ${doc.clientWidth}`);
  const nav = document.querySelector(wiersz);
  if (!nav) return { bledy: [...bledy, 'BRAK_WIERSZA_CZASU'] };
  const chipy = [...nav.querySelectorAll('a.chip')];
  if (chipy.length !== 4) bledy.push('ZLA_LICZBA_CHIPOW ' + chipy.length);
  for (const chip of chipy) {
    const r = chip.getBoundingClientRect();
    const nazwa = chip.textContent.trim();
    if (r.height < 48 || r.width < 48) bledy.push(`CEL_MALY ${nazwa} ${Math.round(r.width)}x${Math.round(r.height)}`);
    if (r.left < -1 || r.right > innerWidth + 1) bledy.push(`POZA_OKNEM ${nazwa}`);
    if (parseFloat(getComputedStyle(chip).fontSize) < 18) bledy.push(`TEKST_MALY ${nazwa} ${getComputedStyle(chip).fontSize}`);
  }
  const wybrane = chipy.filter((c) => c.getAttribute('aria-current') === 'page').map((c) => c.textContent.trim());
  if (wybrane.length !== 1) bledy.push('WYBRANE ' + JSON.stringify(wybrane));
  return { bledy, wybrane };
}

async function zmierz(browser, { adres, szerokosc, czcionka200, skala, wstrzyknij }) {
  // `bypassCSP` tylko dla kontroli ujemnej: CSP serwisu odrzuca wstrzyknięty `<style>`.
  const ctx = await browser.newContext({ viewport: { width: szerokosc, height: 800 }, bypassCSP: Boolean(wstrzyknij) });
  try {
    const page = await ctx.newPage();
    if (czcionka200) await (await ctx.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    const odp = await page.goto(adres + SCIEZKA, { waitUntil: 'networkidle' });
    assert.equal(odp.status(), 200, `HTTP_${odp.status()} ${SCIEZKA}`);
    await page.evaluate(({ skala, wstrzyknij }) => {
      document.documentElement.dataset.textScale = String(skala);
      if (wstrzyknij) document.head.insertAdjacentHTML('beforeend', `<style>${wstrzyknij}</style>`);
    }, { skala, wstrzyknij });
    await page.evaluate(async () => {
      await document.fonts.ready;
      for (let i = 0; i < 5; i++) await new Promise(requestAnimationFrame);
    });
    return await page.evaluate(pomiar, WIERSZ);
  } finally { await ctx.close(); }
}

async function wybierzKlawiatura(browser, adres, javaScriptEnabled) {
  const ctx = await browser.newContext({ viewport: { width: 320, height: 800 }, javaScriptEnabled });
  try {
    const page = await ctx.newPage();
    await page.goto(adres + '/szukaj?q=chleb&sekcja=przepisy', { waitUntil: 'networkidle' });
    const cel = page.locator(`${WIERSZ} a`, { hasText: 'Do 15 minut' });
    // Tab aż fokus stanie na chipie — bez klikania myszą.
    for (let i = 0; i < 80; i++) {
      await page.keyboard.press('Tab');
      if (await cel.evaluate((el) => el === document.activeElement)) break;
    }
    assert(await cel.evaluate((el) => el === document.activeElement), 'FOKUS_NIE_DOSZEDL_DO_CHIPA');
    await Promise.all([page.waitForURL(/czas=15/), page.keyboard.press('Enter')]);
    const po = await page.evaluate(pomiar, WIERSZ);
    assert.deepEqual(po.wybrane, ['Do 15 minut'], 'WYBOR_PO_ENTER ' + JSON.stringify(po));
    await page.reload({ waitUntil: 'networkidle' });
    const poOdswiezeniu = await page.evaluate(pomiar, WIERSZ);
    assert.deepEqual(poOdswiezeniu.wybrane, ['Do 15 minut'], 'WYBOR_PO_ODSWIEZENIU ' + JSON.stringify(poOdswiezeniu));
  } finally { await ctx.close(); }
}

export async function sprawdzZakresyCzasu({ browser, adres }) {
  let konfiguracje = 0;
  const porazki = [];
  for (const szerokosc of [320, 360, 414]) {
    for (const [czcionka200, skala] of [[false, 100], [false, 140], [true, 100]]) {
      const w = await zmierz(browser, { adres, szerokosc, czcionka200, skala });
      konfiguracje++;
      if (w.bledy.length) porazki.push({ szerokosc, czcionka200, skala, bledy: w.bledy });
      else assert.deepEqual(w.wybrane, ['Do 30 minut']);
    }
  }
  assert.equal(porazki.length, 0, 'ZAKRESY_CZASU ' + JSON.stringify(porazki, null, 1));

  await wybierzKlawiatura(browser, adres, true);
  await wybierzKlawiatura(browser, adres, false);

  const bezZawijania = await zmierz(browser, {
    adres, szerokosc: 320, czcionka200: true, skala: 100, wstrzyknij: '.chipsy { flex-wrap: nowrap !important; }',
  });
  assert(bezZawijania.bledy.some((b) => b.startsWith('PRZEPELNIENIE')), 'KONTROLA_UJEMNA_ZAWIJANIE ' + JSON.stringify(bezZawijania));
  const male = await zmierz(browser, {
    adres, szerokosc: 320, czcionka200: false, skala: 100, wstrzyknij: '.chip { min-height: 0 !important; padding: 0 !important; }',
  });
  assert(male.bledy.some((b) => b.startsWith('CEL_MALY')), 'KONTROLA_UJEMNA_CEL ' + JSON.stringify(male));
  console.log(`Zakresy czasu (#1997): ${konfiguracje} konfiguracji PASS, klawiatura z JS i bez JS PASS, `
    + `kontrole ujemne → ${bezZawijania.bledy[0]}; ${male.bledy.find((b) => b.startsWith('CEL_MALY'))}`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const adres = process.env.ADRES;
  if (!adres || !['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('Podaj lokalny ADRES=http://127.0.0.1:PORT');
  const lokalna = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || (existsSync(lokalna) ? lokalna : undefined) });
  try {
    await sprawdzZakresyCzasu({ browser, adres });
  } finally { await browser.close(); }
}
