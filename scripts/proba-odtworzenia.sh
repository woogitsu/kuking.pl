#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — PRÓBA ODTWORZENIA (restore drill), issue #9 i #193
# =============================================================================
#
#  CO TO ROBI
#  ----------
#  Bierze zrzut bazy (`pg_dump --format=custom`, jawny albo zaszyfrowany
#  `.cms`), wlewa go do CZYSTEJ bazy o nazwie, której nie da się pomylić
#  z produkcyjną, a potem SPRAWDZA WYNIK — nie sam fakt, że coś się wykonało.
#
#  DLACZEGO TO ISTNIEJE OSOBNO OD `docker/kopia/kopia-bazy.sh`
#  -----------------------------------------------------------
#  Tamten skrypt robi kopię i potrafi ją zweryfikować wyłącznie tak, jak da
#  się to zrobić bez klucza prywatnego: czyta spis treści archiwum
#  (`pg_restore --list`). To wyłapuje zrzut pustej bazy i archiwum obcięte —
#  i na tym jego możliwości się kończą, bo w kontenerze kopii NIE MA klucza
#  prywatnego i nie ma drugiego serwera PostgreSQL.
#
#  Spis treści nie odpowiada jednak na pytanie, o które chodzi w issue #9:
#  **czy z tego pliku da się mieć z powrotem BAZĘ KUKINGA**, a nie „poprawne
#  archiwum”. Różnica jest mierzalna i akurat w tym repozytorium ogromna:
#  część gwarancji danych nie stoi w kodzie PHP, tylko w bazie, i nosi ją
#  TRZY WYZWALACZE oraz 87 ograniczeń `CHECK` i 25 `UNIQUE`.
#
#      follows_blokada_ma_pierwszenstwo_trg   obserwowanie nie współistnieje
#                                             z blokadą (D-080)
#      dziennik_zgod_bez_zmian                dziennik zgód jest append-only
#      dziennik_zgod_bez_czyszczenia          (D-072) — dowód zgody RODO
#
#  Zrzut, który gubi te wyzwalacze, wygląda w `pg_restore --list` identycznie
#  jak dobry: te same tabele, te same wiersze. Odtworzona z niego baza
#  przyjmie obserwowanie osoby zablokowanej i pozwoli skasować dowód zgody —
#  czyli będzie bazą Kukinga bez połowy tego, co ją trzyma. Dlatego ten skrypt
#  sprawdza wyzwalacze WPROST, i sprawdza je DWA RAZY:
#
#    * że są (zapytanie do `pg_trigger`),
#    * że DZIAŁAJĄ (sonda: próba zapisu, który MUSI zostać odrzucony).
#
#  Sama obecność nie wystarcza: wyzwalacz przywrócony bez swojej funkcji,
#  z funkcją okrojoną przy ręcznym łataniu zrzutu albo wyłączony
#  (`ALTER TABLE … DISABLE TRIGGER`) nadal siedzi w `pg_trigger` i nadal nie
#  pilnuje niczego. Ta druga kontrola jest różnicą między „wiersz w katalogu
#  systemowym jest” a „bariera trzyma”.
#
#  CZEGO TEN SKRYPT NIE DOTYKA
#  ---------------------------
#  Produkcji. Nigdy, w żadnym trybie. Wlewa TYLKO do bazy `proba_odtworzenia*`
#  i pilnuje tego DWOMA NIEZALEŻNYMI BEZPIECZNIKAMI (opisanymi niżej, przy
#  funkcjach `bezpiecznik_nazwy` i `bezpiecznik_serwera`). Zrzut czyta z pliku;
#  z bazą źródłową rozmawia wyłącznie wtedy, gdy podasz `--zrodlo`, i wtedy
#  wyłącznie zapytaniami `SELECT count(*)`.
#
#  URUCHOMIENIE
#  ------------
#    scripts/proba-odtworzenia.sh --petla-lokalna          <-- JEDNA KOMENDA
#    scripts/proba-odtworzenia.sh --zrzut kuking-20260911-021700Z.dump
#    scripts/proba-odtworzenia.sh --zrzut kopia.dump.cms --klucz PRYWATNY.pem
#    scripts/proba-odtworzenia.sh --zrzut k.dump --zrodlo "$DSN_ZRODLA"
#
#  PĘTLA LOKALNA (--petla-lokalna) — całe ćwiczenie jedną komendą
#  --------------------------------------------------------------
#  kopia lokalnej bazy (`scripts/kopia-lokalna.sh`, ten sam skrypt, którym
#  robi się kopię naprawdę) → odtworzenie do ŚWIEŻEJ, osobnej bazy →
#  porównanie liczby wierszy W KAŻDEJ TABELI, co do jednego →
#  `php artisan migrate:status` na odtworzonej bazie.
#
#  Rozjazd choćby jednej tabeli kończy się kodem 63. Brakująca albo czekająca
#  migracja — kodem 64. Adres bazy źródłowej bierze się z `.env` tego
#  repozytorium (albo ze zmiennej `PROBA_ZRODLO`), a serwerem, na którym
#  staje baza próbna, jest ten sam serwer — bo obie są lokalne.
#
#  DLACZEGO PĘTLA WOŁA `kopia-lokalna.sh`, A NIE `pg_dump` WPROST
#  Bo ćwiczeniem ma być ODTWORZENIE TEJ KOPII, którą się naprawdę robi,
#  a nie zrzutu zrobionego obok, innymi przełącznikami. Zrzut z własnego
#  `pg_dump` w tym skrypcie dowodziłby tylko tego, że ten skrypt umie
#  rozmawiać sam ze sobą.
#
#  Adres serwera, na którym wolno założyć bazę próbną, bierze się z `--serwer`
#  albo ze zmiennej `PROBA_SERWER`. To MA BYĆ inny serwer niż produkcyjny —
#  lokalny Postgres albo świeży serwis w nowym projekcie. Skrypt odmawia
#  pracy, gdy adres wygląda na Railwaya (bezpiecznik 1).
#
#  Testy tego skryptu (z kontrolą ujemną): tests/skrypty/proba-odtworzenia.sh
#  Kontekst i procedura dla człowieka:     docs/infra/KOPIE_I_ODTWORZENIE.md §8
#
#  KODY WYJŚCIA — każdy mówi, CO dokładnie zawiodło
#  ------------------------------------------------
#     2  błąd użycia (argumenty)
#    10  brak narzędzia (psql / pg_restore / openssl)
#    11  pg_restore starszy niż pg_dump, który zrobił zrzut
#    20  BEZPIECZNIK 1: nazwa bazy celu nie jest nazwą próbną
#    21  BEZPIECZNIK 1: adres serwera wygląda na produkcyjny (Railway)
#    22  BEZPIECZNIK 2: serwer zwrócił INNĄ bazę, niż zadeklarowano
#    23  BEZPIECZNIK 2: baza próbna nie jest pusta
#    24  BEZPIECZNIK 3: tożsamość instancji docelowej niepotwierdzona
#        (albo potwierdzenie nie zgadza się z tym, co stoi pod adresem)
#    30  nie udało się założyć bazy próbnej
#    40  zrzutu nie ma albo jest za mały (pusta kopia!)
#    41  pg_restore nie potrafi odczytać archiwum
#    42  w archiwum jest za mało tabel
#    43  odszyfrowanie `.cms` nie udało się
#    44  odszyfrowany zrzut nie zgadza się ze skrótem z pliku `.meta`
#    50  pg_restore zakończył się błędem
#    60  w odtworzonej bazie jest za mało tabel
#    61  nazwanej tabeli nie ma w odtworzonej bazie
#    62  w nazwanej tabeli jest za mało wierszy
#    63  liczba wierszy nie zgadza się ze źródłem (`--zrodlo`)
#    70  brak wyzwalacza, który niesie gwarancję danych
#    71  wyzwalacz JEST, ale NIE DZIAŁA (sonda przeszła, a miała zostać odrzucona)
#    72  za mało ograniczeń CHECK
#    73  za mało ograniczeń UNIQUE
#    74  za mało kluczy obcych
#    75  ograniczenie CHECK nie działa
#    76  ograniczenie UNIQUE nie działa
#    64  migracje w odtworzonej bazie nie zgadzają się z repozytorium
#    65  PĘTLA LOKALNA: nie udało się zrobić kopii (scripts/kopia-lokalna.sh)
# =============================================================================

set -Eeuo pipefail

# --- Wartości domyślne -------------------------------------------------------
#
# Progi są celowo NIŻSZE niż stan produkcji (49 tabel, 3 wyzwalacze, 87 CHECK,
# 25 UNIQUE, 68 kluczy obcych — zmierzone 11.09.2026 na schemacie po
# migracjach). Próg ma łapać zrzut z INNEJ albo pustej bazy, a nie oblewać się
# przy każdym dołożeniu migracji. Wartość „ile jest dziś” nie jest progiem:
# byłaby testem, który trzeba poprawiać przy każdej zmianie schematu, a taki
# test po trzecim razie podnosi się bez czytania.
MIN_BAJTOW="${PROBA_MIN_BAJTOW:-20000}"
MIN_TABEL="${PROBA_MIN_TABEL:-20}"
MIN_WYZWALACZY="${PROBA_MIN_WYZWALACZY:-3}"
MIN_CHECK="${PROBA_MIN_CHECK:-40}"
MIN_UNIQUE="${PROBA_MIN_UNIQUE:-15}"
MIN_KLUCZY_OBCYCH="${PROBA_MIN_KLUCZY_OBCYCH:-30}"
MIN_WIERSZY="${PROBA_MIN_WIERSZY:-1}"

# Tabele, których liczbę wierszy sprawdzamy po nazwie. Nie „wszystkie”, bo
# wszystkie już policzył próg wyżej — tu chodzi o te, których PUSTKA znaczy
# „to nie jest baza Kukinga”: konta, wpisy, przepisy i ugotowania.
TABELE_DO_POLICZENIA="${PROBA_TABELE:-users,posts,recipes,cooked_events}"

# Wyzwalacze, które niosą część gwarancji danych (patrz nagłówek).
WYZWALACZE_WYMAGANE='follows_blokada_ma_pierwszenstwo_trg dziennik_zgod_bez_zmian dziennik_zgod_bez_czyszczenia'

SERWER="${PROBA_SERWER:-}"
# Katalog repozytorium — stąd bierze się `.env` (adres lokalnej bazy),
# `scripts/kopia-lokalna.sh` i `artisan`. Liczony od położenia TEGO pliku,
# nie od katalogu roboczego: ćwiczenie wolno uruchomić skądkolwiek.
KATALOG_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
BAZA=''
PLIK_ZRZUTU=''
PLIK_KLUCZA=''
DSN_ZRODLA=''
ZOSTAW=0
PETLA_LOKALNA=0
# Bezpiecznik 3: jawnie potwierdzony odcisk instancji docelowej.
INSTANCJA_POTWIERDZONA="${PROBA_INSTANCJA:-}"
ODCISK_CELU=''
KATALOG_POSWIADCZEN=''
SCIEZKA_PGPASS=''
DSN_BEZ_HASLA=''
HASLO_Z_DSN=''
HOST_Z_DSN='*'
PORT_Z_DSN='*'
UZYTKOWNIK_Z_DSN='*'
# Porównanie ŚCISŁE: każda tabela co do jednego wiersza. Włącza je pętla
# lokalna, bo tam baza źródłowa stoi w miejscu i nie ma prawa się rozjechać.
SCISLE=0
# Liczby, którymi kończy się przebieg — zbierane po drodze, wypisywane na końcu.
TABEL_POROWNANYCH=0
WIERSZY_W_ZRODLE=0
WIERSZY_W_PROBIE=0
MIGRACJI_WYKONANYCH=0

ZIELONY=$'\033[0;32m'
CZERWONY=$'\033[0;31m'
ZOLTY=$'\033[0;33m'
RESET=$'\033[0m'

