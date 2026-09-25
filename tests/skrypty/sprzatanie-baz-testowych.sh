#!/usr/bin/env bash
# =============================================================================
#  Dowód, że `scripts/cleanup-test-dbs.sh` NIE KASUJE BAZY ŻYWEJ KOPII (#736)
# =============================================================================
#
#  DLACZEGO AKURAT TEN DOWÓD
#  Sprzątacz baz jest jedynym miejscem w repozytorium, które wykonuje
#  `DROP DATABASE` na bazach nienależących do siebie. Pomyłka „zostawiłem
#  śmieć" kosztuje kilkaset megabajtów. Pomyłka „skasowałem bazę kogoś, kto
#  akurat pracuje" kosztuje cudzy dzień i jest nieodwracalna. To nie są
#  porównywalne szkody, więc kierunek pomyłki musi być zawsze ten sam:
#  NIE WIEM = ZOSTAWIAM.
#
#  Test sprawdza CZTERY sytuacje na prawdziwych bazach i prawdziwym rejestrze.
#  Trzy z nich to kontrole ujemne dla samego sprzątacza: gdyby przestał kasować
#  cokolwiek, przypadek „osierocona znika" by to złapał; gdyby zaczął kasować
#  wszystko, złapią to trzy pozostałe.
#
#  Bazy są zakładane pod nazwami `kuking_test_sprzatacz_dowod_*` i kasowane
#  na końcu — nie dotykają żadnej bazy roboczej. Rejestr idzie do katalogu
#  tymczasowego przez `KUKING_REJESTR_BAZ`, więc dowód nie widzi prawdziwego
#  rejestru i nie może skasować niczego czyjegoś.
#
#  Uruchomienie:  bash tests/skrypty/sprzatanie-baz-testowych.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${KATALOG}" || exit 1

# shellcheck source=scripts/port-bazy.sh
. "${KATALOG}/scripts/port-bazy.sh"

kuking_ustal_dostep_do_bazy "${KATALOG}" || exit 1

# Hasło z `port-bazy.sh`, nie zaszyte `kuking` — patrz komentarz tam. Zaszyte
# przechodziło lokalnie (klaster z `trust`) i oblewało 5 z 6 werdyktów w CI,
# gdzie usługa startuje z `POSTGRES_PASSWORD: secret`.
export PGPASSWORD="${PGPASSWORD:-${KUKING_DB_HASLO}}"
PSQL=(psql -q -tA -U "${KUKING_DB_UZYTKOWNIK}" -h "${KUKING_DB_HOST}" -p "${KUKING_DB_PORT}" -d postgres)

zdane=0
oblane=0

