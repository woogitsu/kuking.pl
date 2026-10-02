# Jak union.py, ale gdy strona HEAD zaczyna się od nagłówka „## ” (np. Alfa 0.78),
# nowe wpisy z drugiej strony idą NAD nagłówek (do „Nieopublikowane”).
import sys
for p in sys.argv[1:]:
    s=open(p).read(); out=[]; a=[]; b=[]; mode=None
    for line in s.splitlines(keepends=True):
        if line.startswith('<<<<<<< '): mode='a'; a=[]; b=[]; continue
        if line.startswith('=======') and mode=='a': mode='b'; continue
        if line.startswith('>>>>>>> ') and mode=='b':
            mode=None
            if a and a[0].startswith('## '):
                out+=b
                if b and b[-1].strip(): out.append('\n')
                out+=a
            else: out+=a+b
            continue
        (a if mode=='a' else b if mode=='b' else out).append(line)
    open(p,'w').write(''.join(out))
