/*
 * #957 — kolaż hero na stronie powitalnej: żądania sieciowe w prawdziwym
 * Chromium przy 390 px i 1280 px.
 *
 * Leży w `scripts/przegladarka/` z tego samego powodu co sąsiedzi (potrzebuje
 * Chromium, którego nie ma w `npm run build`); woła go nazwany krok w ci.yml.
 * Lokalnie:
 *   npx playwright install chromium   (albo CHROMIUM_PATH=/ścieżka/do/chrome)
 *   node --test scripts/przegladarka/kolaz-lcp.test.mjs
 *
 * Co jest dowodzone:
 *  - pierwszy kafel jest pobierany od razu, z priorytetem sieciowym High
 *    (`fetchpriority="high"`), także gdy leży poniżej pierwszego ekranu;
 *  - pozostałe kafle NIE dostają High (nie konkurują z pierwszym o łącze);
 *  - na obu szerokościach pierwszy kafel startuje jako pierwszy z czterech.
 *
 * Czego NIE dowodzi, świadomie: „zera pobrań na telefonie”. Od 13.09.2026
 * kolaż jest na telefonie widoczny (decyzja właściciela, komentarz w
 * `landing.blade.php`), więc telefon pobiera kafle tak samo jak desktop.
 *
 * Znaczniki `<img>` NIE są tu przepisane z pamięci: test wyciąga blok
 * `hero-kolaz-kafel` z `landing.blade.php` i rozwija `@if($loop->first)`,
 * więc zepsucie widoku psuje ten test. Serwer to atrapa (`page.route`),
 * bez sieci zewnętrznej.
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

const blade = readFileSync(new URL('../../resources/views/pages/landing.blade.php', import.meta.url), 'utf8');

function znacznikKafla(indeks) {
    const blok = blade.match(/<img class="hero-kolaz-kafel"[\s\S]*?decoding="async">/);
    assert.ok(blok, 'BRAK_ZNACZNIKA_KAFLA: nie znaleziono <img class="hero-kolaz-kafel"> w landing.blade.php');
    const pierwszy = indeks === 0;
    return blok[0]
        .replace(/@if\(\$loop->first\)([\s\S]*?)@else([\s\S]*?)@endif/, (_, tak, nie) => (pierwszy ? tak : nie))
        .replace(/src="\{\{[^}]*\}\}"/, `src="/kafel-${indeks}.png"`)
        .replace(/\{\{[^}]*\}\}/g, '320');
}

// Jednopikselowy PNG; rozmiar zadają atrybuty width/height z widoku.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

function strona(wysokoscNadKolazem) {
    const kafle = [0, 1, 2, 3].map(znacznikKafla).join('\n');
    return `<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{margin:0}.hero-kolaz{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hero-kolaz-kafel{width:100%;height:auto}</style></head><body>
<h1>Strona powitalna</h1><div style="height:${wysokoscNadKolazem}px"></div>
<div class="hero-kolaz">${kafle}</div></body></html>`;
}

async function zmierz(szerokosc, wysokosc, wysokoscNadKolazem) {
    const opcje = {headless: true};
    if (process.env.CHROMIUM_PATH) opcje.executablePath = process.env.CHROMIUM_PATH;
    const browser = await chromium.launch(opcje);
    try {
        const page = await browser.newPage({viewport: {width: szerokosc, height: wysokosc}});
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Network.enable');
        const zadania = [];
        cdp.on('Network.requestWillBeSent', e => {
            const m = new URL(e.request.url).pathname.match(/^\/kafel-(\d)\.png$/);
            if (m) zadania.push({kafel: Number(m[1]), priorytet: e.request.initialPriority});
        });
        await page.route('http://kuking.test/**', route => {
            const sciezka = new URL(route.request().url()).pathname;
            if (/^\/kafel-\d\.png$/.test(sciezka)) return route.fulfill({contentType: 'image/png', body: PNG});
            return route.fulfill({contentType: 'text/html', body: strona(wysokoscNadKolazem)});
        });
        await page.goto('http://kuking.test/', {waitUntil: 'load'});
        await page.waitForTimeout(300);
        return zadania;
    } finally { await browser.close(); }
}

for (const [nazwa, szer, wys, nad] of [
    ['390 px (telefon, kolaż pod pierwszym ekranem)', 390, 844, 900],
    ['1280 px (desktop, kolaż w pierwszym paśmie)', 1280, 800, 0],
]) {
    test(`kolaż hero: pierwszy kafel od razu z High, reszta bez High — ${nazwa}`, async () => {
        const zadania = await zmierz(szer, wys, nad);
        const pierwszy = zadania.find(z => z.kafel === 0);
        assert.ok(pierwszy, 'PIERWSZY_KAFEL_NIE_POBRANY');
        assert.equal(zadania[0].kafel, 0, 'PIERWSZY_KAFEL_NIE_STARTUJE_PIERWSZY');
        assert.ok(['High', 'VeryHigh'].includes(pierwszy.priorytet), `PIERWSZY_KAFEL_BEZ_WYSOKIEGO_PRIORYTETU: ${pierwszy.priorytet}`);
        for (const z of zadania.filter(z => z.kafel !== 0)) {
            assert.ok(!['High', 'VeryHigh'].includes(z.priorytet), `KAFEL_${z.kafel}_KONKURUJE_WYSOKIM_PRIORYTETEM: ${z.priorytet}`);
        }
    });
}
