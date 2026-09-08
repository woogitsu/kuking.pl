from pathlib import Path
import json, subprocess, hashlib

root = Path.cwd()
out = root / 'audit-evidence'
out.mkdir(exist_ok=True)
results = []

def run(label, args):
    p = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    (out / (label + '.log')).write_text(p.stdout)
    return p.returncode

cases = [
    ('fillable', 'app/Models/User.php', "protected $fillable = [", "protected $fillable = ['status', 'role',", ['tests/Feature/SecurityTest.php']),
    ('media-open', 'app/Domain/Media/DostepDoZdjecia.php', "public function moze(?User $widz, Media $zdjecie): bool\n    {", "public function moze(?User $widz, Media $zdjecie): bool\n    {\n        return true;", ['tests/Feature/DostepDoZdjeciaBezPolicyRodzicaTest.php', 'tests/Feature/ZdjeciaChronioneNieWyciekajaTest.php']),
    ('csrf-exception', 'bootstrap/app.php', "validateCsrfTokens(except: ['_csp'])", "validateCsrfTokens(except: [])", ['tests/Feature/PolitykaBezpieczenstwaTest.php']),
]
for label, name, old, new, tests in cases:
    file = root / name
    original = file.read_bytes()
    text = original.decode()
    assert text.count(old) == 1, (name, 'Nie znaleziono jednoznacznego miejsca mutacji')
    baseline = {}
    for test in tests:
        baseline[test] = run(label + '-before-' + Path(test).stem, ['php', 'artisan', 'test', test])
    assert all(code == 0 for code in baseline.values()), (label, 'Brak zielonej kontroli przed mutacja')
    mutants = {}
    try:
        file.write_text(text.replace(old, new, 1))
        assert run(label + '-syntax', ['php', '-l', name]) == 0
        for test in tests:
            mutants[test] = run(label + '-mutated-' + Path(test).stem, ['php', 'artisan', 'test', test])
    finally:
        file.write_bytes(original)
    after = {}
    for test in tests:
        after[test] = run(label + '-after-' + Path(test).stem, ['php', 'artisan', 'test', test])
    results.append({'mutation': label, 'file': name, 'baseline': baseline, 'mutant': mutants, 'restored': after, 'byte_equal_after_restore': file.read_bytes() == original, 'sha256': hashlib.sha256(original).hexdigest()})
    (out / 'mutations.json').write_text(json.dumps(results, indent=2))
assert all(all(c == 0 for c in r['restored'].values()) and r['byte_equal_after_restore'] for r in results)
