#!/usr/bin/env bash
set -euo pipefail
ROOT=/mnt/c/Users/matma/Documents/kuking-flota/gpt-harmonogram
TMP=$(mktemp -d /home/mateusz/flota/gpt-harmonogram-caddy.XXXXXX)
CID=''
trap '[[ -z "$CID" ]] || docker rm -f "$CID" >/dev/null; rm -rf "$TMP"' EXIT
mkdir -p "$TMP/build/assets"
printf 'body{color:red}' > "$TMP/build/assets/app-ABCDEFGH.css"
printf 'console.log(1)' > "$TMP/build/assets/app-IJKLMNOP.js"
printf '{}' > "$TMP/build/manifest.json"
CID=$(docker run -d --rm --network none -e SERVER_NAME=:8080 -v "$ROOT/docker/Caddyfile:/tmp/Caddyfile:ro" -v "$TMP:/app/public:ro" --entrypoint frankenphp kuking:ci-534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24 run --config /tmp/Caddyfile --adapter caddyfile)
for attempt in {1..20}; do
    docker exec "$CID" curl -s http://127.0.0.1:8080/build/manifest.json >/dev/null 2>&1 && break
    sleep 0.2
done
for asset in assets/app-ABCDEFGH.css assets/app-IJKLMNOP.js manifest.json assets/missing-ABCDEFGH.js; do
    echo "Zasób: /build/$asset"
    response=$(docker exec "$CID" curl -sS -D - -o /dev/null "http://127.0.0.1:8080/build/$asset")
    grep -Ei '^(HTTP|Cache-Control|Content-Type)' <<< "$response"
    case "$asset" in
        manifest.json) grep -qi 'cache-control: no-cache' <<< "$response"; ! grep -qi immutable <<< "$response";;
        *missing*) grep -q '404' <<< "$response";;
        *) grep -q '200' <<< "$response"; grep -qi 'cache-control: public, max-age=31536000, immutable' <<< "$response";;
    esac
done
