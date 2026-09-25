#!/usr/bin/env python3
"""Test przyrządu kontroli negatywnych: czerwień liczy się tylko z właściwego powodu (#1011).

Bez bazy, bez PHP, poniżej sekundy. Podstawia `przebiegowi` atrapę testu, która
zwraca przygotowany raport JUnit, i sprawdza każdy werdykt:

  • porażka z oczekiwanym komunikatem → POTWIERDZONA,
  • porażka z innym komunikatem, porażka innego testu, wyjątek zamiast asercji,
    brak raportu (fatal), samo słowo FAILED w wyjściu → ZLA_PRZYCZYNA,
  • zielony test po mutacji → BRAK_PORAZKI,
  • brak mutacji, czerwony baseline, przywrócenie źródła także po wyjątku,
  • kontrola mechanizmu, która dostałaby POTWIERDZONA, wywraca przebieg,
  • loader odmawia kontroli bez `oczekuj`.

  python3 tests/skrypty/kontrole-negatywne-przyczyna.py
"""

import contextlib
import io
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from kontrole_negatywne import _narzedzia as n  # noqa: E402


KLASA = "Tests\\Feature\\AtrapaStraznikaTest"
METODA = "test_atrapa_pilnuje_wzoru"


def junit(*porazki, testow=3):
    """Raport JUnit w kształcie PHPUnita: `(metoda, rodzaj, typ, komunikat)`."""
    przypadki = []
    for i in range(testow):
        przypadki.append(f'<testcase name="test_zielony_{i}" class="{KLASA}"/>')
    for metoda, rodzaj, typ, komunikat in porazki:
        przypadki.append(
            f'<testcase name="{metoda} with data set &quot;x&quot;" class="{KLASA}">'
            f'<{rodzaj} type="{typ}">{KLASA}::{metoda} with data set "x"\n{komunikat}\n\n/plik.php:1</{rodzaj}>'
            "</testcase>"
        )
    return '<?xml version="1.0"?><testsuites><testsuite name="x">' + "".join(przypadki) + "</testsuite></testsuites>"


ZIELONY = n.WynikTestu(0, "OK", junit())
ASERCJA = n.WynikTestu(1, "FAILED", junit((METODA, "failure", "PHPUnit\\Framework\\ExpectationFailedException",
                                           "Przepuszczony: obcy.pl\nFailed asserting that null is not null.")))


class Werdykt(unittest.TestCase):
    def ocen(self, wynik, oczekuj="Przepuszczony", test=METODA):
        return n.werdykt(test, oczekuj, wynik).werdykt

    def test_porazka_z_oczekiwanym_komunikatem_potwierdza(self):
        self.assertEqual(n.POTWIERDZONA, self.ocen(ASERCJA))
        self.assertEqual(n.POTWIERDZONA, self.ocen(ASERCJA, test="AtrapaStraznikaTest"))

    def test_porazka_z_innym_komunikatem_to_zla_przyczyna(self):
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(ASERCJA, oczekuj="Brak kotwicy"))

    def test_wzorzec_nie_pasuje_do_samej_nazwy_testu(self):
        # Pierwszy wiersz porażki to identyfikator testu — nie może „wyjaśnić" czerwieni.
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(ASERCJA, oczekuj="atrapa_pilnuje"))

    def test_porazka_innego_testu_to_zla_przyczyna(self):
        inny = n.WynikTestu(1, "FAILED", junit(("test_cos_innego", "failure", "AssertionFailedError", "Przepuszczony")))
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(inny))

    def test_wyjatek_zamiast_asercji_to_zla_przyczyna_nawet_przy_wzorcu_na_wszystko(self):
        fatal = n.WynikTestu(2, "FAILED", junit((METODA, "error", "ParseError", "syntax error, unexpected token")))
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(fatal, oczekuj="."))

    def test_brak_raportu_to_zla_przyczyna_mimo_slowa_failed(self):
        # Dokładnie stary warunek: kod niezerowy + FAILED w wyjściu. Już nie wystarcza.
        padl = n.WynikTestu(255, "PHP Fatal error ... FAILED", None)
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(padl, oczekuj="."))

    def test_kod_niezerowy_bez_porazek_to_zla_przyczyna(self):
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(n.WynikTestu(1, "FAILED", junit())))

    def test_nieczytelny_raport_to_zla_przyczyna(self):
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(n.WynikTestu(1, "FAILED", "<testsuites>")))

    def test_zielony_po_mutacji_to_brak_porazki(self):
        self.assertEqual(n.BRAK_PORAZKI, self.ocen(ZIELONY))

    def test_filtr_bez_testow_to_zla_przyczyna(self):
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(n.WynikTestu(0, "", junit(testow=0))))


