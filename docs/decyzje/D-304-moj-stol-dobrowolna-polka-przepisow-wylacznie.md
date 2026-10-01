## D-304 — „Mój stół”: dobrowolna półka przepisów wyłącznie z zamkniętej listy doboru (#1749, D-275, 26 września 2026)

**Data:** 26 września 2026 · **Decyzja właściciela** (26.09: „budujemy teraz”,
twardy warunek: dobór wyłącznie z zamkniętej listy D-275) · Status: **obowiązuje**

### Decyzja

Budujemy „Mój stół” z issue #1749 — prywatną, **dobrowolną** półkę przepisów
pod `/moj-stol`, ze skrótem w prawej szynie Startu. Obserwowani i „Świeżo
z Kuking” zostają bez zmian (chronologia, D-276, D-277).

**Dobór — tylko reguły z AGENTS.md §8 (D-275):**

1. „Z tagów, które obserwujesz” — jawne polecenie widza (obserwowane, aktywne
   tagi), w nim czas i równość autorów: najnowszy przepis każdej osoby, potem
   od najnowszego, najwyżej 6 (`MojStol::NA_POLCE_Z_TAGOW`).
2. „Wybór gospodarza: tag …” — oznaczony wybór gospodarza: pierwszy tag z listy
   `tag_promotions` (w kolejności gospodarza), którego widz **nie** obserwuje
   i w którym jest co pokazać; w nim czas i równość autorów, najwyżej 3.
   To jest obowiązkowa pula „nowego tematu” z issue (przeciw bańce) i zimny
   start dla kogoś bez obserwowanych tagów.
3. Przed wyborem: bramki i blokady (publiczny, opublikowany, wskazuje widoczny
   przepis, aktywne konto autora, blokady w obie strony) oraz ukrycia widza
   z #1810 — wpis **i osoba** (półka to podsunięcie, jak Odkrywanie, D-278).
   Własne przepisy widza pomijamy.
