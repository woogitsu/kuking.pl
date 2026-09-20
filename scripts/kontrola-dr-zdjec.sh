#!/usr/bin/env bash
# Psuje wyłącznie własną kopię runtime, nigdy worktree ani produkcję.
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
if [[ "$(pwd -P)" != /home/mateusz/flota/gpt-dr-zdjecia-run ]]; then
    echo 'Uruchom kontrolę w runtime gpt-dr-zdjecia-run.' >&2
    exit 2
fi
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
mkdir -p storage/dr617
bash scripts/kontrola-ujemna.sh \
    --nazwa 'Brak retencji pozwala skasować kopię' \
    --plik scripts/proba-dr-zdjec.py \
    --zamien 'mc("retention", "set", "--default", "COMPLIANCE", "1d", "dr/dr-backup")' \
    --na 'pass # kontrola braku retencji' \
    --oczekuj 'BRAK_ODMOWY: pisarz kasuje' \
    --json storage/dr617/bez-retencji.json \
    -- bash scripts/proba-dr-zdjec.sh
bash scripts/kontrola-ujemna.sh \
    --nazwa 'Zmiana bajtu kopii bez zmiany rozmiaru' \
    --plik tests/Dr/DrZdjecMinioTest.php \
    --zamien '$this->assertSame($entry['"'"'bytes'"'"'], strlen($body));' \
    --na '$body[0] = chr(ord($body[0]) ^ 1); $this->assertSame($entry['"'"'bytes'"'"'], strlen($body));' \
    --oczekuj 'NIEZGODNA_SUMA_KOPII' \
    --json storage/dr617/uszkodzona-kopia.json \
    -- bash scripts/proba-dr-zdjec.sh
bash scripts/kontrola-ujemna.sh \
    --nazwa 'GPS omija sanitator przed zapisem do storage' \
    --plik app/Domain/Media/Actions/StoreUploadedImage.php \
    --zamien 'UsunGps::zBajtow($file->get())' \
    --na '$file->get()' \
    --oczekuj 'GPSLatitude' \
    --json storage/dr617/gps-przed-storage.json \
    -- bash scripts/proba-dr-zdjec.sh
