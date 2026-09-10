# Kuking.pl — audyt migracji, rollbacku i bezpiecznych wdrożeń

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026

## Werdykt

Repo ma dojrzalsze podejście do migracji niż przeciętny projekt: migracje uruchamiane są w fazie pre-deploy, a jawnie destrukcyjne usuwanie `topics` ma bramkę na istniejące dane. Nie znalazłem podstaw do ogólnej tezy „migracje są niebezpieczne”.

Najważniejszy nowy problem jest semantyczny: rollback może **usunąć wybór użytkownika dotyczący zakresu kasowania konta**, a późniejsze ponowne `up()` odtworzy wartość domyślną inną niż wcześniej wybrana.

## MIG-01 — rollback `delete_scope` może zmienić przyszłe wykonanie żądania usunięcia danych — P1

### Kod

`database/migrations/2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php` dodaje `delete_scope` i w `up()` wypełnia braki wartością `minimum`.

`down()` usuwa kolumnę `delete_scope`.

Jeżeli użytkownik w okresie karencji ma aktywne żądanie i wybrał **`everything`**, rollback:

1. trwale gubi tę informację;
2. po późniejszym ponownym wdrożeniu `up()` null/brak zostaje ustawiony na `minimum`;
3. finalne wykonanie usunięcia może zatrzymać społecznościową treść, mimo że użytkownik wybrał pełniejsze usunięcie.

To nie jest techniczny kosmetyk. Rollback schematu zmienia semantykę żądania prywatności.

### Naprawa

Po uruchomieniu tej funkcji dla realnych użytkowników migrację traktować jako **forward-only w produkcji**.

Minimalny guard w `down()`:

- jeśli istnieje jakikolwiek aktywny rekord z `delete_scope='everything'` lub inna wartość nienadająca się do bezstratnego odtworzenia — przerwać rollback z jednoznacznym komunikatem;
- runbook powinien preferować fix-forward;
- jeśli rewersja całego środowiska jest konieczna, najpierw zachować/exportować semantyczne dane albo odtworzyć backup.

Dodać migration test: użytkownik `pending_delete + everything` → `down()` nie może cicho stracić wyboru.

## MIG-02 — rollback digestu przywraca `DEFAULT true` — P1 istniejący / cross-reference

`database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php` w `up()` naprawia historyczny implicit opt-in: ustawia default false oraz istniejące `true` na false.

`down()` świadomie przywraca **`DEFAULT true` dla nowych rekordów**, choć nie zapisuje ponownie istniejących użytkowników.

To nadal oznacza, że po rollbacku **nowe konto może powstać ze zgodą true bez pytania na rejestracji**. Z perspektywy prawa/produktu rollback nie może przywracać znanego niepoprawnego defaultu tylko po to, żeby schema wyglądała „jak dawniej”.

### Naprawa

- w produkcji traktować tę zmianę jako forward-only;
- albo `down()` powinien zachować bezpieczny default false i cofnąć wyłącznie elementy techniczne, które dają się odwrócić bez naruszenia zgód.

## MIG-03 — `drop_topics` ma prawidłową bramkę bezpieczeństwa — pozytywne

`database/migrations/2026_09_07_300000_drop_topics.php` nie usuwa danych w ciemno. Sprawdza stan tabel/dependencji i odmawia destrukcyjnej operacji, gdy migracja nie jest bezpieczna.

Nie raportuję więc „DROP TABLE w migracji” jako błędu sam w sobie. Liczy się guard i procedura danych.

## DEPLOY-01 — migracje w pre-deploy to właściwy model — pozytywne

`.railway/railway.ts` świadomie umieszcza migracje w pre-deploy zamiast:

- build phase bez dostępu do prod DB;
- start command uruchamianym na każdej replice.

To ogranicza wyścigi wielu instancji o schema i pozwala zatrzymać deploy po błędzie migracji.

## DEPLOY-02 — rollback aplikacji i rollback danych to dwie różne operacje — P2 proceduralne

W miarę dojrzewania produktu rośnie liczba migracji, których `down()` nie może sensownie cofnąć bez utraty semantyki. W tej klasie są już:

- zgody/opt-in;
- decyzje użytkownika o usunięciu;
- migracje treści/stanów moderacyjnych.

Runbook powinien jawnie rozróżnić:

1. rollback obrazu/kodu do wcześniejszego commita;
2. kompatybilność starego kodu z nowszą schema;
3. rewersję schema/danych — wykonywaną tylko, gdy dowiedziono bezstratności.

### Zalecany wzorzec

- expand → deploy compatible code → backfill → contract później;
- fix-forward jako domyślna strategia;
- destrukcyjny `down()` z guardem, nie z „best effort”;
- przed schema contract: backup + restore point + sprawdzony plan.

## DEPLOY-03 — identyczne prefixy timestamp kilku migracji nie są same w sobie błędem

Laravel sortuje nazwy migracji deterministycznie. Nie znalazłem konkretnej zależności, która przez te same timestampy dawałaby losową kolejność. Nie zgłaszam tego jako finding.

## Bramka przed publiczną betą

- MIG-01 i MIG-02 muszą dostać jasną politykę forward-only/guard;
- pre-deploy należy potwierdzić realnym deploymentem;
- restore drill ma obejmować scenariusz, w którym baza ma nowszą schema niż wracający obraz aplikacji.
