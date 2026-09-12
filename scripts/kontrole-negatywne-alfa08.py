#!/usr/bin/env python3
"""Kontrole regresji Alfa 0.8, wyłącznie w izolowanym zadaniu testowym CI."""

import hashlib
import os
from pathlib import Path
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
os.chdir(ROOT)
if os.environ.get("CI") != "true":
    raise SystemExit("Uruchamiaj wyłącznie w izolowanym zadaniu testowym CI.")

CONTROLLER = "app/Http/Controllers/CollectionController.php"
LAYOUT = "resources/views/components/layout.blade.php"
CSS = "resources/css/app.css"
COLLECTION_TEST = "WyborZeszytuMaWalidacjeTest"
COMPOSER_TEST = "KafelDodawaniaPrzyDuzymTekscieTest"


def digest(path):
    return hashlib.md5(path.read_bytes()).hexdigest()


def run_test(name, expected_success):
    result = subprocess.run(
        ["php", "artisan", "test", "--filter=" + name, "--no-ansi"],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=180,
    )
    print(result.stdout, flush=True)
    if (result.returncode == 0) != expected_success:
        raise RuntimeError("Nieoczekiwany wynik testu: " + name)
    if not expected_success and "FAILED" not in result.stdout:
        raise RuntimeError("Brak dowodu niezaliczonej asercji; sama awaria procesu nie wystarczy.")


def replace_once(source, old, new):
    if source.count(old) != 1:
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego miejsca mutacji.")
    return source.replace(old, new, 1)


def remove_notice(source):
    start = source.index("@error('collection_id')")
    end = source.index("@enderror", start) + len("@enderror")
    return source[:start] + source[end:]


def smaller_help(source):
    start = source.index(".composer-help {")
    end = source.index("}", start)
    block = replace_once(source[start:end], "var(--text-body)", "var(--text-help)")
    return source[:start] + block + source[end:]


checks = [
    ("Format UUID", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
    ("Własność zeszytu", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey())", "Rule::exists('collections', 'id')")),
    ("Komunikat po powrocie", LAYOUT, COLLECTION_TEST, remove_notice),
    ("Podpis co najmniej 18 px", CSS, COMPOSER_TEST, smaller_help),
]

run_test(COLLECTION_TEST, True)
run_test(COMPOSER_TEST, True)
with tempfile.TemporaryDirectory(prefix="kuking-kontrola-") as directory:
    backup = Path(directory) / "oryginal"
    for label, filename, test, mutate in checks:
        path = ROOT / filename
        subprocess.run(["cp", str(path), str(backup)], check=True)
        before = digest(path)
        try:
            path.write_text(mutate(path.read_text()))
            changed = digest(path)
            if changed == before:
                raise RuntimeError("Mutacja nie zmieniła źródła.")
            print(f"{label}: przed={before}, mutacja={changed}", flush=True)
            run_test(test, False)
        finally:
            subprocess.run(["cp", str(backup), str(path)], check=True)
            restored = digest(path)
            print(f"{label}: po przywróceniu={restored}", flush=True)
            if restored != before:
                raise RuntimeError("Przywrócone źródło różni się od oryginału.")
        run_test(test, True)
print("Cztery kontrole negatywne wykryły regresje; źródła przywrócone.")
