#!/usr/bin/env python3
"""#2880: pominięcie, obcy przypadek i awaria nie są dowodem kontroli.

Ładuje rzeczywiste funkcje przyrządu z AST, bez wykonywania mutacji źródeł
ani połączenia z bazą. Atrapa procesu zapisuje raport pod żądanym --log-junit.
Fizyczne cofnięcie bramki pominięć musi oblać test na własnym markerze.
"""

import ast
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "scripts/kontrola-negatywna-2880.py"
sys.path.insert(0, str(ROOT / "scripts"))
from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


TEST_CLASS = r"Tests\Dwa\PrzypomnieniaUrodzinPoZmianieDecyzjiTest"
TEST = "test_wylaczenie_widocznosci_przed_zapisem_nie_tworzy_powiadomienia"
MARKER = "URODZINY_2880_OFF_NIE_TWORZY"


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
        self.run_case(0, junit(), positive=True)
        self.run_case(1, junit(outcome="failure"), positive=False)

    def test_pominiety_przebieg_nie_jest_zielenia(self):
        self.run_case(0, junit(outcome="skipped"), positive=True,
                      rejects="PRZYRZAD_2880_NIE_WYKONANO",
                      assertion="PRZYRZAD_2880_POMINIETY_NIE_JEST_PASS")

    def test_mutant_z_pominietym_przypadkiem_odmawia(self):
        self.run_case(1, junit(outcome="skipped"), positive=False,
                      rejects="PRZYRZAD_2880_NIE_WYKONANO")

    def test_blad_wykonania_odmawia_w_obu_przebiegach(self):
        for positive in (True, False):
            with self.subTest(positive=positive):
                self.run_case(1, junit(outcome="error"), positive,
                              rejects="PRZYRZAD_2880_NIE_WYKONANO")

    def test_brak_i_nieczytelny_raport_odmawiaja(self):
        for positive in (True, False):
            for report, marker in ((None, "PRZYRZAD_2880_BRAK_JUNIT"),
                                   ("<uszkodzony", "PRZYRZAD_2880_NIECZYTELNY_JUNIT")):
                with self.subTest(positive=positive, report=report):
                    self.run_case(1, report, positive, rejects=marker)

    def test_brak_przypadku_i_nadmiar_odmawiaja(self):
        for positive in (True, False):
            for count in (0, 2):
                with self.subTest(positive=positive, count=count):
                    self.run_case(1, junit(count=count), positive,
                                  rejects="PRZYRZAD_2880_LICZBA_TESTOW")

    def test_obca_klasa_albo_metoda_odmawia_mimo_wlasnego_markera(self):
        for positive in (True, False):
            for args in ({"classname": "ObcaKlasa"}, {"name": "test_obcy"}):
                with self.subTest(positive=positive, args=args):
                    self.run_case(1, junit(outcome="failure", **args), positive,
                                  rejects="PRZYRZAD_2880_OBCY_TEST")

    def test_czerwien_po_przywroceniu_odmawia(self):
        self.run_case(1, junit(outcome="failure"), positive=True,
                      rejects="Dodatni przeplot #2880 nie przeszedł")

    def test_mutant_musi_oblac_na_wlasnym_markerze(self):
        self.run_case(1, junit(outcome="failure", message="Awaria innej asercji"),
                      positive=False, rejects="Niewłaściwa przyczyna porażki #2880")

    def test_zielony_mutant_i_niespojny_kod_odmawiaja(self):
        for code, report in ((0, junit()), (0, junit(outcome="failure")), (1, junit())):
            with self.subTest(code=code, report=report):
                self.run_case(code, report, positive=False,
                              rejects="Niewłaściwa przyczyna porażki #2880")

    def run_case(self, code, report, positive, rejects=None, assertion=None):
        tree = ast.parse(SOURCE.read_text(encoding="utf-8"))
        names = {"run_test", "wykonany_test", "positive", "negative"}
        functions = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name in names]
        self.assertEqual(names, {node.name for node in functions}, "Brak właściwych funkcji przyrządu #2880.")
        module = ast.fix_missing_locations(ast.Module(body=functions, type_ignores=[]))
        with tempfile.TemporaryDirectory(prefix="kuking-2880-mechanizm-") as directory:
            def fake_run(command, **kwargs):
                self.assertIn("--filter=" + TEST, command)
                self.assertIn("--group=dwa-polaczenia", command)
                self.assertIn("tests/Dwa/PrzypomnieniaUrodzinPoZmianieDecyzjiTest.php", command)
                self.assertEqual(directory, kwargs["env"]["APP_BASE_PATH"])
                index = command.index("--log-junit")
                if report is not None:
                    Path(command[index + 1]).write_text(report, encoding="utf-8")
                return SimpleNamespace(returncode=code, stdout="FAIL lub PASS: tekstowy reporter\n")

            scope = {"os": os, "Path": Path, "tempfile": tempfile, "ET": ET,
                     "subprocess": SimpleNamespace(run=fake_run, PIPE=subprocess.PIPE, STDOUT=subprocess.STDOUT),
                     "ROOT": Path(directory), "TEST_FILE": "tests/Dwa/PrzypomnieniaUrodzinPoZmianieDecyzjiTest.php",
                     "TEST_CLASS": TEST_CLASS, "POTWIERDZONA": POTWIERDZONA, "WynikTestu": WynikTestu,
                     "czytaj_junit": czytaj_junit, "werdykt": werdykt}
            exec(compile(module, str(SOURCE), "exec"), scope)

            def invoke():
                if positive:
                    scope["positive"](TEST)
                else:
                    scope["negative"](TEST, MARKER, scope["run_test"](TEST))

            if rejects is None:
                invoke()
            else:
                with self.assertRaisesRegex(RuntimeError, rejects, msg=assertion):
                    invoke()


if __name__ == "__main__":
    unittest.main()
