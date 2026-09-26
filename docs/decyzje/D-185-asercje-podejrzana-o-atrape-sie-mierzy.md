## D-185 · Asercję podejrzaną o atrapę się mierzy, a nie przepisuje

**Data:** 12 września 2026 · PR #460 · Status: **obowiązuje**

### Kontekst

Pułapka 1 i 1b z `docs/PULAPKI_TESTOW.md`: `assertSee('X')` na całej odpowiedzi łapie X
z tytułu karty przeglądarki, z `<meta>`, ze stopki i z menu — czyli przechodzi, choć
mierzonej rzeczy na ekranie nie ma.

### Decyzja

Asercja **podejrzana** nie jest asercją **złą**. Każdą poddajemy pomiarowi: usunięcie
mierzonej rzeczy z widoku, przebieg testu, a po naprawie ten sam sabotaż jeszcze raz.
Dopiero czerwień albo jej brak rozstrzyga.

Sabotowane widoki wracają z kopii spoza repozytorium (pułapka 8), nie przez
`git checkout` — i w diffie zostaje wyłącznie `tests/`.

### Dlaczego to nie jest formalność

Poddane pomiarowi **22** asercje. Atrapami okazało się **17**, dobrych było **5**:
„Wrócimy dziś" na 503, „Bezpieczeństwo" i „Twoje dane" w spisie ustawień oraz
„Obserwuj" i „Zablokuj" w główce profilu. Gdyby przepisać wszystkie 22 „na wszelki
wypadek", pięć zmian byłoby ruchem bez powodu, a to w tym pliku nie do odróżnienia od
ruchu z powodu.

Naprawy używają wzorców z tabeli w §1, bez wymyślania piątego. Asercje „czegoś nie ma"
zostają na całym dokumencie — tam szersze spojrzenie jest **ostrożniejsze**, nie słabsze.

📄 `docs/PULAPKI_TESTOW.md` · `StronyBleduPoPolskuTest` · `UstawieniaNawigacjaTest` ·
`SpisTematowTest` · D-132
