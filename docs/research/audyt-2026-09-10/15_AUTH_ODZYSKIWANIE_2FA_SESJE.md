# Audyt 15 — uwierzytelnianie, odzyskiwanie konta, 2FA i sesje

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026  
**Zakres:** logowanie hasłem, magic-link, reset hasła, 2FA, zmiana e-maila, rotacja sesji, wyścigi współbieżności.

## Wniosek

Architektura uwierzytelniania jest mocna: magic-link zastępuje tylko hasło, a nie 2FA; konta moderatorów/adminów są z niego wyłączone; token magic-link jest jednorazowy i przy konsumpcji blokowany `FOR UPDATE`; reset hasła rotuje sesje i unieważnia oczekującą zmianę e-maila.

Są jednak **dwa błędy współbieżności klasy P1**, oba na granicy bezpieczeństwa konta. Nie są to „teoretyczne race conditions” bez skutku — jeden może złamać gwarancję unieważnienia zmiany e-maila po resecie hasła, drugi może dawać 500 tylko dla istniejącego konta przy równoległym żądaniu magic-link, co narusza cel antyenumeracyjny.

## Ustalenia

### AUTH-01 — P1 — potwierdzenie zmiany e-maila może wygrać z jej anulowaniem

**Pliki:**
- `app/Domain/Users/Actions/ConfirmEmailChange.php`
- `app/Domain/Users/Actions/CancelEmailChange.php`
- `app/Http/Controllers/Auth/PasswordResetController.php`
- `app/Http/Controllers/Settings/SecuritySettingsController.php`
- `app/Domain/Users/Actions/RequestEmailChange.php`

**Problem:** potwierdzenie żądania zmiany adresu sprawdza jego ważność przed właściwą sekcją krytyczną i nie współdzieli blokady z operacją anulowania. `CancelEmailChange` kasuje `pending_email_changes`, ale rozpoczęte wcześniej potwierdzenie może nadal operować na już pobranym modelu.

Możliwy przebieg:

1. request A odczytuje ważne `PendingEmailChange`;
2. request B resetuje/zmienia hasło i kasuje oczekującą zmianę;
3. request A wchodzi do swojej transakcji i dokańcza zmianę e-maila na podstawie starego obiektu.

Skutek: deklarowana własność „reset/zmiana hasła unieważnia oczekującą zmianę adresu” nie jest liniaryzowalna.

**Naprawa rekomendowana:**
- w `ConfirmEmailChange` pobierać `pending_email_changes` **wewnątrz transakcji** przez `lockForUpdate()`;
- blokować także `users` w stałej kolejności, np. `users` → `pending_email_changes`;
- po założeniu blokad ponownie sprawdzić `expires_at`, `user_id`, stan konta i zajętość adresu;
- `CancelEmailChange` powinno używać tej samej kolejności blokad;
- `RequestEmailChange` również powinno serializować wymianę oczekującego rekordu na danym użytkowniku.

**Test regresyjny:** dwa niezależne połączenia PostgreSQL. Jedno zatrzymane po pobraniu żądania, drugie wykonuje reset hasła/anulowanie; po zwolnieniu blokady potwierdzenie musi zakończyć się jako nieaktualne, bez zmiany `users.email`.

---

### AUTH-02 — P1 — równoległe wystawienie magic-link może zdradzać istnienie konta

**Pliki:**
- `app/Domain/Security/WyslijLinkDoLogowania.php`
- `app/Http/Controllers/Auth/LoginLinkController.php`
- `database/migrations/2026_09_10_100000_create_login_link_tokens_table.php`

Schemat poprawnie wymaga jednego tokenu na konto: `login_link_tokens.user_id` jest `UNIQUE`. Akcja wystawiająca nowy token robi jednak:

1. `DELETE` poprzedniego tokenu,
2. `INSERT` nowego,

bez blokady wiersza użytkownika, która serializowałaby dwa równoległe żądania.

Dwa równoległe requesty dla **istniejącego** konta mogą oba dojść do `INSERT`; jeden może odbić się o unikalność. Dla **nieistniejącego** adresu oba kończą się normalną ścieżką „nic nie wysyłamy”. Jeżeli `UniqueConstraintViolationException` wydostanie się na HTTP, para równoległych requestów staje się bocznym kanałem enumeracji: 500 może wystąpić tylko tam, gdzie istnieje konto.

Turnstile i limitery utrudniają masowe wykorzystanie, ale nie usuwają błędu kontraktu. Ten sam race może też dać zwykłemu użytkownikowi 500 po podwójnym wysłaniu formularza.

**Naprawa rekomendowana:**
- przed wymianą tokenu zablokować `users` dla znalezionego konta (`lockForUpdate`);
- pod tą blokadą wykonać wymianę tokenu;
- defensywnie przechwycić konflikt unikalności i sprowadzić wynik do tej samej neutralnej odpowiedzi HTTP;
- testować dwa równoległe `POST` dla istniejącego i nieistniejącego adresu: status, redirect i treść odpowiedzi muszą być nierozróżnialne.

---

### AUTH-03 — P2 — ten sam wzorzec `DELETE + INSERT` występuje przy zamawianiu zmiany e-maila

`RequestEmailChange` także kasuje poprzedni `pending_email_changes`, a następnie tworzy nowy wiersz przy `UNIQUE(user_id)` bez blokady użytkownika.

Tu ryzyko enumeracji jest mniejsze, bo endpoint jest uwierzytelniony. Skutkiem jest przede wszystkim możliwość `500`, utrata przewidywalnego „ostatnie żądanie wygrywa” i niespójność tego, który link jest naprawdę ważny.

**Naprawa:** ta sama serializacja po `users.id` co w AUTH-01.

## Co jest zrobione dobrze

- Magic-link jest 3-etapowy: GET nie konsumuje tokenu; POST z CSRF dopiero go zużywa. To chroni przed link-scannerami pocztowymi.
- Konsumpcja tokenu używa transakcji i `lockForUpdate()`, więc jeden token nie powinien wejść dwa razy.
- Moderator/admin nie dostaje magic-link.
- Magic-link respektuje 2FA.
- Reset hasła rotuje wszystkie sesje i `remember_token`.
- Zmiana/reset hasła próbuje unieważnić zmianę e-maila — problemem jest atomowość, nie brak intencji.
- Komunikaty dla istniejącego/nieistniejącego konta są projektowane świadomie jako nierozróżnialne.

## Priorytet wykonania

1. AUTH-01.
2. AUTH-02.
3. AUTH-03 wspólnym refaktorem blokad.
4. Dopisać testy współbieżności na prawdziwym PostgreSQL, nie na mockach.

## Kryterium zamknięcia

Audyt uznaję za zamknięty dopiero, gdy testy potwierdzają inwarianty:

- po rozpoczęciu resetu/zmiany hasła stary link zmiany e-maila **nie może** później zmienić adresu;
- dwa równoległe żądania magic-link nie generują 500 i nie zdradzają istnienia konta;
- dwa równoległe żądania zmiany e-maila kończą się jednym, jednoznacznie ważnym rekordem.

