#!/usr/bin/env python3
"""Test przyrządu kontroli negatywnych: czerwień liczy się tylko z właściwego powodu (#1011).

Bez bazy, bez PHP, poniżej sekundy. Podstawia `przebiegowi` atrapę testu, która
zwraca przygotowany raport JUnit, i sprawdza każdy werdykt:

  • porażka z oczekiwanym komunikatem → POTWIERDZONA,
  • porażka z innym komunikatem (także drugiego, niezależnego testu spod tego samego filtra), wyjątek zamiast asercji,
    brak raportu (fatal), samo słowo FAILED w wyjściu → ZLA_PRZYCZYNA,
  • zielony test po mutacji → BRAK_PORAZKI,
  • brak mutacji, czerwony baseline, przywrócenie źródła także po wyjątku,
  • kontrola mechanizmu, która dostałaby POTWIERDZONA, wywraca przebieg,
  • kontrola bez wzorca nigdy nie jest POTWIERDZONA (werdykt BEZ_WZORCA, wymieniona z nazwy w raporcie),
    a zły wzorzec i literówka w nazwie kontroli odmawiają przed pierwszą mutacją.

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

import kontrola_przyczyny as n  # noqa: E402


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

    def test_wzorzec_widzi_komunikat_ze_zwinietymi_bialymi_znakami(self):
        zawiniety = n.WynikTestu(1, "FAILED", junit((METODA, "failure", "AssertionFailedError", "Przepuszczony:\n    obcy.pl")))
        self.assertEqual(n.POTWIERDZONA, self.ocen(zawiniety, oczekuj="Przepuszczony: obcy"))

    def test_porazka_z_innym_komunikatem_to_zla_przyczyna(self):
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(ASERCJA, oczekuj="Brak kotwicy"))

    def test_wzorzec_nie_pasuje_do_samej_nazwy_testu(self):
        # Pierwszy wiersz porażki to identyfikator testu — nie może „wyjaśnić" czerwieni.
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(ASERCJA, oczekuj="atrapa_pilnuje"))

    def test_drugi_niezalezny_czerwony_test_pod_tym_samym_filtrem_to_zla_przyczyna(self):
        # Filtr klasy łapie kilka metod: jedna oblewa właściwą asercją, druga z zupełnie
        # innego powodu. Wystarczy, że KAŻDA porażka ma oczekiwany komunikat — druga nie ma.
        dwie = n.WynikTestu(1, "FAILED", junit(
            (METODA, "failure", "ExpectationFailedException", "Przepuszczony: obcy.pl"),
            ("test_inna_regula", "failure", "ExpectationFailedException", "Zupełnie inna asercja"),
        ))
        ocena = n.werdykt("AtrapaStraznikaTest", "Przepuszczony", dwie)
        self.assertEqual(n.ZLA_PRZYCZYNA, ocena.werdykt)
        self.assertIn("test_inna_regula", ocena.powod)

    def test_szeroki_filtr_zalicza_dopiero_gdy_kazda_porazka_ma_wlasciwy_komunikat(self):
        dwie = n.WynikTestu(1, "FAILED", junit(
            (METODA, "failure", "ExpectationFailedException", "Przepuszczony: obcy.pl"),
            ("test_druga_metoda", "failure", "ExpectationFailedException", "Przepuszczony: drugi.pl"),
        ))
        self.assertEqual(n.POTWIERDZONA, n.werdykt("AtrapaStraznikaTest", "Przepuszczony", dwie).werdykt)

    def test_wyjatek_jest_objawem_tylko_gdy_wzorzec_jest_jawnie_wyjatkiem(self):
        blad = n.WynikTestu(1, "FAILED", junit((METODA, "error", "QueryException", "QueryException: SQLSTATE[25P02] transakcja przerwana")))
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(blad, oczekuj="25P02"))
        self.assertEqual(n.POTWIERDZONA, self.ocen(n.WynikTestu(1, "FAILED", blad.junit), oczekuj=n.Wyjatek("QueryException: SQLSTATE.25P02")))
        fatal = n.WynikTestu(2, "FAILED", junit((METODA, "error", "ParseError", "ParseError: syntax error")))
        self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(fatal, oczekuj=n.Wyjatek("QueryException: SQLSTATE.25P02")))

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


class WzorzecKursoraWyszukiwania(unittest.TestCase):
    """#2856: rozszerzona klasa musi mieć wzorzec każdej własnej porażki."""

    NAZWA = "Dalsze okno wyszukiwania bez kursora rankingu"
    KLASA = r"Tests\Feature\StabilneOknaWyszukiwaniaTest"
    METODA = "test_miary_kursora_odrzucone_przez_postgresql_real_wracaja_do_wlasciwego_okna_obu_list"

    def ocen(self, message, kind="failure", output="FAILED"):
        from kontrole_oczekiwana_przyczyna import OCZEKUJ
        report = junit((self.METODA, kind, "ExpectationFailedException", message)).replace(KLASA, self.KLASA)
        return n.werdykt("StabilneOknaWyszukiwaniaTest", OCZEKUJ[self.NAZWA], n.WynikTestu(1, output, report)).werdykt

    def test_wzorzec_obejmuje_nowe_i_stare_wlasne_porazki(self):
        for message in (
            "KURSOR_REAL_2856_PRAWIDLOWA_MIARA_PRZEPISU",
            "KURSOR_REAL_2856_PRAWIDLOWA_MIARA_OSOBY",
            "Dalsze okno powtórzyło już pokazany przepis.",
            "Dalsze okno pominęło przepis, który nie był jeszcze pokazany.",
            "Dalsze okno powtórzyło osobę.",
        ):
            with self.subTest(message=message):
                self.assertEqual(n.POTWIERDZONA, self.ocen(message), "PRZYRZAD_2856_WZORZEC_KAZDEJ_PORAZKI")

    def test_obca_asercja_wyjatek_i_marker_tylko_w_logu_odmawiaja(self):
        for message, kind, output in (
            ("Awaria innego warunku", "failure", "FAILED"),
            ("KURSOR_REAL_2856_PRAWIDLOWA_MIARA_PRZEPISU", "error", "FAILED"),
            ("Awaria innego warunku", "failure", "KURSOR_REAL_2856_PRAWIDLOWA_MIARA_OSOBY"),
        ):
            with self.subTest(message=message, kind=kind):
                self.assertEqual(n.ZLA_PRZYCZYNA, self.ocen(message, kind, output))


