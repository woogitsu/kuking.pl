# Suma obu stron konfliktu z separatorem wstawianym między nimi: union_sep.py plik 'sep'
import sys
p,sep=sys.argv[1],sys.argv[2].encode().decode('unicode_escape')
s=open(p).read(); out=[]; a=[]; b=[]; mode=None
for line in s.splitlines(keepends=True):
    if line.startswith('<<<<<<< '): mode='a'; a=[]; b=[]; continue
    if line.startswith('=======') and mode=='a': mode='b'; continue
    if line.startswith('>>>>>>> ') and mode=='b': mode=None; out+=a+[sep]+b; continue
    (a if mode=='a' else b if mode=='b' else out).append(line)
open(p,'w').write(''.join(out))
