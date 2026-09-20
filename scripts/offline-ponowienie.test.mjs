// Prawdziwy Chromium i service worker; serwer fixture nie wymaga bazy.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (['/sw.js', '/offline.html', '/icons/kuking-mark.svg', '/icons/kuking-icon-192.png'].includes(path)) {
        response.setHeader('Content-Type', path.endsWith('.js') ? 'application/javascript' : path.endsWith('.html') ? 'text/html; charset=utf-8' : path.endsWith('.svg') ? 'image/svg+xml' : 'image/png');
        response.end(await readFile(`public${path}`));
    } else {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<h1>Otwarta strona: ${request.url.replaceAll('&', '&amp;').replaceAll('<', '&lt;')}</h1>`);
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(base);
    await page.evaluate(async () => {
        await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;
    });
    await page.reload();
    for (const path of ['/przepisy/rosol-fixture', '/szukaj?q=zupa&czas=30']) {
        await context.setOffline(true);
        await page.goto(base + path);
        await page.getByRole('heading', { name: 'Nie ma teraz połączenia z internetem' }).waitFor();
        await context.setOffline(false);
        await page.getByRole('link', { name: 'Spróbuj ponownie' }).click();
        await page.getByRole('heading', { name: `Otwarta strona: ${path}`, exact: true }).waitFor({ timeout: 5000 });
        assert.equal(page.url(), base + path);
        console.log(`Zachowano adres i treść: ${path}`);
    }
    const cached = await page.evaluate(async () => {
        const result = [];
        for (const key of await caches.keys()) {
            for (const request of await (await caches.open(key)).keys()) result.push(new URL(request.url).pathname);
        }
        return result;
    });
    assert.deepEqual(cached.sort(), ['/icons/kuking-icon-192.png', '/icons/kuking-mark.svg', '/offline.html'].sort());
    await context.setOffline(true);
    assert.equal(await page.evaluate(() => fetch('/wpisy', { method: 'POST', body: 'fixture' }).then(() => 'response', () => 'network-error')), 'network-error');
    console.log('POST bez ponawiania; HTML odwiedzanych stron poza cache.');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