log() { printf '[proba] %s\n' "$*" >&2; }
ok() { printf '[proba] %s✓%s %s\n' "${ZIELONY}" "${RESET}" "$*" >&2; }

# Porażka mówi TRZY rzeczy: co zawiodło, co to znaczy i co z tym zrobić.
# Ten komunikat czyta człowiek w dniu, w którym i tak wszystko się wali.
padnij() {
  local kod="$1"
  shift
  printf '[proba] %sODMOWA/PORAŻKA (kod %s)%s\n' "${CZERWONY}" "${kod}" "${RESET}" >&2
  local linia
  for linia in "$@"; do
    printf '        %s\n' "${linia}" >&2
  done
  exit "${kod}"
}

# --- Adres bez hasła, do logu ------------------------------------------------
bez_hasla() { sed -E 's#(://[^:/@]+):[^@]*@#\1:***@#' <<<"$1"; }

# =============================================================================
#  POŚWIADCZENIE POZA LISTĄ PROCESÓW (#594)
#
#  `psql "postgresql://user:HASŁO@host/db"` i `pg_restore --dbname=…` pokazują
#  hasło w `ps` KAŻDEMU użytkownikowi maszyny: argumenty procesu są na Linuksie
#  jawne. Ten skrypt dostaje w `--zrodlo` adres bazy, z której tylko czyta —
#  a przy ćwiczeniu z produkcji jest to poświadczenie produkcyjne.
#
#  Hasła idą więc do prywatnego `PGPASSFILE` (prawa 600, w katalogu roboczym,
#  który ginie razem z ćwiczeniem), a do narzędzi trafiają adresy BEZ hasła.
#  `--no-password` tego nie psuje: ten przełącznik blokuje wyłącznie pytanie
#  na terminalu, nie odczyt pliku.
# =============================================================================
odkoduj_procenty() {
  local s="${1//\\/\\\\}"
  printf '%b' "${s//%/\\x}"
}

# Rozkłada DSN na części. Ustawia: DSN_BEZ_HASLA, HASLO_Z_DSN, HOST_Z_DSN,
# PORT_Z_DSN, UZYTKOWNIK_Z_DSN. Adres bez hasła zostawia nietknięty.
rozdziel_dsn() {
  local dsn="$1"
  DSN_BEZ_HASLA="${dsn}"
  HASLO_Z_DSN=''
  HOST_Z_DSN='*'
  PORT_Z_DSN='*'
  UZYTKOWNIK_Z_DSN='*'

  case "${dsn}" in
    postgresql://* | postgres://*) ;;
    *) return 0 ;;
  esac

  local schemat="${dsn%%://*}://" reszta="${dsn#*://}"
  local przed_sciezka="${reszta%%/*}"

  case "${przed_sciezka}" in
    *@*) ;;
    *) return 0 ;;
  esac

  local userinfo="${przed_sciezka%@*}" gospodarz="${przed_sciezka##*@}"
  UZYTKOWNIK_Z_DSN="$(odkoduj_procenty "${userinfo%%:*}")"

  case "${gospodarz}" in
    \[*) ;; # IPv6 — zostawiamy gwiazdkę, dopasowanie po użytkowniku wystarczy
    *:*)
      HOST_Z_DSN="${gospodarz%%:*}"
      PORT_Z_DSN="${gospodarz##*:}"
      ;;
    *) HOST_Z_DSN="${gospodarz}" ;;
  esac

  case "${userinfo}" in
    *:*) ;;
    *) return 0 ;;
  esac

  HASLO_Z_DSN="$(odkoduj_procenty "${userinfo#*:}")"
  DSN_BEZ_HASLA="${schemat}${userinfo%%:*}@${gospodarz}${reszta#"${przed_sciezka}"}"
}

# Dopisuje poświadczenie z DSN-u do prywatnego PGPASSFILE i zostawia adres bez
# hasła w `DSN_BEZ_HASLA`. Wynik JEST W ZMIENNEJ, a nie na wyjściu, i to jest tu
# istotne: `X="$(schowaj_haslo_z_dsn "$X")"` uruchomiłoby tę funkcję
# w podpowłoce, a wtedy `export PGPASSFILE` zginąłby razem z nią. Pierwsza
# wersja tej poprawki miała dokładnie ten błąd i przechodziła tylko dlatego,
# że w środowisku stało `PGPASSWORD` — czyli zielono, bez PGPASSFILE.
#
# Plik powstaje DOPIERO gdy jest co w nim schować — pusty PGPASSFILE
# przesłoniłby `~/.pgpass` i zerwałby połączenie adresem bez hasła.
schowaj_haslo_z_dsn() {
  local dsn="$1"
  rozdziel_dsn "${dsn}"

  if [[ -n "${HASLO_Z_DSN}" ]]; then
    if [[ "${PGPASSFILE:-}" != "${SCIEZKA_PGPASS}" ]]; then
      (
        umask 077
        : >"${SCIEZKA_PGPASS}"
      )
      chmod 600 "${SCIEZKA_PGPASS}"
      export PGPASSFILE="${SCIEZKA_PGPASS}"
    fi

    local pole="${HASLO_Z_DSN//\\/\\\\}"
    pole="${pole//:/\\:}"
    printf '%s:%s:*:%s:%s\n' \
      "${HOST_Z_DSN}" "${PORT_Z_DSN}" "${UZYTKOWNIK_Z_DSN}" "${pole}" >>"${SCIEZKA_PGPASS}"
  fi
}

# =============================================================================
#  Argumenty
# =============================================================================
pomoc() {
  cat <<'POMOC'
Próba odtworzenia bazy Kuking z zrzutu (restore drill).

  --zrzut PLIK      zrzut pg_dump --format=custom; `.cms` = zaszyfrowany
  --klucz PLIK      klucz PRYWATNY do `.cms` (wymagany tylko dla `.cms`)
  --serwer DSN      serwer, na którym wolno założyć bazę próbną
                    (albo zmienna PROBA_SERWER); DSN wskazuje bazę
                    utrzymaniową, np. postgresql://user:hasło@host:5432/postgres
  --baza NAZWA      nazwa bazy próbnej; MUSI zaczynać się od
                    `proba_odtworzenia` (domyślnie: proba_odtworzenia_<znacznik>)
  --zrodlo DSN      opcjonalnie: baza źródłowa TYLKO DO ODCZYTU, żeby
                    porównać liczby wierszy co do jednego
  --instancja ODC   JAWNE potwierdzenie, do której instancji wlewasz zrzut
                    (odcisk klastra; skrypt wypisuje go w odmowie). Wymagane
                    dla każdego serwera, który nie jest Postgresem tego
                    repozytorium — nazwa hosta nie jest dowodem, bo za
                    tunelem produkcja też nazywa się 127.0.0.1
  --tabele a,b,c    które tabele policzyć po nazwie
  --zostaw          nie kasuj bazy próbnej po ćwiczeniu (do obejrzenia)
  --petla-lokalna   CAŁE ĆWICZENIE JEDNĄ KOMENDĄ: kopia lokalnej bazy →
                    odtworzenie do świeżej bazy → porównanie liczby wierszy
                    w KAŻDEJ tabeli → migrate:status. Adres bazy bierze
                    z `.env` (albo ze zmiennej PROBA_ZRODLO)
  --scisle          liczby wierszy muszą zgadzać się CO DO JEDNEGO
                    (w --petla-lokalna włączone samo)
  -h, --help        ta pomoc

Pełna procedura dla człowieka: docs/infra/KOPIE_I_ODTWORZENIE.md §8
POMOC
}

# =============================================================================
#  ADRES LOKALNEJ BAZY — składany z `.env`, a nie zgadywany
#
#  Pętla lokalna ma być JEDNĄ komendą, więc nie może wymagać, żeby człowiek
#  przepisywał DSN z `.env` do wiersza polecenia. Czytamy stamtąd cztery
#  wartości i składamy adres. Hasło przechodzi przez kodowanie procentowe,
#  bo `@` albo `/` w haśle rozbiłoby adres na części w złych miejscach.
# =============================================================================
zakoduj_url() {
  local surowy="$1" wynik='' znak
  local i
  for ((i = 0; i < ${#surowy}; i++)); do
    znak="${surowy:i:1}"
    case "${znak}" in
      [a-zA-Z0-9.~_-]) wynik+="${znak}" ;;
      *) wynik+="$(printf '%%%02X' "'${znak}")" ;;
    esac
  done
  printf '%s' "${wynik}"
}

z_env() {
  local klucz="$1" plik="${KATALOG_REPO}/.env"

  # Zmienna ze ŚRODOWISKA wygrywa z plikiem — dokładnie tak robi Laravel i tak
  # uruchamiane są testy (`DB_PORT=… php artisan test`). Bez tego bezpiecznik 3
  # porównywałby cel z klastrem, do którego nikt się w tym przebiegu nie łączy.
  if [[ -n "${!klucz:-}" ]]; then
    printf '%s' "${!klucz}"
    return 0
  fi

  [[ -f "${plik}" ]] || return 0
  sed -n -E "s/^[[:space:]]*${klucz}[[:space:]]*=[[:space:]]*//p" "${plik}" \
    | tail -n 1 | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' | tr -d '\r'
}

dsn_z_env() {
  local host port uzytkownik haslo baza
  host="$(z_env DB_HOST)"; port="$(z_env DB_PORT)"
  uzytkownik="$(z_env DB_USERNAME)"; haslo="$(z_env DB_PASSWORD)"
  baza="$(z_env DB_DATABASE)"

  [[ -n "${host}" && -n "${uzytkownik}" && -n "${baza}" ]] || return 0

  printf 'postgresql://%s:%s@%s:%s/%s' \
    "$(zakoduj_url "${uzytkownik}")" "$(zakoduj_url "${haslo}")" \
    "${host}" "${port:-5432}" "${baza}"
}

# Ten sam adres, inna baza na końcu. Używane dwa razy: do adresu bazy
# utrzymaniowej (`postgres`) i do adresu bazy próbnej.
podmien_baze_w_dsn() {
  local dsn="$1" nowa="$2"
  printf '%s' "${dsn%/*}/${nowa}"
}

# Argumenty przetwarza FUNKCJA, a nie kod na poziomie pliku — żeby ten plik
# dał się wczytać (`source`) bez uruchamiania czegokolwiek. Tak robią testy:
# `tests/skrypty/proba-odtworzenia.sh` woła `kontrola_dodatnia_zapisu()`
# wprost, na dwóch różnych bazach, bo tylko tak da się pokazać, że ta
# kontrola sama cokolwiek mierzy.
przetworz_argumenty() {
  while (($# > 0)); do
    case "$1" in
      --zrzut)
        PLIK_ZRZUTU="${2:-}"
        shift 2
        ;;
      --klucz)
        PLIK_KLUCZA="${2:-}"
        shift 2
        ;;
      --serwer)
        SERWER="${2:-}"
        shift 2
        ;;
      --baza)
        BAZA="${2:-}"
        shift 2
        ;;
      --zrodlo)
        DSN_ZRODLA="${2:-}"
        shift 2
        ;;
      --instancja)
        INSTANCJA_POTWIERDZONA="${2:-}"
        shift 2
        ;;
      --tabele)
        TABELE_DO_POLICZENIA="${2:-}"
        shift 2
        ;;
      --zostaw)
        ZOSTAW=1
        shift
        ;;
      --petla-lokalna)
        PETLA_LOKALNA=1
        SCISLE=1
        shift
        ;;
      --scisle)
        SCISLE=1
        shift
        ;;
      -h | --help)
        pomoc
        exit 0
        ;;
      *)
        pomoc >&2
        padnij 2 "Nieznany argument: $1"
        ;;
    esac
  done

  # W pętli lokalnej zrzutu jeszcze NIE MA — powstaje za chwilę, z lokalnej
  # bazy. Poza pętlą brak `--zrzut` znaczy, że nie ma czego odtwarzać.
  if ((PETLA_LOKALNA == 0)); then
    [[ -n "${PLIK_ZRZUTU}" ]] || {
      pomoc >&2
      padnij 2 'Brak --zrzut: nie ma czego odtwarzać.'
    }
  else
    [[ -z "${PLIK_ZRZUTU}" ]] || padnij 2 \
      '--petla-lokalna sama robi kopię, więc --zrzut nie ma tu sensu.' \
      'Chcesz odtworzyć GOTOWY plik? Wtedy bez --petla-lokalna.'

    # Źródłem jest lokalna baza tego repozytorium. `PROBA_ZRODLO` pozwala
    # wskazać inną — ale nadal LOKALNĄ: produkcji ten skrypt nie dotyka
    # w żadnym trybie i pilnuje tego `bezpiecznik_serwera` niżej.
    [[ -n "${DSN_ZRODLA}" ]] || DSN_ZRODLA="${PROBA_ZRODLO:-$(dsn_z_env)}"

    [[ -n "${DSN_ZRODLA}" ]] || padnij 2 \
      'Nie umiem złożyć adresu lokalnej bazy.' \
      'Podaj go przez --zrodlo albo przez zmienną PROBA_ZRODLO, albo uzupełnij' \
      'DB_* w pliku .env tego repozytorium.'

    # Baza próbna staje na TYM SAMYM serwerze co źródło — obie są lokalne.
    # Adres bazy utrzymaniowej składamy, podmieniając samą nazwę bazy.
    [[ -n "${SERWER}" ]] || SERWER="$(podmien_baze_w_dsn "${DSN_ZRODLA}" postgres)"
  fi

  [[ -n "${SERWER}" ]] || padnij 2 \
    'Brak --serwer (ani zmiennej PROBA_SERWER).' \
    'Podaj DSN serwera, NA KTÓRYM WOLNO założyć bazę próbną — nie produkcyjnego.'

  # Domyślna nazwa bazy próbnej. Znacznik czasu jest w nazwie po to, żeby dwa
  # ćwiczenia tego samego dnia nie wlały się do jednej bazy.
  [[ -n "${BAZA}" ]] || BAZA="proba_odtworzenia_$(date -u +%Y%m%d_%H%M%S)"
}

