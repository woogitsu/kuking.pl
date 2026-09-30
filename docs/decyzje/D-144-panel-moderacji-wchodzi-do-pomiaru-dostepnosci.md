## D-144 · Panel moderacji wchodzi do pomiaru dostępności Z DANYMI; pusty stan przechodzi każdy audyt

**Data:** 11 września 2026 · Issue #294 · Status: **obowiązuje** · rozwinięcie D-106

### Co przeoczyliśmy przez pół roku

Lista ekranów w `scripts/dostepnosc.mjs` miała `/zgloszenia` — czyli ekran
**zgłaszającego**. Adres wygląda podobnie, a to inna strona, inny układ i inna
rola. Przez to **najbardziej osobna warstwa układu w tym serwisie** — tryb panelu
(`.side-nav[data-tryb-panelu]`, własne reguły poniżej 64rem, własny pasek dolny
`.bottom-nav-panel`) — nie była mierzona **nigdy**: ani na przepełnienie
w poziomie, ani przy powiększonej czcionce.

### I drugi raz to samo, już wewnątrz poprawki

Zmierzone 11 września na świeżo wysianej bazie demo, przy 900 px:

| ekran | co stało na ekranie | węzłów w `<main>` |
|---|---|---|
| `/admin/uzytkownicy` | tabela czterech kont | 126 |
| `/admin/zgloszenia` | „Nic tu nie ma" | 19 |
| `/admin/sygnaly` | „Nic tu nie ma" | 18 |

Dwie z trzech kolejek panelu były mierzone jako **pusty stan**. A cała rzecz,
przez którą panel w ogóle wszedł do tego pomiaru — karta sprawy z formularzem
decyzji (`choice-grid`, dwa zestawy pól wyboru, pole terminu, lista podstaw
prawnych) i karta grupy automatu z paskiem podglądów — nie była na ekranie
ani razu.

`DemoSeeder` nie tworzy ani jednego zgłoszenia (sprawdzone: `grep -n 'Report::'`,
zero wyników).

### Decyzja

Automat **zakłada dane** przed pomiarem panelu i **twardo sprawdza, że wszedł**:
jeśli `/admin/uzytkownicy` nie odpowie `200` pod tym właśnie adresem, automat
przerywa z błędem mówiącym, że ekrany panelu nie zostałyby zmierzone. Cichego
„zmierzono ekran logowania" tu nie ma.

Sesja moderatora jest **osobną funkcją**, nie parametrem zwykłego logowania:
za formularzem stoją jeszcze dwa kroki, których nie ma żaden inny ekran w tym
automacie — włączenie weryfikacji dwuetapowej i twarde sprawdzenie, że panel
naprawdę się otworzył. 2FA moderatora **nie jest obchodzone** na potrzeby pomiaru.

### Punkt 900 px, a nie cała macierz

Między 768 a 1280 px była dziura, w którą wpada cała klasa urządzeń liczących
układ INACZEJ niż oba brzegi: telefon składany rozłożony (zgłoszenie przyszło
z Galaxy Fold), tablet postawiony poziomo i okno przeglądarki na pół ekranu
laptopa. Wszystkie trzy są szersze niż telefon, a mimo to poniżej progu 64rem —
czyli dostawały układ telefonu na szerokim ekranie, którego nikt nigdy nie
zobaczył w pomiarze.

Dołożony został **jeden** punkt, nie cała macierz: każdy punkt kosztuje czas
każdego przebiegu CI, a 900 px pokrywa te trzy przypadki naraz.
