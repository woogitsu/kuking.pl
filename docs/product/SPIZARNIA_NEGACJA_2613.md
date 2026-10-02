# „Bez soli” nie jest dowodem posiadania soli (#2613)

## Naprawa

Reguła D-285 nadal dopuszcza produkt ogólny w jego odmianie, np. masło przy
„200 g masła bez soli”. Poprzednie zawieranie rdzeni dopuszczało też sól,
chociaż sól występuje tam wyłącznie w przeczeniu. Jeden wspólny warunek
`CoUgotuje::PASUJE_SQL` obowiązuje w doborze przepisu, liczbie i treści braków,
liczeniu pilnych produktów oraz liście „Zużyjesz”.

Zapisane rdzenie i indeks GIN nadal zawężają kandydatów. Wyłącznie gdy tekst
linijki zawiera całe słowo „bez”, warunek dodatkowo sprawdza rdzenie po
odrzuceniu klauzuli „bez …” do przecinka, średnika, kropki kończącej zdanie,
wykrzyknika lub znaku zapytania. Kropka w liczbie dziesiętnej, np. `0.5`,
nie kończy klauzuli. W niejasnym zdaniu bez granicy zachowawczo pozostawia
składnik w brakach. Słowo użyte również poza przeczeniem nadal może pasować.
Nie zmieniono funkcji SQL, schematu ani kolejności rekomendacji.

## Dowód i wycofanie

`CoUgotujeNegacjaSkladnikaTest` tworzy prawdziwe produkty i przepisy w PG18,
odczytuje wynik domenowy i widok HTTP w obu trybach oraz sprawdza pozytywne
masło, sól i interpunkcję. Centralna kontrola ujemna wyłącza tylko nową
ochronę i wymaga porażki z markerem `SPIZARNIA_2613_SOL_NIE_JEST_MASLEM`,
po czym przywraca plik. `CoUgotujeKosztTest` nadal pilnuje zera przeliczeń
rdzeni dla zwykłych linijek oraz planu z indeksem GIN.

Wycofanie commitu przywraca błędne dopasowanie soli do „masła bez soli”. Nie
ma migracji ani danych do cofania. Nie używamy tej reguły do wnioskowania o
alergenach lub bezpieczeństwie żywności.
