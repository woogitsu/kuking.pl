"""Uruchamianie testu z kontroli ujemnej bez awarii od błędnych bajtów logu."""

import subprocess


def run_test(name, expected_success, command=None):
    result = subprocess.run(
        command if command is not None else ["php", "artisan", "test", "--filter=" + name, "--no-ansi"],
        text=True, encoding="utf-8", errors="replace",
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=180,
    )
    print(result.stdout, flush=True)
    if (result.returncode == 0) != expected_success:
        raise RuntimeError("Nieoczekiwany wynik testu: " + name)
    if not expected_success and "FAILED" not in result.stdout:
        raise RuntimeError("Brak dowodu niezaliczonej asercji; sama awaria procesu nie wystarczy.")
