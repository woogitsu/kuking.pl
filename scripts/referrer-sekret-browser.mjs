import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { randomUUID, createHash } from 'node:crypto';
import { once } from 'node:events';
import { chromium } from 'playwright';

// Prawdziwe dokumenty Laravel. Żadne żądanie przeglądarki poza lokalny
// origin nie jest przekazywane do sieci. Obcy JS, jeśli wskazany, jest
// wyłącznie lokalnym materiałem pomiarowym, nigdy pobieranym przez test.
assert.equal(process.env.DB_HOST, '127.0.0.1', 'REFERRER_DB_HOST');
assert.match(process.env.DB_DATABASE ?? '', /^kuking_port_[a-z0-9_]+$/, 'REFERRER_DB_NAME');
assert.match(process.env.DB_PORT ?? '', /^\d+$/, 'REFERRER_DB_PORT');
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin,
    SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync' };
const fixture = JSON.parse(execFileSync('php', ['scripts/fixtures/referrer-router.php', 'fixture'], { env, encoding: 'utf8' }));
const state = password => JSON.parse(execFileSync('php', ['scripts/fixtures/referrer-router.php', 'state'], { env, input: JSON.stringify({ id: fixture.id, password }), encoding: 'utf8' }));
const script = readFileSync(process.env.REFERRER_REAL_BEACON ?? 'scripts/fixtures/referrer-beacon.js', 'utf8');
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'scripts/fixtures/referrer-router.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
let browser;
const mode = process.env.REFERRER_REAL_BEACON ? 'real-local-copy' : 'own-minimal-fixture';
const evidence = [];
try {
    // Gotowość sygnalizuje własny proces, nie arbitralny sleep.
    await Promise.race([
        new Promise((resolve, reject) => {
            server.once('error', reject);
            server.once('exit', code => reject(new Error(`REFERRER_SERVER_EXIT_${code}`)));
            server.stderr.on('data', chunk => { if (String(chunk).includes('Development Server')) resolve(); });
        }),
        new Promise((_, reject) => { const timer = setTimeout(() => reject(new Error('REFERRER_SERVER_TIMEOUT')), 10000); timer.unref(); }),
    ]);
    const executablePath = process.env.CHROMIUM_PATH;
    if (executablePath) assert(existsSync(executablePath), 'REFERRER_CHROMIUM_PATH');
    browser = await chromium.launch({ headless: true, executablePath });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const payloads = [];
    const blocked = [];
    await context.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.href === 'https://static.cloudflareinsights.com/beacon.min.js') {
            return route.fulfill({ status: 200, contentType: 'application/javascript', body: script });
        }
        if (url.href === 'https://cloudflareinsights.com/cdn-cgi/rum') {
            if (request.method() === 'POST') payloads.push({ url: url.href, headers: request.headers(), body: request.postData() ?? '' });
            return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': origin, 'Access-Control-Allow-Headers': '*' } });
        }
        if (url.origin === origin) return route.continue();
        blocked.push(url.origin);
        return route.abort('blockedbyclient');
    });
    await context.routeWebSocket('**/*', route => route.close());
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const marker = `TEST_1052_PATH_${randomUUID()}`;
    for (const source of [`/nowe-haslo/${marker}`, `/login?token=${marker}`, '/nie-pamietam-hasla']) {
        const sensitive = source.includes(marker);
        const first = await page.goto(origin + source);
        assert.equal(first.status(), 200, 'REFERRER_SOURCE_NOT_200');
        if (sensitive) assert.equal(await page.locator('script[data-cf-beacon]').count(), 0, 'REFERRER_SOURCE_BEACON');
        const before = payloads.length;
        const targetEvents = () => payloads.slice(before).map(item => JSON.parse(item.body)).filter(item => item.location === origin + '/login');
        const navigation = page.waitForRequest(r => r.isNavigationRequest() && r.url() === origin + '/login');
        await page.locator(`a[href="${origin}/login"]`).first().click();
        const request = await navigation;
        await page.waitForURL(origin + '/login');
        assert.equal(await page.locator('script[data-cf-beacon]').count(), 1, 'REFERRER_TARGET_NO_BEACON');
        // Wykonanie zewnętrznego skryptu i wysyłka muszą zajść naprawdę;
        // pusty zbiór zdarzeń nie zalicza asercji braku sekretu.
        await page.waitForFunction(() => document.readyState === 'complete');
        const deadline = Date.now() + 10000;
        while (targetEvents().length === 0 && Date.now() < deadline) {
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
        }
        assert(payloads.length > before, 'REFERRER_NO_PAYLOAD');
        const captured = payloads.slice(before);
        const targetPayloads = targetEvents();
        assert(targetPayloads.length > 0, 'REFERRER_NO_TARGET_PAYLOAD');
        // Najpierw skutek: kontrola ujemna ma pokazać marker W PAYLOADZIE,
        // nie tylko inne brzmienie nagłówka odpowiedzi.
        assert(!JSON.stringify(captured).includes(marker), 'REFERRER_SECRET_IN_PAYLOAD');
        const referrer = await page.evaluate(() => document.referrer);
        if (sensitive) {
            assert.equal(referrer, '', 'REFERRER_DOCUMENT_SECRET');
            assert.equal(request.headers().referer, undefined, 'REFERRER_NAVIGATION_SECRET');
            assert.equal(first.headers()['referrer-policy'], 'no-referrer');
        } else {
            assert.equal(referrer, origin + source, 'REFERRER_ORDINARY_LOST');
            assert(targetPayloads.some(item => item.referrer === origin + source), 'REFERRER_ORDINARY_PAYLOAD_LOST');
        }
        evidence.push({ scenario: sensitive ? (source.includes('?') ? 'query-to-login' : 'path-to-login') : 'ordinary-to-login', payloads: targetPayloads.length, referrer: sensitive ? 'empty' : 'ordinary-preserved' });
    }

    await context.clearCookies();
    await page.goto(origin + '/logowanie/link/' + fixture.link);
    assert.equal(await page.locator('main form input[name=token]').inputValue(), fixture.link, 'REFERRER_LINK_NO_FORM');
    await page.locator('main form button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.startsWith('/logowanie/link'));
    assert.equal((await page.goto(origin + '/ustawienia')).status(), 200);
    assert(state('unused-state-check').link_used, 'REFERRER_LINK_NOT_USED');
    await context.clearCookies();

    // Formularze: utrzymana prawdziwa sesja; bez from() i ręcznego Referer.
    const password = `Nowe-testowe-${randomUUID()}`;
    const resetPath = `/nowe-haslo/${fixture.reset}`;
    await page.goto(origin + resetPath);
    const reset = page.locator('main form');
    await reset.locator('[name=email]').fill(fixture.email);
    await reset.locator('[name=password]').fill(password);
    await reset.locator('[name=password_confirmation]').fill('inna-wartosc');
    const invalidPost = page.waitForRequest(r => r.method() === 'POST' && r.url() === origin + '/nowe-haslo');
    await reset.locator('button[type=submit]').click();
    assert.equal((await invalidPost).headers().referer, undefined, 'REFERRER_FORM_SENT');
    await page.waitForURL(origin + resetPath);
    assert.match(await page.locator('main').innerText(), /Oba hasła/);
    assert.equal(await page.locator('main [name=email]').inputValue(), fixture.email, 'REFERRER_OLD_INPUT_LOST');
    await page.locator('main [name=password]').fill(password);
    await page.locator('main [name=password_confirmation]').fill(password);
    await page.locator('main button[type=submit]').click();
    await page.waitForURL(origin + '/login');
    assert(state(password).password_changed, 'REFERRER_PASSWORD_NOT_CHANGED');
    await page.locator('main [name=login]').fill(fixture.email);
    await page.locator('main [name=password]').fill(password);
    await page.locator('main button[type=submit]').click();
    await page.waitForURL(url => url.pathname !== '/login');
    const settings = await page.goto(origin + '/ustawienia');
    assert.equal(settings.status(), 200, 'REFERRER_LOGIN_NOT_AUTHENTICATED');
    evidence.push({ scenario: 'reset-validation-reset-login', actualPasswordChanged: true, authenticatedSettings: true });

    // Osobny kontekst bez zalogowanego konta, ale ta sama blokada sieci.
    await context.clearCookies();
    await page.goto(origin + '/nowe-haslo/' + marker);
    // Laravel 13 akceptuje potwierdzone same-origin przez Sec-Fetch-Site.
    // Sprawdzamy osobno drogę tokenową: klient HTTP tej samej sesji, lecz
    // bez dowodu same-origin, bez Referer i bez tokenu. Wyłącznie lokalny URL.
    const csrf = (await context.request.post(origin + '/nowe-haslo', {
        form: { token: 'invalid', email: 'synthetic@example.test', password: 'invalid' }, maxRedirects: 0,
    })).status();
    assert.equal(csrf, 419, 'REFERRER_CSRF_NOT_ENFORCED');
    const wrongCsrf = (await context.request.post(origin + '/nowe-haslo', {
        form: { _token: 'wrong-1052', token: 'invalid' }, maxRedirects: 0,
    })).status();
    assert.equal(wrongCsrf, 419, 'REFERRER_WRONG_CSRF_ACCEPTED');
    await page.goto(origin + '/nowe-haslo/' + marker);
    const csrfToken = await page.locator('main input[name=_token]').inputValue();
    const validCsrf = await context.request.post(origin + '/nowe-haslo', {
        form: { _token: csrfToken, token: 'invalid' }, maxRedirects: 0,
    });
    assert.equal(validCsrf.status(), 302, 'REFERRER_VALID_CSRF_REJECTED');
    assert.equal(validCsrf.headers().location, origin + '/nowe-haslo/' + marker, 'REFERRER_CSRF_RETURN_LOST');
    await page.goto(validCsrf.headers().location);
    assert.match(await page.locator('main').innerText(), /Wpisz adres e-mail/, 'REFERRER_VALID_CSRF_NO_VALIDATION');
    await page.goto(origin + '/zaproszenie/' + fixture.invite);
    assert.equal(await page.locator('main form input[name=token]').inputValue(), fixture.invite, 'REFERRER_INVITE_NO_FORM');
    await page.locator('main form button[type=submit]').click();
    await page.waitForURL(origin + '/register');
    assert.equal(await page.locator('main .field-static strong').innerText(), fixture.inviteEmail);
    evidence.push({ scenario: 'csrf-invite-magic-link', missingToken: csrf, wrongToken: wrongCsrf, validToken: validCsrf.status(), inviteAccepted: true, linkUsed: true });
    console.log(JSON.stringify({ mode, beaconSha256: createHash('sha256').update(script).digest('hex'), evidence, blockedExternalOrigins: [...new Set(blocked)], networkPolicy: 'only-exact-local-origin-forwarded' }, null, 2));
} finally {
    if (browser) await browser.close();
    if (server.exitCode === null && server.signalCode === null) {
        const stopped = once(server, 'exit');
        server.kill('SIGTERM');
        await Promise.race([stopped, new Promise((_, reject) => {
            const timer = setTimeout(() => reject(new Error('REFERRER_SERVER_STOP_TIMEOUT')), 5000); timer.unref();
        })]);
    }
}
