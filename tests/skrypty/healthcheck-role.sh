#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CHECK="$ROOT/docker/healthcheck.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

grep -Fq 'HEALTHCHECK --interval=30s' "$ROOT/Dockerfile"
grep -Fq 'CMD ["/usr/local/bin/kuking-healthcheck"]' "$ROOT/Dockerfile"
grep -Fq 'COPY docker/healthcheck.sh /usr/local/bin/kuking-healthcheck' "$ROOT/Dockerfile"
grep -Fq 'ROLE="${1:-${APP_ROLE:-web}}"' "$ROOT/docker/entrypoint.sh"

printf '#!/usr/bin/env bash\nprintf "called\\n" >> "$PROBE_LOG"\nexit "${PROBE_STATUS:-0}"\n' > "$TMP/php"
chmod +x "$TMP/php"
export PATH="$TMP:$PATH" PROBE_LOG="$TMP/probe.log" APP_ROLE=web

probe() {
  local role="$1" expected="$2" status="$3" result
  printf '%s\0' /usr/bin/tini -g -- /usr/local/bin/kuking-entrypoint "$role" > "$TMP/cmdline"
  : > "$PROBE_LOG"
  export PROBE_STATUS="$status"
  result=0
  HEALTHCHECK_CMDLINE="$TMP/cmdline" bash "$CHECK" || result=$?
  [[ "$result" == "$expected" ]] || { echo "Role $role: expected exit $expected, got $result" >&2; exit 1; }
  if [[ "$role" == web || "$role" == all ]]; then
    [[ "$(wc -l < "$PROBE_LOG")" == 1 ]] || { echo "HTTP probe missing for $role" >&2; exit 1; }
  else
    [[ ! -s "$PROBE_LOG" ]] || { echo "HTTP probe unexpectedly ran for $role" >&2; exit 1; }
  fi
}

probe worker 0 1
probe scheduler 0 1
probe web 1 1
probe all 1 1
probe web 0 0
probe all 0 0

# Bez argumentu entrypoint używa APP_ROLE, zgodnie z jego kontraktem.
printf '%s\0' /usr/bin/tini -g -- /usr/local/bin/kuking-entrypoint > "$TMP/cmdline"
: > "$PROBE_LOG"
APP_ROLE=worker HEALTHCHECK_CMDLINE="$TMP/cmdline" bash "$CHECK"
[[ ! -s "$PROBE_LOG" ]] || { echo 'Worker fallback ran HTTP probe' >&2; exit 1; }

printf '%s\0' /usr/bin/tini -g -- /usr/local/bin/kuking-entrypoint unexpected > "$TMP/cmdline"
if HEALTHCHECK_CMDLINE="$TMP/cmdline" bash "$CHECK"; then
  echo 'Unknown service role was accepted' >&2
  exit 1
fi

echo 'Role-aware Docker healthcheck: OK'
