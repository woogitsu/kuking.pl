import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import vm from 'node:vm';
import { EKRANY_OAUTH, WARIANTY_OAUTH } from './oauth-dostepnosc.mjs';

// Wykonujemy prawdziwy blok pomiaru i cały prawdziwy writer końcowego JSON.
// Atrapy dotyczą kosztownych pomiarów, nie kodu integracji ani serializacji.
const source = readFileSync(new URL('../dostepnosc.mjs', import.meta.url), 'utf8');
function fragment(start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, 'Przeniesiono blok automatu: regresja musi nadal wykonać jego źródło.');
  return source.slice(from, to);
}
const measurement = fragment('// OAuth: własny serwer pomiarowy', '// Koniec pomiaru OAuth.');
const writer = fragment("writeFileSync('storage/dostepnosc.json'", "log(`Wynik zapisany:");

export async function report(measure) {
  const directory = mkdtempSync(join(tmpdir(), 'kuking-raport345-'));
  const path = join(directory, 'dostepnosc.json');
  const context = {
    zmierzOauth: measure, EKRANY_OAUTH, WARIANTY_OAUTH, przegladarka: {},
    mieszanaKaruzela: { path: '/wpisy/probka' }, CHROMIUM: undefined,
    log() {}, console: { error() {} }, process: { exitCode: 0 },
    writeFileSync(name, json) {
      assert.equal(name, 'storage/dostepnosc.json');
      writeFileSync(path, json);
    },
    wersjaPrzegladarki: 'pomiar-integracji', blokujacych: 0, UDZIAL_BELKI_MAKS: .3,
    karuzelaBezJs: { wyniki: [], blad: null },
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

const complete = EKRANY_OAUTH.flatMap((ekran) => WARIANTY_OAUTH.map((wariant) =>
  ({ ...ekran, ...wariant, status: 'success', axe: 0 })));

test('raport zawiera każdy ekran OAuth w obu motywach', async () => {
  const result = await report(async ({ wyniki }) => { wyniki.push(...complete); });
  assert.equal(result.exitCode, 0);
  assert.deepEqual(result.json.oauth, { wyniki: complete, blad: null });
  assert.equal(result.json.wyborZdjeciaBezJs.doszloTabem, true);
});

test('wyjątek zachowuje rozpoczęty i ukończone pomiary w rzeczywistym JSON', async () => {
  const partial = [...complete.slice(0, 2), { ...complete[2], status: 'error' }];
  const result = await report(async ({ wyniki }) => {
    wyniki.push(...partial);
    throw Error('przerwane połączenie testowe');
  });
  assert.equal(result.exitCode, 1);
  assert.deepEqual(result.json.oauth, { wyniki: partial, blad: 'przerwane połączenie testowe' });
});

for (const [name, bad] of [
  ['brak ostatniego stanu', complete.slice(0, -1)],
  ['powtórzony stan zamiast innego przy prawidłowej liczbie', [...complete.slice(0, -1), complete[0]]],
  ['nieukończony pomiar bez wyjątku', complete.map((w, i) => i === 0 ? { ...w, status: 'started' } : w)],
]) {
  test(name + ' nie daje zielonego wyniku', async () => {
    const result = await report(async ({ wyniki }) => { wyniki.push(...bad); });
    assert.equal(result.exitCode, 1);
    assert.equal(result.json.oauth.wyniki.length, bad.length);
    assert.match(result.json.oauth.blad, /niepełny raport/);
  });
}
