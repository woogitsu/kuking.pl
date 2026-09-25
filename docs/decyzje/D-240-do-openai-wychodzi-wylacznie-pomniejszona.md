## D-240 — Do OpenAI wychodzi wyłącznie pomniejszona, publiczna treść; awatar nie wychodzi wcale (22 września 2026)

**Data:** 22 września 2026 · **Decyzja właściciela** (pozycja nr 1 listy,
„incydent trwający": `OPENAI_MODERATION_KEY` jest ustawiony na produkcji) ·
Uzupełnia D-055, **uchyla D-061** w części „zdjęcie profilowe idzie do modelu" ·
Zamyka #827 · Status: **obowiązuje**

Decyzja dosłownie: `gpt-openai-granice` + `gpt-moderacja-ai` połączyć ręcznie
w jedną poprawkę; do OpenAI ma wychodzić wyłącznie pomniejszona, publiczna
treść; awatary bez potwierdzonej zgody — nie wysyłać.

### Co było zepsute

1. **Komentarz wychodził bez pytania o rodzica (#827).** `PrzeanalizujTresc`
   sprawdzało u komentarza tylko `status = published`. Komentarz pod wpisem,
   przepisem albo wykonaniem, które w międzyczasie przestały być publiczne
   (prywatne, „dla obserwujących", ukryte, usunięte, konto autora zbanowane
   albo w karencji usunięcia), szedł do OpenAI i stawiał oznaczenie
   w kolejce moderatora. To samo dla śladu „Komentarz usunięty."
   (`body_removed_at`) i komentarza zbanowanej osoby.
2. **Zdjęcie mogło wyjść w pełnym rozmiarze.** `jakoJpeg()` brało
   `wariantDoSerwowania('thumb')`, które przy braku miniatury podstawia
   pierwszy lepszy wariant. Zmierzone w teście: zdjęcie z samym `large`
   wychodziło jako JPEG 1600 × 1200, a `thumb` wskazujący na duży plik —
   2048 × 1536. Wymiarów nikt nie sprawdzał.
3. **Awatar wychodził zawsze** (D-061), bez żadnej zgody.
4. **Uszkodzona odpowiedź udawała czystą ocenę.** `category_scores: []`,
   wyniki-napisy, wyniki spoza 0–1 i odpowiedź bez znanej kategorii
   kończyły się jako „nic nie znaleziono", bez śladu w dzienniku. Brak klucza
   na produkcji był tak samo cichy jak lokalnie.

### Co obowiązuje

- **„Publiczna" = widoczna dla gościa bez konta w chwili wysyłki.**
  `app/Moderacja/GranicaWysylki.php` pyta te same Policy co strona dla gościa
  (`Gate::forUser(null)`, `PostPolicy`/`CommentPolicy`, a ta dalej o rodzica),
  czytając stan świeżo z bazy. Pytana jest przed tekstem, przed **każdym**
  zdjęciem i jeszcze raz przed postawieniem oznaczenia. **Treść „dla
  obserwujących" przestaje być oceniana modelem** — to świadome zawężenie
  wobec D-055, wynikające wprost ze słowa „publiczna" w decyzji.
- **Lokalne sygnały (D-052) mają osobną, szerszą granicę** —
  `GranicaWysylki::pozaAutorem()`: „dla obserwujących" wolno, prywatne nie,
  jak przed tą zmianą. Nowe jest to, że komentarz pyta o aktualny stan
  rodzica (#827) i o ślad usunięcia. Sygnały lokalne nie opuszczają
  serwera, więc zawężanie ich do „publicznej" byłoby zmianą poza zakresem
  tej decyzji. Jedyny skutek uboczny: zapowiedź przepisu „dla
  obserwujących" pyta `PostPolicy` o bramkę przepisu i przez to nie stawia
  lokalnego oznaczenia.
- **Zdjęcie: tylko wariant `thumb`, bez zastępstwa, najwyżej 320 px
  zmierzone z bajtów** — przed dekodowaniem i na gotowym JPEG.
  `OcenaModelem::MAX_BOK` celowo nie jest czytany z konfiguracji wariantów.
  Brak miniatury = zdjęcie pominięte, ostrzeżenie `stage=image_boundary`.
- **Awatar nie wychodzi.** W serwisie nie ma mechanizmu potwierdzonej zgody
  na ocenę zdjęcia profilowego (`dziennik_zgod` zna jeden cel —
  `tygodniowy_digest`), więc nie ma jej nikt. `AvatarSettingsController`
  nie zleca oceny; `PrzeanalizujAwatar` zostaje pustym zadaniem wyłącznie
  dla zleceń czekających w kolejce sprzed wdrożenia. Przywrócenie wymaga
  osobnej decyzji: celu zgody, ekranu udzielania i wycofania, sprawdzenia
  przed każdą wysyłką.
- **Awaria nie udaje „czyste".** `KlientOpenAI` odrzuca odpowiedź pustą,
  z polem nieliczbowym, nieskończonym albo spoza 0–1 i odpowiedź bez znanej
  kategorii — z ostrzeżeniem. Limit czasu przycięty do 1–8 s, połączenie
  3 s (`0` w Guzzle znaczy „bez limitu"). Brak klucza **na produkcji**
  zostawia ostrzeżenie `stage=openai_disabled` przy każdej nieocenionej
  treści; lokalnie i w CI zostaje cichy. Lokalne sygnały działają
  niezależnie od stanu modelu.

### Co wzięto z gałęzi źródłowych, a czego nie

Z `gpt-openai-granice` (7fd9aa8): zasada „dokładnie `thumb`, wymiary
z bajtów, 320 px, bez zamiennika" i przypadki testowe zdjęć; pominięcie
śladu usunięcia komentarza. **Pominięto:** ponowną analizę po edycji
komentarza (#909), transakcyjne `DeleteComment` (#911) i uzupełnianie
otwartych oznaczeń — to inne pozycje, nie granica wysyłki. Pominięto też
decyzję tamtej gałęzi, by awatary wysyłać „wspólną ochroną" — właściciel
rozstrzygnął odwrotnie.

Z `gpt-moderacja-ai` (33ebfd0): walidacja wyników (`poprawneWyniki()`,
`KategorieModeracji::jestZnana()`), przycięcie limitu czasu i zasada
„aktualny stan rodzica przy wykonaniu, a nie przy zleceniu" (#827).
**Pominięto:** `AutomaticAnalysisAccess` w tamtym kształcie (klonował
rodzica i przestawiał mu widoczność na publiczną, żeby przepuścić
„dla obserwujących" — sprzeczne z „wyłącznie publiczna"), rozbicie zdjęć
na osobne zadania `PrzeanalizujZdjecieWpisu` i zapis lokalnego sygnału
przed HTTP (#829/#830) — to niezawodność kolejki, nie granica wysyłki.
Logowanie klasy wyjątku zamiast treści jest już na `main` (#1072,
`ExceptionContext`).

### Czego ta decyzja NIE zmienia

Schematu (brak migracji), progów, alarmu pocztowego, wyglądu kolejki
moderatora. Oznaczenia awatarów sprzed D-240 zostają w kolejce i dają się
rozpatrzyć.

### Dowód

`tests/Feature/GranicaWysylkiDoOpenAiTest.php` — 46 przypadków, wszystkie
przez `Http::fake()`. Na kodzie sprzed tej zmiany **oblewa 35**: 18 rodziców
komentarza, 2 stany komentarza, 2 stany wpisu, zmiana na prywatny w trakcie
oceny, 5 złych miniatur, 2 drogi awatara, brak klucza na produkcji
i 4 uszkodzone odpowiedzi. Pozostałe 11 to kontrole dodatnie (publiczny
rodzic × 3, poprawna miniatura 320 × 240) i zabezpieczenia, które `main`
już miał (prywatny/ukryty/usunięty wpis, ukryty/usunięty komentarz,
nieczytelny plik, HTTP 503) — pilnują, żeby granica nie przepuszczała
za mało i nie blokowała za dużo. Przypadki „dla obserwujących" sprawdzają
obie granice naraz: zero żądań do dostawcy i jedno lokalne oznaczenie.

### Wycofanie

Odwrócić commit. **Przed** odwróceniem wyczyścić `OPENAI_MODERATION_KEY`
na produkcji, bo odwrócenie przywraca znane drogi wysyłki treści
niepublicznej, pełnowymiarowego zdjęcia i awatara. Danych nie trzeba
cofać: zmiana niczego nie zapisuje w bazie.
