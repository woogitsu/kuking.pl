/* D-212: rzeczywista geometria zainteresowań i zapełnionych powiadomień. */
import { appendFileSync, writeFileSync, readFileSync, mkdtempSync, statSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { sprawdzTab } from './zoom-marki.mjs';

export async function sprawdzKompozycje513({ browser, adres, sesja, tagi513, akcje513 = [], bezCelu513, phpEnv = process.env, negatywy = true }) {
  const php = args => execFileSync('php', args, { env: phpEnv });
  const started = Date.now();
  const paths = ['/witaj/zainteresowania', '/powiadomienia'];
  if (!['localhost', '127.0.0.1'].includes(new URL(adres).hostname)) throw new Error('K513_LOCAL_ONLY');
  mkdirSync('storage/port-projektu/513', { recursive: true });
  async function measure(width, dark, scale, path, interaction = false) {
    const context = await browser.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    try {
      const page = await context.newPage();
      const large = String(scale).startsWith('font');
      const factor = scale === 140 || scale === 'font+140' ? 1.4 : 1;
      if (large) await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
      await page.addInitScript(({ dark, factor }) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = factor === 1.4 ? '140' : '100';
      }), { dark, factor });
      const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
      if (response.status() !== 200) throw new Error('K513_HTTP ' + path);
      await page.evaluate(() => document.fonts.ready);
      await page.waitForFunction(size => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - size) < .15, 18 * factor * (large ? 2 : 1));
      await page.waitForFunction(dark => getComputedStyle(document.body).color === (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)'), dark);
      const r = await page.evaluate(desktop => {
        const full = el => {
          if (!el?.getClientRects().length) return false;
          const range = document.createRange(); range.selectNodeContents(el);
          for (const p of [el, ...function* () { for (let n = el.parentElement; n; n = n.parentElement) yield n; }()]) {
            const s = getComputedStyle(p), b = p.getBoundingClientRect();
            if (s.display === 'none' || s.visibility !== 'visible' || Number(s.opacity) < .99 || !['none', '0'].includes(s.webkitLineClamp)) return false;
            for (const rect of range.getClientRects()) {
              if (rect.left < -1 || rect.right > innerWidth + 1) return false;
              if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && (rect.left < b.left - 1 || rect.right > b.right + 1)) return false;
              if (/(hidden|clip|auto|scroll)/.test(s.overflowY) && (rect.top < b.top - 1 || rect.bottom > b.bottom + 1)) return false;
            }
          }
          return true;
        };
        const tiles = [...document.querySelectorAll('.onboarding-interest-tile')];
        const cards = [...document.querySelectorAll('.marka-powiadomienia article')];
        return {
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
          heading: parseFloat(getComputedStyle(document.querySelector('main h1')).fontSize),
          tiles: tiles.length, names: tiles.every(t => full(t.querySelector('.onboarding-interest-name'))),
          targets: tiles.every(t => { const b = t.getBoundingClientRect(); return b.width >= 48 && b.height >= 48; }),
          columns: new Set(tiles.map(t => Math.round(t.getBoundingClientRect().x))).size,
          ordinary: cards.filter(c => c.matches('.marka-powiadomienie-zwykle')).length,
          decision: cards.some(c => !c.matches('.marka-powiadomienie-zwykle') && c.querySelectorAll('.powiadomienie-tresc > p').length > 2 && [...c.querySelectorAll('a')].some(a => a.textContent.includes('Odwoł'))),
          text: cards.every(c => [...c.querySelectorAll('.powiadomienie-tresc > p')].every(full)),
          forms: cards.every(c => [...c.querySelectorAll('form')].every(f => f.method === 'post' && f.querySelector('input[name="_token"]') && f.querySelector('button'))),
          geometry: cards.filter(c => c.matches('.marka-powiadomienie-zwykle')).every(c => {
            const body = c.querySelector('.powiadomienie-tresc'), form = body.querySelector(':scope > form'), p = body.querySelector('p');
            if (!form) return true;
            const a = form.getBoundingClientRect(), b = p.getBoundingClientRect(), outer = c.getBoundingClientRect();
            if (desktop && a.left < b.right - 1) return false;
            return a.right <= outer.right + 1 && a.left >= outer.left - 1 && (a.top >= b.bottom - 1 || a.left >= b.right - 1);
          }),
        };
      }, width === 1440 && scale === 100);
      const fail = code => { throw new Error(code + ' ' + JSON.stringify({ width, dark, scale, path, r })); };
      if (r.overflow) fail('K513_OVERFLOW');
      const expectedHeading = Math.min(52 * (large ? 2 : 1), Math.max(34 * (large ? 2 : 1), width * .036)) * factor;
      if (Math.abs(r.heading - expectedHeading) > .2) fail('K513_NAGLOWEK');
      if (path.includes('zainteresowania')) {
        if (r.tiles !== tagi513 || !r.targets) fail('K513_KAFLE');
        if (!r.names) fail('K513_TEKST');
        if (width === 1440 && scale === 100 && r.columns !== 3) fail('K513_KOLUMNY');
        if (interaction) {
          const input = page.locator('.onboarding-interest-tile input').first(), tile = page.locator('.onboarding-interest-tile').first();
          const before = await tile.evaluate(el => getComputedStyle(el).backgroundColor);
          await tile.click();
          if (!await input.isChecked()) fail('K513_WYBOR');
          await tile.evaluate(async el => { await new Promise(requestAnimationFrame); await Promise.all(el.getAnimations().map(a => a.finished)); });
          const after = await tile.evaluate(el => getComputedStyle(el).backgroundColor);
          if (before === after) fail('K513_STAN');
          await input.focus(); await page.keyboard.press('Space');
          if (await input.isChecked()) fail('K513_WYBOR');
        }
      } else {
        if (r.ordinary < 7 || !r.decision || !r.forms) fail('K513_ZAKRES');
        if (!r.text) fail('K513_TEKST');
        if (!r.geometry) fail('K513_UKLAD');
      }
      if (interaction) await sprawdzTab(page, path);
      if (width === 320 && scale === 140) { await page.evaluate(() => { document.activeElement?.blur(); scrollTo(0, 0); }); await page.screenshot({ path: `storage/port-projektu/513/${path.includes('witaj') ? 'zainteresowania' : 'powiadomienia'}-${dark ? 'dark' : 'light'}.png`, fullPage: true }); }
    } finally { await context.close(); }
  }
  let count = 0;
  for (const width of [320,360,390,414,768,1440]) for (const dark of [false,true]) for (const scale of [100,140,'font','font+140']) for (const path of paths) {
    await measure(width,dark,scale,path,width === 320 && scale === 140); count++;
  }
  if (negatywy) {
    for (const [name, source, css, code, width, path, interaction] of [
      ['dluga-legenda','resources/css/marka-onboarding.css','LEGEND','K513_OVERFLOW',320,paths[0],false],
      ['naglowek','resources/css/marka-rama.css','HEADING','K513_NAGLOWEK',1440,paths[0],false],
      ['decyzja-zwykla','resources/views/pages/notifications.blade.php','BLADE','K513_ZAKRES',320,paths[1],false],
      ['szeroka-siatka','resources/css/marka-onboarding.css','.marka-onboarding .onboarding-interest-grid { grid-template-columns: 900px !important; }','K513_OVERFLOW',320,paths[0],false],
      ['ucieta-nazwa','resources/css/marka-onboarding.css','.onboarding-interest-name { display: -webkit-box !important; -webkit-box-orient: vertical; -webkit-line-clamp: 1 !important; overflow: hidden !important; }','K513_TEKST',320,paths[0],false],
      ['brak-stanu','resources/css/marka-onboarding.css','.onboarding-interest-tile:has(input:checked) { background: var(--color-surface-raised) !important; }','K513_STAN',320,paths[0],true],
      ['akcja-pod-tekstem','resources/css/marka-powiadomienia.css','.marka-powiadomienie-zwykle .powiadomienie-tresc:has(> form) { display:block !important; }','K513_UKLAD',1440,paths[1],false],
      ['kolizja-akcji','resources/css/marka-powiadomienia.css','.marka-powiadomienie-zwykle .powiadomienie-tresc { display:grid !important; grid-template-columns: 850px 200px !important; }','K513_OVERFLOW',320,paths[1],false],
    ]) {
      const backup = mkdtempSync(tmpdir()+'/kuking513-')+'/source';
      const hash = p => createHash('md5').update(readFileSync(p)).digest('hex');
      const before = hash(source), mtime = statSync(source).mtimeMs;
      execFileSync('cp',['-p',source,backup]);
      const build = () => execFileSync('npm',['run','build:assets'],{stdio:'pipe'});
      let failure;
      const scale = name === 'dluga-legenda' ? 'font+140' : name === 'akcja-pod-tekstem' ? 100 : 140;
      try {
        if (css === 'LEGEND') {
          const original = readFileSync(source, 'utf8');
          const changed = original.replace(/(\.marka-onboarding \.onboarding-interests legend\s*\{)([^}]+)(\})/, (_, open, body, close) => open + body.replace(/(?:min-width:\s*0|max-width:\s*100%|overflow-wrap:\s*anywhere);/g, '') + close);
          if (changed === original) throw new Error('K513_MUTATION_SOURCE');
          writeFileSync(source, changed);
        } else if (css === 'HEADING') {
          const original = readFileSync(source, 'utf8');
          const changed = original.replace('[data-marka] .marka-onboarding > h1,', '');
          if (changed === original) throw new Error('K513_MUTATION_SOURCE');
          writeFileSync(source, changed);
        } else if (css === 'BLADE') {
          const original = readFileSync(source, 'utf8');
          if (original.split('=> $zwykleZdarzenie').length !== 2) throw new Error('K513_MUTATION_SOURCE');
          writeFileSync(source, original.replace('=> $zwykleZdarzenie', '=> true'));
          php(['artisan', 'view:clear']);
        } else appendFileSync(source,'\n/* Negatyw513 */\n'+css);
        build(); try { await measure(width,false,scale,path,interaction); } catch(e) { failure=e; }
      }
      finally { execFileSync('cp',['-p',backup,source]); if (hash(source)!==before || statSync(source).mtimeMs!==mtime) throw new Error('K513_RESTORE'); php(['artisan', 'view:clear']); build(); }
      await measure(width,false,scale,path,interaction);
      if (!failure?.message.startsWith(code+' ')) throw new Error('K513_NEGATIVE_WRONG '+name+' '+(failure?.message??'PASS'));
      console.log(`K513_NEGATIVE_OK ${name} ${code} MD5=${before} mtime=${mtime} restored`);
    }
  }
  // POST przez istniejący formularz, a potem niezależny od DOM odczyt bazy.
  const state = () => JSON.parse(php(['scripts/fixtures/kompozycje-513.php', 'stan']).toString());
  const originalState = state();
  const context = await browser.newContext({ storageState: sesja, javaScriptEnabled: false });
  try {
    const page = await context.newPage();
    for (const action of akcje513) {
      const before = state();
      await page.goto(adres + '/powiadomienia');
      // Adres formularza bierze się z prawdziwego HTML; UUID zawęża go do jednej pozycji.
      const actual = page.locator('main form').filter({ has: page.locator('input[name="_token"]') });
      const forms = await actual.evaluateAll(nodes => nodes.map(n => ({ action:n.action, token:n.querySelector('[name="_token"]').value })));
      const found = forms.find(f => f.action.includes(action.id));
      if (!found || !action.url) throw new Error('K513_POST_FORM ' + action.id);
      const response = await context.request.post(found.action, { form: { _token: found.token }, maxRedirects: 0 });
      const destination = new URL(response.headers().location, adres), expected = new URL(action.url, adres);
      if (response.status() !== 302 || destination.pathname !== expected.pathname || destination.hash !== expected.hash) throw new Error('K513_POST_REDIRECT ' + JSON.stringify({ id: action.id, status: response.status(), actual: destination.pathname + destination.hash, expected: expected.pathname + expected.hash }));
      const target = await context.request.get(adres + destination.pathname + destination.search);
      if (target.status() !== 200) throw new Error('K513_TARGET_GET ' + action.id + ' ' + target.status());
      const after = state();
      if (!after.read[action.id] || Object.keys(before.read).some(id => id !== action.id && before.read[id] !== after.read[id])) throw new Error('K513_POST_READ ' + action.id);
    }
    await page.goto(adres + '/powiadomienia');
    const noTarget = page.locator('main form').filter({ has: page.locator('button') });
    const noTargetForm = await noTarget.evaluateAll(forms => forms.map(f => ({ action: f.action, token: f.querySelector('[name="_token"]')?.value })));
    const orphan = noTargetForm.find(f => f.action.includes(bezCelu513));
    const beforeOrphan = state();
    if (!orphan) throw new Error('K513_BEZ_CELU_FORM');
    const orphanResponse = await context.request.post(orphan.action, { form: { _token: orphan.token }, maxRedirects: 0 });
    const afterOrphan = state();
    if (orphanResponse.status() !== 302 || !afterOrphan.read[bezCelu513] || Object.keys(beforeOrphan.read).some(id => id !== bezCelu513 && beforeOrphan.read[id] !== afterOrphan.read[id])) throw new Error('K513_BEZ_CELU_POST');
    for (const position of [0, 1]) {
      php(['scripts/fixtures/kompozycje-513.php', 'przywroc', JSON.stringify(originalState)]);
      await page.goto(adres + '/powiadomienia');
      const all = page.getByRole('button', { name: 'Oznacz wszystkie jako przeczytane', exact: true });
      if (await all.count() !== 2) throw new Error('K513_READ_ALL_FORM');
      await Promise.all([page.waitForNavigation(), all.nth(position).click()]);
      if (Object.values(state().read).some(value => !value)) throw new Error('K513_READ_ALL_DB');
    }
    await page.goto(adres + paths[0]);
    const tag = page.locator('.onboarding-interest-tile input').last();
    const id = await tag.getAttribute('value');
    const before = state();
    if (before.tags.includes(id)) throw new Error('K513_TAG_FIXTURE wybrany tag już obserwowany');
    await tag.check();
    await Promise.all([page.waitForURL('**/witaj/ludzie'), page.locator('main button[type="submit"]').click()]);
    const after = state();
    if (!after.tags.includes(id) || before.tags.some(t => !after.tags.includes(t))) throw new Error('K513_TAG_DB');
    console.log(`K513_POST_OK ${akcje513.length} pojedynczych odczytów, bez celu, oba read-all i zapis tagu bez JS`);
  } finally {
    await context.close();
    php(['scripts/fixtures/kompozycje-513.php', 'przywroc', JSON.stringify(originalState)]);
    if (JSON.stringify(state()) !== JSON.stringify(originalState)) throw new Error('K513_FIXTURE_RESTORE');
  }
  await sprawdzStanyList513({ browser, adres, sesja, php });
  console.log(`K513_OK ${count} konfiguracji czas_ms=${Date.now()-started}`);
}


