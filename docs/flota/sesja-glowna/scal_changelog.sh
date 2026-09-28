#!/bin/bash
# $1 = gałąź; scala origin/main, konflikty tylko w CHANGELOG.md rozwiązuje zachowując obie strony
set -e
B=$1; W=/workspace/wm-$$
cd /workspace/kuking.pl && git fetch -q origin
git worktree add -q $W -b tmp-scal-$$ origin/$B
cd $W
if git -c user.name=Claude -c user.email=noreply@anthropic.com merge -q --no-edit origin/main 2>/dev/null; then echo "$B: bez konfliktu"; else
  C=$(git diff --name-only --diff-filter=U)
  ZLE=$(echo "$C" | grep -vxE 'CHANGELOG.md|scripts/kontrole-negatywne-alfa08.py|docs/DECISIONS.md|.gitignore' || true)
  if [ -n "$ZLE" ]; then echo "$B: konflikt poza CHANGELOG: $C"; git merge --abort; cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q; exit 1; fi
  # po podziale dziennika (#1744): wpisy gałęzi → docs/decyzje/ skryptem z main
  if echo "$C" | grep -qx 'docs/DECISIONS.md' && git cat-file -e origin/main:scripts/decyzje-przenies.py 2>/dev/null; then
    git checkout origin/main -- docs/DECISIONS.md
    if ! python3 scripts/decyzje-przenies.py >/tmp/przen-$$.txt 2>&1 || grep -qE 'ręczn|przepnij' /tmp/przen-$$.txt || ! php scripts/decyzje-indeks.php >/dev/null || ! php scripts/decyzje-indeks.php --sprawdz >/dev/null; then
      echo "$B: decyzje do ręcznego przeniesienia: $(tr '\n' ' ' </tmp/przen-$$.txt)"; git merge --abort; cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q; exit 1; fi
    git add docs/DECISIONS.md docs/decyzje
    C=$(echo "$C" | grep -vx 'docs/DECISIONS.md' || true)
  fi
  # po podziale kontroli (#1478): wpisy gałęzi → scripts/kontrole_negatywne/kNN_*.py, nigdy „obie strony”
  if echo "$C" | grep -qx 'scripts/kontrole-negatywne-alfa08.py' && git cat-file -e origin/main:scripts/kontrole_negatywne/README.md 2>/dev/null; then
    if ! python3 /tmp/claude-0/-workspace-kuking-pl/59748e86-571c-55a6-9b6e-f2c80a66befa/scratchpad/przenies_kontrole.py --nazwa "$B" >/tmp/kontr-$$.txt 2>&1; then
      echo "$B: kontrole do ręcznego przeniesienia: $(tr '\n' ' ' </tmp/kontr-$$.txt)"; git merge --abort; cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q; exit 1; fi
    C=$(echo "$C" | grep -vx 'scripts/kontrole-negatywne-alfa08.py' || true)
  fi
  [ -z "$C" ] || python3 - "$C" <<'PY' || { echo "$B: nie udało się połączyć"; git merge --abort; cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q; exit 1; }
import re,sys,py_compile
for f in sys.argv[1].split():
    s=open(f).read()
    if f=='.gitignore':
        # suma wierszy obu stron bez dubli (main pierwszy)
        def suma(m):
            out=[]
            for l in (m.group(2)+m.group(1)).splitlines(True):
                if l not in out: out.append(l)
            return ''.join(out)
        s=re.sub(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', suma, s, flags=re.S)
    elif f=='CHANGELOG.md':
        # main (theirs) na górze, gałąź pod spodem
        s=re.sub(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', lambda m: m.group(2)+m.group(1), s, flags=re.S)
    else:
        import os
        assert f!='scripts/kontrole-negatywne-alfa08.py' or not os.path.isdir('scripts/kontrole_negatywne'), 'stary plik kontroli po podziale — tylko przez przenies_kontrole.py'
        # kontrole negatywne: najpierw main, potem gałąź (obie listy wpisów)
        s=re.sub(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', lambda m: m.group(2)+m.group(1), s, flags=re.S)
    assert '<<<<<<<' not in s and '>>>>>>>' not in s
    open(f,'w').write(s)
    if f.endswith('.py'): py_compile.compile(f, doraise=True)
    if f=='docs/DECISIONS.md':
        import collections
        dup=[k for k,v in collections.Counter(re.findall(r'^## (D-\d+)\b',s,flags=re.M)).items() if v>1]
        assert not dup, 'zdublowane numery: %s'%dup
PY
  [ -z "$C" ] || git add $C
  if [ -d scripts/kontrole_negatywne ] && ! python3 scripts/kontrole-negatywne-alfa08.py --lista >/tmp/lista-$$.txt 2>&1; then
    echo "$B: --lista po scaleniu nie przechodzi: $(tail -3 /tmp/lista-$$.txt | tr '\n' ' ')"; git merge --abort; cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q; exit 1; fi
  git -c user.name=Claude -c user.email=noreply@anthropic.com commit -q -m "Scal origin/main (CHANGELOG / kontrole negatywne: obie strony)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01GS2ZCY3HVphcSditk6mGNU"
  echo "$B: rozwiązano $(echo $C | tr "\n" " ")"
fi
git push -q origin HEAD:$B && echo "$B: push $(git rev-parse --short HEAD)"
cd /; git -C /workspace/kuking.pl worktree remove --force $W; git -C /workspace/kuking.pl branch -D tmp-scal-$$ -q
