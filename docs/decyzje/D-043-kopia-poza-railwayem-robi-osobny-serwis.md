## D-043 · Kopia poza Railwayem robi osobny serwis Railway, nie scheduler aplikacji

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**,
**wykonanie PILNE** (patrz sprostowanie niżej)

> **SPROSTOWANIE Z TEGO SAMEGO DNIA — CZYTAJ RAZEM Z WPISEM.**
> Pierwsza wersja tego wpisu nazywała zrzut offsite „trzecią warstwą" i pisała,
> że do jego powstania chronią nas Volume Backups i PITR w Railwayu. **To była
> nieprawda.** Właściciel sprawdził panel: **Volume Backups i PITR są dostępne
> wyłącznie w planie Pro**, a Kuking jest na Free i przechodzi na Hobby.
>
> Nie ma więc trzech warstw ani dwóch. **Jest zero.** Zrzut z #193 nie jest
> ostatnią linią obrony — jest jedyną, i przestaje być pracą „po R2".
>
> Sam kierunek decyzji zostaje bez zmian i jest teraz jeszcze mocniejszy:
> osobny serwis, bo w kontenerze aplikacji `proc_open` jest zablokowany;
> nie GitHub Actions, bo poświadczenie do bazy nie ma opuszczać Railwaya.

> **Rozstrzyga sprzeczność w istniejącym planie, nie dokłada nowej warstwy.**
> `docs/infra/INFRA_DECISION.md` §10 zakładał trzy warstwy kopii i trzeciej —
> zrzutu `pg_dump` poza Railwayem — nie da się uruchomić tam, gdzie tamten
> dokument ją umieścił.

Trzecia warstwa mieszka w **osobnym, minimalnym serwisie Railway**
uruchamianym harmonogramem: `pg_dump` → szyfrowanie → R2. Praca opisana
w #193.

**DLACZEGO NIE W KONTENERZE APLIKACJI — TO NIE JEST WYGODA, TYLKO ŚCIANA.**
`docker/php.ini` ma `disable_functions=...,proc_open,...`, a `pg_dump` wołany
z PHP potrzebuje dokładnie `proc_open` (`Symfony\Process`). To nie jest
przeoczenie: `routes/console.php` używa wyłącznie `Schedule::call()`, a jedyne
dwa wystąpienia `Schedule::command()` w tym pliku stoją w komentarzu
zaczynającym się od „UWAGA — NIE UŻYWAMY GO TUTAJ", który podaje tę samą
przyczynę i dopisuje, że na produkcji kończyło się to natychmiastowym błędem. Osłabienia tego hardeningu zabrania
`AGENTS.md`, więc „zrzut na schedulerze" nie jest do naprawienia — jest do
przeniesienia.

**DLACZEGO NIE GITHUB ACTIONS**, mimo że to najtańsze i nie wymaga nowego
serwisu: produkcyjne poświadczenie do bazy musiałoby trafić do sekretów
GitHuba. Powstałaby **druga kopia najwrażliwszego klucza, w innym systemie
niż baza**. Wybrany wariant trzyma poświadczenie wewnątrz Railwaya i łączy
się po sieci wewnętrznej.

**DLACZEGO NIE RĘCZNIE RAZ W TYGODNIU.** Bo zależy od tego, że człowiek
pamięta. Przy jednoosobowej obsłudze to jest obietnica, która łamie się po
trzech tygodniach — a łamie się cicho.

**DLACZEGO W OGÓLE TRZECIA WARSTWA, SKORO RAILWAY ROBI KOPIE SAM.** Bo Volume
Backups i PITR leżą **w tym samym miejscu, co baza**. Utrata konta, pomyłka
w panelu albo awaria po stronie dostawcy zabiera jednocześnie bazę i obie jej
kopie. Warstwa offsite istnieje dokładnie na ten jeden scenariusz.

**CO JEST WAŻNIEJSZE OD SAMEGO ZRZUTU.** Dwie rzeczy, obie w kryteriach #193:
**alarm, gdy zrzut nie powstanie** (backup, który po cichu przestał się robić,
jest gorszy niż jego brak, bo daje fałszywe poczucie bezpieczeństwa), oraz
**jedno prawdziwe odtworzenie z tej warstwy**, wpisane do tabeli w
`KOPIE_I_ODTWORZENIE.md` §5. Zrzut, którego nikt nigdy nie odtworzył, nie
jest kopią — to plik, o którym się zakłada, że jest kopią.

**CO CHRONI NAS DO TEGO CZASU — NIC.** Tak brzmi poprawna odpowiedź po
sprawdzeniu panelu. Volume Backups i PITR to funkcje planu Pro; na Free
i Hobby ich nie ma. Pytania 1 i 2 z `KOPIE_I_ODTWORZENIE.md` §2.3
(„czy backupy są włączone", „od kiedy liczy się okno PITR") są **bezprzedmiotowe
przy obecnym planie** i trzeba je tam przeformułować.

**CO Z TEGO WYNIKA DLA KOLEJNOŚCI.** `docs/OTWARCIE.md` stawia etap 0 (kopia
i ćwiczenie odtworzenia) przed wszystkim innym i to zostaje — ale etap 0 nie
sprowadza się już do przeklikania dwóch przełączników. Wymaga wykonania #193,
a #193 potrzebuje miejsca do lądowania zrzutu, czyli bucketu z #120.
**R2 ma darmowy pułap 10 GB**, więc pieniądze nie są tu przeszkodą — przeszkodą
jest tylko to, że bucket jeszcze nie istnieje.

📄 `docs/infra/INFRA_DECISION.md` §10 · `docs/infra/KOPIE_I_ODTWORZENIE.md`
§2.1, §2.3, §5 · `docs/OTWARCIE.md` etap 0 · `docker/php.ini` ·
`routes/console.php` · #193 · #120 · audyt A6, bramka A6-07
