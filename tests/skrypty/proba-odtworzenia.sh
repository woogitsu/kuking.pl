#!/usr/bin/env bash
# =============================================================================
#  Testy kopii lokalnej i próby odtworzenia (issue #9, #193)
#     scripts/kopia-lokalna.sh      — kopia na dysk właściciela
#     scripts/proba-odtworzenia.sh  — restore drill z kontrolą treści
# =============================================================================
#
#  DLACZEGO TEST W BASHU, A NIE W PHPUNICIE
#  Bo oba sprawdzane pliki są skryptami powłoki i w prawdziwej awarii
#  uruchamia je człowiek z terminala, nie Laravel. Ta sama zasada i ten sam
#  kształt, co `tests/skrypty/kopia-bazy.sh` oraz `entrypoint-nadzor.sh`.
#  Do zwykłego `php artisan test` wciąga je `tests/Feature/ProbaOdtworzeniaTest.php`,
#  żeby nie dało się ich pominąć.
#
#  CO TU JEST PRAWDZIWE — i to jest różnica wobec `tests/skrypty/kopia-bazy.sh`
#  ---------------------------------------------------------------------------
#  Prawdziwy `pg_dump`, prawdziwy `pg_restore`, prawdziwy PostgreSQL i
#  prawdziwy schemat Kukinga po wszystkich migracjach. Kopie i odtworzenia
#  w tym pliku DZIEJĄ SIĘ, nie są udawane. Prawdziwe jest też szyfrowanie:
#  para kluczy RSA powstaje w trakcie testu, kopia szyfruje się kluczem
#  publicznym, a odtworzenie idzie SAMYM kluczem prywatnym.
#
#  CZEGO NADAL NIE MA, ŚWIADOMIE
#  Serwera PostgreSQL **18** (lokalnie stoi 16 — zgodność wersji pilnuje
#  osobny test w `tests/skrypty/kopia-bazy.sh`), rozmowy z R2 i produkcyjnych
#  danych. Zielony wynik tego pliku znaczy „mechanizm kopii i odtworzenia
#  działa”, a NIE „kuking.pl ma kopię”. Liczba kopii produkcyjnej bazy
#  wynosi dziś ZERO i zmienią to wyłącznie cztery czynności właściciela
#  z `docs/infra/KOPIE_I_ODTWORZENIE.md` §8.
#
#  KAŻDY TEST MA TU KONTROLĘ UJEMNĄ — I TO ONA JEST TREŚCIĄ TEGO PLIKU
#  -------------------------------------------------------------------
#  Test próby odtworzenia, który sprawdza tylko, że dobry zrzut przechodzi,
#  byłby zielony także wtedy, gdyby skrypt nie sprawdzał NICZEGO: dobry
#  zrzut przechodzi każdy brak kontroli. Dlatego niżej do każdej kontroli
#  skryptu jest fikstura, która ją ŁAMIE, i asercja na dosłowny komunikat
#  oraz na kod wyjścia. Najważniejsza jest fikstura „wyzwalacz-atrapa”:
#  wyzwalacz JEST, jest włączony, liczby tabel i wierszy się zgadzają,
#  a bariera nie trzyma. Łapie ją wyłącznie sonda zachowania.
#
#  Uruchomienie:  bash tests/skrypty/proba-odtworzenia.sh
#  Wymaga: PostgreSQL jako rola `kuking`/`kuking` (tak jak testy), pg_dump,
#          pg_restore, openssl, php (do migracji fikstury).
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SKRYPT_PROBY="${KATALOG}/scripts/proba-odtworzenia.sh"
SKRYPT_KOPII="${KATALOG}/scripts/kopia-lokalna.sh"

export PGPASSWORD="${PGPASSWORD:-kuking}"
# ─────────────────────────────────────────────────────────────────────────────
#  POŚWIADCZENIA BIERZEMY ZE ŚRODOWISKA, NIE Z PAMIĘCI
#
#  Do 11 września stało tu na sztywno `kuking:kuking`. Przechodziło lokalnie,
#  bo tyle ma nasz kontener deweloperski — i OBLEWAŁO CAŁY ZESTAW w CI, gdzie
#  usługa Postgresa startuje z `POSTGRES_PASSWORD: secret` (`ci.yml`). Objaw
#  był mylący: 44 sprawdzenia na 75 czerwone, każde z innym komunikatem,
#  a przyczyna jedna — `FATAL: password authentication failed for user
#  "kuking"`. Wyglądało to na usterkę skryptów kopii, a było niemożnością
#  zalogowania się do bazy.
#
#  Te same nazwy zmiennych czyta aplikacja (`config/database.php`), więc test
#  łączy się dokładnie tam, gdzie ona. Wartości zapasowe są lokalne, żeby
#  `bash tests/skrypty/proba-odtworzenia.sh` dalej działało bez ustawiania
#  czegokolwiek.
# ─────────────────────────────────────────────────────────────────────────────
BAZA_UZYTKOWNIK="${DB_USERNAME:-kuking}"
BAZA_HASLO="${DB_PASSWORD:-kuking}"
BAZA_HOST="${DB_HOST:-127.0.0.1}"
BAZA_PORT="${DB_PORT:-5432}"
BAZA_POLACZENIE="${BAZA_UZYTKOWNIK}:${BAZA_HASLO}@${BAZA_HOST}:${BAZA_PORT}"

SERWER="postgresql://${BAZA_POLACZENIE}/postgres"
# `psql` do przygotowania fikstur — TE SAME poświadczenia co wyżej.
#
# Stało tu `psql -q -U kuking -h 127.0.0.1`: twardy użytkownik, twardy host,
# BEZ PORTU i BEZ HASŁA. Lokalnie działa, bo nasz Postgres ufa połączeniom
# miejscowym. W CI nie ma prawa zadziałać — usługa jest wystawiona na innym
# porcie i wymaga hasła. Po naprawie samych adresów DSN zostało 25 oblanych
# sprawdzeń z 75 i wszystkie waliły w port 5432, podczas gdy udane szły na
# port z `DB_PORT`. To była reszta tej samej usterki, nie nowa.
#
# `PGPASSWORD` eksportujemy, bo `psql` nie przyjmuje hasła w argumencie;
# dziedziczą je też skrypty wołane niżej, i o to chodzi.
export PGPASSWORD="${BAZA_HASLO}"
PSQL=(psql -q -U "${BAZA_UZYTKOWNIK}" -h "${BAZA_HOST}" -p "${BAZA_PORT}")

# Nazwy baz są UNIKALNE DLA WORKTREE, bo w tym kontenerze pracuje równolegle
# kilku agentów i kilka przebiegów testów. Sufiks liczy ta sama funkcja, co
# nazwy baz testowych (`tests/bootstrap.php`) — żeby nie było w repozytorium
# drugiej reguły nazywania baz, która może się z tamtą rozjechać.
SUFIKS="$(php -r 'require "'"${KATALOG}"'/tests/nazwa-bazy.php"; echo substr(kuking_nazwa_testowej_bazy("'"${KATALOG}"'"), strlen("kuking_test"));' 2>/dev/null)"
SUFIKS="${SUFIKS:-_glowny}"

BAZA_ZRODLOWA="kuking_zrodlo_proby${SUFIKS}"
BAZA_PROBNA="proba_odtworzenia_test${SUFIKS//_/}"
KATALOG_KOPII=''

zdane=0
oblane=0

sprawdz() {
  local opis="$1" oczekiwane="$2" otrzymane="$3"
  if [[ "${oczekiwane}" == "${otrzymane}" ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n     oczekiwano: %s\n     otrzymano:  %s\n' \
      "${opis}" "${oczekiwane}" "${otrzymane}"
    oblane=$((oblane + 1))
  fi
}

# Asercja na FRAGMENT komunikatu, a nie na całość: komunikaty są długie
# i mają prawo się zmieniać, ale ten jeden fragment jest tym, po którym
# człowiek rozpoznaje, co się stało. Zmiana fragmentu ma oblać test.
sprawdz_zawiera() {
  local opis="$1" fragment="$2" tresc="$3"
  if grep -qF -- "${fragment}" <<<"${tresc}"; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n     brak fragmentu: %s\n     w treści:  %s\n' \
      "${opis}" "${fragment}" "$(tr '\n' '|' <<<"${tresc}" | cut -c1-400)"
    oblane=$((oblane + 1))
  fi
}

# Strażnik przed fałszywą zielenią: bez tych plików nie ma czego testować.
for plik in "${SKRYPT_PROBY}" "${SKRYPT_KOPII}"; do
  if [[ ! -f "${plik}" ]]; then
    printf '  \033[0;31m✗\033[0m brak pliku %s\n' "${plik#"${KATALOG}"/}"
    printf '\033[0;31mNie ma czego testować.\033[0m\n'
    exit 1
  fi
done

for narzedzie in pg_dump pg_restore psql openssl php; do
  if ! command -v "${narzedzie}" >/dev/null 2>&1; then
    printf '\033[0;31mBrak narzędzia %s — nie ma czego dowodzić.\033[0m\n' "${narzedzie}" >&2
    exit 1
  fi
done

# Port z tej samej zmiennej, z której korzysta reszta skryptu (`BAZA_PORT`),
# a nie domyślny 5432 gołego `pg_isready` — inaczej test melduje „baza jest",
# patrząc na klaster innego projektu.
if ! pg_isready -q -h "${BAZA_HOST}" -p "${BAZA_PORT}" 2>/dev/null; then
  printf '\033[0;31mPostgreSQL nie odpowiada na %s:%s — nie ma czego dowodzić.\033[0m\n' \
    "${BAZA_HOST}" "${BAZA_PORT}" >&2
  exit 1
fi

posprzataj() {
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_ZRODLOWA} WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA} WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}_zajeta WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}por WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}pelny WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}obca WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}cms WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}bezhasla WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}drop WITH (FORCE)" >/dev/null 2>&1
  [[ -n "${KATALOG_KOPII}" && -d "${KATALOG_KOPII}" ]] && rm -rf "${KATALOG_KOPII}"
  return 0
}
trap posprzataj EXIT

