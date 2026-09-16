"""Porównanie feedu na izolowanej kopii wykonawczej; nie uruchamia migracji."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import fcntl

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', required=True)
parser.add_argument('--manifest', type=Path, required=True)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--repetitions', type=int, default=3)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
if args.output.exists():
    parser.error('Plik wyniku już istnieje; wybierz nowy, aby zachować dowody.')
if args.repetitions < 1:
    parser.error('Liczba powtórzeń musi być dodatnia.')
manifest = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
if set(manifest['viewers']) != {'10', '100', '500', '2000'}:
    parser.error('Manifest wymaga czterech kont pomiarowych.')
evidence = root / 'docs/infra/evidence/feed585'
expected = json.loads((evidence / 'variants.json').read_text())
source = root / 'app/Domain/Feed/FollowingFeed.php'
lock = (root / 'storage/framework/feed-benchmark.lock').open('a')
try:
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    parser.error('Inny pomiar korzysta z tej kopii wykonawczej.')
original = source.read_bytes()
stamp = source.stat().st_mtime_ns
sha = lambda data: hashlib.sha256(data).hexdigest()
source_manifest = {}
for directory in ['app', 'config', 'database/migrations']:
    for path in sorted((root / directory).rglob('*.php')):
        source_manifest[str(path.relative_to(root))] = sha(path.read_bytes())
for filename in ['composer.lock', 'scripts/pomiar-feedu.php', 'scripts/porownaj-feed.py', 'scripts/dane-pomiaru-feedu.php', 'scripts/wzbogac-dane-feedu.php', 'scripts/sprawdz-dane-feedu.php', 'scripts/media-pomiaru-feedu.php', 'docs/infra/evidence/feed585/variants.json', 'docs/infra/evidence/feed585/exists.patch', 'docs/infra/evidence/feed585/join.patch']:
    source_manifest[filename] = sha((root / filename).read_bytes())
if sha(original) != expected['exists']['baseline_sha256']:
    parser.error('Źródło bazowe nie odpowiada zatwierdzonym wariantom.')
env = os.environ | {'APP_BASE_PATH': str(root)}
# Guard w PHP sprawdza rzeczywisty driver, host, port, nazwę bazy i środowisko.
backup = Path(tempfile.mkdtemp(prefix='kuking-feed-backup-')) / source.name
shutil.copy2(source, backup)
results, oracle = [], {}
failure = None
restored = False
fixture_counts = None
try:
    probe = subprocess.run([args.php, 'scripts/sprawdz-dane-feedu.php'], cwd=root, env=env, capture_output=True, text=True)
    if probe.returncode:
        raise RuntimeError(f'Kontrola danych zakończyła się kodem {probe.returncode}: ' + probe.stdout + probe.stderr)
    fixture_counts = json.loads(probe.stdout)
    for repetition in range(args.repetitions):
        order = ['baseline', 'exists', 'join']
        if repetition % 2:
            order.reverse()
        for variant in order:
            shutil.copy2(backup, source)
            if variant != 'baseline':
                subprocess.run(['patch', '--batch', '--forward', str(source), str(evidence / (variant + '.patch'))], check=True, capture_output=True)
                if sha(source.read_bytes()) != expected[variant]['variant_sha256']:
                    raise RuntimeError('Hash wariantu nie odpowiada wzorcowi.')
            for count, viewer in manifest['viewers'].items():
                process = subprocess.run([args.php, 'scripts/pomiar-feedu.php', viewer, 'following'], cwd=root, env=env, capture_output=True, text=True)
                if process.returncode:
                    raise RuntimeError(f'Proces pomiaru zakończył się kodem {process.returncode}: ' + process.stdout + process.stderr)
                result = json.loads(process.stdout)
                if result['source_sha256'] != sha(source.read_bytes()) or result['following_count'] != int(count):
                    raise RuntimeError('Pomiar użył niewłaściwego źródła lub konta.')
                signature = {key: result[key] for key in ['rows', 'next_cursor', 'previous_cursor']}
                if not result['rows'] or signature != oracle.setdefault(count, signature):
                    raise RuntimeError('Wariant zmienił wiersze, liczniki lub kursor.')
                results.append({'repetition': repetition, 'variant': variant, 'follows': int(count), 'result': result})
                print(repetition, variant, count, flush=True)
except Exception as error:
    failure = str(error)
finally:
    try:
        shutil.copy2(backup, source)
        restored = source.read_bytes() == original and source.stat().st_mtime_ns == stamp
        if not restored:
            raise RuntimeError('Nie udało się potwierdzić przywrócenia źródła.')
    except Exception as error:
        failure = (failure or '') + ' Przywrócenie: ' + str(error)
args.output.write_text(json.dumps({'success': failure is None and restored, 'error': failure, 'backup': str(backup), 'restored': restored, 'restored_md5': hashlib.md5(original).hexdigest() if restored else None, 'source_manifest': source_manifest, 'fixture_manifest': manifest, 'fixture_counts': fixture_counts, 'runs': results}, indent=2))
if failure is not None:
    raise SystemExit(failure)
