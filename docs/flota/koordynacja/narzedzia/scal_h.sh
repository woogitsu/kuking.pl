#!/bin/bash
SP=${SP:?ustaw SP na katalog z tymi narzędziami}
rozwiaz(){ U=$(git diff --name-only --diff-filter=U); [ -z "$U" ] && return 0
  inne=$(echo "$U" | grep -v -E '^(CHANGELOG.md|resources/nowosci/tresc.md|docs/decyzje/D-333.*|scripts/kontrole-negatywne-alfa08.py|scripts/kontrole_oczekiwana_przyczyna.py|docs/DATABASE.md)$')
  [ -n "$inne" ] && { echo "STOP: $inne"; return 1; }
  echo "$U" | grep -q '^docs/DATABASE.md$' && python3 $SP/scal_db.py
  U2=$(echo "$U" | grep -v '^docs/DATABASE.md$'); [ -n "$U2" ] && python3 $SP/union_cl.py $U2
  python3 $SP/changelog_lista.py; git add $U && git commit -q --no-edit; }
rozwiaz || exit 1
for b in "$@"; do git merge -q --no-ff --no-edit $b >/dev/null 2>&1; rozwiaz || { echo "przy $b"; exit 1; }; echo "OK $b"; done
