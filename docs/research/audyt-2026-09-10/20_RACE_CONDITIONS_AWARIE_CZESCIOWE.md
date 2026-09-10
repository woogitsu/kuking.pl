# Audyt 20 — race conditions, idempotencja i awarie częściowe

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026

## Wniosek

Największy nowy wniosek drugiej warstwy: Kuking ma dużo poprawnych transakcji i constraints, ale **część krytycznych inwariantów żyje jeszcze jako sekwencja „sprawdź → zrób” zamiast jako atomowa operacja**. Przy pojedynczych requestach wszystko wygląda poprawnie; problem ujawnia się dopiero przy double-submit, dwóch workerach albo crashu między efektem zewnętrznym i commitem stanu.

## Macierz ryzyk

| ID | Obszar | Wyścig / partial failure | Priorytet | Twarda bariera docelowa |
|---|---|---|---|---|
| RACE-01 | zmiana e-maila | confirm może wygrać z cancel/reset | **P1** | `FOR UPDATE` + rewalidacja w jednej transakcji |
| RACE-02 | magic-link | równoległe `DELETE+INSERT` może dać unique violation tylko dla istniejącego konta | **P1** | blokada użytkownika + neutralny catch konfliktu |
| RACE-03 | mail budget | oba requesty widzą ostatnie wolne miejsce | **P1** | atomowa rezerwacja `used < limit` |
| RACE-04 | digest | queue side-effect przed trwałym oznaczeniem odbiorcy | **P1** | idempotency key / outbox |
| RACE-05 | data export | `exists` przed `INSERT` | **P2** | partial unique index |
| RACE-06 | request email change | równoległe `DELETE+INSERT` | **P2** | blokada `users.id` |
| RACE-07 | scheduler | wiele replik odpali te same `Schedule::call` | **P2 / scale gate** | 1 scheduler albo `onOneServer` + idempotencja |

## Dlaczego constraints są lepsze niż test „czy już istnieje”

Przy PostgreSQL inwariant powinien być w bazie wszędzie, gdzie da się go wyrazić:

- „jeden aktywny eksport na usera” → częściowy `UNIQUE`;
- „jeden token magic-link na usera” → `UNIQUE` już jest, ale trzeba obsłużyć konflikt/serializować;
- „jedno wysłanie digestu na okres” → `UNIQUE(user_id, period_start)`;
- „użycie budżetu nie przekracza limitu” → warunkowy `UPDATE`/rezerwacja.

`exists()` w PHP jest dobry dla UX („pokaż ładny komunikat”), ale **nie jest gwarancją integralności**. Gwarancję daje constraint/lock/atomic update.

## Wzorzec naprawy

### 1. DB-only state transition

```text
BEGIN
SELECT ... FOR UPDATE
ponowna walidacja aktualnego stanu
UPDATE/INSERT
COMMIT
```

### 2. DB + kolejka

Preferowane:
```text
BEGIN
INSERT idempotency/reservation
INSERT outbox
COMMIT

worker:
  pobierz outbox
  wykonaj efekt
  oznacz wynik
```

### 3. DB + storage

Obecny `EraseAccountData` pokazuje dobry kierunek:
- najpierw commit stanu DB,
- później nieodwracalny delete obiektu,
- zachowaj retry handle, jeśli delete nie wyszedł.

## Minimalny zestaw testów współbieżności

Testy muszą używać prawdziwego PostgreSQL i dwóch niezależnych połączeń/procesów. Zwykły PHPUnit w jednym połączeniu nie odtworzy interleavingu.

Scenariusze:
1. email-confirm vs password-reset;
2. dwa magic-link requesty na to samo konto;
3. dwa requesty zmiany e-maila;
4. dwa requesty eksportu;
5. dwa równoległe `tryReserve` przy `limit-1`;
6. crash digestu po zakolejkowaniu pierwszej wiadomości i ponowne uruchomienie.

## Kryterium zamknięcia

Dla każdego P1 ma istnieć nie tylko test „normalna ścieżka”, lecz test, który **wymusza konkretny interleaving** i wykazuje, że końcowy stan jest jednoznaczny.

