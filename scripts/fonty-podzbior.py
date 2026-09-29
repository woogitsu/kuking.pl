#!/usr/bin/env python3
"""Generuje podzbiory Inter Variable serwowane przez Kuking (#1000).

Wejście:  resources/fonts/zrodlo/*.woff2  — pełne pliki `latin` i `latin-ext`
          z paczki @fontsource-variable/inter 5.3.0 (SIL OFL 1.1).
Wyjście:  resources/fonts/inter-podstawa-wght-normal.woff2
          resources/fonts/inter-europa-wght-normal.woff2
          resources/fonts/podzbior.json   (kontrakt znaków + rozmiary)

Uruchomienie:  python3 scripts/fonty-podzbior.py   (pip install fonttools brotli)
Test kontraktu: tests/Feature/PodzbiorFontuMaPolskieZnakiTest.php

Zasady:
- zakresy znaków są TU, w jednym miejscu; `resources/css/fonts.css` powtarza je
  jako `unicode-range`, a test sprawdza, że oba opisy są identyczne;
- oś `wght` 100–900 i wszystkie funkcje layoutu zostają (nic nie jest
  przycinane poza glifami spoza kontraktu);
- wynik jest deterministyczny (ten sam plik źródłowy daje te same bajty);
- znak spoza kontraktu nie znika: przeglądarka bierze go ze stosu systemowego
  (`--font-sans` w resources/css/app.css).
Aktualizacja Inter: podmień pliki w zrodlo/, uruchom skrypt, `npm run build`.
"""

import json
import sys
from pathlib import Path

from fontTools import subset
from fontTools.ttLib import TTFont

KATALOG = Path(__file__).resolve().parent.parent / "resources" / "fonts"

# Podstawa: ASCII + Latin-1 (ó, é, ü, ß, ç…) + typowa interpunkcja.
PODSTAWA = [
    (0x0020, 0x007E), (0x00A0, 0x00FF), (0x0131, 0x0131),
    (0x2013, 0x2014), (0x2018, 0x201E), (0x2020, 0x2022), (0x2026, 0x2026),
    (0x2030, 0x2030), (0x2039, 0x203A), (0x20AC, 0x20AC), (0x2122, 0x2122),
    (0x2212, 0x2212),
]
# Europa: Latin Extended-A (ą ć ę ł ń ś ź ż oraz nazwiska czeskie, słowackie,
# węgierskie, rumuńskie, litewskie, chorwackie). Ładowana tylko wtedy, gdy
# na stronie jest choć jeden z tych znaków.
EUROPA = [(0x0100, 0x017F)]

PLIKI = [
    ("inter-latin-wght-normal.woff2", "inter-podstawa-wght-normal.woff2", PODSTAWA),
    ("inter-latin-ext-wght-normal.woff2", "inter-europa-wght-normal.woff2", EUROPA),
]


def zakres_css(zakresy):
    return ", ".join(
        "U+%04X" % a if a == b else "U+%04X-%04X" % (a, b) for a, b in zakresy
    )


def zakresy_z_cmap(plik):
    """Kody znaków, które plik NAPRAWDĘ ma (cmap), jako zwarte zakresy."""
    font = TTFont(str(plik))
    kody = sorted(font.getBestCmap())
    font.close()
    wynik = []
    for k in kody:
        if wynik and k == wynik[-1][1] + 1:
            wynik[-1][1] = k
        else:
            wynik.append([k, k])
    return zakres_css([tuple(z) for z in wynik])


def podzbior(zrodlo, cel, zakresy):
    opcje = subset.Options()
    opcje.flavor = "woff2"
    opcje.layout_features = ["*"]
    opcje.name_IDs = ["*"]
    opcje.notdef_outline = True
    opcje.glyph_names = False
    opcje.hinting = False
    opcje.layout_closure = True
    font = subset.load_font(str(zrodlo), opcje)
    sub = subset.Subsetter(opcje)
    sub.populate(unicodes=[c for a, b in zakresy for c in range(a, b + 1)])
    sub.subset(font)
    subset.save_font(font, str(cel), opcje)
    font.close()


def main():
    raport = {}
    for zrodlo, cel, zakresy in PLIKI:
        wejscie = KATALOG / "zrodlo" / zrodlo
        wyjscie = KATALOG / cel
        podzbior(wejscie, wyjscie, zakresy)
        raport[cel] = {
            "unicode_range": zakres_css(zakresy),
            "glify": zakresy_z_cmap(wyjscie),
            "bajty": wyjscie.stat().st_size,
            "zrodlo_bajty": wejscie.stat().st_size,
        }
    raport["razem_bajty"] = sum(v["bajty"] for v in raport.values() if isinstance(v, dict))
    (KATALOG / "podzbior.json").write_text(
        json.dumps(raport, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    print(json.dumps(raport, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
