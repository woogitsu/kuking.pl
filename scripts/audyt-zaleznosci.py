#!/usr/bin/env python3
"""Bramka audytu zależności (#2215): high/critical blokuje, wyjątek jest jawny i wygasa.

Wejście: JSON z `composer audit --locked --format=json` i `npm audit --json`.
Wyjątki: `.github/wyjatki-audytu.json` (patrz docs/infra/BRAMKI_CI_2215.md).

Zasady (fail-closed):
- Composer: blokuje każda podatność bez znanego niskiego/średniego poziomu
  (brak pola `severity` = blokuje, bo nie da się jej ocenić).
- npm: blokuje `high` i `critical` (jak dotychczasowe `--audit-level=high`).
- Podatność z ważnym wyjątkiem (ten sam identyfikator: CVE, GHSA, PKSA) nie blokuje.
- Wyjątek bez wymaganego pola, z terminem w przeszłości albo dalszym niż
  30 dni od dziś blokuje — wyjątek ma być świadomym, krótkim wyborem.
- Plik wyniku nie jest poprawnym JSON-em (narzędzie nie zadziałało) — blokuje.
Kod wyjścia: 0 = bramka przepuszcza, 1 = blokuje, 2 = błąd wywołania.
"""

import argparse
import datetime
import json
import sys
from pathlib import Path

MAKS_DNI_WYJATKU = 30
POLA_WYJATKU = ("id", "narzedzie", "zakres", "wlasciciel", "zgoda", "wygasa")
NISKIE = {"low", "moderate", "medium", "info"}


def wczytaj(sciezka, nazwa):
    try:
        dane = json.loads(Path(sciezka).read_text(encoding="utf-8"))
    except (OSError, ValueError) as blad:
        raise BladWejscia(f"{nazwa}: brak poprawnego JSON-a wyniku audytu ({blad}). Bramka nie zgaduje — blokuje.")
    if not isinstance(dane, dict):
        raise BladWejscia(f"{nazwa}: wynik audytu nie jest obiektem JSON.")
    return dane


class BladWejscia(Exception):
    pass


def podatnosci_composera(dane):
    """Lista (pakiet, tytuł, poziom, zbiór identyfikatorów) z `composer audit`."""
    wynik = []
    advisories = dane.get("advisories", {})
    if isinstance(advisories, list):  # pusty wynik bywa `[]`
        advisories = {}
    for pakiet, lista in advisories.items():
        for a in lista:
            ids = {a.get("advisoryId"), a.get("cve"), a.get("link")}
            for zrodlo in a.get("sources") or []:
                ids.add(zrodlo.get("remoteId"))
            wynik.append((pakiet, a.get("title", "?"), str(a.get("severity") or "").lower(), {i for i in ids if i}))
    return wynik


def podatnosci_npm(dane):
    """Lista (pakiet, tytuł, poziom, zbiór identyfikatorów) z `npm audit --json`.

    Bierzemy tylko wpisy `via` będące obiektami (prawdziwe zalecenia); wpis
    tranzytywny, który wskazuje wyłącznie inny pakiet, byłby liczony podwójnie.
    """
    wynik = []
    for pakiet, v in (dane.get("vulnerabilities") or {}).items():
        for via in v.get("via") or []:
            if not isinstance(via, dict):
                continue
            ids = {str(via.get("source")) if via.get("source") is not None else None, via.get("url")}
            url = via.get("url") or ""
            if "/advisories/" in url:
                ids.add(url.rsplit("/", 1)[-1])
            wynik.append((pakiet, via.get("title", "?"), str(via.get("severity") or "").lower(), {i for i in ids if i}))
    return wynik


def sprawdz_wyjatki(dane, dzis):
    """Zwraca (ważne wyjątki jako lista, lista błędów)."""
    wazne, bledy = [], []
    for i, w in enumerate(dane.get("wyjatki", []), 1):
        brak = [p for p in POLA_WYJATKU if not str(w.get(p, "")).strip()]
        if brak:
            bledy.append(f"Wyjątek #{i} ({w.get('id', '?')}): brak pól {', '.join(brak)}.")
            continue
        try:
            wygasa = datetime.date.fromisoformat(w["wygasa"])
        except ValueError:
            bledy.append(f"Wyjątek {w['id']}: `wygasa` musi mieć postać RRRR-MM-DD.")
            continue
        if wygasa < dzis:
            bledy.append(f"Wyjątek {w['id']} wygasł {w['wygasa']} — usuń go albo świadomie odnów (właściciel: {w['wlasciciel']}).")
        elif (wygasa - dzis).days > MAKS_DNI_WYJATKU:
            bledy.append(f"Wyjątek {w['id']}: termin {w['wygasa']} jest dalszy niż {MAKS_DNI_WYJATKU} dni od dziś.")
        else:
            wazne.append(w)
    return wazne, bledy


def ocen(composer, npm, wyjatki, dzis):
    """Zwraca (przepuszcza: bool, linie informacyjne, lista blokad)."""
    wazne, bledy = sprawdz_wyjatki(wyjatki, dzis)
    linie = []
    blokady = list(bledy)
    for narzedzie, lista in (("composer", podatnosci_composera(composer)), ("npm", podatnosci_npm(npm))):
        for pakiet, tytul, poziom, ids in lista:
            blokuje = poziom not in NISKIE if narzedzie == "composer" else poziom in ("high", "critical")
            if not blokuje:
                linie.append(f"- {narzedzie} `{pakiet}` ({poziom or 'brak poziomu'}): {tytul} — informacyjnie, nie blokuje.")
                continue
            wyj = next((w for w in wazne if w["narzedzie"] == narzedzie and w["id"] in ids), None)
            if wyj:
                linie.append(f"- {narzedzie} `{pakiet}` ({poziom}): {tytul} — WYJĄTEK {wyj['id']} do {wyj['wygasa']}, właściciel {wyj['wlasciciel']}.")
            else:
                blokady.append(f"{narzedzie} `{pakiet}` ({poziom or 'brak poziomu'}): {tytul} [{', '.join(sorted(ids))}]")
    return (not blokady), linie, blokady


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--composer", required=True)
    p.add_argument("--npm", required=True)
    p.add_argument("--wyjatki", required=True)
    p.add_argument("--dzis", default=None, help="RRRR-MM-DD (tylko do testów)")
    a = p.parse_args()
    dzis = datetime.date.fromisoformat(a.dzis) if a.dzis else datetime.date.today()
    try:
        wyjatki = wczytaj(a.wyjatki, "wyjątki")
        ok, linie, blokady = ocen(wczytaj(a.composer, "composer audit"), wczytaj(a.npm, "npm audit"), wyjatki, dzis)
    except BladWejscia as blad:
        print(f"## Audyt zależności: BLOKUJE\n\n{blad}")
        return 1
    print("## Audyt zależności: " + ("bramka przepuszcza" if ok else "BLOKUJE"))
    print()
    if blokady:
        print("Blokują merge (high/critical bez ważnego wyjątku albo błędny wyjątek):")
        print()
        for b in blokady:
            print(f"- {b}")
        print()
        print("Co zrobić: zaktualizuj pakiet albo dopisz jawny wyjątek z terminem do 30 dni "
              "(`.github/wyjatki-audytu.json`, docs/infra/BRAMKI_CI_2215.md).")
        print()
    if linie:
        print("Pozostałe pozycje:")
        print()
        print("\n".join(linie))
    elif not blokady:
        print("Brak znanych podatności.")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
