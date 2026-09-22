import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = 'http://127.0.0.1:18776';
const browser = await chromium.launch({ headless: true });
const results = [];
mkdirSync('output/playwright/2fa', { recursive: true });
try {
  for (const javascript of [true, false]) {
    execFileSync('php', ['scripts/fixtures/ustawienia-2fa.php'], { stdio: 'pipe' });
    const context = await browser.newContext({ javaScriptEnabled: javascript, viewport: { width: 320, height: 800 } });
    const page = await context.newPage();
    await page.goto(base + '/login');
    await page.locator('input[name=login]').fill('pomiar2fa@example.test');
    await page.locator('input[name=password]').fill('haslo-lokalnego-pomiaru');
    await page.locator('form').filter({ has: page.locator('input[name=login]') }).locator('button[type=submit]').click();
    await page.waitForURL('**/logowanie/kod');
    await page.getByText('Nie mam dostępu do telefonu', { exact: true }).click();
    await page.locator('input[name=backup_code]').fill('TEST-TEST');
    await page.getByRole('button', { name: 'Zaloguj się kodem zapasowym' }).click();
    await page.waitForURL('**/home');
    if (javascript && await page.getByRole('button', { name: 'Rozumiem', exact: true }).isVisible()) await page.getByRole('button', { name: 'Rozumiem', exact: true }).click();
    for (const operation of ['regenerate', 'disable']) {
      await page.goto(base + '/ustawienia/2fa');
      const field = page.locator('#f-password-' + operation);
      const details = field.locator('xpath=ancestor::details');
      await details.locator('summary').focus();
      await page.keyboard.press('Enter');
      await field.fill('niepoprawne-haslo');
      await field.press('Tab');
      await page.keyboard.press('Enter');
      await page.waitForLoadState('networkidle');
      const summary = details.locator('.error-summary');
      const visible = await summary.isVisible();
      const open = await details.getAttribute('open') !== null;
      const link = summary.locator('a');
      await link.focus();
      await page.keyboard.press('Enter');
      const focusCorrect = await field.evaluate(e => document.activeElement === e);
      const idsUnique = await page.evaluate(() => { const ids = [...document.querySelectorAll('[id]')].map(e => e.id); return ids.length === new Set(ids).size; });
      const blank = await field.inputValue() === '';
      const onlyOneInvalid = await page.locator('input[aria-invalid=true]').count() === 1;
      const noOverflow = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
      const font = await field.evaluate(e => parseFloat(getComputedStyle(e).fontSize));
      const target = await details.locator('button[type=submit]').boundingBox();
      results.push({ javascript, operation, visible, open, focusCorrect, idsUnique, blank, onlyOneInvalid, noOverflow, font, targetHeight: target.height });
      if (javascript && operation === 'regenerate') await page.screenshot({ path: 'output/playwright/2fa/blad-320.png', fullPage: true });
    }
    // Pomiar kodów i druku: sekret zakryty w artefaktach; nie zapisujemy pliku PDF z kodami.
    if (javascript) {
      await page.goto(base + '/ustawienia/2fa');
      await page.getByText('Wygeneruj nowe kody zapasowe', { exact: true }).click();
      await page.locator('#f-password-regenerate').fill('haslo-lokalnego-pomiaru');
      await page.getByRole('button', { name: 'Wygeneruj nowe kody', exact: true }).click();
      await page.waitForURL('**/kody-zapasowe');
      await page.screenshot({ path: 'output/playwright/2fa/kody-320-zakryte.png', fullPage: true, mask: [page.locator('.kod-do-przepisania li')] });
      await page.emulateMedia({ media: 'print' });
      await page.setViewportSize({ width: 794, height: 1123 });
      await page.screenshot({ path: 'output/playwright/2fa/druk-zakryty.png', fullPage: true, mask: [page.locator('.kod-do-przepisania li')] });
    }
    await context.close();
  }
} finally { await browser.close(); }
writeFileSync('output/playwright/2fa/formularze.json', JSON.stringify({ browser: browser.version(), results }, null, 2));
console.log(JSON.stringify(results, null, 2));
if (results.some(r => !r.visible || !r.open || !r.focusCorrect || !r.idsUnique || !r.blank || !r.onlyOneInvalid || !r.noOverflow || r.font < 18 || r.targetHeight < 48)) process.exitCode = 1;