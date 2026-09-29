/*
 * =============================================================================
 *  DLACZEGO TEN TEST LEŻY W `scripts/przegladarka/`, A NIE W `scripts/`
 * =============================================================================
 *  Bo NIE MOŻE wejść na listę `node --test` w skrypcie `build` z package.json,
 *  a wszystko, co leży płasko w `scripts/`, na tę listę wejść musi.
 *
 *  `npm run build` biegnie w miejscach, które nie mają i nie będą miały
 *  przeglądarki:
 *    1. `Dockerfile` (etap `assets`, obraz `node:22-bookworm-slim`) — obraz
 *       produkcyjny; Chromium to tam setki megabajtów za nic.
 *    2. `.github/workflows/ci.yml`, zadanie `assets` — `npm run build` stoi
 *       PRZED krokiem, który instaluje Chromium.
 *
 *  URUCHAMIA GO WŁASNY KROK w `ci.yml`, w zadaniu `assets`, POSTAWIONY PO
 *  instalacji Chromium. Lokalnie:
 *    npx playwright install chromium
 *    node --test scripts/przegladarka/wyglad-nawigacja.test.mjs
 *
 *  Test czysto node'owy tej samej gałęzi, `scripts/kopiowanie-adresu.test.mjs`,
 *  poszedł DRUGĄ drogą — na listę `build` — bo przeglądarki nie potrzebuje.
 *
 *  UWAGA DLA STRAŻNIKA (`scripts/straznik-testow-js.test.mjs` z gałęzi
 *  `naprawa/testy-js-wchodza-do-ci`): ten katalog jest poza jego skanem,
 *  bo jego reguła brzmi „każdy test JS ma być na liście `build`", a dla
 *  testów przeglądarkowych ta reguła jest nie do spełnienia. Strażnik
 *  powinien objąć ten katalog własną regułą — „każdy plik z
 *  `scripts/przegladarka/` jest wołany nazwanym krokiem w `ci.yml`".
 *  Do rozstrzygnięcia przez autora strażnika.
 * =============================================================================
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Pełny moduł produkcyjny w Chromium; kontrolowany serwer i mały DOM panelu.
// To nie jest test zapisu konta ani fizycznego telefonu.
const source = readFileSync(new URL('../../resources/js/szybki-wyglad.js', import.meta.url), 'utf8');
const html = `<html data-text-scale="100" data-theme="light"><head><meta charset="utf-8"></head><body>
<a href="/cel">Przejdź dalej</a>
<details open data-szybki-wyglad><summary>Wygląd</summary>
<div class="szybki-wyglad-panel"><form action="/motyw">
<select name="text_scale"><option>100</option><option>125</option></select>
<select name="theme"><option>light</option><option>dark</option></select>
<button>Zapisz</button><button type="button" data-wyglad-zamknij>Zamknij</button>
</form><p role="status" data-wyglad-status></p></div></details>
<div data-wyglad-podpowiedz><button>Rozumiem</button></div>
<script type="module" src="/wyglad.js"></script></body></html>`;

for (const mode of ['success', '500', 'offline', 'timeout', 'json']) {
    test(`oczekująca nawigacja: ${mode}`, async () => {
        const browser = await chromium.launch({headless: true});
        try {
            const page = await browser.newPage();
            let release;
            const gate = new Promise(resolve => { release = resolve; });
            await page.route('http://kuking.test/**', async route => {
                const path = new URL(route.request().url()).pathname;
                if (path === '/wyglad.js') return route.fulfill({contentType: 'text/javascript', body: source});
                if (path === '/motyw') {
                    await gate;
                    if (mode === 'offline') return route.abort('internetdisconnected');
                    if (mode === 'timeout') return;
                    return route.fulfill({status: mode === '500' ? 500 : 200, contentType: 'application/json',
                        body: mode === 'json' ? '{' : JSON.stringify({theme: 'light', text_scale: 125})});
                }
                return route.fulfill({contentType: 'text/html', body: path === '/cel' ? '<h1>Cel</h1>' : html});
            });
            await page.goto('http://kuking.test/');
            await page.locator('[data-wyglad-gotowy]').waitFor();
            const request = page.waitForRequest('**/motyw');
            await page.selectOption('[name=text_scale]', '125');
            await request;
            await page.getByRole('link', {name: 'Przejdź dalej'}).click();
            assert.equal(page.url(), 'http://kuking.test/');
            release();
            if (mode === 'success') {
                await page.waitForURL('**/cel');
            } else {
                await page.waitForTimeout(mode === 'timeout' ? 10500 : 300);
                assert.equal(page.url(), 'http://kuking.test/', 'BLAD_ZNIKA_PRZEZ_NAWIGACJE');
                const status = page.locator('[data-wyglad-status]');
                assert.equal(await status.isVisible(), true, 'BLAD_SCHOWANY_W_PANELU');
                assert.match(await status.textContent(), /Nie mamy potwierdzenia/);
                assert.equal(await page.locator('html').getAttribute('data-text-scale'), '100');
                assert.equal(await page.locator('[name=text_scale]').isEnabled(), true);
                await page.getByRole('link', {name: 'Przejdź dalej'}).click();
                await page.waitForURL('**/cel');
            }
        } finally { await browser.close(); }
    });
}

