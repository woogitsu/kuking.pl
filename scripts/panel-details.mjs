/* #581 - regresja integracyjna, przeniesiona z details-probe581.mjs.
 * 24 konfiguracje CSS; prawdziwy zoom 200% pozostaje osobnym krokiem odbioru.
 * Runner dostarcza przegladarke, prywatna sesje oraz pelna lokalna fixture.
 * Ten modul nie uruchamia PHP, nie tworzy danych i nie zamyka browser.
 * Izolacje bazy i mailer array musi wczesniej potwierdzic runner.
 */
import { mkdirSync, readdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';

export async function screenshot(page, path) {
  // Capture the actual compositor viewport without Playwright temporarily
  // overriding device metrics, which changes capture coordinates at native zoom.
  const client = await page.context().newCDPSession(page);
  try {
    const { data } = await client.send('Page.captureScreenshot', {
      format: 'png', fromSurface: true, captureBeyondViewport: false,
    });
    writeFileSync(path, Buffer.from(data, 'base64'));
  } finally { await client.detach(); }
}

async function nextTo(page, selector, key = 'Tab') {
  for (let i = 0; i < 500; i++) {
    await page.keyboard.press(key);
    if (await page.evaluate(sel => document.activeElement.matches(sel), selector)) return i + 1;
  }
  throw Error('DETAILS_TAB_UNREACHABLE ' + selector);
}

// Nie zapisujemy tekstu, adresu e-mail ani atrybutow href do dowodow.
async function fullText(locator) {
  const measured = await locator.evaluate(el => {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const fragments = [];
    let node;
    while ((node = walker.nextNode())) {
      const text = node.textContent;
      if (!text.trim()) continue;
      // Hit-test painted graphemes, not the whitespace/advance at an inline line end.
      // Keep full rectangle clipping checks for every non-whitespace grapheme.
      const rects = [];
      for (const { segment, index } of new Intl.Segmenter('pl', { granularity: 'grapheme' }).segment(text)) {
        if (!segment.trim()) continue;
        const range = document.createRange();
        range.setStart(node, index); range.setEnd(node, index + segment.length);
        rects.push(...[...range.getClientRects()].filter(r => r.width > 0 && r.height > 0));
      }
      let painted = true;
      for (let ancestor = node.parentElement; ancestor; ancestor = ancestor.parentElement) {
        const css = getComputedStyle(ancestor);
        if (css.display === 'none' || css.visibility !== 'visible' || Number(css.opacity) === 0
          || css.color === 'transparent' || /rgba\([^)]*,\s*0\)$/.test(css.color)) painted = false;
      }
      if (!rects.length) fragments.push({ readable: false });
      for (const rect of rects) {
        let readable = painted && rect.left >= 0 && rect.right <= innerWidth
          && rect.top >= 0 && rect.bottom <= innerHeight;
        for (let ancestor = node.parentElement; ancestor; ancestor = ancestor.parentElement) {
          const css = getComputedStyle(ancestor), box = ancestor.getBoundingClientRect();
          if (['hidden', 'clip', 'scroll', 'auto'].includes(css.overflowX))
            readable &&= rect.left >= box.left + ancestor.clientLeft && rect.right <= box.left + ancestor.clientLeft + ancestor.clientWidth;
          if (['hidden', 'clip', 'scroll', 'auto'].includes(css.overflowY))
            readable &&= rect.top >= box.top + ancestor.clientTop && rect.bottom <= box.top + ancestor.clientTop + ancestor.clientHeight;
        }
        const mx = (rect.left + rect.right) / 2, my = (rect.top + rect.bottom) / 2;
        for (const [x, y] of [[mx, my]]) {
          const hit = document.elementFromPoint(x, y);
          readable &&= hit === el || el.contains(hit);
        }
        fragments.push({ rect: rect.toJSON(), readable, painted,
          ancestors: [...(function* () { for (let a = node.parentElement; a; a = a.parentElement) yield a; })()].map(a => {
            const c = getComputedStyle(a), b = a.getBoundingClientRect();
            return { tag: a.tagName, overflowX: c.overflowX, overflowY: c.overflowY, rect: b.toJSON(), clientWidth: a.clientWidth, clientHeight: a.clientHeight };
          }),
          hits: [mx].map(x => {
            const h = document.elementFromPoint(x, my); return { tag: h?.tagName, inside: h === el || el.contains(h) };
          }) });
      }
    }
    return { fragments, elementRects: [...el.getClientRects()].map(r => r.toJSON()) };
  });
  if (!measured.fragments.length || !measured.fragments.every(f => f.readable)) {
    const error = new Error('DETAILS_FULL_TEXT_CLIPPED');
    error.geometry = measured;
    throw error;
  }
  return measured;
}

