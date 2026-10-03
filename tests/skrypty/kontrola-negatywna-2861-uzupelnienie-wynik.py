#!/usr/bin/env python3
"""Przyrząd #2861 odmawia zieleni z pominięcia, obcego testu lub cudzej porażki."""

import importlib.util
import tempfile
import unittest
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


if __name__ == "__main__":
    unittest.main()
