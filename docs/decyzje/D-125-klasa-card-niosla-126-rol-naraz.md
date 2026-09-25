## D-125 · Klasa `.card` niosła 126 ról naraz — sześć warstw powierzchni zamiast jednej

**Data:** 11 września 2026 · Status: **obowiązuje** · Inwentarz: `docs/design/ROLE_KART.md`

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „hierarchia bierze
> się z **uniesienia i mocy obwódki**, nie z koloru… warstwy 1, 3 i 4 różni
> wyłącznie cień" przestało opisywać stan faktyczny po porcie marki.
> `resources/css/marka-rama.css:238-241` nadaje globalnie `[data-marka]
> :is(.card, .panel-formularza, .empty-state)` ten sam `border-radius: 24px` i
> ten sam `box-shadow`, więc karta treści (warstwa 1) i panel formularza
> (warstwa 2) różnią się dziś już tylko obwódką — uniesienie, czyli połowa
> nośnika hierarchii z cytatu, między nimi zniknęło. Sześć klas i tokeny
> `--warstwa-*` nadal istnieją (`resources/css/tokens.css:1204-1270`). Pomiar
> opisany w tym wpisie (`scripts/warstwy-pomiar.mjs`, wyniki „3/1/3 → 3/2/2")
> nie jest wywoływany ani w `scripts/check.sh`, ani w
> `.github/workflows/ci.yml`, więc liczby stąd nie są odtwarzalne w bramce i
> nikt nie zauważy ich zmiany.

### Co było nie tak

Jedno tło, jedna obwódka, jeden promień i jeden cień były jednocześnie kartą wpisu,
sekcją strony, blokiem prawej szyny, panelem formularza, ramką z wyjaśnieniem
i kaflem, w który się klika.

Widać to było na `/napisz-do-nas`: karta „Chodzi o czyjś wpis?", formularz i karta
„Co się stanie dalej" wyglądały identycznie, choć tylko **jedna** z tych trzech
rzeczy czegokolwiek od człowieka chciała.

### Decyzja

Sześć warstw: karta treści · panel formularza · sekcja strony · blok szyny · ramka
pomocnicza · kafel akcji. Hierarchia bierze się z **uniesienia i mocy obwódki**,
nie z koloru. Warstwy 1, 3 i 4 różni wyłącznie cień i tak ma być.

**Obwódka mocna tam, gdzie czegoś od człowieka chcemy.** Panel formularza i kafel
akcji dostają `--color-border-strong` — tę samą, którą mają pola formularza. To
jedyne dwie warstwy, które czegoś WYMAGAJĄ, więc niosą obwódkę kontrolki, a nie
linię dekoracyjną: obwódka kontrolki musi mieć 3:1 do tła (WCAG 1.4.11),
a `--color-border` tego progu nie ma.

### Miara, która pokazuje płaski ekran

`scripts/warstwy-pomiar.mjs` podaje **powierzchnie / sygnatury / największą grupę
jednakowych**. Gdy pierwsza liczba równa się trzeciej, ekran nie ma hierarchii.
`/napisz-do-nas` szło z 3/1/3 na 3/2/2, `/logowanie` z 2/1/2 na 2/2/1.

### Gdy wszystko ma tę samą rangę, nic jej nie ma

Najbardziej traci na tym ktoś, kto czyta wolniej albo powiększa tekst — bo
skanowanie wzrokiem przestaje być skrótem.

---


> **Uwaga (20.09.2026, pomiar do D-223).** Podział `.card` na sześć warstw
> powierzchni obowiązuje jako rozstrzygnięcie, ale zmierzone w przeglądarce
> deklaracje samej `.card` z warstwy `components` (m.in. tło, obramowanie
> i promień) są **całkowicie przykryte** przez `[data-marka] .post-card`
> i pokrewne z arkuszy `marka-*.css` spoza warstw. O wyglądzie karty decyduje
> dziś warstwa marki, nie te reguły.
>
> Nic tu nie usuwamy ani nie zmieniamy statusu — to jest adnotacja o tym, GDZIE
> wartość naprawdę obowiązuje. Poprawka wpisana w regułę `.card` z `components`
> nie dojdzie do nikogo. Szczegóły i strażnik: D-223.
