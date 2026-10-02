# Stan produktu przy dopasowaniu wartości odżywczych (#2563)

D-299 wybiera najdłuższy polski alias produktu bez zgadywania. Krótszy alias
„ryżu” wskazuje ryż surowy (CIQUAL 9100), a „makaronu” suchy makaron
(CIQUAL 9810). Pominięcie słowa „ugotowanego” dawało więc liczby dla innego
produktu nawet przy jawnych 100 g.

Słowa określające stan obróbki nie są neutralnym opisem przed ani po nazwie.
Skończona lista rdzeni po normalizacji: `ugotowan`, `gotowan`, `upieczon`,
`pieczon`, `usmazon`, `smazon`, `surow`, `suszon`, `such`, `wedzon`, `kiszon`.
Obejmuje ona gotowanie, pieczenie, smażenie, surowość, suszenie, wędzenie
i kiszenie, bo te stany mogą wskazywać inną pozycję tabeli na 100 g. To nie
jest słownik wszystkich przymiotników. Wielkość, temperatura i „świeży”
pozostają w dotychczasowym kontrakcie; ochrona „mleka kokosowego” też.

Pełny alias nadal ma pierwszeństwo: „ugotowanych ziemniaków” trafia do
gotowanych ziemniaków, a „surowego boczku” do surowego boczku. Gdy słownik
nie ma pełnego aliasu, „ugotowany ryż” pozostaje składnikiem nieznanym.
Znana masa tego składnika nadal wchodzi do mianownika pokrycia D-299; przy
pokryciu poniżej 90% wynik nie pokazuje liczb. Nie przeliczamy masy po
ugotowaniu, nie dopisujemy nowego produktu do CSV i nie zmieniamy tekstu autora.

Test `SlownikStanProduktuTest` sprawdza `dopasuj()` z zaimportowanymi
aliasami oraz końcowy kalkulator. Dwie kontrole ujemne osobno usuwają ochronę
stanu przed i po nazwie; muszą oblać dokładnymi markerami. Rollback kodu
przywraca błędne dopasowanie krótkiego aliasu; dane i schemat bez zmian.
