"""Jawny model budżetu, nie rachunek ani pomiar przyszłego klastra."""
import json
import pathlib

root = pathlib.Path(__file__).resolve().parents[2]
evidence = root / "docs/infra/evidence/redis-ha"
metrics = {x["measurement"]: x for x in json.loads((evidence / "pgmetrics.json").read_text())["result"]["metrics"]}
fx = json.loads((evidence / "fx.json").read_text())["usd_pln"]
disk = metrics["DISK_USAGE_GB"]["current"]


def cost(ram, cpu, volume, egress=0):
    usd = 10 * ram + 20 * cpu + .15 * volume + .05 * egress
    return {"usd": round(usd, 4), "pln_net": round(usd * fx, 2)}


result = {"usd_pln": fx, "disk_proxy_gb": disk, "assumptions": {
    "ha_nodes": {"postgres": 3, "etcd": 3, "haproxy": 3},
    "per_node_low": {"postgres": {"ram_gb": .25, "vcpu": .02}, "etcd": {"ram_gb": .05, "vcpu": .005, "disk_gb": .01}, "haproxy": {"ram_gb": .025, "vcpu": .002}},
    "per_node_high": {"postgres": {"ram_gb": .5, "vcpu": .05}, "etcd": {"ram_gb": .1, "vcpu": .01, "disk_gb": .05}, "haproxy": {"ram_gb": .05, "vcpu": .005}},
    "warning": "Rozmiar to metryka usługi, nie pg_database_size. Zużycie HA założone, nie zmierzone; brak WAL, backupów i egress poza podanym dyskiem."},
    "current_pg_month_projection": cost(metrics["MEMORY_USAGE_GB"]["average"], metrics["CPU_USAGE"]["average"], metrics["DISK_USAGE_GB"]["average"]),
    "ha": {}, "redis": {}}
for multiplier in [1, 10]:
    result["ha"][str(multiplier)] = {
        "low": cost(.975, .081, 3 * disk * multiplier + .03),
        "high": cost(1.95, .195, 3 * disk * multiplier + .15)}
result["ha"]["10_plus_heavier_workload"] = {
    "assumption": "3 PG po 1 GB i 0.1 vCPU, etcd i HAProxy jak wariant górny; nie 10x ruchu z pomiaru",
    "cost": cost(3.45, .345, 30 * disk + .15)}
result["redis"] = {
    "small_assumed_025GB_001cpu_1GBdisk": cost(.25, .01, 1),
    "larger_assumed_1GB_005cpu_2GBdisk": cost(1, .05, 2)}
print(json.dumps(result, ensure_ascii=False, indent=2))