async function sprawdzStanyList513({ browser, adres, sesja, php }) {
  const backup = mkdtempSync(tmpdir() + '/kuking-513-listy-') + '/dane.json';
  const fixture = (...args) => php(['scripts/fixtures/kompozycje-513.php', ...args]);
  fixture('zapisz-listy', backup);
  try {
    for (const variant of ['puste-listy', 'paginacja']) {
      fixture('przywroc-listy', backup);
      fixture(variant);
      for (const width of [320, 1440]) for (const dark of [false, true]) {
        const context = await browser.newContext({ storageState: sesja, viewport: { width, height: 900 } });
        try {
          const page = await context.newPage();
          await page.addInitScript(dark => document.addEventListener('DOMContentLoaded', () => { document.documentElement.dataset.theme = dark ? 'dark' : 'light'; }), dark);
          for (const path of variant === 'puste-listy' ? ['/witaj/zainteresowania', '/powiadomienia'] : ['/powiadomienia']) {
            const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
            if (response.status() !== 200 || await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) throw new Error('K513_STANY_GEOMETRIA');
            if (variant === 'puste-listy') {
              const empty = path === '/powiadomienia' ? 'Nie ma jeszcze żadnych powiadomień' : 'Nie mamy jeszcze listy tagów';
              if (!(await page.locator('main').innerText()).includes(empty)) throw new Error('K513_PUSTY_STAN');
            } else {
              // Od #986 skrypt zamienia odnośnik „Następna strona powiadomień”
              // na przycisk „Pokaż więcej powiadomień”, który DOKŁADA drugą
              // porcję do listy (9 kart) zamiast otwierać osobną stronę.
              // Ścieżka bez skryptu (sam odnośnik do page=2) jest sprawdzana niżej.
              const more = page.locator('main').getByRole('button', { name: 'Pokaż więcej powiadomień', exact: true });
              if (await more.count() !== 1) throw new Error('K513_PAGINACJA_LINK');
              const articles = page.locator('main article');
              const before = await articles.count();
              await more.click();
              await page.waitForFunction(n => document.querySelectorAll('main article').length >= n, before + 9, { timeout: 15000 }).catch(() => {});
              if (await articles.count() !== before + 9 || await more.count() !== 0) throw new Error('K513_PAGINACJA_TRESC');
              if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) throw new Error('K513_STANY_GEOMETRIA');
            }
          }
        } finally { await context.close(); }
      }
      if (variant === 'paginacja') {
        // Bez JavaScriptu (AGENTS.md §5) zostaje uczciwy odnośnik do drugiej strony.
        const context = await browser.newContext({ storageState: sesja, javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
        try {
          const page = await context.newPage();
          await page.goto(adres + '/powiadomienia', { waitUntil: 'networkidle' });
          const next = page.locator('main a[href*="page=2"]').first();
          if (!await next.count()) throw new Error('K513_PAGINACJA_LINK_BEZ_JS');
          const second = await page.goto(await next.getAttribute('href'), { waitUntil: 'networkidle' });
          if (second.status() !== 200 || await page.locator('main article').count() !== 9) throw new Error('K513_PAGINACJA_TRESC_BEZ_JS');
        } finally { await context.close(); }
      }
    }
    console.log('K513_STANY_OK 8 pustych stron, 4 dokładania porcji i przejście paginacji bez JS');
  } finally { fixture('przywroc-listy', backup); }
}

