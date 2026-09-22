#!/usr/bin/env bash
set -uo pipefail
export MSYS_NO_PATHCONV=1
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-wyszukiwanie-granice --filter '^(?!.*ProbaOdtworzeniaTest)' > /mnt/c/Users/matma/Documents/kuking-flota/gpt-wyszukiwanie-granice/storage/app/qa-granice/final-tests.txt 2>&1
result=$?
tail -n 8 /mnt/c/Users/matma/Documents/kuking-flota/gpt-wyszukiwanie-granice/storage/app/qa-granice/final-tests.txt
exit "$result"