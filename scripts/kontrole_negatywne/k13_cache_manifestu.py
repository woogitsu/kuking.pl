"""Cache manifestu Vite (#809).

Strażnik czyta `docker/Caddyfile`: pliki z hashem w `/build/assets/*` dostają
rok `immutable`, manifest `no-cache`. Mutacja wraca do dawnej, szerokiej reguły
`/build/*` — tej, która dawała manifestowi bez hasha roczny cache — i test ma
zapalić.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


CADDYFILE = "docker/Caddyfile"
CACHE_MANIFESTU_TEST = "test_manifest_bez_hasha_nie_dostaje_rocznego_cache_assetow"

KONTROLE_DODATNIE = [CACHE_MANIFESTU_TEST]

KONTROLE = [
    Kontrola("Manifest Vite z rocznym cache assetów", CADDYFILE, CACHE_MANIFESTU_TEST,
             lambda s: replace_once(s, "@viteAssets path /build/assets/*", "@viteAssets path /build/*")),
]
