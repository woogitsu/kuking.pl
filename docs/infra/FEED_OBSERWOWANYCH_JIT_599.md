# Feed obserwowanych na `/`: koszt planu i JIT (#599)

Pomiar z 29 września 2026 do issue [#599](https://github.com/woogitsu/kuking.pl/issues/599)
(alarm „Łączny czas zapytań SQL żądania przekroczył próg" na `GET /`, 1330 i 1116 ms przy progu 1000 ms).
Dotyczy zalogowanej osoby: `FollowingFeed::paginate()`. Gość ma inne zapytanie (patrz `ODKRYJ_KOSZT_1952.md`).

## Wynik w skrócie

- **Przyczyna.** Główne zapytanie feedu miało szacowany koszt planu ok. 178 tys. (275 tys. w teście
  w skali #605), czyli powyżej `jit_above_cost` = 100 000. PostgreSQL kompilował je przez JIT **przy
  każdym żądaniu**: ok. 220–280 ms z ok. 300–390 ms wykonania. Czas samego wykonania bez JIT to
  ok. 40 ms.
- **Dlaczego szacunek był tak wysoki.** Skorelowane `EXISTS` w alternatywach (`OR`) planer nalicza
  za **każdy** kandydujący wiersz `posts` (tu ok. 4000 z 6000). Nie może zamienić ich na półzłączenie,
  bo stoją wewnątrz `OR`. W feedzie było ich pięć:
  1. przepis wpisu (`whereHas('recipe')`, w tym `Recipe::widoczneDla()`),
  2. zdjęcie wpisu (`post_media`, „własna treść"),
  3. obserwowanie autora w `Post::widoczneDla()` („tylko dla obserwujących"),
  4. to samo w `Recipe::widoczneDla()`,
  5. gałąź tematów: `whereHas('tags')` i ukrycie osoby (`hides`).
  Sam przepis wpisu kosztował ok. 17,7 na wiersz, czyli ok. 70 tys. z 155 tys.
- **Poprawka.** Te same reguły zapisane jako `IN (podzapytanie)` (i `NOT IN` z jawnym
  `IS NOT NULL` dla ukrytych osób). Podzapytanie bez korelacji planer nalicza raz, a wynik jest ten
  sam. Zmiana **nie dotyka ustawień JIT bazy** (to decyzja infrastruktury).
- **Skutek.** Koszt planu 178 tys. → ok. 3 tys. (test w skali #605: 275 816 → 3383). JIT znika.
  Czas SQL ciepłego żądania: mediana ok. 370 ms → ok. 77 ms (pomiar niżej).

## Co dokładnie się zmieniło

Wspólne scope’y dostały opcjonalny argument `bool $bezKorelacji = false`. Domyślnie działają
dokładnie jak przedtem (skorelowany `EXISTS`); tylko `FollowingFeed` przekazuje `true`.

| Miejsce | Domyślnie (bez zmian) | Z `bezKorelacji: true` |
|---|---|---|
| `Post::scopeWidoczneDla()` | `EXISTS follows (… = posts.author_id)` | `posts.author_id IN (SELECT followed_id FROM follows WHERE follower_id = ?)` |
| `Recipe::scopeWidoczneDla()` | `EXISTS follows (… = recipes.author_id)` | `recipes.author_id IN (SELECT …)` jak wyżej |
| `Post::scopeBezUkrytychOsob()` | `NOT EXISTS hides (… = posts.author_id)` | `posts.author_id NOT IN (SELECT hidden_user_id FROM hides WHERE … AND hidden_user_id IS NOT NULL)` |
| `Post::scopeZWidocznymPrzepisemAlboWlasnaTresciBezKorelacji()` (nowy) | (stary scope `zWidocznymPrzepisemAlboWlasnaTrescia()`: `EXISTS post_media`, `EXISTS recipes`) | `posts.id IN (post_media)`, `posts.recipe_id IN (widoczne przepisy)` |
| `FollowingFeed::zrodla()` | `whereHas('tags')` | `posts.id IN (SELECT post_id FROM post_tags JOIN tags …)` |

**Dlaczego flaga, a nie zmiana globalna.** Pierwsza wersja zmieniała `widoczneDla` wprost. Test
`SzynaOstatnioZapisanychKosztTest` od razu oblał: szyna „Ostatnio zapisane" czytała 320 zamiast
≤ 100 wierszy, a liczniki zeszytów miały koszt 562 tys. zamiast 211 tys. Tam kandydatów jest
niewielu i skorelowany `EXISTS` jest tańszy niż czytanie całej tabeli. Reguły zostają więc w
jednym miejscu, a forma zależy od miejsca użycia.

Stary scope `zWidocznymPrzepisemAlboWlasnaTrescia()` zostaje bez zmian dla pozostałych list
(profil, tag, Odkrywanie, tablica). `NOT IN` bez `IS NOT NULL` byłoby błędem: wiersze „ukryty
wpis" mają `hidden_user_id = NULL`, a jedno `NULL` na liście `NOT IN` odrzuca wszystko (test to
łapie, patrz niżej).

## Cena tej zmiany i granice

Podzapytania o widoczne przepisy i o wpisy ze zdjęciem czytają te tabele **raz na zapytanie**
(tablica haszująca), a nie tylko wiersze wskazane przez kandydatów. Przy 20 tys. przepisów to
ok. 25–40 ms z ok. 40–60 ms zapytania i dziś jest to jedyny większy koszt. Rośnie liniowo z
liczbą przepisów, więc przy wielokrotnie większej tabeli trzeba mierzyć od nowa
(kolejny krok, jeśli będzie potrzebny: ograniczyć zbiór kandydatów przed sprawdzaniem treści albo
podać planerowi kolejność po `published_at`). Nie robiłem tego teraz, bo koszt planu ma zapas
30-krotny, a wykonanie jest kilka razy szybsze niż przed zmianą.

Ten sam wzorzec (skorelowane `EXISTS` w `OR`) występuje w innych listach. Nie mierzyłem ich w tym
etapie. Przy alarmach na innych trasach warto sprawdzić `EXPLAIN` pod kątem `SubPlan` w `Filter`.

## Dane i sposób pomiaru

- PostgreSQL 18.6 lokalnie (127.0.0.1:5432), ustawienia domyślne (`jit = on`, `jit_above_cost = 100000`),
  po `ANALYZE`. Maszyna: 4 CPU, współdzielona z innymi procesami. Czasy mówią o proporcjach.
- Zbiór w skali #605: 361 kont, zalogowany obserwuje **300** osób (a każde konto tła 60), **6000
  wpisów** (co 3. zapowiedź przepisu, co 4. ze zdjęciem, co 9. „dla obserwujących", co 17. prywatny),
  **20 000 przepisów** (prywatne, „dla obserwujących", szkice), 30 tematów z przypiętymi wpisami
  i **5 obserwowanych tematów**, 10 ukrytych wpisów i 3 ukryte osoby, blokada w obie strony,
  jeden zbanowany obserwowany. Zbiór jest budowany w teście
  (`tests/Feature/FeedObserwowanychKosztPlanuTest.php`).
- Pomiar czasu: oddzielna baza, ten sam zbiór, `FollowingFeed::paginate()` po jednym żądaniu
  rozgrzewającym, suma czasów zapytań z `DB::listen`, pięć przebiegów na wariant.
  `EXPLAIN (ANALYZE, FORMAT JSON)` głównego zapytania z tymi samymi parametrami.
- Wynik feedu porównany 1:1 ze starym zapytaniem (te same identyfikatory, ta sama kolejność).

## Wyniki

| | Przed | Po |
|---|---:|---:|
| Koszt planu głównego zapytania (baza pomiarowa) | 178 167 | 3 080 |
| Koszt planu w teście (skala #605) | 275 816 | 3 383 |
| Czas głównego zapytania [ms], 5 przebiegów | 194 / 355 / 354 / 339 / 573 | 49 / 57 / 55 / 38 / 62 |
| Suma czasów zapytań `paginate()` [ms] | 207 / 369 / 371 / 363 / 588 | 60 / 85 / 77 / 54 / 91 |
| JIT na jedno żądanie (`EXPLAIN ANALYZE`) | 223–277 ms, z inliningiem | brak |

Mediana sumy SQL: ok. 370 ms przed, ok. 77 ms po (maszyna dzielona, rozrzut duży; rząd wielkości ok. 5x). Liczba zapytań się nie zmieniła (9).

## Test

`tests/Feature/FeedObserwowanychKosztPlanuTest.php`:

1. `test_koszt_planu_feedu_przy_skali_605_nie_przekracza_progu_jit`: najwyższy szacunek ze
   wszystkich SELECT-ów jednego `paginate()` poniżej `jit_above_cost`. Dodatkowo ten sam wynik co
   stare zapytanie na trzech stronach kursora.
2. `test_feed_zwraca_ta_sama_liste_co_zapytanie_sprzed_zmiany`: scena z pułapkami (wpisy ukryte,
   zapowiedzi bez treści z przepisem prywatnym, „dla obserwujących", szkicem, usuniętym, autorem
   zablokowanym i blokującym, zbanowanym, tematem aktywnym i ukrytym, ukrytą osobą obserwowaną
   wprost i tylko z tematu, wygasłym ukryciem). `staraLista()` w teście to dosłowna kopia zapytania
   sprzed zmiany, razem ze starymi postaciami `widoczneDla`.
3. Test sprząta po sobie: po wycofaniu transakcji robi `VACUUM (ANALYZE)` na zasianych tabelach.
   Bez tego martwe krotki i `reltuples` z 20 tys. przepisów zostają w bazie i zawyżają koszt
   następnego testu w tym samym procesie (liczniki zeszytów: 621 tys. zamiast 211 tys.).

Kontrole ujemne (wszystkie wykonane): przywrócenie starego kodu w trzech plikach `app/` (`Post`, `Recipe`, `FollowingFeed`) daje
koszt 275 816 i test kosztu **oblewa**; usunięcie warunku zdjęcia, zmiana `follower_id` w
`Recipe::widoczneDla()` albo usunięcie `IS NOT NULL` przy ukrytych osobach — test zgodności listy
**oblewa**.
