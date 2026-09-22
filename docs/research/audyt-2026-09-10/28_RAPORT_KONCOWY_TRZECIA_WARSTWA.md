# Kuking.pl — raport końcowy, trzecia warstwa audytu

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026  
**Status:** aktualny raport nadrzędny; trzecia warstwa kodu na `fd164ad3…`, finalny delta-check `main` na `47dbb6cc9afb4a4000291d655cfc2b9061e6daf8`

## Decyzja

**Werdykt nie zmienia się: development/staging = GO; mała alpha = warunkowo; publiczna beta = NO-GO do zamknięcia bramek P0 i P1; szeroka kampania migracyjna Garnek.pl = NO-GO dodatkowo do realnego cold-startu społeczności i pomiaru retencji.**

Trzecia warstwa nie odkryła „dziurawego routingu” ani potrzeby zmiany architektury. Odkryła kolejne problemy typowe dla dojrzałego monolitu: inwarianty poprawne w jednej transakcji, ale nie przy dwóch równoległych procesach; koszt autoryzacji mnożony przez setki requestów obrazków; oraz rollback techniczny, który może zmienić znaczenie decyzji użytkownika.

## Finalny delta-check `main` — ważna korekta pierwszej warstwy

Przed spakowaniem `main` przesunął się o dwa commity z `fd164ad3…` do `47dbb6cc…`. Compare pokazuje, że zmieniono głównie dokumenty audytu/`OTWARCIE.md` oraz drobne elementy UX JS/rejestracji; **żaden z plików odpowiedzialnych za nowe P1 trzeciej warstwy nie został zmieniony**, więc MEDIA-01, MEDIA-03, SOCIAL-01 i MIG-01 pozostają aktualne na podstawie delta-review.

W repo pojawiło się także niezależne `SPRAWDZENIE.md`, które zakwestionowało jedną tezę pierwszej warstwy. Zweryfikowałem ją bezpośrednio w kodzie i korekta jest słuszna:

- zwykłe `Zgłoś` **ma** receipt przez `ReportContent`/`NotifyReporterReceipt`;
- zgłaszający z kontem **ma** informację o decyzji przez `NotifyReporterDecision`;
- istnieją numer sprawy i widoki własnych zgłoszeń;
- nieaktualny jest `MODERATION_PLAYBOOK.md`, który nadal opisuje stan sprzed issue #10.

Dlatego usuwam P0 „brak reporter lifecycle” z listy blockerów. Zastępuje go **P1 dokumentacyjno-operacyjny: playbook moderacji kłamie o działającym lifecycle**. Nie budować tej funkcji drugi raz.

Finalna weryfikacja przyniosła też dwa ważne doprecyzowania operacyjne:

- poczta **wychodzi z produkcji**; nadal blokuje ją rozjazd polityki z realnym open trackingiem EmailLabs;
- nowe zdjęcia **rzeczywiście idą przez podpisany R2** w sprawdzonym scenariuszu, ale stare media nadal są na wolumenie i formalna bramka #120 nie jest kompletna.

## Nowe P1 z trzeciej warstwy

### 1. MEDIA-01 — cleanup zdjęcia ściga się z attach

Sprzątacz osieroconych media i publikacja treści nie blokują wspólnego wiersza `media`. Cleanup może uznać upload za nieużywany i zacząć kasować R2, podczas gdy publikacja właśnie go przypina.

**Ryzyko:** utrata zdjęcia / niespójna treść po legalnym scenariuszu użytkownika.  
**Naprawa:** jeden `FOR UPDATE`/lock protocol dla attach i delete + prawdziwy concurrency test PG.

### 2. MEDIA-03 — autoryzacja zdjęć mnoży zapytania do PostgreSQL

`DostepDoZdjecia` wykonuje stały zestaw co najmniej pięciu lookupów rodziców dla każdego obrazu. `MediaController` może wykonać go ponownie jako anonymous dla decyzji cache. Feed legalnie generuje 100+ requestów zdjęć.

**Ryzyko:** p95 głównej powierzchni, przeciążenie DB, łatwa asymetria kosztu requestu.  
**Naprawa:** zmniejszyć parent lookup do jednego/few query, nie powtarzać grafu dla cacheability; zmierzyć query-count. Nie dodawać Redis przed tym pomiarem.

### 3. SOCIAL-01 — `block` i `follow` mogą współistnieć po race

`FollowUser` robi `check block → check follow → attach` bez locka, a `BlockUser` osobno zapisuje block i usuwa follows. Można wymusić kolejność, w której follow zostaje zapisany już po wykonaniu blokady.

**Ryzyko:** naruszenie granicy prywatności i powiadomienie po blokadzie.  
**Naprawa:** serializacja obu operacji po tej samej parze użytkowników, lock rows/advisory lock, re-check pod lockiem.

### 4. MIG-01 — rollback `delete_scope` może zgubić wybór „everything”

`down()` usuwa kolumnę zakresu kasowania. Po ponownym `up()` brak jest backfillowany jako `minimum`.

**Ryzyko:** techniczny rollback może zmienić późniejsze wykonanie żądania usunięcia danych.  
**Naprawa:** forward-only w produkcji albo `down()` odmawiający wykonania, gdy istnieją semantycznie nieodwracalne wartości.

## P1 z drugiej warstwy nadal otwarte na analizowanym snapshotcie

1. race `ConfirmEmailChange` vs anulowanie/reset hasła;
2. race wystawiania magic-link i możliwy side-channel/500 dla istniejącego konta;
3. nieatomowa rezerwacja dziennego budżetu poczty;
4. digest bez trwałej idempotencji po częściowym crashu.

