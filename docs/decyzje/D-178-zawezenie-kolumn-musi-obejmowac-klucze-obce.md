## D-178 · Zawężenie kolumn musi obejmować klucze obce relacji dociąganych dalej

**Data:** 12 września 2026 · PR #449 · issue #447 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela: „klikam na bigos z cukinii, przekierowuje mnie na to okno
gdzie jest info Ula bigos napisz komentarz itp a **nie ma przepisu ani zdjęcia**".

Odtworzone na produkcji: strona wpisu zwracała 200, a w całym `<main>` było **zero
obrazków** — przy zdjęciu widocznym na tej samej karcie w strumieniu. Dwa ekrany
rysowały ten sam komponent i **nie zgadzały się, czy wpis ma zdjęcie**.

### Przyczyna

`PostController::show()` doładowywał `recipe:id,title,slug`. Zawężenie gubiło dwie
kolumny i **żadna nie zgłaszała się błędem**:

* **`hero_media_id`** — bez niej relacja `heroMedia` nie ma po czym trafić w wiersz
  i zwraca `null`. Wpis z przepisu nie ma własnych zdjęć z założenia (#368), więc
  tracił jedyne, jakie miał.
* **`visibility`** — bez niej karta bierze widoczność **wpisu**, a ta dla wpisu
  z przepisu jest zawsze `public` (bramką jest przepis). Strona pisała więc autorowi
  **„· publicznie"** także pod przepisem, który widzą wyłącznie jego obserwujący.

### Reguła

**Zawężenie kolumn (`with('rel:a,b,c')`, `load(...)`) musi obejmować klucze obce
relacji, które będą dociągane dalej.** Brak klucza nie jest błędem — jest **cichym
`null`**. To jest klasa błędu, nie jeden przypadek: nie daje żadnego sygnału ani
w logu, ani w testach, które nie patrzą na obecność treści.

### Druga decyzja tego samego PR-a: wpis bez własnej treści nie ma własnej strony

`Post::jestSamymPrzepisem()` i `Post::adresTresci()`. Karta prowadzi wprost do
przepisu, a `posts.show` takiego wpisu przekierowuje.

* **Przekierowanie, a nie 404 i nie skasowana trasa:** adres wpisu mógł już ktoś komuś
  wysłać („Podziel się"). Ma działać dalej, tylko prowadzić tam, gdzie jest danie.
* **`url()` zostaje kanonicznym adresem wpisu** i nie wolno go zamienić
  z `adresTresci()` — kanoniczny idzie do udostępniania, `<link rel="canonical">`
  i danych strukturalnych.
* **Wpis z komentarzem nie jest „samym przepisem"** i zostaje przy swojej stronie: ma
  już coś własnego — rozmowę ludzi. Gdyby warunek o to nie pytał, przekierowanie
  zostawiłoby ją pod adresem, do którego nic nie prowadzi.

### Zauważone przy okazji

W `OdstepPodZdjeciemNaKarcieWpisuTest` stał komentarz **opisujący tę usterkę jako stan
normalny**: „`PostController::show()` doładowuje przepis bez kolumny `hero_media_id`,
więc na stronie samego wpisu zdjęcia przepisu NIE MA". Ktoś to zauważył, obszedł
w teście i pojechał dalej. Komentarz przepisany.

📄 `app/Http/Controllers/PostController.php` · `app/Models/Post.php` ·
`WpisZPrzepisuProwadziDoPrzepisuTest` · issue #368
