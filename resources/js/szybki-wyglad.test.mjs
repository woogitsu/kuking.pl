/*
 * #804: link kliknięty podczas zapisu wyglądu czeka na wynik zapisu.
 * Po porażce strona ZOSTAJE, żeby komunikat dało się przeczytać; po sukcesie
 * przejście następuje. Test wykonuje cały prawdziwy moduł w `vm` z atrapą DOM,
 * więc sprawdza zachowanie handlera, a nie obecność tekstu w źródle.
 *
 *   node --test resources/js/szybki-wyglad.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const zrodlo = readFileSync(new URL('./szybki-wyglad.js', import.meta.url), 'utf8');
const ADRES = 'https://kuking.pl/przepisy/proba';

function element(extra = {}) {
    const listeners = {};
    const attrs = new Set();
    return {
        listeners,
        dataset: {},
        hidden: false,
        disabled: false,
        isConnected: true,
        textContent: '',
        style: { setProperty() {} },
        addEventListener(name, fn, opts) {
            (listeners[name] ??= []).push(fn);
            opts?.signal?.addEventListener('abort', () => { listeners[name] = listeners[name].filter(f => f !== fn); });
        },
        querySelector: () => null,
        querySelectorAll: () => [],
        getBoundingClientRect: () => ({ top: 500, bottom: 548, left: 0, right: 48, width: 48, height: 48 }),
        getClientRects: () => [],
        hasAttribute: name => attrs.has(name),
        setAttribute: name => attrs.add(name),
        removeAttribute: name => attrs.delete(name),
        toggleAttribute: (name, on) => (on ? attrs.add(name) : attrs.delete(name)),
        contains: () => false,
        matches: () => false,
        focus() {},
        ...extra,
    };
}

function uruchom(odpowiedz) {
    const scale = { value: '100', selectedIndex: 0, options: [{}, {}, {}] };
    const theme = { value: 'light' };
    const status = element();
    const summary = element();
    const panel = element();
    const closeButton = element();
    const form = element({ action: '/ustawienia/wyglad', elements: { text_scale: scale, theme } });
    const widget = element({
        open: false,
        querySelector: s => ({ form, '[data-wyglad-status]': status, summary, '.szybki-wyglad-panel': panel, '[data-wyglad-zamknij]': closeButton })[s] ?? null,
    });
    const hint = element({ querySelector: () => element() });
    const html = element();
    const document = element({
        documentElement: html,
        body: element(),
        querySelector: s => ({ '[data-szybki-wyglad]': widget, '[data-wyglad-podpowiedz]': hint })[s] ?? null,
    });
    document.activeElement = document.body;
    const location = { origin: 'https://kuking.pl', href: 'https://kuking.pl/' };
    let rozstrzygnij;
    const kontekst = {
        document,
        location,
        innerHeight: 800,
        window: element({ scrollBy() {} }),
        localStorage: { getItem: () => '1', setItem() {} },
        getComputedStyle: () => ({ right: '12px', position: 'static' }),
        ResizeObserver: class { observe() {} disconnect() {} },
        requestAnimationFrame: fn => fn(),
        setTimeout,
        AbortController,
        AbortSignal,
        FormData: class { set() {} },
        fetch: () => new Promise((ok, zle) => { rozstrzygnij = () => odpowiedz(ok, zle); }),
    };
    vm.runInNewContext(zrodlo, kontekst);

    const zmien = wartosc => { theme.value = wartosc; form.listeners.change.forEach(fn => fn({})); };
    const kliknij = (zmiany = {}) => {
        const zdarzenie = {
            button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false,
            zatrzymane: false,
            target: { closest: () => ({ href: ADRES, target: '', hasAttribute: () => false }) },
            preventDefault() { this.zatrzymane = true; },
            stopImmediatePropagation() {},
            ...zmiany,
        };
        document.listeners.click.forEach(fn => fn(zdarzenie));
        return zdarzenie;
    };
    return { zmien, kliknij, zakoncz: () => rozstrzygnij(), location, status, widget, html };
}

const chwila = () => new Promise(r => setTimeout(r, 0));

for (const [nazwa, odpowiedz] of [
    ['odpowiedź 500', ok => ok({ ok: false })],
    ['brak sieci', (ok, zle) => zle(new Error('offline'))],
    ['zepsuty JSON', ok => ok({ ok: true, json: async () => { throw new SyntaxError('JSON'); } })],
]) {
    test(`porażka zapisu (${nazwa}): link nie przechodzi, komunikat zostaje`, async () => {
        const s = uruchom(odpowiedz);
        s.zmien('dark');
        assert.equal(s.html.dataset.theme, 'dark');
        const klik = s.kliknij();
        assert.equal(klik.zatrzymane, true, 'Link podczas zapisu musi poczekać.');
        s.zakoncz();
        await chwila();
        assert.equal(s.location.href, 'https://kuking.pl/', 'NAWIGACJA_PO_PORAZCE');
        assert.match(s.status.textContent, /Nie mamy potwierdzenia zapisu wyglądu/);
        assert.match(s.status.textContent, /kliknij link jeszcze raz/);
        assert.equal(s.widget.open, true);
        assert.equal(s.html.dataset.theme, 'light', 'Po porażce wraca zapisany wygląd.');

        // Następny krok z komunikatu działa: drugie kliknięcie nie jest już przechwytywane.
        assert.equal(s.kliknij().zatrzymane, false);
    });
}

test('udany zapis: link przechodzi do celu', async () => {
    const s = uruchom(ok => ok({ ok: true, json: async () => ({ theme: 'dark', text_scale: 100 }) }));
    s.zmien('dark');
    assert.equal(s.kliknij().zatrzymane, true);
    s.zakoncz();
    await chwila();
    assert.equal(s.location.href, ADRES);
    assert.equal(s.status.textContent, 'Wygląd zapisany.');
});

test('Ctrl+klik i nowa karta nie są przechwytywane w trakcie zapisu', () => {
    const s = uruchom(ok => ok({ ok: false }));
    s.zmien('dark');
    assert.equal(s.kliknij({ ctrlKey: true }).zatrzymane, false);
    assert.equal(s.kliknij({ metaKey: true }).zatrzymane, false);
    assert.equal(s.kliknij({ target: { closest: () => ({ href: ADRES, target: '_blank', hasAttribute: () => false }) } }).zatrzymane, false);
});
