# Ilości dziesiętne w tekście PDF (#2614)

Importer tekstowego PDF czyta wiersze lokalnie i zapisuje prywatny szkic do
sprawdzenia (D-300). Poprzedni wzorzec traktował `1.` na początku `1.5 kg`
jak numer listy, przez co w szkicu powstawało `5 kg`. Ten sam błąd zmieniał
`0.5 l` w `5 l`. Nie jest to przeliczanie ani ograniczenie długości tekstu.

Numer listy zakończony kropką wymaga teraz widocznego odstępu po kropce:
`1. 500 g` traci `1. `, lecz `1.5 kg` i niejednoznaczne `1.500 g` zachowują
oryginalne cyfry. Odstęp niełamliwy po kropce lub przed jednostką działa
jak zwykły odstęp. Nawias zamykający jednoznacznie wskazuje numerację,
więc `2) 1.5 kg` i `2)1.5 kg` tracą tylko numer. Symboliczne punktory
pozostają obsługiwane; zapis `-1.5 g`, który może być liczbą ujemną,
pozostaje nienaruszony. To świadomie zachowuje źródło przy niepewności.

Regresja mierzy publiczne `ParserTekstuPrzepisu::odczytaj()` na pełnym
tekście z nagłówkami oraz prawdziwą warstwę tekstu PDF przez zadanie kolejki
do prywatnego szkicu. Nie wywołuje AI i nie publikuje przepisu. Kontrola
ujemna przywraca stary wzorzec; asercja składników musi oblać się z
markerem `PDF_2614_ILOSC_DZIESIETNA_NIE_JEST_NUMEREM_LISTY`.

Zmiana nie dotyka limitów z #2521 ani schematu bazy. Rollback kodu
przywraca zmianę ilości w prywatnych szkicach; istniejące szkice pozostają
niezmienione i wymagają sprawdzenia ze źródłem przez autora.
