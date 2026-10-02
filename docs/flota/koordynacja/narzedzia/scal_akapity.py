import re,sys
p=sys.argv[1]; s=open(p).read()
pat=re.compile(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', re.S)
def ok(b): 
    t=b.strip('\n'); return t.startswith('### ')
bad=[m for m in pat.finditer(s) if not (ok(m.group(1)) and ok(m.group(2)))]
if bad: print('NIE: blok nie jest całymi akapitami'); sys.exit(1)
s=pat.sub(lambda m: m.group(1).rstrip('\n')+'\n\n'+m.group(2), s)
open(p,'w').write(s)
