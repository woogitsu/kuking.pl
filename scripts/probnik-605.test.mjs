import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

// Wykonujemy rzeczywisty blok obliczeń próbnika, bez Dockera i cudzych procesów.
const source = readFileSync(new URL('./probnik-obciazenia-605.sh', import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const start = source.indexOf('  RDZENIE_KONT=0;');
const end = source.indexOf('\n  printf', start);
assert.ok(start > 0 && end > start, 'Kontrola musi odnaleźć i wykonać blok próbnika');
const block = source.slice(start, end);
function sample(pid, previousPid, ticks, previousTicks, nextWindow = false) {
  const setup = `poprzedni_czas=1; MONO=2; TAKTY=100; CPU_USEC=1000000; poprzedni_cpu=0;
PID_WEB=${pid}; PID_WORKER=${pid}; poprzedni_pid_web=${previousPid}; poprzedni_pid_worker=${previousPid};
WEB_T=${ticks}; WRK_T=${ticks}; poprzedni_web=${previousTicks}; poprzedni_worker=${previousTicks};
HOST_T=100; poprzedni_host=0; GEN_T=0; poprzedni_gen=0; SAM_T=0; poprzedni_sam=0;`;
  const next = nextWindow ? `\nMONO=3; WEB_T=${ticks + 100}; WRK_T=${ticks + 100};\n${block}` : '';
  const output = execFileSync('bash', ['-c', setup + '\n' + block + next + '\nprintf "[%s,%s]" "$RDZENIE_WEB" "$RDZENIE_WRK"'], { encoding: 'utf8' });
  return JSON.parse(output);
}
assert.deepEqual(sample(100, 100, 150, 50), [1, 1], 'Dodatnia kontrola musi zmierzyć wzrost CPU tego samego procesu');
assert.deepEqual(sample(101, 100, 10, 1000), [null, null], 'Nowy proces nie może mieć ujemnego CPU po starym');
assert.deepEqual(sample(101, 100, 2000, 1000), [null, null], 'Zmiana PID nie może udawać poprawnej dodatniej różnicy');
assert.deepEqual(sample(0, 100, 0, 1000), [null, null], 'Brak procesu oznacza brak pomiaru, nie ujemne CPU');
assert.deepEqual(sample(100, 100, 1, 1000), [null, null], 'Cofnięty licznik wymaga odrzucenia próbki');
assert.deepEqual(sample(101, 101, 250, 150), [1, 1], 'Po zmianie procesu kolejne pełne okno znów mierzy CPU');
assert.deepEqual(sample(101, 100, 10, 1000, true), [1, 1], 'Próbnik musi zapamiętać nowy PID dla następnego okna');
console.log('PASS: próbnik mierzy pełne okna tego samego procesu i jawnie odrzuca reset licznika');
