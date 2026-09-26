## D-262 — Panel moderacji: napisy pomocnicze poniżej 18 px, świadomy wyjątek od AGENTS.md §5 (audyt B1, znalezisko 7, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela · Status: **obowiązuje**

Audyt `docs/audyt/2026-09-25-B1.md`, znalezisko 7, znalazł w panelu
moderacji (widoczny wyłącznie dla moderatorów) cztery miejsca z tekstem
poniżej 18 px z `AGENTS.md` §5, przy czym jedno z nich powoływało się na
D-051 — decyzję, która swój zakres ogranicza wyraźnie do dwóch elementów
stopki („ZAKRES WYJĄTKU — TYLKO TE DWA ELEMENTY") i nie obejmuje niczego
w panelu moderacji. Właściciel dostał znalezisko do decyzji: podnieść te
cztery miejsca do 18 px (rekomendacja audytu) albo zapisać dla nich osobny,
nazwany wyjątek. **Wybrał świadomie drugi wariant** — moderator pracuje
w tym panelu godzinami, gęstość informacji na ekranie ma dla niego wartość,
a odbiorcą tych konkretnych napisów nigdy nie jest osoba 50+ z reszty
serwisu, tylko moderator zalogowany do narzędzia wewnętrznego.

### DLACZEGO TO JEST WYJĄTEK, NIE ZMIANA REGUŁY

Reguła z `AGENTS.md` §5 zostaje bez zmian wszędzie indziej. *(Pierwotnie:
„`AGENTS.md` §5 zostaje dokładnie taki, jaki jest”. Decyzją właściciela
z 25 września 2026 — po audycie `docs/audyt/2026-09-25-PO-FALI.md`,
pkt 8–9 — §5 wymienia D-262 z nazwy jako drugi nazwany wyjątek obok D-051,
z listą czterech selektorów, żeby agent czytający tylko `AGENTS.md` nie
„naprawiał” tych miejsc. Treść reguły się nie zmieniła.)* Minimum
18 px dla samodzielnego tekstu nadal obowiązuje na każdym ekranie, który
widzi członek/członkini serwisu — w tym w PUBLICZNEJ części panelu (np.
w widokach dla odwołujących się). Wyjątek dotyczy WYŁĄCZNIE napisów
pomocniczych w panelu moderacji, nie przycisków: `.btn` i inne cele dotyku
w tym panelu mają nadal ≥ 48 px, bez zmian.

### ZAKRES WYJĄTKU — WYŁĄCZNIE TE SELEKTORY

- `.tabela-kont .drobne` — `resources/css/ekran-uzytkownikow.css` —
  drugi, cichy wiersz w komórce tabeli kont („@nazwa", przyczyna, termin);
  `--text-meta` (15 px).
- `.stan-konta` — `resources/css/ekran-uzytkownikow.css` — plakietka stanu
  konta („Zawieszone", „Zablokowane"); `--text-meta` (15 px). Kolor nadal
  nigdy nie jest jedynym nośnikiem informacji — słowo w środku zostaje.
- `.sygnal-podglad-cytat` — `resources/css/app.css` — cytat cudzej treści
  w podglądzie sygnału, o jedno kliknięcie od pełnego rozmiaru;
  `--text-help` (16 px). Komentarz przy tej regule błędnie powoływał się
  na D-051 — poprawiony na odwołanie do tego wpisu.
- `.side-nav-moderacja-naglowek` — `resources/css/app.css` — samodzielny
  nagłówek sekcji „Moderacja" w bocznej nawigacji, wersalikami;
  `--text-help` (16 px).

Nigdzie indziej. W szczególności: publiczne widoki odwołań i zgłoszeń,
ekran „Czytelność", i każdy inny ekran panelu spoza tej listy — tam
minimum 18 px obowiązuje bez wyjątku.

### CO Z TYM ZROBIONO W KODZIE

W `resources/css/app.css` zamieniono błędne powołanie na D-051 przy
`.sygnal-podglad-cytat` na powołanie na D-262 i dopisano odwołanie do
D-262 przy `.side-nav-moderacja-naglowek`. W `resources/css/ekran-uzytkownikow.css`
dopisano odwołanie do D-262 przy `.tabela-kont .drobne` i `.stan-konta`.
Strażnik `tests/Feature/MinimalnyRozmiarTekstuTest.php` pilnuje ZAMKNIĘTEJ
listy `SAMODZIELNE_ETYKIETY`, w której żaden z tych czterech selektorów nie
stał ani wcześniej, ani teraz — nie jest to strażnik z otwartą listą
wyjątków, więc nie ma tu nic do dopisania; gdyby ktoś kiedyś przepisał go
na skaner całego CSS, te cztery selektory muszą wtedy dostać jawny wpis na
liście wyjątków z odwołaniem do D-262, a nie zgłoszenie jako regresja.

### Wycofanie
Podnieść cztery selektory z listy wyżej do `--text-body` (18 px) i usunąć
ten wpis. Nic w bazie ani w migracjach się nie zmienia.
