"""Zachowuje wszystkie próbki, skraca zapis JSON i wylicza tabele raportu."""
import json
import pathlib

root = pathlib.Path(__file__).resolve().parents[2]
evidence = root / "docs/infra/evidence/redis-ha"
output = {}
for name in ["measurement-first", "measurement"]:
    path = evidence / (name + ".json")
    data = json.loads(path.read_text())
    output[name] = {"utc": data["utc"], "environment": data["environment"], "runs": {}}
    for key, run in data["runs"].items():
        short = {"summary": run["summary"], "seconds": sum(r["seconds"] for r in run["raw"])}
        if "size" in run:
            short["size"] = run["size"]
        if run.get("producers"):
            short["infra_qps"] = sum(sum(len(v) for v in p["sql_ms"].values()) / p["seconds"] for p in run["producers"])
        output[name]["runs"][key] = short
    encoded = json.dumps(data, ensure_ascii=False, separators=(",", ":")) + "\n"
    assert json.loads(encoded) == data
    path.write_text(encoded)
(evidence / "summary.json").write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n")
