# Odtworzenie bazy: karta wykonania #594 / #193

Stan instrukcji: 20 września 2026. Zakres: **lokalne dane syntetyczne**.
Wynik próby: [raport i dowody](evidence/dr594/RAPORT.md).
To uzupełnienie [głównej procedury kopii](KOPIE_I_ODTWORZENIE.md), nie zgoda
na produkcyjny restore. Nie otwieraj publicznego portu produkcji i nie pobieraj
jej danych na ten komputer. Nie zamykaj #594 ani #193 na podstawie tej próby.
Ćwiczenie na **prawdziwym** zrzucie produkcji prowadzi osobna karta:
[`DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`](DR594_PIERWSZY_ZRZUT_WLASCICIEL.md).

## 1. O trzeciej w nocy: najpierw ustal, co odtwarzasz

1. Zapisz czas awarii UTC, ostatni znany poprawny zapis i identyfikator kopii.
2. Wyznacz operatora i osobę podejmującą decyzję o utracie zapisów po kopii.
3. Ustal **osobną, pustą bazę celu**. Nie odtwarzaj nad istniejącymi danymi.
4. Sprawdź, czy masz kompletny szyfrogram `.dump.cms`, towarzyszący `.meta`,
   pasujący klucz prywatny i wersję kodu zgodną z kopią. `APP_KEY` to inny
   sekret niż klucz kopii; zachowaj go, nie generuj zamiennika dla produkcji.
5. Sprawdź wolne miejsce na szyfrogram, odszyfrowany zrzut, bazę, indeksy,
   pliki tymczasowe i WAL. Rozmiar skompresowanego pliku nie jest rozmiarem bazy.
6. Zatrzymanie produkcyjnych zapisów, przełączenie aplikacji, jej kluczy,
   kolejek i ruchu wymagają osobnego zatwierdzonego planu właściciela.
   **Nie ma tutaj polecenia przełączenia produkcji.**

Nie uznawaj `pg_restore --list` za dowód odtworzenia: czyta katalog archiwum.
Sukces oznacza kod 0, spodziewane dane, zgodny schemat, działające bariery
i sprawdzenie aplikacji w izolacji. Kopia bazy zawiera metadane zdjęć,
**nie bajty obiektów R2**.

## 2. Dokładne stanowisko tej próby

| Element | Wartość |
|---|---|
| Worktree Windows | `C:\Users\matma\Documents\kuking-flota\gpt-dr-baza` |
| Gałąź / baza kodu | `gpt/dr-baza` / `4c811cc7bff365fb8f86d87eabac93b7738a45cd` |
| Runtime WSL Ubuntu | `/home/mateusz/flota/gpt-dr-baza-run` |
| PostgreSQL | `127.0.0.1:55439`, rola `kuking` |
| Źródło syntetyczne | `kuking_flota_gpt_dr_baza_source` |
| Cel | `proba_odtworzenia_gpt_dr_baza_<czas UTC>` |
| Artefakty poza Git | `/home/mateusz/flota/gpt-dr-baza-artifacts/<czas UTC>` |
| Baza zwykłych testów | `kuking_flota_gpt-dr-baza` — inna niż źródło i cel próby |

Z **Git Bash w Windows** przygotuj runtime po każdej zmianie kodu:

```bash
cd /c/Users/matma/Documents/kuking-flota/gpt-dr-baza
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-dr-baza
MSYS_NO_PATHCONV=1 wsl -d Ubuntu
```

Dalsze polecenia wykonuj **w Bash wewnątrz Ubuntu**, nie w PowerShell:

```bash
cd /home/mateusz/flota/gpt-dr-baza-run
set -euo pipefail
umask 077
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export APP_ENV=local DB_CONNECTION=pgsql
unset DB_URL PGSERVICE PGSERVICEFILE PGHOSTADDR
export DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt_dr_baza_source DB_USERNAME=kuking
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking
export PGTZ=UTC PGOPTIONS='-c timezone=UTC' PGCONNECT_TIMEOUT=10
export MAIL_MAILER=array QUEUE_CONNECTION=database
read -r -s -p 'Hasło LOKALNEJ roli kuking: ' PGPASSWORD; echo
export PGPASSWORD DB_PASSWORD="$PGPASSWORD"
psql -X -d postgres -v ON_ERROR_STOP=1 -c \
  'SELECT current_user, inet_server_addr(), inet_server_port(), version();'
df -h /home/mateusz
pg_dump --version
pg_restore --version
```

