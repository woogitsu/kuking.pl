#!/usr/bin/env python3
"""Przenosi kontrole negatywne gałęzi ze starego monolitu do katalogu (po #1478).

Uruchamiaj w katalogu repo (worktree gałęzi PR-a) W TRAKCIE `git merge origin/main`,
gdy main ma już `scripts/kontrole_negatywne/` i scalenie dało konflikt
w `scripts/kontrole-negatywne-alfa08.py`:

    git merge origin/main            # konflikt w starym pliku
    python3 przenies_kontrole.py     # nowe kNN_*.py + punkt wejścia z main, git add

Porównuje starą wersję pliku z gałęzi (domyślnie HEAD) z bazą scalenia
(domyślnie `git merge-base HEAD MERGE_HEAD`). Automatycznie przenosi TYLKO:
  - nowe krotki w `checks`  -> `Kontrola(...)` w nowym pliku kNN_<obszar>.py,
  - nowe `run_test(X, True)` -> `KONTROLE_DODATNIE`,
  - nowe stałe/funkcje/importy, od których te wpisy zależą (także niezmienione
    stałe/funkcje istniejące, np. LAYOUT — kopiowane do nowego pliku).
Wszystko inne (zmiana/usunięcie istniejącej kontroli, zmiana wartości istniejącej
stałej = zmiana kotwicy, zmiana funkcji, zmiany poza stałymi/funkcjami/checks/
run_test) -> kod 2, opis, NIC nie jest zapisywane.

Kody wyjścia: 0 przeniesione albo nic do przeniesienia; 2 wymaga ręcznej pracy
(nic nie zapisano); 1 błąd środowiska/argumentów; 3 wygenerowany plik nie przeszedł
walidacji (`--lista`/preflight) — pliki wycofane, konflikt przywrócony.

Opcje:
  --galaz REF      wersja gałęzi starego pliku (domyślnie HEAD)
  --main REF       main z katalogiem (domyślnie MERGE_HEAD, inaczej origin/main)
  --baza REF       baza porównania (domyślnie merge-base galaz main)
  --jeden-plik     wszystkie kontrole gałęzi do jednego pliku (domyślnie grupy
                   po wspólnym teście/pliku mutacji)
  --obszar SLUG    nazwa obszaru (tylko z --jeden-plik)
  --oczekuj 'NAZWA=REGEX'  (powtarzalne) — gdy Kontrola na main ma pole `oczekuj`
  --sucho          tylko pokaż, co by powstało
  --bez-git-add    nie dodawaj plików do indeksu
"""

import argparse
import ast
import io
import os
import re
import subprocess
import sys
import tokenize
import unicodedata
from pathlib import Path

STARY = "scripts/kontrole-negatywne-alfa08.py"
KATALOG = "scripts/kontrole_negatywne"
STRAZNIK = "StraznikTekstuMaKontroleDodatniaTest"
# Nazwy mechanizmu starego pliku, które w katalogu daje _narzedzia (albo są zbędne).
MECHANIZM_STAREGO = {"ROOT", "odmow", "digest", "run_test", "replace_once", "checks"}


class Reczne(Exception):
    """Czegoś nie da się przenieść automatycznie."""


def git(*a, check=True):
    r = subprocess.run(["git", *a], text=True, capture_output=True)
    if check and r.returncode:
        raise SystemExit(f"git {' '.join(a)}: {r.stderr.strip()}")
    return r


def pokaz(ref, path):
    r = git("show", f"{ref}:{path}", check=False)
    return r.stdout if r.returncode == 0 else None


# ------------------------------------------------------------------ parsowanie

def komentarze_nad(linie, lineno, dolna_granica=0):
    """Wiersze komentarza tuż nad `lineno` (1-based), bez pustych przerw."""
    i = lineno - 2
    out = []
    while i >= dolna_granica and linie[i].strip().startswith("#"):
        out.insert(0, linie[i])
        i -= 1
    return out


def segment(linie, node):
    """Tekst węzła od początku jego pierwszego wiersza do końca (pełne wiersze)."""
    return "\n".join(linie[node.lineno - 1:node.end_lineno])


