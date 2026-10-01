## D-138 · Panel moderacji bierze całą szerokość; reguła 45rem broni CZYTANIA, a nie tabeli

**Data:** 11 września 2026 · Issue #365 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„dla admina i moderatora jest wąskie, przez co informacje trzeba przewijać, zrób
dla admina i moderatora 100% szerokości".

### Przyczyna miała DWIE warstwy i to jest sedno tego wpisu

Pierwsza warstwa to sufit ramy. Druga to `max-width: var(--container-content)`
na `.app-main`. **Samo podniesienie sufitu zostawiłoby tabelę przy 720 px** —
czyli zmiana wyglądałaby na zrobioną, a zgłoszenie zostałoby otwarte.

Zmierzone na `/admin/uzytkownicy`: przy 1920 px kontener 686 → 1566 px przy
tabeli 1358 px — **przewijanie znika**. Przy 1280 px 686 → 926 px — **dalej
przewija**, bo tabela potrzebuje 1358 px. Zgłoszenie jest więc zamknięte
od 1600 px w górę, nie wszędzie, i tak to nazywam.

### Dlaczego wolno było zdjąć 45rem akurat tutaj

Reguła 45rem istnieje dla **wiersza tekstu** — oko gubi początek następnego
wiersza przy zbyt długiej linii. Tabela kont to siedem kolumn porównywanych
w poziomie; na zwężeniu nie zyskuje nic, a traci wszystko.

Przy 320 px nic się nie zmienia: tabela dalej jeździ w **swoim** kontenerze.
WCAG 2.2 AA 1.4.10 broni przed przewijaniem CAŁEJ strony, nie przed przewijaniem
tabeli, która z natury jest szeroka.

### Czego tu NIE zrobiono, mimo że brzmiało jak część tego samego

Sufit ramy (1424 px) **nie został podniesiony**, a przepisanie go na procenty
jest zmianą zapisu, nie pikseli — zmierzone 0 px różnicy. Powód: trzecia kolumna
`.app-body` to sztywne `var(--container-rail)`. Przy szerszej ramie nadwyżka
wpadłaby w kolumnę środkową, którą `.app-main` i tak przycina — pustka
przeniosłaby się z prawej krawędzi na środek strony. Gorzej, nie lepiej.
