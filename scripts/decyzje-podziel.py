#!/usr/bin/env python3
"""Dzieli jednoplikowy dziennik decyzji na pliki `docs/decyzje/D-NNN-slug.md`.

Jednorazowy rozdział `docs/DECISIONS.md` (jeden plik, dopisywany na końcu,
konflikt w każdej parze równoległych PR-ów) na jeden plik na decyzję
i indeks. Odtwarzalny: da się go puścić na dowolnym starym układzie dziennika
(np. na `origin/main~N`), żeby sprawdzić, że nic nie ginie.

  python3 scripts/decyzje-podziel.py [--z REF] [--sucho]

  --z REF   skąd wziąć stary dziennik (domyślnie HEAD; plik musi być
            w starym układzie, bez znacznika tabeli indeksu)
  --sucho   tylko wypisz, co powstałoby; nic nie zapisuj

Co robi:
  - każdy nagłówek „## D-NNN …" albo „## Uzupełnienie #NNN …" otwiera wpis,
    który trwa do następnego nagłówka „## ”. Wiersze końcowe złożone z samych
    odstępów i separatora „---" nie należą do wpisu. Treść wpisu jest kopiowana
    bajt w bajt. Jedyna zmiana: względne odnośniki markdown dostają „../",
    bo plik leży katalog głębiej niż dawny dziennik;
  - sekcja „## Jak dopisywać decyzje" nie jest decyzją — jej treść przechodzi do
    wstępu nowego indeksu (razem z mechaniką: nowa decyzja = nowy plik);
  - wstęp z ramką o pustych numerach zostaje w indeksie słowo w słowo;
  - na końcu odświeża tabelę indeksu (php scripts/decyzje-indeks.php);
  - wypisuje sumy kontrolne: liczba wpisów, wierszy i to, czy ten sam
    multizbiór niepustych wierszy stoi przed i po podziale.

Nic nie commituje. Wpisy gałęzi napisane jeszcze w starym układzie przenosi
`scripts/decyzje-przenies.py` (z tego samego modułu pomocniczego).
"""

import argparse
from collections import Counter
import importlib.util
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
KATALOG = ROOT / "docs" / "decyzje"
DZIENNIK = ROOT / "docs" / "DECISIONS.md"
ZNACZNIK_INDEKSU = "<!-- indeks-decyzji:poczatek"
SEKCJA_JAK_DOPISYWAC = "## Jak dopisywać decyzje"

_spec = importlib.util.spec_from_file_location("decyzje_przenies", ROOT / "scripts" / "decyzje-przenies.py")
przenies = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(przenies)

STARY_AKAPIT = """Decyzje, które **zostały podjęte** i których nie należy otwierać na nowo bez
nowej informacji. Każdy agent AI i każda osoba dołączająca do projektu czyta
ten plik, żeby nie proponować rzeczy już rozstrzygniętych.
"""

NOWY_AKAPIT = """Decyzje, które **zostały podjęte** i których nie należy otwierać na nowo bez
nowej informacji. Każdy agent AI i każda osoba dołączająca do projektu czyta
ten indeks i wpisy, do których prowadzi, żeby nie proponować rzeczy już
rozstrzygniętych.

**Od 30 września 2026 każda decyzja to osobny plik** w
[`docs/decyzje/`](decyzje/), nazwany `D-NNN-krotki-slug.md`. Ten plik jest
indeksem: numer, tytuł, status i odnośnik. Odnośnik „D-NNN w
`docs/DECISIONS.md`” w kodzie i dokumentach dalej działa — numer znajdziesz
w tabeli niżej. Treść decyzji przeniesiono bez zmian; historia sprzed podziału
jest w historii gita tego pliku (`git log -- docs/DECISIONS.md`).
"""

