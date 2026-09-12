#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — PRÓBA WYCOFANIA MIGRACJI (rollback drill)
# =============================================================================
#
#  CO TO ROBI
#  ----------
#  Bierze KOMPLET migracji tego repozytorium, podnosi je na WŁASNEJ, pustej
#  bazie pomiarowej, wycofuje krok po kroku do zera, podnosi z powrotem do
#  końca — i za każdym razem PORÓWNUJE SCHEMAT ze wzorcem, zamiast wierzyć,
#  że „polecenie się wykonało".
#
#  DLACZEGO TO ISTNIEJE
#  --------------------
#  `AGENTS.md` §6 obiecuje przy każdej zmianie schematu cztery rzeczy, a
#  czwartą jest ROLLBACK. Migracji jest w tym repozytorium 76 i do 12 września
#  2026 nikt nie sprawdził, czy ich `down()` naprawdę działają. Migracja bez
#  działającego wycofania to obietnica, której nie da się spełnić dokładnie
#  w tej chwili, w której jest potrzebna — przy awaryjnym cofaniu wdrożenia,
#  w nocy, pod presją.
#
#  CZEGO NIE WYSTARCZY ZMIERZYĆ — I DLACZEGO TEN SKRYPT MA CZTERY FAZY
#  -------------------------------------------------------------------
#  Pierwszy pomiar przy zakładaniu tego skryptu wyglądał tak: `migrate`,
#  potem `migrate:rollback --step=1` w pętli do zera, potem `migrate`.
#  Wszystkie 76 migracji przeszły w obie strony i wyglądało to na dowód, że
#  wycofania działają. NIE JEST TO DOWÓD, z dwóch osobnych powodów:
#
#  1. **Zero tabel to za niski próg.** Wycofanie do zera zawsze kończy się
#     pustą bazą, więc `down()`, który kasuje ZA DUŻO (całą tabelę zamiast
#     jednej kolumny), przechodzi tak samo gładko jak poprawny — to, co zdjął
#     za wcześnie, i tak zdjęłaby za chwilę migracja wcześniejsza, a
#     `Schema::dropIfExists` nie powie o tym ani słowa. Widać to dopiero
#     wtedy, gdy po wycofaniu PODNIESIE SIĘ Z POWROTEM i porówna schemat.
#
#  2. **Wycofanie do zera nie sprawdza wycofania CZĘŚCIOWEGO**, a w praktyce
#     wycofuje się właśnie częściowo: jedną, dwie, trzy ostatnie migracje przy
#     cofaniu wdrożenia. Dlatego faza czwarta — WAHADŁO — schodzi po kolei na
#     każdą głębokość od 1 do 76, za każdym razem wraca na szczyt i porównuje
#     schemat ze wzorcem.
#
#  Uczciwie: 12 września 2026, przy zakładaniu tego skryptu, faza czwarta NIE
#  znalazła ani jednej usterki — wszystkie 76 migracji wróciły na każdej
#  głębokości ze schematem identycznym co do znaku. To nie czyni jej zbędną,
#  tylko każe pokazać, że cokolwiek mierzy. ZMIERZONE kontrolą ujemną, dwoma
#  sabotażami tej samej migracji (`..._add_klucz_wyslania_to_recipes`):
#
#      sabotaż                                       fazy 1-3    faza 4
#      `down()` kasuje CAŁĄ tabelę `recipes`         kod 50      —
#      `down()` zdejmuje CUDZY indeks                kod 0 (!)   kod 72
#      (`recipes_title_trgm_idx`, założony przez
#       migrację 2026_09_09_100000)
#
#  Drugi wiersz jest całym powodem istnienia fazy czwartej. Fazy 1-3 kończą
#  się na nim komunikatem „wycofanie migracji działa" i kodem 0: do zera
#  schodzi bez błędu (indeks i tak by zszedł), a `migrate` od zera odtwarza
#  schemat co do znaku (bo zakłada go migracja, która przecież się wykonuje).
#  Dopiero wycofanie CZĘŚCIOWE — jedna migracja w dół, jedna w górę — pokazuje,
#  że indeksu już nie ma; faza czwarta oblewa się na głębokości 1 i wypisuje
#  brakującą linijkę zrzutu z nazwy.
#
#  Pierwszy wiersz jest przy okazji przestrogą z `docs/PULAPKI_TESTOW.md` §3:
#  ten sabotaż miał pokazać to samo, a trafił w inną gałąź, bo `DROP TABLE`
#  bez CASCADE przewraca się o klucze obce z sześciu innych tabel. Sabotaż,
#  który oblewa się nie tam, gdzie się spodziewasz, nie dowodzi tego, co
#  miał dowieść.
#
#  CO JEST WZORCEM
#  ---------------
#  `pg_dump --schema-only` z bazy zaraz po pierwszym `migrate`. Porównanie
#  jest DOSŁOWNE (`diff`), bo pytanie brzmi „czy schemat jest ten sam", a nie
#  „czy jest podobny". Ze zrzutu zdejmujemy tylko dwie linijki, które zmieniają
#  się same z siebie przy każdym uruchomieniu `pg_dump` (`\restrict` z losowym
#  ciągiem i nagłówek „Dumped from/by") — bez tego każde porównanie byłoby
#  czerwone i nikt nie czytałby wyniku.
#
#  CZEGO TEN SKRYPT NIE MIERZY
#  ---------------------------
#  Zachowania `down()` przy DANYCH, które łamią założenie migracji (kolumna
#  z wartością spoza CHECK-a, wiersz-sierota po kluczu obcym). Baza pomiarowa
#  jest pusta i taka ma być: inaczej strażniki semantyczne (D-088) odmówiłyby
#  wycofania i faza druga nie doszłaby do zera. Ich odmowy pilnują osobne
#  testy PHPUnit (`CofniecieMigracji*Test`), a to, że KAŻDA migracja ma
#  niepusty `down()`, pilnuje `tests/Feature/KazdaMigracjaMaWycofanieTest.php`.
#
#  BEZPIECZNIKI — dwa, niezależne, jak w scripts/proba-odtworzenia.sh
#  ------------------------------------------------------------------
#  Skrypt KASUJE I ZAKŁADA bazę, po czym zrzuca w niej cały schemat do zera.
#  To jest najbardziej destrukcyjna operacja w tym repozytorium, więc:
#    1. nazwa bazy musi pasować do `proba_wycofania*` — produkcja w Railwayu
#       nazywa się `railway`, lokalna deweloperska `kuking`, testowe
#       `kuking_test*`, wyścigowe `kuking_race*`; żadna z nich nie wpadnie do
#       tego wzorca nawet przy literówce, bo to nie jest różnica jednego znaku,
#    2. serwer jest PYTANY, do czego naprawdę jesteśmy podłączeni
#       (`current_database()`), bo `?dbname=` i `PGDATABASE` potrafią
#       przekierować połączenie bez zmiany napisu, który widzi bezpiecznik 1.
#
#  UŻYCIE
#  ------
#    ./scripts/proba-wycofania.sh                # pełny przebieg (cztery fazy)
#    ./scripts/proba-wycofania.sh --bez-wahadla  # tylko fazy 1–3 (szybko)
#    ./scripts/proba-wycofania.sh --zostaw       # nie kasuj bazy po pomiarze
#
#  KODY WYJŚCIA — każdy mówi, CO dokładnie zawiodło
#  ------------------------------------------------
#     2  błąd użycia (argumenty)
#    10  brak narzędzia (php / psql / pg_dump)
#    20  BEZPIECZNIK 1: nazwa bazy pomiarowej nie jest nazwą próbną
#    21  BEZPIECZNIK 1: adres serwera wygląda na produkcyjny (Railway)
#    22  BEZPIECZNIK 2: serwer zwrócił INNĄ bazę, niż zadeklarowano
#    23  BEZPIECZNIK 2: baza pomiarowa nie jest pusta
#    24  nie udało się złożyć adresu bazy z `.env`
#    30  nie udało się założyć albo skasować bazy pomiarowej
#    40  FAZA 1: pierwsze `migrate` nie przeszło
#    41  FAZA 1: po `migrate` w bazie jest podejrzanie mało migracji
#    50  FAZA 2: `down()` którejś migracji wywrócił się
#    51  FAZA 2: wycofanie stanęło, nie dochodząc do zera
#    52  FAZA 2: po zejściu do zera w bazie ZOSTAŁY obiekty schematu
#    60  FAZA 3: `up()` nie przeszedł po wycofaniu do zera
#    61  FAZA 3: schemat po cyklu RÓŻNI SIĘ od wzorca
#    70  FAZA 4 (wahadło): `down()` wywrócił się na którejś głębokości
#    71  FAZA 4 (wahadło): `up()` nie przeszedł po wycofaniu
#    72  FAZA 4 (wahadło): schemat po powrocie RÓŻNI SIĘ od wzorca
#    90  serwer bazy jest wyczerpany (za dużo połączeń) — to awaria MASZYNY,
#        nie migracji; osobny kod, żeby nikt nie szukał usterki w kodzie
#        (patrz docs/PULAPKI_TESTOW.md §8b)
# =============================================================================

