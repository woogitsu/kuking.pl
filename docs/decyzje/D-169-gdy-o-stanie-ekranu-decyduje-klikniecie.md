## D-169 · Gdy o stanie ekranu decyduje kliknięcie, a nie serwer, warstwę wybiera arkusz

**Data:** 12 września 2026 · PR #420 · issue #343 · Status: **obowiązuje** ·
rozszerzenie D-126

### Decyzja

D-126 każe panelowi formularza znikać tam, gdzie nie ma czego wypełnić, i na
czterech ekranach robi to `@class([...])` — bo tam stan zna **serwer** w chwili
renderowania. `<details>` przełącza się już **po** wyjściu odpowiedzi, więc Blade
nie ma czego wybrać. Wtedy warstwę wybiera selektor stanu w arkuszu
(`details.panel-formularza:not([open])`), i to jest **rozszerzenie D-126, nie
wyjątek od niej**.

Dwa warunki: wartości biorą się w całości z tokenów istniejącej warstwy (żadnych
połowicznych sygnatur), a reguła działa **bez JavaScriptu**.

**Zmierzony koszt** na `/zeszyt`, identycznie przy 390 i 1512 px: obwódka
`rgb(138,122,99)` → `rgb(228,218,203)`, cień → `none`, wcięcie 24 → 20 px,
wysokość 100,5 → 92,5 px. Przy rozwinięciu przycisk przesuwa się w dół o **4 px**;
jego własna wysokość zostaje 50,5 px, powyżej progu 48.

### Drugi werdykt: rolę nadaje MIEJSCE, nie obiekt

Ekran „Komuś wyszło" (`pages/cooked/celebrate.blade.php`) zostaje **sekcją**.
Zapisany argument za kartą brzmiał „warstwa 1 wymienia wykonanie wprost" — to
argument z **obiektu**, a D-128 mówi, że rolę nadaje **miejsce**. Oba miejsca
istnieją w kodzie obok siebie: stały dom wykonania `/ugotowane/{id}` → karta
treści; jednorazowe potwierdzenie `/ugotowane/{id}/wyszlo` → sekcja (drugie
wejście przekierowuje). To ten sam układ, który D-128 rozstrzygnął dla zgłoszeń,
więc **nie wymagał nowej decyzji** — tylko zastosowania istniejącej.

### Liczba z tytułu issue była nieaktualna

`.card` niesie dziś **22** żywe wystąpienia, nie 130: 35 trafień komendą z issue,
24 po odsianiu nazw z myślnikiem (`post-card-head`), 22 po wycięciu komentarzy
Blade. Trzeci wyjątek (puste stany panelu) był już domknięty w `e57b2c9` (#367) —
nieaktualny był **dokument**, nie kod.

### Zostaje otwarte

Czwarte ograniczenie z tej samej sekcji `ROLE_KART.md`: automat dostępności nie
wchodzi na trzy ekrany z ramkami (403 na koncie demo, brak 2FA na koncie demo).
To nie jest wyjątek warstwy, tylko **luka w pokryciu pomiarem** — osobna sprawa.

📄 `resources/css/tokens.css` · `docs/design/ROLE_KART.md` ·
`tests/Feature/WyjatkiRolKartTest.php` · D-053 · D-125 · D-126 · D-128 ·
issue #343