# =============================================================================
#  Fikstura: prawdziwa baza Kukinga po wszystkich migracjach, z kilkoma
#  wierszami. Migracje, nie ręcznie pisany schemat — inaczej test dowodziłby
#  czegoś o swojej własnej atrapie schematu, a nie o schemacie tego projektu.
# =============================================================================
echo "── Fikstura: schemat po migracjach ──"

posprzataj
"${PSQL[@]}" -d postgres -c "CREATE DATABASE ${BAZA_ZRODLOWA} OWNER kuking" >/dev/null 2>&1

DSN_ZRODLA="postgresql://${BAZA_POLACZENIE}/${BAZA_ZRODLOWA}"

if ! (cd "${KATALOG}" && APP_BASE_PATH="${KATALOG}" DB_DATABASE="${BAZA_ZRODLOWA}" \
  php artisan migrate --force >/dev/null 2>&1); then
  printf '  \033[0;31m✗\033[0m nie udało się zmigrować bazy %s\n' "${BAZA_ZRODLOWA}"
  exit 1
fi

"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
INSERT INTO users (id, email, password) VALUES
  ('11111111-0000-4000-8000-000000000001', 'fikstura-1@example.invalid', 'x'),
  ('11111111-0000-4000-8000-000000000002', 'fikstura-2@example.invalid', 'x');
INSERT INTO follows (follower_id, followed_id)
  VALUES ('11111111-0000-4000-8000-000000000001', '11111111-0000-4000-8000-000000000002');
SQL

