# Mąka gryczana nie jest kaszą w szacunku kosztu (#2605)

## Zakres

`kasza_gryczana` w `database/data/ceny_skladnikow.csv` miała samotne wzorce `gryczana` i `gryczanej`. Dopasowanie pierwszego pasującego wiersza mogło przez to wycenić „500 g mąki gryczanej” ceną kaszy gryczanej prażonej. Usunięto tylko te dwa wzorce. Pełne nazwy i odmiany kaszy pozostają; cenę mąki gryczanej pozostawiono nieznaną. Nie zmieniono ceny kaszy, progu pokrycia, źródeł ani pierwszeństwa kwoty autora (D-286).

Test ładuje **cały rzeczywisty CSV** komendą `kuking:ceny-skladnikow`, sprawdza wybrany klucz i pełne liczenie kosztu. Kontrola ujemna fizycznie przywraca oba samotne wzorce, wymaga czerwonych asercji z markerami `KOSZT_2605_*`, a potem przywraca plik. Odróżnia mąkę gryczaną od poprawnych nazw kaszy, mąki pszennej i mąki ryżowej.

## Wdrożenie i wycofanie

Zmiana samego pliku w repozytorium nie aktualizuje tabeli już działającej aplikacji. Po zielonym wydaniu osoba prowadząca powinna najpierw uruchomić `php artisan kuking:ceny-skladnikow --sprawdz` na wdrożonym pliku, a następnie w kontrolowanym oknie wczytać go komendą `php artisan kuking:ceny-skladnikow`. To wczytanie zastępuje cały cennik w jednej transakcji; wymaga sprawdzenia aktualnej wersji pliku i zasad wdrożenia przed uruchomieniem. Ten PR nie uruchamia komendy na produkcji.

Wycofanie poprawki oznacza przywrócenie poprzedniego wiersza CSV, przegląd pełnej różnicy i ponowne kontrolowane wczytanie cennika. Wróci wtedy opisane błędne dopasowanie; sam rollback aplikacji bez ponownego importu nie zmienia tabeli cen.