Oczekuj `kuking`, `127.0.0.1`, `55439`, PostgreSQL 18.6 (wersja zmierzona).
Klient `pg_dump` nie może być starszy od serwera; `pg_restore` nie może być
starszy od narzędzia, które zrobiło zrzut. **Gdy wynik jest inny, przerwij.**
Nie uruchamiaj próby z gołej powłoki: `DB_PORT` służy też bezpiecznikowi
tożsamości instancji, a `PGPORT` klientom PostgreSQL. Oba muszą wynosić 55439.
Bez jawnych wartości możliwa jest próba połączenia z niedozwolonym 5432.

## 3. Przygotowanie danych — tylko gdy źródła jeszcze nie ma

Najpierw sprawdź nazwę i właściciela:

```bash
psql -X -d postgres -c "SELECT datname,pg_get_userbyid(datdba) AS owner
  FROM pg_database WHERE datname='kuking_flota_gpt_dr_baza_source';"
```

Jeżeli baza istnieje, **nie uruchamiaj generatora ponownie i nie czyść jej**.
Sprawdź liczności według raportu. Jeżeli jej nie ma:

```bash
createdb --owner=kuking kuking_flota_gpt_dr_baza_source
psql -X -d "$DB_DATABASE" -c 'SELECT current_database(),inet_server_addr(),inet_server_port();'
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
psql -X -d "$DB_DATABASE" -v ON_ERROR_STOP=1 -f scripts/dr594-dane.sql
```

To istniejące migracje i seeder, a następnie generator **5 500 000 dodatkowych
wierszy** w rzeczywistych tabelach. Generator jest jedną transakcją, odmawia
na innym hoście, porcie, użytkowniku, nazwie bazy i przy istniejących danych
`dr594-*`. Nie wyłącza FK, CHECK ani wyzwalaczy. Nie tworzy publicznych kont.
Hasła kont pomiarowych nie umożliwiają logowania. Nie uruchamiaj workerów,
schedulera ani serwera WWW na tej bazie podczas pomiaru.

## 4. Zrzut → szyfrowanie → odtworzenie → weryfikacja

```bash
python3 scripts/dr594-pomiar.py
```

Przyrząd uruchamia istniejące `scripts/kopia-lokalna.sh` i
`scripts/proba-odtworzenia.sh` z `--scisle --zostaw`. Generuje osobną parę
kluczy **wyłącznie do lokalnej próby**. Nie zastępuje kodu odtwarzania.
Zapisuje `backup.txt`, `restore.txt`, czasy w JSON i `receipt.json` w katalogu
artefaktów. W logach przy każdym wierszu jest czas od początku danego etapu.
Znaczniki z komunikatów mierzą czas do odebrania wiersza przez przyrząd,
więc ułamki sekund są pomiarem przybliżonym; sam skrypt mierzy całe sekundy.

Oczekuj wszystkich wyników naraz:

- kod procesu **0** oraz `PRÓBA ODTWORZENIA ZALICZONA`;
- 50 tabel porównanych, identyczna suma wierszy po obu stronach;
- 82 migracje wykonane, **0 czekających** dla podanej bazy kodu;
- 3 wymagane wyzwalacze, 91 CHECK, 26 UNIQUE i 71 FK;
- odrzucenie czterech zakazanych zapisów i przyjęcie dozwolonego zapisu;
- SHA-256 odszyfrowanego zrzutu zgodny z `.meta`;
- `receipt.json`: `content_equal: true`, `tables_compared: 50`;
- zgodne `source-manifest.json` i `target-manifest.json`: SHA-256 strumienia
  **wszystkich kolumn i wierszy każdej tabeli**, w stabilnej kolejności JSONB.

`receipt.json` pojawia się na końcu porównania treści. Samo istnienie
`restore.json` z kodem 0 jeszcze tego ostatniego kroku nie potwierdza.
W czasie próby nikt nie może pisać do źródła: `--scisle` porównuje stan
bieżący ze zrzutem. Przy żywym źródle różnica może wynikać z nowszych zapisów.

## 5. Udowodnij, że weryfikacja wykrywa stratę

