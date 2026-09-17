import {chromium} from 'playwright';
import {mkdtempSync,writeFileSync,mkdirSync} from 'node:fs';
const root='output/zoom644';mkdirSync(root,{recursive:true});
const ext=mkdtempSync('/tmp/kuking644-zoom-ext-');
writeFileSync(ext+'/manifest.json',JSON.stringify({manifest_version:3,name:'Local zoom regression',version:'1.0',permissions:['tabs'],host_permissions:['<all_urls>'],background:{service_worker:'worker.js'}}));
writeFileSync(ext+'/worker.js','chrome.runtime.onInstalled.addListener(()=>{});');
const ctx=await chromium.launchPersistentContext(mkdtempSync('/tmp/kuking644-zoom-profile-'),{executablePath:'/home/mateusz/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome',headless:true,viewport:null,args:['--window-size=640,1800','--no-sandbox','--disable-extensions-except='+ext,'--load-extension='+ext]});
const results=[];
try {
 const worker=ctx.serviceWorkers()[0]||await ctx.waitForEvent('serviceworker');
 const page=await ctx.newPage();
 await page.goto('http://127.0.0.1:8044/login');
 await page.getByRole('textbox',{name:'Adres e-mail albo nazwa użytkownika (wymagane)'}).fill('owner644@example.test');
 await page.getByRole('textbox',{name:'Hasło (wymagane)',exact:true}).fill('haslo-testowe-123');
 await page.getByRole('button',{name:'Zaloguj się',exact:true}).click();await page.waitForURL('**/home');
 const paths={przepis:'/przepisy/zupa-odbior-zeszytu644',wpis:'/wpisy/01a0afc9-2632-7070-b05a-d50b91395cc1'};
 for(const [kind,path] of Object.entries(paths))for(const theme of ['light','dark'])for(const scale of [100,140]) {
  await page.goto('http://127.0.0.1:8044'+path);
  const zoom=await worker.evaluate(async url=>{const t=(await chrome.tabs.query({})).find(t=>t.url===url);await chrome.tabs.setZoom(t.id,2);return chrome.tabs.getZoom(t.id);},page.url());
  await page.evaluate(async ({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);await document.fonts.ready;},{theme,scale});
  const box=page.locator('.wybor-zeszytu');await box.locator('summary').click();
  await box.locator('summary').focus();await page.keyboard.press('Tab');await page.keyboard.press('ArrowDown');await page.keyboard.press('Tab');
  await page.waitForTimeout(200);
  const m=await box.evaluate(e=>{const b=e.querySelector('button[type=submit]');const r=b.getBoundingClientRect();return {width:innerWidth,height:innerHeight,dpr:devicePixelRatio,font:getComputedStyle(document.body).fontSize,overflow:document.documentElement.scrollWidth>innerWidth+1,focus:document.activeElement===b,rect:{x:r.x,y:r.y,right:r.right,bottom:r.bottom},labels:[...e.querySelectorAll('.wybor-zeszytu-opcja span')].map(s=>({client:s.clientWidth,scroll:s.scrollWidth}))};});
  if(zoom!==2||m.width!==320||m.dpr!==2||m.overflow||!m.focus||m.rect.y<0||m.rect.bottom>m.height||m.labels.some(x=>x.scroll>x.client+1))throw Error(JSON.stringify({kind,theme,scale,zoom,...m}));
  await page.waitForTimeout(600); const capture=await worker.evaluate(async url=>{const t=(await chrome.tabs.query({})).find(t=>t.url===url);await chrome.tabs.update(t.id,{active:true});return chrome.tabs.captureVisibleTab(t.windowId,{format:'png'});},page.url()); writeFileSync(root+'/'+kind+'-'+theme+'-'+scale+'.png',Buffer.from(capture.split(',')[1],'base64'));
  results.push({kind,theme,scale,zoom,...m});
 }
 writeFileSync(root+'/results.json',JSON.stringify(results,null,2));console.log(JSON.stringify({passed:results.length,results}));
}finally{await ctx.close();}
