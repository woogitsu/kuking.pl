/* Realny blok app.js i znaczniki Blade; bez bazy, atrap zegara i wyników.
 * Uruchom z katalogu repo: node scripts/minutnik-regresja.mjs.
 * PLAYWRIGHT_MODULE może wskazać istniejący index.mjs zależności poza repo.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';
import http from 'node:http';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE
  ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const source = readFileSync('resources/js/app.js', 'utf8');
const begin = source.indexOf("document.querySelectorAll('.cook-timer').forEach");
const end = source.indexOf('// --- Karuzela zdjęć', begin);
assert(begin >= 0 && end > begin, 'Nie znaleziono rzeczywistego bloku minutnika');
// Od przeniesienia arytmetyki minutnika do osobnego modułu ten wycięty
// fragment app.js wywołuje `kluczStanu` (i inne funkcje) z importu, którego
// tu nie ma — wstrzyknięty jako zwykły <script> dostałby ReferenceError
// zanim doszedłby do odkrycia przycisku. Wstrzykujemy fragment jako
// PRAWDZIWY moduł ES (`type="module"`), z prawdziwym `import` z realnie
// serwowanego `minutnik-krok.js` — to samo, co robi przeglądarka na
// produkcji, nie jego namiastka.
const moduleFileName = 'minutnik-krok.js';
const moduleSource = readFileSync(`resources/js/${moduleFileName}`, 'utf8');
assert(moduleSource.includes('export function pozostaloSekund'),
  'Nie znaleziono prawdziwej arytmetyki minutnika w minutnik-krok.js');
const blade = readFileSync('resources/views/pages/recipes/cooking.blade.php', 'utf8');
const template = blade.match(/<div class="cook-timer"[\s\S]*?<\/div>/)?.[0];
assert(template, 'Nie znaleziono rzeczywistego HTML minutnika');
const html = seconds => template.replace(/\{\{--[\s\S]*?--\}\}/g, '')
  .replaceAll('{{ $aktualnyKrok->timer_seconds }}', String(seconds))
  .replaceAll('{{ $timerLabel }}', `${seconds} sekund`);
// Pas alarmów minutników z innych kroków (issue #1301) — też z realnego Blade.
const alarmyTemplate = blade.match(/<div class="cook-alarmy[^"]*"[^\n]*? hidden><\/div>/)?.[0];
assert(alarmyTemplate, 'Nie znaleziono rzeczywistego pasa alarmów innych kroków');
const importLine = `import { pozostaloSekund, formatMinutySekundy, kluczStanu, zapiszStan, odczytajStan, odczytajTermin, krokZKlucza } from './${moduleFileName}';\n`;
// Jedna strona trybu gotowania = jeden krok: pas alarmów + (opcjonalnie)
// minutnik tego kroku, jak w cooking.blade.php.
const stepPage = (krok, seconds) => '<p class="cook-progress">Krok ' + krok + '</p>'
  + alarmyTemplate.replaceAll('{{ $recipe->slug }}', 'zupa')
    .replaceAll('{{ $krok }}', String(krok))
    .replace(/\{\{ route\([^}]*\}\}/, '/gotuj')
  + (seconds ? html(seconds).replaceAll('{{ $recipe->slug }}', 'zupa').replaceAll('{{ $krok }}', String(krok)) : '');
// Wyjście z trybu gotowania (przegląd #1301) — oba linki z realnego Blade.
const zakonczTemplate = blade.match(/<a class="btn btn-secondary cook-exit"[\s\S]*?<\/a>/)?.[0];
const ugotowalemTemplate = blade.match(/<a class="btn btn-primary btn-cook" href="\{\{ route\('cooked\.create'[^\n]*?<\/a>/)?.[0];
assert(zakonczTemplate && ugotowalemTemplate, 'Nie znaleziono rzeczywistych linków „Zakończ gotowanie” i „Ugotowałem”');
const withExits = body => zakonczTemplate.replace(/\{\{ route\([^}]*\}\}/, '/przepis')
  + ugotowalemTemplate.replace(/\{\{ route\([^}]*\}\}/, '/ugotowalem') + body;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

// Prawdziwe pochodzenie (origin) zamiast `page.setContent`. Dwa niezależne
// powody:
//  1. Import modułu z relatywną ścieżką ('./minutnik-krok.js') potrzebuje
//     realnego URL-a bazowego, żeby się rozwiązać — `page.setContent` go
//     nie daje.
//  2. Strona zrobiona przez `page.setContent` ma nieprzezroczyste (opaque)
//     pochodzenie, na którym Chromium w tej wersji ODMAWIA dostępu do
//     `sessionStorage` („Access is denied for this document") — a kod
//     minutnika czyta `sessionStorage` przy KAŻDYM uruchomieniu (odtwarzanie
//     stanu po przeładowaniu, issue #740), więc bez realnego originu każdy
//     z sześciu podtestów padłby identycznie, niezależnie od poprawki importu.
let currentBody = '';
const server = http.createServer((request, response) => {
  if (request.url === `/${moduleFileName}`) {
    response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8' });
    response.end(moduleSource);
    return;
  }
  response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  response.end(currentBody);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const baseUrl = `http://127.0.0.1:${server.address().port}/`;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH,
  headless: true, args: ['--no-sandbox'] });
const results = [];

async function fixture(durations, run) {
  const page = await browser.newPage();
  try {
    currentBody = durations.map(html).join('\n');
    await page.goto(baseUrl);
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
    await page.addScriptTag({
      type: 'module',
      content: importLine + source.slice(begin, end),
    });
    await run(page);
  } finally { await page.close(); }
}
// Przejście do innego kroku to w aplikacji pełne przeładowanie strony
// (GET ?krok=N) — tu też: nowy dokument pod tym samym originem, więc
// sessionStorage zostaje, a cały stan JavaScriptu znika.
async function openStep(page, krok, seconds, { exits = false } = {}) {
  currentBody = exits ? withExits(stepPage(krok, seconds)) : stepPage(krok, seconds);
  await page.goto(baseUrl + '?krok=' + krok);
  await page.evaluate(() => {
    window.alarmBeeps = 0;
    window.audioContexts = 0;
    const Original = window.AudioContext;
    window.AudioContext = class extends Original {
      constructor(...args) { super(...args); window.audioContexts += 1; }
      createOscillator() { window.alarmBeeps += 1; return super.createOscillator(); }
    };
  });
  await page.addScriptTag({ type: 'module', content: importLine + source.slice(begin, end) });
}
const block = (page, index = 0) => page.locator('.cook-timer').nth(index);
const button = (page, index = 0) => block(page, index).locator('.cook-timer-start');
const anulujButton = (page, index = 0) => block(page, index).locator('.cook-timer-anuluj');
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
    assert(await button(page).isHidden(), 'Przycisk Start musi zniknac podczas odliczania');
    assert(await anulujButton(page).isVisible(), 'Przycisk Anuluj musi byc widoczny podczas odliczania');
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
    assert(await button(page, 1).isHidden(), 'Przycisk Start drugiego minutnika musi zniknac podczas odliczania');
    assert(await anulujButton(page, 1).isVisible(), 'Przycisk Anuluj drugiego minutnika musi byc widoczny podczas odliczania');
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
  await check('minutnik_poprzedniego_kroku_alarmuje_w_nastepnym', async () => {
    const page = await browser.newPage();
    try {
      await openStep(page, 1, 2);
      await button(page).click();
      assert.equal(await remaining(page), 2);
      // Następny krok, zanim minutnik kroku 1 skończył — krok 2 bez minutnika.
      await openStep(page, 2, 0);
      const pas = page.locator('.cook-alarmy');
      assert(await pas.isHidden(), 'Pas alarmów nie może się pokazać przed końcem odliczania');
      await page.waitForTimeout(2600);
      const alarm = page.locator('.cook-alarm');
      assert.equal(await alarm.count(), 1, 'Dokładnie jeden alarm dla jednego minutnika');
      assert(await alarm.isVisible(), 'Alarm minutnika kroku 1 musi być widoczny na kroku 2');
      assert.equal(await alarm.getAttribute('role'), 'alert');
      assert.equal((await page.locator('.cook-alarm-tekst').innerText()).trim(),
        'Minutnik kroku 1 skończył odliczanie.');
      assert(await page.evaluate(() => window.alarmBeeps) >= 1, 'Alarm musi zagrać sygnał');
      await page.waitForTimeout(5300);
      assert(await page.evaluate(() => window.alarmBeeps) >= 2, 'Sygnał alarmu musi się powtórzyć');
      assert.equal(await page.evaluate(() => window.audioContexts), 1,
        'Powtórzenia alarmu muszą korzystać z jednego wspólnego AudioContext');
      const wylacz = page.getByRole('button', { name: 'Wyłącz alarm' });
      assert.equal(await page.getByRole('link', { name: 'Przejdź do kroku 1' }).getAttribute('href'), '/gotuj?krok=1');
      // Zapis zniknął przed alarmem — nic nie zadzwoni drugi raz.
      assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.1')), null);
      await wylacz.click();
      assert.equal(await alarm.count(), 0, 'Wyłącz alarm musi usunąć komunikat');
      assert(await pas.isHidden());
      const beepsAfterOff = await page.evaluate(() => window.alarmBeeps);
      await page.waitForTimeout(5600);
      assert.equal(await page.evaluate(() => window.alarmBeeps), beepsAfterOff, 'Po wyłączeniu sygnał nie może się powtarzać');
      // Powrót do kroku 1: zwykły przycisk startu, bez drugiego alarmu.
      await openStep(page, 1, 2);
      await page.waitForTimeout(1200);
      assert(await button(page).isVisible(), 'Po alarmie krok 1 pokazuje przycisk startu');
      assert.equal(await page.locator('.cook-alarm').count(), 0);
    } finally { await page.close(); }
  });
  await check('powrot_do_kroku_minutnika_bez_podwojnego_alarmu', async () => {
    const page = await browser.newPage();
    try {
      await openStep(page, 1, 3);
      await button(page).click();
      await openStep(page, 2, 0);
      await openStep(page, 1, 3);
      await page.evaluate(() => {
        window.timerEnds = [0];
        const node = document.querySelector('.cook-timer-komunikat');
        new MutationObserver(() => {
          if (node.textContent === 'Czas minął!') window.timerEnds[0] += 1;
        }).observe(node, { childList: true, characterData: true, subtree: true });
      });
      await page.waitForTimeout(3300);
      assert.equal(await page.evaluate(() => window.timerEnds[0]), 1, 'Widoczny krok kończy swój minutnik raz');
      assert.equal(await page.locator('.cook-alarm').count(), 0, 'Pas innych kroków nie dubluje alarmu widocznego kroku');
    } finally { await page.close(); }
  });
  await check('porzucony_minutnik_nie_alarmuje_po_powrocie', async () => {
    const page = await browser.newPage();
    try {
      // 20 minut w kroku 3, potem „Zakończ gotowanie” bez „Anuluj”.
      await openStep(page, 3, 1200, { exits: true });
      await button(page).click();
      assert.notEqual(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.3')), null);
      await Promise.all([page.waitForURL('**/przepis'), page.getByRole('link', { name: 'Zakończ gotowanie' }).click()]);
      assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.3')), null,
        '„Zakończ gotowanie” musi wyczyścić minutniki tego przepisu');

      // To samo przez „Ugotowałem”.
      await openStep(page, 2, 1200, { exits: true });
      await button(page).click();
      await openStep(page, 3, 0, { exits: true });
      await Promise.all([page.waitForURL('**/ugotowalem'), page.getByRole('link', { name: 'Ugotowałem' }).click()]);
      assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.2')), null,
        '„Ugotowałem” musi wyczyścić minutniki tego przepisu');

      // Wyjście inną drogą (zamknięty link, wpisany adres): zapis zostaje,
      // a powrót po trzech godzinach nie może zacząć się od alarmu.
      await page.evaluate(() => {
        const trzyGodzinyTemu = Date.now() - 3 * 60 * 60 * 1000;
        sessionStorage.setItem('kuking.minutnik.zupa.3',
          JSON.stringify({ sekundyCalkiem: 1200, terminEpoka: trzyGodzinyTemu }));
      });
      await openStep(page, 1, 0);
      await page.waitForTimeout(1500);
      assert.equal(await page.locator('.cook-alarm').count(), 0, 'Porzucony minutnik nie może alarmować po godzinach');
      assert.equal(await page.evaluate(() => window.alarmBeeps), 0, 'Porzucony minutnik nie może piszczeć');
      assert.equal(await page.evaluate(() => sessionStorage.getItem('kuking.minutnik.zupa.3')), null,
        'Porzucony zapis znika po cichu');
    } finally { await page.close(); }
  });
} finally { await browser.close(); server.close(); }
console.log(JSON.stringify({ source: 'resources/js/app.js', markup: 'resources/views/pages/recipes/cooking.blade.php', results }, null, 2));
if (results.some(result => result.result !== 'PASS')) process.exitCode = 1;
