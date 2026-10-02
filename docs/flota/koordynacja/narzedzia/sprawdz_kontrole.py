import re,sys,ast,importlib
from collections import Counter
src=open('scripts/kontrole-negatywne-alfa08.py').read(); ast.parse(src)
sys.path.insert(0,'scripts')
O=importlib.import_module('kontrole_oczekiwana_przyczyna').OCZEKUJ
st=src.index('checks = [\n')
labels=[ast.literal_eval(x) for x in re.findall(r'^\s{4}\(\s*("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')\s*,',src[st:],re.M)]
d=[k for k,v in Counter(labels).items() if v>1]
print('kontroli:',len(labels),'wzorców:',len(O),'duplikaty:',d,'bez wzorca:',[l for l in labels if l not in O],'wzorzec bez kontroli:',[k for k in O if k not in labels])