MECHANIKA = """Mechanika — **nowa decyzja to nowy plik, nigdy dopisek w tym indeksie**:

1. Numer: `php scripts/decyzje-indeks.php --nastepny` (największy numer plus
   jeden; puste numery z ramki wyżej zostają puste). Koordynator przed
   scaleniem sprawdza aktualny `origin/main` i żywe gałęzie z decyzjami.
2. Nowy plik `docs/decyzje/D-NNN-krotki-slug.md` — slug to kilka słów tytułu,
   małe litery bez polskich znaków, cyfry i myślniki. Pierwszy wiersz:
   `## D-NNN · Tytuł decyzji`, podsekcje jako `### `. Format treści jak we
   wpisach obok: data, kto zdecydował, `Status: **obowiązuje**`, dlaczego,
   co musiałoby się stać, żeby decyzję zmienić, linia `📄` z plikami.
   Względne odnośniki markdown liczysz od katalogu `docs/decyzje/`.
3. Odśwież tabelę: `php scripts/decyzje-indeks.php` — i zacommituj oba pliki.
   Tabeli nie edytuj ręcznie; po konflikcie w niej weź dowolną stronę
   i uruchom skrypt jeszcze raz.
4. Sprawdzenie: `php scripts/decyzje-indeks.php --sprawdz`. W CI to samo
   pilnuje `DziennikDecyzjiZgodnyZIndeksemTest` (unikalne numery, numer
   w nazwie = numer w nagłówku, indeks zgodny z plikami, brak treści decyzji
   w tym pliku).

Dwa PR-y z tym samym numerem nie dają konfliktu w gicie (różne slugi), tylko
czerwony test po scaleniu drugiego: młodsza gałąź zmienia numer (nazwę pliku,
nagłówek i odwołania), odświeża indeks i przechodzi CI ponownie. Gałąź, która
dopisała decyzję do starego, jednoplikowego dziennika, przenosi ją według
[`docs/flota/PRZENIESIENIE_PO_PODZIALE.md`](flota/PRZENIESIENIE_PO_PODZIALE.md).
"""


def stary_dziennik(ref):
    wynik = subprocess.run(["git", "show", f"{ref}:docs/DECISIONS.md"], cwd=ROOT, text=True, capture_output=True)
    if wynik.returncode != 0:
        raise SystemExit(f"git show {ref}:docs/DECISIONS.md: {wynik.stderr.strip()}")
    if ZNACZNIK_INDEKSU in wynik.stdout:
        raise SystemExit(f"{ref}:docs/DECISIONS.md jest już indeksem — nie ma czego dzielić.")
    return wynik.stdout


