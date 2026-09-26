# Jedno przejście strażnika kolejki (zgoda właściciela 25.09 ~19:35: „rób tak sam z automatu”).
# 1) CI main czeka w kolejce > 10 min → anuluj czekające runy PR-ów (nie main, nie PRIORYTET).
# 2) CI main nie czeka i kolejka mała → ponów do 25 anulowanych runów PR-ów (ostatni run CI PR-a = cancelled).
import datetime as dt, json, subprocess, sys
from gh import req
PRIORYTET={'claude/naprawa-main-kursor-startu'}
teraz=dt.datetime.now(dt.timezone.utc)
m=req('GET','/commits/main')['sha']
ci=[r for r in req('GET',f'/actions/runs?head_sha={m}&event=push')['workflow_runs'] if r['name']=='CI']
czeka = bool(ci) and ci[0]['status'] in ('queued','waiting','pending') and \
        (teraz-dt.datetime.fromisoformat(ci[0]['created_at'].replace('Z','+00:00'))).total_seconds()>600
kol=req('GET','/actions/runs?status=queued&per_page=1')['total_count']
if czeka:
    runs=[];p=1
    while True:
        r=req('GET',f'/actions/runs?status=queued&per_page=100&page={p}')['workflow_runs']; runs+=r
        if len(r)<100: break
        p+=1
    n=0
    for r in runs:
        if r['head_branch']=='main' or r['head_branch'] in PRIORYTET: continue
        try: req('POST',f"/actions/runs/{r['id']}/cancel"); n+=1
        except Exception: pass
    print(f'ANULOWANO {n} runów PR-ów (main {m[:8]} czekał w kolejce)',flush=True)
elif ci and ci[0]['status']!='queued' and kol<15:
    out=subprocess.run([sys.executable,'ponow_anulowane.py','25'],capture_output=True,text=True).stdout.strip().splitlines()
    if out and not out[-1].startswith('RAZEM ponowiono 0'): print('PONOWIONO:',out[-1],flush=True)