set -Eeuo pipefail

KATALOG_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

BAZA=''
ZOSTAW=0
WAHADLO=1
# Próg „podejrzanie mało migracji”. Celowo NIŻSZY niż stan dzisiejszy (76):
# próg ma łapać pustą albo obcą bazę, a nie oblewać się przy każdej nowej
# migracji. „Ile jest dziś” nie jest progiem — to byłby test poprawiany przy
# każdej zmianie schematu, czyli po trzecim razie podnoszony bez czytania.
MIN_MIGRACJI="${WYCOFANIE_MIN_MIGRACJI:-40}"

# Liczby, którymi kończy się przebieg.
MIGRACJI_PODNIESIONYCH=0
MIGRACJI_WYCOFANYCH=0
MIGRACJI_PODNIESIONYCH_PONOWNIE=0
GLEBOKOSCI_SPRAWDZONYCH=0

ZIELONY=$'\033[0;32m'
CZERWONY=$'\033[0;31m'
ZOLTY=$'\033[0;33m'
RESET=$'\033[0m'

log() { printf '[wycofanie] %s\n' "$*" >&2; }
ok() { printf '[wycofanie] %s✓%s %s\n' "${ZIELONY}" "${RESET}" "$*" >&2; }
naglowek() { printf '\n[wycofanie] %s── %s ──%s\n' "${ZOLTY}" "$*" "${RESET}" >&2; }

