# Pełny port projektu Kuking do aplikacji

13 września 2026. **Status: scalono, zweryfikowano i wdrożono Alfa 0.9.**

Ten dokument określa zakres i kryteria odbioru. Wyniki i ograniczenia
zapisano w [raporcie Alfa 0.9](WERYFIKACJA_ALFA_09.md). Wiersze tabeli
opisują zakres, nie indywidualne testy. Wyniki Alfa 0.8 dotyczą wcześniejszej
integracji palety i nie są dowodem odbioru tego portu.

## Cel i źródło

Właściciel zlecił przeniesienie całego zaakceptowanego kierunku: wyglądu,
układu, nawigacji, typografii i stylu marki do repozytorium oraz działającej
aplikacji. Wzorzec wizualny stanowi rozwijany prototyp Kuking: pływająca rama,
ciemny kafel publikacji, jasne zaokrąglone karty i mocna typografia Inter.
Standard marki zapisuje [KONSTYTUCJA_MARKI.md](../brand/KONSTYTUCJA_MARKI.md).

Prototyp dostarcza kompozycji. Istniejąca aplikacja dostarcza tras, danych,
reguł dostępu i zachowania. Port pozostaje w Laravel, Blade, Livewire,
Alpine i obecnym systemie CSS. Produkcję publikuje dotychczasowy proces
GitHub CI → Railway.

## Mapa rodzin ekranów

W każdej rodzinie obowiązuje wspólna typografia, skala kontrolek, obrys
pól, kontrast, fokus, motywy i obsługa powiększenia. Szczegóły funkcjonalne
pozostają zgodne z aktualnym AGENTS.md oraz regułami aplikacji.

| Rodzina | Przenoszony wygląd i układ | Zachowanie wymagane przy odbiorze |
|---|---|---|
| Wspólna rama | Pływający nagłówek, szeroka oś treści, desktopowe menu w nagłówku, bez lewego paska użytkownika; pływające menu mobilne | Start, Szukaj, Dodaj, Moje, Profil; prawidłowa aktywna pozycja również podczas dodawania; dostęp do powiadomień, konta i wylogowania |
| Strona powitalna | Mocny nagłówek, spokojne sekcje, jasne karty, wyraźne zaproszenie | Istniejące treści oraz działające wejście do rejestracji i logowania; brak demonstracyjnego dowodu społecznego |
| Start i odkrywanie | Powitanie, ciemny kafel publikacji, spójne zakładki i karty; prawa kolumna tylko tam, gdzie istnieje pomocnicza treść | Chronologia i fallback pustego strumienia, widoczność wpisów, blokady, Pokaż więcej, prawdziwe informacje o autorze |
| Wpis i rozmowa | Karta z wyraźną metryką, dominującym zdjęciem i czytelnymi akcjami; formularz komentarza w tym samym rytmie | Pełna treść i jej rozwijanie, komentarze, Zapisuję, menu autora, tryb prezentacji zdjęcia i kontrola prywatności |
| Szukanie | Panel wyszukiwania, czytelne filtry, karty wyników i osób | Zapytanie, aktualna sekcja, puste wyniki i paginacja; długie nazwy autorów bez obcięcia |
| Dodaj, szkice i edycja | Duże kafle wyboru, formularze na spójnych powierzchniach, czytelne etapy | Publikacja zdjęcia i przepisu, odzyskanie szkicu, walidacja przy polu i w podsumowaniu, zachowanie wpisanych danych, widoczność treści |
| Przepis, wykonanie i gotowanie | Mocny nagłówek, zdjęcie, metadane, wyraźne akcje, czytelne składniki i kroki | Ugotowałem, Zapisuję i istniejący tryb gotowania; akcje przed składnikami na telefonie, rzeczywiste wykonania i komentarze |
| Moje i zeszyty | Karty zeszytów, spójne zakładki, czytelne listy zapisanych treści | Wybór własnego zeszytu, walidacja dostępu, widoczny błąd wyboru, zarządzanie zapisami i prywatnością |
| Profil i relacje | Ciemny nagłówek profilu, rzeczywisty awatar, nazwa i opis, jasne karty treści | Różnica własnego i cudzego profilu, edycja, obserwowanie i blokowanie, uprawnienia do treści; wyłącznie rzeczywiste liczniki |
| Powiadomienia | Czytelne wiersze lub karty, wyraźny stan nieprzeczytania | Faktyczny stan odczytu, otwarcie celu, działanie formularzy; rzeczowy tekst cudzej aktywności |
| Ustawienia i czytelność | Spójne panele, etykiety i kontrolki; układ rosnący z tekstem | Trwałość wybranego motywu i rozmiaru tekstu, ustawienia konta, prywatności oraz powiadomień bez zmiany znaczenia |
| Logowanie, rejestracja i bezpieczeństwo | Ta sama rodzina powierzchni i typografii, jednoznaczna hierarchia formularza | Istniejące mechanizmy ochrony, błędy, odzyskiwanie dostępu i potwierdzenia; brak żartu i gry nazwą |
| Pomoc, kontakt, prawo i błędy HTTP | Wspólna rama i czytelna szerokość dłuższego tekstu; spokojne komunikaty | Istniejące treści i drogi powrotu, poprawny kod HTTP, brak danych wewnętrznych; 404 działa również bez sesyjnego worka błędów |
| Moderacja i administracja | Wspólne tokeny, typografia, formularze i karty; osobna nawigacja robocza | Tryb panelu wynika z trasy i uprawnień; zwykłe konto nie otrzymuje menu panelu; potwierdzenia działań pozostają |

