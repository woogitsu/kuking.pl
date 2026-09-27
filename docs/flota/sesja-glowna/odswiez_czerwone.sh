#!/bin/bash
# Sekwencyjnie wciąga main do otwartych PR-ów z zakończoną porażką CI (commit starszy niż 20 min), pomija WYKL, api-*, wt-* w toku.
cd /tmp/claude-0/-workspace-kuking-pl/59748e86-571c-55a6-9b6e-f2c80a66befa/scratchpad
python3 - <<'PY' > /tmp/czerwone-lista.txt
import datetime as dt, os
from gh import req
teraz=dt.datetime.now(dt.timezone.utc); prs=[];p=1
while True:
    r=req('GET',f'/pulls?state=open&per_page=100&page={p}'); prs+=r
    if len(r)<100: break
    p+=1
for pr in prs:
    n=pr['number']; ref=pr['head']['ref']
    if n in (1511,1744) or ref.startswith('claude/api-') or not pr['head']['repo'] or pr['head']['repo']['full_name']!='woogitsu/kuking.pl': continue
    if os.path.isdir(f'/workspace/wt-{n}'): continue
    c=req('GET',f"/commits/{pr['head']['sha']}")
    if (teraz-dt.datetime.fromisoformat(c['commit']['committer']['date'].replace('Z','+00:00'))).total_seconds()<1200: continue
    cr=req('GET',f"/commits/{pr['head']['sha']}/check-runs?per_page=100")['check_runs']
    if any(x['status']=='completed' and x['conclusion']=='failure' for x in cr): print(n, ref)
PY
while read n ref; do bash scal_changelog.sh "$ref" 2>&1 | tail -1 | sed "s/^/#$n /"; done < /tmp/czerwone-lista.txt
echo "KONIEC odświeżania: $(wc -l < /tmp/czerwone-lista.txt) PR-ów"
