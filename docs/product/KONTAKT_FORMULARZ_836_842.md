# Kontakt — pomiary i poprawki #836, #842, #838, #837, #841

Stan początkowy: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`, gałąź `gpt/kontakt-formularz`.
W katalogu zastano szkic czterech zmian. Właściciel jawnie polecił go przejąć.
Szkic odłożono do kopii, a pierwsze pomiary wykonano na kodzie z commita bazowego.
Nie przyjęto jego wyników ani założeń jako własnego pomiaru.

## Własne pomiary

Środowisko: WSL Ubuntu, PHP 8.4, PostgreSQL `127.0.0.1:55439`,
własna baza `kuking_flota_gpt-kontakt-formularz`, runtime
`/home/mateusz/flota/gpt-kontakt-formularz-run`.
Skrypty floty otrzymują `gpt-kontakt-formularz`, ponieważ podane w poleceniu
`kontakt-formularz` nie wskazuje istniejącego katalogu stanowiska.
Przed testami każdej wersji kodu wykonywano `przygotuj-runtime.sh`.

- Nietknięta implementacja: 14 dotychczasowych testów kontaktu, 66 asercji — zielone.
- #836: pięć nowych testów czerwonych przed poprawką. Mierzą HTML z Referer i old,
  sesję po walidacji, HTTP z zapisem PostgreSQL, zapis domenowy i kontrolę
  zwykłej ścieżki/obcego źródła. Po poprawce: razem 19 testów, 142 asercje — zielone.
  Dane wrażliwe są generowane wyłącznie w pamięci testu; asercje nie drukują ich wartości.
- #842/#838/#837: siedem testów czerwonych przed poprawką; kontrola identycznego
  powtórzenia zielona. Odtworzono fałszywy sukces świeżego GET, utratę danych po
  kolejnych żądaniach, kolizję dwóch kart, brak wygaśnięcia, zgubienie poprawki
  adresu oraz niewykonalną instrukcję zalogowanej osoby.
- Żądania przechodzą przez kernel Laravel i renderują Blade; to nie ogląd
  przeglądarki ani pomiar produkcji. Sesja w testach potwierdzeń używa
  rzeczywistego zapisu handlera i ciasteczka pomiędzy żądaniami.

## Zmiany i granice

### #836

Jedna funkcja `PageContext::clean()` działa przed walidacją formularza oraz
w akcji zapisującej wiadomość. Odcina parametry, fragmenty, obce domeny,
niejednoznaczne ścieżki i dynamiczne części czterech ekranów konta.
Zwykła lokalna ścieżka pozostaje użyteczna. Kodowanie procentowe nie omija
rozpoznawania ekranu. Nie zmieniono schematu ani retencji.

Nie przeglądano ani nie czyszczono historycznych danych produkcyjnych.
Osobny uprawniony przegląd powinien ustalić skalę i sposób redakcji dawnych
wartości; raport nie ma zawierać ich treści. Cofnięcie tej ochrony przywraca wyciek.

### #842

Wspólne `FormConfirmation` obsługuje kontakt i zgłoszenie nielegalnej treści.
Każde przyjęcie ma osobny klucz potwierdzenia, powiązany z tą samą sesją
i tożsamością konta/gościa. Klucz w URL nie jest identyfikatorem wiadomości
ani uprawnieniem do pobrania jej z bazy. Adres pozostaje wyłącznie w sesji.
Podgląd trwa 30 minut, sesja przechowuje najwyżej 10 potwierdzeń; odświeżanie
nie przedłuża terminu. Inna karta nie podmienia treści wcześniejszego potwierdzenia.
Odpowiedź ma `Cache-Control: private, no-store`.

Brak, wygaśnięcie lub obca sesja daje neutralny ekran, bez twierdzenia o sukcesie
czy pominięciu adresu i bez namawiania do dublowania wiadomości. Nadal dopuszczamy
prawidłowe wysłanie bez adresu. Przejścia dwóch kart zmierzono sekwencyjnie;
nie jest to test jednoczesnych zapisów sesji z dwóch procesów.

### #838

Adres gościa jest częścią tożsamości ponowienia razem z kluczem, rodzajem,
treścią i autorem. Zmiana A→B albo brak→adres korzysta z istniejącej ścieżki
nowej wiadomości bez zajętego klucza, z nowym dzwonkiem. Stary rekord pozostaje
nietknięty. Nie przyjęto aktualizacji starego rekordu z zastanego szkicu:
znajomość klucza i treści nie daje uprawnienia do jego edycji.

Identyczne ponowienie nadal daje jeden zapis i jeden dzwonek. Dla zalogowanej
osoby podstawiony adres jest ignorowany; używany jest adres konta. Kontekst
strony pozostaje diagnostyką, nie częścią treści wysłania. Kolejne zmienione
wysłanie ze starym zajętym kluczem nadal korzysta z historycznego fallbacku
bez klucza — ta poprawka nie przebudowuje całego mechanizmu idempotencji.
Dzwonki w testach są atrapami; nic nie wysłano do ludzi ani usług.

### #837

Zalogowana osoba dostaje odnośnik do ustawień adresu, informację o wymaganym
haśle i nowej skrzynce oraz alternatywę kontaktu z własnej poczty.
Gość dostaje odnośnik do formularza. Nie dodano pola adresu zalogowanemu.

## #841 — pomiar, nie wdrożenie wyszukiwarki

Reprodukcja: `testuj.sh gpt-kontakt-formularz tests/Measurements/KontaktOdnajdywaniePomiarTest.php`.
Przyrząd jest poza domyślną suitą Feature, aby obserwacje nie stały się
asercją nakazującą brak wyszukiwarki. Sprawdza poprawność fixture i HTTP,
a wyniki wypisuje zbiorczo, bez treści korespondencji.

100 wiadomości oznaczonych jako załatwione; wybrana właściwa zakładka.
Poszukiwanie zaczyna się na jej pierwszej stronie. Fragment treści leży
po 400. znaku; jeden rozpoznawalny autor ma wiadomość 76., gość — 93.

| Zadanie | Znalezione | GET listy | GET szczegółów | SQL łącznie | Czas wykonania HTTP w procesie |
|---|---:|---:|---:|---:|---:|
| fragment długiej treści | tak | 4 | 88 | 568 | 1275,3 ms |
| nazwa konta | tak | 4 | 0 | 38 | 63,3 ms |
| adres gościa | tak | 4 | 93 | 598 | 1341,9 ms |

To zmierzone koszty automatycznego przejścia po istniejących ekranach,
bez sieci, renderowania przeglądarki i czasu czytania. Nie oznaczają, że
człowiek wykona zadania w tych czasach. Wyniki zależą od rozmieszczenia
wiadomości; nie są średnią z ruchu produkcyjnego. Kod kolejki nie był zmieniany.

Nie zmierzono realnego wolumenu, częstości powrotów ani czasu pracy obsługi:
brak takich danych w zadaniu i zakaz kontaktowania się z ludźmi.

Decyzja właściciela:

1. Odłożyć wyszukiwarkę i najpierw zebrać zagregowaną liczbę powrotów do dawnych
   spraw oraz czas ich odnajdywania. Koszt: dalsze ręczne przeglądanie;
   brak dodatkowego kodu i przetwarzania prywatnych fraz.
2. Jeśli potrzeba jest częsta, zamówić jedno pole wyszukiwania w PostgreSQL.
   Koszt: walidacja, zakres treść/adres/konto, zachowanie statusu i paginacji,
   prywatność fraz w URL/logach/analityce, kontrola autoryzacji i regresje.
   Przed wdrożeniem ustalić dopasowanie pełnego adresu czy fragmentu.

Nie wdrożono wyszukiwarki, CRM ani zmian retencji. Syntetyczny pomiar potwierdza
koszt nawigacji, ale nie dowodzi częstości problemu w pracy obsługi.

## Wycofanie i przekazanie

Bez migracji. Wycofanie zmian potwierdzenia nie usuwa wiadomości z bazy;
stare potwierdzenia mogą przestać działać. Nie zaleca się wycofywania #836.
Zgłoszenie nielegalnej treści: trzy regresje również zmierzono na czerwono
przed poprawką (świeży GET, dwie karty i utrata numeru po odświeżeniu).
Numery sprawy pozostają w sesji, z osobnym zakresem formularza; nie zmieniono
potwierdzeń e-mail ani polityki obsługi zgłoszeń.

Nie wykonano push, PR, wdrożenia ani operacji na danych produkcyjnych.
Walidację końcową i SHA podaje meldunek wykonawcy.
## Dowody kontroli ujemnych

Siedem prób przez repozytoryjne `scripts/kontrola-ujemna.sh` dało kolejno
PASS → FAIL z oczekiwanego powodu → PASS. Dowody: `kontakt-dowody/*.json`.
Przyrząd zapisuje swój JSON przed końcowym trapem, dlatego jego pole
`przywrocenie` pozostaje tekstem „nie wykonane”, mimo wykonania przywrócenia.
Nie poprawiano go ręcznie. Osobny `przywrocenie.json` porównuje rzeczywiste
MD5 oraz `mtime_ns` sześciu źródeł przed całą serią i po niej; wszystkie
porównania są dodatnie. To rozróżnienie dotyczy formatu dowodu, nie wyniku testu.

Przebiegi nie dotykają źródeł innych stanowisk. Nie użyto stash, resetu,
prune ani obejścia hooków. Pomiary wyszukania #841 nie są twierdzeniem
o częstotliwości potrzeby na produkcji ani o gotowości wyszukiwarki.
## Walidacja końcowa

- `vendor/bin/pint`: 1160 plików, PASS, bez zmian formatowania.
- PHPStan dla zmienionych klas kontaktu, potwierdzeń, kontrolerów i nowych
  testów/pomiaru: bez błędów. Nie jest to deklaracja pełnej analizy repozytorium.
- Testy numerów spraw, idempotencji i kontekstu: 26 PASS, 185 asercji.
  Starsze testy odczytujące pole flash zastąpiono kontrolą rzeczywistego
  ekranu; nadal sprawdzają numer tej samej sprawy i brak duplikacji.
- `ProbaOdtworzeniaTest` świadomie wyłączony zgodnie z poleceniem właściciela:
  ten test kieruje stanowiska do jednej wspólnej bazy.
- Nie wykonano oglądu w przeglądarce, produkcji ani CI. Zmiany widoków
  zweryfikowano przez renderowanie Blade w testach HTTP.
- Pełny końcowy przebieg po poprawieniu odczytu ekranu w starszych testach:
  **4401 PASS, 83800 asercji, 342,02 s**, PostgreSQL na porcie 55439.
  Wcześniejszy przebieg wykazał trzy porażki tych testów — nie potraktowano
  ich jako zastanych, tylko poprawiono i powtórzono całą suitę.
- Commity implementacji: `3120093e3351091392ea3d11181415f75d26f9a5` (#836)
  oraz `1d2bc302245d4292904064e5bb2a58db7c97c0fb` (#842/#838/#837).
