/* Rzeczywisty Laravel + Chromium na izolowanej bazie: PublishRecipe, GET, reload
 * tej samej karty i dwa fronty minutnika. Bez atrap odpowiedzi/Blade. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { uruchomSerwer } from './lib/serwer-lokalny.mjs';

const env = { ...process.env, APP_BASE_PATH: process.cwd() };
const fixture = polecenie => {
  const output = execFileSync('php', ['scripts/fixtures/minutnik-2589.php', polecenie], {
    cwd: process.cwd(), env, encoding: 'utf8',
  }).trim();
  return output ? JSON.parse(output) : null;
};
let serwer = null;
let browser = null;
let utworzono = false;
try {
  const pierwszy = fixture('utworz');
  utworzono = true;
  serwer = await uruchomSerwer();
  browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
  const page = await browser.newPage();
  const gotuj = krok => `${serwer.adres}/przepisy/${pierwszy.slug}/gotuj?krok=${krok}`;
  const kolejka = krok => `${serwer.adres}/gotuj-kilka?p=${pierwszy.slug}:${krok}&a=${pierwszy.slug}`;
  const przejdz = async url => {
    const response = await page.goto(url);
    assert.equal(response?.status(), 200, `HTTP ${url}`);
  };
  const zapisy = () => page.evaluate(() => Object.keys(sessionStorage)
    .filter(key => key.startsWith('kuking.minutnik.')));
  const termin = key => page.evaluate(key => JSON.parse(sessionStorage.getItem(key)).terminEpoka, key);
  const przyspiesz = (key, ms) => page.evaluate(([key, ms]) => {
    const value = JSON.parse(sessionStorage.getItem(key));
    value.terminEpoka = Date.now() + ms;
    sessionStorage.setItem(key, JSON.stringify(value));
  }, [key, ms]);

  await przejdz(gotuj(2));
  await page.locator('.cook-timer-start').click();
  const [singleKey] = await zapisy();
  assert(singleKey?.includes(pierwszy.steps[1].id), 'Start pojedynczy zapisuje UUID rzeczywistego kroku');
  const deadline = await termin(singleKey);
  const poZamianie = fixture('zamien'); // PublishRecipe zachowuje UUID, zmienia kolejność
  assert.equal(poZamianie.steps[0].id, pierwszy.steps[1].id, 'Edycja faktycznie przestawiła krok');
  assert.equal(poZamianie.steps[0].fingerprint, pierwszy.steps[1].fingerprint);
  await page.reload(); // ten sam adres kroku 2, ta sama karta, nowa czynność A
  await page.locator('.cook-timer-start').waitFor({ state: 'visible' });
  assert.equal(await page.locator('.cook-timer').getAttribute('data-timer-step-id'), pierwszy.steps[0].id);
  assert.equal(await termin(singleKey), deadline);
  assert.match(await page.locator('.cook-alarmy [role=status]').innerText(), /Minutnik kroku 1, uruchomiony na 1:00/);
  assert(await page.locator('.cook-alarmy [role=timer]').isVisible());
  assert.equal(await page.locator('.cook-alarmy .cook-alarm').count(), 0, 'Przed terminem nie ma alarmu');
  await przejdz(kolejka(2)); // single → queue, nowy numer 2 należy do innej czynności
  assert.equal(await termin(singleKey), deadline, 'Nawigacja nie przesunęła terminu');
  await page.locator('[data-kolejka-minutniki-lista] li').waitFor();
  assert.equal(await page.locator('[data-kolejka-minutniki-lista] li').count(), 1);
  assert.match(await page.locator('[data-kolejka-minutniki-lista]').innerText(), /krok 1/);
  await przyspiesz(singleKey, 4000);
  await page.reload(); // odtwarzamy nowy deadline i uruchamiamy jeden front
  await page.locator('[data-kolejka-minutniki-lista] li').waitFor();
  await page.locator('[data-kolejka-alarmy] [role=alert]').waitFor({ state: 'visible', timeout: 12000 });
  assert.equal(await page.locator('[data-kolejka-alarmy] [role=alert]').count(), 1,
    'Single → queue: jeden alarm dla przeniesionego kroku');
  assert.equal(await termin(singleKey).catch(() => null), null);

  await przejdz(kolejka(1));
  await page.locator('[data-kolejka-minutnik-start]').click();
  const [queueKey] = await zapisy();
  assert(queueKey?.includes(poZamianie.steps[0].id), 'Kolejka zapisuje ten sam UUID kroku');
  const drugiDeadline = await termin(queueKey);
  const poZmianie = fixture('zmien'); // ten sam UUID, zmiana tekstu i czasu
  assert.equal(poZmianie.steps[0].id, poZamianie.steps[0].id);
  assert.notEqual(poZmianie.steps[0].fingerprint, poZamianie.steps[0].fingerprint);
  await przejdz(gotuj(1)); // queue → single; nowa czynność nie przejmuje starego minutnika
  assert.equal(await termin(queueKey), drugiDeadline);
  await page.locator('.cook-timer-start').waitFor({ state: 'visible' });
  assert.match(await page.locator('.cook-alarmy [role=status]').innerText(), /Przepis się zmienił/);
  assert(await page.locator('.cook-alarmy [role=timer]').isVisible(), 'Pozostały czas ma widoczny licznik');
  await przyspiesz(queueKey, 4000);
  await page.reload();
  await page.locator('.cook-alarmy [role=status]').waitFor();
  await page.locator('.cook-alarmy .cook-alarm').waitFor({ state: 'visible', timeout: 12000 });
  assert.equal(await page.locator('.cook-alarmy .cook-alarm').count(), 1,
    'Queue → single: alarm tylko raz po zmianie czynności');
  assert.equal(await page.locator('.cook-alarmy .cook-alarm a').count(), 0,
    'Alarm starej czynności nie kieruje do nowej');
  assert.equal(await termin(queueKey).catch(() => null), null);
  console.log('MINUTNIK_2589_HTTP: rzeczywista edycja PublishRecipe, single↔queue, reload i pojedyncze alarmy PASS');
} finally {
  await browser?.close();
  serwer?.zamknij();
  if (utworzono) fixture('usun');
}
