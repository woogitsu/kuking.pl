#!/usr/bin/env python3
"""
Uzupełnia wartości odżywcze w database/data/odzywcze/skladniki.csv z plików
źródłowych CIQUAL 2025 i USDA FoodData Central (SR Legacy) — D-299.

PO CO TEN SKRYPT, SKORO PLIK JEST W REPOZYTORIUM
Liczby w skladniki.csv nie są przepisane z palca. Człowiek wybiera w pliku
tylko ZRÓDŁO i IDENTYFIKATOR pozycji (kolumny `zrodlo`, `zrodlo_id`), a ten
skrypt dopisuje do niej nazwę i wartości dokładnie tak, jak stoją w tabeli
źródłowej. Dzięki temu każdą liczbę da się sprawdzić jednym poleceniem,
a zmiana źródła to zmiana jednego identyfikatora, nie ręczne przepisywanie.

Skrypt NIE jest częścią wdrożenia i nie chodzi na produkcji: pobrane pliki
źródłowe leżą poza repozytorium, a produkcja importuje gotowy CSV komendą
`php artisan kuking:importuj-wartosci-odzywcze`. Żadnego pobierania z sieci
w aplikacji (decyzja właściciela z 26.09.2026).

Uruchomienie (pliki źródłowe: patrz database/data/odzywcze/ZRODLA.md):

    python3 scripts/odzywcze/uzupelnij_wartosci.py \
        --ciqual "Table Ciqual 2025_FR_2025_11_03.xlsx" \
        --usda FoodData_Central_sr_legacy_food_csv_2018-04 \
        [--z-tsv mapa.tsv]

Zasady odczytu wartości (te same dla każdej pozycji):
- CIQUAL: energia wg rozporządzenia UE 1169/2011 (kcal/100 g), a gdy jej
  brak — energia „N x facteur Jones, avec fibres”; białko „N x facteur de
  Jones”, a gdy brak — „N x 6.25”; „Glucides” i „Lipides”.
- „traces” i „< x” zapisujemy jako 0 — to jest ilość poniżej granicy
  oznaczalności, a zaokrąglamy wynik i tak do 1 g.
- „-” (brak oznaczenia) przy którejkolwiek z czterech wartości przerywa
  skrypt: taką pozycję trzeba zamienić na inną, a nie zgadywać.
- USDA SR Legacy: składniki 1008 (Energy, kcal), 1003 (Protein),
  1004 (Total lipid), 1005 (Carbohydrate, by difference).
"""
import argparse
import csv
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
PLIK = REPO / 'database/data/odzywcze/skladniki.csv'
KOLUMNY = ['klucz', 'nazwa', 'aliasy', 'zrodlo', 'zrodlo_id', 'gestosc_g_ml', 'pomijalny',
           'zrodlo_nazwa', 'kcal', 'bialko', 'tluszcz', 'weglowodany']
NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'


def czytaj_xlsx(sciezka):
    z = zipfile.ZipFile(sciezka)
    napisy = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).iter(NS + 'si'):
            napisy.append(''.join(t.text or '' for t in si.iter(NS + 't')))
    arkusz = sorted(n for n in z.namelist() if n.startswith('xl/worksheets/sheet'))[0]
    wiersze = []
    for r in ET.fromstring(z.read(arkusz)).iter(NS + 'row'):
        wiersz = {}
        for c in r.iter(NS + 'c'):
            kol = re.match(r'[A-Z]+', c.get('r')).group()
            v = c.find(NS + 'v')
            if c.get('t') == 's' and v is not None:
                wiersz[kol] = napisy[int(v.text)]
            elif c.get('t') == 'inlineStr':
                wiersz[kol] = ''.join(t.text or '' for t in c.iter(NS + 't'))
            else:
                wiersz[kol] = v.text if v is not None else ''
        wiersze.append(wiersz)
    return wiersze


def bez_akcentow(tekst):
    return unicodedata.normalize('NFKD', tekst).encode('ascii', 'ignore').decode().lower()


def liczba_ciqual(wartosc):
    w = (wartosc or '').strip()
    if w in ('', '-'):
        return None
    if w == 'traces' or w.startswith('<'):
        return 0.0
    return float(w.replace(',', '.'))


