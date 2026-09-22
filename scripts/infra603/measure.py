"""Lokalne pomiary kosztu infrastruktury; bez SQL i danych osobowych w wynikach."""
import concurrent.futures
import datetime
import json
import math
import os
import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / "storage/infra603"
OUT.mkdir(exist_ok=True)


def probe(mode, *args):
    result = subprocess.run(["php", "scripts/infra603/probe.php", mode, *map(str, args)],
                            cwd=ROOT, text=True, capture_output=True, timeout=90)
    if result.returncode:
        raise RuntimeError(f"Próba {mode} nieudana: {result.stderr[:500]}")
    return json.loads(result.stdout)


def summary(runs):
    groups = {}
    for run in runs:
        for category, values in run["sql_ms"].items():
            groups.setdefault(category, []).extend(values)
    result = {}
    for category, values in groups.items():
        values.sort()
        result[category] = {"calls": len(values), "total_ms": round(sum(values), 3),
                            "p95_ms": values[math.ceil(len(values) * .95) - 1],
                            "p99_ms": values[math.ceil(len(values) * .99) - 1]}
    return result


report = {"utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
          "code_baseline": "4c811cc7bff365fb8f86d87eabac93b7738a45cd",
          "environment": {"load_start": os.getloadavg(), "php": subprocess.check_output(["php", "-v"], text=True).splitlines()[0]},
          "runs": {}}
for mode in ["idle-worker", "operations", "media", "gc"]:
    runs = [probe(mode)]
    report["runs"][mode] = {"summary": summary(runs), "raw": runs}
    print(mode, report["runs"][mode]["summary"], flush=True)

# Każde żądanie to świeży proces PHP, pełny kernel i zapis sesji przy terminate.
# Nie obejmuje sieci, FPM/FrankenPHP ani zalogowanego ruchu.
for index in range(3):
    probe("http", index)  # Rozgrzewka poza wynikiem.
    runs = [probe("http", index) for _ in range(20)]
    report["runs"][f"http-{index}"] = {"summary": summary(runs), "raw": runs}
    print("http", index, summary(runs), flush=True)

# Naprzemienny eksperyment A/B: to samo SQL biznesowe bez / z czterema
# syntetycznymi producentami ruchu cache, blokad i pustego pollingu.
for repeat in range(3):
    for load in [False, True]:
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            futures = [pool.submit(probe, "infra-load") for _ in range(4)] if load else []
            if load:
                time.sleep(1)
            run = probe("business")
            producers = [future.result() for future in futures]
        key = f"ab-{repeat}-{'infra' if load else 'alone'}"
        report["runs"][key] = {"summary": summary([run]), "raw": [run],
                               "producers": producers, "load": os.getloadavg()}
        print(key, summary([run]), flush=True)

for size in [0, 1000, 10000, 100000]:
    setup = probe("queue-size", size)
    runs = [probe("queue-pop") for _ in range(3)]
    report["runs"][f"queue-{size}"] = {"size": setup, "summary": summary(runs), "raw": runs}
    print("queue", size, setup, summary(runs), flush=True)
probe("queue-size", 0)
report["environment"]["load_end"] = os.getloadavg()
# Kontrola dodatnia: przyrząd musi rzeczywiście widzieć pracę każdej grupy.
assert report["runs"]["idle-worker"]["summary"]["queue"]["calls"] >= 36
assert report["runs"]["operations"]["summary"]["cache"]["calls"] == 400
assert report["runs"]["operations"]["summary"]["locks"]["calls"] >= 400
assert report["runs"]["media"]["raw"][0]["details"]["ready"] == 6
assert report["runs"]["gc"]["raw"][0]["details"]["sessions_deleted"] == 2000
for index in range(3):
    assert report["runs"][f"http-{index}"]["summary"]["business"]["calls"] >= 200
    assert report["runs"][f"http-{index}"]["summary"]["sessions"]["calls"] >= 40
for size in [0, 1000, 10000, 100000]:
    assert report["runs"][f"queue-{size}"]["summary"]["queue"]["calls"] == 600
report["positive_controls"] = "PASS"
(OUT / "measurement.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
print("Wynik: storage/infra603/measurement.json", flush=True)