# Porażka mówi TRZY rzeczy: co zawiodło, co to znaczy i co z tym zrobić.
padnij() {
  local kod="$1"
  shift
  printf '[wycofanie] %sPORAŻKA (kod %s)%s\n' "${CZERWONY}" "${kod}" "${RESET}" >&2
  local linia
  for linia in "$@"; do
    printf '            %s\n' "${linia}" >&2
  done
  exit "${kod}"
}

pomoc() {
  cat <<'POMOC'
Próba wycofania migracji Kuking (rollback drill).

  --baza NAZWA     nazwa bazy pomiarowej; MUSI zaczynać się od
                   `proba_wycofania` (domyślnie: proba_wycofania_<worktree>)
  --bez-wahadla    pomiń fazę 4 (sprawdzanie każdej głębokości z osobna);
                   przebieg jest wtedy kilkanaście razy krótszy, ale NIE
                   sprawdza wycofania częściowego — czyli tego, które robi
                   się naprawdę przy cofaniu wdrożenia
  --zostaw         nie kasuj bazy pomiarowej po przebiegu (do obejrzenia)
  -h, --help       ta pomoc

Pełne wyjaśnienie, co i dlaczego mierzą poszczególne fazy: nagłówek tego pliku.
POMOC
}

przetworz_argumenty() {
  while (($# > 0)); do
    case "$1" in
      --baza)
        BAZA="${2:-}"
        shift 2
        ;;
      --bez-wahadla)
        WAHADLO=0
        shift
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
}

# =============================================================================
#  ADRES BAZY — składany z `.env`, a nie zgadywany
#
#  Ta sama metoda co w scripts/proba-odtworzenia.sh: cztery wartości z `.env`
#  i kodowanie procentowe hasła, bo `@` albo `/` w haśle rozbiłoby adres na
#  części w złych miejscach.
# =============================================================================
zakoduj_url() {
  local surowy="$1" wynik='' znak i
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
  [[ -f "${plik}" ]] || return 0
  sed -n -E "s/^[[:space:]]*${klucz}[[:space:]]*=[[:space:]]*//p" "${plik}" \
    | tail -n 1 | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' | tr -d '\r'
}

SERWER=''
dsn_serwera() {
  local host port uzytkownik haslo
  host="$(z_env DB_HOST)"
  port="$(z_env DB_PORT)"
  uzytkownik="$(z_env DB_USERNAME)"
  haslo="$(z_env DB_PASSWORD)"

  [[ -n "${host}" && -n "${uzytkownik}" ]] || return 0

  printf 'postgresql://%s:%s@%s:%s' \
    "$(zakoduj_url "${uzytkownik}")" "$(zakoduj_url "${haslo}")" \
    "${host}" "${port:-5432}"
}

# =============================================================================
#  BEZPIECZNIK 1 — NAZWA I ADRES, SPRAWDZANE PRZED JAKIMKOLWIEK POŁĄCZENIEM
#
#  Patrzy na to, CO KAZANO ZROBIĆ: na nazwę bazy i adres serwera, jako na
#  napisy. To jedyny bezpiecznik działający także wtedy, gdy serwer jest
#  nieosiągalny — a więc jedyny, który zadziała, gdy ktoś przez pomyłkę
#  wskaże produkcję, do której akurat nie ma dostępu.
#
#  Wzorzec sprawdzamy CAŁYM dopasowaniem, nie prefiksem: nazwa trafia do SQL-a
#  w `CREATE DATABASE` i `DROP DATABASE`, więc musi też przejść przez sito
#  dozwolonych znaków.
# =============================================================================
bezpiecznik_nazwy() {
  if [[ ! "${BAZA}" =~ ^proba_wycofania[a-z0-9_]*$ ]]; then
    padnij 20 \
      "Baza pomiarowa to \"${BAZA}\", a wolno wyłącznie \`proba_wycofania*\`" \
      '(małe litery, cyfry i podkreślenia).' \
      'Ten skrypt KASUJE bazę i zrzuca w niej schemat do zera — nazwa jest tu' \
      'bezpiecznikiem, nie konwencją. Popraw --baza albo nie podawaj jej wcale.'
  fi

  case "${SERWER}" in
    *proxy.rlwy.net* | *railway.internal* | *.up.railway.app*)
      padnij 21 \
        'Adres serwera wskazuje na Railwaya, czyli na to samo miejsce, co produkcja.' \
        'Ten skrypt kasuje bazę i wycofuje WSZYSTKIE migracje do zera. Na produkcji' \
        'nie ma prawa się uruchomić. Popraw DB_HOST w .env albo uruchom go lokalnie.'
      ;;
  esac

  ok "bezpiecznik 1: baza \"${BAZA}\" jest nazwą pomiarową, serwer nie jest produkcyjny"
}

