#!/usr/bin/env python3
"""Kontrole regresji Alfa 0.8 i portu marki: w CI albo lokalnie na własnej bazie.

Cienki punkt wejścia. Kontrole żyją po jednej na plik w `scripts/kontrole_negatywne/`
(wzór w tamtejszym README.md), narzędzia i bramka lokalnego uruchomienia —
w `scripts/kontrole_negatywne/_narzedzia.py`. Ta ścieżka zostaje, bo woła ją CI,
dokumentacja i komunikaty strażnika.

  python3 scripts/kontrole-negatywne-alfa08.py           pełne kontrole (CI albo zgoda lokalna)
  python3 scripts/kontrole-negatywne-alfa08.py --lista   tryb suchy: lista, bez testów i bez mutacji

NIE DOPISUJ TU KONTROLI. Od 25.09.2026 ten plik nie ma listy `checks`, stałych
ani `run_test(...)`. Gałąź sprzed podziału, która dopisywała tu wpis, dostaje
przy scaleniu main konflikt; instrukcja: docs/flota/PRZENIESIENIE_PO_PODZIALE.md.
"""

from pathlib import Path
import re
import sys

# Bez `__pycache__/` w katalogu kontroli — ani w CI, ani na stanowiskach.
sys.dont_write_bytecode = True

# Scalenie, które zostawiło tu wpisy w starym układzie (np. rozwiązane „weź
# moje" albo dopisek po `uruchom()`), kończy się czytelną odmową zamiast
# `NameError` w środku kroku CI albo cichego pominięcia nowej kontroli.
_STARY_UKLAD = re.compile(r"^(checks\s*=|run_test\(|def |[A-Z][A-Z0-9_]*\s*=)", re.M)
_wlasny = Path(__file__).read_text(encoding="utf-8")
_trafienie = _STARY_UKLAD.search(_wlasny)
if _trafienie:
    _wiersz = _wlasny.count("\n", 0, _trafienie.start()) + 1
    raise SystemExit(
        f"{Path(__file__).name}:{_wiersz}: wpis kontroli w starym pliku ({_trafienie.group(0).strip()!r}).\n"
        "Ten plik jest tylko punktem wejścia. Przenieś wpis do NOWEGO pliku\n"
        "  scripts/kontrole_negatywne/kNN_<obszar>.py\n"
        "według wzoru z scripts/kontrole_negatywne/README.md (stałe, mutacja,\n"
        "KONTROLE_DODATNIE, KONTROLE = [Kontrola(...)]), a ten plik weź z main:\n"
        "  git checkout origin/main -- scripts/kontrole-negatywne-alfa08.py\n"
        "Kroki: docs/flota/PRZENIESIENIE_PO_PODZIALE.md"
    )

from kontrole_negatywne import _narzedzia  # noqa: E402


if sys.argv[1:] == ["--lista"]:
    _narzedzia.lista()
elif sys.argv[1:]:
    raise SystemExit("Nieznane argumenty: " + " ".join(sys.argv[1:]) + ". Dozwolone: --lista.")
else:
    _narzedzia.uruchom()