TABEL_FIKSTURY="$("${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -Atc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")"
sprawdz "fikstura ma schemat po migracjach (co najmniej 40 tabel)" \
  "tak" "$(((TABEL_FIKSTURY >= 40)) && echo tak || echo "nie (${TABEL_FIKSTURY})")"

WYZWALACZY_FIKSTURY="$("${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -Atc \
  "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
     JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE NOT t.tgisinternal AND n.nspname='public'")"
sprawdz "fikstura niesie trzy wyzwalacze gwarancji (D-072, D-080)" "3" "${WYZWALACZY_FIKSTURY}"

# =============================================================================
echo
echo "── scripts/kopia-lokalna.sh ──"
# =============================================================================

KATALOG_KOPII="$(mktemp -d "${TMPDIR:-/tmp}/kopia-testy.XXXXXX")"

# --- Przebieg dodatni: kopia powstaje i przechodzi własną weryfikację -------
wyjscie="$(bash "${SKRYPT_KOPII}" --zrodlo "${DSN_ZRODLA}" --katalog "${KATALOG_KOPII}" 2>&1)"
kod=$?
sprawdz "kopia bez szyfrowania kończy się kodem 0" "0" "${kod}"
sprawdz_zawiera "kopia mówi, ile tabel z danymi zrzuciła" \
  "tabel z danymi" "${wyjscie}"
sprawdz_zawiera "kopia mówi wprost, że nie jest zaszyfrowana" \
  "UWAGA: kopia NIE JEST zaszyfrowana." "${wyjscie}"
sprawdz_zawiera "kopia odsyła do próby odtworzenia, a nie kończy na sobie" \
  "I DOPÓKI JEJ NIE ODTWORZYSZ, JEST OBIETNICĄ, NIE KOPIĄ." "${wyjscie}"

ZRZUT_JAWNY="$(find "${KATALOG_KOPII}" -maxdepth 1 -name '*.dump' | head -1)"
sprawdz "kopia zostawiła plik zrzutu" "tak" \
  "$([[ -s "${ZRZUT_JAWNY}" ]] && echo tak || echo nie)"
sprawdz "kopia zostawiła plik .meta" "tak" \
  "$([[ -n "$(find "${KATALOG_KOPII}" -maxdepth 1 -name '*.meta')" ]] && echo tak || echo nie)"

# --- KONTROLA UJEMNA: „zrzut się nie udał” to INNY stan niż „zrzut jest pusty”
#
# Oba kończą się kodem różnym od zera i oba MUSZĄ dać się rozróżnić bez
# czytania logu: inaczej w panelu widać tylko „czerwone” i nie wiadomo, czy
# nie było jak się połączyć, czy zrzut wyszedł podejrzanie mały. Tu pierwszy
# z nich: baza, której nie ma.
#
# KATALOG MUSI BYĆ PUSTY I WŁASNY — inaczej ta kontrola nie mierzy niczego.
#
# Wersja licząca pliki w `${KATALOG_KOPII}` oblewała, i słusznie: kilkanaście
# wierszy wyżej stoi tam zrzut z UDANEJ kopii. Liczyła więc cudzy plik
# i nazywała go kadłubkiem po nieudanym `pg_dump`. Skrypt kasuje swój kadłubek
# prawidłowo (`rm -f "${PLIK}"` przed `padnij 30`) — to sprawdzenie patrzyło
# w złe miejsce.
#
# Pułapka jest ta sama, co przy kontroli ujemnej, która nie oblewa: wynik
# wygląda na pomiar, a jest artefaktem stanu zostawionego przez poprzedni krok.
KATALOG_KADLUBKA="$(mktemp -d "${TMPDIR:-/tmp}/kopia-kadlubek.XXXXXX")"

wyjscie="$(bash "${SKRYPT_KOPII}" \
  --zrodlo "postgresql://${BAZA_POLACZENIE}/nie_ma_takiej_bazy_kuking" \
  --katalog "${KATALOG_KADLUBKA}" 2>&1)"
kod=$?
sprawdz "„pg_dump się nie udał” ma własny kod wyjścia (30)" "30" "${kod}"
sprawdz_zawiera "…i mówi, że kopii NIE MA" "KOPII NIE MA" "${wyjscie}"
sprawdz "…i nie zostawia po sobie pliku-kadłubka" "0" \
  "$(find "${KATALOG_KADLUBKA}" -maxdepth 1 -name '*.dump' | wc -l)"
sprawdz "…ani żadnego innego pliku" "0" \
  "$(find "${KATALOG_KADLUBKA}" -maxdepth 1 -type f | wc -l)"

rm -rf "${KATALOG_KADLUBKA}"

# --- KONTROLA UJEMNA: katalog w repozytorium --------------------------------
# Zrzut w katalogu roboczym gita kończy się kiedyś w commicie.
wyjscie="$(bash "${SKRYPT_KOPII}" --zrodlo "${DSN_ZRODLA}" --katalog "${KATALOG}/storage" 2>&1)"
kod=$?
sprawdz "odmawia zapisu kopii W REPOZYTORIUM (kod 20)" "20" "${kod}"
sprawdz_zawiera "…i mówi dlaczego" "leży w repozytorium" "${wyjscie}"

# --- KONTROLA UJEMNA: sklejona para kluczy ----------------------------------
# `cat kuking-kopie-*.pem` skleja publiczny z prywatnym. Wynik szyfruje bez
# problemu, tylko klucz do odczytu leży od tej pory obok kopii.
openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
  -keyout "${KATALOG_KOPII}/PRYWATNY.pem" -out "${KATALOG_KOPII}/publiczny.pem" \
  -subj "/CN=proba odtworzenia test" >/dev/null 2>&1
cat "${KATALOG_KOPII}/publiczny.pem" "${KATALOG_KOPII}/PRYWATNY.pem" \
  >"${KATALOG_KOPII}/SKLEJONE.pem"

rm -f "${KATALOG_KOPII}"/*.dump "${KATALOG_KOPII}"/*.meta

wyjscie="$(bash "${SKRYPT_KOPII}" --zrodlo "${DSN_ZRODLA}" --katalog "${KATALOG_KOPII}" \
  --klucz-publiczny "${KATALOG_KOPII}/SKLEJONE.pem" 2>&1)"
kod=$?
sprawdz "odmawia, gdy „klucz publiczny” zawiera klucz PRYWATNY (kod 22)" "22" "${kod}"
sprawdz_zawiera "…i nazywa rzecz po imieniu" "zawiera KLUCZ PRYWATNY" "${wyjscie}"

# NAJWAŻNIEJSZA ASERCJA TEJ KONTROLI. Pierwsza wersja skryptu sprawdzała
# klucz PO zrobieniu zrzutu: mówiła „KOPIA NIE POWSTAŁA”, a w katalogu
# zostawał jawny zrzut całej bazy, o którym nikt nie wiedział.
sprawdz "…i NIE zostawia po sobie jawnego zrzutu" "0" \
  "$(find "${KATALOG_KOPII}" -maxdepth 1 -name '*.dump' | wc -l)"

# --- Przebieg dodatni: kopia szyfrowana -------------------------------------
wyjscie="$(bash "${SKRYPT_KOPII}" --zrodlo "${DSN_ZRODLA}" --katalog "${KATALOG_KOPII}" \
  --klucz-publiczny "${KATALOG_KOPII}/publiczny.pem" 2>&1)"
kod=$?
sprawdz "kopia szyfrowana kończy się kodem 0" "0" "${kod}"

ZRZUT_CMS="$(find "${KATALOG_KOPII}" -maxdepth 1 -name '*.dump.cms' | head -1)"
sprawdz "powstał szyfrogram .cms" "tak" \
  "$([[ -s "${ZRZUT_CMS}" ]] && echo tak || echo nie)"
sprawdz "zrzut JAWNY zniknął z dysku po zaszyfrowaniu" "0" \
  "$(find "${KATALOG_KOPII}" -maxdepth 1 -name '*.dump' | wc -l)"

# Szyfrogram nie może być czytelny bez klucza prywatnego. Zamiast wierzyć
# nazwie formatu — sprawdzamy, że `pg_restore` NIE potrafi go przeczytać.
if pg_restore --list "${ZRZUT_CMS}" >/dev/null 2>&1; then
  sprawdz "szyfrogramu nie da się odczytać bez klucza" "pg_restore odmawia" "pg_restore CZYTA"
else
  sprawdz "szyfrogramu nie da się odczytać bez klucza" "pg_restore odmawia" "pg_restore odmawia"
fi

# =============================================================================
echo
echo "── scripts/proba-odtworzenia.sh — przebieg dodatni ──"
# =============================================================================

# Zrzut jawny do dalszych prób (kopia szyfrowana ma własny test niżej).
ZRZUT_DOBRY="${KATALOG_KOPII}/dobry.dump"
pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --no-privileges --file="${ZRZUT_DOBRY}"

uruchom_probe() {
  bash "${SKRYPT_PROBY}" --zrzut "$1" --serwer "${SERWER}" --baza "${BAZA_PROBNA}" \
    --tabele "${2:-users,follows}" "${@:3}" 2>&1
}

wyjscie="$(uruchom_probe "${ZRZUT_DOBRY}")"
kod=$?
sprawdz "dobry zrzut przechodzi próbę odtworzenia (kod 0)" "0" "${kod}"
sprawdz_zawiera "…bezpiecznik 1 meldował się" "bezpiecznik 1" "${wyjscie}"
sprawdz_zawiera "…bezpiecznik 2 pytał SERWER o nazwę bazy" \
  "serwer potwierdza bazę" "${wyjscie}"
sprawdz_zawiera "…sprawdził wyzwalacze wprost" \
  "wszystkie trzy nazwane obecne i włączone" "${wyjscie}"
sprawdz_zawiera "…sprawdził, że wyzwalacz follows DZIAŁA" \
  "blokada ma pierwszeństwo przed obserwowaniem" "${wyjscie}"
sprawdz_zawiera "…sprawdził, że dziennika zgód nie da się zmienić" \
  "dziennika zgód nie da się zmienić" "${wyjscie}"
sprawdz_zawiera "…sprawdził CHECK i UNIQUE zachowaniem, nie tylko liczbą" \
  "nie da się obserwować samego siebie" "${wyjscie}"
sprawdz_zawiera "…miał kontrolę dodatnią do sond" \
  "kontrola dodatnia: zapis dozwolony PRZESZEDŁ" "${wyjscie}"
sprawdz_zawiera "…podał zmierzone RTO do wpisania w tabelę §5" \
  "czas odtworzenia" "${wyjscie}"
sprawdz "…i posprzątał po sobie bazę próbną" "0" \
  "$("${PSQL[@]}" -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname='${BAZA_PROBNA}'")"

# Pełny obieg: kopia szyfrowana kluczem publicznym odtworzona SAMYM
# kluczem prywatnym. To jest ta droga, którą właściciel pójdzie w awarii
# (KOPIE_I_ODTWORZENIE.md §7.4) — i jedyna, w której klucz prywatny jest
# w ogóle potrzebny.
wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_CMS}" --klucz "${KATALOG_KOPII}/PRYWATNY.pem" \
  --serwer "${SERWER}" --baza "${BAZA_PROBNA}" --tabele users,follows 2>&1)"
kod=$?
sprawdz "kopia SZYFROWANA odtwarza się samym kluczem prywatnym (kod 0)" "0" "${kod}"
sprawdz_zawiera "…z odszyfrowaniem jako osobnym, mierzonym krokiem" \
  "odszyfrowane w" "${wyjscie}"

# =============================================================================
echo
echo "── BEZPIECZNIK 1 (nazwa i adres) — kontrole ujemne ──"
# =============================================================================
#
#  Pierwszy bezpiecznik patrzy na to, CO KAZANO: na nazwę bazy i adres
#  serwera, jako na napisy, przed jakimkolwiek połączeniem.

for zla_nazwa in kuking railway postgres kuking_test restore_drill; do
  wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
    --baza "${zla_nazwa}" 2>&1)"
  kod=$?
  sprawdz "odmawia odtwarzania do bazy \"${zla_nazwa}\" (kod 20)" "20" "${kod}"
done
sprawdz_zawiera "…i mówi, jaka nazwa jest dozwolona" \
  'wolno wyłącznie `proba_odtworzenia*`' "${wyjscie}"

# Adres Railwaya w --serwer to pomyłka, po której nie ma już czego naprawiać.
for zly_adres in \
  "postgresql://u:h@monorail.proxy.rlwy.net:12345/railway" \
  "postgresql://u:h@postgres.railway.internal:5432/railway"; do
  wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${zly_adres}" 2>&1)"
  kod=$?
  sprawdz "odmawia, gdy --serwer wskazuje Railwaya ($(sed -E 's#.*@([^:/]+).*#\1#' <<<"${zly_adres}")) (kod 21)" \
    "21" "${kod}"
done
sprawdz_zawiera "…i tłumaczy, gdzie zakłada się bazę próbną" \
  "Baza próbna zakłada się NA INNYM serwerze" "${wyjscie}"

# =============================================================================
echo
echo "── BEZPIECZNIK 2 (serwer) — kontrole ujemne ──"
# =============================================================================
#
#  DLACZEGO TO NIE JEST TEN SAM BEZPIECZNIK DRUGI RAZ.
#  Nazwa w DSN-ie nie musi być nazwą bazy, do której libpq się połączy:
#  parametr `dbname` z części zapytania WYGRYWA z nazwą ze ścieżki. Poniższy
#  adres ma w ścieżce nazwę próbną (bezpiecznik 1 go przepuszcza), a łączy
#  się z bazą źródłową. Gdyby bezpiecznika 2 nie było, `pg_restore` wlałby
#  49 tabel do bazy, która czemuś służy.
wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" \
  --serwer "${SERWER}?dbname=${BAZA_ZRODLOWA}" --baza "${BAZA_PROBNA}" 2>&1)"
kod=$?
sprawdz "łapie przekierowanie połączenia przez ?dbname= (kod 22)" "22" "${kod}"
sprawdz_zawiera "…i mówi, gdzie NAPRAWDĘ jesteśmy" \
  "a serwer mówi, że jesteśmy w \"${BAZA_ZRODLOWA}\"" "${wyjscie}"
sprawdz "…a bezpiecznik 1 sam by to przepuścił (dowód, że są dwa)" \
  "tak" "$(grep -qF 'bezpiecznik 1' <<<"${wyjscie}" && echo tak || echo nie)"

# Baza próbna, która NIE jest pusta. Produkcyjna nigdy nie jest pusta, więc
# ten jeden warunek łapie każdy cel, który przeżył bezpiecznik 1.
"${PSQL[@]}" -d postgres -c "CREATE DATABASE ${BAZA_PROBNA}_zajeta OWNER kuking" >/dev/null 2>&1
"${PSQL[@]}" -d "${BAZA_PROBNA}_zajeta" -c 'CREATE TABLE cos_wazne (id int)' >/dev/null 2>&1

wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
  --baza "${BAZA_PROBNA}_zajeta" 2>&1)"
kod=$?
sprawdz "odmawia odtwarzania do bazy, która NIE jest pusta (kod 23)" "23" "${kod}"
sprawdz_zawiera "…i mówi, ile tam tabel" "nie jest pusta (1 tabel" "${wyjscie}"

# ODWROTNA STRONA TEJ SAMEJ ODMOWY, i to jest błąd, który ten test złapał
# w pierwszej wersji skryptu: sprzątanie kasowało bazę próbną ZAWSZE, więc
# po odmowie „baza nie jest pusta” kasowało dokładnie tę bazę, której
# właśnie odmówiło dotknąć.
sprawdz "…i NIE kasuje bazy, której odmówił dotknąć" "1" \
  "$("${PSQL[@]}" -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname='${BAZA_PROBNA}_zajeta'")"
sprawdz "…a tabela w tej bazie jest nietknięta" "1" \
  "$("${PSQL[@]}" -d "${BAZA_PROBNA}_zajeta" -Atc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")"

# =============================================================================
echo
echo "── BEZPIECZNIK 3 (tożsamość instancji docelowej, #594) ──"
# =============================================================================
#
#  ODTWORZONA USTERKA, ZMIERZONA 17.09.2026
#  Bezpiecznik 1 czyta NAZWĘ HOSTA. Za tunelem (`railway connect postgres
#  --tunnel-only`) produkcja nazywa się `127.0.0.1` i przechodzi bez słowa,
#  a bezpiecznik 2 pyta o bazę CELU — świeżo założoną, więc naprawdę pustą
#  i naprawdę nazwaną `proba_odtworzenia_*`.
#
#  Zmierzone: TEN SAM klaster odrzucony pod adresem `*.proxy.rlwy.net`
#  (kod 21) został PRZYJĘTY pod `127.0.0.1` — baza powstała, `pg_restore`
#  wlał na tę instancję komplet danych osobowych, a skrypt wypisał przy tym
#  „serwer nie jest produkcyjny".
#
#  „Obca instancja" jest tu robiona najuczciwszym dostępnym sposobem: przez
#  wskazanie repozytorium INNEGO Postgresa (`DB_PORT`), niż jest celem. To
#  jest dokładnie ten kształt, co tunel — cel jest osiągalny i wygląda
#  niewinnie, a klaster, który repozytorium zna jako swój, to nie on.
proba_obca_instancja() {
  (
    export DB_PORT=1 # pod tym portem nie ma nikogo: cel NIE JEST klastrem repo
    bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
      --baza "${BAZA_PROBNA}obca" --tabele users,follows "$@" 2>&1
  )
}

wyjscie="$(proba_obca_instancja)"
kod=$?
sprawdz "odmawia obcej instancji docelowej (kod 24)" "24" "${kod}"
sprawdz_zawiera "…i mówi wprost, że nazwa hosta nie jest dowodem" \
  "za tunelem" "${wyjscie}"

# NAJWAŻNIEJSZA ASERCJA TEJ KONTROLI: odmowa ma nastąpić, ZANIM cokolwiek
# powstanie. Bezpiecznik, który odmawia po `CREATE DATABASE`, zostawia na
# cudzej instancji bazę, o której nikt nie wie.
sprawdz "…zanim na tej instancji cokolwiek powstanie" "0" \
  "$("${PSQL[@]}" -d postgres -Atc \
    "SELECT count(*) FROM pg_database WHERE datname='${BAZA_PROBNA}obca'")"

ODCISK_Z_ODMOWY="$(sed -n -E 's/.*--instancja ([0-9a-f]{16}).*/\1/p' <<<"${wyjscie}" | head -1)"
sprawdz "…podając odcisk, którym da się to potwierdzić" "16" "${#ODCISK_Z_ODMOWY}"

wyjscie="$(proba_obca_instancja --instancja "${ODCISK_Z_ODMOWY}")"
kod=$?
sprawdz "…a po JAWNYM potwierdzeniu przechodzi (kod 0)" "0" "${kod}"
sprawdz_zawiera "…meldując, że potwierdzenie było jawne" \
  "potwierdzona jawnie" "${wyjscie}"

wyjscie="$(proba_obca_instancja --instancja 0000000000000000)"
kod=$?
sprawdz "potwierdzenie NIE TEJ instancji oblewa (kod 24)" "24" "${kod}"
sprawdz_zawiera "…i pokazuje obie wartości, nie samo „nie zgadza się\"" \
  "a pod adresem stoi" "${wyjscie}"

# KONTROLA DODATNIA. Bez niej wszystkie asercje wyżej byłyby zielone także
# wtedy, gdyby bezpiecznik 3 odmawiał ZAWSZE — a ćwiczenie na własnym
# Postgresie ma zostać JEDNĄ komendą (pułapka 4 z PULAPKI_TESTOW.md).
sprawdz_zawiera "kontrola dodatnia: własny Postgres repozytorium przechodzi bez potwierdzenia" \
  "cel to własny Postgres tego repozytorium" \
  "$(uruchom_probe "${ZRZUT_DOBRY}")"

# =============================================================================
echo
echo "── USZKODZONY SZYFROGRAM (#594) ──"
# =============================================================================
#
#  ZMIERZONE 17.09.2026: CMS `EnvelopedData` z AES-256-CBC nie niesie
#  uwierzytelnienia. Przekłamanie bajtów w środku `.cms` przeszło przez
#  `openssl cms -decrypt` z kodem 0 i przez spis archiwum, a ćwiczenie padło
#  dopiero na `pg_restore` (kod 50, „odtworzenie nie udało się") — czyli PO
#  założeniu bazy i po wlaniu do niej części danych, z komunikatem
#  wskazującym na serwer, a nie na uszkodzony plik.
#
#  Skrót jawnego zrzutu leży w pliku `.meta` obok kopii od początku. Do tej
#  pory nikt go nie czytał.
ZRZUT_CMS_USZKODZONY="${KATALOG_KOPII}/uszkodzony.dump.cms"
cp "${ZRZUT_CMS}" "${ZRZUT_CMS_USZKODZONY}"
cp "${ZRZUT_CMS%.dump.cms}.meta" "${KATALOG_KOPII}/uszkodzony.meta"
printf 'ZEPSUTE' | dd of="${ZRZUT_CMS_USZKODZONY}" bs=1 \
  seek=$(($(stat -c %s "${ZRZUT_CMS_USZKODZONY}") / 2)) conv=notrunc status=none 2>/dev/null

wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_CMS_USZKODZONY}" \
  --klucz "${KATALOG_KOPII}/PRYWATNY.pem" --serwer "${SERWER}" \
  --baza "${BAZA_PROBNA}cms" 2>&1)"
