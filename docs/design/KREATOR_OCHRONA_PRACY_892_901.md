# Kreator — ochrona pracy i dostęp do szkiców

Stanowisko: `gpt-kreator-przepisu`, baza gałęzi
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Pomiar własny, 20 września 2026.
PostgreSQL: `127.0.0.1:55439`, baza `kuking_flota_gpt-kreator-przepisu`.
Żadnych pomiarów ani zmian na produkcji.

## Przed poprawką

- Istniejące `RecipeWizardTest` i `AutozapisKreatoraWalidujePrzedZapisemTest`:
  41 testów, 477 asercji, wszystkie zielone na nietkniętym kodzie.
- #893: trzy czerwone testy pełnego komponentu Livewire. Po otwarciu szkicu
  usunięcie przez HTTP i następnie autozapis, zapis ręczny albo publikacja
  dawały **dwa** rekordy zamiast jednego. Kontrola tworzenia i zwykłej edycji
  przechodziła. To sekwencja dwóch żądań, nie pomiar współbieżnych transakcji.
- #897: rzeczywisty PUT odrzucony walidacją i ponowny GET gubiły tekst pod
  kluczami `3` oraz `1000000`. Ciągłe klucze zachowywały tekst kroku.
  Dodatkowo formularz nie odtwarzał uwagi do składnika nawet przy ciągłych
  kluczach; pełne listy 60 kroków i 120 składników mieściły się w limitach.
- #894: cztery czerwone próby Livewire z rzeczywistą odmową przyjęcia zdjęcia
  (kontrolowany limit wielkości). Ruch w górę, w dół i usunięcie poprzednika
  zostawiały błąd pod dawnym indeksem; usunięcie wadliwego kroku zostawiało
  jego błąd. Test czyta także wyrenderowany wiersz i odnośnik podsumowania.
- #892: Chromium po pierwszym zapisie i kolejnym wpisaniu tytułu pokazywał
  nadal „Szkic zapisany.”. Test oblał asercję `STARE_POTWIERDZENIE`.
- #901: test HTTP listy dawał 404; test przeglądarkowy nie znajdował
  odnośnika „Wszystkie szkice” na „Dodaj” dla konta z siedmioma szkicami.
- #899: w Chromium oba odnośniki z edycji otworzyły kreator z zapisanym
  tytułem „Najstarszy szkic” zamiast „Niezapisany tytuł”, bez dialogu.

Treści zgłoszeń były punktem wyjścia, a nie zastępstwem powyższych pomiarów.

## Zachowanie po zmianie

Niepuste ID kreatora pozostaje intencją edycji. Gdy przepis zniknie,
zapis odmawia i prosi o skopiowanie tekstu przed opuszczeniem formularza.
Nie powstaje zastępczy szkic ani wpis; nie przywracamy usuniętego przepisu.
Zwykła autoryzacja przy każdym zapisie pozostaje w Policy.

Klucz wiersza i jego numer prezentacyjny są rozdzielone. Formularz szczegółów
renderuje klucze z wejścia wraz z ID kroku, minutnikiem i uwagami.
Puste pola dostają pierwsze wolne klucze; duży klucz nie
powoduje tworzenia miliona kontrolek. W Livewire przestawienie listy mapuje
błędy po stabilnym `_key`, tak samo jak przenosi treść i identyfikator zdjęcia.
Usunięcie kroku usuwa wyłącznie jego błędy. Nie zmieniamy indeksowania
kontrolera przyjmującego pliki ani mechanizmu migawek i wersji.

Plakietka rozróżnia lokalną zmianę, żądanie w toku i odpowiedź serwera.
Lokalny licznik zmian porównujemy z wersją formularza obsłużoną przez serwer.
Starsza odpowiedź nie potwierdza nowego tekstu. Samo `wire:dirty` nie
wystarczyło: próba z opóźnioną odpowiedzią oblała `STARSZA_ODPOWIEDZ`.
Opóźnienie autozapisu 3 s oraz ręczny zapis zostają.

Rozszerzony pomiar ujawnił dodatkowo 404 po zmianie nazwy szkicu otwartego
przez `/przepisy/{slug}/szczegoly`: zapis zmienia slug szkicu, a następne
żądanie Livewire odtwarza wiązanie starego adresu. Dla opublikowanego przepisu
slug pozostaje stały. Wejście szkicu przez nazwę teraz po Policy przekierowuje
do istniejącego `/dodaj/przepis?szkic=UUID`. Test przekierowania najpierw
oblał (200 zamiast przekierowania); próba Chromium obejmuje tę drogę.

„Dodaj” nadal pokazuje trzy skróty i prowadzi do „Wszystkie szkice”.
Lista `/dodaj/szkice` pobiera wyłącznie szkice zalogowanego autora,
sprawdza Policy, porządkuje po aktualizacji i ID oraz stronicuje kursorem
po 20 pozycji. Dalszą stronę otwiera „Pokaż więcej”.

## #899 — decyzja właściciela, bez zmiany zachowania

