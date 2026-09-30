import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ALIASY, GRUPY, wybierzGrupe, wykonajGrupe } from './port-grupy.mjs';

/* Kolejność jak w `port-projektu.mjs`: baza, część 1, część 2, baza. */
const KOLEJNOSC = ['baza', 'rozszerzenia-1', 'rozszerzenia-2', 'baza'];

async function wykonane(wybor) {
  const lista = [];
  for (const nazwa of KOLEJNOSC) {
    await wykonajGrupe(wybierzGrupe(wybor), nazwa, async () => lista.push(nazwa));
  }
  return lista;
}

for (const [wybor, expected] of [
  [undefined, KOLEJNOSC],
  ['baza', ['baza', 'baza']],
  ['rozszerzenia-1', ['rozszerzenia-1']],
  ['rozszerzenia-2', ['rozszerzenia-2']],
  ['rozszerzenia', ['rozszerzenia-1', 'rozszerzenia-2']],
]) {
  test(`wykonuje tylko wybrane pomiary: ${wybor ?? 'domyślnie'}`, async () => {
    assert.deepEqual(await wykonane(wybor), expected);
  });
}
test('nieznana grupa odrzucana przed wywołaniem pomiaru', async () => {
  let wywolany = false;
  assert.throws(() => wybierzGrupe('literowka'), /Nieznana PORT_GRUPA/);
  await assert.rejects(wykonajGrupe('literowka', 'baza', async () => { wywolany = true; }), /Nieznana PORT_GRUPA/);
  assert.equal(wywolany, false);
});
test('literówka w nazwie grupy w skrypcie nie wyłącza pomiaru po cichu', async () => {
  let wywolany = false;
  await assert.rejects(wykonajGrupe('baza', 'rozszerzenia', async () => { wywolany = true; }), /Nieznana grupa pomiaru/);
  assert.equal(wywolany, false);
});

/* Wzór „Podział testów gubi plik": podział nie może zgubić ani zdublować
   pomiaru. (1) każda grupa z GRUPY ma w skrypcie własne `wykonajGrupe`,
   (2) skrypt nie używa grupy spoza GRUPY, (3) alias to suma części bez
   powtórzeń. Że każda część ma swój element macierzy w CI, pilnuje
   `PortMarkiMaWlasnaBramkeCiTest`. */
test('każda grupa jest w skrypcie i nie ma grup spoza listy', () => {
  const zrodlo = readFileSync(new URL('./port-projektu.mjs', import.meta.url), 'utf8');
  const uzyte = new Set([...zrodlo.matchAll(/wykonajGrupe\(grupa, '([^']+)'/g)].map((m) => m[1]));
  assert.deepEqual([...uzyte].sort(), [...GRUPY].sort());
});
test('alias rozszerzeń to suma części bez powtórzeń i bez grup spoza listy', () => {
  for (const [alias, czesci] of Object.entries(ALIASY)) {
    assert.equal(new Set(czesci).size, czesci.length, `alias ${alias} powtarza część`);
    for (const czesc of czesci) assert.ok(GRUPY.includes(czesc), `alias ${alias}: ${czesc} spoza GRUPY`);
    assert.ok(czesci.length >= 2, `alias ${alias} nie dzieli niczego`);
  }
  assert.deepEqual(GRUPY.filter((g) => g.startsWith('rozszerzenia-')), ALIASY.rozszerzenia);
});

/* #2299: pomiary przesuwa się między częściami, żeby je zrównoważyć
   (tagi przeszły z części 1 do 2). Przesunięcie nie może zgubić pomiaru:
   każdy zaimportowany `sprawdz*` jest wywołany dokładnie raz i wewnątrz
   grupy — pomiar poza grupą nie należałby do żadnej części CI. */
test('każdy zaimportowany pomiar jest wywołany dokładnie raz, wewnątrz grupy', () => {
  const zrodlo = readFileSync(new URL('./port-projektu.mjs', import.meta.url), 'utf8');
  const importy = [...zrodlo.matchAll(/^import \{ (sprawdz\w+) \} from/gm)].map((m) => m[1]);
  assert.ok(importy.length >= 10, 'odczyt importów pomiarów zepsuty');
  const grupy = [...zrodlo.matchAll(/wykonajGrupe\(grupa, '[^']+', async \(\) => \{([\s\S]*?)\n  \}\);/g)]
    .map((m) => m[1]).join('\n');
  for (const nazwa of importy) {
    const wzor = new RegExp(`await ${nazwa}\\(`, 'g');
    assert.equal((zrodlo.match(wzor) ?? []).length, 1, `${nazwa}: oczekiwane dokładnie jedno wywołanie`);
    assert.equal((grupy.match(wzor) ?? []).length, 1, `${nazwa} poza grupą — żadna część CI go nie uruchomi`);
  }
});