kod=$?
sprawdz "uszkodzony szyfrogram oblewa na skrócie z .meta (kod 44)" "44" "${kod}"
sprawdz_zawiera "…nazywając rzecz po imieniu, nie „pg_restore padł\"" \
  "NIE ZGADZA SIĘ ze skrótem" "${wyjscie}"
sprawdz "…i NIE zakłada bazy, do której miałby to wlać" "0" \
  "$("${PSQL[@]}" -d postgres -Atc \
    "SELECT count(*) FROM pg_database WHERE datname='${BAZA_PROBNA}cms'")"

# KONTROLA DODATNIA do tej samej kontroli: NIETKNIĘTY szyfrogram z tym samym
# `.meta` ma przejść. Bez niej „oblewa zawsze" byłoby nie do odróżnienia
# od „łapie uszkodzenie".
sprawdz_zawiera "kontrola dodatnia: nietknięty szyfrogram przechodzi porównanie skrótu" \
  "zgadza się z .meta" \
  "$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_CMS}" \
    --klucz "${KATALOG_KOPII}/PRYWATNY.pem" --serwer "${SERWER}" \
    --baza "${BAZA_PROBNA}cms" --tabele users,follows 2>&1)"

# =============================================================================
echo
echo "── HASŁO BAZY POZA LISTĄ PROCESÓW (#594) ──"
# =============================================================================
#
#  ODTWORZONE 17.09.2026 PRAWDZIWYM `ps`: przez cały czas trwania zrzutu
#  wiersz `ps -o args=` procesu `pg_dump` zawierał pełny DSN razem z hasłem.
#  Argumenty procesu są na Linuksie jawne dla KAŻDEGO użytkownika maszyny —
#  a tym DSN-em jest poświadczenie do produkcyjnej bazy.
#
#  TEST NIE UŻYWA JUŻ `ps` I TO JEST POPRAWKA, NIE ULGA. Pierwsza wersja
#  uruchamiała prawdziwy `pg_dump` w tle i próbowała złapać jego wiersz
#  z `ps`. Przy pełnym zestawie testów zrzut fikstury kończył się szybciej,
#  niż pętla zdążyła spróbować — asercja „ps naprawdę pokazał wiersz"
#  oblewała się losowo (zmierzone: samotnie zielona, w pełnym przebiegu
#  czerwona, dwa razy z rzędu). Wyścig w teście jest usterką testu.
#
#  Patrzymy więc na ARGUMENTY, które podstawiony `pg_dump` naprawdę dostał.
#  To jest dokładnie to, co pokazuje `ps`: `ps -o args=` wypisuje `argv`
#  procesu — ten sam wektor, który przekazuje `execve`. Różnica jest tylko
#  taka, że tu nie ma czego przegapić.
KATALOG_PS="$(mktemp -d)"
cat >"${KATALOG_PS}/pg_dump" <<'PODSTAWKA'
#!/usr/bin/env bash
# Podglądamy dwie rzeczy naraz: wiersz z `ps` prawdziwego procesu ORAZ to,
# co pg_dump dostał w `PGPASSFILE`. Druga połowa jest tu konieczna, bo
# „zrzut powstał" niczego nie dowodzi na kliencie, którego `pg_hba.conf`
# ustawiono na `trust` — a tak stoi lokalny klaster deweloperski.
printf 'PGPASSFILE=%s\n' "${PGPASSFILE:-BRAK}" >>"${PODGLAD_PASS}"
if [[ -n "${PGPASSFILE:-}" && -f "${PGPASSFILE}" ]]; then
  printf 'PRAWA=%s\n' "$(stat -c %a "${PGPASSFILE}")" >>"${PODGLAD_PASS}"
  cat "${PGPASSFILE}" >>"${PODGLAD_PASS}"