# =============================================================================
#  BEZPIECZNIK 1 — NAZWA I ADRES, SPRAWDZANE PRZED JAKIMKOLWIEK POŁĄCZENIEM
# =============================================================================
#
#  Pierwszy bezpiecznik patrzy na to, CO KAZANO MU ZROBIĆ: na nazwę bazy
#  i na adres serwera, jako na napisy, zanim cokolwiek się połączy. To jest
#  jedyny bezpiecznik, który działa także wtedy, gdy serwer jest nieosiągalny
#  — a więc jedyny, który zadziała, gdy ktoś przez pomyłkę wskaże produkcję,
#  do której nie ma w tej chwili dostępu.
#
#  DLACZEGO „proba_odtworzenia”, A NIE „restore_drill” ANI „kuking_test”
#  Bo `pg_restore` do bazy próbnej ZAPISUJE — i to jest jedyna operacja
#  w tym repozytorium, która wlewa komplet cudzych danych do bazy wskazanej
#  z wiersza polecenia. Nazwa musi być taka, żeby nie dało się jej pomylić
#  z niczym istniejącym: produkcja w Railwayu nazywa się `railway`, lokalna
#  deweloperska `kuking`, testowe `kuking_test*`, wyścigowe `kuking_race*`.
#  Wzorzec `proba_odtworzenia*` nie koliduje z żadną z nich, a `railway`,
#  `kuking` ani `postgres` nigdy do niego nie wpadną — nawet przy literówce,
#  bo to nie jest różnica jednego znaku.
#
#  Wzorzec sprawdzamy CAŁYM dopasowaniem, nie prefiksem przez `str_starts`:
#  nazwa musi też przejść przez sito dozwolonych znaków, bo trafia do SQL-a
#  w `CREATE DATABASE` i w `DROP DATABASE`.
bezpiecznik_nazwy() {
  if [[ ! "${BAZA}" =~ ^proba_odtworzenia[a-z0-9_]*$ ]]; then
    padnij 20 \
      "Baza celu to \"${BAZA}\", a wolno wyłącznie \`proba_odtworzenia*\`" \
      '(małe litery, cyfry i podkreślenia).' \
      'Ten skrypt ZAPISUJE do bazy celu — nazwa jest tu bezpiecznikiem,' \
      'nie konwencją. Popraw --baza albo nie podawaj jej wcale.'
  fi

  # Adres produkcyjny w `--serwer` to pomyłka, po której nie ma już czego
  # naprawiać: `CREATE DATABASE` na serwerze produkcyjnym przeszedłby
  # (uprawnienia ma), a potem wlalibyśmy tam 49 tabel obok żywej bazy.
  # Railway daje oba adresy — wewnętrzny i publiczny — więc łapiemy oba
  # kształty, nie tylko ten, którym łączy się człowiek z laptopa.
  local adres
  for adres in "${SERWER}" "${DSN_ZRODLA}"; do
    [[ -n "${adres}" ]] || continue
    case "${adres}" in
      *proxy.rlwy.net* | *railway.internal* | *.up.railway.app*)
        if [[ "${adres}" == "${SERWER}" ]]; then
          padnij 21 \
            'Adres --serwer wskazuje na Railwaya, czyli na to samo miejsce, co produkcja.' \
            'Baza próbna zakłada się NA INNYM serwerze: lokalnym Postgresie albo' \
            'świeżym serwisie w NOWYM projekcie. Patrz docs/infra/KOPIE_I_ODTWORZENIE.md §8.'
        fi
        # `--zrodlo` z Railwaya jest w porządku: z niego tylko CZYTAMY
        # (`SELECT count(*)`), i o to w porównaniu chodzi.
        log "OSTRZEŻENIE: --zrodlo wskazuje na Railwaya — liczniki czytam z produkcji (tylko SELECT)."
        ;;
    esac
  done

  ok "bezpiecznik 1: baza celu \"${BAZA}\" jest nazwą próbną, serwer nie jest produkcyjny"
}

# =============================================================================
#  BEZPIECZNIK 2 — PYTAMY SERWER, DO CZEGO NAPRAWDĘ JESTEŚMY PODŁĄCZENI
# =============================================================================
#
#  Drugi bezpiecznik nie wierzy pierwszemu i nie wierzy sobie: pyta SERWER,
#  jak nazywa się baza, w której właśnie jesteśmy, i czy jest pusta.
#
#  DLACZEGO TO NIE JEST TEN SAM BEZPIECZNIK DRUGI RAZ — ZMIERZONE
#  Bo nazwa w DSN-ie NIE MUSI być nazwą bazy, do której libpq się połączy.
#  Parametr z części zapytania wygrywa z nazwą ze ścieżki:
#
#      psql "postgresql://…/proba_odtworzenia_test?dbname=kuking_test_wt_kopie" \
#           -Atc 'SELECT current_database()'
#      → kuking_test_wt_kopie          (sprawdzone 11.09.2026, psql 16.13)
#
#  Bezpiecznik 1 widzi w takim adresie nazwę próbną i przepuszcza — bo
#  patrzy na to, co KAZANO. Dopiero serwer mówi, gdzie naprawdę jesteśmy.
#  To samo dotyczy `PGDATABASE` w środowisku i pliku `~/.pg_service.conf`:
#  jedno i drugie potrafi przekierować połączenie bez zmiany naszego napisu.
#
#  Druga połowa tego bezpiecznika to PUSTOŚĆ. „Czysta baza” z zadania nie
#  jest wygodą — jest warunkiem, żeby liczniki po odtworzeniu cokolwiek
#  znaczyły (przy zastanych danych liczba wierszy to suma dwóch rzeczy)
#  i żeby nie dolać zrzutu do bazy, która czemuś SŁUŻY. Baza produkcyjna
#  nigdy nie jest pusta, więc ten jeden warunek łapie każdy cel, który
#  przeżył bezpiecznik 1 przez przekierowanie wyżej.
bezpiecznik_serwera() {
  local dsn="$1"

  local nazwa_z_serwera
  nazwa_z_serwera="$(psql "${dsn}" --no-password --quiet --no-align --tuples-only \
    --command 'SELECT current_database()' 2>/dev/null | tr -d '[:space:]')" || true

  if [[ "${nazwa_z_serwera}" != "${BAZA}" ]]; then
    padnij 22 \
      "Zadeklarowano bazę \"${BAZA}\", a serwer mówi, że jesteśmy w \"${nazwa_z_serwera:-?}\"." \
      'Najczęstsza przyczyna: `?dbname=` albo `PGDATABASE` przekierowuje połączenie' \
      'gdzie indziej, niż mówi ścieżka w adresie. NIE ODTWARZAM — to jest dokładnie' \
      'ta pomyłka, po której zrzut wlewa się do żywej bazy.'
  fi

  local ile_tabel
  ile_tabel="$(psql "${dsn}" --no-password --quiet --no-align --tuples-only --command \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" \
    2>/dev/null | tr -d '[:space:]')" || true

  if [[ ! "${ile_tabel}" =~ ^[0-9]+$ ]]; then
    padnij 22 \
      "Nie udało się odczytać liczby tabel w \"${BAZA}\" — nie wiem, czy baza jest pusta." \
      'Bez tej wiedzy nie odtwarzam: „nie wiemy” liczy się jak nieprzejście.'
  fi

  if ((ile_tabel > 0)); then
    padnij 23 \
      "Baza \"${BAZA}\" nie jest pusta (${ile_tabel} tabel w schemacie public)." \
      'Próba odtworzenia wymaga CZYSTEJ bazy: inaczej liczniki po odtworzeniu' \
      'są sumą zrzutu i tego, co tam leżało, a baza czemuś służy.' \
      "Skasuj ją świadomie (DROP DATABASE ${BAZA}) albo podaj inną --baza."
  fi

  ok "bezpiecznik 2: serwer potwierdza bazę \"${nazwa_z_serwera}\", pustą (0 tabel)"
}

# =============================================================================
#  BEZPIECZNIK 3 — TOŻSAMOŚĆ INSTANCJI DOCELOWEJ (#594)
# =============================================================================
#
#  DLACZEGO ISTNIEJE — ZMIERZONE 17.09.2026
#  Bezpiecznik 1 patrzy na NAZWĘ HOSTA. Za tunelem (`railway connect postgres
#  --tunnel-only`) produkcja nazywa się `127.0.0.1` i przechodzi bez słowa.
#  Bezpiecznik 2 pyta o bazę CELU — a ta jest świeżo założona, więc rzeczywiście
#  jest pusta i rzeczywiście nazywa się `proba_odtworzenia_*`.
#
#  Odtworzone: TEN SAM klaster, na którym stała żywa instalacja Kukinga,
#  został odrzucony pod adresem `*.proxy.rlwy.net` (kod 21) i PRZYJĘTY pod
#  `127.0.0.1` — baza powstała, `pg_restore` wlał na tę instancję komplet
#  danych osobowych, a skrypt wypisał przy tym „serwer nie jest produkcyjny”.
#  Blokada po nazwie hosta nie jest więc blokadą produkcji; jest blokadą
#  jednego sposobu jej zapisania.
#
#  CO PYTA TEN BEZPIECZNIK
#  O tożsamość KLASTRA, a nie o napis. `system_identifier` z
#  `pg_control_system()` nadaje `initdb` i żaden tunel go nie zmienia. Gdy ta
#  funkcja jest niedostępna (rola bez uprawnień), bierzemy odcisk zastępczy
#  z wartości dostępnych każdemu: czasu startu postmastera, wersji serwera
#  i OID-u `template0`. Odcisk zastępczy zmienia się po restarcie serwera —
#  i wtedy potwierdzenie trzeba wkleić jeszcze raz. To jest cena za to, żeby
#  brak uprawnień nie kończył się przepuszczeniem produkcji.
#
#  KIEDY PRZEPUSZCZA BEZ PYTANIA
#  Gdy klaster docelowy jest TYM SAMYM klastrem, który to repozytorium ma
#  skonfigurowany jako swoją bazę (`DB_HOST`/`DB_PORT` ze środowiska, a gdy
#  ich nie ma — z `.env`). To jest Postgres dewelopera i ćwiczenie na nim ma
#  zostać JEDNĄ komendą (`--petla-lokalna`).
#
#  KAŻDY INNY klaster wymaga potwierdzenia: `--instancja <odcisk>` albo
#  zmiennej `PROBA_INSTANCJA`. Odcisk skrypt wypisuje w odmowie, więc drugie
#  uruchomienie jest wklejeniem — ale wklejeniem ŚWIADOMYM, po przeczytaniu,
#  do czego się podłączył. Tego kroku nie da się przejść przez pomyłkę
#  w adresie, bo pomyłka daje inny odcisk.
#
#  CZEGO NIE UDAJE: to nie jest dowód, że cel NIE JEST produkcją. To jest
#  wymuszenie, żeby człowiek nazwał instancję, na którą wlewa dane — i żeby
#  zrobił to raz na instancję, a nie raz na życie.
odcisk_instancji() {
  local dsn="$1" surowy

  surowy="$(psql "${dsn}" --no-password --quiet --no-align --tuples-only \
    --command 'SELECT system_identifier FROM pg_control_system()' 2>/dev/null \
    | tr -d '[:space:]')" || true

  if [[ ! "${surowy}" =~ ^[0-9]+$ ]]; then
    surowy="$(psql "${dsn}" --no-password --quiet --no-align --tuples-only --command \
      "SELECT pg_postmaster_start_time()::text || '|' || version() || '|' ||
              (SELECT oid FROM pg_database WHERE datname = 'template0')" \
      2>/dev/null | tr -d '[:space:]')" || true
  fi

  [[ -n "${surowy}" ]] || return 1
  printf '%s' "$(printf '%s' "${surowy}" | sha256sum | cut -c1-16)"
}

