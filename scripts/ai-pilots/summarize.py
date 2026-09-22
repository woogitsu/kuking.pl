"""Ocena wyników przyrządu. Zero sieci; nie zastępuje oceny człowieka."""
import json
import math
from pathlib import Path
from statistics import median, mean

root = Path(__file__).resolve().parents[2]
corpus = root / 'scripts/ai-pilots/corpus'
evidence = root / 'docs/research/ai-pilots/evidence'
cases = {c['id']: c for c in json.loads((corpus / 'search.json').read_text())}
gold = json.loads((corpus / 'search-relevance.json').read_text())
rows = json.loads((evidence / 'sql-baseline.json').read_text())
assert len(cases) == 100 and len(gold) == 60 and len(rows) >= 200

def p95(values):
    return sorted(values)[math.ceil(len(values) * .95) - 1]

summary = {'scope': 'Własny pomiar SQL; ręcznie ustalona przydatność tytułów fixture, bez badania użytkowników', 'variants': {}}
assessments = []
for variant in sorted({r['variant'] for r in rows}):
    selected = [r for r in rows if r['variant'] == variant]
    supported = [r for r in selected if r['id'] in gold]
    precisions = []
    hits = 0
    nonempty = 0
    for row in supported:
        wanted = gold[row['id']]
        returned = [('recipe', r) for r in row['recipes']] + [('person', p) for p in row['people']]
        # Dwa typy wyników mają osobne listy do 5 elementów. Nie udajemy wspólnego rankingu.
        judged = []
        for kind, item in returned:
            relevant = (item in wanted['people']) if kind == 'person' else (
                item['title'] in wanted['recipes'] and (wanted['minutes'] is None or (
                    item['minutes'] is not None and item['minutes'] <= wanted['minutes'])))
            judged.append(bool(relevant))
        if returned:
            nonempty += 1
            precisions.append(sum(judged) / len(judged))
        hits += any(judged)
        assessments.append({'id': row['id'], 'variant': variant, 'returned': len(returned), 'relevant': sum(judged), 'flags': judged})
    summary['variants'][variant] = {
        'cases': len(selected), 'supported': len(supported), 'supported_nonempty': nonempty,
        'supported_with_relevant_result': hits, 'supported_empty': len(supported) - nonempty,
        'mean_precision_nonempty': round(mean(precisions), 4) if precisions else None,
        'median_ms': median([r['latency_ms'] for r in selected]),
        'p95_ms': p95([r['latency_ms'] for r in selected]),
        'sql_queries': sum(r['sql_queries'] for r in selected),
        'provider_requests': 0 if variant in ['A', 'B'] else 'z osobnego pliku odpowiedzi',
    }
(evidence / 'sql-summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
(evidence / 'sql-assessment.json').write_text(json.dumps(assessments, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(summary, ensure_ascii=False, indent=2))