sprawdz() {
  local opis="$1" warunek="$2"
  if [[ "${warunek}" -eq 0 ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n' "${opis}"
    oblane=$((oblane + 1))
  fi
}

istnieje_baza() {
  [ "$("${PSQL[@]}" -c "SELECT count(*) FROM pg_database WHERE datname='$1'" 2>/dev/null)" = "1" ]
}

echo "── Sprzątacz baz testowych nie rusza bazy żywej kopii (#736) ──"

if ! pg_isready -q -h "${KUKING_DB_HOST}" -p "${KUKING_DB_PORT}" 2>/dev/null; then
  printf 'PostgreSQL nie odpowiada na %s — nie ma czego dowodzić.\n' "$(kuking_opis_bazy)" >&2
  exit 1
fi

# `pg_isready` nie loguje się, więc mówi „accepting connections" także przy
# złym haśle. Bez tego kroku dowód nie padał — on się DEGRADOWAŁ: `CREATE
# DATABASE` cicho nie działało, sprzątacz meldował „Brak baz", a werdykty
# 2–6 robiły się czerwone z komunikatem wskazującym na sprzątacza, choć winna
# była niemożność zalogowania. Przyczyna ma stać w pierwszej linii, nie być
# zgadywana z pięciu skutków.
if ! "${PSQL[@]}" -c "SELECT 1" >/dev/null 2>&1; then
  printf '\033[0;31mNie umiem się zalogować do %s jako %s — dowód nie mierzyłby sprzątacza, tylko brak dostępu.\033[0m\n' \
    "$(kuking_opis_bazy)" "${KUKING_DB_UZYTKOWNIK}" >&2
  exit 1
fi

PIASKOWNICA="$(mktemp -d "${TMPDIR:-/tmp}/kuking-sprzatacz-XXXXXX")"
export KUKING_REJESTR_BAZ="${PIASKOWNICA}/rejestr"
mkdir -p "${KUKING_REJESTR_BAZ}"

BAZA_ZYWA="kuking_test_sprzatacz_dowod_zywa"
BAZA_OSIEROCONA="kuking_test_sprzatacz_dowod_osierocona"
BAZA_NIEZNANA="kuking_test_sprzatacz_dowod_nieznana"
BAZA_ZAJETA="kuking_test_sprzatacz_dowod_zajeta"

KATALOG_ZYWY="${PIASKOWNICA}/kopia-zywa"
KATALOG_ZNIKNIETY="${PIASKOWNICA}/kopia-skasowana"
KATALOG_ZAJETY="${PIASKOWNICA}/kopia-zajeta"

posprzataj() {
  for b in "${BAZA_ZYWA}" "${BAZA_OSIEROCONA}" "${BAZA_NIEZNANA}" "${BAZA_ZAJETA}"; do
    "${PSQL[@]}" -c "DROP DATABASE IF EXISTS ${b} WITH (FORCE)" >/dev/null 2>&1
  done
  rm -rf "${PIASKOWNICA}"
}
trap posprzataj EXIT INT TERM

for b in "${BAZA_ZYWA}" "${BAZA_OSIEROCONA}" "${BAZA_NIEZNANA}" "${BAZA_ZAJETA}"; do
  "${PSQL[@]}" -c "DROP DATABASE IF EXISTS ${b} WITH (FORCE)" >/dev/null 2>&1
  "${PSQL[@]}" -c "CREATE DATABASE ${b} OWNER ${KUKING_DB_UZYTKOWNIK}" >/dev/null 2>&1
  # Bez tego sprawdzenia nieudane `CREATE` szło niezauważone, a werdykt
  # „baza X ZOSTAJE" robił się czerwony dla bazy, której nigdy nie było —
  # czyli dowód oskarżał sprzątacza o cudzą winę. Jedyny wyjątek jest odwrotny:
  # werdykt „osierocona ZNIKA" byłby wtedy ZIELONY bez powodu.
  if ! istnieje_baza "${b}"; then
    printf '\033[0;31mNie udało się założyć bazy %s — dowód nie miałby na czym stać.\033[0m\n' "${b}" >&2
    exit 1
  fi
done

mkdir -p "${KATALOG_ZYWY}" "${KATALOG_ZAJETY}"
# Katalog „zniknięty" celowo NIE powstaje — to jest cały dowód osierocenia.

printf '%s\n' "${KATALOG_ZYWY}"      > "${KUKING_REJESTR_BAZ}/${BAZA_ZYWA}"
printf '%s\n' "${KATALOG_ZNIKNIETY}" > "${KUKING_REJESTR_BAZ}/${BAZA_OSIEROCONA}"
printf '%s\n' "${KATALOG_ZAJETY}"    > "${KUKING_REJESTR_BAZ}/${BAZA_ZAJETA}"
# Dla BAZA_NIEZNANA świadomie NIE ma wpisu w rejestrze.

# Symulacja trwającego przebiegu: otwarte połączenie do BAZA_ZAJETA, którego
# rejestr NIE broni (wpis wskazuje katalog, który zaraz skasujemy). Jedynym,
# co tę bazę ratuje, jest bezpiecznik na `pg_stat_activity`.
psql -q -U "${KUKING_DB_UZYTKOWNIK}" -h "${KUKING_DB_HOST}" -p "${KUKING_DB_PORT}" \
  -d "${BAZA_ZAJETA}" -c "SELECT pg_sleep(25)" >/dev/null 2>&1 &
PID_POLACZENIA=$!
rm -rf "${KATALOG_ZAJETY}"

# Czekamy, aż połączenie NAPRAWDĘ stanie w pg_stat_activity. Bez tego dowód
# potrafiłby przejść przypadkiem, na wolnej maszynie mierząc pustkę.
# Warunek jest na „padło co najmniej jedno połączenie", a NIE na „odpowiedź
# jest różna od zera": przy nieudanym `psql` odpowiedź jest PUSTA, więc
# `!= "0"` było prawdziwe i pętla przerywała się od razu, przepuszczając dowód,
# który niczego nie zmierzył.
for _ in $(seq 1 50); do
  liczba_polaczen="$("${PSQL[@]}" -c "SELECT count(*) FROM pg_stat_activity WHERE datname='${BAZA_ZAJETA}'" 2>/dev/null)"
  [[ "${liczba_polaczen}" =~ ^[1-9][0-9]*$ ]] && break
  sleep 0.2
done

if ! [[ "${liczba_polaczen}" =~ ^[1-9][0-9]*$ ]]; then
  printf '\033[0;31mNie udało się otworzyć połączenia kontrolnego — dowód byłby pusty.\033[0m\n' >&2
  kill "${PID_POLACZENIA}" 2>/dev/null
  exit 1
fi

echo
echo "Przebieg sprzątacza:"
WYJSCIE="$(bash "${KATALOG}/scripts/cleanup-test-dbs.sh" 2>&1)"
printf '%s\n' "${WYJSCIE}" | sed 's/^/  │ /'

kill "${PID_POLACZENIA}" 2>/dev/null
wait "${PID_POLACZENIA}" 2>/dev/null

echo
echo "Werdykty:"

# --- 1. KONTROLA DODATNIA: sprzątacz w ogóle sprząta ------------------------
# Bez tego przypadku wszystkie pozostałe byłyby zielone także dla skryptu,
# który nie robi NIC — a taki skrypt jest bezużyteczny, nie bezpieczny.
sprawdz "baza po skasowanej kopii roboczej ZNIKA" \
  "$(istnieje_baza "${BAZA_OSIEROCONA}" && echo 1 || echo 0)"

# --- 2. Baza żywej kopii roboczej zostaje -----------------------------------
sprawdz "baza kopii, która istnieje na dysku, ZOSTAJE" \
  "$(istnieje_baza "${BAZA_ZYWA}" && echo 0 || echo 1)"

# --- 3. Baza bez wpisu w rejestrze zostaje ----------------------------------
# „Nie wiem, czyja to baza" musi znaczyć „zostawiam". Tu wpada każda baza
# sprzed tej zmiany i każda założona ręcznie (np. kuking_test_a11y).
sprawdz "baza bez wpisu w rejestrze ZOSTAJE (nie wiem = zostawiam)" \
  "$(istnieje_baza "${BAZA_NIEZNANA}" && echo 0 || echo 1)"

# --- 4. Baza z otwartym połączeniem zostaje, choć rejestr mówi „osierocona" --
# To jest najważniejszy przypadek: rejestr może kłamać (ktoś przeniósł katalog,
# ktoś pracuje z kontenera o innej ścieżce), a połączenie nie kłamie nigdy.
sprawdz "baza z OTWARTYM POŁĄCZENIEM zostaje, mimo wpisu o zniknięciu katalogu" \
  "$(istnieje_baza "${BAZA_ZAJETA}" && echo 0 || echo 1)"

# --- 4b. …i zostaje ŚWIADOMIE, a nie przez przypadek -------------------------
# To nie jest ozdobnik do przypadku wyżej, tylko jego warunek sensowności.
# PostgreSQL i tak odmawia `DROP DATABASE`, gdy ktoś jest podłączony — więc
# sama obecność bazy po przebiegu byłaby zielona TAKŻE dla sprzątacza, który
# w ogóle nie patrzy na `pg_stat_activity` i tylko dostaje po rękach od bazy.
# Wykryła to kontrola ujemna: po wycięciu bezpiecznika przypadek 4 dalej
# przechodził. Dowodem świadomości jest zdanie w raporcie.
sprawdz "raport nazywa ją trwającym przebiegiem, zamiast próbować ją skasować" \
  "$(grep -q "ktoś jest podłączony" <<< "${WYJSCIE}" && echo 0 || echo 1)"

# --- 5. Raport nazywa powód pozostawienia -----------------------------------
# Skrypt, który zostawia bazę bez słowa, jest nie do odróżnienia od skryptu,
# który jej nie zauważył.
sprawdz "raport mówi, DLACZEGO baza bez wpisu została" \
  "$(grep -q "brak wpisu w rejestrze" <<< "${WYJSCIE}" && echo 0 || echo 1)"

echo
if [[ "${oblane}" -eq 0 ]]; then
  printf '\033[0;32mWszystkie %d sprawdzenia sprzątacza baz przechodzą.\033[0m\n' "${zdane}"
  exit 0
fi
printf '\033[0;31mProblemów: %d z %d.\033[0m\n' "${oblane}" "$((zdane + oblane))"
exit 1
