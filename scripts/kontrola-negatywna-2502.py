#!/usr/bin/env python3
"""#2502: rzeczywisty DOM ma odrzucić dawny klucz bez kontekstu porcji."""

import os
from pathlib import Path
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parent.parent
MODUL = ROOT / "resources/js/skladniki-gotowania.js"
TEST = "scripts/przegladarka/skladniki-gotowania.test.mjs"
KOMENDA = ["node", "--test", "--test-name-pattern=zmiana porcji wymaga", TEST]
ZRODLO = MODUL.read_text(encoding="utf-8")
ORIGINAL = "return `kuking.skladniki.${recipeId}.${porcje}`;"
MUTACJA = "return `kuking.skladniki.${recipeId}`;"
MARKER = "Nowa ilość nie jest automatycznie przygotowana."

if ZRODLO.count(ORIGINAL) != 1:
    raise SystemExit("#2502: nie znaleziono dokładnie jednego miejsca mutacji klucza.")


def przebieg(modul):
    env = dict(os.environ)
    env["SKLADNIKI_MODUL"] = str(modul)
    wynik = subprocess.run(KOMENDA, cwd=ROOT, env=env, capture_output=True, text=True, timeout=120)
    return wynik.returncode, wynik.stdout + wynik.stderr


kod, log = przebieg(MODUL)
if kod != 0 or "# tests 1" not in log or "# pass 1" not in log:
    sys.stderr.write(log)
    raise SystemExit("#2502: dodatni test DOM nie przeszedł; brak dowodu mutacji.")

with tempfile.TemporaryDirectory(prefix="kuking-2502-") as katalog:
    mutant = Path(katalog) / "skladniki-gotowania.js"
    mutant.write_text(ZRODLO.replace(ORIGINAL, MUTACJA), encoding="utf-8")
    kod, log = przebieg(mutant)

if kod == 0 or MARKER not in log or "# tests 1" not in log or "# fail 1" not in log:
    sys.stderr.write(log)
    raise SystemExit("#2502: mutant nie oblał dokładnie na starej ilości; brak dowodu.")

print("#2502: dodatni test DOM PASS; dawny klucz bez porcji oblał na oczekiwanym markerze.")
