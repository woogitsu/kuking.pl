import {chromium} from '/home/mateusz/kuking-work560/node_modules/playwright/index.mjs';
import {mkdirSync,writeFileSync,mkdtempSync} from 'node:fs';import assert from 'node:assert/strict';
const out='/mnt/c/Users/matma/Documents/Codex/kuking.pl/output/549';mkdirSync(out,{recursive:true});const results=[];
for(const [width,zoom] of [[320,2],[1440,1]]){
 const ext=mkdtempSync('/home/mateusz/kuking-local/ext549-');writeFileSync(ext+'/manifest.json',JSON.stringify({manifest_version:3,name:'Odbior549',version:'1.0',permissions:['tabs'],background:{service_worker:'worker.js'}}));writeFileSync(ext+'/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
 const c=await chromium.launchPersistentContext(mkdtempSync('/home/mateusz/kuking-local/profile549-'),{viewport:null,serviceWorkers:'block',headless:true,executablePath:'/home/mateusz/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome',args:[`--window-size=${width*zoom},${900*zoom+87}`,'--disable-extensions-except='+ext,'--load-extension='+ext]});
 try{const p=await c.newPage();if(zoom===1)await p.setViewportSize({width,height:900});const base='http://127.0.0.1:8033';await p.goto(base);const worker=c.serviceWorkers()[0]||await c.waitForEvent('serviceworker');const tab=(await worker.evaluate(()=>chrome.tabs.query({}))).find(t=>t.url?.startsWith(base));await worker.evaluate(({id,zoom})=>chrome.tabs.setZoom(id,zoom),{id:tab.id,zoom});
 for(const state of ['pelny','czesc','brak','logowanie'])for(const theme of ['light','dark']){
 const url=base+(state==='logowanie'?'/dodaj/przepis':'/zglos-nielegalna-tresc');const form={_token:'niepoprawny-token-lokalnego-odbioru',body:state==='brak'?'x'.repeat(200001):'Własny opis do lokalnego odbioru komunikatu.'};if(state==='czesc')form.za_duze='x'.repeat(200001);
 const response=await c.request.post(url,{form,headers:{Accept:'text/html'},maxRedirects:0});assert.equal(response.status(),419);const html=await response.text();await p.route(url,route=>route.fulfill({status:419,contentType:'text/html',body:html}));await p.goto(url);await p.unroute(url);
 await p.evaluate(theme=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale='140';},theme);await p.evaluate(async()=>{await document.fonts.ready;for(let i=0;i<30;i++)await new Promise(requestAnimationFrame);});assert.equal(await p.locator('h1').innerText(),'Nie udało się wysłać formularza');const text=await p.locator('main').innerText();assert(!text.includes('otwarty dłużej'));assert(text.includes('Nie mogliśmy potwierdzić tego wysłania.'));assert.equal(await worker.evaluate(id=>chrome.tabs.getZoom(id),tab.id),zoom);assert.equal(await p.evaluate(()=>innerWidth),width);assert(!(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)));
 assert.equal(text.includes('Najpierw zaloguj się jeszcze raz'),state==='logowanie');if(state==='czesc')assert(text.includes('Część Twojego tekstu'));if(state==='brak')assert(text.includes('Nie udało się odzyskać tekstu'));if(state==='pelny')assert(text.includes('Twój tekst jest na miejscu'));
 const cdp=await c.newCDPSession(p);const shot=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(out+`/${state}-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));await cdp.detach();results.push({state,width,zoom,theme,status:419,heading:'Nie udało się wysłać formularza',overflow:false});console.log(state,width,theme,'PASS');
 }
 }finally{await c.close();}
}
writeFileSync(out+'/przegladarka.json',JSON.stringify(results,null,2));


