#!/usr/bin/env python3
"""Test parsera z `ceny-warzyw-zsrir-pobierz.py` (D-286, część 3).

Ten skrypt od 26.09.2026 uruchamia się automatycznie, co tydzień, w GitHub
Actions (`.github/workflows/ceny-warzyw-auto.yml`) — bez tego testu nikt by
nie złapał, że MRiRW zmieniło układ arkusza, dopóki nie zobaczyłby dziwnej
ceny w PR-ze albo pustego `brak` na czerwono w CI.

Fikstura NIE pobiera niczego z sieci: buduje mały, prawdziwy plik .xlsx
w pamięci (`openpyxl`) o takim samym układzie jak arkusz „ZAKUP WARZ DETAL -
DO 2 KG” prawdziwego biuletynu ZSRIR (sekcja, wiersz TOWAR, wiersz
tygodniowa/roczna, dane, pusty wiersz kończący sekcję), tylko z mniejszą
liczbą wierszy — dokładnie tyle, ile trzeba do sprawdzenia parsera.

Uruchomienie:
    python3 scripts/ceny_warzyw_zsrir_pobierz_test.py
"""

from __future__ import annotations

import importlib.util
import io
import sys
import unittest
from pathlib import Path

try:
    import openpyxl
except ImportError as e:  # pragma: no cover
    raise SystemExit("Brakuje pakietu openpyxl. Zainstaluj: pip install openpyxl") from e


def _zaladuj_modul():
    """Import pliku z myślnikiem w nazwie — `ceny-warzyw-zsrir-pobierz.py`."""
    sciezka = Path(__file__).resolve().parent / "ceny-warzyw-zsrir-pobierz.py"
    spec = importlib.util.spec_from_file_location("ceny_warzyw_zsrir_pobierz", sciezka)
    modul = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(modul)

    return modul


MODUL = _zaladuj_modul()


def _fikstura_xlsx(arkusz_nazwa: str = MODUL.ARKUSZ) -> bytes:
    """Mały skoroszyt o układzie arkusza „ZAKUP WARZ DETAL - DO 2 KG”.

    Kolumny: A puste, B etykieta/nagłówek, C najnowsza cena (zł/100 kg),
    D-E starsze ceny (parser ich nie czyta — tak jak prawdziwy plik).
    """
    skoroszyt = openpyxl.Workbook()
    skoroszyt.remove(skoroszyt.active)
    arkusz = skoroszyt.create_sheet(arkusz_nazwa)

    wiersze = [
        [None, "ŚREDNIE CENY ZAKUPU WARZYW PŁACONE PRZEZ PODMIOTY HANDLU DETALICZNEGO — opakowania do 2 kg"],
        [None, "(zł/100 kg)"],
        [None],
        [None, "WARZYWA krajowe - opakowania do 2 kg"],
        [None, "TOWAR", "20.09.2026", "13.09.2026", "21.09.2025"],
        [None, None, None, None, "tygodniowa", "roczna"],
        [None, "Cebula biała", 241.74, 249.31, "---"],
        [None, "Ziemniaki", 185.20, 179.22, 175.13],
        [None, "Ogórki szklarniowe", "nld", 461.6, "---"],  # brak ceny w tym tygodniu
        [None],
        [None, "WARZYWA z importu - do 2 kg"],
        [None, "TOWAR", "20.09.2026", "13.09.2026", "21.09.2025"],
        [None, None, None, None, "tygodniowa", "roczna"],
        [None, "Cebula biała", "nld", "nld", "---"],
    ]

    for wiersz in wiersze:
        arkusz.append(wiersz)

    bufor = io.BytesIO()
    skoroszyt.save(bufor)

    return bufor.getvalue()


class OkresZTytuluTest(unittest.TestCase):
    def test_zwykly_tytul(self) -> None:
        tytul = "Rynek owoców i warzyw - notowania za okres: 14-22.09.2026 r."
        self.assertEqual(MODUL.okres_z_tytulu(tytul), "notowania 14-22.09.2026")

    def test_tytul_bez_pasujacego_wzorca_konczy_dzialanie(self) -> None:
        with self.assertRaises(SystemExit):
            MODUL.okres_z_tytulu("Zupełnie inny tytuł zasobu")


class WyciagnijCenyTest(unittest.TestCase):
    def test_czyta_tylko_sekcje_krajowa_i_dzieli_przez_100(self) -> None:
        ceny = MODUL.wyciagnij_ceny(_fikstura_xlsx())

        self.assertEqual(ceny, {"Cebula biała": 2.42, "Ziemniaki": 1.85})

    def test_brak_ceny_nld_jest_pomijany_a_nie_zerem(self) -> None:
        ceny = MODUL.wyciagnij_ceny(_fikstura_xlsx())

        self.assertNotIn("Ogórki szklarniowe", ceny)

    def test_sekcja_import_nie_nadpisuje_krajowej(self) -> None:
        # Kontrola ujemna na samej fikturze: sekcja „z importu” w tym samym
        # arkuszu ma inną (pustą, „nld”) cenę cebuli — gdyby parser czytał
        # obie sekcje, „Cebula biała” zniknęłaby z wyniku albo dostała
        # złą wartość zamiast 2.42.
        ceny = MODUL.wyciagnij_ceny(_fikstura_xlsx())

        self.assertEqual(ceny["Cebula biała"], 2.42)

    def test_brakujacy_arkusz_konczy_dzialanie_czytelnym_bledem(self) -> None:
        with self.assertRaises(SystemExit):
            MODUL.wyciagnij_ceny(_fikstura_xlsx(arkusz_nazwa="Zupełnie inny arkusz"))


if __name__ == "__main__":
    sys.exit(unittest.main())
