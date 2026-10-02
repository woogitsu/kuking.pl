# Budżet pamięci podglądu własnej paczki (#2611)

Podgląd nadal przyjmuje `dane.json` do 12 MiB i najwyżej 5000 pozycji w
każdej z sekcji przepisów, wpisów i zeszytów. Rozmiar pliku nie wyznacza jednak
kosztu `json_decode(..., true)`: 800 000 małych obiektów w nieznanym polu
`extra` zajmuje 6 400 106 bajtów, a stary parser w PHP 8.4.24 z
`memory_limit=256M` kończył się fatalem (exit 255) przed sprawdzeniem sekcji.

Przed dekodowaniem czytnik liczy poza napisami JSON osobno nawiasy otwierające
kontenery (limit 150 000) i przecinki (limit 1 000 000). Wiele pól w jednym
przepisie kosztuje mniej pamięci niż tyle samo osobnych tablic; wspólny limit
obu rodzajów znaczników niepotrzebnie odrzucałby legalny eksport. Skaner nie
tworzy drugiej listy tokenów. Ukośnik pomija następujący po nim znak w
napisie, więc nawiasy, przecinki i escapowane cudzysłowy w treści nie zużywają
budżetu. Błędną składnię i głębokość nadal rozstrzyga `json_decode`.
Po przekroczeniu jednego z progów podgląd odmawia z instrukcją po polsku,
zanim powstanie duża tablica. To budżet całego dokumentu, także nieznanych pól.
Paczka nie trafia wtedy do prywatnej poczekalni; import niczego nie zapisuje.

Progi wynikają z pomiaru **osobnych procesów PHP 256M**, a nie mnożnika bajtów:
149 994 małych obiektów (ostatnia dozwolona granica kontenerów w próbie)
dekoduje się ze szczytem 71 MiB; 150 000 obiektów z ośmioma polami miało
szczyt 113 MiB, a obiekt z milionem różnych kluczy — 94 MiB. Wykonywana
kontrola dodatnia 100 000 obiektów z ośmioma kluczami każdy ma 5 000 106
bajtów i przechodzi przy szczycie 72 MiB. 800 000 małych
obiektów kończyło się fatalem; teraz jest odrzucane przed dekodowaniem przy
szczycie 18 MiB. Poprawna paczka 3500 wpisów o treści 3300 znaków każdy ma
11 651 595 bajtów i przechodzi z wynikiem 31 MiB. **Gęsty eksport z 5000
przepisów**, każdy z pięcioma składnikami i trzema krokami oraz pozostałymi
polami rzeczywistego eksportera, zajmuje 8 241 765 bajtów, 65 005 kontenerów
i 405 003 separatory; przechodzi przy szczycie 56 MiB. Drugi wykonywany
eksport z 1000 przepisów po 40 składników i 20 kroków ma 7 753 765 bajtów,
65 005 kontenerów i 429 003 separatory; przechodzi przy szczycie 56 MiB.
Maksima formularza to
120 składników i 60 kroków na przepis;
łącznie z sufitem 12 MiB ograniczają liczbę tak rozbudowanych receptur.
Próby w `tests/Support/paczka-budzet-2611.php` mierzą sam parser; test HTTP i
pełny podgląd z bazą weryfikują resztę drogi w CI. Te liczby nie są pomiarem
zużycia produkcyjnego procesu Laravel i nie uzasadniają podniesienia limitu
pamięci ani utożsamienia liczby bajtów z jej zużyciem.

Rollback kodu: przywrócenie wcześniejszego parsera odtwarza opisany fatal;
nie ma migracji danych. W razie awarii należy zostawić ochronę i skorygować
budżet po nowym pomiarze syntetycznych paczek w 256M, nie wyłączać jej.
