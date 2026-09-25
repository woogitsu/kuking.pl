## D-252 — Aplikacja sama dosyła zaległe potwierdzenia przyjęcia zgłoszeń, co godzinę (#797, DSA art. 16 ust. 4, 23 września 2026)

**Data:** 23 września 2026 · Decyzja właściciela · Status: **obowiązuje**

### Problem

Potwierdzenie przyjęcia zgłoszenia (powiadomienie w serwisie
`report.received` + znacznik `reports.receipt_sent_at`) powstaje POZA
transakcją zapisu sprawy — celowo, żeby awaria powiadomienia nie zabrała
człowiekowi przyjętego zgłoszenia. Awaria zostawia sprawę z pustym
znacznikiem. Dokańczał ją tylko powrót człowieka do tej samej sprawy;
sprawa, do której nikt nie wraca, zostawała bez potwierdzenia na zawsze,
a art. 16 ust. 4 DSA wymaga potwierdzenia „bez zbędnej zwłoki".

### Decyzja

**TAK — aplikacja co godzinę dosyła zgłaszającym potwierdzenia, które
wcześniej nie wyszły.** Robi to komenda
`kuking:dosylaj-potwierdzenia-zgloszen` w harmonogramie (minuta 35 każdej
godziny; 25 zajmuje `kuking:budzet-polaczen`).

- **Najwyżej jedno potwierdzenie na zgłoszenie.** Komenda woła tę samą
  akcję co formularz (`NotifyReporterReceipt::handle()`); zamkiem jest
  warunkowy `UPDATE ... WHERE receipt_sent_at IS NULL` w jednej transakcji
  z utworzeniem powiadomienia. Gdy dosyłka zbiegnie się z człowiekiem
  wracającym do tej samej sprawy, wiersz dostaje dokładnie jedno
  potwierdzenie — ten jeden przeplot mierzy
  `tests/Dwa/DosylkaNieDublujePotwierdzeniaTest` na dwóch połączeniach.
  Dwóch przebiegów dosyłki naraz nikt nie mierzy osobno: nie dopuszczają
  ich `onOneServer()` i `withoutOverlapping(50)`, a gdyby do nich doszło,
  chroni ten sam warunkowy `UPDATE`.
- **Partiami.** `--ile` (domyślnie 200) ogranicza jeden przebieg,
  najstarsze sprawy idą pierwsze, reszta czeka na następną godzinę.
- **Sprawa, która pada stale, nie zatyka kolejki.** Porażki są liczone
  per sprawa (cache, klucz `kuking:dosylka-potwierdzen:porazki`, 30 dni);
  po 3 porażkach z rzędu sprawa idzie na koniec kolejki i dostaje próbę
  dopiero, gdy w partii zostaje miejsce po sprawach zdrowych. Nie przepada:
  dalej liczy się jako zaległość, a jej porażka dalej daje kod ≠ 0. Udana
  próba albo zniknięcie zaległości zeruje licznik. Licznik nie jest
  w kolumnie, bo to stan roboczy dosyłki, nie fakt o sprawie — jego utrata
  kosztuje tylko kilka dodatkowych prób.
- **Nie jest zaległością** (i nie wchodzi do licznika): zgłoszenie bez
  konta (droga prawna ma własne potwierdzenie mailowe), zgłaszający
  z kontem wymazanym (brak czytelnika), sprawa, której rozstrzygnięcie
  (art. 16 ust. 5, `decision_sent_at`) już doszło — potwierdzenie mówi
  „sprawdzimy i napiszemy, co postanowiliśmy", więc po decyzji byłoby
  nieprawdą, a informacja o decyzji niesie ten sam numer sprawy. Warunek
  decyzji stoi także w samym zamku, więc decyzja doręczona w trakcie
  przebiegu wygrywa. Sprawa rozstrzygnięta BEZ doręczonej decyzji
  potwierdzenie dostaje — to wtedy jedyny ślad, że zgłoszenie doszło.
- **Ping skasowany przez retencję nie jest zaległością** — komenda pyta
  o znacznik, nigdy o istnienie powiadomienia.
- **Porażka widoczna.** Awaria jednej sprawy nie zatrzymuje partii, ale
  komenda kończy się kodem ≠ 0, a wspólny adapter
  `App\Support\Harmonogram::artisan()` zamienia go w wyjątek (#835). Zadanie ma `onOneServer()` (#595)
  i `withoutOverlapping(50)` (#1002).

### Czego ta decyzja NIE rozstrzyga

Górnej granicy wieku sprawy: komenda dośle potwierdzenie także do
zgłoszenia sprzed roku. „Od kiedy jest za późno" wymaga osobnej decyzji.
Nie dotyczy też informacji o rozstrzygnięciu, która nie doszła —
to osobna zaległość bez własnej dosyłki.

### Dowód

`tests/Feature/DosylkaZaleglychPotwierdzenTest.php`,
`tests/Dwa/DosylkaNieDublujePotwierdzeniaTest.php`.

### Wycofanie

Usunąć zadanie z `routes/console.php` (komenda może zostać do ręcznego
użycia). Schemat się nie zmienia; wysłanych powiadomień nie trzeba cofać.
