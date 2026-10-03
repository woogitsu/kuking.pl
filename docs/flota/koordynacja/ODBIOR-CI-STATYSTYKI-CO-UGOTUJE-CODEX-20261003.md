# Odbiór higieny statystyk fixture „Co ugotuję” — Codex, 3.10.2026

## Zakres i wynik

**Dowiedzione: nieaktualne statystyki fixture. Przyczyna timeoutu CI nie jest dowiedziona.**

Korekta po niezależnym review commita `8460fd56b0278df74b13812c002ca540d7eab07c`:
usunięto dziesięć asercji wymagających dokładności estymaty reltuples.
Nie wprowadzono tolerancji, listenera SQL ani nowego strażnika. Reltuples
pozostaje diagnostyką sondy na małej kontrolowanej bazie, nie kontraktem testu.

Własna gałąź `codex/ci-statystyki-co-ugotuje-20261003`, od C
`e625ab336f04f87df7f2be7eb86fa4866e7a13a6`. Własny WT:
`C:\Users\matma\.codex\worktrees\ci-statystyki-co-ugotuje-20261003\Portale`.
Zmiana kodu obejmuje wyłącznie `tests/Feature/CoUgotujeGranicaPaginacjiTest.php`.
Zachowano wszystkie 52 wcześniejsze asercje, SQL domeny i limit 15000 ms.
Bez migracji, push, PR, ponowienia CI, produkcji i edycji frozen WT.

