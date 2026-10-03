#!/usr/bin/env python3
"""#2811: werdykt pochodzi z właściwego JUnit, niezależnie od reportera.

Ładuje rzeczywiste funkcje przyrządu z AST, bez mutacji domeny ani bazy.
Atrapa procesu zapisuje raport pod żądanym --log-junit. Przywrócenie starej
bramki JSON ma fizycznie oblać poprawny raport przy tekstowym wyjściu.
"""

import ast
import contextlib
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "scripts/kontrola-negatywna-2811.py"
sys.path.insert(0, str(ROOT / "scripts"))
from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


TEST_FILE = "tests/Dwa/PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest.php"
TEST_CLASS = r"Tests\Dwa\PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest"
TEST = "test_ten_sam_klucz_czeka_przed_utworzeniem_media"
MARKER = "DOLACZENIE_2811_RYWAL_CZEKA_PRZED_MEDIA"


def junit(name=TEST, classname=TEST_CLASS, outcome=None, message=MARKER, count=1):
    root = ET.Element("testsuites")
    suite = ET.SubElement(root, "testsuite", name=TEST_CLASS)
    for _ in range(count):
        case = ET.SubElement(suite, "testcase", name=name, **{"class": classname})
        if outcome is not None:
            ET.SubElement(case, outcome).text = message
    return ET.tostring(root, encoding="unicode")


class WynikKontroli(unittest.TestCase):
    def test_tekstowy_reporter_dopuszcza_wlasciwa_zielen_i_mutanta(self):
        for code, report, positive in ((0, junit(), True),
                                       (1, junit(outcome="failure"), False)):
            with self.subTest(positive=positive):
                self.run_case(code, report, positive,
                              assertion="PRZYRZAD_2811_TEKST_NIE_ODRZUCA_JUNIT")

    def test_pominiety_przebieg_nie_jest_zielenia(self):
        self.run_case(0, junit(outcome="skipped"), positive=True,
                      rejects="PRZYRZAD_2811_NIE_WYKONANO")

    def test_mutant_z_pominietym_przypadkiem_odmawia(self):
        self.run_case(1, junit(outcome="skipped"), positive=False,
                      rejects="PRZYRZAD_2811_NIE_WYKONANO")

    def test_blad_wykonania_odmawia_w_obu_przebiegach(self):
        for positive in (True, False):
            with self.subTest(positive=positive):
                self.run_case(1, junit(outcome="error"), positive,
                              rejects="PRZYRZAD_2811_NIE_WYKONANO")

    def test_brak_i_nieczytelny_raport_odmawiaja(self):
        for positive in (True, False):
            for report, marker in ((None, "PRZYRZAD_2811_BRAK_JUNIT"),
                                   ("<uszkodzony", "PRZYRZAD_2811_NIECZYTELNY_JUNIT")):
                with self.subTest(positive=positive, report=report):
                    self.run_case(0 if positive else 1, report, positive, rejects=marker)

    def test_brak_przypadku_i_nadmiar_odmawiaja(self):
        for positive in (True, False):
            for count in (0, 2):
                with self.subTest(positive=positive, count=count):
                    self.run_case(0 if positive else 1, junit(count=count), positive,
                                  rejects="PRZYRZAD_2811_LICZBA_TESTOW")

    def test_obca_klasa_albo_metoda_odmawia_w_obu_przebiegach(self):
        for positive in (True, False):
            for args in ({"classname": "ObcaKlasa"}, {"name": "test_obcy"}):
                with self.subTest(positive=positive, args=args):
                    self.run_case(0 if positive else 1,
                                  junit(outcome=None if positive else "failure", **args), positive,
                                  rejects="PRZYRZAD_2811_OBCY_TEST")

    def test_czerwien_po_przywroceniu_odmawia(self):
        self.run_case(1, junit(outcome="failure"), positive=True,
                      rejects="PRZYRZAD_2811_BRAK_ZIELENI")

    def test_mutant_musi_oblac_na_wlasnym_markerze(self):
        self.run_case(1, junit(outcome="failure", message="Awaria innej asercji"),
                      positive=False, rejects="PRZYRZAD_2811_ZLA_PRZYCZYNA")

    def test_marker_tylko_w_logu_nie_potwierdza_obcej_asercji(self):
        self.run_case(1, junit(outcome="failure", message="Awaria innej asercji"),
                      positive=False, rejects="PRZYRZAD_2811_ZLA_PRZYCZYNA", output=MARKER)

    def test_zielony_mutant_i_niespojny_kod_odmawiaja(self):
        for code, report in ((0, junit()), (0, junit(outcome="failure")), (1, junit())):
            with self.subTest(code=code, report=report):
                self.run_case(code, report, positive=False,
                              rejects="PRZYRZAD_2811_ZLA_PRZYCZYNA")

    def test_zielony_raport_z_niezerowym_kodem_nie_jest_restore_pass(self):
        self.run_case(1, junit(), positive=True, rejects="PRZYRZAD_2811_BRAK_ZIELENI")

    def run_case(self, code, report, positive, rejects=None, assertion=None,
                 output="Tests: 1, Assertions: 9, Failures: 1.\n"):
        tree = ast.parse(SOURCE.read_text(encoding="utf-8"))
        names = {"run_test", "ocen_wynik"}
        functions = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name in names]
        self.assertEqual(names, {node.name for node in functions}, "Brak właściwych funkcji przyrządu #2811.")
        module = ast.fix_missing_locations(ast.Module(body=functions, type_ignores=[]))
        with tempfile.TemporaryDirectory(prefix="kuking-2811-mechanizm-") as directory:
            def fake_run(command, **kwargs):
                self.assertIn("--filter=" + TEST, command)
                self.assertIn("--group=dwa-polaczenia", command)
                self.assertIn(TEST_FILE, command)
                self.assertEqual(directory, kwargs["env"]["APP_BASE_PATH"])
                index = command.index("--log-junit")
                if report is not None:
                    Path(command[index + 1]).write_text(report, encoding="utf-8")
                return SimpleNamespace(returncode=code, stdout=output)

            scope = {"os": os, "Path": Path, "tempfile": tempfile, "ET": ET,
                     "subprocess": SimpleNamespace(run=fake_run, PIPE=subprocess.PIPE, STDOUT=subprocess.STDOUT),
                     "ROOT": Path(directory), "TEST_FILE": TEST_FILE, "TEST_CLASS": TEST_CLASS,
                     "TEST": TEST, "MARKER": MARKER, "POTWIERDZONA": POTWIERDZONA,
                     "WynikTestu": WynikTestu, "czytaj_junit": czytaj_junit, "werdykt": werdykt}
            exec(compile(module, str(SOURCE), "exec"), scope)
            with contextlib.redirect_stdout(io.StringIO()):
                if rejects is not None:
                    with self.assertRaisesRegex(RuntimeError, rejects):
                        scope["run_test"](positive)
                else:
                    try:
                        scope["run_test"](positive)
                    except RuntimeError as error:
                        self.fail((assertion or "Przyrząd odrzucił poprawny wynik #2811") + ": " + str(error))


if __name__ == "__main__":
    unittest.main()
