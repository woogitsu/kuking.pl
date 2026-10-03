/*
 * #2827: natywne zamknięcie odpowiedzi kończy Web Speech mimo pozostawienia
 * hosta w DOM. Chromium i prawdziwy moduł; silnik mowy jest syntetyczny.
 * Ten test jest osobnym krokiem CI, bo produkcyjny build nie ma Chromium.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const modul = new URL('../../resources/js/dyktowanie.js', import.meta.url);
const widok = new URL('../../resources/views/components/comment-thread.blade.php', import.meta.url);

test('zamknięcie odpowiedzi zatrzymuje mikrofon bez utraty tekstu', async () => {
    const blade = await readFile(widok, 'utf8');
    assert.match(blade, /<details data-dyktowanie-zamkniecie\b/, 'DYKTOWANIE_2827_ZAMKNIECIE: widok musi oznaczyć odpowiedź.');
    const zrodlo = await readFile(modul, 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext();
        await context.addInitScript(() => {
            window.__silniki = [];
            window.SpeechRecognition = class {
                constructor() { this.wolane = []; window.__silniki.push(this); }
                start() { this.wolane.push('start'); }
                stop() { this.wolane.push('stop'); }
                abort() { this.wolane.push('abort'); }
                wynik(tekst, koncowy) {
                    const wpis = [{ transcript: tekst }];
                    wpis.isFinal = koncowy;
                    this.onresult({ resultIndex: 0, results: [wpis] });
                }
            };
        });
        await context.route('http://kuking.test/**', async (route) => {
            if (new URL(route.request().url()).pathname === '/dyktowanie.js') {
                return route.fulfill({ contentType: 'text/javascript', body: zrodlo });
            }
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html lang="pl"><body>
                <details data-dyktowanie-zamkniecie><summary>Odpowiedz</summary>
                    <label for="odpowiedz">Odpowiedź</label><textarea id="odpowiedz">Własne słowa</textarea>
                    <div data-dyktowanie data-cel="odpowiedz" data-wstaw-napis="Wstaw do pola"></div>
                </details>
                <details data-dyktowanie-zamkniecie><summary>Druga odpowiedź</summary>
                    <label for="druga">Druga odpowiedź</label><textarea id="druga"></textarea>
                    <div data-dyktowanie data-cel="druga" data-wstaw-napis="Wstaw do pola"></div>
                </details>
                <script type="module" src="/dyktowanie.js"></script></body></html>` });
        });
        const page = await context.newPage();
        await page.goto('http://kuking.test/');
        const pierwsza = page.locator('details').first();
        const druga = page.locator('details').nth(1);
        await pierwsza.locator('summary').click();
        await pierwsza.getByRole('button', { name: 'Dyktuj' }).click();
        await page.evaluate(() => window.__silniki[0].wynik('podyktowane', false));
        await pierwsza.locator('summary').focus();
        await page.keyboard.press('Enter'); // natywny toggle klawiaturą
        await assert.doesNotReject(async () => {
            await page.waitForFunction(() => window.__silniki[0].wolane.includes('abort'), null, { timeout: 3000 });
        }, 'DYKTOWANIE_2827_ZAMKNIECIE: zamknięty formularz nadal słucha.');
        assert.equal(await pierwsza.getAttribute('open'), null);
        assert.equal(await page.locator('#odpowiedz').inputValue(), 'Własne słowa');
        await page.evaluate(() => window.__silniki[0].wynik('spóźnione', true));
        await pierwsza.locator('summary').click();
        assert.equal(await pierwsza.locator('.dyktowanie-podglad').textContent(), 'podyktowane');
        assert.equal(await page.evaluate(() => window.__silniki.length), 1, 'Ponowne otwarcie nie włącza mikrofonu samo.');
        await pierwsza.getByRole('button', { name: 'Dyktuj dalej' }).click();
        assert.equal(await page.evaluate(() => window.__silniki.length), 2);

        await druga.locator('summary').click();
        await druga.locator('summary').click();
        assert.equal(await page.evaluate(() => window.__silniki[1].wolane.includes('abort')), false, 'Zamknięcie innej odpowiedzi nie zatrzymuje tej sesji.');
        await page.evaluate(() => document.querySelector('details').remove());
        await page.waitForFunction(() => window.__silniki[1].wolane.includes('abort'));
        await context.close();
    } finally {
        await browser.close();
    }
});
