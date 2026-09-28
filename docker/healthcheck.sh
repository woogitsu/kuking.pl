#!/usr/bin/env bash
set -uo pipefail

# tini (PID 1) zachowuje argument entrypointu, który ma pierwszeństwo przed
# APP_ROLE. Samo APP_ROLE=web nie wystarcza do rozpoznania roli workera.
rola_kontenera() {
  local cmdline="${HEALTHCHECK_CMDLINE:-/proc/1/cmdline}"
  local -a argumenty=()
  local i

  [[ -r "$cmdline" ]] || return 1
  mapfile -d '' -t argumenty < "$cmdline"
  for ((i = 0; i < ${#argumenty[@]}; i++)); do
    if [[ "${argumenty[i]}" == */kuking-entrypoint ]]; then
      printf '%s\n' "${argumenty[i + 1]:-${APP_ROLE:-web}}"
      return 0
    fi
  done
  return 1
}

healthcheck() {
  local rola
  rola="$(rola_kontenera)" || return 1
  case "$rola" in
    web|all)
      php -r 'exit(@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:8080)."/health") ? 0 : 1);'
      ;;
    worker|scheduler)
      # Te role celowo nie uruchamiają HTTP. Docker uznaje zakończony
      # kontener za stopped; stan kolejki wymaga osobnej sondy aplikacyjnej.
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  healthcheck
fi
