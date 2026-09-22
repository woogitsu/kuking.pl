# Ciche granice wyszukiwania — #885 i #886

Stanowisko: `gpt-wyszukiwanie-granice`, gałąź `gpt/wyszukiwanie-granice`.
Punkt odniesienia: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, czyste drzewo przed pomiarem.
Data: 20 września 2026.

## Pomiary własne przed zmianą kodu aplikacji

WSL Ubuntu, PostgreSQL `127.0.0.1:55439`, użytkownik `kuking`, osobna baza
`kuking_flota_gpt-wyszukiwanie-granice`. Sonda sprawdza host, port i nazwę
przed uruchomieniem żądań. Dane syntetyczne, bez odczytu produkcji.

- Istniejący `SearchTest`: **12 testów, 34 asercje, zielony**.
- Sonda `sondy/GraniceWyszukiwaniaPomiarTest.php`: żądania przez testowy kernel
  HTTP Laravela, wyrenderowany HTML, rzeczywiste zapytania PostgreSQL
  przechwycone przez `DB::listen`. Nie jest pomiarem przeglądarki.
- Fixture aktywnego profilu: `username=basia`, `display_name=Basia`,
  `speciality=zupy`. Fraza `basia` zwraca ten profil; `@basia` i ` @basia `
  zwracają pustą kolekcję. Wiązania faktycznie wykonanego `LIKE`:
  `%basia%` wobec `%@basia%`.
- Długości 119, 120, 121 i 150, osobno dla ASCII i polskiego `ż`:

| Wpisane znaki | Znaki w polu HTML | Znaki frazy w SQL: przepisy | Znaki frazy w SQL: ludzie |
|---|---|---|---|
| 119 | 119 | 119 | 119 |
| 120 | 120 | 120 | 120 |
| 121 | 121 | 120 | 120 |
| 150 | 150 | 120 | 120 |

- Dodatkowy przepis z opisem zawierającym 120 znaków `ż` wraca po zapytaniu
  o te 120 znaków plus `XYZ`, mimo że całej frazy w opisie nie ma. To
  pomiar faktycznej kolekcji wyników, nie samego echa frazy w formularzu.
- `information_schema.columns`: sześć kolumn `*_search` ma typ `text`,
  `character_maximum_length = NULL`.
- Bezpośrednie SQL: normalizacja 500 znaków zwraca długość 500,
  `similarity` dwóch identycznych 500-znakowych tekstów zwraca 1.
  To dowód obsługi długości, **nie benchmark wydajności produkcyjnej**.

## Skąd 120

Odczyt własny: `SearchQuery::normalize()` wywołuje
`mb_substr($phrase, 0, 120)` przed transliteracją i zamianą wielkości liter.
Komentarz wskazuje ochronę przed całymi akapitami i kosztem `similarity()`.
Obie metody (`recipes`, `people`) korzystają z tej samej normalizacji.
Kontroler i widok zachowują pełną frazę.

Historia własna: `git log -S 'mb_substr($phrase, 0, 120)'` wskazuje początkowy
commit aplikacji `9d7717c4`. Nie znalazłem w przeczytanej dokumentacji
wyszukiwarki ani decyzjach pomiaru uzasadniającego **dokładnie 120**.
Migracja `2026_09_09_100000_materialize_search_columns` tworzy kolumny `text`
i indeksy GIN, bez tej granicy. Liczba jest ochroną aplikacyjną, nie
ograniczeniem typu kolumny ani zadeklarowanym ograniczeniem indeksu.

