/*
 * „Pokaż więcej” dokłada porcję do listy, zamiast ją podmieniać (#986).
 *
 * Leży w `scripts/przegladarka/`, a nie na liście `node --test` w `build`,
 * z tego samego powodu co `tagi-potwierdzenie.test.mjs`: potrzebuje Chromium,
 * a `npm run build` jedzie też w `Dockerfile` bez przeglądarki. Uruchamia go
 * własny krok w `ci.yml`. Lokalnie:
 *   node --test scripts/przegladarka/pokaz-wiecej.test.mjs
 *
 * Prawdziwy DOM, prawdziwy moduł `resources/js/pokaz-wiecej.js`, prawdziwe
 * przeładowanie i powrót w historii. Serwer jest atrapą (`page.route`), która
 * oddaje znacznik w tym samym kształcie co `components/show-more.blade.php`;
 * zgodność tego kształtu z Blade pilnuje `PokazWiecejDokladaPorcjeTest`.
 * Test NIE mierzy wypowiedzi czytnika ekranu — sprawdza treść regionu
 * `aria-live` i to, gdzie stoi fokus.
 *
 * KONTROLA UJEMNA: podmiana modułu na pusty plik (czyli powrót do samego
 * `nextPageUrl()` bez dokładania) oblewa każdy test poniżej poza ostatnim,
 * który sprawdza właśnie zachowanie bez skryptu. Patrz
 * `scripts/kontrole-negatywne-alfa08.py`.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const MODUL = process.env.POKAZ_WIECEJ_MODUL
    ?? new URL('../../resources/js/pokaz-wiecej.js', import.meta.url).pathname;
const NA_PORCJE = 5;
const PORCJI = 3;

function blokWiecej({klucz, czego, lista, nastepny}) {
    if (! nastepny) return '';

    return `<div class="pokaz-wiecej" data-pokaz-wiecej="${klucz}" data-pokaz-wiecej-czego="${czego}"
        data-pokaz-wiecej-etykieta="Pokaż więcej ${czego}" ${lista ? `data-pokaz-wiecej-lista="${lista}"` : ''}>
        <p><a class="btn btn-secondary" href="${nastepny}">Następna strona ${czego}</a></p>
        <p class="visually-hidden" aria-live="polite" data-pokaz-wiecej-ogloszenie></p>
        <p class="field-error" role="alert" data-pokaz-wiecej-blad hidden></p>
    </div>`;
}

/* Jedna lista „wpisów” z kursorem; wysokie karty, żeby dało się przewijać. */
function elementy(prefiks, od, do_) {
    let html = '';
    for (let i = od; i <= do_; i++) {
        html += `<article class="card" data-klucz="${prefiks}-${i}" style="height:300px"><a href="/wpis/${prefiks}-${i}">${prefiks} ${i}</a></article>`;
    }

    return html;
}

function strona(tresc) {
    return `<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Lista</title></head>
        <body><main><h1>Lista</h1>${tresc}<footer style="height:400px">Stopka</footer></main>
        <script type="module" src="/pokaz-wiecej.js"></script></body></html>`;
}