def parsuj_stary(tekst, skad):
    try:
        drzewo = ast.parse(tekst)
    except SyntaxError as e:
        raise Reczne(f"{skad}: stary plik się nie parsuje ({e}) — np. znaczniki konfliktu.")
    linie = tekst.splitlines()
    wynik = {"stale": {}, "funkcje": {}, "importy": {}, "checks": None, "run_test": [], "inne": [], "linie": linie}
    for node in drzewo.body:
        kom = komentarze_nad(linie, node.lineno)
        if isinstance(node, (ast.Import, ast.ImportFrom)):
            for alias in node.names:
                wynik["importy"][(alias.asname or alias.name).split(".")[0]] = segment(linie, node)
        elif isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            nazwa = node.targets[0].id
            if nazwa == "checks":
                if not isinstance(node.value, ast.List):
                    raise Reczne(f"{skad}: `checks` nie jest listą literalną.")
                wynik["checks"] = node
            else:
                wynik["stale"][nazwa] = {"node": node, "src": "\n".join(kom + [segment(linie, node)]),
                                         "kod": segment(linie, node), "dump": ast.dump(node.value),
                                         "od": node.lineno - len(kom), "do": node.end_lineno}
        elif isinstance(node, ast.FunctionDef):
            start = node.decorator_list[0].lineno if node.decorator_list else node.lineno
            kom = komentarze_nad(linie, start)
            kod = "\n".join(linie[start - 1:node.end_lineno])
            wynik["funkcje"][node.name] = {"node": node, "src": "\n".join(kom + [kod]), "dump": ast.dump(node)}
        elif (isinstance(node, ast.Expr) and isinstance(node.value, ast.Call)
              and isinstance(node.value.func, ast.Name) and node.value.func.id == "run_test"):
            args = node.value.args
            if len(args) != 2 or not (isinstance(args[1], ast.Constant) and args[1].value is True):
                raise Reczne(f"{skad}:{node.lineno}: nietypowe wywołanie run_test: {segment(linie, node).strip()}")
            wynik["run_test"].append({"src": ast.get_source_segment(tekst, args[0]), "dump": ast.dump(args[0]),
                                      "node": args[0]})
        else:
            wynik["inne"].append(ast.dump(node))
    if wynik["checks"] is None:
        raise Reczne(f"{skad}: brak `checks = [...]` — to nie jest stary monolit "
                     "(np. gałąź już scalona z katalogiem albo zepsute scalenie „obie strony”). "
                     "Podaj --galaz/--baza z commitami sprzed scalenia z #1478.")
    wpisy = []
    poprzedni_koniec = wynik["checks"].lineno  # wiersz `checks = [`
    for el in wynik["checks"].value.elts:
        if not (isinstance(el, ast.Tuple) and el.elts and isinstance(el.elts[0], ast.Constant)
                and isinstance(el.elts[0].value, str)):
            raise Reczne(f"{skad}:{el.lineno}: wpis w `checks` nie jest krotką z nazwą w cudzysłowie.")
        kom = komentarze_nad(linie, el.lineno, poprzedni_koniec)
        tekst_el = ast.get_source_segment(tekst, el)
        if not (tekst_el.startswith("(") and tekst_el.endswith(")")):
            raise Reczne(f"{skad}:{el.lineno}: krotka bez nawiasów — nietypowy wpis.")
        wpisy.append({"nazwa": el.elts[0].value, "node": el, "src": tekst_el, "kom": kom,
                      "dump": ast.dump(el), "wciecie": el.col_offset})
        poprzedni_koniec = el.end_lineno
    wynik["wpisy"] = wpisy
    return wynik


def nazwy_w(node):
    return {n.id for n in ast.walk(node) if isinstance(n, ast.Name)}


def wartosc(stary, node):
    """Wartość literalna argumentu (stała po nazwie) — do grupowania."""
    if isinstance(node, ast.Name) and node.id in stary["stale"]:
        node = stary["stale"][node.id]["node"].value
    try:
        return ast.literal_eval(node)
    except Exception:
        return ast.dump(node)


# ------------------------------------------------------------------ katalog main

def katalog_main(main):
    lista = git("ls-tree", "--name-only", f"{main}:{KATALOG}", check=False)
    if lista.returncode:
        raise SystemExit(f"{main} nie ma {KATALOG}/ — przenoszenie ma sens dopiero po scaleniu #1478.")
    return lista.stdout.split()


