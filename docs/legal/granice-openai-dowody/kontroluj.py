import hashlib, json, os, pathlib, subprocess
root = pathlib.Path('/home/mateusz/flota/gpt-openai-granice-run')
out = pathlib.Path('/mnt/c/Users/matma/Documents/kuking-flota/gpt-openai-granice/docs/legal/granice-openai-dowody')
out.mkdir(exist_ok=True)
os.chdir(root)
os.environ.update(DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT='55439', DB_DATABASE='kuking_flota_gpt-openai-granice', DB_USERNAME='kuking', DB_PASSWORD='kuking')
os.environ['PATH'] = '/opt/kuking-php-8.4-avif/bin:' + os.environ['PATH']
cases = [
('wymiar', 'app/Moderacja/OcenaModelem.php', 'private const MAX_IMAGE_DIMENSION = 320;', 'private const MAX_IMAGE_DIMENSION = 4096;', 'Do atrapy wyslano obraz', 'OpenAiImageBoundaryTest'),
('zamiennik', 'app/Moderacja/OcenaModelem.php', "$wariant = $media->wariant('thumb');", "$selected = $media->wariantDoSerwowania('thumb'); $wariant = $selected === null ? null : ['key' => $selected['klucz']];", 'Do atrapy wyslano obraz', 'OpenAiImageBoundaryTest'),
('edycja', 'app/Http/Controllers/CommentController.php', 'PrzeanalizujTresc::dlaKomentarza($comment)->afterCommit();', '', 'job was not dispatched', 'CommentModerationBoundaryTest'),
('transakcja', 'app/Domain/Comments/Actions/DeleteComment.php', 'DB::transaction(function () use', '(static fn ($callback) => $callback())(function () use', 'przetrwalo awarie', 'CommentModerationBoundaryTest'),
('znacznik', 'app/Domain/Comments/Actions/DeleteComment.php', '$current->trashed() || $current->body_removed_at !== null', 'false', 'Entries found: 2', 'CommentModerationBoundaryTest'),
('uzupelnienie', 'app/Jobs/PrzeanalizujTresc.php', 'uzupelnij: $tresc instanceof Comment', 'uzupelnij: false', 'numer telefonu', 'CommentModerationBoundaryTest'),
]
results = []
for name, filename, old, new, expected, test in cases:
    p = root / filename
    before, stamp = p.read_bytes(), p.stat().st_mtime_ns
    args = ['bash', 'scripts/kontrola-ujemna.sh', '--nazwa', name, '--plik', filename, '--zamien', old, '--na', new, '--oczekuj', expected, '--json', str(out / (name+'.json')), '--', 'php', 'vendor/bin/phpunit', '--filter', test]
    run = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    import re
    (out / (name+'.txt')).write_text(re.sub(r'\x1b\[[0-9;]*m', '', run.stdout), encoding='utf-8')
    restored = p.read_bytes() == before and p.stat().st_mtime_ns == stamp
    results.append(dict(name=name, exit=run.returncode, restored_md5_mtime=restored, md5=hashlib.md5(before).hexdigest()))
    print(name, run.returncode, 'restored=', restored, flush=True)
    if run.returncode or not restored:
        print(run.stdout[-4000:])
        break
(out / 'wyniki.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
if len(results) != len(cases) or any(r['exit'] or not r['restored_md5_mtime'] for r in results):
    raise SystemExit(1)