fi
PRAWDZIWY="$(PATH="${PATH#*:}" command -v pg_dump)"
# `argv` procesu — to samo, co wypisałby `ps -o args=`, tylko bez wyścigu
# o to, czy zdążymy zajrzeć, zanim proces się skończy.
printf '%s\n' "${PRAWDZIWY} $*" >>"${PODGLAD_PS}"
exec "${PRAWDZIWY}" "$@"
PODSTAWKA
chmod +x "${KATALOG_PS}/pg_dump"

KATALOG_KOPII_PS="${KATALOG_PS}/kopia"
mkdir -p "${KATALOG_KOPII_PS}"
PODGLAD_PS="${KATALOG_PS}/ps.txt"
PODGLAD_PASS="${KATALOG_PS}/pass.txt"
: >"${PODGLAD_PS}"
: >"${PODGLAD_PASS}"

# `PGPASSWORD` MUSI ZNIKNĄĆ NA CZAS TEJ PRÓBY — inaczej nie widać RÓŻNICY
# między „hasło poszło przez plik" a „hasło poszło przez środowisko".
kod_ps="$(
  unset PGPASSWORD
  PATH="${KATALOG_PS}:${PATH}" PODGLAD_PS="${PODGLAD_PS}" PODGLAD_PASS="${PODGLAD_PASS}" \
    bash "${SKRYPT_KOPII}" --zrodlo "${DSN_ZRODLA}" --katalog "${KATALOG_KOPII_PS}" \
    >/dev/null 2>&1
  printf '%s' "$?"
)"

sprawdz "kopia powstała (kod 0)" "0" "${kod_ps}"

# UWAGA NA FAŁSZYWY DOWÓD. Lokalny klaster deweloperski przyjmuje połączenia
# BEZ hasła (`trust` w pg_hba.conf) — sprawdzone 17.09.2026. Samo „zrzut
# powstał" nie dowodzi więc, że `PGPASSFILE` w ogóle zadziałał: dowodzi tylko,
# że `pg_dump` się połączył. Dlatego niżej patrzymy PROSTO na plik, który
# `pg_dump` dostał, i na jego prawa.
sprawdz "pg_dump dostał PGPASSFILE" "tak" \
  "$(grep -q '^PGPASSFILE=/' "${PODGLAD_PASS}" && echo tak || echo nie)"
sprawdz "…z hasłem bazy w środku" "tak" \
  "$(grep -qF ":${BAZA_HASLO}" "${PODGLAD_PASS}" && echo tak || echo nie)"
sprawdz "…i prawami 600, bo to poświadczenie do bazy" "PRAWA=600" \
  "$(grep -m1 '^PRAWA=' "${PODGLAD_PASS}" || echo 'PRAWA=brak')"

sprawdz "argumenty pg_dumpa NAPRAWDĘ zapisane (inaczej test nic nie mierzy)" \
  "tak" "$(grep -qF 'format=custom' "${PODGLAD_PS}" && echo tak || echo nie)"
sprawdz "…i nie ma w nich hasła do bazy" "brak" \
  "$(grep -qF ":${BAZA_HASLO}@" "${PODGLAD_PS}" && echo JEST || echo brak)"
sprawdz "…a sam adres bazy w argumentach nadal jest (dowód, że patrzymy w to miejsce)" \
  "tak" "$(grep -qF "@${BAZA_HOST}:${BAZA_PORT}/" "${PODGLAD_PS}" && echo tak || echo nie)"

rm -rf "${KATALOG_PS}"

# --- POŚWIADCZENIE MUSI PRZEŻYĆ KATALOG ROBOCZY -----------------------------
#
#  `sprzataj()` kasuje katalog roboczy, a DOPIERO POTEM robi `DROP DATABASE`.
#  Pierwsza wersja poprawki z #594 trzymała `PGPASSFILE` właśnie tam — więc na
#  serwerze WYMAGAJĄCYM HASŁA (CI, produkcja za tunelem) `DROP` nie miałby czym
#  się zalogować i baza próbna zostawałaby po ćwiczeniu, przy samym ostrzeżeniu
#  w logu.
#
#  SAMEGO SKUTKU (baza zostaje) NA TYM KLIENCIE POKAZAĆ SIĘ NIE DA: stoi on na
#  `trust`, więc `DROP` udaje się także bez poświadczenia — sprawdzone, sabotaż
#  przechodził zielono. Sprawdzamy więc PRZYCZYNĘ, i to zachowaniem, nie
#  czytaniem pliku: podstawiony `psql` zapisuje, czy w chwili `DROP DATABASE`
#  plik z `PGPASSFILE` jeszcze ISTNIEJE. Jeśli nie istnieje, to na serwerze
#  wymagającym hasła tego `DROP`-a już nie będzie.
KATALOG_DROP="$(mktemp -d)"
PODGLAD_DROP="${KATALOG_DROP}/drop.txt"
: >"${PODGLAD_DROP}"

cat >"${KATALOG_DROP}/psql" <<'PODSTAWKA'
#!/usr/bin/env bash
for arg in "$@"; do
  case "${arg}" in
    *'DROP DATABASE IF EXISTS'*)
      if [[ -n "${PGPASSFILE:-}" && -f "${PGPASSFILE}" ]]; then
        printf 'PGPASSFILE_ISTNIEJE\n' >>"${PODGLAD_DROP}"
      else
        printf 'PGPASSFILE_ZNIKNAL\n' >>"${PODGLAD_DROP}"
      fi
      ;;
  esac
done
exec "$(PATH="${PATH#*:}" command -v psql)" "$@"
PODSTAWKA
chmod +x "${KATALOG_DROP}/psql"

(
  PATH="${KATALOG_DROP}:${PATH}" PODGLAD_DROP="${PODGLAD_DROP}" \
    bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
    --baza "${BAZA_PROBNA}drop" --tabele users >/dev/null 2>&1
)

sprawdz "podstawiony psql NAPRAWDĘ zobaczył DROP DATABASE (inaczej nic nie mierzymy)" \
  "tak" "$([[ -s "${PODGLAD_DROP}" ]] && echo tak || echo nie)"
sprawdz "…a PGPASSFILE w tej chwili WCIĄŻ ISTNIEJE (inaczej DROP nie miałby hasła)" \
  "PGPASSFILE_ISTNIEJE" "$(head -1 "${PODGLAD_DROP}" 2>/dev/null || echo brak)"

rm -rf "${KATALOG_DROP}"

# CAŁY PRZEBIEG BEZ `PGPASSWORD` W ŚRODOWISKU — tak jak u człowieka, który
# wkleił DSN z tunelu i nic więcej nie ustawiał. Na tym kliencie (`trust`) nie
# dowodzi to uwierzytelnienia; dowodzi, że rozbicie DSN-u na adres i plik
# niczego po drodze nie psuje i że po ćwiczeniu nie zostaje baza.
kod_sprzatania="$(
  unset PGPASSWORD
  bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
    --baza "${BAZA_PROBNA}bezhasla" --tabele users,follows >/dev/null 2>&1
  printf '%s' "$?"
)"
sprawdz "cały przebieg działa bez PGPASSWORD w środowisku" "0" "${kod_sprzatania}"
sprawdz "…i baza próbna NIE zostaje na serwerze po sprzątaniu" "0" \
  "$("${PSQL[@]}" -d postgres -Atc \
    "SELECT count(*) FROM pg_database WHERE datname='${BAZA_PROBNA}bezhasla'")"

# =============================================================================
echo
echo "── KOPIA PUSTA, OBCIĘTA I BEZ DANYCH — kontrole ujemne ──"
# =============================================================================

