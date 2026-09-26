## D-277 — Start: obserwowane tagi razem z obserwowanymi osobami (#1808, #1781, zmienia D-021, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781, kryteria #1808) · Status: **obowiązuje**

### Problem

`FeedController::aktualneZrodloFeedu()` wybierał JEDNO źródło: obserwowani →
tagi → Odkrywanie. Kto obserwował choć jedną aktywną osobę, nie widział na
Starcie nigdy wpisów z obserwowanych tagów — „Obserwuj ten tag” było dla
niego bez skutku, a ekran ustawień obiecywał tagi tylko „gdy nie ma wpisów od
obserwowanych osób”.

### Decyzja

`FollowingFeed` zwraca sumę chronologiczną:

- wpisy obserwowanych osób i własne (publiczne i „tylko dla obserwujących”),
- publiczne wpisy z obserwowanych, **aktywnych** tagów — z `widoczneDla()`
  (blokady w obie strony), `tylkoOdAktywnychAutorow()` i bramką przepisu
  (`zWidocznymPrzepisem`); obserwowanie tagu nie otwiera wpisów „tylko dla
  obserwujących” ani prywatnych;
- bez duplikatów (`whereHas` = `EXISTS`, jeden wpis raz).

Karta, która przyszła **wyłącznie** przez tag, ma podpis „Z tagu: {nazwa}”
z odnośnikiem do strony tagu. Wpis obserwowanej osoby albo własny podpisu nie
dostaje — przyszedł od osoby. `isEmptyFor()` i `paginate()` dzielą jedno
zapytanie źródeł (`FollowingFeed::zrodla()`); własne wpisy dalej nie liczą się
jako treść. Źródło „tagi” przestaje być osobnym stopniem Startu: zostały
„obserwowani” i „odkrywanie”, a stare odnośniki `zrodlo=tagi` wracają do
pierwszej strony. Klasa `TagFeed` usunięta; jej testy widoczności
(`FeedTagow*Test`) sprawdzają teraz połączoną listę.

**Słowo na ekranie: „tag”, nie „temat”.** Issue pisze „Z tematu: {tag}”, ale
na ekranie obowiązuje jedno słowo — decyzja właściciela z 11 września 2026,
pilnowana przez `JednoSlowoNaTagiTest`. Zmiana na „temat” wymaga cofnięcia
tamtej decyzji (pytanie do właściciela w raporcie).

To reguła z listy D-275 („jawne polecenia widza — obserwuj — z listą do
cofnięcia”: `/ustawienia/tagi`) i kolejność po czasie. Nic nie jest układane
po reakcjach.

Zmiana względem wcześniejszego zachowania, zamierzona: tag ukryty albo
scalony przez moderację nie prowadzi już wpisów na Start (dawny `TagFeed`
tego nie sprawdzał).

### Zdanie do strony „Jak dobieramy wpisy” (#1811)

> Na Starcie widzisz wpisy osób i tagów, które obserwujesz, od najnowszych.
> Przy wpisie, który trafił do Ciebie przez tag, jest napisane „Z tagu: …”.
> Tagi zmienisz w ustawieniach, osoby — na ich profilach.

### Wycofanie

Bez migracji. Przywrócić `TagFeed` i trzy stopnie w `FeedController`
z historii gita (przed tym wpisem), teksty `/pomoc`, `/o-kuking`,
`/ustawienia/tagi` i nagłówek Startu.

📄 `app/Domain/Feed/FollowingFeed.php` · `app/Http/Controllers/FeedController.php` ·
`resources/views/components/post-card.blade.php` · `tests/Feature/StartOsobyITagiRazemTest.php` · D-021
