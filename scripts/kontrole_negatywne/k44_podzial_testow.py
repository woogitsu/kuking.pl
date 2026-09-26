"""Testy w CI w czterech częściach — żaden plik nie może wypaść (24.09.2026).
Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


BRAMKA_CI = ".github/workflows/ci.yml"
# Testy w CI idą w czterech równoległych częściach (24.09.2026). Plik, który
# nie trafi do żadnej części, nie uruchamia się nigdzie, a przebieg jest zielony.
# Pierwsza mutacja gubi plik w SAMYM ODKRYWANIU listy — własny sprawdzian
# skryptu jej nie widzi (porównuje części ze swoją, też krótszą listą), więc
# zapalić ma porównanie z listą PHPUnita. Druga skraca macierz w ci.yml.
PODZIAL_TESTOW = "scripts/podzial-testow.php"
PODZIAL_TESTOW_TEST = "PodzialTestowJestKompletnyTest"


def podzial_gubi_plik(source):
    """KONTROLA DODATNIA: odkrywanie plików testów gubi pierwszy plik listy."""
    return replace_once(
        source,
        "    $pliki = array_values(array_unique(array_merge(...$pliki)));",
        "    $pliki = array_slice(array_values(array_unique(array_merge(...$pliki))), 1);",
    )


def macierz_krotsza_niz_podzial(source):
    """KONTROLA DODATNIA: macierz uruchamia trzy części, skrypt dzieli na cztery."""
    return replace_once(source, "czesc: [1, 2, 3, 4, kontrole]", "czesc: [1, 2, 3, kontrole]")


KONTROLE_DODATNIE = [PODZIAL_TESTOW_TEST]

KONTROLE = [
    Kontrola("Podział testów gubi plik", PODZIAL_TESTOW, PODZIAL_TESTOW_TEST, podzial_gubi_plik),
    Kontrola("Macierz testów krótsza niż podział", BRAMKA_CI, PODZIAL_TESTOW_TEST, macierz_krotsza_niz_podzial),
]
