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
#    scripts/proba-odtworzenia.sh --zrzut kuking-20260911-021700Z.dump
#    scripts/proba-odtworzenia.sh --zrzut kopia.dump.cms --klucz PRYWATNY.pem
#    scripts/proba-odtworzenia.sh --zrzut k.dump --zrodlo "$DSN_ZRODLA"
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
#    30  nie udało się założyć bazy próbnej
#    40  zrzutu nie ma albo jest za mały (pusta kopia!)
#    41  pg_restore nie potrafi odczytać archiwum
#    42  w archiwum jest za mało tabel
#    43  odszyfrowanie `.cms` nie udało się
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
BAZA=''
PLIK_ZRZUTU=''
PLIK_KLUCZA=''
DSN_ZRODLA=''
ZOSTAW=0

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
  --tabele a,b,c    które tabele policzyć po nazwie
  --zostaw          nie kasuj bazy próbnej po ćwiczeniu (do obejrzenia)
  -h, --help        ta pomoc

Pełna procedura dla człowieka: docs/infra/KOPIE_I_ODTWORZENIE.md §8
POMOC
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
      --tabele)
        TABELE_DO_POLICZENIA="${2:-}"
        shift 2
        ;;
      --zostaw)
        ZOSTAW=1
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

  [[ -n "${PLIK_ZRZUTU}" ]] || {
    pomoc >&2
    padnij 2 'Brak --zrzut: nie ma czego odtwarzać.'
  }

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

  return "${kod}"
}

main() {
  przetworz_argumenty "$@"

  KATALOG_ROBOCZY="$(mktemp -d "${TMPDIR:-/tmp}/proba-odtworzenia.XXXXXX")"
  trap sprzataj EXIT

  log "start; baza próbna: ${BAZA}; serwer: $(bez_hasla "${SERWER}")"

  sprawdz_narzedzia
  bezpiecznik_nazwy
  przygotuj_zrzut
  przeczytaj_spis
  zaloz_baze_probna
  bezpiecznik_serwera "${DSN_PROBNY}"
  odtworz
  sprawdz_tabele
  sprawdz_wiersze
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
  printf '\nWpisz te liczby do tabeli w docs/infra/KOPIE_I_ODTWORZENIE.md §5.\n' >&2
  printf 'Zrzut, którego nikt nie odtworzył, jest obietnicą — ten właśnie przestał nią być.\n' >&2
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