Przepisz dokładną nazwę celu z `receipt.json`, a potem:

```bash
TARGET=proba_odtworzenia_gpt_dr_baza_20260920t173937z  # przykład z raportu
bash scripts/dr594-kontrole.sh "$TARGET"
```

Skrypt najpierw zalicza zgodność. Następnie odkłada dokładny wiersz jednego
syntetycznego komentarza, usuwa go **tylko z celu**, wymaga kodu **63**
i wskazania tabeli `comments`, przywraca wiersz, wymaga ponownego sukcesu.
Pułapka EXIT przywraca wiersz także po błędzie. Przed wykonaniem wybierz
własny cel istniejącego przebiegu; przykładowa baza może już być posprzątana.

Pełny zestaw kontroli dodatkowo sprawdza podmianę treści przy niezmienionym
liczniku, ponowne uruchomienie generatora, niewłaściwy cel generatora
i uszkodzenie jednego bajtu szyfrogramu:

```bash
python3 scripts/dr594-sprawdz.py /home/mateusz/flota/gpt-dr-baza-artifacts/20260920T173937Z
```

Użyj katalogu własnego zakończonego przebiegu. Oczekuj `controls.json`
z poprawnymi kodami, `content-restored.equal: true` oraz
`corrupt-archive.database_created: false`. Ten zestaw wykonuj raz na katalog;
istniejący katalog `corruption` oznacza, że kontrola była już uruchamiana.
Podmiana treści jest przywracana w `finally`; po SIGKILL sprawdź manifesty
i odtwórz cel od nowa, jeżeli się różnią.

## 6. Co robić po błędzie lub przerwaniu w połowie

| Objaw / kod | Następny krok |
|---|---|
| Brak zmiennej, błąd hasła / połączenia | Powtórz §2. Sprawdź obydwa porty, rolę i faktyczną tożsamość serwera. Nie próbuj portu 5432. |
| Generator przerwany | Transakcja wycofa dane generatora; seeder pozostaje. Sprawdź brak `dr594-*`, następnie powtórz generator. Nie stosuj `migrate:fresh` do niezweryfikowanego celu. |
| Kopia kod 21 (brak katalogu) | Utwórz świadomie katalog poza repozytorium, z prawami 700; nie poprawiaj przypadkowej ścieżki w ciemno. |
| Zrzut przerwany, kod 30/40 | Sprawdź miejsce, dostęp i wersję klienta. Nie używaj częściowego pliku; zrób nowy zrzut do nowego katalogu. |
| Odtworzenie 43/44 | Sprawdź parę kluczy oraz komplet `.cms` + `.meta`. Przy 44 odzyskaj nieuszkodzoną kopię; nie omijaj kontroli skrótu. |
| Kod 23 (cel niepusty) | Zachowaj cel do diagnozy. Wybierz nową nazwę i pustą bazę, nie dokładaj danych do częściowego restore. |
| Kod 24 (tożsamość instancji) | Sprawdź jawne DB_HOST/DB_PORT i właściciela instancji. Nie kopiuj automatycznie odcisku do `--instancja`. |
| Kod 50 (restore) / przerwane `pg_restore` | Zachowaj log, sprawdź zasoby i wersję. Cel może zawierać część danych. Powtórz do NOWEGO celu z tej samej zweryfikowanej kopii. |
| Kod 63 lub różne manifesty | Nie zaliczaj. Sprawdź, czy źródło stało w miejscu, która tabela różni się i czy kopia jest kompletna. |
| Kod 64 (migracje) | Dopasuj wersję kodu do zrzutu. Nie uruchamiaj odruchowo migracji, żeby ukryć błąd weryfikacji. |
| Kody 70–76 (bariery) | Baza nie spełnia reguł Kukinga. Nie przełączaj na nią aplikacji. Zapisz komunikat sondy i sprawdź schemat. |

Zwykły skrypt bez `--zostaw` usuwa założoną przez siebie bazę w pułapce EXIT.
Przyrząd używa `--zostaw`, więc cel **celowo pozostaje także po porażce**.
SIGKILL / utrata zasilania nie wykonuje pułapek: sprawdź również katalogi
`/tmp/proba-odtworzenia.*`, w których może zostać jawny zrzut, i osierocone
procesy. Usuwaj tylko artefakty tego konkretnego przebiegu.

