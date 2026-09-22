# #27 — Po co planer i lista zakupów, ile kosztują i kiedy wrócić do tematu

## Po co: problem jest wiarygodny, ale osobny moduł nie jest dziś uzasadniony

**Rekomendacja: odłożyć implementację #27. Najpierw sprawdzić potrzebę na
istniejącym Zeszycie i udokumentować spełnienie bramki V1.** To rekomendacja
do decyzji właściciela, nie zamknięcie zgłoszenia ani nowa decyzja projektowa.

Konkretny problem osoby gotującej codziennie brzmi: „Mam kilka pomysłów na
obiad, chcę wybrać coś na najbliższe dni i kupić brakujące rzeczy za jednym
razem, bez ponownego szukania przepisów”. Planer miałby pomóc doprowadzić
zapisany przepis do garnka, a lista — nie zapomnieć zakupów. Samo wypełnienie
kalendarza nie jest sukcesem Kuking: sukcesem jest gotowanie, a dla wspólnoty
również podzielenie się wykonaniem. Zaplanowanie nie może automatycznie
utworzyć „Ugotowałem” ani podnieść Weekly Active Cooks.

To **hipoteza potrzeby**, nie wynik rozmów z użytkownikami. Persona Ani w
`docs/PRODUCT.md` wskazuje planer jako potrzebę późniejszą. Nie dowodzi to
jeszcze, że osoby 50+ korzystające z Kuking porzucają gotowanie przez brak
kalendarza. Nie mam pomiaru częstotliwości tego problemu ani powrotów do
planowania. Nie przyjmuję też za fakt ogólnej tezy, że planery są nieużywane:
w tym zleceniu nie badano innych serwisów.

Zeszyt już rozwiązuje pierwszą połowę problemu: pozwala zebrać wybrane
przepisy w prywatnym folderze, np. na najbliższy tydzień, i do nich wrócić.
Nie ustala dat, nie sprawdza zapasów i nie tworzy listy do sklepu. Przy
kilku daniach i zakupach zapisywanych na kartce może jednak wystarczyć.
Dodatkowy moduł ma sens dopiero, gdy właśnie te pozostałe czynności są
powtarzalną przeszkodą, a nie tylko atrakcyjną obietnicą.

Dziś koszt nowej drogi konkuruje z dopracowaniem już istniejącego zapisu,
odnajdywania przepisu i publikacji wykonania. Dwa sąsiadujące pojęcia —
„zapisać” i „zaplanować” — zwiększają liczbę decyzji przed gotowaniem.
Dlatego nie przygotowuję ekranów ani schematu wdrożeniowego V1-minimum:
warunek przejścia do tego etapu w zleceniu nie został wykazany.

## Bramka V1 i stan źródeł

Analiza z 20.09.2026 dotyczy gałęzi `gpt/planer-zakupy`, bazowego commita
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Przed pomiarem drzewo było czyste.
Nie jest to deklaracja stanu najnowszego `main` ani produkcji.

- `AGENTS.md` §12 wyłącza planer i zakupy z MVP. `docs/FEATURES.md` umieszcza
  je w V1, a `docs/ROADMAP.md` kończy się warunkiem: WAC i D30 mają pokazać
  powroty. W roadmapie nie ma liczbowego progu ani długości okresu oceny.
