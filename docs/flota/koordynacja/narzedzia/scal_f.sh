#!/bin/bash
# Scala kolejne gałęzie; konflikty tylko w plikach "addytywnych" rozwiązuje sumą.
SP=${SP:?ustaw SP na katalog z tymi narzędziami}
for b in "$@"; do
  if git merge -q --no-ff --no-edit $b >/dev/null 2>&1; then echo "OK $b"; continue; fi
  U=$(git diff --name-only --diff-filter=U)
  inne=$(echo "$U" | grep -v -E '^(CHANGELOG.md|resources/nowosci/tresc.md|docs/decyzje/D-333.*|scripts/kontrole-negatywne-alfa08.py|scripts/kontrole_oczekiwana_przyczyna.py|docs/legal/REJESTR.*)$')
  if [ -n "$inne" ]; then echo "STOP $b: $U"; exit 1; fi
  python3 $SP/union_cl.py $U; python3 $SP/changelog_lista.py
  git add $U && git commit -q --no-edit && echo "OK* $b ($(echo $U|tr '\n' ' '))"
done
