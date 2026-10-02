# Konflikt w indeksie docs/DATABASE.md: ten sam wiersz pliku z różnymi listami tabel → suma list.
import re
p='docs/DATABASE.md'; s=open(p).read()
def fix(m):
    a=m.group(1).strip('\n').split('\n'); b=m.group(2).strip('\n').split('\n'); out=[]
    for la in a:
        kl=la.split('|')[1]
        lb=next((x for x in b if x.split('|')[1]==kl),None)
        if lb:
            ta=[t.strip() for t in la.split('|')[3].split(',')]; tb=[t.strip() for t in lb.split('|')[3].split(',')]
            tabs=ta+[t for t in tb if t not in ta]
            cz=la.split('|'); cz[3]=' '+', '.join(tabs)+' '; out.append('|'.join(cz))
        else: out.append(la)
    out+=[x for x in b if x.split('|')[1] not in [l.split('|')[1] for l in a]]
    return '\n'.join(out)+'\n'
s=re.sub(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n',fix,s,flags=re.S)
open(p,'w').write(s)
