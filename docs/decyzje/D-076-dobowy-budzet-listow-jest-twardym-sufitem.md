## D-076 · Dobowy budżet listów jest twardym sufitem: jedna atomowa rezerwacja pod blokadą `Cache::lock()`, bez nowej tabeli

**Data:** 10 września 2026 · Źródło: audyt drugiej warstwy, **MAIL-01 / RACE-03 (P1)** · Status: **obowiązuje**

### Co było źle

`App\Domain\Security\DziennyBudzetListow` rozdzielał odczyt licznika od jego
zapisu — i tak też był wołany:

```php
if (! $budzet->jestMiejsce()) { odmów; }   // odczyt: 119 ze 120
// …dziesięć linii dalej…
$budzet->zajmij();                          // zapis: 120
```

Tak stało w `LoginLinkController::send()` (sprawdzenie i zajęcie w odległości
dziesięciu linii) i w `kuking:wyslij-podsumowania` (rozmiar paczki liczony raz
z `zostalo()`, przed pętlą). Przy suficie 120 i zużyciu 119 dwa równoległe
żądania czytają oba 119, oba widzą wolne miejsce, oba wysyłają list i oba
inkrementują licznik. Wychodzi 121 listów przy sufcie 120.

`Cache::increment()` jest atomowy jako POJEDYNCZA operacja — i to właśnie
usypiało czujność. Para „sprawdź, a potem zajmij" nie jest atomowa jako para
i żadna liczba komentarzy w kodzie tego nie zmienia.