# =============================================================================
#  BEZPIECZNIK 2 — PYTAMY SERWER, DO CZEGO NAPRAWDĘ JESTEŚMY PODŁĄCZENI
#
#  Nie wierzy pierwszemu i nie wierzy sobie. Nazwa w DSN-ie NIE MUSI być nazwą
#  bazy, do której libpq się połączy: parametr `?dbname=` z części zapytania
#  wygrywa ze ścieżką, a `PGDATABASE` i `~/.pg_service.conf` potrafią
#  przekierować połączenie bez zmiany naszego napisu (zmierzone na psql 16.13,
#  patrz nagłówek scripts/proba-odtworzenia.sh).
#
#  Druga połowa to PUSTOŚĆ. Bazę zakładamy przed chwilą sami, więc pusta być
#  musi — jeśli nie jest, to znaczy, że jesteśmy gdzie indziej, niż myślimy,
#  i to jest ostatni moment, żeby się zatrzymać.
# =============================================================================
bezpiecznik_serwera() {
  local nazwa_z_serwera ile_obiektow

  nazwa_z_serwera="$(psql_pomiarowa -Atc 'SELECT current_database()' | tr -d '[:space:]')" || true

  if [[ "${nazwa_z_serwera}" != "${BAZA}" ]]; then
    padnij 22 \
      "Zadeklarowano bazę \"${BAZA}\", a serwer mówi, że jesteśmy w \"${nazwa_z_serwera:-?}\"." \
      'Najczęstsza przyczyna: `?dbname=` albo `PGDATABASE` przekierowuje połączenie' \
      'gdzie indziej, niż mówi ścieżka w adresie. NIE MIERZĘ — to jest dokładnie' \
      'ta pomyłka, po której `migrate:rollback` zdejmuje schemat żywej bazy.'
  fi

  ile_obiektow="$(policz_obiekty)"

  if [[ ! "${ile_obiektow}" =~ ^[0-9]+$ ]]; then
    padnij 22 \
      "Nie udało się policzyć obiektów w \"${BAZA}\" — nie wiem, czy baza jest pusta." \
      'Bez tej wiedzy nie mierzę: „nie wiemy” liczy się jak nieprzejście' \
      '(docs/PULAPKI_TESTOW.md §5).'
  fi

  if ((ile_obiektow > 0)); then
    padnij 23 \
      "Baza \"${BAZA}\" nie jest pusta (${ile_obiektow} obiektów w schemacie public)." \
      'Zakładaliśmy ją przed chwilą sami, więc pusta BYĆ MUSI. Skoro nie jest,' \
      'to jesteśmy podłączeni gdzie indziej, niż mówi nazwa. Zatrzymuję się.'
  fi

  ok "bezpiecznik 2: serwer potwierdza bazę \"${BAZA}\" i jej pustkę"
}

# --- Połączenia --------------------------------------------------------------
#
# `--no-password` jest tu ważne: bez niego psql przy złym haśle zawiesza się
# na pytaniu i skrypt stoi w nieskończoność zamiast paść z komunikatem.
psql_utrzymaniowa() { psql "${SERWER}/postgres" --no-password --quiet "$@"; }
psql_pomiarowa() { psql "${SERWER}/${BAZA}" --no-password --quiet "$@"; }