def pola_kontroli(narzedzia_src):
    for node in ast.parse(narzedzia_src).body:
        if isinstance(node, ast.ClassDef) and node.name == "Kontrola":
            return [(s.target.id, s.value is not None) for s in node.body
                    if isinstance(s, ast.AnnAssign) and isinstance(s.target, ast.Name)]
    raise SystemExit("_narzedzia.py na main nie ma klasy Kontrola.")


def nazwy_narzedzi(narzedzia_src):
    out = set()
    for node in ast.parse(narzedzia_src).body:
        if isinstance(node, (ast.FunctionDef, ast.ClassDef)):
            out.add(node.name)
        elif isinstance(node, ast.Assign):
            out |= {t.id for t in node.targets if isinstance(t, ast.Name)}
    return out


def etykiety_w_katalogu(root):
    """{nazwa kontroli: plik} z BIEŻĄCEGO drzewa (main + to, co już przeniesiono)."""
    out = {}
    for p in sorted((root / KATALOG).glob("*.py")):
        if p.name.startswith("_"):
            continue
        try:
            drzewo = ast.parse(p.read_text(encoding="utf-8"))
        except SyntaxError:
            continue
        for n in ast.walk(drzewo):
            if (isinstance(n, ast.Call) and isinstance(n.func, ast.Name) and n.func.id == "Kontrola"
                    and n.args and isinstance(n.args[0], ast.Constant)):
                out[n.args[0].value] = p.name
    return out


def gdzie_stala(root, nazwa):
    trafienia = []
    for p in sorted((root / KATALOG).glob("*.py")):
        for i, l in enumerate(p.read_text(encoding="utf-8").splitlines(), 1):
            if re.match(rf"{re.escape(nazwa)}\s*=", l):
                trafienia.append(f"{KATALOG}/{p.name}:{i}")
    return trafienia


# ------------------------------------------------------------------ generowanie

def slug(tekst, maks_slow=5):
    t = unicodedata.normalize("NFKD", tekst.replace("ł", "l").replace("Ł", "L"))
    t = "".join(c for c in t if not unicodedata.combining(c))
    t = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", t)          # CamelCase -> snake
    t = re.sub(r"[^A-Za-z0-9]+", "_", t).strip("_").lower()
    t = re.sub(r"^test_", "", t)
    t = re.sub(r"_test$", "", t)
    slowa = [s for s in t.split("_") if s][:maks_slow]
    return "_".join(slowa) or "kontrola"


def bez_ryzyka_wciecia(src):
    """True, gdy żaden token napisu nie przechodzi przez koniec wiersza."""
    try:
        for tok in tokenize.generate_tokens(io.StringIO(src).readline):
            if tok.type == tokenize.STRING and tok.start[0] != tok.end[0]:
                return False
    except (tokenize.TokenError, IndentationError):
        return False
    return True


def jako_kontrola(wpis, oczekuj):
    src = wpis["src"]
    wnetrze = src[1:-1].rstrip()
    if wnetrze.endswith(","):
        wnetrze = wnetrze[:-1]
    linie = wnetrze.split("\n")
    if len(linie) > 1 and bez_ryzyka_wciecia(src):
        # Kontynuacje wyrównane jak w plikach k01–k37 (4 spacje + "Kontrola(").
        stare = wpis["wciecie"] + 1
        linie = [linie[0]] + [" " * 13 + l[stare:] if l[:stare].strip() == "" else l for l in linie[1:]]
    tekst = "\n".join(linie)
    if oczekuj is not None:
        tekst += f",\n             oczekuj={oczekuj!r}"
    kom = "".join("    " + k.strip() + "\n" for k in wpis["kom"])
    return f"{kom}    Kontrola({tekst}),"


