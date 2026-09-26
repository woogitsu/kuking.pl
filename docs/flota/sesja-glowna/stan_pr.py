from gh import *
import json
prs=[];p=1
while True:
    r=req('GET',f'/pulls?state=open&per_page=100&page={p}')
    if not r: break
    prs+=r; p+=1
out=[]
for pr in prs:
    n=pr['number']; sha=pr['head']['sha']
    d=req('GET',f'/pulls/{n}')
    cr=req('GET',f'/commits/{sha}/check-runs?per_page=100')['check_runs']
    concl=[c['conclusion'] for c in cr]
    pend=sum(1 for c in cr if c['status']!='completed')
    bad=[c['name'] for c in cr if c['status']=='completed' and c['conclusion'] not in ('success','skipped','neutral')]
    out.append((n,d['mergeable_state'],len(cr),pend,bad,pr['head']['ref'],pr['title'][:60]))
json.dump(out,open('stan_pr.json','w'),ensure_ascii=False)
for o in sorted(out):
    tag='ZIELONY' if o[2]>0 and o[3]==0 and not o[4] else ('W TOKU' if o[3] else ('CZERWONY' if o[4] else 'BRAK CI'))
    print(o[0],tag,o[1],o[5][:40],'|',(o[4][:2] if o[4] else ''))