Nie da się tego naprawić sprawdzeniem PO inkrementacji („czy przekroczyliśmy?").
Wiadomość jest wtedy już zakolejkowana, przekroczenie już nastąpiło, a listu
z drogi nie cofniemy.

### Dlaczego to nie jest usterka kosmetyczna

Wiadro u dostawcy to 300 listów na dobę na CAŁY serwis (D-047), a z tego samego
wiadra idzie **potwierdzenie rejestracji**, które sufitu nie ma i mieć nie może
— nie da się go przełożyć na jutro. Sufit przeciekający o kilka listów pod
obciążeniem zabiera je dokładnie tam. Właściciel spodziewa się fali migracyjnej
z Garnek.pl, czyli dnia, w którym logowanie linkiem i rejestracja mają szczyt
w tej samej godzinie.

### Decyzja

1. **Jedna atomowa operacja rezerwacji:** `sprobujZarezerwowac(): bool`.
   Zajmuje miejsce i zwraca `true`, albo nie zajmuje niczego i zwraca `false`.
   W środku, pod blokadą, chodzi ta sama para co dawniej — ale nikt z zewnątrz
   nie może już wejść między jej dwa kroki.
2. **Wszystkie miejsca decydujące o wysyłce przeszły na tę metodę.** Sprawdzone
   `grep`iem: w `app/` nie została ani jedna para sprawdź-potem-zajmij.
3. **`jestMiejsce()` i `zostalo()` zostają jako ODCZYT** — do pokazania
   człowiekowi, do diagnostyki i do oszacowania rozmiaru paczki
   (`kuking:wyslij-podsumowania` nie pobiera z bazy stu odbiorców, gdy zostało
   pięć miejsc). Docblocki mówią teraz wprost, czego nimi robić nie wolno.
4. **Nie udało się zdobyć blokady w 2 sekundy → ODMOWA wysyłki.** Nie „wyślij
   na wszelki wypadek": przekroczony budżet u dostawcy odbija się na całej
   poczcie serwisu, a jedna niewysłana wiadomość odbija się na jednej osobie,
   która dostaje uczciwy komunikat i klika drugi raz.
5. **Miejsce, z którego nic nie wyszło, wraca do puli** (`zwolnij()`) — patrz
   niżej, „Rezerwacja przed wysyłką kontra stara reguła".

### Dlaczego blokada na istniejącym mechanizmie, a nie własna tabela z `UPDATE ... WHERE used < limit`

Warunkowy `UPDATE` byłby poprawny i byłby atomowy bez żadnej blokady — to
uczciwa alternatywa i została rozważona. Kosztuje jednak: nową tabelę,
migrację, wpis w `docs/DATABASE.md`, opisany rollback i sprzątanie starych
wierszy. Czyli **drugi mechanizm obok tego, który już mamy**, przy zasadzie
projektu mówiącej odwrotnie: żadnych nowych mechanizmów bez zmierzonej
potrzeby (AGENTS.md §3).

Rozstrzyga to, czym jest tu `Cache::lock()`. Sterownik cache w tym projekcie to
`database` (`config/cache.php` → `env('CACHE_STORE', 'database')`,
`.env.example` → `CACHE_STORE=database`), więc blokada jest **prawdziwa,
współdzielona między procesami i trwała**, oparta o tabelę `cache_locks`
z migracji `0001_01_01_000002_create_cache_table`. To ta sama tabela w tej
samej bazie, do której poszedłby własny warunkowy `UPDATE` — z tą różnicą, że
nie musimy jej pisać, migrować ani sprzątać.

Gdyby sterownikiem był `array`, blokada nie wychodziłaby poza jeden proces PHP
i cały ten sufit byłby atrapą. Ten warunek nie jest już domysłem: pilnuje go
`AtomowaRezerwacjaBudzetuTest::test_produkcyjny_sterownik_cache_daje_prawdziwa_wspoldzielona_blokade`.

**Redisa nie dodajemy** — projekt świadomie go nie ma (AGENTS.md §3), a
`database` tu wystarcza.

### Rezerwacja przed wysyłką kontra stara reguła „licz dopiero wysłane listy"

Sufit musi być zajmowany PRZED wysyłką, bo po niej jest już za późno na
cokolwiek. Ale przy logowaniu linkiem list wychodzi tylko wtedy, gdy pod
podanym adresem NAPRAWDĘ jest konto — i to nie jest szczegół: gdyby licznik
ruszał przy każdym wysłaniu formularza, byle automat wpisujący nieistniejące
adresy wyczerpałby dobowy budżet w kilka minut, nie wysławszy ani jednego
listu prawdziwej osobie.

Obie reguły trzymamy naraz: rezerwacja stoi przed wysyłką, a nieużyte miejsce
wraca do puli przez `zwolnij()`. Nieudane zdobycie blokady przy oddawaniu
zostawia licznik zawyżony o jeden i tak ma być — pomyłka idzie wtedy w stronę
„wyślemy o jeden list mniej", nie w stronę przekroczenia limitu dostawcy.
Regresję pilnuje istniejący `test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu`.

### Dwa powody odmowy, dwa różne zdania dla człowieka

Rezerwacja mówi tylko „nie", a te dwa „nie" znaczą dla człowieka coś zupełnie
innego. Przy wyczerpanym budżecie czekanie na list jest bezcelowe („nie czekaj
na niego"); przy ścisku na blokadzie budżet jest wolny i drugie kliknięcie
zwykle wystarcza. Zdanie „wysłaliśmy już wszystkie e-maile na dziś" w drugim
przypadku byłoby po prostu **nieprawdą**, a komunikaty w tym serwisie nie
opowiadają rzeczy, które się nie stały (D-056, ekran linku). Treść komunikatu
dobiera odczyt `jestMiejsce()` — już PO tym, jak rezerwacja rozstrzygnęła
o wysyłce.

### Czego świadomie nie zmieniono

- **Wartości sufitów w `config/kuking.php`** — ani jednej liczby. Podział
  wiadra pilnuje `PodzialLimituPocztyTest` i nie ma z tą usterką nic wspólnego.
- **List próbny `kuking:wyslij-podsumowania --tylko` stoi ponad sufitem**, tak
  jak przed tą zmianą: to jedna wiadomość wypuszczana ręcznie przez właściciela,
  który chce ZOBACZYĆ list. Ale musi się policzyć, więc gdy rezerwacja odmówi,
  miejsce zajmowane jest bezwarunkowo (`zajmij()`). To jedyne miejsce w kodzie,
  w którym wolno wołać `zajmij()` wprost.
- **Idempotencja tygodniowego digestu** — osobne zadanie, osobna gałąź.

**Zmiana wymaga:** zmierzonego problemu z blokadą na sterowniku `database`
(np. przy dziesiątkach żądań na sekundę na ten jeden klucz). Wtedy — i tylko
wtedy — wraca do rozważenia warunkowy `UPDATE` we własnej tabeli z pełnym
kompletem: migracja, test, `docs/DATABASE.md`, rollback.

📄 `app/Domain/Security/DziennyBudzetListow.php` ·
`app/Http/Controllers/Auth/LoginLinkController.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`tests/Feature/AtomowaRezerwacjaBudzetuTest.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`tests/Feature/TygodniowePodsumowanieTest.php` ·
`config/cache.php` · `database/migrations/0001_01_01_000002_create_cache_table.php` ·
D-047 · D-056 · D-057
