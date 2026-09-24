/*
 * =============================================================================
 *  #947 — POLE Z FOKUSEM NIE CHOWA SIĘ POD BELKAMI PRZY OTWARTEJ KLAWIATURZE
 * =============================================================================
 *  Leży w `scripts/przegladarka/`, nie na liście `node --test` w `build`, z tego
 *  samego powodu co `tagi-potwierdzenie.test.mjs` (pełne uzasadnienie w jego
 *  nagłówku): potrzebuje Chromium, a `npm run build` biegnie też w Dockerfile
 *  i w zadaniu `assets` PRZED instalacją przeglądarki. Woła go nazwany krok
 *  w `ci.yml`, w zadaniu `assets`, po `npm run build` — test czyta ZBUDOWANY
 *  arkusz z `public/build`. Lokalnie:
 *    npm run build
 *    node --test scripts/przegladarka/klawiatura-belki.test.mjs
 *
 *  JAK TO EMULUJE KLAWIATURĘ. `layout.blade.php` deklaruje
 *  `interactive-widget=resizes-content`: klawiatura zmniejsza LAYOUT viewport,
 *  nie tylko visual viewport. Desktopowy Chromium nie ma klawiatury ekranowej,
 *  więc po fokusie zmniejszamy okno o wysokość klawiatury — dokładnie to, co
 *  przy tej deklaracji robi Chrome na Androidzie — i przewijamy pole do widoku
 *  tak jak przeglądarka po otwarciu klawiatury. Test MIERZY, że emulacja jest
 *  tym, za co się podaje: `visualViewport.height === clientHeight` (layout
 *  i visual viewport równe), a potem geometrię pola i obu belek.
 *
 *  CZEGO NIE DOWODZI. Fizycznego telefonu, Safari (pomija `interactive-widget`)
 *  ani rzeczywistych wysokości klawiatur — przyjęte niżej liczby to typowe
 *  klawiatury (portret ~340 px, poziomo ~200 px, tablet ~240 px). DOM jest
 *  reprezentatywny: te same klasy ramy co w layoucie, bez Laravela.
 *
 *  KONTROLA UJEMNA jest w samym teście: ten sam pomiar na arkuszu z wyłączoną
 *  regułą `@media (max-height: 25rem)` z `marka-rama.css` MUSI wykryć pole
 *  zasłonięte belką — inaczej test niczego nie pilnuje.
 * =============================================================================
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = readFileSync(`public/build/${manifest['resources/css/app.css'].file}`, 'utf8');
const REGULA = '@media (height<=25rem){';
assert.equal(css.split(REGULA).length, 2, `Zbudowany arkusz ma mieć dokładnie jedną regułę ${REGULA} (marka-rama.css, #947).`);

const layout = readFileSync('resources/views/components/layout.blade.php', 'utf8');
const meta = layout.match(/<meta name="viewport" content="([^"]+)">/);
assert(meta, 'layout.blade.php nie ma meta viewport');
assert(meta[1].split(',').map(s => s.trim()).includes('interactive-widget=resizes-content'),
    'Bez interactive-widget=resizes-content klawiatura nie zmniejsza layout viewport i ten pomiar nie opisuje telefonu.');

// [szerokość, wysokość okna, wysokość z otwartą klawiaturą]
const OKNA = [
    [390, 844, 500],
    [844, 390, 190],
    [768, 500, 260],
];
const POLA = ['#pole-0', '#pole-5', '#pole-11', '#zapisz'];

const pola = Array.from({ length: 12 }, (_, i) =>
    `<div class="field"><label class="field-label" for="pole-${i}">Pole ${i + 1}</label><input id="pole-${i}" class="field-input"></div>`).join('');
const html = skala => `<!doctype html><html lang="pl" data-text-scale="${skala}"><head><meta charset="utf-8">
<meta name="viewport" content="${meta[1]}"></head>
<body data-marka="kuking-2026">
<header class="topbar marka-topbar" data-pasek-przewijany><div class="topbar-inner"><a class="wordmark" href="#">KuKing.pl</a>
<div class="topbar-actions"><a class="btn btn-secondary" href="#">Szukaj</a><a class="btn btn-primary" href="#">Konto</a></div></div></header>
<div class="app-body marka-rama"><main class="app-main" id="tresc"><form class="panel-formularza">${pola}
<div class="form-actions"><button id="zapisz" class="btn btn-primary" type="button">Zapisz szkic</button></div></form></main></div>
<nav class="bottom-nav" aria-label="Nawigacja główna">${['Start', 'Szukaj', 'Dodaj', 'Moje', 'Profil'].map(n => `<a class="bottom-nav-item" href="#">${n}</a>`).join('')}</nav>
</body></html>`;

async function zmierz(browser, arkusz, [szerokosc, wysokosc, zKlawiatura], skala, cel) {
    const page = await browser.newPage({ viewport: { width: szerokosc, height: wysokosc }, reducedMotion: 'reduce' });
    try {
        await page.setContent(html(skala));
        await page.addStyleTag({ content: arkusz });
        const przed = await page.evaluate(() => ({
            gora: getComputedStyle(document.querySelector('.marka-topbar')).position,
            dol: getComputedStyle(document.querySelector('.bottom-nav')).position,
        }));
        await page.locator(cel).focus();
        await page.setViewportSize({ width: szerokosc, height: zKlawiatura });
        const klaw = await page.evaluate(async cel => {
            document.querySelector(cel).scrollIntoView({ block: 'nearest' });
            for (let i = 0; i < 3; i++) await new Promise(requestAnimationFrame);
            const ramka = s => { const r = document.querySelector(s).getBoundingClientRect(); return { top: r.top, bottom: r.bottom }; };
            return {
                visual: visualViewport.height,
                layout: document.documentElement.clientHeight,
                fokus: document.activeElement === document.querySelector(cel),
                pole: ramka(cel), gora: ramka('.marka-topbar'), dol: ramka('.bottom-nav'),
            };
        }, cel);
        // Zamknięcie klawiatury: okno wraca, fokus schodzi z pola.
        await page.locator(cel).blur();
        await page.setViewportSize({ width: szerokosc, height: wysokosc });
        const po = await page.evaluate(() => ({
            gora: getComputedStyle(document.querySelector('.marka-topbar')).position,
            dol: getComputedStyle(document.querySelector('.bottom-nav')).position,
        }));
        return { przed, klaw, po };
    } finally {
        await page.close();
    }
}

function zaslonieta({ klaw }) {
    const { pole, gora, dol, layout } = klaw;
    const nachodzi = belka => belka.bottom > pole.top + 0.5 && belka.top < pole.bottom - 0.5;
    if (pole.top < -0.5 || pole.bottom > layout + 0.5) return 'pole poza widocznym obszarem';
    if (nachodzi(gora)) return 'pole pod górną belką';
    if (nachodzi(dol)) return 'pole pod dolną belką';
    return null;
}

test('#947: pole z fokusem i przycisk zapisu nie chowają się pod belkami przy klawiaturze', async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    try {
        for (const okno of OKNA) for (const skala of [100, 140]) for (const cel of POLA) {
            const wynik = await zmierz(browser, css, okno, skala, cel);
            const opis = `${okno[0]}×${okno[1]} → ${okno[0]}×${okno[2]}, tekst ${skala}%, ${cel}: ${JSON.stringify(wynik)}`;
            assert.equal(wynik.klaw.fokus, true, `Fokus zgubiony — ${opis}`);
            assert.equal(wynik.klaw.layout, okno[2], `Emulacja nie zmniejszyła layout viewport — ${opis}`);
            assert.equal(wynik.klaw.visual, wynik.klaw.layout, `visualViewport ≠ clientHeight: to nie jest resizes-content — ${opis}`);
            assert.equal(zaslonieta(wynik), null, `${zaslonieta(wynik)} — ${opis}`);
            assert.deepEqual(wynik.po, wynik.przed, `Po zamknięciu klawiatury belki nie wróciły — ${opis}`);
        }
    } finally {
        await browser.close();
    }
});

test('#947: tablet 768×500 bez klawiatury zostaje przypięty, telefon poziomo nie', async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    try {
        const tablet = await zmierz(browser, css, [768, 500, 500], 100, '#pole-0');
        assert.deepEqual(tablet.przed, { gora: 'sticky', dol: 'fixed' }, 'Reguła niskiego okna nie może odpinać belek na każdym tablecie w poziomie.');
        const telefon = await zmierz(browser, css, [844, 390, 390], 100, '#pole-0');
        assert.deepEqual(telefon.przed, { gora: 'relative', dol: 'relative' }, 'Telefon w poziomie (390 px) ma odpięte belki.');
    } finally {
        await browser.close();
    }
});

test('#947 kontrola ujemna: bez reguły niskiego okna pomiar wykrywa zasłonięte pole', async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    try {
        const bezReguly = css.replace(REGULA, '@media (height<=0rem){');
        assert.notEqual(bezReguly, css);
        for (const okno of [[844, 390, 190], [768, 500, 260]]) {
            const wynik = await zmierz(browser, bezReguly, okno, 100, '#pole-5');
            assert.notEqual(zaslonieta(wynik), null, `Bez reguły pomiar nie widzi problemu przy ${okno.join('/')} — test niczego nie pilnuje: ${JSON.stringify(wynik)}`);
        }
    } finally {
        await browser.close();
    }
});
