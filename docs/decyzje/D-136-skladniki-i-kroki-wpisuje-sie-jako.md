## D-136 · Składniki i kroki wpisuje się jako TEKST w jednym polu; baza dalej trzyma wiersze

**Data:** 11 września 2026 · Issue #364 · Status: **obowiązuje**

### Decyzja

Formularz przyjmuje `skladniki_tekst` i `przygotowanie_tekst` — dwa zwykłe pola
wielowierszowe. `App\Domain\Recipes\TekstNaWiersze` rozbija je na wiersze:
składniki po liniach, kroki po pustej linii.

**Schemat bazy się nie zmienia.** `recipe_ingredients` i `recipe_steps` zostają
takie, jakie były. To jest zmiana wyłącznie po stronie wejścia — dlatego nie ma
tu migracji ani wpisu w `docs/DATABASE.md`.

### Dlaczego nie zostawić tablicy pól

Lista składników jako osobne pola z jednostką, ilością, grupą i uwagą to przy
dziesięciu składnikach czterdzieści kontrolek. Człowiek, który ma przepis
przepisany na kartce albo w mailu, chce go **wkleić**. Rozbicie na wiersze
robi za niego to, co i tak zrobiłby ręcznie, tylko czterdzieści razy.

### Czego pilnują testy

Że wklejona lista daje **tyle wierszy, ile niepustych linii** (a nie „jakieś"),
że pusta linia rozdziela kroki, i że cztery składniki po dopisaniu szczegółów
dalej są czterema **i w tej samej kolejności**. Ten ostatni jest tu najważniejszy:
konwersja tekst → wiersze → tekst → wiersze to miejsce, w którym kolejność gubi
się po cichu i nikt tego nie zauważa aż do skargi.
