import assert from 'node:assert/strict';import {mkdirSync,writeFileSync} from 'node:fs';
export async function sprawdzSzybkiWyglad({browser:b,adres,out='output/wyglad574'}) {
mkdirSync(out,{recursive:true});
try{
await sprawdzBladPodWygladem({browser:b,adres});
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

await sprawdzPodpowiedzUstepujeWskaznikowi({browser:b,adres,out});

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
const timingRows=await sprawdzFokusBezCzekania(account,adres);
writeFileSync(out+'/fokus-bez-czekania.json',JSON.stringify(timingRows,null,2));
const focusRows = await sprawdzFokusPrzyPrzewijanejNawigacji(account,out);
writeFileSync(out+'/fokus-font32.json',JSON.stringify(focusRows,null,2));
await sprawdzWygladBezJs({browser:b,adres,storageState:await account.context().storageState()});
await account.close();

writeFileSync(out+'/wyniki.json',JSON.stringify({functional:'guest persistence, reset, 429 rollback, hint, Escape PASS',rows},null,2));await p.close();console.log('36 geometrii + zapis gościa/reset/429/Escape PASS');
}finally{}
}
export async function sprawdzFokusBezCzekania(page,adres) {
 const cdp=await page.context().newCDPSession(page);const rows=[];
 await cdp.send('Page.setFontSizes',{fontSizes:{standard:32,fixed:32}});
 await page.emulateMedia({reducedMotion:'reduce'});
 try {
  for(const width of [320,360])for(const path of ['/home','/ustawienia/profil']) {
   await page.setViewportSize({width,height:740});
   await page.goto(adres+path,{waitUntil:'domcontentloaded'});
   await page.evaluate(()=>document.fonts.ready);
   let found=false,complete=false;
   for(let step=0;step<400;step++) {
    // Ten sam moment i siatka 4×4 co dostepnosc.mjs: żadnej pauzy po Tab.
    await page.keyboard.press('Tab');
    const state=await page.evaluate(()=>{
     const e=document.activeElement;
     if(!e||e===document.body||e===document.documentElement||e.dataset.wygladFokusWidziany==='1')return {end:true};
     e.dataset.wygladFokusWidziany='1';
     if(!e.matches('[data-szybki-wyglad] summary'))return {};
     const r=e.getBoundingClientRect(),nav=document.querySelector('.bottom-nav');
     const left=Math.max(r.left,0),right=Math.min(r.right,innerWidth),top=Math.max(r.top,0),bottom=Math.min(r.bottom,innerHeight);let covered=0;
     for(let y=0;y<4;y++)for(let x=0;x<4;x++) {
      const hit=document.elementFromPoint(left+(right-left)*(x+.5)/4,top+(bottom-top)*(y+.5)/4);
      if(hit&&nav?.contains(hit)&&!e.contains(hit))covered++;
     }
     return {summary:true,covered,rect:r.toJSON(),nav:nav?.getBoundingClientRect().toJSON(),visible:r.top>=0&&r.bottom<=innerHeight};
    });
    if(state.end){complete=true;break;}
    if(state.summary){found=true;rows.push({width,path,...state});assert(state.visible&&state.covered===0,'NATYCHMIASTOWY_FOKUS: '+JSON.stringify(rows.at(-1)));}
   }
   assert(found&&complete,'PELNY_TAB_BEZ_CZEKANIA');
  }
  return rows;
 } finally {await cdp.send('Page.setFontSizes',{fontSizes:{standard:16,fixed:16}});await cdp.detach();}
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
     return {hit:hit?.outerHTML.slice(0,200),panel:panel?.toJSON(),withinPanel:!!panel&&r.right<=panel.right&&r.left>=panel.left,rect:r.toJSON(),text:e.textContent,inside:!!e.closest('.szybki-wyglad-panel'),tag:e.tagName,id:e.id,close:e.hasAttribute('data-wyglad-zamknij'),
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
export async function sprawdzFokusProfilu(page, out) {
 await page.evaluate(()=>{window.scrollTo(0,0);document.activeElement?.blur();});
 let avatar=false;
 for(let i=0;i<120;i++) {
  await page.keyboard.press('Tab');
  if(await page.locator('.profil-awatar-zmiana').evaluate(e=>e===document.activeElement)){avatar=true;break;}
 }
 assert(avatar,'PROFILE_AVATAR_TAB');await page.waitForTimeout(100);
 const state=await page.locator('.profil-awatar-zmiana').evaluate(e=>{
  const r=e.getBoundingClientRect(), w=document.querySelector('[data-szybki-wyglad] summary').getBoundingClientRect();
  const h=document.querySelector('.topbar'), hs=getComputedStyle(h);
  const top=['fixed','sticky'].includes(hs.position)?h.getBoundingClientRect().bottom:0;
  // Całe odcinki czterech krawędzi, nie tylko ich środki.
  const gap=3.5,left=r.left-gap,right=r.right+gap,upper=r.top-gap,lower=r.bottom+gap;
  const overlap=(a,b,c,d)=>a<d&&b>c;
  const edges=[upper>=w.top&&upper<=w.bottom&&overlap(left,right,w.left,w.right),lower>=w.top&&lower<=w.bottom&&overlap(left,right,w.left,w.right),left>=w.left&&left<=w.right&&overlap(upper,lower,w.top,w.bottom),right>=w.left&&right<=w.right&&overlap(upper,lower,w.top,w.bottom)];
  return {width:innerWidth,height:innerHeight,dpr:devicePixelRatio,scrollY,rect:r.toJSON(),widget:w.toJSON(),top,edges,flow:document.querySelector('[data-szybki-wyglad]').hasAttribute('data-wyglad-w-przeplywie')};
 });
 await page.screenshot({path:out+'-avatar.png'});
 writeFileSync(out+'-avatar.json',JSON.stringify(state,null,2));
 assert(state.rect.top-3.5>state.top,'PROFILE_RING_UNDER_HEADER');
 assert(state.edges.every(v=>!v),'PROFILE_WIDGET_OVER_RING');
 assert(state.flow,'PROFILE_FLOW_FALLBACK');
 await page.locator('[data-szybki-wyglad] summary').click();
 assert(await page.locator('[data-szybki-wyglad]').evaluate(e=>e.open),'PROFILE_FLOW_POINTER_OPEN');
 await page.keyboard.press('Escape');
 let back=false;
 for(let i=0;i<120;i++){await page.keyboard.press('Shift+Tab');if(await page.locator('.profil-awatar-zmiana').evaluate(e=>e===document.activeElement)){back=true;break;}}
 assert(back,'PROFILE_REVERSE_TAB');await page.waitForTimeout(100);
 await page.evaluate(()=>window.scrollTo(0,700));await page.waitForTimeout(100);
 assert(await page.locator('[data-szybki-wyglad]').evaluate(e=>getComputedStyle(e).position==='fixed'&&!e.hasAttribute('data-wyglad-w-przeplywie')),'PROFILE_SCROLL_RESTORE');
 let reached=false;
 for(let i=0;i<120;i++){await page.keyboard.press('Tab');if(await page.locator('[data-szybki-wyglad] summary').evaluate(e=>e===document.activeElement)){reached=true;break;}}
 assert(reached,'PROFILE_WIDGET_TAB');
 assert(await page.locator('[data-szybki-wyglad]').evaluate(e=>getComputedStyle(e).position==='fixed'),'PROFILE_WIDGET_FIXED');
 await page.keyboard.press('Enter');assert(await page.locator('[data-szybki-wyglad]').evaluate(e=>e.open),'PROFILE_WIDGET_ENTER');
 await page.keyboard.press('Escape');assert(await page.locator('[data-szybki-wyglad] summary').evaluate(e=>e===document.activeElement&&!e.parentElement.open),'PROFILE_WIDGET_ESCAPE');
 await page.waitForTimeout(150);
 const returned=await page.locator('[data-szybki-wyglad] summary').evaluate(e=>{const r=e.getBoundingClientRect(),hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return {rect:r.toJSON(),visible:r.top>=0&&r.bottom<=innerHeight,hit:hit===e||e.contains(hit),scrollY,max:document.documentElement.scrollHeight-innerHeight};});
 writeFileSync(out+'-widget.json',JSON.stringify(returned,null,2));
 assert(returned.visible&&returned.hit,'PROFILE_WIDGET_VISIBLE_RETURN');
 const capture=await page.context().newCDPSession(page);
 try {const shot=await capture.send('Page.captureScreenshot',{format:'png',fromSurface:true,captureBeyondViewport:false});writeFileSync(out+'-widget.png',Buffer.from(shot.data,'base64'));}
 finally {await capture.detach();}
 console.log('PROFILE_FOCUS_AND_WIDGET_PASS '+out);
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

// Prawdziwa odpowiedź walidacji, przewijana bez fokusowania komunikatu.
export async function sprawdzBladPodWygladem({browser,adres}) {
 const page=await browser.newPage({viewport:{width:720,height:456}});
 try {
  await page.goto(adres+'/login');
  // Czcionka webowa przesuwa `getBoundingClientRect()` błędu i widżetu —
  // ten sam powód, dla którego czeka na nią `scripts/pasek-przewijany.mjs`.
  // Bez tego pomiar delty niżej bywa zrobiony na tymczasowym, nieostatecznym
  // układzie.
  await page.evaluate(()=>document.fonts.ready);
  await page.locator('input[name=login]').fill('nieistniejacy-odbior-wygladu@example.test');
  await page.locator('input[name=password]').fill('nieprawidlowe-haslo');
  await page.getByRole('button',{name:'Zaloguj się',exact:true}).click();
  await page.locator('.field-error').first().waitFor();
  await page.waitForFunction(()=>document.querySelector('[data-szybki-wyglad]')?.dataset.wygladGotowy==='1');
  await page.evaluate(()=>{
   document.documentElement.dataset.textScale='140';
   document.querySelector('[data-wyglad-podpowiedz]').hidden=true;
  });
  await page.locator('input[name=login]').focus();
  await page.evaluate(()=>{
   const error=document.querySelector('.field-error').getBoundingClientRect();
   const widget=document.querySelector('[data-szybki-wyglad] summary').getBoundingClientRect();
   window.scrollBy(0,error.top-widget.top);
  });
  // NIE czekamy na atrybut `data-wyglad-w-przeplywie`. Zmierzone na żywo na
  // tym samym /login, 20.09.2026: scrollBy realnie przesuwa stronę
  // (np. 445 px → 0 px, przycięte do góry dokumentu),
  // `scroll` faktycznie leci, `geometry()` w `resources/js/szybki-wyglad.js`
  // faktycznie się wykonuje — a mimo to atrybut bywa ustawiony w jednym
  // przebiegu i nie ustawiony w kolejnym (3 uruchomienia pod rząd:
  // FAIL 30,8 s / PASS / FAIL 30,8 s), bo `geometry()` ustawia go WYŁĄCZNIE
  // gdy w danej klatce realnie wykryje zasłonięcie — to jest wynik, nie
  // warunek wstępny testu. Czekanie na ten atrybut testowało więc
  // implementację, nie zachowanie, i było źródłem timeoutu.
  // Zamiast tego czekamy dwie klatki animacji — `scroll` po programowym
  // `scrollBy` bywa dostarczany asynchronicznie, więc potrzeba co najmniej
  // jednej klatki, by `geometry()` zdążyła przeliczyć layout — a potem
  // mierzymy PRAWDZIWE zasłonięcie bezpośrednio. To wciąż twarda asercja na
  // mechanizm: jeśli błąd naprawdę chowa się pod widżetem, `overlaps` niżej
  // będzie `true` i test poczerwienieje, tak jak przed tą zmianą.
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  const overlaps=await page.evaluate(()=>{
   const w=document.querySelector('[data-szybki-wyglad] summary').getBoundingClientRect();
   return [...document.querySelectorAll('.field-error')].some(e=>[...e.getClientRects()].some(r=>r.right>w.left&&r.left<w.right&&r.bottom>w.top&&r.top<w.bottom));
  });
  assert(!overlaps,'WYGLAD_ZASLANIA_BLAD');
  await page.locator('[data-szybki-wyglad] summary').click();
  assert(await page.locator('[data-szybki-wyglad]').evaluate(e=>e.open),'WYGLAD_PO_BLEDZIE_OTWIERA_SIE');
 } finally {await page.close();}
}

/*
 * REGRESJA #684 — podpowiedź „Wygląd" nie może zablokować sterowania wskaźnikowi.
 *
 * CO SIĘ PSUŁO
 * `aside.szybki-wyglad-podpowiedz` stoi `position: fixed` nad treścią. Przy
 * NISKIM oknie (np. 320×512, 390×520 — telefon w poziomie, małe okno na
 * pulpicie) siadała po przewinięciu na dół dokładnie na przełączniku motywu
 * w stopce: przykrycie 3536 px², wszystkie DZIEWIĘĆ punktów próbnych trafiało
 * w podpowiedź, nie w przycisk. Ani kliknięcie, ani dotknięcie nie dochodziło.
 *
 * DLACZEGO KLAWIATURA TEGO NIE MIAŁA
 * Uchwyt `focusin` w `resources/js/szybki-wyglad.js` chowa podpowiedź, gdy
 * fokus trafi poza widget. Tab więc działał. Mysz i dotyk nie wywołują
 * `focusin` na przykrytym elemencie, więc nie dostawały niczego.
 *
 * CZEGO TEN TEST PILNUJE
 * Że `wheel` (kółko) oraz `touchstart`/`pointerdown` poza samą podpowiedzią
 * chowają ją tak samo, jak robi to `focusin`. To jest cała poprawka — nie
 * sprawdzamy tu geometrii ani motywu, bo te mają własne przebiegi wyżej.
 *
 * PUŁAPKA POMIARU, KTÓRA KOSZTOWAŁA GODZINĘ (docs/PULAPKI_TESTOW.md)
 * Przy 320×512 podpowiedź zajmuje ~296×156 px w dolnej części okna. Gest
 * rozpoczęty od 75% wysokości startuje NA NIEJ, więc `hint.contains(target)`
 * jest prawdziwe i podpowiedź słusznie zostaje. Wygląda to jak niedziałająca
 * poprawka, a jest źle wycelowanym palcem. Gest musi omijać podpowiedź.
 *
 * TA SAMA PUŁAPKA, DRUGI RAZ — UŁAMEK WYSOKOŚCI TO NIE JEST POMIAR (#684)
 * Pierwotna wersja startowała palec od 0,45·h i zakładała, że „górna połowa
 * okna" leży poza podpowiedzią. To założenie jest fałszywe przy niskim oknie.
 * Podpowiedź jest zakotwiczona do DOLNEJ krawędzi (`bottom: 76px`) i ma stałą
 * wysokość ~156,3 px, więc jej górna krawędź to zawsze `h − 232,3` — a nie
 * jakiś procent wysokości. Zmierzone na `/` (Chromium, gość):
 *   320×512 → podpowiedź 279,7–436,0; punkt 0,45·h = 230 leży 49,7 px NAD nią,
 *   320×420 → podpowiedź 187,7–344,0; punkt 0,45·h = 189 wpada 1,3 px W NIĄ.
 * Warunek `0,45·h < h − 232,3` spełnia się dopiero powyżej ~422 px wysokości,
 * więc okno 320×420 przewracało się z definicji, a nie przez wadę układu:
 * przykrycie jest przy obu oknach identyczne co do piksela (296×156,3 px,
 * 76 px nad dolną krawędzią, te same elementy strony pod spodem).
 * Dlatego punkt startu liczymy z FAKTYCZNEGO prostokąta podpowiedzi i jawnie
 * sprawdzamy, że `elementFromPoint` nie zwraca z niej niczego. To nie jest
 * poluzowanie strażnika: dotąd „poza podpowiedzią" było życzeniem autora
 * testu, teraz jest zmierzone przy każdym oknie i każdym sposobie.
 */
export async function sprawdzPodpowiedzUstepujeWskaznikowi({browser:b,adres,out}) {
  const OKNA = [{w:320,h:512},{w:320,h:420},{w:384,h:512},{w:390,h:520}];
  const wiersze = [];
  for (const o of OKNA) {
    for (const sposob of ['kolko','dotyk']) {
      const ctx = await b.newContext({viewport:{width:o.w,height:o.h},hasTouch:sposob==='dotyk',serviceWorkers:'block'});
      const p = await ctx.newPage();
      await p.goto(adres+'/');
      await p.locator('[data-wyglad-podpowiedz]').waitFor({state:'visible'});
      assert(!await p.evaluate(()=>document.querySelector('[data-wyglad-podpowiedz]').hidden),'PODPOWIEDZ_MA_BYC_WIDOCZNA');

      if (sposob === 'kolko') {
        await p.mouse.wheel(0,600);
      } else {
        // Prawdziwe przeciągnięcie palcem, celowo POZA podpowiedzią. Punkt
        // startu bierzemy z jej zmierzonego prostokąta, nie z ułamka wysokości
        // okna — przy 320×420 ułamek 0,45 wpadał 1,3 px w podpowiedź, bo jej
        // górna krawędź to `h − 232,3`, a nie procent wysokości (patrz nagłówek).
        const cdp = await ctx.newCDPSession(p);
        const x = Math.round(o.w/2);
        const gornaKrawedz = await p.evaluate(()=>document.querySelector('[data-wyglad-podpowiedz]').getBoundingClientRect().top);
        const dol = Math.round(o.h*0.05);
        const gora = Math.min(Math.round(o.h*0.45), Math.floor(gornaKrawedz) - 12);
        // Dwie asercje zamiast założenia: palec startuje NAPRAWDĘ poza
        // podpowiedzią i gest zostaje prawdziwym przeciągnięciem, a nie
        // muśnięciem, którego przeglądarka mogłaby nie policzyć za przewijanie.
        assert(gora - dol >= 48,`GEST_ZA_KROTKI ${o.w}x${o.h} ${sposob} start=${gora} koniec=${dol}`);
        assert(await p.evaluate(([x,y])=>{
          const el = document.elementFromPoint(x,y);
          return !!el && !document.querySelector('[data-wyglad-podpowiedz]').contains(el);
        },[x,gora]),`PUNKT_GESTU_W_PODPOWIEDZI ${o.w}x${o.h} ${sposob} y=${gora} gora_podpowiedzi=${gornaKrawedz}`);
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y:gora}]});
        for (let k=1;k<=5;k++) await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:Math.round(gora-(gora-dol)*k/5)}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
      }
      await p.waitForTimeout(150);
      const ukryta = await p.evaluate(()=>document.querySelector('[data-wyglad-podpowiedz]').hidden);
      assert(ukryta,`PODPOWIEDZ_NIE_USTAPILA ${o.w}x${o.h} ${sposob}`);

      // Kontrola granicy: to NIE jest „Rozumiem". Po odświeżeniu podpowiedź
      // ma wrócić, bo nie zapisaliśmy niczego w localStorage.
      await p.reload();
      await p.locator('[data-wyglad-podpowiedz]').waitFor({state:'visible'});
      assert(!await p.evaluate(()=>document.querySelector('[data-wyglad-podpowiedz]').hidden),`PODPOWIEDZ_MIALA_WROCIC ${o.w}x${o.h} ${sposob}`);

      wiersze.push({okno:`${o.w}x${o.h}`,sposob,ustapila:true,wrocilaPoOdswiezeniu:true});
      await ctx.close();
    }
  }
  // Kontrola dodatnia dla samej podpowiedzi: dotknięcie JEJ nie chowa jej
  // po cichu — od tego jest przycisk „Rozumiem".
  const ctx = await b.newContext({viewport:{width:320,height:512},hasTouch:true,serviceWorkers:'block'});
  const p = await ctx.newPage();
  await p.goto(adres+'/');
  await p.locator('[data-wyglad-podpowiedz]').waitFor({state:'visible'});
  const box = await p.locator('[data-wyglad-podpowiedz] p').boundingBox();
  await p.touchscreen.tap(Math.round(box.x+box.width/2),Math.round(box.y+box.height/2));
  await p.waitForTimeout(150);
  assert(!await p.evaluate(()=>document.querySelector('[data-wyglad-podpowiedz]').hidden),'DOTKNIECIE_PODPOWIEDZI_NIE_CHOWA_JEJ');
  await ctx.close();

  writeFileSync(out+'/podpowiedz-ustepuje-684.json',JSON.stringify(wiersze,null,2));
  console.log(`#684: podpowiedź ustępuje wskaźnikowi w ${wiersze.length}/${wiersze.length} konfiguracjach`);
}
