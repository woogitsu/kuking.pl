#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — test instalacji klienta PostgreSQL 18 w CI
#  (scripts/ci/klient-postgresql-18.sh, #2309)
# =============================================================================
#  Skrypt dodaje repozytorium PGDG do APT tylko wtedy, gdy pobrany klucz ma
#  zatwierdzony odcisk. Test biegnie bez sieci i bez roota: `curl`, `sudo`,
#  `apt-get`, `lsb_release` i `pg_restore` to atrapy w PATH, a `gpg` jest
#  prawdziwy, bo to on czyta odcisk.
#
#  Cztery części:
#   1. PRAWDZIWY klucz PGDG (tests/skrypty/dane/pgdg-ACCC4CF8.asc): klucz
#      trafia do APT, potem `apt-get update` i instalacja, kod 0;
#   2. INNY klucz, klucz PGDG sklejony z obcym, plik, który nie jest kluczem:
#      kod 1, a dziennik atrap NIE MA ani instalacji klucza, ani `apt-get`.
#      To jest kontrola ujemna z #2309: zmieniony klucz zatrzymuje job
#      przed `apt-get update`;
#   3. klient 18 już jest: apt nietknięty, curl też;
#   4. KONTROLA UJEMNA skryptu: kopia bez sprawdzenia odcisku (albo z odciskiem
#      obcego klucza) MUSI obleć część 2 albo 1. Mutacja, która nie trafiła
#      albo nie zmieniła pliku, kończy test błędem (PULAPKI_TESTOW §5).
#
#  Użycie:  bash tests/skrypty/klient-postgresql-18.sh
#  Wyjście: 0 = wszystko przechodzi, 1 = coś oblało.
# =============================================================================

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

SKRYPT="scripts/ci/klient-postgresql-18.sh"
KLUCZ_PGDG="tests/skrypty/dane/pgdg-ACCC4CF8.asc"
KLUCZ_OBCY="tests/skrypty/dane/klucz-obcy-testowy.asc"
for plik in "$SKRYPT" "$KLUCZ_PGDG" "$KLUCZ_OBCY"; do
    [ -f "$plik" ] || { echo "Brak ${plik}"; exit 1; }
