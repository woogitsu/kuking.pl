"""Regresje zawężenia `--filter` do plików w kontrolach negatywnych (#2299).

Zawężenie ma tylko przyspieszać: dla każdego filtra lista plików musi obejmować
każdy test, który PHPUnit wybrałby z pełnego zestawu — także taki, który
pasuje wyłącznie nazwą zbioru danych. Gdy nie da się tego zagwarantować,
polecenie ma wrócić do pełnego zestawu.
"""

import contextlib
import io
import os
import subprocess
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))

import zawezenie_testow as z  # noqa: E402

# Wiersze w formacie `artisan test --list-tests` (PHPUnit 12: ` with data set `
# wycięte z nazwy, zbiór zaczyna się od `"` albo `#`).
SPIS = """PHPUnit 12.5.34 by Sebastian Bergmann and contributors.

Available tests:
 - Tests\\Feature\\KoszykTest::test_dodaje_pozycje
 - Tests\\Feature\\KoszykTest::test_limit"pusty_koszyk_mowi_co_zrobic"
 - Tests\\Feature\\KoszykTest::test_limit#1
 - Tests\\Feature\\InnyTest::test_bez_zwiazku"zbior z grzybami"
 - Tests\\Unit\\StrażnikTest::test_zaden_widok_nie_liczy
 - Tests\\Unit\\TydzienFikcyjnyTest::test_tydzien
"""

PLIKI = {
    "Tests\\Feature\\KoszykTest": "tests/Feature/KoszykTest.php",
    "Tests\\Feature\\InnyTest": "tests/Feature/InnyTest.php",
    "Tests\\Unit\\StrażnikTest": "tests/Unit/StrażnikTest.php",
    "Tests\\Unit\\TydzienFikcyjnyTest": "tests/Unit/TydzienFikcyjnyTest.php",
}


def indeks():
    return z.Indeks(z.pelne_nazwy(SPIS), dict(PLIKI))


class PelneNazwyTest(unittest.TestCase):
    def test_zbior_danych_wraca_do_nazwy_tak_jak_widzi_ja_filtr(self):
        nazwy = dict((pelna, klasa) for klasa, pelna in z.pelne_nazwy(SPIS))
        self.assertIn('Tests\\Feature\\KoszykTest::test_limit with data set "pusty_koszyk_mowi_co_zrobic"', nazwy)
        self.assertIn("Tests\\Feature\\KoszykTest::test_limit with data set #1", nazwy)
        self.assertIn("Tests\\Feature\\KoszykTest::test_dodaje_pozycje", nazwy)

    def test_naglowek_spisu_nie_jest_testem(self):
        self.assertEqual(6, len(z.pelne_nazwy(SPIS)))

    def test_pliki_klas_z_xml_phpunita_wzgledem_katalogu(self):
        xml = ('<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>'
               '<testClass name="Tests\\Feature\\KoszykTest" file="/repo/tests/Feature/KoszykTest.php">'
               '<testMethod id="x" name="test_dodaje_pozycje"/></testClass></tests></testSuite>')
        self.assertEqual({"Tests\\Feature\\KoszykTest": "tests/Feature/KoszykTest.php"}, z.pliki_klas(xml, "/repo"))


class PlikiDlaFiltraTest(unittest.TestCase):
    def test_nazwa_metody_wybiera_plik_jej_klasy(self):
        self.assertEqual(["tests/Feature/KoszykTest.php"], indeks().pliki_dla("test_dodaje_pozycje"))

    def test_filtr_pasujacy_tylko_nazwa_zbioru_danych_nie_gubi_pliku(self):
        # PHPUnit dopasowuje filtr także do „with data set …”; bez tego ten
        # test zniknąłby z zawężonego przebiegu po cichu.
        self.assertEqual(["tests/Feature/InnyTest.php"], indeks().pliki_dla("grzybami"))
        self.assertEqual(["tests/Feature/KoszykTest.php"], indeks().pliki_dla("pusty_koszyk"))

    def test_wielkosc_liter_ascii_jak_w_pcre_i(self):
        self.assertEqual(["tests/Unit/TydzienFikcyjnyTest.php"], indeks().pliki_dla("tydzienfikcyjnytest"))

    def test_alternatywa_i_klasa_z_metoda(self):
        self.assertEqual(["tests/Feature/KoszykTest.php", "tests/Unit/TydzienFikcyjnyTest.php"],
                         indeks().pliki_dla("test_dodaje_pozycje|TydzienFikcyjnyTest"))
        self.assertEqual(["tests/Feature/KoszykTest.php"], indeks().pliki_dla("KoszykTest::test_limit"))

    def test_szeroki_filtr_bierze_kazdy_pasujacy_plik(self):
        self.assertEqual(sorted(PLIKI.values()), indeks().pliki_dla("Test"))

    def test_filtr_z_wyrazeniem_albo_zbiorem_idzie_pelnym_zestawem(self):
        for filtr in ("test_limit#1", "test_limit@pusty.*", "test_.*_pozycje", "Tests\\\\Feature", "|KoszykTest",
                      "KoszykTest|", "_KoszykTest", "test limit"):
            with self.subTest(filtr=filtr):
                self.assertIsNone(indeks().pliki_dla(filtr))

    def test_filtr_bez_dopasowania_idzie_pelnym_zestawem(self):
        self.assertIsNone(indeks().pliki_dla("test_ktorego_nie_ma"))

    def test_klasa_bez_pliku_w_spisie_idzie_pelnym_zestawem(self):
        pliki = dict(PLIKI)
        del pliki["Tests\\Feature\\InnyTest"]
        self.assertIsNone(z.Indeks(z.pelne_nazwy(SPIS), pliki).pliki_dla("Test"))

    def test_litera_spoza_ascii_nie_daje_dopasowania_ascii(self):
        # „ż” nie ma odpowiednika w ASCII: filtr ASCII nie może jej dopasować.
        self.assertIsNone(indeks().pliki_dla("straznikTest"))
        self.assertEqual(["tests/Unit/StrażnikTest.php"], indeks().pliki_dla("test_zaden_widok_nie_liczy"))


