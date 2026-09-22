#!/bin/bash
set -e
cd /mnt/c/Users/matma/Documents/kuking-flota/gpt-pytania-widoki
CLI=/mnt/c/Users/matma/.codex/skills/playwright/scripts/playwright_cli.sh
bash "$CLI" -s=pytania834 resize 320 900
bash "$CLI" -s=pytania834 snapshot
bash "$CLI" -s=pytania834 run-code 'async (page) => { await page.screenshot({path:"output/playwright/powiadomienia-320.png",fullPage:true}); console.log(await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth,titles:[...document.querySelectorAll(".powiadomienie-tresc .block")].map(x=>({text:x.textContent,font:getComputedStyle(x).fontSize,width:x.getBoundingClientRect().width,scroll:x.scrollWidth}))}))); }'
bash "$CLI" -s=pytania834 resize 640 1200
bash "$CLI" -s=pytania834 run-code 'async (page) => { await page.evaluate(() => {document.documentElement.style.zoom="2";}); await page.screenshot({path:"output/playwright/powiadomienia-zoom-css-200.png",fullPage:true}); console.log(await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth,zoom:getComputedStyle(document.documentElement).zoom}))); }'
