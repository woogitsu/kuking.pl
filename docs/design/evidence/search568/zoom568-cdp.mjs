import {chromium} from 'playwright';
import {mkdtempSync,mkdirSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {sprawdzTab} from '../scripts/zoom-marki.mjs';
const temp=mkdtempSync(tmpdir()+'/kuking-zoom568-');
const ext=temp+'/extension';mkdirSync(ext);
writeFileSync(ext+'/manifest.json',JSON.stringify({manifest_version:3,name:'Pomiar wyszukiwania',version:'1.0',permissions:['tabs'],background:{service_worker:'worker.js'}}));
writeFileSync(ext+'/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
const context=await chromium.launchPersistentContext(temp+'/profile',{executablePath:process.env.CHROMIUM_PATH,channel:'chromium',headless:true,args:['--no-sandbox','--disable-extensions-except='+ext,'--load-extension='+ext]});
const results=[];
const fs = await import('node:fs');
try {
const page=await context.newPage();
await page.goto('http://127.0.0.1:8068/szukaj?q=Kalarepa&ile=200&od_przepisu=200');
await page.getByRole('button',{name:'Rozumiem'}).click();
const worker=context.serviceWorkers()[0]||await context.waitForEvent('serviceworker');
const tab=(await worker.evaluate(()=>chrome.tabs.query({}))).find(t=>t.url?.startsWith('http://127.0.0.1:8068'));
for(const width of [320,768]) for(const theme of ['light','dark']) {
await worker.evaluate(id=>chrome.tabs.setZoom(id,2),tab.id);
await page.setViewportSize({width:width*2,height:1600});
await page.evaluate(theme=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale='140';},theme);
await page.waitForFunction(w=>innerWidth===w,width);
const zoom=await worker.evaluate(id=>chrome.tabs.getZoom(id),tab.id);if(zoom!==2)throw Error('Nieprawdziwy zoom');
await page.evaluate(()=>scrollTo(0,0));
await page.waitForTimeout(350);
await page.screenshot({path:`output/zoom568-${width}-${theme}-top.png`});
await sprawdzTab(page,'/szukaj');
await page.waitForTimeout(350);
console.log('GEOMETRIA',JSON.stringify(await page.evaluate(()=>({scrollY,innerHeight,active:document.activeElement?.outerHTML?.slice(0,250),elements:[...document.querySelectorAll('header,main,h1,form')].map(e=>({tag:e.tagName,cls:e.className,top:e.getBoundingClientRect().top,height:e.getBoundingClientRect().height,position:getComputedStyle(e).position}))}))));
const geometry=await page.evaluate(()=>({width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,font:getComputedStyle(document.body).fontSize}));
if(geometry.overflow)throw Error('Overflow');
await page.screenshot({path:`output/zoom568-${width}-${theme}.png`});
const cdp=await context.newCDPSession(page);
const shot=await cdp.send('Page.captureScreenshot',{format:'png',fromSurface:true,captureBeyondViewport:false});
fs.writeFileSync(`output/zoom568-${width}-${theme}-cdp.png`,Buffer.from(shot.data,'base64'));
await cdp.detach();
results.push({theme,zoom,...geometry});
}
writeFileSync('output/zoom568.json',JSON.stringify(results,null,2));console.log(results);
} finally {await context.close();}