bezpiecznik_instancji() {
  local odcisk_celu
  odcisk_celu="$(odcisk_instancji "${SERWER}")" || padnij 24 \
    "Nie udało się odczytać tożsamości instancji pod $(bez_hasla "${SERWER}")." \
    'Bez niej nie wiem, DO CZEGO wlewam zrzut — a „nie wiem” liczy się tu' \
    'jak nieprzejście. Sprawdź, czy adres i poświadczenie są poprawne.'

  ODCISK_CELU="${odcisk_celu}"

  # 1. Potwierdzenie podane wprost wygrywa ze wszystkim innym.
  if [[ -n "${INSTANCJA_POTWIERDZONA}" ]]; then
    if [[ "${INSTANCJA_POTWIERDZONA}" != "${ODCISK_CELU}" ]]; then
      padnij 24 \
        "Potwierdzono instancję \"${INSTANCJA_POTWIERDZONA}\", a pod adresem stoi \"${ODCISK_CELU}\"." \
        'To NIE JEST ta instancja, o której myślisz — adres wskazuje gdzie indziej.' \
        'Nie odtwarzam.'
    fi
    ok "bezpiecznik 3: instancja docelowa potwierdzona jawnie (${ODCISK_CELU})"
    return 0
  fi

  # 2. Bez potwierdzenia przechodzi wyłącznie własny Postgres tego repozytorium.
  local dsn_lokalny odcisk_lokalny=''
  dsn_lokalny="$(dsn_z_env)"

  if [[ -n "${dsn_lokalny}" ]]; then
    schowaj_haslo_z_dsn "$(podmien_baze_w_dsn "${dsn_lokalny}" postgres)"
    dsn_lokalny="${DSN_BEZ_HASLA}"
    odcisk_lokalny="$(odcisk_instancji "${dsn_lokalny}")" || odcisk_lokalny=''
  fi

  if [[ -n "${odcisk_lokalny}" && "${odcisk_lokalny}" == "${ODCISK_CELU}" ]]; then
    ok "bezpiecznik 3: cel to własny Postgres tego repozytorium (${ODCISK_CELU})"
    return 0
  fi

  padnij 24 \
    "Instancja docelowa (${ODCISK_CELU}) NIE JEST Postgresem tego repozytorium." \
    'Nazwa hosta niczego tu nie dowodzi: za tunelem `railway connect` produkcja' \
    'też nazywa się 127.0.0.1, a bazy próbnej nie da się odróżnić od świeżej.' \
    'Zobacz, do czego naprawdę jesteś podłączony, i potwierdź to JAWNIE:' \
    "  --instancja ${ODCISK_CELU}" \
    '(albo zmienną PROBA_INSTANCJA). Jeśli tego odcisku nie rozpoznajesz —' \
    'to jest dokładnie ten przebieg, którego nie wolno uruchomić.'
}

# =============================================================================
#  Pomocnicze: pytanie do bazy próbnej o jedną liczbę
# =============================================================================
liczba() {
  local dsn="$1" zapytanie="$2" wynik
  wynik="$(psql "${dsn}" --no-password --quiet --no-align --tuples-only \
    --command "${zapytanie}" 2>/dev/null | tr -d '[:space:]')" || true
  [[ "${wynik}" =~ ^-?[0-9]+$ ]] || wynik=''
  printf '%s' "${wynik}"
}

# =============================================================================
#  SONDA ZACHOWANIA — zapis, który MUSI zostać odrzucony
# =============================================================================
#
#  Każda sonda idzie w transakcji i kończy się `ROLLBACK`, a gdy zapis
#  zostanie odrzucony (czyli gdy sonda robi to, po co jest), `ON_ERROR_STOP`
#  przerywa `psql` i transakcja pada razem z połączeniem. Po sondzie w bazie
#  próbnej nie zostaje ani jeden wiersz — sprawdzane w
#  `tests/skrypty/proba-odtworzenia.sh`.
#
#  Sonda jest zaliczona, gdy `psql` kończy się BŁĘDEM, a w treści błędu jest
#  spodziewany napis. Dwa warunki, nie jeden: sam niezerowy kod wyjścia
#  potwierdziłby też literówkę w SQL-u sondy, a taka „zielona” sonda
#  dowodziłaby dokładnie niczego.
sonda_musi_zostac_odrzucona() {
  local dsn="$1" opis="$2" oczekiwany_napis="$3" sql="$4" kod_porazki="$5"
  local plik_bledu
  plik_bledu="$(mktemp -p "${KATALOG_ROBOCZY}")"

  if psql "${dsn}" --no-password --quiet --variable=ON_ERROR_STOP=1 \
    >/dev/null 2>"${plik_bledu}" <<<"${sql}"; then
    local tresc
    tresc="$(tr '\n' ' ' <"${plik_bledu}")"
    rm -f "${plik_bledu}"
    padnij "${kod_porazki}" \
      "${opis}: zapis PRZESZEDŁ, a miał zostać odrzucony." \
      'Odtworzona baza NIE NIESIE tej gwarancji — zrzut stracił ją po drodze.' \
      "Wyjście psql: ${tresc:-brak}"
  fi

  if ! grep -qF "${oczekiwany_napis}" "${plik_bledu}"; then
    local tresc
    tresc="$(tr '\n' ' ' <"${plik_bledu}")"
    rm -f "${plik_bledu}"
    padnij "${kod_porazki}" \
      "${opis}: zapis został odrzucony, ale NIE z tego powodu, o który pytamy." \
      "Spodziewany fragment komunikatu: ${oczekiwany_napis}" \
      "Otrzymano: ${tresc:-brak}" \
      'Sonda bez tego dopasowania przechodziłaby też na literówce w SQL-u.'
  fi

  rm -f "${plik_bledu}"
  ok "${opis}"
}

# =============================================================================
#  KROK 1 — narzędzia
# =============================================================================
sprawdz_narzedzia() {
  local narzedzie
  for narzedzie in psql pg_restore; do
    command -v "${narzedzie}" >/dev/null 2>&1 || padnij 10 \
      "Brak narzędzia ${narzedzie}." \
      'Doinstaluj klienta PostgreSQL w wersji NIE STARSZEJ niż serwer, z którego' \
      'pochodzi zrzut (numer stoi w pliku .meta w polu `pg_dump`).'
  done

  if [[ "${PLIK_ZRZUTU}" == *.cms ]]; then
    command -v openssl >/dev/null 2>&1 || padnij 10 \
      'Brak openssl, a zrzut jest zaszyfrowany (.cms).'
    [[ -n "${PLIK_KLUCZA}" ]] || padnij 2 \
      'Zrzut jest zaszyfrowany (.cms), a nie podano --klucz.' \
      'Klucz PRYWATNY jest w menedżerze haseł i na nośniku offline —' \
      'docs/infra/KOPIE_I_ODTWORZENIE.md §7.1.'
    [[ -r "${PLIK_KLUCZA}" ]] || padnij 2 "Nie mogę odczytać klucza ${PLIK_KLUCZA}."
  fi
}

# =============================================================================
#  KROK 2 — zrzut: istnienie, rozmiar, ewentualne odszyfrowanie
#
#  ROZMIAR JEST PIERWSZĄ RZECZĄ, BO ZERO BAJTÓW TO NAJCZĘSTSZY KSZTAŁT
#  „kopii, której nie ma”: przerwana wysyłka, pełny dysk, `> plik` bez zapisu.
#  Plik jest, `ls` go pokazuje, data się zgadza — i nie ma w nim nic.
# =============================================================================
przygotuj_zrzut() {
  [[ -f "${PLIK_ZRZUTU}" ]] || padnij 40 \
    "Zrzutu ${PLIK_ZRZUTU} nie ma." \
    'Kopia, której nie ma, jest widoczna od razu — i to jest jej jedyna zaleta' \
    'wobec kopii pustej. Sprawdź, czy nazwa i katalog są te, o których myślisz.'

  local rozmiar
  rozmiar="$(stat -c %s "${PLIK_ZRZUTU}")"

  if ((rozmiar < MIN_BAJTOW)); then
    padnij 40 \
      "Zrzut ma ${rozmiar} B, a minimum to ${MIN_BAJTOW} B." \
      'Zrzut tej wielkości nie jest bazą Kukinga: to albo plik pusty, albo' \
      'zrzut pustej bazy, albo urwana wysyłka. NIE ODTWARZAM — kopia, która' \
      'cicho zapisuje zero bajtów, jest gorsza od braku kopii, bo usypia.'
  fi

  ok "zrzut: ${PLIK_ZRZUTU##*/}, ${rozmiar} B"

  if [[ "${PLIK_ZRZUTU}" != *.cms ]]; then
    ZRZUT_JAWNY="${PLIK_ZRZUTU}"
    return 0
  fi

  # Odszyfrowanie. To jedyny moment całej tej warstwy, w którym klucz
  # prywatny jest w ogóle potrzebny — i jedyny, w którym da się sprawdzić,
  # że para kluczy naprawdę do siebie pasuje. Plik jawny ląduje w katalogu
  # roboczym i ginie razem z nim (`trap`).
  ZRZUT_JAWNY="${KATALOG_ROBOCZY}/odszyfrowany.dump"
  log 'odszyfrowuję (openssl cms)…'

  local plik_bledu="${KATALOG_ROBOCZY}/openssl.txt"
  local start koniec
  start="$(date +%s)"

  if ! openssl cms -decrypt -binary -inform DER \
    -in "${PLIK_ZRZUTU}" -inkey "${PLIK_KLUCZA}" -out "${ZRZUT_JAWNY}" 2>"${plik_bledu}"; then
    log 'Wyjście openssl:'
    sed 's/^/  openssl: /' "${plik_bledu}" >&2
    padnij 43 \
      'Odszyfrowanie nie udało się.' \
      'Najczęstsza przyczyna: to nie ten klucz prywatny (odcisk certyfikatu jest' \
      'w pliku .meta obok zrzutu — porównaj go z odciskiem swojego klucza).' \
      'Gdyby openssl pytał o certyfikat odbiorcy, wytnij go z .meta i dodaj' \
      '-recip cert.pem — procedura w KOPIE_I_ODTWORZENIE.md §7.4.'
  fi

  koniec="$(date +%s)"
  CZAS_ODSZYFROWANIA=$((koniec - start))
  ok "odszyfrowane w ${CZAS_ODSZYFROWANIA} s ($(stat -c %s "${ZRZUT_JAWNY}") B)"

  sprawdz_skrot_z_meta
}

