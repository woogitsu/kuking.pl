#!/usr/bin/env python3
"""Lokalna próba #617. Tworzy własny MinIO; nie przyjmuje adresu R2 ani sekretów."""

import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import urllib.request


SERVER = "quay.io/minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e"
CLIENT = "quay.io/minio/mc@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727"
ROOT = Path(__file__).resolve().parent.parent


def run(args, *, data=None, env=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True, env=env, timeout=120)
    if result.returncode:
        # Polecenia administracyjne niosą losowe hasła. Nie wypisujemy argv ani odpowiedzi.
        raise RuntimeError("Nie udało się wykonać lokalnego kroku: " + args[0])
    return result.stdout.strip()


def main():
    if len(sys.argv) != 1:
        raise RuntimeError("Uruchom bez argumentów; próba nie obsługuje zewnętrznych bucketów.")
    if os.environ.get("DB_HOST") != "127.0.0.1" or os.environ.get("DB_PORT") != "55439":
        raise RuntimeError("Ustaw lokalną bazę na 127.0.0.1:55439.")
    if os.environ.get("DB_DATABASE") != "kuking_flota_gpt-dr-zdjecia":
        raise RuntimeError("Wybierz własną bazę kuking_flota_gpt-dr-zdjecia.")
    name = "kuking-dr-zdjecia-" + secrets.token_hex(6)
    credentials = {role: {"key": secrets.token_hex(10), "secret": secrets.token_hex(24)}
                   for role in ("admin", "app", "writer", "reader")}
    admin = credentials["admin"]
    env = os.environ.copy()
    env.update(MINIO_ROOT_USER=admin["key"], MINIO_ROOT_PASSWORD=admin["secret"])
    container = None
    try:
        container = run(["docker", "run", "--detach", "--rm", "--name", name,
                         "--label", "kuking.proba=dr-zdjecia", "--publish", "127.0.0.1::9000",
                         "--env", "MINIO_ROOT_USER", "--env", "MINIO_ROOT_PASSWORD",
                         SERVER, "server", "/data"], env=env)
        port = run(["docker", "port", container, "9000/tcp"]).split(":")[-1]
        endpoint = "http://127.0.0.1:" + port
        for _ in range(60):
            try:
                with urllib.request.urlopen(endpoint + "/minio/health/ready", timeout=1) as response:
                    if response.status == 200:
                        break
            except OSError:
                time.sleep(0.5)
        else:
            raise RuntimeError("Własny MinIO nie zgłosił gotowości.")
        env["MC_HOST_dr"] = "http://" + admin["key"] + ":" + admin["secret"] + "@127.0.0.1:9000"

        def mc(*args, data=None):
            return run(["docker", "run", "--rm", "--interactive", "--network", "container:" + container,
                        "--env", "MC_HOST_dr", CLIENT, *args], data=data, env=env)

        for bucket in ("dr-originals", "dr-variants", "dr-control"):
            mc("mb", "dr/" + bucket)
        mc("mb", "--with-lock", "dr/dr-backup")
        # Konfigurację retencji sprawdza próba, nie samo istnienie bucketu.
        mc("retention", "set", "--default", "COMPLIANCE", "1d", "dr/dr-backup")

        def policy(buckets, actions):
            return {"Version": "2012-10-17", "Statement": [{"Effect": "Allow", "Action": actions,
                    "Resource": [resource for bucket in buckets
                                 for resource in ("arn:aws:s3:::" + bucket, "arn:aws:s3:::" + bucket + "/*")]}]}

        policies = {
            "app": policy(["dr-originals", "dr-variants"], ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:ListBucket"]),
            # Prawo DELETE jest celowe: odmowa ma pochodzić z retencji, nie z braku tego prawa.
            "writer": policy(["dr-backup", "dr-control"], ["s3:GetObject", "s3:GetObjectVersion", "s3:PutObject",
                                                          "s3:DeleteObject", "s3:DeleteObjectVersion", "s3:ListBucket"]),
            "reader": policy(["dr-backup"], ["s3:GetObject", "s3:GetObjectVersion", "s3:ListBucket", "s3:ListBucketVersions"]),
        }
        for role, document in policies.items():
            mc("admin", "user", "add", "dr", credentials[role]["key"], credentials[role]["secret"])
            mc("admin", "policy", "create", "dr", role, "/dev/stdin", data=json.dumps(document))
            mc("admin", "policy", "attach", "dr", role, "--user", credentials[role]["key"])
        env.update(DR_LOCAL_ENDPOINT=endpoint, DR_LOCAL_KEYS=json.dumps(credentials))
        result = subprocess.run(["php", "artisan", "test", "tests/Dr/DrZdjecMinioTest.php", "--colors=never"],
                                cwd=ROOT, env=env, text=True, capture_output=True, timeout=180)
        output = result.stdout + result.stderr
        for credential in credentials.values():
            for value in credential.values():
                output = output.replace(value, "[ukryto]")
        print(output, end="")
        return result.returncode
    finally:
        if container:
            run(["docker", "stop", "--time", "3", container])
            for _ in range(20):
                remaining = run(["docker", "ps", "--all", "--quiet", "--filter", "name=^/" + name + "$"])
                if not remaining:
                    break
                time.sleep(0.2)
            if remaining:
                raise RuntimeError("Sprawdź sprzątanie własnego kontenera " + name)
            print("SPRZATANIE: własny kontener usunięty; brak wolumenów i zmian produkcji.")


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as error:
        # TimeoutExpired może zawierać argv z hasłem do lokalnego MinIO.
        print("PRZERWANO: sprawdź lokalny Docker/runtime (" + type(error).__name__ + ").", file=sys.stderr)
        sys.exit(2)
