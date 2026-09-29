#!/usr/bin/env bash
# =============================================================================
#  Ratchet PHPStana: wyczyszczone rodziny błędów poziomu 4 nie mogą wrócić.
#  (issue #1731 — etapowe podnoszenie rygoru bez baseline'u)
# =============================================================================
#
#  Globalny poziom w `phpstan.neon` jest niższy niż 4. Poziom 4 zgłasza jeszcze
#  wiele rodzin błędów (identyfikatorów), więc nie może być twardą bramką w
#  całości. Ten skrypt robi z niego bramkę CZĘŚCIOWĄ: analiza idzie na
#  poziomie 4 (`phpstan-etap4.neon`), a padamy wyłącznie na identyfikatorach
#  z listy WYCZYSZCZONE niżej. Pozostałe identyfikatory są tylko podliczane —
#  bez wyciszania, bez baseline'u.
#
#  Jak dopisać kolejny etap: usuń rodzinę błędów w KODZIE, potem dopisz jej
#  identyfikator do WYCZYSZCZONE. Kontrola ujemna: wprowadź błąd tej rodziny
#  (np. `match` bez `default` na napisie) i sprawdź, że skrypt pada.
#
#  Zmienne: KUKING_PHPSTAN_KONFIG — inny plik konfiguracji (domyślnie
#  phpstan-etap4.neon), np. z ograniczeniem procesów na współdzielonej maszynie.

set -uo pipefail
cd "$(dirname "$0")/.."

WYCZYSZCZONE=(
    match.unhandled
    catch.neverThrown
    nullCoalesce.offset
    nullCoalesce.expr
    nullCoalesce.property
    nullCoalesce.variable
    deadCode.unreachable
    booleanNot.alwaysTrue
)

konfig="${KUKING_PHPSTAN_KONFIG:-phpstan-etap4.neon}"

if [ ! -x vendor/bin/phpstan ]; then
    echo "Brak vendor/bin/phpstan — uruchom: composer install" >&2
    exit 1
fi

log=$(mktemp "${TMPDIR:-/tmp}/kuking-phpstan-etap4.XXXXXX")
trap 'rm -f "$log"' EXIT

# `-v` jest potrzebne, żeby wynik JSON nie był obcinany.
vendor/bin/phpstan analyse -c "$konfig" --no-progress --memory-limit=1G \
    -v --error-format=json >"$log" 2>/dev/null

php -r '
$wyczyszczone = array_slice($argv, 2);
$dane = json_decode((string) file_get_contents($argv[1]), true);
if (! is_array($dane)) {
    fwrite(STDERR, "PHPStan (etap 4) nie zwrócił czytelnego wyniku — uruchom: vendor/bin/phpstan analyse -c phpstan-etap4.neon\n");
    exit(2);
}
// Format klasyczny (`files` → `messages`) albo skrócony (`error_details`).
$pliki = [];
foreach ($dane["files"] ?? [] as $plik => $v) {
    $pliki[$plik] = $v["messages"] ?? [];
}
foreach ($dane["error_details"] ?? [] as $plik => $v) {
    $pliki[$plik] = $v;
}
$zle = [];
$reszta = [];
foreach ($pliki as $plik => $bledy) {
    foreach ($bledy as $blad) {
        $id = $blad["identifier"] ?? "(bez identyfikatora)";
        if (in_array($id, $wyczyszczone, true)) {
            $zle[] = sprintf("  %s:%d [%s] %s", $plik, $blad["line"] ?? 0, $id, $blad["message"]);
        } else {
            $reszta[$id] = ($reszta[$id] ?? 0) + 1;
        }
    }
}
if ($zle !== []) {
    fwrite(STDERR, "Wróciły błędy PHPStana poziomu 4 z rodzin już wyczyszczonych:\n".implode("\n", $zle)."\n");
    exit(1);
}
arsort($reszta);
printf("Wyczyszczone rodziny poziomu 4 (%s): 0 błędów. Reszta poziomu 4, jeszcze nie bramka: %d.\n",
    implode(", ", $wyczyszczone), array_sum($reszta));
' "$log" "${WYCZYSZCZONE[@]}"
