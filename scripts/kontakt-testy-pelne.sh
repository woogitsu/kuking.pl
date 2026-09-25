#!/usr/bin/env bash
set -euo pipefail
# Jedyny dopuszczony przez właściciela wyjątek używa wspólnej bazy próby.
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh \
  gpt-kontakt-panel '--filter=^(?!.*ProbaOdtworzeniaTest)'
