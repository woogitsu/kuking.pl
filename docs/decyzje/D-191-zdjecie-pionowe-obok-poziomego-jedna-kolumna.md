## D-191 · Zdjęcie pionowe obok poziomego: jedna kolumna na wąskim ekranie, kwadrat w karuzeli

**Data:** 12 września 2026 · PR #455 · issue #431 · Status: **obowiązuje**

### Zgłoszenie

„Jedno zdjęcie pionowe, drugie poziome, przez to jest rozjazd i bierze całą wysokość
najwyższego zdjęcia nawet jak nie jest wyświetlane."

### Dane demo nie miały czego pokazać — i to jest połowa tego zgłoszenia

`DemoSeeder` tworzy każde zdjęcie jako 1600×1200, a te zdjęcia nie mają wygenerowanych
wariantów, więc podstawia się znak serwisu o `viewBox="0 0 64 64"`, czyli **kwadrat**.
Pomiar na samym demo mierzyłby galerię, w której zgłoszonej usterki **nie da się
zrobić**, i meldował „w porządku". Dlatego `scripts/galeria-orientacje.mjs` sam dokłada
wpisy z prawdziwymi plikami 1200×1600 i 1600×900 i **zatrzymuje się z błędem**, jeśli na
mierzonej stronie nie stanęły obok siebie zdjęcie pionowe i poziome.

### Decyzja

`.photo-grid` poniżej 30rem schodzi do **jednej kolumny** — ten sam próg i ten sam
argument co przy kolażu: przy 320 px kolumna miała 142 px, a zdjęcie poziome mieściło
się w niej na 80 px wysokości. Martwe pole nie zostaje zasłonięte, tylko przestaje
istnieć. Dochodzi `align-items: start`, bo rozciągał się **odnośnik** „powiększ
zdjęcie": kliknięcie w puste miejsce pod zdjęciem otwierało powiększenie.

Slajdy karuzeli dostają pole o proporcji **1/1** z `object-fit: contain`. Wysokości
taśmy zależnej od widocznego slajdu nie da się zrobić bez JavaScriptu, a karuzela ma
działać bez skryptu (AGENTS.md §5) — to nie jest opcja odrzucona, tylko nieistniejąca.

### Cena, wprost

Zdjęcie pionowe przy 320 px ma teraz 214,8 × 286 px zamiast 286 × 381,3 px. Pole 4/3
zbijało martwe piksele mocniej, ale zabierało zdjęciu pionowemu **44%** wysokości —
a to najczęstszy kształt tego, co ktoś robi telefonem nad garnkiem. Kwadrat zabiera 25%
i nie wyróżnia żadnej orientacji.

Zostające w karuzeli ~125 px to co innego niż 220 px sprzed poprawki: tamte brały się
z sąsiada, którego nie było widać, te są dwoma równymi pasami nad i pod zdjęciem —
**ramą, nie dziurą**.

### Zmierzone: martwe piksele pod zdjęciem poziomym

`.photo-grid`: 320 px 109,5 → 0,0 · 360 px 124,9 → 0,0 · 390 px 136,4 → 0,0 ·
414 px 145,7 → 0,0 (przy czcionce 200% analogicznie, wszystkie → 0,0).
Karuzela: 320 px 220,5 → 125,1 · 414 px 292,9 → 167,1.

`min-height: 0` na polu slajdu nie jest ozdobą: bez niego proporcja działa tylko na
zdjęciach poziomych, czyli poprawka poprawiałaby połowę przypadków i **wyglądała
w pomiarze prawie jak poprawka**.

📄 `resources/css/app.css` · `scripts/galeria-orientacje.mjs` ·
`GaleriaMieszanychOrientacjiTest` · D-092
