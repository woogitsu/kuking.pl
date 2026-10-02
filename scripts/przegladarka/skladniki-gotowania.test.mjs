/*
 * Checklista przygotowania składników w trybie „Gotuję” (issue #2069).
 *
 * Leży w `scripts/przegladarka/`, a nie na liście `node --test` w `build`,
 * z tego samego powodu co `pokaz-wiecej.test.mjs`: potrzebuje Chromium,
 * a `npm run build` jedzie też w `Dockerfile` bez przeglądarki. Czyta
 * ZBUDOWANY arkusz z `public/build`, więc lokalnie:
 *   npm run build   (albo: npx vite build)
 *   node --test scripts/przegladarka/skladniki-gotowania.test.mjs
 *
 * Prawdziwy DOM, prawdziwy moduł `resources/js/skladniki-gotowania.js`,
 * prawdziwy arkusz i prawdziwe przeładowanie pod tym samym originem
 * (`sessionStorage` wymaga realnego pochodzenia — patrz komentarz
 * w `scripts/minutnik-regresja.mjs`). Znacznik jest w tym samym kształcie
 * co `<details class="cook-ingredients">` w `cooking.blade.php`; zgodność
 * kształtu z Blade pilnuje `ChecklistaSkladnikowGotowaniaTest`.
 *
 * CZEGO NIE DOWODZI: wypowiedzi prawdziwego czytnika ekranu (sprawdzamy
 * rolę, nazwę i stan pola w drzewie dostępności Chromium) ani fizycznego
 * telefonu (okno 320 px z dotykiem i `isMobile`).
 *
 * KONTROLA UJEMNA: podmiana modułu na pusty plik (SKLADNIKI_MODUL=/dev/null)
 * oblewa każdy test poza tym „bez skryptu”.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { utworzSerwer } from './skladniki-gotowania-server.mjs';

const MODUL = process.env.SKLADNIKI_MODUL
    ?? new URL('../../resources/js/skladniki-gotowania.js', import.meta.url).pathname;
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const CSS = readFileSync(`public/build/${manifest['resources/css/app.css'].file}`, 'utf8');

const PRZEPIS = '01a0e80d-0000-7000-8000-000000002069';
const SKLADNIKI = [
    { id: 'id-maka', grupa: 'Ciasto', tekst: '500 g mąki pszennej typ 450', notatka: 'przesianej' },
    { id: 'id-sol-ciasto', grupa: 'Ciasto', tekst: 'sól', doSmaku: true },
    { id: 'id-sol-farsz', grupa: 'Farsz', tekst: 'sól', doSmaku: true },
    { id: 'id-maslo', grupa: 'Farsz', tekst: '200 g masła', zamiennik: 'margaryna albo olej kokosowy' },
];

function wiersz(s) {
    return `<li data-skladnik="${s.id}">
        <label class="cook-skladnik">
        <input type="checkbox" class="cook-skladnik-pole" data-przygotowanie-pole hidden>
        <span class="cook-skladnik-tresc">
        ${s.tekst}
        ${s.doSmaku ? '<span class="meta"> — do smaku</span>' : ''}
        ${s.notatka ? `<span class="meta"> — ${s.notatka}</span>` : ''}
        ${s.zamiennik ? `<span class="skladnik-zamiennik">Zamiast tego: ${s.zamiennik}</span>` : ''}
        </span>
        <span class="cook-skladnik-stan" data-przygotowanie-stan aria-hidden="true" hidden>Przygotowane</span>
        </label>
    </li>`;
}

function strona(skladniki, krok, porcje) {
    const grupy = [...new Set(skladniki.map((s) => s.grupa))];
    const listy = grupy.map((g) => `<h3 class="naglowek-grupy">${g}</h3>
        <ul class="ingredient-list">${skladniki.filter((s) => s.grupa === g).map(wiersz).join('')}</ul>`).join('');

    return `<!doctype html><html lang="pl"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Gotuję</title><link rel="stylesheet" href="/app.css"></head>
        <body><main><article class="stack max-w-[38rem] mx-auto">
        <p class="cook-progress" aria-live="polite">Krok ${krok} z 2</p>
        <details class="cook-ingredients" data-przygotowanie="${PRZEPIS}" data-przygotowanie-porcje="${porcje}">
            <summary>Składniki (${skladniki.length})<span class="cook-przygotowanie-skrot" data-przygotowanie-podsumowanie hidden></span></summary>
            <p class="cook-przygotowanie-wstep" data-przygotowanie-wstep hidden>Możesz zaznaczyć składniki, które już masz odmierzone.</p>
            ${listy}
            <div class="cook-przygotowanie-akcje" data-przygotowanie-akcje hidden>
                <p class="cook-przygotowanie-licznik" data-przygotowanie-licznik aria-live="polite"></p>
                <button type="button" class="btn btn-secondary cook-przygotowanie-wyczysc" data-przygotowanie-wyczysc hidden>Wyczyść zaznaczenie składników</button>
            </div>
        </details>
        <section class="cook-step"><p class="cook-step-tekst">Krok numer ${krok}.</p>
            <form method="POST" action="/gotuj/zaznacz" class="cook-zaznacz">
                <button type="submit" class="btn btn-primary btn-cook">Oznacz krok jako zrobiony</button>
            </form>
        </section>
        </article></main>
        <script type="module" src="/skladniki-gotowania.js"></script></body></html>`;
}

let skladnikiSerwera = SKLADNIKI;
const zadaniaPost = [];
const serwer = utworzSerwer({ strona, skladniki: () => skladnikiSerwera, modul: MODUL, css: CSS, zadaniaPost });
await new Promise((r) => serwer.listen(0, '127.0.0.1', r));
const ADRES = `http://127.0.0.1:${serwer.address().port}`;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
test.after(async () => {
    await browser.close();
    serwer.close();
});

async function nowaStrona(opcje = {}) {
    const context = await browser.newContext({ viewport: { width: 390, height: 800 }, ...opcje });
    const page = await context.newPage();

    return { context, page };
}

async function otworz(page, krok = 1, porcje = 4) {
    skladnikiSerwera = skladnikiSerwera ?? SKLADNIKI;
    await page.goto(`${ADRES}/?krok=${krok}&porcje=${porcje}`);
    await page.waitForFunction(() => document.querySelector('details[data-przygotowanie-gotowe]') !== null);
    await page.evaluate(() => { document.querySelector('details.cook-ingredients').open = true; });
}

const pole = (page, id) => page.locator(`li[data-skladnik="${id}"] input[data-przygotowanie-pole]`);
const stan = (page, id) => page.locator(`li[data-skladnik="${id}"] [data-przygotowanie-stan]`);
const zapisane = (page, porcje = 4) => page.evaluate((k) => sessionStorage.getItem(k), `kuking.skladniki.${PRZEPIS}.${porcje}`);

test('odhaczenie A nie rusza B ani drugiej „soli”, stan ma słowo i przeżywa zmianę kroku', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await otworz(page, 1);
        for (const s of SKLADNIKI) assert.equal(await pole(page, s.id).isChecked(), false);

        // Klik w TEKST składnika (nie w kwadracik) — etykieta obejmuje wiersz.
        await page.locator('li[data-skladnik="id-sol-ciasto"] .cook-skladnik-tresc').click();
        assert.equal(await pole(page, 'id-sol-ciasto').isChecked(), true);
        assert.equal(await pole(page, 'id-sol-farsz').isChecked(), false, 'Druga „sól” (Farsz) ma własny stan.');
        assert.equal(await pole(page, 'id-maka').isChecked(), false);
        assert.equal(await stan(page, 'id-sol-ciasto').isVisible(), true);
        assert.equal((await stan(page, 'id-sol-ciasto').textContent()).trim(), 'Przygotowane');
        assert.equal(await stan(page, 'id-sol-farsz').isVisible(), false);
        assert.match(await page.locator('[data-przygotowanie-licznik]').textContent(), /Przygotowane: 1 z 4\. Zostało: 3\./);
        assert.match(await page.locator('summary').textContent(), /przygotowane 1 z 4/);
        assert.deepEqual(JSON.parse(await zapisane(page)), ['id-sol-ciasto']);

        // Zamiennik i notatka zostają czytelne po zaznaczeniu.
        await pole(page, 'id-maslo').check();
        assert.equal(await page.locator('li[data-skladnik="id-maslo"] .skladnik-zamiennik').isVisible(), true);

        // Następny krok = nowy dokument w tej samej karcie.
        await otworz(page, 2);
        assert.equal(await pole(page, 'id-sol-ciasto').isChecked(), true);
        assert.equal(await pole(page, 'id-maslo').isChecked(), true);
        assert.equal(await pole(page, 'id-sol-farsz').isChecked(), false);
        // Odświeżenie tej samej karty.
        await page.reload();
        await page.waitForFunction(() => document.querySelector('details[data-przygotowanie-gotowe]') !== null);
        assert.equal(await pole(page, 'id-sol-ciasto').isChecked(), true);
        assert.equal(await page.locator('details.cook-ingredients').evaluate((d) => d.open), false,
            'Sekcja składników po przeładowaniu znów startuje zwinięta.');
    } finally { await context.close(); }
});

test('druga karta zaczyna bez stanu z pierwszej', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await otworz(page);
        await pole(page, 'id-maka').check();
        const druga = await context.newPage();
        await otworz(druga);
        assert.equal(await pole(druga, 'id-maka').isChecked(), false);
        assert.equal(await druga.locator('[data-przygotowanie-wyczysc]').isVisible(), false);
    } finally { await context.close(); }
});

test('zmiana porcji wymaga ponownego potwierdzenia składników, zmiana kroku zachowuje odhaczenie', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await otworz(page, 1, 4);
        await page.evaluate(() => {
            sessionStorage.setItem('kuking.skladniki.inny-przepis.4', '["cudzy-skladnik"]');
            sessionStorage.setItem('kuking.minutnik.zupa.1', '123');
        });
        await pole(page, 'id-maka').check();
        assert.equal(await pole(page, 'id-maka').isChecked(), true);
        await otworz(page, 2, 4);
        assert.equal(await pole(page, 'id-maka').isChecked(), true, 'Sam krok nie zmienia ilości.');

        await otworz(page, 2, 8);
        assert.equal(await pole(page, 'id-maka').isChecked(), false, 'Nowa ilość nie jest automatycznie przygotowana.');
        assert.equal(await stan(page, 'id-maka').isVisible(), false);
        assert.match(await page.locator('[data-przygotowanie-wstep]').textContent(), /Sprawdź nowe ilości/);
        assert.equal(await zapisane(page, 4), null, 'Zmiana ilości usuwa dawny zapis tego przepisu.');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.skladniki.inny-przepis.4')), '["cudzy-skladnik"]');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.1')), '123');

        await pole(page, 'id-maka').check();
        assert.deepEqual(JSON.parse(await zapisane(page, 8)), ['id-maka']);
        await otworz(page, 1, 4);
        assert.equal(await pole(page, 'id-maka').isChecked(), false, 'Powrót do dawnej ilości wymaga ponownego odmierzenia.');
        assert.equal(await zapisane(page, 8), null, 'Zmiana powrotna usuwa także późniejszy zapis.');
    } finally { await context.close(); }
});

test('„Wyczyść zaznaczenie składników” czyści tylko checklistę: bez żądania, kroki i inne pamięci zostają', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await otworz(page);
        await page.evaluate(() => {
            sessionStorage.setItem('kuking.wakelock.zupa', '1');
            sessionStorage.setItem('kuking.minutnik.zupa.1', '123');
        });
        await pole(page, 'id-maka').check();
        await pole(page, 'id-sol-farsz').check();

        const zadania = [];
        page.on('request', (r) => zadania.push(r.url()));
        const postyPrzed = zadaniaPost.length;

        const przycisk = page.getByRole('button', { name: 'Wyczyść zaznaczenie składników' });
        assert.equal(await przycisk.isVisible(), true);
        await przycisk.click();

        for (const s of SKLADNIKI) assert.equal(await pole(page, s.id).isChecked(), false);
        assert.equal(await zapisane(page), null, 'Po wyczyszczeniu klucz znika z pamięci karty.');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.wakelock.zupa')), '1');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.1')), '123');
        assert.deepEqual(zadania, [], 'Wyczyszczenie nie wysyła niczego na serwer — kroki w sesji zostają.');
        assert.equal(zadaniaPost.length, postyPrzed);
        assert.match(await page.locator('[data-przygotowanie-licznik]').textContent(), /Odhaczenia kroków zostały bez zmian/);
        assert.equal(await przycisk.isVisible(), false, 'Nie ma już czego czyścić — przycisk się chowa.');
        assert.equal(await page.evaluate(() => document.activeElement?.closest('li')?.dataset.skladnik), 'id-maka',
            'Fokus nie przepada na <body> — idzie na pierwszy składnik.');

        // I odwrotnie: odhaczenie kroku (POST + przekierowanie) nie rusza checklisty.
        await pole(page, 'id-maslo').check();
        await page.getByRole('button', { name: 'Oznacz krok jako zrobiony' }).click();
        await page.waitForFunction(() => document.querySelector('details[data-przygotowanie-gotowe]') !== null);
        assert.equal(await pole(page, 'id-maslo').isChecked(), true);
    } finally { await context.close(); }
});

test('klucz to ID: zmiana kolejności nie przenosi odhaczenia, usunięty składnik znika przy zapisie', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await otworz(page);
        await page.evaluate((k) => sessionStorage.setItem(k, JSON.stringify(['id-maslo', 'id-usuniety'])), `kuking.skladniki.${PRZEPIS}.4`);
        skladnikiSerwera = [SKLADNIKI[3], SKLADNIKI[2], SKLADNIKI[1], SKLADNIKI[0]];
        await otworz(page);
        assert.equal(await pole(page, 'id-maslo').isChecked(), true);
        assert.equal(await pole(page, 'id-maka').isChecked(), false);
        assert.match(await page.locator('[data-przygotowanie-licznik]').textContent(), /Przygotowane: 1 z 4/,
            'Nieznane ID nie liczy się do postępu.');
        await pole(page, 'id-maka').check();
        assert.deepEqual(JSON.parse(await zapisane(page)).sort(), ['id-maka', 'id-maslo']);

        // Zepsuty zapis nie psuje strony.
        await page.evaluate((k) => sessionStorage.setItem(k, '{nie-json'), `kuking.skladniki.${PRZEPIS}.4`);
        await otworz(page);
        for (const s of SKLADNIKI) assert.equal(await pole(page, s.id).isChecked(), false);
    } finally {
        skladnikiSerwera = SKLADNIKI;
        await context.close();
    }
});

test('klawiatura i czytnik: Tab trafia w pole z nazwą składnika, spacja przełącza, fokus jest widoczny', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona();
    try {
        await page.goto(`${ADRES}/?krok=1`);
        await page.waitForFunction(() => document.querySelector('details[data-przygotowanie-gotowe]') !== null);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'SUMMARY');
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('details.cook-ingredients').evaluate((d) => d.open), true);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.closest('li')?.dataset.skladnik), 'id-maka');

        const fokus = await page.evaluate(() => {
            const s = getComputedStyle(document.activeElement);

            return { styl: s.outlineStyle, szer: parseFloat(s.outlineWidth), cien: s.boxShadow };
        });
        assert.ok((fokus.styl !== 'none' && fokus.szer > 0) || fokus.cien !== 'none', `Brak widocznego fokusu: ${JSON.stringify(fokus)}`);

        await page.keyboard.press('Space');
        assert.equal(await pole(page, 'id-maka').isChecked(), true);

        const drzewo = await page.getByRole('checkbox', { name: /500 g mąki pszennej typ 450 — przesianej/ });
        assert.equal(await drzewo.count(), 1, 'Nazwa pola to treść składnika z notatką.');
        assert.equal(await drzewo.isChecked(), true);
        assert.equal(await page.getByRole('checkbox', { name: /sól/ }).count(), 2);
    } finally { await context.close(); }
});

test('telefon 320 px: bez przewijania w bok, wiersz i przycisk ≥ 48 px, tekst ≥ 18 px', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona({ viewport: { width: 320, height: 740 }, isMobile: true, hasTouch: true });
    try {
        await otworz(page);
        await page.locator('li[data-skladnik="id-maslo"] .cook-skladnik-tresc').tap();
        assert.equal(await pole(page, 'id-maslo').isChecked(), true, 'Dotknięcie tekstu zaznacza składnik.');

        const pomiar = await page.evaluate(() => ({
            szerokosc: document.documentElement.scrollWidth,
            okno: document.documentElement.clientWidth,
            wiersze: [...document.querySelectorAll('.cook-skladnik')].map((l) => {
                const r = l.getBoundingClientRect();

                return { h: r.height, w: r.width, px: parseFloat(getComputedStyle(l.querySelector('.cook-skladnik-tresc')).fontSize) };
            }),
            pole: (() => { const r = document.querySelector('.cook-skladnik-pole').getBoundingClientRect(); return { w: r.width, h: r.height }; })(),
            stanPx: parseFloat(getComputedStyle(document.querySelector('[data-przygotowanie-stan]:not([hidden])')).fontSize),
            licznikPx: parseFloat(getComputedStyle(document.querySelector('[data-przygotowanie-licznik]')).fontSize),
            przycisk: (() => { const b = document.querySelector('[data-przygotowanie-wyczysc]'); const r = b.getBoundingClientRect(); return { h: r.height, prawa: r.right, px: parseFloat(getComputedStyle(b).fontSize) }; })(),
        }));

        assert.ok(pomiar.szerokosc <= pomiar.okno, `Przewijanie w bok: ${pomiar.szerokosc} > ${pomiar.okno}`);
        for (const w of pomiar.wiersze) {
            assert.ok(w.h >= 48, `Wiersz składnika ma ${w.h} px wysokości (< 48).`);
            assert.ok(w.w >= 200, `Wiersz składnika ma tylko ${w.w} px szerokości — cel dotyku to cała linia.`);
            assert.ok(w.px >= 18, `Tekst składnika ${w.px} px (< 18).`);
        }
        assert.ok(pomiar.pole.w >= 24 && pomiar.pole.h >= 24, `Pole ${JSON.stringify(pomiar.pole)} mniejsze niż 24 px (WCAG 2.5.8).`);
        assert.ok(pomiar.stanPx >= 18 && pomiar.licznikPx >= 18, `Napisy stanu ${pomiar.stanPx} / licznika ${pomiar.licznikPx} px (< 18).`);
        assert.ok(pomiar.przycisk.h >= 48, `Przycisk „Wyczyść…” ma ${pomiar.przycisk.h} px (< 48).`);
        assert.ok(pomiar.przycisk.px >= 18);
        assert.ok(pomiar.przycisk.prawa <= 320, 'Przycisk „Wyczyść…” wychodzi poza ekran.');
    } finally { await context.close(); }
});

test('bez skryptu: zwykła lista do czytania, bez pola, stanu i przycisku', async () => {
    skladnikiSerwera = SKLADNIKI;
    const { context, page } = await nowaStrona({ javaScriptEnabled: false });
    try {
        await page.goto(`${ADRES}/?krok=1`);
        await page.locator('summary').click();
        assert.equal(await page.locator('details.cook-ingredients').evaluate((d) => d.open), true);
        assert.equal(await page.locator('input[type=checkbox]:visible').count(), 0);
        assert.equal(await page.getByRole('button', { name: /Wyczyść/ }).count(), 0);
        assert.equal(await page.locator('[data-przygotowanie-stan]:visible').count(), 0);
        assert.equal(await page.locator('[data-przygotowanie-wstep]:visible, [data-przygotowanie-akcje]:visible').count(), 0);
        assert.equal(await page.getByText('500 g mąki pszennej typ 450').isVisible(), true);
        assert.equal(await page.getByText('Zamiast tego: margaryna albo olej kokosowy').isVisible(), true);
        assert.equal(await page.locator('.cook-skladnik').first().evaluate((l) => getComputedStyle(l).cursor === 'pointer'), false,
            'Bez skryptu wiersz nie udaje klikalnego.');
        // Etykieta nie może przejąć wyglądu etykiety pola z `tokens.css`
        // (pogrubienie, 18 px, margines) — tekst ma wyglądać jak przed #2069.
        const wyglad = await page.locator('li[data-skladnik="id-maka"]').evaluate((li) => {
            const l = getComputedStyle(li.querySelector('.cook-skladnik'));
            const w = getComputedStyle(li);

            return { etykieta: [l.fontWeight, l.fontSize, l.marginBottom], wiersz: [w.fontWeight, w.fontSize, '0px'] };
        });
        assert.deepEqual(wyglad.etykieta, wyglad.wiersz);
    } finally { await context.close(); }
});
