#!/usr/bin/env bash
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-harmonogram --filter '^(?!.*ProbaOdtworzeniaTest)' --compact > /mnt/c/Users/matma/Documents/kuking-flota/_zapas/gpt-harmonogram-przejecie/pelne-testy.txt 2>&1
result=$?
tail -n 65 /mnt/c/Users/matma/Documents/kuking-flota/_zapas/gpt-harmonogram-przejecie/pelne-testy.txt
exit "$result"
