# Liczniki i cele odnośników — weryfikacja #666 i #667

Data: 20 września 2026. Stan wejściowy: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
czyste drzewo gałęzi `gpt/przepis-liczniki`. Praca lokalna, bez publikacji.

## Wynik rozpoznania

Obie pierwotne poprawki **są już w badanym drzewie**:

- `bdc56b8cf9b664eda104b628d85149b08d84d8d9` — jednostki wykonań i odpowiedzi (#671);
- `71549ebad6eab2d41c71073c2f93aed85419e69d` — nazwy i cele odnośników (#672).

Samodzielnie sprawdzono obecność obu commitów w historii, odczytano bieżące
zgłoszenia przez `gh issue view` i uruchomiono testy na źródłach wejściowych.
Otwarte issue nie oznacza tu brakującej implementacji.

[pomiar cudzy: komentarze właściciela w #666 i #667 oraz
LICZNIKI_WYKONAN_666.md i CELE_LINKOW_667.md] Wcześniejsze wdrożenia i odbiory
produkcyjne opisują działające poprawki. Pozostały reprezentatywne rzeczywiste
dane niezerowych liczników oraz zalogowany odbiór pustych zeszytów. W tej sesji
nie sprawdzano produkcji ani nie tworzono na niej danych.

## Co naprawdę jest liczone

| Miejsce | Źródło liczby i podpis | Weryfikacja w tej sesji |
|---|---|---|
| Strona przepisu, znaczek i galeria | `paginate()->total()` po `widoczneDla(widz)`; „Ugotowane N ×” oraz odmienione „N wykonań” | Render Laravel, wielokrotne wykonania tej samej osoby, 0/1/2/5/12/22, paginacja i blokady |
| Odpowiedzi przy przepisie | Zdarzenia z niepustym `would_make_again`, osobno odpowiedzi `true`; nie unikalne osoby | 4 zdarzenia 2 osób, sprzeczne odpowiedzi: 2 z 4, 50%; brak odpowiedzi nie podnosi progu 3 |
| Dane strukturalne przepisu | `userInteractionCount` z tej samej liczby co galeria | Test renderu i prywatności |
| Karta wyniku wyszukiwania | `withCount(cookedEvents => widoczneDla(widz))`, „Ugotowane N ×” | Test zgodności z widokiem przepisu i filtrami widza |
| Karty na profilu i w zeszycie | Bez załadowanego `cooked_events_count` znaczek jest pomijany, nie pokazuje zmyślonego zera | Odczyt kontrolerów i `recipe-card.blade.php` |
| Licznik profilu | Zdarzenia właściciela z widocznych dla widza przepisów, „N razy „Ugotowałem”” | Testy zgodności z listą i odmiany podpisów |
| Powiadomienie | Pojedyncze zdarzenie, „imię — ugotowane z Twojego przepisu”; brak zbiorczego licznika osób | Odczyt widoku i test dwóch wykonań tej samej osoby → dwa powiadomienia |
| Indeks HTML eksportu | Liczba własnych zapisanych zdarzeń z `ugotowalem`, „N zapisanych wykonań” | Odczyt generatora i widoku; wykonana rodzina `DataExportTest` |
| Przepis w JSON eksportu | Wszystkie zdarzenia pod przepisem, bez filtrów galerii; dotąd błędny klucz `ile_razy_ugotowany_przez_innych` | Osobne odtworzenie i regresja opisane niżej |

Profil, galeria i eksport mają różne zakresy danych. Ich liczby nie muszą być
równe; podpis musi jasno nazywać zakres i jednostkę. Eksport własnych treści
obejmuje także treści niewidoczne w publicznych listach.

## Warianty produktu i koszt

1. **Zdarzenia („12 wykonań”).** Pokazują powroty do przepisu. Strona już
   potrzebuje tej liczby do paginacji; odczyt `total()` nie dodaje zapytania.
   Każda odpowiedź przy kolejnym gotowaniu zachowuje własne znaczenie.
2. **Osoby („8 osób”).** Pokazują zasięg wśród różnych kucharzy.
   `count(distinct user_id)` wymaga deduplikacji przy tych samych filtrach
   dostępu. Nie zastępuje liczby zdarzeń potrzebnej galerii. Najprostsza
   implementacja dodaje agregację; trzeba również wybrać regułę dla
   sprzecznych odpowiedzi jednej osoby.
3. **Obie liczby.** Więcej informacji, ale dłuższy podpis i dodatkowa agregacja
   osób. Bez decyzji właściciela nie dokładamy drugiej statystyki do interfejsu.

Zakres #666 już rozstrzyga zachowanie zdarzeń i odpowiedzi; potwierdza to
komentarz właściciela w issue. Nie zmieniamy tej decyzji na `DISTINCT`.

### Samodzielny pomiar PostgreSQL

Własna baza `kuking_flota_gpt-przepis-liczniki`, właściciel `kuking`,
`127.0.0.1:55439` — parametry sprawdzone przed pomiarem. Syntetyczne zdarzenia
jednego przepisu, 8 aktywnych kucharzy, widz zalogowany, zakres modelu
`CookedEvent::where('recipe_id', ...)->widoczneDla(widz)`. Dla tego samego
zbioru porównano `count(*)` i `count(distinct cooked_events.user_id)`.
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`: 2 rozgrzewki i mediana kolejnych
5 wykonań, bez kosztu połączenia i renderu. Dane wycofano transakcją.

| Zdarzenia | Unikalne osoby | `count(*)`, ms | `count(distinct ...)`, ms |
|---|---|---|---|
| 12 | 8 | 0,044 | 0,050 |
| 1000 | 8 | 2,046 | 1,994 |
| 10000 | 8 | 13,483 | 11,465 |

Odczyty buforów współdzielonych były takie same dla obu wariantów:
38 / 3014 / 20135; zapisu plików tymczasowych nie było. Przy 10000 zdarzeń
zakresy próbek wyniosły 12,574–16,460 ms i 10,003–13,618 ms.
To mała próbka na współdzielonym hoście, z 8 powtarzającymi się kucharzami,
a nie prognoza wydajności produkcji. Nie wykazała spowolnienia `DISTINCT`.
Koszt dodatkowego zapytania o osoby pozostaje realny, bo liczba zdarzeń jest
już potrzebna paginatorowi. Wybór jednostki opiera się na znaczeniu produktu.

## Dodatkowa rozbieżność w eksporcie i decyzja właściciela

`CollectUserExportData` liczy wszystkie zdarzenia, lecz stary klucz mówił
„przez innych”. Przypadek kontrolny: 1 wykonanie autora, 2 wykonania jednego
aktywnego kucharza i 2 wykonania kucharza zbanowanego. To **5 zdarzeń, 3 osoby,
4 cudze zdarzenia i 3 zdarzenia widoczne w galerii**.

Samodzielnie uruchomiony zbieracz eksportu przed poprawką zwrócił
`ile_razy_ugotowany_przez_innych = 5` dla tych danych. Transakcję wycofano.

Właściciel w tej sesji wybrał: **„Wszystkie wykonania, także własne i ukryte;
nazwij ten pełny zakres wprost.”** Dlatego nowy klucz to
`ile_razy_ugotowany_lacznie`, a `o_tym_pliku.zakres_licznika_wykonan_przepisu`
wyjaśnia, że liczba obejmuje własne i cudze wykonania, także niewidoczne
w galerii, i liczy każde kolejne gotowanie osobno. Zapytanie pozostaje takie samo.

To zmiana nazwy pola JSON, bez migracji bazy. Stary, mylący klucz nie jest
utrzymywany jako alias. Programy czytające przyszłe paczki muszą uwzględnić
nową nazwę; wcześniej pobrane archiwa pozostają bez zmian. Wyszukiwanie
w źródłach nie wykazało innych konsumentów starego klucza w aplikacji.
Własne wykonania w `ugotowalem` i indeks HTML eksportu zachowują swój zakres.

## Odnośniki #667

Samodzielny render HTTP/Blade potwierdził:

- Okruszek przepisu i `BreadcrumbList` nazywają cel „Świeżo z Kuking”
  i prowadzą do `/odkryj`.
- „Poszukaj przepisów” w obu pustych stanach zeszytu prowadzi do
  `/szukaj?sekcja=przepisy`.
- Wyszukiwarka bez frazy zachowuje zakres i prosi o wpisanie zapytania;
  nie przedstawia się jako katalog wszystkich przepisów.

Okruszek i akcja szukania **nie mają już wspólnego celu**. Dwa przyciski
szukania w dwóch pustych stanach mają ten sam cel zgodnie z tą samą czynnością.
Nie powstała nowa nazwa funkcji ani katalog przepisów.

## Wykonane kontrole

Wszystkie poniższe wyniki są własnymi pomiarami na PostgreSQL
`127.0.0.1:55439`, w bazie `kuking_flota_gpt-przepis-liczniki`:

| Moment | Kontrola | Wynik |
|---|---|---|
| Nietknięty kod wejściowy | 10 rodzin liczników, odnośników, profilu, powiadomień i eksportu | 86 testów, 495 asercji, PASS |
| Nietknięty kod wejściowy | Domyślny zestaw `php artisan test`, filtr `^(?!.*ProbaOdtworzeniaTest)` | 4393 testy, 83692 asercje, PASS, 522,65 s |
| Nowy test, stary kod eksportu | `DataExportTest::test_licznik_przepisu_w_paczce_nazywa_pelny_zakres_wykonan` | FAIL, 8 asercji; wykryty klucz `ile_razy_ugotowany_przez_innych` |
| Poprawiony eksport | `DataExportTest` | 26 testów, 153 asercje, PASS |
| Stan końcowy kodu | Te same 10 rodzin oraz filtr `Teksty` | 140 testów, 795 asercji, PASS |
| Stan końcowy kodu | `vendor/bin/pint` | PASS, 1155 plików |
| Stan końcowy zmian | `git diff --check` | PASS |

Rodziny pierwszego i końcowego przebiegu: `CookedCountsUnitsTest`,
`LinkDestinationLabelsTest`, `LicznikUgotowanZgadzaSieZListaTest`,
`PrzepisyLicznikiZgadzajaSieZGaleriaTest`, `KomuWyszloUkladTest`,
`WyszukiwarkaLiczyWykonaniaTakSamoJakPrzepisTest`,
`ProfilLicznikiTresciZgadzajaSieZListamiTest`, `NaglowekProfiluOdmieniaLicznikiTest`,
`UgotowalemZawszePowiadamiaAutoraTest`, `DataExportTest`.

Pełnego zestawu nie powtarzano po wąskiej zmianie klucza i objaśnienia JSON;
końcowy przebieg objął zmieniony eksport, wszystkie wskazane rodziny oraz
reguły tekstów. `ProbaOdtworzeniaTest` pominięto zgodnie z poleceniem ze względu
na wspólną bazę próby. Grupa `dwa-polaczenia` pozostaje wyłączona domyślnie
przez konfigurację projektu; nie uruchamiano jej osobno.

Przygotowanie runtime dwukrotnie zgłosiło `rsync: Cannot allocate memory`.
Każdą taką próbę powtórzono do kodu wyjścia 0 przed uruchomieniem testów.
Roboczy próbnik PHP początkowo trafił do skanu Pint; usunięto go po pomiarze,
ponownie zsynchronizowano runtime i dopiero wtedy uzyskano końcowy PASS.

## Wycofanie i granice

Wycofanie lokalnego commita przywraca poprzedni format przyszłych paczek;
nie wymaga operacji na bazie i nie przepisuje pobranych archiwów. Przywróci
jednak również błędne określenie „przez innych”, dlatego preferowana reakcja
na problem zgodności to poprawienie konsumenta JSON.

Nie wykonano push, PR, zamknięcia issues, zmian produkcji ani nowego odbioru
przeglądarkowego. Zmiana dotyczy danych JSON, bez zmiany HTML/CSS.
Nowej decyzji produktowej o licznikach nie potrzeba; właściciel rozstrzygnął
zakres eksportu. Otwarte pozostają wcześniej opisane odbiory produkcyjne.
