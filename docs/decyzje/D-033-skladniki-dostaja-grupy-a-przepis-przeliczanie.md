## D-033 · Składniki dostają grupy, a przepis przeliczanie porcji

> **Doprecyzowanie właściciela, 20 września 2026, #878:** „Bez ilości” nie
> oznacza „do smaku”. Pokazujemy wyłącznie tekst autora i jego uwagę, bez
> automatycznego dopisku. Zmiana dotyczy prezentacji z #44; flaga i CHECK
> zostają. W zadaniu #741 właściciel polecił poprawić opisy, bez budowania
> skalowania porcji: jest ono nadal niewdrożonym planem V2 (`FEATURES.md`).

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana** · **poprawia D-017**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Status „przyjęta,
> **niezbudowana**" jest dziś prawdziwy tylko dla połowy tego wpisu. Zdanie
> „grupy składników… **w bazie nie ma na to kolumny**" jest nieprawdziwe:
> kolumna `recipe_ingredients.group_name varchar(120) NULL` stoi w schemacie,
> zapis i ujednolicanie pisowni idą przez
> `app/Domain/Recipes/Actions/PublishRecipe.php:399,452`, scalanie przez
> `app/Domain/Recipes/GrupySkladnikow.php`, nagłówki grup renderuje
> `resources/views/pages/recipes/show.blade.php:468`, a pole w formularzu
> działa bez JavaScriptu
> (`resources/views/pages/recipes/szczegoly.blade.php:339-346`). Druga połowa
> — przeliczanie porcji — faktycznie nie istnieje: `servings` jest zwykłym
> polem liczbowym i nic nie skaluje ilości.
>
> **Proponowany kształt, NIE wykonany przez audyt — do rozstrzygnięcia przez
> właściciela.** Jeden status nie może opisywać rzeczy zbudowanej i
> niezbudowanej naraz, więc wpis prosi się o rozdzielenie: część „grupy
> składników" zostaje pod D-033 ze statusem **obowiązuje, wdrożone**, a część
> „przeliczanie porcji" dostaje pierwszy wolny numer na końcu dziennika ze
> statusem **przyjęta, niezbudowana** i zdaniem „wydzielone z D-033".
> Rozdzielenie zmienia strukturę rejestru i numerację — to nie jest adnotacja
> i audyt tego nie robi.

Pierwotne pytanie („czy składnik ma osobne pole na ilość") było nieaktualne
w chwili zadawania: `recipe_ingredients` ma `quantity` (decimal 12,4),
`unit_id` i `no_amount` od 5 września. **D-017 rozjechało się przez to ze
schematem własnej bazy** — mówi „składniki z kolumną ilości: nie i nie
będzie", a kolumna jest.

Zostały dwie rzeczy, których naprawdę nie ma, i obie właściciel przyjął do
zbudowania:

1. **Grupy składników.** Strona przepisu w systemie v3.1 grupuje je pod
   nagłówkami („Ciasto", „Farsz", „Do podania"). W bazie nie ma na to kolumny.
2. **Przeliczanie porcji.** Makieta kroku 2 obiecuje pod polami „żeby dało się
   je potem przeliczyć na inną liczbę porcji". Nic tego nie liczy.

**Czego to nie wolno złamać.** `no_amount` istnieje dokładnie po to, żeby „sól
do smaku" nie skalowała się razy trzy (issue #44), a CHECK
`recipe_ingredients_no_amount_check` pilnuje, że składnik bez ilości nie ma
ani `quantity`, ani `unit_id`. Przeliczanie porcji musi te wiersze zostawić
w spokoju — to jest warunek wbudowany w bazę, nie uprzejmość.

**Odrzucone: zostawić jak jest i skasować obietnicę.** Byłoby tanie (jedno
zdanie z pomocy przy kroku 2), ale przepis bez grup jest listą dwudziestu
pozycji bez podziału na ciasto i farsz — a to jest dokładnie ten przepis,
który się drukuje i kładzie obok blatu.

**Zanim to powstanie:** D-017 ma opisywać stan faktyczny — ilość JEST,
skalowania nie ma — a nie zaprzeczać schematowi.

**Zmiana wymaga:** nowej decyzji właściciela; ta jest świeża i nie ma jeszcze
kodu, który mogłaby unieważnić.

📄 `database/migrations/2026_09_06_130000_add_no_amount_to_recipe_ingredients.php` ·
`database/migrations/2026_09_08_100000_add_group_name_check_to_recipe_ingredients.php` ·
issue #44 · D-017 ·
`docs/ROADMAP.md`