4. Najwyżej jeden przepis od osoby na całej półce.
5. „kuKINGi na dziś” (dopisane 26.09 po odpowiedzi właściciela, PR #1875) —
   oznaczony wybór gospodarza na dziś (`daily_picks`, tylko wpisy wskazujące
   przepis), w kolejności gospodarza (`daily_picks.position`), najwyżej 3
   (`MojStol::NA_POLCE_NA_DZIS`). Trzecia sekcja półki z własnym nagłówkiem
   i „Pokazujemy, bo gospodarz wybrał ten przepis na dziś.” Te same filtry co
   reszta półki — **także ukrycie osoby** (na tablicy dnia ukrycie osoby
   wyboru gospodarza nie zdejmuje, D-278; na półce właściciel chce jednego
   zestawu filtrów). Autor, który już stoi na półce, nie wchodzi drugi raz;
   z dwóch wyborów jednej osoby zostaje pierwszy w kolejności gospodarza.

**„Dlaczego to widzę”** — reguła jednym zdaniem na półce, w szynie (odnośnik)
i na stronie pomocy (`MojStol::DLACZEGO`):

> Pokazujemy najnowsze przepisy z tagów, które obserwujesz, z jednego tagu
> polecanego przez gospodarza i przepisy, które gospodarz wybrał na dziś — po
> jednym od osoby, bez tego, co ukrywasz, i nigdy według liczby polubień ani
> Twoich kliknięć.

Przy każdej pozycji: „Pokazujemy, bo obserwujesz tag: …” albo „…bo gospodarz
poleca tag: …”, i przycisk „Nie pokazuj mi tego” (= „Ukryj ten wpis” z #1810,
z „Cofnij” i listą „Ukryte”).

### Co z issue #1749 świadomie odpada

- **Zapisane przepisy, ugotowane dania, podobieństwo składników i typu dania
  jako źródło kandydatów** i „deterministyczny scoring” — to przewidywanie
  gustu z zachowania widza, poza zamkniętą listą. Wymagałoby osobnej decyzji
  właściciela (AGENTS.md §8: „reguła spoza tej listy wymaga decyzji
  właściciela, nie PR-a”).
- **„Resetuj moje dopasowanie”** — półka niczego nie zapamiętuje, więc nie ma
  czego resetować. Strona mówi to wprost i prowadzi do listy „Ukryte”
  i ustawień tagów.
- **„Ukryj temat”** — ukrycia tagu nie ma jeszcze w #1810 („tag później”).
  Na półce działa „Zmień obserwowane tagi”; tag od gospodarza po prostu
  ustępuje następnemu, gdy widz zacznie go obserwować.
- Metryki przed modelem uczonym (#1814) — model uczony nie powstaje.

### Dane

Jedna kolumna `users.moj_stol_enabled` (`boolean NOT NULL DEFAULT false`,
docs/DATABASE.md). Wyłączona półka nie liczy żadnego zapytania o propozycje.
Eksport: `konto.moj_stol_wlaczony`; wymazanie konta ustawia `false`.
Rollback **przechodzi bez odmowy** — świadome odstępstwo od D-088: utracona
wartość to preferencja wyświetlania, a kierunek utraty (wyłączenie) jest
bezpieczny. **Właściciel zaakceptował to odstępstwo 26 września 2026**
(odpowiedź na pytania do PR #1875).

### Odpowiedzi właściciela z 26 września 2026 (PR #1875)

1. Kandydaci z zapisów, wykonań i podobieństwa składników oraz scoring —
   zostają poza półką, jak wyżej.
2. Brak „resetu” i „ukryj temat” — zostaje, jak wyżej.
3. Rollback bez odmowy — zaakceptowany.
4. Na półce tylko wpisy wskazujące przepis — zostaje.
5. „kuKINGi na dziś” — dodane jako trzecia sekcja (punkt 5 decyzji).

### Strażnik

`app/Domain/Feed/MojStol.php` leży w `app/Domain/Feed`, więc skan
`FeedNieSortujePoMierzeReakcjiTest` obejmuje go z definicji katalogu;
dodatkowo kotwica zasięgu i asercja, że skan widzi sortowanie półki po
`posts.published_at` oraz „kuKINGów na dziś” po `daily_picks.position`. Dwie
kontrole ujemne w `scripts/kontrole-negatywne-alfa08.py` podmieniają każde
z tych sortowań na `cooked_events_count` — strażnik ma oblać (obie sprawdzone
lokalnie).

### Koszt wejścia jest stały (#1968)

Temat od gospodarza nie odpytuje bazy osobno dla każdego promowanego tagu.
Jedno zbiorcze zapytanie numeruje wpisy w oknie (tag, osoba) i (tag), z tymi
samymi bramkami, blokadami, ukryciami i pominięciem autorów już na półce;
wybór tematu (pierwszy w kolejności gospodarza, który ma co pokazać) zapada
w PHP. Pula to najwyżej `MojStol::TEMATOW_DO_ROZPATRZENIA` (20) pierwszych
tagów listy gospodarza — dalsze na półkę nie trafiają. „kuKINGi na dziś”
numerują wybory po osobie i biorą `NA_POLCE_NA_DZIS` w samym zapytaniu.
Test: ta sama liczba zapytań przy 1 i 10 promowanych tagach
(`test_temat_gospodarza_ma_stala_liczbe_zapytan_niezalezna_od_liczby_tagow`).

### Wycofanie

Zdjęcie trasy `/moj-stol`, bloku w `szyna-startowa` i klasy `MojStol`;
kolumnę można zostawić (kod bez niej działa) albo cofnąć migrację. Każde
rozszerzenie doboru poza listę D-275 — nowa decyzja właściciela.

📄 `app/Domain/Feed/MojStol.php` · `app/Http/Controllers/MojStolController.php` ·
`resources/views/pages/moj-stol.blade.php` · `tests/Feature/MojStolTest.php` ·
D-275 · D-276 · D-277 · D-278