[pomiar cudzy: treść #885 i #886] Zgłoszenia zawierały sondy prywatnej
normalizacji przez Reflection, bez PostgreSQL i HTTP. Wnioski powyżej
potwierdzono samodzielnie na bazie i odpowiedziach HTTP; nie przejęto ich
jako własnych pomiarów. Historycznych czasów zapytań z komentarzy kodu
nie powtarzano i nie użyto do wyznaczenia nowego limitu.

## #885 — warianty przedstawione właścicielowi

| Wariant | Korzyść | Koszt i wymagania |
|---|---|---|
| Odrzuć frazę ponad 120 znaków, zachowaj pełny tekst | Wyniki zawsze odpowiadają całej zaakceptowanej frazie; zachowana ochrona bazy | Użytkownik musi skrócić tekst. Komunikat przy polu i w podsumowaniu, obsługa GET bez pętli przekierowań, spójność obu gałęzi, onboardingu i przyszłego #815 |
| Szukaj pierwszych 120 i jawnie pokaż użyty fragment | Wyniki pojawiają się od razu | Ucięcie może zmienić sens; komunikat musi jasno oddzielić tekst wpisany od przeszukanego, także po paginacji. Nie wolno cytować całości jako wykonanej frazy |
| Podnieś limit | Więcej tekstów przejdzie bez korekty | Najpierw benchmark na reprezentatywnej liczbie profili/przepisów i różnych frazach; trzeba wybrać nową granicę oraz zachowanie po jej przekroczeniu. Samo podniesienie tylko przesuwa problem |

Decyzja właściciela w tej sesji: pierwszy wariant — „Tak, zachowaj tekst i poproś o skrócenie”. Nie wymaga migracji ani nowej biblioteki.
Sonda długości jest poza domyślnym zestawem testów i **nie zawiera asercji
nakazującej obcinanie, odrzucanie ani konkretną nową granicę**.

## #886 — wybór właściciela

Wspólny odbiór obu wariantów: pełne `@basia` skopiowane z profilu ma znaleźć
aktywną Basię, z zachowaniem blokad i ograniczeń kont, w wyszukiwarce oraz
onboardingu. Regresja sprawdza identyfikator osoby i checkbox obserwowania,
nie obecność frazy w HTML.

| Wariant | Koszt |
|---|---|
| Pojedyncze początkowe `@` jako zapis dotychczasowej frazy | Najmniejsza zmiana; nadal mogą pasować fragmenty loginu, imię i specjalność |
| `@` jako dokładny login | Jednoznaczne trafienie, ale osobny tryb wyszukiwania; trzeba opisać, że fragment, imię i specjalność z prefiksem nie działają |

Decyzja właściciela w tej sesji: „Takie same wyniki jak dla «basia» bez @ — najmniejsza zmiana”. Zachowujemy dotychczasowe dopasowanie trzech pól i kolejność; pomijamy tylko pojedyncze początkowe `@`. `@@` i znak wewnątrz frazy pozostają dosłowne. Próg minimum dwóch znaków odnosi się do nazwy bez prefiksu w zakładce „Ludzie” i onboardingu.

Pierwszy czerwony przebieg przed zmianą: **2 testy, 9 asercji, obie porażki** na
`Skopiowana nazwa nie odnalazła profilu` — oczekiwany identyfikator, otrzymana
pusta kolekcja. Zwykłe `basia` przechodzi w tym samym teście jako kontrola.

## Ograniczenia

Nie zmieniano obsługi `%`, `_` ani typu `q` (#753/#738).
Nie wykonywano push, PR ani zmian produkcji.
Nie badano obciążenia na skali produkcyjnej ani częstości długich zapytań.
## Wdrożenie i weryfikacja

- Wspólny limit: `SearchQuery::MAX_PHRASE_LENGTH`, 120 znaków przed
  transliteracją, po przycięciu skrajnych spacji. Także prefiks `@` liczy
  się do maksymalnej długości wpisanego tekstu.
- Formularze GET renderują błąd w odpowiedzi 200, bez przekierowania i bez
  wyszukiwania fragmentu. Cały tekst pozostaje w polu, komunikat jest przy
  nim i w podsumowaniu (`aria-invalid`, `aria-describedby`, link `#f-q`).
  Odrzucone wyszukiwanie nie zapisuje sygnału `search_performed`.
- Bezpośrednie metody domenowe zgłaszają `ValidationException` z kluczem
  `q`, przed pierwszym zapytaniem. To ochrona również dla przyszłego #815;
  eksperyment AI nie jest implementowany w tej zmianie.
- Nowe regresje: `DlugoscFrazyWyszukiwaniaTest` oraz
  `PrefiksNazwyWWyszukiwaniuTest`. Sprawdzają identyfikatory wyników,
  faktyczne SQL, pole właściwego formularza, błąd i drogę do pola.
  W onboardingu jest też inne pole `q` w nawigacji: pomiar błędu jest
  świadomie ograniczony do `#f-q`, nie pierwszego inputu o nazwie `q`.
- Czerwień #885 przed zmianą: brak błędu przy polu na `/szukaj` i przyjęcie
  długiej frazy przez domenę. Czerwień #886: brak identyfikatora Basi.
- Trzy kontrole przez `scripts/kontrola-ujemna.sh`: wyłączenie usuwania
  prefiksu, zmiana limitu 120 → 1000, usunięcie obu walidacji domenowych.
  Wszystkie: **PASS → FAIL z oczekiwanej przyczyny → PASS**. Przywrócenie
  MD5 i mtime sprawdzone przez przyrząd. Dwie metody domenowe mają osobne
  przypadki testowe, więc pierwsza porażka nie zasłania drugiej.
- Przebieg przed poprawkami: **4393 testy, 83692 asercje, zielony** (506,55 s).
  Pominięto `ProbaOdtworzeniaTest`, zgodnie z jawnym wyjątkiem zadania:
  używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Nowe czerwone testy
  prefiksu nie należały do tego przebiegu bazowego.
- `vendor/bin/pint`: 1158 plików, formatowanie poprawione wyłącznie w trzech
  nowych plikach pomiaru/testów. Późniejszy `--test` na pięciu zmienionych
  plikach PHP: zielony.
- `npm run build`: zielony, łącznie z kontrolą kontrastu i testami JS
  uruchamianymi przez skrypt.
- Końcowy pełny przebieg na izolowanym PostgreSQL: **4423 testy,
  84005 asercji, zielony** (424,16 s). Jedyny pominięty test:
  `ProbaOdtworzeniaTest`, z przyczyny opisanej powyżej.
- Własny ogląd lokalnego Chromium: wysłanie 121 znaków `ż` zachowuje
  wszystkie znaki; link błędu przenosi fokus do `f-q`. Przy szerokości
  320 px szerokość dokumentu wynosi 320 px, pole ma tekst 20 px,
  komunikat 18 px, przycisk wysokość 50,5 px. Po zmianie na `basia`
  i ponownym wysłaniu błąd znika, pojawia się zwykły ekran wyników.
  Sprawdzono też powiększenie CSS 200% przy szerokości 640 px: brak
  przewijania poziomego, komunikat pozostaje czytelny. To emulacja CSS,
  nie pomiar natywnego powiększenia przeglądarki. Onboarding i trafienia
  osób potwierdzają testy HTTP; nie oglądano ich na produkcji.

## Wycofanie i przekazanie

Nie zmieniamy schematu ani danych trwałych; migracja nie jest potrzebna.
Wycofanie: odwrócenie commita aplikacji razem z jego regresjami i opisem
kontraktu (przywróci również stare, ciche ograniczenia). Dla kolejki push:
pliki `SearchQuery.php` i `SearchController.php` mogą wymagać ręcznego
połączenia z gałęzią `wyszukiwarka`; pozostaw jej zmiany `%`, `_` i typu `q`.
Po połączeniu uruchom ponownie testy wyszukiwania na izolowanej bazie.
Obie decyzje produktowe zostały podjęte w tej sesji; nie pozostał wybór
limitu ani semantyki prefiksu do rozstrzygnięcia.
