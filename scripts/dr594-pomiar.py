#!/usr/bin/env python3
"""Lokalny przyrząd DR: prawdziwe skrypty kopii/restore, zegar monotoniczny, dowody.

Nie łączy się z produkcją. Uruchom z katalogu runtime według runbooka DR594.
"""

import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time


ROOT = Path(__file__).resolve().parent.parent
SOURCE = "kuking_flota_gpt_dr_baza_source"
STAMP = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
TARGET = "proba_odtworzenia_gpt_dr_baza_" + STAMP.lower()
OUT = Path("/home/mateusz/flota/gpt-dr-baza-artifacts") / STAMP
ENV = os.environ.copy()
EXPECTED = {"DB_HOST": "127.0.0.1", "DB_PORT": "55439", "DB_DATABASE": SOURCE,
            "DB_USERNAME": "kuking", "PGHOST": "127.0.0.1", "PGPORT": "55439", "PGUSER": "kuking"}
if any(ENV.get(key) != value for key, value in EXPECTED.items()):
    raise SystemExit("Ustaw jawne parametry własnej bazy z runbooka DR594; odmowa połączenia.")
if ENV.get("APP_ENV") != "local" or ENV.get("DB_URL"):
    raise SystemExit("Ustaw APP_ENV=local i usuń DB_URL przed pomiarem.")
ENV.update(PGTZ="UTC", PGOPTIONS="-c timezone=UTC", PGCONNECT_TIMEOUT="10")
for key in ("PGSERVICE", "PGSERVICEFILE", "PGHOSTADDR"):
    ENV.pop(key, None)
SOURCE_DSN = f"postgresql://kuking@127.0.0.1:55439/{SOURCE}"
SERVER_DSN = "postgresql://kuking@127.0.0.1:55439/postgres"


def sql(database, query):
    return subprocess.check_output(
        ["psql", "-X", "--no-password", "-v", "ON_ERROR_STOP=1", "-At", "-d", database, "-c", query],
        env=ENV, text=True, cwd=ROOT,
    ).strip()


def run(label, command):
    start = time.monotonic()
    marks = {}
    with (OUT / (label + ".txt")).open("w", encoding="utf-8") as log:
        with subprocess.Popen(command, cwd=ROOT, env=ENV, stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT, text=True, bufsize=1) as process:
            for line in process.stdout:
                elapsed = time.monotonic() - start
                line = re.sub(r"\x1b\[[0-9;]*m", "", line.rstrip())
                log.write(f"{elapsed:.6f}\t{line}\n")
                log.flush()
                print(line, flush=True)
                for key, marker in [("dump_start", "[kopia] pg_dump z "),
                                    ("dump_end", "[kopia] ✓ zrzut:"),
                                    ("restore_start", "[proba] pg_restore…"),
                                    ("restore_end", "pg_restore bez błędu,"),
                                    ("verification_end", "PRÓBA ODTWORZENIA ZALICZONA.")]:
                    if marker in line:
                        marks[key] = elapsed
            code = process.wait()
    result = {"seconds": time.monotonic() - start, "exit_code": code, "marks": marks}
    (OUT / (label + ".json")).write_text(json.dumps(result, indent=2) + "\n")
    if code:
        raise RuntimeError(f"Etap {label}: kod {code}. Zachowaj dowody w {OUT}; nie ogłaszaj sukcesu.")
    return result


def content_manifest(database):
    """Strumień wszystkich kolumn każdego wiersza; kolejność po pełnym wierszu JSONB.

    Koszt sortowania i odczytu jest częścią dodatkowej weryfikacji, nie restore.
    SHA-256 liczymy w strumieniu, więc nie trzymamy całej bazy w pamięci Pythona.
    """
    tables = sql(database, "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename").splitlines()
    if len(tables) < 40:
        raise RuntimeError("Manifest nie widzi pełnego schematu. Przerwij próbę.")
    result = {}
    for table in tables:
        quoted = '"' + table.replace('"', '""') + '"'
        query = f"COPY (SELECT to_jsonb(t)::text FROM public.{quoted} t ORDER BY to_jsonb(t)::text COLLATE \"C\") TO STDOUT"
        digest = hashlib.sha256()
        size = 0
        with subprocess.Popen(["psql", "-X", "--no-password", "-v", "ON_ERROR_STOP=1", "-q", "-d", database, "-c", query],
                              env=ENV, stdout=subprocess.PIPE) as process:
            while block := process.stdout.read(1024 * 1024):
                digest.update(block)
                size += len(block)
            if process.wait():
                raise RuntimeError(f"Nie udało się odczytać tabeli {table}.")
        result[table] = {"bytes": size, "sha256": digest.hexdigest()}
    return result


