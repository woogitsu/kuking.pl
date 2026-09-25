#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — składnia i testy skryptów powłoki
# =============================================================================
#  JEDNO MIEJSCE, DWÓCH WOŁAJĄCYCH (audyt A4 5.1). Ten sam zestaw uruchamia
#  `scripts/check.sh` (lokalnie, przed PR-em) i job `lint` w
#  `.github/workflows/ci.yml`. Wcześniej lista żyła tylko w `check.sh`, a CI
#  uruchamiało z niej wyłącznie `kopia-bazy.sh` — regresja w entrypoincie
#  kontenera albo w sondach testu dymnego przechodziła przez zielone CI,
#  jeśli autor nie uruchomił `check.sh`. Dopisujesz test powłoki? Dopisz go
#  TUTAJ, a dojdzie do obu miejsc naraz.
#
#  Entrypoint kontenera to kod, który decyduje o tym, czy serwis w ogóle żyje —
#  a żaden test PHPUnit go nie dotknie. Awaria z 5–6 września 2026 (3,5 godziny
#  niedostępności) siedziała dokładnie tam: w tym, jak skrypt powłoki odróżnia
#  „proces się skończył" od „proces padł".
#
#  Zatrzymuje się na PIERWSZYM oblanym kroku. Ostatnia linia wyjścia mówi
#  wtedy, co uruchomić, żeby zobaczyć szczegóły — `check.sh` pokazuje właśnie
#  ją. Wyjście: 0 = wszystko przechodzi, 1 = coś oblało.
#
#  Użycie:  bash scripts/kontrole-powloki.sh
# =============================================================================

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

oblane() {
    printf '%s\n' "$1"
    exit 1
}

# --- Składnia ------------------------------------------------------------------
bledy_bash=""
sprawdzonych=0
for skrypt in docker/entrypoint.sh docker/klucz-preview.sh docker/kopia/*.sh scripts/*.sh tests/skrypty/*.sh; do
    [ -f "$skrypt" ] || continue
    sprawdzonych=$((sprawdzonych + 1))
    bash -n "$skrypt" 2>/dev/null || bledy_bash="$bledy_bash $skrypt"
done

# Zero plików to nie sukces, tylko pomyłka w ścieżkach (PULAPKI_TESTOW §2).
[ "$sprawdzonych" -gt 0 ] || oblane "Nie znaleziono ani jednego skryptu powłoki do sprawdzenia — zła ścieżka?"
[ -z "$bledy_bash" ] || oblane "Błąd składni w:$bledy_bash"
echo "Składnia: $sprawdzonych skryptów bez błędów"

# --- Testy -------------------------------------------------------------------
# Każdy wiersz: plik testu | co oblało, gdy oblało.
#
#  * kopia-bazy — kopia bazy to skrypt powłoki w obrazie bez PHP (D-043), więc
#    żaden test PHPUnit jej nie dotknie; to dziś JEDYNA planowana kopia bazy.
#    Wymaga klienta `pg_restore` 18 (w CI doinstalowuje go krok joba `lint`);
#  * php-ini-slady — obraz FrankenPHP nie ma php.ini-production; bez tej
#    dyrektywy w docker/php.ini ślady wyjątków niosą prefiksy argumentów,
#    także sekretów (#1357);
#  * kontrola-ujemna — przyrząd `scripts/kontrola-ujemna.sh` pilnuje, żeby
#    mutacja, która nie trafiła, nie udawała wykonanej kontroli. Bez własnej
#    kontroli ujemnej byłby tym, co naprawia (PULAPKI_TESTOW §5);
#  * kontrola-sondy-wdrozenia — sondy testu dymnego po wdrożeniu (#1012,
#    #1332) chodzą tylko w GitHub Actions, na produkcji; tu na atrapach curl.
while IFS='|' read -r test opis; do
    [ -n "$test" ] || continue
    [ -f "$test" ] || oblane "Brak pliku $test — lista w scripts/kontrole-powloki.sh jest nieaktualna"
    echo "Uruchamiam: $test"
    if ! bash "$test" </dev/null >/dev/null 2>&1; then
        oblane "$opis — uruchom: bash $test"
    fi
done <<'LISTA'
tests/skrypty/entrypoint-nadzor.sh|Testy entrypointu oblewają
tests/skrypty/preflight-bazy.sh|Preflight bazy w entrypoincie oblewa
tests/skrypty/php-ini-slady.sh|Ślady wyjątków w docker/php.ini niosą argumenty
tests/skrypty/kopia-bazy.sh|Testy kopii bazy oblewają
tests/skrypty/cache-assetow.sh|Sonda cache oblewa
tests/skrypty/kontrola-ujemna.sh|Przyrząd kontroli ujemnych oblewa
tests/skrypty/kontrola-sondy-wdrozenia.sh|Sondy testu dymnego oblewają
LISTA

echo "Składnia i testy skryptów powłoki przechodzą"
