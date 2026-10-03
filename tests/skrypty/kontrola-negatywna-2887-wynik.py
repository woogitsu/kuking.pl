#!/usr/bin/env python3
"""#2887: sprawdza rzeczywisty czytnik i wywołanie JUnit bez łączenia z bazą."""

import importlib.util
import os
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
SPEC = importlib.util.spec_from_file_location("kontrola_2887", ROOT / "scripts/kontrola-negatywna-2887.py")
CONTROL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CONTROL)


def junit(name=CONTROL.CASE, classname=CONTROL.TEST_CLASS, outcome=None, message=CONTROL.MARKER, count=1):
    root = ET.Element("testsuites")
    suite = ET.SubElement(root, "testsuite")
    for _ in range(count):
        case = ET.SubElement(suite, "testcase", name=name, **{"class": classname})
        if outcome is not None:
            ET.SubElement(case, outcome).text = message
    return ET.tostring(root, encoding="unicode")


class WynikKontroli(unittest.TestCase):
    def run_case(self, code, report, positive, rejects=None, assertion=None):
        def fake_run(command, **kwargs):
            self.assertIn("--filter=" + CONTROL.METHOD + "@" + CONTROL.DATASET, command)
            self.assertIn("--group=dwa-polaczenia", command)
            self.assertIn(CONTROL.TEST_FILE, command)
            self.assertEqual(str(ROOT), kwargs["env"]["APP_BASE_PATH"])
            if report is not None:
                Path(command[command.index("--log-junit") + 1]).write_text(report, encoding="utf-8")
            return SimpleNamespace(returncode=code, stdout="Reporter tekstowy: PASS albo FAIL")

        with patch.object(CONTROL.subprocess, "run", fake_run):
            result = CONTROL.run_test()
        invoke = CONTROL.positive if positive else CONTROL.negative
        if rejects is None:
            invoke(result)
        else:
            with self.assertRaisesRegex(RuntimeError, rejects, msg=assertion):
                invoke(result)

    def test_wlasciwy_pass_i_fail_sa_dopuszczone(self):
        self.run_case(0, junit(), True)
        self.run_case(1, junit(outcome="failure"), False)

    def test_skip_odmawia_w_obu_przebiegach(self):
        for positive in (True, False):
            self.run_case(0 if positive else 1, junit(outcome="skipped"), positive,
                          "PRZYRZAD_2887_NIE_WYKONANO", "PRZYRZAD_2887_SKIP_NIE_JEST_DOWODEM")

    def test_error_odmawia_w_obu_przebiegach(self):
        for positive in (True, False):
            self.run_case(1, junit(outcome="error"), positive, "PRZYRZAD_2887_NIE_WYKONANO")

    def test_brak_i_nieczytelny_junit_odmawiaja(self):
        for positive in (True, False):
            for report, marker in ((None, "PRZYRZAD_2887_BRAK_JUNIT"), ("<uszkodzony", "PRZYRZAD_2887_NIECZYTELNY_JUNIT")):
                self.run_case(1, report, positive, marker)

    def test_zero_i_wiele_przypadkow_odmawiaja(self):
        for positive in (True, False):
            for count in (0, 2):
                self.run_case(1, junit(count=count), positive, "PRZYRZAD_2887_LICZBA_TESTOW")

    def test_obca_klasa_metoda_i_wariant_odmawiaja(self):
        for positive in (True, False):
            for args in ({"classname": "ObcaKlasa"}, {"name": "test_obcy"}, {"name": CONTROL.METHOD + ' with data set "author-cook-lower"'}):
                self.run_case(1, junit(outcome="failure", **args), positive, "PRZYRZAD_2887_OBCY_TEST")

    def test_fail_po_restore_odmawia(self):
        self.run_case(1, junit(outcome="failure"), True, "Dodatni przeplot #2887 nie przeszedł")

    def test_obcy_marker_odmawia(self):
        self.run_case(1, junit(outcome="failure", message="Awaria innej asercji"), False, "Niewłaściwa przyczyna porażki #2887")

    def test_zielony_mutant_niespojny_kod_i_fatal_odmawiaja(self):
        for code, report in ((0, junit()), (0, junit(outcome="failure")), (1, junit()), (2, junit(outcome="failure"))):
            self.run_case(code, report, False, "Niewłaściwa przyczyna porażki #2887")

    def test_cel_wymaga_jawnej_izolacji(self):
        env = {"DB_URL": "", "DATABASE_URL": "", "CI": "", "KUKING_KONTROLA_2887_LOKALNIE": "1",
               "DB_HOST": "127.0.0.1", "DB_PORT": "55488", "DB_DATABASE": "kuking_race_wlasna_2887", "DB_USERNAME": "wlasna_rola"}
        with patch.dict(os.environ, env, clear=True):
            CONTROL.sprawdz_cel()
        for change in ({"DB_DATABASE": "kuking_race"}, {"DB_PORT": "5432"}, {"DB_HOST": "produkcja"},
                       {"DB_USERNAME": ""}, {"DB_URL": "postgres://obca"}, {"DATABASE_URL": "postgres://obca"},
                       {"KUKING_KONTROLA_2887_LOKALNIE": ""}):
            with self.subTest(change=change), patch.dict(os.environ, env | change, clear=True):
                with self.assertRaises(SystemExit):
                    CONTROL.sprawdz_cel()
        with patch.dict(os.environ, env | {"CI": "true", "DB_DATABASE": "kuking_race"}, clear=True):
            CONTROL.sprawdz_cel()


if __name__ == "__main__":
    unittest.main()
