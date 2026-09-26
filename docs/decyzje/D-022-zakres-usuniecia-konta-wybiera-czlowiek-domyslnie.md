## D-022 · Zakres usunięcia konta wybiera człowiek; domyślnie tekst zostaje

**Data:** 7 września 2026 · Status: **obowiązuje** · rozszerza D-018 ·
weryfikacja W1 (pomiar), issue #8

D-018 rozstrzygnęło: zdjęcia kasujemy wszystkie, tekst zostaje
zanonimizowany. **Pomiar z 7 września pokazał, że druga połowa tej decyzji
nigdy nie działała.**

### Co było zepsute i dlaczego nikt tego nie zauważył

`EraseAccountData` nie zmienia `users.status` — po zakończonej anonimizacji
konto zostaje na `pending_delete`. Na tym statusie stoi
`User::jestDostepnyJakoAutor()` i sześć Policy. Zmierzone na żywej bazie:

| Co | Przed anonimizacją | Po anonimizacji |
|---|---|---|
| przepis | 200 | **403** |
| wpis | 200 | **403** |
| profil | 200 | **403** |
| przepis w CUDZYM zeszycie | widoczny | **wypada z listy** |
| komentarz | widoczny | **niewidoczny nawet dla autora wpisu** |

Czyli: tekst zostawał w bazie, ale znikał ze serwisu. D-018 obiecało jedno,
a serwis robił drugie — i to jest **dokładnie ten nawracający wzorzec, który
opisuje `docs/HANDOVER.md`**: reguła istnieje poprawnie w jednej warstwie,
a druga implementuje ją inaczej.

Nie zauważono tego, bo test `test_tekst_zostaje_ale_bez_nazwiska` asertuje
**wyłącznie obecność wiersza w bazie**. Widoczności nie sprawdza wcale. Test
przechodził i „dowodził" czegoś, czego nie było. Drugi test,
`KomentarzeGranicaStatusuAutoraTest:62`, **aktywnie pilnował zaprzeczenia**
tej obietnicy — zamroził stan faktyczny jako oczekiwany.

### Co odrzucono

**Sam nowy status końcowy, bez pytania człowieka.** Naprawiłoby D-018
dosłownie i było najtańsze. Odrzucone, bo zostawia jedno rozstrzygnięcie
narzucone wszystkim: część ludzi usuwa konto właśnie po to, żeby ich słowa
zniknęły, i dla nich „tekst zostaje, tylko bez podpisu" nie jest tym, o co
prosili. Anonimizacja jest naszą oceną, że tak jest lepiej dla społeczności
— a to nie jest ocena, którą wolno robić za kogoś przy jego własnych
danych.

**Kasowanie wszystkiego, na powrót do wariantu odrzuconego w D-018.**
Argument z D-018 nadal obowiązuje: cudze wątki urywają się w połowie, cudze
zeszyty gubią przepisy. Nie ma powodu unieważniać tamtej analizy.

### Co wybrano

**Ekran usuwania konta pyta, a domyślnie kasuje MINIMUM.**

Haczyk „usuń także moje wpisy, przepisy i komentarze" jest **odhaczony**.
Kto go nie tknie, dostaje D-018: zdjęcia znikają, tekst zostaje
zanonimizowany i — po tej naprawie — **nadal widoczny**. Kto go zaznaczy,
dostaje pełne usunięcie razem z tekstem.

Uzasadnienie domyślnej wartości: domyślna opcja ma być tą, której skutków
nie da się cofnąć w mniejszym stopniu. Zostawiony tekst da się skasować
później; skasowanego nie da się przywrócić. Domyślne odhaczenie nie jest
więc wygodą dla serwisu, tylko wyborem mniej nieodwracalnej ścieżki dla
osoby, która klika w pośpiechu.

### Co to wymaga od kodu

1. **Stan końcowy konta** obok `pending_delete` — inaczej granica
   autoryzacji dalej ukrywa tekst i cała ta decyzja jest fasadą.
   `data_erased_at` już istnieje, ale `jestDostepnyJakoAutor()` go nie
   czyta.
2. Wybór człowieka **zapisany razem z żądaniem usunięcia**, nie odczytany
   w chwili wykonania — między jednym a drugim mija 30 dni i ekran, na
   którym stawiano haczyk, może już nie istnieć w tej formie.
3. `KomentarzeGranicaStatusuAutoraTest:62` do świadomego przepisania. To
   nie jest test do wyciszenia — to jest test, który trzeba zmienić razem
   z decyzją, którą zamroził.
4. Test na WIDOCZNOŚĆ, nie na obecność wiersza. Poprzedni test przechodził
   właśnie dlatego, że sprawdzał to drugie.

📄 `app/Domain/Users/Actions/EraseAccountData.php` · `app/Models/User.php` ·
`resources/views/pages/settings/data.blade.php` · D-018
