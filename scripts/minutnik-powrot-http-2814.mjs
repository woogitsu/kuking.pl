/* #2814: prawdziwy GET linku alarmu na lokalnym Laravel + PG18 + Chromium. */
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
import {uruchomSerwer} from './lib/serwer-lokalny.mjs';

const env = {...process.env, APP_BASE_PATH: process.cwd()};
const fixture = polecenie => {
    const output = execFileSync('php', ['scripts/fixtures/minutnik-2814.php', polecenie], {
        cwd: process.cwd(), env, encoding: 'utf8',
    }).trim();
    return output ? JSON.parse(output) : null;
};

let serwer = null;
let browser = null;
let utworzono = false;
try {
    const przepis = fixture('utworz');
    utworzono = true;
    serwer = await uruchomSerwer();
    browser = await chromium.launch({headless: true, ...(process.env.CHROMIUM_PATH ? {executablePath: process.env.CHROMIUM_PATH} : {})});
    const page = await browser.newPage();
    const gotuj = krok => `${serwer.adres}/przepisy/${przepis.slug}/gotuj?krok=${krok}`;
    const przejdz = async adres => {
        const odpowiedz = await page.goto(adres);
        assert.equal(odpowiedz?.status(), 200, `HTTP ${adres}`);
    };
    const zapisy = () => page.evaluate(() => Object.keys(sessionStorage).filter(klucz => klucz.startsWith('kuking.minutnik.')));

    await przejdz(gotuj(1));
    await page.locator('.cook-timer-start').click();
    const [klucz] = await zapisy();
    assert(klucz?.includes(przepis.steps[0].id), 'Start zapisuje tożsamość pierwszego kroku');
    await page.evaluate(klucz => {
        const stan = JSON.parse(sessionStorage.getItem(klucz));
        stan.terminEpoka = Date.now() + 1500;
        sessionStorage.setItem(klucz, JSON.stringify(stan));
    }, klucz);
    await przejdz(gotuj(2));
    const alarm = page.locator('.cook-alarmy .cook-alarm a');
    await alarm.waitFor({state: 'visible', timeout: 12000});
    assert.equal(new URL(await alarm.getAttribute('href'), serwer.adres).searchParams.get('krok'), '1');
    await alarm.click();
    await page.waitForURL(url => url.pathname.endsWith('/gotuj') && url.searchParams.get('krok') === '1');
    assert(await page.locator('.cook-timer-dodaj').isVisible(), 'MINUTNIK_2814_POWROT_ALARMU_DODAJE_CZAS');
    assert.equal(await page.locator('.cook-alarmy .cook-alarm').count(), 0, 'GET nie powtarza alarmu');
    const znaczniki = (await zapisy()).filter(klucz => klucz.endsWith('.po-alarmie'));
    assert.equal(znaczniki.length, 1, 'Zakończenie ma jeden znacznik i nie jest aktywnym minutnikiem');
    assert.equal((await zapisy()).filter(klucz => !klucz.endsWith('.po-alarmie')).length, 0);

    await page.reload();
    assert(await page.locator('.cook-timer-dodaj').isVisible(), 'Powrót/reload zachowuje możliwość dodania');
    assert.equal(await page.locator('.cook-alarmy .cook-alarm').count(), 0, 'Reload nie powtarza alarmu');
    await page.locator('.cook-timer-dodaj').click();
    await page.locator('.cook-timer-dodatkowy-minuty').fill('abc');
    await page.locator('.cook-timer-dodatkowy button[type=submit]').click();
    assert.equal(await page.locator('.cook-timer-dodatkowy-minuty').inputValue(), 'abc');
    assert(await page.locator('.cook-timer-dodatkowy-blad').isVisible());
    await page.locator('.cook-timer-dodatkowy-anuluj').click();
    assert.equal((await zapisy()).filter(klucz => !klucz.endsWith('.po-alarmie')).length, 0, 'Anulowanie formularza nie uruchamia zegara');

    await page.locator('.cook-timer-dodaj').click();
    await page.locator('.cook-timer-dodatkowy-minuty').fill('5');
    await page.locator('.cook-timer-dodatkowy button[type=submit]').click();
    assert.equal((await zapisy()).filter(klucz => klucz.endsWith('.po-alarmie')).length, 0);
    assert.equal((await zapisy()).length, 1, 'Po +5 jeden aktywny minutnik');
    await przejdz(gotuj(2));
    await page.locator('.cook-timer-start').click();
    assert.equal((await zapisy()).length, 2, 'Drugi krok ma niezależny minutnik');
    await przejdz(gotuj(1));
    assert(await page.locator('.cook-timer-dodaj').isVisible(), 'Nowe odliczanie przeżywa przejście między krokami');
    assert.equal((await zapisy()).length, 2);
    await page.locator('.cook-timer-anuluj').click();
    assert.equal((await zapisy()).length, 1, 'Anulowanie pierwszego nie rusza minutnika drugiego kroku');

    await page.evaluate(klucz => sessionStorage.setItem(`${klucz}.po-alarmie`, String(Date.now() - 16 * 60 * 1000)), klucz);
    await page.reload();
    assert.equal(await page.locator('.cook-timer-dodaj').isVisible(), false, 'Stary alarm nie zostawia bezterminowej historii');
    assert.equal((await zapisy()).length, 1, 'Wygasły znacznik jest usuwany, drugi minutnik trwa');

    await page.evaluate(klucz => sessionStorage.setItem(`${klucz}.po-alarmie`, String(Date.now())), klucz);
    await page.reload();
    assert(await page.locator('.cook-timer-dodaj').isVisible());
    await page.locator('[data-minutniki-koniec]').first().click();
    assert.equal((await zapisy()).length, 0, 'Zakończenie gotowania sprząta znaczniki tego przepisu');

    console.log('MINUTNIK_2814_POWROT_ALARMU_DODAJE_CZAS: HTTP link, reload, walidacja, +5 i pojedynczy zegar PASS');
} finally {
    await browser?.close();
    serwer?.zamknij();
    if (utworzono) fixture('usun');
}
// Windows potrafi trzymać uchwyt procesu potomnego `artisan serve` po jego
// zamknięciu. Wszystkie asercje i sprzątanie już się zakończyły.
process.exit(0);
