import { chromium } from 'playwright';
const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', headless:true});
const c = await b.newContext({viewport:{width:320,height:800}});
const p = await c.newPage();
await p.goto('http://127.0.0.1:8123/', {waitUntil:'networkidle'});
await p.evaluate(() => { document.documentElement.style.fontSize = '32px'; });
await p.waitForTimeout(300);
console.log(await p.evaluate(() => {
  const win = document.documentElement.clientWidth;
  const zle = [];
  document.querySelectorAll('*').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.right > win + 1 || r.width > win + 1) {
      zle.push({tag: el.tagName, cls: (el.className||'').toString().slice(0,60), right: Math.round(r.right), w: Math.round(r.width)});
    }
  });
  return {win, scrollWidth: document.documentElement.scrollWidth, zle: zle.slice(0, 20)};
}));
await b.close();