# Plik zerobajtowy: `ls` go pokazuje, data się zgadza, wygląda jak kopia.
: >"${KATALOG_KOPII}/zerobajtowy.dump"
wyjscie="$(uruchom_probe "${KATALOG_KOPII}/zerobajtowy.dump")"
kod=$?
sprawdz "oblewa się na zrzucie zerobajtowym (kod 40)" "40" "${kod}"
sprawdz_zawiera "…i podaje zmierzony rozmiar oraz próg" \
  "Zrzut ma 0 B, a minimum to" "${wyjscie}"
sprawdz_zawiera "…mówiąc, że pusta kopia jest gorsza od jej braku" \
  "gorsza od braku kopii, bo usypia" "${wyjscie}"

# Zrzut PUSTEJ bazy: poprawne archiwum, czytelne, tylko bez niczyich danych.
"${PSQL[@]}" -d postgres -c "CREATE DATABASE ${BAZA_PROBNA}_pusta OWNER kuking" >/dev/null 2>&1
pg_dump "postgresql://${BAZA_POLACZENIE}/${BAZA_PROBNA}_pusta" \
  --format=custom --no-owner --file="${KATALOG_KOPII}/pusta-baza.dump" 2>/dev/null
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA}_pusta WITH (FORCE)" >/dev/null 2>&1

wyjscie="$(uruchom_probe "${KATALOG_KOPII}/pusta-baza.dump")"
kod=$?
sprawdz "oblewa się na zrzucie PUSTEJ bazy (kod 40)" "40" "${kod}"

# ZRZUT OBCIĘTY W ŚRODKU — mocniejsza kontrola niż plik pusty.
#
# Tak wygląda kopia przerwana na braku miejsca na dysku albo na zerwanym
# łączu przy pobieraniu z bucketu: plik WAŻY DUŻO (tu 60 000 B, czyli trzy
# razy powyżej progu rozmiaru), data się zgadza, `ls` go pokazuje jak każdy
# inny. Próg rozmiaru go NIE ZŁAPIE — i to jest cała różnica między tym
# przypadkiem a zerobajtowym. Łapie go dopiero odczytanie archiwum z powrotem,
# i dlatego ten krok jest w skrypcie osobno.
head -c 60000 "${ZRZUT_DOBRY}" >"${KATALOG_KOPII}/obciety.dump"
wyjscie="$(uruchom_probe "${KATALOG_KOPII}/obciety.dump")"
kod=$?
sprawdz "oblewa się na zrzucie OBCIĘTYM W ŚRODKU (kod 41)" "41" "${kod}"
sprawdz_zawiera "…mówiąc, że pg_restore nie potrafi go odczytać" \
  "nie potrafi odczytać tego archiwum" "${wyjscie}"
sprawdz_zawiera "…i odsyłając do sha256 z pliku .meta" \
  "sha256_jawnego" "${wyjscie}"
if (($(stat -c %s "${KATALOG_KOPII}/obciety.dump") > 20000)); then
  sprawdz "…a próg rozmiaru sam by go przepuścił (dowód, że to inna kontrola)" \
    "powyżej progu" "powyżej progu"
else
  sprawdz "…a próg rozmiaru sam by go przepuścił (dowód, że to inna kontrola)" \
    "powyżej progu" "poniżej progu"
fi

# Zrzut ze SCHEMATEM, ale bez wierszy — najgroźniejszy z trzech, bo ma
# właściwy rozmiar, wszystkie 49 tabel, wszystkie wyzwalacze i wszystkie
# ograniczenia. Różni się od dobrej kopii wyłącznie tym, że nie ma w nim
# ani jednego konta.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -c 'DELETE FROM follows' -c 'DELETE FROM users' >/dev/null 2>&1
pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --file="${KATALOG_KOPII}/bez-danych.dump"
wyjscie="$(uruchom_probe "${KATALOG_KOPII}/bez-danych.dump" users)"
kod=$?
sprawdz "oblewa się na zrzucie ze schematem, ale BEZ DANYCH (kod 62)" "62" "${kod}"
sprawdz_zawiera "…i mówi, która tabela jest pusta" \
  'W tabeli "users" jest 0 wierszy' "${wyjscie}"
sprawdz_zawiera "…nazywając ten stan wprost" \
  "plik jest, schemat jest," "${wyjscie}"

# Wiersze wracają — dalsze fikstury łamią wyzwalacze, nie dane.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
INSERT INTO users (id, email, password) VALUES
  ('11111111-0000-4000-8000-000000000001', 'fikstura-1@example.invalid', 'x'),
  ('11111111-0000-4000-8000-000000000002', 'fikstura-2@example.invalid', 'x');
INSERT INTO follows (follower_id, followed_id)
  VALUES ('11111111-0000-4000-8000-000000000001', '11111111-0000-4000-8000-000000000002');
SQL

# =============================================================================
echo
echo "── WYZWALACZE — kontrole ujemne (sedno issue #9) ──"
# =============================================================================

# 1. Wyzwalacza NIE MA w zrzucie.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 \
  -c 'DROP TRIGGER follows_blokada_ma_pierwszenstwo_trg ON follows' >/dev/null
pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --file="${KATALOG_KOPII}/bez-wyzwalacza.dump"

wyjscie="$(uruchom_probe "${KATALOG_KOPII}/bez-wyzwalacza.dump")"
kod=$?
sprawdz "oblewa się, gdy zrzut zgubił wyzwalacz (kod 70)" "70" "${kod}"
sprawdz_zawiera "…i mówi, ile ich znalazł" \
  "wyzwalaczy, a ma być co najmniej" "${wyjscie}"

# 2. Wyzwalacz JEST, ale WYŁĄCZONY. `pg_dump` wiernie przenosi stan
#    wyłączenia, a w `pg_trigger` taki wyzwalacz wygląda jak każdy inny.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 \
  -c 'CREATE TRIGGER follows_blokada_ma_pierwszenstwo_trg BEFORE INSERT ON follows
        FOR EACH ROW EXECUTE FUNCTION follows_blokada_ma_pierwszenstwo()' \
  -c 'ALTER TABLE follows DISABLE TRIGGER follows_blokada_ma_pierwszenstwo_trg' >/dev/null
pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --file="${KATALOG_KOPII}/wyzwalacz-wylaczony.dump"

wyjscie="$(uruchom_probe "${KATALOG_KOPII}/wyzwalacz-wylaczony.dump")"
kod=$?
sprawdz "oblewa się, gdy wyzwalacz jest WYŁĄCZONY (kod 70)" "70" "${kod}"
sprawdz_zawiera "…i nazywa stan po imieniu" "WYŁĄCZONY (tgenabled='D')" "${wyjscie}"

# 3. NAJWAŻNIEJSZA FIKSTURA TEGO PLIKU — WYZWALACZ-ATRAPA.
#
#    Wyzwalacz jest, jest włączony, nazywa się tak samo, stoi na tej samej
#    tabeli i na tym samym zdarzeniu. Jego funkcja robi `RETURN NEW` i nic
#    więcej. Liczba tabel się zgadza, liczba wierszy się zgadza, liczba
#    wyzwalaczy się zgadza, liczba ograniczeń się zgadza.
#
#    Każda kontrola „czy JEST” przepuszcza tę bazę. Łapie ją wyłącznie
#    sonda zachowania — i to jest dokładnie powód, dla którego sondy są
#    w tym skrypcie.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 \
  -c 'ALTER TABLE follows ENABLE TRIGGER follows_blokada_ma_pierwszenstwo_trg' \
  -c 'CREATE OR REPLACE FUNCTION follows_blokada_ma_pierwszenstwo() RETURNS TRIGGER
        LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$' >/dev/null
pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --file="${KATALOG_KOPII}/wyzwalacz-atrapa.dump"

wyjscie="$(uruchom_probe "${KATALOG_KOPII}/wyzwalacz-atrapa.dump")"
kod=$?
sprawdz "oblewa się na WYZWALACZU-ATRAPIE (kod 71)" "71" "${kod}"
sprawdz_zawiera "…mówiąc, że zapis PRZESZEDŁ, a miał zostać odrzucony" \
  "zapis PRZESZEDŁ, a miał zostać odrzucony" "${wyjscie}"
sprawdz_zawiera "…i że to zrzut stracił gwarancję, nie baza" \
  "NIE NIESIE tej gwarancji" "${wyjscie}"

# DOWÓD, ŻE TA FIKSTURA PRZECHODZI WSZYSTKIE KONTROLE „CZY JEST”.
# Bez tej asercji test nie odróżniałby „sonda złapała atrapę” od „coś
# innego oblało się wcześniej” — czyli nie dowodziłby, po co są sondy.
sprawdz_zawiera "…a kontrola obecności wyzwalaczy ją PRZEPUŚCIŁA" \
  "wszystkie trzy nazwane obecne i włączone" "${wyjscie}"
sprawdz_zawiera "…i kontrola ograniczeń też ją przepuściła" \
  "ograniczenia: CHECK" "${wyjscie}"

