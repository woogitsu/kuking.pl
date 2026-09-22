import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
const urls=JSON.parse(readFileSync('output/browser561-urls.json','utf8'));
const b=await chromium.launch({executablePath:process.env.CHROMIUM_PATH,headless:true});
const results=[];mkdirSync('output/interakcje561',{recursive:true});
try{for(const [mode,url] of Object.entries(urls))for(const width of [320,360,390,414,768,1440])for(const theme of ['light','dark'])for(const scale of [70,100,140]){
 const c=await b.newContext({viewport:{width,height:900},hasTouch:true});const p=await c.newPage();await p.goto(url);
 if(await p.getByRole('button',{name:'Rozumiem',exact:true}).isVisible())await p.getByRole('button',{name:'Rozumiem',exact:true}).click();
 await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});
 await p.waitForFunction(s=>Math.abs(parseFloat(getComputedStyle(document.body).fontSize)-18*s/100)<0.1,scale);
 const pictures=p.locator('main .photo-zoom-media img');assert.equal(await pictures.count(),3);
 const rows=[];
 for(let i=0;i<3;i++){
  const img=pictures.nth(i);await img.scrollIntoViewIfNeeded();await img.evaluate(async e=>{e.loading='eager';await e.decode();});
  const row=await img.evaluate(e=>{const m=e.parentElement,r=m.getBoundingClientRect(),v=e.getBoundingClientRect();return {w:r.width,h:r.height,iw:v.width,ih:v.height,nw:e.naturalWidth,nh:e.naturalHeight,fit:getComputedStyle(e).objectFit,mobile:matchMedia('(max-width:30rem)').matches};});
  assert(row.nw>0&&row.nh>0);
  if(mode==='carousel'||mode==='collage'&&!row.mobile){assert(Math.abs(row.w-row.h)<2,JSON.stringify({mode,width,scale,row}));assert.equal(row.fit,'contain');}
  else assert(Math.abs(row.iw/row.ih-row.nw/row.nh)<0.02,JSON.stringify({mode,width,scale,row}));
  rows.push(row);
 }
 if(mode==='carousel'){
  await p.goto(url);await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});
  const slides=p.locator('.karuzela-slajd');
  for(const [from,to,label,touch] of [[0,1,'Następne zdjęcie',false],[1,2,'Następne zdjęcie',true],[2,1,'Poprzednie zdjęcie',false],[1,0,'Poprzednie zdjęcie',true]]){
   const control=slides.nth(from).getByRole('link',{name:label,exact:true});if(touch)await control.tap();else await control.click();
   await p.waitForFunction(n=>{const track=document.querySelector('.karuzela-tasma').getBoundingClientRect(),s=document.querySelectorAll('.karuzela-slajd')[n].getBoundingClientRect();return Math.abs(track.left-s.left)<4;},to);
  }
 }
 await pictures.first().scrollIntoViewIfNeeded();const box=await pictures.first().boundingBox();await p.mouse.click(box.x+box.width/2,Math.min(box.y+box.height/2,850));
 assert(await p.locator('#powiekszenie').evaluate(e=>e.open),'Kliknięcie nie otworzyło podglądu');await p.keyboard.press('Escape');
 await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(100);
 assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 if([320,1440].includes(width)&&scale===140)await p.screenshot({path:`output/interakcje561/${mode}-${width}-${theme}.png`,fullPage:true});
 results.push({mode,width,theme,scale,photos:rows,actions:mode==='carousel'?'next next previous previous; mouse/touch':'mouse zoom'});
 writeFileSync('output/interakcje561.json',JSON.stringify(results,null,2));console.log('PASS',mode,width,theme,scale);await c.close();
}}finally{await b.close();}
