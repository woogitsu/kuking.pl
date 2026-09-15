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
writeFileSync(out+'/wyniki.json',JSON.stringify({functional:'guest persistence, reset, 429 rollback, hint, Escape PASS',rows},null,2));await p.close();console.log('36 geometrii + zapis gościa/reset/429/Escape PASS');
}finally{}
}
