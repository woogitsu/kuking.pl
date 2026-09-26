## D-035 · Natywne pole wyboru pliku znika za własnym obszarem

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ten wpis nosi status
> „przyjęta, **niezbudowana**" i opisuje stan zastany zdaniem: „dziś `<input
> type="file">` jest w pełni widoczny wewnątrz dużego obszaru »Dodaj zdjęcie«,
> a komentarz mówi wprost, że zostaje widoczny celowo". Dziś jest dokładnie
> odwrotnie: `resources/views/pages/posts/create.blade.php:73-90` ma pole z
> klasą `visually-hidden pole-zdjecia-input`, a komentarz nad nim brzmi
> „Natywne pole pliku jest tu SCHOWANE DLA OKA (decyzja właściciela D-035)".
> Warunki wykonania postawione w tym wpisie są spełnione: nie `display:none`,
> prawdziwa `<label for>`, `aria-labelledby` i obwódka `:focus-visible`.
> **Decyzja jest wdrożona — nieaktualny jest jej status i akapit „Dziś…".**

Dziś `<input type="file">` jest w pełni widoczny wewnątrz dużego obszaru
„Dodaj zdjęcie", a komentarz w `pages/posts/create.blade.php` mówi wprost, że
zostaje widoczny celowo. Skutek: w środku polskiego formularza siedzi
angielskie „Choose File / No file chosen", którego nie da się przetłumaczyć —
rysuje je przeglądarka.

Właściciel rozstrzygnął, że wolno je schować i klikalna zostaje sama etykieta.

**Strata jest świadoma i zapisana tutaj, żeby nikt jej potem nie odkrył jako
usterki.** Nazwa pliku w natywnym polu była jedynym potwierdzeniem, że wybór
się udał. Po schowaniu pola — **bez JavaScriptu między kliknięciem
a wysłaniem człowiek nie dostaje nic**. Potwierdzenie przychodzi dopiero
z serwera: po wysłaniu widać miniaturę i „Zmień zdjęcie" (to już działa).

**Warunek wykonania:** samo pole musi zostać w drzewie dostępności i pod
klawiaturą (nie `display: none`), a etykieta musi być prawdziwą `<label>`
związaną z polem — inaczej zamiast jednego angielskiego napisu mamy
formularz, którego nie da się wypełnić czytnikiem ekranu.

**Zmiana wymaga:** dowodu z testów z osobami 50+ (#15), że brak potwierdzenia
między kliknięciem a wysłaniem powoduje porzucanie formularza.

📄 `resources/views/pages/posts/create.blade.php` · D-107 (system v3.1) ·
`docs/UX_50_PLUS.md`
