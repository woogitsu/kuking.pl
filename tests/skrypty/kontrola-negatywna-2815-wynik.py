#!/usr/bin/env python3
"""#2815: tekstowy reporter nie może unieważnić prawdziwego wyniku JUnit.

Ładuje AST wyłącznie funkcji odczytującej wynik. Nie wykonuje górnego poziomu
skryptu, nie dotyka domeny ani bazy. Atrapa procesu zapisuje raport do pliku,
który funkcja rzeczywiście przekazała przez --log-junit.
"""

import ast
import contextlib
import io
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "scripts/kontrola-negatywna-2815.py"
TEST = "test_sankcja_zatwierdzona_przed_blokada_pozycji_odmawia_bez_sladu"
MARKER = "IMPORT_2815_SANKCJA_PRZED_ZAPISEM"


def junit(failures=(), skipped=(), errors=(), missing=()):
    root = ET.Element("testsuites")
    suite = ET.SubElement(root, "testsuite", name="ImportPaczkiPoSankcjiKontaTest")
    for rodzaj in ("przepis", "wpis", "zeszyt"):
        if rodzaj in missing:
            continue
        case = ET.SubElement(suite, "testcase", name=f'{TEST} with data set "{rodzaj}"')
        if rodzaj in failures:
            ET.SubElement(case, "failure", message=failures[rodzaj]).text = failures[rodzaj]
        if rodzaj in skipped:
            ET.SubElement(case, "skipped")
        if rodzaj in errors:
            ET.SubElement(case, "error", message="Awaria przyrządu")
    return ET.tostring(root, encoding="unicode")


class WynikKontroli(unittest.TestCase):
    def test_tekstowe_wyjscie_z_poprawnym_junit_potwierdza_mutanta_i_restore(self):
        failures = {"wpis": MARKER, "zeszyt": MARKER}
        self.run_case(1, junit(failures), expected_success=False)
        self.run_case(0, junit(), expected_success=True)

    def test_brak_raportu_odmawia_mimo_tekstu_o_porazce(self):
        self.run_case(1, None, expected_success=False, rejects=True)

    def test_zielony_mutant_odmawia(self):
        self.run_case(0, junit(), expected_success=False, rejects=True)

    def test_obca_przyczyna_odmawia(self):
        self.run_case(1, junit({"wpis": MARKER, "zeszyt": "Inna asercja"}),
                      expected_success=False, rejects=True)

    def test_pominiecie_i_blad_wykonania_odmawiaja(self):
        for report in (junit({"wpis": MARKER, "zeszyt": MARKER}, skipped=("przepis",)),
                       junit({"wpis": MARKER, "zeszyt": MARKER}, errors=("przepis",))):
            with self.subTest(report=report):
                self.run_case(1, report, expected_success=False, rejects=True)

    def test_brak_jednego_wariantu_odmawia(self):
        self.run_case(1, junit({"wpis": MARKER, "zeszyt": MARKER}, missing=("przepis",)),
                      expected_success=False, rejects=True)

    def test_restore_musi_byc_zielony(self):
        self.run_case(1, junit({"wpis": MARKER}), expected_success=True, rejects=True)

    def run_case(self, code, report, expected_success, rejects=False):
        source = ast.parse(SOURCE.read_text(encoding="utf-8"))
        functions = [node for node in source.body if isinstance(node, ast.FunctionDef) and node.name == "test"]
        self.assertEqual(1, len(functions), "Brak jednej właściwej funkcji przyrządu #2815.")
        module = ast.fix_missing_locations(ast.Module(body=functions, type_ignores=[]))
        with tempfile.TemporaryDirectory(prefix="kuking-2815-mechanizm-") as directory:
            def fake_run(command, **kwargs):
                self.assertIn("--filter=" + TEST, command)
                reports = [arg.partition("=")[2] for arg in command if arg.startswith("--log-junit=")]
                self.assertEqual(1, len(reports), "Przyrząd nie żąda raportu JUnit.")
                if report is not None:
                    Path(reports[0]).write_text(report, encoding="utf-8")
                return SimpleNamespace(returncode=code, stdout="FAIL Tests: 2 failed, 1 passed\n")

            scope = {"os": os, "subprocess": SimpleNamespace(run=fake_run, PIPE=subprocess.PIPE,
                                                               STDOUT=subprocess.STDOUT),
                     "tempfile": tempfile, "ET": ET, "Path": Path, "ROOT": Path(directory),
                     "TEST": TEST, "MARKER": MARKER}
            exec(compile(module, str(SOURCE), "exec"), scope)
            with contextlib.redirect_stdout(io.StringIO()):
                if rejects:
                    with self.assertRaises(RuntimeError):
                        scope["test"](expected_success)
                else:
                    scope["test"](expected_success)


if __name__ == "__main__":
    unittest.main()
