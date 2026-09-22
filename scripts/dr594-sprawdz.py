#!/usr/bin/env python3
"""Kontrole dodatnie/ujemne przyrządu i istniejącego restore na lokalnej kopii."""
import json
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
artifact = Path(sys.argv[1]).resolve()
if artifact.parent != Path('/home/mateusz/flota/gpt-dr-baza-artifacts'):
    raise SystemExit('Wskaż katalog własnej lokalnej próby.')
receipt = json.loads((artifact / 'receipt.json').read_text())
target = receipt['target']
if not re.fullmatch(r'proba_odtworzenia_gpt_dr_baza_[0-9]{8}t[0-9]{6}z', target):
    raise SystemExit('Nieprawidłowa nazwa celu. Przerwij kontrolę.')
# Wczytanie funkcji sprawdza jawny host/port/źródło, ale nie uruchamia main.
meter = runpy.run_path(str(ROOT / 'scripts/dr594-pomiar.py'))
env, sql = meter['ENV'], meter['sql']
results = {}


def check(name, command, expected, message):
    process = subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True)
    output = process.stdout + process.stderr
    output = re.sub(r'\x1b\[[0-9;]*m', '', output)
    (artifact / (name + '.txt')).write_text(output, encoding='utf-8')
    results[name] = {'exit_code': process.returncode, 'expected': expected,
                     'expected_message_found': message in output}
    if process.returncode != expected or message not in output:
        raise RuntimeError(f'{name}: sprawdź dowód, otrzymano {process.returncode}, oczekiwano {expected}.')
    print(name + ': zgodny kod i przyczyna', flush=True)


check('negative-row', ['bash', 'scripts/dr594-kontrole.sh', target], 0, 'KONTROLA ZALICZONA')
check('generator-repeat', ['psql', '-X', '-d', meter['SOURCE'], '-f', 'scripts/dr594-dane.sql'],
      3, 'Dane próby już istnieją')
check('generator-wrong-target', ['psql', '-X', '-d', target, '-f', 'scripts/dr594-dane.sql'],
      3, 'Wybierz własną bazę')

# Liczniki nie wystarczą: podmiana treści jednego komentarza zostawia liczbę wierszy.
before = json.loads((artifact / 'target-manifest.json').read_text())
original = sql(target, "SELECT body FROM comments WHERE id=md5('dr594:comment:1')::uuid")
if not original:
    raise RuntimeError('Brak komentarza do kontroli. Nie wykonuj mutacji.')
try:
    sql(target, "UPDATE comments SET body='Celowo zmieniona treść kontroli DR594' WHERE id=md5('dr594:comment:1')::uuid")
    after = meter['content_manifest'](target)
    changed = [name for name in before if before[name] != after[name]]
    if changed != ['comments']:
        raise RuntimeError(f'Kontrola treści wskazała niewłaściwe tabele: {changed}')
    results['same-count-different-content'] = {'changed_tables': changed, 'caught': True}
finally:
    quoted = original.replace("'", "''")
    sql(target, f"UPDATE comments SET body='{quoted}' WHERE id=md5('dr594:comment:1')::uuid")
restored = meter['content_manifest'](target)
if before != restored:
    raise RuntimeError('Nie przywrócono dokładnej treści po kontroli. Zachowaj bazę do diagnozy.')
results['content-restored'] = {'equal': True, 'tables': len(restored)}
print('Podmiana treści wykryta; po przywróceniu wszystkie tabele zgodne.', flush=True)

bad_dir = artifact / 'corruption'
bad_dir.mkdir(exist_ok=False, mode=0o700)
archive = next((artifact / 'backup').glob('*.dump.cms'))
bad = bad_dir / archive.name
shutil.copyfile(archive, bad)
shutil.copyfile(archive.with_suffix('').with_suffix('.meta'), bad.with_suffix('').with_suffix('.meta'))
with bad.open('r+b') as stream:
    stream.seek(bad.stat().st_size // 2)
    value = stream.read(1)
    stream.seek(-1, 1)
    stream.write(bytes([value[0] ^ 1]))
bad_target = target + '_uszkodzony'
check('corrupt-archive', ['bash', 'scripts/proba-odtworzenia.sh', '--zrzut', str(bad),
      '--klucz', str(artifact / 'private.pem'), '--serwer', meter['SERVER_DSN'], '--baza', bad_target],
      44, 'NIE ZGADZA SIĘ ze skrótem')
if sql('postgres', f"SELECT count(*) FROM pg_database WHERE datname='{bad_target}'") != '0':
    raise RuntimeError('Uszkodzona kopia utworzyła bazę celu.')
results['corrupt-archive']['database_created'] = False
(artifact / 'controls.json').write_text(json.dumps(results, indent=2, ensure_ascii=False) + '\n')
print('Wszystkie kontrole zaliczone. Dowody: ' + str(artifact))