def wczytaj_ciqual(sciezka):
    wiersze = czytaj_xlsx(sciezka)
    naglowek = {bez_akcentow(v.replace('\n', ' ')): k for k, v in wiersze[0].items()}

    def kolumna(*fragmenty):
        for nazwa, k in naglowek.items():
            if all(f in nazwa for f in fragmenty):
                return k
        sys.exit(f'CIQUAL: nie ma kolumny z {fragmenty}')

    k_kod = kolumna('alim_code')
    k_nazwa = kolumna('alim_nom_fr')
    k_kcal = kolumna('energie', '1169', 'kcal')
    k_kcal_jones = kolumna('energie', 'jones', 'kcal')
    k_bialko = kolumna('proteines', 'jones')
    k_bialko_625 = kolumna('proteines', '6.25')
    k_wegle = kolumna('glucides (g')
    k_tluszcz = kolumna('lipides (g')
    wynik = {}
    for w in wiersze[1:]:
        kcal = liczba_ciqual(w.get(k_kcal))
        if kcal is None:
            kcal = liczba_ciqual(w.get(k_kcal_jones))
        bialko = liczba_ciqual(w.get(k_bialko))
        if bialko is None:
            bialko = liczba_ciqual(w.get(k_bialko_625))
        wynik[w.get(k_kod, '').strip()] = (
            w.get(k_nazwa, '').strip(), kcal, bialko,
            liczba_ciqual(w.get(k_tluszcz)), liczba_ciqual(w.get(k_wegle)),
        )
    return wynik


def wczytaj_usda(katalog):
    katalog = Path(katalog)
    nazwy = {r['fdc_id']: r['description'] for r in csv.DictReader(open(katalog / 'food.csv', encoding='utf-8'))}
    wart = {}
    for r in csv.DictReader(open(katalog / 'food_nutrient.csv', encoding='utf-8')):
        if r['nutrient_id'] in ('1008', '1003', '1004', '1005'):
            wart.setdefault(r['fdc_id'], {})[r['nutrient_id']] = float(r['amount'])
    return {fid: (nazwy[fid], w.get('1008'), w.get('1003'), w.get('1004'), w.get('1005'))
            for fid, w in wart.items() if fid in nazwy}


def liczba_do_csv(x):
    return f'{x:.2f}'.rstrip('0').rstrip('.')


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--ciqual', required=True)
    p.add_argument('--usda', required=True)
    p.add_argument('--z-tsv', help='pierwsze wypełnienie z ręcznej mapy TSV')
    a = p.parse_args()

    if a.z_tsv:
        wiersze = []
        for linia in open(a.z_tsv, encoding='utf-8'):
            if linia.startswith('#') or not linia.strip():
                continue
            klucz, nazwa, aliasy, zrodlo, gestosc, pomijalny = linia.rstrip('\n').split('\t')
            zr, zid = zrodlo.split(':')
            wiersze.append({'klucz': klucz, 'nazwa': nazwa, 'aliasy': aliasy, 'zrodlo': zr,
                            'zrodlo_id': zid, 'gestosc_g_ml': gestosc, 'pomijalny': pomijalny})
    else:
        wiersze = list(csv.DictReader(open(PLIK, encoding='utf-8')))

    zrodla = {'ciqual': wczytaj_ciqual(a.ciqual), 'usda': wczytaj_usda(a.usda)}
    bledy = []
    for w in wiersze:
        rekord = zrodla[w['zrodlo']].get(w['zrodlo_id'])
        if rekord is None:
            bledy.append(f"{w['klucz']}: nie ma pozycji {w['zrodlo']}:{w['zrodlo_id']}")
            continue
        nazwa, kcal, bialko, tluszcz, wegle = rekord
        if None in (kcal, bialko, tluszcz, wegle):
            bledy.append(f"{w['klucz']}: {w['zrodlo']}:{w['zrodlo_id']} ({nazwa}) nie ma kompletu wartości")
            continue
        w.update(zrodlo_nazwa=nazwa, kcal=liczba_do_csv(kcal), bialko=liczba_do_csv(bialko),
                 tluszcz=liczba_do_csv(tluszcz), weglowodany=liczba_do_csv(wegle))
    if bledy:
        sys.exit('\n'.join(bledy))

    with open(PLIK, 'w', encoding='utf-8', newline='') as f:
        pisz = csv.DictWriter(f, fieldnames=KOLUMNY, lineterminator='\n')
        pisz.writeheader()
        for w in wiersze:
            pisz.writerow({k: w.get(k, '') for k in KOLUMNY})
    print(f'Zapisano {len(wiersze)} pozycji do {PLIK.relative_to(REPO)}')


if __name__ == '__main__':
    main()