def niepuste(tresc):
    return Counter(linia for linia in tresc.split("\n") if linia.strip())


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--z", default="HEAD", help="ref ze starym dziennikiem (domyślnie HEAD)")
    parser.add_argument("--sucho", action="store_true")
    arg = parser.parse_args()

    stary = stary_dziennik(arg.z)
    linie = stary.split("\n")
    naglowki = [i for i, linia in enumerate(linie) if linia.startswith("## ")]
    if not naglowki:
        raise SystemExit("Brak nagłówków „## ” w starym dzienniku.")

    wstep = "\n".join(linie[: naglowki[0]])
    if STARY_AKAPIT not in wstep:
        raise SystemExit("Wstęp dziennika się zmienił — popraw STARY_AKAPIT w skrypcie.")

    wpisy = []  # (klucz, nazwa pliku, treść po korekcie odnośników, treść oryginalna)
    pominiete = []  # sekcje, które nie są decyzjami
    numery = Counter()
    for od, do in zip(naglowki, naglowki[1:] + [len(linie)]):
        kawalek = linie[od:do]
        while kawalek and kawalek[-1].strip() in ("", "---"):
            kawalek.pop()
        tresc = "\n".join(kawalek) + "\n"
        m = przenies.NAGLOWEK.match(kawalek[0])
        if not m:
            pominiete.append((kawalek[0], tresc))
            continue
        klucz = m.group(1) or m.group(4)
        numery[klucz] += 1
        wpisy.append((klucz, przenies.nazwa_pliku(klucz, kawalek[0]), przenies.odnosniki_o_katalog_glebiej(tresc), tresc))

    dubel = [k for k, n in numery.items() if n > 1]
    if dubel:
        raise SystemExit("Ten sam numer dwa razy w starym dzienniku: " + ", ".join(dubel))
    nazwy = Counter(nazwa for _, nazwa, _, _ in wpisy)
    if any(n > 1 for n in nazwy.values()):
        raise SystemExit("Kolizja nazw plików: " + ", ".join(n for n, c in nazwy.items() if c > 1))
    inne = [n for n, _ in pominiete if n != SEKCJA_JAK_DOPISYWAC]
    if inne:
        raise SystemExit("Nagłówki „## ” spoza wzorca decyzji: " + "; ".join(inne))

    # Nagłówek indeksu: stary wstęp z nowym akapitem + sekcja „Jak dodać decyzję".
    jak_dopisywac = dict(pominiete).get(SEKCJA_JAK_DOPISYWAC)
    if jak_dopisywac is None or "Nowa decyzja trafia tutaj, gdy" not in jak_dopisywac:
        raise SystemExit("Nie ma sekcji „Jak dopisywać decyzje” w oczekiwanej postaci.")
    kiedy = jak_dopisywac.split("\n", 2)[2].rstrip("\n")  # bez nagłówka i pustego wiersza
    kiedy = kiedy.replace("Nowa decyzja trafia tutaj, gdy", "Nowa decyzja trafia do dziennika, gdy", 1)
    indeks = (
        wstep.replace(STARY_AKAPIT, NOWY_AKAPIT).rstrip("\n")
        + "\n\n## Jak dodać decyzję\n\n"
        + kiedy
        + "\n\n"
        + MECHANIKA
        + "\n## Indeks\n\n"
        + ZNACZNIK_INDEKSU
        + " — generuje php scripts/decyzje-indeks.php, nie edytuj ręcznie -->\n\n"
        + "<!-- indeks-decyzji:koniec -->\n"
    )

    # Kontrola: multizbiór niepustych wierszy przed i po (z wyjątkiem wstępu i sekcji „Jak dopisywać”).
    przed = niepuste("\n".join(linie[naglowki[0]:])) - niepuste(jak_dopisywac) - niepuste("---")
    po = Counter()
    for _, _, tresc, _ in wpisy:
        po += niepuste(tresc)
    po_oryginalne = Counter()
    for _, _, _, tresc in wpisy:
        po_oryginalne += niepuste(tresc)
    przed_bez_separatorow = Counter({k: v for k, v in przed.items() if k.strip() != "---"})
    po_bez_separatorow = Counter({k: v for k, v in po_oryginalne.items() if k.strip() != "---"})
    zgodne = przed_bez_separatorow == po_bez_separatorow

    zmienione = sum(1 for _, _, nowa, stara in wpisy if nowa != stara)
    print(f"Wpisów: {len(wpisy)} (w tym uzupełnień: {sum(1 for k in numery if k.startswith('Uzup'))}), "
          f"sekcji spoza wpisów: {len(pominiete)}.")
    print(f"Niepustych wierszy treści przed/po: {sum(przed_bez_separatorow.values())}/"
          f"{sum(po_bez_separatorow.values())}; ten sam multizbiór: {'TAK' if zgodne else 'NIE'}.")
    print(f"Wpisy z poprawionym odnośnikiem względnym („../”): {zmienione}.")
    if not zgodne:
        return 1
    if arg.sucho:
        return 0

    KATALOG.mkdir(exist_ok=True)
    for klucz, nazwa, tresc, _ in wpisy:
        (KATALOG / nazwa).write_text(tresc, encoding="utf-8")
    DZIENNIK.write_text(indeks, encoding="utf-8")
    subprocess.run(["php", "scripts/decyzje-indeks.php"], cwd=ROOT, check=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
