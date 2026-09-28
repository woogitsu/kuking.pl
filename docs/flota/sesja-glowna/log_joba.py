# użycie: python3 log_joba.py JOB_ID [plik] — pobiera pełny log joba GitHub Actions (drukuje ścieżkę i linie z błędami)
import sys,urllib.request; sys.path.insert(0,'/tmp/claude-0/-workspace-kuking-pl/59748e86-571c-55a6-9b6e-f2c80a66befa/scratchpad')
from gh import H
class NoAuth(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return urllib.request.Request(newurl)
jid=sys.argv[1]; out=sys.argv[2] if len(sys.argv)>2 else '/tmp/claude-0/-workspace-kuking-pl/59748e86-571c-55a6-9b6e-f2c80a66befa/scratchpad/log_%s.txt'%jid
t=urllib.request.build_opener(NoAuth).open(urllib.request.Request('https://api.github.com/repos/woogitsu/kuking.pl/actions/jobs/%s/logs'%jid,headers=H)).read().decode('utf-8','replace')
open(out,'w').write(t); print(out)
import re
for i,l in enumerate(t.splitlines()):
    if re.search(r'##\[error\]|FAILED|⨯|Error:|not ok|✗|violation|TimeoutError|expect\(',l): print(i+1, l[29:220])