async function przygotuj(context, {
    lista = 'lista-wpisow',
    przesuniecie = 0,
    bledy = 0,
    bezListy = false,
    naglowki = false,
} = {}) {
    const zrodlo = await readFile(MODUL, 'utf8');
    const zadania = [];
    let pozostaleBledy = bledy;

    await context.route('http://kuking.test/**', async (route) => {
        const url = new URL(route.request().url());

        if (url.pathname === '/pokaz-wiecej.js') {
            return route.fulfill({contentType: 'text/javascript', body: zrodlo});
        }

        if (url.pathname.startsWith('/wpis/')) {
            return route.fulfill({contentType: 'text/html', body: strona(`<p>Karta ${url.pathname}</p>`)});
        }

        if (url.pathname === '/dwie') {
            // Dwie niezależne listy stronicowane numerami (`komentarze`, `wykonania`).
            const k = Number(url.searchParams.get('komentarze') ?? 1);
            const w = Number(url.searchParams.get('wykonania') ?? 1);
            zadania.push(url.search);
            const nast = (nazwa, n) => {
                const u = new URL(url);
                u.searchParams.set(nazwa, String(n + 1));

                return n < PORCJI ? u.pathname + u.search : null;
            };

            return route.fulfill({contentType: 'text/html', body: strona(`
                <section><div id="lista-komentarzy">${elementy('komentarz', (k - 1) * NA_PORCJE + 1, k * NA_PORCJE)}</div>
                ${blokWiecej({klucz: 'komentarze', czego: 'komentarzy', lista: 'lista-komentarzy', nastepny: nast('komentarze', k)})}</section>
                <section><div id="lista-wykonan">${elementy('wykonanie', (w - 1) * NA_PORCJE + 1, w * NA_PORCJE)}</div>
                ${blokWiecej({klucz: 'wykonania', czego: 'wykonań', lista: 'lista-wykonan', nastepny: nast('wykonania', w)})}</section>`)});
        }

        // Jedna lista z kursorem: `cursor=N` znaczy „po N-tym elemencie”.
        const po = Number(url.searchParams.get('cursor') ?? 0);
        if (po > 0) zadania.push(url.search);

        if (po > 0 && pozostaleBledy > 0) {
            pozostaleBledy--;

            return route.fulfill({status: 500, contentType: 'text/html', body: strona('<p>Błąd</p>')});
        }

        if (po > 0 && bezListy) {
            return route.fulfill({contentType: 'text/html', body: strona('<h1>Zaloguj się</h1>')});
        }

        // `przesuniecie` udaje OFFSET, który między żądaniami przesunął się
        // o jeden element: kolejna porcja zaczyna od ostatniego z poprzedniej.
        const od = po === 0 ? 1 : po + 1 - przesuniecie;
        const doIlu = Math.min(od + NA_PORCJE - 1, PORCJI * NA_PORCJE);
        const nastepny = doIlu < PORCJI * NA_PORCJE ? `/lista?cursor=${doIlu}` : null;

        return route.fulfill({contentType: 'text/html', body: strona(`
            <div class="stack" id="lista-wpisow">${naglowki ? '<h2 data-klucz="miesiac-wrzesien">Wrzesień 2026</h2>' : ''}${elementy('wpis', od, doIlu)}${naglowki && po > 0 ? '<p class="notice">Pusty stan bez tożsamości</p>' : ''}</div>
            ${blokWiecej({klucz: 'cursor', czego: 'wpisów', lista, nastepny})}`)});
    });

    return zadania;
}

async function klucze(page, lista = 'lista-wpisow') {
    return page.$$eval(`#${lista} > [data-klucz]`, (el) => el.map((e) => e.dataset.klucz));
}

function oczekiwane(prefiks, ile) {
    return Array.from({length: ile}, (_, i) => `${prefiks}-${i + 1}`);
}

async function zPrzegladarka(fn) {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined});
    try {
        const context = await browser.newContext({viewport: {width: 390, height: 740}});
        await fn(context);
    } finally {
        await browser.close();
    }
}

