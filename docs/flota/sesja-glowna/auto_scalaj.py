# jeden przebieg: scala zielone PR-y z poszanowaniem kolejności; konfliktowym wciąga main (tylko CHANGELOG)
import sys,time,subprocess; sys.path.insert(0,'.')
from gh import req
WYKL={1511}
PO={1618:[1453],1548:[1268],1575:[1485],1571:[1518],1505:[1511],1510:[1511],1616:[1541],
    1580:[1577],1566:[1580],1567:[1566],1584:[1567],1586:[1584],1578:[1586],1583:[1579]}
def scalony(n):
    return req('GET','/pulls/%d'%n).get('merged_at') is not None
prs=[];p=1
while True:
    r=req('GET','/pulls?state=open&per_page=100&page=%d'%p)
    if not r: break
    prs+=r; p+=1
odsw=0
_msha=req('GET','/commits/main')['sha']
mr=[x for x in req('GET','/actions/runs?head_sha=%s&per_page=20'%_msha)['workflow_runs'] if x['name']=='CI' and x['event']=='push']
# brak przebiegu CI dla bieżącego main (jeszcze nie założony) też znaczy „zajęty”
MAIN_ZAJETY = (not mr) or mr[0]['status']!='completed'
print('MAIN',_msha[:8],'CI:',(mr[0]['status'],mr[0]['conclusion']) if mr else None,flush=True)
# po zielonym main czekaj (max 20 min), aż produkcja pokaże ten commit w stopce — inaczej Railway przeskakuje wdrożenie
if mr and not MAIN_ZAJETY and mr[0]['conclusion']=='success':
    import datetime,re as _re
    try:
        html=subprocess.run(['curl','-s','-m','15','-A','Mozilla/5.0','https://kuking.pl/'],capture_output=True,text=True).stdout
        na_prod=mr[0]['head_sha'][:7] in html
    except Exception: na_prod=True
    kon=datetime.datetime.fromisoformat(mr[0]['updated_at'].replace('Z','+00:00'))
    if not na_prod and (datetime.datetime.now(datetime.timezone.utc)-kon).total_seconds()<1200:
        MAIN_ZAJETY=True; print('CZEKAM NA WDROŻENIE',mr[0]['head_sha'][:8],flush=True)
for pr in sorted(prs,key=lambda x:x['number']):
    n=pr['number']
    if pr['draft'] or n in WYKL: continue
    if any(not scalony(q) for q in PO.get(n,[])): continue
    sha=pr['head']['sha']
    cr=req('GET','/commits/%s/check-runs?per_page=100'%sha)['check_runs']
    if not cr or any(c['status']!='completed' or c['conclusion'] not in ('success','skipped','neutral') for c in cr): continue
    full=req('GET','/pulls/%d'%n)
    for _ in range(4):
        if full.get('mergeable') is not None: break
        time.sleep(3); full=req('GET','/pulls/%d'%n)
    TYLKO_NAPRAWA = not scalony(1757)
    if full.get('mergeable') is True and (not TYLKO_NAPRAWA or n==1757) and (not MAIN_ZAJETY or n in (1732,1757)):
        try: r=req('PUT','/pulls/%d/merge'%n,{'merge_method':'merge','sha':sha}); print('SCALONY #%d %s'%(n,pr['title'][:60]),flush=True)
        except Exception as e: print('BŁĄD scalania #%d'%n,flush=True)
    elif full.get('mergeable') is False and odsw<40 and not pr['head']['ref'].startswith('claude/api-') and pr['head']['repo'] and pr['head']['repo']['full_name']=='woogitsu/kuking.pl':
        odsw+=1
        out=subprocess.run(['bash','scal_changelog.sh',pr['head']['ref']],capture_output=True,text=True,timeout=300).stdout.strip().splitlines()
        print('ODŚWIEŻONY #%d: %s'%(n,out[-1] if out else '?'),flush=True)