# Fikstura wraca do stanu prawdziwego — następne testy mają liczyć na
# działającej barierze.
"${PSQL[@]}" -d "${BAZA_ZRODLOWA}" -v ON_ERROR_STOP=1 \
  -c "CREATE OR REPLACE FUNCTION follows_blokada_ma_pierwszenstwo() RETURNS TRIGGER
        LANGUAGE plpgsql AS \$\$
      BEGIN
        IF EXISTS (SELECT 1 FROM blocks
                    WHERE (blocker_id = NEW.follower_id AND blocked_id = NEW.followed_id)
                       OR (blocker_id = NEW.followed_id AND blocked_id = NEW.follower_id))
        THEN RAISE EXCEPTION 'Blokada ma pierwszenstwo przed obserwowaniem'; END IF;
        RETURN NEW;
      END; \$\$" >/dev/null

# =============================================================================
echo
echo "── KONTROLA DODATNIA SOND (pułapka 4 z PULAPKI_TESTOW.md) ──"
# =============================================================================
#
#  Cztery sondy zachowania sprawdzają, że zapis ZOSTAŁ ODRZUCONY. Wszystkie
#  cztery przeszłyby także w bazie, w której nie przechodzi ŻADEN zapis —
#  i byłyby wtedy dowodem na to, że nic nie działa, podanym jako dowód, że
#  wszystko działa. Przed tym stoi `kontrola_dodatnia_zapisu()`.
#
#  Wołamy ją tu WPROST, na dwóch bazach, bo tylko tak da się pokazać, że
#  ona sama cokolwiek mierzy.

pg_dump "${DSN_ZRODLA}" --format=custom --no-owner --file="${KATALOG_KOPII}/do-kontroli.dump"
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PROBNA} WITH (FORCE)" >/dev/null 2>&1
"${PSQL[@]}" -d postgres -c "CREATE DATABASE ${BAZA_PROBNA} OWNER kuking" >/dev/null 2>&1
DSN_PROBNEJ="postgresql://${BAZA_POLACZENIE}/${BAZA_PROBNA}"
pg_restore --dbname="${DSN_PROBNEJ}" --no-owner --no-privileges \
  "${KATALOG_KOPII}/do-kontroli.dump" >/dev/null 2>&1

# Funkcję wołamy w ZAGNIEŻDŻONEJ podpowłoce, bo przy porażce kończy się
# ona `exit 71` (przez `padnij`). Bez tego zagnieżdżenia `exit` wychodziłby
# z całego podstawienia i `printf KOD=` nigdy by się nie wykonał — czyli
# kontrola ujemna nie miałaby czego porównać. Złapane przy pierwszym
# uruchomieniu tego pliku.
wynik="$(
  # shellcheck disable=SC1090
  . "${SKRYPT_PROBY}"
  set +eo pipefail
  KATALOG_ROBOCZY="$(mktemp -d)"
  (kontrola_dodatnia_zapisu "${DSN_PROBNEJ}") 2>&1
  printf 'KOD=%s' "$?"
  rm -rf "${KATALOG_ROBOCZY}"
)"
sprawdz "kontrola dodatnia PRZECHODZI na normalnej bazie" "KOD=0" \
  "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"

# Ta sama baza, ale ustawiona na TYLKO DO ODCZYTU. Tak wygląda baza,
# w której cztery sondy „przeszłyby” wszystkie naraz.
"${PSQL[@]}" -d postgres -c \
  "ALTER DATABASE ${BAZA_PROBNA} SET default_transaction_read_only = true" >/dev/null 2>&1

wynik="$(
  # shellcheck disable=SC1090
  . "${SKRYPT_PROBY}"
  set +eo pipefail
  KATALOG_ROBOCZY="$(mktemp -d)"
  (kontrola_dodatnia_zapisu "${DSN_PROBNEJ}") 2>&1
  printf 'KOD=%s' "$?"
  rm -rf "${KATALOG_ROBOCZY}"
)"
sprawdz "kontrola dodatnia OBLEWA SIĘ, gdy baza nie przyjmuje żadnego zapisu (kod 71)" \
  "KOD=71" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i mówi, że sondy wtedy niczego nie dowodzą" \
  "nie dowodzą niczego o barierach" "${wynik}"

"${PSQL[@]}" -d postgres -c \
  "ALTER DATABASE ${BAZA_PROBNA} RESET default_transaction_read_only" >/dev/null 2>&1

# =============================================================================
echo
echo "── Porównanie liczby wierszy w KAŻDEJ tabeli i migrate:status (#193) ──"
# =============================================================================
#
#  TO JEST NAJWAŻNIEJSZA KONTROLA UJEMNA W TYM PLIKU.
#
#  Krok 6 skryptu liczy wiersze w czterech tabelach WYBRANYCH Z NAZWY.
#  Zrzut, który zgubił piątą, przechodzi go bez jednego ostrzeżenia — bo
#  krok 6 o piątą nie pyta. Krok 6B pyta o wszystkie, a poniższe przypadki
#  sprawdzają, czy naprawdę pyta: kasujemy wiersze w ODTWORZONEJ bazie
#  i żądamy, żeby porównanie OBLAŁO.
#
#  Bez tej pary (dodatniej i ujemnej) krok 6B byłby zielony także wtedy,
#  gdyby nie porównywał niczego — czyli byłby pułapką 5 z
#  `docs/PULAPKI_TESTOW.md`: narzędziem, które melduje sukces, nie
#  zrobiwszy nic.
#
#  Funkcje wołamy WPROST (`source` + podpowłoka), a nie przez pełny przebieg
#  skryptu. Powód jest jeden i ten sam co przy `kontrola_dodatnia_zapisu()`:
#  tylko tak da się wejść MIĘDZY odtworzenie a porównanie i popsuć bazę
#  dokładnie w tym jednym momencie, o który chodzi.

# --- 0. NAJPIERW: czy `main` W OGÓLE WOŁA te dwa kroki ----------------------
#
#  Wszystkie przypadki niżej wołają funkcje WPROST — inaczej nie dałoby się
#  wejść między odtworzenie a porównanie. Cena jest taka, że wycięcie wywołania
#  z `main` nie oblałoby żadnego z nich: funkcje dalej by istniały i dalej by
#  działały, tylko nikt by ich nie wołał. To jest ta sama klasa pomyłki co
#  pułapka 2 — test zielony nad martwym kodem.
#
#  Dlatego jeden przypadek idzie PEŁNYM przebiegiem, z `--zrodlo`, i żąda,
#  żeby oba kroki zameldowały się w wyjściu.
#
#  WŁASNA baza próbna, nie `${BAZA_PROBNA}`: tamtą zostawia z tabelą w środku
#  przypadek „odmawia odtwarzania do bazy, która NIE jest pusta" — i słusznie
#  jej nie kasuje. Ten przebieg dostałby wtedy kod 23 z całkiem innego powodu
#  niż ten, o który pytamy.
BAZA_PELNEGO="${BAZA_PROBNA}pelny"
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_PELNEGO} WITH (FORCE)" >/dev/null 2>&1

wyjscie="$(bash "${SKRYPT_PROBY}" --zrzut "${ZRZUT_DOBRY}" --serwer "${SERWER}" \
  --baza "${BAZA_PELNEGO}" --tabele users,follows --zrodlo "${DSN_ZRODLA}" --scisle 2>&1)"
kod=$?
sprawdz "pełny przebieg z --zrodlo --scisle przechodzi (kod 0)" "0" "${kod}"
# Bez odwrotnych apostrofów w opisie: w łańcuchu w cudzysłowach bash wykonuje
# to, co w nich stoi. Opis „woła `main`" uruchamiał funkcję main.
sprawdz_zawiera "…a main NAPRAWDĘ woła porównanie wszystkich tabel" \
  "co do jednego wiersza" "${wyjscie}"
sprawdz_zawiera "…i NAPRAWDĘ woła migrate:status" \
  "migrate:status na odtworzonej bazie" "${wyjscie}"
sprawdz_zawiera "…a podsumowanie kończy się LICZBAMI, nie ptaszkiem" \
  "tabel porównanych:" "${wyjscie}"
sprawdz_zawiera "…w tym sumą wierszy po obu stronach" "wierszy w źródle:" "${wyjscie}"

# `main` nie ruszy przy `source` — pilnuje tego `BASH_SOURCE[0] == 0`
# na końcu skryptu próby.
# shellcheck disable=SC1090
source "${SKRYPT_PROBY}"

BAZA_POROWNANIA="${BAZA_PROBNA}por"

przygotuj_baze_porownania() {
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_POROWNANIA} WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "CREATE DATABASE ${BAZA_POROWNANIA} OWNER kuking" >/dev/null 2>&1
  pg_restore --dbname "postgresql://${BAZA_POLACZENIE}/${BAZA_POROWNANIA}" \
    --no-owner --no-privileges "${ZRZUT_DOBRY}" >/dev/null 2>&1
}

# Ustawiamy globalne, które czytają obie sprawdzane funkcje.
DSN_PROBNY="postgresql://${BAZA_POLACZENIE}/${BAZA_POROWNANIA}"
DSN_ZRODLA="postgresql://${BAZA_POLACZENIE}/${BAZA_ZRODLOWA}"
KATALOG_ROBOCZY="${KATALOG_KOPII}"
SCISLE=1