# Liczy WSZYSTKIE obiekty schematu `public`, nie same tabele: `down()`, który
# zostawia po sobie osierocony widok, sekwencję, funkcję PL/pgSQL, wyzwalacz
# albo typ wyliczeniowy, jest tak samo niedokończony jak ten, który zostawia
# tabelę — a przy liczeniu samych tabel byłby niewidoczny. Zmierzone: przy
# liczeniu samych tabel wycofanie do zera wyglądało na czyste, a w bazie
# zostawała funkcja PL/pgSQL wyzwalacza z D-080.
#
# Funkcji wniesionych przez ROZSZERZENIA (pgcrypto, pg_trgm, unaccent) nie
# liczymy: należą do rozszerzenia, nie do migracji, a rozszerzeń świadomie nie
# zdejmujemy (patrz `down()` w 0001_01_01_000000_enable_postgres_extensions).
# Odsiewa je `pg_depend` z `deptype = 'e'`.
#
# Nie liczymy też KSIĘGOWOŚCI Laravela: tabeli `migrations` i jej sekwencji
# `migrations_id_seq`. Zakłada je sam framework, żadna migracja ich nie tworzy
# ani nie kasuje, więc ich obecność po zejściu do zera nie jest niczyim
# przeoczeniem. Sekwencję rozpoznajemy po WŁAŚCICIELU (`pg_depend` z
# `deptype = 'a'`), a nie po nazwie: nazwa jest zbiegiem okoliczności, a
# własność jest faktem zapisanym w katalogu systemowym.
#
# Zapytanie stoi w JEDNEJ zmiennej, bo liczenie i wypisywanie muszą pytać
# dokładnie o to samo. Gdyby to były dwa zapytania, rozjechałyby się przy
# pierwszej zmianie — i wtedy licznik mówiłby „zostały 2", a lista nie
# pokazywałaby żadnego, co wygląda na usterkę skryptu, a nie schematu.
SQL_OBIEKTY="
    SELECT 'relacja ' || c.relkind::text || ' ' || c.relname AS opis
      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND c.relkind IN ('r','v','m','S','p','f')
       AND c.relname <> 'migrations'
       AND NOT EXISTS (
             SELECT 1 FROM pg_depend d JOIN pg_class w ON w.oid = d.refobjid
              WHERE d.objid = c.oid AND d.deptype = 'a' AND w.relname = 'migrations')
    UNION ALL
    SELECT 'typ wyliczeniowy ' || t.typname
      FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
     WHERE n.nspname = 'public' AND t.typtype = 'e'
    UNION ALL
    SELECT 'wyzwalacz ' || g.tgname || ' na ' || c.relname
      FROM pg_trigger g
      JOIN pg_class c ON c.oid = g.tgrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND NOT g.tgisinternal
    UNION ALL
    SELECT 'funkcja ' || p.proname || '()'
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace
      LEFT JOIN pg_depend d ON d.objid = p.oid AND d.deptype = 'e'
     WHERE n.nspname = 'public' AND d.objid IS NULL
"

policz_obiekty() {
  psql_pomiarowa -Atc "SELECT count(*) FROM (${SQL_OBIEKTY}) AS obiekty" 2>/dev/null \
    | tr -d '[:space:]'
}

# Te same obiekty, ale wypisane z nazwy — do komunikatu porażki. Sama liczba
# („zostało 2") nie mówi, czego szukać; nazwa mówi.
wypisz_obiekty() {
  psql_pomiarowa -Atc "${SQL_OBIEKTY}" 2>/dev/null
}

# Migracje odnotowane w bazie. Pusty wynik (brak tabeli `migrations`) to zero.
policz_migracje() {
  psql_pomiarowa -Atc "
    SELECT count(*) FROM migrations
  " 2>/dev/null | tr -d '[:space:]' || true
}

# Zrzut schematu do porównania. Zdejmujemy DWIE rzeczy, które zmieniają się
# same z siebie przy każdym uruchomieniu `pg_dump` i nie mówią nic o schemacie:
# losowy ciąg w `\restrict`/`\unrestrict` (pg_dump ≥ 16.10) oraz nagłówek
# „Dumped from/by”. Gdyby zostały, KAŻDE porównanie byłoby czerwone — a test,
# który jest czerwony zawsze, przestaje być czytany po drugim razie.
zrzut_schematu() {
  pg_dump "${SERWER}/${BAZA}" --schema-only --no-owner --no-privileges 2>/dev/null \
    | grep -vE '^\\(un)?restrict |^-- Dumped (from|by)'
}

# --- Artisan -----------------------------------------------------------------
#
# APP_BASE_PATH: w worktree z dowiązanym `vendor` bez tego Laravel czyta trasy,
# konfigurację i migracje z GŁÓWNEGO katalogu (AGENTS.md §10) — czyli mierzyłby
# cudze migracje, nie te, które właśnie zmieniasz.
artisan() {
  (cd "${KATALOG_REPO}" && APP_BASE_PATH="${KATALOG_REPO}" DB_DATABASE="${BAZA}" \
    php artisan "$@" --no-interaction 2>&1)
}

