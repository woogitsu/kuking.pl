/* Regresje #892/#901 oraz pomiar decyzji #899, wyłącznie na bazie pomiarowej.

   Tryby `autosave` i `published` chodzą w CI (job `port_funkcje`, baza
   `kuking_port_pomiar`) — to jedyny test regresji #892: plakietka nie może
   obiecywać „Szkic zapisany.” dla tekstu, którego serwer jeszcze nie dostał.
   Czekamy na STAN strony (waitForFunction), nie na zegar: debounce 3000 ms
   i czas odpowiedzi runnera nie decydują o wyniku. */
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { createServer } from 'node:net';

const env = { ...process.env };
// Skrypt tworzy konto i szkice, więc tylko na bazie pomiarowej — ta sama
// osłona co w `scripts/kroki-kreatora.mjs` (plus historyczna baza floty).
const bazaPomiarowa = baza => /^[a-zA-Z0-9_-]+$/.test(baza || '')
    && (baza.endsWith('_pomiar') || baza.startsWith('kuking_qa_') || baza === 'kuking_flota_gpt-kreator-przepisu');
assert(bazaPomiarowa(env.DB_DATABASE), 'Kreator wymaga osobnej bazy pomiarowej (…_pomiar albo kuking_qa_…)');
assert(['127.0.0.1', 'localhost'].includes(env.DB_HOST || '127.0.0.1'), 'Pomiar tylko na lokalnej bazie');
const mode = process.argv[2] || 'autosave';
const fixture = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', `
    if (config('database.connections.'.config('database.default').'.database') !== '${env.DB_DATABASE}') { throw new RuntimeException('Obca baza'); }
    $u = App\\Models\\User::factory()->create(['password' => Illuminate\\Support\\Facades\\Hash::make('Test-kreatora-123!')]);
    $u->profile()->update(['username' => 'kreator'.Illuminate\\Support\\Str::lower(Illuminate\\Support\\Str::random(8)), 'display_name' => 'Pomiar kreatora']);
    $r = App\\Models\\Recipe::factory()${mode === 'published' ? '' : '->draft()'}->create(['author_id' => $u->id, 'title' => 'Najstarszy szkic', 'updated_at' => now()->subDays(2)]);
    $r->steps()->create(['position' => 0, 'instruction' => 'Zagotuj wodę.']);
    App\\Models\\Recipe::factory()->draft()->count(6)->create(['author_id' => $u->id]);
    echo json_encode(['login' => $u->refresh()->profile->username, 'id' => $r->id, 'slug' => $r->slug, 'draftPath' => route('recipes.create', ['szkic' => $r->id], false)]);
