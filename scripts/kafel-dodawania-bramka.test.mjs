/* Regresja #484: uruchamiamy rzeczywistą bramkę w osobnych procesach Node.
   Dane pomiarowe nie zastępują piętnastu wariantów pomiaru przeglądarką. */
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = fileURLToPath(new URL('./kafel-dodawania.mjs', import.meta.url));
const marker = '/* --- BRAMKI POMIARU';
const fixture = () => ({
  widok: '320 px', pismo: '100%', kafel: { wysokosc: 100, szerokosc: 300 },
  wysokoscOkna: 740, szerokoscOkna: 320, szerokoscDokumentu: 320,
  pismoTytulu: 18, pismoPodpisu: 18, focus: { pokrycie: 0, pokrycieTopbar: 0 },
});
const cases = [
  ['niemieszczace', (w) => { w.kafel.wysokosc = 741; }],
  ['zaslonione', (w) => { w.focus.pokrycie = 1 / 16; }],
  ['zaMaleCele', (w) => { w.kafel.szerokosc = 47; }],
  ['zaMalyTytul', (w) => { w.pismoTytulu = 17; }],
  ['zaMalyPodpis', (w) => { w.pismoPodpisu = 17; }],
  ['przewijanie', (w) => { w.szerokoscDokumentu = 321; }],
];
function run(path, mutate = () => {}, previousError = false, count = 15) {
  const text = readFileSync(path, 'utf8');
  assert.equal(text.split(marker).length, 2, 'Brak jednoznacznej bramki.');
  const rows = Array.from({ length: count }, fixture);
  if (rows.length) mutate(rows[0]);
  const prefix = 'const wiersze=' + JSON.stringify(rows)
    + '; const SZEROKOSCI=Array(5); const WARIANTY_PISMA=Array(3); let bylBlad=' + previousError + ';\n';
  const result = spawnSync(process.execPath, ['--input-type=module', '--eval', prefix + text.slice(text.indexOf(marker))], { encoding: 'utf8', timeout: 10000 });
  if (result.error) throw result.error;
  assert.equal(result.signal, null);
  if (count === 15) assert.equal(result.stderr, '', 'Awaria procesu nie dowodzi działania bramki.');
  else assert.match(result.stderr, /zebrano .* pomiarów zamiast/);
  return result.status;
}
assert.equal(run(source), 0, 'Poprawne 15 pomiarów');
for (const [category, mutate] of cases) assert.equal(run(source, mutate), 1, category);
assert.equal(run(source, (w) => { w.focus.pokrycieTopbar = 1 / 16; }), 1, 'Górna nakładka');
assert.equal(run(source, (w) => { w.kafel.wysokosc = 47; }), 1, 'Wysokość celu');
assert.equal(run(source, () => {}, true), 1, 'Wcześniejszy błąd');
assert.equal(run(source, () => {}, false, 14), 1, 'Niepełny pomiar');
assert.equal(run(source, () => {}, false, 0), 1, 'Brak pomiarów');

const directory = mkdtempSync(join(tmpdir(), 'kuking-bramka-'));
const copy = join(directory, 'kafel-dodawania.mjs');
const backup = join(directory, 'oryginal.mjs');
const md5 = (path) => createHash('md5').update(readFileSync(path)).digest('hex');
const original = md5(source);
try {
  execFileSync('cp', [source, backup]);
  execFileSync('cp', [backup, copy]);
  for (const [category, mutate] of cases) {
    const before = md5(copy);
    try {
      const text = readFileSync(copy, 'utf8');
      const list = '[niemieszczace, zaslonione, zaMaleCele, zaMalyTytul, zaMalyPodpis, przewijanie]';
      assert.equal(text.split(list).length, 2);
      writeFileSync(copy, text.replace(list, list.replace(category, '[]')));
      const changed = md5(copy);
      assert.notEqual(changed, before);
      const status = run(copy, mutate);
      assert.equal(status, 0, 'Mutacja musi odtworzyć fałszywy sukces.');
      assert.throws(() => assert.equal(status, 1, category), { name: 'AssertionError' });
      console.log('Kontrola ujemna ' + category + ': wykryta regresja exit=' + status + '; przed=' + before + '; mutacja=' + changed);
    } finally {
      execFileSync('cp', [backup, copy]);
      assert.equal(md5(copy), before);
      console.log('Przywrócono źródło: ' + md5(copy));
    }
    assert.equal(run(copy, mutate), 1, category + ' po przywróceniu');
  }
} finally {
  rmSync(directory, { recursive: true, force: true });
  assert.equal(md5(source), original, 'Źródło w repo zostało naruszone.');
}
console.log('Bramka: sześć kategorii, obie nakładki, oba wymiary celu, wcześniejszy błąd i niepełny pomiar — OK.');
