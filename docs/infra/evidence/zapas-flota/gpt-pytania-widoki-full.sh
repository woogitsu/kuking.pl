#!/bin/bash
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-pytania-widoki --exclude-group dwa-polaczenia --filter '^(?!.*ProbaOdtworzeniaTest)' > /tmp/gpt-pytania-widoki-full.txt 2>&1
result=$?
tail -n 80 /tmp/gpt-pytania-widoki-full.txt
exit "$result"
