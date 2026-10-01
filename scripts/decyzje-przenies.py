#!/usr/bin/env python3
"""Przenosi wpisy gałęzi sprzed podziału dziennika do plików `docs/decyzje/`.

Gałąź, która dopisała albo zmieniła decyzję w starym, jednoplikowym
`docs/DECISIONS.md`, po scaleniu main dostaje konflikt w tym pliku. Kroki:

  git merge origin/main                       # konflikt w docs/DECISIONS.md
  git checkout origin/main -- docs/DECISIONS.md
  python3 scripts/decyzje-przenies.py         # wpisy gałęzi → docs/decyzje/
  php scripts/decyzje-indeks.php              # tabela indeksu z plików
  git add docs/DECISIONS.md docs/decyzje && git commit

Skrypt porównuje dziennik z GAŁĘZI (domyślnie HEAD, czyli Twoja strona scalenia)
z dziennikiem z bazy scalenia (merge-base z origin/main). Każdy wpis, który
gałąź dodała albo zmieniła:
  - nowy numer, wolny w docs/decyzje/     → nowy plik D-NNN-slug.md;
  - numer zajęty przez INNĄ decyzję z main → nowy plik z następnym wolnym
    numerem; skrypt wypisuje, które odnośniki przepiąć;
  - zmiana istniejącej decyzji, której main nie ruszał → nadpisuje jej plik;
  - zmiana decyzji, którą main też zmienił → NIC nie rusza, wypisuje do
    ręcznego scalenia (wersja gałęzi obok, w pliku *.z-galezi).
Treść idzie bez zmian; względne odnośniki markdown dostają `../`, bo plik
leży katalog głębiej. Nic nie commituje.

  python3 scripts/decyzje-przenies.py [--z REF] [--baza REF] [--sucho]
"""

import argparse
from pathlib import Path
import re
import subprocess
import sys
import unicodedata

ROOT = Path(__file__).resolve().parent.parent
KATALOG = ROOT / "docs" / "decyzje"
DZIENNIK = "docs/DECISIONS.md"
ZNACZNIK_INDEKSU = "<!-- indeks-decyzji:poczatek"

PL = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
NAGLOWEK = re.compile(r"^## (?:(D-(\d+)(-ROBOCZA)?)|(Uzupełnienie #(\d+)))\s*(?:·|—)\s*(.+)$")


def git(*argumenty, sprawdz=True):
    wynik = subprocess.run(["git", *argumenty], cwd=ROOT, text=True, capture_output=True)
    if sprawdz and wynik.returncode != 0:
        raise SystemExit("git " + " ".join(argumenty) + ": " + wynik.stderr.strip())
    return wynik


def slug(tytul, slow=6, najwiecej=50):
    tekst = re.sub(r"\([^)]*\)", " ", tytul).translate(PL)
    tekst = unicodedata.normalize("NFKD", tekst).encode("ascii", "ignore").decode().lower()
    wynik = []
    for slowo in re.findall(r"[a-z0-9]+", tekst):
        if len(wynik) >= slow or len("-".join(wynik + [slowo])) > najwiecej:
            break
        wynik.append(slowo)
    return "-".join(wynik) or "wpis"


def wpisy(tresc, duble=None):
    """{klucz: (nagłówek, treść)} ze starego, jednoplikowego dziennika.

    Ten sam numer dwa razy (konflikt „weź obie strony” na gałęzi) trafia do
    `duble` — tego nie rozstrzygnie żaden automat.
    """
    linie = tresc.split("\n")
    poczatki = [i for i, linia in enumerate(linie) if linia.startswith("## ")]
    wynik = {}
    for od, do in zip(poczatki, poczatki[1:] + [len(linie)]):
        kawalek = linie[od:do]
        while kawalek and kawalek[-1].strip() in ("", "---"):
            kawalek.pop()
        m = NAGLOWEK.match(kawalek[0])
        if m:
            klucz = m.group(1) or m.group(4)
            if klucz in wynik and duble is not None:
                duble.add(klucz)
            wynik[klucz] = (kawalek[0], "\n".join(kawalek) + "\n")
    return wynik


def odnosniki_o_katalog_glebiej(tresc):
    return re.sub(
        r"\]\(([^)\s]+)\)",
        lambda m: m.group(0) if re.match(r"^(https?:|mailto:|#|/)", m.group(1)) else "](../" + m.group(1) + ")",
        tresc,
    )


