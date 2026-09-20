import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Pełny moduł produkcyjny w Chromium; kontrolowany serwer i mały DOM panelu.
// To nie jest test zapisu konta ani fizycznego telefonu.
const source = readFileSync(new URL('../resources/js/szybki-wyglad.js', import.meta.url), 'utf8');
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