class Przebieg(unittest.TestCase):
    """Cały przebieg na pliku w katalogu tymczasowym, z atrapą zamiast `php artisan test`."""

    ORYGINAL = "wzor = '/^host$/'\n"

    def setUp(self):
        katalog = tempfile.TemporaryDirectory()
        self.addCleanup(katalog.cleanup)
        self.root = Path(katalog.name)
        self.plik = self.root / "zrodlo.php"
        self.plik.write_text(self.ORYGINAL)

    NAZWA = "Atrapa bez kotwicy"

    def kontrola(self, mutacja=lambda s: s.replace("$/", "/"), oczekuj="Przepuszczony"):
        return (self.NAZWA, "zrodlo.php", METODA, mutacja, oczekuj)

    def runner(self, po_mutacji, baseline=ZIELONY):
        def uruchom(_test):
            return baseline if self.plik.read_text() == self.ORYGINAL else po_mutacji
        return uruchom

    def uruchom(self, checks, runner, mechanizmu=(), dodatnie=(METODA,)):
        """`checks` to krotki z `kontrola()`; wzorce idą osobno, tak jak w skrypcie."""
        oczekuj = {k[0]: k[4] for k in checks}
        czteroelementowe = [k[:4] for k in checks]
        with contextlib.redirect_stdout(io.StringIO()) as log:
            n.przebieg(list(dodatnie), czteroelementowe, oczekuj, list(mechanizmu), runner=runner, root=self.root)
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

    def bez_wzorca(self, po_mutacji):
        with contextlib.redirect_stdout(io.StringIO()) as log:
            wynik = n.przebieg([METODA], [self.kontrola()[:4]], {}, runner=self.runner(po_mutacji), root=self.root)
        return wynik, log.getvalue()

    def test_kontrola_bez_wzorca_nie_jest_potwierdzona_i_trafia_z_nazwy_do_raportu(self):
        (potwierdzone, bez), log = self.bez_wzorca(ASERCJA)
        self.assertEqual(0, potwierdzone)
        self.assertEqual([self.NAZWA], bez)
        self.assertIn("WERDYKT Atrapa bez kotwicy: BEZ_WZORCA", log)
        self.assertNotIn("POTWIERDZONA", log.replace("POTWIERDZONYCH", ""))
        self.assertIn("BEZ WZORCA", log)
        self.assertIn(self.NAZWA, log.split("BEZ WZORCA")[1])
        self.assertEqual(self.ORYGINAL, self.plik.read_text())

    def test_czesc_ci_sprawdza_wzorce_wzgledem_calosci_a_raportuje_tylko_swoje_wpisy(self):
        # `--czesc N/M`: pętla dostaje `wybrane`, a `wszystkie` to pełne `checks`.
        # Wzorzec kontroli z INNEJ części nie jest literówką, a bez `wszystkie`
        # przebieg uznałby go za wzorzec dla nieistniejącej kontroli.
        kontrola = self.kontrola()
        inna = ("Kontrola z innej części", "zrodlo.php", METODA, kontrola[3], "Przepuszczony")
        oczekuj = {kontrola[0]: kontrola[4], inna[0]: inna[4]}
        with contextlib.redirect_stdout(io.StringIO()):
            wynik = n.przebieg([], [kontrola[:4]], oczekuj, runner=self.runner(ASERCJA),
                               root=self.root, wszystkie=[kontrola[:4], inna[:4]])
        self.assertEqual((1, []), wynik)
        with self.assertRaisesRegex(RuntimeError, "nieistniejącej kontroli"):
            n.przebieg([], [kontrola[:4]], oczekuj, runner=self.runner(ASERCJA), root=self.root)
        self.assertEqual(self.ORYGINAL, self.plik.read_text())

    def test_tryb_wymagaj_wzorca_odmawia_zanim_cokolwiek_zmutuje(self):
        with self.assertRaisesRegex(RuntimeError, "Kontrole bez wzorca"):
            n.przebieg([], [self.kontrola()[:4]], {}, runner=lambda _t: self.fail("uruchomiono test"),
                       root=self.root, wymagaj_wzorca=True)
        self.assertEqual(self.ORYGINAL, self.plik.read_text())

    def test_kontrola_bez_wzorca_nadal_odrzuca_fatal_i_zielony_test(self):
        for wynik in (n.WynikTestu(255, "FAILED", None), ZIELONY,
                      n.WynikTestu(2, "FAILED", junit((METODA, "error", "ParseError", "syntax error")))):
            with self.assertRaises(RuntimeError):
                self.bez_wzorca(wynik)
            self.assertEqual(self.ORYGINAL, self.plik.read_text())

    def test_kontrola_mechanizmu_przechodzi_na_fatalu(self):
        fatal = n.WynikTestu(2, "FAILED", junit((METODA, "error", "ParseError", "syntax error")))
        log = self.uruchom([], self.runner(fatal), mechanizmu=[self.kontrola(oczekuj=".")])
        self.assertIn("ZLA_PRZYCZYNA", log)

    def test_kontrola_mechanizmu_wywraca_przebieg_gdy_fatal_uszedlby_za_dowod(self):
        # Mechanizm, który wziąłby tę czerwień za dowód, sam jest zepsuty.
        self.assertIn("oczekiwano ZLA_PRZYCZYNA",
                      self.odmowa([], self.runner(ASERCJA), mechanizmu=[self.kontrola(oczekuj=".")]))