Świeżo przeczytano pełny body i komentarze otwartego
[#2591](https://github.com/woogitsu/kuking.pl/issues/2591): dotyczy zakresu
własnych zeszytów, nie tej korekty statystyk. Wyszukiwanie wszystkich stanów
po `CoUgotuje statystyki`, `CoUgotuje CI timeout`, nazwie klasy i
`ANALYZE fixture` nie znalazło duplikatu tej awarii ani aktywnej poprawki.
Zamknięte [#2516](https://github.com/woogitsu/kuking.pl/issues/2516) dotyczy
pomiaru innej szyny. Odczytano aktualne AGENTS, D-333, PULAPKI_TESTOW,
strażników źródeł i istniejący rejestr mutacji paginacji. Nowe asercje czytają
rzeczywisty katalog PostgreSQL, nie tekst źródeł.

## Czerwień CI i identyczne zielone źródło

Run [37124099712](https://github.com/woogitsu/kuking.pl/actions/runs/37124099712),
job [111205863496](https://github.com/woogitsu/kuking.pl/actions/runs/37124099712/job/111205863496),
„Testy (PostgreSQL 18) — część 1/4”: 3571 PASS, 1 FAIL, 81707 asercji.
Metoda `test_ostatnia_dostepna_strona_nie_odsyla_do_siebie_w_obu_trybach`
po dodaniu 10021. widocznego przepisu dostała 503 zamiast 200 przy GET
`od=10000` (stara linia 84). SQLSTATE 57014, `statement_timeout`, kontekst
`kuking_normalize`; metoda trwała 16,65 s. Nie był to proper FAIL mutanta.
Kontrole ujemne 1/3 i 2/3 w momencie diagnozy nadal pracowały.

V `4d3f5d3ba01b32482ef54da2f8390af174e7f414` i C mają identyczne drzewo
`7006987a70ae2ccccfeb42b789e63252d56689a7`.
Na V run 37121885979, job 111199505562: ta sama metoda PASS 3,90 s;
3572 PASS, 81736 asercji. Migracja rdzeni przeszła w obu jobach. Błędny job
nie podał rzeczywistego EXPLAIN ani katalogu indeksów: lokalnego planu
nie przypisujemy jego bazie.

Dokładny serwerowy STATEMENT z parametrami `$1`–`$14` jest poza repo
w `ci-failed-server-sql.log`, bez wartości testowych kont. LIMIT 21 OFFSET
10000, sortowanie po brakujących składnikach, czasie, publikacji i id,
filtr rdzeni GIN, oba opakowania oraz warunki widoczności. Interpolowane SQL
z QueryException przesuwało wartości na `?` wewnątrz regexu; pomiar użył
rzeczywistego SQL z oddzielnymi wiązaniami, bez wydruku wartości kont.

## Izolowany pomiar

Runtime na normalhp:
`/home/codex-admin/kuking-koordynacja-20261003-codex/ci-statystyki-co-ugotuje-20261003`.
Vendor fizycznie skopiowany, zgodny composer.lock; różne inode autoload.php
(20215791 w tej kopii i 550881 w kopii źródłowej), bez dowiązania.
Bazę ustalono i utworzono przed migracjami:
`127.0.0.1:55488/kuking_test_ci_statystyki_co_ugotuje_20261003`,
właściciel `kuking_pg18_owner`, PG18.6, `jit=off`.
Nowy klucz dotyczy wyłącznie nowej instancji. Sonda potwierdziła rzeczywiste
`statement_timeout=15s`; ustawień planera ani limitu nie zmieniono.

Pierwszy start PHPUnit odmówił połączenia na domyślnych parametrach przed
fixture: ERROR konfiguracji, nie baseline. Potem jawnie przekazano wszystkie
parametry własnej bazy w otoczeniu procesu. Oryginalny test: **1/52 PASS,
5,077 s, 0 failures/errors/skips** (`ci-statystyki-baseline-explicit.xml`).

Dokładny fixture: 10020 publicznych i 1 prywatny przepis, po 1 składniku,
1 produkt „jajka”, 0 drugich opakowań, 2 konta. Po dodaniu kolejnego
publicznego przepisu: **10021 widocznych / 10022 wszystkich**, 10022 składników.
Sonda odtwarza te same fabryki i legalną operację terminu w transakcji,
przechwytuje SQL+wiązania `CoUgotuje::dla`, katalog, pg_stats i plany przed/po.

| Tabela | COUNT(*) przed/po | reltuples przed | reltuples po ANALYZE |
|---|---:|---:|---:|
| recipes | 10022 | 0 | 10022 |
| recipe_ingredients | 10022 | 0 | 10022 |
| pantry_items | 1 | -1 | 1 |
| pantry_second_packages | 0 | -1 | 0 |
| users | 2 | -1 | 2 |

Wszystkie wymagane indeksy istnieją, valid+ready: recipes PK i indeks
autora/publikacji, unique `(recipe_id, position)`,
`recipe_ingredients_rdzenie_gin_idx`, unique drugiego opakowania po produkcie.
Pełne definicje, relpages, pg_stats i EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
są w `ci-statystyki-probe.json` poza repo.

| Pełny fixture, od=10000 | Przed ANALYZE | Po ANALYZE |
|---|---:|---:|
| domyślny — domena | 218,067 ms | 182,408 ms |
| domyślny — EXPLAIN ANALYZE | 229,290 ms | 194,243 ms |
| termin — domena | 361,878 ms | 302,992 ms |
| termin — EXPLAIN ANALYZE | 380,788 ms | 320,420 ms |
| domyślny — Total Cost | 258,99 | 16894,33 |
| termin — Total Cost | 391,08 | 17503,40 |

W obu fazach: 20 kart i `jest_wiecej=true`. **Timeoutu nie odtworzono.**
Błędne statystyki są udowodnione; lokalny czas nie dowodzi przyczyny CI.
Nie wykonano kontrolowanego pomiaru dużego bloat/starych stron z wielu
poprzednich fixture pełnego sharda. PostgreSQL nie gwarantuje dokładnego
reltuples po ANALYZE: [katalog pg_class PG18](https://www.postgresql.org/docs/18/catalog-pg-class.html),
[ANALYZE PG18](https://www.postgresql.org/docs/18/sql-analyze.html).
Zaobserwowana równość w tabeli nie jest wymaganiem dla dowolnego przebiegu CI.

## Minimalna korekta i wykonawcze kontrole

ANALYZE pięciu tabel po pierwszym zasiewie i po dodatkowym przepisie,
przed obiema seriami HTTP. Nie ma asercji estymaty ani pętli odczytu katalogu
w teście paginacji; zachowano wszystkie **52 asercje rzeczywistego wyniku**.
Po rollbacku VACUUM (ANALYZE) tych tabel przez zachowane PDO — jak istniejący
CoUgotujeKosztTest, bo statystyki i strony nie są transakcyjne.
Istniejąca mutacja #2599 i wszystkie asercje paginacji pozostają.

Przyrząd poza repo wymaga jednej dokładnej klasy i metody, 0 errors/skips
w testcase i licznikach JUnit. Mutant: exit 1, dokładnie 1 failure,
właściwy marker. Dodatni: exit 0 i 0 failures. Brak, wrongcase, ERROR
i SKIP są odrzucane. Przed każdym przebiegiem czyści tylko własny cache Blade.

| JUnit (1 właściwy testcase) | Asercje | FAIL | ERROR | SKIP |
|---|---:|---:|---:|---:|
| revised-control-before.xml | 52 | 0 | 0 | 0 |
| revised-pagination-mutant.xml | 25 | 1 | 0 | 0 |
| revised-pagination-after.xml | 52 | 0 | 0 | 0 |

Po korekcie wykonano jeden dodatni przebieg (5,084 s), fizyczną mutację
pętli stron (4,167 s) i dodatni po restore (5,048 s).
Fizyczne przywrócenie `@if($jest_wiecej)` daje właściwy FAIL
`PAGINACJA_2599_BEZ_PETLI`. Restore w finally odtwarza oryginalne bajty oraz
mtime_ns widoku; źródło testu pozostaje stabilne w całym cyklu.
SHA256, MD5, rozmiar i mtime_ns są **identyczne przed i po**:

| Cel | SHA256 | MD5 | Bajty | mtime_ns |
|---|---|---|---:|---|
| test, stabilny w cyklu | f66b17a1aa77796309d0cde3761d844c01be1a03816ccf0bd828d814a696e76b | 00098e35b839acbf4db265622e4390e0 | 6561 | 1791036326450936310 |
| widok paginacji | dc1d9097aa82d1710c5548527e98492734fc28026ca6159102b22a4dbf78d0a8 | 65a8c6229e9878eab31e6774473211bd | 8805 | 1791031739000000000 |

Wcześniejszy `physical-control.json` z 8460 pozostaje historycznym pomiarem
małej kontrolowanej bazy: usunięcie ANALYZE oblało ówczesną asercję na 0/-1,
a odtworzenie dokładnych bajtów/mtime dało PASS. Nie jest to powszechny
dowód dokładności statystyk ani kontrola końcowego testu. Ówczesne 1/62
zawierało dziesięć nadmiernych asercji, usuniętych po review.

Pierwszy wadliwie zacytowany filtr klasy dał zero case i został odrzucony.
Pierwszy restore widoku odtworzył bajty/mtime, lecz miał nowszy skompilowany
mutant Blade: dodatni przebieg oblał marker pętli. Zachowano
`pagination-restored-stale-view-cache.xml`; nie zaliczono tej próby.
Po view:clear powtórzono ówczesny fizyczny cykl. Końcowy cykl po korekcie
jest zapisany oddzielnie w `revised-physical-control.json`.
Pint jednego pliku PASS, składnia PHP PASS, git diff --check PASS.
Nie uruchamiano full gates dla tej wąskiej korekty.

## Dowody i rollback

Windows poza repo:
`C:\Users\matma\.codex\worktrees\ci-statystyki-co-ugotuje-20261003\evidence`.
Remote poza runtime:
`/home/codex-admin/kuking-koordynacja-20261003-codex/ci-statystyki-evidence`.
Sonda i baseline są dodatkowo w nadrzędnym katalogu remote, ich kopie są
w Windows evidence. Logi, JUnit, sonda i przyrząd są dostępne w tych katalogach.

| Artefakt | SHA256 |
|---|---|
| revised-physical-control.json (końcowy cykl) | 1535b632d93be1ea0195e833b65683d308caf96134b668db57940373d76f3c12 |
| revised-pagination-after.xml (końcowy 1/52 PASS) | d7db836ec74b7236814d4ec3010b49d386c2f9fd0a6a0bb16320733fc7c7120b |
| physical-control.json (historyczny pomiar 8460) | 73ffcb5d5220d684c957160b7f9d45cc8f7525f7173507faef162c4c54c20cf9 |
| ci-statystyki-probe.json | b9e104e40cc6091633dd2b07c4832b388c5c6b6c87af1e0cf8766f38af6600d3 |
| ci-statystyki-baseline-explicit.xml | 664c16bc30be857bbd665e725de27f72f4c469bc4ac8f93fa922825ad98fdae4 |

Końcowe bajty testu są identyczne w lokalnym WT i odtworzonym runtime
(SHA256 w tabeli restore). Rollback: revert lokalnej korekty testu/receipt.
Bez migracji, zmiany produkcji lub SQL domeny. CI może jeszcze ujawnić
oddzielny problem obciążenia/planera — ta korekta nie ogłasza go rozwiązanym.
