import {chromium} from '/home/mateusz/kuking-370-browser/node_modules/playwright/index.mjs';
import assert from 'node:assert/strict';
const b=await chromium.launch({headless:true});
try {const p=await b.newPage({viewport:{width:1440,height:1100}});await p.goto('http://127.0.0.1:8071/tagi');await p.locator('[data-tag-collage] img').evaluateAll(async es=>await Promise.all(es.map(e=>e.decode())));const sizes=await p.locator('[data-tag-collage]').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {width:r.width,height:r.height}}));console.log(JSON.stringify(sizes));assert.equal(sizes.length,5);assert(sizes.every(r=>Math.abs(r.height-r.width*.75)<=1),'COLLAGE_ASPECT_RATIO');}finally{await b.close();}
