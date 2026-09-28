"""Kontrola dodatnia i ujemna bramki skipów #2167, bez bazy i PHP."""

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


PLIK = Path(__file__).with_name("sprawdz-pominiete-testy.py")
SPEC = importlib.util.spec_from_file_location("sprawdz_pominiete_testy", PLIK)
MODUL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODUL)


class BramkaSkipowTest(unittest.TestCase):
    def test_znany_skip_z_dokladnym_powodem_przechodzi(self):
        self.assertTrue(self.sprawdz("znany powod", "znany powod")[0])

    def test_dodatkowy_lub_zmieniony_skip_oblewa(self):
        ok, tekst = self.sprawdz("inny powod", "znany powod")
        self.assertFalse(ok)
        self.assertIn("NIEZNANY SKIP", tekst)

    def test_skip_bez_powodu_oblewa(self):
        with self.assertRaisesRegex(ValueError, "bez nazwy lub powodu"):
            self.sprawdz("", "znany powod")

    def sprawdz(self, w_raporcie, w_allowliscie):
        with tempfile.TemporaryDirectory() as katalog:
            root = Path(katalog)
            raport = root / "junit.xml"
            allowlista = root / "allowlista.json"
            raport.write_text(
                '<testsuite><testcase class="Tests\\Feature\\PrzykladTest" name="test_a">'
                f'<skipped message="{w_raporcie}"/></testcase></testsuite>',
                encoding="utf-8",
            )
            allowlista.write_text(json.dumps({"skipy": {
                "Tests\\Feature\\PrzykladTest::test_a": {
                    "powod": w_allowliscie, "issue": "#2167", "do": "2099-01-01"
                }
            }}), encoding="utf-8")
            return MODUL.sprawdz(raport, allowlista)


if __name__ == "__main__":
    unittest.main()
