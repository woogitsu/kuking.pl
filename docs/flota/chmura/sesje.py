#!/usr/bin/env python3
# Użycie: python3 sesje.py <plik-z-list_sessions> [...]  — wypisuje tylko NIEzarchiwizowane sesje
import json, sys
for p in sys.argv[1:]:
    t = open(p).read()
    d, _ = json.JSONDecoder().raw_decode(t[t.find('{"ccr'):])
    c = d['ccr']
    for s in c['data']:
        st = s.get('session_status', '')
        if 'ARCHIV' in st or s['title'] == 'Kuking.pl':
            continue
        pts = s.get('post_turn_summary') or {}
        print(s['id'], st.replace('SESSION_STATUS_', ''), s.get('status_bucket', '').replace('SESSION_STATUS_BUCKET_', ''),
              s['updated_at'][5:16], s['title'][:60], '|', (pts.get('status_detail') or '')[:90])
    print('has_more', c.get('has_more'), 'last', c.get('last_id'), file=sys.stderr)
