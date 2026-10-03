"""Wzorce oczekiwanej przyczyny kontroli negatywnych Alfa 0.8 (#1011).

Klucz to nazwa kontroli z `checks` w `scripts/kontrole-negatywne-alfa08.py`
(literówka wywraca preflight z nazwą kontroli). Wartość to wyrażenie regularne
(`re.search`, `re.DOTALL`) dopasowywane do komunikatu KAŻDEJ porażki po mutacji,
z białymi znakami zwiniętymi do jednej spacji. Jedna kontrola może mieć kilka
porażek (szeroki filtr, testy z data providerem) — wtedy wzorzec jest alternatywą
`a|b`, a KAŻDA porażka musi pasować do którejś jej gałęzi.

JAK PISAĆ WZORZEC. Wskaż regułę, którą mutacja ma naruszyć: fragment WŁASNEGO
komunikatu asercji (`assertTrue($x, 'Bramka pomija job …')`) albo tekst, którego
brakuje w wyniku (`contains "1 aktywnych ukryć"`). Nie wystarczy ogólne
`Failed asserting that` — pasuje do każdej cudzej asercji, czyli do niczego.
Nie wpisuj ścieżek, identyfikatorów, znaczników czasu ani numerów linii: wzorzec
ma przeżyć przeformatowanie testu.

`Wyjatek(...)` dopuszcza wyjątek (`<error>`) jako objaw tej mutacji — tylko tam,
gdzie sama mutacja go powoduje (przerwana transakcja, niespełniona atrapa);
wzorzec ma wtedy wskazać klasę wyjątku i treść.

Kontrola bez wpisu przechodzi jako BEZ_WZORCA i jest wymieniona z nazwy w
podsumowaniu: to dowód niepełny (docs/PULAPKI_TESTOW.md §5b).

Ten moduł nie zawiera nazw żadnych testów projektu w cudzysłowie: strażnik
`StraznikTekstuMaKontroleDodatniaTest` czyta pliki `scripts/*.py` i uznałby
taką nazwę za pokrycie.
"""

from kontrola_przyczyny import Wyjatek


# #2167: brak wymaganego CSV ma oblać test własnym komunikatem, nie skipem.
OCZEKUJ_MIARY = r'Brak wymaganego pliku database/data/odzywcze/miary\.csv'

OCZEKUJ_GRAF_CYKLI = r'Graf zależności modułów app/Domain zmienił swoje cykle'

