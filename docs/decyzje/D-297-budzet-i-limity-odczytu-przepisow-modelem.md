## D-297 — Budżet i limity odczytu przepisów modelem: 5 USD dziennie, 100 USD miesięcznie, 5/30 odczytów na osobę (26 września 2026)

**Data:** 26 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
Dotyczy: V2 import/OCR (D-282, D-298), projekt `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §3.3

**Decyzja właściciela dosłownie:** budżet 5 USD/dzień i 100 USD/mies.
(w konfiguracji) + limit na osobę 5 importów/dzień i 30/mies. (propozycja
z projektu).

### Co obowiązuje

- **Budżet serwisu w PostgreSQL**, tabela `ai_budzet_dzienny` (jeden wiersz
  na dzień w strefie `Europe/Warsaw`, kwoty w mikro-USD). Bez Redisa i bez
  cache'u — to są pieniądze, a licznik w cache'u znika przy restarcie.
- **Rezerwacja przed wywołaniem, rozliczenie po nim** (`App\Domain\Import\BudzetAi`).
  Rezerwacja = najgorszy przypadek: pełne okno kontekstu zatwierdzonego
  `gpt-6-luna` (1 050 000 tokenów, również dla wszystkich stron skanu PDF)
  × 2 × cena wejścia + sufit tokenów wyjścia (z rozumowaniem) × 1,5 × cena
  wyjścia (droższa taryfa modelu po 272 tys. tokenów wejścia).
  Model inny niż `gpt-6-luna` lub cennik poniżej 0,10/0,50 USD za milion
  tokenów wyłącza import, zamiast udawać twardy limit kosztu. Zapis pod
  `SELECT … FOR UPDATE` na wierszu dnia szereguje równoległe odczyty.
  Rozliczenie z `usage` zwalnia nadwyżkę; **brak `usage` = cała rezerwacja
  wydana** (żądanie mogło dojść i zostać policzone). Rezerwację zwalniamy bez
  wydatku wyłącznie wtedy, gdy żądanie na pewno nie wyszło.
- **Brak cennika = brak wywołań.** Ceny w USD za milion tokenów
  (`KUKING_IMPORT_CENA_WEJSCIE`, `KUKING_IMPORT_CENA_WYJSCIE`) wpisuje się
  ręcznie z cennika OpenAI; bez nich nie da się zarezerwować budżetu, więc
  funkcja jest wyłączona tak samo jak bez klucza.
- **Limit na osobę liczony z bazy** (`importy_przepisow`, dzień i miesiąc
  w strefie człowieka), nie `RateLimiter`-em — ma być dokładny. Liczy się
  zlecenie (także „Odczytaj jeszcze raz”); zlecenia zatrzymane na limicie
  (`wstrzymany_limitem`) się nie liczą, bo do modelu nie poszły. Równoległe
  zlecenia jednej osoby szereguje blokada doradcza na czas transakcji.
  Osobno `throttle:import` (10 / 10 min) chroni samą trasę przed pętlą żądań.
- **Próg ostrzegawczy 80%** dziennego budżetu: jeden `Log::warning`
  (`stage=import_budzet_prog`) dziennie.
- **Moderacja nie jest w tym budżecie** — jest bezpłatna, ma osobny klucz,
  a wyczerpanie budżetu importu nie może jej zatrzymać.
- **Druga linia obrony poza naszym kodem:** osobny projekt OpenAI z limitem
  wydatków ustawionym w panelu dostawcy (krok właściciela).

### Co widzi człowiek

Wyczerpany budżet serwisu: przycisk zostaje, ale NAD nim stoi informacja,
zanim ktoś kliknie; zlecenie złożone mimo to kończy się stanem
`wstrzymany_limitem` z komunikatem „Twoje zdjęcie jest zapisane…”. Limit
osoby: szkic ze zdjęciem i tak powstaje, a komunikat mówi, kiedy można dalej.

### Dowód

`tests/Feature/Import/BudzetAiTest.php`, `tests/Dwa/BudzetAiNaDwochPolaczeniachTest.php`
(dwie rezerwacje naraz, limit na jedną — jedna przechodzi),
`tests/Feature/Import/CofniecieBudzetuAiOdmawiaTest.php` (rollback odmawia
przy wydatkach w bieżącym miesiącu — D-088).

### Wycofanie / zmiana

Kwoty i limity zmienia się w env (`KUKING_IMPORT_BUDZET_DZIEN`,
`KUKING_IMPORT_BUDZET_MIESIAC`, `KUKING_IMPORT_NA_OSOBE_DZIEN`,
`KUKING_IMPORT_NA_OSOBE_MIESIAC`) — zmiana domyślnych wartości w repo wymaga
nowej decyzji właściciela.
