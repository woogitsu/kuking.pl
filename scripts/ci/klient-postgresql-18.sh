#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — klient PostgreSQL 18 na runnerze CI (#2309)
# =============================================================================
#  Wołają go oba kroki „Klient PostgreSQL 18 (wymagany przez skrypty kopii)”
#  w .github/workflows/ci.yml. Wcześniej każdy krok miał własną kopię tej
#  powłoki w `run: |`. Skąd potrzeba klienta 18: komentarz nad tymi krokami.
#
#  KLUCZ PGDG JEST SPRAWDZANY, ZANIM APT MU ZAUFA (#2309).
#  Do 30.09.2026 klucz z https://www.postgresql.org/media/keys/ACCC4CF8.asc
#  trafiał wprost do /usr/share/postgresql-common/pgdg i od razu stawał się
#  kluczem całego repozytorium apt.postgresql.org. HTTPS chroni tylko drogę.
#  Nie chroni przed podmienionym plikiem u źródła, w proxy ani w lustrze.
#  Każdy poprawnie zbudowany klucz OpenPGP z tego adresu podpisałby wtedy
#  pakiet `postgresql-client-18`, a jego skrypty instalacyjne biegną jako root
#  na runnerze. Teraz klucz idzie najpierw do pliku tymczasowego.
#  `sprawdz_klucz_pgdg` wymaga w nim DOKŁADNIE JEDNEGO klucza głównego bez
#  podkluczy, o odcisku PGDG_ODCISK. Inny plik zatrzymuje krok z kodem 1,
#  zanim cokolwiek trafi do katalogu kluczy APT i przed `apt-get update`.
#
#  ROTACJA KLUCZA (PGDG ogłasza ją na https://www.postgresql.org/download/linux/debian/
#  i na liście pgsql-pkg-debian):
#    1. pobierz nowy klucz i wypisz odcisk:
#         curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /tmp/pgdg.asc
#         gpg --show-keys --with-colons /tmp/pgdg.asc | grep '^fpr'
#    2. porównaj odcisk z NIEZALEŻNYM źródłem, nie z tym samym plikiem:
#       serwer kluczy (https://keyserver.ubuntu.com, szukaj po odcisku) albo
#       pakiet `postgresql-common` z Debiana/Ubuntu, który niesie ten klucz
#       w /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc;
#    3. zmień PGDG_ODCISK niżej i podmień tests/skrypty/dane/pgdg-ACCC4CF8.asc
#       w TYM SAMYM PR-ze; w opisie PR napisz, skąd pochodzi porównanie z pkt 2.
#       Klucz z podkluczem odrzucamy celowo. Gdy PGDG taki wyda, zmiana
#       reguły idzie osobnym PR-em z testem.
#  Test: tests/skrypty/klient-postgresql-18.sh (atrapy curl/sudo/apt-get,
#  prawdziwy gpg), strażnik kroków: tests/Feature/InstalacjeCiSaPrzypieteTest.php.
#
#  WYJŚCIE: 0 = klient 18 jest; 1 = wersja lub odcisk nie przeszły kontroli.
#  Błąd etapu zachowuje kod polecenia; timeout daje 124 lub po SIGKILL 137.
# =============================================================================
set -euo pipefail

# Wszystkie limity dotyczą tylko instalacji klienta CI, nie pracy z bazą.
# Krótsze wartości w testach pozwalają sprawdzić timeout bez czekania minut.
PG18_APT_UPDATE_TIMEOUT="${PG18_APT_UPDATE_TIMEOUT:-90s}"
PG18_APT_INSTALL_TIMEOUT="${PG18_APT_INSTALL_TIMEOUT:-120s}"
PG18_CURL_TIMEOUT="${PG18_CURL_TIMEOUT:-70s}"
PG18_KILL_AFTER="${PG18_KILL_AFTER:-5s}"

uruchom_etap() {
  local opis="$1" limit="$2" start=$SECONDS kod
  shift 2
  echo "Klient PG18: start ${opis} (limit ${limit})."
  if timeout --kill-after="$PG18_KILL_AFTER" "$limit" "$@"; then
    echo "Klient PG18: ${opis} zakończony po $((SECONDS - start)) s."
  else
    kod=$?
    echo "::error title=Klient PG18 - ${opis}::Etap zakończył się kodem ${kod} po $((SECONDS - start)) s (limit ${limit}). Sprawdź dostępność PGDG i dziennik APT; klient nie jest gotowy."
    return "$kod"
  fi
}