# --- 1. Kontrola DODATNIA: wierna kopia przechodzi --------------------------
przygotuj_baze_porownania
wynik="$( (porownaj_wszystkie_tabele) 2>&1; echo "KOD=$?" )"
sprawdz "wierna kopia przechodzi porównanie wszystkich tabel (kod 0)" \
  "KOD=0" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i MÓWI, ile tabel porównał (a nie tylko że zaliczone)" \
  "co do jednego wiersza" "${wynik}"
sprawdz_zawiera "…i podaje sumę wierszy po obu stronach" \
  "wierszy razem:" "${wynik}"

# Liczba porównanych tabel MUSI być liczbą schematu Kukinga. Bez tej asercji
# „porównano 0 tabel" przeszłoby jako sukces — to jest pułapka 2: skan, który
# nie znajduje niczego, uznaje to za zaliczone.
#
# Liczbę czytamy z WYJŚCIA, nie ze zmiennej `TABEL_POROWNANYCH`. Funkcja
# chodzi w podpowłoce (`( … )`), bo tylko tak da się złapać jej `exit`
# z `padnij` — a przypisanie zrobione w podpowłoce nie wraca do rodzica
# i zostawiłoby tu zawsze zero. Zero wyglądałoby przy tym jak prawdziwy
# wynik pomiaru, a nie jak jego brak.
ile_tabel="$(sed -n -E 's/.*porównano ([0-9]+) tabel.*/\1/p' <<<"${wynik}" | head -n 1)"
sprawdz "…na PEŁNYM schemacie, nie na wycinku (≥40 tabel)" "tak" \
  "$(((${ile_tabel:-0} >= 40)) && echo tak || echo "nie (${ile_tabel:-0})")"

# --- 2. KONTROLA UJEMNA: skasowane wiersze mają OBLAĆ -----------------------
przygotuj_baze_porownania
"${PSQL[@]}" -d "${BAZA_POROWNANIA}" -v ON_ERROR_STOP=1 >/dev/null 2>&1 <<'SQL'
DELETE FROM follows;
DELETE FROM users WHERE email = 'fikstura-2@example.invalid';
SQL

wynik="$( (porownaj_wszystkie_tabele) 2>&1; echo "KOD=$?" )"
sprawdz "skasowane wiersze w odtworzonej bazie OBLEWAJĄ porównanie (kod 63)" \
  "KOD=63" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i mówi, KTÓRA tabela się rozjechała" "users:" "${wynik}"
sprawdz_zawiera "…wymieniając obie rozjechane, nie tylko pierwszą" "follows:" "${wynik}"
sprawdz_zawiera "…i podaje obie liczby, żeby dało się to przeczytać" \
  "w źródle" "${wynik}"

# --- 3. KONTROLA UJEMNA: brakująca TABELA ma OBLAĆ --------------------------
#
#  Najcichsza z możliwych strat: liczniki wszystkich pozostałych tabel
#  zgadzają się co do jednego, bo tej jednej po prostu nikt nie liczy.
przygotuj_baze_porownania
"${PSQL[@]}" -d "${BAZA_POROWNANIA}" -c "DROP TABLE follows CASCADE" >/dev/null 2>&1

wynik="$( (porownaj_wszystkie_tabele) 2>&1; echo "KOD=$?" )"
sprawdz "brakująca TABELA oblewa porównanie (kod 63)" \
  "KOD=63" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i nazywa ją po imieniu" "follows" "${wynik}"
sprawdz_zawiera "…tłumacząc, że liczby pozostałych mogły się zgadzać" \
  "mogą się" "${wynik}"

# --- 4. migrate:status ------------------------------------------------------
przygotuj_baze_porownania
wynik="$( (sprawdz_migracje) 2>&1; echo "KOD=$?" )"
sprawdz "odtworzona baza przechodzi migrate:status (kod 0)" \
  "KOD=0" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i podaje LICZBĘ wykonanych migracji" "wykonanych, 0 czekających" "${wynik}"

# KONTROLA UJEMNA: zrzut sprzed migracji. Kasujemy ostatni wpis z tabeli
# `migrations`, więc plik migracji jest w repozytorium, a baza o nim nie wie
# — czyli dokładnie stan „kopia starsza niż kod".
przygotuj_baze_porownania
"${PSQL[@]}" -d "${BAZA_POROWNANIA}" -c \
  "DELETE FROM migrations WHERE id = (SELECT max(id) FROM migrations)" >/dev/null 2>&1

wynik="$( (sprawdz_migracje) 2>&1; echo "KOD=$?" )"
sprawdz "baza sprzed migracji OBLEWA migrate:status (kod 64)" \
  "KOD=64" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…i mówi, ile migracji czeka" "migracji CZEKAJĄCYCH" "${wynik}"

# KONTROLA UJEMNA: zgubiona tabela `migrations`. Dane mogą być komplet,
# a baza i tak nie wie, w jakim jest schemacie.
przygotuj_baze_porownania
"${PSQL[@]}" -d "${BAZA_POROWNANIA}" -c "TRUNCATE migrations" >/dev/null 2>&1

wynik="$( (sprawdz_migracje) 2>&1; echo "KOD=$?" )"
sprawdz "pusta tabela migrations OBLEWA migrate:status (kod 64)" \
  "KOD=64" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…nazywając rzecz po imieniu" "NIE MA ANI JEDNEJ" "${wynik}"

# Dowód, że kontrola ujemna 4 nie przeszła przypadkiem: nazwa migracji
# `create_pending_email_changes_table` zawiera słowo „pending" i przy
# dopasowaniu do całego wiersza (a nie do kolumny stanu) meldowała jedną
# migrację czekającą na bazie, w której wszystkie były wykonane. To jest
# pułapka 1 z docs/PULAPKI_TESTOW.md, złapana na tym kroku 12.09.2026.
przygotuj_baze_porownania

# Najpierw: czy pułapka w ogóle stoi w tej fiksturze. Asercja bez tego
# sprawdzenia byłaby zielona także na bazie, w której żadna migracja nie ma
# tego słowa w nazwie — czyli nie dowodziłaby niczego (pułapka 2).
z_pending="$("${PSQL[@]}" -d "${BAZA_POROWNANIA}" -Atc \
  "SELECT count(*) FROM migrations WHERE migration LIKE '%pending%'")"
sprawdz "fikstura NAPRAWDĘ zawiera migrację ze słowem pending w nazwie" "tak" \
  "$(((z_pending > 0)) && echo tak || echo "nie (${z_pending})")"

# I dopiero teraz: mimo tej nazwy przebieg jest czysty.
wynik="$( (sprawdz_migracje) 2>&1; echo "KOD=$?" )"
sprawdz "…a mimo to migrate:status melduje ZERO czekających (kod 0)" \
  "KOD=0" "$(grep -o 'KOD=[0-9]*' <<<"${wynik}")"
sprawdz_zawiera "…bo liczona jest kolumna stanu, nie cały wiersz" \
  "0 czekających" "${wynik}"

"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${BAZA_POROWNANIA} WITH (FORCE)" >/dev/null 2>&1

# =============================================================================
echo
echo "── Zgoda dokumentu z rzeczywistością ──"
# =============================================================================
#
#  Dokument kopii jest jedynym miejscem, z którego właściciel będzie
#  odtwarzał bazę — a rozjazd między nim a skryptem zauważy w najgorszym
#  możliwym momencie. Dlatego nazwy plików i komend sprawdza test.
DOKUMENT="${KATALOG}/docs/infra/KOPIE_I_ODTWORZENIE.md"

if [[ ! -f "${DOKUMENT}" ]]; then
  sprawdz "dokument kopii istnieje" "tak" "nie"
else
  tresc="$(cat "${DOKUMENT}")"
  sprawdz_zawiera "dokument odsyła do scripts/proba-odtworzenia.sh" \
    "scripts/proba-odtworzenia.sh" "${tresc}"
  sprawdz_zawiera "dokument odsyła do scripts/kopia-lokalna.sh" \
    "scripts/kopia-lokalna.sh" "${tresc}"
  sprawdz_zawiera "dokument mówi wprost, ile kopii jest DZIŚ" \
    "wynosi **zero**" "${tresc}"
fi

# =============================================================================
echo
if ((oblane > 0)); then
  printf '\033[0;31mOblane: %d, zdane: %d\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d testów kopii i próby odtworzenia przechodzi.\033[0m\n' "${zdane}"
printf 'Uwaga: to NIE dowodzi, że kuking.pl ma kopię — nie ma tu ani R2, ani produkcji,\n'
printf 'ani serwera PostgreSQL 18. Liczba kopii produkcyjnej bazy wynosi dziś ZERO;\n'
printf 'zmieni to wyłącznie lista z docs/infra/KOPIE_I_ODTWORZENIE.md §8.\n'
