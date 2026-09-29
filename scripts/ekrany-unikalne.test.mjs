#!/usr/bin/env node
/*
 * Test kontroli unikalności ekranów (#611, etap 4).
 * Uruchomienie: node scripts/ekrany-unikalne.test.mjs
 * (chodzi także z PHP: tests/Feature/EkranyDostepnosciSaUnikalneTest.php)
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { duplikatyEkranow, wymagajUnikalnychEkranow } from './ekrany-unikalne.mjs';

assert.deepEqual(duplikatyEkranow([{ nazwa: 'a' }, { nazwa: 'b' }]), []);
assert.doesNotThrow(() => wymagajUnikalnychEkranow([{ nazwa: 'a' }, { nazwa: 'b' }], 'X'));

// Kontrola ujemna: powtórzona nazwa MUSI zostać wykryta i wywrócić start.
assert.deepEqual(duplikatyEkranow([{ nazwa: 'a' }, { nazwa: 'b' }, { nazwa: 'a' }, { nazwa: 'a' }]), ['a']);
assert.throws(
  () => wymagajUnikalnychEkranow([{ nazwa: 'ostrzeżenie' }, { nazwa: 'ostrzeżenie' }], 'EKRANY_UKLADU'),
  /powtórzone ekrany: „ostrzeżenie"/,
);

console.log('ekrany-unikalne: OK');

// Prawdziwy plik: obie listy są sprawdzane przy starcie, a wpisy pisane
// wprost w EKRANY i EKRANY_UKLADU nie powtarzają nazw (spread `...EKRANY`
// w EKRANY_UKLADU pilnuje wywołanie w czasie działania).
const zrodlo = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'dostepnosc.mjs'), 'utf8');

assert.match(zrodlo, /wymagajUnikalnychEkranow\(EKRANY, 'EKRANY'\);/);
assert.match(zrodlo, /wymagajUnikalnychEkranow\(EKRANY_UKLADU, 'EKRANY_UKLADU'\);/);

const wpisy = (nazwaListy) => {
  const start = zrodlo.indexOf(`const ${nazwaListy} = [`);
  const koniec = zrodlo.indexOf('\n];', start);
  assert.ok(start > 0 && koniec > start, `nie znaleziono listy ${nazwaListy}`);
  return [...zrodlo.slice(start, koniec).matchAll(/^ {2}\{ nazwa: '([^']+)'/gm)].map((m) => ({ nazwa: m[1] }));
};

assert.ok(wpisy('EKRANY').length > 5, 'lista EKRANY nie może być pusta');
assert.deepEqual(duplikatyEkranow(wpisy('EKRANY')), []);
assert.deepEqual(
  duplikatyEkranow([...wpisy('EKRANY'), ...wpisy('EKRANY_UKLADU')]),
  [],
  'ekran w EKRANY_UKLADU dubluje wpis z EKRANY',
);

console.log('ekrany-unikalne (źródło dostepnosc.mjs): OK');
