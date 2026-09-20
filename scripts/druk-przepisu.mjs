// Pomiar prawdziwego HTML Laravel z WydrukPrzepisuFixtureTest i CSS z buildu.
import { chromium } from 'playwright';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const phase = process.argv[2] || 'po';
const root = 'output/playwright/druk765';
await mkdir(`${root}/${phase}`, { recursive: true });
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const css = manifest['resources/css/app.css'].file;
const server = createServer(async (request, response) => {
    try {
        const path = new URL(request.url, 'http://localhost').pathname;
        const target = path.startsWith('/build/') ? `public${path}` : `${root}/${path.slice(1)}`;
        response.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : path.endsWith('.html') ? 'text/html; charset=utf-8' : 'application/octet-stream');
        response.end(await readFile(target));
    } catch { response.writeHead(404); response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
const results = [];
try {
    for (const name of ['krotki', 'dlugi']) for (const theme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: { width: 794, height: 1123 } });
        // Obraz kontrolny zachowuje proporcje zdjęcia 1600×1200. Nie jest zdjęciem użytkownika.
        await page.route('**/zdjecia/**', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1200"><rect width="1600" height="1200" fill="#ded2b7"/><circle cx="800" cy="600" r="380" fill="#eee"/><text x="450" y="620" font-size="70">Obraz kontrolny A4</text></svg>' }));
        await page.goto(`${base}/${name}-${theme}.html`);
        await page.addStyleTag({ url: `${base}/build/${css}` });
        await page.emulateMedia({ media: 'print', colorScheme: theme });
        await page.evaluate(() => document.fonts.ready);
        const pdf = `${root}/${phase}/${name}-${theme}.pdf`;
        await page.pdf({ path: pdf, format: 'A4', printBackground: true, margin: { top: '12mm', right: '12mm', bottom: '12mm', left: '12mm' } });
        const text = execFileSync('pdftotext', ['-layout', pdf, '-'], { encoding: 'utf8' });
        const info = execFileSync('pdfinfo', [pdf], { encoding: 'utf8' });
        const pages = Number(info.match(/Pages:\s+(\d+)/)[1]);
        const metrics = await page.evaluate(() => ({
            controls: [...document.querySelectorAll('button, form, nav')].filter(el => el.getBoundingClientRect().height > 0).length,
            background: getComputedStyle(document.body).backgroundColor,
            colorScheme: getComputedStyle(document.documentElement).colorScheme,
            steps: document.querySelectorAll('.step-list > li').length,
        }));
        results.push({ name, theme, pages, ...metrics });
        if (phase !== 'przed') {
            assert.equal(metrics.controls, 0, 'Na papierze zostały przyciski, formularze albo nawigacja');
            assert.equal(metrics.background, 'rgb(255, 255, 255)', 'Ciemne tło na papierze');
            assert.equal(metrics.colorScheme, 'light', 'Ciemne marginesy na papierze');
            assert(!text.includes('Powiększ zdjęcie'), 'Na papierze została akcja powiększania');
            for (const content of ['Autorka przepisu A4', 'Ciasto', 'Farsz', 'drobno posiekany', 'do smaku', 'example.org/przepis-pierogi']) assert(text.includes(content), `Brak: ${content}`);
            for (const group of ['Ciasto', 'Farsz']) for (let i = 1; i <= (name === 'dlugi' ? 9 : 3); i++) assert(text.includes(`${group} składnik ${i}`), `Brak składnika: ${group} ${i}`);
            const instructions = await page.locator('.step-list > li > div > p').allTextContents();
            assert.equal(instructions.length, name === 'dlugi' ? 14 : 3);
            const normalized = text.replace(/\s+/g, ' ');
            for (const instruction of instructions) assert(normalized.includes(instruction.replace(/\s+/g, ' ').trim()), 'Niepełna treść kroku w PDF');
            for (let i = 1; i <= (name === 'dlugi' ? 14 : 3); i++) assert(text.includes(`Koniec kroku ${i}.`), `Ucięto krok ${i}`);
        }
        await page.close();
    }
    await writeFile(`${root}/${phase}/pomiar.json`, JSON.stringify({ chromium: browser.version(), results }, null, 2));
    console.log(JSON.stringify(results, null, 2));
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
