# użycie: python3 otworz_wiele.py gałąź... — PR: tytuł z pierwszego własnego commita, treść z opisów commitów gałęzi (bez merge i atrybucji)
import sys,subprocess
from gh import req, FOOT
G=['git','-C','/workspace/kuking.pl']
subprocess.run(G+['fetch','-q','origin'])
for b in sys.argv[1:]:
    if req('GET',f'/pulls?head=woogitsu:{b}&state=open'): print(b,'już ma PR'); continue
    shas=subprocess.run(G+['log','--no-merges','--reverse','--format=%H',f'origin/main..origin/{b}'],capture_output=True,text=True).stdout.split()
    if not shas: print(b,'brak commitów'); continue
    parts=[]; title=None
    for s in shas:
        msg=subprocess.run(G+['log','-1','--format=%B',s],capture_output=True,text=True).stdout
        L=[l for l in msg.strip().splitlines() if not l.startswith(('Co-Authored-By','Claude-Session'))]
        if title is None: title=L[0]
        parts.append('### '+L[0]+'\n'+'\n'.join(L[1:]).strip())
    body='\n\n'.join(parts)
    if len(shas)>1: body=f'Gałąź ma {len(shas)} commity/-ów — opis każdego poniżej.\n\n'+body
    try: r=req('POST','/pulls',{'title':title[:250],'head':b,'base':'main','body':body[:60000]+FOOT}); print(b,'#%d'%r['number'])
    except Exception as e: print(b,'BŁĄD')
