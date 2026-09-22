import {chromium} from '/home/mateusz/kuking-370-browser/node_modules/playwright/index.mjs';
import fs from 'node:fs';import assert from 'node:assert/strict';
const b=await chromium.launch({headless:true}),results=[];
try{for(const theme of ['light','dark'])for(const width of [320,1440]){
const c=await b.newContext({viewport:{width,height:900},storageState:'/home/mateusz/kuking-370-browser/.state-'+theme+'-140.json'});const p=await c.newPage();await p.route('**/*',r=>new URL(r.request().url()).origin==='http://127.0.0.1:8071'?r.continue():r.abort());
await p.goto('http://127.0.0.1:8071/tag/odbior-kolaz-5');const seen=[];
for(let i=0;i<70&&seen.length<5;i++){await p.keyboard.press('Tab');const m=await p.evaluate(()=>{const e=document.activeElement;if(!e.matches('.tag-welcome [data-tag-collage] a'))return null;const r=e.getBoundingClientRect(),s=getComputedStyle(e);return {href:e.href,outline:s.outlineWidth,visible:e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)),y:r.y,bottom:r.bottom,height:innerHeight};});if(m){assert(m.visible,'FOCUS_CENTER_OBSCURED');assert(parseFloat(m.outline)>0,'NO_FOCUS');assert(m.y>=0&&m.bottom<=m.height,'FOCUS_OUTSIDE_VIEWPORT');seen.push(m);}}
assert.equal(seen.length,5);await p.keyboard.press('Enter');await p.waitForURL(seen[4].href);await p.getByText(/Lokalne gotowanie numer/).first().waitFor();assert(await p.locator('.post-card img').count()>0,'POST_PHOTO_MISSING');results.push({theme,width,links:seen,destination:p.url()});await c.close();}
}finally{await b.close();}fs.writeFileSync('/mnt/c/Users/matma/Documents/Codex/kuking-370/output/browser370/actions-results.json',JSON.stringify(results,null,2));console.log('PASS 4 keyboard scenes, 20 photo links, Enter destinations');
