## D-161 · Adres strony źródłowej idzie do `isBasedOn` — bo tu typ encji jest znany

**Data:** 12 września 2026 · PR #413 · Status: **obowiązuje** · domknięcie D-156

### Co zostało otwarte

D-156 rozstrzygnęło, że `author` w JSON-LD opisuje wyłącznie konto publikujące,
a pochodzenie przepisu (`recipes.source_person`) idzie do `citation` jako zwykły
`Text`. Ta sama decyzja zostawiła jawnie drugą połowę sprawy:

> `source_url` przy `source_type = 'external'` nie idzie do JSON-LD wcale.
> Tam `isBasedOn` **byłoby** uczciwe, bo to prawdziwy URL.

Przepis przepisany z cudzej strony miał jej adres w bazie, pokazywał go
człowiekowi na ekranie — a dane strukturalne o nim milczały.

### Decyzja

Blok `Recipe` dostaje `isBasedOn` z `recipes.source_url`, ale **tylko przy
`source_type = 'external'`**.

**To nie jest wyjątek od zasady z D-156, tylko jej druga strona.** Zasada mówi:
wartości, o której nie wiemy, jakim typem encji jest, nie wolno wkładać do pola,
które typ wymusza. `source_person` jest wolnym tekstem („od mamy", nazwa grupy
na Facebooku) i dlatego poszedł do `citation`. `source_url` jest adresem strony
i niczym innym — obie drogi zapisu walidują go regułą `url`, a widok pokazuje go
człowiekowi jako link. **Typ jest znany, więc pole jest uczciwe.**

Wybór sprawdzony u źródła (schema.org V30.0, 19 marca 2026), nie zgadnięty:

| pole | co przyjmuje | ocena |
|---|---|---|
| `isBasedOn` | `CreativeWork`, `Product`, **`URL`**; stoi na `CreativeWork` | **wybrane** |
| `isBasedOnUrl` | to samo, ale schema.org oznacza je „SupersededBy: `isBasedOn`" | odrzucone: zastąpione |
| `citation` | `CreativeWork`, `Text` | zajęte przez D-156 na `source_person` — nietknięte |

Dziedziczenie sprawdzone: `Thing > CreativeWork > HowTo > Recipe`, a `isBasedOn`
jest wymienione na stronie `Recipe`. `URL` w schema.org to **goły napis**, więc
nie deklarujemy żadnego `@type`: adres nie udaje ani osoby, ani organizacji.

### Dwa szczegóły, które nie są ozdobą

1. **Bramka `source_type` jest konieczna.** Formularz nie ukrywa pola adresu przy
   pozostałych trzech odpowiedziach, więc adres bywa wpisany także przy przepisie
   własnym czy rodzinnym — a wtedy widoczna treść strony nie pokazuje go wcale.
   Google traktuje niezgodność danych strukturalnych z widoczną treścią jako
   naruszenie wytycznych (`docs/seo/SEO_TECHNICAL.md` §2), więc warunek w JSON-LD
   jest **dokładnie ten sam** co przy widocznym zdaniu „Przepis pochodzi ze strony".
2. **`?:` przed `null`**, tak samo jak przy `citation`: `array_filter` na końcu
   bloku odrzuca `null` i `[]`, ale **pusty napis by przepuścił**.

### Zauważone, nietknięte

Widoczny link w `show.blade.php` wstawia `source_url` do `href` bez sprawdzania
schematu, a walidacja `url` przepuszcza też schematy inne niż `http`/`https`.
To sprawa bezpieczeństwa widoku, nie danych strukturalnych — osobno. Dalej
aktualne z D-156: `docs/DATABASE.md` nie opisuje kolumn `source_person`,
`source_note` ani `source_url`.

Bez zmiany schematu.

📄 `resources/views/pages/recipes/show.blade.php` ·
`tests/Feature/ZrodloZewnetrzneWDanychStrukturalnychTest.php` ·
`docs/seo/SEO_TECHNICAL.md` §2 · D-156 · D-153 · D-099 · D-106
