/*
 * #749 — „Spróbuj ponownie” na ekranie offline ponawia TEN adres, który się
 * nie wczytał, a nie prowadzi na stronę główną.
 *
 * Wykonujemy prawdziwe `public/sw.js`, a w cache kładziemy prawdziwe
 * `public/offline.html`. Przeglądarka nadaje dokumentowi z respondWith adres
 * nawigacji, więc odnośnik rozwiązujemy względem adresu żądania — tak jak
 * zrobi to przeglądarka po kliknięciu.
 *
 * Kontrola ujemna: `href="/home"` w odnośniku „Spróbuj ponownie” oblewa oba
 * przypadki GET. Kontrola dodatnia: POST nadal nie jest przechwytywany,
 * a prywatny HTML nie trafia do cache.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const offlineHtml = readFileSync(new URL('../public/offline.html', import.meta.url), 'utf8');

function worker() {
  const events = {};
  const store = new Map();
  let online = true;
  const key = (r) => typeof r === 'string' ? r : new URL(r.url).pathname;
  const response = (body) => ({ body, ok: true, type: 'basic', clone() { return response(body); } });
  const caches = {
    async open() {
      return {
        async addAll(urls) {
          for (const url of urls) store.set(url, response(url === '/offline.html' ? offlineHtml : url));
        },
        async put(r, value) { store.set(key(r), value); },
      };
    },
    async keys() { return []; },
    async delete() { return true; },
    async match(r) { return store.get(key(r)); },
  };
  runInNewContext(readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8'), {
    URL, caches,
    self: { location: { origin: 'https://kuking.test' }, addEventListener(n, fn) { events[n] = fn; },
      async skipWaiting() {}, clients: { async claim() {} } },
    async fetch(r) { if (!online) throw new TypeError('offline'); return response(`sieć:${key(r)}`); },
  });
  const lifecycle = async (name) => { let work; events[name]({ waitUntil(p) { work = p; } }); await work; };
  const request = (url, mode, method = 'GET') => {
    let work;
    events.fetch({ request: { url, mode, method }, respondWith(p) { work = p; }, waitUntil() {} });
    return work;
  };
  return { lifecycle, request, store, setOnline(v) { online = v; } };
}

/* Cel odnośnika o danym napisie, rozwiązany względem adresu dokumentu. */
function cel(html, napis, adresDokumentu) {
  const linki = [...html.matchAll(/<a\b([^>]*)>([^<]*)<\/a>/g)];
  const trafienie = linki.find(([, , tekst]) => tekst.trim() === napis);
  assert.ok(trafienie, `Brak odnośnika „${napis}” na ekranie offline`);
  const href = trafienie[1].match(/\bhref="([^"]*)"/);
  assert.ok(href, `Odnośnik „${napis}” nie ma href — bez JS byłby martwym przyciskiem`);
  const url = new URL(href[1], adresDokumentu);
  url.hash = '';
  return url.href;
}

test('„Spróbuj ponownie” ponawia przepis i wyszukiwanie z frazą', async () => {
  const sw = worker();
  await sw.lifecycle('install');
  await sw.lifecycle('activate');
  sw.setOnline(false);

  for (const adres of [
    'https://kuking.test/przepisy/rosol-fixture',
    'https://kuking.test/szukaj?q=zupa&sekcja=przepisy',
    'https://kuking.test/szukaj?q=%C5%BCurek#wyniki',
  ]) {
    const odpowiedz = await sw.request(adres, 'navigate');
    assert.equal(odpowiedz.body, offlineHtml, `Brak ekranu offline dla ${adres}`);
    const oczekiwany = new URL(adres);
    oczekiwany.hash = '';
    assert.equal(cel(odpowiedz.body, 'Spróbuj ponownie', adres), oczekiwany.href,
      'Ponowienie musi wrócić pod ten sam adres, ze ścieżką i parametrami');
    assert.equal(cel(odpowiedz.body, 'Przejdź na stronę główną', adres), 'https://kuking.test/home');
  }
});

test('kontrola dodatnia: POST bez przechwycenia, prywatny HTML poza cache', async () => {
  const sw = worker();
  await sw.lifecycle('install');
  sw.setOnline(true);
  assert.equal((await sw.request('https://kuking.test/przepisy/rosol-fixture', 'navigate')).body,
    'sieć:/przepisy/rosol-fixture');
  assert.equal(sw.store.has('/przepisy/rosol-fixture'), false, 'Prywatny HTML nie trafia do cache');
  sw.setOnline(false);
  assert.equal(sw.request('https://kuking.test/wpisy', 'navigate', 'POST'), undefined,
    'POST nie może być przechwycony ani ponawiany');
});

test('kontrola ujemna: stary odnośnik na /home jest wykrywany', () => {
  const stary = offlineHtml.replace(/<a href="">Spróbuj ponownie<\/a>/, '<a href="/home">Spróbuj ponownie</a>');
  assert.notEqual(stary, offlineHtml, 'Podmiana musi trafić — inaczej kontrola ujemna jest pusta');
  assert.equal(cel(stary, 'Spróbuj ponownie', 'https://kuking.test/szukaj?q=zupa'), 'https://kuking.test/home');
});
