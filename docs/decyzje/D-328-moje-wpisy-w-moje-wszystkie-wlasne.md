## D-328 — „Moje wpisy” w „Moje”: wszystkie własne wpisy autora w jednym miejscu (26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela

**Problem.** „Moje” (`/zeszyt`, „Twój zeszyt”) miało zapisane cudze przepisy
i wpisy oraz planer, ale nie miało wpisów samej osoby. Własne wpisy autor
widział tylko w profilu, a profil pokazuje wyłącznie OPUBLIKOWANE
(`ProfileController::postsFor()` → `published()`). Szkic i wpis ukryty przez
moderację nie były więc widoczne dla autora nigdzie, a wpisy „tylko dla mnie”
i „tylko dla obserwujących” były przemieszane z publicznymi bez jednego
miejsca, w którym widać, kto co widzi.

**Decyzja.** W „Moje” jest przycisk „Moje wpisy” obok „Planer tygodnia”,
prowadzący do `/zeszyt/moje-wpisy` (`collections.own-posts`). Lista:

- pokazuje WSZYSTKIE wpisy zalogowanej osoby — publiczne, dla obserwujących,
  tylko dla mnie, szkice i ukryte przez moderację;
- jest chronologiczna, od najnowszego; szkic (bez `published_at`) stoi według
  chwili założenia, remis rozstrzyga `id`;
- przy każdym wpisie mówi SŁOWAMI, kto go widzi („Publiczny”, „Dla
  obserwujących”, „Tylko dla mnie”) i w jakim jest stanie („Opublikowany”,
  „Szkic — jeszcze nieopublikowany”, „Ukryty przez moderację”);
- zapowiedź przepisu (wpis bez własnej treści, #368) bierze widoczność
  z przepisu — tak jak karta wpisu, bo jej `visibility = 'public'` nie jest
  wyborem autora;
- ma paginację wzorcem serwisu (`x-show-more`: bez skryptu „Następna strona”,
  ze skryptem „Pokaż więcej”), po 20 wpisów.

**Autoryzacja: trasa bez identyfikatora.** Zapytanie zawsze zawęża do
`author_id` zalogowanej osoby; w adresie nie ma czego podmienić. Nie ma więc
Policy do napisania — nie ma cudzej listy, do której dałoby się wejść.
Wejście w pojedynczy wpis dalej idzie przez `PostPolicy::view()`, która
szkic i wpis ukryty wpuszcza autora.

**Czego na liście nie ma.** Wpisów miękko usuniętych (w tym zdjętych przez
moderację, `removed`) — wiązanie trasy wpisu ich nie znajduje, więc karta
prowadziłaby do 404; tę samą granicę ma profil. Pytań przy wyłączonym dziale
pytań (`enabledKinds()`) — ta sama flaga odmawia wejścia na stronę pytania.
Od #2870 nie ma też opublikowanej zapowiedzi przepisu bez własnego tekstu
i zdjęcia, gdy przepis usunięto lub autor wpisu nie może go odczytać. Ten sam
warunek działa przed szukaniem, liczeniem i paginacją. Wpis z własną treścią
(także zdjęciem jeszcze w obróbce) oraz własny szkic lub wpis ukryty zostają
na liście. Po odzyskaniu przepisu jako prywatnego szkicu jego zapowiedź
wraca wyłącznie autorowi, zgodnie z `PostPolicy::view()`.

**Wydajność.** Relacje karty (`media`, `recipe:id,title,slug,visibility`)
ładowane z góry; `MojeWpisyTest::test_liczba_zapytan_nie_rosnie_z_liczba_wpisow`
porównuje liczbę zapytań przy 2 i 12 wpisach (kontrola ujemna: bez `recipe`
w `with()` 6 → 11 zapytań). Bez nowej migracji — sortowanie idzie po
istniejącym zakresie `author_id`.

**Bez JavaScriptu.** Lista to zwykłe odnośniki i przyciski ≥ 48 px.

### Wycofanie
Bez danych do cofania: trasa, kontroler (`MojeWpisyController`), klasa
`App\Domain\Posts\MojeWpisy`, widok i przycisk w „Moje”. Zdjęcie ich
przywraca stan sprzed zmiany; odnośniki do `collections.own-posts` (strona
tagu, D-307) trzeba wtedy przepiąć z powrotem na profil.

📄 `routes/web.php`, `app/Http/Controllers/MojeWpisyController.php`,
`app/Domain/Posts/MojeWpisy.php`, `resources/views/pages/collections/moje-wpisy.blade.php`,
`docs/FLOWS_AND_SCREENS.md`, `tests/Feature/MojeWpisyTest.php`

Wycofanie samej poprawki #2870 nie zmienia danych: przywraca wcześniejsze
zapytanie `MojeWpisy`, ale może znów pokazać martwe karty zapowiedzi.