| Wariant | Co otrzymuje człowiek | Koszt i ograniczenia |
|---|---|---|
| Ostrzeżenie tylko przy zmianach | Informację, że kreator otworzy zapisaną wersję; możliwość pozostania przy wpisanej treści | Najmniejsza zmiana. Trzeba śledzić pola i wybór pliku, objąć oba odnośniki, sprawdzić anulowanie oraz formularz bez zmian. Tekst nie przechodzi do kreatora. |
| Przeniesienie niezapisanych zmian | Kontynuację edycji w kreatorze z dotychczasową pracą | Większa zmiana: przeniesienie stanu przez serwer, walidacja także niepełnych danych, obsługa plików i ich czasu życia, tożsamości kroków i mediów. Nie wolno przy okazji publikować zmian już publicznego przepisu. |

Rekomendacja do decyzji: ostrzeżenie jako mała, jawna ochrona. Nie wprowadzono
go samodzielnie ani nie zapisano asercji wybierającej przyszły produkt.
Pomiar `transfer` jedynie raportuje stan, bez wymagania utrzymania usterki.

## Zakres sprawdzenia

Końcowy pełny przebieg: **4413 testów przeszło, 83 920 asercji, 496,00 s**,
kod wyjścia 0. Pominięto wyłącznie `ProbaOdtworzeniaTest`.
Powód pominięcia: **[pomiar cudzy: instrukcja właściciela w tym zadaniu]**
wspólna baza `kuking_zrodlo_proby_glowny`; sam nie odtwarzałem kolizji.
`vendor/bin/pint`: PASS, 1159 plików. `npm run build`: PASS — 20 testów JS,
72 kontrole kontrastu i budowanie Vite. Wszystkie te wyniki zmierzyłem sam.

Własne próby Chromium obejmują szkic i opublikowany przepis: okres przed
wysłaniem, wstrzymaną odpowiedź HTTP 200, dalsze pisanie podczas żądania,
potwierdzenie nowego tekstu, odświeżenie po zapisie oraz błąd walidacji.
Zmiana jeszcze niewysłana **nie przetrwa odświeżenia**; poprawka nie jest
lokalnym odzyskiwaniem formularza, tylko usuwa fałszywe potwierdzenie zapisu.
Próba kroków sprawdza również zmianę instrukcji. Osobny rzeczywisty POST
sprawdza tekst i odnośnik błędu minutnika przy kluczu `7`.

Lista szkiców: HTTP dla 0, 1, 3, 4, 7 i 21 pozycji (w tym kolejna strona,
odmowa dostępu do cudzego szkicu i przekierowanie gościa). Chromium przechodzi
od „Dodaj” do najstarszego z siedmiu szkiców; szerokości 320/360/390/414 px
bez poziomego przepełnienia, odnośnik ma co najmniej 48 px wysokości.
Nie jest to pełny audyt WCAG ani próba czytnika ekranu.

Pięć kontroli ujemnych (#893, #894, #897, #901, #892): każda ma przebieg
PASS → FAIL z oczekiwanej przyczyny → PASS. Przyrząd sprawdza zmianę MD5
oraz przywrócenie treści i mtime. Przed każdym testem czyścimy skompilowane
widoki: po odtworzeniu starego mtime Blade mógł nadal używać zmutowanego
widoku, mimo identycznych bajtów źródła. Bez tego pierwsza próba #893
nie przeszła kontroli po przywróceniu i nie została uznana za dowód.
Wyniki końcowe są w `dowody-kreatora/*.json` obok tego dokumentu.
Uwaga do formatu przyrządu: JSON powstaje przed końcowym `trap`, dlatego jego
pole `przywrocenie` nadal brzmi „nie wykonane”. Końcowe wyjście każdej próby
potwierdziło porównanie MD5 i mtime. Niezależnie po wszystkich próbach
porównałem MD5 plików roboczych z `md5_przed`: kreator
`1575aedb0452afb182c91840b500c583`, szczegóły
`370d91efc1d815a291e3b00b5bf2adea`, ekran dodawania
`47c45ec5eff214e046a671d2be4044db` — wszystkie zgodne.

Nie wykonano wdrożenia, CI, pomiarów produkcji ani współbieżnych transakcji
usunięcia/zapisu. Dla #899 zmierzono oba odnośniki oraz niezapisany tytuł
i składnik; nie wykonano pełnej macierzy plików i historii przeglądarki,
ponieważ zachowanie wymaga decyzji właściciela.

## Uruchamianie i wycofanie

Przed każdym przebiegiem odświeżyć runtime skryptem floty. Testy PHP:
`KreatorNieOdtwarzaUsunietegoPrzepisuTest`, `SzczegolyZachowujaKluczeWierszyTest`,
`BladZdjeciaPodazaZaKrokiemTest`, `WszystkieSzkiceTest` oraz dotychczasowe testy.
Skrypt `scripts/kreator-pomiar-flota.sh` uruchamia pomiary Chromium:
`autosave`, `published`, `drafts`, `indices`, `transfer`; wymaga zbudowanych
assetów w runtime. Tryb `tests` pomija wyłącznie `ProbaOdtworzeniaTest` z powodu
wspólnej bazy próby odtworzenia wskazanej przez właściciela.

Bez migracji, zmian schematu i zależności. Wycofanie: revert commitów tej
zmiany; istniejące przepisy i szkice pozostają w bazie. Wycofanie przywróci
opisane usterki, więc nie wymaga i nie uzasadnia kasowania danych.