# =============================================================================
#  SKRÓT Z PLIKU `.meta` — JEDYNA KONTROLA SPÓJNOŚCI, JAKĄ MA TA WARSTWA
#
#  ZMIERZONE 17.09.2026. CMS `EnvelopedData` z AES-256-CBC NIE NIESIE
#  UWIERZYTELNIENIA: przekłamanie bajtów w środku szyfrogramu odszyfrowuje się
#  BEZ BŁĘDU, a `openssl` kończy się zerem. W przebiegu kontrolnym taki plik
#  przeszedł odszyfrowanie i spis archiwum, a ćwiczenie padło dopiero na
#  `pg_restore` (kod 50, „odtworzenie nie udało się") — czyli PO założeniu
#  bazy i po wlaniu do niej części danych, i z komunikatem wskazującym na
#  serwer, a nie na uszkodzony plik.
#
#  Skrót jawnego zrzutu jest zapisywany w pliku `.meta` obok kopii (§7.2)
#  przez OBA skrypty kopii. Do tej pory nikt go nie czytał. Teraz czyta go ta
#  funkcja — i uszkodzona kopia zatrzymuje się TU, zanim cokolwiek powstanie.
#
#  Brak `.meta` nie jest błędem: kopia sprzed tej zmiany i ręczny `pg_dump`
#  go nie mają. Wtedy mówimy wprost, że tej kontroli nie było — cicho
#  pominięta kontrola jest gorsza od jej braku.
# =============================================================================
sprawdz_skrot_z_meta() {
  local meta="${PLIK_ZRZUTU%.cms}"
  meta="${meta%.dump}.meta"

  if [[ ! -r "${meta}" ]]; then
    log "OSTRZEŻENIE: nie ma pliku ${meta##*/} — skrótu zrzutu NIE MAM z czym porównać."
    return 0
  fi

  local oczekiwany
  oczekiwany="$(sed -n -E 's/^sha256_jawnego:[[:space:]]*([0-9a-f]{64}).*/\1/p' "${meta}" | head -1)"

  if [[ -z "${oczekiwany}" ]]; then
    log "OSTRZEŻENIE: w ${meta##*/} nie ma pola sha256_jawnego — skrótu nie porównuję."
    return 0
  fi

  local policzony
  policzony="$(sha256sum "${ZRZUT_JAWNY}" | cut -d' ' -f1)"

  if [[ "${policzony}" != "${oczekiwany}" ]]; then
    padnij 44 \
      'Odszyfrowany zrzut NIE ZGADZA SIĘ ze skrótem z pliku .meta.' \
      "  w .meta:    ${oczekiwany}" \
      "  policzony:  ${policzony}" \
      'Szyfrogram jest uszkodzony (AES-CBC odszyfrowuje śmieci BEZ BŁĘDU) albo' \
      'plik .meta należy do innej kopii. NIE ODTWARZAM — nic jeszcze nie' \
      'powstało, więc nie ma czego sprzątać.'
  fi

  ok "skrót odszyfrowanego zrzutu zgadza się z .meta (${policzony:0:16}…)"
}

# =============================================================================
#  KROK 3 — spis treści archiwum, PRZED wlaniem czegokolwiek do bazy
#
#  `pg_restore --list` czyta nagłówek i katalog archiwum. Archiwum obcięte
#  na 80% (padło łącze przy pobieraniu z bucketu) albo zaszyfrowane innym
#  kluczem wysypuje się TUTAJ, a nie w połowie wlewania danych do bazy.
# =============================================================================
przeczytaj_spis() {
  local spis plik_bledu="${KATALOG_ROBOCZY}/spis.txt"

  if ! pg_restore --list "${ZRZUT_JAWNY}" >"${plik_bledu}" 2>"${KATALOG_ROBOCZY}/blad.txt"; then
    sed 's/^/  pg_restore: /' "${KATALOG_ROBOCZY}/blad.txt" >&2
    padnij 41 \
      'pg_restore nie potrafi odczytać tego archiwum.' \
      'Plik jest uszkodzony, obcięty albo to nie jest zrzut w formacie custom.' \
      'Sprawdź sha256 wobec pola `sha256_jawnego` z pliku .meta — jeśli się nie' \
      'zgadza, plik zepsuł się po drodze i trzeba pobrać go jeszcze raz.'
  fi

  spis="$(cat "${plik_bledu}")"
  TABEL_W_ARCHIWUM="$(grep -c 'TABLE DATA' <<<"${spis}" || true)"

  if ((TABEL_W_ARCHIWUM < MIN_TABEL)); then
    padnij 42 \
      "W archiwum jest ${TABEL_W_ARCHIWUM} tabel z danymi, a minimum to ${MIN_TABEL}." \
      'Najczęstsza przyczyna: zrzut zrobiono z INNEJ albo ze świeżej, pustej bazy.' \
      'Taki plik jest poprawnym archiwum i wygląda jak kopia — i nie ma w nim' \
      'niczyich danych.'
  fi

  # Wersja `pg_dump`, która zrobiła zrzut, stoi w nagłówku archiwum. Starszy
  # `pg_restore` niż `pg_dump` odmawia pracy — a dowiedzieć się o tym warto
  # teraz, nie po dwudziestu minutach wlewania.
  local wersja_zrzutu wersja_klienta
  wersja_zrzutu="$(grep -m1 -oE 'Dumped by pg_dump version: [0-9]+' <<<"${spis}" \
    | grep -oE '[0-9]+$' || true)"
  wersja_klienta="$(pg_restore --version | sed -E 's/[^0-9]*([0-9]+).*/\1/')"

  if [[ "${wersja_zrzutu}" =~ ^[0-9]+$ ]] && [[ "${wersja_klienta}" =~ ^[0-9]+$ ]]; then
    if ((wersja_klienta < wersja_zrzutu)); then
      padnij 11 \
        "Zrzut zrobił pg_dump ${wersja_zrzutu}, a tu jest pg_restore ${wersja_klienta}." \
        'Starszy klient odmówi pracy albo wczyta archiwum niekompletnie.' \
        "Doinstaluj klienta PostgreSQL ${wersja_zrzutu} lub nowszego."
    fi
    ok "archiwum: ${TABEL_W_ARCHIWUM} tabel z danymi, pg_dump ${wersja_zrzutu}, pg_restore ${wersja_klienta}"
  else
    ok "archiwum: ${TABEL_W_ARCHIWUM} tabel z danymi"
  fi
}

# =============================================================================
#  KROK 4 — czysta baza próbna
# =============================================================================
zaloz_baze_probna() {
  # `psql -l` zamiast `CREATE DATABASE … IF NOT EXISTS` (czego PostgreSQL nie
  # ma): bazę zastaną wolno użyć, ale tylko wtedy, gdy bezpiecznik 2 potwierdzi,
  # że jest pusta. Nie kasujemy jej z własnej inicjatywy.
  local istnieje
  istnieje="$(liczba "${SERWER}" \
    "SELECT count(*) FROM pg_database WHERE datname = '${BAZA}'")"

  if [[ "${istnieje}" != '1' ]]; then
    if ! psql "${SERWER}" --no-password --quiet \
      --command "CREATE DATABASE \"${BAZA}\"" >/dev/null 2>"${KATALOG_ROBOCZY}/blad.txt"; then
      sed 's/^/  psql: /' "${KATALOG_ROBOCZY}/blad.txt" >&2
      padnij 30 \
        "Nie udało się założyć bazy \"${BAZA}\" na $(bez_hasla "${SERWER}")." \
        'Sprawdź, czy DSN z --serwer wskazuje bazę utrzymaniową (np. `postgres`)' \
        'i czy to konto ma prawo CREATE DATABASE.'
    fi
    # Sprzątanie kasuje bazę próbną WYŁĄCZNIE wtedy, gdy założył ją ten
    # przebieg. Pierwsza wersja tego skryptu kasowała ją zawsze — i przy
    # odmowie z bezpiecznika 2 („baza nie jest pusta”) po cichu kasowała
    # dokładnie tę bazę, której właśnie odmówiła dotknąć. Zmierzone przy
    # kontroli ujemnej 11.09.2026: baza z jedną tabelą zniknęła po odmowie.
    BAZA_NASZA=1
    log "założona baza próbna ${BAZA}"
  else
    log "baza ${BAZA} już istnieje — NIE jest moja, więc jej nie skasuję; bezpiecznik 2 sprawdzi, czy jest pusta"
  fi

  # DSN bazy próbnej budujemy z DSN-u serwera, podmieniając ostatni segment
  # ścieżki. Świadomie NIE zgadujemy, czy to się udało — pyta o to
  # bezpiecznik 2, bo część zapytania w adresie potrafi tę podmianę
  # unieważnić (patrz komentarz przy `bezpiecznik_serwera`).
  DSN_PROBNY="${SERWER%/*}/${BAZA}"
  case "${SERWER}" in
    *\?*) DSN_PROBNY="${SERWER%%\?*}" && DSN_PROBNY="${DSN_PROBNY%/*}/${BAZA}?${SERWER#*\?}" ;;
  esac
}

# =============================================================================
#  KROK 5 — odtworzenie, z pomiarem czasu (to jest realne RTO)
# =============================================================================
odtworz() {
  log 'pg_restore…'
  local start koniec
  start="$(date +%s)"

  if ! pg_restore --dbname="${DSN_PROBNY}" --no-owner --no-privileges \
    --exit-on-error "${ZRZUT_JAWNY}" >/dev/null 2>"${KATALOG_ROBOCZY}/restore.txt"; then
    sed 's/^/  pg_restore: /' "${KATALOG_ROBOCZY}/restore.txt" >&2
    padnij 50 \
      'pg_restore zakończył się błędem — odtworzenie NIE UDAŁO SIĘ.' \
      'Wyjście jest wyżej. Najczęstsze przyczyny: brak rozszerzenia PostgreSQL' \
      'na serwerze docelowym (pg_trgm, unaccent, pgcrypto) albo wersja serwera' \
      'starsza niż ta, z której pochodzi zrzut.'
  fi

  koniec="$(date +%s)"
  CZAS_ODTWORZENIA=$((koniec - start))
  ok "pg_restore bez błędu, ${CZAS_ODTWORZENIA} s"
}

# =============================================================================
#  KROK 6 — CO NAPRAWDĘ STOI W ODTWORZONEJ BAZIE
#
#  Od tego miejsca skrypt przestaje sprawdzać, czy narzędzia się wykonały,
#  i zaczyna sprawdzać WYNIK. To jest cała różnica między „restore przebiegł”
#  a „mam z powrotem bazę Kukinga”.
# =============================================================================
sprawdz_tabele() {
  local ile
  ile="$(liczba "${DSN_PROBNY}" \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")"

  [[ "${ile}" =~ ^[0-9]+$ ]] || padnij 60 \
    'Nie udało się policzyć tabel w odtworzonej bazie.'

  if ((ile < MIN_TABEL)); then
    padnij 60 \
      "W odtworzonej bazie jest ${ile} tabel, a minimum to ${MIN_TABEL}." \
      "W archiwum było ich ${TABEL_W_ARCHIWUM} — czyli restore zgubił część po drodze."
  fi

  TABEL_W_BAZIE="${ile}"
  ok "tabel w odtworzonej bazie: ${ile} (w archiwum: ${TABEL_W_ARCHIWUM})"
}

