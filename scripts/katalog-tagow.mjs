import assert from 'node:assert/strict';

// Pomiar rzeczywistego katalogu. Dane tworzy istniejący fixture tagów.
export async function sprawdzKatalogTagow({ browser, adres, expectedTag = null }) {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    assert.equal((await page.goto(adres + '/tagi')).status(), 200);
    assert(await page.locator('.tag-directory-card').count() > 0, 'K681_BRAK_DANYCH');
    const photos = page.locator('.tag-directory-card--photo');
    const photo = expectedTag ? photos.filter({ has: page.getByText(expectedTag, { exact: true }) }) : photos.first();
    assert(await photo.count() > 0, 'K681_BRAK_FOTOGRAFII');
    assert((await photo.locator('.tag-directory-credit').innerText()).startsWith('Zdjęcie: '), 'K681_AUTOR');
    if (expectedTag) assert.equal(await photo.locator('.tag-directory-credit').innerText(), 'Zdjęcie: Aleksandra Katarzyna z kuchni', 'K681_DLUGI_PODPIS');
    await photo.scrollIntoViewIfNeeded();
    await photo.locator('img').evaluate(img => img.decode());
    let count = 0;
    for (const width of [320, 360, 390, 414, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      for (const theme of ['light', 'dark']) for (const scale of [70, 100, 140]) {
        await page.evaluate(({ theme, scale }) => {
          document.documentElement.dataset.theme = theme;
          document.documentElement.dataset.textScale = String(scale);
        }, { theme, scale });
        await page.evaluate(() => document.fonts.ready);
        const result = await page.evaluate(() => {
          const cards = [...document.querySelectorAll('.tag-directory-card')];
          return {
            overflow: document.documentElement.scrollWidth > innerWidth + 1,
            mainWidth: document.querySelector('main').getBoundingClientRect().width,
            cards: cards.map(el => {
              const box = el.getBoundingClientRect();
              const copy = el.querySelector('.tag-directory-copy').getBoundingClientRect();
              return { width: box.width, height: box.height,
                fits: copy.left >= box.left - 1 && copy.right <= box.right + 1 && copy.bottom <= box.bottom + 1,
                overflow: el.scrollWidth > el.clientWidth + 1 };
            }),
          };
        });
        assert(!result.overflow, 'K681_OVERFLOW');
        assert(result.cards.every(c => c.fits && !c.overflow), 'K681_TEKST');
        // Kafel ma powierzchnię fotografii, nie rozmiar dawnej etykiety.
        assert(result.cards.every(c => c.width >= 180 && c.height >= c.width - 2), 'K681_KAFEL');
        if (width === 1440 && scale === 100) assert(result.mainWidth >= 1000, 'K681_SZEROKOSC');
        count++;
      }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.evaluate(() => { document.documentElement.dataset.textScale = '100'; });
    const featured = page.locator('.tag-featured-card');
    if (await featured.count()) {
      // Kontrolowany stan jednej rekomendacji; bez modyfikowania bazy.
      await featured.evaluateAll(cards => cards.slice(1).forEach(c => { c.style.display = 'none'; }));
      assert((await featured.first().boundingBox()).width <= 500, 'K681_JEDNA_POLECANA');
    }
    console.log(`K681_OK ${count} konfiguracji katalogu`);
  } finally { await context.close(); }
}
