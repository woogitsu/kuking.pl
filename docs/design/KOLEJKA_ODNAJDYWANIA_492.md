# Kolejka odnajdywania i zapisywania #492

14.09.2026, odczyt dokumentacji i przyrządów. Bez nowych testów, przeglądarki i zmian aplikacji. Brak pomiaru nie jest potwierdzoną usterką.

## Dowody już wykonane — nie powtarzać

- **#515 / PR521:** `POLECANE_TAGI_515.md` —192 konfiguracje pełnej/pustej listy promowanych tagów, gość/konto, oba motywy, szerokości i skale. Tab przy320/140 i rzeczywiste wejście na tag;16 prawdziwych zoomów dotyczy wyszukiwarki. To stan bez frazy, nie wszystkie wyniki/filtry. Są34 testy PHP/211asercji, w tym SearchTest i SzukajWidocznoscTest. Nie ma podstaw do ponownego audytu samych kafli.
- **#517 / #511:** `ZESZYTY_MARKI_511.md` —96 konfiguracji indeksu i szczegółu z fotografią/brakiem fotografii/długą nazwą; osobny zoom, klik zdjęcia, pięć negatywów CSS.41testów/241asercji obejmuje prywatność, odrębne paginatory i zapytania, ale nie wizualny odbiór strony2.
- **Dodatkowe stany511:** `ODBIOR_STANOW_ZESZYTU_511.md` —20 wariantów: pusty indeks, otwarty formularz, prawdziwy błąd nazwy, pusty szczegół, tylko wpisy. Zachowany opis, zero utworzonych zeszytów po błędzie, reprezentatywne obrazy.22 naciśnięcia Tab to ślad, nie kompletny oracle. Brak zoomu tych stanów oraz poprawnego utworzenia zeszytu.
- **PR512:** właściwy raport to `KOMPOZYCJE_MARKI_509.md`, uzupełnienie od sekcji PR512. Dotyczy kompozycji wejścia/przepisu/profilu; nie dowodzi osobnego oglądu pustych i wielostronicowych list obserwowanych/obserwujących.
- **PR519 / #513:** `ZAINTERESOWANIA_POWIADOMIENIA_513.md`, nie raport filtrów wyszukiwarki. Dodatkowe osiem pustych stron i cztery przejścia paginacji mają HTTP/treść/overflow, nie pełne skale i Tab (`MACIERZ_KOMPLETNOSCI_517.md`, aktualizacja513). Nie przenosić tego dowodu na paginację zeszytów.

## Kolejność najmniejszych pakietów

1. **Zapisz i odnajdź jeden istniejący przepis.** Użyć izolowanego konta/istniejącego przepisu: zapis → odejście na Start → Zeszyt → otwarcie właściwego przepisu → cofnięcie własnego zapisu. Dołączyć poprawne utworzenie jednego prywatnego zeszytu i ponowny odczyt. Błąd formularza już ma dowód, nie odtwarzać całych20stanów. Zachować snapshot i przywrócić wyłącznie własne zmiany. Nowy dowód: rzeczywisty sukces, odnalezienie i potwierdzenie zapisu; wąski zoom/pełny Tab dla tej drogi. Przyrząd `scripts/zeszyty-marki.mjs`, fixture `scripts/fixtures/zeszyty-marki.php` są wzorcem, nie zgodą na reset istniejących danych.

2. **Jedna wyszukana fraza, brak wyników i jedna zmiana dostępnego filtra.** Zacząć od konkretnego przygotowanego przepisu; sprawdzić frazę → otwarcie poprawnego wyniku → powrót → filtr/sekcja rzeczywiście występujące w widoku → brak wyników → usunięcie ograniczenia. Nie wymyślać nowych filtrów. Ogląd320 z dużym tekstem i prawdziwym zoomem, oba motywy; desktop kontrolny. Najpierw odczytać `SearchController`, `pages/search.blade.php`, SearchTest; nie powtarzać192wariantów promowanych tagów z `tagi-marki.mjs`.

3. **Druga strona jednego mieszanego zeszytu i niedostępny zapis.** Minimalny kontrolowany fixture ponad faktyczny limit jednej listy. Sprawdzić zachowanie drugiego niezależnego paginatora, wejście/powrót i brak utraty filtra; osobno widoczny komunikat niedostępnego zapisu bez ujawnienia prywatnej treści. PHP już pokrywa kontrakty; nowy zakres to rzeczywista nawigacja, komunikat i fokus. Publiczna cudza kolekcja pozostaje odrębnym stanem uprawnień — nie zaliczać do niej własnej prywatnej.

4. **Listy obserwowanych/obserwujących: pusta i druga strona.** Dwa powiązane widoki, minimalny fixture z rzeczywistymi relacjami. Otworzyć z profilu, przejść paginację i profil osoby, wrócić; zweryfikować pusty stan osobno. Obecna macierz ma historyczny L014, ale brak wskazanego aktualnego oglądu/zoomu. Nie ponawiać kompozycji całego profilu z512.

## Granice i sposób raportowania

Macierz nadal wymienia nieobejrzane kombinacje filtrów, stronę2, ograniczoną widoczność i sukces utworzenia zeszytu. Pakiety wyżej zamykają konkretne ścieżki, nie wszystkie kombinacje. Rozdzielić zapis danych, HTTP, geometrię, pełny oracle Tab/radiów i obejrzane rastry. Prawdziwy zoom nie jest fontem32px. Nie nazywać istniejących testów nowo wykonanymi. Przed wznowieniem sprawdzić aktualne źródła, stan fixture i trwające procesy; nie uruchamiać setupu w bazie używanej przez inne zadanie.

Aktualne statusy GitHub/produkcji ustala root; ten odczyt nie potwierdza ich ponownie. Powiązania512/517/519/521 ustalono z aktualnych raportów, nie z numeru nazwy pliku. Lista jest dodatkiem do #492, nie nowym szerokim audytem.
