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
for skrypt in docker/entrypoint.sh docker/healthcheck.sh docker/klucz-preview.sh docker/kopia/*.sh scripts/*.sh scripts/ci/*.sh tests/skrypty/*.sh; do
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
#  * healthcheck-role — `docker/healthcheck.sh` (HEALTHCHECK obrazu) sprawdza
#    zdrowie zgodnie z rolą procesu kontenera; atrapa `php`, bez bazy;
#  * bramka-migracji — worker i scheduler czekają na migracje web, zamiast
#    startować na starym schemacie (#2044); atrapa `php`, bez bazy;
#  * kopia-bazy — kopia bazy to skrypt powłoki w obrazie bez PHP (D-043), więc
#    żaden test PHPUnit jej nie dotknie; to dziś JEDYNA planowana kopia bazy.
#    Wymaga klienta `pg_restore` 18 (w CI doinstalowuje go krok joba `lint`);
#  * php-ini-slady — obraz FrankenPHP nie ma php.ini-production; bez tej
#    dyrektywy w docker/php.ini ślady wyjątków niosą prefiksy argumentów,
#    także sekretów (#1357);
#  * kontrola-ujemna — przyrząd `scripts/kontrola-ujemna.sh` pilnuje, żeby
#    mutacja, która nie trafiła, nie udawała wykonanej kontroli. Bez własnej
#    kontroli ujemnej byłby tym, co naprawia (PULAPKI_TESTOW §5);
#  * check-postgres — krok „PostgreSQL” z `check.sh` na atrapach `pg_isready`
#    i `pg_ctlcluster`: port ze zmiennej DB_PORT, cudzy klaster nieruszany (#732);
#  * kontrola-sondy-wdrozenia — sondy testu dymnego po wdrożeniu (#1012,
#    #1332) chodzą tylko w GitHub Actions, na produkcji; tu na atrapach curl;
#  * kontrola-czekania-preview — czekanie na gotowe preview (#1389) chodzi
#    tylko w GitHub Actions; tu na atrapie `gh`, bez sieci: sam adres
#    deploymentu to jeszcze nie gotowość.
LISTA=$(cat <<'KONIEC'
tests/skrypty/entrypoint-nadzor.sh|Testy entrypointu oblewają
tests/skrypty/entrypoint-sigterm-caly.sh|Zatrzymanie prawdziwego entrypointu w trakcie pracy oblewa
tests/skrypty/healthcheck-role.sh|Kontrola zdrowia ról kontenera oblewa
tests/skrypty/install-hooks-worktree.sh|Instalator hooków w odrębnym worktree oblewa
tests/skrypty/preflight-bazy.sh|Preflight bazy w entrypoincie oblewa
tests/skrypty/bramka-migracji.sh|Bramka migracji workera i schedulera oblewa
tests/skrypty/php-ini-slady.sh|Ślady wyjątków w docker/php.ini niosą argumenty
tests/skrypty/kopia-bazy.sh|Testy kopii bazy oblewają
tests/skrypty/cache-assetow.sh|Sonda cache oblewa
tests/skrypty/kontrola-ujemna.sh|Przyrząd kontroli ujemnych oblewa
tests/skrypty/check-postgres.sh|Sonda PostgreSQL w check.sh oblewa
tests/skrypty/kontrola-sondy-wdrozenia.sh|Sondy testu dymnego oblewają
tests/skrypty/kontrola-czekania-preview.sh|Czekanie na preview oblewa
tests/skrypty/zakres.sh|Bramka zakres (scripts/ci/zakres.sh) oblewa
tests/skrypty/stan-wdrozenia.sh|Decyzja o stanie wdrożenia (scripts/ci/stan-wdrozenia.sh) oblewa
tests/skrypty/klient-postgresql-18.sh|Instalacja klienta PostgreSQL 18 z kluczem PGDG (scripts/ci/klient-postgresql-18.sh) oblewa
tests/skrypty/preview-bramka.sh|Bramka testu dymnego preview (scripts/ci/preview-bramka.sh) oblewa
KONIEC
)

# Pliki z `tests/skrypty/`, których ten skrypt CELOWO nie uruchamia — każdy z
# powodem. Bez wpisu tu albo w LISTA nowy plik oblewa strażnika niżej (i
# `KontrolePowlokiLokalnieIWCiTest`): test powłoki, którego nikt nie woła,
# nie pilnuje niczego.
#  * atrapa-*.sh — nie są testami, tylko atrapami `curl` wczytywanymi przez
#    `SondaWdrozeniaTest` i `CloudflareCacheGateTest` (PHPUnit);
#  * proba-odtworzenia.sh — prawdziwy `pg_dump`/`pg_restore` na serwerze
#    PostgreSQL, którego job `lint` nie ma; chodzi przez `ProbaOdtworzeniaTest`;
#  * kontrola-cache.sh, kontrola-sondy.sh — nie testy, tylko zapis ręcznych
#    kontroli ujemnych (mutacje kodu przez `scripts/kontrola-ujemna.sh`),
#    puszczanych po testach w runtime floty; pierwszy odmawia poza jej ścieżką;
#  * izolacja-bazy-testowej.sh — jednorazowy dowód do #66, wymaga serwera
#    PostgreSQL (roli `kuking`); dziś nie chodzi ani w check.sh, ani w CI.
POZA_LISTA=$(cat <<'KONIEC'
tests/skrypty/atrapa-cache-gate.sh|atrapa wczytywana przez CloudflareCacheGateTest
tests/skrypty/atrapa-sondy.sh|atrapa wczytywana przez SondaWdrozeniaTest
tests/skrypty/proba-odtworzenia.sh|chodzi przez ProbaOdtworzeniaTest (wymaga serwera PostgreSQL)
tests/skrypty/kontrola-cache.sh|ręczna kontrola ujemna cache (mutacje), tylko w runtime floty
tests/skrypty/kontrola-sondy.sh|ręczna kontrola ujemna sondy wdrożenia (mutacje), tylko w runtime floty
tests/skrypty/izolacja-bazy-testowej.sh|dowód do #66, wymaga serwera PostgreSQL; nie chodzi w check.sh ani w CI
KONIEC
)

# --- Strażnik: żaden plik tests/skrypty/*.sh nie zostaje pominięty -------------
niezaklasyfikowane=""
for plik in tests/skrypty/*.sh; do
    [ -f "$plik" ] || continue
    printf '%s\n%s\n' "$LISTA" "$POZA_LISTA" | cut -d'|' -f1 | grep -qxF "$plik" \
        || niezaklasyfikowane="$niezaklasyfikowane $plik"
done
[ -z "$niezaklasyfikowane" ] || oblane "Test powłoki poza listą w scripts/kontrole-powloki.sh:$niezaklasyfikowane — dopisz go do LISTA (albo do POZA_LISTA z powodem)"

while IFS='|' read -r test opis; do
    [ -n "$test" ] || continue
    [ -f "$test" ] || oblane "Brak pliku $test — lista w scripts/kontrole-powloki.sh jest nieaktualna"
    echo "Uruchamiam: $test"
    if ! bash "$test" </dev/null >/dev/null 2>&1; then
        oblane "$opis — uruchom: bash $test"
    fi
done <<< "$LISTA"

echo "Składnia i testy skryptów powłoki przechodzą"
