# Własne wykonania i przepisy w paczce (#2639)

`dane.json` zachowuje dotychczasowe pola każdego `ugotowalem` (tytuł,
autor, notatkę, datę, zdjęcia i ewentualny numer wersji). Nowe pole
`plik_wlasnego_przepisu` ma ścieżkę `przepisy/*.html` tylko wtedy, gdy
wykonanie dotyczy własnego przepisu, którego plik rzeczywiście znajduje się
w tej samej paczce. Jest to dokładnie wartość `przepisy[].plik_do_czytania`,
wyliczona przez wspólny `ExportFileNames::recipeFile()`. Dwa przepisy o tym
samym tytule pozostają odróżnialne bez zgadywania po nazwie.

W pozostałych przypadkach pole ma `null`: dla cudzej receptury, usuniętej
albo niedostępnej. Nie ujawnia identyfikatora, tytułu ani adresu cudzego
niedostępnego przepisu. Oznaczenie wersji przepisu pozostaje osobnym,
historycznym faktem; brak migawki nie jest rekonstruowany.

To dopisanie opcjonalnej relacji w sekcji `ugotowalem`, której obecny podgląd
importu nie czyta. Numer formatu pozostaje 1 zgodnie z
`WersjaFormatuPaczki`: nie zmienia się znaczenie żadnego pola `przepisy`,
`wpisy` ani `kolekcje`. Podgląd importu nadal tworzy wyłącznie swoje
dotychczasowe pozycje, bez odtwarzania historii wykonań.

Test buduje prawdziwe archiwum ZIP z dwoma własnymi jednakowymi tytułami,
sprawdza dwa różne odnośniki i ich pliki w archiwum, a osobno granicę dla
cudzych i niedostępnych przepisów. Kontrola ujemna usuwa nowe pole z
eksportera; wtedy regresja oblewa markerem relacji. Cofnięcie kodu usuwa
wyłącznie nowe pole z przyszłych paczek. Pobrane już archiwa pozostają
czytelne; nie ma migracji bazy ani zmiany danych kont.
