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

import csv
import importlib.util
import io
import shutil
import sys
import tempfile
import unittest
import unittest.mock
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


def _fikstura_hurt_xlsx(arkusz_nazwa: str = MODUL.ARKUSZ_HURT) -> bytes:
    """Mały skoroszyt o układzie arkusza „HURT WARZ”.

    Kolumny: A puste, B etykieta, C-L pięć par (min, max) — jedna para na
    rynek, w kolejności `MODUL.RYNKI_HURT`. `Buraki cwiklowe` ma piąty
    rynek (Rzeszów) bez notowania — to kontrola ujemna na sumowanie
    braku jako zera (patrz test niżej).
    """
    skoroszyt = openpyxl.Workbook()
    skoroszyt.remove(skoroszyt.active)
    arkusz = skoroszyt.create_sheet(arkusz_nazwa)

    wiersze = [
        [None, "ŚREDNIE CENY ZAKUPU WARZYW W SKUPIE — RYNKI HURTOWE"],
        [None, "(zł/kg albo zł/szt)"],
        [None],
        [None, SEKCJA_HURT_DOMYSLNA],
        [None, "TOWAR", "Bronisze", None, "Kalisz", None, "Łódź", None, "Poznań", None, "Rzeszów", None],
        [None, None, "min", "max", "min", "max", "min", "max", "min", "max", "min", "max"],
        [None, "Kapusta biala", 1.0, 1.4, 1.0, 1.4, 1.0, 1.4, 1.0, 1.4, 1.0, 1.4],
        [None, "Buraki cwiklowe", 1.0, 1.2, 1.0, 1.2, 1.0, 1.2, 1.0, 1.2, "nld", "nld"],
        [None, "Por", 2.0, 2.2, 2.0, 2.2, 2.0, 2.2, 2.0, 2.2, 2.0, 2.2],
        [None, "Seler korzeniowy", 3.0, 3.2, 3.0, 3.2, 3.0, 3.2, 3.0, 3.2, 3.0, 3.2],
        [None, "Pietruszka (korzeń)", 4.0, 4.2, 4.0, 4.2, 4.0, 4.2, 4.0, 4.2, 4.0, 4.2],
        [None, "Sałata (głowa)", 2.5, 2.7, 2.5, 2.7, 2.5, 2.7, 2.5, 2.7, 2.5, 2.7],
        [None, "Ogórek gruntowy", 4.0, 4.4, 4.0, 4.4, 4.0, 4.4, 4.0, 4.4, 4.0, 4.4],
        [None],
        [None, "WARZYWA z importu - hurt"],
        [None, "TOWAR", "Bronisze", None, "Kalisz", None, "Łódź", None, "Poznań", None, "Rzeszów", None],
        [None, None, "min", "max", "min", "max", "min", "max", "min", "max", "min", "max"],
        [None, "Kapusta biala", 9.0, 9.9, 9.0, 9.9, 9.0, 9.9, 9.0, 9.9, 9.0, 9.9],
    ]

    for wiersz in wiersze:
        arkusz.append(wiersz)

    bufor = io.BytesIO()
    skoroszyt.save(bufor)

    return bufor.getvalue()


SEKCJA_HURT_DOMYSLNA = MODUL.SEKCJA_HURT


