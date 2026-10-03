#!/usr/bin/env python3
"""#2783: kontrola celu dopuszcza bazę CI i odmawia obcym nazwom bez połączenia."""

import os
from pathlib import Path
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[2]
SKRYPT = ROOT / "scripts/kontrola-negatywna-2783.py"


def sprawdz(nazwa: str, dozwolona: bool, *, lokalnie: bool = False, url: str = "", port: str = "55439") -> None:
    env = os.environ.copy()
    env.update({
        "CI": "false" if lokalnie else "true",
        "KUKING_KONTROLA_2783_LOKALNIE": "1" if lokalnie else "",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": port,
        "DB_DATABASE": nazwa,
        "DB_URL": url,
    })
    wynik = subprocess.run(
        [sys.executable, str(SKRYPT), "--sprawdz-cel"],
        cwd=ROOT, env=env, capture_output=True, text=True, check=False,
    )
    if (wynik.returncode == 0) != dozwolona:
        raise AssertionError(f"#2783: błędna ocena celu {nazwa!r}: {wynik.stdout}{wynik.stderr}")


for baza in ("kuking_race", "kuking_race_agent_01"):
    sprawdz(baza, True)
for baza in ("kuking", "kuking_test", "kuking_race_", "kuking_race-prod", "kuking_raceX", "kuking_race/obca"):
    sprawdz(baza, False)
sprawdz("kuking_race", False, url="postgres://127.0.0.1/inna")
sprawdz("kuking_race_agent_01", True, lokalnie=True)
sprawdz("kuking_race_agent_01", False, lokalnie=True, port="5432")
print("#2783: CI i lokalna izolacja celu — 11 przypadków PASS.")
