import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { CZESCI_PANELU, DODATKI_PANELU, SZEROKOSCI_PANELU, wybierzCzescPanelu } from './panel-czesci.mjs';

/* Wzór „Podział testów gubi plik" (#2299): podział panelu marki na części
   joba nie może zgubić ani zdublować szerokości albo dodatku. Że każda
   część ma swój element macierzy w `ci.yml`, pilnuje
   `PanelMarkiDzieliSieBezUtratyPomiaruTest`. */
const czesci = Object.values(CZESCI_PANELU);

test('suma szerokości części to pełna lista, bez powtórzeń', () => {
  const wszystkie = czesci.flatMap(c => c.szerokosci);
  assert.equal(new Set(wszystkie).size, wszystkie.length, 'szerokość w dwóch częściach');
  assert.deepEqual([...wszystkie].sort((a, b) => a - b), [...SZEROKOSCI_PANELU].sort((a, b) => a - b));
});

test('suma dodatków części to pełna lista, bez powtórzeń', () => {
  const wszystkie = czesci.flatMap(c => c.dodatki);
  assert.equal(new Set(wszystkie).size, wszystkie.length, 'dodatek w dwóch częściach');
  assert.deepEqual([...wszystkie].sort(), [...DODATKI_PANELU].sort());
});

test('każda część coś mierzy, a części są co najmniej dwie', () => {
  assert.ok(czesci.length >= 2);
  for (const c of czesci) assert.ok(c.szerokosci.length > 0, 'część bez szerokości');
});

test('bez PANEL_CZESC pomiar jest pełny, jak przed podziałem', () => {
  for (const brak of [undefined, '']) {
    const wybor = wybierzCzescPanelu(brak);
    assert.equal(wybor.czesc, null);
    assert.deepEqual(wybor.szerokosci, SZEROKOSCI_PANELU);
    assert.deepEqual(wybor.dodatki, DODATKI_PANELU);
  }
});

test('nieznana część odrzucona, zamiast mierzyć nic', () => {
  for (const zla of ['0', '3', 'wszystko', '1 ', 'constructor']) {
    assert.throws(() => wybierzCzescPanelu(zla), /P581_CZESC/);
  }
  assert.deepEqual(wybierzCzescPanelu('2').szerokosci, CZESCI_PANELU[2].szerokosci);
});

test('skrypt panelu uruchamia każdy dodatek i przekazuje szerokości części', () => {
  const zrodlo = readFileSync(new URL('./panel-marki-run.mjs', import.meta.url), 'utf8');
  for (const d of DODATKI_PANELU) assert.match(zrodlo, new RegExp(`dodatek\\('${d}'\\)`), `dodatek ${d} bez wywołania`);
  const wywolania = [...zrodlo.matchAll(/dodatek\('([^']+)'\)/g)].map(m => m[1]);
  for (const d of wywolania) assert.ok(DODATKI_PANELU.includes(d), `dodatek ${d} spoza listy`);
  assert.match(zrodlo, /sprawdzPanelMarki\(\{[^}]*szerokosci: czesc\.szerokosci/);
  assert.match(zrodlo, /sprawdzKompletnoscPaneluMarki\(\{[^}]*szerokosci: czesc\.szerokosci/);
});
