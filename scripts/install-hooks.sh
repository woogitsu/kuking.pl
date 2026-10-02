#!/usr/bin/env bash
# Instaluje pre-push wyłącznie w bieżącym checkoutcie (także git worktree).

set -euo pipefail
cd "$(dirname "$0")/.." || exit 1

if [ "$(git config --bool --get extensions.worktreeConfig || true)" != true ]; then
    echo 'Nie instaluję hooka: włącz extensions.worktreeConfig dla tego repozytorium i spróbuj ponownie.' >&2
    exit 1
fi

# `git rev-parse --git-path hooks` wskazuje wspólne hooki głównego repo także
# w linked worktree. Katalog administracyjny bieżącego drzewa jest odrębny.
hooks_dir="$(git rev-parse --absolute-git-dir)/hooks"
existing_path="$(git config --get core.hooksPath || true)"
if [ -n "$existing_path" ] && [ "$existing_path" != "$hooks_dir" ]; then
    echo "Nie instaluję hooka: core.hooksPath wskazuje już $existing_path." >&2
    exit 1
fi

if [ -L "$hooks_dir" ] || [ -L "$hooks_dir/pre-push" ]; then
    echo "Nie instaluję hooka: $hooks_dir lub pre-push jest dowiązaniem symbolicznym." >&2
    exit 1
fi

if [ -e "$hooks_dir/pre-push" ] && ! grep -q '^# Kuking — kontrola przed wysłaniem\. Instalowana przez scripts/install-hooks\.sh$' "$hooks_dir/pre-push"; then
    echo "Nie instaluję hooka: istniejący pre-push w $hooks_dir należy do innego narzędzia." >&2
    exit 1
fi

mkdir -p "$hooks_dir"

cat > "$hooks_dir/pre-push" <<'HOOK'
#!/usr/bin/env bash
# Kuking — kontrola przed wysłaniem. Instalowana przez scripts/install-hooks.sh
echo "Kuking: sprawdzam zmiany przed wysłaniem…"
exec ./scripts/check.sh --szybko
HOOK

chmod +x "$hooks_dir/pre-push"
git config --worktree core.hooksPath "$hooks_dir"

echo "Zainstalowano hook pre-push dla bieżącego drzewa."
echo "Od teraz 'git push' uruchomi ./scripts/check.sh przed wysłaniem."
