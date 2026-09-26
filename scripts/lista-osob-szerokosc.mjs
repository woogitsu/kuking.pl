/*
 * Kuking.pl — lista obserwujących i obserwowanych przy dużej czcionce (#1341).
 *
 * `.osoba-link` miało `min-width: 14rem`. `rem` rośnie z czcionką
 * przeglądarki: przy 200% to 448 px, więcej niż całe okno 320 px, i strona
 * przewijała się w bok. Pomiar na ułożonej stronie, nie odczyt arkusza —
 * PHPUnit nie składa układu.
 *
 * Sprawdza obie listy (gość i zalogowana `ania`), 320/360/414 px, czcionkę
 * przeglądarki 100% i 200% (CDP `Page.setFontSizes`, jak w
 * `scripts/dostepnosc.mjs`) oraz „Większy tekst" 140% w aplikacji. Na każdej
 * stronie podmienia W DOM-ie nazwę pierwszej osoby na bardzo długą — bez
 * zapisu w bazie. Warunki: `scrollWidth <= clientWidth`, nazwa i akcje
 * mieszczą się w karcie, nic na siebie nie nachodzi, cele dotyku ≥ 48 px,
 * awatar zostaje kwadratowy.
 *
 * KONTROLA UJEMNA w tym samym przebiegu: arkusz z samym `min-width: 14rem`
 * (stan sprzed poprawki) musi przy 320 px i czcionce 200% dać PRZEPELNIENIE.
 * Jeśli nie da — pomiar niczego nie mierzy i skrypt kończy się błędem.
 *
 * Uruchomienie (serwer z `DemoSeeder` musi już działać; tylko adres lokalny):
 *     ADRES=http://127.0.0.1:8123 node scripts/lista-osob-szerokosc.mjs
 * W `scripts/port-projektu.mjs` idzie jako `sprawdzListeOsob(...)`.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { existsSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const EKRANY = ['/@ania/obserwowani', '/@basia/obserwujacy'];
const DLUGA_NAZWA = 'Aleksandra Katarzyna Przepiórkowska-Wielopolska od kuchni babci Zofii '
  + 'Najdłuższesłowobezżadnejprzerwyktórepowinnosięzłamać';

function pomiar() {
  const bledy = [];
  const doc = document.documentElement;
  if (doc.scrollWidth > doc.clientWidth + 1) bledy.push(`PRZEPELNIENIE ${doc.scrollWidth} > ${doc.clientWidth}`);
  const karty = [...document.querySelectorAll('.card')].filter((k) => k.querySelector('.osoba-link'));
  if (karty.length < 2) bledy.push('ZA_MALO_KART ' + karty.length);
  const zawiera = (a, b) => b.left >= a.left - 1 && b.right <= a.right + 1;
  const nachodzi = (a, b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1;
  karty.forEach((karta, i) => {
    const k = karta.getBoundingClientRect();
    if (k.right > innerWidth + 1 || k.left < -1) bledy.push(`KARTA_POZA_OKNEM ${i}`);
    const link = karta.querySelector('.osoba-link');
    const l = link.getBoundingClientRect();
    if (!zawiera(k, l)) bledy.push(`LINK_POZA_KARTA ${i} ${Math.round(l.width)}/${Math.round(k.width)}`);
    if (l.height < 48) bledy.push(`LINK_NISKI ${i}`);
    const img = link.querySelector('img, .avatar, [class*="avatar"]');
    if (img) {
      const a = img.getBoundingClientRect();
      if (Math.abs(a.width - a.height) > 1) bledy.push(`AWATAR_ZGNIECIONY ${i} ${a.width}x${a.height}`);
    }
    for (const t of link.querySelectorAll('span span')) {
      const r = t.getBoundingClientRect();
      if (!zawiera(k, r) || t.scrollWidth > t.clientWidth + 1) bledy.push(`NAZWA_UCIETA ${i}`);
    }
    for (const el of karta.querySelectorAll('button, .badge, .meta:not(.osoba-link .meta)')) {
      const r = el.getBoundingClientRect();
      if (!zawiera(k, r)) bledy.push(`AKCJA_POZA_KARTA ${i} ${el.textContent.trim()}`);
      if (nachodzi(l, r)) bledy.push(`NAKLADANIE ${i} ${el.textContent.trim()}`);
      if (el.tagName === 'BUTTON' && (r.height < 48 || r.width < 48)) bledy.push(`PRZYCISK_MALY ${i}`);
    }
  });
  return { bledy, przyciski: document.querySelectorAll('.card button').length };
}

async function zmierz(browser, { adres, sesja, sciezka, szerokosc, czcionka200, skala, wstrzyknij }) {
  // `bypassCSP` tylko dla kontroli ujemnej: CSP serwisu (słusznie) odrzuca
  // wstrzyknięty `<style>`, a wtedy „stary arkusz" byłby po cichu nowym.
  const ctx = await browser.newContext({
    viewport: { width: szerokosc, height: 800 }, bypassCSP: Boolean(wstrzyknij), ...(sesja ? { storageState: sesja } : {}),
  });
  try {
    const page = await ctx.newPage();
    if (czcionka200) await (await ctx.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    const odp = await page.goto(adres + sciezka, { waitUntil: 'networkidle' });
    assert.equal(odp.status(), 200, `HTTP_${odp.status()} ${sciezka}`);
    await page.evaluate(({ skala, nazwa, wstrzyknij }) => {
      document.documentElement.dataset.textScale = String(skala);
      const pierwsza = document.querySelector('.osoba-link .font-semibold');
      if (pierwsza) pierwsza.textContent = nazwa;
      if (wstrzyknij) document.head.insertAdjacentHTML('beforeend', `<style>${wstrzyknij}</style>`);
    }, { skala, nazwa: DLUGA_NAZWA, wstrzyknij });
    if (wstrzyknij) {
      const minimum = await page.locator('.osoba-link').first().evaluate((el) => getComputedStyle(el).minWidth);
      assert(!minimum.startsWith('min('), 'MUTACJA_NIE_WESZLA ' + minimum);
    }
    await page.evaluate(async () => {
      await document.fonts.ready;
      for (let i = 0; i < 5; i++) await new Promise(requestAnimationFrame);
    });
    return await page.evaluate(pomiar);
  } finally { await ctx.close(); }
}

export async function sprawdzListeOsob({ browser, adres, sesja }) {
  let konfiguracje = 0, przyciski = 0;
  const porazki = [];
  for (const [kto, s] of [['gość', null], ['ania', sesja]]) {
    for (const sciezka of EKRANY) for (const szerokosc of [320, 360, 414]) {
      for (const [czcionka200, skala] of [[false, 100], [false, 140], [true, 100]]) {
        const w = await zmierz(browser, { adres, sesja: s, sciezka, szerokosc, czcionka200, skala });
        konfiguracje++; przyciski += w.przyciski;
        if (w.bledy.length) porazki.push({ kto, sciezka, szerokosc, czcionka200, skala, bledy: w.bledy });
      }
    }
  }
  assert.equal(porazki.length, 0, 'LISTA_OSOB ' + JSON.stringify(porazki, null, 1));
  // Bez przycisków zalogowanej osoby połowa warunków nie miałaby czego mierzyć.
  assert(przyciski > 0, 'BRAK_PRZYCISKOW_PRZESTAN_OBSERWOWAC');

  const ujemna = await zmierz(browser, {
    adres, sesja, sciezka: EKRANY[0], szerokosc: 320, czcionka200: true, skala: 100,
    wstrzyknij: '.osoba-link { min-width: 14rem; }',
  });
  assert(ujemna.bledy.some((b) => b.startsWith('PRZEPELNIENIE')), 'KONTROLA_UJEMNA_NIE_WYKRYLA ' + JSON.stringify(ujemna));
  console.log(`Lista osób (#1341): ${konfiguracje} konfiguracji PASS, kontrola ujemna 14rem → ${ujemna.bledy[0]}`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const adres = process.env.ADRES;
  if (!adres || !['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('Podaj lokalny ADRES=http://127.0.0.1:PORT');
  const lokalna = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || (existsSync(lokalna) ? lokalna : undefined) });
  try {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(adres + '/login');
    await page.fill('input[name="login"]', process.env.KONTO || 'ania');
    await page.fill('input[name="password"]', process.env.HASLO || 'haslo-testowe-123');
    await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('.panel-formularza button[type="submit"]')]);
    const sesja = await ctx.storageState();
    await ctx.close();
    await sprawdzListeOsob({ browser, adres, sesja });
  } finally { await browser.close(); }
}