class Przebieg(unittest.TestCase):
    """Cały przebieg na pliku w katalogu tymczasowym, z atrapą zamiast `php artisan test`."""

    ORYGINAL = "wzor = '/^host$/'\n"

    def setUp(self):
        katalog = tempfile.TemporaryDirectory()
        self.addCleanup(katalog.cleanup)
        self.root = Path(katalog.name)
        self.plik = self.root / "zrodlo.php"
        self.plik.write_text(self.ORYGINAL)

    def kontrola(self, mutacja=lambda s: s.replace("$/", "/"), oczekuj="Przepuszczony"):
        return n.Kontrola("Atrapa bez kotwicy", "zrodlo.php", METODA, mutacja, oczekuj)

    def runner(self, po_mutacji, baseline=ZIELONY):
        def uruchom(_test):
            return baseline if self.plik.read_text() == self.ORYGINAL else po_mutacji
        return uruchom

    def uruchom(self, checks, runner, mechanizmu=(), dodatnie=(METODA,)):
        with contextlib.redirect_stdout(io.StringIO()) as log:
            n.przebieg(list(dodatnie), checks, list(mechanizmu), runner=runner, root=self.root)
        return log.getvalue()

    def odmowa(self, *args, **kwargs):
        with self.assertRaises(RuntimeError) as blad:
            self.uruchom(*args, **kwargs)
        self.assertEqual(self.ORYGINAL, self.plik.read_text(), "Źródło nie wróciło po odmowie.")
        return str(blad.exception)

    def test_oczekiwana_porazka_zalicza_i_przywraca_zrodlo(self):
        log = self.uruchom([self.kontrola()], self.runner(ASERCJA))
        self.assertIn("WERDYKT Atrapa bez kotwicy: POTWIERDZONA", log)
        self.assertNotIn("FAILED", log, "Oczekiwana czerwień nie ma trafiać do logu surowym wyjściem.")
        self.assertEqual(self.ORYGINAL, self.plik.read_text())

    def test_inny_komunikat_wywraca_przebieg(self):
        self.assertIn(n.ZLA_PRZYCZYNA, self.odmowa([self.kontrola(oczekuj="Brak kotwicy")], self.runner(ASERCJA)))

    def test_fatal_po_mutacji_wywraca_przebieg(self):
        self.assertIn(n.ZLA_PRZYCZYNA, self.odmowa([self.kontrola()], self.runner(n.WynikTestu(255, "FAILED", None))))

    def test_brak_mutacji_odmawia(self):
        self.assertIn("nie zmieniła", self.odmowa([self.kontrola(mutacja=lambda s: s)], self.runner(ASERCJA)))

    def test_zielony_po_mutacji_odmawia(self):
        self.assertIn(n.BRAK_PORAZKI, self.odmowa([self.kontrola()], self.runner(ZIELONY)))

    def test_czerwony_baseline_odmawia_przed_mutacja(self):
        mutacje = []

        def mutacja(s):
            mutacje.append(1)
            return s + "#"

        self.assertIn("przed mutacją", self.odmowa([self.kontrola(mutacja=mutacja)], self.runner(ASERCJA, baseline=ASERCJA)))
        self.assertEqual([], mutacje, "Czerwony baseline ma zatrzymać przebieg, zanim cokolwiek zmutuje.")

    def test_czerwony_po_przywroceniu_odmawia(self):
        stan = {"po": False}

        def uruchom(_test):
            if self.plik.read_text() != self.ORYGINAL:
                stan["po"] = True
                return ASERCJA
            return ASERCJA if stan["po"] else ZIELONY

        self.assertIn("po przywróceniu", self.odmowa([self.kontrola()], uruchom))

    def test_zrodlo_wraca_po_wyjatku_w_tescie(self):
        def uruchom(_test):
            if self.plik.read_text() != self.ORYGINAL:
                raise RuntimeError("przerwany test")
            return ZIELONY

        self.odmowa([self.kontrola()], uruchom)

    def test_kontrola_mechanizmu_przechodzi_na_fatalu(self):
        fatal = n.WynikTestu(2, "FAILED", junit((METODA, "error", "ParseError", "syntax error")))
        log = self.uruchom([], self.runner(fatal), mechanizmu=[self.kontrola(oczekuj=".")])
        self.assertIn("ZLA_PRZYCZYNA", log)

    def test_kontrola_mechanizmu_wywraca_przebieg_gdy_fatal_uszedlby_za_dowod(self):
        # Mechanizm, który wziąłby tę czerwień za dowód, sam jest zepsuty.
        self.assertIn("oczekiwano ZLA_PRZYCZYNA",
                      self.odmowa([], self.runner(ASERCJA), mechanizmu=[self.kontrola(oczekuj=".")]))


class Loader(unittest.TestCase):
    def zaladuj(self, tresc):
        with tempfile.TemporaryDirectory() as katalog:
            (Path(katalog) / "k01_atrapa.py").write_text(tresc)
            with mock.patch.object(n, "KATALOG", Path(katalog)):
                return n.zaladuj()

    NAGLOWEK = "from kontrole_negatywne._narzedzia import Kontrola\n"

    def test_kontrola_bez_oczekuj_jest_odrzucona(self):
        with self.assertRaisesRegex(RuntimeError, "bez `oczekuj`"):
            self.zaladuj(self.NAGLOWEK + 'KONTROLE = [Kontrola("A", "p", "T", lambda s: s)]\n')

    def test_zly_wzorzec_jest_odrzucony(self):
        with self.assertRaisesRegex(RuntimeError, "zły wzorzec"):
            self.zaladuj(self.NAGLOWEK + 'KONTROLE = [Kontrola("A", "p", "T", lambda s: s, "(")]\n')

    def test_prawdziwy_katalog_ma_wzorzec_przy_kazdej_kontroli_i_kontrole_mechanizmu(self):
        _dodatnie, kontrole, mechanizmu = n.zaladuj()
        self.assertTrue(kontrole)
        self.assertTrue(mechanizmu, "Brak kontroli mechanizmu — nic nie dowodzi, że fatal zostanie odrzucony.")
        self.assertTrue(all(k.oczekuj for k in kontrole + mechanizmu))


if __name__ == "__main__":
    unittest.main(verbosity=1)
