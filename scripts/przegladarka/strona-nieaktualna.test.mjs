/*
 * =============================================================================
 *  419 Z LIVEWIRE: POLSKI KOMUNIKAT ZAMIAST ANGIELSKIEGO confirm() (#977)
 * =============================================================================
 *  Prawdziwy klient Livewire (`vendor/livewire/livewire/dist/livewire.js` —
 *  ten sam plik, który serwuje `@livewireScripts`) w Chromium. Odpowiedź
 *  `/livewire/update` jest atrapą 419 — dokładnie tym, co zwraca serwer po
 *  `LivewireReleaseTokenMismatchException`. Po stronie PHP token sprawdza
 *  `tests/Feature/LivewireReleaseTokenZWydaniaTest.php`.
 *
 *  KONTROLA DODATNIA (AGENTS.md §10): ta sama strona BEZ naszego modułu musi
 *  otworzyć angielskie okienko Livewire'a. Jeśli nie otwiera, atrapa nie
 *  trafia w gałąź 419 i reszta testu niczego nie dowodzi.
 *
 *  Leży w `scripts/przegladarka/`, bo potrzebuje Chromium i `vendor/` —
 *  powody w nagłówku `tagi-potwierdzenie.test.mjs`. Uruchamia go nazwany
 *  krok w `ci.yml` w zadaniu z `composer install` i Chromium. Lokalnie:
 *    node --test scripts/przegladarka/strona-nieaktualna.test.mjs
 *  (LIVEWIRE_JS=ścieżka, gdy `vendor/` jest gdzie indziej).
 * =============================================================================
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const korzen = new URL('../../', import.meta.url);
const livewireJs = readFileSync(process.env.LIVEWIRE_JS ?? new URL('vendor/livewire/livewire/dist/livewire.js', korzen), 'utf8');
const modul = readFileSync(new URL('resources/js/strona-nieaktualna.js', korzen), 'utf8');

// Zbudowany CSS (gdy jest), żeby zmierzyć prawdziwy przycisk: ≥ 48 px.
const manifestUrl = new URL('public/build/manifest.json', korzen);
const css = existsSync(manifestUrl)
    ? readFileSync(new URL(`public/build/${JSON.parse(readFileSync(manifestUrl, 'utf8'))['resources/css/app.css'].file}`, korzen), 'utf8')
    : null;

function strona({ zapis, zNaszymModulem }) {
    const snapshot = JSON.stringify({
        data: { heroPhoto: null },
        memo: { id: 'k1', name: 'recipe-wizard', path: 'dodaj/przepis', method: 'GET', release: 'a-stary-sha-', children: [], scripts: [], assets: [], errors: [], locale: 'pl' },
        checksum: '0',
    }).replaceAll("'", '&#39;');
    const kreator = zapis === null ? '' : ` data-kreator-zapis="${zapis}"`;

    return `<!doctype html><html lang="pl"><head><meta charset="utf-8">
${css ? `<style>${css}</style>` : ''}
</head><body><main>
<div wire:id="k1" wire:snapshot='${snapshot}' wire:effects='{}'${kreator}>
  <textarea>Długi opis, który ktoś właśnie pisze</textarea>
  <button type="button" wire:click="next">Dalej</button>
  <input id="f-heroPhoto" type="file" wire:model="heroPhoto">
</div>
</main>
<script src="/livewire.js" data-csrf="token" data-update-uri="/livewire/update"></script>
${zNaszymModulem ? `<script type="module">
  import { podlaczStronaNieaktualna } from '/strona-nieaktualna.js';
  const podlacz = () => podlaczStronaNieaktualna(window.Livewire);
  if (window.Livewire) podlacz(); else document.addEventListener('livewire:init', podlacz);
</script>` : ''}
</body></html>`;
}

async function otworz(browser, opcje) {
    const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
    const okienka = [];
    page.on('dialog', async (d) => { okienka.push(d.message()); await d.dismiss(); });
    const zadania = [];
    await page.route('http://kuking.test/**', (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/livewire.js') return route.fulfill({ contentType: 'text/javascript', body: livewireJs });
        if (url.pathname === '/strona-nieaktualna.js') return route.fulfill({ contentType: 'text/javascript', body: modul });
        if (url.pathname === '/livewire/update') {
            zadania.push(route.request().postData());
            return route.fulfill({ status: 419, contentType: 'application/json', json: { message: 'Livewire detected a release token mismatch.' } });
        }
        return route.fulfill({ contentType: 'text/html', body: strona(opcje) });
    });
    await page.goto('http://kuking.test/dodaj/przepis');
    await page.waitForFunction(() => window.Livewire?.all?.().length === 1);
    return { page, okienka, zadania };
}

test('Kontrola dodatnia: bez modułu Livewire 4 otwiera angielskie confirm() przy 419', async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    try {
        const { page, okienka, zadania } = await otworz(browser, { zapis: 'szkic', zNaszymModulem: false });
        await page.getByRole('button', { name: 'Dalej' }).click();
        await page.waitForTimeout(500);
        assert.equal(zadania.length, 1, 'kliknięcie musi wysłać żądanie Livewire');
        assert.deepEqual(okienka, ['This page has expired.\nWould you like to refresh the page?']);
    } finally {
        await browser.close();
    }
});

test('Zapisany szkic: polski komunikat, żadnego okienka, fokus i przycisk „Odśwież stronę”', async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    try {
        const { page, okienka } = await otworz(browser, { zapis: 'szkic', zNaszymModulem: true });
        await page.getByRole('button', { name: 'Dalej' }).click();
        const alert = page.getByRole('alert');
        await alert.waitFor();
        assert.deepEqual(okienka, [], 'żadnego window.confirm');
        const tekst = await alert.innerText();
        assert.match(tekst, /Ta strona jest nieaktualna/);
        assert.match(tekst, /Szkic zapisany wcześniej zostaje\./);
        assert.match(tekst, /Ostatnia zmiana w formularzu mogła się jednak nie zapisać/);
        assert.doesNotMatch(tekst, /Zdjęcie/);
        assert.equal(await page.evaluate(() => document.activeElement?.textContent), 'Ta strona jest nieaktualna');

        const odswiez = alert.getByRole('button', { name: 'Odśwież stronę' });
        if (css) {
            const { height } = await odswiez.boundingBox();
            assert.ok(height >= 48, `przycisk ma ${height} px, a musi mieć ≥ 48 px`);
        }
        // Tekst w formularzu zostaje nietknięty do chwili odświeżenia.
        assert.equal(await page.locator('textarea').inputValue(), 'Długi opis, który ktoś właśnie pisze');

        // Drugie 419 (jak z debounce kreatora, gdy ktoś pisze dalej) nie dokłada
        // okienka ani drugiej ramki i NIE zabiera fokusu z pola. Klik z JS,
        // żeby sam klik nie przeniósł fokusu na przycisk.
        await page.locator('textarea').focus();
        await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Dalej').click());
        await page.waitForTimeout(300);
        assert.equal(await page.getByRole('alert').count(), 1);
        assert.deepEqual(okienka, []);
        assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'TEXTAREA', 'drugie 419 nie zabiera fokusu');

        await Promise.all([page.waitForEvent('load'), odswiez.click()]);
    } finally {
        await browser.close();
    }
});

test('Niezapisany przepis i zdjęcie w trakcie przesyłania: tekst nie obiecuje bezpieczeństwa', async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    try {
        const { page, okienka, zadania } = await otworz(browser, { zapis: 'brak', zNaszymModulem: true });
        await page.locator('#f-heroPhoto').setInputFiles({ name: 'danie.jpg', mimeType: 'image/jpeg', buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9]) });
        const alert = page.getByRole('alert');
        await alert.waitFor();
        assert.match(zadania[0] ?? '', /_startUpload/, 'wybór pliku musi pójść przez _startUpload');
        assert.deepEqual(okienka, []);
        const tekst = await alert.innerText();
        assert.match(tekst, /Ten przepis nie jest jeszcze zapisany\. Po odświeżeniu formularz będzie pusty/);
        assert.match(tekst, /Zdjęcie wybrane przed chwilą nie zostało przesłane\. Po odświeżeniu dodaj je jeszcze raz\./);
        assert.doesNotMatch(tekst, /zostaje/);
    } finally {
        await browser.close();
    }
});

test('Poza kreatorem: ogólny komunikat bez słowa o szkicu', async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    try {
        const { page, okienka } = await otworz(browser, { zapis: null, zNaszymModulem: true });
        await page.getByRole('button', { name: 'Dalej' }).click();
        const tekst = await page.getByRole('alert').innerText();
        assert.deepEqual(okienka, []);
        assert.match(tekst, /Odśwież stronę i zrób to jeszcze raz\./);
        assert.doesNotMatch(tekst, /[Ss]zkic/);
    } finally {
        await browser.close();
    }
});
