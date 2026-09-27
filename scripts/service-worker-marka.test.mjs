/* Wykonujemy prawdziwe źródło workera, z pamięcią cache i siecią pod kontrolą. */
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';

const events = {};
const entries = new Map();
const stores = new Map([['kuking-v1', new Map([['/offline.html', 'stary ekran']])]]);
let online = true;
let requests = 0;
let cacheFull = false;
let putGate = null;
const unhandled = [];
process.on('unhandledRejection', (e) => unhandled.push(e));
const key = (r) => typeof r === 'string' ? r : new URL(r.url).pathname;
const response = (body) => ({ body, ok: true, type: 'basic', clone() { return response(body); } });
const caches = {
  async open(name) {
    if (!stores.has(name)) stores.set(name, entries);
    const store = stores.get(name);
    return {
      async addAll(urls) { for (const url of urls) store.set(url, response(`nowe:${url}`)); },
      async put(r, value) { if (putGate) await putGate; if (cacheFull) throw new Error('QuotaExceededError'); store.set(key(r), value); },
    };
  },
  async keys() { return [...stores.keys()]; },
  async delete(name) { return stores.delete(name); },
  async match(r) { for (const store of stores.values()) if (store.has(key(r))) return store.get(key(r)); },
};
runInNewContext(readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8'), {
  URL, caches,
  self: { location: { origin: 'https://kuking.test' }, addEventListener(n, fn) { events[n] = fn; },
    async skipWaiting() {}, clients: { async claim() {} } },
  async fetch(r) { requests++; if (!online) throw new Error('offline'); return response(`sieć:${key(r)}`); },
});
async function lifecycle(name) { let work; events[name]({ waitUntil(p) { work = p; } }); await work; }
async function get(path, mode = 'cors', method = 'GET', lifetimes = []) {
  let work;
  events.fetch({ request: { url: `https://kuking.test${path}`, mode, method },
    respondWith(p) { work = p; }, waitUntil(p) { lifetimes.push(p); } });
  return work;
}
const tick = () => new Promise((r) => setImmediate(r));
await lifecycle('install');
await lifecycle('activate');
assert(!stores.has('kuking-v1'), 'Stary cache musi zniknąć przy aktualizacji');
assert.equal(stores.size, 1);
for (const path of ['/icons/kuking-mark.svg', '/manifest.webmanifest']) {
  entries.set(path, response('stary znak'));
  assert.equal((await get(path)).body, `sieć:${path}`, 'Stały adres musi odświeżyć markę');
  online = false;
  assert.equal((await get(path)).body, `sieć:${path}`, 'Nowy znak musi działać bez sieci');
  online = true;
}
cacheFull = true;
entries.set('/icons/kuking-mark.svg', response('stara ikona'));
assert.equal((await get('/icons/kuking-mark.svg')).body, 'sieć:/icons/kuking-mark.svg', 'Pełny cache nie może zastąpić świeżej odpowiedzi starą');
cacheFull = false;
entries.set('/build/assets/app-hash.css', response('wersjonowany CSS'));
const before = requests;
assert.equal((await get('/build/assets/app-hash.css')).body, 'wersjonowany CSS');
assert.equal(requests, before, 'Wersjonowany CSS nadal korzysta z cache');
// #1348: pierwsze pobranie assetu — zapis kopii musi trzymać zdarzenie fetch przy życiu.
let release;
putGate = new Promise((r) => { release = r; });
const lifetimes = [];
assert.equal((await get('/build/assets/app-nowy.js', 'cors', 'GET', lifetimes)).body, 'sieć:/build/assets/app-nowy.js',
  'Odpowiedź nie czeka na zapis do cache');
assert.equal(lifetimes.length, 1, 'Zapis kopii assetu musi przedłużyć życie zdarzenia (event.waitUntil)');
let done = false;
lifetimes[0].then(() => { done = true; });
await tick();
assert(!done, 'Zdarzenie musi trwać do końca cache.put()');
release();
putGate = null;
await Promise.all(lifetimes);
assert.equal((await caches.match('/build/assets/app-nowy.js'))?.body, 'sieć:/build/assets/app-nowy.js');
const przed = requests;
assert.equal((await get('/build/assets/app-nowy.js')).body, 'sieć:/build/assets/app-nowy.js');
assert.equal(requests, przed, 'Drugi odczyt assetu to trafienie w cache, bez sieci');
// Pełny magazyn: odpowiedź z sieci zostaje, obietnica zapisu nie jest odrzucona bez obsługi.
cacheFull = true;
const pelny = [];
assert.equal((await get('/build/assets/pelny.css', 'cors', 'GET', pelny)).body, 'sieć:/build/assets/pelny.css');
assert.equal(pelny.length, 1);
await Promise.all(pelny);
await tick();
assert.deepEqual(unhandled, [], 'Odmowa cache nie może zostać nieobsłużoną obietnicą');
cacheFull = false;
assert.equal((await get('/home', 'navigate')).body, 'sieć:/home');
assert.equal(await caches.match('/home'), undefined, 'Prywatny HTML nie trafia do cache');
online = false;
assert.equal((await get('/home', 'navigate')).body, 'nowe:/offline.html');
// #749: ekran offline przychodzi POD ADRESEM, który człowiek otwierał
// (respondWith przy nawigacji), więc „Spróbuj ponownie" rozwiązane względem
// tego adresu ma ponowić ten sam przepis i to samo wyszukiwanie.
const offlineHtml = readFileSync(new URL('../public/offline.html', import.meta.url), 'utf8');
const ponow = offlineHtml.match(/<a href="([^"]*)">Spróbuj ponownie<\/a>/);
assert(ponow, 'Ekran offline musi mieć odnośnik „Spróbuj ponownie"');
for (const path of ['/przepisy/rosol-fixture', '/szukaj?q=zupa']) {
  assert.equal((await get(path, 'navigate')).body, 'nowe:/offline.html');
  const cel = new URL(ponow[1], `https://kuking.test${path}`);
  assert.equal(cel.pathname + cel.search, path, `„Spróbuj ponownie" porzuca ${path}`);
}
assert.equal(await get('/dodaj/zdjecie', 'navigate', 'POST'), undefined);
assert.equal(await get('/zdjecia/prywatne/feed'), undefined);
console.log('Service worker: odświeżanie marki, offline i prywatność — OK');
