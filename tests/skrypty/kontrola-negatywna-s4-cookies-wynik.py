#!/usr/bin/env python3
"""Przyrząd S4 musi odróżniać dziewięć właściwych porażek od nieuruchomionych GET."""

import importlib.util
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("kontrola_s4_cookies", ROOT / "scripts/kontrola-negatywna-s4-cookies.py")
CONTROL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CONTROL)


class WynikLiteralnychCookies(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.report = Path(self.tmp.name) / "junit.xml"

    def raport(self, mutowany=False):
        root = ET.Element("testsuites")
        for name in sorted(CONTROL.CASES):
            case = ET.SubElement(root, "testcase", name=name, **{
                "class": CONTROL.CLASS, "classname": CONTROL.CLASS.replace("\\", "."),
            })
            if mutowany:
                ET.SubElement(case, "failure").text = CONTROL.MARKER
        return root

    def zapisz(self, root):
        ET.ElementTree(root).write(self.report, encoding="utf-8")

    def test_dodatni_i_dziewiec_wlasciwych_porazek(self):
        for mutant in (False, True):
            with self.subTest(mutant=mutant):
                self.zapisz(self.raport(mutant))
                CONTROL.sprawdz_junit(self.report, 1 if mutant else 0, mutant)

    def test_brak_i_uszkodzony_junit_odmawia(self):
        with self.assertRaises(RuntimeError):
            CONTROL.sprawdz_junit(self.report, 0)
        self.report.write_text("<broken>")
        with self.assertRaises(ET.ParseError):
            CONTROL.sprawdz_junit(self.report, 1, True)

    def test_obca_klasa_metoda_i_wariant_odmawia(self):
        for attr, value in (("class", "InnaKlasa"), ("classname", "InnaKlasa"), ("name", "inna_metoda"),
                            ("name", CONTROL.METHOD + ' with data set "obcy"')):
            with self.subTest(attr=attr, value=value):
                root = self.raport(True)
                root[0].set(attr, value)
                self.zapisz(root)
                with self.assertRaises(RuntimeError):
                    CONTROL.sprawdz_junit(self.report, 1, True)

    def test_brak_duplikat_lub_nadmiar_przypadku_odmawia(self):
        for mode in ("brak", "duplikat", "nadmiar"):
            with self.subTest(mode=mode):
                root = self.raport(True)
                if mode == "brak":
                    root.remove(root[0])
                elif mode == "duplikat":
                    root[0].set("name", root[1].get("name"))
                else:
                    ET.SubElement(root, "testcase", name="dziesiaty")
                self.zapisz(root)
                with self.assertRaises(RuntimeError):
                    CONTROL.sprawdz_junit(self.report, 1, True)

    def test_skip_error_odmawia(self):
        for mutowany in (False, True):
            for tag in ("skipped", "error"):
                with self.subTest(mutowany=mutowany, tag=tag):
                    root = self.raport(mutowany)
                    ET.SubElement(root[0], tag)
                    self.zapisz(root)
                    with self.assertRaises(RuntimeError):
                        CONTROL.sprawdz_junit(self.report, 1 if mutowany else 0, mutowany)

    def test_brak_porazki_zly_marker_i_dwie_porazki_odmawia(self):
        for mode in ("brak", "marker", "dwie"):
            with self.subTest(mode=mode):
                root = self.raport(True)
                if mode == "brak":
                    root[0].remove(root[0][0])
                elif mode == "marker":
                    root[0][0].text = "INNA_PRZYCZYNA"
                else:
                    ET.SubElement(root[0], "failure").text = CONTROL.MARKER
                self.zapisz(root)
                with self.assertRaises(RuntimeError):
                    CONTROL.sprawdz_junit(self.report, 1, True)

    def test_czerwony_dodatni_i_zle_kody_odmawia(self):
        self.zapisz(self.raport(True))
        with self.assertRaises(RuntimeError):
            CONTROL.sprawdz_junit(self.report, 0)
        for mutant in (False, True):
            for code in (0 if mutant else 1, 255):
                with self.subTest(mutant=mutant, code=code):
                    self.zapisz(self.raport(mutant))
                    with self.assertRaises(RuntimeError):
                        CONTROL.sprawdz_junit(self.report, code, mutant)

    def test_kotwica_raz_i_podmiana_rzeczywistego_cookie(self):
        self.assertEqual(CONTROL.REPLACEMENT, CONTROL.mutant(CONTROL.ANCHOR))
        for text in ("", CONTROL.ANCHOR * 2):
            with self.assertRaises(RuntimeError):
                CONTROL.mutant(text)


if __name__ == "__main__":
    unittest.main()