def _fikstura_pelna_xlsx() -> bytes:
    """Skoroszyt z OBOMA arkuszami tego samego biuletynu (detal + hurt) —
    do sprawdzenia, że `main()` aktualizuje wszystkie 12 warzyw w JEDNYM
    przebiegu (D-286 część 3: „Jeden PR tygodniowo ma obejmować wszystkie
    12 warzyw”).
    """
    skoroszyt = openpyxl.Workbook()
    skoroszyt.remove(skoroszyt.active)

    detal = skoroszyt.create_sheet(MODUL.ARKUSZ)
    for wiersz in [
        [None, "ŚREDNIE CENY ZAKUPU WARZYW PŁACONE PRZEZ PODMIOTY HANDLU DETALICZNEGO — opakowania do 2 kg"],
        [None, "(zł/100 kg)"],
        [None],
        [None, MODUL.SEKCJA],
        [None, "TOWAR", "20.09.2026", "13.09.2026", "21.09.2025"],
        [None, None, None, None, "tygodniowa", "roczna"],
        [None, "Ziemniaki", 200.0, 190.0, 180.0],
        [None, "Cebula biała", 250.0, 240.0, 230.0],
        [None, "Marchew", 300.0, 290.0, 280.0],
        [None, "Papryka czerwona", 1700.0, 1650.0, 1600.0],
        [None, "Pomidory okrągłe", 850.0, 800.0, 750.0],
        [None],
    ]:
        detal.append(wiersz)

    hurt = skoroszyt.create_sheet(MODUL.ARKUSZ_HURT)
    for wiersz in [
        [None, "ŚREDNIE CENY ZAKUPU WARZYW W SKUPIE — RYNKI HURTOWE"],
        [None],
        [None, MODUL.SEKCJA_HURT],
        [None, "TOWAR", "Bronisze", None, "Kalisz", None, "Łódź", None, "Poznań", None, "Rzeszów", None],
        [None, None, "min", "max", "min", "max", "min", "max", "min", "max", "min", "max"],
        [None, "Kapusta biala", 1.0, 1.4, 1.0, 1.4, 1.0, 1.4, 1.0, 1.4, 1.0, 1.4],
        [None, "Buraki cwiklowe", 1.0, 1.2, 1.0, 1.2, 1.0, 1.2, 1.0, 1.2, "nld", "nld"],
        [None, "Por", 2.0, 2.2, 2.0, 2.2, 2.0, 2.2, 2.0, 2.2, 2.0, 2.2],
        [None, "Seler korzeniowy", 3.0, 3.2, 3.0, 3.2, 3.0, 3.2, 3.0, 3.2, 3.0, 3.2],
        [None, "Pietruszka (korzeń)", 4.0, 4.2, 4.0, 4.2, 4.0, 4.2, 4.0, 4.2, 4.0, 4.2],
        [None, "Sałata (głowa)", 2.5, 2.7, 2.5, 2.7, 2.5, 2.7, 2.5, 2.7, 2.5, 2.7],
        [None, "Ogórek gruntowy", 4.0, 4.4, 4.0, 4.4, 4.0, 4.4, 4.0, 4.4, 4.0, 4.4],
        [None],
    ]:
        hurt.append(wiersz)

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


class WyciagnijCenyHurtTest(unittest.TestCase):
    """Arkusz „HURT WARZ" — siedem warzyw z D-286 część 3 (Uzupełnienie 26.09.2026)."""

    def test_liczy_srednia_min_max_z_pieciu_rynkow(self) -> None:
        ceny = MODUL.wyciagnij_ceny_hurt(_fikstura_hurt_xlsx())

        # Każdy rynek: (1.0 + 1.4) / 2 = 1.2 — pięć identycznych rynków,
        # więc średnia końcowa jest tą samą liczbą.
        self.assertAlmostEqual(ceny["Kapusta biala"], 1.2)
        self.assertAlmostEqual(ceny["Por"], 2.1)
        self.assertAlmostEqual(ceny["Seler korzeniowy"], 3.1)
        self.assertAlmostEqual(ceny["Pietruszka (korzeń)"], 4.1)
        self.assertAlmostEqual(ceny["Sałata (głowa)"], 2.6)
        self.assertAlmostEqual(ceny["Ogórek gruntowy"], 4.2)

    def test_brakujacy_rynek_jest_pomijany_a_nie_zerem(self) -> None:
        # Kontrola ujemna: `Buraki cwiklowe` mają cztery rynki po 1,1 zł
        # (średnia (1,0+1,2)/2) i piąty rynek bez notowania („nld”, „nld”).
        # Gdyby parser liczył brakujący rynek jako 0, średnia spadłaby do
        # 0,88 zamiast 1,1 — czyli poniżej najniższego prawdziwego notowania.
        ceny = MODUL.wyciagnij_ceny_hurt(_fikstura_hurt_xlsx())

        self.assertAlmostEqual(ceny["Buraki cwiklowe"], 1.1)

    def test_sekcja_import_nie_nadpisuje_krajowej(self) -> None:
        # Sekcja „z importu” w tej samej fikturze ma dla „Kapusta biala”
        # zupełnie inną cenę (9,0–9,9) — gdyby parser czytał obie sekcje,
        # wynik nie byłby już 1,2.
        ceny = MODUL.wyciagnij_ceny_hurt(_fikstura_hurt_xlsx())

        self.assertAlmostEqual(ceny["Kapusta biala"], 1.2)

    def test_brakujacy_arkusz_konczy_dzialanie_czytelnym_bledem(self) -> None:
        with self.assertRaises(SystemExit):
            MODUL.wyciagnij_ceny_hurt(_fikstura_hurt_xlsx(arkusz_nazwa="Zupełnie inny arkusz"))


