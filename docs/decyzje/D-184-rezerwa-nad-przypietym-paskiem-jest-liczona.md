## D-184 · Rezerwa nad przypiętym paskiem jest liczona ze zmierzonej wysokości i ma sufit

**Data:** 12 września 2026 · PR #460 · issue #26 · Status: **obowiązuje**

### Co było nie tak

`scripts/dostepnosc.mjs` meldował „focus częściowo zasłonięty: 27". Dwadzieścia jeden
z tych ostrzeżeń mówiło o `.topbar` i miało jedną przyczynę: przy dolnej belce stało
`scroll-padding-bottom`, a przy górnym pasku nie stało nic. Przewinięcie fokusu w widok,
które przeglądarka robi sama po Tab, liczy się wtedy do krawędzi okna — a na tej
krawędzi siedzi przypięty pasek. Osoba chodząca po serwisie klawiszem Tab przestawała
widzieć, gdzie jest.

### Decyzja

Rezerwa jest liczona od **zmierzonej** wysokości paska, nie z palca: 170,7 px bez
powiększania i 194,6 px przy tekście 140% (320/360/414 px), 75,5 / 84,5 px od 768 px.
Stąd `calc(7rem + 5rem * var(--user-text-scale, 1))` i osobny, niższy stopień od 64rem.

Część stała jest konieczna, bo pasek prawie nie jest typografią: urósł o 14%, gdy tekst
urósł o 40%. Reszta jego wysokości to minima przycisków i wypełnienia w `rem`.

### Rezerwa ma sufit i to on, a nie pasek, jest tu trudny

Gdy kontrolka nie mieści się w pasie między rezerwami, przeglądarka równa ją górą
i **każdy piksel rezerwy spycha jej dół pod belkę dolną**. Zmierzone przy 320 px
i tekście 140%: sufit **244,5 px**, a wariant 14rem × skala (313,6 px) dokładał nowe
ostrzeżenie zamiast zbijać stare. Więcej rezerwy nie jest tu lepiej.

Rezerwa znika dokładnie na obu progach, na których pasek przestaje być przypięty
(D-107) — nad odpiętym paskiem byłaby czystą stratą ekranu.

### Zmierzone

Tym samym skryptem, baza `kuking_a11y_c`, axe 42/42, układ 47/47: „focus częściowo
zasłonięty" **27 → 6**, pozostałe liczniki bez zmian. Kontrola ujemna: samo zdjęcie
reguły `scroll-padding-top` przywraca 27.

Sześć ostrzeżeń zostaje i nie da się ich zdjąć przewijaniem — to kontrolki **wyższe niż
okno** przy czcionce przeglądarki 200% (2087, 1070 i 783 px przy oknie 740 px).

📄 `resources/css/app.css` · `RezerwaNadPaskiemTest` · `scripts/dostepnosc.mjs` · D-107
