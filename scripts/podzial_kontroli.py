"""Podział wpisów `checks` z kontrole-negatywne-alfa08.py na części CI.

Kontrole negatywne to kilkaset przebiegów `artisan test --filter`. Jedna część
CI nie mieściła się w 40 minutach (paczka C: anulowana po 153 z ~208 wpisów),
więc job macierzy `test` uruchamia je równolegle w LICZBIE części z ci.yml
(`kontrole_czesc: 1..N` i argument `--czesc N/LICZBA`).

Podział jest deterministyczny i zależy tylko od położenia wpisu w `checks`:
wpis o indeksie `i` należy do części `i % liczba + 1`. Każdy wpis leży więc
w DOKŁADNIE jednej części, a nowy wpis nie wymaga żadnej ręcznej listy.
Elementy spoza pętli `checks` (miary.csv #2167, GRUPA_SYGNALOW_TEST,
odwracalność migracji) przypisuje jawnie kontrole-negatywne-alfa08.py
do części `CZESC_ELEMENTOW_POZA_PETLA`.
"""

CZESC_ELEMENTOW_POZA_PETLA = 3


def parsuj_czesc(argumenty):
    """`--czesc N/M` -> (N, M); brak argumentu -> None (uruchom wszystko)."""
    if not argumenty:
        return None
    if len(argumenty) != 2 or argumenty[0] != "--czesc":
        raise SystemExit("Użycie: kontrole-negatywne-alfa08.py [--czesc N/M]")
    numer, ukosnik, liczba = argumenty[1].partition("/")
    if not (ukosnik and numer.isdigit() and liczba.isdigit()):
        raise SystemExit(f"Zła część {argumenty[1]!r}: podaj N/M, na przykład 2/3.")
    numer, liczba = int(numer), int(liczba)
    if liczba < 1 or not 1 <= numer <= liczba:
        raise SystemExit(f"Część {numer}/{liczba} jest poza podziałem.")
    return numer, liczba


def wybierz_indeksy(liczba_wpisow, czesc):
    """Indeksy wpisów `checks` należące do części; `czesc=None` daje wszystkie."""
    if czesc is None:
        return list(range(liczba_wpisow))
    numer, liczba = czesc
    return [i for i in range(liczba_wpisow) if i % liczba == numer - 1]


def poza_petla_w_tej_czesci(czesc):
    """Czy elementy spoza `checks` idą w tej części (lokalnie: zawsze)."""
    return czesc is None or czesc[0] == min(CZESC_ELEMENTOW_POZA_PETLA, czesc[1])


def naruszenia_podzialu(liczba_wpisow, liczba_czesci, wybierz=wybierz_indeksy):
    """Lista naruszeń: pusta znaczy, że każdy wpis jest w dokładnie jednej części."""
    naruszenia = []
    licznik = [0] * liczba_wpisow
    for numer in range(1, liczba_czesci + 1):
        indeksy = wybierz(liczba_wpisow, (numer, liczba_czesci))
        if not indeksy:
            naruszenia.append(f"Część {numer}/{liczba_czesci} jest pusta.")
        for i in indeksy:
            if not 0 <= i < liczba_wpisow:
                naruszenia.append(f"Część {numer} wskazuje wpis {i} spoza `checks`.")
            else:
                licznik[i] += 1
    for i, ile in enumerate(licznik):
        if ile == 0:
            naruszenia.append(f"Wpis {i} nie trafił do żadnej części.")
        elif ile > 1:
            naruszenia.append(f"Wpis {i} jest w {ile} częściach.")
    if sorted(wybierz(liczba_wpisow, None)) != list(range(liczba_wpisow)):
        naruszenia.append("Bez części (uruchomienie lokalne) nie idą wszystkie wpisy.")
    return naruszenia
