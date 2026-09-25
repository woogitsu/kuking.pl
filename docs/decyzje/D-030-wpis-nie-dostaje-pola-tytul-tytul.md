## D-030 · Wpis nie dostaje pola „tytuł" — tytuł należy do przepisu

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „pola na tytuł nie
> ma, `posts.title` nie istnieje w żadnej migracji" przestało obowiązywać 18
> września 2026 — patrz **D-163**. Migracja
> `database/migrations/2026_09_18_100000_add_kind_and_title_to_posts.php:18`
> dodaje `title varchar(180) NULL`. Sedno tej decyzji przetrwało i jest dziś
> wymuszone bazą: `posts_kind_title_check` wymaga `(kind = 'dish' AND title IS
> NULL)`, więc wpis-danie tytułu nadal nie ma i mieć nie może. Zmieniła się
> klasa obiektu: `kind = 'question'` tytułu WYMAGA (10–180 znaków). D-163 nie
> odesłała tutaj, więc do dziś ten wpis odpowiadał nieprawdziwie na pytanie
> „czy wpis ma tytuł" dla połowy wierszy w `posts`.

System projektowy v3.1 wprowadza `.karta-tytul` i opisuje go wprost jako nowy
element: „dziś karta ma tylko treść, przez co nazwa autora jest największym
napisem w karcie". D-110 daje mu 24 px i wagę 800 — czyli szczyt hierarchii.
Makieta tablicy używa go trzy razy.

Produkt mówi co innego i mówi to od początku: wpis to **„zdjęcie i kilka
słów"** (`docs/brand/BRAND_EXTENDED.md` §1.1), a formularz dodania zdjęcia ma
pola „Napisz kilka słów" i „Kto to widzi". Pola na tytuł nie ma, `posts.title`
nie istnieje w żadnej migracji.

**Rozstrzygnięcie: tytuł zostaje tam, gdzie już jest — w przepisie.**
`.karta-tytul` obsługuje kartę przepisu, nie kartę wpisu.

**Dlaczego nie odwrotnie.** Główna akcja serwisu brzmi „Co dziś ugotowałeś?" —
zdjęcie i kilka słów. Pole tytułu dokłada do niej **jedną decyzję przed
opublikowaniem**, a każda taka decyzja to miejsce, w którym ktoś przestaje
publikować. Grupa 50+ jest na to szczególnie czuła: pusty formularz z trzema
polami jest trudniejszy niż z dwoma, a wpisów bez tytułu jest dziś
osiemdziesiąt i nie ma sensownej odpowiedzi na pytanie, co z nimi zrobić.

**Skutek dla kitu:** największym napisem w karcie wpisu zostaje nazwa autora.
To jest świadome odstępstwo od v3.1, nie przeoczenie — kit dopasowuje się do
danych, nie odwrotnie (ta sama zasada co D-017).

**Zmiana wymaga:** zmierzonego problemu z przeglądaniem feedu, którego nie
rozwiązuje pierwsze zdanie treści wpisu użyte jako podpis.

📄 `docs/design/DESIGN_SYSTEM.md` §2.1 · `resources/views/components/post-card.blade.php` ·
`docs/brand/BRAND_EXTENDED.md` §1.1
