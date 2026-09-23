#!/bin/bash
# WYMAGA: WSL Ubuntu tej maszyny — worktree pod /mnt/c/Users/matma/Documents/kuking-flota/, cache vendor/node_modules w /home/mateusz/flota/vendor-cache i PHP z /opt/kuking-php-8.4-avif; poza tą maszyną nie zadziała, czyta się go jak zapis metody.
# Stawia runtime WSL dla jednego stanowiska floty.
# Uzycie (z WSL):  bash przygotuj-runtime.sh <nazwa-stanowiska>
# Przyklad:        bash przygotuj-runtime.sh gotowanie
set -euo pipefail
N="${1:?podaj nazwe stanowiska}"
WT="/mnt/c/Users/matma/Documents/kuking-flota/$N"
RT="/home/mateusz/flota/$N-run"
CACHE="/home/mateusz/flota/vendor-cache"

[ -d "$WT" ] || { echo "BRAK worktree $WT"; exit 1; }
mkdir -p "$RT"

# Kopiujemy CALE drzewo z wykluczeniami, nie liste katalogow do wziecia.
# (PULAPKI_TESTOW.md §10: lista milczy, gdy dojdzie piaty katalog.)
# Ukosnik na POCZATKU kotwiczy wzorzec do korzenia. Bez niego 'vendor/'
# wyklucza KAZDY katalog o tej nazwie — takze resources/views/vendor/mail,
# a wtedy StandardoweWiadomosciMarkiTest pada na domyslnych kolorach Laravela
# (#fafafa zamiast #F3F4F1) i wyglada jak regresja galezi. Zmierzone przez
# stanowisko dsa-odwolania: po dograniu tego katalogu te same 7 testow przechodzi.
rsync -a --delete \
  --exclude '/vendor/' --exclude '/node_modules/' --exclude '.git' \
  --exclude '.env' \
  --exclude 'storage/framework/cache/' --exclude 'storage/logs/' \
  "$WT"/ "$RT"/

# vendor i node_modules KOPIUJEMY, nie dowiazujemy symlinkiem
# (symlink wywraca JednoDekodowanieZdjeciaTest po osmiu minutach hooka).
# MUSI stac PRZED zakladaniem .env — `artisan key:generate` bez vendora pada,
# a pod `set -e` zostawia .env z PUSTYM APP_KEY, ktorego kolejne uruchomienie
# juz nie poprawi (warunek `! -f .env` jest wtedy falszywy). Objaw:
# MissingAppKeyException wygladajacy na regresje galezi. Zglosila bramka-startowa.
for d in vendor node_modules; do
  if [ ! -d "$RT/$d" ]; then cp -a "$CACHE/$d" "$RT/$d"; fi
done

# .env i APP_KEY. Worktree go nie ma (jest w .gitignore), a bez klucza aplikacji
# testy padaja na MissingAppKeyException i wygladaja na regresje gałęzi.
# '.env' jest wykluczony z rsynca WYZEJ — inaczej --delete kasowalby go
# przy kazdym uruchomieniu, bo w zrodle takiego pliku nie ma.
# Warunek patrzy na TRESC klucza, nie na istnienie pliku — plik z pustym
# APP_KEY jest gorszy niz brak pliku, bo wyglada na zalatwiony.
if ! grep -qE '^APP_KEY=base64:.+' "$RT/.env" 2>/dev/null; then
  [ -f "$RT/.env" ] || cp "$RT/.env.example" "$RT/.env"
  ( cd "$RT" && PATH="/opt/kuking-php-8.4-avif/bin:$PATH" php artisan key:generate --quiet ) \
    && echo "Utworzono .env i APP_KEY" \
    || echo "UWAGA: nie udalo sie wygenerowac APP_KEY — testy padna na MissingAppKeyException"
fi

# Kontrola swiezosci .github — PortMarkiMaWlasnaBramkeCiTest czyta ci.yml przez base_path().
if ! diff -q "$WT/.github/workflows/ci.yml" "$RT/.github/workflows/ci.yml" >/dev/null 2>&1; then
  echo "UWAGA: ci.yml w runtime rozjechany z worktree"
fi

echo "RUNTIME: $RT"
echo "Wlasna baza testowa: kuking_flota_$N   (port 55439, nigdy 5432)"