class PolecenieTest(unittest.TestCase):
    def test_zawezone_polecenie_zachowuje_filtr_i_dokleja_pliki_przed_nim(self):
        self.assertEqual(
            ["php", "artisan", "test", "tests/Feature/KoszykTest.php", "--filter=test_dodaje_pozycje", "--no-ansi",
             "--log-junit", "r.xml"],
            z.polecenie_testu("test_dodaje_pozycje", "--log-junit", "r.xml", indeks=indeks()),
        )

    def test_bez_zawezenia_polecenie_jak_dawniej(self):
        self.assertEqual(["php", "artisan", "test", "--filter=test_.*", "--no-ansi"],
                         z.polecenie_testu("test_.*", indeks=indeks()))

    def test_brak_spisu_to_pelny_zestaw(self):
        with mock.patch.object(z, "_INDEKS", [None]):
            self.assertEqual(["php", "artisan", "test", "--filter=KoszykTest", "--no-ansi"], z.polecenie_testu("KoszykTest"))

    def test_wylacznik_awaryjny(self):
        with mock.patch.dict(os.environ, {"KUKING_KONTROLE_BEZ_ZAWEZENIA": "1"}), \
                mock.patch.object(z, "_INDEKS", [indeks()]):
            self.assertEqual(["php", "artisan", "test", "--filter=KoszykTest", "--no-ansi"], z.polecenie_testu("KoszykTest"))

    def test_nieudany_spis_daje_none(self):
        def pada(*_a, **_k):
            return subprocess.CompletedProcess([], 1, "Fatal", None)
        self.assertIsNone(z.zbuduj_indeks(".", uruchom=pada))


class ZgodnoscTest(unittest.TestCase):
    """Próba na żywo: różnica między pełnym a zawężonym przebiegiem zatrzymuje kontrole."""

    def uruchom_z(self, pelny, zawezony):
        def uruchom(polecenie, **_k):
            testy = zawezony if any(a.endswith(".php") for a in polecenie) else pelny
            raport = Path(polecenie[polecenie.index("--log-junit") + 1])
            raport.write_text("<testsuites>" + "".join(
                f'<testcase class="{k}" name="{n}"/>' for k, n in testy) + "</testsuites>")
            return subprocess.CompletedProcess(polecenie, 0, "", None)
        return uruchom

    def test_te_same_testy_przechodza(self):
        testy = [("Tests\\Feature\\KoszykTest", "test_dodaje_pozycje")]
        with contextlib.redirect_stdout(io.StringIO()) as wyjscie:
            z.sprawdz_zgodnosc(["test_dodaje_pozycje"], indeks=indeks(), uruchom=self.uruchom_z(testy, testy))
        self.assertIn("ZGODNOŚĆ zawężenia", wyjscie.getvalue())

    def test_zgubiony_test_zatrzymuje(self):
        pelne = [("Tests\\Feature\\KoszykTest", "test_dodaje_pozycje"), ("Tests\\Feature\\InnyTest", "test_x")]
        with self.assertRaisesRegex(RuntimeError, "rozjechało się z pełnym zestawem"):
            z.sprawdz_zgodnosc(["test_dodaje_pozycje"], indeks=indeks(), uruchom=self.uruchom_z(pelne, pelne[:1]))

    def test_pusty_przebieg_zatrzymuje(self):
        with self.assertRaisesRegex(RuntimeError, "pełny 0 testów"):
            z.sprawdz_zgodnosc(["test_dodaje_pozycje"], indeks=indeks(), uruchom=self.uruchom_z([], []))



class PodpiecieTest(unittest.TestCase):
    """Zawężenie działa tylko wtedy, gdy oba uruchamiacze i skrypt kontroli z niego korzystają."""

    KATALOG = Path(__file__).resolve().parent

    def test_uruchamiacze_buduja_polecenie_przez_zawezenie(self):
        for plik, wywolanie in (("kontrola_przyczyny.py", 'polecenie_testu(name, "--log-junit", str(raport))'),
                                ("kontrola_wyjscia_testu.py", "polecenie_testu(name)")):
            with self.subTest(plik=plik):
                self.assertIn(wywolanie, (self.KATALOG / plik).read_text(encoding="utf-8"))

    def test_skrypt_kontroli_robi_probe_zgodnosci_przed_kontrolami_dodatnimi(self):
        zrodlo = (self.KATALOG / "kontrole-negatywne-alfa08.py").read_text(encoding="utf-8")
        proba = zrodlo.find("sprawdz_zgodnosc(kontrole_dodatnie[:2])")
        self.assertNotEqual(-1, proba, "Skrypt kontroli nie robi próby zgodności zawężenia (#2299).")
        self.assertLess(proba, zrodlo.find("    run_test(test, True)"),
                        "Próba zgodności ma iść przed pierwszym testem, na nietkniętych źródłach.")


if __name__ == "__main__":
    unittest.main()
