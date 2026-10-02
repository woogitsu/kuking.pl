# Dosłowny znak porównania w imporcie JSON-LD (#2580)

Po dwóch dopuszczonych warstwach dekodowania zapis `&amp;lt;80` staje się
`<80`. PHP `strip_tags()` traktował go jak początek niedomkniętego znacznika
i usuwał liczbę wraz z resztą instrukcji. Dotyczyło to zarówno tekstowego
`recipeInstructions`, jak i pola `text` w `HowToStep`.

Przed usuwaniem rzeczywistych znaczników HTML chronimy dosłowny znak `<`
bezpośrednio przed cyfrą. Znacznik zastępczy wybierany jest spoza wejścia i
odtwarzany po `strip_tags()`. Granice `<br>`, `<p>` i `<li>` nadal dzielą
wiersze; prawdziwy markup jest usuwany, a limit dwóch dekodowań zostaje.
Odczyt pozostaje lokalny, bez pobierania dodatkowych adresów i bez renderowania
HTML ze źródła. Wynik importu nadal jest prywatnym szkicem (D-300).

Test publicznego parsera porównuje całe instrukcje po jednej i dwóch
warstwach kodowania, a test helpera mierzy podziały, markup i granicę dwóch
warstw. Zarejestrowana kontrola ujemna wyłącza ochronę `<80` i musi oblać
test z markerem `IMPORT_2580_POROWNANIE_NIE_UCINA`. Rollback kodu przywróciłby
utratę tekstu; schemat i dane nie są zmieniane.