test('dwa kliknięcia zostawiają porcje 1, 2 i 3 w kolejności, bez skoku na górę', async () => {
    await zPrzegladarka(async (context) => {
        const zadania = await przygotuj(context);
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');

        const przycisk = page.getByRole('button', {name: 'Pokaż więcej wpisów'});
        await przycisk.waitFor();
        assert.equal(await page.getByRole('link', {name: /Następna strona/}).count(), 0, 'Odnośnik ma być zastąpiony przyciskiem.');

        await przycisk.scrollIntoViewIfNeeded();
        const yPrzed = await page.evaluate(() => scrollY);
        assert.ok(yPrzed > 500, 'Test musi zaczynać przewinięty w dół.');

        await przycisk.click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 10);

        assert.deepEqual(await klucze(page), oczekiwane('wpis', 10));
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), 'Załadowano więcej wpisów: 5.');
        assert.equal(await page.evaluate(() => document.activeElement?.dataset.klucz), 'wpis-6', 'Fokus na pierwszym doklejonym elemencie.');
        assert.ok(await page.evaluate(() => scrollY) >= yPrzed - 5, 'Strona nie może skoczyć na początek.');
        assert.equal(new URL(page.url()).searchParams.get('porcje_cursor'), '1');

        // Klawiatura: Tab z doklejonej karty idzie do jej odnośnika, nie za listę.
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.textContent), 'wpis 6');

        await przycisk.click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 15);

        assert.deepEqual(await klucze(page), oczekiwane('wpis', 15));
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), 'Załadowano więcej wpisów: 5. To już koniec listy.');
        assert.equal(await przycisk.count(), 0, 'Po ostatniej porcji przycisku ma nie być.');
        assert.equal(new URL(page.url()).searchParams.get('porcje_cursor'), '2');
        assert.deepEqual(zadania, ['?cursor=5', '?cursor=10'], 'Bez pustego dodatkowego żądania po ostatniej porcji.');
    });
});

test('przesunięty OFFSET nie dokleja tej samej karty drugi raz', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context, {przesuniecie: 1});
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        await page.getByRole('button', {name: 'Pokaż więcej wpisów'}).click();
        await page.waitForFunction(() => document.querySelector('[data-pokaz-wiecej-ogloszenie]')?.textContent);

        assert.deepEqual(await klucze(page), oczekiwane('wpis', 9));
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), 'Załadowano więcej wpisów: 4.', 'Liczba mówi o NOWYCH elementach.');
    });
});

test('nagłówek tego samego miesiąca nie wraca, element bez tożsamości nie jest doklejany', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context, {naglowki: true});
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        await page.getByRole('button', {name: 'Pokaż więcej wpisów'}).click();
        await page.waitForFunction(() => document.querySelector('[data-pokaz-wiecej-ogloszenie]')?.textContent);

        assert.deepEqual(await klucze(page), ['miesiac-wrzesien', ...oczekiwane('wpis', 10)]);
        assert.equal(await page.locator('#lista-wpisow > .notice').count(), 0);
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), 'Załadowano więcej wpisów: 5.', 'Liczymy karty, nie nagłówki.');
    });
});

test('dwie niezależne listy na jednym ekranie pamiętają własne porcje', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context);
        const page = await context.newPage();
        await page.goto('http://kuking.test/dwie');

        await page.getByRole('button', {name: 'Pokaż więcej komentarzy'}).click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-komentarzy > *').length === 10);
        await page.getByRole('button', {name: 'Pokaż więcej wykonań'}).click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wykonan > *').length === 10);
        await page.getByRole('button', {name: 'Pokaż więcej komentarzy'}).click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-komentarzy > *').length === 15);

        assert.deepEqual(await klucze(page, 'lista-komentarzy'), oczekiwane('komentarz', 15));
        assert.deepEqual(await klucze(page, 'lista-wykonan'), oczekiwane('wykonanie', 10));
        const parametry = new URL(page.url()).searchParams;
        assert.equal(parametry.get('porcje_komentarze'), '2');
        assert.equal(parametry.get('porcje_wykonania'), '1');

        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('#lista-komentarzy > *').length === 15
            && document.querySelectorAll('#lista-wykonan > *').length === 10);
        assert.deepEqual(await klucze(page, 'lista-komentarzy'), oczekiwane('komentarz', 15));
        assert.deepEqual(await klucze(page, 'lista-wykonan'), oczekiwane('wykonanie', 10));
    });
});

