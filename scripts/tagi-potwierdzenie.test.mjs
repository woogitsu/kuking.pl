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
            await page.addScriptTag({ type: 'module', content: await readFile(new URL('../resources/js/tagi-w-opisie.js', import.meta.url), 'utf8') });
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
