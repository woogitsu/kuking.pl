// Chromium + rzeczywisty HTML z testu HTTP na PostgreSQL; bez połączeń z produkcją.
// PHOTO_BROWSER_FIXTURES=1 ... --filter LimityIPodgladZdjecTest, npm run build,
// następnie node scripts/zdjecia-limity.mjs. POST przechwytujemy przed siecią.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';

const browser = await chromium.launch({headless: true, ...(existsSync('/opt/pw-browsers/chromium-1194/chrome-linux/chrome') ? {executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'} : {})});
const source = readFileSync('resources/js/app.js', 'utf8');
const preview = source.slice(source.indexOf('function kotwicaPodPolem'), source.indexOf('// --- Nieudana wysyłka'));
assert.ok(preview.length > 1000, 'Pomiar musi wykonywać prawdziwy handler podglądu.');
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = readFileSync(`public/build/${manifest['resources/css/app.css'].file}`, 'utf8');
const testedScript = process.env.PHOTO_FULL_BUNDLE
    ? readFileSync(`public/build/${manifest['resources/js/app.js'].file}`, 'utf8')
    : preview;
const files = ['A', 'B', 'C'].map(name => ({name: `${name}.png`, mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')}));
const results = [];
async function open(form, options = {}) {
    const page = await browser.newPage({viewport: {width: 320, height: 800}, ...options});
    const html = readFileSync(`output/playwright/${form}.html`, 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace('</head>', `<style>${css}</style></head>`);
    await page.route('**/*', route => route.fulfill({status: 200, contentType: 'text/html', body: route.request().url() === 'http://kuking.test/form' ? html : 'Zapisano próbę.'}));
    page.setDefaultTimeout(10000);
    await page.goto('http://kuking.test/form');
    return page;
}
const names = page => page.locator('#f-photos').evaluate(input => new FormData(input.form).getAll('photos[]').filter(file => file.name).map(file => file.name));
try {
    for (const form of ['post', 'cooked']) {
        console.log(form, 'start');
        const page = await open(form);
        await page.evaluate(() => {
            window.activeUrls = new Set();
            const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
            URL.createObjectURL = file => { const url = create(file); activeUrls.add(url); return url; };
            URL.revokeObjectURL = url => { activeUrls.delete(url); revoke(url); };
        });
        await page.addScriptTag({content: testedScript});
        const text = page.locator(`textarea[name="${form === 'post' ? 'body' : 'note'}"]`);
        await text.fill('Zachowaj mój opis.');
        await page.locator('#f-photos').setInputFiles(files);
        const buttons = page.locator('#f-photos-podglad button');
        assert.equal(await buttons.count(), 3);
        const aria = await page.locator('#f-photos-podglad').ariaSnapshot();
        assert.match(aria, /Usuń zdjęcie 2: B.png/);
        const size = await buttons.first().evaluate(el => ({height: el.getBoundingClientRect().height, font: parseFloat(getComputedStyle(el).fontSize)}));
        assert.ok(size.height >= 48 && size.font >= 18, JSON.stringify(size));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        await buttons.nth(1).focus();
        await page.keyboard.press('Enter');
        assert.deepEqual(await names(page), ['A.png', 'C.png']);
        assert.equal(await text.inputValue(), 'Zachowaj mój opis.');
        assert.equal(await page.locator('#f-photos-podglad button:focus').count(), 1);
        assert.match(await page.locator('#f-photos-wybor-status').innerText(), /Wybrane zdjęcia: 2/);
        await page.screenshot({path: `output/playwright/${form}-wybor.png`, fullPage: true});
        await page.setViewportSize({width: 640, height: 900});
        await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        await buttons.first().focus();
        assert.equal(await page.locator('#f-photos-podglad button:focus').count(), 1);
        const request = page.waitForRequest(r => r.method() === 'POST');
        await page.locator(`button[type="submit"]`).filter({hasText: form === 'post' ? /^Opublikuj$/ : /^Wyślij$/}).click();
        const payload = (await request).postDataBuffer().toString();
        assert.match(payload, /filename="A.png"/);
        assert.match(payload, /filename="C.png"/);
        assert.doesNotMatch(payload, /filename="B.png"/);
        await page.close();

        console.log(form, 'multipart PASS');
        const empty = await open(form);
        await empty.addScriptTag({content: testedScript});
        await empty.locator('#f-photos').setInputFiles(files);
        for (let i = 0; i < 3; i++) await empty.locator('#f-photos-podglad button').first().click();
        assert.deepEqual(await names(empty), []);
        assert.equal(await empty.locator('#f-photos:focus').count(), 1);
        assert.match(await empty.locator('[role="status"]').filter({hasText: 'Nie wybrano nowych zdjęć.'}).innerText(), /Nie wybrano/);
        await empty.locator('#f-photos').setInputFiles(files);
        await empty.locator('#f-photos').evaluate(input => {
            input.dataset.photoLimit = '3';
            const saved = document.createElement('input');
            saved.type = 'hidden'; saved.name = 'media_ids[]'; saved.value = 'zachowane'; input.form.append(saved);
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
        assert.match(await empty.locator('#f-photos-wybor-status').innerText(), /Łącznie ze zdjęciami zachowanymi: 4. Limit: 3/);
        assert.deepEqual(await names(empty), ['A.png', 'B.png', 'C.png']);
        await empty.locator('#f-photos-podglad button').first().click();
        assert.doesNotMatch(await empty.locator('#f-photos-wybor-status').innerText(), /Limit:/);
        assert.equal(await empty.locator('input[name="media_ids[]"]').inputValue(), 'zachowane');
        await empty.locator('#f-photos').evaluate(input => input.form.reset());
        assert.equal(await empty.locator('#f-photos-podglad button').count(), 0);
        await empty.close();

        console.log(form, 'empty PASS');
        for (const fallback of ['no-transfer', 'setter-refuses']) {
            const p = await open(form);
            await p.addScriptTag({content: testedScript});
            if (fallback === 'no-transfer') await p.evaluate(() => { window.DataTransfer = undefined; });
            await p.locator('#f-photos').setInputFiles(files);
            if (fallback === 'setter-refuses') {
                await p.locator('#f-photos').evaluate(input => {
                    const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'files');
                    Object.defineProperty(input, 'files', {get() { return descriptor.get.call(this); }, set() { throw new Error('Odmowa testowa'); }});
                });
                await p.locator('#f-photos-podglad button').nth(1).click();
                assert.match(await p.locator('#f-photos-wybor-status').innerText(), /Nie udało się usunąć/);
            } else {
                assert.equal(await p.locator('#f-photos-podglad button').count(), 0);
                assert.match(await p.locator('#f-photos-wybor-status').innerText(), /wybierz zdjęcia ponownie/);
            }
            assert.deepEqual(await names(p), ['A.png', 'B.png', 'C.png']);
            await p.close();
        }
        console.log(form, 'fallback PASS');
        const nojs = await open(form, {javaScriptEnabled: false});
        await nojs.locator('#f-photos').setInputFiles(files);
        assert.equal(await nojs.locator('#f-photos-podglad button').count(), 0);
        await nojs.locator(`textarea[name="${form === 'post' ? 'body' : 'note'}"]`).fill('Bez skryptu.');
        const nativeRequest = nojs.waitForRequest(r => r.method() === 'POST');
        await nojs.getByRole('button', {name: form === 'post' ? 'Opublikuj' : 'Wyślij', exact: true}).click();
        const nativePayload = (await nativeRequest).postDataBuffer().toString();
        for (const file of files) assert.ok(nativePayload.includes(`filename="${file.name}"`));
        assert.ok(nativePayload.includes('Bez skryptu.'));
        await nojs.close();
        results.push({form, size, multipart: ['A.png', 'C.png'], noJavaScript: 'POST z trzema plikami', fallback: 'oba warianty zachowują pliki', emptyAndReset: 'PASS', savedAndNewLimit: 'PASS'});
        console.log(JSON.stringify(results.at(-1)));
    }
    const scope = await open('post');
    await scope.locator('#f-photos').evaluate(input => input.removeAttribute('data-remove-photos'));
    await scope.addScriptTag({content: testedScript});
    await scope.locator('#f-photos').setInputFiles(files);
    assert.equal(await scope.locator('#f-photos-podglad button').count(), 0);
    assert.equal(await scope.locator('#f-photos-podglad img').count(), 3);
    await scope.close();
    const lifecycle = await open('post');
    await lifecycle.evaluate(() => {
        window.activeUrls = new Set();
        const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
        URL.createObjectURL = file => { const url = create(file); activeUrls.add(url); return url; };
        URL.revokeObjectURL = url => { activeUrls.delete(url); revoke(url); };
    });
    await lifecycle.addScriptTag({content: testedScript});
    await lifecycle.locator('#f-photos').setInputFiles([{name: 'uszkodzone.png', mimeType: 'image/png', buffer: Buffer.from('nie jest obrazem')}]);
    await lifecycle.waitForFunction(() => activeUrls.size === 0);
    await lifecycle.locator('#f-photos').setInputFiles(files);
    const cleanup = await lifecycle.locator('#f-photos').evaluate(input => {
        input.dispatchEvent(new Event('change', {bubbles: true}));
        const previous = [...activeUrls];
        input.dispatchEvent(new Event('change', {bubbles: true}));
        const replaced = previous.every(url => !activeUrls.has(url));
        window.dispatchEvent(new Event('pagehide'));
        return {replaced, active: activeUrls.size};
    });
    assert.deepEqual(cleanup, {replaced: true, active: 0});
    await lifecycle.close();
    results.push({urls: 'error, ponowny wybór przed load i pagehide: PASS', zoom: 'CSS 200%, szerokość efektywna 320 px: PASS', scope: 'pole bez opt-in: brak usuwania'});
    writeFileSync('output/playwright/zdjecia-limity.json', JSON.stringify(results, null, 2));
} finally {
    await browser.close();
}
