# Kuking.pl — delta `main` i korekta reporter lifecycle

**Repozytorium:** `woogitsu/kuking.pl`  
**Bazowy snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Finalny `main` sprawdzony przed pakowaniem:** `47dbb6cc9afb4a4000291d655cfc2b9061e6daf8`  
**Delta:** 2 commity  
**Data:** 10.09.2026

## Cel

Repo zmieniło się podczas przygotowywania raportów. Ten dokument sprawdza, czy zmiany unieważniają ustalenia trzeciej warstwy oraz czy repozytoryjne `SPRAWDZENIE.md` słusznie koryguje wcześniejszy audyt.

## 1. Czy nowe P1 trzeciej warstwy nadal są aktualne?

**Tak.** Compare `fd164ad3… → 47dbb6cc…` nie zmienił plików źródłowych odpowiedzialnych za:

- `DostepDoZdjecia` / `MediaController` — MEDIA-03;
- `ProcessUploadedImage` / `KasujZdjecie` / attach media — MEDIA-01/MEDIA-02;
- `FollowUser` / `BlockUser` — SOCIAL-01;
- migrację `add_erased_status_and_delete_scope_to_users` — MIG-01;
- second-layer auth/mail/digest classes.

Zmiany dotyczyły przede wszystkim `docs/OTWARCIE.md`, kopii audytu w `docs/research`, `resources/js/app.js`, jednego pola rejestracji i testów podpowiadania nazwy użytkownika.

Wniosek: **nie trzeba powtarzać trzeciej warstwy na całym repo; wystarcza delta-review, a nowe P1 pozostają otwarte.**

## 2. Korekta wcześniejszego P0 reporter lifecycle

Repozytoryjne `SPRAWDZENIE.md` twierdzi, że audyt pomylił się, uznając zwykłe „Zgłoś” za ścieżkę bez receipt/decyzji. Tezę sprawdzono bezpośrednio w kodzie.

### Potwierdzenie

`app/Domain/Moderation/Actions/ReportContent.php` po utworzeniu nowego raportu wywołuje `NotifyReporterReceipt`.

`app/Domain/Moderation/Actions/NotifyReporterDecision.php` dla raportera z kontem tworzy `TYPE_REPORT_DECIDED`, przechowuje numer sprawy/action ID i ustawia `decision_sent_at`.

Repo ma również numer sprawy, własną listę/podgląd zgłoszeń i testy tego flow.

**Wniosek:** wcześniejszy P0 „produkt nie informuje zwykłego reportera” jest fałszywy i został usunięty z raportów 09, 10, 14 i 28.

## 3. Prawdziwy problem: `MODERATION_PLAYBOOK.md` opisuje stan sprzed issue #10 — P1

Aktualny `docs/legal/MODERATION_PLAYBOOK.md` nadal mówi, że zwykły reporter nie dostaje powiadomienia o decyzji ani linku i ma jedynie adres kontaktowy.

To jest sprzeczne z działającym kodem.

### Ryzyko

- moderator może wykonać niepotrzebny drugi kontakt ręczny;
- operator może uznać, że regulaminowej obietnicy nie da się realizować;
- podczas incydentu dokument operacyjny staje się mniej wiarygodny niż kod, którego moderator nie powinien musieć czytać.

### Naprawa

- poprawić trzy nieaktualne fragmenty playbooka;
- opisać jeden rzeczywisty flow receipt → numer sprawy → status → decision → appeal;
- dołożyć contract test / statyczny test dokumentacyjny, jeśli w repo już stosuje się taki mechanizm;
- **nie tworzyć nowego reporter lifecycle**.

## 4. Nowe fakty operacyjne z `OTWARCIE.md`

Finalny snapshot stanu bramek doprecyzowuje wcześniejszy audyt:

### Poczta

- produkcyjna wysyłka została realnie potwierdzona;
- nadal otwarty jest blocker open tracking EmailLabs niezgodny z deklaracją polityki;
- `kontakt@kuking.pl`, SPF/DKIM/DMARC na konkretnych polskich skrzynkach wymagają dalszej weryfikacji.

### R2

- nowe warianty w sprawdzonym scenariuszu: aplikacja → 302 → signed R2 → 200 WebP;
- unsigned/original odrzucone;
- stare zdjęcia nadal są serwowane z wolumenu;
- formalna bramka #120 nie ma kompletnego dowodu, więc storage pozostaje bramką startową, ale nie wolno już mówić „nie wiadomo, czy nowe uploady w ogóle idą do R2”.

### Backup

`OTWARCIE.md` utrzymuje, że automatycznej kopii DB poza Railway nadal nie ma, a restore drill nie został wykonany. To pozostaje najtwardszym operacyjnym blockerem szerokiego startu.

## 5. Werdykt delta

Delta nie zmienia decyzji startowej. **Publiczna beta nadal NO-GO**, ale lista powodów jest teraz dokładniejsza:

- usuwamy fałszywy P0 reporter lifecycle;
- pozostawiamy P1 drift playbooka;
- potwierdzamy działającą wysyłkę poczty, ale tracking nadal blokuje;
- potwierdzamy signed-R2 dla nowych mediów w jednym realnym scenariuszu, ale stary wolumen + bramka #120 nadal otwarte;
- wszystkie P1 concurrency/invariants z raportów 21 i 28 pozostają aktualne.