def plik_klucza(klucz):
    m = re.match(r"^D-(\d+)(-ROBOCZA)?$", klucz)
    if m:
        wzorzec = f"D-{m.group(1)}-robocza-*.md" if m.group(2) else f"D-{m.group(1)}-*.md"
    else:
        wzorzec = "U-" + re.sub(r"\D", "", klucz).zfill(3) + "-*.md"
    pliki = sorted(p for p in KATALOG.glob(wzorzec) if m is None or m.group(2) or "-robocza-" not in p.name)
    return pliki[0] if pliki else None


def nazwa_pliku(klucz, naglowek):
    tytul = NAGLOWEK.match(naglowek).group(6)
    m = re.match(r"^D-(\d+)(-ROBOCZA)?$", klucz)
    if m:
        return f"D-{m.group(1)}-" + ("robocza-" if m.group(2) else "") + slug(tytul) + ".md"
    return "U-" + re.sub(r"\D", "", klucz).zfill(3) + "-" + slug(tytul) + ".md"


def nastepny_wolny():
    numery = [int(m.group(1)) for p in KATALOG.glob("D-*.md") if (m := re.match(r"^D-(\d{3})-", p.name))]
    return max(numery, default=0) + 1


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--z", default="HEAD", help="gałąź ze starym dziennikiem (domyślnie HEAD)")
    parser.add_argument("--baza", help="baza porównania (domyślnie merge-base z origin/main)")
    parser.add_argument("--sucho", action="store_true", help="tylko wypisz, nic nie zapisuj")
    arg = parser.parse_args()

    baza = arg.baza or git("merge-base", arg.z, "origin/main").stdout.strip()
    z_galezi = git("show", f"{arg.z}:{DZIENNIK}", sprawdz=False)
    if z_galezi.returncode != 0 or ZNACZNIK_INDEKSU in z_galezi.stdout:
        print(f"{arg.z}:{DZIENNIK} nie jest starym dziennikiem — nie ma czego przenosić.")
        return 0
    stare = wpisy(git("show", f"{baza}:{DZIENNIK}").stdout)
    duble = set()
    galaz = wpisy(z_galezi.stdout, duble)

    reczne = sorted(duble)
    zrobione = [f"{k}: w dzienniku gałęzi ten numer stoi DWA razy — rozdziel ręcznie, pliku nie ruszono"
                for k in reczne]
    for klucz, (naglowek, tresc) in galaz.items():
        if klucz in duble:
            continue
        if klucz in stare and stare[klucz][1] == tresc:
            continue  # gałąź tego wpisu nie ruszała

        nowa = odnosniki_o_katalog_glebiej(tresc)
        istniejacy = plik_klucza(klucz)

        if istniejacy is None:
            cel = KATALOG / nazwa_pliku(klucz, naglowek)
            opis = "nowy wpis"
        elif istniejacy.read_text(encoding="utf-8") == nowa:
            continue  # już przeniesiony
        elif klucz in stare and istniejacy.read_text(encoding="utf-8") == odnosniki_o_katalog_glebiej(stare[klucz][1]):
            cel = istniejacy
            opis = "zmiana istniejącego wpisu (main go nie ruszał)"
        elif klucz not in stare:
            numer = f"D-{nastepny_wolny():03d}"
            naglowek = re.sub(r"^## D-\d+(-ROBOCZA)?", "## " + numer, naglowek)
            nowa = re.sub(r"^## D-\d+(-ROBOCZA)?", "## " + numer, nowa, count=1)
            cel = KATALOG / nazwa_pliku(numer, naglowek)
            opis = f"numer {klucz} zajęty na main przez {istniejacy.name} — wpis dostaje {numer}; " \
                   f"przepnij odnośniki {klucz} → {numer} w zmianach tej gałęzi (git grep -n '{klucz}\\b')"
        else:
            cel = istniejacy.with_name(istniejacy.name + ".z-galezi")
            opis = f"main też zmienił {istniejacy.name} — scal ręcznie z wersją gałęzi obok, potem usuń *.z-galezi"
            reczne.append(cel)

        zrobione.append(f"{klucz}: {opis}\n    → {cel.relative_to(ROOT)}")
        if not arg.sucho:
            cel.write_text(nowa, encoding="utf-8")

    for klucz in stare.keys() - galaz.keys():
        reczne.append(klucz)
        zrobione.append(f"{klucz}: gałąź USUNĘŁA ten wpis — rozstrzygnij ręcznie (pliku nie ruszono)")

    print("\n".join(zrobione) if zrobione else "Gałąź nie dodała ani nie zmieniła żadnego wpisu.")
    if not arg.sucho and zrobione:
        print("\nDalej: php scripts/decyzje-indeks.php && php scripts/decyzje-indeks.php --sprawdz")
    return 1 if reczne else 0


if __name__ == "__main__":
    sys.exit(main())
