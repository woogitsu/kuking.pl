# Ponawia ostatni przebieg CI otwartych PR-ów, jeśli zakończył się jako cancelled.
from gh import req
import sys
LIMIT=int(sys.argv[1]) if len(sys.argv)>1 else 10**6
prs=[];p=1
while True:
    r=req('GET',f'/pulls?state=open&per_page=100&page={p}')
    if not r: break
    prs+=r; p+=1
n=0;bl=0
for pr in prs:
    if n>=LIMIT: break
    try:
        runs=[w for w in req('GET',f"/actions/runs?head_sha={pr['head']['sha']}&per_page=10")['workflow_runs'] if w['name']=='CI']
    except Exception: continue
    if not runs: continue
    w=runs[0]
    if w['status']=='completed' and w['conclusion']=='cancelled':
        try: req('POST',f"/actions/runs/{w['id']}/rerun",{}); n+=1; print('ponowiono',pr['number'],flush=True)
        except Exception: bl+=1
print('RAZEM ponowiono',n,'błędy',bl,'otwartych PR',len(prs))
