## D-114 · Obietnica z miarą wymaga pomiaru — inaczej jej nie piszemy

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/`
· Status: **obowiązuje**

### Zasada

„Zajmuje minutę", „to najczęściej czytana część", „teraz idzie najszybciej" to
zdania o czasie, liczbie albo cudzym zachowaniu. **Wchodzą do interfejsu tylko
wtedy, gdy w repozytorium stoi mechanizm albo pomiar, który je pokrywa.**

### Dlaczego to nie jest czepianie się

Człowiek, któremu obiecano minutę, a dodawanie zdjęcia zajęło pięć — bo zasięg
był słaby — nie myśli „ładny copywriting". Myśli, że serwis nie mówi prawdy.
Przy grupie 50+, która i tak podchodzi do nowego serwisu ostrożnie, **jedna
niesprawdzalna obietnica kosztuje więcej niż dziesięć nudnych zdań.**

Żadnej z sześciu obietnic „minuty" nikt nie zmierzył. Nie zostały uznane za
fałszywe — zostały uznane za **niepokryte**, a to wystarczy, żeby ich nie pisać.

### Czego ta zasada NIE obejmuje

Liczb, które serwis naprawdę liczy: czasu gotowania z przepisu, okna na
poprawienie komentarza, terminu odwołania. Te mają pokrycie w kodzie i wolno
je pisać wprost.

### Gdzie to stoi

Pozycja listy kontrolnej w `docs/brand/COPY_STYLE.md` §7, pilnowana przez
`tests/Feature/TekstyMowiaPrawdeTest.php` (dziesięć miejsc, A1–A10). Każdy test
zawężony do elementu, bo słowo „minut" pada w serwisie także legalnie.

### Co musiałoby się stać, żeby to zmienić

Ktoś zmierzyłby któryś z tych czasów na prawdziwych kontach i prawdziwych
łączach. Wtedy obietnica wraca — z liczbą, która ma pokrycie.
