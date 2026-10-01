## D-310 — Planer tygodnia bez listy zakupów: pierwszy krok z #27 (26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela ·
Dotyczy **#27**, opiera się na **D-282**

**Co powstało.** `/planer` — tydzień od poniedziałku do niedzieli, każdy dzień
z listą pozycji. Pozycja to przepis (dodany przyciskiem „Dodaj do planera” na
stronie przepisu) albo własny wpis wpisany ręcznie („obiad u mamy”). Do tego
„Skopiuj poprzedni tydzień”. Wejście z ekranu „Moje”, bo dolna nawigacja ma
najwyżej pięć pozycji (`AGENTS.md` §5) i planer się do niej nie dopisuje.

**Czego NIE ma i to jest wybór, nie brak czasu.** Listy zakupów, sumowania
składników, trybu offline i współdzielenia z domownikami. Issue #27 opisuje
„najmniejszą kolejność” po spełnieniu bramki i ta zmiana realizuje wyłącznie
jej punkt 1. Powód jest w samym issue: składnik jest u nas wolnym tekstem
(`ingredient_text`), więc automatyczne „1 jajko + 2 jajka = 3 jajka”
wymagałoby parsera i potwierdzania wyniku przez człowieka. Obiecywanie tego
jako „prostego wykorzystania gotowych danych” byłoby nieprawdą.

**Plan jest prywatny i nie jest furtką do treści.** Nie ma widoczności do
ustawienia, bo nie ma czego pokazać innym. Przepis widnieje w planie z
tytułem i linkiem TYLKO wtedy, gdy właściciel planu wciąż go widzi — ta sama
reguła co na liście zeszytu. Przepis zawężony, usunięty miękko albo odcięty
blokadą zostaje w planie jako „Przepis jest już niedostępny.”, bez tytułu;
po twardym usunięciu — „Przepis został usunięty.”. Pozycja NIE znika:
„poprawne dane nigdy nie znikają” dotyczy też planu, a kryterium z #27 mówi
to wprost.

**Dlaczego `ON DELETE SET NULL`, a nie `CASCADE`.** Bo `CASCADE` kasowałby
ręcznie ułożony plan przy usunięciu cudzego przepisu. Kosztem jest wiersz bez
przepisu i bez tekstu — dlatego CHECK mówi „najwyżej jedno z dwóch”, a nie
„dokładnie jedno” jak w `collection_items`. Szczegóły schematu:
`docs/DATABASE.md`, sekcja `meal_plan_entries`.

**Bez przeciągania i bez skryptu.** Każda akcja to zwykły formularz z
przyciskiem ≥ 48 px, wybór dnia to lista radiowa, nie `<select>`. Ekran działa
z wyłączonym JavaScriptem w całości (D-053 nie wymaga tu skryptu).

**Limity.** Własny koszyk `kuking.limits.planer` (60/10), żeby układanie
tygodnia nie zjadało budżetu zapisywania przepisów — ta sama pomyłka, którą
naprawiono przy zeszycie. Najwyżej `kuking.planer.wpisow_na_dzien` (10)
pozycji na dzień, okno dni: 60 wstecz i rok do przodu.

**RODO.** Paczka danych wydaje plan w sekcji `planer` (bez tytułów przepisów,
których właściciel już nie widzi — to dane ich autorów), a wymazanie konta
kasuje pozycje bezwarunkowo, niezależnie od zakresu usunięcia: plan nigdy nie
był pokazany nikomu innemu.

**Czego ta decyzja NIE przesądza.** Czy planer będzie funkcją premium
(`docs/MONETIZATION.md` wymienia go jako kandydata) i czy lista zakupów
w ogóle powstanie — issue #27 każe najpierw zmierzyć użycie. Pomiar przejścia
`plan → ugotowanie` liczy się z istniejących tabel (`meal_plan_entries` razem
z `cooked_events`), więc nie dokładamy pod to nowego sygnału produktowego.

### Wycofanie
Bez zmian w cudzych danych: trasy, ekran i akcje są samodzielne. Migracja
`2026_09_26_100000_create_meal_plan_entries_table` przy cofaniu ODMAWIA, gdy
w tabeli są plany ludzi (D-088 — powód i droga ręczna w komunikacie).
Wycofanie funkcji wymaga zdjęcia wpisu z `InwentarzDanychKonta` i sekcji
`planer` z paczki danych, inaczej test inwentarza oblewa.

📄 `docs/DATABASE.md`, `docs/FLOWS_AND_SCREENS.md`, `config/kuking.php`,
`routes/web.php`, `app/Domain/Planer/`, `tests/Feature/PlanerTygodniaTest.php`
