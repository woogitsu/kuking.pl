/* Realny blok app.js i znaczniki Blade; bez bazy, atrap zegara i wyników.
 * Uruchom z katalogu repo: node scripts/minutnik-regresja.mjs.
 * PLAYWRIGHT_MODULE może wskazać istniejący index.mjs zależności poza repo.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE
  ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const source = readFileSync('resources/js/app.js', 'utf8');
const begin = source.indexOf("document.querySelectorAll('.cook-timer').forEach");
const end = source.indexOf('// --- Karuzela zdjęć', begin);
assert(begin >= 0 && end > begin, 'Nie znaleziono rzeczywistego bloku minutnika');
const blade = readFileSync('resources/views/pages/recipes/cooking.blade.php', 'utf8');
const template = blade.match(/<div class="cook-timer"[\s\S]*?<\/div>/)?.[0];
assert(template, 'Nie znaleziono rzeczywistego HTML minutnika');
const html = seconds => template.replace(/\{\{--[\s\S]*?--\}\}/g, '')
  .replaceAll('{{ $aktualnyKrok->timer_seconds }}', String(seconds))
  .replaceAll('{{ $timerLabel }}', `${seconds} sekund`);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH,
  headless: true, args: ['--no-sandbox'] });
const results = [];

async function fixture(durations, run) {
  const page = await browser.newPage();
  try {
    await page.setContent(durations.map(html).join('\n'));
    // Obserwacja końca nie zastępuje działania minutnika ani API dźwięku.
    await page.evaluate(() => {
      window.timerEnds = [];
      document.querySelectorAll('.cook-timer-komunikat').forEach((node, index) => {
        window.timerEnds[index] = 0;
        new MutationObserver(records => {
          if (node.textContent === 'Czas minął!') window.timerEnds[index] += records.length;
        }).observe(node, { childList: true, characterData: true, subtree: true });
      });
    });
    await page.addScriptTag({ content: source.slice(begin, end) });
    await run(page);
  } finally { await page.close(); }
}
const block = (page, index = 0) => page.locator('.cook-timer').nth(index);
const button = (page, index = 0) => block(page, index).locator('.cook-timer-start');
async function remaining(page, index = 0) {
  const value = await block(page, index).locator('.cook-timer-odliczanie').innerText();
  assert.match(value, /^\d+:\d{2}$/, 'Niepoprawny czas: ' + value);
  const [minutes, seconds] = value.split(':').map(Number);
  return minutes * 60 + seconds;
}
async function pause(page, milliseconds) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Debugger.enable');
  const paused = new Promise(resolve => cdp.once('Debugger.paused', resolve));
  await cdp.send('Debugger.pause');
  await paused;
  const start = performance.now();
  try { await sleep(milliseconds); }
  finally { await cdp.send('Debugger.resume'); await cdp.detach(); }
  return performance.now() - start;
}
async function check(name, run) {
  try { await run(); results.push({ name, result: 'PASS' }); }
  catch (error) { results.push({ name, result: 'FAIL', error: error.message }); }
  console.log(JSON.stringify(results.at(-1)));
}

try {
  await check('zwykle_odliczanie_jeden_koniec_restart', () => fixture([2], async page => {
    await button(page).click();
    assert.equal(await remaining(page), 2);
    assert(await button(page).isDisabled());
    await page.waitForTimeout(1150);
    assert.equal(await remaining(page), 1);
    await page.waitForTimeout(1150);
    assert.equal(await remaining(page), 0);
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 1);
    assert(await button(page).isEnabled());
    await page.waitForTimeout(1200);
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 1);
    await button(page).click();
    assert.equal(await remaining(page), 2);
    await page.waitForTimeout(2300);
    assert.equal(await remaining(page), 0);
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 2);
  }));
  await check('pauza_przed_terminem', () => fixture([8], async page => {
    await button(page).click();
    const start = performance.now();
    await page.waitForTimeout(1100);
    const pausedMs = await pause(page, 4100);
    assert(pausedMs >= 4000);
    await page.waitForTimeout(1100);
    const elapsed = performance.now() - start;
    const actual = await remaining(page);
    const expected = Math.max(0, Math.ceil(8 - elapsed / 1000));
    assert(Math.abs(actual - expected) <= 1,
      JSON.stringify({ elapsed, pausedMs, actual, expected }));
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 0);
  }));
  await check('pauza_przez_koniec_jedna_finalizacja', () => fixture([3], async page => {
    await button(page).click();
    const pausedMs = await pause(page, 4100);
    assert(pausedMs >= 4000);
    await page.waitForTimeout(150);
    assert.equal(await remaining(page), 0, 'Pierwszy callback po terminie musi zakończyć minutnik');
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 1);
    assert(await button(page).isEnabled());
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.waitForTimeout(1200);
    assert.equal(await page.evaluate(() => window.timerEnds[0]), 1);
  }));
  await check('niezalezne_minutniki', () => fixture([2, 5], async page => {
    await button(page, 0).click();
    await button(page, 1).click();
    await page.waitForTimeout(2300);
    assert.equal(await remaining(page, 0), 0);
    assert.equal(await remaining(page, 1), 3);
    assert(await button(page, 1).isDisabled());
    await button(page, 0).click();
    await page.waitForTimeout(1200);
    assert.equal(await remaining(page, 0), 1);
    assert.equal(await remaining(page, 1), 2);
    await page.waitForTimeout(2100);
    assert.deepEqual(await page.evaluate(() => window.timerEnds), [2, 1]);
  }));
  await check('aktualny_czas_dostepny_bez_spamu_live', () => fixture([8], async page => {
    await button(page).click();
    await page.waitForTimeout(1150);
    const counter = block(page).locator('.cook-timer-odliczanie');
    const snapshot = await counter.ariaSnapshot();
    assert(snapshot.trim(), 'Aktualny czas nieobecny w drzewie dostępności');
    assert.match(snapshot, /0:07|7\s+sekund/, 'AX nie zawiera aktualnej wartości: ' + snapshot);
    assert.equal(await counter.getAttribute('aria-live'), 'off', 'Odliczanie nie powinno ogłaszać każdej sekundy');
    await page.waitForTimeout(1100);
    assert.match(await counter.ariaSnapshot(), /0:06|6\s+sekund/);
    assert.match(await block(page).locator('.cook-timer-komunikat').innerText(), /Minutnik ustawiony/);
  }));
} finally { await browser.close(); }
console.log(JSON.stringify({ source: 'resources/js/app.js', markup: 'resources/views/pages/recipes/cooking.blade.php', results }, null, 2));
if (results.some(result => result.result !== 'PASS')) process.exitCode = 1;