# Wyczerpany serwer bazy to awaria MASZYNY, nie migracji, i ma własny kod
# wyjścia. Bez tego rozróżnienia człowiek czyta „FATAL: sorry, too many clients”
# jako usterkę `down()` i idzie szukać błędu, którego nie ma
# (docs/PULAPKI_TESTOW.md §8b: czerwień bez przeczytanej przyczyny nie jest
# informacją).
sprawdz_czy_to_nie_maszyna() {
  local dziennik="$1"
  if grep -qiE 'too many clients|could not fork|No space left on device|out of memory' "${dziennik}"; then
    padnij 90 \
      'Serwer bazy albo maszyna są wyczerpane — to NIE jest usterka migracji.' \
      'Pierwsza linijka, która o tym mówi:' \
      "  $(grep -im1 -E 'too many clients|could not fork|No space left on device|out of memory' "${dziennik}")" \
      'Sprawdź `df -h /` i liczbę połączeń (pg_stat_activity), poczekaj i powtórz.'
  fi
}

# =============================================================================
#  PRZEBIEG
# =============================================================================
sprzataj() {
  if ((ZOSTAW == 1)); then
    log "baza pomiarowa \"${BAZA}\" ZOSTAJE (--zostaw)"
    return 0
  fi
  psql_utrzymaniowa -c "DROP DATABASE IF EXISTS ${BAZA} WITH (FORCE)" >/dev/null 2>&1 || true
}

