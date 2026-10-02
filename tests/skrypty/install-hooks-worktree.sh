#!/usr/bin/env bash
# Testuje instalator wyłącznie w tymczasowym repozytorium i jego worktree.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d -t kuking-hooks.XXXXXX)"
tmp_parent="$(cd "$(dirname "$tmp")" && pwd -P)"
case "$tmp" in
    "$tmp_parent"/kuking-hooks.*) ;;
    *) echo 'Nieznany katalog tymczasowy, odmawiam sprzątania' >&2; exit 1 ;;
esac
trap 'rm -rf -- "$tmp"' EXIT

git init -q "$tmp/repo"
git -C "$tmp/repo" config user.name Test
git -C "$tmp/repo" config user.email test@example.test
printf 'start\n' > "$tmp/repo/README"
git -C "$tmp/repo" add README
git -C "$tmp/repo" commit -qm start
git -C "$tmp/repo" worktree add -qb test "$tmp/linked"
mkdir -p "$tmp/linked/scripts" "$tmp/repo/.git/hooks"
cp "$root/scripts/install-hooks.sh" "$tmp/linked/scripts/install-hooks.sh"
printf '#!/usr/bin/env bash\nprintf "CHECK:%s\\n" "$*" > "%s"\n' '%s' "$tmp/sentinel" > "$tmp/linked/scripts/check.sh"
chmod +x "$tmp/linked/scripts/check.sh"
printf 'foreign hook\n' > "$tmp/repo/.git/hooks/pre-push"
before="$(sha256sum "$tmp/repo/.git/hooks/pre-push" | cut -d' ' -f1)"
before_mtime="$(stat -c '%y' "$tmp/repo/.git/hooks/pre-push")"

# Brak rozszerzenia ma odmówić bez zapisu do wspólnego katalogu.
if (cd "$tmp/linked" && bash scripts/install-hooks.sh) > "$tmp/noextension.log" 2>&1; then
    echo 'BRAK_ODMOWY: brak worktreeConfig' >&2; exit 1
fi
grep -q 'extensions.worktreeConfig' "$tmp/noextension.log"
test -z "$(git -C "$tmp/linked" config --get core.hooksPath || true)"

git -C "$tmp/repo" config extensions.worktreeConfig true
# Fizyczna mutacja całego instalatora: przywrócenie błędnego celu `.git/hooks`
# ma oblać w linked worktree dokładnie przez `.git` będące plikiem.
sed 's|^hooks_dir="$(git rev-parse --absolute-git-dir)/hooks"$|hooks_dir=".git/hooks"|' \
    "$root/scripts/install-hooks.sh" > "$tmp/linked/scripts/install-hooks-mutant.sh"
grep -qx 'hooks_dir=".git/hooks"' "$tmp/linked/scripts/install-hooks-mutant.sh"
if (cd "$tmp/linked" && bash scripts/install-hooks-mutant.sh) > "$tmp/mutant.log" 2>&1; then
    echo 'BRAK_UJEMNEJ: stary cel .git/hooks przeszedł' >&2; exit 1
fi
grep -Eq 'Not a directory|Nie jest katalogiem' "$tmp/mutant.log"
echo 'INSTALL_HOOKS_WORKTREE_MUTANT_FAIL_OWN_MARKER'

git init -q --bare "$tmp/remote.git"
git -C "$tmp/linked" remote add origin "$tmp/remote.git"
(cd "$tmp/linked" && bash scripts/install-hooks.sh)
local_hooks="$(git -C "$tmp/linked" rev-parse --absolute-git-dir)/hooks"
test "$(git -C "$tmp/linked" config --worktree --get core.hooksPath)" = "$local_hooks"
test -x "$local_hooks/pre-push"
test "$(sha256sum "$tmp/repo/.git/hooks/pre-push" | cut -d' ' -f1)" = "$before"
test "$(stat -c '%y' "$tmp/repo/.git/hooks/pre-push")" = "$before_mtime"
test -z "$(git -C "$tmp/repo" config --worktree --get core.hooksPath || true)"
(cd "$tmp/linked" && git push -q -u origin test)
grep -q 'CHECK:--szybko' "$tmp/sentinel"
(cd "$tmp/linked" && printf 'nastepny\n' >> README && git add README && git commit -qm nastepny)
remote_before="$(git -C "$tmp/remote.git" rev-parse refs/heads/test)"
printf '#!/usr/bin/env bash\necho CHECK_FAIL_2632 >&2\nexit 1\n' > "$tmp/linked/scripts/check.sh"
if (cd "$tmp/linked" && git push origin test) > "$tmp/rejected.log" 2>&1; then
    echo 'BRAK_ODMOWY: czerwony check.sh przepuścił push' >&2; exit 1
fi
grep -q 'CHECK_FAIL_2632' "$tmp/rejected.log"
test "$(git -C "$tmp/remote.git" rev-parse refs/heads/test)" = "$remote_before"
(cd "$tmp/linked" && bash scripts/install-hooks.sh) > /dev/null
test "$(git -C "$tmp/linked" config --worktree --get core.hooksPath)" = "$local_hooks"
test "$(sha256sum "$tmp/repo/.git/hooks/pre-push" | cut -d' ' -f1)" = "$before"
test "$(stat -c '%y' "$tmp/repo/.git/hooks/pre-push")" = "$before_mtime"

# Obcy hook w kolejnym worktree ma pozostać nietknięty.
git -C "$tmp/repo" worktree add -qb other "$tmp/other"
mkdir -p "$tmp/other/scripts"
cp "$root/scripts/install-hooks.sh" "$tmp/other/scripts/install-hooks.sh"
other_hooks="$(git -C "$tmp/other" rev-parse --absolute-git-dir)/hooks"
mkdir -p "$other_hooks"
printf 'obcy\n' > "$other_hooks/pre-push"
if (cd "$tmp/other" && bash scripts/install-hooks.sh) > "$tmp/foreign.log" 2>&1; then
    echo 'BRAK_ODMOWY: obcy pre-push' >&2; exit 1
fi
grep -q 'należy do innego narzędzia' "$tmp/foreign.log"
test "$(cat "$other_hooks/pre-push")" = obcy
test -z "$(git -C "$tmp/other" config --worktree --get core.hooksPath || true)"

# Cudzy core.hooksPath nie może być zamieniony nawet bez pre-push.
git -C "$tmp/other" config --worktree core.hooksPath "$tmp/foreign-hooks"
custom_before="$(git -C "$tmp/other" config --worktree --get core.hooksPath)"
if (cd "$tmp/other" && bash scripts/install-hooks.sh) > "$tmp/custom.log" 2>&1; then
    echo 'BRAK_ODMOWY: obca konfiguracja hooksPath' >&2; exit 1
fi
grep -q 'core.hooksPath wskazuje już' "$tmp/custom.log"
test "$(git -C "$tmp/other" config --worktree --get core.hooksPath)" = "$custom_before"
echo 'INSTALL_HOOKS_WORKTREE_PASS'