// #684 — pływający przycisk „Wygląd" nie może zabrać myszy i palcowi celu,
// który leży pod nim. Klawiatura ma odsłanianie w `focusin`; tu mierzymy
// wskaźnik: prawdziwy arkusz widgetu, kafel-link przewinięty dokładnie pod
// przycisk, kliknięcie i dotknięcie w środek kafla.
const css = readFileSync(new URL('../../resources/css/szybki-wyglad.css', import.meta.url), 'utf8');
const stronaZKaflem = tresc => `<html data-text-scale="100" data-theme="light"><head><meta charset="utf-8">
<style>:root{--spacing-2:8px;--spacing-3:12px;--spacing-4:16px;--text-body:18px;--leading-body:1.5}body{margin:0;font:18px/1.5 sans-serif}</style>
<style>${css}</style></head><body>
<div style="height:1500px"></div>
${tresc}
<div style="height:1500px"></div>
<details class="szybki-wyglad" data-szybki-wyglad><summary>Wygląd</summary>
<div class="szybki-wyglad-panel"><form action="/motyw">
<select name="text_scale"><option>100</option><option>125</option></select>
<select name="theme"><option>light</option><option>dark</option></select>
<button>Zapisz</button><button type="button" data-wyglad-zamknij>Zamknij</button>
</form><p role="status" data-wyglad-status></p></div></details>
<aside class="szybki-wyglad-podpowiedz" data-wyglad-podpowiedz hidden><button>Rozumiem</button></aside>
<script type="module" src="/wyglad.js"></script></body></html>`;
const KAFEL = '<a id="cel" href="/wpis" style="display:block;margin-left:auto;margin-right:12px;width:110px;height:44px">Piąty kafel</a>';
const TEKST = '<p id="cel" style="margin:0 12px 0 auto;width:110px;height:44px">Zwykły tekst</p>';

async function podPrzyciskiem(sposob, tresc) {
    const browser = await chromium.launch({headless: true});
    try {
        const context = await browser.newContext({viewport: {width: 320, height: 600}, hasTouch: sposob === 'dotyk'});
        const page = await context.newPage();
        await page.route('http://kuking.test/**', route => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/wyglad.js') return route.fulfill({contentType: 'text/javascript', body: source});
            return route.fulfill({contentType: 'text/html', body: path === '/wpis' ? '<h1>Wpis</h1>' : stronaZKaflem(tresc)});
        });
        await page.goto('http://kuking.test/');
        await page.locator('[data-wyglad-gotowy]').waitFor();
        // Przewijamy tak, by środek celu wypadł na środku pływającego przycisku.
        await page.evaluate(() => {
            const przycisk = document.querySelector('[data-szybki-wyglad] summary').getBoundingClientRect();
            const cel = document.getElementById('cel').getBoundingClientRect();
            window.scrollBy(0, (cel.top + cel.height / 2) - (przycisk.top + przycisk.height / 2));
        });
        await page.waitForTimeout(100);
        return {browser, page};
    } catch (e) { await browser.close(); throw e; }
}

for (const sposob of ['mysz', 'dotyk']) {
    test(`#684 cel pod przyciskiem Wygląd jest osiągalny: ${sposob}`, async () => {
        const {browser, page} = await podPrzyciskiem(sposob, KAFEL);
        try {
            const srodek = await page.evaluate(() => {
                const r = document.getElementById('cel').getBoundingClientRect();
                return {x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2)};
            });
            assert.equal(await page.evaluate(({x, y}) => document.elementFromPoint(x, y)?.id, srodek), 'cel',
                'PRZYCISK_WYGLAD_ZAKRYWA_CEL: środek kafla trafia w widget, nie w link');
            if (sposob === 'dotyk') await page.touchscreen.tap(srodek.x, srodek.y);
            else await page.mouse.click(srodek.x, srodek.y);
            await page.waitForURL('**/wpis');
        } finally { await browser.close(); }
    });
}

test('#684 kontrola dodatnia: nad zwykłym tekstem przycisk zostaje w rogu', async () => {
    const {browser, page} = await podPrzyciskiem('mysz', TEKST);
    try {
        assert.equal(await page.locator('[data-szybki-wyglad]').evaluate(e => e.hasAttribute('data-wyglad-w-przeplywie')), false,
            'Widget ustąpił, choć pod nim nie ma nic do kliknięcia.');
        assert.equal(await page.locator('[data-szybki-wyglad]').evaluate(e => getComputedStyle(e).position), 'fixed');
    } finally { await browser.close(); }
});
