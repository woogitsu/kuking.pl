#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-wyszukiwanie-granice-run
cli=/mnt/c/Users/matma/.codex/skills/playwright/scripts/playwright_cli.sh
bash "$cli" -s=kuking-granice click f1e30
bash "$cli" -s=kuking-granice eval '() => { const q=document.getElementById("f-q"); return {length:q.value.length,focus:document.activeElement.id,width:innerWidth,scroll:document.documentElement.scrollWidth,font:getComputedStyle(q).fontSize,errorFont:getComputedStyle(document.getElementById("f-q-error")).fontSize,buttonHeight:document.querySelector("button[type=submit]").getBoundingClientRect().height}; }'
mkdir -p output/playwright
bash "$cli" -s=kuking-granice screenshot --filename=output/playwright/granica-320.png
bash "$cli" -s=kuking-granice resize 640 1000
bash "$cli" -s=kuking-granice eval '() => {document.body.style.zoom="2"; return {width:innerWidth,scroll:document.documentElement.scrollWidth,zoom:getComputedStyle(document.body).zoom};}'
bash "$cli" -s=kuking-granice screenshot --filename=output/playwright/granica-200.png