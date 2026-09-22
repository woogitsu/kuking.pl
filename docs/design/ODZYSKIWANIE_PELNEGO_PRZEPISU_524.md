# Pełny tekst przepisu po 419 i 429 — #524

## Integracja i odbiór końcowego zakresu

Pierwotny worktree opisany niżej włączono do gałęzi
`fix/524-527-odzyskiwanie-formularzy`, Alfa 0.24, razem z precyzją
[podsumowania walidacji #527](PODSUMOWANIE_WALIDACJI_527.md).
Zintegrowany lokalny zestaw: **93 testy / 671 asercji, zielony** po negatywach.
Niezależny dodatkowy recenzent przeczytał diff i testy bez zgłoszenia
konkretnej regresji; jego review nie obejmowało uruchomienia testów.

Rzeczywisty lokalny Chromium i aplikacja na bazie `kuking_audit429`, port
PostgreSQL 55439: 51 instrukcji po 4000 znaków, prawdziwe HTTP 419 i 429.
Wartości wszystkich kroków porównano jeden do jednego. **48 konfiguracji**:
oba statusy, szerokości 320/360/390/414/768/1440, dwa motywy i tekst 100/140%.
Dokument mieścił się w widoku; przyciski miały co najmniej 48 px.

Dodatkowo **8 prawdziwych zoomów 200%**: oba statusy, motywy i skale tekstu.
`chrome.tabs.getZoom()` zwrócił 2, DPR 2, szerokość CSS 320 przy oknie 640.
Po zmianie zoomu oczekiwano na faktyczne zastosowanie skali; pierwszy
natychmiastowy odczyt ujawnił wyścig pomiaru i nie został uznany za wynik.
Obejrzano reprezentatywne zrzuty pierwszego i ostatniego pola oraz zoomu;
nie wszystkie kombinacje mają osobny ogląd. Zrzuty lokalne:
`output/playwright/recovery524-*.png`, przyrządy `output/browser524.js`
i `output/zoom524.mjs`.

Na pełnym formularzu 429, przy 320 px i tekście 140%, Tab odwiedził
**53/53 dostępne przystanki w każdym motywie**, z fokusem i prostokątem
kontrolki w widoku. Ten przebieg nie sprawdzał osobno zasłaniania przez
każde nakładane menu ani niedostępnego w czasie oczekiwania przycisku.
Nie przypisujemy mu klawiaturowego odbioru 419 ani zoomu.

419 wywołano natywnym POST z pustej strony i błędnym tokenem, 429 przez
istniejący lokalny limiter. To nie naturalne wygaśnięcie sesji użytkownika.
Ponowienie z CSRF i edycją sprawdzają testy HTTP poniżej; w przeglądarce
nie publikowano tego przepisu. Nie wykonywano tych prób na produkcji.

## Zakres i punkt odniesienia pierwotnego worktree

Praca lokalna od `fc7473ee936323b97f00842e424a8c3923712a48`, gałąź
`fix/524-pelny-przepis-odzyskiwanie`. Bez zmiany limitów produktu, publikacji,
wersji, schematu bazy i odzyskiwania zdjęć. Komunikaty #523 pozostają.

Wykonanie: PHP 8.4.24, PostgreSQL `127.0.0.1:55439`, osobna baza
`kuking_proof524`, UTC, `APP_URL=http://localhost`. Kopia wykonawcza
`/tmp/kuking-recovery524-exec`; `APP_BASE_PATH` wskazuje ją jawnie.
Nie uruchamiano całego zestawu PHP ani GitHub Actions.

## Dowód przed poprawką: poprawny i faktycznie zapisany przepis

POST `recipes.store`: `title=Zupa`, `visibility=public`, 51 elementów
`steps`, każdy z `instruction` równym 4000 literom `a`. Razem 204010 znaków.
Bez zdjęć i bez składników (składniki są opcjonalne).

Zwykły POST: HTTP 302, brak błędów walidacji, jeden przepis i 51 kroków;
każda instrukcja zapisana jako 4000 znaków. Rzeczywiste odbicie CSRF 419
i wyczerpanie limitera 429 zwracały po **49 kroków**. Odrzucone żądania
nie zapisywały drugiego przepisu. Odczyt starego kodu wyjaśnia wynik:
196010 znaków mieściło się, następny krok przekraczał 200000 i `break`
odrzucał oba pozostałe kroki.

Dowód lokalny: `output/proof524/proof524-result.json`,
`output/proof524/Proof524Test.php` (pomiar na starej wersji).

## Zmiana

`LimityTekstuPrzepisu::POLA` jest wspólnym źródłem istniejących maksymalnych
długości tekstów dla `RecipeController` i budżetu odzyskiwania. Żadna
wartość walidacji nie została podniesiona ani obniżona.

Tylko `recipes.store` i `recipes.update` mają większy, wyliczony budżet.
Inne trasy zachowują 200000 znaków łącznie. Limit pojedynczej wartości
200000 pozostaje także dla przepisu; najdłuższe pole istniejącego
formularza dopuszcza 120000. Nie ogranicza to poprawnych tekstów przepisu.

Wyliczenie obejmuje:

- 60 instrukcji po 4000: 240000 znaków;
- 120 składników po 240 + 120 + 300: 79200;
- tytuł, opis i pola źródła: 6300;
- obie alternatywne reprezentacje tekstowe: 30000 + 120000;
- 64 znaki rezerwy na każde z 752 pól dla ustawień, UUID i akcji.

Suma to **523628 znaków**, a nie nowa granica długości przepisu dla autora.
Obie reprezentacje liczymy razem, ponieważ kontroler akceptuje ich wspólne
przesłanie i dopiero potem wybiera reprezentację tekstową.

Ochrona jest oddzielna od tego wyliczenia:

- maksymalnie 752 odzyskiwane pola, łącznie z polami ponownego wyboru pliku;
- nazwa pola najwyżej 256 bajtów;
- leniwe spłaszczanie, najwyżej 1504 odwiedzone węzły i głębokość 8;
- zliczanie `strlen(e(wartość))`, `strlen(e(nazwa))` oraz escapowanego
  adresu akcji, z rezerwą 2048 bajtów na markup każdego pola;
- budżet tych danych i rezerw: 5836936 bajtów dla przepisu.

**5836936 nie jest zmierzonym twardym limitem całej odpowiedzi HTTP.**
To budżet danych po escapowaniu i rezerw, a layout, etykiety i przyszłe
zmiany Blade mają własny narzut. Pełny HTML mierzy test HTTP, który wymaga
wyniku poniżej 4 MiB dla maksymalnej sondy. Nie deklarujemy ochrony pamięci
przed samym parsowaniem żądania przez PHP; `post_max_size` działa wcześniej.

Nie usunięto filtrowania sekretów ani zgody po nazwie trasy. Pliki nadal
wymagają ponownego wyboru. Istniejące mapowanie zagnieżdżonych uploadów
`steps[]` nie odtwarza `steps[i][photo]`; #524 tego nie naprawia i nie
deklaruje pełnego odzyskania takich zdjęć.

## Pomiary po poprawce

`PelnyPrzepisPrzezywaOdzyskiwanieTest` najpierw zapisuje poprawne 51 kroków,
następnie wywołuje prawdziwe 419/429, odczytuje pola z formularza HTML
i wysyła odzyskaną treść. Po ponowieniu są dwa przepisy i **102 kroki**.

Osobna regresja HTTP edycji używa 51 UUID rzeczywiście zapisanych kroków.
Po odbiciu 419 i 429 porównuje wszystkie UUID oraz wartości w formularzu
z wysłanym payloadem i sprawdza, że baza nadal zawiera pierwotne kroki.
Ponowienie odczytuje `action`, `method=POST` oraz ukryte `_method=PUT`
z HTML, zamiast samodzielnie konstruować adres lub metodę. Po udanej edycji
pozostaje **jeden przepis i dokładnie 51 kroków**, w prawidłowej kolejności,
z pełnymi instrukcjami i minutnikami.

Przy ponowieniu zarówno utworzenia, jak i edycji ustawione jest środowisko
`production` w `try/finally`, aby CSRF naprawdę działał. Próba ze złym
tokenem daje 419 bez zapisu, a dokładny token odczytany z odpowiedzi pozwala
wysłać formularz. Dotychczasowa semantyka domeny nie została zmieniona:
`syncSteps()` po udanym zapisie odtwarza wiersze z nowymi UUID. Zachowanie
UUID oznacza tu ich przeniesienie w payloadzie do tej operacji, nie trwałość
identyfikatorów po wykonaniu istniejącego zapisu domenowego.

Druga sonda bada maksimum dopuszczone przez walidator, z 120 składnikami,
60 krokami, UUID, metryką i obiema reprezentacjami tekstowymi naraz.
Teksty zawierają cudzysłowy rozszerzające się do encji i polskie znaki.
Oryginalne i odzyskane wartości wszystkich **734 pól** są identyczne.

| Status | Zmierzony cały HTML |
|---|---:|
| 419 | 3189388 bajtów |
| 429 | 3189624 bajty |

To **maksimum akceptowane przez walidator**, nie dowód zapisu każdego
możliwego maksymalnego składnika. Odrębny błąd słownika opisano niżej.
Test jednostkowy obejmuje też obie nazwy tras, 727 pól danych wyprowadzonych
z limitów i porównanie całej odzyskanej struktury jeden do jednego.

Ograniczony zestaw: **51 testów, 440 asercji, zielony** (2,482 s;
81 MiB raportowane przez PHPUnit). Obejmuje nowe testy i regresje #523,
`LimitZapytanNieZjadaTekstuTest`, `TekstyMowiaPrawdeTest`,
`SekretyNieWracajaNaEkranTest`. Pint: sześć zmienionych plików PHP poprawnych.
PHPStan: trzy zmienione pliki aplikacji, brak błędów. `git diff --check`
bez uwag.

Siedem niezależnych kontroli ujemnych oblało właściwe asercje:

1. przywrócenie limitu 200000 dla przepisu;
2. usunięcie kontroli liczby pól;
3. usunięcie kontroli długości nazw;
4. zliczanie surowego, zamiast escapowanego adresu akcji;
5. usunięcie kontroli głębokości.
6. usunięcie `_method=PUT` z odzyskanego formularza edycji;
7. pominięcie UUID kroków przy odzyskiwaniu.

Po każdym negatywie przywrócono dokładne bajty i mtime pliku w kopii
wykonawczej. `output/proof524/negatives.json` zawiera MD5/mtime oraz statusy;
pliki `negative-*.txt` zawierają rzeczywiste błędy. Po negatywach zestaw
pozostał zielony; końcowe dodatkowe asercje pełnej struktury i większa
sonda mają wynik w `green-final.txt`. Rozszerzenie o edycję oraz rzeczywisty
CSRF przy ponowieniach ma końcowy wynik w
`green-with-update-after-negatives.txt`; dwie dodatkowe kontrole z zapisem
MD5/mtime są w `negatives-update.json`.

Regresja komunikatów #523 nie została wyciszona. Jej dawne 51 poprawnych
kroków jest teraz kontrolą dodatnią #524; częściowe odzyskanie wymusza
pierwszy poprawny krok i drugie pole 200001 znaków. Testy obcięcia pierwszego
pola oraz limitu 200000 na innych trasach nadal działają.

## Osobne wykrycie: tekst składnika 240, słownik 160

Reproduktor: `output/proof524/Ingredient240ProofTest.php`. Zalogowany
użytkownik wysyła zwykły POST `recipes.store`:

```php
[
    'title' => 'Zupa',
    'visibility' => 'public',
    'steps' => [['instruction' => 'Gotuj przez kilka minut.']],
    'ingredients' => [['text' => str_repeat('a', 240), 'no_amount' => '1']],
]
```

Rzeczywisty wynik: **HTTP 500, brak błędów walidacji w sesji, zero zapisanych
przepisów** (transakcja wycofana). `PublishRecipe::syncIngredients()` woła
`Ingredient::findOrCreateByName()` z całym tekstem. Wstawienie do słownika
kończy się SQLSTATE 22001: `value too long for type character varying(160)`.
Pierwotna sonda maksimum zawierająca 240 znaków miała ten sam wynik.

Ślady: `ingredient240-proof.txt` (osobny reproduktor, 1 test / 4 asercje)
oraz `max-ingredient-existing-failure.txt` (SQL i ścieżka wywołania).
To osobne [zgłoszenie #526](https://github.com/woogitsu/kuking.pl/issues/526):
nie zmieniono słownika, parsera ani schematu w #524.

## Ryzyka i cofnięcie

Odpowiedź odzyskiwania bardzo dużego przepisu jest większa niż wcześniej;
zmierzony HTML ma około 3,19 MB. To koszt zachowania już akceptowanej treści,
bez sesji/cache i bez nowego mechanizmu zapisu. Rozwój formularza musi
aktualizować wspólne granice i test maksimum. Nietypowe, nadmiarowe nazwy,
zagnieżdżenia i arbitralnie długie zapisy liczb nadal mogą zostać odrzucone
przez bezpieczniki; obietnica dotyczy pól istniejących formularzy.

Cofnięcie zmiany nie wymaga migracji, ale przywraca utratę poprawnych kroków
po 419/429. Poprawka #523 uczciwie ostrzega wtedy o utracie; jej cofnięcie
nie jest częścią rollbacku #524.

## Analiza statyczna PR #529

Pierwszy CI (34818811112) ujawnił przekroczenie limitu 1 GB przez PHPStan przy analizie budowy maksymalnego formularza w `BudzetOdzyskiwaniaTest`. Problem odtworzono na samym teście bez cache. Doprecyzowanie typu akumulatora `array<string, mixed>` zapobiega rozbudowywaniu unii kształtów; nie zmienia danych, asercji ani ustawień CI. Izolowana pełna analiza z tym samym limitem 1 GB i pięć testów (26 asercji) przeszły. Wynik kolejnego CI wymaga osobnego potwierdzenia.