sprawdz_wiersze() {
  local -a tabele=()
  IFS=',' read -r -a tabele <<<"${TABELE_DO_POLICZENIA}"

  local tabela
  for tabela in "${tabele[@]}"; do
    tabela="$(tr -d '[:space:]' <<<"${tabela}")"
    [[ -n "${tabela}" ]] || continue

    [[ "${tabela}" =~ ^[a-z_][a-z0-9_]*$ ]] || padnij 2 \
      "Nazwa tabeli \"${tabela}\" z --tabele nie wygląda na nazwę tabeli."

    local istnieje
    istnieje="$(liczba "${DSN_PROBNY}" \
      "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='${tabela}'")"

    if [[ "${istnieje}" != '1' ]]; then
      padnij 61 \
        "Tabeli \"${tabela}\" NIE MA w odtworzonej bazie." \
        'To jedna z tabel, po których poznaje się bazę Kukinga. Zrzut jest' \
        'albo z innej bazy, albo niekompletny.'
    fi

    local ile
    ile="$(liczba "${DSN_PROBNY}" "SELECT count(*) FROM \"${tabela}\"")"

    [[ "${ile}" =~ ^[0-9]+$ ]] || padnij 61 \
      "Nie udało się policzyć wierszy w \"${tabela}\"."

    if ((ile < MIN_WIERSZY)); then
      padnij 62 \
        "W tabeli \"${tabela}\" jest ${ile} wierszy, a minimum to ${MIN_WIERSZY}." \
        'Zrzut przeszedł wszystkie sprawdzenia kształtu i nie ma w nim danych —' \
        'dokładnie ten stan usypia najskuteczniej: plik jest, schemat jest,' \
        'liczba tabel się zgadza, a treści nie ma.'
    fi

    # Porównanie ze źródłem jest MOCNIEJSZE niż próg, bo próg mówi tylko
    # „coś tam jest”. Różnica dopuszczalna jest jedna: wiersze dopisane do
    # źródła PO zrobieniu zrzutu — dlatego źródło może mieć WIĘCEJ, nigdy
    # mniej.
    if [[ -n "${DSN_ZRODLA}" ]]; then
      local w_zrodle
      w_zrodle="$(liczba "${DSN_ZRODLA}" "SELECT count(*) FROM \"${tabela}\"")"

      if [[ ! "${w_zrodle}" =~ ^[0-9]+$ ]]; then
        log "OSTRZEŻENIE: nie udało się odczytać licznika \"${tabela}\" ze źródła — pomijam porównanie."
      elif ((ile > w_zrodle)); then
        padnij 63 \
          "W odtworzonej bazie jest ${ile} wierszy w \"${tabela}\", a w źródle ${w_zrodle}." \
          'Odtworzona baza ma WIĘCEJ danych niż źródło — to nie jest ta baza,' \
          'albo baza próbna nie była pusta.'
      else
        ok "wierszy w \"${tabela}\": ${ile} (źródło: ${w_zrodle})"
        continue
      fi
    fi

    ok "wierszy w \"${tabela}\": ${ile}"
  done
}

# =============================================================================
#  KROK 6B — LICZBA WIERSZY W KAŻDEJ TABELI, CO DO JEDNEGO
#
#  DLACZEGO TO NIE JEST TO SAMO, CO KROK 6
#  Krok 6 liczy wiersze w CZTERECH tabelach wybranych z nazwy i porównuje je
#  ze źródłem z tolerancją „źródło może mieć więcej". To dobra bramka dla
#  zrzutu z produkcji, która pisze w trakcie zrzucania — i za słaba dla
#  ćwiczenia, którego celem jest DOWÓD, że nic nie zginęło.
#
#  Tabela, o której nikt nie pomyślał, jest dokładnie tą, którą zrzut gubi
#  po cichu: `dziennik_zgod`, `recipe_versions`, `notifications`. Zrzut bez
#  jednej z nich przechodzi krok 6 bez jednego ostrzeżenia, bo krok 6 o nią
#  nie pyta.
#
#  Dlatego tutaj pytamy o WSZYSTKIE tabele schematu `public` — najpierw
#  w odtworzonej bazie, potem w źródle — i porównujemy pary. Przy `--scisle`
#  (czyli zawsze w pętli lokalnej) różnica choćby jednego wiersza kończy
#  przebieg kodem 63.
#
#  Kontrola ujemna tego kroku jest w `tests/skrypty/proba-odtworzenia.sh`:
#  kilka wierszy skasowanych w odtworzonej bazie PRZED porównaniem ma ten
#  skrypt OBLAĆ. Skrypt, który melduje sukces nie robiąc nic, to pułapka 5
#  z `docs/PULAPKI_TESTOW.md`.
# =============================================================================
spis_tabel() {
  psql "$1" --no-password --quiet --no-align --tuples-only --command "
    SELECT table_name FROM information_schema.tables
     WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
     ORDER BY table_name" 2>/dev/null | tr -d '\r' | sed '/^$/d'
}

# Liczba wierszy we WSZYSTKICH tabelach naraz, jednym zapytaniem. Pętla po
# tabelach z osobnym `psql` na każdą kosztowała przy 49 tabelach 49 połączeń
# i trwała dłużej niż samo odtworzenie.
liczniki_tabel() {
  local dsn="$1" zapytanie='' tabela pierwsza=1

  while IFS= read -r tabela; do
    [[ -n "${tabela}" ]] || continue
    ((pierwsza == 1)) || zapytanie+=" UNION ALL "
    pierwsza=0
    zapytanie+="SELECT '${tabela}' AS t, count(*) AS n FROM \"${tabela}\""
  done <<<"$2"

  [[ -n "${zapytanie}" ]] || return 0

  psql "${dsn}" --no-password --quiet --no-align --tuples-only --field-separator='|' \
    --command "SELECT t, n FROM (${zapytanie}) w ORDER BY t" 2>/dev/null | tr -d '\r' | sed '/^$/d'
}

porownaj_wszystkie_tabele() {
  if [[ -z "${DSN_ZRODLA}" ]]; then
    log 'bez --zrodlo nie ma z czym porównywać liczby wierszy tabela po tabeli — pomijam krok 6B.'
    return 0
  fi

  local tabele_w_probie tabele_w_zrodle
  tabele_w_probie="$(spis_tabel "${DSN_PROBNY}")"
  tabele_w_zrodle="$(spis_tabel "${DSN_ZRODLA}")"

  # Pułapka 2 z docs/PULAPKI_TESTOW.md: skan, który nie znalazł ŻADNEJ tabeli,
  # przeszedłby ten krok bez jednej różnicy — czyli zameldowałby sukces,
  # nie porównawszy niczego.
  local ile_probie ile_zrodle
  ile_probie="$(grep -c . <<<"${tabele_w_probie}" || true)"
  ile_zrodle="$(grep -c . <<<"${tabele_w_zrodle}" || true)"

  if ((ile_probie < MIN_TABEL)) || ((ile_zrodle < MIN_TABEL)); then
    padnij 63 \
      "Spis tabel jest za krótki: ${ile_probie} w próbie, ${ile_zrodle} w źródle (minimum ${MIN_TABEL})." \
      'Porównanie na tak krótkiej liście nie dowodziłoby niczego — brak różnic' \
      'znaczyłby wtedy „nie było czego porównać", a nie „wszystko się zgadza".'
  fi

  # Tabela, która JEST w źródle, a której NIE MA w odtworzonej bazie, to
  # najcichsza z możliwych strat: liczniki pozostałych zgadzają się co do
  # jednego, bo tej po prostu nikt nie liczy.
  local brakujace=''
  local tabela
  while IFS= read -r tabela; do
    [[ -n "${tabela}" ]] || continue
    grep -qxF "${tabela}" <<<"${tabele_w_probie}" || brakujace+=" ${tabela}"
  done <<<"${tabele_w_zrodle}"

  if [[ -n "${brakujace}" ]]; then
    padnij 63 \
      "W odtworzonej bazie NIE MA tabel, które są w źródle:${brakujace}" \
      'Zrzut zgubił je po drodze. Liczby wierszy w pozostałych tabelach mogą się' \
      'przy tym zgadzać co do jednego — i właśnie dlatego ten krok pyta o spis,' \
      'a nie tylko o liczby.'
  fi

  local liczniki_probie liczniki_zrodle
  liczniki_probie="$(liczniki_tabel "${DSN_PROBNY}" "${tabele_w_probie}")"
  liczniki_zrodle="$(liczniki_tabel "${DSN_ZRODLA}" "${tabele_w_zrodle}")"

  local rozjazdy='' linia nazwa w_probie w_zrodle
  TABEL_POROWNANYCH=0
  WIERSZY_W_PROBIE=0
  WIERSZY_W_ZRODLE=0

  while IFS='|' read -r nazwa w_probie; do
    [[ -n "${nazwa}" ]] || continue

    w_zrodle="$(sed -n -E "s/^${nazwa}\|//p" <<<"${liczniki_zrodle}" | head -n 1)"
    [[ -n "${w_zrodle}" ]] || w_zrodle='brak'

    TABEL_POROWNANYCH=$((TABEL_POROWNANYCH + 1))
    WIERSZY_W_PROBIE=$((WIERSZY_W_PROBIE + w_probie))
    [[ "${w_zrodle}" == 'brak' ]] || WIERSZY_W_ZRODLE=$((WIERSZY_W_ZRODLE + w_zrodle))

    if [[ "${w_zrodle}" == 'brak' ]]; then
      rozjazdy+=$'\n'"    ${nazwa}: w próbie ${w_probie}, w źródle NIE MA TEJ TABELI"
    elif ((SCISLE == 1)); then
      ((w_probie == w_zrodle)) || rozjazdy+=$'\n'"    ${nazwa}: w próbie ${w_probie}, w źródle ${w_zrodle}"
    else
      ((w_probie <= w_zrodle)) || rozjazdy+=$'\n'"    ${nazwa}: w próbie ${w_probie}, w źródle ${w_zrodle} (próba ma WIĘCEJ)"
    fi
  done <<<"${liczniki_probie}"

  if [[ -n "${rozjazdy}" ]]; then
    padnij 63 \
      'Liczba wierszy NIE ZGADZA SIĘ ze źródłem:'"${rozjazdy}" \
      '' \
      'Rozjazd choćby jednej tabeli znaczy, że z tej kopii NIE MA się bazy' \
      'Kukinga z powrotem — ma się jej część. Przy zrzucie z bazy, do której' \
      'ktoś pisze w trakcie, uruchom bez --scisle.'
  fi

  ok "porównano ${TABEL_POROWNANYCH} tabel co do jednego wiersza"
  ok "wierszy razem: ${WIERSZY_W_PROBIE} w odtworzonej bazie, ${WIERSZY_W_ZRODLE} w źródle"
}

