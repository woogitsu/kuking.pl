/*
 * =============================================================================
 *  DLACZEGO TEN TEST LEŻY W `scripts/przegladarka/`, A NIE W `scripts/`
 * =============================================================================
 *  Bo NIE MOŻE wejść na listę `node --test` w skrypcie `build` z package.json,
 *  a wszystko, co leży płasko w `scripts/`, na tę listę wejść musi.
 *
 *  `npm run build` biegnie w trzech miejscach, z których DWA nie mają i nie
 *  będą miały przeglądarki:
 *    1. `Dockerfile` (etap `assets`, obraz `node:22-bookworm-slim`) — obraz
 *       produkcyjny; dokładanie tam Chromium to setki megabajtów za nic.
 *       Ten etap kopiuje zresztą pojedyncze pliki z `scripts/`, nie cały
 *       katalog, więc test i tak by tam nie dojechał.
 *    2. `.github/workflows/ci.yml`, zadanie `assets` — `npm run build` stoi
 *       PRZED krokiem, który instaluje Chromium.
 *
 *  Test w `build` byłby więc czerwony w obu tych miejscach — i to czerwony
 *  z powodu, który nie ma nic wspólnego z testowanym zachowaniem.
 *
 *  URUCHAMIA GO WŁASNY KROK w `ci.yml`, w zadaniu `assets`, POSTAWIONY PO
 *  instalacji Chromium — tą samą drogą, którą już jedzie
 *  `scripts/port-grupy.test.mjs`. Lokalnie:
 *    npx playwright install chromium
 *    node --test scripts/przegladarka/tagi-potwierdzenie.test.mjs
 *
 *  UWAGA DLA STRAŻNIKA (`scripts/straznik-testow-js.test.mjs` z gałęzi
 *  `naprawa/testy-js-wchodza-do-ci`): ten katalog jest poza jego skanem,
 *  bo jego reguła brzmi „każdy test JS ma być na liście `build`", a dla
 *  testów przeglądarkowych ta reguła jest nie do spełnienia. To NIE jest
 *  wymigiwanie się: strażnik powinien objąć ten katalog własną regułą —
 *  „każdy plik z `scripts/przegladarka/` jest wołany nazwanym krokiem
 *  w `ci.yml`". Do rozstrzygnięcia przez autora strażnika.
 * =============================================================================
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

// Prawdziwy DOM i zdarzenia; odpowiedź wyszukiwarki jest izolowaną atrapą.
// Test nie mierzy wypowiedzi czytnika ekranu ani backendu wyszukiwania.
for (const method of ['kliknięcie', 'Enter']) {
    test(`Potwierdzenie wyboru tagu pozostaje po obsłudze zdarzeń: ${method}`, async () => {
        const browser = await chromium.launch();
        try {
            const page = await browser.newPage();
            await page.route('http://kuking.test/**', route => route.fulfill({
                contentType: 'text/html',
                body: '<form><div data-tagi-opis data-tagi-endpoint="/tags" data-tagi-min="2" data-tagi-max="50"><textarea name="body"></textarea></div><button>Wyślij</button></form>',
            }));
            let requests = 0;
            await page.route('http://kuking.test/tags?*', route => {
                requests++;
                return route.fulfill({ json: { tags: [{ name: 'sernik', slug: 'sernik', public_posts_count: 3 }], can_create: false, exact_match: false } });
            });
            await page.goto('http://kuking.test/');
            await page.evaluate(() => {
                window.submits = 0;
                window.inputs = [];
                document.querySelector('form').addEventListener('submit', e => { e.preventDefault(); window.submits++; });
                document.querySelector('textarea').addEventListener('input', e => window.inputs.push(e.target.value));
            });
            await page.addScriptTag({ type: 'module', content: await readFile(new URL('../../resources/js/tagi-w-opisie.js', import.meta.url), 'utf8') });
            await page.waitForSelector('[data-tagi-ready]');
            const input = page.locator('textarea');
            await input.fill('Dziś #ser');
            await page.getByRole('option').waitFor();
            if (method === 'kliknięcie') await page.getByRole('option').click();
            else { await input.press('ArrowDown'); await input.press('Enter'); }
            // Odczekujemy również opóźnione selectionchange/select i debounce wyszukiwania.
            await page.waitForTimeout(400);
            assert.equal(await input.inputValue(), 'Dziś #sernik');
            assert.equal(await page.getByRole('listbox', { includeHidden: true }).isHidden(), true);
            assert.equal(await page.getByRole('status').textContent(), 'Tag jest w opisie. Możesz pisać dalej.');
            assert.deepEqual(await page.evaluate(() => ({ submits: window.submits, value: window.inputs.at(-1) })), { submits: 0, value: 'Dziś #sernik' });
            assert.equal(requests, 1, 'Wybór nie powinien otwierać kolejnego wyszukiwania.');
            await input.press('Space');
            assert.equal(await page.getByRole('status').textContent(), '');
        } finally {
            await browser.close();
        }
    });
}
