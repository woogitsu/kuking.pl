import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
export async function sprawdzPasek({browser:b,adres,out='output/pasek'}) {
mkdirSync(out,{recursive:true});
const results=[];
try {
for(const width of [320,360,390,414,768,1440])for(const theme of ['light','dark'])for(const scale of [100,140]){
 const p=await b.newPage({viewport:{width,height:900},serviceWorkers:'block'});
 await p.goto(adres);await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});
 await p.evaluate(()=>document.fonts.ready);const bar=p.locator('[data-pasek-przewijany]');await bar.waitFor();
 await p.evaluate(()=>scrollTo(0,1000));await p.waitForTimeout(300);
 const sticky=await bar.evaluate(e=>getComputedStyle(e).position==='sticky');
 if(sticky){assert((await bar.boundingBox()).y+(await bar.boundingBox()).height<=0,'pasek powinien być poza ekranem');}
 await p.evaluate(()=>scrollBy(0,-90));await p.waitForTimeout(300);
 if(sticky)assert((await bar.boundingBox()).y>=0,'powrót w górę');
 await p.evaluate(()=>scrollBy(0,100));await p.waitForTimeout(300);
 await bar.locator('a').first().focus();await p.keyboard.press('Tab');
 assert(await bar.evaluate(e=>e.contains(document.activeElement)));assert(!await bar.evaluate(e=>e.hasAttribute('data-pasek-schowany')));
 if(sticky)assert((await bar.boundingBox()).y>=0,'fokus widoczny');
 await p.evaluate(()=>document.activeElement.blur());await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(300);assert((await bar.boundingBox()).y>=0);
 assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 if(width===390&&scale===100&&theme==='light'){
  await p.screenshot({path:out+'/widoczny.png'});await p.evaluate(()=>scrollTo(0,1000));await p.waitForTimeout(300);await p.screenshot({path:out+'/schowany.png'});
 }
 results.push({width,theme,scale,sticky,result:'PASS'});await p.close();
}
const p=await b.newPage({reducedMotion:'reduce'});await p.goto(adres);assert(await p.locator('[data-pasek-przewijany]').evaluate(e=>parseFloat(getComputedStyle(e).transitionDuration)<=0.001));await p.close();
writeFileSync(out+'/wyniki.json',JSON.stringify(results,null,2));console.log(results.length,'PASS + reduced motion');
}finally{}
}
