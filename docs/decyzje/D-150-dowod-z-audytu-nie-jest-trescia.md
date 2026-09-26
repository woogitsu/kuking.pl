## D-150 · Dowód z audytu nie jest treścią dokumentu prawnego

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/` ·
PR #395 · Status: **obowiązuje** · rozwinięcie D-149

### Co stało w polityce prywatności

> „Sprawdziliśmy to **9 września 2026** na prawdziwym liście doręczonym do
> skrzynki, czytając jego **surowe źródło**, a nie wierząc na słowo."

Zdanie prawdziwe, konkretne, z datą — i w dokumencie prawnym **nie na miejscu**.
Zostało usunięte; zostało to, co dostawca rejestruje, czym to robi i że my tego
nie odczytujemy.

### Dlaczego to nie jest ukrywanie dowodu

Dowód i dokument mają dwóch różnych czytelników. **Dowód z audytu jest
adresowany do nas** — mówi, że sprawdzenie zostało wykonane, i chroni nas przed
powtórzeniem pracy. **Dokument prawny jest adresowany do czytelnika** i ma mu
powiedzieć, co się dzieje z jego danymi. Wpisany do dokumentu dowód robi trzy
szkody naraz:

1. czytelnik nie ma czym go zweryfikować, więc nie dostaje informacji, tylko
   zapewnienie;
2. **ma krótszy okres przydatności niż dokument** — data „9 września" starzeje
   się, a akapit o danych nie; dokument zaczyna po cichu mówić nieprawdę o samym
   sobie;
3. sugeruje, że reszta dokumentu sprawdzona nie była, bo przy niej takiego
   zdania nie ma.

Punkt 2 nie jest teoretyczny: **dwie z trzech usuniętych not o lukach były już
nieaktualne** — dane spółki weszły do regulaminu 8 września, EmailLabs do
polityki 10 września, a noty stały dalej.

### Gdzie dowód mieszka zamiast tego

W opisie Pull Requesta, w `docs/research/`, w komentarzu przy kodzie i w teście.
**Test jest lepszym dowodem niż zdanie w dokumencie**, bo starzeje się na
czerwono, a zdanie starzeje się cicho.

### Przy okazji, poprawka merytoryczna z tego samego przeglądu

Regulamin §8 mówił „przez pierwsze 24 godziny nie można potwierdzić własnego
rozstrzygnięcia", a kod (`ResolveAppeal::sprawdzKarencje()`,
`kuking.moderation.appeal_self_uphold_hours`) liczy 24 h **od pierwotnej
decyzji**, nie od złożenia odwołania. Zdanie mówi teraz to, co robi kod. Klasa
usterki jest ta sama: dokument mówił o czymś, czego nie sprawdził przy kodzie.

📄 `resources/legal/` · `docs/research/audyt-copy-2026-09-11/` · D-149 · D-140