## Świadome różnice względem statycznego szkicu

- Kanoniczny znak to garnek, pokrywka w formie korony i uśmiech. Tymczasowa
  litera K nie wraca do aplikacji. Logotyp pozostaje KuKing.pl.
- Inter jest lokalny. Nie dokładamy szeryfu ani zewnętrznego serwisu fontów.
- Zachowujemy sprawdzaną paletę aplikacji. Odcień ze szkicu nie zastępuje
  odcienia przycisku, jeśli pogarsza kontrast jego napisu.
- Nie przenosimy fikcyjnych kont, ruchu „na żywo”, liczb gotujących, ocen
  ani zdjęć jako rzekomych wykonań. Puste stany pozostają prawdziwe.
- Nie dodajemy funkcji zapowiedzianej wyłącznie w demonstracji. Nowy wygląd
  obejmuje rzeczywiście działające zadania produktu.
- Podpisy i kontrolki pozostają zgodne z regułami 18 px / 48 px. Mniejsze
  wartości w historycznym CSS szkicu nie uzasadniają pomniejszenia aplikacji.
- Nie kopiujemy uszkodzonej mobilnej siatki. Jedna kolumna musi otrzymać
  całą dostępną szerokość, również przy powiększonym tekście.

## Kryteria odbioru

### Podobieństwo wizualne

Odbiór obejmuje zrzuty reprezentujące wszystkie rodziny ekranów z tabeli.
Na komputerze trzeba zobaczyć nową ramę i nawigację, na Start ciemny kafel
publikacji, na profilu ciemny nagłówek, a na listach wspólną geometrię kart.
Sama paleta, hasło albo numer wersji nie dowodzą przeniesienia projektu.

### Czytelność i działanie

Sprawdzenie obejmuje szerokości 320, 360, 390, 414 px i ekran komputera,
jasny oraz ciemny motyw, powiększenie aplikacji i czcionkę przeglądarki 200%.
Trzeba zmierzyć przepełnienie, wielkość tekstu i cele dotyku oraz sprawdzić
kolejność fokusu i zasłanianie przez pływające paski. Długie nazwiska,
brak zdjęcia, pusty wynik, błąd formularza i rozbudowany wpis są osobnymi
stanami odbioru, a nie odstępstwami od projektu.

Wynik skryptu oznacza zaliczenie tylko wtedy, gdy jego warunki rzeczywiście
kończą proces błędem przy naruszeniu. Poprawki regresji wymagają kontroli
ujemnych zgodnie z AGENTS.md. Testy aplikacji obejmują istniejące funkcje
na PostgreSQL; pomiar kontrastu tokenów nie zastępuje kontroli renderowania.

### Repozytorium i produkcja

Do zakończenia potrzebne są: scalony commit, wyniki wymaganych bramek CI
dla tego commita, potwierdzone wdrożenie Railway oraz kontrola wdrożonej
wersji. Publiczny smoke test nie pokazuje wyglądu strony po zalogowaniu.
Weryfikację tego widoku należy odnotować oddzielnie, z zakresem i ograniczeniami.

## Stan dowodów

Kod scalono w PR #488 jako `66980acc83ea8298b76771682e4bda96264a6484`.
CI gałęzi i main zakończyły się sukcesem. Railway potwierdził produkcję
13 września 2026 o 03:29 UTC; test dymny sprawdził również nową ramę
w serwowanym HTML oraz zbudowanym CSS.

[Raport odbioru](WERYFIKACJA_ALFA_09.md) podaje przebiegi, zakres oglądanych
zrzutów i ograniczenia, w tym sześć ostrzeżeń częściowego zasłonięcia
fokusu długiej nazwy przy powiększeniu. Nie przeprowadzono badania 50+
ani logowania na prywatne konto produkcyjne w celu odbioru wizualnego.

## Dokumentacja i wycofanie

[NOWY_STYL.md](NOWY_STYL.md) opisuje wcześniejszy etap palety;
[WERYFIKACJA_ALFA_08.md](WERYFIKACJA_ALFA_08.md) przechowuje jego wyniki.
Ten dokument rozszerza zakres do całej warstwy interfejsu. Nazwy i ton
pozostają zgodne z [COPY_STYLE.md](../brand/COPY_STYLE.md) oraz
[GLOS_MARKI.md](../brand/GLOS_MARKI.md). Numer decyzji nadaje się po scaleniu,
zgodnie z zasadą projektu.

Port wyglądu nie wymaga zmiany znaczenia danych ani migracji produkcyjnej
bazy. Plan wycofania powinien wskazać konkretny commit przywracający
poprzednie widoki i style, z ponownym zbudowaniem zasobów i wdrożeniem
przez dotychczasowy proces CI.
