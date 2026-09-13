/* Prawdziwy zoom karty Chromium przez chrome.tabs.setZoom, nie font ani
   transform CSS. Rozszerzenie i profil powstają wyłącznie w katalogu tmp. */
import { chromium } from 'playwright';
import { mkdtempSync, writeFileSync, readFileSync, appendFileSync, statSync, realpathSync } from 'node:fs';
import { sep } from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';

async function sprawdzTab(page, path) {
  const expected = await page.evaluate(() => {
    const elements = [...document.querySelectorAll('main a[href], main button, main input, main select, main textarea, main summary, main [tabindex]')]
      .filter(el => el.tabIndex >= 0 && !el.disabled && el.getClientRects().length && getComputedStyle(el).visibility === 'visible')
      .filter(el => {
        for (let p = el.parentElement; p; p = p.parentElement) {
          if (p.matches('details:not([open])') && !p.querySelector(':scope > summary')?.contains(el)) return false;
        }
        return true;
      });
    elements.forEach((el, i) => el.dataset.pomiarTab = String(i));
    window.scrollTo(0, 0);
    document.activeElement?.blur();
    return elements.length;
  });
  if (!expected) throw new Error('ZOOM_TAB brak elementów ' + path);
  const seen = new Set();
  for (let i = 0; i < expected + 80 && seen.size < expected; i++) {
    await page.keyboard.press('Tab');
    const r = await page.evaluate(async () => {
      await new Promise(requestAnimationFrame);
      // Pierwsza klatka może zawierać dopiero początek transition (halo 0px).
      // Czekamy na skończenie krótkiej animacji, nie obniżamy progu kontrastu.
      await Promise.race([
        Promise.all(document.activeElement?.getAnimations().map(a => a.finished.catch(() => {})) ?? []),
        new Promise(resolve => setTimeout(resolve, 500)),
      ]);
      await new Promise(requestAnimationFrame);
      const el = document.activeElement;
      if (!el?.hasAttribute('data-pomiar-tab')) return null;
      const fragments = [...el.getClientRects()].filter(r => r.width > 0 && r.height > 0);
      const css = getComputedStyle(el);
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1;
      const painter = canvas.getContext('2d', { willReadFrequently: true });
      const rgba = value => {
        if (!CSS.supports('color', value)) throw new Error('ZOOM_FOCUS_COLOR_FORMAT ' + value);
        painter.clearRect(0, 0, 1, 1);
        painter.fillStyle = value;
        painter.fillRect(0, 0, 1, 1);
        const c = painter.getImageData(0, 0, 1, 1).data;
        return [c[0], c[1], c[2], c[3] / 255];
      };
      const blend = (a, z) => a.slice(0, 3).map((v, i) => v * a[3] + z[i] * (1 - a[3]));
      const chain = [];
      let opacity = 1;
      let hidden = false;
      for (let p = el; p; p = p.parentElement) {
        const s = getComputedStyle(p);
        opacity *= Number(s.opacity);
        hidden ||= s.visibility !== 'visible' || s.display === 'none';
        chain.push(p);
      }
      let background = [255, 255, 255];
      for (const p of chain.slice(1).reverse()) background = blend(rgba(getComputedStyle(p).backgroundColor), background);
      const luminance = c => c.map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
        .reduce((a, v, i) => a + v * [.2126, .7152, .0722][i], 0);
      const ratio = (a, b) => {
        const x = luminance(a), y = luminance(b);
        return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
      };
      const painted = color => { const c = rgba(color); c[3] *= opacity; return blend(c, background); };
      const rings = [];
      const outlineWidth = parseFloat(css.outlineWidth);
      if (outlineWidth > 0 && !['none', 'hidden'].includes(css.outlineStyle)) {
        rings.push({ kind: 'outline', paint: painted(css.outlineColor), contrast: ratio(painted(css.outlineColor), background),
          distance: parseFloat(css.outlineOffset) + outlineWidth / 2 });
      }
      // Dwie bezrozmyciowe warstwy tworzą halo i pierścień. Zwykły cień
      // karty (offset/blur) nie jest dowodem fokusu. Sprawdzamy obie strony.
      let depth = 0, start = 0;
      const layers = [];
      for (let i = 0; i <= css.boxShadow.length; i++) {
        if (css.boxShadow[i] === '(') depth++;
        if (css.boxShadow[i] === ')') depth--;
        if (i === css.boxShadow.length || (css.boxShadow[i] === ',' && depth === 0)) {
          layers.push(css.boxShadow.slice(start, i).trim()); start = i + 1;
        }
      }
      const shadows = layers.map(value => {
        const sizes = value.match(/-?[\d.]+px\b/g)?.map(parseFloat);
        const color = value.replace(/-?[\d.]+px\b/g, '').replace(/\binset\b/g, '').trim();
        if (!color || value.includes('inset') || sizes?.length !== 4 || sizes.slice(0, 3).some(v => v !== 0) || sizes[3] <= 0) return null;
        return { color, spread: sizes[3] };
      }).filter(Boolean).sort((a, b) => a.spread - b.spread);
      for (let j = 0; j < shadows.length; j++) {
        const ring = shadows[j], inner = shadows[j - 1];
        const innerColor = inner ? painted(inner.color) : painted(css.backgroundColor);
        const outerColor = shadows[j + 1] ? painted(shadows[j + 1].color) : background;
        if (ring.spread - (inner?.spread ?? 0) < 1) continue;
        rings.push({ kind: 'shadow', paint: painted(ring.color), contrast: Math.min(ratio(painted(ring.color), innerColor), ratio(painted(ring.color), outerColor)),
          distance: ((inner?.spread ?? 0) + ring.spread) / 2 });
      }
      const ring = rings.sort((a, b) => b.contrast - a.contrast)[0];
      const contrast = ring?.contrast ?? 0;
      const distance = ring?.distance ?? 0;
      // Link inline może mieć osobny prostokąt w każdym wierszu. Środek
      // ich sumy trafia w pusty akapit, choć żaden fragment nie jest zakryty.
      // Sprawdzamy wszystkie fragmenty, nie tylko pierwszy ani największy.
      const points = fragments.flatMap(b => [[b.x + b.width / 2, b.y - distance], [b.x + b.width / 2, b.bottom + distance],
        [b.x - distance, b.y + b.height / 2], [b.right + distance, b.y + b.height / 2]]);
      const centersVisible = fragments.every(b => {
        const center = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
        return center === el || el.contains(center);
      });
      const ambiguousPoints = [];
      const occluded = points.some(([x, y]) => {
        if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) return true;
        const hit = document.elementFromPoint(x, y);
        if (!hit) return true;
        // Hit testing includes transparent sibling boxes; only raster proves
        // that their paint actually covers the focus indicator.
        if (!(hit === el || el.contains(hit) || hit.contains(el))) ambiguousPoints.push([x, y]);
        return chain.slice(1).some(p => {
          const s = getComputedStyle(p), r = p.getBoundingClientRect();
          return (/(hidden|clip|auto|scroll)/.test(s.overflowX) && (x < r.left || x > r.right))
            || (/(hidden|clip|auto|scroll)/.test(s.overflowY) && (y < r.top || y > r.bottom));
        });
      });
      return { id: el.dataset.pomiarTab, name: el.textContent.trim().slice(0,80), hidden, opacity,
        fragments: fragments.map(b => ({ x: b.x, y: b.y, width: b.width, height: b.height })),
        visible: fragments.length > 0 && fragments.every(b => b.y >= 0 && b.bottom <= innerHeight && b.x >= 0 && b.right <= innerWidth),
        centerVisible: centersVisible, occluded, contrast, color: css.outlineColor,
        ring: ring?.kind ?? null, paint: ring?.paint, ambiguousPoints };
    });
    if (!r) continue;
    if (r.hidden || r.opacity <= .01) throw new Error('ZOOM_FOCUS_HIDDEN ' + path + ' ' + JSON.stringify(r));
    if (!r.ring || r.contrast < 3) throw new Error('ZOOM_FOCUS_CONTRAST ' + path + ' ' + JSON.stringify(r));
    if (!r.visible || !r.centerVisible || r.occluded) throw new Error('ZOOM_FOCUS_OCCLUDED ' + path + ' ' + JSON.stringify(r));
    if (r.ambiguousPoints.length) {
      const cdp = await page.context().newCDPSession(page);
      let shot;
      try { shot = await cdp.send('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: false }); }
      finally { await cdp.detach(); }
      const paintedEdges = await page.evaluate(async ({ data, points, paint }) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + data;
        await img.decode();
        const canvas = document.createElement('canvas');
        canvas.width = img.width; canvas.height = img.height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        return points.map(([x, y]) => {
          const px = Math.floor(x * img.width / innerWidth), py = Math.floor(y * img.height / innerHeight);
          const actual = [...ctx.getImageData(px, py, 1, 1).data].slice(0, 3);
          // Sample the middle of the ring, allowing only minor raster rounding.
          return { x, y, actual, visible: actual.every((v, i) => Math.abs(v - paint[i]) <= 24) };
        });
      }, { data: shot.data, points: r.ambiguousPoints, paint: r.paint });
      if (paintedEdges.some(edge => !edge.visible)) throw new Error('ZOOM_FOCUS_OCCLUDED ' + path + ' ' + JSON.stringify({ ...r, paintedEdges }));
    }
    seen.add(r.id);
  }
  if (seen.size !== expected) {
    const missing = await page.evaluate(ids => [...document.querySelectorAll('[data-pomiar-tab]')]
      .filter(el => !ids.includes(el.dataset.pomiarTab)).map(el => ({ id: el.dataset.pomiarTab, html: el.outerHTML.slice(0, 500), inert: !!el.closest('[inert]'), details: el.closest('details')?.outerHTML.slice(0, 200) })), [...seen]);
    throw new Error('ZOOM_TAB incomplete ' + path + ' ' + seen.size + '/' + expected + ' ' + JSON.stringify(missing));
  }
  console.log('ZOOM_TAB_OK ' + path + ' ' + seen.size + '/' + expected);
}

