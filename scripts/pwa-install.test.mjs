import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {test} from 'node:test';
import assert from 'node:assert/strict';

// Testujemy prawdziwy moduł, z atrapą DOM i API instalacji. To regresja
// kolejności zdarzeń, nie dowód natywnej instalacji ani geometrii strony.
const source = readFileSync(new URL('../resources/js/pwa-install.js', import.meta.url), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture({standalone = false, allowed = true, fail = false} = {}) {
    const window = new EventTarget();
    window.matchMedia = () => ({matches: standalone});
    const document = new EventTarget();
    const accept = new EventTarget();
    const dismiss = new EventTarget();
    const status = {textContent: ''};
    const actions = [];
    const panel = {
        hidden: true, isConnected: true,
        dataset: {pwaContext: 'kontekst-testowy', pwaUrl: '/instalacja/decyzja'},
        contains: () => false,
        getBoundingClientRect: () => ({top: 100, bottom: 300, left: 10, right: 300}),
        querySelector: selector => ({
            '[data-pwa-accept]': accept, '[data-pwa-dismiss]': dismiss,
            '[data-pwa-status]': status, 'input[name="_token"]': {value: 'csrf-testowy'},
        })[selector],
    };
    document.querySelector = selector => selector === '[data-pwa-install]' ? panel : null;
    document.visibilityState = 'visible';
    document.body = {};
    document.activeElement = document.body;
    runInNewContext(source, {
        window, document, navigator: {}, location: new URL('https://kuking.example/home'),
        URL, AbortController, AbortSignal, innerHeight: 900, innerWidth: 390,
        fetch: async (url, options) => {
            assert.equal(new URL(url).origin, 'https://kuking.example');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-testowy');
            const {action, context} = JSON.parse(options.body);
            assert.equal(context, 'kontekst-testowy');
            actions.push(action);
            return {ok: !fail, json: async () => ({changed: allowed})};
        },
    });
    let prompts = 0;
    const offer = (outcome = 'accepted') => {
        const event = new Event('beforeinstallprompt', {cancelable: true});
        event.prompt = () => { prompts++; return Promise.resolve(); };
        event.userChoice = Promise.resolve({outcome});
        window.dispatchEvent(event);
    };
    return {window, document, panel, accept, dismiss, actions, status, offer, prompts: () => prompts};
}

test('bez zdarzenia oraz w standalone panel nie składa propozycji', async () => {
    const normal = fixture();
    await settle();
    assert.equal(normal.panel.hidden, true);
    assert.deepEqual(normal.actions, []);
    const installed = fixture({standalone: true});
    installed.offer();
    await settle();
    assert.equal(installed.panel.hidden, true);
    assert.deepEqual(installed.actions, []);
});

// D-053: bez propozycji przeglądarki nie wolno pokazać panelu, bo przycisk
// „Zainstaluj aplikację" nie miałby czego uruchomić. Powrót do karty jest
// właśnie tym momentem, w którym panel próbuje się odsłonić.
test('powrót do karty bez beforeinstallprompt nie odsłania martwego przycisku', async () => {
    const f = fixture();
    await settle();
    f.document.dispatchEvent(new Event('visibilitychange'));
    await settle();
    assert.equal(f.panel.hidden, true, 'panel bez zdarzenia instalacji ma zostać ukryty');
    assert.deepEqual(f.actions, [], 'bez zdarzenia instalacji nie rezerwujemy propozycji');
    f.accept.dispatchEvent(new Event('click'));
    await settle();
    assert.equal(f.prompts(), 0);
    assert.deepEqual(f.actions, [], 'kliknięcie w niedostępny panel nie zapisuje decyzji');
});

test('odmowa rezerwacji albo awaria HTTP nie ujawnia panelu', async () => {
    for (const options of [{allowed: false}, {fail: true}]) {
        const f = fixture(options);
        f.offer();
        await settle();
        assert.equal(f.panel.hidden, true);
        assert.deepEqual(f.actions, ['offer']);
    }
});

test('accepted nie oznacza installed; prompt tylko raz i dopiero po kliknięciu', async () => {
    const f = fixture();
    f.offer();
    await settle();
    assert.equal(f.panel.hidden, false);
    assert.deepEqual(f.actions, ['offer', 'shown']);
    assert.equal(f.prompts(), 0);
    f.accept.dispatchEvent(new Event('click'));
    assert.equal(f.prompts(), 1);
    f.accept.dispatchEvent(new Event('click'));
    await settle();
    assert.equal(f.prompts(), 1);
    assert.deepEqual(f.actions, ['offer', 'shown', 'request']);
    f.window.dispatchEvent(new Event('appinstalled'));
    await settle();
    assert.deepEqual(f.actions, ['offer', 'shown', 'request', 'installed']);
});

test('odmowa zamyka panel, a cleanup odłącza zdarzenia starej strony', async () => {
    const f = fixture();
    f.offer();
    await settle();
    f.dismiss.dispatchEvent(new Event('click'));
    await settle();
    assert.equal(f.panel.hidden, true);
    assert.deepEqual(f.actions, ['offer', 'shown', 'dismiss']);
    f.document.dispatchEvent(new Event('livewire:navigating'));
    f.window.dispatchEvent(new Event('appinstalled'));
    f.offer();
    await settle();
    assert.deepEqual(f.actions, ['offer', 'shown', 'dismiss']);
});
