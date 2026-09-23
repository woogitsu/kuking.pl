import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// Rzeczywisty formularz, sesja, CSRF i PostgreSQL. Fixture tylko zakłada konta.
const fixture = action => execFileSync('php', ['tests/Fixtures/onboarding-browser.php', action], { encoding: 'utf8' });
const cookie = JSON.parse(fixture('setup'));
const server = spawn('php', ['-S', '127.0.0.1:18551', '-t', 'public'], { stdio: 'ignore' });
let browser;
try {
    for (let i = 0; i < 50; i++) {
        try { await fetch('http://127.0.0.1:18551/login'); break; } catch { await new Promise(r => setTimeout(r, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 320, height: 844 }, javaScriptEnabled: !process.argv.includes('--bez-js') });
    await context.addCookies([{ ...cookie, url: 'http://127.0.0.1:18551' }]);
    const page = await context.newPage();
    await page.goto('http://127.0.0.1:18551/witaj/ludzie');
    const checkbox = name => page.locator(`input[type="checkbox"][value="proba851_${name}"]`);
    await checkbox('halina').check();
    await page.getByLabel('Imię lub nazwa użytkownika', { exact: true }).fill('proba851_marek');
    await page.getByRole('button', { name: 'Szukaj', exact: true }).click();
    assert.equal(await checkbox('halina').isChecked(), true, '851: wyszukiwanie zgubiło Halinę');
    await checkbox('marek').check();
    await page.getByLabel('Imię lub nazwa użytkownika', { exact: true }).fill('proba851_cezary');
    await page.getByRole('button', { name: 'Szukaj', exact: true }).click();
    await page.getByText('Wyczyść wyszukiwanie', { exact: true }).click();
    assert.equal(await checkbox('halina').isChecked(), true, '851: czyszczenie zgubiło Halinę');
    assert.equal(await checkbox('marek').isChecked(), true, '851: czyszczenie zgubiło Marka');
    assert.equal(await checkbox('halina').count(), 1, '851: podwójny checkbox tej samej osoby');
    assert.equal(new URL(page.url()).searchParams.has('_token'), false, '851: CSRF wyciekł do adresu');
    for (const name of ['Szukaj', 'Dalej']) {
        const size = await page.getByRole('button', { name, exact: true }).boundingBox();
        assert.ok(size.height >= 48, `851: przycisk ${name} jest niższy niż 48 px`);
    }
    const geometry = await page.evaluate(() => ({
        width: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
        font: parseFloat(getComputedStyle(document.querySelector('input[name="q"]')).fontSize),
    }));
    assert.ok(geometry.scroll <= geometry.width + 1, '851: strona wychodzi poza szerokość 320 px');
    assert.ok(geometry.font >= 18, '851: pismo pola jest mniejsze niż 18 px');
    mkdirSync('output/playwright', { recursive: true });
    await page.screenshot({ path: 'output/playwright/onboarding-wybor.png', fullPage: true });
    assert.deepEqual(JSON.parse(fixture('read')), [], '851: wyszukiwanie zapisało obserwowania');
    await checkbox('halina').uncheck();
    await page.getByRole('button', { name: 'Dalej', exact: true }).click();
    await page.waitForURL('**/witaj/gotowe');
    assert.deepEqual(JSON.parse(fixture('read')), ['proba851_marek']);
    await page.goto('http://127.0.0.1:18551/witaj/ludzie');
    await checkbox('halina').check();
    await page.getByRole('link', { name: 'Pomiń ten krok', exact: true }).click();
    await page.waitForURL('**/witaj/gotowe');
    assert.deepEqual(JSON.parse(fixture('read')), ['proba851_marek'], '851: pominięcie zapisało szkic');
    console.log('851: przeglądarka zachowała wybór A/B, odznaczenie A i zapisała tylko B.');
} finally {
    await browser?.close();
    server.kill();
    fixture('cleanup');
}