def zaleznosci(start, galaz, narzedzia):
    """Domknięcie nazw (stałe, funkcje, importy) potrzebnych do `start`."""
    potrzebne, importy, z_narzedzi = [], set(), set()
    kolejka = list(start)
    widziane = set()
    while kolejka:
        n = kolejka.pop()
        if n in widziane:
            continue
        widziane.add(n)
        if n in ("Kontrola",):
            continue
        if n in MECHANIZM_STAREGO or (n in narzedzia and n not in galaz["stale"] and n not in galaz["funkcje"]):
            if n in narzedzia:
                z_narzedzi.add(n)
            elif n != "checks":
                raise Reczne(f"wpis używa `{n}` ze starego mechanizmu, którego _narzedzia.py nie ma.")
            continue
        if n in galaz["stale"]:
            potrzebne.append(n)
            kolejka += nazwy_w(galaz["stale"][n]["node"].value)
        elif n in galaz["funkcje"]:
            potrzebne.append(n)
            kolejka += nazwy_w(galaz["funkcje"][n]["node"])
        elif n in galaz["importy"]:
            importy.add(galaz["importy"][n])
    return potrzebne, importy, z_narzedzi


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--galaz", default="HEAD")
    ap.add_argument("--main")
    ap.add_argument("--baza")
    ap.add_argument("--jeden-plik", action="store_true")
    ap.add_argument("--obszar")
    ap.add_argument("--oczekuj", action="append", default=[])
    ap.add_argument("--nazwa", help="nazwa gałęzi/PR-a do docstringu (domyślnie z --galaz)")
    ap.add_argument("--sucho", action="store_true")
    ap.add_argument("--bez-git-add", action="store_true")
    a = ap.parse_args()

    root = Path(git("rev-parse", "--show-toplevel").stdout.strip())
    os.chdir(root)
    w_scaleniu = git("rev-parse", "-q", "--verify", "MERGE_HEAD", check=False).returncode == 0
    main_ref = a.main or ("MERGE_HEAD" if w_scaleniu else "origin/main")
    katalog_main(main_ref)
    baza = a.baza or git("merge-base", a.galaz, main_ref).stdout.strip()
    narzedzia_src = pokaz(main_ref, f"{KATALOG}/_narzedzia.py")
    pola = pola_kontroli(narzedzia_src)
    narzedzia = nazwy_narzedzi(narzedzia_src)
    oczekuj_wymagane = any(n == "oczekuj" for n, _ in pola)
    oczekuj = dict(x.split("=", 1) for x in a.oczekuj)

    try:
        tekst_galezi = pokaz(a.galaz, STARY)
        tekst_bazy = pokaz(baza, STARY)
        if tekst_galezi is None or tekst_bazy is None:
            raise Reczne(f"brak {STARY} w {a.galaz if tekst_galezi is None else baza}.")
        if tekst_galezi == tekst_bazy:
            print("Gałąź nie zmienia starego pliku względem bazy — weź punkt wejścia z main.")
            nowe_wpisy, galaz = [], None
        else:
            galaz = parsuj_stary(tekst_galezi, f"{a.galaz}:{STARY}")
            b = parsuj_stary(tekst_bazy, f"{baza[:9]}:{STARY}")
            problemy = []
            tylko_kotwice = False
            wb = {w["nazwa"]: w for w in b["wpisy"]}
            wg = {w["nazwa"]: w for w in galaz["wpisy"]}
            for n in wb:
                if n not in wg:
                    problemy.append(f"usunięta albo przemianowana kontrola „{n}”")
                elif wb[n]["dump"] != wg[n]["dump"]:
                    problemy.append(f"zmieniona istniejąca kontrola „{n}” — zmień ją w pliku katalogu "
                                    f"(`grep -rn '{n}' {KATALOG}/`)")
            for n, s in b["stale"].items():
                if n not in galaz["stale"]:
                    problemy.append(f"usunięta stała {n}")
                elif s["dump"] != galaz["stale"][n]["dump"]:
                    gdzie = gdzie_stala(root, n)
                    if not gdzie:
                        # stała mogła zniknąć przy przenosinach (np. wpisana w lambdę) — pokaż kontrole
                        juz_kat = etykiety_w_katalogu(root)
                        uzywa = [w["nazwa"] for w in b["wpisy"] if n in nazwy_w(w["node"])
                                 or any(n in nazwy_w(b["funkcje"][f]["node"]) for f in nazwy_w(w["node"]) if f in b["funkcje"])
                                 or any(n in nazwy_w(b["stale"][c]["node"].value) for c in nazwy_w(w["node"]) if c in b["stale"])]
                        gdzie = [f"brak stałej; używają jej kontrole: "
                                 + ", ".join(f"„{u}” ({KATALOG}/{juz_kat.get(u, '?')})" for u in uzywa)]
                    problemy.append(f"zmieniona wartość istniejącej stałej {n} (kotwica?) — w katalogu: "
                                    f"{', '.join(gdzie)}; nowa wersja z gałęzi:\n      "
                                    + galaz["stale"][n]["kod"].replace("\n", "\n      "))
                    tylko_kotwice = True
            for n, f in b["funkcje"].items():
                if n not in galaz["funkcje"]:
                    problemy.append(f"usunięta funkcja {n}")
                elif f["dump"] != galaz["funkcje"][n]["dump"] and n not in MECHANIZM_STAREGO:
                    problemy.append(f"zmieniona funkcja {n} — zmień ją w pliku katalogu, gdzie teraz mieszka")
                elif f["dump"] != galaz["funkcje"][n]["dump"]:
                    problemy.append(f"zmieniony mechanizm starego pliku ({n}) — przenieś do _narzedzia.py ręcznie")
            rb = [r["dump"] for r in b["run_test"]]
            rg = [r["dump"] for r in galaz["run_test"]]
            for r in b["run_test"]:
                if r["dump"] not in rg:
                    problemy.append(f"usunięta kontrola dodatnia run_test({r['src']}, True)")
            if sorted(b["inne"]) != sorted(galaz["inne"]):
                problemy.append("zmiany poza stałymi/funkcjami/`checks`/`run_test` (np. pętla, importy, "
                                "docstring mechanizmu) — przenieś do _narzedzia.py ręcznie")
            nowe_imp = set(galaz["importy"]) - set(b["importy"])
            if problemy:
                tekst = "\n".join("  - " + p for p in problemy)
                if tylko_kotwice:
                    # Katalog main mógł już dostać tę samą poprawkę kotwicy (np. #1528 vs k27):
                    # preflight katalogu na kodzie PO scaleniu mówi, czy jest co poprawiać.
                    r = subprocess.run([sys.executable, "-c",
                                        "import sys; sys.path.insert(0, 'scripts'); sys.dont_write_bytecode=True\n"
                                        "from kontrole_negatywne import _narzedzia; _narzedzia.lista()"],
                                       text=True, capture_output=True)
                    tekst += ("\n  preflight katalogu main na drzewie scalenia: "
                              + ("PRZECHODZI — kotwica w katalogu już pasuje; zmiana gałęzi jest prawdopodobnie "
                                 "zbędna (sprawdź i weź punkt wejścia z main)" if r.returncode == 0
                                 else "NIE przechodzi: " + (r.stderr.strip().splitlines() or ["?"])[-1]))
                raise Reczne(tekst)

            juz = etykiety_w_katalogu(root)
            nowe_wpisy = []
            for w in galaz["wpisy"]:
                if w["nazwa"] in wb:
                    continue
                if w["nazwa"] in juz:
                    print(f"pomijam „{w['nazwa']}” — już jest w katalogu ({juz[w['nazwa']]}).")
                    continue
                if len(w["node"].elts) != 4:
                    if len(w["node"].elts) == 5 and oczekuj_wymagane:
                        pass
                    else:
                        raise Reczne(f"„{w['nazwa']}”: krotka ma {len(w['node'].elts)} elementów, "
                                     f"Kontrola na main ma pola {[n for n, _ in pola]}.")
                nowe_wpisy.append(w)
            nowe_rt = [r for r in galaz["run_test"] if r["dump"] not in rb]
            if nowe_rt and not nowe_wpisy:
                raise Reczne("gałąź dodaje tylko kontrole dodatnie (" + ", ".join(r["src"] for r in nowe_rt)
                             + ") bez nowych kontroli — dopisz je do KONTROLE_DODATNIE w pliku katalogu, "
                             "który ma kontrole tego testu.")
            if oczekuj_wymagane:
                brak = [w["nazwa"] for w in nowe_wpisy if w["nazwa"] not in oczekuj and len(w["node"].elts) == 4]
                if brak:
                    raise Reczne("Kontrola na main wymaga `oczekuj` (wzorzec komunikatu asercji). Podaj:\n"
                                 + "\n".join(f"  --oczekuj '{n}=<regex>'" for n in brak))
    except Reczne as e:
        print(f"PRZENIESIENIE RĘCZNE ({a.galaz} vs baza {baza[:9]}):\n{e}", file=sys.stderr)
        return 2

    if not nowe_wpisy:
        if not a.sucho:
            git("checkout", main_ref, "--", STARY)
            if not a.bez_git_add:
                git("add", STARY)
        print("Brak nowych kontroli do przeniesienia; punkt wejścia wzięty z main.")
        return 0

    # --- grupy: wspólny test albo wspólny plik mutacji -> jeden plik kNN
    if a.jeden_plik:
        grupy = [nowe_wpisy]
    else:
        grupy = []
        for w in nowe_wpisy:
            klucze = {("t", repr(wartosc(galaz, w["node"].elts[2]))), ("p", repr(wartosc(galaz, w["node"].elts[1])))}
            trafione = [g for g in grupy if g["klucze"] & klucze]
            nowa = {"klucze": set(klucze), "wpisy": []}
            for g in trafione:
                nowa["klucze"] |= g["klucze"]
                nowa["wpisy"] += g["wpisy"]
                grupy.remove(g)
            nowa["wpisy"].append(w)
            grupy.append(nowa)
        kolejnosc = {w["nazwa"]: i for i, w in enumerate(nowe_wpisy)}
        grupy = [sorted(g["wpisy"], key=lambda w: kolejnosc[w["nazwa"]]) for g in grupy]
        grupy.sort(key=lambda g: kolejnosc[g[0]["nazwa"]])

    # kontrole dodatnie -> grupa, której test pasuje; reszta -> pierwsza grupa
    dodatnie = {i: [] for i in range(len(grupy))}
    for r in nowe_rt:
        v = repr(wartosc(galaz, r["node"]))
        cel = next((i for i, g in enumerate(grupy)
                    if any(repr(wartosc(galaz, w["node"].elts[2])) == v for w in g)), 0)
        dodatnie[cel].append(r)

    istniejace = sorted((root / KATALOG).glob("k[0-9]*.py"))
    nr = max([int(re.match(r"k(\d+)", p.name).group(1)) for p in istniejace] or [0]) + 1
    wszystkie_stale = set(galaz["stale"]) | set(galaz["funkcje"])
    uzyte = set()
    pliki = []
    for i, g in enumerate(grupy):
        start = set()
        for w in g:
            start |= nazwy_w(w["node"])
        for r in dodatnie[i]:
            start |= nazwy_w(r["node"])
        potrzebne, importy, z_narz = zaleznosci(start, galaz, narzedzia)
        uzyte |= set(potrzebne)
        kolej = {n: galaz[("stale" if n in galaz["stale"] else "funkcje")][n]["node"].lineno for n in potrzebne}
        potrzebne = sorted(set(potrzebne), key=kolej.get)
        if a.jeden_plik and a.obszar:
            obszar = slug(a.obszar, 8)
        else:
            t = wartosc(galaz, g[0]["node"].elts[2])
            obszar = slug(t.split("|")[0] if isinstance(t, str) else g[0]["nazwa"], 4)
        nazwa_pliku = f"k{nr:02d}_{obszar}.py"
        while (root / KATALOG / nazwa_pliku).exists():
            nr += 1
            nazwa_pliku = f"k{nr:02d}_{obszar}.py"
        nr += 1
        kom = []
        for w in g:
            kom += [k.strip().lstrip("#").strip() for k in w["kom"]]
        opis = " ".join(k for k in kom if k)
        galaz_nazwa = a.nazwa or git("rev-parse", "--abbrev-ref", a.galaz, check=False).stdout.strip()
        skad = f"gałąź {galaz_nazwa}, " if galaz_nazwa and galaz_nazwa != "HEAD" else ""
        naglowek = "; ".join(w["nazwa"] for w in g)
        if len(naglowek) > 90:
            naglowek = f"{len(g)} kontroli: {g[0]['nazwa']} i pokrewne"
        doc = (naglowek + ".\n\n"
               + (opis + "\n\n" if opis else "")
               + f"Przeniesione automatycznie ze starego pliku {STARY}\n"
               f"({skad}po podziale #1478 — docs/flota/PRZENIESIENIE_PO_PODZIALE.md).")
        doc = doc.replace("\\", "\\\\").replace('"""', '\\"\\"\\"')
        import_narz = sorted({"Kontrola"} | z_narz, key=lambda n: (n != "Kontrola", n))
        czesci = [f'"""{doc}\n"""\n']
        if importy:
            czesci.append("\n".join(sorted(importy)) + "\n")
        czesci.append(f"from kontrole_negatywne._narzedzia import {', '.join(import_narz)}\n\n")
        blok, ostatni_koniec = [], None
        for n in potrzebne:
            wpis = galaz["stale"].get(n) or galaz["funkcje"].get(n)
            if n in galaz["funkcje"]:
                blok.append("\n\n" + wpis["src"] + "\n\n")
                ostatni_koniec = None
            else:
                # stałe stojące w gałęzi jedna pod drugą zostają razem
                if blok and ostatni_koniec is not None and wpis["od"] == ostatni_koniec + 1:
                    blok[-1] = blok[-1] + wpis["src"] + "\n"
                else:
                    blok.append(wpis["src"] + "\n")
                ostatni_koniec = wpis["node"].end_lineno
        czesci += blok
        if dodatnie[i]:
            czesci.append("\nKONTROLE_DODATNIE = [" + ", ".join(r["src"] for r in dodatnie[i]) + "]\n")
        czesci.append("\nKONTROLE = [\n" + "\n".join(
            jako_kontrola(w, oczekuj.get(w["nazwa"]) if oczekuj_wymagane and len(w["node"].elts) == 4 else None)
            for w in g) + "\n]\n")
        tresc = re.sub(r"\n{4,}", "\n\n\n", "\n".join(czesci))
        kod_bez_doc = re.sub(r'"""[\s\S]*?"""', "", tresc)
        if not nazwa_pliku.startswith("k05_") and re.search(rf"[\"']{STRAZNIK}[\"']", kod_bez_doc):
            print(f"PRZENIESIENIE RĘCZNE: wpis zależy od stałej z nazwą {STRAZNIK} w cudzysłowie — "
                  "wolno ją mieć tylko w k05_straznik_tekstu.py.", file=sys.stderr)
            return 2
        compile(tresc, nazwa_pliku, "exec")
        pliki.append((nazwa_pliku, tresc, [w["nazwa"] for w in g]))

    niewykorzystane = sorted((set(galaz["stale"]) - set(b["stale"]) | set(galaz["funkcje"]) - set(b["funkcje"])) - uzyte)
    for n in niewykorzystane:
        print(f"UWAGA: nowa stała/funkcja {n} z gałęzi nie jest używana przez żadną nową kontrolę — nie przeniesiona.",
              file=sys.stderr)

    for nazwa_pliku, tresc, etyk in pliki:
        print(f"{'[sucho] ' if a.sucho else ''}{KATALOG}/{nazwa_pliku}: {len(etyk)} kontroli — " + "; ".join(etyk))
        if a.sucho:
            print("-" * 60 + "\n" + tresc + "-" * 60)
    if a.sucho:
        return 0

    zapisane = []
    for nazwa_pliku, tresc, _ in pliki:
        p = root / KATALOG / nazwa_pliku
        p.write_text(tresc, encoding="utf-8")
        zapisane.append(p)

    def wycofaj(powod):
        for p in zapisane:
            p.unlink(missing_ok=True)
        if w_scaleniu:
            git("checkout", "-m", "--", STARY, check=False)   # przywraca znaczniki konfliktu
        print(f"WALIDACJA NIE PRZESZŁA — pliki wycofane:\n{powod}", file=sys.stderr)
        return 3

    # 1) katalog sam w sobie (bez punktu wejścia, który może mieć jeszcze znaczniki)
    env = dict(os.environ, PYTHONDONTWRITEBYTECODE="1")
    r = subprocess.run([sys.executable, "-c",
                        "import sys; sys.path.insert(0, 'scripts'); sys.dont_write_bytecode=True\n"
                        "from kontrole_negatywne import _narzedzia; _narzedzia.lista()"],
                       text=True, capture_output=True, env=env)
    if r.returncode:
        return wycofaj(r.stdout[-2000:] + r.stderr[-3000:])
    # 2) punkt wejścia z main i pełne --lista (preflight kotwic)
    git("checkout", main_ref, "--", STARY)
    r = subprocess.run([sys.executable, STARY, "--lista"], text=True, capture_output=True, env=env)
    brakuje = [e for _, _, et in pliki for e in et if f"\t{e}\t" not in r.stdout]
    if r.returncode or brakuje:
        return wycofaj((r.stdout[-1500:] + r.stderr[-3000:]) + (f"\nbrak na liście: {brakuje}" if brakuje else ""))
    print(r.stdout.strip().splitlines()[-1])
    if not a.bez_git_add:
        git("add", STARY, *[str(p.relative_to(root)) for p in zapisane])
    return 0


if __name__ == "__main__":
    sys.exit(main())
