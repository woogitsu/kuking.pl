/*
 * #2857: formularz renderuje Laravel na osobnej PG18; Chromium wykonuje
 * prawdziwy wybór pliku, kliknięcia i żądanie multipart. Odpowiedź POST jest
 * przechwycona, więc ten test nie twierdzi, że serwer zapisał wykonanie.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';
import {chromium} from 'playwright';

const uruchom = promisify(execFile);
const repo = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const znacznik = 'DOPISEK_2857_DOM_PLIK_ZOSTAJE';

test('Laravel i Chromium: wstawienie dopisku zachowuje pola i plik w multipart (#2857)', async () => {
    assert.match(process.env.DB_DATABASE ?? '', /^kuking_test_2857_[a-z0-9_]+$/);
    assert.equal(process.env.DB_HOST, '127.0.0.1');
    assert.match(process.env.DB_PORT ?? '', /^\d+$/);
    assert.notEqual(process.env.DB_PORT, '5432');
    assert.equal(process.env.DB_URL ?? '', '');

    const katalog = await mkdtemp(join(tmpdir(), 'kuking-2857-'));
    try {
        await uruchom(process.env.PHP_BIN || 'php', ['artisan', 'view:clear'], {
            cwd: repo, env: {...process.env, APP_BASE_PATH: repo}, maxBuffer: 1024 * 1024,
        });
        await uruchom(process.env.PHP_BIN || 'php', [
            'artisan', 'test', '--filter=test_przycisk_wstawia_w_biezacym_formularzu_bez_porzucajacego_linku',
            'tests/Feature/DopisekZGotowaniaTest.php',
        ], {cwd: repo, env: {...process.env, APP_BASE_PATH: repo, APP_ENV: 'testing', APP_URL: 'http://localhost', DOPISEK_2857_HTML_KATALOG: katalog}, maxBuffer: 1024 * 1024});
        const [html, modul, wejscie] = await Promise.all([
            readFile(join(katalog, 'formularz.html'), 'utf8'),
            readFile(resolve(repo, 'resources/js/wstaw-dopisek-ugotowalem.js'), 'utf8'),
            readFile(resolve(repo, 'resources/js/app.js'), 'utf8'),
        ]);
        const manifest = JSON.parse(await readFile(resolve(repo, 'public/build/manifest.json'), 'utf8'));
        const css = await readFile(resolve(repo, 'public/build', manifest['resources/css/app.css'].file), 'utf8');
        assert.match(wejscie, /import '\.\/wstaw-dopisek-ugotowalem\.js'/, `${znacznik}: moduł musi być włączony na stronie.`);
        const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined});
        try {
            const context = await browser.newContext({viewport: {width: 320, height: 900}});
            let wyslanie = null;
            await context.route('http://localhost/**', (route) => {
                if (route.request().method() === 'POST') {
                    wyslanie = route.request().postDataBuffer();
                    return route.fulfill({contentType: 'text/html', body: '<h1>Żądanie odebrane</h1>'});
                }
                if (new URL(route.request().url()).pathname === '/formularz') {
                    // Niezwiązane moduły strony nie uczestniczą w teście tej akcji.
                    const strona = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
                        .replace('</head>', '<link rel="stylesheet" href="/build/2857.css"></head>');
                    return route.fulfill({contentType: 'text/html', body: strona});
                }
                if (new URL(route.request().url()).pathname === '/build/2857.css') {
                    return route.fulfill({contentType: 'text/css', body: css});
                }
                return route.fulfill({status: 404, body: ''});
            });
            const page = await context.newPage();
            await page.goto('http://localhost/formularz');
            assert.equal(await page.locator('[data-wstaw-dopisek]').isHidden(), true, `${znacznik}: bez modułu nie wolno pokazać martwego przycisku.`);
            assert.equal(await page.locator('[data-dopisek-recznie]').isVisible(), true, `${znacznik}: bez modułu potrzebna jest instrukcja ręczna.`);
            await page.addScriptTag({type: 'module', content: modul});
            const przycisk = page.locator('[data-wstaw-dopisek]');
            assert.equal(await przycisk.count(), 1, `${znacznik}: oczekiwano przycisku w rzeczywistym widoku.`);
            await przycisk.waitFor({state: 'visible'});
            assert.equal(await page.locator('[data-dopisek-recznie]').isHidden(), true);
            assert.ok((await przycisk.boundingBox())?.height >= 48, `${znacznik}: przycisk musi mieć co najmniej 48 px.`);
            await page.locator('[name="note"]').fill('Świeża uwaga z tej karty');
            await page.locator('[name="changes_note"]').fill('Moje świeże zmiany');
            await page.locator('[name="actual_minutes"]').fill('45');
            await page.locator('[name="dzien_gotowania"]').fill('2026-10-01');
            await page.locator('[name="faktyczne_porcje"]').fill('2,5');
            await page.locator('[name="would_make_again"][value="1"]').check();
            await page.locator('#f-photos').setInputFiles({name: 'moje-danie.jpg', mimeType: 'image/jpeg', buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9])});
            await przycisk.click();
            assert.equal(await page.locator('[data-dopisek-potwierdzenie]').isVisible(), true, `${znacznik}: nie wolno nadpisać niepustego pola bez wyboru.`);
            await page.locator('[data-dopisek-zostaw]').click();
            assert.equal(await page.locator('[name="changes_note"]').inputValue(), 'Moje świeże zmiany', `${znacznik}: odmowa zachowuje tekst.`);
            await przycisk.click();
            await page.locator('[data-dopisek-zastap]').click();
            assert.equal(await page.locator('[name="changes_note"]').inputValue(), 'dolane 50 ml wody, dłuższy czas', `${znacznik}: świadome wstawienie dopisku.`);
            assert.equal(await page.locator('[name="note"]').inputValue(), 'Świeża uwaga z tej karty', `${znacznik}: uwaga została utracona.`);
            assert.equal(await page.locator('[name="actual_minutes"]').inputValue(), '45', `${znacznik}: czas został utracony.`);
            assert.equal(await page.locator('[name="dzien_gotowania"]').inputValue(), '2026-10-01', `${znacznik}: dzień został utracony.`);
            assert.equal(await page.locator('[name="faktyczne_porcje"]').inputValue(), '2,5', `${znacznik}: porcje zostały utracone.`);
            assert.equal(await page.locator('[name="would_make_again"][value="1"]').isChecked(), true, `${znacznik}: wybór został utracony.`);
            assert.deepEqual(await page.locator('#f-photos').evaluate(el => Array.from(el.files ?? [], f => f.name)), ['moje-danie.jpg'], `${znacznik}: FileList została utracona.`);
            const odpowiedz = page.waitForResponse(response => response.request().method() === 'POST');
            await page.getByRole('button', {name: 'Wyślij'}).click();
            await odpowiedz;
            assert.ok(wyslanie, `${znacznik}: kliknięcie „Wyślij” nie wywołało POST (adres: ${page.url()}).`);
            assert.ok(wyslanie?.includes(Buffer.from('filename="moje-danie.jpg"')), `${znacznik}: plik nie trafił do rzeczywistego żądania multipart.`);
            assert.ok(wyslanie?.includes(Buffer.from([0xff, 0xd8, 0xff, 0xd9])), `${znacznik}: bajty pliku nie trafiły do wysłania.`);

            // Osobny odbiór przy 200%: reguła UX wymienia 320 px i powiększenie
            // jako dwa warunki, nie wymaga ich złożenia w 160 px CSS.
            const powiekszona = await context.newPage();
            await powiekszona.setViewportSize({width: 1280, height: 900});
            await powiekszona.goto('http://localhost/formularz');
            await powiekszona.addScriptTag({type: 'module', content: modul});
            await powiekszona.evaluate(() => { document.documentElement.style.zoom = '2'; });
            await powiekszona.locator('[data-wstaw-dopisek]').click();
            assert.equal(await powiekszona.locator('[name="changes_note"]').inputValue(), 'dolane 50 ml wody, dłuższy czas', `${znacznik}: przy 200% wstawienie nie działa.`);
        } finally {
            await browser.close();
        }
    } finally {
        await rm(katalog, {recursive: true, force: true});
    }
});
