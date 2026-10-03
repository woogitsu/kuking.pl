#!/usr/bin/env bash
# Testuje instalator wyłącznie w tymczasowym repozytorium i jego worktree.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"

# Pre-push przekazuje GIT_DIR (a czasem także GIT_WORK_TREE i parametry
# konfiguracji) do uruchamianych poleceń. `git -C` tego nie odcina: `git init
# --bare` potrafi wtedy przestawić core.bare w repozytorium wywołującym.
# Git sam podaje kompletną listę zmiennych lokalnych dla repozytorium.
mapfile -t lokalne_git < <(git rev-parse --local-env-vars)
for git_zmienna in "${lokalne_git[@]}"; do
    unset "$git_zmienna"
done

# Test nie może zmienić nawet konfiguracji lub hooka drzewa, z którego go
# wywołano. Mierzymy bajty, czas modyfikacji i stan Git przed oraz po fixture.
git_dir_wolajacego="$(git -C "$root" rev-parse --absolute-git-dir)"
git_common_wolajacego="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
pliki_wolajacego=(
    "$git_common_wolajacego/config"
    "$git_common_wolajacego/hooks/pre-push"
    "$git_dir_wolajacego/config.worktree"
    "$git_dir_wolajacego/hooks/pre-push"
    "$root/tests/skrypty/install-hooks-worktree.sh"
)
odcisk_plikow() {
    local plik
    for plik in "$@"; do
        if [ -e "$plik" ]; then
            printf '%s|%s|%s\n' "$plik" "$(sha256sum "$plik" | cut -d' ' -f1)" "$(stat -c '%y' "$plik")"
        else
            printf '%s|brak\n' "$plik"
        fi
    done
}
przed_wolajacym="$(odcisk_plikow "${pliki_wolajacego[@]}")"
stan_przed="$(git -C "$root" status --porcelain --untracked-files=all)"

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

if [ "${KUKING_HOOKS_WNETRZE_2871:-}" != 1 ]; then
    # Drugi przebieg wywołuje TEN SAM test tak, jak robi to pre-push: z
    # odziedziczonym GIT_DIR i GIT_CONFIG_PARAMETERS linked worktree. Własny
    # tymczasowy caller daje dowód bez dotykania konfiguracji prawdziwego repo.
    git init -q "$tmp/caller"
    git -C "$tmp/caller" config user.name Test
    git -C "$tmp/caller" config user.email test@example.test
    printf 'caller\n' > "$tmp/caller/README"
    git -C "$tmp/caller" add README
    git -C "$tmp/caller" commit -qm caller
    git -C "$tmp/caller" config extensions.worktreeConfig true
    git -C "$tmp/caller" worktree add -qb caller "$tmp/caller-linked"
    caller_dir="$(git -C "$tmp/caller-linked" rev-parse --absolute-git-dir)"
    git -C "$tmp/caller-linked" config --worktree test.canary zostaje
    mkdir -p "$caller_dir/hooks"
    printf 'obcy hook\n' > "$caller_dir/hooks/pre-push"
    caller_pliki=("$tmp/caller/.git/config" "$caller_dir/config.worktree" "$caller_dir/hooks/pre-push" "$tmp/caller-linked/README")
    caller_przed="$(odcisk_plikow "${caller_pliki[@]}")"
    caller_stan_przed="$(git -C "$tmp/caller-linked" status --porcelain --untracked-files=all)"

    wynik_wewnetrzny=0
    (GIT_DIR="$caller_dir" GIT_WORK_TREE="$tmp/caller-linked" GIT_CONFIG_PARAMETERS="'test.fixture=true'" \
        KUKING_HOOKS_WNETRZE_2871=1 bash "$root/tests/skrypty/install-hooks-worktree.sh") \
        > "$tmp/inherited.log" 2>&1 || wynik_wewnetrzny=$?
    if [ "$(odcisk_plikow "${caller_pliki[@]}")" != "$caller_przed" ] \
        || ! caller_stan_po="$(git -C "$tmp/caller-linked" status --porcelain --untracked-files=all 2>/dev/null)" \
        || [ "$caller_stan_po" != "$caller_stan_przed" ]; then
        echo 'INSTALL_HOOKS_2871_WOLAJACY_ZMIENIONY: fixture zmienił konfigurację, hook lub stan wywołującego worktree.' >&2
        exit 1
    fi
    if [ "$wynik_wewnetrzny" -ne 0 ] || ! grep -q 'INSTALL_HOOKS_WORKTREE_PASS' "$tmp/inherited.log"; then
        echo 'Fixture z odziedziczonym GIT_DIR nie przeszedł:' >&2
        tail -n 20 "$tmp/inherited.log" >&2
        exit 1
    fi
fi

if [ "$(odcisk_plikow "${pliki_wolajacego[@]}")" != "$przed_wolajacym" ] \
    || ! stan_po="$(git -C "$root" status --porcelain --untracked-files=all 2>/dev/null)" \
    || [ "$stan_po" != "$stan_przed" ]; then
    echo 'INSTALL_HOOKS_2871_WOLAJACY_ZMIENIONY: test zmienił własne repozytorium.' >&2
    exit 1
fi
echo 'INSTALL_HOOKS_WORKTREE_PASS'
