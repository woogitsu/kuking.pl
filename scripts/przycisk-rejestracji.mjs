import assert from 'node:assert/strict';

// Pełny landing Laravel; font przeglądarki jest osobnym wariantem od zoomu.
export async function sprawdzPrzyciskRejestracji({ browser, adres }) {
  const context = await browser.newContext({ viewport: { width: 320, height: 900 }, hasTouch: true });
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  let liczba = 0;
  try {
    for (const font of [16, 32]) {
      await cdp.send('Page.setFontSizes', { fontSizes: { standard: font, fixed: font } });
      for (const width of [320, 360, 390, 414, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const theme of ['light', 'dark']) for (const scale of [100, 140]) {
          const opis = `${width}/${theme}/${scale}/font${font}`;
          await page.goto(adres + '/');
          await page.evaluate(({ theme, scale }) => {
            document.documentElement.dataset.theme = theme;
            document.documentElement.dataset.textScale = String(scale);
          }, { theme, scale });
          await page.evaluate(async () => {
            await document.fonts.ready;
            for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
          });
          const button = page.locator('.hero-akcje > .btn-duzy');
          assert.equal(await button.count(), 1, 'BRAK_CTA ' + opis);
          let reached = false;
          for (let i = 0; i < 20; i++) {
            await page.keyboard.press('Tab');
            if (await button.evaluate(a => a === document.activeElement)) { reached = true; break; }
          }
          assert(reached, 'TAB_NIE_DOCIERA_DO_CTA ' + opis);
          await page.waitForTimeout(300);
          const stan = await button.evaluate(a => {
            const r = a.getBoundingClientRect();
            const label = a.cloneNode(true);
            label.querySelectorAll('[aria-hidden="true"]').forEach(el => el.remove());
            const css = getComputedStyle(a);
            const points = [[(r.left + r.right) / 2, r.top + 6], [(r.left + r.right) / 2, r.bottom - 6], [(r.left + r.right) / 2, (r.top + r.bottom) / 2]];
            return {
              top: r.top, bottom: r.bottom, left: r.left, right: r.right,
              height: r.height, viewport: innerHeight, viewportWidth: innerWidth,
              label: label.textContent.replace(/\s+/g, ' ').trim(),
              ring: css.boxShadow !== 'none' || (css.outlineStyle !== 'none' && parseFloat(css.outlineWidth) > 0),
              rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize),
              font: parseFloat(getComputedStyle(a).fontSize),
              focus: a.matches(':focus-visible'), overflow: document.documentElement.scrollWidth > innerWidth + 1,
              covered: points.some(([x, y]) => !a.contains(document.elementFromPoint(x, y))),
              href: new URL(a.href).pathname,
            };
          });
          assert.equal(stan.rootFont, font, 'NIEZASTOSOWANY_FONT ' + opis);
          assert.equal(stan.label, 'Zostań kukingiem — bez opłat i bez reklam', 'ZMIENIONA_ETYKIETA_CTA ' + opis);
          assert(Math.abs(stan.font - 20 * font / 16 * scale / 100) < 0.1, 'ZMIENIONY_ROZMIAR_TEKSTU ' + opis);
          assert(stan.height >= 48 && stan.top >= 6 && stan.bottom <= stan.viewport - 6,
            'CTA_POZA_EKRANEM ' + opis + ' ' + JSON.stringify(stan));
          assert(stan.left >= 6 && stan.right <= stan.viewportWidth - 6, 'CTA_FOKUS_POZIOMY ' + opis);
          assert(stan.focus && stan.ring && !stan.covered && !stan.overflow, 'CTA_ZASLONIETE ' + opis);
          assert.equal(stan.href, '/register', 'BLEDNY_CEL_CTA ' + opis);
          if (scale === 140 && width === 320) {
            if (theme === 'light') await button.click();
            else await button.tap();
            await page.waitForURL(url => url.pathname === '/register');
          }
          liczba++;
        }
      }
    }
    assert.equal(liczba, 48, 'NIEPELNA_MACIERZ_CTA');
    console.log(`Przycisk rejestracji: ${liczba}/48, Tab, kliknięcie i dotyk PASS.`);
  } finally {
    await context.close();
  }
}