test('odświeżenie i powrót z karty odtwarzają cały zakres i pozycję', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context);
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        const przycisk = page.getByRole('button', {name: 'Pokaż więcej wpisów'});
        await przycisk.click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 10);
        await przycisk.click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 15);

        await page.locator('[data-klucz="wpis-12"]').scrollIntoViewIfNeeded();
        await page.waitForTimeout(400); // zapis pozycji po przewinięciu
        const y = await page.evaluate(() => scrollY);

        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 15);
        await page.waitForFunction((cel) => Math.abs(scrollY - cel) < 50, y);
        assert.deepEqual(await klucze(page), oczekiwane('wpis', 15));
        assert.equal(await page.getByRole('button', {name: 'Pokaż więcej wpisów'}).count(), 0, 'Koniec listy — bez przycisku.');
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), '', 'Odtwarzanie niczego nie ogłasza — człowiek nic nie kliknął.');

        await page.getByRole('link', {name: 'wpis 12'}).click();
        await page.waitForURL('**/wpis/wpis-12');
        await page.goBack();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 15);
        await page.waitForFunction((cel) => Math.abs(scrollY - cel) < 300, y);
        assert.deepEqual(await klucze(page), oczekiwane('wpis', 15));
        assert.ok(await page.locator('[data-klucz="wpis-12"]').isVisible(), 'Otwarta karta jest znów na ekranie.');
    });
});

test('błąd pobrania zostawia listę, mówi co zrobić i pozwala ponowić', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context, {bledy: 1});
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        const przycisk = page.getByRole('button', {name: 'Pokaż więcej wpisów'});
        await przycisk.click();

        const blad = page.getByRole('alert');
        await blad.waitFor();
        assert.match(await blad.textContent(), /Nie udało się wczytać kolejnych wpisów\. Lista wyżej zostaje bez zmian\. Sprawdź połączenie z internetem i naciśnij „Pokaż więcej wpisów” jeszcze raz\./);
        assert.deepEqual(await klucze(page), oczekiwane('wpis', 5));
        assert.equal(await page.locator('[data-pokaz-wiecej-ogloszenie]').textContent(), '', 'Region wyniku milczy przy błędzie.');
        assert.equal(new URL(page.url()).searchParams.get('porcje_cursor'), null);
        assert.equal(await page.evaluate(() => document.activeElement?.textContent), 'Pokaż więcej wpisów', 'Fokus zostaje na przycisku do ponowienia.');

        await przycisk.click();
        await page.waitForFunction(() => document.querySelectorAll('#lista-wpisow > *').length === 10);
        assert.equal(await blad.isHidden(), true);
        assert.deepEqual(await klucze(page), oczekiwane('wpis', 10));
    });
});

test('strona bez listy w odpowiedzi (np. logowanie) to błąd, nie „koniec listy”', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context, {bezListy: true});
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        await page.getByRole('button', {name: 'Pokaż więcej wpisów'}).click();
        await page.getByRole('alert').waitFor();
        assert.equal(await page.getByRole('button', {name: 'Pokaż więcej wpisów'}).count(), 1);
        assert.deepEqual(await klucze(page), oczekiwane('wpis', 5));
    });
});

test('bez wskazanej listy albo bez skryptu zostaje uczciwy odnośnik „Następna strona”', async () => {
    await zPrzegladarka(async (context) => {
        await przygotuj(context, {lista: null});
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        await page.waitForLoadState('load');
        assert.equal(await page.getByRole('button').count(), 0, 'Żadnego przycisku, który nie wie, gdzie dokleić porcję.');
        await page.getByRole('link', {name: 'Następna strona wpisów'}).click();
        await page.waitForURL('**/lista?cursor=5');
        assert.deepEqual(await klucze(page), ['wpis-6', 'wpis-7', 'wpis-8', 'wpis-9', 'wpis-10']);
    });

    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined});
    try {
        const context = await browser.newContext({javaScriptEnabled: false});
        await przygotuj(context);
        const page = await context.newPage();
        await page.goto('http://kuking.test/lista');
        await page.getByRole('link', {name: 'Następna strona wpisów'}).click();
        await page.waitForURL('**/lista?cursor=5');
        assert.deepEqual(await klucze(page), ['wpis-6', 'wpis-7', 'wpis-8', 'wpis-9', 'wpis-10']);
    } finally {
        await browser.close();
    }
});
