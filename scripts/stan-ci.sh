#!/bin/bash
# Czeka na CI dla konkretnego SHA. Wypisuje ZIELONE / ZLE:<joby> / CZEKA / BRAK.
# ŚWIADOMIE wymaga, żeby WSZYSTKIE siedem prawdziwych jobów było `completed`
# i `success` — mój pierwszy, naiwny wariant tej pętli raz odpowiedział
# „ZIELONE" na odpowiedź, w której wszystko stało w kolejce.
SHA=$1; MAX=${2:-14}
JOBY='Pint (styl kodu)|Larastan (analiza statyczna)|Testy (PostgreSQL 18)|Build assetów (Vite)|Build obrazu (weryfikacja)|Audyt zależności|Dostępność (axe-core) i wydajność (Lighthouse)'
for i in $(seq 1 "$MAX"); do
  out=$(curl -s -H "Authorization: token $GITHUB_TOKEN" \
    "https://api.github.com/repos/woogitsu/kuking.pl/commits/$SHA/check-runs" \
    | JOBY="$JOBY" python3 -c "
import sys,json,os
wymagane=set(os.environ['JOBY'].split('|'))
try: d=json.load(sys.stdin)
except Exception: print('BLAD_ODCZYTU'); raise SystemExit
runs={r['name']:r for r in d.get('check_runs',[])}
brak=[n for n in wymagane if n not in runs]
if brak: print('BRAK:'+','.join(sorted(brak)[:3])); raise SystemExit
niegotowe=[n for n in wymagane if runs[n]['status']!='completed']
zle=[n for n in wymagane if runs[n]['status']=='completed' and runs[n].get('conclusion') not in ('success','skipped')]
if zle: print('ZLE:'+','.join(zle))
elif niegotowe: print('CZEKA:'+str(len(niegotowe)))
else: print('ZIELONE')
")
  echo "$i: $out"
  case "$out" in ZIELONE|ZLE:*) exit 0;; esac
  sleep 30
done
