"""Kontrole dodatnie SAMEGO MECHANIZMU: czerwień z niewłaściwego powodu ma nie zaliczać (#1011).

Do 24.09.2026 wystarczał dowolny niezerowy kod i słowo `FAILED`. Te dwie
mutacje dają czerwień, która NIE jest dowodem, i przebieg ma ją odrzucić
werdyktem ZLA_PRZYCZYNA — jeśli dostałyby POTWIERDZONA, pada cały krok CI:

  1. Fatal zamiast asercji: błąd składni w klasie strażnika. Wzorzec `.`
     pasuje do wszystkiego, więc odrzucić może go tylko rozpoznanie wyjątku
     (`<error>` w JUnit) albo brak raportu — dokładnie to, co mechanizm
     przestałby umieć, gdyby wrócił do liczenia samego `FAILED`.
  2. Właściwa asercja, obcy komunikat: ta sama mutacja co „bez kotwicy końca"
     w k16, ale z wzorcem, którego test nigdy nie wypisze. Dowód, że `oczekuj`
     jest naprawdę porównywany z prawdziwym wyjściem PHPUnita.

Obie idą PRZED zwykłymi kontrolami i przywracają źródło tak samo jak one.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


STRAZNIK_R2 = "app/Support/Storage/DozwolonyHostR2.php"
STRAZNIK_R2_TEST = "test_straznik_r2_odrzuca_host_spoza_wzoru"
WZOR_R2 = r"""'/^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$/'"""


def blad_skladni(source):
    """Stała bez średnika — PHP nie załaduje klasy, test pada wyjątkiem ParseError."""
    return replace_once(source, WZOR_R2 + ";", WZOR_R2)


KONTROLE_MECHANIZMU = [
    Kontrola("Mechanizm: fatal zamiast asercji", STRAZNIK_R2, STRAZNIK_R2_TEST,
             blad_skladni, oczekuj=r"."),
    Kontrola("Mechanizm: asercja z obcym komunikatem", STRAZNIK_R2, STRAZNIK_R2_TEST,
             lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace("$/", "/")),
             oczekuj=r"Komunikat, którego ten test nigdy nie wypisze"),
]
