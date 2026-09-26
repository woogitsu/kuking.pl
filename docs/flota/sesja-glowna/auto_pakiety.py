# otwiera PR dla każdej gałęzi claude/pakiet-* bez PR (dowolny stan)
import sys,subprocess; sys.path.insert(0,'.')
from gh import req
out=subprocess.run(['git','-C','/workspace/kuking.pl','ls-remote','--heads','origin','claude/pakiet-*','claude/api-*'],capture_output=True,text=True).stdout
for l in out.splitlines():
    b=l.split('refs/heads/')[1]
    if req('GET','/pulls?state=all&head=woogitsu:%s'%b): continue
    r=subprocess.run(['python3','otworz_pr.py',b],capture_output=True,text=True).stdout.strip()
    print('PR OTWARTY',r,flush=True)
