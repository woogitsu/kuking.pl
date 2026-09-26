## D-156 · Autor w danych strukturalnych to konto, które treść opublikowało — pochodzenie idzie do `citation`

**Data:** 11 września 2026 · Zgłosił właściciel · PR #403 · Status: **obowiązuje** ·
rozwinięcie D-153

### Co było nieprawdą o danych

Blok JSON-LD na stronie przepisu składał obiekt `Person` **z dwóch różnych
encji**: `name` brał z `recipes.source_person`, a `url` z profilu konta
publikującego. Autor dostawał imię jednej rzeczy i adres innej.

Do tego `@type: Person` deklarował typ encji, **którego nikt nie zna** —
`source_person` jest wolnym tekstem, a właściciel potwierdził, że wpisuje tam
**nazwę grupy na Facebooku**. Widoczny tekst strony uznał to już przy D-153
(wartość idzie dosłownie, bez doklejanego przyimka); dane wypuszczane do
Google kłamały dalej, i to na dwa sposoby naraz.

Widok był przy tym niezgodny z **własną specyfikacją projektu**:
`docs/seo/SEO_TECHNICAL.md` §2.1 od początku mapuje `author.name` i
`author.url` na `profiles.display_name` i `profiles.username` autora po
`recipes.author_id`.

### Decyzja

`author` w każdym JSON-LD opisuje **konto, które treść opublikowało, i tylko
je** — `name` i `url` z tego samego konta.

**Pochodzenie treści nie jest autorem** i idzie do `citation` jako zwykły
`Text`. Wybór sprawdzony na schema.org (V30.0), nie zgadnięty:

| pole | co przyjmuje | ocena |
|---|---|---|
| `citation` | `CreativeWork`, **`Text`**; stoi na `CreativeWork` | **wybrane** |
| `isBasedOn` | `CreativeWork`, `Product`, `URL` — bez `Text` | odrzucone: „od mamy" nie jest adresem |
| `sourceOrganization` | wyłącznie `Organization` | odrzucone: ten sam fałsz z drugiej strony |
| `recipeSource` | **w schema.org nie istnieje** (HTTP 404) | odrzucone: pole ze starego mikroformatu hRecipe |

Dziedziczenie sprawdzone: `Thing > CreativeWork > HowTo > Recipe`, a `citation`
jest wymienione na stronie `Recipe`.

### Zasada ogólna, nie łatka na jedno pole

> **Jeżeli o wartości nie wiemy, jakim typem encji jest, NIE WOLNO jej wkładać
> do pola, które typ wymusza. Lepiej nie wypuścić jej do danych strukturalnych
> wcale niż wypuścić z fałszywym `@type`.**

Ryzykiem jest zaufanie do **wszystkich** danych strukturalnych domeny, nie do
jednego pola.

### Szczegół, który nie jest ozdobą

`?:` przy `citation` jest konieczne: `array_filter` na końcu bloku odrzuca
tylko `null` i `[]`, więc **pusty napis by przeszedł**. Osobna asercja tego
pilnuje.

### Strażnik

`tests/Feature/AutorPrzepisuWDanychStrukturalnychTest.php` — wrogie dane
(nazwa grupy na Facebooku, nazwa własna bez człowieka w środku, wzmianka
o gazecie, „od mamy" i wartość, która sama jest imieniem), **oba stany ekranu**
(publiczny ma blok, prywatny nie ma go wcale) i **rekurencyjny skan po całej
stronie** za fałszywą encją nazwaną, a nie tylko po `author`.

Pięć kontroli ujemnych, każda oblewa z osobna. Dwie z nich są tam z konkretnego
powodu: sabotaż samego `author.name` oblewa **dwa** twierdzenia naraz, więc bez
osobnej kontroli („`citation` poprawne, a obok dochodzi fałszywy `Person`") nie
dałoby się pokazać, że rekurencyjny skan łapie się **sam**. Druga („bramka
`isPublic` zawsze prawdziwa") dowodzi, że test przepisu prywatnego mierzy stan,
w którym blok naprawdę nie istnieje — a nie pustkę z innego powodu (D-099, D-106).

### Zauważone, nietknięte

`source_url` przy `source_type = 'external'` nie idzie do JSON-LD wcale. Tam
`isBasedOn` **byłoby** uczciwe, bo to prawdziwy URL — ale to poszerza zakres
poza naprawiany błąd. Osobno: `docs/DATABASE.md` nie opisuje kolumn
`source_person`, `source_note` ani `source_url` w ogóle.

Bez zmiany schematu — nowa kolumna do tego nie jest potrzebna.

📄 `resources/views/pages/recipes/show.blade.php` · `docs/seo/SEO_TECHNICAL.md` §2.1 ·
`tests/Feature/AutorPrzepisuWDanychStrukturalnychTest.php` · D-153