OCZEKUJ = {
    'Planer pomija błąd frazy w podsumowaniu (#2846)': r'PLANER_2846_BLAD_W_PODSUMOWANIU',
    'Odebranie współtworzenia obiecuje brak widoku zeszytu (#2822)': r'ZESZYT_2822_KONIEC_WSPOLTWORZENIA',
    'Potwierdzenie odejścia obiecuje brak widoku zeszytu (#2822)': r'ZESZYT_2822_POTWIERDZENIE_BEZ_OBIETNICY',
    'Błąd szukania w zeszytach nie trafia do podsumowania (#2850)': r'ZESZYTY_2850_BLAD_W_PODSUMOWANIU',
    'Wygasła prośba nadal blokuje poprawę uwagi (#2820)': r'KOREKTA_2820_WYGASLA_PROSBA_ODBLOCKOWUJE',
    'Kolejka gubi zdjęcie bieżącego kroku (#2828)': r'ZDJECIE_2828_BIEZACY_KROK',
    'Usunięta lista zakupów prowadzi do nieistniejącego formularza (#2806)': r'ZAKUPY_2806_BEZ_DRUGIEGO_PRZEKIEROWANIA',
    'Błąd spiżarni gubi nazwaną listę zakupów (#2806)': r'ZAKUPY_2806_NAZWANA_LISTA_ZOSTAJE',
    'Błąd Bez składnika znika z podsumowania (#2842)': r'BEZ_SKLADNIKA_2842_PODSUMOWANIE',
    'Podsumowanie Bez składnika prowadzi do nieistniejącego pola (#2842)': r'BEZ_SKLADNIKA_2842_CEL',
    'Limit przepisu gubi wybraną listę zakupów (#2818)': r'LISTY_2818_WRACA_NA_WYBRANA',
    'Anonimowy licznik omija sprzeciw wobec statystyk (#2837)': r'SPRZECIW_2837_ANI_ANONIMOWO',
    'Dokumentacja bazy wraca do martwej lokalnej kotwicy': r'BAZA_KOTWICA_20261003',
    'Kopia odzyskania gubi ręczną kolejność (#2816)': r'ODZYSKANIE_2816_RECZNA_KOLEJNOSC',
    'Odtworzenie zeszytu gubi ręczną kolejność (#2816)': r'ODZYSKANIE_2816_RECZNA_KOLEJNOSC',
    'Stary przycisk odzyskuje nową kopię zeszytu (#2867)': r'KOPIA_2867_STARY_PRZYCISK',
    'Pełny limit chowa nazwę nowej listy (#2821)': r'LISTY_2821_ZYWE_POLE',
    'Ponowienie zaproszenia ujawnia nazwę po odebraniu dostępu (#2825)': r'ZESZYT_2825_PONOWIENIE_BEZ_DOSTEPU',
    'Zawieszona autorka nie może cofnąć udostępnienia (#2791)': r'UDOSTEPNIENIE_2791_COFNIECIE_MIMO_KARY',
    'Moje rozmowy przepuszczają niemożliwą datę kursora (#2803)': r'ROZMOWY_2803_NIEMOZLIWY_CZAS',
    'Ponowienie przeniesienia ujawnia niedostępny tytuł (#2809)': r'PRZENIESIENIE_2809_TYTUL_POD_POLICY',
    'Moje rozmowy pomijają Policy wpisu (#2432)': r'ROZMOWY_2432_POLICY_TRESCI',
    'Import odrzuca ułamek czasu bez ostrzeżenia (#2546)': r'IMPORT_2546_CZAS_WIDOCZNY',
    'Import nie zapisuje ostrzeżeń parsera przy szkicu (#2548)': r'IMPORT_2548_OSTRZEZENIE_TRWA',
    'Importowany szkic traci bramkę odczytu w kopii (#2800)': r'KOPIA_2800_IMPORT_OMINIETY',
    'Spiżarnia: jedyne opakowanie bez odcisku (#2783)': r'ODCISK_2783_FORMULARZ',
    'Notatka z dalszej porcji wraca na pierwszą stronę (#2829)': r'NOTATKA_2829_DRUGA_STRONA',
    'Spiżarnia: identyczne B bez kontroli tożsamości (#2783)': r'ODCISK_2783_TOZSAMOSC',
    'Niezmieniona kopia szkicu wychodzi do ludzi (#2507)': r'KOPIA_2507_PUBLIKACJA',
    'Kopia szkicu gubi podpis Mojej wersji (#2507)': r'KOPIA_2507_ATRYBUCJA',
    'Kopia szkicu przejmuje zdjęcia kroków (#2507)': r'KOPIA_2507_MEDIA',
    'Kopia szkicu ocenia prawo na starym stanie (#2507)': r'KOPIA_2507_SWIEZY_STAN',
    'Kopię cudzego szkicu zrobi każdy (#2507)': r'KOPIA_2507_WLASCICIEL',
    'Kolaż hero bez podpisu autora przy zdjęciu (#2708)': r'Kafel nie ma podpisu z nazwą dokładnie jednego autora',
    'Dołączenie zdjęcia: podmiana UUID kucharza (#2500)': r'DOLACZENIE_2500_PODMIANA_UUID',
    'Ponowiony multipart zapisuje osierocone zdjęcie (#2811)': r'DOLACZENIE_2811_BEZ_OSIEROCONEGO_MEDIA',
    'Historia zdjęć urywa się po szóstym wysłaniu (#2811)': r'DOLACZENIE_2811_SIODME_WYSLANIE_PO_USUNIECIU',
    'Wymazanie zostawia prywatne klucze zdjęć (#2811)': r'DOLACZENIE_2811_WYMAZANE_KLUCZE',
    'Dołączenie zdjęcia liczy limit bez przypiętych (#2500)': r'DOLACZENIE_2500_LIMIT',
    'Dołączenie zdjęcia ignoruje świeży stan pod blokadą (#2500)': r'DOLACZENIE_2500_SWIEZY_STAN',
    'Dołączenie zdjęcia przejmuje cudze albo cudzo-przypięte (#2500)': r'DOLACZENIE_2500_WLASNOSC_MEDIOW',
    'Rollback udostępnień przepisów kasuje bez pytania (#2650)': r'Rollback skasował udostępnienia bez pytania',
    'Niedostępny przepis usuwa drogę rezygnacji (#2859)': r'UDOSTEPNIENIE_2859_ANONIMOWA_REZYGNACJA',
    'Eksport pobiera tytuł przepisu bez aktualnego dostępu (#2785)': r'EKSPORT_2785_BIEZACY_TYTUL_BEZ_DOSTEPU',
    'Limit listy odzyskania przed filtrem moderacji (#2868)': r'ODZYSKANIE_2868_STARSZY_CZYSTY',
