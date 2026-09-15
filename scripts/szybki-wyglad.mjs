import assert from 'node:assert/strict';import {mkdirSync,writeFileSync} from 'node:fs';
export async function sprawdzSzybkiWyglad({browser:b,adres,out='output/wyglad574'}) {
mkdirSync(out,{recursive:true});
try{
const p=await b.newPage({viewport:{width:390,height:850},serviceWorkers:'block'});await p.goto(adres);await p.locator('[data-wyglad-podpowiedz]').waitFor({state:'visible'});await p.locator('[data-wyglad-pomin]').click();await p.reload();assert(await p.locator('[data-wyglad-podpowiedz]').isHidden());
await p.locator('[data-szybki-wyglad] summary').click();await p.selectOption('#szybka-skala','80');await p.locator('[data-wyglad-status]').filter({hasText:'Wygląd zapisany.'}).waitFor();assert.equal(await p.locator('html').getAttribute('data-text-scale'),'80');await p.reload();assert.equal(await p.locator('html').getAttribute('data-text-scale'),'80');
await p.locator('[data-szybki-wyglad] summary').click();await p.selectOption('#szybki-motyw','dark');await p.locator('[data-wyglad-status]').filter({hasText:'Wygląd zapisany.'}).waitFor();await p.reload();assert.equal(await p.locator('html').getAttribute('data-theme'),'dark');
await p.locator('[data-szybki-wyglad] summary').click();await p.locator('[name=reset_appearance]').click();await p.locator('[data-wyglad-status]').filter({hasText:'Wygląd zapisany.'}).waitFor();assert.equal(await p.locator('html').getAttribute('data-text-scale'),'100');
await p.route('**/motyw',route=>route.fulfill({status:429,contentType:'application/json',body:'{}'}));await p.selectOption('#szybka-skala','70');await p.locator('[data-wyglad-status]').filter({hasText:'Nie udało'}).waitFor();assert.equal(await p.locator('html').getAttribute('data-text-scale'),'100');await p.unroute('**/motyw');
const rows=[];
for(const width of [320,360,390,414,768,1440])for(const theme of ['light','dark'])for(const scale of [70,100,140]){
 await p.setViewportSize({width,height:850});await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});await p.waitForTimeout(80);
 const box=await p.locator('.szybki-wyglad-panel').boundingBox();assert(box.x>=-1&&box.x+box.width<=width+1);assert(box.y>=0);assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 for(const button of await p.locator('.szybki-wyglad-panel button').all()){const r=await button.boundingBox();if(r)assert(r.height>=48);}
 if(width===390&&scale===100)await p.screenshot({path:out+'/panel-'+theme+'.png'});
 rows.push({width,theme,scale,result:'PASS'});
}
await p.keyboard.press('Escape');assert(!await p.locator('[data-szybki-wyglad]').evaluate(e=>e.open));assert(await p.locator('[data-szybki-wyglad] summary').evaluate(e=>e===document.activeElement));

const account = await b.newPage({viewport:{width:1440,height:900},serviceWorkers:'block'});
await account.goto(adres+'/login');
await account.fill('input[name=login]','ania');
await account.fill('input[name=password]','haslo-testowe-123');
await Promise.all([account.waitForURL(u=>!u.pathname.endsWith('/login')),account.click('button[type=submit]')]);
for(const width of [1440,320,390,1440]) {
 await account.setViewportSize({width,height:900});await account.waitForTimeout(120);
 const box=await account.locator('[data-szybki-wyglad] summary').boundingBox();
 assert(box.y>=0&&box.y+box.height<=901,'POZYCJA_KONTO');
}
const focusRows = await sprawdzFokusPrzyPrzewijanejNawigacji(account,out);
writeFileSync(out+'/fokus-font32.json',JSON.stringify(focusRows,null,2));
await sprawdzWygladBezJs({browser:b,adres,storageState:await account.context().storageState()});
await account.close();

