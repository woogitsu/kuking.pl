## D-238 — Cofnięcie migracji 2FA ODMAWIA, zamiast po cichu zdjąć drugi składnik (DB-01, 22 września 2026)

**Data:** 22 września 2026 · **Naprawa znaleziska z audytu** (DB-01 z
`docs/AUDYT_2026-09-13.md`, gałąź `claude/laughing-edison-sz4k69`) ·
Status: **obowiązuje**

### Co było zepsute

`down()` migracji `2026_09_06_120000_add_two_factor_to_users_table` kasowało
bezwarunkowo cztery kolumny: `two_factor_secret`, `two_factor_backup_codes`,
`two_factor_confirmed_at`, `two_factor_last_used_at` — a wcześniej zdejmowało
CHECK `users_two_factor_confirmed_requires_secret_check`.

Sekret TOTP jest zaszyfrowany i nie ma go skąd odtworzyć. Kody zapasowe są
trzymane wyłącznie jako skróty. Po cofnięciu nie da się przywrócić ani
jednego, ani drugiego.

### Dlaczego to nie było „świadome", tylko przeoczone

Migracja **broniła się własnym komentarzem**: „nikt nie zostaje zablokowany,
bo wymóg drugiego składnika znika razem z kolumnami, które go przechowywały".
To samo zdanie stało w `docs/DATABASE.md`. I ono jest prawdziwe — dlatego
właśnie było groźne.

> Cofnięcie nie wybija nikogo z serwisu. Ono ZDEJMUJE OCHRONĘ.

Cykl `rollback` → `migrate`, który CI wykonuje jako `migrate:refresh`,
zostawia kolumny puste, a razem z nimi znika CHECK pilnujący niezmiennika.
Konto moderatora, o którym właściciel wie, że jest chronione dwoma
składnikami, wraca do logowania samym hasłem — bez błędu, bez komunikatu,
bez śladu. Moderator widzi zgłoszenia, cudze ukryte treści i odwołania;
`docs/SECURITY_PRIVACY_LEGAL.md` obiecuje „MFA obowiązkowe dla adminów".

To jest dokładnie „przywracanie stanu groźnego" z zasady **D-088**, tylko
w postaci trudniejszej do zauważenia niż w #287: tam cofnięcie po cichu
zmieniało ZNACZENIE decyzji człowieka, tu po cichu USUWA jego zabezpieczenie.
Objaw jest ten sam — brak śladu błędu.

### Ile było takich strażników przed tą naprawą

W `database/migrations/` odmowę miało już kilkanaście migracji, a w
`tests/Feature/` stało **dziewiętnaście** testów `Cofniecie*` — m.in. dziennik
zgód, zaproszenia, zgłoszenia prawne, odwołania zgłaszających, tożsamość
Google, tożsamość Facebooka, skala tekstu, znacznik odebrania dostępu,
zeszyty, kolaż powitalny, numer sprawy, sygnały automatu i wiadomości.

Dla 2FA — czyli dla najbardziej wrażliwej z tych wartości — **nie było ani
jednego**. Nie dlatego, że ktoś to rozważył i odrzucił: przeciwnie,
komentarz przy migracji pokazuje, że ryzyko było zauważone i uznane za
akceptowalne, zanim powstała zasada D-088.

### Rozstrzygnięcie

`down()` liczy konta z `two_factor_confirmed_at IS NOT NULL` i przy
niezerowym wyniku rzuca wyjątek z instrukcją — **przed jakąkolwiek operacją
niszczącą**, także przed zdjęciem CHECK-a. Świadome cofnięcie przepuszcza
`KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK=1`, zgodnie z konwencją furtek z
`KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY` i `KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE`
(`getenv()`, nie `env()` — na produkcji konfiguracja bywa zbuforowana).

**Granica jest przy POTWIERDZENIU, nie przy sekrecie.** Sekret zapisany bez
`confirmed_at` to konto w trakcie włączania 2FA — ekran włączenia pokazuje
sekret, zanim człowiek wpisze pierwszy kod. To nie jest ochrona, którą można
stracić; człowiek zaczyna włączanie od nowa. Gdyby strażnik liczył sam
sekret, jedno porzucone włączanie blokowałoby rollback na stałe.

Na świeżym środowisku cofnięcie działa bez pytania, więc `migrate:refresh`
w `scripts/check.sh` i w CI chodzi jak dotąd.

### Czego ta decyzja NIE zmienia

Nie zmienia schematu, zachowania logowania ani niczego, co widzi użytkownik.
Kolumny, CHECK i limit prób zostają bez zmian. Zmienia się wyłącznie to, co
`down()` robi, gdy ktoś ma 2FA naprawdę włączone.

### Dowód

`tests/Feature/CofniecieMigracji2faOdmawiaTest.php` — pięć przypadków, obie
strony granicy: odmowa z danymi nietkniętymi po niej, świeże środowisko bez
pytania, sam sekret bez potwierdzenia nieblokujący, furtka przepuszczająca
oraz kolejność (strażnik przed zdjęciem CHECK-a i przed `dropColumn`).

Kontrola ujemna: na kodzie sprzed tej naprawy **oblewają dwa przypadki z
pięciu** — odmowa i kolejność. Pozostałe trzy przechodzą w obie strony i to
jest zamierzone: pilnują, żeby strażnik nie blokował za dużo.
