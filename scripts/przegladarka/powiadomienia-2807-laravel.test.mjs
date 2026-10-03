/*
 * #2807: dwie odpowiedzi naprawdę renderuje Laravel na izolowanej PG18.
 * Chromium otrzymuje dokładnie te HTML-e przez HTTP i uruchamia moduł strony.
 * Usunięcie pustego ul z Blade zmienia odpowiedź i oblewa ten test.
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
const modul = resolve(repo, 'resources/js/pokaz-wiecej.js');

test('Laravel i Chromium: pusta dalsza strona powiadomień kończy listę (#2807)', async () => {
    assert.match(process.env.DB_DATABASE ?? '', /^kuking_test_2807_[a-z0-9_]+$/, 'Wymagana osobna baza tego pomiaru.');
    assert.equal(process.env.DB_HOST, '127.0.0.1');
    assert.match(process.env.DB_PORT ?? '', /^\d+$/);
    assert.notEqual(process.env.DB_PORT, '5432');
    assert.equal(process.env.DB_URL ?? '', '');

    const katalog = await mkdtemp(join(tmpdir(), 'kuking-2807-'));
    try {
        let bladPhp = null;
        try {
            // Po fizycznej mutacji i przywróceniu Blade mtime może być starszy
            // od skompilowanego widoku; zawsze renderuj bieżące źródło.
            await uruchom(process.env.PHP_BIN || 'php', ['artisan', 'view:clear'], {
                cwd: repo, env: {...process.env, APP_BASE_PATH: repo}, maxBuffer: 1024 * 1024,
            });
            await uruchom(process.env.PHP_BIN || 'php', [
                'artisan', 'test',
                '--filter=test_pusta_dalsza_strona_po_odczycie_w_drugiej_karcie_ma_droge_do_pierwszej',
                'tests/Feature/PowiadomieniaFiltrNieprzeczytanychTest.php',
            ], {cwd: repo, env: {
                ...process.env, APP_BASE_PATH: repo, APP_URL: 'http://localhost',
                POWIADOMIENIA_2807_HTML_KATALOG: katalog,
            }, maxBuffer: 1024 * 1024});
        } catch (error) {
            bladPhp = error;
        }
        if (bladPhp && process.env.POWIADOMIENIA_2807_PO_MUTACJI !== '1') throw bladPhp;

        const [pierwsza, druga, zrodlo] = await Promise.all([
            readFile(join(katalog, 'pierwsza.html'), 'utf8'),
            readFile(join(katalog, 'druga.html'), 'utf8'),
            readFile(modul, 'utf8'),
        ]);
        const zadania = [];
        const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined});
        try {
            const context = await browser.newContext();
            await context.route('http://localhost/**', (route) => {
                const url = new URL(route.request().url());
                if (url.pathname === '/pokaz-wiecej.js') {
                    return route.fulfill({contentType: 'text/javascript', body: zrodlo});
                }
                if (url.pathname === '/powiadomienia') {
                    zadania.push(url.pathname + url.search);
                    return route.fulfill({contentType: 'text/html', body: url.searchParams.get('page') === '2' ? druga : pierwsza});
                }
                return route.fulfill({status: 404, body: ''});
            });
            const page = await context.newPage();
            await page.goto('http://localhost/powiadomienia?zakres=nieprzeczytane');
            assert.equal(await page.locator('#lista-powiadomien > li').count(), 30);
            await page.addScriptTag({type: 'module', url: '/pokaz-wiecej.js'});
            const przycisk = page.getByRole('button', {name: 'Pokaż więcej powiadomień'});
            await przycisk.waitFor();
            await przycisk.click();
            await page.waitForFunction(() => document.querySelector('[data-pokaz-wiecej-ogloszenie]')?.textContent
                || ! document.querySelector('[data-pokaz-wiecej-blad]')?.hidden);
            assert.equal(await page.locator('#lista-powiadomien > li').count(), 30, 'POWIADOMIENIA_2807_LARAVEL_BROWSER: wcześniejsze karty pozostają.');
            assert.equal(await przycisk.count(), 0, 'POWIADOMIENIA_2807_LARAVEL_BROWSER: poprawna pusta odpowiedź kończy paginację.');
            assert.equal(await page.locator('[data-pokaz-wiecej-blad]').isHidden(), true, 'POWIADOMIENIA_2807_LARAVEL_BROWSER: brak fałszywej awarii.');
            assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), 'Nie ma nowych powiadomień do pokazania. To już koniec listy.');
            assert.equal(await page.evaluate(() => document.activeElement?.closest('li')?.parentElement?.id), 'lista-powiadomien');
            assert.deepEqual(zadania, ['/powiadomienia?zakres=nieprzeczytane', '/powiadomienia?zakres=nieprzeczytane&page=2']);
        } finally {
            await browser.close();
        }
    } finally {
        await rm(katalog, {recursive: true, force: true});
    }
});