class Wzorce(unittest.TestCase):
    CHECKS = [("A", "p", "T", lambda s: s), ("B", "p", "T", lambda s: s)]

    def test_kontrola_bez_wzorca_jest_zgloszona_jako_niepelny_dowod(self):
        self.assertEqual(["B"], n.sprawdz_wzorce(self.CHECKS, {"A": "x"}))

    def test_wzorzec_dla_nieistniejacej_kontroli_to_literowka(self):
        with self.assertRaisesRegex(RuntimeError, "nieistniejącej"):
            n.sprawdz_wzorce(self.CHECKS, {"A": "x", "B": "y", "C": "z"})

    def test_zly_i_pusty_wzorzec_sa_odrzucone(self):
        with self.assertRaisesRegex(RuntimeError, "Zły wzorzec"):
            n.sprawdz_wzorce(self.CHECKS, {"A": "(", "B": "y"})
        with self.assertRaisesRegex(RuntimeError, "Pusty wzorzec"):
            n.sprawdz_wzorce(self.CHECKS, {"A": " ", "B": "y"})

    def test_zdublowana_nazwa_kontroli_jest_odrzucona(self):
        with self.assertRaisesRegex(RuntimeError, "Dwie kontrole"):
            n.sprawdz_wzorce(self.CHECKS + [("A", "p", "T", lambda s: s)], {"A": "x", "B": "y"})

    def test_preflight_z_wymaganiem_odrzuca_nowy_wpis_bez_wzorca_z_nazwa(self):
        # Strażnik #1011: WYMAGAJ_WZORCA=True w skrypcie kontroli oznacza, że
        # `sprawdz_wzorce(..., wymagaj=True)` odmawia PRZED jakimkolwiek testem.
        with self.assertRaisesRegex(RuntimeError, r"Kontrole bez wzorca oczekiwanej przyczyny.*: B$"):
            n.sprawdz_wzorce(self.CHECKS, {"A": "x"}, wymagaj=True)
        self.assertEqual([], n.sprawdz_wzorce(self.CHECKS, {"A": "x", "B": "y"}, wymagaj=True))
        # bez wymagania (tryb `--tylko`) ta sama luka jest tylko raportowana
        self.assertEqual(["B"], n.sprawdz_wzorce(self.CHECKS, {"A": "x"}, wymagaj=False))


