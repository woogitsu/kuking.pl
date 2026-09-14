import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import vm from 'node:vm';
import { zmierzKaruzele } from './karuzela-mieszana.mjs';

// Wykonujemy prawdziwy blok pomiaru i cały prawdziwy writer końcowego JSON.
// Atrapy dotyczą kosztownych pomiarów, nie kodu integracji ani serializacji.
const source = readFileSync(new URL('../dostepnosc.mjs', import.meta.url), 'utf8');
function fragment(start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, 'Przeniesiono blok automatu: regresja musi nadal wykonać jego źródło.');
  return source.slice(from, to);
}
const measurement = fragment("log('Karuzela bez JavaScriptu:');", '/* =============================================================================');
const writer = fragment("writeFileSync('storage/dostepnosc.json'", "log(`Wynik zapisany:");

export async function report(measure) {
  const directory = mkdtempSync(join(tmpdir(), 'kuking-raport431-'));
  const path = join(directory, 'dostepnosc.json');
  const context = {
    zmierzKaruzele: measure, przegladarka: {}, adres: 'http://127.0.0.1',
    mieszanaKaruzela: { path: '/wpisy/probka' }, CHROMIUM: undefined,
    log() {}, console: { error() {} }, process: { exitCode: 0 },
    writeFileSync(name, json) {
      assert.equal(name, 'storage/dostepnosc.json');
      writeFileSync(path, json);
    },
    wersjaPrzegladarki: 'pomiar-integracji', blokujacych: 0, UDZIAL_BELKI_MAKS: .3,
    oauth: { wyniki: [], blad: null },
    wyborZdjeciaBezJs: { doszloTabem: true }, zbadanePrzezAxe: new Set(['ekran']), zmierzoneUkladem: new Set(['ekran']),
  };
  for (const name of ['WARIANTY', 'wyniki', 'EKRANY', 'SZEROKOSCI_UKLADU', 'SKALE_UKLADU', 'EKRANY_UKLADU',
    'przepelnienia', 'SZEROKOSCI_WYROWNANIA', 'rozjazdyBelki', 'niespojneSzerokosci', 'SZEROKOSCI_FOCUS',
    'naruszeniaFocus', 'ostrzezeniaFocus', 'wysokosciBelki', 'zaWysokaBelka', 'SZEROKOSCI_TABLICY',
    'rozjazdyTablicy', 'SZEROKOSCI_LICZB', 'rozjazdyLiczb', 'SZEROKOSCI_SZYNY_GOSCIA', 'rozjazdySzynyGoscia']) context[name] = [];
  try {
    await vm.runInNewContext(`(async () => { ${measurement}\n${writer} })()`, context);
    return { json: JSON.parse(readFileSync(path, 'utf8')), exitCode: context.process.exitCode };
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
}

const complete = Array.from({ length: 8 }, (_, i) => ({ width: i < 4 ? 320 : 390, mode: `wariant-${i}`, status: 'ok', steps: [{ slide: 1 }, { slide: 2 }, { slide: 1 }] }));

test('końcowy JSON zachowuje osiem pełnych wyników i istniejące sekcje', async () => {
  const result = await report(async ({ wyniki = [] }) => { wyniki.push(...complete); return wyniki; });
  assert.equal(result.exitCode, 0);
  assert.deepEqual(result.json.karuzelaBezJs, { wyniki: complete, blad: null });
  assert.equal(result.json.wyborZdjeciaBezJs.doszloTabem, true);
  assert.equal(result.json.zbadanych, 1);
});

test('błąd pomiaru nadal zapisuje ukończone i przerwane przebiegi oraz kod 1', async () => {
  const partial = [...complete.slice(0, 2), { width: 320, mode: 'font200', status: 'blad', steps: [{ slide: 1 }], blad: 'zerwane połączenie' }];
  const result = await report(async ({ wyniki = [] }) => { wyniki.push(...partial); throw Error('zerwane połączenie'); });
  assert.equal(result.exitCode, 1);
  assert.deepEqual(result.json.karuzelaBezJs, { wyniki: partial, blad: 'zerwane połączenie' });
});

test('siedem wyników bez wyjątku nie staje się kompletnym pomiarem', async () => {
  const result = await report(async ({ wyniki = [] }) => { wyniki.push(...complete.slice(0, 7)); return wyniki; });
  assert.equal(result.exitCode, 1);
  assert.equal(result.json.karuzelaBezJs.wyniki.length, 7);
  assert.match(result.json.karuzelaBezJs.blad, /niepełny raport/);
});

test('prawdziwy moduł zachowuje rozpoczęty wariant także przy awarii przeglądarki', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'kuking-przerwany431-'));
  const wyniki = [];
  try {
    await assert.rejects(zmierzKaruzele({
      browser: { newContext: async () => { throw Error('nie można otworzyć kontekstu'); } },
      adres: 'http://127.0.0.1', path: '/wpisy/probka', output: directory, wyniki,
    }), /nie można otworzyć kontekstu/);
    assert.equal(wyniki.length, 1);
    assert.equal(wyniki[0].status, 'blad');
    assert.equal(wyniki[0].width, 320);
    assert.match(wyniki[0].blad, /nie można otworzyć kontekstu/);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
