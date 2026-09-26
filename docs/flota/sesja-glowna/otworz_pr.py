# użycie: python3 otworz_pr.py gałąź [gałąź...] — PR z tematu i treści ostatniego commita (bez linii Co-Authored/Claude-Session)
import sys,subprocess; sys.path.insert(0,'.')
from gh import req, FOOT
for b in sys.argv[1:]:
    subprocess.run(['git','-C','/workspace/kuking.pl','fetch','-q','origin',b])
    msg=subprocess.run(['git','-C','/workspace/kuking.pl','log','--format=%B','-1','origin/'+b],capture_output=True,text=True).stdout
    lines=[l for l in msg.strip().splitlines() if not l.startswith(('Co-Authored-By','Claude-Session'))]
    title=lines[0]; body='\n'.join(lines[1:]).strip()
    try:
        r=req('POST','/pulls',{'title':title,'head':b,'base':'main','body':body+FOOT}); print(b,'#%d'%r['number'])
    except Exception: print(b,'BŁĄD')
