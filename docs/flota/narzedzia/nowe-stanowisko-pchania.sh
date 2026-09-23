# WYMAGA: WSL, repozytorium kanonicznego pod /mnt/c/Users/matma/Documents/Codex/kuking.pl i cache vendor w /home/mateusz/flota/vendor-cache; poza tą maszyną nie zadziała.
set -e
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_PREFIX GIT_COMMON_DIR
KAN=/mnt/c/Users/matma/Documents/Codex/kuking.pl
RT=/home/mateusz/flota/push-run
CACHE=/home/mateusz/flota/vendor-cache
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"

if [ -d "$RT/.git" ] && git -C "$RT" rev-parse --git-dir >/dev/null 2>&1; then
  echo "KLON JUZ JEST"
else
  rm -rf "$RT"
  echo "klonuje z repozytorium kanonicznego..."
  git clone --no-hardlinks "$KAN" "$RT" >/dev/null 2>&1
  echo "sklonowane"
fi

cd "$RT"
for d in vendor node_modules; do
  [ -d "$RT/$d" ] || cp -a "$CACHE/$d" "$RT/$d"
done
if ! grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null; then
  cp .env.example .env
  php artisan key:generate --quiet
fi
# Hook pre-push MUSI byc — bez niego pchanie omijaloby bramke, czego zasady zabraniaja.
bash scripts/install-hooks.sh >/dev/null 2>&1 || true
echo "=== hook pre-push ==="; ls -l .git/hooks/pre-push 2>/dev/null || echo "!!! BRAK HOOKA"
echo "=== git dziala ==="; git rev-parse --abbrev-ref HEAD; git log --oneline -1
echo "=== vendor ==="; ls -d vendor >/dev/null && echo ok
