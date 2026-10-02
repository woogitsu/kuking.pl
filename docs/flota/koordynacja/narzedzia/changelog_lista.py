p='CHANGELOG.md'; s=open(p).read()
i=s.index('## Nieopublikowane'); j=s.find('\n## ',i+5); j=len(s) if j<0 else j
linie=s[i:j].split('\n'); w=[]
for k,l in enumerate(linie):
    if l=='' and w and w[-1].startswith('- ') and k+1<len(linie) and linie[k+1].startswith('- '): continue
    w.append(l)
open(p,'w').write(s[:i]+'\n'.join(w)+s[j:])