class SkryptKontroli(unittest.TestCase):
    """Skrypt kontroli ma wymagać wzorca i przekazywać to wymaganie do preflightu."""

    ZRODLO = (Path(__file__).resolve().parents[2] / "scripts" / "kontrole-negatywne-alfa08.py").read_text()

    def test_wymagaj_wzorca_jest_wlaczone(self):
        self.assertRegex(self.ZRODLO, r"(?m)^WYMAGAJ_WZORCA = True$")

    def test_preflight_i_przebieg_dostaja_wymaganie(self):
        self.assertIn("sprawdz_wzorce(checks, OCZEKUJ, wymagaj=WYMAGAJ_TERAZ)", self.ZRODLO)
        self.assertIn("wymagaj_wzorca=WYMAGAJ_TERAZ", self.ZRODLO)
        self.assertRegex(self.ZRODLO, r"(?m)^WYMAGAJ_TERAZ = WYMAGAJ_WZORCA and not TYLKO$")

    def test_preflight_stoi_przed_pierwszym_testem(self):
        self.assertLess(self.ZRODLO.index("sprawdz_wzorce(checks, OCZEKUJ, wymagaj=WYMAGAJ_TERAZ)"),
                        self.ZRODLO.index("run_test(test, True)"))


if __name__ == "__main__":
    unittest.main(verbosity=1)
