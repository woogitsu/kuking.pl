# Pierwszy zrzut produkcji i jego odtworzenie — karta właściciela (#594, #193)

Stan instrukcji: 24 września 2026. Wykonuje: **właściciel, na własnym
komputerze (WSL Ubuntu)**. Czas: ok. 45 minut, w tym 10 minut protokołu.

Ta karta prowadzi przez **jedno** ćwiczenie: ręczny zrzut produkcyjnej bazy,
odtworzenie go do świeżej, odizolowanej bazy i zapisanie wyniku. Uzasadnienia
i szczegóły każdego kroku są w [`KOPIE_I_ODTWORZENIE.md`](KOPIE_I_ODTWORZENIE.md)
§7.1 i §8.1–8.2 — tu są wyłącznie komendy, oczekiwany wynik i miejsce na zapis.
Próba na danych syntetycznych jest w [`DR594_RUNBOOK_LOKALNY.md`](DR594_RUNBOOK_LOKALNY.md).

**Co ta karta zamyka w #594:** „wykonano `pg_dump`”, „zrzut odtworzono do
osobnej bazy”, „dane zweryfikowano”, „zapisano wynik, RPO i RTO”, „wiadomo,
czy Volume Backups i PITR są aktywne”.
**Czego nie zamyka:** automatycznej kopii offsite (#193, kroki 3–8 w §8.3–8.5),
bajtów zdjęć w R2 (#617), ponownego zastosowania usunięć kont po odtworzeniu
starszej kopii (komentarz z 21.09 w #594). Tych pól nie odhaczaj.

## Zasady na cały czas ćwiczenia

- Kopia **tylko czyta** produkcję (`pg_dump` otwiera migawkę, nie blokuje
  zapisów). Odtworzenie idzie **wyłącznie** na świeży klaster z kroku 3,
  na Twoim komputerze. Tunel do produkcji zamykasz **przed** odtworzeniem.
- Hasła i adresy baz podajesz przez `read -s`, nie w wierszu polecenia:
  argument widać w `ps` i zostaje w historii powłoki. Żaden krok nie wypisuje
  hasła — skrypty maskują je jako `***`.
- Wszystko powstaje w katalogu `$DR` (domyślnie `dr594` w katalogu domowym, prawa 700), **poza repozytorium**.
  Skrypt kopii odmawia zapisu w repozytorium (kod 20), a zrzut i `.meta`
  dostają prawa 600 niezależnie od Twojego `umask`.
- Kopia z tej karty jest **od razu szyfrowana**. Para kluczy to i tak krok 5
  z §8; zrobiona teraz sprawia, że na dysku nie leży ani chwili jawny komplet
  danych osobowych, a ćwiczenie sprawdza przy okazji odczyt klucza prywatnego.
- Każda porażka skryptu kończy się kodem różnym od 0 i mówi, co zrobić.
  Tabela kodów: [`DR594_RUNBOOK_LOKALNY.md`](DR594_RUNBOOK_LOKALNY.md) §6.
  **Nie omijaj kontroli, która odmówiła** — zapisz kod w protokole.

## Krok 0 — przygotowanie (5 min)

W Ubuntu (WSL), w katalogu repozytorium:

```bash
git fetch origin && git switch --detach <SHA_WDROŻONY_NA_PRODUKCJI>
```

SHA odczytasz w panelu Railway → `kuking.pl` → Deployments → aktywne
wdrożenie. **Ćwiczenie uruchamiaj z tego samego commita, który stoi na
produkcji**, i nie z commita starszego niż ten, który wprowadził tę kartę:
kontrola migracji porównuje odtworzoną bazę z katalogiem `database/migrations`
w checkoucie. Inny commit = fałszywe „migracje czekają” (kod 64).

```bash
set -o pipefail                 # `echo "kod=$?"` po `| tee` pokaże kod skryptu, nie tee
export DR=~/dr594
mkdir -p "$DR/nosnik" "$DR/tmp" && chmod 700 "$DR" "$DR/nosnik" "$DR/tmp"
export TMPDIR="$DR/tmp"        # odszyfrowany zrzut żyje tylko tu i ginie po próbie
pg_dump --version; pg_restore --version; psql --version; openssl version
```

Oczekuj klienta PostgreSQL **18** (nie starszego niż serwer produkcji).
Starszy `pg_dump` odmawia pracy — wtedy najpierw doinstaluj klienta 18.

## Krok 1 — stan warstw Railwaya (5 min, sam odczyt)

Panel Railway → projekt → środowisko `production` → `Postgres` → zakładka
**Backups**. Nic nie klikaj, nie zmieniaj planu. Zapisz w protokole
(pole **P1**) dosłownie, co widzisz: czy Volume Backups są włączone, jaki
harmonogram, czy PITR jest dostępne i aktywne, albo treść komunikatu
o niedostępności. Wynik z 17.09.2026 był: *„only available for customers on
the Pro plan”* — nie przepisuj go, sprawdź dziś.

## Krok 2 — para kluczy (5 min, raz na zawsze)

Dokładnie według §7.1 — w katalogu `$DR`, nie w repozytorium:

```bash
cd "$DR"
openssl req -x509 -newkey rsa:4096 -sha256 -days 7300 -nodes \
  -keyout kuking-kopie-PRYWATNY.pem -out kuking-kopie-publiczny.pem \
  -subj "/CN=Kuking kopia bazy"
chmod 600 kuking-kopie-PRYWATNY.pem
head -1 kuking-kopie-publiczny.pem     # musi być -----BEGIN CERTIFICATE-----
openssl x509 -in kuking-kopie-publiczny.pem -noout -fingerprint -sha256
cd -
```

Klucz prywatny **teraz** zapisz w menedżerze haseł i na nośniku offline
w innym miejscu (§7.1). Jego utrata unieważnia wszystkie przyszłe kopie.

## Krok 3 — zrzut produkcji (10 min)

**Okno A** — tunel, zostaw otwarte (szczegóły: §8.1 a):

```bash
railway link                             # projekt kuking, środowisko production
railway connect postgres --tunnel-only   # wypisze host, port, użytkownika i hasło
```

**Okno B** — ten sam `DR`/`TMPDIR` co w kroku 0 (ustaw je ponownie, jeśli
to nowe okno). Adres wpisujesz niewidocznie:

```bash
read -r -s -p 'DSN z tunelu (postgresql://postgres:HASŁO@localhost:PORT/railway): ' KOPIA_ZRODLO; echo
export KOPIA_ZRODLO
time ./scripts/kopia-lokalna.sh --katalog "$DR/nosnik" \
  --klucz-publiczny "$DR/kuking-kopie-publiczny.pem" 2>&1 | tee "$DR/kopia.log"; echo "kod=$?"
unset KOPIA_ZRODLO
```

Oczekuj kodu 0 i kolejno: `✓ zrzut: kuking-<znacznik>.dump, … B`,
`✓ w zrzucie <N> tabel z danymi`, `✓ zaszyfrowane: kuking-<znacznik>.dump.cms`,
`✓ metadane: kuking-<znacznik>.meta`, `KOPIA POWSTAŁA`. Od 24.09.2026 skrypt
czyta zrzut **do końca** przed szyfrowaniem: obcięty plik jest kasowany
(kod 41), a nie zostawiany jako kopia.

**Zamknij teraz tunel w oknie A (Ctrl+C).** Od tej chwili żaden dalszy krok
nie ma drogi do produkcji. `<znacznik>` z nazwy pliku to chwila zrzutu (UTC) —
przepisz go do pola **P3**.

## Krok 4 — świeży, odizolowany serwer odtworzenia (5 min)

Osobny klaster z hasłem, tylko na `127.0.0.1`, w `$DR`. To dokładnie ten
wariant przećwiczono 24.09.2026 (§ „Przećwiczenie” niżej):

```bash
( umask 077; openssl rand -hex 24 >"$DR/haslo-celu" )
/usr/lib/postgresql/18/bin/initdb -D "$DR/klaster" -U postgres \
  --pwfile="$DR/haslo-celu" -A scram-sha-256 >/dev/null
/usr/lib/postgresql/18/bin/pg_ctl -D "$DR/klaster" -l "$DR/klaster.log" \
  -o "-p 55442 -k $DR -c listen_addresses=127.0.0.1" start
pg_isready -h 127.0.0.1 -p 55442        # accepting connections
export PROBA_SERWER="postgresql://postgres:$(cat "$DR/haslo-celu")@127.0.0.1:55442/postgres"
```

Port 55442 wybrano, żeby nie trafić w 5432 ani 55439 z runbooka lokalnego.
Gdy jest zajęty, weź inny wolny — w obu miejscach tej karty.

## Krok 5 — odtworzenie z weryfikacją (10 min)

Pierwsze uruchomienie **ma odmówić** kodem 24 i wypisać odcisk instancji —
skrypt nie zna tego klastra:

```bash
Z="$(ls -1 "$DR"/nosnik/kuking-*.dump.cms | tail -1)"
./scripts/proba-odtworzenia.sh --zrzut "$Z" --klucz "$DR/kuking-kopie-PRYWATNY.pem"
# → ODMOWA/PORAŻKA (kod 24) … --instancja <16 znaków>
```

Potwierdź odcisk **innym kanałem** — przez gniazdo w `$DR`, nie przez port:

```bash
PGPASSWORD="$(cat "$DR/haslo-celu")" psql -h "$DR" -p 55442 -U postgres -d postgres -Atc \
  'SELECT system_identifier FROM pg_control_system()' | tr -d '[:space:]' | sha256sum | cut -c1-16
```

Tylko jeśli oba odciski są **identyczne**, uruchom właściwy przebieg:

```bash
time ./scripts/proba-odtworzenia.sh --zrzut "$Z" --klucz "$DR/kuking-kopie-PRYWATNY.pem" \
  --instancja <ODCISK> 2>&1 | tee "$DR/odtworzenie.log"; echo "kod=$?"
```

Oczekuj kodu 0, `PRÓBA ODTWORZENIA ZALICZONA` i w podsumowaniu:

| Wiersz podsumowania | Co musi stać | Pole |
|---|---|---|
| `skrót z .meta:` | `zgodny z kuking-<znacznik>.meta (sha256_jawnego)`. **`NIE SPRAWDZONY` = ćwiczenie niezaliczone** — brakuje `.meta` obok `.cms` | P6 |
| `tabel w archiwum` / `tabel w bazie` | ta sama liczba | P7 |
| `✓ wierszy w "users"` … `"cooked_events"` (w logu wyżej) | liczby rzędu tych, które znasz z panelu admina | P8 |
| `migracji wykonanych: N, czekających: 0` | 0 czekających | P9 |
| `✓ wyzwalacze`, `✓ ograniczenia`, cztery sondy + kontrola dodatnia | wszystkie obecne | P10 |
| `czas odszyfrowania`, `czas odtworzenia`, `real` z `time` | liczby | P4, P5 |

Relacje sprawdza sam `pg_restore` (każdy klucz obcy jest odtwarzany
i walidowany na danych) oraz liczba kluczy obcych w `✓ ograniczenia`.
Media: w bazie są **metadane** zdjęć, nie ich bajty — to jest #617.

## Krok 6 — kontrola ujemna na Twojej kopii (5 min)

Dowód, że na Twoim komputerze uszkodzona kopia zostanie **odrzucona**, zanim
powstanie jakakolwiek baza. Działa na kopii pliku, oryginał zostaje nietknięty:

```bash
mkdir -m 700 "$DR/kontrola"
cp "$Z" "$DR/kontrola/"; cp "${Z%.dump.cms}.meta" "$DR/kontrola/"
U="$DR/kontrola/$(basename "$Z")"
printf '\377\377\377\377\377\377\377\377' | dd of="$U" bs=1 seek=$(( $(stat -c %s "$U") / 2 )) conv=notrunc status=none
./scripts/proba-odtworzenia.sh --zrzut "$U" --klucz "$DR/kuking-kopie-PRYWATNY.pem" \
  --instancja <ODCISK> --baza proba_odtworzenia_kontrola --zostaw; echo "kod=$?"
PGPASSWORD="$(cat "$DR/haslo-celu")" psql -h "$DR" -p 55442 -U postgres -d postgres -Atc \
  "SELECT count(*) FROM pg_database WHERE datname LIKE 'proba%'"
```

Oczekuj `kod=44` (`Zrzut NIE ZGADZA SIĘ ze skrótem z pliku .meta`) i `0`
z drugiego polecenia — mimo `--zostaw` baza nie powstała. Inny wynik zapisz
w polu **P11** i nie zaliczaj ćwiczenia.

## Krok 7 — sprzątanie (3 min)

```bash
/usr/lib/postgresql/18/bin/pg_ctl -D "$DR/klaster" stop
rm -rf "$DR/klaster" "$DR/klaster.log" "$DR/kontrola" "$DR/haslo-celu" "$DR/tmp"
unset PROBA_SERWER
ls -la "$DR/nosnik"         # zostaje: kuking-<znacznik>.dump.cms + .meta, prawa 600
```

- `.dump.cms` i `.meta` przenieś **razem** na nośnik, który zamykasz — bez
  `.meta` nie ma kontroli skrótu (krok 5, pole P6).
- `kuking-kopie-PRYWATNY.pem` usuń z dysku, gdy potwierdzisz obie jego kopie
  (menedżer haseł + nośnik offline). Część publiczna może zostać.
- Logi `kopia.log` i `odtworzenie.log` nie zawierają haseł ani wierszy danych —
  wolno je zachować przy protokole. Nie dodawaj do repozytorium plików
  `.dump`, `.cms`, `.pem` ani `.env`.
- Wróć w repozytorium na gałąź: `git switch main`.

## Protokół — do wypełnienia

Skopiuj blok do komentarza w #594 albo do
`docs/infra/evidence/dr594/PROTOKOL_<RRRR-MM-DD>.md`, a wiersz skrócony do
tabeli [`KOPIE_I_ODTWORZENIE.md` §5](KOPIE_I_ODTWORZENIE.md). Tylko liczby
i nazwy plików — **bez adresów, haseł, e-maili i treści wierszy**.

```text
PROTOKÓŁ ĆWICZENIA ODTWORZENIA — kuking.pl (#594)
P0  Data (UTC) i wykonujący ................. 
    Commit checkoutu = commit produkcji ..... 
    Wersje: pg_dump / pg_restore / openssl .. 
P1  Railway Backups / PITR (dosłownie) ...... 
P2  Kopia: kod wyjścia / czas `real` ........ 
    Rozmiar .dump / .dump.cms (B) ........... 
    Tabel z danymi w zrzucie ................ 
    Odcisk certyfikatu (16 znaków) .......... 
P3  Znacznik zrzutu (z nazwy pliku, UTC) .... 
P4  Odtworzenie: czas odszyfrowania (s) ..... 
    czas pg_restore (s) ..................... 
    cały przebieg, `real` z `time` .......... 
P5  RTO ćwiczenia = od „mam plik i klucz”
    do „ZALICZONA” (min) .................... 
    Wiek zrzutu w chwili odtworzenia (RPO
    tej próby) = start kroku 5 − P3 ......... 
    Docelowe RPO dziś: BRAK GWARANCJI — kopie ręczne; po uruchomieniu
    `kopia-bazy` (#193) ≤ 24 h + czas przebiegu. Akceptuję / nie (podpis): 
P6  Skrót z .meta ........................... zgodny / NIE SPRAWDZONY
P7  Tabel w archiwum / w bazie .............. 
P8  Wiersze: users / posts / recipes /
    cooked_events ........................... 
P9  Migracje wykonane / czekające ........... 
P10 Wyzwalacze / CHECK / UNIQUE / FK / sondy  
P11 Kontrola ujemna (krok 6): kod / baz ..... 44 / 0 ?
P12 Sprzątanie wykonane; klucz prywatny w 2 miejscach, usunięty z dysku: 
P13 Co nie zadziałało / odstępstwa od karty . 
WYNIK: ZALICZONE / NIEZALICZONE
```

RTO z tej karty **nie obejmuje** wykrycia awarii, postawienia nowego serwisu
Railway, przełączenia aplikacji ani ruchu (§3(b)). To jest dolna granica.
RPO tej próby mówi, ile danych straciłbyś, odtwarzając tę kopię w tej chwili;
gwarancję RPO daje dopiero harmonogram z #193.

## Przećwiczenie tej karty — 24.09.2026, lokalnie, bez produkcji

Kroki 2–7 wykonano poleceniami z tej karty na **jednorazowych** danych:
źródło `kuking_dr594_cwiczenie_zrodlo` po wszystkich migracjach i
`php artisan db:seed` (z danymi demo: 16 kont, 88 wpisów, 41 przepisów),
serwer odtworzenia — osobny klaster `initdb` ze `scram-sha-256` na
`127.0.0.1:55441`. **PostgreSQL 16.13** (środowisko robocze nie ma 18;
na produkcji i u właściciela jest 18). Produkcji, Railwaya ani R2 nie dotknięto.

| Krok | Wynik |
|---|---|
| 3 kopia | kod 0; zrzut 342 173 B, 53 tabele z danymi, szyfrogram 343 196 B |
| 5 pierwszy przebieg | kod 24 z odciskiem `65ff9999010b9566`; odcisk przez gniazdo identyczny |
| 5 właściwy przebieg (z `--zrodlo --scisle` wobec źródła, bo stało w miejscu) | kod 0, 3,5 s; skrót zgodny (`sha256_jawnego`); 53/53 tabel, **4 665 = 4 665** wierszy; 93 migracje / 0 czekających; 3 wyzwalacze, CHECK 105, UNIQUE 30, FK 74; sondy i kontrola dodatnia zaliczone |
| 6 kontrola ujemna | uszkodzony w połowie `.cms` → **kod 44**; obcy klucz → **kod 43**; obcięty do 99 % → **kod 43**; baz próbnych po wszystkich trzech: **0** (mimo `--zostaw`) |
| 7 sprzątanie | baza próbna usunięta przez skrypt, klaster zatrzymany i skasowany |

**Dwie usterki znalezione tym przećwiczeniem i naprawione w tym samym PR:**

1. Pierwszy właściwy przebieg padł kodem **64** po poprawnym odtworzeniu
   i porównaniu wszystkich tabel: `migrate:status` dostawał hasło z `.env`
   repozytorium zamiast z DSN-u serwera odtworzenia. Wcześniejsze próby stały
   na `trust` albo na serwerze z tym samym hasłem, więc tego nie widziały.
   U właściciela, na klastrze z kroku 4, ćwiczenie kończyłoby się fałszywą
   czerwienią w ostatnim kroku.
2. Jawny zrzut (pierwsza kopia bez klucza, §8.1) ze zmienionymi bajtami
   przechodził całą próbę jako **ZALICZONA** — pole `sha256_pliku` z `.meta`
   nie było czytane. Zrzut obcięty do 99 % zakładał bazę próbną i padał
   dopiero na `pg_restore` (kod 50) z komunikatem o rozszerzeniach serwera.
   Teraz: kod 44 i kod 41, oba **przed** `CREATE DATABASE`. Przy okazji zrzut
   z `kopia-lokalna.sh` powstawał z prawami 0644 — teraz 0600.

To przećwiczenie **nie jest** odbiorem produkcji i nie wypełnia tabeli §5.
