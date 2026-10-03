#!/usr/bin/env python3
"""#2879: własne pięć odmów, pięć dodatnich przeplotów i brak pozornych porażek."""

import importlib.util
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("kontrola_2879", ROOT / "scripts/kontrola-negatywna-2879.py")
CONTROL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CONTROL)


class WynikPostepuPoZawieszeniu(unittest.TestCase):
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
            if mutowany and name in CONTROL.REFUSALS:
                ET.SubElement(case, "failure").text = CONTROL.MARKER
        return root

    def zapisz(self, root):
        ET.ElementTree(root).write(self.report, encoding="utf-8")

    def test_dodatni_i_piec_wlasciwych_porazek_przy_pieciu_dodatnich(self):
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
                            ("name", CONTROL.REFUSAL + ' with data set "obcy"')):
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
                    ET.SubElement(root, "testcase", name="jedenasty")
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

    def test_brak_porazki_zly_marker_dwie_porazki_lub_odwrotny_przeplot_odmawia(self):
        for mode in ("brak", "marker", "dwie", "odwrotny"):
            with self.subTest(mode=mode):
                root = self.raport(True)
                case = next(c for c in root if c.get("name") in CONTROL.REFUSALS)
                if mode == "brak":
                    case.remove(case[0])
                elif mode == "marker":
                    case[0].text = "INNA_PRZYCZYNA"
                elif mode == "dwie":
                    ET.SubElement(case, "failure").text = CONTROL.MARKER
                else:
                    reverse = next(c for c in root if c.get("name") not in CONTROL.REFUSALS)
                    ET.SubElement(reverse, "failure").text = CONTROL.MARKER
                self.zapisz(root)
                with self.assertRaises(RuntimeError):
                    CONTROL.sprawdz_junit(self.report, 1, True)

    def test_sqlstate_i_timeout_nawet_z_wlasnym_markerem_odmawia(self):
        for cause in ("SQLSTATE[40P01]", "55P03", "57014", "timed out", "lock_timeout", "statement_timeout"):
            with self.subTest(cause=cause):
                root = self.raport(True)
                case = next(c for c in root if c.get("name") in CONTROL.REFUSALS)
                case[0].text = CONTROL.MARKER + " " + cause
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

    def test_kotwica_raz_i_stary_model(self):
        self.assertEqual(CONTROL.REPLACEMENT, CONTROL.mutant(CONTROL.ANCHOR))
        for text in ("", CONTROL.ANCHOR * 2):
            with self.assertRaises(RuntimeError):
                CONTROL.mutant(text)


if __name__ == "__main__":
    unittest.main()
