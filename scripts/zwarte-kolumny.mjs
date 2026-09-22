// Rzeczywiste karty Laravel: odstępy, reakcja na wysokość, DOM i klawiatura.
import assert from 'node:assert/strict';

export async function measureColumns(page, { theme = 'light', scale = 100 } = {}) {
    await page.evaluate(({ theme, scale }) => {
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.textScale = String(scale);
    }, { theme, scale });
    const grid = page.locator('.landing-wpisy-dwie');
    assert(await grid.count(), 'BRAK_LISTY');
    for (const img of await grid.locator('img').all()) {
        await img.scrollIntoViewIfNeeded();
        await page.waitForFunction(i => i.complete && i.naturalWidth > 0, await img.elementHandle());
    }
    const settle = () => page.evaluate(async () => {
        await document.fonts.ready;
        for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
    });
    await settle();
    const inspect = async () => {
        const g = await grid.evaluate(el => ({
            columns: getComputedStyle(el).gridTemplateColumns.split(' ').length,
            expectedColumns: matchMedia('(min-width: 64rem)').matches ? 2 : 1,
            active: el.hasAttribute('data-zwarte-kolumny'),
            width: innerWidth, scroll: document.documentElement.scrollWidth,
            cards: [...el.children].map(c => {
                const r = c.getBoundingClientRect();
                return { x: r.x, y: r.y, bottom: r.bottom, width: r.width,
                    gap: parseFloat(getComputedStyle(c).marginBottom), text: c.textContent,
                    links: [...c.querySelectorAll('a')].map(a => a.href) };
            }),
        }));
        assert(g.cards.length >= 3, 'ZA_MALO_KART');
        assert.equal(g.columns, g.expectedColumns, 'KOLUMNY_PO_ZMIANIE_OKNA');
        assert(g.cards.every(c => c.width >= Math.min(240, g.width - 56)), 'KARTA_ZWEZONA');
        assert(g.scroll <= g.width + 1, 'PRZEPELNIENIE');
        assert.equal(g.active, g.columns === 2, 'TRYB_KOLUMN');
        for (let i = g.columns; i < g.cards.length; i++) {
            const previous = g.cards[i - g.columns], card = g.cards[i];
            assert(Math.abs(card.x - previous.x) < 1, 'KOLEJNOSC_KOLUMN');
            const gap = card.y - previous.bottom;
            if (g.columns === 2) assert(Math.abs(gap - previous.gap) < 1.1, 'PUSTA_PRZERWA ' + gap);
            else assert(gap >= 0 && gap < 60, 'PRZERWA_MOBILE ' + gap);
        }
        return g;
    };
    const before = await inspect();
    // Kontrolowana zmiana treści w DOM mierzy aktualizację rozmiaru, bez zapisu danych.
    await grid.evaluate(el => {
        const probe = document.createElement('p');
        probe.dataset.probaWysokosci = '';
        probe.textContent = 'Dłuższa lokalna treść do sprawdzenia układu. '.repeat(35);
        el.children[0].append(probe);
    });
    await settle(); await inspect();
    await grid.locator('[data-proba-wysokosci]').evaluate(el => el.remove());
    await settle();
    const after = await inspect();
    assert.deepEqual(after.cards.map(c => c.links), before.cards.map(c => c.links), 'ZMIENIONA_KOLEJNOSC');
    const controls = grid.locator('a[href]:not([tabindex="-1"]):visible, button:not([disabled]):not([tabindex="-1"]):visible, summary:not([tabindex="-1"]):visible');
    const count = await controls.count(); assert(count > 0, 'BRAK_KONTROLEK');
    await controls.first().focus(); await page.keyboard.press('Shift+Tab');
    for (let i = 0; i < count; i++) {
        await page.keyboard.press('Tab');
        assert(await controls.nth(i).evaluate(el => el === document.activeElement), 'KOLEJNOSC_TAB ' + i);
        assert(await controls.nth(i).evaluate(el => {
            // Link daty może mieć dwa wiersze. Środek sumy prostokątów
            // trafia wtedy w pusty odstęp; sprawdzamy każdy rzeczywisty wiersz.
            const rects = [...el.getClientRects()].filter(r => r.width > 0 && r.height > 0);
            return el.matches(':focus-visible') && rects.length > 0 && rects.every(r => {
                const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                return r.y >= 3 && r.bottom <= innerHeight - 3 && (hit === el || el.contains(hit));
            });
        }), 'FOKUS_ZASLONIETY ' + i);
    }
    await grid.evaluate(el => scrollTo(0, scrollY + el.getBoundingClientRect().top - document.querySelector('header').getBoundingClientRect().height - 24));
    return { theme, scale, ...after, tab: count };
}

export async function sprawdzZwarteKolumny({ browser, adres }) {
    for (const width of [320, 360, 390, 414, 768, 1440]) {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        try {
            const page = await context.newPage();
            for (const theme of ['light', 'dark']) for (const scale of [100, 140]) {
                await page.goto(adres + '/');
                await measureColumns(page, { theme, scale });
            }
            // Ta sama strona: jawne pozycje kart nie mogą utrzymać kolumny
            // niejawnej po zwężeniu okna poniżej progu CSS.
            if (width === 1440) for (const resized of [390, 1440]) {
                await page.setViewportSize({ width: resized, height: 900 });
                await measureColumns(page);
            }
        } finally { await context.close(); }
    }
    console.log('Zwarte kolumny: 24 konfiguracje PASS');
}
