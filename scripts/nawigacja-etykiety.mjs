import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
// Kandydat regresji #638. Uruchamiać na rzeczywistym widoku Laravel po zalogowaniu.
// Nie zastępuje odbioru klawiatury, kliknięć ani prawdziwego zoomu.
export async function sprawdzEtykietyDolnejNawigacji(page) {
  const nav = page.getByRole('navigation', { name: 'Nawigacja główna', exact: true });
  const result = await nav.evaluate(el => {
    const items = [...el.querySelectorAll('a')].map(a => {
      const walker = document.createTreeWalker(a, NodeFilter.SHOW_TEXT);
      const fragments = [];
      let node;
      while ((node = walker.nextNode())) {
        if (!node.textContent.trim() || node.parentElement.closest('svg,[aria-hidden="true"]')) continue;
        const range = document.createRange();
        const text = node.textContent;
        range.setStart(node, text.search(/\S/));
        range.setEnd(node, text.search(/\s*$/));
        fragments.push(...[...range.getClientRects()].filter(r => r.width > 0 && r.height > 0).map(r => ({ top: r.top, bottom: r.bottom, left: r.left, right: r.right })));
      }
      const box = a.getBoundingClientRect();
      const lines = new Set(fragments.map(r => Math.round(r.top * 10) / 10));
      return { text: a.textContent.trim(), left: box.left, right: box.right, bottom: box.bottom, top: box.top, width: box.width, height: box.height, lines: lines.size, font: parseFloat(getComputedStyle(a).fontSize), contained: fragments.every(r => r.left >= box.left - 1 && r.right <= box.right + 1), fragments };
    });
    const nav = el.getBoundingClientRect();
    return { scale: Number(document.documentElement.dataset.textScale || 100), rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize), nav: {left: nav.left, right: nav.right, top: nav.top, bottom: nav.bottom}, width: innerWidth, clientWidth: document.documentElement.clientWidth, navWidth: el.getBoundingClientRect().width, items };
  });
  const expected = ['Start', 'Szukaj', 'Dodaj', 'Moje', 'Profil'];
  if (JSON.stringify(result.items.map(i => i.text)) !== JSON.stringify(expected)) throw new Error('NAV638_KOMPLETNOSC');
  if (result.items.some(i => i.lines !== 1 || !i.contained || i.width < 48 || i.height < 48)) throw new Error('NAV638_ETYKIETY ' + JSON.stringify(result));
  const expectedFont = 18 * result.rootFont / 16 * result.scale / 100;
  if (result.items.some(i => Math.abs(i.font - expectedFont) > .15)) throw new Error('NAV638_FONT');
  if (result.items.some(i => i.left < Math.max(0, result.nav.left) - 1 || i.right > Math.min(result.width, result.nav.right) + 1 || i.top < result.nav.top - 1 || i.bottom > result.nav.bottom + 1)) throw new Error('NAV638_PRZYCISK_POZA_BELKA');
  for (let a = 0; a < result.items.length; a++) for (let b = a + 1; b < result.items.length; b++) {
    const x=result.items[a], y=result.items[b];
    if (Math.min(x.right,y.right)-Math.max(x.left,y.left)>1 && Math.min(x.bottom,y.bottom)-Math.max(x.top,y.top)>1) throw new Error('NAV638_NAKLADANIE');
  }
  if (result.rootFont <= 16 && result.scale <= 100 && result.navWidth >= 289 && new Set(result.items.map(i=>Math.round(i.top))).size !== 1) throw new Error('NAV638_ZBEDNY_WIERSZ');
  return result;
}
export async function sprawdzMacierzNawigacji({browser, adres, sesja, outputDir='storage/port-projektu/nawigacja638'}) {
  if(!['127.0.0.1','localhost'].includes(new URL(adres).hostname)) throw new Error('NAV638_HOST');
  mkdirSync(outputDir,{recursive:true});
  const context=await browser.newContext({storageState:sesja,reducedMotion:'reduce'});
  const rows=[];
  try {
    const page=await context.newPage();
    for(const width of [305,320,360,390,414,768]) for(const dark of [false,true]) for(const scale of [70,80,90,100,112,125,140]) for(const gutter of [false,true]) {
      await page.setViewportSize({width,height:740});
      if((await page.goto(`${adres}/home`,{waitUntil:'networkidle'})).status()!==200) throw new Error('NAV638_HTTP');
      await page.evaluate(async ({dark,scale,gutter})=>{
        document.documentElement.dataset.theme=dark?'dark':'light';
        document.documentElement.dataset.textScale=String(scale);
        document.documentElement.style.scrollbarGutter=gutter?'stable':'auto';
        await document.fonts.ready;
      },{dark,scale,gutter});
      await page.waitForFunction(scale=>Math.abs(parseFloat(getComputedStyle(document.body).fontSize)-18*scale/100)<.15,scale,{timeout:2000});
      const result=await sprawdzEtykietyDolnejNawigacji(page);
      rows.push({width,dark,scale,gutter,result,pass:true});
      if(width===320&&gutter&&[100,140].includes(scale)) await page.screenshot({path:resolve(outputDir,`nav-${dark}-${scale}.png`)});
    }
    if(rows.length!==168) throw new Error('NAV638_MACIERZ_NIEPELNA');
    writeFileSync(resolve(outputDir,'macierz.json'),JSON.stringify(rows,null,2));
    await sprawdzDuzyFontNawigacji({browser,adres,sesja,outputDir});
    return rows;
  } finally {await context.close();}
}
// Font przeglądarki 32 px to osobny test; nie zastępuje rzeczywistego zoomu.
export async function sprawdzDuzyFontNawigacji({browser,adres,sesja,outputDir}) {
  if(!['127.0.0.1','localhost'].includes(new URL(adres).hostname)) throw new Error('NAV638_HOST');
  const context=await browser.newContext({storageState:sesja,reducedMotion:'reduce'});
  const rows=[];
  try {
    const page=await context.newPage();
    await (await context.newCDPSession(page)).send('Page.setFontSizes',{fontSizes:{standard:32,fixed:32}});
    for(const width of [305,320,360,390,414,768]) for(const dark of [false,true]) for(const scale of [100,140]) {
      await page.setViewportSize({width,height:900});
      if((await page.goto(`${adres}/home`,{waitUntil:'networkidle'})).status()!==200) throw new Error('NAV638_HTTP');
      await page.evaluate(async ({dark,scale})=>{
        document.documentElement.dataset.theme=dark?'dark':'light';
        document.documentElement.dataset.textScale=String(scale);
        await document.fonts.ready;
      },{dark,scale});
      await page.waitForFunction(scale=>Math.abs(parseFloat(getComputedStyle(document.body).fontSize)-36*scale/100)<.15,scale,{timeout:2000});
      const result=await sprawdzEtykietyDolnejNawigacji(page);
      if(result.rootFont!==32 || await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)) throw new Error('NAV638_DUZY_FONT_OVERFLOW');
      rows.push({width,dark,scale,result,pass:true});
      if(width===320&&scale===140) {
        const nav=page.getByRole('navigation',{name:'Nawigacja główna',exact:true});
        await nav.scrollIntoViewIfNeeded();
        await nav.screenshot({path:resolve(outputDir,`nav-font32-${dark}-140.png`)});
      }
    }
    if(rows.length!==24) throw new Error('NAV638_DUZY_FONT_NIEPELNY');
    writeFileSync(resolve(outputDir,'font32.json'),JSON.stringify(rows,null,2));
    return rows;
  } finally {await context.close();}
}
