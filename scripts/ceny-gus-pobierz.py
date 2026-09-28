#!/usr/bin/env python3
"""Odświeża ceny w database/data/ceny_skladnikow.csv z Banku Danych Lokalnych GUS (D-286).

URUCHAMIA SIĘ NA KOMPUTERZE OSOBY PROWADZĄCEJ, NIE NA PRODUKCJI. Skrypt
zmienia tylko plik w repozytorium; zmiana idzie normalnym PR-em (przegląd
różnic cen), a po wdrożeniu produkcja wczytuje ją komendą
`php artisan kuking:ceny-skladnikow`.

Źródło: API BDL, temat P1466 „Żywność i napoje bezalkoholowe” (przeciętne
ceny detaliczne, średnia roczna, Polska). Numer zmiennej każdego wiersza
stoi w kolumnie `zmienna_bdl`. Wiersze bez numeru (np. woda) i wiersze,
dla których GUS nie podał wartości za wskazany rok, zostają bez zmian —
skrypt wypisuje je, żeby nikt nie przyjął starej ceny za nową.

Użycie:
    python3 scripts/ceny-gus-pobierz.py --rok 2025            # podgląd różnic
    python3 scripts/ceny-gus-pobierz.py --rok 2025 --zapisz   # zapis do pliku

Uwaga o rytmie: BDL podaje dla tego tematu średnie ROCZNE. Uruchamianie raz
na kwartał wystarcza, żeby złapać nowy rok, gdy tylko GUS go opublikuje.
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
import time
import urllib.request
from pathlib import Path

PLIK = Path(__file__).resolve().parent.parent / "database" / "data" / "ceny_skladnikow.csv"
API = "https://bdl.stat.gov.pl/api/v1/data/by-unit/000000000000"  # 000000000000 = Polska


def pobierz(zmienne: list[str], rok: int) -> dict[str, float]:
    wynik: dict[str, float] = {}
    for i in range(0, len(zmienne), 20):
        paczka = zmienne[i : i + 20]
        adres = f"{API}?format=json&page-size=100&year={rok}&" + "&".join(f"var-id={z}" for z in paczka)
        with urllib.request.urlopen(adres, timeout=60) as odp:  # noqa: S310 — stały adres GUS
            dane = json.load(odp)
        for r in dane.get("results", []):
            for w in r.get("values", []):
                if str(w.get("year")) == str(rok) and w.get("val") is not None:
                    wynik[str(r["id"])] = float(w["val"])
        time.sleep(1.5)  # limit zapytań BDL dla klienta bez klucza
    return wynik


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--rok", type=int, required=True)
    parser.add_argument("--zapisz", action="store_true", help="zapisz zmiany do pliku CSV")
    args = parser.parse_args()

    with PLIK.open(newline="", encoding="utf-8") as f:
        czytnik = csv.DictReader(f)
        naglowek = czytnik.fieldnames or []
        wiersze = list(czytnik)

    zmienne = sorted({w["zmienna_bdl"] for w in wiersze if w["zmienna_bdl"]})
    ceny = pobierz(zmienne, args.rok)

    brak = []
    for w in wiersze:
        z = w["zmienna_bdl"]
        if not z:
            continue
        if z not in ceny:
            brak.append(w["klucz"])
            continue
        nowa = f"{ceny[z]:.2f}"
        if nowa != w["cena_zl"] or w["okres"] != str(args.rok):
            print(f"{w['klucz']}: {w['cena_zl']} zł ({w['okres']}) → {nowa} zł ({args.rok})")
        w["cena_zl"] = nowa
        w["okres"] = str(args.rok)

    if brak:
        print(f"\nGUS nie podał wartości za {args.rok} dla: {', '.join(brak)} — te ceny zostają bez zmian.", file=sys.stderr)

    if args.zapisz:
        with PLIK.open("w", newline="", encoding="utf-8") as f:
            pisarz = csv.DictWriter(f, fieldnames=naglowek, lineterminator="\n")
            pisarz.writeheader()
            pisarz.writerows(wiersze)
        print(f"\nZapisano {PLIK}. Przejrzyj różnice (git diff) i wczytaj po wdrożeniu: php artisan kuking:ceny-skladnikow")
    else:
        print("\nPodgląd — niczego nie zapisano. Dodaj --zapisz.")

    return 1 if brak else 0


if __name__ == "__main__":
    sys.exit(main())
