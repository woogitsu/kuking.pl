/*
 * =============================================================================
 *  #899 — KLIK W ODNOŚNIK DO KREATORA PRZY ZMIENIONYM FORMULARZU
 * =============================================================================
 *  Leży w `scripts/przegladarka/`, nie na liście `node --test` w `build`, z tego
 *  samego powodu co `tagi-potwierdzenie.test.mjs` (pełne uzasadnienie w jego
 *  nagłówku): potrzebuje Chromium. Woła go nazwany krok w `ci.yml`, w zadaniu
 *  `assets`, po `npm run build`. Lokalnie:
 *    npm run build
 *    node --test scripts/przegladarka/niezapisane-zmiany.test.mjs
 *
 *  CO JEST PRAWDZIWE. Skrypt to ZBUDOWANY pakiet `resources/js/app.js`
 *  z `public/build` — więc test pada także wtedy, gdy ktoś wytnie import
 *  `niezapisane-zmiany.js` z `app.js`. Oba odnośniki są wycięte z
 *  `pages/recipes/szczegoly.blade.php`, a ramka jest wyrenderowanym
 *  `components/ostrzezenie-niezapisanych.blade.php` (podstawienie trzech
 *  zmiennych, bez Laravela) — zmiana atrybutów w Blade też go wywróci.
 *  Formularz jest atrapą z polami każdego rodzaju, jakie ma ekran szczegółów.
 *  Wyrenderowany HTML całej strony sprawdza osobno
 *  `tests/Feature/FormularzeKlawiaturaINiezapisaneZmianyTest.php`.
 *
 *  KONTROLA UJEMNA jest w samym teście: ta sama strona BEZ pakietu przy
 *  zmienionym formularzu MUSI przejść do kreatora — czyli asercja „nie ma
 *  nawigacji” rozróżnia stronę chronioną od niechronionej.
 * =============================================================================
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const pakiet = readFileSync(`public/build/${manifest['resources/js/app.js'].file}`, 'utf8');
const ADRES = 'http://kuking.test';
const KREATOR = `${ADRES}/przepisy/rosol-babci/kreator`;

const szczegoly = readFileSync('resources/views/pages/recipes/szczegoly.blade.php', 'utf8');
const odnosniki = [...szczegoly.matchAll(/<a href="\{\{ \$kreatorUrl \}\}"[^>]*>[^<]*<\/a>/g)].map(m => m[0].replace('{{ $kreatorUrl }}', KREATOR));
assert.equal(odnosniki.length, 2, 'szczegoly.blade.php ma mieć dwa odnośniki do kreatora (górny i przy składnikach).');

const komponent = readFileSync('resources/views/components/ostrzezenie-niezapisanych.blade.php', 'utf8')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@props\([^)]*\)/, '').trim();
const ramka = id => komponent.replace('{{ $id }}', id).replace('{{ $href }}', KREATOR).replace('{{ $zapisz }}', 'Zapisz zmiany');
assert(!/\{\{|@/.test(ramka('x')), 'Komponent ramki ma składnię Blade, której test nie podstawia — rozszerz podstawienie.');

const strona = ({ zPakietem = true, odSerwera = false } = {}) => `<!doctype html><html lang="pl"><head><meta charset="utf-8"></head>
<body data-marka="kuking-2026"><main>
<p class="field-help">Wolisz krok po kroku? ${odnosniki[0]}.</p>
${ramka('ostrzezenie-kreatora-gora')}
<form id="formularz-szczegolow" method="POST" action="/zapisz"${odSerwera ? ' data-niezapisane-od-serwera' : ''}>
<input type="hidden" name="_token" value="abc">
<label>Tytuł <input name="title" value="Rosół babci"></label>
<label>Opis <textarea name="description">Klarowny.</textarea></label>
<label>Porcje <select name="servings"><option>2</option><option>4</option><option>6</option></select></label>
<label>Trudność <select name="difficulty"><option value="latwy">Łatwy</option><option value="sredni" selected>Średni</option></select></label>
<label><input type="checkbox" name="vege" checked> Wegetariański</label>
<label>Zdjęcie <input type="file" name="photo"></label>
<label>Składnik <input name="ingredients[0][name]" value="marchew"></label>
<p class="field-help">W ${odnosniki[1]} wiersze dodaje się od razu.</p>
${ramka('ostrzezenie-kreatora-skladniki')}
<button type="submit">Zapisz zmiany</button>
</form></main>
${zPakietem ? '<script type="module" src="/build/app.js"></script>' : ''}
</body></html>`;

async function otworz(browser, opcje) {
    const page = await browser.newPage();
    const bledy = [];
    page.on('pageerror', e => bledy.push(e.message));
    await page.route(`${ADRES}/**`, route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/build/app.js') return route.fulfill({ contentType: 'text/javascript', body: pakiet });
        if (url.pathname.endsWith('/kreator')) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Kreator</title><h1>Kreator</h1>' });
        return route.fulfill({ contentType: 'text/html', body: strona(opcje) });
    });
    await page.goto(`${ADRES}/przepisy/rosol-babci/edytuj`);
    // Pakiet to moduł: czekamy, aż się wykona (Vite ładuje go jako ES module).
    if (opcje?.zPakietem !== false) await page.waitForFunction(() => document.readyState === 'complete');
    await page.waitForTimeout(100);
    return { page, bledy };
}

const LINKI = [
    ['górny', '#ostrzezenie-kreatora-gora', 'a[data-niezapisane-ostrzezenie="ostrzezenie-kreatora-gora"]'],
    ['przy składnikach', '#ostrzezenie-kreatora-skladniki', 'a[data-niezapisane-ostrzezenie="ostrzezenie-kreatora-skladniki"]'],
];

const launch = () => chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });

for (const [nazwa, ramkaId, link] of LINKI) {
    test(`#899 ${nazwa}: zmienione pole + klik → ramka, bez nawigacji; „Zostań” i Esc zostawiają dane`, async () => {
        const browser = await launch();
        try {
            const { page, bledy } = await otworz(browser);
            const znacznik = `Rosół babci ZNACZNIK-${Date.now()}`;
            await page.fill('[name="title"]', znacznik);
            await page.fill('[name="ingredients[0][name]"]', 'marchew ZNACZNIK');

            await page.click(link);
            await page.waitForTimeout(200);
            assert.equal(page.url(), `${ADRES}/przepisy/rosol-babci/edytuj`, 'Klik przy zmienionym formularzu przeszedł do kreatora — dane przepadły.');
            assert.equal(await page.locator(ramkaId).isVisible(), true, 'Ramka z pytaniem się nie pokazała.');
            assert.equal(await page.evaluate(id => document.activeElement?.id === id.slice(1), ramkaId), true, 'Fokus ma przejść na ramkę.');
            assert.equal(await page.locator(ramkaId).getAttribute('role'), null, 'Ramka z fokusem bez role=alert (inaczej czytnik czyta ją dwa razy).');

            await page.locator(ramkaId).getByRole('button', { name: 'Zostań na tej stronie' }).click();
            assert.equal(await page.locator(ramkaId).isHidden(), true);
            assert.equal(await page.inputValue('[name="title"]'), znacznik, '„Zostań” musi zostawić wpisany tekst.');
            assert.equal(await page.inputValue('[name="ingredients[0][name]"]'), 'marchew ZNACZNIK');
            assert.equal(await page.evaluate(sel => document.activeElement === document.querySelector(sel), link), true, 'Fokus wraca na odnośnik.');

            await page.click(link);
            await page.waitForTimeout(100);
            assert.equal(await page.locator(ramkaId).isVisible(), true);
            await page.keyboard.press('Escape');
            assert.equal(await page.locator(ramkaId).isHidden(), true, 'Esc zamyka ramkę jak „Zostań”.');
            assert.equal(page.url(), `${ADRES}/przepisy/rosol-babci/edytuj`);
            assert.equal(await page.inputValue('[name="title"]'), znacznik);

            await page.click(link);
            await Promise.all([
                page.waitForURL(KREATOR),
                page.locator(ramkaId).getByRole('link', { name: 'Przejdź do kreatora bez tych zmian' }).click(),
            ]);
            assert.deepEqual(bledy, []);
        } finally {
            await browser.close();
        }
    });

    test(`#899 ${nazwa}: niezmieniony formularz (także lista bez „selected”) + klik → przejście`, async () => {
        const browser = await launch();
        try {
            const { page, bledy } = await otworz(browser);
            // Wpisane i cofnięte, lista bez `selected` pokazuje pierwszą opcję — to nie jest zmiana.
            await page.fill('[name="title"]', 'coś innego');
            await page.fill('[name="title"]', 'Rosół babci');
            assert.equal(await page.inputValue('[name="servings"]'), '2');
            await Promise.all([page.waitForURL(KREATOR), page.click(link)]);
            assert.deepEqual(bledy, []);
        } finally {
            await browser.close();
        }
    });

    test(`#899 ${nazwa}: formularz odesłany z błędami pyta nawet bez dotykania pól`, async () => {
        const browser = await launch();
        try {
            const { page } = await otworz(browser, { odSerwera: true });
            await page.click(link);
            await page.waitForTimeout(200);
            assert.equal(page.url(), `${ADRES}/przepisy/rosol-babci/edytuj`);
            assert.equal(await page.locator(ramkaId).isVisible(), true);
        } finally {
            await browser.close();
        }
    });
}

test('#899: wybór innej opcji na liście bez „selected” też pyta', async () => {
    const browser = await launch();
    try {
        const { page } = await otworz(browser);
        await page.selectOption('[name="servings"]', '4');
        await page.click(LINKI[0][2]);
        await page.waitForTimeout(200);
        assert.equal(await page.locator(LINKI[0][1]).isVisible(), true);
    } finally {
        await browser.close();
    }
});

test('#899 kontrola ujemna: bez skryptu zmieniony formularz przechodzi od razu — asercja „bez nawigacji” to rozróżnia', async () => {
    const browser = await launch();
    try {
        const { page } = await otworz(browser, { zPakietem: false });
        await page.fill('[name="title"]', 'Zmienione bez ochrony');
        await Promise.all([page.waitForURL(KREATOR), page.click(LINKI[1][2])]);
    } finally {
        await browser.close();
    }
});
