#!/usr/bin/env python3
"""Kontrole regresji Alfa 0.8 i portu marki: w CI albo lokalnie na własnej bazie.

Cienki punkt wejścia. Kontrole żyją po jednej na plik w `scripts/kontrole_negatywne/`
(wzór w tamtejszym README.md), narzędzia i bramka lokalnego uruchomienia —
w `scripts/kontrole_negatywne/_narzedzia.py`. Ta ścieżka zostaje, bo woła ją CI,
dokumentacja i komunikaty strażnika.

  python3 scripts/kontrole-negatywne-alfa08.py           pełne kontrole (CI albo zgoda lokalna)
  python3 scripts/kontrole-negatywne-alfa08.py --lista   tryb suchy: lista, bez testów i bez mutacji
"""

import sys

# Bez `__pycache__/` w katalogu kontroli — ani w CI, ani na stanowiskach.
sys.dont_write_bytecode = True

from kontrole_negatywne import _narzedzia  # noqa: E402


if sys.argv[1:] == ["--lista"]:
    _narzedzia.lista()
elif sys.argv[1:]:
    raise SystemExit("Nieznane argumenty: " + " ".join(sys.argv[1:]) + ". Dozwolone: --lista.")
else:
    _narzedzia.uruchom()
