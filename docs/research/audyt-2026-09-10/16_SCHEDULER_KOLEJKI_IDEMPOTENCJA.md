# Audyt 16 — scheduler, kolejki, idempotencja i awarie częściowe

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026

## Wniosek

Scheduler jest świadomie napisany pod **jedną replikę** i unika `proc_open`, ale kilka zadań nie ma pełnej semantyki „exactly-once / at-least-once z idempotencją”. Najważniejszy błąd jest w tygodniowym digescie: skutek zewnętrzny — wstawienie wiadomości do kolejki — następuje przed trwałym oznaczeniem odbiorcy jako obsłużonego. Crash między tymi krokami może dać duble.

## Ustalenia

### QUEUE-01 — P1 — digest może wysłać duplikaty po crashu w połowie przebiegu

**Pliki:**
- `app/Console/Commands/WyslijPodsumowaniaTygodnia.php`
- `app/Domain/Digest/OdbiorcyDigestu.php`
- `app/Domain/Security/DziennyBudzetListow.php`

Przebieg dla każdej osoby:

1. `Mail::to(...)->queue($list)` — skutek zostaje zapisany w kolejce;
2. `budzetDnia->zajmij()`;
3. sygnał `WEEKLY_DIGEST_SENT`;
4. użytkownik trafia do tablicy `$wyslane`.

Dopiero **po całej pętli** wykonywane jest `OdbiorcyDigestu::oznaczWyslane($wyslane)`.

Jeżeli proces padnie po zakolejkowaniu N wiadomości, ale przed końcowym `oznaczWyslane`, następny przebieg może ponownie zakwalifikować te same osoby. `withoutOverlapping()` nie chroni przed kolejnym przebiegiem po crashu.

**Naprawa rekomendowana:**
- utworzyć trwały klucz idempotencji per `user_id + okres_digestu` (np. poniedziałek/ISO week);
- `UNIQUE(user_id, period_start)` jako twarda bariera;
- w jednej transakcji zarezerwować wysyłkę, a dopiero potem dispatch;
- alternatywa: transactional outbox, gdzie wiersz outboxa i rezerwacja powstają atomowo, worker wysyła idempotentnie;
- osobno rozróżnić `reserved/queued/accepted/delivered/failed`, jeśli potrzebna jest prawdziwa telemetria dostawy.

---

### QUEUE-02 — P2 / bramka skalowania — harmonogram zakłada jedną replikę

`routes/console.php` sam to dokumentuje: `withoutOverlapping()` nie jest blokadą między niezależnymi schedulerami na kilku instancjach, a przy zwiększeniu liczby replik każde zadanie wymaga `onOneServer()` lub innego leader election.

To **nie jest błąd obecnej konfiguracji**, o ile rola uruchamiająca scheduler faktycznie istnieje tylko w jednym egzemplarzu. Jest to natomiast twarda bramka przed skalowaniem poziomym.

**Kryterium:**
- albo scheduler jako osobna rola z `replicas = 1`,
- albo współdzielony store locków + `onOneServer()`,
- albo zewnętrzny scheduler/cron wywołujący pojedynczą kolejkę z idempotentnymi jobami.

---

### QUEUE-03 — P2 — brak udowodnionego operator alertu dla finalnych `failed_jobs`

Kod sam zauważa, że po trzech nieudanych próbach e-mail może wylądować w `failed_jobs`. W przeglądzie:
- `/health` sprawdza DB, migracje, media i Turnstile;
- nie znaleziono checku backlogu/`failed_jobs`;
- nie znaleziono `Queue::failing(...)` wysyłającego alarm operatorowi;
- `kuking:sprawdz-poczte` potrafi diagnostycznie policzyć/wyjaśnić awarie, ale jest komendą uruchamianą ręcznie.

To nie znaczy, że zewnętrzny Railway/Sentry nie alarmuje — panelu nie audytowałem. Z repo nie ma jednak dowodu.

**Naprawa:**
- operator alert po finalnym failure dla kolejek `high/default/media`;
- alert na `failed_jobs > 0` z deduplikacją;
- osobny alert na „najstarszy job w kolejce > X min”;
- `/health` może pozostać HTTP 200/degraded, aby nie robić restart-loop; monitoring ma czytać treść/status metryki.

---

### QUEUE-04 — P2 — żądanie eksportu danych nie jest atomowo „jedno aktywne na konto”

Szczegóły w audycie 18/20. Kontroler robi `exists()` i później osobny `INSERT`, a schema nie wymusza jednego `queued/processing`.

Skutek: równoległy double-submit może uruchomić dwa ciężkie eksporty tego samego konta.

## Pozytywy

- `Schedule::call(fn () => Artisan::call(...))` jest sensownym obejściem przy świadomie wyłączonym `proc_open`.
- `withoutOverlapping()` jest stosowane konsekwentnie na pojedynczej instancji.
- ciężkie media mają `tries`, `timeout` i `failed()` zmieniający stan modelu, więc rekord nie powinien wisieć bez końca w `processing`;
- cleanup eksportów jest powtarzalny i nie traci adresu obiektu przy nieudanym kasowaniu;
- anonimizacja konta wykonuje kasowanie storage **po** commit transakcji DB, co jest właściwą kolejnością dla nieodwracalnego efektu zewnętrznego.

## Rekomendowany wzorzec dla całego projektu

Dla operacji „DB + kolejka/API/storage” stosować jedno z:

1. **transactional outbox**;
2. twardy **idempotency key** z `UNIQUE`;
3. rezerwację stanu w DB (`reserved`) i idempotentnego workera;
4. przy prostych operacjach — `lockForUpdate` + jednoznaczny state machine.

Nie próbować uzyskiwać exactly-once samym `withoutOverlapping()`.

