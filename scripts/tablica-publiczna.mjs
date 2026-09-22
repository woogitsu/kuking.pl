// Pomiar lokalnej tablicy z trzema publicznymi wpisami i gotowymi mediami.
import {chromium} from 'playwright';
const base=process.env.BASE_URL||'http://127.0.0.1:8033/';
import {mkdtempSync,writeFileSync,mkdirSync,rmSync} from 'node:fs';
const out=process.env.OUTPUT_DIR||'output/landing557';mkdirSync(out,{recursive:true});const rows=[];const height=Number(process.env.HEIGHT||900);
for(const width of process.env.QUICK?[1440]:[320,360,390,414,768,1440])for(const zoom of process.env.QUICK?[1]:[1,2]){
 const ext=mkdtempSync('/tmp/landing557-ext-'),profile=mkdtempSync('/tmp/landing557-browser-');
 writeFileSync(ext+'/manifest.json',JSON.stringify({manifest_version:3,name:'Odbior557',version:'1.0',permissions:['tabs'],background:{service_worker:'worker.js'}}));writeFileSync(ext+'/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
 const context=await chromium.launchPersistentContext(profile,{viewport:null,executablePath:process.env.CHROMIUM_PATH,headless:true,args:['--no-sandbox',`--window-size=${width*zoom},${height*zoom+87}`,'--disable-extensions-except='+ext,'--load-extension='+ext]});
 try{const page=await context.newPage();if(zoom===1)await page.setViewportSize({width,height});await page.goto(base);
 const worker=context.serviceWorkers()[0]||await context.waitForEvent('serviceworker');const tab=(await worker.evaluate(()=>chrome.tabs.query({}))).find(t=>t.url===base);await worker.evaluate(({id,zoom})=>chrome.tabs.setZoom(id,zoom),{id:tab.id,zoom});
 for(const theme of ['light','dark'])for(const scale of [100,140]){
  await page.goto(base);await page.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});await page.evaluate(async()=>{await document.fonts.ready;for(let i=0;i<30;i++)await new Promise(requestAnimationFrame);});
  const prepare=async()=>{await page.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});await page.evaluate(async()=>{await document.fonts.ready;for(let i=0;i<30;i++)await new Promise(requestAnimationFrame);});};
  const sec=page.locator('.landing-tablica');for(const img of await sec.locator('img').all()){await img.scrollIntoViewIfNeeded();await page.waitForFunction(i=>i.complete&&i.naturalWidth>0,await img.elementHandle());}
  const geo=await sec.evaluate(s=>{const a=s.querySelector('.kuking-board-posts'),b=s.querySelector('.kuking-board-people');return{viewport:innerWidth,scroll:document.documentElement.scrollWidth,postsBeforePeople:!!(a.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING),cta:s.querySelectorAll('.landing-tablica-zaproszenie a').length,photos:[...s.querySelectorAll('.kuking-board-post-photo img')].map(i=>({width:i.getBoundingClientRect().width,height:i.getBoundingClientRect().height,natural:i.naturalWidth})),links:[...s.querySelectorAll('.kuking-board-post-link')].map(a=>a.href)};});
  if(geo.viewport!==width||geo.scroll>width+1||!geo.postsBeforePeople||geo.cta!==1||geo.links.length!==3||geo.photos.length!==3||geo.photos.some(i=>i.width<200||i.natural<600))throw Error('PUBLICZNA_KOMPOZYCJA '+JSON.stringify(geo));
  if(await worker.evaluate(id=>chrome.tabs.getZoom(id),tab.id)!==zoom)throw Error('ZOOM');
  await page.reload();await prepare();
  const visited=new Set();for(let i=0;i<100&&visited.size<3;i++){await page.keyboard.press('Tab');const f=await page.evaluate(()=>{const a=document.activeElement;if(!a.matches('.landing-tablica .kuking-board-post-link'))return null;const r=a.getBoundingClientRect(),hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return{href:a.href,y:r.y,bottom:r.bottom,screen:innerHeight,hit:hit?.tagName,hitClass:hit?.className,visible:a.matches(':focus-visible')&&r.y>=3&&r.bottom<=innerHeight-3&&(hit===a||a.contains(hit))};});if(f){if(!f.visible)throw Error('FOCUS '+JSON.stringify({width,zoom,theme,scale,...f}));visited.add(f.href);}}
  if(visited.size!==3)throw Error('TAB_NIEKOMPLETNY');
  for(let i=0;i<3;i++){const photo=page.locator('.landing-tablica .kuking-board-post-photo').nth(i);await photo.scrollIntoViewIfNeeded();const box=await photo.boundingBox();await page.mouse.click(box.x+box.width/2,box.y+box.height/2);await page.waitForURL(geo.links[i]);await page.goBack();await prepare();}
  await prepare();for(const img of await sec.locator('img').all()){await img.scrollIntoViewIfNeeded();await page.waitForFunction(i=>i.complete&&i.naturalWidth>0,await img.elementHandle());}
  await sec.evaluate(s=>scrollTo(0,scrollY+s.getBoundingClientRect().y-document.querySelector('header').getBoundingClientRect().height-30));
  const applied=await page.evaluate(()=>({theme:document.documentElement.dataset.theme,scale:document.documentElement.dataset.textScale,body:getComputedStyle(document.body).fontSize,background:getComputedStyle(document.body).backgroundColor}));if(applied.theme!==theme||applied.scale!==String(scale))throw Error('USTAWIENIA');
  if([320,1440].includes(width)){const cdp=await context.newCDPSession(page);const shot=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(out+`/${width}-${zoom}-${theme}-${scale}.png`,Buffer.from(shot.data,'base64'));await cdp.detach();}
  rows.push({width,height,zoom,theme,scale,applied,...geo,tab:visited.size,clicks:3});writeFileSync(out+'/partial.json',JSON.stringify(rows));console.log(width,zoom,theme,scale);
 }
 }finally{await context.close();rmSync(ext,{recursive:true});rmSync(profile,{recursive:true});}
}
writeFileSync(out+'/wyniki.json',JSON.stringify(rows,null,2));console.log('PASS',rows.length);