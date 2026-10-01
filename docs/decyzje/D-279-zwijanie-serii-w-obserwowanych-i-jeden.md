## D-279 — Zwijanie serii w Obserwowanych i jeden wpis na autora w tygodniowym liście (#1812, #1781, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781, kryteria #1812) · Status: **obowiązuje**

Dwie reguły z listy D-275, obie po czasie, żadna po reakcjach:

1. **Zwijanie serii (Obserwowani).** Więcej niż dwa kolejne wpisy tej samej
   osoby na stronie — albo, od D-277, tego samego tagu (karty „Z tagu: …”) —
   stoją jako dwa wpisy i `<details>` „{nazwa}: jeszcze N wpisów — Pokaż”.
   Kolejność dokładnie ta z `FollowingFeed`, żaden wpis nie znika, bez JS.
   W obrębie jednej strony (seria rozcięta przez „Pokaż więcej” zaczyna się
   od nowa). Nazwa dosłownie, przed dwukropkiem; tag jako „Tag {nazwa}”.
   `App\Domain\Feed\SerieWpisow`.
2. **Tygodniowy list: najwyżej jeden wpis na autora** w sekcji obserwowanych —
   najnowszy. Równość autorów: gospodarz publikujący codziennie nie zajmuje
   całej sekcji. Dwa okna `row_number()` w `ZbierzTresciDigestu` (na autora,
   potem na adresata), oba po `published_at`.

### Zdanie do strony „Jak dobieramy wpisy” (#1811)

> Gdy ktoś opublikuje kilka wpisów pod rząd, na Starcie widzisz dwa, a resztę
> po naciśnięciu „Pokaż” — nic nie znika i kolejność się nie zmienia.
> W tygodniowym e-mailu od każdej obserwowanej osoby jest jeden, najnowszy wpis.

### Wycofanie

Bez migracji: zdjąć grupowanie w `pages/home.blade.php` i wewnętrzne okno
w `ZbierzTresciDigestu::wpisyObserwowanych()`.

📄 `app/Domain/Feed/SerieWpisow.php` · `app/Domain/Digest/ZbierzTresciDigestu.php` ·
`tests/Feature/ZwijanieSeriiWObserwowanychTest.php` · D-275 · D-277
