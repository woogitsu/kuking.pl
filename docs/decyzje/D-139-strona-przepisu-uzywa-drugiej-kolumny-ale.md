## D-139 · Strona przepisu używa drugiej kolumny, ale NIE przez `<x-slot:rail>`

**Data:** 11 września 2026 · Issue #365 · Status: **obowiązuje**

### Sprawa

Prawa strona ekranu przepisu marnowała się pusta, bo strona nie miała szyny
w ogóle — a brak szyny zbijał ramę z 1424 px do ~1040 px. Naturalnym odruchem
było przenieść panel akcji do `<x-slot:rail>`.

### Dlaczego tego nie zrobiono

Slot renderuje się w kodzie **za** całym `<main>`. Na telefonie szyna ląduje pod
treścią — czyli „Ugotowałem", główna akcja produktu, zeszłoby pod składniki
i komentarze. `EkranPrzepisuWedlugKituTest` wymaga, żeby „Ugotowałem" stało
w kodzie PRZED „Składnikami", i ma rację.

### Rozwiązanie

`<main>` zajmuje obie kolumny, a panel przechodzi do drugiej siatką
`.przepis-uklad`. Efekt dla oka jest ten z issue, mechanizm inny.

Zmierzone, dane demo: gość przy 1920 px — wysokość strony 3872 → 3607 px,
treść 720 → 1104 px; zalogowany przy 1280 px — 4634 → 4303 px, treść 576 → 960 px;
„Ugotowałem" przy 1920 px przesuwa się z y=538 na **y=316**, i dalej jest pierwsze
w pasku akcji oraz pierwsze w kodzie.

Przy 200% czcionki wraca jedna kolumna, panel pod zdjęciem nad składnikami.

### Reguła ogólna

**Efekt wizualny nie jest powodem, żeby użyć konkretnego mechanizmu.** Slot
i siatka dają tu ten sam obraz na szerokim ekranie i różny na telefonie —
a telefon jest tym, na którym ta grupa czyta.