async function sprawdzUklad(page, { zoom, width, scale, dark, path }) {
  const r = await page.evaluate(() => ({ width: innerWidth, dpr: devicePixelRatio,
    scroll: document.documentElement.scrollWidth, font: parseFloat(getComputedStyle(document.body).fontSize),
    color: getComputedStyle(document.body).color }));
  if (zoom !== 2 || Math.abs(r.width - width / 2) > 1 || r.dpr !== 2) throw new Error('ZOOM_NIE_PRZYLOZONY ' + JSON.stringify({zoom, width, ...r}));
  if (r.color !== (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)')) throw new Error('ZOOM_THEME ' + path + ' ' + JSON.stringify(r));
  if (Math.abs(r.font - 18 * scale / 100) > .1) throw new Error('ZOOM_FONT ' + path + ' ' + JSON.stringify(r));
  if (r.scroll > r.width + 1) throw new Error('ZOOM_OVERFLOW ' + path + ' ' + JSON.stringify(r));
  return r;
}

export async function sprawdzZoomMarki({ adres, sesja, przepis, negatywy = true }) {
  const directory = mkdtempSync(tmpdir() + '/kuking-zoom-');
  writeFileSync(directory + '/manifest.json', JSON.stringify({ manifest_version: 3, name: 'Odbior zoom Kuking', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
  writeFileSync(directory + '/worker.js', 'chrome.runtime.onInstalled.addListener(() => {});');
  const context = await chromium.launchPersistentContext(mkdtempSync(tmpdir() + '/kuking-zoom-profil-'), {
    executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(), headless: true,
    viewport: { width: 1440, height: 1480 }, reducedMotion: 'reduce',
    args: ['--no-sandbox', '--disable-extensions-except=' + directory, '--load-extension=' + directory],
  });
  let liczba = 0;
  try {
    const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker', { timeout: 15000 });
    const page = await context.newPage();
    for (const width of [640, 1440]) for (const dark of [false, true]) for (const scale of [100, 140]) {
      await page.setViewportSize({ width, height: 1480 });
      for (const path of ['/login', '/register', '/', przepis, '/@ania', '/@zofia_z_bieszczad']) {
        await context.clearCookies();
        if (path.startsWith('/@') || path === przepis) await context.addCookies(sesja.cookies);
        const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
        if (response.status() !== 200) throw new Error('ZOOM_HTTP ' + path);
        const zoom = await worker.evaluate(async url => {
          const tab = (await chrome.tabs.query({})).find(t => t.url === url);
          if (!tab) throw new Error('Brak karty do pomiaru');
          await chrome.tabs.setZoom(tab.id, 2);
          return chrome.tabs.getZoom(tab.id);
        }, page.url());
        await page.evaluate(async ({ dark, scale }) => {
          document.documentElement.dataset.theme = dark ? 'dark' : 'light';
          document.documentElement.dataset.textScale = String(scale);
          await document.fonts.ready;
          for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
        }, { dark, scale });
        await sprawdzUklad(page, { zoom, width, scale, dark, path });
        if (width === 640 && scale === 140) await sprawdzTab(page, path);
        if (width === 640 && scale === 140) await page.screenshot({ path: `storage/port-projektu/zoom200-${path.replaceAll('/', '').replace('@', '') || 'publiczna'}-${dark}.png`, fullPage: true });
        liczba++;
      }
    }
    if (negatywy) {
      const source = 'resources/css/marka-rama.css';
      if (!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(adres).hostname)
        || !realpathSync(source).startsWith(realpathSync(process.cwd()) + sep)) {
        throw new Error('ZOOM_NEGATIVE_ISOLATION wymagany lokalny serwer i źródło wewnątrz checkoutu');
      }
      execFileSync('git', ['ls-files', '--error-unmatch', source], { stdio: 'pipe' });
      const backup = mkdtempSync(tmpdir() + '/kuking-zoom-negative-') + '/marka-rama.css';
      const md5 = path => createHash('md5').update(readFileSync(path)).digest('hex');
      execFileSync('cp', ['-p', source, backup]);
      const original = md5(source), modified = statSync(source).mtimeMs;
      const build = () => execFileSync('npm', ['run', 'build'], { stdio: 'pipe' });
      const probe = async (dark, path = "/login") => {
        await context.clearCookies();
        if (path.startsWith('/@')) await context.addCookies(sesja.cookies);
        await page.setViewportSize({ width: 640, height: 1480 });
        const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
        if (response.status() !== 200) throw new Error('ZOOM_HTTP ' + path);
        const zoom = await worker.evaluate(async url => {
          const tab = (await chrome.tabs.query({})).find(t => t.url === url);
          if (!tab) throw new Error('Brak karty do pomiaru');
          await chrome.tabs.setZoom(tab.id, 2);
          return chrome.tabs.getZoom(tab.id);
        }, page.url());
        await page.evaluate(async dark => {
          document.documentElement.dataset.theme = dark ? 'dark' : 'light';
          document.documentElement.dataset.textScale = '140';
          await document.fonts.ready;
          for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
        }, dark);
        await sprawdzUklad(page, { zoom, width: 640, scale: 140, dark, path });
        await sprawdzTab(page, path);
      };
      const cases = [
        ['tabs-clipped', 'ZOOM_FOCUS_OCCLUDED', '.tabs .tab:focus-visible { outline-offset: 2px !important; }', '/@ania'],
        ['overflow', 'ZOOM_OVERFLOW', 'main { min-width: 1000px !important; }'],
        ['transparent', 'ZOOM_FOCUS_CONTRAST', 'main :focus-visible { outline: 3px solid transparent !important; box-shadow: none !important; }'],
        ['background', 'ZOOM_FOCUS_CONTRAST', 'main, main * { background-color: #fff !important; } main :focus-visible { outline: 3px solid #fff !important; box-shadow: none !important; }'],
        ['ancestor-opacity', 'ZOOM_FOCUS_HIDDEN', 'main { opacity: 0 !important; }'],
        ['covered', 'ZOOM_FOCUS_OCCLUDED', 'body:has(main :focus-visible) .topbar { position: fixed !important; inset: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 2147483647 !important; background: #fff !important; }'],
      ];
      // Mutacje są prawdziwym źródłem w lokalnej kopii roboczej wywołującego.
      // Build lub pomiar może przerwać pracę: przywrócenie jest w finally.
      for (const [name, code, css, path = "/login"] of cases) {
        let failure;
        try {
          appendFileSync(source, '\n/* Kontrola ujemna zoom: ' + name + ' */\n' + css + '\n');
          build();
          try { await probe(false, path); } catch (error) { failure = error; }
        } finally {
          execFileSync('cp', ['-p', backup, source]);
          if (md5(source) !== original || statSync(source).mtimeMs !== modified) throw new Error('ZOOM_RESTORE_BYTES ' + name);
          build();
        }
        await probe(false, path);
        await probe(true, path);
        if (!failure || !String(failure.message).startsWith(code + ' ')) throw new Error('ZOOM_NEGATIVE_WRONG_RESULT ' + name + ' expected=' + code + ' actual=' + (failure?.message ?? 'PASS'));
        console.log('ZOOM_NEGATIVE_OK ' + name + ' code=' + code + ' MD5=' + original + ' restored=true');
      }
    }
  } finally { await context.close(); }
  console.log('ZOOM200_OK ' + liczba + ' wariantów; chrome.tabs.getZoom=2, DPR=2, viewport=połowa szerokości');
}
