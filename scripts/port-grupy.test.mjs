import test from 'node:test';
import assert from 'node:assert/strict';
import { wybierzGrupe, wykonajGrupe } from './port-grupy.mjs';

for (const [wybor, expected] of [
  [undefined, ['baza', 'rozszerzenia', 'baza']],
  ['baza', ['baza', 'baza']],
  ['rozszerzenia', ['rozszerzenia']],
]) {
  test(`wykonuje tylko wybrane pomiary: ${wybor ?? 'domyślnie'}`, async () => {
    const wykonane = [];
    for (const nazwa of ['baza', 'rozszerzenia', 'baza']) {
      await wykonajGrupe(wybierzGrupe(wybor), nazwa, async () => wykonane.push(nazwa));
    }
    assert.deepEqual(wykonane, expected);
  });
}
test('nieznana grupa odrzucana przed wywołaniem pomiaru', async () => {
  let wywolany = false;
  assert.throws(() => wybierzGrupe('literowka'), /Nieznana PORT_GRUPA/);
  await assert.rejects(wykonajGrupe('literowka', 'baza', async () => { wywolany = true; }), /Nieznana PORT_GRUPA/);
  assert.equal(wywolany, false);
});