done
command -v gpg >/dev/null || { echo "Brak gpg: ten test czyta odcisk prawdziwym gpg. Zainstaluj gnupg (apt install gnupg)."; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
ATRAPY="${TMP}/atrapy"
mkdir -p "$ATRAPY"

# --- atrapy ------------------------------------------------------------------
# Każda atrapa dopisuje swoje wywołanie do ${DZIENNIK}. `curl` kopiuje plik
# z ${ATRAPA_KLUCZ} pod adres z `-o`. `pg_restore` mówi 16, dopóki dziennik
# nie ma instalacji `postgresql-client-18` (albo zawsze 18, gdy ATRAPA_PG=18).
cat > "${ATRAPY}/curl" <<'KONIEC'
#!/usr/bin/env bash
echo "curl $*" >> "$DZIENNIK"
[ "${ATRAPA_CURL:-}" = wolno ] && sleep 3
[ "${ATRAPA_CURL:-}" = blad ] && exit 7
cel=""
while [ $# -gt 0 ]; do
    if [ "$1" = -o ]; then cel="$2"; shift; fi
    shift
done
[ -n "$cel" ] || exit 2
cp "$ATRAPA_KLUCZ" "$cel"
KONIEC
cat > "${ATRAPY}/sudo" <<'KONIEC'
#!/usr/bin/env bash
echo "sudo $*" >> "$DZIENNIK"
[ "$1" = apt-get ] && [ "${ATRAPA_APT:-}" = "${2}-wolno" ] && sleep 3
[ "$1" = apt-get ] && [ "${ATRAPA_APT:-}" = "${2}-blad" ] && exit 100
[ "$1" = tee ] && cat >/dev/null
exit 0
KONIEC
cat > "${ATRAPY}/lsb_release" <<'KONIEC'
#!/usr/bin/env bash
echo noble
KONIEC
cat > "${ATRAPY}/pg_restore" <<'KONIEC'
#!/usr/bin/env bash
if [ "${ATRAPA_PG:-}" = 18 ] || grep -q 'apt-get install -y -qq postgresql-client-18' "$DZIENNIK" 2>/dev/null; then
    echo "pg_restore (PostgreSQL) 18.6"
else
    echo "pg_restore (PostgreSQL) 16.10"
fi
KONIEC
chmod +x "${ATRAPY}"/*

# uruchom SKRYPT PLIK_KLUCZA [PG] -> kod wyjścia; wyjście w ${TMP}/wyj, dziennik w ${TMP}/dziennik
uruchom() {
    local skrypt="$1" klucz="$2" pg="${3:-}" curl_mode="${4:-}" apt_mode="${5:-}"
    : > "${TMP}/dziennik"
    : > "${TMP}/github_path"
    PATH="${ATRAPY}:${PATH}" DZIENNIK="${TMP}/dziennik" ATRAPA_KLUCZ="$klucz" ATRAPA_PG="$pg" \
        ATRAPA_CURL="$curl_mode" ATRAPA_APT="$apt_mode" PG18_CURL_TIMEOUT=1s \
        PG18_APT_UPDATE_TIMEOUT=1s PG18_APT_INSTALL_TIMEOUT=1s \
        GITHUB_PATH="${TMP}/github_path" bash "$skrypt" > "${TMP}/wyj" 2>&1
    echo $?
}

cat "$KLUCZ_PGDG" "$KLUCZ_OBCY" > "${TMP}/sklejony.asc"
printf '<html>Service Unavailable</html>\n' > "${TMP}/nie-klucz.asc"

# sprawdz_przypadki SKRYPT -> na stdout liczba oblanych przypadków
sprawdz_przypadki() {
    local skrypt="$1" zle=0 kod klucz opis
    # 1. prawdziwy klucz
    kod="$(uruchom "$skrypt" "$KLUCZ_PGDG")"
    if [ "$kod" != 0 ] \
        || ! grep -q "sudo install -m 0644 .* /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc" "${TMP}/dziennik" \
        || ! grep -q 'curl .*--connect-timeout 10 --max-time 20' "${TMP}/dziennik" \
        || ! grep -q 'sudo apt-get update' "${TMP}/dziennik" \
        || ! grep -q 'sudo apt-get update .*Acquire::Retries=2' "${TMP}/dziennik" \
        || ! grep -q 'sudo apt-get install -y -qq postgresql-client-18' "${TMP}/dziennik" \
        || ! grep -q 'sudo apt-get install .*DPkg::Lock::Timeout=20' "${TMP}/dziennik" \
        || ! grep -qx '/usr/lib/postgresql/18/bin' "${TMP}/github_path"; then
        zle=$((zle + 1)); echo "  OBLANE: prawdziwy klucz PGDG nie doprowadził do instalacji (kod ${kod})" >&2
    fi
    # 2. każdy inny plik: kod 1 i nic w APT
    for klucz in "${KLUCZ_OBCY}|obcy klucz" "${TMP}/sklejony.asc|klucz PGDG sklejony z obcym" "${TMP}/nie-klucz.asc|plik, który nie jest kluczem"; do
        opis="${klucz#*|}"; klucz="${klucz%%|*}"
        kod="$(uruchom "$skrypt" "$klucz")"
        if [ "$kod" != 1 ] || grep -q 'apt-get' "${TMP}/dziennik" || grep -q 'apt.postgresql.org.asc' "${TMP}/dziennik"; then
            zle=$((zle + 1)); echo "  OBLANE: ${opis} przeszedł do APT albo nie dał kodu 1 (kod ${kod})" >&2
        fi
    done
    # 3. klient 18 już jest
    kod="$(uruchom "$skrypt" "$KLUCZ_OBCY" 18)"
    if [ "$kod" != 0 ] || [ -s "${TMP}/dziennik" ]; then
        zle=$((zle + 1)); echo "  OBLANE: obecny klient 18, a skrypt ruszył sieć albo apt (kod ${kod})" >&2
    fi
    echo "$zle"
}

# --- 1–3. Przypadki -------------------------------------------------------------
zle="$(sprawdz_przypadki "$SKRYPT")"
[ "$zle" = 0 ] || { echo "Klient PostgreSQL 18: ${zle} oblanych przypadków"; exit 1; }
echo "Przypadki: klucz PGDG przechodzi, inny plik zatrzymuje krok przed apt-get"

uruchom "$SKRYPT" "$KLUCZ_OBCY" >/dev/null
grep -q '^::error title=Klucz PGDG ma inny odcisk::' "${TMP}/wyj" || { echo "Obcy klucz bez polskiego tytułu błędu"; exit 1; }
grep -q 'ROTACJA KLUCZA' "${TMP}/wyj" || { echo "Błąd klucza nie mówi, co zrobić"; exit 1; }
uruchom "$SKRYPT" "${TMP}/nie-klucz.asc" >/dev/null
grep -q '^::error title=Klucz PGDG nieczytelny::' "${TMP}/wyj" || { echo "Plik bez klucza bez polskiego tytułu błędu"; exit 1; }
echo "Komunikaty: po polsku, z instrukcją"

# Błąd sieci lub zawieszone APT kończy etap z nazwą i czasem, bez pozornego
# sukcesu i bez uruchomienia następnej fazy. Testy nie dotykają systemowego APT.
for przypadek in 'curl|blad|7|pobranie klucza PGDG' \
                  'curl|wolno|124|pobranie klucza PGDG' \
                  'apt|update-blad|100|aktualizacja indeksu APT' \
                  'apt|update-wolno|124|aktualizacja indeksu APT' \
                  'apt|install-blad|100|instalacja klienta PostgreSQL 18' \
                  'apt|install-wolno|124|instalacja klienta PostgreSQL 18'; do
    IFS='|' read -r rodzaj tryb oczekiwany etap <<< "$przypadek"
    if [ "$rodzaj" = curl ]; then
        kod="$(uruchom "$SKRYPT" "$KLUCZ_PGDG" '' "$tryb")"
    else
        kod="$(uruchom "$SKRYPT" "$KLUCZ_PGDG" '' '' "$tryb")"
    fi
    [ "$kod" = "$oczekiwany" ] || { echo "Błędny kod dla ${tryb}: ${kod}"; exit 1; }
    grep -q "::error title=Klient PG18 - ${etap}::" "${TMP}/wyj" \
        || { echo "Brak nazwanego błędu etapu ${etap}"; exit 1; }
    grep -q 'po [0-9][0-9]* s' "${TMP}/wyj" \
        || { echo "Brak czasu etapu ${etap}"; exit 1; }
    if [ "$rodzaj" = curl ]; then
        ! grep -q '^sudo apt-get' "${TMP}/dziennik" || exit 1
    elif [ "${tryb#update}" != "$tryb" ]; then
        ! grep -q '^sudo apt-get install' "${TMP}/dziennik" || exit 1
    fi
done
echo "Timeout i błąd sieci/APT: diagnostyka etapu i zatrzymanie dalszych prac"

# Fizyczna kontrola ujemna: bez timeoutu powolny APT przechodzi, więc
# powyższy przypadek musiałby oblać oczekiwanie kodu 124.
tresc="$(cat "$SKRYPT"; echo x)"; tresc="${tresc%x}"
stary='if timeout "$limit" "$@"; then'
[[ "$tresc" == *"$stary"* ]] || { echo "MUTACJA-NIE-TRAFILA (timeout APT)"; exit 1; }
printf '%s' "${tresc/"$stary"/'if "$@"; then'}" > "${TMP}/bez-timeout.sh"
cmp -s "$SKRYPT" "${TMP}/bez-timeout.sh" && { echo "MUTACJA-NO-OP (timeout APT)"; exit 1; }
kod="$(uruchom "${TMP}/bez-timeout.sh" "$KLUCZ_PGDG" '' '' update-wolno)"
[ "$kod" != 124 ] || { echo "Kontrola ujemna timeoutu nie zapaliła się"; exit 1; }
echo "Kontrola ujemna: brak timeoutu przepuszcza powolny APT"

# --- 4. Kontrola ujemna ----------------------------------------------------------
mutuj() {
    local opis="$1" stary="$2" nowy="$3" tresc kopia="${TMP}/mutant.sh" zle
    tresc="$(cat "$SKRYPT"; echo x)"
    tresc="${tresc%x}"
    case "$tresc" in
        *"$stary"*) ;;
        *) echo "MUTACJA-NIE-TRAFILA (${opis}): brak fragmentu w ${SKRYPT}"; return 1 ;;
    esac
    printf '%s' "${tresc/"$stary"/"$nowy"}" > "$kopia"
    if cmp -s "$SKRYPT" "$kopia"; then echo "MUTACJA-NO-OP (${opis})"; return 1; fi
    zle="$(sprawdz_przypadki "$kopia" 2>/dev/null)"
    if [ "$zle" = 0 ]; then
        echo "KONTROLA-UJEMNA-NIE-ZAPALILA (${opis}): przypadki przeszły na zepsutym skrypcie"
        return 1
    fi
    echo "  ${opis}: mutacja zapaliła przypadki (${zle} oblanych)"
}

mutuj "klucz bez sprawdzenia odcisku (stan sprzed #2309)" 'sprawdz_klucz_pgdg "$tymczasowy" || exit 1' 'true' || exit 1
mutuj "odcisk obcego klucza zatwierdzony" 'PGDG_ODCISK="B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8"' 'PGDG_ODCISK="50491351D381262F7FA3B87C82A3F66EBBEFE549"' || exit 1
mutuj "dodatkowy klucz w pliku przepuszczony" 'if [ "$glowne" != 1 ] || [ "$odciski" != "${PGDG_ODCISK} " ]; then' 'if ! grep -q "${PGDG_ODCISK}" <<< "$odciski"; then' || exit 1
mutuj "nieczytelny plik przepuszczony" '    return 1
  fi
  rm -rf "$dom"' '    return 0
  fi
  rm -rf "$dom"' || exit 1

echo "Kontrola ujemna: każda reguła klucza pilnowana"
echo "Klient PostgreSQL 18: OK"
