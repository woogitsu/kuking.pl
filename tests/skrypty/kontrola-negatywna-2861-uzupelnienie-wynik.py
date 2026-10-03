#!/usr/bin/env python3
"""Przyrząd #2861 odmawia zieleni z pominięcia, obcego testu lub cudzej porażki."""

import importlib.util
import os
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("kontrola_2861_uzupelnienie", ROOT / "scripts/kontrola-negatywna-2861-uzupelnienie.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class Wynik2861Test(unittest.TestCase):
    def setUp(self):
        self.katalog = tempfile.TemporaryDirectory()
        self.addCleanup(self.katalog.cleanup)
        self.raport = Path(self.katalog.name) / "junit.xml"

    def zapisz(self, rodzina=None):
        root = ET.Element("testsuite")
        for nazwa, grupa in mod.CASES.items():
            case = ET.SubElement(root, "testcase", {"class": mod.CLASS, "classname": mod.CLASS.replace("\\", "."), "name": nazwa})
            if rodzina == grupa:
                ET.SubElement(case, "failure").text = mod.MARKERS[grupa]
        self.root = root
        self.zapisz_root()

    def zapisz_root(self):
        ET.ElementTree(self.root).write(self.raport, encoding="utf-8")

    def test_dodatni_i_obie_wlasciwe_kontrole(self):
        for rodzina in (None, "cookie", "okno"):
            with self.subTest(rodzina=rodzina):
                self.zapisz(rodzina)
                mod.sprawdz_junit(self.raport, 0 if rodzina is None else 1, rodzina)

    def test_brak_lub_uszkodzony_junit_odmawia(self):
        with self.assertRaises(RuntimeError):
            mod.sprawdz_junit(self.raport, 0)
        self.raport.write_text("uszkodzone", encoding="utf-8")
        with self.assertRaises(ET.ParseError):
            mod.sprawdz_junit(self.raport, 0)

    def test_skip_i_error_odmawiaja(self):
        for rodzaj in ("skipped", "error"):
            with self.subTest(rodzaj=rodzaj):
                self.zapisz()
                ET.SubElement(self.root[0], rodzaj)
                self.zapisz_root()
                with self.assertRaisesRegex(RuntimeError, "pominięcie/błąd"):
                    mod.sprawdz_junit(self.raport, 0)

    def test_obca_klasa_metoda_i_duplikat_odmawiaja(self):
        for rodzaj in ("class", "classname", "name", "duplikat"):
            with self.subTest(rodzaj=rodzaj):
                self.zapisz()
                if rodzaj == "duplikat":
                    self.root[1].set("name", self.root[0].get("name"))
                else:
                    self.root[0].set(rodzaj, "obcy")
                self.zapisz_root()
                with self.assertRaisesRegex(RuntimeError, "obca klasa/metoda"):
                    mod.sprawdz_junit(self.raport, 0)

    def test_brak_przypadku_odmawia(self):
        self.zapisz()
        self.root.remove(self.root[0])
        self.zapisz_root()
        with self.assertRaisesRegex(RuntimeError, "pięciu"):
            mod.sprawdz_junit(self.raport, 0)

    def test_niewlasciwy_marker_lub_dwie_porazki_odmawiaja(self):
        for rodzaj in ("marker", "liczba"):
            with self.subTest(rodzaj=rodzaj):
                self.zapisz("cookie")
                if rodzaj == "marker":
                    self.root[0].find("failure").text = "cudza porażka"
                else:
                    ET.SubElement(self.root[0], "failure").text = mod.MARKERS["cookie"]
                self.zapisz_root()
                with self.assertRaises(RuntimeError):
                    mod.sprawdz_junit(self.raport, 1, "cookie")

    def test_zielony_mutant_i_porazka_innej_rodziny_odmawiaja(self):
        self.zapisz()
        with self.assertRaises(RuntimeError):
            mod.sprawdz_junit(self.raport, 0, "cookie")
        self.zapisz("cookie")
        ET.SubElement(self.root[-1], "failure").text = mod.MARKERS["okno"]
        self.zapisz_root()
        with self.assertRaises(RuntimeError):
            mod.sprawdz_junit(self.raport, 1, "cookie")

    def test_kod_fatalu_i_czerwony_dodatni_odmawiaja(self):
        self.zapisz("cookie")
        with self.assertRaises(RuntimeError):
            mod.sprawdz_junit(self.raport, 2, "cookie")
        with self.assertRaises(RuntimeError):
            mod.sprawdz_junit(self.raport, 1)

    def zapisz_blad_b(self):
        self.zapisz()
        for case in self.root:
            if mod.CASES[case.get("name")] == "okno":
                ET.SubElement(case, "error", {"type": "RuntimeException"}).text = mod.B_ERROR + ": SQLSTATE=42P01"
        self.zapisz_root()

    def test_blad_sql_b_jest_error_i_nie_potwierdza_mutacji(self):
        self.zapisz_blad_b()
        mod.sprawdz_blad_b(self.raport, 2)
        with self.assertRaisesRegex(RuntimeError, "pominięcie/błąd"):
            mod.sprawdz_junit(self.raport, 2, "okno")

    def test_blad_b_przebrany_za_failure_nie_potwierdza_mutacji(self):
        for marker in (mod.B_ERROR, mod.B_TIMEOUT, "SQLSTATE=42P01"):
            with self.subTest(marker=marker):
                self.zapisz("okno")
                for case in self.root:
                    if mod.CASES[case.get("name")] == "okno":
                        case.find("failure").text += " " + marker
                self.zapisz_root()
                with self.assertRaisesRegex(RuntimeError, "awaria procesu B"):
                    mod.sprawdz_junit(self.raport, 1, "okno")
                with self.assertRaisesRegex(RuntimeError, "skip/failure"):
                    mod.sprawdz_blad_b(self.raport, 2)

    def test_kontrola_b_odmawia_obcej_przyczyny_i_rozmytego_markera(self):
        for szczegoly in (mod.B_ERROR + " SQLSTATE=40P01", mod.B_TIMEOUT + " SQLSTATE=42P01",
                          "SQLSTATE=42P01", mod.B_ERROR + " SQLSTATE=42P01 " + mod.MARKERS["okno"]):
            with self.subTest(szczegoly=szczegoly):
                self.zapisz_blad_b()
                self.root[-1].find("error").text = szczegoly
                self.zapisz_root()
                with self.assertRaisesRegex(RuntimeError, "własnego błędu SQL"):
                    mod.sprawdz_blad_b(self.raport, 2)

    def test_kontrola_b_odmawia_braku_bledu_skip_obcej_klasy_i_kodu(self):
        for rodzaj in ("brak", "skip", "klasa", "kod", "cookie", "dwa"):
            with self.subTest(rodzaj=rodzaj):
                self.zapisz_blad_b()
                if rodzaj == "brak":
                    self.root[-1].remove(self.root[-1].find("error"))
                elif rodzaj == "skip":
                    ET.SubElement(self.root[-1], "skipped")
                elif rodzaj == "klasa":
                    self.root[-1].set("class", "ObcyTest")
                elif rodzaj == "cookie":
                    ET.SubElement(self.root[0], "error").text = mod.B_ERROR + " SQLSTATE=42P01"
                elif rodzaj == "dwa":
                    ET.SubElement(self.root[-1], "error").text = mod.B_ERROR + " SQLSTATE=42P01"
                self.zapisz_root()
                with self.assertRaises(RuntimeError):
                    mod.sprawdz_blad_b(self.raport, 1 if rodzaj == "kod" else 2)

    def test_restore_bajtow_i_mtime_takze_po_odmowie_werdyktu(self):
        plik = Path(self.katalog.name) / "fixture.php"
        oryginal = b"<?php\r\n// \xc5\xbc\x00\r\n"
        plik.write_bytes(oryginal)
        os.utime(plik, ns=(1_700_000_000_111_222_333, 1_700_000_000_444_555_666))
        mtime = plik.stat().st_mtime_ns
        def odmowa(rodzina=None):
            self.assertNotEqual(oryginal, plik.read_bytes())
            raise RuntimeError("nie potwierdzono mutacji")
        with patch.object(mod, "przebieg", side_effect=odmowa):
            with self.assertRaisesRegex(RuntimeError, "nie potwierdzono"):
                mod.kontrola_fizyczna(plik, lambda tekst: tekst + "// mutant", "blad-b")
        self.assertEqual(oryginal, plik.read_bytes())
        self.assertEqual(mtime, plik.stat().st_mtime_ns)


if __name__ == "__main__":
    unittest.main()
