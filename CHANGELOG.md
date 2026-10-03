# Co się zmieniło w Kuking

Ten plik trzyma sekcję „Nieopublikowane” i kilka najnowszych wydanych wersji. Starsze wersje leżą w [archiwum](docs/changelog/) (lista na dole pliku).

Reguła na przyszłość: przy podbiciu numeru wersji, gdy ten plik bez sekcji „Nieopublikowane” przekroczy ok. 40 KB, najstarsze wydane sekcje przenosi się — bez zmian, w tej samej kolejności — do nowego pliku w `docs/changelog/` (nazwa `archiwum-alfa-<od>-<do>.md`, każdy plik do ok. 60 KB) i dopisuje link w sekcji „Starsze wersje” na dole. Nagłówki „## Alfa 0.N — …” zostają w jednym z tych plików; testy czytają CHANGELOG.md razem z archiwum.

## Nieopublikowane

- Poprawione (#2868): ekran „Usunięte przepisy” pokazuje starsze odzyskiwalne przepisy mimo nowszych pozycji objętych moderacją. „Pokaż więcej” prowadzi przez kolejne porcje bez ujawniania chronionych przepisów.
