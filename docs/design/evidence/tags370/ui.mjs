import {chromium} from '/home/mateusz/kuking-370-browser/node_modules/playwright/index.mjs';
import fs from 'node:fs';import assert from 'node:assert/strict';
const out='/mnt/c/Users/matma/Documents/Codex/kuking-370/output/browser370',base='http://127.0.0.1:8071',rows=[];
const b=await chromium.launch({headless:true});
try{for(const theme of ['light','dark'])for(const scale of ['100','140']){
 const c=await b.newContext({viewport:{width:1440,height:1100},serviceWorkers:'block'});await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());const p=await c.newPage();
 await p.goto(base+'/tagi');const dismiss=p.getByRole('button',{name:'Rozumiem',exact:true});if(await dismiss.isVisible())await dismiss.click();
 await p.locator('[data-szybki-wyglad] summary').click();await p.locator('#szybka-skala').selectOption(scale);await p.locator('#szybki-motyw').selectOption(theme);const save=p.getByRole('button',{name:'Zapisz wygląd',exact:true});if(await save.isVisible())await save.click();
 await p.goto(base+'/tagi');await p.waitForFunction(({theme,scale})=>(document.documentElement.dataset.theme||'light')===theme&&(document.documentElement.dataset.textScale||'100')===scale,{theme,scale});
 await c.storageState({path:`/home/mateusz/kuking-370-browser/.state-${theme}-${scale}.json`});
 for(const width of [320,1440,360,390,414,768])for(const n of ['index',0,1,2,3,4,5]){
 await p.setViewportSize({width,height:1100});await p.goto(base+(n==='index'?'/tagi':'/tag/odbior-kolaz-'+n));
 const target=p.locator(n==='index'?'.tag-featured':'.tag-welcome');await target.scrollIntoViewIfNeeded();
 await p.locator('[data-tag-collage] img').evaluateAll(async imgs=>{await Promise.all(imgs.map(i=>i.decode().catch(()=>{})))});
 const m=await p.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme||'light',scale:document.documentElement.dataset.textScale||'100',images:[...document.querySelectorAll('[data-tag-collage] img')].map(i=>({loaded:i.complete&&i.naturalWidth>0,width:i.getBoundingClientRect().width,height:i.getBoundingClientRect().height})),links:[...document.querySelectorAll('.tag-welcome [data-tag-collage] a')].map(e=>({width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height}))}));
 assert.equal(m.theme,theme);assert.equal(m.scale,scale);assert(m.images.every(i=>i.loaded),'IMAGE_NOT_LOADED');if(n!=='index')assert.equal(m.images.length,n);assert(!await p.getByText('NIEWIDOCZNA TRESC ODBIOR370').count());
 const shot=(n==='index'&&[320,1440].includes(width))||(n!=='index'&&((width===320&&scale==='140')||(width===1440&&scale==='100')));
 if(shot)await target.screenshot({path:`${out}/${theme}-${scale}-${width}-${n}.png`});
 const ratios=await p.locator('[data-tag-collage]').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {width:r.width,height:r.height}}));
 assert(m.scroll<=m.width, 'HORIZONTAL_OVERFLOW');assert(m.links.every(l=>l.width>=48&&l.height>=48),'SMALL_TARGET');assert(ratios.every(r=>Math.abs(r.height-r.width*3/4)<=1),'COLLAGE_ASPECT_RATIO');
 rows.push({ratios,page:n,...m,overflow:m.scroll>m.width,smallTargets:m.links.filter(l=>l.width<48||l.height<48).length});
 }
 await c.close();fs.writeFileSync(out+'/ui-results.json',JSON.stringify(rows,null,2));console.log(theme,scale,'completed',rows.length);
}}finally{await b.close();}
