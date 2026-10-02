#!/bin/bash
# Preflight kotwic kontroli negatywnych (jak w CI), bez uruchamiania testów.
# Uruchom w katalogu głównym repozytorium. Wynik: „kontroli N zlych 0”.
KUKING_KONTROLE_LOKALNIE=1 DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_pre \
PYTHONPATH="$PWD/scripts" python3 - <<'PY'
import sys, os
src = open('scripts/kontrole-negatywne-alfa08.py').read()
code = src[:src.index('# PREFLIGHT KOTWIC')] + """
bad = []
for label, filename, _test, mutate in checks:
    try:
        source = (ROOT / filename).read_text()
        if mutate(source) == source: raise RuntimeError('mutacja nic nie zmienia')
    except Exception as e: bad.append((label, filename, str(e)))
print('kontroli', len(checks), 'zlych', len(bad))
for b in bad: print(b)
"""
sys.argv = ['preflight']
exec(compile(code, 'scripts/kontrole-negatywne-alfa08.py', 'exec'),
     {'__name__': 'preflight', '__file__': os.path.abspath('scripts/kontrole-negatywne-alfa08.py')})
PY