`], { env, encoding: 'utf8' }).trim());
const socket = createServer();
await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { env, stdio: 'ignore' });
let browser;
try {
    let gotowy = false;
    for (let i = 0; i < 120; i++) {
        try { if ((await fetch(base + '/health')).ok) { gotowy = true; break; } } catch {}
        await new Promise(r => setTimeout(r, 250));
    }
    assert(gotowy, 'Serwer pomiarowy nie wystartował');
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    const page = await browser.newPage({ viewport: { width: 390, height: 900 }, serviceWorkers: 'block' });
    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(e.message));
    await page.goto(base + '/login');
    await page.fill('[name=login]', fixture.login);
    await page.fill('[name=password]', 'Test-kreatora-123!');
    await Promise.all([page.waitForURL(u => !u.pathname.endsWith('/login')), page.click('button[type=submit]')]);
    if (mode === 'drafts') {
        await page.goto(base + '/dodaj');
        await page.getByRole('link', { name: 'Wszystkie szkice', exact: true }).click({ timeout: 5000 });
        for (const width of [320, 360, 390, 414]) {
            await page.setViewportSize({ width, height: 900 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `SZKICE_OVERFLOW_${width}`);
            assert(await page.getByRole('link', { name: 'Dokończ: Najstarszy szkic', exact: true }).evaluate(e => e.getBoundingClientRect().height >= 48));
        }
        await page.getByRole('link', { name: 'Dokończ: Najstarszy szkic', exact: true }).click();
        assert.equal(await page.locator('#f-title').inputValue(), 'Najstarszy szkic');
        console.log('SZKICE: od Dodaj do najstarszego z siedmiu — OK');
    } else if (mode === 'indices') {
        await page.goto(base + `/przepisy/${fixture.slug}/edycja`);
        await page.fill('#f-steps-1-instruction', 'Krok pod nieciągłym kluczem');
        await page.fill('#f-steps-1-timer_minutes', '10081');
        await page.evaluate(() => {
            const form = document.querySelector('form.panel-formularza');
            form.noValidate = true;
            for (const field of form.querySelectorAll('[name^="steps[1]"]')) field.name = field.name.replace('steps[1]', 'steps[7]');
        });
        await page.getByRole('button', { name: 'Zapisz szkic', exact: true }).click();
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('[name="steps[7][instruction]"]').inputValue(), 'Krok pod nieciągłym kluczem');
        assert.equal(await page.locator('a[href="#f-steps-7-timer_minutes"]').count(), 1, 'BRAK_ODNOSNIKA_BLEDU');
        assert(await page.locator('#f-steps-7-timer_minutes').locator('..').innerText().then(t => t.includes('10080')));
        console.log('INDEKSY: rzeczywisty POST, tekst i błąd przy kluczu 7 — OK');
    } else if (mode === 'transfer') {
        const results = [];
        for (const linkIndex of [0, 1]) {
            await page.goto(base + `/przepisy/${fixture.slug}/edycja`);
            await page.fill('#f-title', 'Niezapisany tytuł');
            await page.fill('[name="ingredients[0][text]"]', 'Niezapisany składnik');
            let dialog = false;
            page.once('dialog', async d => { dialog = true; await d.dismiss(); });
            await page.locator(`a[href="${base}/przepisy/${fixture.slug}/szczegoly"]`).nth(linkIndex).click();
            await page.waitForTimeout(500);
            results.push({ linkIndex, dialog, title: await page.locator('#f-title').inputValue() });
        }
        console.log('PRZEJŚCIE:', JSON.stringify(results));
    } else {
        await page.goto(base + `/przepisy/${fixture.slug}/szczegoly`);
        if (mode !== 'published') assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, fixture.draftPath);
        const badge = page.locator('.autosave-badge:visible');
        const waitSaved = async () => {
            try {
                await page.waitForFunction(() => [...document.querySelectorAll('.autosave-badge')].some(e => e.checkVisibility() && /^(Szkic zapisany\.|Zmiany zapisane\.)$/.test(e.textContent.trim())));
            } catch (error) {
                console.log('STAN PO BRAKU POTWIERDZENIA:', await page.evaluate(() => {
                    const root = document.querySelector('[wire\\:id]');
                    const component = window.Livewire.find(root.getAttribute('wire:id'));
                    return { revision: window.Alpine.$data(root).revision, sent: component.editRevision, acknowledged: component.acknowledgedRevision, title: component.title, message: component.saveMessage };
                }));
                throw error;
            }
        };
        const POTWIERDZENIE = /Szkic zapisany\.|Zmiany zapisane\./;
        const brakPotwierdzenia = async (kod) => assert(!POTWIERDZENIE.test((await badge.allTextContents()).join(' ')), kod);
        // Widoczna plakietka o danym stanie (`data-state`) — sygnał z DOM, nie z zegara.
        const czekajNaPlakietke = (stan) => page.waitForFunction(s => [...document.querySelectorAll('.autosave-badge')]
            .some(e => e.dataset.state === s && e.checkVisibility()), stan, { timeout: 15000 });
        const potwierdzonaWersja = () => page.evaluate(() => {
            const root = document.querySelector('[wire\\:id]');
            return window.Livewire.find(root.getAttribute('wire:id')).acknowledgedRevision;
        });
        await page.fill('#f-title', 'Pierwszy zapis');
        await waitSaved();

        // 1. Zmiana jest tylko w przeglądarce (debounce jeszcze trwa).
        let release;
        let oznaczPrzechwycenie;
        const przechwycone = new Promise(r => { oznaczPrzechwycenie = r; });
        const hold = new Promise(r => { release = r; });
        await page.route(/\/livewire.*\/update/, async route => {
            oznaczPrzechwycenie();
            const response = await route.fetch();
            assert.equal(response.status(), 200, 'Odpowiedź opóźnianego żądania');
            await hold;
            await route.fulfill({ response });
        });
        await page.fill('#f-title', 'Drugi zapis');
        await czekajNaPlakietke('waiting');
        console.log('PRZED WYSŁANIEM:', await badge.allTextContents());
        await brakPotwierdzenia('STARE_POTWIERDZENIE');

        // 2. Żądanie w toku (odpowiedź wstrzymana).
        await przechwycone;
        await czekajNaPlakietke('saving');
        await brakPotwierdzenia('POTWIERDZENIE_W_TRAKCIE');
        const przedOdpowiedzia = await potwierdzonaWersja();

        // 3. Starsza odpowiedź przychodzi, gdy w polu jest już nowszy tekst.
        await page.fill('#f-title', 'Trzeci zapis podczas odpowiedzi');
        release();
        await page.waitForFunction(przed => {
            const root = document.querySelector('[wire\\:id]');
            return window.Livewire.find(root.getAttribute('wire:id')).acknowledgedRevision > przed;
        }, przedOdpowiedzia, { timeout: 15000 });
        await czekajNaPlakietke('waiting');
        await brakPotwierdzenia('STARSZA_ODPOWIEDZ');

        // 4. Sukces dopiero dla najnowszej wersji.
        await page.unrouteAll({ behavior: 'wait' });
        await waitSaved();
        await page.reload();
        assert.equal(await page.locator('#f-title').inputValue(), 'Trzeci zapis podczas odpowiedzi');
        await page.fill('#f-title', 'Niewysłana zmiana przed odświeżeniem');
        await page.reload();
        assert.equal(await page.locator('#f-title').inputValue(), 'Trzeci zapis podczas odpowiedzi');
        console.log('ODŚWIEŻENIE PRZED ZAPISEM: niewysłana zmiana nie przetrwała; plakietka nie obiecuje zapisu.');
        await page.getByRole('button', { name: /Dalej/ }).click();
        await page.getByRole('button', { name: /Dalej/ }).click();
        await page.fill('#f-steps-0-instruction', 'Nowy tekst przygotowania.');
        await czekajNaPlakietke('waiting');
        await brakPotwierdzenia('STARE_POTWIERDZENIE_KROKU');
        await waitSaved();

        // 5. Błąd walidacji: żadnego fałszywego sukcesu.
        await page.getByRole('button', { name: /Wstecz/ }).click();
        await page.getByRole('button', { name: /Wstecz/ }).click();
        await page.fill('#f-servings', '0');
        await page.waitForFunction(() => [...document.querySelectorAll('.autosave-badge')].some(e => e.checkVisibility() && e.textContent.includes('Nie zapisaliśmy')));
        await brakPotwierdzenia('POTWIERDZENIE_PO_BLEDZIE');
        mkdirSync('output/playwright', { recursive: true });
        await page.screenshot({ path: 'output/playwright/kreator-walidacja.png', fullPage: true });
        console.log('AUTOZAPIS: oczekiwanie, żądanie, starsza odpowiedź, sukces i błąd — OK');
    }
    assert.deepEqual(pageErrors, [], 'Błędy skryptów strony');
} finally {
    await browser?.close();
    server.kill('SIGTERM');
}
