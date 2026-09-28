#!/usr/bin/env python3
"""Wymagana bramka #2167: dokładna allowlista skipów z raportu PHPUnit JUnit."""

import argparse
from datetime import date
import json
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def pominiete(raport: Path) -> dict[str, str]:
    korzen = ET.parse(raport).getroot()
    wynik: dict[str, str] = {}
    for test in korzen.iter("testcase"):
        skip = test.find("skipped")
        if skip is None:
            continue
        nazwa = f"{test.get('class', '')}::{test.get('name', '')}"
        powod = (skip.get("message") or "").strip() or " ".join("".join(skip.itertext()).split())
        if not nazwa.strip(":") or not powod:
            raise ValueError(f"Pominięty test bez nazwy lub powodu: {nazwa!r}")
        if nazwa in wynik and wynik[nazwa] != powod:
            raise ValueError(f"Dwa różne powody pominięcia dla {nazwa}")
        wynik[nazwa] = powod
    return wynik


def sprawdz(raport: Path, allowlista: Path) -> tuple[bool, str]:
    dane = json.loads(allowlista.read_text(encoding="utf-8"))
    if not isinstance(dane, dict) or not isinstance(dane.get("skipy"), dict):
        raise ValueError("Allowlista wymaga obiektu skipy: test => {powod, issue, do}.")
    oczekiwane = dane["skipy"]
    faktyczne = pominiete(raport)
    bledy = []
    for test, powod in sorted(faktyczne.items()):
        wpis = oczekiwane.get(test)
        if not isinstance(wpis, dict) or wpis.get("powod") != powod:
            bledy.append(f"NIEZNANY SKIP: {test}: {powod}")
    for test, wpis in sorted(oczekiwane.items()):
        if not isinstance(wpis, dict) or not all(wpis.get(pole) for pole in ("powod", "issue", "do")):
            bledy.append(f"NIEPEŁNA ALLOWLISTA: {test}")
        else:
            try:
                if date.fromisoformat(wpis["do"]) < date.today():
                    bledy.append(f"WYGASŁY WYJĄTEK: {test}: {wpis['do']}")
            except (TypeError, ValueError):
                bledy.append(f"BŁĘDNA DATA WYJĄTKU: {test}")
    tekst = f"Pominięte testy: {len(faktyczne)}\n"
    tekst += "".join(f"- {test}: {powod}\n" for test, powod in sorted(faktyczne.items()))
    tekst += "".join(f"- {blad}\n" for blad in bledy)
    return not bledy, tekst


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("raport", type=Path)
    parser.add_argument("--allowlista", type=Path, default=Path("tests/pominiete-allowlista.json"))
    args = parser.parse_args()
    try:
        ok, tekst = sprawdz(args.raport, args.allowlista)
    except (ET.ParseError, OSError, ValueError, json.JSONDecodeError) as blad:
        print(f"Nie można sprawdzić skipów: {blad}", file=sys.stderr)
        return 1
    print(tekst, end="")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
