#!/usr/bin/env python3
"""Pomiar kosztu licznika „Czeka na odpowiedź (N)” na /pytania (#372).

CO ROBI
  1. Tworzy WŁASNĄ, jednorazową bazę `kuking_pomiar_pytan_<czas>` na serwerze
     wskazanym przez zmienne PG* (PGHOST, PGPORT, PGUSER; hasło przez ~/.pgpass
     albo PGPASSWORD).
  2. Ładuje podzbiór schematu i dane syntetyczne: scripts/pomiar-pytan-372-dane.sql
     (200 000 wpisów, 5% pytań, rozkład odpowiedzi opisany w pliku).
  3. Każde zapytanie z scripts/pomiar-pytan-372-zapytania.sql: rozgrzewka,
     potem POWTORZEN × `EXPLAIN (ANALYZE, BUFFERS)`; drukuje medianę/min/max
     czasu wykonania i zapisuje pierwszy plan.
  4. To samo po założeniu indeksu z migracji `…_add_questions_published_index_to_posts`
     (definicja czytana z pliku migracji, nie przepisana), żeby porównać plany.
  5. Kasuje bazę (chyba że --zostaw).

Nie łączy się z bazą aplikacji: odmawia, jeśli nazwa bazy docelowej nie ma
przedrostka `kuking_pomiar_pytan_`, a SQL danych sprawdza to drugi raz.

Uruchomienie (kontener sesji, klaster lokalny, rola postgres):
  su postgres -c "python3 scripts/pomiar-pytan-372.py --wyniki /tmp/pomiar-372"
"""

import argparse
import datetime
import hashlib
import os
from pathlib import Path
import re
import statistics
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
DANE = ROOT / "scripts" / "pomiar-pytan-372-dane.sql"
ZAPYTANIA = ROOT / "scripts" / "pomiar-pytan-372-zapytania.sql"
MIGRACJA = ROOT / "database" / "migrations" / "2026_09_25_200000_add_questions_published_index_to_posts.php"
PRZEDROSTEK = "kuking_pomiar_pytan_"
POWTORZEN = 7
TAG = "tag-0"


def uuid_uzytkownika(n):
    h = hashlib.md5(f"p372:user:{n}".encode()).hexdigest()
    return f"{h[:8]}-{h[8:12]}-{h[12:16]}-{h[16:20]}-{h[20:]}"


def uuid_widza():
    return uuid_uzytkownika(0)


# Widz pomiaru blokuje konta 8001–8100, a 8101–8200 blokują jego
# (scripts/pomiar-pytan-372-dane.sql); losowe blokady go nie dotyczą.
W_BLOKADZIE = ", ".join(f"'{uuid_uzytkownika(n)}'" for n in range(8001, 8201))


def psql(baza, *args, sql=None):
    command = ["psql", "-X", "--no-password", "-v", "ON_ERROR_STOP=1", "-d", baza, *args]
    if sql is not None:
        command += ["-c", sql]
    return subprocess.check_output(command, text=True, stderr=subprocess.STDOUT, cwd=ROOT)


def zapytania():
    tekst = ZAPYTANIA.read_text(encoding="utf-8")
    wynik = []
    for blok in re.split(r"^-- nazwa: ", tekst, flags=re.M)[1:]:
        nazwa, _, reszta = blok.partition("\n")
        sql = "\n".join(l for l in reszta.splitlines() if not l.lstrip().startswith("--")).strip()
        sql = sql.rstrip(";").replace("{widz}", uuid_widza()).replace("{tag}", TAG)
        sql = sql.replace("{w_blokadzie}", W_BLOKADZIE)
        wynik.append((nazwa.strip(), sql))
    if not wynik:
        raise SystemExit("Brak zapytań w " + str(ZAPYTANIA))
    return wynik


def indeks_z_migracji():
    tekst = MIGRACJA.read_text(encoding="utf-8")
    nazwa = re.search(r"const INDEKS = '(\w+)';", tekst)
    definicja = re.search(r'const DEFINICJA = "([^"]+)";', tekst)
    if not nazwa or not definicja:
        raise SystemExit("Nie znalazłem INDEKS/DEFINICJA w " + str(MIGRACJA))
    return nazwa.group(1), f"CREATE INDEX {nazwa.group(1)} {definicja.group(1)}"


def zmierz(baza, etap, wyniki):
    podsumowanie = []
    for nazwa, sql in zapytania():
        psql(baza, "-At", sql="EXPLAIN (ANALYZE) " + sql)  # rozgrzewka buforów
        czasy = []
        pierwszy = None
        for _ in range(POWTORZEN):
            plan = psql(baza, "-At", sql="EXPLAIN (ANALYZE, BUFFERS) " + sql)
            czas = float(re.search(r"Execution Time: ([\d.]+) ms", plan).group(1))
            czasy.append(czas)
            pierwszy = pierwszy or plan
        wiersze = psql(baza, "-At", sql=f"SELECT count(*) FROM ({sql}) x" if not nazwa.startswith(("licznik", "poprawka_1", "poprawka_2", "poprawka_3")) else sql).strip()
        (wyniki / f"{etap}-{nazwa}.txt").write_text(pierwszy, encoding="utf-8")
        linia = (f"{etap:12} {nazwa:28} mediana {statistics.median(czasy):8.2f} ms"
                 f"  min {min(czasy):8.2f}  max {max(czasy):8.2f}  wynik={wiersze}")
        print(linia, flush=True)
        podsumowanie.append(linia)
    return podsumowanie


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--wyniki", help="katalog na plany (domyślnie katalog tymczasowy)")
    parser.add_argument("--zostaw", action="store_true", help="nie kasuj bazy pomiaru po zakończeniu")
    args = parser.parse_args()

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%d%H%M%S")
    baza = PRZEDROSTEK + stamp
    if not re.fullmatch(PRZEDROSTEK + r"\d{14}", baza):
        raise SystemExit("Odmowa: niepoprawna nazwa bazy pomiaru.")
    wyniki = Path(args.wyniki or tempfile.mkdtemp(prefix="pomiar-372-"))
    wyniki.mkdir(parents=True, exist_ok=True)

    print(psql("postgres", "-At", sql="SELECT version()").strip(), flush=True)
    psql("postgres", sql=f'CREATE DATABASE "{baza}"')
    print("Baza pomiaru:", baza, flush=True)
    try:
        dane = psql(baza, "-f", str(DANE))
        print(dane.strip(), flush=True)
        (wyniki / "dane.txt").write_text(dane, encoding="utf-8")
        linie = zmierz(baza, "bez-indeksu", wyniki)
        nazwa, ddl = indeks_z_migracji()
        print(ddl, flush=True)
        psql(baza, sql=ddl)
        psql(baza, sql="ANALYZE posts")
        rozmiar = psql(baza, "-At", sql=f"SELECT pg_size_pretty(pg_relation_size('{nazwa}'))").strip()
        print(f"Rozmiar indeksu {nazwa}: {rozmiar}", flush=True)
        linie += zmierz(baza, "z-indeksem", wyniki)
        linie.append(f"Rozmiar indeksu {nazwa}: {rozmiar}")
        (wyniki / "podsumowanie.txt").write_text("\n".join(linie) + "\n", encoding="utf-8")
    finally:
        if args.zostaw:
            print("Zostawiono bazę", baza, flush=True)
        else:
            psql("postgres", sql=f'DROP DATABASE "{baza}"')
            print("Usunięto bazę", baza, flush=True)
    print("Plany:", wyniki, flush=True)


if __name__ == "__main__":
    os.environ.setdefault("PGTZ", "UTC")
    main()
