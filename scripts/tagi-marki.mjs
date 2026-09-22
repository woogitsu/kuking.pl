/* Rzeczywiste tagi promowane i brak promocji; izolowane dane, bez publikacji. */
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, appendFileSync, statSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';
import { sprawdzTab } from './zoom-marki.mjs';
import { sprawdzKatalogTagow } from './katalog-tagow.mjs';

export async function sprawdzTagi({ browser, adres, sesja, phpEnv = process.env, negatywy = true }) {
  const started = Date.now();
  if (!['localhost', '127.0.0.1'].includes(new URL(adres).hostname)) throw new Error('K515_LOCAL');
  const dir = mkdtempSync(tmpdir() + '/kuking515-');
  const fixture = (...args) => execFileSync('php', ['scripts/fixtures/kompozycje-515.php', args[0], args[1] ?? dir + '/promotions.json'], { env: phpEnv });
  fixture('zapisz', dir + '/promotions.json');
  mkdirSync('storage/port-projektu/515', { recursive: true });
  let data;
  let lastSearchAt = 0;
  async function goSearch(page) {
    // Rzeczywisty limit wyszukiwarki wynosi60/min; pomiar go nie wyłącza.
    await new Promise(resolve => setTimeout(resolve, Math.max(0, 1250 - (Date.now() - lastSearchAt))));
    lastSearchAt = Date.now();
    return page.goto(adres + '/szukaj', { waitUntil: 'networkidle' });
  }
  async function measure(width, dark, scale, empty = false, keyboard = false, guest = false) {
    const context = await browser.newContext({ storageState: guest ? undefined : sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    try {
      const page = await context.newPage(), large = String(scale).startsWith('font'), factor = scale === 140 || scale === 'font+140' ? 1.4 : 1;
      if (large) await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
      await page.addInitScript(({ dark, factor }) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = factor === 1.4 ? '140' : '100';
      }), { dark, factor });
      const response = await goSearch(page);
      if (response.status() !== 200) throw new Error('K515_HTTP '+JSON.stringify({status:response.status(),width,dark,scale,empty}));
      await page.waitForFunction(size => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - size) < .15, 18 * factor * (large ? 2 : 1));
      await page.waitForFunction(dark => getComputedStyle(document.body).color === (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)'), dark);
      const r = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.marka-szukaj-tag')];
        const full = el => {
          const range = document.createRange(); range.selectNodeContents(el);
          for (let n = el; n; n = n.parentElement) {
            const s = getComputedStyle(n), b = n.getBoundingClientRect();
            if (s.display === 'none' || s.visibility !== 'visible' || Number(s.opacity) < .99 || !['none','0'].includes(s.webkitLineClamp)) return false;
            for (const rect of range.getClientRects()) {
              if (rect.left < -1 || rect.right > innerWidth + 1) return false;
              if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && (rect.left < b.left-1 || rect.right > b.right+1)) return false;
              if (/(hidden|clip)/.test(s.overflowY) && (rect.top < b.top-1 || rect.bottom > b.bottom+1)) return false;
            }
          }
          return true;
        };
        return {
          allTags: (() => {
            const links = [...document.querySelectorAll('.marka-szukaj-tagi .marka-szukaj-wszystkie')];
            if (links.length !== 1) return false;
            const link = links[0], rect = link.getBoundingClientRect();
            return new URL(link.href).pathname === '/tagi' && link.textContent.trim() === 'Wszystkie tagi' && rect.width >= 48 && rect.height >= 48 && full(link);
          })(),
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
          count: cards.length,
          full: cards.every(c => [...c.querySelectorAll('h3,p')].every(full)),
          names: cards.map(c => c.querySelector('h3').textContent),
          notes: cards.map(c => c.querySelector('p')?.textContent ?? null),
          colors: cards.map(c => getComputedStyle(c).backgroundColor),
          targets: cards.every(c => { const r = c.querySelector('a').getBoundingClientRect(); return r.width >= 48 && r.height >= 48; }),
          columns: new Set(cards.map(c => Math.round(c.getBoundingClientRect().x))).size,
          empty: document.querySelector('.marka-szukaj-tagi')?.textContent.includes('Nie ma jeszcze polecanych tagów.'),
        };
      });
      const fail = code => { throw new Error(code + ' ' + JSON.stringify({width,dark,scale,empty,r})); };
      if (r.overflow) fail('K515_OVERFLOW');
      if (!r.allTags) fail('K515_WSZYSTKIE_TAGI');
      if (empty ? r.count !== 0 || !r.empty : r.count !== data.tagi.length) fail('K515_STAN');
      if (!empty) {
        if (!r.full || JSON.stringify(r.names) !== JSON.stringify(data.tagi.map(t=>t.name))) fail('K515_TEKST');
        if (JSON.stringify(r.notes) !== JSON.stringify(data.tagi.map(t=>t.note))) fail('K515_OPIS');
        if (!r.targets) fail('K515_CEL');
        if (new Set(r.colors).size !== 2) fail('K515_KOLORY');
        if (width===1440 && scale===100 && r.columns < 2) fail('K515_KOLUMNY');
      }
      if (keyboard) {
        await sprawdzTab(page, '/szukaj');
        if (!empty) {
          const card = page.locator('.marka-szukaj-tag').first();
          await card.scrollIntoViewIfNeeded();
          const heading = card.locator('h3');
          await heading.scrollIntoViewIfNeeded();
          const box = await heading.boundingBox();
          const point = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
          if (!await page.evaluate(({x,y}) => document.elementFromPoint(x,y)?.closest('a')?.classList.contains('marka-szukaj-tag-link'), point)) fail('K515_LINK');
          await Promise.all([page.waitForURL('**'+data.tagi[0].url), page.mouse.click(point.x, point.y)]);
          if ((await page.request.get(page.url())).status() !== 200) fail('K515_LINK');
          await goSearch(page);
        }
      }
      if ((width===320 && scale==='font+140') || (width===1440 && scale===100)) {
        await page.screenshot({path:`storage/port-projektu/515/${empty?'empty':'full'}-${width}-${dark}-${guest?'guest':'owner'}.png`,fullPage:true});
      }
    } finally { await context.close(); }
  }
  try {
    let count=0;
    for (const empty of [false,true]) {
      if (empty) fixture('puste'); else data=JSON.parse(fixture('pelne').toString());
      for (const guest of [false,true]) for (const width of [320,360,390,414,768,1440]) for (const dark of [false,true]) for (const scale of [100,140,'font','font+140']) {
        await measure(width,dark,scale,empty,width===320&&scale===140,guest);count++;
      }
      for (const guest of [false,true]) {
        const context = await browser.newContext({ storageState: guest ? undefined : sesja, javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
        try {
          const page = await context.newPage();
          if ((await goSearch(page)).status() !== 200) throw new Error('K515_BEZ_JS_START');
          const link = page.locator('.marka-szukaj-tagi').getByRole('link', { name: 'Wszystkie tagi', exact: true });
          const [response] = await Promise.all([page.waitForNavigation(), link.click()]);
          if (response?.status() !== 200 || new URL(page.url()).pathname !== '/tagi') throw new Error('K515_BEZ_JS_CEL');
          console.log(`K515_BEZ_JS_OK empty=${empty} guest=${guest}`);
        } finally { await context.close(); }
      }
    }
    data=JSON.parse(fixture('pelne').toString());
    fixture('fotografia');
    await sprawdzKatalogTagow({ browser, adres, expectedTag: 'Pomiar515 1' });
    if (negatywy) for (const [name,css,code,width,keyboard=false,dark=false] of [
      ['overflow','.marka-szukaj-siatka{grid-template-columns:900px!important}','K515_OVERFLOW',320],
      ['tekst','.marka-szukaj-tag h3{display:-webkit-box!important;-webkit-box-orient:vertical;-webkit-line-clamp:1!important;overflow:hidden!important}','K515_TEKST',320],
      ['kolumny','.marka-szukaj-siatka{grid-template-columns:1fr!important}','K515_KOLUMNY',1440],
      ['kolory','.marka-szukaj-tag{background:white!important}','K515_KOLORY',1440],
      ['klik','.marka-szukaj-tag-link::after{display:none!important}','K515_LINK',320,true],
      ['fokus','.marka-szukaj-tag-link:focus-visible{outline-color:rgb(110,168,255)!important}','ZOOM_FOCUS_CONTRAST',320,true,true],
    ]) {
      const source='resources/css/marka-szukaj.css', copy=dir+'/'+name+'.css';
      const hash=p=>createHash('md5').update(readFileSync(p)).digest('hex');
      const before=hash(source),mtime=statSync(source).mtimeMs;
      execFileSync('cp',['-p',source,copy]);let failure;
      try { appendFileSync(source,'\n'+css);execFileSync('npm',['run','build'],{stdio:'pipe'});try{await measure(width,dark,100,false,keyboard);}catch(e){failure=e;} }
      finally { execFileSync('cp',['-p',copy,source]);if(hash(source)!==before||statSync(source).mtimeMs!==mtime)throw new Error('K515_RESTORE');execFileSync('npm',['run','build'],{stdio:'pipe'}); }
      await measure(width,dark,100,false,keyboard);
      if(!failure?.message.startsWith(code+' '))throw new Error('K515_NEGATIVE '+name+' '+failure?.message);
      console.log(`K515_NEGATIVE_OK ${name} ${code} MD5=${before} mtime=${mtime} restored`);
    }
    console.log(`K515_OK ${count} konfiguracji: 96 pełnych i 96 pustych czas_ms=${Date.now()-started}`);
  } finally { fixture('przywroc',dir+'/promotions.json'); }
}