class MnoznikHurtDetalTest(unittest.TestCase):
    """Test zgodności: mnożnik ma jedno źródło prawdy — stałą w PHP (D-286 cz. 3)."""

    def test_czyta_stala_z_prawdziwego_pliku_php(self) -> None:
        # Kontrola dodatnia na prawdziwym pliku repozytorium — nie na kopii.
        # Gdyby ten skrypt trzymał własną, osobną liczbę 1,6, ten test nie
        # złapałby rozjazdu; czytając PRAWDZIWY plik PHP, łapie.
        self.assertEqual(MODUL.mnoznik_hurt_detal(), 1.6)

    def test_brak_stalej_w_pliku_konczy_dzialanie_czytelnym_bledem(self) -> None:
        # Kontrola ujemna: podmieniamy źródło na plik bez tej stałej — parser
        # ma OBLAĆ jawnym błędem, a nie po cichu przyjąć wartość domyślną.
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as f:
            f.write("<?php // ten plik świadomie NIE ma stałej MNOZNIK_HURT_DETAL\n")
            tymczasowy = Path(f.name)

        try:
            with unittest.mock.patch.object(MODUL, "PLIK_MNOZNIKA", tymczasowy):
                with self.assertRaises(SystemExit):
                    MODUL.mnoznik_hurt_detal()
        finally:
            tymczasowy.unlink()


class MainAktualizujeWszystkie12WarzywTest(unittest.TestCase):
    """D-286 część 3: „Jeden PR tygodniowo ma obejmować wszystkie 12 warzyw”."""

    def test_jeden_przebieg_aktualizuje_pieciu_detalicznych_i_siedmiu_hurtowych(self) -> None:
        with tempfile.TemporaryDirectory() as katalog:
            tymczasowy_csv = Path(katalog) / "ceny_skladnikow.csv"
            shutil.copyfile(MODUL.PLIK, tymczasowy_csv)

            zasob = {
                "download_url": "https://example.invalid/biuletyn.xlsx",
                "title": "Rynek owoców i warzyw - notowania za okres: 14-22.09.2026 r.",
                "data_date": "2026-09-27",
                "format": "xlsx",
            }

            with unittest.mock.patch.object(MODUL, "PLIK", tymczasowy_csv), \
                    unittest.mock.patch.object(MODUL, "najnowszy_zasob", return_value=zasob), \
                    unittest.mock.patch.object(MODUL, "pobierz_xlsx", return_value=_fikstura_pelna_xlsx()), \
                    unittest.mock.patch.object(sys, "argv", ["ceny-warzyw-zsrir-pobierz.py", "--zapisz"]):
                kod_wyjscia = MODUL.main()

            self.assertEqual(kod_wyjscia, 0, "Nie powinno być brakujących cen w tej fikturze.")

            with tymczasowy_csv.open(newline="", encoding="utf-8") as f:
                wiersze = {w["klucz"]: w for w in csv.DictReader(f)}

            oczekiwane_ceny = {
                "ziemniaki": "2.00",
                "cebula": "2.50",
                "marchew": "3.00",
                "papryka_czerwona": "17.00",
                "pomidor": "8.50",
                "kapusta": "1.92",
                "buraki": "1.76",
                "por": "3.36",
                "seler": "4.96",
                "pietruszka": "6.56",
                "salata": "4.16",
                "ogorek": "6.72",
            }

            self.assertEqual(set(oczekiwane_ceny), set(MODUL.MAPA) | set(MODUL.MAPA_HURT), "Lista kluczy w teście musi pokrywać się dokładnie z MAPA ∪ MAPA_HURT (12 warzyw).")

            for klucz, cena in oczekiwane_ceny.items():
                self.assertEqual(wiersze[klucz]["cena_zl"], cena, f"Zła cena dla {klucz}.")
                self.assertEqual(wiersze[klucz]["okres"], "2026", f"Zły rok dla {klucz}.")

            # Jawność źródła (D-286 cz. 3, „Uzupełnienie"): siedem cen hurtowych
            # ma to nazwane wprost w `zrodlo`, pięć detalicznych — nie.
            for klucz in MODUL.MAPA_HURT:
                self.assertIn("szacunek z cen hurtowych", wiersze[klucz]["zrodlo"])

            for klucz in MODUL.MAPA:
                self.assertNotIn("szacunek z cen hurtowych", wiersze[klucz]["zrodlo"])

    def test_kontrola_ujemna_zly_mnoznik_zmienia_cene_hurtowa(self) -> None:
        # Kontrola ujemna na samą logikę mnożenia: gdyby ktoś podmienił
        # mnożnik na inny niż w PHP, wynik dla warzywa hurtowego MUSI się
        # zmienić — inaczej test wyżej nic by nie sprawdzał.
        with unittest.mock.patch.object(MODUL, "mnoznik_hurt_detal", return_value=2.0):
            ceny_hurt = MODUL.wyciagnij_ceny_hurt(_fikstura_hurt_xlsx())
            cena_zla_stala = round(ceny_hurt["Kapusta biala"] * MODUL.mnoznik_hurt_detal(), 2)

        cena_prawdziwa = round(ceny_hurt["Kapusta biala"] * 1.6, 2)

        self.assertNotEqual(cena_zla_stala, cena_prawdziwa)


if __name__ == "__main__":
    sys.exit(unittest.main())