Po zapisaniu dowodów usuń własny cel, sprawdzając go ponownie:

```bash
[[ "$TARGET" =~ ^proba_odtworzenia_gpt_dr_baza_[0-9]{8}t[0-9]{6}z$ ]]
[[ "$PGHOST:$PGPORT" == 127.0.0.1:55439 ]]
psql -X -d "$TARGET" -c 'SELECT current_database(),current_user,inet_server_addr(),inet_server_port();'
dropdb --host=127.0.0.1 --port=55439 --username=kuking "$TARGET"
psql -X -d postgres -c "SELECT datname FROM pg_database WHERE datname='$TARGET';"
```

Oczekuj 0 wierszy. Źródło jest osobną bazą i nie ginie przy tym poleceniu.
Kopie i testowy klucz prywatny zostają poza Git; po zakończeniu okresu
lokalnego przechowywania usuń dokładny katalog artefaktów. Nie kopiuj do
repozytorium `.dump`, `.cms`, klucza prywatnego, `.env` ani danych wierszowych.

## 7. Decyzje właściciela i warunki odbioru produkcji

Nie ustalamy bieżącego planu Railway ani stanu kopii z dawnych dokumentów.
Odczyty panelu z 17 IX i dokumentacji z 18 IX to **[pomiar cudzy:
KOPIE_I_ODTWORZENIE.md §5.2–5.3]**, nie sprawdzenie wykonane w tej sesji.
Aktualny stan produkcji pozostaje niezweryfikowany.

| Decyzja / czynność właściciela | Warianty i koszt operacyjny |
|---|---|
| Miejsce offsite | Osobny prywatny bucket R2 zgodnie z D-043/D-049; ustalić konto, region/jurysdykcję i niezależność dostępu od Railway. Dodatkowa kopia u drugiego dostawcy zwiększa niezależność, ale wymaga drugiego utrzymania i rachunku. |
| Dostęp | Wskazać operatora i zastępcę; token zapisu tylko dla kopii, odczytu dla czujki. Klucz prywatny w dwóch niezależnych miejscach, poza Railway. Przećwiczyć odzyskanie bez dostępu do konta Railway. |
| Częstotliwość / RPO | Kod przewiduje `17 2 * * *` UTC. Codziennie: do doby zapisów między udanymi kopiami; częściej: niższy możliwy RPO, więcej odczytów, transferu i operacji. Awaria harmonogramu wydłuża RPO — sam cron nie daje gwarancji. |
| Retencja | Kod przewiduje 30 dni i minimum 7 kopii. Dłuższa retencja daje dłuższe okno odzyskania, lecz zwiększa przechowywanie danych i koszt. Te wartości są stanem kodu, nie dowodem ustawienia produkcji. |
| Alarm i odpowiedzialność | Ustalić odbiorcę i czas reakcji; osobno alarm nieudanego przebiegu oraz czujkę braku świeżej kopii. Kontrolowany test alarmu wymaga zgody na wysłanie wiadomości. |
| Railway Volume Backups / PITR | Sprawdzić aktualny panel, dostępność i koszt na konkretnym planie. Nie przyjmować historycznego zdania „tylko Pro” jako aktualnego faktu. |
| RTO i budżet | Określić dopuszczalny przestój oraz zasoby celu. Lokalny czas `pg_restore` nie obejmuje wykrycia awarii, odzyskania dostępu/kluczy, pobrania offsite, uruchomienia infrastruktury ani przełączenia ruchu. |
| Produkcyjna próba | Wskazać izolowane, autoryzowane środowisko poza tą maszyną; odtworzyć prawdziwą kopię bez publicznego portu DB. Najpierw plan #595/staging, następnie zatwierdzone uruchomienie `kopia-bazy`. |

Odbiór produkcyjny wymaga daty, identyfikatora i wieku rzeczywistej kopii,
czasu każdego etapu, liczników, migracji, sprawdzenia danych/relacji,
próby aplikacji bez wychodzącej poczty i zadań oraz dowodu kolejnego przebiegu
automatycznego. Dopiero taki wpis może wypełnić produkcyjną tabelę §5.
Wartości RTO/RPO mają zostać zaakceptowane przez właściciela, nie przez test.