def main():
    os.umask(0o077)
    OUT.mkdir(parents=True, mode=0o700)
    (OUT / "backup").mkdir(mode=0o700)
    identity = sql(SOURCE, "SELECT current_database()||'|'||current_user||'|'||host(inet_server_addr())||'|'||inet_server_port()")
    if identity != SOURCE + "|kuking|127.0.0.1|55439":
        raise RuntimeError("Serwer nie potwierdził tożsamości własnej bazy.")
    receipt = {"date_utc": STAMP, "source": SOURCE, "target": TARGET, "identity": identity,
               "postgres": sql(SOURCE, "SELECT version()"),
               "database_bytes": int(sql(SOURCE, "SELECT pg_database_size(current_database())")),
               "load_before": list(os.getloadavg()), "scope": "wyłącznie lokalne dane syntetyczne"}
    # Klucz tylko dla tego ćwiczenia, nie klucz produkcji. Nie wchodzi do dowodów w Git.
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:3072", "-nodes", "-days", "2",
                    "-subj", "/CN=Kuking DR594 lokalna proba", "-keyout", str(OUT / "private.pem"),
                    "-out", str(OUT / "public.pem")], env=ENV, check=True, stdout=subprocess.DEVNULL,
                   stderr=subprocess.DEVNULL)
    receipt["backup"] = run("backup", ["bash", "scripts/kopia-lokalna.sh", "--zrodlo", SOURCE_DSN,
                              "--katalog", str(OUT / "backup"), "--klucz-publiczny", str(OUT / "public.pem")])
    dump_marks = receipt["backup"]["marks"]
    receipt["dump_seconds"] = dump_marks["dump_end"] - dump_marks["dump_start"]
    archives = list((OUT / "backup").glob("*.dump.cms"))
    if len(archives) != 1:
        raise RuntimeError("Oczekiwano dokładnie jednego szyfrogramu. Sprawdź katalog kopii.")
    archive = archives[0]
    receipt["archive_bytes"] = archive.stat().st_size
    receipt["restore"] = run("restore", ["bash", "scripts/proba-odtworzenia.sh", "--zrzut", str(archive),
                               "--klucz", str(OUT / "private.pem"), "--serwer", SERVER_DSN,
                               "--baza", TARGET, "--zrodlo", SOURCE_DSN, "--scisle", "--zostaw"])
    marks = receipt["restore"]["marks"]
    receipt["restore_seconds"] = marks["restore_end"] - marks["restore_start"]
    receipt["verification_seconds"] = marks["verification_end"] - marks["restore_end"]
    start = time.monotonic()
    source_manifest = content_manifest(SOURCE)
    target_manifest = content_manifest(TARGET)
    for name, value in [("source-manifest", source_manifest), ("target-manifest", target_manifest)]:
        (OUT / (name + ".json")).write_text(json.dumps(value, indent=2) + "\n")
    receipt["content_verification_seconds"] = time.monotonic() - start
    receipt["content_equal"] = source_manifest == target_manifest
    receipt["tables_compared"] = len(source_manifest)
    receipt["load_after"] = list(os.getloadavg())
    (OUT / "receipt.json").write_text(json.dumps(receipt, indent=2, ensure_ascii=False) + "\n")
    if not receipt["content_equal"]:
        raise RuntimeError("Treść tabel różni się. Zachowaj obie bazy i manifesty; nie zaliczaj próby.")
    print(f"DOWODY: {OUT}\nTREŚĆ WSZYSTKICH TABEL ZGODNA. Baza próbna zostaje do kontroli ujemnej.")


if __name__ == "__main__":
    main()
