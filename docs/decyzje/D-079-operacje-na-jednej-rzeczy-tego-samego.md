## D-079 · Operacje na jednej rzeczy tego samego konta idą przez JEDNĄ kolejność blokad, a pod blokadą sprawdzamy stan jeszcze raz

**Data:** 10 września 2026 · Ustalenie AUTH-01 / RACE-01 z drugiej warstwy
audytu (`docs/research/audyt-2026-09-10/`) · Status: **obowiązuje**

### Co było złamane

Serwis obiecuje w trzech miejscach jedną własność: **ustawienie nowego hasła
unieważnia oczekującą zmianę adresu e-mail**. Wołają to
`PasswordResetController::reset()` i `SecuritySettingsController::
updatePassword()` przez `CancelEmailChange`, a `PendingEmailChange` wymienia
to jako jedną z trzech dróg wygaszenia żądania.

Ta własność **nie obowiązywała**. `EmailSettingsController::confirm()`
pobierał wiersz `pending_email_changes`, sprawdzał go i oddawał MODEL do
`ConfirmEmailChange::handle()`, które wchodziło do transakcji, blokowało
`users` — i nigdy nie czytało tego wiersza ponownie. `CancelEmailChange`
kasowało wiersz **bez żadnej blokady**. Między odczytem w kontrolerze
a transakcją w akcji było okno:

1. żądanie A czyta ważne `PendingEmailChange`;
2. żądanie B ustawia nowe hasło i kasuje ten wiersz;
3. żądanie A wchodzi do transakcji, przypisuje NOWY ADRES i woła
   `$zmiana->delete()`, które kasuje zero wierszy — i nie zgłasza błędu.

**Nie jest to teoretyczne.** Kontrola ujemna (usunięcie rewalidacji
i uruchomienie testów regresyjnych) pokazuje, że adres konta faktycznie
zmienia się na nowy mimo anulowania.

### Dlaczego to była najpoważniejsza rzecz z całego audytu

Scenariusz, w którym ta obietnica ma sens, to dokładnie ten, w którym ktoś
obcy miał chwilowy dostęp do konta: zamówił zmianę adresu na swój,
a właściciel odzyskuje konto ustawiając nowe hasło. Właściciel wykonuje
**dokładnie tę czynność, którą serwis mu każe** — i mimo tego link
napastnika może później przestawić adres konta, czyli przenieść na niego
logowanie i reset hasła.

Mechanizm zaprojektowany na wypadek przejęcia konta dawał się przejęciu
obejść. Audyt sklasyfikował to jako P1; w praktyce jest to jedyne znalezisko
z obu warstw, które prowadzi do utraty konta bez żadnego błędu właściciela.

### Zasada, która z tego zostaje

**1. Jedna kolejność blokad, w jednym miejscu.** `App\Domain\Users\ZamekKonta`
ustala: najpierw wiersz `users`, potem rzecz zależna. Wszystkie trzy operacje
na zmianie adresu (zamówienie, potwierdzenie, anulowanie) wchodzą przez to
gardło. Kolejność jest ważniejsza niż sam fakt blokowania — dwie różne
kolejności w jednym repozytorium to zakleszczenie, a nie zabezpieczenie.
Dlatego kolejność stoi w jednej klasie, nie w trzech akcjach osobno.

**2. Blokujemy wiersz KONTA, nie rzeczy zależnej.** Bo rzecz zależna może nie
istnieć, a `SELECT ... FOR UPDATE` na nieistniejącym wierszu nie blokuje
niczego i nie powstrzyma drugiego `INSERT`. Konto istnieje zawsze i jest
wspólne dla wszystkich operacji.

**3. Sama blokada nie wystarczy — pod blokadą czytamy stan JESZCZE RAZ.**
Blokada serializuje, ale nie mówi żądaniu A, że świat zmienił się, gdy ono
czekało. Akcja, która dostaje model z zewnątrz, **nie ma prawa mu ufać**:
model mógł zostać odczytany przed sekundą albo przed godziną. Rewalidacja
pyta o to samo co sprawdzenie przed blokadą: czy wiersz istnieje, czy jest
nasz, czy nie wygasł i czy dotyczy tej samej rzeczy.

**4. `exists()` w PHP jest dobre na ładny komunikat, nie na gwarancję.**
Gwarancję daje constraint w PostgreSQL albo blokada. Tam, gdzie inwariant da
się wyrazić w bazie, ma być w bazie.

### Zasięg tej decyzji

Wpis dotyczy zmiany adresu e-mail, ale zasada jest ogólna i audyt wskazuje
te same wzorce w co najmniej pięciu innych miejscach (wystawianie linku do
logowania, dobowy budżet listów, idempotencja digestu, jeden aktywny eksport
danych, harmonogram przy wielu replikach). Każde z nich jest rozstrzygane
osobnym wpisem — ale **kolejność blokad wprowadzona tutaj obowiązuje w całym
repozytorium** i nowa operacja na koncie nie zakłada własnej.

### Czego ta decyzja NIE rozstrzyga

Nie dowodzi poprawnej kolejności blokad przy dwóch równoległych połączeniach
do PostgreSQL — do tego trzeba dwóch procesów i wymuszonego przeplotu na
poziomie bazy, a audyt 20 słusznie stawia to jako osobne kryterium zamknięcia.
Testy regresyjne dowodzą rzeczy węższej i akurat tej, która była złamana:
że akcja nie ufa modelowi podanemu z zewnątrz.

**Pliki:** `app/Domain/Users/ZamekKonta.php` ·
`app/Domain/Users/Actions/ConfirmEmailChange.php` ·
`app/Domain/Users/Actions/CancelEmailChange.php` ·
`app/Domain/Users/Actions/RequestEmailChange.php` ·
`tests/Feature/PotwierdzenieAdresuNieWyprzedzaAnulowaniaTest.php`
