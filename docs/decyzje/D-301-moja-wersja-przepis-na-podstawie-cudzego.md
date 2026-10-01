## D-301 — „Moja wersja”: przepis na podstawie cudzego, z nieusuwalnym podpisem oryginału (issue #23, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela
z 26.09.2026: budujemy teraz, bramka retencji V1 tej funkcji nie blokuje ·
Dotyczy **#23**

**Problem.** Ludzie gotują po swojemu i zapisują to dziś w `changes_note`
przy „Ugotowałem”. Osobny przepis-wersja może zniszczyć produkt na trzy
sposoby (treść issue): kradzież autorstwa, farma niemal identycznych stron
w Google (`docs/seo/SEO_TECHNICAL.md` §1.4 i §7) i rozmycie oryginału.

**Decyzja.**

1. **Schemat:** `recipes.forked_from_id` (FK do `recipes`, `ON DELETE SET
   NULL`) + `recipes.forked_at` (znacznik „to jest wersja”, zostaje po
   twardym skasowaniu oryginału). Obie kolumny poza `$fillable`; ustawia je
   tylko `App\Domain\Recipes\Actions\ZrobWlasnaWersje`. Rollback odmawia przy
   choćby jednej wersji (D-088).
2. **Kto może** (`RecipePolicy::fork`): konto aktywne, przepis cudzy
   i opublikowany, **widoczny dla tej osoby** — także „dla obserwujących”
   (blokady, ban, usuwanie konta — wszystko przez `view()`). Uzupełnienie
   właściciela z 26.09.2026: „nie ma co utrudniać, jak nie skopiują, to
   zrobią screena” — pierwsza wersja tej decyzji dopuszczała tylko przepisy
   publiczne. Odbiorca wersji, który nie widzi oryginału, czyta w podpisie
   „oryginał jest niedostępny”, a `isBasedOn` w JSON-LD dostaje tylko
   oryginał widoczny dla gości.
3. **Co się kopiuje:** tytuł, opis, porcje, czasy, trudność, składniki
   (z grupami, uwagami, „bez ilości”), treść kroków z minutnikami. **Bez
   zdjęć** (to zdjęcia autora oryginału) i **bez pochodzenia**
   (`source_person`, `source_note`, `family_since_year` to historia autora
   oryginału). `source_type = adaptation`. Szkic jest `draft` + `private`;
   drugie kliknięcie oddaje istniejący szkic wersji.
4. **Podpis:** nad tytułem, w kreatorze i w formularzu szczegółów —
   „Na podstawie przepisu: „{tytuł}” · {nazwa konta}”, nazwy dosłownie, bez
   odmiany (COPY_STYLE). Oryginał niewidoczny dla widza (usunięty, ukryty,
   zawężony, autor zablokowany/zbanowany, skasowany) → „Na podstawie
   przepisu innej osoby — oryginał jest niedostępny.” Podpis nie znika nigdy.
5. **Realna różnica:** publikacja wersji, której składniki (tekst, grupa,
   uwaga, „bez ilości”), kroki (treść, minutnik), porcje i czasy są takie
   same jak w oryginale, jest odrzucana komunikatem „To jest ten sam przepis.
   Może wystarczy „Ugotowałem”? …”. **Tytuł, opis, zdjęcie, trudność
   i pochodzenie nie są zmianą przepisu** — inaczej wystarczyłoby
   przemianować cudzy rosół. Porównanie idzie także z oryginałem usuniętym
   miękko (wskrzeszenie zdjętej treści pod innym nazwiskiem).
6. **SEO:** wersja, której mniej niż 30% trzysłowowych fragmentów tekstu
   (składniki + kroki) nie występuje w **publicznym** oryginale, dostaje
   `noindex, follow`; `canonical` zawsze na siebie; JSON-LD `isBasedOn` =
   adres oryginału, gdy oryginał jest widoczny dla gościa. Oryginał poza
   indeksem (prywatny, usunięty) nie ma z czym się dublować, więc nie
   blokuje wersji. **Wszystkie wersje są poza mapą strony** — próg liczony
   w pętli mapy kosztowałby tysiące zapytań; mapa ma być podzbiorem stron
   indeksowalnych, nie pełną listą.
7. **Oryginał zyskuje:** sekcja „Wersje innych osób” (karty przepisów,
   chronologicznie, „Pokaż więcej”, **bez liczby wersji** — AGENTS.md §12),
   tylko wersje opublikowane i widoczne dla widza. Przycisk „Zrób swoją
   wersję” stoi pod przepisem, nie obok „Ugotowałem”.
8. **Powiadomienie** `recipe.forked` do autora oryginału: przy **pierwszym
   udostępnieniu wersji innym** — pierwszym zapisie, po którym wersja jest
   opublikowana z widocznością szerszą niż prywatna (także przejście
   „tylko ja” → „obserwujący”/„wszyscy” po publikacji); tylko gdy autor
   oryginału może ją wtedy zobaczyć; raz na wersję (ponowne udostępnienie
   po powrocie do prywatnej nie powiadamia drugi raz). Uzupełnienie
   właściciela z 26.09.2026; wcześniej: tylko przy pierwszej publikacji. To nie jest „Ugotowałem” i nie zmienia jego obietnicy (AGENTS.md
   §1); granice (własna akcja, konto zamknięte, blokada) daje `NotifyUser`.
9. **Eksport danych:** przy przepisie `moja_wersja_od` i
   `na_podstawie_przepisu` (tytuł i adres oryginału tylko wtedy, gdy
   właściciel paczki może go dziś zobaczyć).

**Czego świadomie nie zrobiono:** wariantu „wersja jako sekcja na stronie
oryginału” zamiast osobnego adresu (SEO §1.4 pkt 2) — próg `noindex` daje
ten sam skutek bez drugiego sposobu wyświetlania przepisu; porównania wersji
między sobą (dwie wersje podobne do siebie, a różne od oryginału).
„Raz na wersję” opiera się na istniejącym powiadomieniu: po jego usunięciu
retencją (3 miesiące) ponowne udostępnienie po okresie prywatności
powiadomiłoby jeszcze raz — świadomie bez osobnej kolumny.

**W kodzie.** `App\Domain\Recipes\MojaWersja`, `ZrobWlasnaWersje`,
`RecipePolicy::fork`, trasa `POST /przepisy/{slug}/moja-wersja`
(`recipes.fork`, limit `post`), `components/na-podstawie-przepisu.blade.php`.
Testy: `MojaWersjaPrzepisuTest`, `CofniecieMigracjiNieGubiPodpisuWersjiTest`.

### Wycofanie
Wyłączenie funkcji = usunięcie przycisku i trasy; istniejące wersje zostają
z podpisem. Cofnięcie schematu odmawia, dopóki w bazie są wersje — komunikat
migracji mówi, jak zapisać powiązania przed ręcznym cofnięciem.