glowna() {
  przetworz_argumenty "$@"

  local narzedzie
  for narzedzie in php psql pg_dump; do
    command -v "${narzedzie}" >/dev/null 2>&1 || padnij 10 \
      "Brak narzędzia \`${narzedzie}\` — nie ma czym zmierzyć wycofania."
  done

  SERWER="$(dsn_serwera)"
  [[ -n "${SERWER}" ]] || padnij 24 \
    'Nie umiem złożyć adresu bazy z `.env` tego repozytorium.' \
    'Uzupełnij DB_HOST, DB_PORT, DB_USERNAME i DB_PASSWORD w .env.'

  # Domyślna nazwa bierze sufiks worktree TĄ SAMĄ metodą, co nazwa bazy
  # testowej i wyścigowej — żeby w repozytorium nie było trzech reguł
  # nazywania baz, które rozjadą się przy pierwszej zmianie.
  [[ -n "${BAZA}" ]] || BAZA="$(cd "${KATALOG_REPO}" \
    && php -r 'require "tests/bootstrap.php"; echo kuking_nazwa_bazy_wycofania(__DIR__);')"

  bezpiecznik_nazwy

  naglowek "Baza pomiarowa: ${BAZA}"

  psql_utrzymaniowa -c "DROP DATABASE IF EXISTS ${BAZA} WITH (FORCE)" >/dev/null 2>&1 || true
  psql_utrzymaniowa -c "CREATE DATABASE ${BAZA}" >/dev/null 2>&1 || padnij 30 \
    "Nie udało się założyć bazy pomiarowej \"${BAZA}\"." \
    'Sprawdź, czy serwer odpowiada i czy użytkownik z .env ma prawo CREATE DATABASE.'

  trap sprzataj EXIT

  bezpiecznik_serwera

  local katalog_roboczy
  katalog_roboczy="$(mktemp -d)"
  trap 'sprzataj; rm -rf "'"${katalog_roboczy}"'"' EXIT

  # --- FAZA 1: podniesienie do końca -----------------------------------------
  naglowek 'FAZA 1 — podniesienie schematu do końca'

  if ! artisan migrate --force >"${katalog_roboczy}/faza1.log"; then
    sprawdz_czy_to_nie_maszyna "${katalog_roboczy}/faza1.log"
    padnij 40 \
      'Pierwsze `migrate` nie przeszło — nie ma czego wycofywać.' \
      'Ostatnie linijki:' "$(tail -n 5 "${katalog_roboczy}/faza1.log")"
  fi

  MIGRACJI_PODNIESIONYCH="$(policz_migracje)"
  [[ "${MIGRACJI_PODNIESIONYCH}" =~ ^[0-9]+$ ]] || MIGRACJI_PODNIESIONYCH=0

  ((MIGRACJI_PODNIESIONYCH >= MIN_MIGRACJI)) || padnij 41 \
    "Po \`migrate\` w bazie jest tylko ${MIGRACJI_PODNIESIONYCH} migracji (próg: ${MIN_MIGRACJI})." \
    'Albo katalog `database/migrations` nie jest tym, o którym myślisz (worktree' \
    'bez APP_BASE_PATH?), albo baza nie jest tą, którą zmierzyliśmy. Nie mierzę dalej.'

  ok "podniesione: ${MIGRACJI_PODNIESIONYCH} migracji"

  zrzut_schematu >"${katalog_roboczy}/wzorzec.sql"
  log "wzorzec schematu: $(wc -l <"${katalog_roboczy}/wzorzec.sql") linii zrzutu"

  # --- FAZA 2: wycofanie krok po kroku do zera -------------------------------
  naglowek 'FAZA 2 — wycofanie krok po kroku do zera'

  local krok=0 nazwa=''
  while ((krok < MIGRACJI_PODNIESIONYCH + 1)); do
    # Nazwę migracji, która ZA CHWILĘ pójdzie w dół, czytamy z bazy PRZED
    # wycofaniem. Gdyby czytać ją z komunikatu `migrate:rollback`, to przy
    # wywrotce nie byłoby czego czytać — a to jest jedyny moment, w którym ta
    # nazwa jest komukolwiek potrzebna.
    nazwa="$(psql_pomiarowa -Atc 'SELECT migration FROM migrations ORDER BY id DESC LIMIT 1' 2>/dev/null | tr -d '[:space:]')" || nazwa=''
    [[ -n "${nazwa}" ]] || break

    if ! artisan migrate:rollback --step=1 --force >"${katalog_roboczy}/faza2.log"; then
      sprawdz_czy_to_nie_maszyna "${katalog_roboczy}/faza2.log"
      padnij 50 \
        "\`down()\` wywrócił się na migracji: ${nazwa}" \
        "Wycofanych przedtem: ${MIGRACJI_WYCOFANYCH} z ${MIGRACJI_PODNIESIONYCH}." \
        'Błąd:' "$(grep -m1 -E 'SQLSTATE|Exception' "${katalog_roboczy}/faza2.log" || tail -n 3 "${katalog_roboczy}/faza2.log")"
    fi

    MIGRACJI_WYCOFANYCH=$((MIGRACJI_WYCOFANYCH + 1))
    krok=$((krok + 1))
  done

  local zostalo_migracji
  zostalo_migracji="$(policz_migracje)"
  [[ "${zostalo_migracji}" =~ ^[0-9]+$ ]] || zostalo_migracji='?'

  [[ "${zostalo_migracji}" == '0' ]] || padnij 51 \
    "Wycofywanie stanęło: w bazie zostało jeszcze ${zostalo_migracji} migracji." \
    "Wycofanych: ${MIGRACJI_WYCOFANYCH} z ${MIGRACJI_PODNIESIONYCH}."

  local obiekty_po_zerze
  obiekty_po_zerze="$(policz_obiekty)"

  [[ "${obiekty_po_zerze}" =~ ^[0-9]+$ ]] || padnij 52 \
    'Nie udało się policzyć obiektów po zejściu do zera — nie wiem, czy baza jest czysta.' \
    '„Nie wiemy” liczy się jak nieprzejście (docs/PULAPKI_TESTOW.md §5).'

  if ((obiekty_po_zerze > 0)); then
    padnij 52 \
      "Po zejściu do zera w schemacie public zostało ${obiekty_po_zerze} obiektów migracji, a ma zostać zero." \
      'Któryś `down()` nie posprzątał po sobie. Co zostało:' \
      "$(wypisz_obiekty | head -20)"
  fi

  ok "wycofane: ${MIGRACJI_WYCOFANYCH} migracji, po migracjach nie został ani jeden obiekt schematu"

  # --- FAZA 3: podniesienie z powrotem do końca ------------------------------
  naglowek 'FAZA 3 — podniesienie z powrotem do końca i porównanie ze wzorcem'

  if ! artisan migrate --force >"${katalog_roboczy}/faza3.log"; then
    sprawdz_czy_to_nie_maszyna "${katalog_roboczy}/faza3.log"
    padnij 60 \
      '`up()` nie przeszedł po wycofaniu do zera.' \
      'To jest gorsze niż brak `down()`: wycofanie ZOSTAWIŁO bazę w stanie, z którego' \
      'nie da się wrócić. Ostatnie linijki:' "$(tail -n 5 "${katalog_roboczy}/faza3.log")"
  fi

  MIGRACJI_PODNIESIONYCH_PONOWNIE="$(policz_migracje)"
  [[ "${MIGRACJI_PODNIESIONYCH_PONOWNIE}" =~ ^[0-9]+$ ]] || MIGRACJI_PODNIESIONYCH_PONOWNIE=0

  zrzut_schematu >"${katalog_roboczy}/po_cyklu.sql"

  if ! diff -q "${katalog_roboczy}/wzorzec.sql" "${katalog_roboczy}/po_cyklu.sql" >/dev/null; then
    padnij 61 \
      'Schemat po pełnym cyklu RÓŻNI SIĘ od wzorca sprzed wycofania.' \
      'Migracje „przeszły” w obie strony, ale baza nie jest tą samą bazą. Różnice:' \
      "$(diff -u "${katalog_roboczy}/wzorzec.sql" "${katalog_roboczy}/po_cyklu.sql" | grep -E '^[+-][^+-]' | head -20)"
  fi

  ok "podniesione ponownie: ${MIGRACJI_PODNIESIONYCH_PONOWNIE} migracji, schemat identyczny ze wzorcem"

  # --- FAZA 4: wahadło — każda głębokość z osobna ----------------------------
  if ((WAHADLO == 1)); then
    naglowek 'FAZA 4 — wahadło: wycofanie częściowe na każdą głębokość i powrót'
    log "to jest ${MIGRACJI_PODNIESIONYCH} zejść i ${MIGRACJI_PODNIESIONYCH} powrotów; potrwa kilka minut"

    local glebokosc najglebsza
    for ((glebokosc = 1; glebokosc <= MIGRACJI_PODNIESIONYCH; glebokosc++)); do
      najglebsza="$(psql_pomiarowa -Atc "SELECT migration FROM migrations ORDER BY id DESC OFFSET $((glebokosc - 1)) LIMIT 1" 2>/dev/null | tr -d '[:space:]')" || najglebsza='?'

      if ! artisan migrate:rollback --step="${glebokosc}" --force >"${katalog_roboczy}/faza4.log"; then
        sprawdz_czy_to_nie_maszyna "${katalog_roboczy}/faza4.log"
        padnij 70 \
          "Wycofanie ${glebokosc} migracji wywróciło się na: ${najglebsza}" \
          "Głębokości sprawdzonych przedtem: ${GLEBOKOSCI_SPRAWDZONYCH}." \
          'Błąd:' "$(grep -m1 -E 'SQLSTATE|Exception' "${katalog_roboczy}/faza4.log" || tail -n 3 "${katalog_roboczy}/faza4.log")"
      fi

      if ! artisan migrate --force >"${katalog_roboczy}/faza4.log"; then
        sprawdz_czy_to_nie_maszyna "${katalog_roboczy}/faza4.log"
        padnij 71 \
          "Po wycofaniu ${glebokosc} migracji \`up()\` już nie przeszedł." \
          "Najgłębsza wycofywana: ${najglebsza}" \
          '`down()`, po którym `up()` nie wraca, jest gorszy niż brak `down()`.' \
          'Błąd:' "$(grep -m1 -E 'SQLSTATE|Exception' "${katalog_roboczy}/faza4.log" || tail -n 3 "${katalog_roboczy}/faza4.log")"
      fi

      zrzut_schematu >"${katalog_roboczy}/wahadlo.sql"

      if ! diff -q "${katalog_roboczy}/wzorzec.sql" "${katalog_roboczy}/wahadlo.sql" >/dev/null; then
        padnij 72 \
          "Po wycofaniu ${glebokosc} migracji i powrocie schemat RÓŻNI SIĘ od wzorca." \
          "Najgłębsza wycofywana: ${najglebsza}" \
          "Głębokość ${glebokosc} to pierwsza, która się rozjeżdża — czyli szuka się w tej jednej migracji." \
          'Różnice (+ = jest po cyklu, a nie powinno; - = było przed, a zniknęło):' \
          "$(diff -u "${katalog_roboczy}/wzorzec.sql" "${katalog_roboczy}/wahadlo.sql" | grep -E '^[+-][^+-]' | head -20)"
      fi

      GLEBOKOSCI_SPRAWDZONYCH=$((GLEBOKOSCI_SPRAWDZONYCH + 1))
      printf '\r[wycofanie] wahadło: %s/%s (ostatnio: %s)     ' \
        "${GLEBOKOSCI_SPRAWDZONYCH}" "${MIGRACJI_PODNIESIONYCH}" "${najglebsza}" >&2
    done
    printf '\n' >&2

    ok "wahadło: ${GLEBOKOSCI_SPRAWDZONYCH} głębokości, za każdym razem schemat identyczny ze wzorcem"
  else
    log 'faza 4 (wahadło) POMINIĘTA (--bez-wahadla) — wycofanie częściowe NIE jest zmierzone'
  fi

  # --- Meldunek liczbami -----------------------------------------------------
  naglowek 'WYNIK'
  printf '  migracji w repozytorium (podniesionych):  %s\n' "${MIGRACJI_PODNIESIONYCH}" >&2
  printf '  wycofanych krok po kroku do zera:         %s\n' "${MIGRACJI_WYCOFANYCH}" >&2
  printf '  podniesionych ponownie do końca:          %s\n' "${MIGRACJI_PODNIESIONYCH_PONOWNIE}" >&2
  if ((WAHADLO == 1)); then
    printf '  głębokości sprawdzonych wahadłem:         %s\n' "${GLEBOKOSCI_SPRAWDZONYCH}" >&2
  else
    printf '  głębokości sprawdzonych wahadłem:         0 (pominięte)\n' >&2
  fi
  printf '  schemat po cyklu vs wzorzec:              identyczny\n' >&2

  ok 'wycofanie migracji działa'
}

glowna "$@"
