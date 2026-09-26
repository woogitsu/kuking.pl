#!/usr/bin/env python3
"""Odświeża ceny WARZYW w database/data/ceny_skladnikow.csv z MRiRW/ZSRIR (D-286, część 3).

DLACZEGO NIE GUS. Bank Danych Lokalnych GUS (temat P1466, źródło pozostałych
wierszy cennika) NIE PODAJE dziś cen warzyw — seria miesięczna z cenami
ziemniaków, cebuli i marchwi kończy się w 2019 roku. Zamiennik: Zintegrowany
System Rolniczej Informacji Rynkowej (ZSRIR) Ministerstwa Rolnictwa i Rozwoju
Wsi, publikowany jako otwarte dane na dane.gov.pl (zbiór 912, licencja CC BY
4.0 / domena publiczna, format xlsx, cotygodniowa aktualizacja). Arkusz
„ZAKUP WARZ DETAL - DO 2 KG” w tym biuletynie to średnia KRAJOWA cena zakupu
warzyw PRZEZ PODMIOTY HANDLU DETALICZNEGO w opakowaniach do 2 kg — najbliższy
oficjalny, cotygodniowy odpowiednik ceny detalicznej, jaki ZSRIR ma. To NIE
jest cena hurtowa (arkusz „HURT WARZ” w tym samym biuletynie) — nie robimy
tu żadnego przelicznika hurt→detal, bo dane w tym arkuszu już są opisane
jako cena na poziomie handlu detalicznego.

URUCHAMIA SIĘ NA KOMPUTERZE OSOBY PROWADZĄCEJ, NIE NA PRODUKCJI — dokładnie
jak `ceny-gus-pobierz.py`. Skrypt zmienia tylko plik w repozytorium; zmiana
idzie normalnym PR-em (przegląd różnic cen), a po wdrożeniu produkcja
wczytuje ją komendą `php artisan kuking:ceny-skladnikow`. Produkcja SAMA
niczego z sieci nie pobiera.

CO SKRYPT AKTUALIZUJE, A CZEGO NIE. Dotyka wyłącznie wierszy, których pole
`zrodlo` zaczyna się od „MRiRW” — te same klucze co dziś w pliku (`ziemniaki`,
`cebula`, `marchew`, `papryka_czerwona`, `pomidor`). Nie dodaje nowych
wierszy: nowy klucz (kolejne warzywo) dopisuje się ręcznie w PR-ze razem
z `wzorce`/`wyklucz`/miarami domowymi — to decyzje o dopasowaniu tekstu
składnika, których żaden skrypt nie powinien zgadywać.

CZEGO ZSRIR NADAL NIE POKRYWA: kapusta, buraki, por, seler, pietruszka
korzeniowa, sałata, ogórek. Arkusz „ZAKUP WARZ DETAL” ich nie notuje;
arkusz „HURT WARZ” notuje, ale to ceny hurtowe z pięciu różnych rynków
(Bronisze, Kalisz, Łódź, Poznań, Rzeszów) o rozrzucie zbyt dużym, żeby dało
się z nich uczciwie wyprowadzić JEDEN przelicznik hurt→detal wspólny dla
wszystkich warzyw — patrz uzasadnienie w docs/DECISIONS.md, D-286.

Użycie:
    python3 scripts/ceny-warzyw-zsrir-pobierz.py             # podgląd różnic
    python3 scripts/ceny-warzyw-zsrir-pobierz.py --zapisz    # zapis do pliku

Wymaga: pip install openpyxl (tylko na komputerze osoby prowadzącej, nie
w zależnościach aplikacji — to jednorazowy import, nie kod produkcyjny).

Uwaga o rytmie: ZSRIR publikuje ten biuletyn co tydzień, ale D-286 zakłada
odświeżanie cennika raz na kwartał — tak samo jak przy GUS — żeby przedział
kosztu dania nie migotał z tygodnia na tydzień.
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import re
import sys
import urllib.request
from pathlib import Path

PLIK = Path(__file__).resolve().parent.parent / "database" / "data" / "ceny_skladnikow.csv"
API_ZASOBY = "https://api.dane.gov.pl/1.4/datasets/912/resources?sort=-data_date&page=1&per_page=10"
ARKUSZ = "ZAKUP WARZ DETAL - DO 2 KG"
SEKCJA = "WARZYWA krajowe - opakowania do 2 kg"

# klucz w ceny_skladnikow.csv → dokładna etykieta w kolumnie „TOWAR” arkusza.
MAPA = {
    "ziemniaki": "Ziemniaki",
    "cebula": "Cebula biała",
    "marchew": "Marchew",
    "papryka_czerwona": "Papryka czerwona",
    "pomidor": "Pomidory okrągłe",
}


def najnowszy_zasob() -> dict:
    with urllib.request.urlopen(API_ZASOBY, timeout=60) as odp:  # noqa: S310 — stały adres dane.gov.pl
        dane = json.load(odp)

    for wpis in dane.get("data", []):
        atrybuty = wpis["attributes"]
        tytul = str(atrybuty.get("title", ""))
        if tytul.lower().startswith("rynek owoc") and atrybuty.get("format") == "xlsx":
            return atrybuty

    raise SystemExit("Nie znalazłem najnowszego biuletynu „Rynek owoców i warzyw” (xlsx) w zbiorze 912.")


def pobierz_xlsx(url: str) -> bytes:
    with urllib.request.urlopen(url, timeout=120) as odp:  # noqa: S310 — adres wzięty z odpowiedzi API dane.gov.pl
        return odp.read()


def okres_z_tytulu(tytul: str) -> str:
    """„Rynek owoców i warzyw - notowania za okres: 14-22.09.2026 r.” → „notowania 14-22.09.2026”.

    Tytuł zasobu jest prostszy i stabilniejszy niż szukanie konkretnej
    komórki w arkuszu — układ arkuszy w tym biuletynie zmienia się
    (np. arkusz „ZAKUP WARZ DETAL - DO 2 KG” w ogóle nie ma własnej
    komórki z opisem tygodnia, w przeciwieństwie do „HURT WARZ”).
    """
    dopasowanie = re.search(r"notowania za okres:\s*([\d.\s-]+)\s*r\.?", tytul, re.IGNORECASE)
    if dopasowanie is None:
        raise SystemExit(f"Nie umiem odczytać okresu notowania z tytułu zasobu: {tytul!r}")

    return f"notowania {dopasowanie.group(1).strip()}"


def wyciagnij_ceny(bajty: bytes) -> dict[str, float]:
    """Zwraca {etykieta: cena_zl_za_kg} z arkusza „ZAKUP WARZ DETAL - DO 2 KG”."""
    try:
        import openpyxl
    except ImportError as e:  # pragma: no cover — komunikat dla człowieka, nie test
        raise SystemExit("Brakuje pakietu openpyxl. Zainstaluj: pip install openpyxl") from e

    skoroszyt = openpyxl.load_workbook(io.BytesIO(bajty), data_only=True)
    if ARKUSZ not in skoroszyt.sheetnames:
        raise SystemExit(f"Biuletyn nie ma arkusza „{ARKUSZ}” — sprawdź, czy MRiRW nie zmieniło układu pliku.")

    arkusz = skoroszyt[ARKUSZ]
    wiersze = list(arkusz.iter_rows(values_only=True))
    ceny: dict[str, float] = {}
    w_sekcji = False

    for wiersz in wiersze:
        pierwsza_niepusta = next((v for v in wiersz if v is not None), None)

        if pierwsza_niepusta == SEKCJA:
            w_sekcji = True
            continue

        if not w_sekcji:
            continue

        if pierwsza_niepusta is None:
            break  # pusty wiersz kończy sekcję

        etykieta = wiersz[1] if len(wiersz) > 1 else None
        cena_100kg = wiersz[2] if len(wiersz) > 2 else None

        if not isinstance(etykieta, str) or etykieta in ("TOWAR", ""):
            continue

        if isinstance(cena_100kg, (int, float)):
            ceny[etykieta.strip()] = round(float(cena_100kg) / 100, 2)

    return ceny


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--zapisz", action="store_true", help="zapisz zmiany do pliku CSV")
    args = parser.parse_args()

    zasob = najnowszy_zasob()
    bajty = pobierz_xlsx(str(zasob["download_url"]))
    ceny = wyciagnij_ceny(bajty)
    okres = okres_z_tytulu(str(zasob.get("title", "")))
    rok = str(zasob.get("data_date", ""))[:4] or "brak-daty"

    with PLIK.open(newline="", encoding="utf-8") as f:
        czytnik = csv.DictReader(f)
        naglowek = czytnik.fieldnames or []
        wiersze = list(czytnik)

    brak = []
    for w in wiersze:
        if not w["zrodlo"].startswith("MRiRW"):
            continue

        etykieta = MAPA.get(w["klucz"])
        if etykieta is None or etykieta not in ceny:
            brak.append(w["klucz"])
            continue

        nowa = f"{ceny[etykieta]:.2f}"
        if nowa != w["cena_zl"] or w["okres"] != rok:
            print(f"{w['klucz']}: {w['cena_zl']} zł ({w['okres']}) → {nowa} zł ({rok})")

        w["cena_zl"] = nowa
        w["okres"] = rok
        w["zrodlo"] = (
            f"MRiRW, ZSRIR: {okres}, średnia cena zakupu warzyw przez detal "
            f"(opak. do 2 kg), {etykieta.lower()} - za 1kg (z zł/100 kg)"
        )

    if brak:
        print(
            f"\nBiuletyn nie podał ceny (opakowania do 2 kg) dla: {', '.join(brak)} — te ceny zostają bez zmian.",
            file=sys.stderr,
        )

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
