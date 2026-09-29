#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — decyzja „czy uruchamiać test dymny preview” (job `preview_bramka`
#  w .github/workflows/preview.yml, #611 etap 7)
# =============================================================================
#  Problem: job `smoke` miał na poziomie joba warunek
#  `vars.KUKING_DEPLOY_ENABLED == 'true'`. Gdy zmienna nie była ustawiona (albo
#  ktoś wpisał `TRUE`, `1`, `yes`), GitHub pomijał job BEZ ŻADNEGO śladu w
#  podsumowaniu: pominięty test dymny wyglądał jak zielony. Ten skrypt zamienia
#  ciche pominięcie w pominięcie Z UZASADNIENIEM: powód trafia do podsumowania
#  joba (GITHUB_STEP_SUMMARY) i do adnotacji, a decyzja do wyjść `uruchom`/`powod`,
#  z których `smoke` czyta, czy ma chodzić.
#
#  NIE ŁĄCZY SIĘ Z RAILWAY ANI Z GITHUBEM — dostaje dane i tylko decyduje;
#  dzięki temu da się go sprawdzić tabelą przypadków (tests/skrypty/preview-bramka.sh).
#
#  WEJŚCIE (zmienne środowiska):
#    ZDARZENIE — `github.event_name`;
#    FLAGA     — `vars.KUKING_DEPLOY_ENABLED` (pusta, gdy zmiennej nie ma);
#    GITHUB_OUTPUT, GITHUB_STEP_SUMMARY — opcjonalne pliki (poza Actions ich brak).
#
#  REGUŁY:
#    - ZDARZENIE puste                      -> kod 2 (nie zgadujemy);
#    - ZDARZENIE inne niż pull_request      -> uruchom=false, powód „nie dotyczy”;
#    - FLAGA dokładnie `true`               -> uruchom=true;
#    - FLAGA pusta albo `false`             -> uruchom=false, notice z powodem;
#    - FLAGA inna (`TRUE`, `1`, ` true`...) -> uruchom=false, ale GŁOŚNO
#      (`::warning`): wartość musi brzmieć dokładnie `true`. Literówka w
#      zmiennej nie może wyglądać jak celowe wyłączenie.
#  Wyjście: 0 = decyzja podjęta (także „pomiń”), 2 = błąd wejścia.
# =============================================================================
set -euo pipefail

zdarzenie="${ZDARZENIE:-}"
flaga="${FLAGA:-}"

if [ -z "$zdarzenie" ]; then
  echo "::error title=Brak zdarzenia::Bramka preview nie dostała nazwy zdarzenia (ZDARZENIE). Sprawdź krok w preview.yml."
  exit 2
fi

uruchom=false
poziom=notice
tytul="Preview pominięty"
if [ "$zdarzenie" != pull_request ]; then
  powod="Zdarzenie '${zdarzenie//[^A-Za-z0-9_]/?}' nie jest pull_requestem — test dymny preview dotyczy tylko PR-ów."
elif [ "$flaga" = true ]; then
  uruchom=true
  tytul="Preview uruchomiony"
  powod="Test dymny preview włączony (KUKING_DEPLOY_ENABLED=true)."
elif [ -z "$flaga" ]; then
  powod="Test dymny preview POMINIĘTY: zmienna repozytorium KUKING_DEPLOY_ENABLED nie jest ustawiona, czyli wdrożenia Railway są wyłączone. To nie jest zielony wynik testu — nic nie sprawdzono. Włączenie: Settings → Secrets and variables → Actions → Variables → KUKING_DEPLOY_ENABLED = true."
elif [ "$flaga" = false ]; then
  powod="Test dymny preview POMINIĘTY: KUKING_DEPLOY_ENABLED=false (wyłączone jawnie). Nic nie sprawdzono."
else
  poziom=warning
  powod="Test dymny preview POMINIĘTY: KUKING_DEPLOY_ENABLED ma wartość inną niż dokładnie 'true' (długość ${#flaga} znaków; wielkość liter i spacje mają znaczenie). Nic nie sprawdzono. Popraw wartość zmiennej na: true."
fi

echo "::${poziom} title=${tytul}::${powod}"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "uruchom=${uruchom}"
    echo "powod=${powod}"
  } >> "$GITHUB_OUTPUT"
fi
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### Test dymny preview"
    echo
    echo "- Decyzja: **$([ "$uruchom" = true ] && echo uruchomiony || echo pominięty)**"
    echo "- Powód: ${powod}"
  } >> "$GITHUB_STEP_SUMMARY"
fi
exit 0
