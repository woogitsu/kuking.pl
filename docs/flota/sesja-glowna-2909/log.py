# log.py <job_id> : pobiera log joba do ../logs/<id>.txt (redirect bez nagłówka auth)
import sys, urllib.request, os
from gh import H
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
op=urllib.request.build_opener(NoRedir)
os.makedirs('../logs',exist_ok=True)
for j in sys.argv[1:]:
    try:
        op.open(urllib.request.Request(f'https://api.github.com/repos/woogitsu/kuking.pl/actions/jobs/{j}/logs',headers=H))
    except urllib.error.HTTPError as e:
        loc=e.headers['Location']
    t=urllib.request.urlopen(loc).read().decode('utf-8','replace')
    open(f'../logs/{j}.txt','w').write(t); print(j,len(t))
