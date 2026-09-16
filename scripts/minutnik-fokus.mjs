// Rzeczywiste znaczniki Blade oraz skompilowany arkusz aplikacji.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { sprawdzTab } from './zoom-marki.mjs';

const blade = readFileSync('resources/views/pages/recipes/cooking.blade.php', 'utf8');
const template = blade.match(/<div class="cook-timer"[\s\S]*?<\/div>/)?.[0];
assert(template, 'Brak znaczników minutnika');
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = readFileSync('public/build/' + manifest['resources/css/app.css'].file, 'utf8');
const html = template.replace(/\{\{--[\s\S]*?--\}\}/g, '')
  .replaceAll('{{ $aktualnyKrok->timer_seconds }}', '3')
  .replaceAll('{{ $timerLabel }}', '3 sekundy');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 320, height: 900 } });
  for (const theme of ['light', 'dark']) {
    await page.setContent(`<html data-theme="${theme}" data-text-scale="140"><body><main>${html}</main></body></html>`);
    await page.addStyleTag({ content: css });
    await page.locator('.cook-timer-start').evaluate(button => button.hidden = false);
    await sprawdzTab(page, 'minutnik-' + theme);
  }
} finally {
  await browser.close();
}