# =============================================================================
#  KROK 6C — `php artisan migrate:status` NA ODTWORZONEJ BAZIE
#
#  Liczby wierszy mówią o DANYCH. Ten krok pyta o coś innego: czy odtworzona
#  baza jest tą bazą, do której pasuje dzisiejszy kod. Zrzut sprzed trzech
#  migracji odda komplet wierszy i przejdzie każdy poprzedni krok — a
#  aplikacja postawiona na nim wywróci się na pierwszej kolumnie, której
#  w nim nie ma.
#
#  Pytamy o WYNIK, nie o kod wyjścia: `migrate:status` kończy się zerem także
#  wtedy, gdy wypisze same „Pending". To pułapka 5 — narzędzie melduje sukces,
#  nie robiąc tego, po co je wołano.
# =============================================================================
sprawdz_migracje() {
  if [[ ! -f "${KATALOG_REPO}/artisan" ]]; then
    log 'nie widzę artisana — pomijam migrate:status (to ćwiczenie chodzi poza repozytorium?).'
    return 0
  fi

  local wyjscie
  # DB_URL przebija DB_HOST/DB_DATABASE w `config/database.php`, więc jednym
  # przestawieniem kierujemy artisana na bazę PRÓBNĄ i tylko na nią.
  if ! wyjscie="$(cd "${KATALOG_REPO}" && DB_URL="${DSN_PROBNY}" DB_DATABASE='' \
    php artisan migrate:status --no-ansi 2>&1)"; then
    padnij 64 \
      'php artisan migrate:status nie wykonał się na odtworzonej bazie.' \
      'Wyjście:' "${wyjscie}"
  fi

  # LICZYMY KOLUMNĘ STANU, NIE CAŁEGO WIERSZA — i to jest poprawka błędu,
  # który ten krok miał przy pierwszym uruchomieniu.
  #
  # `grep -c -i 'Pending'` meldował jedną migrację czekającą na bazie, w której
  # wszystkie były wykonane. Trafiał w NAZWĘ pliku:
  # `2026_09_09_300000_create_pending_email_changes_table .. [1] Ran`.
  # To jest pułapka 1 z docs/PULAPKI_TESTOW.md w czystej postaci — dopasowanie
  # do całego wiersza łapie to samo słowo skądinąd — tylko że tutaj wypadła
  # w drugą stronę niż zwykle: dała FAŁSZYWĄ CZERWIEŃ zamiast fałszywej
  # zieleni. Gdyby wypadła w tamtą, nikt by jej nie zauważył.
  #
  # `migrate:status` kończy każdy wiersz stanem: `[N] Ran` albo `Pending`.
  # Kotwiczymy na końcu wiersza i tylko tam.
  local wykonane czekajace
  wykonane="$(grep -cE '\[[0-9]+\][[:space:]]+Ran[[:space:]]*$' <<<"${wyjscie}" || true)"
  czekajace="$(grep -cE '(^|[^A-Za-z_])Pending[[:space:]]*$' <<<"${wyjscie}" || true)"

  if ((wykonane == 0)); then
    padnij 64 \
      'W odtworzonej bazie NIE MA ANI JEDNEJ wykonanej migracji.' \
      'Zrzut zgubił tabelę `migrations` — dane mogą być komplet, a baza i tak' \
      'nie wie, w jakim jest schemacie. Wyjście:' "${wyjscie}"
  fi

  if ((czekajace > 0)); then
    padnij 64 \
      "Odtworzona baza ma ${czekajace} migracji CZEKAJĄCYCH." \
      'To znaczy, że zrzut jest starszy niż kod w tym repozytorium: dane wrócą,' \
      'ale aplikacja postawiona na nich nie ruszy bez `php artisan migrate`.' \
      'Wyjście:' "${wyjscie}"
  fi

  MIGRACJI_WYKONANYCH="${wykonane}"
  ok "migrate:status na odtworzonej bazie: ${wykonane} wykonanych, 0 czekających"
}

# =============================================================================
#  KROK 7 — WYZWALACZE I OGRANICZENIA: NAJPIERW ŻE SĄ
# =============================================================================
sprawdz_wyzwalacze() {
  local ile
  ile="$(liczba "${DSN_PROBNY}" "
    SELECT count(*) FROM pg_trigger t
      JOIN pg_class c ON c.oid = t.tgrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE NOT t.tgisinternal AND n.nspname = 'public'")"

  [[ "${ile}" =~ ^[0-9]+$ ]] || padnij 70 'Nie udało się policzyć wyzwalaczy.'

  if ((ile < MIN_WYZWALACZY)); then
    padnij 70 \
      "W odtworzonej bazie jest ${ile} wyzwalaczy, a ma być co najmniej ${MIN_WYZWALACZY}." \
      'W tym repozytorium wyzwalacze niosą część gwarancji danych (D-072, D-080),' \
      'więc zrzut, który je gubi, jest zrzutem bezwartościowym — mimo zgodnych' \
      'liczb tabel i wierszy.'
  fi

  local wyzwalacz
  for wyzwalacz in ${WYZWALACZE_WYMAGANE}; do
    local jest
    jest="$(liczba "${DSN_PROBNY}" "
      SELECT count(*) FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE NOT t.tgisinternal AND n.nspname = 'public' AND t.tgname = '${wyzwalacz}'")"

    if [[ "${jest}" != '1' ]]; then
      padnij 70 \
        "Brak wyzwalacza \"${wyzwalacz}\" w odtworzonej bazie." \
        'Ten wyzwalacz jest barierą, której nie da się obejść żadną drogą zapisu' \
        '— i jest w bazie właśnie dlatego, że walidacja w PHP jest dodatkiem,' \
        'nie zamiennikiem (AGENTS.md §6).'
    fi

    # Wyzwalacz wyłączony (`ALTER TABLE … DISABLE TRIGGER`) siedzi w katalogu
    # jak każdy inny i nie pilnuje niczego. `tgenabled='O'` znaczy „włączony
    # w zwykłym trybie”; `'D'` to wyłączony.
    local wlaczony
    wlaczony="$(liczba "${DSN_PROBNY}" "
      SELECT count(*) FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE NOT t.tgisinternal AND n.nspname = 'public'
         AND t.tgname = '${wyzwalacz}' AND t.tgenabled <> 'D'")"

    if [[ "${wlaczony}" != '1' ]]; then
      padnij 70 \
        "Wyzwalacz \"${wyzwalacz}\" jest w bazie, ale WYŁĄCZONY (tgenabled='D')." \
        'Wyłączony wyzwalacz wygląda w katalogu systemowym jak działający.'
    fi
  done

  ok "wyzwalacze: ${ile}, wszystkie trzy nazwane obecne i włączone"
}

sprawdz_ograniczenia() {
  local check unikalne obce

  check="$(liczba "${DSN_PROBNY}" "
    SELECT count(*) FROM pg_constraint con
      JOIN pg_class c ON c.oid = con.conrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND con.contype = 'c'")"
  unikalne="$(liczba "${DSN_PROBNY}" "
    SELECT count(*) FROM pg_constraint con
      JOIN pg_class c ON c.oid = con.conrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND con.contype = 'u'")"
  obce="$(liczba "${DSN_PROBNY}" "
    SELECT count(*) FROM pg_constraint con
      JOIN pg_class c ON c.oid = con.conrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND con.contype = 'f'")"

  [[ "${check}" =~ ^[0-9]+$ && "${unikalne}" =~ ^[0-9]+$ && "${obce}" =~ ^[0-9]+$ ]] \
    || padnij 72 'Nie udało się policzyć ograniczeń w odtworzonej bazie.'

  ((check >= MIN_CHECK)) || padnij 72 \
    "Ograniczeń CHECK jest ${check}, a ma być co najmniej ${MIN_CHECK}." \
    'CHECK-i w tej bazie trzymają stany kont, rodzaje zgłoszeń i zakresy' \
    'liczb — baza bez nich przyjmie wartości, których aplikacja nie umie obsłużyć.'

  ((unikalne >= MIN_UNIQUE)) || padnij 73 \
    "Ograniczeń UNIQUE jest ${unikalne}, a ma być co najmniej ${MIN_UNIQUE}."

  ((obce >= MIN_KLUCZY_OBCYCH)) || padnij 74 \
    "Kluczy obcych jest ${obce}, a ma być co najmniej ${MIN_KLUCZY_OBCYCH}." \
    'Bez kluczy obcych odtworzona baza zbiera sieroty przy pierwszym kasowaniu konta.'

  ok "ograniczenia: CHECK ${check}, UNIQUE ${unikalne}, klucze obce ${obce}"
}

# =============================================================================
#  KONTROLA DODATNIA DO CZTERECH SOND ZACHOWANIA
#
#  Bez niej wszystkie cztery sondy przeszłyby także w bazie, w której KAŻDY
#  zapis jest odrzucany — bo baza jest tylko do odczytu, bo konto nie ma
#  uprawnień, bo skończyło się miejsce na dysku. Cztery „zapis odrzucony”
#  byłyby wtedy dowodem na to, że nic nie działa, przedstawionym jako dowód,
#  że wszystko działa. To jest pułapka 4 z `docs/PULAPKI_TESTOW.md`
#  („asercja tylko negatywna przechodzi, gdy mechanizm nie działa wcale”),
#  przeniesiona ze zwykłego testu na skrypt operacyjny.
#
#  Zapis w tej funkcji MUSI przejść: obserwowanie pary BEZ blokady jest
#  dozwolone. Funkcja jest osobna, bo w tym kształcie da się ją sprawdzić
#  wprost — `tests/skrypty/proba-odtworzenia.sh` woła ją dwa razy: na bazie
#  normalnej (ma przejść) i na bazie ustawionej na tylko-do-odczytu (ma
#  OBLAĆ SIĘ i powiedzieć dlaczego).
# =============================================================================
kontrola_dodatnia_zapisu() {
  local dsn="$1"
  local a='aaaaaaaa-0000-4000-8000-000000000001'
  local b='aaaaaaaa-0000-4000-8000-000000000002'
  local plik_bledu="${KATALOG_ROBOCZY}/dodatnia.txt"

  if ! psql "${dsn}" --no-password --quiet --variable=ON_ERROR_STOP=1 \
    >/dev/null 2>"${plik_bledu}" <<SQL
BEGIN;
INSERT INTO users (id, email, password)
  VALUES ('${a}', 'proba-odtworzenia-1@example.invalid', 'x'),
         ('${b}', 'proba-odtworzenia-2@example.invalid', 'x');
INSERT INTO follows (follower_id, followed_id) VALUES ('${a}', '${b}');
ROLLBACK;
SQL
  then
    sed 's/^/  psql: /' "${plik_bledu}" >&2
    padnij 71 \
      'Kontrola dodatnia OBLAŁA SIĘ: zapis, który ma być dozwolony (obserwowanie' \
      'pary bez blokady), też został odrzucony.' \
      'Czyli cztery sondy wyżej nie dowodzą niczego o barierach — w tej bazie' \
      'nie przechodzi ŻADEN zapis. Sprawdź uprawnienia konta i miejsce na dysku.'
  fi

  ok 'kontrola dodatnia: zapis dozwolony PRZESZEDŁ (sondy mierzą bariery, nie brak uprawnień)'
}

# =============================================================================
#  KROK 8 — ŻE DZIAŁAJĄ. Cztery sondy, każda w transakcji do wycofania.
# =============================================================================
sprawdz_zachowanie() {
  # Identyfikatory stałe i jawnie „próbne”: gdyby kiedykolwiek przeżyły
  # ROLLBACK, od razu widać, skąd są. Adresy w domenie `.invalid`
  # (RFC 2606) — nie istnieje i istnieć nie może.
  local a='aaaaaaaa-0000-4000-8000-000000000001'
  local b='aaaaaaaa-0000-4000-8000-000000000002'

  sonda_musi_zostac_odrzucona "${DSN_PROBNY}" \
    'wyzwalacz follows: blokada ma pierwszeństwo przed obserwowaniem' \
    'Blokada ma pierwszenstwo' \
    "BEGIN;
     INSERT INTO users (id, email, password)
       VALUES ('${a}', 'proba-odtworzenia-1@example.invalid', 'x'),
              ('${b}', 'proba-odtworzenia-2@example.invalid', 'x');
     INSERT INTO blocks (blocker_id, blocked_id) VALUES ('${a}', '${b}');
     INSERT INTO follows (follower_id, followed_id) VALUES ('${a}', '${b}');
     ROLLBACK;" \
    71

  sonda_musi_zostac_odrzucona "${DSN_PROBNY}" \
    'wyzwalacz dziennik_zgod: dziennika zgód nie da się zmienić' \
    'tylko do dopisywania' \
    "BEGIN;
     INSERT INTO users (id, email, password)
       VALUES ('${a}', 'proba-odtworzenia-1@example.invalid', 'x');
     INSERT INTO dziennik_zgod (user_id, cel, czynnosc, zrodlo, wersja_polityki)
       VALUES ('${a}', 'tygodniowy_digest', 'udzielona', 'ustawienia', 'proba');
     UPDATE dziennik_zgod SET czynnosc = 'wycofana' WHERE user_id = '${a}';
     ROLLBACK;" \
    71

  sonda_musi_zostac_odrzucona "${DSN_PROBNY}" \
    'CHECK follows_no_self_check: nie da się obserwować samego siebie' \
    'follows_no_self_check' \
    "BEGIN;
     INSERT INTO users (id, email, password)
       VALUES ('${a}', 'proba-odtworzenia-1@example.invalid', 'x');
     INSERT INTO follows (follower_id, followed_id) VALUES ('${a}', '${a}');
     ROLLBACK;" \
    75

  sonda_musi_zostac_odrzucona "${DSN_PROBNY}" \
    'UNIQUE na adresie konta: dwa konta z tym samym adresem nie wejdą' \
    'duplicate key value' \
    "BEGIN;
     INSERT INTO users (email, password) VALUES ('proba-odtworzenia-3@example.invalid', 'x');
     INSERT INTO users (email, password) VALUES ('proba-odtworzenia-3@example.invalid', 'x');
     ROLLBACK;" \
    76

  kontrola_dodatnia_zapisu "${DSN_PROBNY}"

  # Po sondach baza próbna ma być w tym samym stanie, co po odtworzeniu.
  # Gdyby ROLLBACK gdzieś nie zadziałał, liczniki z KROKU 6 przestałyby być
  # prawdą o zrzucie — a ćwiczenie ma nie zmieniać tego, co mierzy.
  local zostalo
  zostalo="$(liczba "${DSN_PROBNY}" \
    "SELECT count(*) FROM users WHERE email LIKE 'proba-odtworzenia-%@example.invalid'")"

  if [[ "${zostalo}" != '0' ]]; then
    padnij 71 \
      "Po sondach w bazie próbnej zostało ${zostalo} kont sondujących." \
      'Transakcje sond miały zostać wycofane. Liczniki z tego przebiegu nie są' \
      'już prawdą o zrzucie — powtórz ćwiczenie na świeżej bazie.'
  fi
  ok 'po sondach nie został ani jeden wiersz sondujący'
}