async function focus(page) {
  let result;
  let previousGeometry, stableFrames = 0;
  for (let frame = 0; frame <= 30; frame++) {
    result = await page.evaluate(() => {
      const el = document.activeElement, css = getComputedStyle(el);
      const probe = document.createElement('i');
      probe.style.cssText = 'position:fixed!important;width:0!important;height:0!important;transition:none!important;animation:none!important;pointer-events:none!important';
      const token = css.getPropertyValue('--color-focus').trim();
      probe.style.setProperty('color', token, 'important'); document.body.append(probe);
      const color = token ? getComputedStyle(probe).color : ''; probe.remove();
      const layers = css.boxShadow.split(/,(?![^()]*\))/).map(s => ({ color: s.match(/rgba?\([^)]*\)/)?.[0], inset: s.includes('inset'), px: [...s.matchAll(/(-?[\d.]+)px/g)].map(m => Number(m[1])) }));
      const ring = layers.some((l, i) => l.color === color && /^rgb\(/.test(color) && !l.inset && l.px.length === 4 && l.px.slice(0, 3).every(n => n === 0) && l.px[3] - Math.max(0, ...layers.slice(0, i).filter(a => !a.inset).map(a => (a.px[3] || 0) + (a.px[2] || 0) + Math.max(Math.abs(a.px[0] || 0), Math.abs(a.px[1] || 0)))) >= 2);
      const outline = css.outlineStyle === 'solid' && parseFloat(css.outlineWidth) >= 2 && css.outlineColor === color && /^rgb\(/.test(color);
      const fragments = [...el.getClientRects()].map(r => {
        const mx = (r.left + r.right) / 2, my = (r.top + r.bottom) / 2;
        const points = [[mx,r.top+2],[mx,r.bottom-2],[r.left+2,my],[r.right-2,my],[mx,my]];
        return { rect: r.toJSON(), points: points.map(([x,y]) => { const h = document.elementFromPoint(x,y); return { x,y,ok:x>=0&&x<innerWidth&&y>=0&&y<innerHeight&&(h===el||el.contains(h)) }; }) };
      });
      const box = el.getBoundingClientRect();
      const target = el.matches('button,summary');
      const extent = outline ? Math.max(0, parseFloat(css.outlineOffset) + parseFloat(css.outlineWidth))
        : Math.max(0, ...layers.filter(l => !l.inset && l.color === color).map(l => (l.px[3] || 0) + (l.px[2] || 0)));
      const indicator = { left: box.left - extent, right: box.right + extent, top: box.top - extent, bottom: box.bottom + extent };
      let indicatorVisible = indicator.left >= 0 && indicator.right <= innerWidth && indicator.top >= 0 && indicator.bottom <= innerHeight;
      for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const style = getComputedStyle(parent), clip = parent.getBoundingClientRect();
        if (['hidden', 'clip', 'scroll', 'auto'].includes(style.overflowX))
          indicatorVisible &&= indicator.left >= clip.left + parent.clientLeft && indicator.right <= clip.left + parent.clientLeft + parent.clientWidth;
        if (['hidden', 'clip', 'scroll', 'auto'].includes(style.overflowY))
          indicatorVisible &&= indicator.top >= clip.top + parent.clientTop && indicator.bottom <= clip.top + parent.clientTop + parent.clientHeight;
      }
      return { visible: el.matches(':focus-visible'), outline, ring, color, outlineColor: css.outlineColor, shadow: css.boxShadow, fragments, target, width: box.width, height: box.height, indicator, indicatorVisible };
    });
    result.frame = frame;
    const geometry = JSON.stringify(result.fragments.map(f => f.rect));
    stableFrames = geometry === previousGeometry ? stableFrames + 1 : 0;
    previousGeometry = geometry;
    if (result.visible && (result.outline || result.ring) && stableFrames >= 3) break;
    await page.evaluate(() => new Promise(requestAnimationFrame));
  }
  assert(result.visible && (result.outline || result.ring), 'DETAILS_FOCUS ' + JSON.stringify(result));
  assert(result.fragments.length && result.fragments.every(f => f.points.every(p => p.ok)), 'DETAILS_OCCLUDED ' + JSON.stringify(result));
  if (result.target) assert(result.width >= 48 && result.height >= 48, 'DETAILS_TARGET_48');
  if (!result.indicatorVisible) { const error = new Error('DETAILS_FOCUS_CLIPPED'); error.geometry = result; throw error; }
  result.fullText = await fullText(page.locator(':focus'));
  return result;
}

