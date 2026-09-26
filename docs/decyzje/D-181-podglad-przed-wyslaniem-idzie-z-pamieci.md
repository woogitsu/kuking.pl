## D-181 · Podgląd przed wysłaniem idzie z pamięci przeglądarki, a jego układ mieszka w arkuszu

**Data:** 12 września 2026 · PR #451 · issue #430 · Status: **obowiązuje**

### Kontekst

Pytanie właściciela: „Nie można zrobić coś by z cache przeglądarki pokazywało chwilowo
a nie z serwera?".

Odpowiedź ma dwie części. **Cache przeglądarki tego zdjęcia nie ma** — cache trzyma to,
co przeglądarka **pobrała**, a zdjęcie poszło w drugą stronę, w ciele żądania POST.
To, co istnieje, to **plik wybrany przez człowieka**, żyjący w pamięci strony do
przejścia dalej. I tego właśnie używa `resources/js/app.js` (`URL.createObjectURL`) —
**od dawna, jeszcze przed tym pytaniem**.

### Dlaczego to nie zastępuje pracy po stronie serwera

Publikacja wpisu to POST → przekierowanie → **nowy dokument**, a adres `blob:`
poprzedniego dokumentu jest wtedy martwy — czyli znika dokładnie w tym momencie,
w którym zaczyna się problem z D-180. Przetrwanie wymagałoby IndexedDB, JavaScriptu na
dwóch ekranach i drugiego źródła prawdy o tym, jak wygląda to zdjęcie — a pomagałoby
**wyłącznie osobie wgrywającej, na tym jednym urządzeniu**.

**Obie połowy zostają i nie kolidują:** przed wysłaniem — z pamięci przeglądarki, po
opublikowaniu — wariant z serwera.

### Co było zepsute po stronie podglądu

Siatka była wpisana **w skrypcie** jako `repeat(auto-fill, minmax(120px, 1fr))`, więc
**jedno** zdjęcie dostawało jedną kolumnę z dwóch. Zmierzone: **150 px z 308 px** przy
oknie 390 px, czyli 49%. Człowiek, który właśnie wybrał zdjęcie swojego obiadu, widział
znaczek mniejszy niż połowa ekranu. To ta sama usterka, którą właściciel zgłosił przy
bloku zastępczym w kolażu (#432), tylko o jeden ekran wcześniej.

### Decyzja

Skrypt mówi **ile** jest zdjęć (`data-ile`), a **układ jest w arkuszu**, na tokenach.
Dopóki `gridTemplateColumns`, `borderRadius` i `marginTop` były przypisywane przez
`element.style`, istniały **dwa źródła prawdy** o wyglądzie tego bloku — a to
w skrypcie wygrywało z arkuszem i nie znało żadnego tokenu.

### Zmierzone (obrazek / szerokość pojemnika)

320 px: 238/238 (100%) → bez zmian · 360 px: 135/278 (49%) → **278/278 (100%)** ·
390 px: 150/308 (49%) → **308/308 (100%)** · 414 px: 162/332 (49%) → **332/332 (100%)**.
Źródło `blob:`, **63–103 ms** od wyboru pliku, zero naruszeń CSP (`img-src` ma `blob:`),
zero wyjazdu w bok.

### Czego pilnuje test, skoro wszystko dzieje się w przeglądarce

PHPUnit widzi HTML **sprzed** wykonania skryptu, więc o tym ekranie nie powie nic.
Pilnuje trzech rzeczy, które cicho cofają tę poprawkę: reguły dla jednego zdjęcia,
tego że skrypt mówi arkuszowi liczbę zdjęć zamiast wpisywać style, i tego że **bez
JavaScriptu nie zostaje na ekranie pusty `aria-live`**. Pomiar strony przeglądarkowej
robi `scripts/podglad-przed-wyslaniem.mjs`.

📄 `resources/js/app.js` · `resources/css/ekran-dodawania.css` ·
`scripts/podglad-przed-wyslaniem.mjs` · `PodgladWybranegoZdjeciaTest` · D-180 · D-035
