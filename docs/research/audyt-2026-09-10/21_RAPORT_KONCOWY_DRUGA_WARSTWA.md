# Kuking.pl — raport końcowy, druga warstwa audytu

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot drugiej warstwy:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026  
**Status dokumentu:** uzupełnia i ma pierwszeństwo interpretacyjne nad `14_RAPORT_KONCOWY_PRIORYTETY_I_BRAMKI.md` w obszarach współbieżności, poczty i lifecycle danych.

## Decyzja

**Werdykt pierwszej warstwy pozostaje bez zmian: publiczna beta i szeroka kampania migracyjna powinny poczekać. Druga warstwa dodaje cztery P1 w samym kodzie/kontrakcie, które należy zamknąć przed szerokim ruchem.**

Nie chodzi o „niedojrzały kod”. Przeciwnie: projekt ma dużo dobrych constraints, transakcji, polityk i testów. Właśnie dlatego pozostałe problemy leżą teraz głównie w miejscach trudniejszych: współbieżność, idempotencja, efekty zewnętrzne i granice prawne.

## Nowe priorytety P1

### 1. Atomowość zmiany e-maila

`ConfirmEmailChange` i `CancelEmailChange` nie współdzielą jednej sekcji krytycznej. Reset hasła może anulować żądanie, a równoległe potwierdzenie rozpoczęte wcześniej może je mimo to dokończyć.

**Naprawa:** lock + rewalidacja wewnątrz jednej transakcji, jedna kolejność blokad.

### 2. Race w wystawianiu magic-link

`UNIQUE(user_id)` jest poprawne, ale `DELETE + INSERT` bez serializacji może dać konflikt tylko dla istniejącego konta. To psuje UX double-submit i może naruszać antyenumeracyjny kontrakt endpointu.

**Naprawa:** lock usera podczas replace + catch konfliktu do neutralnego wyniku.

### 3. Dobowy budżet poczty nie jest atomowy

`jestMiejsce()` i `zajmij()` to dwie operacje. Równoległość może przekroczyć ustawiony limit.

**Naprawa:** `tryReserve()` jako jedna atomowa operacja.

### 4. Digest nie jest idempotentny po częściowym crashu

Wiadomości są zakolejkowane przed zbiorczym `oznaczWyslane()`. Restart w środku przebiegu może ponownie wybrać tych samych odbiorców.

**Naprawa:** trwały `UNIQUE(user, period)` lub outbox.

## Nowe P2 / decyzje projektowe

- jeden aktywny eksport danych na konto nie jest wymuszony przez DB;
- scheduler jest bezpieczny tylko przy jednej instancji;
- search/discovery ma niespójną semantykę dla `suspended`;
- sygnał `WEEKLY_DIGEST_SENT` w praktyce znaczy „queued”;
- brak repozytoryjnego dowodu na automatyczny alert dla finalnych `failed_jobs`;
- self-service export jest bliżej art. 20/portability niż udowodnionego pełnego art. 15 DSAR;
- retained UGC po usunięciu profilu nie może być automatycznie klasyfikowany jako anonimowy.

## Zaktualizowana lista bramek przed publiczną betą

### P0 / operacyjne i prawne z pierwszej warstwy

1. realne R2 i prywatność oryginałów;
2. backup offsite + wykonany restore drill;
3. realna, zweryfikowana poczta i brak konfliktu z polityką trackingu;
4. zgodny z publicznymi dokumentami workflow zgłoszeń/moderacji;
5. priorytety/SLA dla krytycznej moderacji;
6. zamknięcie direct-origin / Host trust;
7. realny post-deploy smoke i niezależny monitoring;
8. przegląd prawny/ROPA/DPA;
9. kolejne realne sesje UX 50–75;
10. cold-start społeczności przed kampanią Garnek.

### P1 / kod dodany przez drugą warstwę

11. race confirm/cancel e-mail;
12. race magic-link issuance;
13. atomowy budżet poczty;
14. idempotentny digest.

**Moja kolejność:** 11 → 12 → 13 → 14, bo pierwsze dwa dotykają przejęcia/odzyskania konta, a kolejne dwa niezawodności komunikacji.

## Co można uruchomić wcześniej

| Etap | Decyzja |
|---|---|
| development/staging | **GO** |
| wewnętrzne testy z kontami technicznymi | **GO** |
| mała alpha zaproszeniowa | **CONDITIONAL GO** po backup/storage/mail + AUTH P1 |
| publiczna beta | **NO-GO do zamknięcia P0 + czterech nowych P1** |
| szeroka kampania Garnek | **NO-GO dodatkowo do zbudowania cold-startu i zweryfikowania retencji** |

## Rzeczy, których druga warstwa NIE potwierdziła jako problem

- nie znalazłem prostego obejścia 2FA przez magic-link;
- konsumpcja magic-link ma poprawne `lockForUpdate` i single-use;
- reset hasła rotuje sesje;
- kasowanie zdjęć przy anonimizacji jest wykonane **po** commit DB i ma retry;
- dynamiczne `element.style.*` w `app.js` nie jest samo w sobie dowodem złamania obecnego CSP;
- sam fakt użycia `Schedule::call()` zamiast `Schedule::command()` jest uzasadniony przez hardening `proc_open`.

## Najbardziej opłacalny kolejny sprint

### Sprint bezpieczeństwo/inwarianty

- wspólny serwis/lock-order dla `RequestEmailChange`, `ConfirmEmailChange`, `CancelEmailChange`;
- serializowana wymiana magic-link;
- concurrency tests na PostgreSQL;
- partial unique index dla aktywnego eksportu.

### Sprint messaging

- atomowa rezerwacja budżetu;
- `digest_deliveries`/idempotency key;
- rozdzielenie queued/accepted/failed;
- automatyczny alert finalnych `failed_jobs`.

### Sprint zgodność

- przegląd retained UGC pod kątem prawdziwej anonimizacji;
- rozdzielenie self-service portability od pełnego Art.15 DSAR;
- aktualizacja polityki/usuwania tak, aby dokumentacja nie opierała się na uproszczeniu „tekst = nie dane osobowe”.

## Źródła zewnętrzne użyte w drugiej warstwie

- EDPB — Anonymisation / pseudonymisation:
  https://www.edpb.europa.eu/topics/ai-and-technology/anonymisation-pseudonymisation_en
- EDPB — Guidelines 02/2026 on Anonymisation (draft/public consultation w dniu audytu):
  https://www.edpb.europa.eu/public-consultations/guidelines-022026-on-anonymisation_en
- EDPB — Guidelines 01/2022, Right of Access:
  https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-012022-data-subject-rights-right-access_en
- RODO — EUR-Lex:
  https://eur-lex.europa.eu/eli/reg/2016/679/oj

## Końcowy werdykt

**Największym ryzykiem Kuking nie jest dziś framework, SQL ani brak funkcji. Jest nim rozjazd między „działa w pojedynczym happy path” a „zachowuje inwariant przy dwóch requestach, crashu workera i częściowym wykonaniu”.**

Po zamknięciu czterech P1 druga warstwa techniczna przechodzi z kategorii „przed szeroką betą poprawić” do „bardzo solidna baza MVP”. Nadal pozostają jednak bramki operacyjne z raportu 14 — przede wszystkim storage, restore, realna poczta, monitoring i legal/moderation.