writeFileSync(out+'/wyniki.json',JSON.stringify({functional:'guest persistence, reset, 429 rollback, hint, Escape PASS',rows},null,2));await p.close();console.log('36 geometrii + zapis gościa/reset/429/Escape PASS');
}finally{}
}
export async function sprawdzFokusPrzyPrzewijanejNawigacji(page,out=null) {
 const cdp = await page.context().newCDPSession(page);
 await cdp.send('Page.setFontSizes',{fontSizes:{standard:32,fixed:32}});
 const rows=[];
 try {
  for(const width of [320,390])for(const theme of ['light','dark'])for(const scale of [70,100,140]) {
   await page.setViewportSize({width,height:740});
   await page.evaluate(({theme,scale})=>{
    document.documentElement.dataset.theme=theme;
    document.documentElement.dataset.textScale=String(scale);
    document.querySelector('[data-szybki-wyglad]').open=false;
    window.scrollTo(0,0);
   },{theme,scale});
   await page.waitForTimeout(100);
   assert.equal(await page.locator('html').evaluate(e=>getComputedStyle(e).fontSize),'32px','RZECZYWISTY_FONT32');
   assert.equal(await page.locator('.bottom-nav').evaluate(e=>getComputedStyle(e).position),'relative','PRZEWIJANA_NAWIGACJA');
   // Ustawiamy początek próby na ostatnim odnośniku. Dopiero rzeczywisty
   // Tab przenosi fokus do przycisku wyglądu i wywołuje przewijanie.
   await page.locator('.bottom-nav a').last().focus();
   await page.keyboard.press('Tab');
   await page.waitForTimeout(100);
   const result=await page.locator('[data-szybki-wyglad] summary').evaluate(e=>{
    const r=e.getBoundingClientRect(),n=document.querySelector('.bottom-nav').getBoundingClientRect();
    const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);
    return {focused:document.activeElement===e,visible:r.top>=0&&r.bottom<=innerHeight&&r.left>=0&&r.right<=innerWidth,
     overlap:r.left<n.right&&r.right>n.left&&r.top<n.bottom&&r.bottom>n.top,
     onTop:hit===e||e.contains(hit),summary:r.toJSON(),nav:n.toJSON(),scrollY};
   });
   rows.push({width,theme,scale,...result});
   assert(result.focused,'TAB_DO_WYGLADU');
   assert(result.visible,'WYGLAD_W_VIEWPORCIE');
   assert(!result.overlap&&result.onTop,'FOKUS_WYGLADU_NAD_NAWIGACJA: '+JSON.stringify(rows.at(-1)));
   if(out&&width===320&&scale===140)await page.screenshot({path:out+'/font32-summary-'+theme+'.png'});
   await page.keyboard.press('Enter');await page.waitForTimeout(100);
   const panel=await page.locator('.szybki-wyglad-panel').boundingBox();
   assert(panel.y>=0&&panel.y+panel.height<=741,'PANEL_W_VIEWPORCIE: '+JSON.stringify({width,theme,scale,panel}));
   const controls=[];
   for(let step=0;step<12;step++) {
    await page.keyboard.press('Tab');await page.waitForTimeout(40);
    const focused=await page.evaluate(()=>{
     const e=document.activeElement,r=e.getBoundingClientRect(),hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2),panel=e.closest('.szybki-wyglad-panel')?.getBoundingClientRect();
     return {withinPanel:!!panel&&r.right<=panel.right&&r.left>=panel.left,rect:r.toJSON(),text:e.textContent,inside:!!e.closest('.szybki-wyglad-panel'),tag:e.tagName,id:e.id,close:e.hasAttribute('data-wyglad-zamknij'),
      visible:r.top>=0&&r.bottom<=innerHeight&&r.left>=0&&r.right<=innerWidth,onTop:hit===e||e.contains(hit)};
    });
    assert(focused.inside&&focused.visible&&focused.onTop&&focused.withinPanel,'FOKUS_POLA_PANELU: '+JSON.stringify({width,theme,scale,focused}));
    if(out&&width===320&&scale===140&&(focused.id==='szybka-skala'||focused.close))await page.screenshot({path:out+'/font32-'+(focused.close?'zamknij':'select')+'-'+theme+'.png'});
    controls.push(focused);if(focused.close)break;
   }
   assert(controls.some(e=>e.id==='szybka-skala')&&controls.some(e=>e.id==='szybki-motyw')&&controls.at(-1).close,'POLA_I_ZAMKNIECIE_OSIAGALNE');
   rows.at(-1).panel={box:panel,controls};
   await page.keyboard.press('Escape');
   assert(await page.locator('[data-szybki-wyglad] summary').evaluate(e=>document.activeElement===e&&!e.parentElement.open),'ESCAPE_WRACA_DO_SUMMARY');
   const scrolls=[];
   for(const top of [700,600,500,400,300,200,100,0,-100]) {
    await page.locator('.bottom-nav').evaluate((e,top)=>window.scrollBy(0,e.getBoundingClientRect().top-top),top);
    await page.waitForTimeout(60);
    const position=await page.locator('[data-szybki-wyglad] summary').evaluate(e=>{
     const r=e.getBoundingClientRect(),n=document.querySelector('.bottom-nav').getBoundingClientRect();
     const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);
     return {summary:r.toJSON(),nav:n.toJSON(),visible:r.top>=0&&r.bottom<=innerHeight,
      overlap:r.left<n.right&&r.right>n.left&&r.top<n.bottom&&r.bottom>n.top,onTop:hit===e||e.contains(hit)};
    });
    scrolls.push(position);
    assert(position.visible&&!position.overlap&&position.onTop,'PRZEWIJANIE_NAWIGACJI: '+JSON.stringify({width,theme,scale,top,position}));
   }
   rows.at(-1).scrolls=scrolls;
   // Po odejściu nawigacji poza ekran rezerwa nie może zostać na stałe.
   await page.evaluate(()=>window.scrollTo(0,0));await page.waitForTimeout(100);
   assert.equal(await page.locator('html').evaluate(e=>e.style.getPropertyValue('--wyglad-dol')),'0px','REZERWA_PO_PRZEWINIECIU');
  }
  return rows;
 } finally {await cdp.send('Page.setFontSizes',{fontSizes:{standard:16,fixed:16}});await cdp.detach();}
}
export async function sprawdzWygladBezJs({browser,adres,storageState}) {
 const page=await browser.newPage({viewport:{width:320,height:740},javaScriptEnabled:false,storageState});
 try {
  const cdp=await page.context().newCDPSession(page);
  await cdp.send('Page.setFontSizes',{fontSizes:{standard:32,fixed:32}});
  await page.goto(adres+'/home');
  const original={scale:await page.locator('#szybka-skala').inputValue(),theme:await page.locator('#szybki-motyw').inputValue()};
  assert.equal(await page.locator('[data-szybki-wyglad]').getAttribute('data-wyglad-gotowy'),null);
  assert.equal(await page.locator('[data-szybki-wyglad]').evaluate(e=>getComputedStyle(e).position),'relative','BAZA_BEZ_JS_W_PRZEPLYWIE');
  await page.locator('.bottom-nav a').last().focus();await page.keyboard.press('Tab');
  assert(await page.locator('[data-szybki-wyglad] summary').evaluate(e=>e===document.activeElement),'NOJS_TAB');
  await page.keyboard.press('Enter');
  await page.selectOption('#szybka-skala','70');
  await Promise.all([page.waitForNavigation(),page.locator('[data-szybki-wyglad] button[type=submit]').first().click()]);
  await page.reload();assert.equal(await page.locator('html').getAttribute('data-text-scale'),'70','NOJS_POST_ODCZYT');
  await page.locator('[data-szybki-wyglad] summary').click();
  await page.selectOption('#szybka-skala',original.scale);await page.selectOption('#szybki-motyw',original.theme);
  await Promise.all([page.waitForNavigation(),page.locator('[data-szybki-wyglad] button[type=submit]').first().click()]);
  await page.reload();assert.equal((await page.locator('html').getAttribute('data-text-scale'))??'100',original.scale);
  console.log('NoJS: Tab, panel w przepływie, POST 70%, odczyt i przywrócenie PASS');
 } finally {await page.close();}
}
