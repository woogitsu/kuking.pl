## D-163 · Dział pytań nazywa się „Poradźcie", a osobnego miejsca na rozmowy nie o gotowaniu nie budujemy

**Data:** 12 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
dotyczy issue #372

> **Adnotacja z 20 września 2026 (audyt rejestru).** Nazwa i ekran istnieją
> (`resources/views/pages/questions/index.blade.php`), ale dwie rzeczy
> zatwierdzone w tym wpisie nie powstały. Po pierwsze pozycja **w menu**:
> wiersz „MENU: Poradźcie" nie ma pokrycia — `questions.index` nie występuje w
> `resources/views/components/layout.blade.php` ani w szynie bocznej, ani w
> dolnym pasku. Po drugie strażnik, który ten wpis sam stawia jako warunek
> zatwierdzenia nazwy: „asercja: nagłówek »Poradźcie« **i** zdanie
> wyjaśniające na tej samej stronie — powstaje razem z ekranem". Ekran jest,
> strażnika nie ma. Jedyna asercja z tą frazą
> (`tests/Feature/QuestionIndexTest.php:25`) dotyczy `/odkryj`, nie
> `/pytania`, i jest `assertSee` po CAŁEJ odpowiedzi — a ta sama fraza siedzi
> w atrybucie `description` renderowanym jako `<meta name="description">`.
> Usunięcie `<h1>` i akapitu z `/pytania` nie zaświeciłoby dziś na czerwono
> ani razu. To wzorzec atrapy nazwany wprost w D-164 i D-185.

### Nazwa

Dział, w którym można poprosić innych o radę, nazywa się **„Poradźcie"** —
w menu i w nagłówku strony. Brzmienie ekranu zatwierdzone co do słowa:

```
MENU:  Poradźcie

STRONA:
  # Poradźcie
  Ktoś to już robił i chętnie powie, jak.
  Pytanie do innych jest w porządku.

  [Zapytaj innych]

  Czeka na odpowiedź (3)
```

### Ryzyko przedstawione właścicielowi i przez niego przyjęte

„Poradźcie" to **czasownik w trybie rozkazującym**, więc w menu — bez kontekstu,
obok rzeczowników „Start", „Szukaj", „Dodaj" — część osób może nie wiedzieć, czy
to **ona ma radzić**, czy **jej poradzą**. To jest odstępstwo od testu czasownika
z `docs/brand/BRAND_EXTENDED.md` §3, świadome, i ma precedens: **„Ugotowałem"**
też jest formą czasownikową użytą jako nazwa własna funkcji.

Dlatego zdanie pod nagłówkiem — **„Ktoś to już robił i chętnie powie, jak."** —
**nie jest ozdobą, tylko warunkiem z D-147**: charakter wolno tam, gdzie obok
stoi zdanie, które tłumaczy. Jeśli ktoś usunie to zdanie przy porządkowaniu
tekstów, nazwa przestaje spełniać warunek, na którym została zatwierdzona.
Strażnik na to (asercja: nagłówek „Poradźcie" **i** zdanie wyjaśniające na tej
samej stronie) powstaje razem z ekranem — dziś ekranu nie ma.

Adres strony i nazwa parametru zostają techniczne (`/pytania`,
`bez-odpowiedzi`) — patrz otwarte pytania w #372.

### Brak działu off-topic

**Osobnego miejsca na rozmowy nie o gotowaniu nie budujemy.**

Powód: kącik o niczym trzeba moderować **tak samo** jak resztę serwisu — te same
zgłoszenia, te same decyzje, ten sam czas człowieka — a nie przybliża nikogo do
ugotowania czegokolwiek. Przy jednej osobie prowadzącej moderację to koszt
realny, nie teoretyczny.

To nie jest „nigdy": **wracamy do tego, jeśli ludzie sami zaczną tak pisać** —
czyli jeśli w pytaniach i komentarzach pojawi się rozmowa niekulinarna, której
nie da się nigdzie odłożyć. Wtedy będzie to odpowiedź na zachowanie, a nie zakład.

Konsekwencja dla pracy nad #372: zabieramy z forum **pytanie i odpowiedź**, nie
strukturę „forum → działy → wątki → off-topic".

📄 issue #372 · issue #370 · `docs/research/tematy-i-pytania-2026-09-11/` §3.5 ·
`docs/brand/BRAND_EXTENDED.md` §1.1, §3 · D-147 · D-159