# =============================================================================
#  Sprzątanie
# =============================================================================
KATALOG_ROBOCZY=''
DSN_PROBNY=''
ZRZUT_JAWNY=''
BAZA_NASZA=0
CZAS_ODSZYFROWANIA=0
CZAS_ODTWORZENIA=0
TABEL_W_ARCHIWUM=0
TABEL_W_BAZIE=0

sprzataj() {
  local kod=$?

  # `set -e` obowiązuje także w pułapce EXIT, więc każdy warunek jest tu
  # jawnym `if`. Warunek zapisany jako `[[ … ]] && rm …` zwraca 1, gdy jest
  # fałszywy — i wyszedłby z tej funkcji PRZED skasowaniem bazy próbnej.
  if [[ -n "${KATALOG_ROBOCZY}" && -d "${KATALOG_ROBOCZY}" ]]; then
    # Odszyfrowany zrzut to komplet danych osobowych wszystkich kont. Ginie
    # RAZEM z katalogiem roboczym, także po przerwaniu ćwiczenia.
    rm -rf "${KATALOG_ROBOCZY}"
  fi

  # POŚWIADCZENIE MA PRZEŻYĆ SKASOWANIE KATALOGU ROBOCZEGO O JEDEN KROK.
  # Pierwsza wersja poprawki z #594 trzymała `PGPASSFILE` w katalogu roboczym,
  # więc `DROP DATABASE` niżej dostawał adres bez hasła i bez pliku — baza
  # próbna zostawała na cudzym serwerze, a skrypt tylko ostrzegał. Przeszło to
  # testy wyłącznie dlatego, że w ich środowisku stało `PGPASSWORD`.
  # Dlatego plik ma własny katalog i ginie DOPIERO na końcu tej funkcji.

  # DROP pod TRZEMA warunkami naraz:
  #   * bazę założył TEN przebieg (`BAZA_NASZA`) — bazy zastanej nie kasujemy
  #     nigdy, bo nie wiemy, czemu służy i kto ją założył;
  #   * nazwa PONOWNIE przechodzi przez bezpiecznik 1 — kasowanie jest
  #     operacją destrukcyjną i nie ma prawa polegać na tym, że zmienna nie
  #     zmieniła się od początku przebiegu;
  #   * nie poproszono o `--zostaw`.
  if ((ZOSTAW == 0)) && ((BAZA_NASZA == 1)) \
    && [[ -n "${BAZA}" && "${BAZA}" =~ ^proba_odtworzenia[a-z0-9_]*$ ]] \
    && [[ -n "${SERWER}" ]]; then
    psql "${SERWER}" --no-password --quiet \
      --command "DROP DATABASE IF EXISTS \"${BAZA}\" WITH (FORCE)" >/dev/null 2>&1 \
      || log "OSTRZEŻENIE: nie udało się skasować bazy próbnej ${BAZA} — zrób to ręcznie."
  fi

  if [[ -n "${KATALOG_POSWIADCZEN}" && -d "${KATALOG_POSWIADCZEN}" ]]; then
    rm -rf "${KATALOG_POSWIADCZEN}"
  fi

  return "${kod}"
}

# =============================================================================
#  KROK 0 (TYLKO W PĘTLI LOKALNEJ) — ZRÓB KOPIĘ TYM SKRYPTEM, KTÓRYM SIĘ ROBI
#
#  Woła `scripts/kopia-lokalna.sh`, a nie własny `pg_dump`. To jest cała
#  wartość tego kroku: ćwiczymy odtworzenie TEJ kopii, którą naprawdę się
#  robi, razem ze wszystkimi jej przełącznikami. Zrzut zrobiony tutaj obok,
#  własnymi flagami, dowodziłby tylko tego, że ten skrypt umie rozmawiać
#  sam ze sobą.
#
#  Kopia ląduje w katalogu roboczym, który ginie razem z ćwiczeniem (`sprzataj`)
#  — bo zrzut to komplet danych osobowych i nie ma prawa zostać na dysku po
#  przebiegu, o którym nikt już nie pamięta.
# =============================================================================
zrob_kopie_lokalna() {
  local skrypt="${KATALOG_REPO}/scripts/kopia-lokalna.sh"

  [[ -x "${skrypt}" ]] || padnij 65 \
    "Nie ma ${skrypt} albo nie jest wykonywalny." \
    'Pętla lokalna robi kopię TYM skryptem — bez niego nie ma czego odtwarzać.'

  local katalog_kopii="${KATALOG_ROBOCZY}/kopia"
  mkdir -p "${katalog_kopii}"

  log "kopia lokalnej bazy przez scripts/kopia-lokalna.sh …"

  local wyjscie
  if ! wyjscie="$("${skrypt}" --zrodlo "${DSN_ZRODLA}" --katalog "${katalog_kopii}" 2>&1)"; then
    printf '%s\n' "${wyjscie}" | sed 's/^/  kopia-lokalna: /' >&2
    padnij 65 \
      'scripts/kopia-lokalna.sh zakończył się błędem — KOPII NIE MA.' \
      'Wyjście jest wyżej; kody wyjścia tamtego skryptu opisuje jego nagłówek.'
  fi

  # Nazwę pliku bierzemy z katalogu, nie z parsowania cudzego komunikatu:
  # komunikat wolno zmienić bez uprzedzenia, plik na dysku jest faktem.
  PLIK_ZRZUTU="$(find "${katalog_kopii}" -maxdepth 1 -type f -name 'kuking-*.dump' | sort | tail -n 1)"

  [[ -n "${PLIK_ZRZUTU}" && -f "${PLIK_ZRZUTU}" ]] || padnij 65 \
    'kopia-lokalna.sh zakończył się zerem, a pliku zrzutu w katalogu NIE MA.' \
    "Szukałem w ${katalog_kopii}. Wyjście tamtego skryptu:" "${wyjscie}"

  ok "kopia: ${PLIK_ZRZUTU##*/} ($(stat -c %s "${PLIK_ZRZUTU}") B)"
}

main() {
  przetworz_argumenty "$@"

  KATALOG_ROBOCZY="$(mktemp -d "${TMPDIR:-/tmp}/proba-odtworzenia.XXXXXX")"
  trap sprzataj EXIT

  log "start; baza próbna: ${BAZA}; serwer: $(bez_hasla "${SERWER}")"

  sprawdz_narzedzia

  # Od tej linii adresy nie zawierają już haseł — leżą one w PGPASSFILE
  # w OSOBNYM katalogu, kasowanym na samym końcu `sprzataj` (powód tam).
  KATALOG_POSWIADCZEN="$(mktemp -d "${TMPDIR:-/tmp}/proba-pass.XXXXXX")"
  SCIEZKA_PGPASS="${KATALOG_POSWIADCZEN}/pgpass"
  schowaj_haslo_z_dsn "${SERWER}"
  SERWER="${DSN_BEZ_HASLA}"
  if [[ -n "${DSN_ZRODLA}" ]]; then
    schowaj_haslo_z_dsn "${DSN_ZRODLA}"
    DSN_ZRODLA="${DSN_BEZ_HASLA}"
  fi

  # Bezpiecznik 1 patrzy na napisy i nie wymaga połączenia. Bezpiecznik 3 pyta
  # SERWER o jego tożsamość — i musi to zrobić PRZED `CREATE DATABASE`, czyli
  # przed pierwszym zapisem gdziekolwiek.
  bezpiecznik_nazwy
  bezpiecznik_instancji

  # Kopia powstaje dopiero po obu bezpiecznikach adresowych — w pętli lokalnej
  # to ona jest przedmiotem ćwiczenia.
  if ((PETLA_LOKALNA == 1)); then
    zrob_kopie_lokalna
  fi

  przygotuj_zrzut
  przeczytaj_spis
  zaloz_baze_probna
  bezpiecznik_serwera "${DSN_PROBNY}"
  odtworz
  sprawdz_tabele
  sprawdz_wiersze
  porownaj_wszystkie_tabele
  sprawdz_migracje
  sprawdz_wyzwalacze
  sprawdz_ograniczenia
  sprawdz_zachowanie

  printf '\n%sPRÓBA ODTWORZENIA ZALICZONA.%s\n' "${ZIELONY}" "${RESET}" >&2
  printf '  zrzut:              %s\n' "${PLIK_ZRZUTU##*/}" >&2
  printf '  tabel w archiwum:   %s\n' "${TABEL_W_ARCHIWUM}" >&2
  printf '  tabel w bazie:      %s\n' "${TABEL_W_BAZIE}" >&2
  printf '  czas odszyfrowania: %s s\n' "${CZAS_ODSZYFROWANIA}" >&2
  printf '  czas odtworzenia:   %s s   %s← to jest zmierzone RTO tej warstwy%s\n' \
    "${CZAS_ODTWORZENIA}" "${ZOLTY}" "${RESET}" >&2

  # LICZBY, A NIE PTASZKI. Przebieg, który kończy się samym „zaliczone",
  # wygląda identycznie wtedy, gdy porównał 49 tabel, i wtedy, gdy nie
  # porównał żadnej (pułapka 5 z docs/PULAPKI_TESTOW.md).
  if ((TABEL_POROWNANYCH > 0)); then
    printf '  tabel porównanych:  %s   %s\n' "${TABEL_POROWNANYCH}" \
      "$( ((SCISLE == 1)) && printf 'co do jednego wiersza' || printf 'z tolerancją na zapisy w trakcie zrzutu')" >&2
    printf '  wierszy w próbie:   %s\n' "${WIERSZY_W_PROBIE}" >&2
    printf '  wierszy w źródle:   %s\n' "${WIERSZY_W_ZRODLE}" >&2
  else
    printf '  tabel porównanych:  0   %s← bez --zrodlo nie było z czym porównywać%s\n' \
      "${ZOLTY}" "${RESET}" >&2
  fi

  if ((MIGRACJI_WYKONANYCH > 0)); then
    printf '  migracji wykonanych:%s, czekających: 0\n' " ${MIGRACJI_WYKONANYCH}" >&2
  fi
  printf '\nWpisz te liczby do tabeli w docs/infra/KOPIE_I_ODTWORZENIE.md §5.\n' >&2
  printf 'Zrzut, którego nikt nie odtworzył, jest obietnicą — ten właśnie przestał nią być.\n' >&2
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
