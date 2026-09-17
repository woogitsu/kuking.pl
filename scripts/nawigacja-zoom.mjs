// Rzeczywisty zoom Chromium. Sesja pochodzi z logowania i TOTP lokalnej fixture.
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { sprawdzEtykietyDolnejNawigacji } from './nawigacja-etykiety.mjs';

export async function sprawdzZoomNawigacji({ chromium, adres, sesja, outputDir }) {
  if (!['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('NAV638_ZOOM_HOST');
  const directory = mkdtempSync(join(tmpdir(), 'kuking-nav638-zoom-'));
  const profile = mkdtempSync(join(tmpdir(), 'kuking-nav638-profile-'));
  let context;
  let etap = 'START';
  let konfiguracja = null;
  let geometria = null;
  let wyglad = null;
  try {
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Pomiar menu Kuking', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
    writeFileSync(join(directory, 'worker.js'), 'chrome.runtime.onInstalled.addListener(() => {});');
    etap = 'PRZEGLADARKA';
    context = await chromium.launchPersistentContext(profile, {
      executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(), headless: true,
      viewport: { width: 640, height: 1800 }, reducedMotion: 'reduce',
      args: ['--disable-extensions-except=' + directory, '--load-extension=' + directory],
    });
    etap = 'SESJA';
    await context.addCookies((typeof sesja === 'string' ? JSON.parse(readFileSync(sesja, 'utf8')) : sesja).cookies);
    await context.route('**/*', route => ['GET', 'HEAD'].includes(route.request().method()) ? route.continue() : route.abort());
    etap = 'ROZSZERZENIE';
    const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker', { timeout: 15000 });
    const page = await context.newPage();
    const wyniki = [];
    for (const width of [640, 1440]) for (const dark of [false, true]) {
      konfiguracja = { width, dark, scale: 140 };
      geometria = null;
      wyglad = null;
      etap = 'NAWIGACJA';
      await page.setViewportSize({ width, height: 1800 });
      const response = await page.goto(`${adres}/home`, { waitUntil: 'load' });
      if (response.status() !== 200) throw new Error('NAV638_ZOOM_HTTP');
      etap = 'USTAWIENIE_ZOOMU';
      const zoom = await worker.evaluate(async url => {
        const tab = (await chrome.tabs.query({})).find(t => t.url === url);
        if (!tab) throw new Error('NAV638_ZOOM_TAB');
        await chrome.tabs.setZoom(tab.id, 2);
        return chrome.tabs.getZoom(tab.id);
      }, page.url());
      etap = 'WYGLAD';
      wyglad = await page.evaluate(async dark => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = '140';
        await document.fonts.ready;
        // Po zmianie zoomu Chromium może przez kilka klatek zwracać dawny
        // font mimo aktualnego atrybutu i tokena CSS. Czekamy na wynik stylu,
        // nie ponawiamy ustawień; trwała regresja nadal kończy się błędem.
        return new Promise(resolve => {
          let stable = 0;
          let frame;
          const snapshot = () => ({ stable: stable >= 3, font: parseFloat(getComputedStyle(document.body).fontSize), scale: Number(document.documentElement.dataset.textScale), tokenScale: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--user-text-scale')) });
          const timer = setTimeout(() => { cancelAnimationFrame(frame); resolve(snapshot()); }, 2000);
          const sample = () => {
            stable = Math.abs(snapshot().font - 25.2) <= .15 ? stable + 1 : 0;
            if (stable >= 3) { clearTimeout(timer); resolve(snapshot()); }
            else frame = requestAnimationFrame(sample);
          };
          frame = requestAnimationFrame(sample);
        });
      }, dark);
      if (!wyglad.stable) throw new Error('NAV638_ZOOM_FONT_NIEUSTALONY');
      const geo = await page.evaluate(() => ({ width: innerWidth, dpr: devicePixelRatio, scroll: document.documentElement.scrollWidth, font: parseFloat(getComputedStyle(document.body).fontSize), scale: Number(document.documentElement.dataset.textScale || 100), tokenScale: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--user-text-scale')), ready: document.readyState }));
      geometria = { zoom, ...geo };
      etap = 'GEOMETRIA';
      if (zoom !== 2 || Math.abs(geo.width - width / 2) > 1 || geo.dpr !== 2 || geo.scroll > geo.width + 1 || Math.abs(geo.font - 25.2) > .15) {
        throw new Error('NAV638_ZOOM_GEOMETRIA ' + JSON.stringify(geo));
      }
      etap = 'ZRZUT';
      await page.screenshot({ path: join(outputDir, `nav638-zoom200-${width}-${dark ? 'dark' : 'light'}.png`) });
      etap = 'ETYKIETY';
      const labels = await sprawdzEtykietyDolnejNawigacji(page);
      etap = 'KLAWIATURA';
      const nav = page.getByRole('navigation', {name:'Nawigacja główna',exact:true});
      const links = nav.locator('a');
      await links.first().focus();
      const tab = [];
      for (let i=0;i<5;i++) {
        if(i) await page.keyboard.press('Tab');
        const state = await links.nth(i).evaluate(el => {
          const b=el.getBoundingClientRect();
          const top=document.elementFromPoint(b.x+b.width/2,b.y+b.height/2);
          return {text:el.textContent.trim(),active:el===document.activeElement,focusVisible:el.matches(':focus-visible'),outline:parseFloat(getComputedStyle(el).outlineWidth),visible:b.top>=0&&b.bottom<=innerHeight+1&&!!top&&(top===el||el.contains(top))};
        });
        if(!state.active||!state.focusVisible||state.outline<2||!state.visible) throw new Error('NAV638_FOKUS');
        tab.push(state);
      }
      const profileTarget = await links.last().getAttribute('href');
      await Promise.all([page.waitForURL(profileTarget),page.keyboard.press('Enter')]);
      if(!(await page.locator('main').isVisible())) throw new Error('NAV638_ENTER');
      etap = 'KLIKNIECIA';
      const clicks=[];
      for(const label of ['Start','Szukaj','Dodaj','Moje','Profil']) {
        // Pełna nawigacja odtwarza preferencje konta fixture; utrzymujemy
        // mierzoną konfigurację także przy każdym kolejnym kliknięciu.
        await page.evaluate(async dark => {
          document.documentElement.dataset.theme=dark?'dark':'light';
          document.documentElement.dataset.textScale='140';
          await document.fonts.ready;
        },dark);
        await page.waitForFunction(()=>Math.abs(parseFloat(getComputedStyle(document.body).fontSize)-25.2)<.15,null,{timeout:2000});
        const link=page.getByRole('navigation',{name:'Nawigacja główna',exact:true}).getByRole('link',{name:label,exact:true});
        const href=await link.getAttribute('href');
        await Promise.all([page.waitForURL(href),link.click()]);
        if(!(await page.locator('main').isVisible())) throw new Error('NAV638_KLIK');
        clicks.push({label,path:new URL(page.url()).pathname});
      }
      wyniki.push({ width, dark, scale: 140, zoom, geo, labels, tab, clicks, pass: true });
    }
    writeFileSync(join(outputDir, 'nav638-zoom200.json'), JSON.stringify(wyniki, null, 2));
    return wyniki;
  } catch (error) {
    // Nie publikujemy komunikatu Playwright: może zawierać URL lub dane sesji.
    const kod = /^(?:NAV638|MENU)_[A-Z_]+(?=[\s:]|$)/.exec(String(error?.message ?? ''))?.[0] ?? 'NIEZNANY';
    writeFileSync(join(outputDir, 'nav638-zoom200-blad.json'), JSON.stringify({ etap, kod, konfiguracja, geometria, wyglad }, null, 2));
    throw new Error(`NAV638_ZOOM_ETAP: ${etap}; ${kod}`);
  } finally {
    await context?.close();
    rmSync(directory, { recursive: true, force: true });
    rmSync(profile, { recursive: true, force: true });
  }
}
