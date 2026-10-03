#!/usr/bin/env python3
"""#2855: preflight celu kontroli Dwa bez bazy i bez mutowania źródeł."""

import os
from pathlib import Path
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/kontrola-negatywna-2855.py"


def sprawdz(nazwa: str, dozwolona: bool, *, lokalnie: bool = False, url: str = "", port: str = "55439", zgoda: str = "1") -> None:
    env = os.environ.copy()
    env.update({
        "CI": "false" if lokalnie else "true",
        "KUKING_KONTROLA_2855_LOKALNIE": zgoda if lokalnie else "",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": port,
        "DB_DATABASE": nazwa,
        "DB_URL": url,
    })
    result = subprocess.run(
        [sys.executable, str(SCRIPT), "--sprawdz-cel"],
        cwd=ROOT, env=env, capture_output=True, text=True, check=False,
    )
    if (result.returncode == 0) != dozwolona:
        raise AssertionError(f"#2855: błędna ocena celu {nazwa!r}: {result.stdout}{result.stderr}")


for name in ("kuking_race", "kuking_race_agent_01"):
    sprawdz(name, True)
for name in ("kuking", "kuking_test", "kuking_race_", "kuking_race-prod", "kuking_raceX", "kuking_race/obca"):
    sprawdz(name, False)
sprawdz("kuking_race", False, url="postgres://127.0.0.1/inna")
sprawdz("kuking_race_agent_01", True, lokalnie=True)
sprawdz("kuking_race_agent_01", False, lokalnie=True, port="5432")
sprawdz("kuking_race_agent_01", False, lokalnie=True, zgoda="")
print("#2855: CI i lokalna izolacja celu — 12 przypadków PASS.")