- [Zgłoszenie #27](https://github.com/woogitsu/kuking.pl/issues/27) jest
  otwarte. Odczytano jego treść i komentarze przez GitHub CLI.
- **[pomiar cudzy: komentarz w #27 z 09.09.2026,
  https://github.com/woogitsu/kuking.pl/issues/27#issuecomment-5602928667]**:
  autor komentarza stwierdził wówczas brak implementacji i niespełnioną
  bramkę. Nie przenoszę jego oceny retencji na 20.09.2026.
- Bieżące wartości produkcyjnych WAC/D30 są w tym zleceniu **nieustalone**.
  „Nie wykazano spełnienia” nie znaczy „zmierzono niespełnienie”.
- `docs/product/COLD_START.md` §9 podaje m.in. WAC ≥80 i D30 publikujących
  ≥25% dla bramki wzrostu 200 → 2000. To inna bramka: nie przepisuję tych
  progów jako automatycznej zgody na planer.
- Istnieje `php artisan kuking:raport` (`RaportPowrotow`). Przy przyszłym
  odbiorze trzeba podać datę, liczebność kohorty, wykluczenia i definicję
  retencji. `PowrotPoDniach` mierzy ostatnią wizytę co najmniej 30 dni po
  rejestracji, a `CookRetentionCohorts` aktywność gotujących w tygodniach.
  Żadne z nich nie jest automatycznie dokładnym powrotem w trzydziestym
  dniu ani wskaźnikiem „D30 publikujących”. Nie mieszać mianowników.

Dwie korekty założeń z opisu #27:

1. `database/reference/schema_future.sql` jest szkicem oznaczonym „Nie
   uruchamiać automatycznie”. Zawiera nagłówek `meal_plans`, ale nie pozycje
   planu ani tabele zakupów. Nie jest gotową bazą funkcji.
2. Pytanie o premium odwołuje się do dawnej hipotezy. Aktualny
   `docs/MONETIZATION.md` uznaje je za przedwczesne; przychód nie uzasadnia
   funkcji. Ten projekt nie proponuje płatności ani ograniczania Zeszytu.

## Co Zeszyt już pokrywa — odczyt i własny pomiar

| Potrzeba | Stan na badanym commicie | Granica |
|---|---|---|
| Odłożyć przepis bez organizowania folderów | Zapis do domyślnego zeszytu | Zapis nie oznacza daty ani zobowiązania do ugotowania |
| Wybrać kilka dań na najbliższe dni | Własny zeszyt z nazwą, opisem i wyborem przy zapisie przepisu/wpisu | Porządek według czasu zapisu, nie dnia gotowania |
| Zachować wybór dla siebie | Zeszyt domyślnie prywatny; własność sprawdzana przy wyborze | Można świadomie utworzyć publiczny zeszyt; to nie podstawa prywatnego planera |
| Wrócić do zapisanych rzeczy | Indeks „Moje”, widok zeszytu i ostatnie zapisy | Zapis jest odnośnikiem, nie gwarancją wiecznego dostępu do cudzej treści |
| Dopisać termin albo prywatną uwagę przy przepisie | Akcja domenowa przyjmuje `note`, relacja je czyta, eksport obsługuje notatkę | Formularz wyboru zeszytu i `saveRecipe()` nie przekazują notatki; nie ma tu dostępnego planowania terminów |
| Ugotować to samo kilka razy | Można wracać do tego samego przepisu | Unikalny zapis przepisu w danym zeszycie nie jest listą powtarzalnych zdarzeń |
| Lista do sklepu i odhaczanie | Brak tej funkcji w badanych trasach/modelach/migracjach | Nie udawać listy zakupów publicznym wpisem ani opisem folderu |

Źródła odczytu: `CollectionController`, `Collection`, `CollectionPolicy`,
`SaveRecipeToCollection`, `resources/views/components/wybor-zeszytu.blade.php`,
`resources/views/pages/collections/`, `docs/DATABASE.md` (kolekcje i składniki).
D-031 rozdziela zapis przepisu i inspiracji; D-036 utrzymuje nazwę „Zapisuję”;
D-211 zachowuje istniejące drogi i prywatność przy zmianach wyglądu.

**Własny pomiar wykonany przed utworzeniem tego dokumentu:**
`php artisan test --filter Zeszy --compact` przez skrypt floty —
**123 testy przeszły, 1174 asercje, 21,78 s**. Obejmuje m.in.
`ZapisDoWybranegoZeszytuTest` (formularz w HTML → żądanie → baza → ponowienie),
`WyborZeszytuMaWalidacjeTest`, `IdempotentnyZapisDoZeszytuTest`,
`ZawartoscZeszytuTest`, `ZeszytNiedostepneZapisyTest`,
`Visibility/ZeszytWidocznoscTest`, `ZeszytBezKluczaGlownegoTest`
i przypadek eksportu zapisanej notatki z `DataExportTest`.
To pomiar aplikacji przez testy HTTP i PostgreSQL, **nie ogląd przeglądarki
ani badanie łatwości obsługi przez człowieka**. Zielone testy nie dowodzą,
że folder tygodniowy wystarczy ludziom.

Nazwy `17-zeszyt-zapisy` i `47-zeszyt-droga` z przekazanego zlecenia nie
zostały jednoznacznie zmapowane na historyczne zgłoszenia. Nie ma ich
w przeszukanych nazwach gałęzi i komunikatach commitów. Numery GitHub #17
(„Komuś wyszło”) i #47 (macierz widoczności) oznaczają inne zadania.
Potwierdzonym powiązanym zgłoszeniem jest zamknięte
[#644](https://github.com/woogitsu/kuking.pl/issues/644), dotyczące wyboru
zeszytu. Dostępność tej drogi sprawdzono własnym przebiegiem testów,
a nie wywnioskowano wyłącznie ze statusu zgłoszenia.

## Tańsze warianty i koszt decyzji

**Poniższe liczby są własnym szacunkiem inżynierskim, nie pomiarem czasu
wykonania ani wyceną dostawcy.** Dzień oznacza 8 godzin pracy jednej osoby
znającej ten projekt, z testami, dokumentacją i odbiorem. Widełki nie
obejmują oczekiwania na badanych, kolejkę wdrożeń ani zmian zakresu.
Nie sumować wariantów — są alternatywami.

| Wariant | Przyrost danych i interfejsu | Szacunek | Co nadal pozostaje po stronie człowieka |
|---|---|---|---|
| A. Obecny prywatny Zeszyt i dotychczasowa kartka/notatka na zakupy | 0 tabel, 0 migracji, 0 nowych ekranów | 0 dni implementacji; 3–5 dni pracy badawczej rozłożone na 3 tygodnie | Wybór dnia, sprawdzenie zapasów, zapis zakupów |
| B. Zeszyt + jeden opcjonalny termin przy zapisanej rzeczy | 0 nowych tabel, 1 migracja kolumny daty i ewentualnego indeksu w `collection_items`; edycja i prezentacja na istniejących ekranach | 4–7 dni | Zakupy nadal osobno; pojedynczy termin nie obsłuży wielu wykonań tego samego przepisu |
| C. Osobny plan i prosta lista online | Około 4 tabel, 2 migracje, 2 nowe ekrany oraz zmiany wejść z przepisu i Zeszytu | 17–29 dni | Sprawdzenie ilości i zapasów, bez gwarancji użycia w sklepie bez sieci |
| D. Zakres z #27 z offline i łączeniem składników | Zakres C oraz synchronizacja, reguły jednostek, obsługa konfliktów i prywatności urządzenia | 27–49 dni | Nadal ręczna ocena zamienników, składników „do smaku” i zawartości kuchni |

A jest rekomendowanym sposobem sprawdzenia potrzeby, nie nakazem, by każdy
prowadził tygodniowe foldery. B jest alternatywą do oceny po badaniu, nie
zaakceptowanym zakresem: trzeba rozstrzygnąć prywatność daty w publicznym
zeszycie, przenoszenie terminu, usunięcie zapisu i ponowne gotowanie.
Nie wolno dopisać daty do tekstowego `note` i obiecywać poprawnego sortowania.
Istnienie kolumny notatki nie usuwa kosztu formularza, Policy, walidacji,
eksportu, testów oraz wycofania bez utraty danych.

### Skąd koszt osobnego modułu

To **inwentaryzacja kosztu odrzuconej na dziś opcji**, nie projekt do
implementacji i nie obietnica dostarczenia C jako V1-minimum.

| Składnik pracy C | Dni |
|---|---:|
| Rozstrzygnięcia produktowe, dane, migracje, ograniczenia, wycofanie | 2–4 |
| Plan: wpis własny/przepis, zmiana dnia, usuwanie, kopiowanie tygodnia, autoryzacja | 3–5 |
| Lista: pobranie składników bez automatycznego sumowania, ręczne pozycje, odhaczanie, ręczne działy sklepu | 3–5 |
| Dostępne formularze i dojścia z istniejących ekranów, zachowanie danych po błędzie | 3–5 |
| Niedostępny przepis, eksport, usunięcie konta, historia i ograniczenia danych | 2–4 |
| Testy uprawnień i regresji, odbiór 50+/320 px/200%, dokumentacja i wydanie | 4–6 |
| **Razem** | **17–29** |

D dodaje orientacyjnie **6–12 dni** na offline i synchronizację oraz
**4–8 dni** na bezpieczne łączenie składników: razem dodatkowe **10–20 dni**.
Grupowanie po działach sklepu nie wynika z `recipe_ingredients.group_name`
(„Ciasto”, „Farsz”). Normalizacja nazw w `ingredients` również nie rozstrzyga,
czy wolno sumować „cebula” i „cebula czerwona”, sztuki i gramy albo ilości
niepodane. C nie spełnia wymogu offline z #27, więc nie należy ogłaszać nim
realizacji tego zgłoszenia. Jeśli brak sieci jest głównym problemem badanych,
C odpada, nawet gdy jest tańsze.

Potencjalne tabele, gdyby właściciel później wybrał moduł: `meal_plans`
(nagłówek tygodnia i właściciel), `meal_plan_items` (datowane pozycje,
przepis albo własny wpis), `shopping_lists` (prywatna lista właściciela),
`shopping_list_items` (tekst pozycji, ilość/jednostka, dział i odhaczenie).
Dwie migracje mogą tworzyć po dwie tabele; to nie cztery gotowe pliki.
Każda wymagałaby testów, wpisu w `docs/DATABASE.md` i planu wycofania.
Nie wystarczy FK do użytkownika: konto jest również anonimizowane, więc
prywatne dane muszą wejść do istniejącej procedury usuwania i eksportu.
D-088 wyklucza ciche usunięcie dokonanych wyborów przy rollbacku.

Dotknięte miejsca: strona przepisu (dodatkowa akcja), indeks i szczegół
Zeszytu (wejście do planu), nowy plan tygodnia, nowa lista zakupów,
eksport/usuwanie danych i dokumentacja. Wejście przez istniejące „Moje”
pozwala nie dokładać szóstej pozycji nawigacji mobilnej; samo znalezienie
tego wejścia trzeba byłoby zbadać. Nie obiecujemy podmiany głównej akcji
publikowania ani „Ugotowałem” na obsługę kalendarza.

## Co ta funkcja zabiera

- **Uwagę i prostotę.** Dwa nowe ekrany i kolejne akcje na przepisie.
  Człowiek musi odróżnić „chcę zachować”, „chcę ugotować wtedy” i „ugotowane”.
  Automatyczne zakładanie drugiej kopii Zeszytu tylko zwiększa ten koszt.
- **Czas użytkownika.** Aktualizacja planu po zmianie dnia, wykreślanie
  zakupów, sprawdzenie domu przed zakupami. Kopiowanie tygodnia nie może
  oznaczać ponownego kupienia wszystkiego bez sprawdzenia.
- **Czas zespołu.** Każda późniejsza zmiana widoczności przepisu, blokad,
  eksportu, usuwania konta i nawigacji dostaje nowe przypadki do sprawdzenia.
  Rezerwa planistyczna na utrzymanie: 0,5–1,5 dnia miesięcznie dla C,
  1–3 dni dla D, przy małej skali; nie jest to zmierzony koszt hostingu.
- **Spokój powiadomień, jeżeli dojdą przypomnienia.** Nie są konieczne do
  przechowywania planu i nie wchodzą do porównania kosztowego C/D.
  Oznaczają osobną decyzję o zgodzie, godzinie, strefie, ponowieniach i ciszy.
  Sam zapis w planie nie powinien powiadamiać autora przepisu. Istniejące
  „Ugotowałem” zachowuje swoje reguły bez zmian.
- **Miejsce i zasady życia danych.** Upływ niedzieli nie jest zgodą na
  skasowanie planu. Do wyboru: historia do ręcznego usunięcia (prościej,
  ale rośnie) albo jawna retencja z możliwością zachowania/eksportu
  (więcej stanów i pracy). Nie wybieram arbitralnie liczby dni.
  Przy założeniu 1000 planujących, 52 tygodni, 7 dań i 30 pozycji zakupów
  tygodniowo historia roczna to 52 tys. planów + 364 tys. pozycji planu +
  52 tys. list + 1,56 mln pozycji list, czyli **2,028 mln wierszy**.
  To obliczenie z założeń, nie prognoza ruchu ani pomiar wielkości bazy.
- **Nowe ryzyko prywatności offline.** Lista zostaje na urządzeniu po
  zamknięciu strony; trzeba ustalić zachowanie przy wylogowaniu, zmianie
  konta i cofnięciu dostępu do przepisu. Bez tej decyzji nie rozszerzać
  service workera o prywatne dane. Współdzielenie linkiem też nie jest
  „darmowym” obejściem kont domowników — link staje się uprawnieniem.

**Osobna mechanika moderacji: nie jest potrzebna w wariancie wyłącznie
prywatnym.** Nie ma publikacji, rankingu, zgłoszeń od innych ani nowej
kolejki moderatora. Nadal potrzebne są Policy właściciela, limity rozmiaru
i liczby zapisów, bezpieczne wyświetlanie tekstu i respektowanie dostępu do
powiązanych przepisów. Prywatność nie daje prawa oglądania zablokowanego
przepisu. Udostępnianie planów/list zmieniłoby tę ocenę i wymaga osobnej decyzji.

Integracje sklepowe, współdzielenie, spiżarnia, skalowanie porcji i AI nie
są potrzebne do sprawdzenia podstawowej hipotezy. Odkładamy je bez utraty
możliwości ręcznego wybrania dań i zapisania zakupów. Automatyczne sumowanie
też może poczekać, ale tylko jeśli badani akceptują ręczne sprawdzenie listy;
nie nazywać tego rozwiązaniem problemu, którego nie rozwiązuje.

## Co wystarczy, żeby wrócić do decyzji

Propozycja badania do zatwierdzenia, **nie wykonane badanie ani kontakt
z użytkownikami**: 6–8 osób 50+ gotujących regularnie, z różnym sposobem
robienia zakupów. Najpierw rozmowa o ostatnich rzeczywistych zakupach,
potem trzy tygodnie obserwacji używania własnych metod i istniejącego Zeszytu.
Nie pytać wyłącznie „czy chcesz planer”, nie wysyłać cotygodniowego
przypomnienia wymuszającego użycie. Notować:

1. Czy i kiedy ginie decyzja „co ugotuję”, czy tylko sam zapisany przepis.
2. Czy wybór kilku przepisów w Zeszycie wystarcza; ile osób wraca do niego
   w drugim i trzecim tygodniu bez podpowiedzi prowadzącego.
3. Które wybrane dania faktycznie ugotowano; czy zakupów zabrakło przez
   brak listy, brak terminu, trudność znalezienia przepisu czy inne powody.
4. Czy osobna data i lista oszczędzają pracę względem kartki i Zeszytu,
   w tym w sklepie bez zasięgu. Makietę porównawczą robić dopiero, gdy
   ujawni się niezaspokojona potrzeba, a nie jako sugestię odpowiedzi.

Koszt 3–5 dni w A obejmuje przygotowanie i rekrutację, rozmowy/obserwacje
oraz opracowanie wyniku; czas kalendarzowy jest dłuższy. Mała próba pozwala
znaleźć przeszkody, nie oszacować retencję całej społeczności. W raporcie
zapisać liczniki i mianowniki, także rezygnacje i brak poprawy.

Powrót do projektu wymaga **obu dowodów**: zaakceptowanego raportu WAC/D30
z produkcji oraz powtarzalnej potrzeby niepokrytej prostszym rozwiązaniem.
Wynik „folder wystarcza” kończy temat bez nowego modułu; wynik „potrzebna
jest tylko data” kieruje do oceny B; dopiero problem planu i zakupów
uzasadnia projekt V1-minimum. Nie tworzyć testu kodu, który narzuca jedną
z tych decyzji właścicielowi.

## Decyzje właściciela

1. Czy przyjąć odłożenie #27 i badanie wariantu A (0 dni implementacji,
   3–5 dni pracy badawczej), czy wskazać istniejący dowód potrzeby?
2. Jakie definicje, progi, okres i minimalna liczebność danych otwierają
   bramkę V1? Czy dostępny jest aktualny raport do takiej oceny?
3. Dopiero po pozytywnym wyniku: wystarczy termin przy zapisie czy potrzebny
   jest oddzielny plan? Wariant B kosztuje 4–7 dni, C 17–29, D 27–49.
4. Jeżeli moduł wróci: czy offline jest warunkiem wydania, jak długo zostaje
   historia i jak traktować niedostępny przepis? Współdzielenie oraz
   przypomnienia wymagają osobnego zakresu i ponownego oszacowania.

Nie ma potrzeby odpowiadać na pytania 3–4, żeby przyjąć rekomendację odłożenia.

## Weryfikacja i przekazanie do kolejki

- Własny runtime: `/home/mateusz/flota/gpt-planer-zakupy-run`, kopie
  zależności przygotowane skryptem floty, bez dowiązania `vendor`.
- Baza testów: `kuking_flota_gpt-planer-zakupy`, użytkownik `kuking`,
  PostgreSQL `127.0.0.1:55439`; parametry jawnie ustawione przez `testuj.sh`.
- Powtarzalne polecenia z Git Bash na Windows:

  ```bash
  MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-planer-zakupy
  MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-planer-zakupy --filter Zeszy --compact
  ```

- Pierwsza próba z filtrem złożonym z alternatyw została błędnie rozdzielona
  przez granicę Windows/WSL (`command not found`). Nie liczę jej jako
  wyniku testów. Udany przebieg powyżej używa pojedynczego filtra `Zeszy`.
- Pint: `php vendor/bin/pint --test` w runtime — **PASS, 1155 plików**.
- Nie zmieniono kodu produkcyjnego, testów, schematu ani zależności.
  Nie powstał prototyp, nowa trasa, widok ani migracja. Nie zmieniono
  roadmapy ani dziennika decyzji — rekomendacja nie udaje decyzji właściciela.
- Nie uruchamiano pełnego zestawu testów, buildu ani przeglądarki: wynik
  zlecenia jest dokumentem, a pomiar ograniczono do istniejącej alternatywy.
  `ProbaOdtworzeniaTest` nie wchodzi w wybrany filtr; nie deklaruję jego
  zaliczenia ani własnego odtworzenia historycznej kolizji bazy.
- Nie mierzono produkcji, nie wysyłano wiadomości, nie zmieniano issue #27,
  nie wykonano push ani nie utworzono PR-a. Dokument przeznaczony do
  lokalnego commita i szeregowej kolejki publikacji.

### Blokada commita wykryta przy końcowej kontroli

Po zapisaniu dokumentu `git status` oraz `git diff --check` zakończyły się
`fatal: not a git repository: (NULL)`. Wskaźnik `.git` stanowiska wskazywał
`C:/Users/matma/Documents/Codex/kuking.pl/.git/worktrees/gpt-planer-zakupy`,
a `git rev-parse --resolve-git-dir` potwierdził, że ten cel nie jest już
katalogiem Git. Wywołanie `git rev-parse --show-toplevel --git-common-dir`
z podanej lokalizacji kanonicznej rozpoznało **inny, nadrzędny klon**
`C:/Users/matma/Documents/Codex` i `../.git`.

Na początku sesji te same polecenia w stanowisku działały: potwierdziły
`gpt/planer-zakupy`, SHA bazowe i czyste drzewo. Przyczyna późniejszej zmiany
nie została ustalona. Nie wykonywałem naprawy, reinicjalizacji ani commita
w nadrzędnym klonie. **Nie powstał commit — nie ma końcowego SHA do przekazania.**
Potrzebne jest przywrócenie metadanych stanowiska lub wskazanie nowego
położenia właściwego repozytorium. Po odzyskaniu sprawdzić gałąź i różnice,
dodać wyłącznie ten dokument i wykonać lokalny commit:
„Oceń zasadność i koszt planera oraz listy zakupów”.