# Odcisk klucza „PostgreSQL Debian Repository” (ACCC4CF8). Sprawdzony
# 30.09.2026 w dwóch miejscach: gpg na pobranym pliku i keyserver.ubuntu.com
# (ten sam odcisk, uid „PostgreSQL Debian Repository”). Zmiana tylko według „ROTACJA KLUCZA” wyżej.
PGDG_ODCISK="B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8"
PGDG_URL="https://www.postgresql.org/media/keys/ACCC4CF8.asc"
PGDG_KLUCZ="/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc"

# sprawdz_klucz_pgdg PLIK -> 0, gdy plik to dokładnie klucz PGDG; inaczej 1.
sprawdz_klucz_pgdg() {
  local plik="$1" dom wynik glowne odciski
  dom="$(mktemp -d)"
  if ! wynik="$(GNUPGHOME="$dom" gpg --batch --with-colons --show-keys "$plik" 2>/dev/null)"; then
    rm -rf "$dom"
    echo "::error title=Klucz PGDG nieczytelny::Plik pobrany z ${PGDG_URL} nie jest kluczem OpenPGP. Nie dodaję repozytorium PGDG. Ponów przebieg; jeśli błąd wraca, sprawdź adres klucza na postgresql.org."
    return 1
  fi
  rm -rf "$dom"
  glowne="$(grep -c '^pub:' <<< "$wynik" || true)"
  odciski="$(grep '^fpr:' <<< "$wynik" | cut -d: -f10 | tr '\n' ' ')"
  if [ "$glowne" != 1 ] || [ "$odciski" != "${PGDG_ODCISK} " ]; then
    echo "::error title=Klucz PGDG ma inny odcisk::Klucz pobrany z ${PGDG_URL} ma odciski: ${odciski:-brak} (kluczy głównych: ${glowne}), a zatwierdzony to ${PGDG_ODCISK}. Nie dodaję go do APT. Jeśli PGDG ogłosił nowy klucz, zmień odcisk według „ROTACJA KLUCZA” w scripts/ci/klient-postgresql-18.sh. Jeśli nie ogłosił, ktoś podmienia plik po drodze: nie ponawiaj, zgłoś to."
    return 1
  fi
  echo "Klucz PGDG: odcisk ${PGDG_ODCISK} zgodny."
}

jest_18() {
  case "$1" in
    *" 18."*|*" 18") return 0 ;;
  esac
  return 1
}

if jest_18 "$(pg_restore --version 2>/dev/null || true)"; then
  echo "Klient 18 juz jest - apt nietkniety."
  exit 0
fi
echo "Obecny klient: $(pg_restore --version 2>/dev/null || echo brak)"

tymczasowy="$(mktemp)"
trap 'rm -f "$tymczasowy"' EXIT
uruchom_etap "pobranie klucza PGDG" "$PG18_CURL_TIMEOUT" \
  curl -fsSL --retry 2 --retry-delay 2 --retry-max-time 60 \
    --connect-timeout 10 --max-time 20 -o "$tymczasowy" "$PGDG_URL"
sprawdz_klucz_pgdg "$tymczasowy" || exit 1

sudo install -d /usr/share/postgresql-common/pgdg
sudo install -m 0644 "$tymczasowy" "$PGDG_KLUCZ"
echo "deb [signed-by=${PGDG_KLUCZ}] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
  | sudo tee /etc/apt/sources.list.d/pgdg.list >/dev/null
uruchom_etap "aktualizacja indeksu APT" "$PG18_APT_UPDATE_TIMEOUT" \
  sudo apt-get update -qq -o Acquire::Retries=2 \
    -o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20
uruchom_etap "instalacja klienta PostgreSQL 18" "$PG18_APT_INSTALL_TIMEOUT" \
  sudo apt-get install -y -qq postgresql-client-18 \
    -o Acquire::Retries=2 -o Acquire::http::Timeout=20 \
    -o Acquire::https::Timeout=20 -o DPkg::Lock::Timeout=20

# Bez tego `pg_restore` w kolejnych krokach wskazywałby starszego klienta z PATH.
echo "/usr/lib/postgresql/18/bin" >> "$GITHUB_PATH"
export PATH="/usr/lib/postgresql/18/bin:$PATH"
wersja="$(pg_restore --version)"
echo "Po instalacji: ${wersja}"
# Kontrola dodatnia: krok ma paść TU, a nie dopiero na kopii bazy.
if ! jest_18 "$wersja"; then
  echo "::error::Klient PostgreSQL 18 NIE zostal zainstalowany (${wersja}). Skrypty kopii bazy odmowilyby odczytu ZDROWEGO archiwum, meldujac to jako uszkodzenie."
  exit 1
fi
