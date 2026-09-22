set -uo pipefail
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-heic-format --compact --filter '^(?!.*ProbaOdtworzeniaTest)' > /mnt/c/Users/matma/Documents/kuking-flota/gpt-heic-format/.codex/heic-119/full-suite.txt 2>&1
result=$?
tail -90 /mnt/c/Users/matma/Documents/kuking-flota/gpt-heic-format/.codex/heic-119/full-suite.txt
exit "$result"