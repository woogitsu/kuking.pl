#!/usr/bin/env python3
# Czuwanie sesji głównej Kuking: jedno przejście, wypisuje tylko zmiany od poprzedniego.
import json, os, subprocess, urllib.request, time
ST = os.environ.get("CZUWANIE_STAN", os.path.join(os.path.dirname(os.path.abspath(__file__)), "czuwanie-stan.json"))
B = "https://api.github.com/repos/woogitsu/kuking.pl"
H = {"Authorization": "Bearer " + os.environ.get("GH_TOKEN", "")}
def get(u):
    try:
        return json.load(urllib.request.urlopen(urllib.request.Request(B + u, headers=H), timeout=30))
    except Exception:
        return None
try: st = json.load(open(ST))
except Exception: st = {"runs": [], "heads": {}, "main": "", "init": True}
if not st.get("since"): st["since"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
out = []
# 1) zakończone przebiegi CI (nowe od ostatniego razu)
runs = get("/actions/workflows/ci.yml/runs?status=completed&per_page=40") or {}
seen = set(st["runs"])
for r in runs.get("workflow_runs", []):
    if r["id"] in seen: continue
    seen.add(r["id"])
    if st.get("init"): continue
    if r.get("updated_at","") < st.get("since",""): continue
    prs = ",".join("#%d" % p["number"] for p in r.get("pull_requests", [])) or r["head_branch"]
    out.append("CI %s: %s (%s, próba %s, run %s)" % (prs, r["conclusion"], r["head_sha"][:8], r["run_attempt"], r["id"]))
st["runs"] = sorted(seen)[-800:]
# 2) gałęzie: nowe albo przesunięte (poza main)
ls = subprocess.run(["git", "-C", os.environ.get("KUKING_REPO", os.getcwd()), "ls-remote", "--heads", "origin"], capture_output=True, text=True).stdout
heads = {}
for line in ls.splitlines():
    sha, ref = line.split("\t"); heads[ref.replace("refs/heads/", "")] = sha
if not st.get("init"):
    for b, sha in heads.items():
        if b == "main": continue
        old = st["heads"].get(b)
        if old is None: out.append("NOWA GAŁĄŹ %s @ %s" % (b, sha[:8]))
        elif old != sha: out.append("PUSH %s: %s -> %s" % (b, old[:8], sha[:8]))
m = heads.get("main", "")
if st.get("main") and m != st["main"] and not st.get("init"):
    out.append("MAIN przesunięty: %s -> %s" % (st["main"][:8], m[:8]))
# 3) PR-y gotowe do scalenia: clean + wszystkie checki zielone; każdy head zgłaszany raz.
#    Sprawdzane: PR-y z właśnie zakończonym zielonym CI oraz pełny przegląd co 30 min.
def gotowy(n):
    d = get("/pulls/%d" % n)
    if not d or d.get("state") != "open" or d.get("draft"): return None
    if d.get("mergeable_state") != "clean": return None
    c = (get("/commits/%s/check-runs?per_page=100" % d["head"]["sha"]) or {}).get("check_runs", [])
    if len(c) < 4 or any(x["status"] != "completed" or x["conclusion"] not in ("success", "skipped", "neutral") for x in c): return None
    return d
kandydaci = set()
for r in runs.get("workflow_runs", []):
    if r.get("conclusion") == "success" and r.get("updated_at", "") >= st.get("since", ""):
        for pr in r.get("pull_requests", []): kandydaci.add(pr["number"])
if time.time() - st.get("przeglad", 0) > 1800:
    st["przeglad"] = time.time()
    for pr in get("/pulls?state=open&per_page=100") or []: kandydaci.add(pr["number"])
zg = st.setdefault("zgloszone", {})
for n in sorted(kandydaci):
    d = gotowy(n)
    if d and zg.get(str(n)) != d["head"]["sha"]:
        zg[str(n)] = d["head"]["sha"]
        out.append("DO SCALENIA #%d (%s) %s — %s" % (n, d["head"]["sha"][:8], d["head"]["ref"], d["title"][:60]))
# 4) Na main: niezaczęty (queued) przebieg CI starszego commitu jest zbędny, gdy czeka nowszy
#    (zgoda właściciela 23.09: anulować przebiegi bez sensu). Pending = nowszy w grupie.
mr = get("/actions/runs?branch=main&event=push&per_page=8") or {}
ci = [r for r in mr.get("workflow_runs", []) if r.get("name") == "CI" and r.get("status") in ("queued", "pending")]
if len(ci) >= 2:
    ci.sort(key=lambda r: r["created_at"])
    for r in ci[:-1]:
        if r["status"] == "queued":
            try:
                urllib.request.urlopen(urllib.request.Request(B + "/actions/runs/%d/cancel" % r["id"], method="POST", headers=H), timeout=30)
                out.append("ANULOWANY zbędny CI main %s (czeka nowszy %s)" % (r["head_sha"][:8], ci[-1]["head_sha"][:8]))
            except Exception:
                pass
st["heads"] = heads; st["main"] = m; st["init"] = False
json.dump(st, open(ST, "w"))
for o in out: print(o, flush=True)
