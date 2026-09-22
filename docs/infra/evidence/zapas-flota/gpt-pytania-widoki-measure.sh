#!/bin/bash
set -e
cd /mnt/c/Users/matma/Documents/kuking-flota/gpt-pytania-widoki
CLI=/mnt/c/Users/matma/.codex/skills/playwright/scripts/playwright_cli.sh
bash "$CLI" -s=pytania834 eval '({width:innerWidth,scroll:document.documentElement.scrollWidth,zoom:getComputedStyle(document.documentElement).zoom})'
bash "$CLI" -s=pytania834 eval 'document.documentElement.style.zoom="1"'
bash "$CLI" -s=pytania834 resize 320 900
bash "$CLI" -s=pytania834 eval '({width:innerWidth,scroll:document.documentElement.scrollWidth,titles:[...document.querySelectorAll(".powiadomienie-tresc .block")].map(x=>({font:getComputedStyle(x).fontSize,width:x.getBoundingClientRect().width,scroll:x.scrollWidth}))})'
bash "$CLI" -s=pytania834 console error
