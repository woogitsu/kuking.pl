import re,subprocess,sys,os,tempfile
f=sys.argv[1]; basef=sys.argv[2]
s=open(f).read()
base=open(basef).read().split('\n')
def tok(t): return re.findall(r'\S+|\s+',t)
out=[];pos=0
pat=re.compile(r'<<<<<<< HEAD\n(.*?)=======\n(.*?)>>>>>>> origin/codex/integracja-poprawki-20261002-c\n',re.S)
for m in pat.finditer(s):
    o=m.group(1).rstrip('\n'); t=m.group(2).rstrip('\n')
    assert '\n' not in o and '\n' not in t
    key=o[:60]
    cands=[l for l in base if l[:60]==key]
    if not cands: key=t[:60]; cands=[l for l in base if l[:60]==key]
    assert len(cands)==1,(key,len(cands))
    b=cands[0]
    d=tempfile.mkdtemp()
    for n,x in (('o',o),('b',b),('t',t)):
        open(f'{d}/{n}','w').write('\n'.join(tok(x).__iter__().__class__ and [w.replace('\n','') for w in tok(x)])+'\n')
    r=subprocess.run(['git','merge-file','-p',f'{d}/o',f'{d}/b',f'{d}/t'],capture_output=True,text=True)
    if r.returncode!=0: print('CONFLICT',r.stdout); sys.exit(1)
    merged=''.join(r.stdout.split('\n'))
    out.append(s[pos:m.start()]); out.append(merged+'\n'); pos=m.end()
out.append(s[pos:])
open(f,'w').write(''.join(out))
