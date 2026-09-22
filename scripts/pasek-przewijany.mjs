import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';

/* Jeden przebieg macierzy na podanej stronie. Wydzielone z pętli, bo od
   19 września 2026 ta sama macierz idzie w DWÓCH stanach zalogowania: pasek
   chowa się teraz także po zalogowaniu (`layout.blade.php`), a do tamtej pory
   atrybut stał pod `@guest` i mierzyliśmy wyłącznie widok gościa. */
async function przebieg({p,bar,sticky,out,width,theme,scale,kto}) {
 await p.evaluate(()=>scrollTo(0,1000));await p.waitForTimeout(300);
 if(sticky){assert((await bar.boundingBox()).y+(await bar.boundingBox()).height<=0,`pasek powinien być poza ekranem (${kto})`);}
 await p.evaluate(()=>scrollBy(0,-90));await p.waitForTimeout(300);
 if(sticky)assert((await bar.boundingBox()).y>=0,`powrót w górę (${kto})`);
 await p.evaluate(()=>scrollBy(0,100));await p.waitForTimeout(300);
 await bar.locator('a').first().focus();await p.keyboard.press('Tab');
 assert(await bar.evaluate(e=>e.contains(document.activeElement)));assert(!await bar.evaluate(e=>e.hasAttribute('data-pasek-schowany')));
 if(sticky)assert((await bar.boundingBox()).y>=0,`fokus widoczny (${kto})`);
 await p.evaluate(()=>document.activeElement.blur());await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(300);assert((await bar.boundingBox()).y>=0);
 assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 if(width===390&&scale===100&&theme==='light'&&kto==='gość'){
  await p.screenshot({path:out+'/widoczny.png'});await p.evaluate(()=>scrollTo(0,1000));await p.waitForTimeout(300);await p.screenshot({path:out+'/schowany.png'});
 }
}

export async function sprawdzPasek({browser:b,adres,sesja,out='output/pasek'}) {
mkdirSync(out,{recursive:true});
const results=[];
try {
for(const width of [320,360,390,414,768,1440])for(const theme of ['light','dark'])for(const scale of [100,140]){
 const p=await b.newPage({viewport:{width,height:900},serviceWorkers:'block'});
 await p.goto(adres);await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});
 await p.evaluate(()=>document.fonts.ready);const bar=p.locator('[data-pasek-przewijany]');await bar.waitFor();
 const sticky=await bar.evaluate(e=>getComputedStyle(e).position==='sticky');
 await przebieg({p,bar,sticky,out,width,theme,scale,kto:'gość'});
 results.push({width,theme,scale,sticky,kto:'gość',result:'PASS'});await p.close();
}
const p=await b.newPage({reducedMotion:'reduce'});await p.goto(adres);assert(await p.locator('[data-pasek-przewijany]').evaluate(e=>parseFloat(getComputedStyle(e).transitionDuration)<=0.001));await p.close();

/* STAN ZALOGOWANY. Mechanizm (CSS i JS) jest wspólny, więc nie powtarzamy
   pełnych 24 konfiguracji — to kupowałoby minuty CI za tę samą wiedzę.
   Bierzemy trzy szerokości, na których pasek zalogowanej osoby jest
   najciaśniejszy, i dokładamy przypadek, którego u gościa NIE MA:
   otwarte menu konta. Skrypt nie ma prawa schować paska, gdy człowiek
   ma w nim otwarte menu — pilnuje tego `details[open]`
   w `resources/js/pasek-przewijany.js`, a do tej pory nikt tego nie mierzył,
   bo gość menu konta nie ma. */
if(sesja){
 for(const width of [320,390,768])for(const scale of [100,140]){
  const kontekst=await b.newContext({storageState:sesja,viewport:{width,height:900},serviceWorkers:'block'});
  const p=await kontekst.newPage();
  await p.goto(adres);await p.evaluate((scale)=>{document.documentElement.dataset.textScale=String(scale);},scale);
  await p.evaluate(()=>document.fonts.ready);const bar=p.locator('[data-pasek-przewijany]');
  await bar.waitFor();
  const sticky=await bar.evaluate(e=>getComputedStyle(e).position==='sticky');
  await przebieg({p,bar,sticky,out,width,theme:'light',scale,kto:'zalogowana'});
  results.push({width,theme:'light',scale,sticky,kto:'zalogowana',result:'PASS'});
  await kontekst.close();
 }
 const kontekst=await b.newContext({storageState:sesja,viewport:{width:390,height:900},serviceWorkers:'block'});
 const p=await kontekst.newPage();
 await p.goto(adres);await p.evaluate(()=>document.fonts.ready);
 const bar=p.locator('[data-pasek-przewijany]');await bar.waitFor();
 const menu=bar.locator('details.topbar-konto');
 assert(await menu.count()>0,'zalogowany pasek nie ma menu konta — nie ma czego sprawdzać');
 await menu.locator('summary').first().click();
 assert(await menu.first().evaluate(e=>e.open),'menu konta się nie otworzyło');
 await p.evaluate(()=>scrollTo(0,1000));await p.waitForTimeout(400);
 assert(!await bar.evaluate(e=>e.hasAttribute('data-pasek-schowany')),'pasek schował się z otwartym menu konta');
 if(await bar.evaluate(e=>getComputedStyle(e).position==='sticky'))assert((await bar.boundingBox()).y>=0,'pasek z otwartym menu zniknął z ekranu');
 results.push({width:390,theme:'light',scale:100,kto:'zalogowana, otwarte menu konta',result:'PASS'});
 await kontekst.close();
}

writeFileSync(out+'/wyniki.json',JSON.stringify(results,null,2));console.log(results.length,'PASS + reduced motion');
}finally{}
}
