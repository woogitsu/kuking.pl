# ci.py <sha|ref>... : stan check-runów dla commitu
import sys
from gh import req
for ref in sys.argv[1:]:
    cr=req('GET',f'/commits/{ref}/check-runs?per_page=100')['check_runs']
    pend=[c['name'] for c in cr if c['status']!='completed']
    bad=[c['name'] for c in cr if c['status']=='completed' and c['conclusion'] not in ('success','skipped','neutral')]
    ok=sum(1 for c in cr if c['conclusion'] in('success',))
    print(ref[:12], f"runs={len(cr)} ok={ok} pending={len(pend)} bad={bad}", ('PEND:'+','.join(p[:30] for p in pend[:4])) if pend else '')
