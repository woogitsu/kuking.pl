import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {mkdtempSync,mkdirSync,writeFileSync,readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {sprawdzTab} from '../scripts/zoom-marki.mjs';
const urls=JSON.parse(readFileSync('output/browser561-urls.json','utf8'));
const temp=mkdtempSync(tmpdir()+'/kuking-zoom561-');
const ext=temp+'/extension';mkdirSync(ext);
writeFileSync(ext+'/manifest.json',JSON.stringify({manifest_version:3,name:'Pomiar zdjęć Kuking',version:'1.0',permissions:['tabs'],background:{service_worker:'worker.js'}}));
writeFileSync(ext+'/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
const context=await chromium.launchPersistentContext(temp+'/profile',{executablePath:process.env.CHROMIUM_PATH,channel:'chromium',headless:true,args:['--no-sandbox','--disable-extensions-except='+ext,'--load-extension='+ext]});
const results=[];
try {
 const page=await context.newPage();
 await page.goto(urls.normal);
 if(await page.getByRole('button',{name:'Rozumiem',exact:true}).isVisible())await page.getByRole('button',{name:'Rozumiem',exact:true}).click();
 const worker=context.serviceWorkers()[0]||await context.waitForEvent('serviceworker');
 const tab=(await worker.evaluate(()=>chrome.tabs.query({}))).find(t=>t.url?.startsWith('http://127.0.0.1:8061'));
 for(const [mode,url] of Object.entries(urls))for(const width of [320,768])for(const theme of ['light','dark']){
  await page.goto(url);await worker.evaluate(id=>chrome.tabs.setZoom(id,2),tab.id);
  await page.setViewportSize({width:width*2,height:1600});
  await page.evaluate(theme=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale='140';},theme);
  await page.waitForFunction(w=>innerWidth===w,width);
  assert.equal(await worker.evaluate(id=>chrome.tabs.getZoom(id),tab.id),2);
  await sprawdzTab(page,'zdjecia561-'+mode);
  // Nowa nawigacja resetuje pozycję Tab; następnie prawdziwe Enter i Escape.
  await page.goto(url);await page.evaluate(theme=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale='140';},theme);
  const seen=new Set();
  for(let i=0;i<100&&seen.size<3;i++){
   await page.keyboard.press('Tab');
   const href=await page.evaluate(()=>document.activeElement.matches('a[data-powieksz]')?document.activeElement.href:null);
   if(!href)continue;
   seen.add(href);await page.keyboard.press('Enter');
   assert(await page.locator('#powiekszenie').evaluate(el=>el.open));
   await page.waitForFunction(()=>{const img=document.querySelector('.lightbox-obraz');return img.complete&&img.naturalWidth>0;},null,{timeout:10000}).catch(async error=>{console.log('IMAGE_FAILURE',await page.locator('.lightbox-obraz').evaluate(img=>({src:img.src,current:img.currentSrc,complete:img.complete,natural:img.naturalWidth})));throw error;});
   await page.keyboard.press('Escape');
   assert.equal(await page.evaluate(()=>document.activeElement.href),href);
  }
  assert.equal(seen.size,3,'Niekompletne zdjęcia');
  const geometry=await page.evaluate(()=>({width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,font:getComputedStyle(document.body).fontSize}));
  assert(!geometry.overflow);
  await page.screenshot({path:`output/zoom561-${mode}-${width}-${theme}.png`});
  results.push({mode,theme,zoom:2,photos:seen.size,...geometry});
  writeFileSync('output/zoom561.json',JSON.stringify(results,null,2));console.log('PASS',mode,width,theme);
 }
}finally{await context.close();}
