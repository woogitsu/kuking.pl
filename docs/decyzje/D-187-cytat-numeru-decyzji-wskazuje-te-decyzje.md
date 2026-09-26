## D-187 · Cytat numeru decyzji wskazuje tę decyzję, a brakującego numeru się nie wymyśla

**Data:** 12 września 2026 · PR #458 · Status: **obowiązuje**

### Kontekst

Audyt znalazł w `HealthController` cytat „D-042" przy sprawdzeniu kolejki, a pod tym
numerem stoi decyzja o czymś zupełnie innym. `NumeryDecyzjiMajaWpisyTest` pilnował
tylko tego, że numer **ma wpis** — nie tego, że wpis mówi o tym samym, co kod obok.

To jest klasa błędu, nie jeden przypadek: numer decyzji czyta się jak uzasadnienie
i **zatrzymuje szukanie**. Zły numer jest gorszy niż brak numeru, bo wygląda na
sprawdzony.

### Zmierzone

Przejrzane **3701** wystąpień `D-NNN` w **651** plikach, każde sprawdzone wobec treści
wpisu o tym numerze. Błędnych: **11 wystąpień w 6 plikach**, czyli cztery pomyłki.

### Decyzja

Gdy cytowana decyzja istnieje pod innym numerem — przepinamy odnośnik. Gdy decyzji
w dzienniku **nie ma** — numeru nie wymyślamy: cytat znika, a zostaje odesłanie do
dokumentu, który tę zasadę naprawdę niesie.

Tak rozstrzygnięte zostało „D-004" przy zdaniu „ugotowanie jest ważniejsze niż lajk"
na stronie powitalnej i w `GLOS_MARKI.md`. D-004 dotyczy wyszukiwarki na PostgreSQL,
a decyzji o hierarchii „ugotowałem" ponad lajkiem w dzienniku po prostu nie było —
patrz D-194, który tę lukę zamyka.

📄 `app/Http/Controllers/HealthController.php` · `NumeryDecyzjiMajaWpisyTest` ·
`docs/infra/MONITORING_BLEDOW.md` · D-029 · D-041 · D-057 · D-194
