## D-302 — Rodzinny zeszyt: jeden właściciel, zaproszone osoby dopisują (#1743, 26 września 2026)

**Decyzja właściciela (26 września 2026):** #1743 „budujemy teraz”. Właściciel
świadomie pominął bramkę z opisu issue, czyli test problemu z gospodarstwami
domowymi przed projektowaniem migracji. Wspólny zeszyt powstaje od razu,
w wąskim zakresie opisanym niżej. Jeśli użycie pokaże, że ludzie nie zapisują
wspólnie, wracamy do tej decyzji. Nie rozbudowujemy wtedy modelu członkostwa.

**Zakres.**

1. **Jeden właściciel.** `collections.owner_id` zostaje jedynym właścicielem.
   Zaproszona osoba jest współtwórcą, nie drugim właścicielem: może zapisywać
   i wyjmować pozycje oraz pisać notatki (`CollectionPolicy::addItem()`,
   `removeItem()`, `UpdateCollectionItemNote`). Nie zmienia nazwy ani
   widoczności zeszytu, nie usuwa go i nie zaprasza dalej. `share()` ma tylko
   właściciel. Współtwórca może sam odejść (`leave()`).
2. **Zaproszenie** wysyła się po nazwie konta (ważne 14 dni) albo jako
   jednorazowy link (ważny 7 dni), `config/kuking.php` → `collections.*`.
   W bazie jest tylko SHA-256 tokenu. Jednorazowość i „jedno oczekujące
   zaproszenie na osobę” pilnuje baza, nie tylko kod. Najwyżej
   `collections.max_members` = 5 osób z dostępem na zeszyt.
3. **Domyślnego zeszytu nie da się udostępnić**, a właściciel nie może być
   swoim współtwórcą. Pilnuje wyzwalacz `collection_members_guard`, bo CHECK
   nie sięga do wiersza `collections`.
4. **Kto co dodał** widać przy pozycji (`collection_items.added_by_id`).
5. **Blokada** w którąkolwiek stronę kończy wspólny zeszyt w obie strony
   i odwołuje oczekujące zaproszenia (`ZerwijWspoldzielenie::miedzy()`).
   Odblokowanie niczego nie przywraca.
6. **Usunięcie konta** (każdy zakres) kasuje członkostwa i zaproszenia tej
   osoby. Pozycje, które dopisała w cudzych zeszytach, zostają bez podpisu
   (`added_by_id = NULL`, „osoba, która usunęła konto”)
   (`ZerwijWspoldzielenie::przyWymazaniu()`).
7. **Eksport danych** obejmuje zeszyty, do których osoba ma dostęp, i pozycje,
   które w nich dodała. Dane innych osób trafiają tam tylko w granicach
   RODO art. 15 ust. 4.
8. **Powiadomienia** w serwisie: zaproszenie (`collection.invited`)
   i przyjęcie (`collection.joined`). „Zobacz” przy zaproszeniu działa,
   dopóki da się na nie odpowiedzieć.

9. **Granice modułów.** `Social` (blokada) i `Users` (wymazanie konta) nie
   importują `Collections` — kończą wspólne zeszyty przez kontrakt
   `App\Domain\Users\KoniecWspolnychZeszytow`, który implementuje
   `ZerwijWspoldzielenie`, a wiąże `AppServiceProvider`. Dzięki temu graf
   `app/Domain` nie dostaje nowych cykli (`GrafModulowDomenyBezCykliTest`).
10. **Autoryzacja tras.** Ekran „Kto ma dostęp”, odwołanie zaproszenia
    i odebranie dostępu: `CollectionPolicy::manageAccess()` (właściciel,
    także zawieszony — to zawęża, nie pisze). Odejście: `leave()`.
    Zaproszenie po nazwie: `CollectionInvitationPolicy::respond()` (tylko
    adresat, każdy inny dostaje 404). Link-zaproszenie ma za poświadczenie
    token w adresie.

**Powrót po rejestracji (#2420).** Gdy osoba bez konta otwiera ważny link,
middleware zapamiętuje jego adres w sesji. Rejestracja przenosi wyłącznie
rozpoznany token do krótkiego (najwyżej 2 godziny) zamiaru przypisanego do
nowego konta. Po ukończeniu lub pominięciu pierwszych kroków wraca na
podgląd zaproszenia; dołączenie nadal wymaga jej kliknięcia. Przy powrocie
sprawdzamy ponownie termin, odwołanie i jednorazowość w bazie. Obcy adres
z sesji ani parametr przekierowania nie są celem powrotu.

**Poza zakresem:** wspólne zeszyty poza najbliższymi (grupy, publiczne
współtworzenie), role inne niż właściciel i współtwórca, historia zmian.

**Wycofanie.** Obie migracje odmawiają `down()`, gdy wycofanie zgubiłoby
dane (D-088). Szczegóły i zmienna wymuszenia są w `docs/DATABASE.md`,
w sekcji `collection_members + collection_invitations`.