Łącznie audyt ma więc **osiem kodowych/inwariantowych P1**, które powinny zostać zamknięte przed szerokim ruchem, niezależnie od pierwszej warstwy operacyjno-prawnej.

## Nowe P2 trzeciej warstwy

- `ProcessUploadedImage` może zostawić wariant utworzony przed awarią, którego `metadata` nie zna;
- moderator ma dostęp do zwykłych draftów przepisu;
- moderator ma blanket shortcut do dowolnego `ready` media;
- production `SESSION_ENCRYPT=true` trzeba potwierdzić, bo świeże recovery codes 2FA przechodzą przez flash session;
- Turnstile powinien sprawdzać `hostname/action` po `success=true`;
- Cloudflare purge należy agregować i respektować rate limit/Retry-After;
- równoległe podwójne follow powinno być idempotentne;
- rollback danych musi być odseparowany proceduralnie od rollbacku obrazu aplikacji.

## Co poprawiło się w czasie audytu

Repo było aktywnie rozwijane. Po snapshocie drugiej warstwy `e3cf6ab5…` doszły m.in. zmiany upraszczające landing page i onboarding, a aktualny snapshot `fd164ad3…` zawiera wyszukiwanie znajomych podczas onboardingu i mocniejsze testy widoczności. To częściowo redukuje wcześniejsze problemy UX/cold-start dotyczące pierwszego sukcesu użytkownika.

Nie wpływa to jednak na nowe P1: dotyczą transakcji, storage, DB query i rollbacków.

## Pełna bramka publicznej bety

### Operacyjne/prawne P0/P1 z pierwszej warstwy

- dokończona bramka R2 #120 i migracja starych mediów; nowe uploady mają już częściowy dowód produkcyjny signed-R2;
- automatyczny offsite DB backup + wykonany pełny restore drill + RPO/RTO;
- EmailLabs: poczta produkcyjna działa, ale open tracking musi zostać wyłączony/ujawniony i zweryfikowany na realnym MIME;
- aktualny `MODERATION_PLAYBOOK.md` zgodny z już działającym reporter lifecycle;
- trwały priorytet/SLA P0–P3 w moderacji;
- zamknięty direct-origin / Host trust;
- niezależny uptime + udany post-deploy smoke;
- finalny legal review, ROPA, DPA i aktualny status polskiego DSA;
- realne testy UX 50–75, szczególnie 60–75.

### Kodowe P1 z drugiej i trzeciej warstwy

- email change atomics;
- magic-link issuance atomics;
- mail-budget atomic reservation;
- digest idempotency;
- media attach/delete lock protocol;
- media authorization query reduction;
- block/follow pair serialization;
- forward-only/guard dla `delete_scope` i bezpieczna polityka rollbacków zgód.

## Bramka szerokiej kampanii „tęsknisz za Garnek.pl?”

Poza wszystkim wyżej:

- około 20–30 **realnych** aktywnych osób przed szerokim ruchem;
- około 100–150 autentycznych wpisów/przepisów/zdjęć;
- 7–10 dni aktywności, aby feed nie wyglądał jak seed/demo;
- odpowiedzi/komentarze i relacje follow, nie tylko statyczne treści;
- zmierzona aktywacja, first response, D1/D7 przed zwiększeniem skali promocji.

## Zalecana kolejność napraw

### Sprint A — inwarianty bezpieczeństwa i danych

1. email change lock order;
2. magic-link lock/replace;
3. `block↔follow` pair lock;
4. `media cleanup↔attach` row lock;
5. realne testy PG na dwóch połączeniach dla wszystkich czterech.

### Sprint B — messaging i operacyjność

1. atomowy mail budget;
2. digest delivery/idempotency key;
3. queued/accepted/failed telemetry;
4. alert finalnych `failed_jobs`;
5. EmailLabs tracking/deliverability proof.

### Sprint C — wydajność media

1. query-count benchmark jednego redirectu;
2. redukcja 5 parent-query per media;
3. eliminacja podwójnego permission graph dla cacheability;
4. test feedu 30/100 zdjęć;
5. dopiero potem decyzja, czy PostgreSQL-backed cache/session nadal wystarcza.

### Sprint D — prywatność i deploy

1. forward-only guards dla consent/delete-scope;
2. retained UGC vs rzeczywista anonimizacja;
3. formalny Art.15 proces vs self-service portability;
4. restore po usunięciu danych;
5. DPA/ROPA/DSA review.

## Co nadal NIE jest potrzebne

- mikroserwisy;
- algorytmiczny feed;
- Redis bez zmierzonego powodu;
- i18n przed product-market fit;
- arbitralne cięcie 50 MP do 32–36 MP;
- duży system event sourcing tylko po to, by naprawić kilka race conditions;
- Terraform/Sentry/PostHog jako substytut podstawowych backupów i monitoringu.

## Ostateczna ocena

Kuking.pl jest funkcjonalnie i architektonicznie **bliżej dobrego startu niż sugerowałaby liczba raportów**. Duża liczba ustaleń wynika z głębokości audytu, a nie z chaosu kodu. Największe ryzyko nie leży dziś w „braku funkcji”, tylko w sytuacjach, których zwykły happy-path nie pokazuje: dwie równoległe operacje, crash między DB i usługą zewnętrzną, rollback semantycznych danych oraz koszt requestu pomnożony przez setkę zdjęć.

Po zamknięciu P0 operacyjno-prawnych i ośmiu P1 kodowych projekt nadaje się do kontrolowanej publicznej bety. Szeroką kampanię migracyjną należy odpalić dopiero na już żyjącą społeczność, nie po to, żeby tę społeczność dopiero stworzyć.
