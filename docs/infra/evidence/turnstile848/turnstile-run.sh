#!/bin/bash
set -o pipefail
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-turnstile-pomoc --filter '^(?!.*ProbaOdtworzeniaTest)' > /mnt/c/Users/matma/Documents/kuking-flota/gpt-turnstile-pomoc/turnstile-full.txt 2>&1
status=$?
tail -35 /mnt/c/Users/matma/Documents/kuking-flota/gpt-turnstile-pomoc/turnstile-full.txt
exit "$status"