## D-070 — Zapisy przepisu przez różne osoby łączą się w jedno powiadomienie, dopóki autor go nie przeczyta (#906, PR #1213, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (20.09 — kształt
powiadomienia, 23.09 — potwierdzenie łączenia różnych osób) · Status:
**obowiązuje**

> Numer D-070 był wcześniej rezerwacją niescalonej gałęzi
> `claude/priorytet-w-kolejce-moderacji` (patrz przekazanie pracy z 10.09);
> rezerwacja została zwolniona i numer nadano tej decyzji.

### Co się łączy

Powiadomienia typu `recipe.saved` („ktoś ma Twój przepis w swoim zeszycie")
dla **tego samego autora i tego samego przepisu**. Zapisy od RÓŻNYCH osób nie
tworzą osobnych wierszy — dokładają się do jednego powiadomienia, dopóki jest
ono **nieprzeczytane** (`read_at IS NULL`). Treść: „Jan ma Twój przepis …"
przy jednej osobie, „Jan oraz 3 inne osoby zapisały Twój przepis …" przy
kilku, z pełną polską odmianą liczebnika (`Notification::tresc()`).
Powiadomienia innych typów i innych przepisów się nie łączą.

### Kiedy powstaje nowe powiadomienie

- **Pierwsza osoba** — gdy dla tego przepisu nie ma otwartego
  (nieprzeczytanego) powiadomienia. Autor dostaje je **natychmiast**, bez
  czekania na partię.
- **Po przeczytaniu** — przeczytane powiadomienie jest zamkniętą historią
  i nie zmienia się. Następny zapis (także osoby, która już była w tamtej
  partii, jeśli w międzyczasie wyjęła przepis ze wszystkich zeszytów i zapisała
  go od nowa) otwiera nowe powiadomienie.

### Jak liczymy osoby

- Liczą się **osoby, nie zeszyty**. Jedna osoba zapisująca przepis do kilku
  SWOICH zeszytów liczy się **raz** — powiadomienie idzie przy pierwszym
  zeszycie, kolejne nic nie dokładają (`SaveRecipeToCollection`).
- Osoba już obecna w otwartej partii nie jest dopisywana drugi raz
  (`data.savers` to lista unikalnych identyfikatorów w kolejności zapisu).
- **Wycofanie przed przeczytaniem:** kto wyjmie przepis ze **wszystkich**
  swoich zeszytów, znika z partii; jeśli był jedyny — powiadomienie znika.
  Wyjęcie z jednego z kilku zeszytów niczego nie zmienia.
- Z nazwy (i awatarem) wymieniamy pierwszą osobę z partii, która jest dla
  autora **widoczna** — bez blokady w żadną stronę i bez statusu
  z `User::STATUSY_UKRYWAJACE_TRESC`. Osoby niewidoczne **nie są wymieniane,
  ale zostają w liczbie** „N innych osób” (liczba, nie imiona — D-081).
  Blokada albo ban ustawione **po** zapisie działają tak samo: miejsce z imieniem
  przejmuje następna widoczna osoba, reszta partii nie znika.
- **Widoczność całego powiadomienia liczy się po `data.savers`, nie po
  `actor_id`** (`Notification::scopeVisibleTo()`). Wiersz znika z listy
  i z licznika dopiero wtedy, gdy niewidoczni są **wszyscy** z partii —
  dokładnie jak pojedyncze powiadomienie od zablokowanej osoby. Blokada
  nie kasuje wiersza, więc odblokowanie go przywraca. Wcześniej wystarczyło
  zablokować pierwszą osobę, żeby zniknęło całe „A oraz 2 inne osoby…”,
  a każdy następny zapis dopisywał się do ukrytego wiersza (przegląd PR #1213).
- Otwarta partia, w której dziś nie widać nikogo, **przyjmuje** nową osobę:
  ta właśnie przeszła kontrolę blokady, więc wiersz staje się widoczny
  i pokazuje ją z imienia. Osobny wiersz złamałby zasadę jednej otwartej
  partii na parę (autor, przepis).
- `data.savers` trzyma **pełną** listę, bez obcinania: potrzebna do
  pominięcia osoby już obecnej i do wycofania zapisu. Partia żyje tylko
  do odczytania, a nagłówek kosztuje stałą liczbę zapytań niezależnie od
  jej długości (jedno o pierwszą widoczną osobę + jej profil,
  `Notification::zapisujacyDoPokazania()`).

### Wiek partii

Dołączenie nowej osoby **przesuwa `created_at` na teraz**. Lista jest
ułożona od najnowszego, a retencja (`SprzatajPowiadomienia`, 3 miesiące)
liczy wiek od `created_at` — bez tego świeży zapis lądował głęboko na liście
i znikał razem z partią założoną miesiące wcześniej. Wycofanie zapisu
`created_at` nie rusza.

### Współbieżność

Każda zmiana partii (zapis, dołączenie, wycofanie) idzie pod **blokadą
doradczą** `pg_advisory_xact_lock(906, hashtext('<autor>:<przepis>'))`,
trzymaną do końca transakcji. `SELECT … FOR UPDATE` sam nie wystarczał:
przy braku partii nie ma czego zablokować, więc dwa równoległe pierwsze
zapisy zakładały dwa wiersze. `SaveRecipeToCollection` bierze tę samą
blokadę **przed** policzeniem zeszytów osoby, żeby równoległy zapis jednej
osoby do dwóch jej zeszytów nie liczył się dwa razy. Pomiar na dwóch
połączeniach: `tests/Dwa/ZbiorczyZapisNaDwochPolaczeniachTest.php`.

**Odczytanie a dołączenie — świadomie zostawiony wyścig.** „Oznacz jako
przeczytane” (`UPDATE … WHERE read_at IS NULL`) nie bierze blokady partii.
Kiedy przegrywa z dołączeniem, czeka na blokadę wiersza i zamyka partię
**razem** z osobą, która doszła, gdy autor miał otwartą starszą wersję
listy — ta osoba jest w treści przeczytanego powiadomienia, ale autor mógł
jej nie zauważyć jako nowej. Kiedy wygrywa, dołączenie widzi `read_at`
i otwiera nową partię. Nic nie ginie z bazy ani z listy; najgorszy skutek to
jedna osoba zaliczona do już przeczytanej wiadomości. Domknięcie tego
wymagałoby wersjonowania treści przy odczycie — nie jest tego warte przy
powiadomieniu, które tylko cieszy.

### Granice — te same co w `NotifyUser`

Zbiorcze powiadomienie powstaje w `NotifyRecipeSaved`, nie w `NotifyUser`
(musi aktualizować istniejący wiersz pod blokadą partii), więc powtarza
jego granice wprost: brak powiadomienia o własnej akcji, brak dla konta,
które nie może czytać (`mozeCzytac()` — zawieszony autor DOSTAJE), brak przy
blokadzie w którąkolwiek stronę. Czwarta granica jest właściwa zapisowi:
zapis z konta, które nie jest aktywne, nikogo nie powiadamia (#926). Każda
granica dotyczy i nowego wiersza, i dołączenia do otwartej partii —
`tests/Feature/ZbiorczyZapisTrzymaGranicePowiadomienTest.php`,
`tests/Feature/ZbiorczePowiadomienieOZapisieTest.php`.

### Co musiałoby się stać, żeby to zmienić

Sygnał, że autorzy przegapiają nowe osoby w partii (np. chcą osobnej
wiadomości za każdego), albo powiadomienia z ustawieniami użytkownika —
wtedy granice trzeba przenieść do jednego miejsca, zamiast je powtarzać.
