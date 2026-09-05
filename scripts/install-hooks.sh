#!/usr/bin/env bash
#
# Instaluje lokalne hooki gita.
#
# Po co: repozytorium jest prywatne, więc GitHub Actions kosztują minuty.
# Ten hook przenosi kontrolę na komputer programisty — za darmo i szybciej,
# bo nie trzeba czekać na kolejkę runnerów.
#
#   ./scripts/install-hooks.sh
#
# Pominięcie hooka w wyjątkowej sytuacji:
#   git push --no-verify

set -euo pipefail
cd "$(dirname "$0")/.." || exit 1

mkdir -p .git/hooks

cat > .git/hooks/pre-push <<'HOOK'
#!/usr/bin/env bash
# Kuking — kontrola przed wysłaniem. Instalowana przez scripts/install-hooks.sh
echo "Kuking: sprawdzam zmiany przed wysłaniem (pominięcie: git push --no-verify)…"
exec ./scripts/check.sh --szybko
HOOK

chmod +x .git/hooks/pre-push

echo "Zainstalowano hook pre-push."
echo "Od teraz 'git push' uruchomi ./scripts/check.sh przed wysłaniem."
