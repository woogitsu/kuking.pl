"""Kontrola dodatnia i ujemna bramki audytu zależności #2215, bez sieci i PHP."""

import datetime
import importlib.util
import tempfile
import unittest
from pathlib import Path

PLIK = Path(__file__).with_name("audyt-zaleznosci.py")
SPEC = importlib.util.spec_from_file_location("audyt_zaleznosci", PLIK)
MODUL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODUL)

DZIS = datetime.date(2026, 9, 29)
CZYSTY_COMPOSER = {"advisories": []}
CZYSTY_NPM = {"vulnerabilities": {}}


def composer(severity="high", ident="PKSA-1"):
    a = {"advisoryId": ident, "cve": "CVE-2026-1", "title": "Dziura", "link": "https://x/1",
         "sources": [{"name": "GitHub", "remoteId": "GHSA-aaaa"}]}
    if severity is not None:
        a["severity"] = severity
    return {"advisories": {"vendor/pakiet": [a]}}


def npm(severity="high"):
    return {"vulnerabilities": {
        "vite": {"severity": severity, "via": [{
            "source": 42, "name": "vite", "title": "Dziura", "severity": severity,
            "url": "https://example.test/advisories/GHSA-bbbb"}]},
        "tranzyt": {"severity": severity, "via": ["vite"]},
    }}


def wyjatek(**zmiany):
    w = {"id": "GHSA-aaaa", "narzedzie": "composer", "zakres": "tylko dev", "wlasciciel": "mateusz",
         "zgoda": "#2215", "wygasa": "2026-10-15"}
    w.update(zmiany)
    return w


def ocen(c=CZYSTY_COMPOSER, n=CZYSTY_NPM, wyjatki=()):
    return MODUL.ocen(c, n, {"wyjatki": list(wyjatki)}, DZIS)


class AudytZaleznosciTest(unittest.TestCase):
    def test_czysty_wynik_przepuszcza(self):
        self.assertTrue(ocen()[0])

    def test_composer_high_critical_i_brak_poziomu_blokuja(self):
        for poziom in ("high", "critical", None):
            with self.subTest(poziom=poziom):
                self.assertFalse(ocen(c=composer(poziom))[0])

    def test_composer_niski_poziom_tylko_informuje(self):
        ok, linie, _ = ocen(c=composer("medium"))
        self.assertTrue(ok)
        self.assertIn("nie blokuje", linie[0])

    def test_npm_high_i_critical_blokuja_a_tranzyt_nie_liczy_sie_podwojnie(self):
        for poziom in ("high", "critical"):
            ok, _, blokady = ocen(n=npm(poziom))
            self.assertFalse(ok)
            self.assertEqual(1, len(blokady))

    def test_npm_moderate_nie_blokuje(self):
        self.assertTrue(ocen(n=npm("moderate"))[0])

    def test_wazny_wyjatek_po_identyfikatorze_przepuszcza(self):
        for ident in ("GHSA-aaaa", "CVE-2026-1", "PKSA-1"):
            with self.subTest(ident=ident):
                self.assertTrue(ocen(c=composer(), wyjatki=[wyjatek(id=ident)])[0])

    def test_wyjatek_npm_po_identyfikatorze_zalecenia(self):
        self.assertTrue(ocen(n=npm(), wyjatki=[wyjatek(id="GHSA-bbbb", narzedzie="npm")])[0])

    def test_wyjatek_innego_narzedzia_lub_id_nie_pomaga(self):
        self.assertFalse(ocen(c=composer(), wyjatki=[wyjatek(narzedzie="npm")])[0])
        self.assertFalse(ocen(c=composer(), wyjatki=[wyjatek(id="GHSA-inne")])[0])

    def test_wygasly_wyjatek_blokuje_nawet_bez_podatnosci(self):
        ok, _, blokady = ocen(wyjatki=[wyjatek(wygasa="2026-09-28")])
        self.assertFalse(ok)
        self.assertIn("wygasł", blokady[0])

    def test_wyjatek_dluzszy_niz_30_dni_lub_niepelny_blokuje(self):
        self.assertFalse(ocen(wyjatki=[wyjatek(wygasa="2026-11-15")])[0])
        self.assertFalse(ocen(wyjatki=[wyjatek(wlasciciel="")])[0])
        self.assertFalse(ocen(wyjatki=[wyjatek(wygasa="jutro")])[0])

    def test_uszkodzony_plik_wyniku_blokuje(self):
        with tempfile.TemporaryDirectory() as katalog:
            zly = Path(katalog) / "zly.json"
            zly.write_text("nie json", encoding="utf-8")
            with self.assertRaises(MODUL.BladWejscia):
                MODUL.wczytaj(zly, "composer audit")


if __name__ == "__main__":
    unittest.main()
