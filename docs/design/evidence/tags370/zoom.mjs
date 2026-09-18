import {chromium} from '/home/mateusz/kuking-370-browser/node_modules/playwright/index.mjs';
import {mkdtempSync,mkdirSync,writeFileSync} from 'node:fs';import {tmpdir} from 'node:os';import assert from 'node:assert/strict';
const out='/mnt/c/Users/matma/Documents/Codex/kuking-370/output/browser370',base='http://127.0.0.1:8071',rows=[];
const temp=mkdtempSync(tmpdir()+'/kuking370-zoom-');mkdirSync(temp+'/extension');
writeFileSync(temp+'/extension/manifest.json',JSON.stringify({manifest_version:3,name:'Zoom370',version:'1.0',permissions:['tabs'],background:{service_worker:'worker.js'}}));writeFileSync(temp+'/extension/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
for(const theme of ['light','dark']){
 const c=await chromium.launchPersistentContext(temp+'/'+theme,{executablePath:chromium.executablePath(),headless:true,viewport:{width:640,height:1800},args:['--disable-extensions-except='+temp+'/extension','--load-extension='+temp+'/extension']});
 try{
 const worker=c.serviceWorkers().find(w=>w.url().startsWith('chrome-extension://'))||await c.waitForEvent('serviceworker');const p=await c.newPage();await p.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
 await p.goto(base+'/tagi');const dismiss=p.getByRole('button',{name:'Rozumiem',exact:true});if(await dismiss.isVisible())await dismiss.click();
 await p.locator('[data-szybki-wyglad] summary').click();await p.locator('#szybka-skala').selectOption('140');await p.locator('#szybki-motyw').selectOption(theme);const save=p.getByRole('button',{name:'Zapisz wygląd',exact:true});if(await save.isVisible())await save.click();await p.waitForFunction(t=>document.documentElement.dataset.textScale==='140'&&(document.documentElement.dataset.theme||'light')===t,theme);
 await worker.evaluate(async url=>{const tab=(await chrome.tabs.query({})).find(t=>t.url===url);await chrome.tabs.setZoom(tab.id,2);},p.url());
 for(const [name,path] of [['index','/tagi'],...Array.from({length:6},(_,n)=>['count'+n,'/tag/odbior-kolaz-'+n])]){
  await p.goto(base+path);await p.locator('h1').scrollIntoViewIfNeeded();
  const zoom=await worker.evaluate(async url=>{const tab=(await chrome.tabs.query({})).find(t=>t.url===url);return chrome.tabs.getZoom(tab.id);},p.url());assert.equal(zoom,2);
  const m=await p.evaluate(()=>({width:innerWidth,height:innerHeight,dpr:devicePixelRatio,scroll:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme||'light',scale:document.documentElement.dataset.textScale}));assert.equal(m.width,320);assert.equal(m.dpr,2);assert.equal(m.theme,theme);assert.equal(m.scale,'140');assert(m.scroll<=m.width);
  const cdp=await c.newCDPSession(p);const shot=await cdp.send('Page.captureScreenshot',{format:'png',fromSurface:true,captureBeyondViewport:false});writeFileSync(`${out}/zoom-${theme}-${name}.png`,Buffer.from(shot.data,'base64'));await cdp.detach();rows.push({theme,page:name,zoom,...m});
 }
 }finally{await c.close();}
}
writeFileSync(out+'/zoom-results.json',JSON.stringify(rows,null,2));console.log('PASS14 actual zoom2 text140');
