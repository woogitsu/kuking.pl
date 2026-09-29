"""Strażnik podziału kontroli negatywnych na części CI.

Każdy wpis `checks` z kontrole-negatywne-alfa08.py ma być w DOKŁADNIE jednej
części, a lokalny przebieg (bez części) ma obejmować wszystkie. Gubiący albo
dubluący wpisy podział dawałby zielone CI z mniejszym pokryciem.
"""

import os
import re
import sys
import unittest
from pathlib import Path

import podzial_kontroli as pk

ROOT = Path(__file__).resolve().parent.parent
SKRYPT = ROOT / "scripts" / "kontrole-negatywne-alfa08.py"
WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
ZNACZNIK = "# PREFLIGHT KOTWIC"


def wpisy_checks():
    """Prawdziwa lista `checks`: wykonuje tylko definicje sprzed preflightu."""
    zrodlo = SKRYPT.read_text()
    fragment = zrodlo[: zrodlo.index(ZNACZNIK)]
    poprzednie_ci = os.environ.get("CI")
    poprzednie_argv = sys.argv
    os.environ["CI"] = "true"
    sys.argv = [str(SKRYPT)]
    try:
        przestrzen = {"__name__": "x", "__file__": str(SKRYPT)}
        exec(compile(fragment, str(SKRYPT), "exec"), przestrzen)
    finally:
        sys.argv = poprzednie_argv
        if poprzednie_ci is None:
            os.environ.pop("CI", None)
        else:
            os.environ["CI"] = poprzednie_ci
    return przestrzen["checks"]


def numery_czesci_z_ci():
    return sorted(int(n) for n in re.findall(r"^ +kontrole_czesc: (\d+)$", WORKFLOW.read_text(), re.M))


class PodzialKontroliTest(unittest.TestCase):
    def test_kazdy_wpis_checks_jest_w_dokladnie_jednej_czesci(self):
        wpisy = wpisy_checks()
        self.assertGreater(len(wpisy), 150, "`checks` wygląda na pusty — strażnik świeciłby nad niczym.")
        numery = numery_czesci_z_ci()
        self.assertEqual(numery, list(range(1, len(numery) + 1)), "Części w ci.yml to nie 1..N.")
        self.assertGreaterEqual(len(numery), 3)
        self.assertEqual(pk.naruszenia_podzialu(len(wpisy), len(numery)), [])

    def test_lokalny_przebieg_bez_czesci_obejmuje_wszystkie_wpisy(self):
        wpisy = wpisy_checks()
        self.assertEqual(pk.wybierz_indeksy(len(wpisy), None), list(range(len(wpisy))))
        self.assertTrue(pk.poza_petla_w_tej_czesci(None))

    def test_elementy_poza_petla_sa_w_dokladnie_jednej_czesci(self):
        for liczba in (1, 2, 3, 5):
            czesci = [n for n in range(1, liczba + 1) if pk.poza_petla_w_tej_czesci((n, liczba))]
            self.assertEqual(len(czesci), 1, f"Przy {liczba} częściach: {czesci}")

    # KONTROLA UJEMNA: strażnik umie powiedzieć „nie".
    def test_straznik_wykrywa_wpis_zgubiony_zdublowany_i_pusta_czesc(self):
        def gubi(liczba_wpisow, czesc):
            indeksy = pk.wybierz_indeksy(liczba_wpisow, czesc)
            return indeksy[1:] if czesc == (1, 3) else indeksy

        def dubluje(liczba_wpisow, czesc):
            indeksy = pk.wybierz_indeksy(liczba_wpisow, czesc)
            return indeksy + [0] if czesc == (2, 3) else indeksy

        def pusta(liczba_wpisow, czesc):
            return [] if czesc == (3, 3) else pk.wybierz_indeksy(liczba_wpisow, czesc)

        def bez_pelnego_lokalnego(liczba_wpisow, czesc):
            return [0] if czesc is None else pk.wybierz_indeksy(liczba_wpisow, czesc)

        self.assertEqual(pk.naruszenia_podzialu(10, 3), [], "Strażnik odrzuca poprawny podział.")
        for nazwa, zepsuty in (("gubi", gubi), ("dubluje", dubluje), ("pusta", pusta),
                               ("bez_pelnego_lokalnego", bez_pelnego_lokalnego)):
            with self.subTest(nazwa):
                self.assertNotEqual(pk.naruszenia_podzialu(10, 3, zepsuty), [])

    def test_parsowanie_argumentu_odmawia_zlych_czesci(self):
        self.assertIsNone(pk.parsuj_czesc([]))
        self.assertEqual(pk.parsuj_czesc(["--czesc", "2/3"]), (2, 3))
        for zle in (["--czesc", "0/3"], ["--czesc", "4/3"], ["--czesc", "x/3"],
                    ["--czesc", "2"], ["2/3"], ["--czesc"]):
            with self.subTest(zle), self.assertRaises(SystemExit):
                pk.parsuj_czesc(zle)

    def test_tylko_wybiera_wpisy_po_etykiecie(self):
        self.assertEqual((None, ("A", "B")), pk.parsuj_argumenty(["--tylko", "A", "--tylko", "B"]))
        self.assertEqual(((2, 3), ("A",)), pk.parsuj_argumenty(["--czesc", "2/3", "--tylko", "A"]))
        self.assertEqual((None, ()), pk.parsuj_argumenty([]))
        nazwy = ["a", "b", "c"]
        self.assertEqual([2, 0], sorted(pk.indeksy_po_etykietach(nazwy, ("c", "a")), reverse=True))
        for zle in (["--tylko"], ["--tylko", "A", "--x"], ["--czesc", "1/2", "--czesc", "2/2"]):
            with self.subTest(zle), self.assertRaises(SystemExit):
                pk.parsuj_argumenty(zle)
        with self.assertRaisesRegex(SystemExit, "brak wpisu `checks` o etykiecie 'zz'"):
            pk.indeksy_po_etykietach(nazwy, ("a", "zz"))


if __name__ == "__main__":
    unittest.main()