export function przypadkiDetails(message) {
  return [
    { question: "Wyczyścić wybór? Kolaż dobierze zdjęcia sam.", id: 'kolaz', path: '/admin/kolaz-powitalny', summary: 'Wyczyść wybór zdjęć' },
    { question: "Wyczyścić tablicę na dziś? Wróci do trybu automatycznego.", id: 'tablica', path: '/admin/kuking-na-dzis', summary: 'Wyczyść dzisiejszy wybór' },
    { id: 'wiadomosc', path: message.path, summary: 'Poczta nie działa albo trzeba wysłać załącznik' },
  ];
}

// Wspolny scenariusz: bez uruchamiania browser i bez zamykania kontekstu.
export async function sprawdzScenariuszDetails({ page, context, origin, c, width, theme, scale, outputDir, name, row, requests, dpr = 1, przygotuj }) {
      await context.route('**/*', r => {
        if (!['GET', 'HEAD'].includes(r.request().method())) { requests.push({ method: r.request().method() }); return r.abort(); }
        if (new URL(r.request().url()).origin !== origin) { requests.push({ external: true }); return r.abort(); }
        return r.continue();
      });
      await page.addInitScript(({ theme, scale }) => document.addEventListener('DOMContentLoaded', () => { document.documentElement.dataset.theme = theme; document.documentElement.dataset.textScale = String(scale); }), { theme, scale });
      const response = await page.goto(new URL(c.path, origin).href, { waitUntil: 'networkidle' }); assert.equal(response.status(), 200);
      await page.evaluate(() => document.fonts.ready);
      if (przygotuj) await przygotuj(page);
      row.geometry = await page.evaluate(() => ({ width: innerWidth, dpr: devicePixelRatio, height: innerHeight, font: parseFloat(getComputedStyle(document.body).fontSize), scroll: document.documentElement.scrollWidth, color: getComputedStyle(document.body).color }));
      assert.equal(row.geometry.width, width); assert.equal(row.geometry.dpr, dpr); assert.equal(row.geometry.height, 900); assert(Math.abs(row.geometry.font - 18 * scale / 100) < .1); assert(row.geometry.scroll <= width + 1);
      assert.equal(row.geometry.color, theme === 'dark' ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)');
      const summary = page.locator('main summary').filter({ hasText: c.summary }); assert.equal(await summary.count(), 1);
      assert(await summary.evaluate((e, expected) => e.textContent.replace(/\s+/g, ' ').trim() === expected, c.summary), 'DETAILS_SUMMARY_TEXT');
      await summary.evaluate(e => { e.dataset.detailsProbe = 'summary'; e.parentElement.dataset.detailsProbe = 'container'; });
      row.tabsToSummary = await nextTo(page, 'summary[data-details-probe="summary"]'); row.summaryFocus = await focus(page);
      await page.keyboard.press('Enter');
      assert(await page.locator('details[data-details-probe="container"]').evaluate(e => e.open), 'DETAILS_NOT_OPEN');
      const container = page.locator('details[data-details-probe="container"]');
      if (c.id !== 'wiadomosc') {
        const question = container.locator('.confirm-body > .confirm-question');
        assert.equal(await question.count(), 1, 'DETAILS_QUESTION_COUNT');
        assert(await question.evaluate((e, expected) => e.textContent.replace(/\s+/g, ' ').trim() === expected, c.question), 'DETAILS_QUESTION_TEXT');
        const confirm = container.locator('.confirm-body > form > button[type="submit"]');
        assert.equal(await confirm.count(), 1, 'DETAILS_CONFIRM_COUNT');
        assert(await confirm.evaluate((e, expected) => !e.disabled && e.textContent.replace(/\s+/g, ' ').trim() === 'Tak, ' + expected.toLocaleLowerCase('pl'), c.summary), 'DETAILS_CONFIRM_TEXT');
        await question.scrollIntoViewIfNeeded();
        row.questionText = await fullText(question);
      } else {
        const mail = container.locator('a[href^="mailto:"]');
        assert.equal(await mail.count(), 1, 'DETAILS_MAILTO_COUNT');
        assert(await mail.evaluate(e => {
          const text = e.textContent.trim();
          return /^[^\s@]+@[^\s@]+$/.test(text) && e.getAttribute('href') === 'mailto:' + text;
        }), 'DETAILS_MAILTO_TEXT');
        const paragraph = container.locator(':scope > p');
        assert.equal(await paragraph.count(), 1, 'DETAILS_HELP_COUNT');
        assert(await paragraph.evaluate(e => {
          const mail = e.querySelector('a[href^="mailto:"]');
          return mail && e.textContent.replace(/\s+/g, ' ').trim() ===
            'Wtedy odpisz ze swojego programu poczty na ' + mail.textContent.trim() +
            ' i zapisz w notatce niżej treść odpowiedzi — bo tej drogi serwis nie widzi i nie pokaże jej w historii wyżej.';
        }), 'DETAILS_HELP_TEXT');
        await paragraph.scrollIntoViewIfNeeded();
        row.helpText = await fullText(paragraph);
      }
      const count = await page.locator('details[data-details-probe="container"]').evaluate(e => {
        const controls = [...e.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')].filter(n => n.tabIndex >= 0 && !n.disabled && n.checkVisibility());
        controls.forEach((n,i) => n.dataset.detailsNew = String(i)); return controls.length;
      });
      assert(count > 0, 'DETAILS_NO_NEW_CONTROLS'); assert.equal(count, 1, 'DETAILS_EXPECTED_CONTROL_COUNT'); row.controls = [];
      for (let i = 0; i < count; i++) {
        await nextTo(page, `[data-details-new="${i}"]`); row.controls.push(await focus(page));
      }
      await screenshot(page, resolve(outputDir, name + '-open.png'));
      await nextTo(page, 'summary[data-details-probe="summary"]', 'Shift+Tab'); await focus(page);
      await page.keyboard.press('Enter'); assert(!(await page.locator('details[data-details-probe="container"]').evaluate(e => e.open)), 'DETAILS_NOT_CLOSED');
      if (c.id === 'wiadomosc') {
        // `textarea`, nie samo `[name=handler_note]`: od #845 formularz odpowiedzi niesie
        // ukrytą, wyłączoną kopię notatki (`data-kopia-z`), więc sama nazwa pola pasuje do dwóch formularzy.
        const last = page.locator('main form').filter({ has: page.locator('textarea[name=handler_note]') }).locator('button[type=submit]');
        assert.equal(await last.count(), 1); assert(await last.evaluate(e => !e.disabled && e.textContent.trim() === 'Zapisz'), 'DETAILS_SAVE_TEXT'); await last.evaluate(e => e.dataset.detailsLast = 'save');
        await nextTo(page, '[data-details-last="save"]'); row.lastButton = await focus(page);
        await screenshot(page, resolve(outputDir, name + '-last-button.png'));
        const paragraph = page.locator('main .marka-panel-tresc > p.meta.mt-5').filter({ hasText: 'Ta wiadomo\u015b\u0107 zniknie z bazy sama,' });
        assert.equal(await paragraph.count(), 1, 'DETAILS_RETENTION_NOTICE_MISSING');
        assert(await paragraph.evaluate(e => /^Ta wiadomo\u015b\u0107 zniknie z bazy sama, \d+ (miesi\u0105c|miesi\u0105ce|miesi\u0119cy) po oznaczeniu jako za\u0142atwiona\. Dop\u00f3ki jest otwarta, nie kasuje jej nic\.$/.test(e.textContent.replace(/\s+/g, ' ').trim())), 'DETAILS_RETENTION_NOTICE_TEXT');
        await paragraph.scrollIntoViewIfNeeded();
        const readParagraph = async () => {
          try { return (await fullText(paragraph)).fragments; }
          catch (error) {
            if (error.message !== 'DETAILS_FULL_TEXT_CLIPPED') throw error;
            return [{ readable: false }];
          }
        };
        row.lastParagraphBefore = await readParagraph();
        row.scrollBefore = await page.evaluate(() => ({ y:scrollY, height:document.documentElement.scrollHeight, viewport:innerHeight, max:document.documentElement.scrollHeight-innerHeight }));
        await screenshot(page, resolve(outputDir, name + '-last-paragraph-before.png'));
        await page.mouse.move(width - 2, 450);
        for (let wheel = 0; wheel < 10; wheel++) {
          await page.mouse.wheel(0, 900);
          await page.evaluate(async () => { for (let frame=0; frame<6; frame++) await new Promise(requestAnimationFrame); });
          if (await page.evaluate(() => Math.abs(scrollY - (document.documentElement.scrollHeight-innerHeight)) <= 1)) break;
        }
        row.scrollAfter = await page.evaluate(() => ({ y:scrollY, height:document.documentElement.scrollHeight, viewport:innerHeight, max:document.documentElement.scrollHeight-innerHeight }));
        assert(Math.abs(row.scrollAfter.y-row.scrollAfter.max) <= 1, 'DETAILS_END_SCROLL_NOT_REACHED');
        row.lastParagraph = await readParagraph();
        await screenshot(page, resolve(outputDir, name + '-last-paragraph.png'));
        row.paragraphEvidence = row.lastParagraphBefore.length && row.lastParagraphBefore.every(r => r.readable) ? 'before' : row.lastParagraph.length && row.lastParagraph.every(r => r.readable) ? 'after' : null;
        assert(row.paragraphEvidence, 'DETAILS_LAST_PARAGRAPH_OCCLUDED');
      }
      assert.equal(requests.length, 0, 'DETAILS_FORBIDDEN_REQUEST'); row.pass = true;
  return row;
}

/** sesja: sciezka prywatnego storageState albo obiekt Playwright.
 * fixture: wynik fazy pelny; scenariusze: opcjonalnie jej tablica scenariusze.
 * outputDir: oddzielny pusty katalog przeznaczony tylko na te dowody.
 */
export async function sprawdzDetailsPanelu({ browser, adres, sesja, fixture, scenariusze = fixture?.scenariusze, outputDir }) {
  assert(browser && typeof browser.newContext === 'function', 'DETAILS_BROWSER_REQUIRED');
  assert(sesja && (typeof sesja === 'string' || typeof sesja === 'object'), 'DETAILS_SESSION_REQUIRED');
  const base = new URL(adres);
  assert(['http:', 'https:'].includes(base.protocol) && base.hostname === '127.0.0.1'
    && !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash, 'DETAILS_LOCAL_ORIGIN_REQUIRED');
  const origin = base.origin;
  assert.equal(fixture?.phase, 'pelny', 'DETAILS_FULL_FIXTURE_REQUIRED');
  assert(Array.isArray(scenariusze), 'DETAILS_SCENARIOS_REQUIRED');
  const messages = scenariusze.filter(s => s.rodzina === 'wiadomosc');
  assert.equal(messages.length, 1, 'DETAILS_MESSAGE_FIXTURE_REQUIRED');
  const message = messages[0];
  assert(typeof message.path === 'string' && /^\/admin\/wiadomosci\/[^/?#\\]+$/.test(message.path)
    && new URL(message.path, origin).origin === origin, 'DETAILS_MESSAGE_PATH');
  assert(typeof outputDir === 'string' && outputDir.length > 0, 'DETAILS_OUTPUT_REQUIRED');
  outputDir = resolve(outputDir);
  mkdirSync(outputDir, { recursive: true, mode: 0o700 });
  assert.equal(readdirSync(outputDir).length, 0, 'DETAILS_OLD_EVIDENCE');
  const cases = przypadkiDetails(message);
  const rows = [], requests = [];
  const scope = '320/1440 CSS, tekst100/140, oba motywy, reducedMotion reduce. Bez zoomu, submit i aktywowania mailto.';
  const save = () => writeFileSync(resolve(outputDir, 'details-wyniki581.json'), JSON.stringify({ scope, requests, rows }, null, 2), { mode: 0o600 });
try {
  for (const width of [320, 1440]) for (const theme of ['light', 'dark']) for (const scale of [100, 140]) for (const c of cases) {
    const row = { id: c.id, width, theme, scale }, name = `details-${c.id}-${width}-${theme}-${scale}`;
    const context = await browser.newContext({ storageState: sesja, serviceWorkers: 'block', viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    let page;
    try {
      page = await context.newPage();
      await sprawdzScenariuszDetails({ page, context, origin, c, width, theme, scale, outputDir, name, row, requests });
    } catch (e) { row.pass = false; row.error = /^DETAILS_[A-Z_]+/.exec(String(e.message))?.[0] || 'DETAILS_ASSERTION_OR_BROWSER'; if (e.geometry) row.failureGeometry = e.geometry; if (page) await screenshot(page, resolve(outputDir, name + '-FAIL.png')).catch(()=>{}); }
    finally { rows.push(row); try { save(); } finally { await context.close(); } }
  }
  assert.equal(rows.length, 24); assert.equal(requests.length, 0, 'DETAILS_FORBIDDEN_REQUEST');
  assert(rows.every(row => row.pass), 'DETAILS_CASE_FAILED');
  return { scope, requests, rows }; // Wylacznie jawnie zmierzone wyniki.
} finally { save(); }
}
