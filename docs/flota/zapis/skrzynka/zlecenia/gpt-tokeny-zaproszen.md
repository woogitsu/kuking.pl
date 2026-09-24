# Zlecenia — gpt-tokeny-zaproszen

## 2026-09-20 21:49 — zadanie bieżące

Pełna treść: `C:\Users\matma\Documents\kuking-flota\_prompty\57-tokeny-zaproszen.txt`

#889 w dwóch kolejnych kanałach: UstawienieNowegoHasla buduje czas z konfiguracji brokera, ZaproszenieDoZalozeniaKonta ignoruje przekazaną datę. Żadna nie ma shouldSend.

Gdy skończysz: dopisz meldunek do `meldunki/gpt-tokeny-zaproszen.md` i zajrzyj tutaj —
dopiszę kolejne zadanie na końcu tego pliku.

## 2026-09-20 21:51 — kolejne zadanie

Poprzednie przyjęte. Pełna treść nowego: `C:\Users\matma\Documents\kuking-flota\_prompty$2`

Dwie rzeczy uchodzą dziś za sprawdzone, a nie są: jurysdykcja bucketów R2 i wyłączenie piksela śledzącego u dostawcy poczty. OBU nie da się sprawdzić bez zalogowania do paneli — to należy do właściciela, nie do Ciebie. Twoim produktem jest PROCEDURA: dokładne kroki, dokładna nazwa pola do obejrzenia i jednoznaczne kryterium, co oznacza wynik.

Gdy skończysz — meldunek do skrzynki i zajrzyj tutaj po następne.

---

## UWAGI AUDYTU — NIE POLECENIE (2026-09-21 07:54)

Z przebiegu 3 audytu przed kolejką (`skrzynka/meldunki/AUDYT-2026-09-21-0754.md`).
**To nie jest zlecenie.** Decyzja, co z tym zrobić, należy do Ciebie i do koordynatora.

- **[pkt 4 — reguła w dwóch z trzech miejsc] `app/Notifications/UstawienieHaslaZamiastLinku.php:89-135`.**
  Ta klasa dziedziczy po tym samym `ResetPassword`, jest `ShouldQueue` i dostaje
  **ten sam token brokera** (`app/Domain/Security/WyslijOdzyskanieKonta.php:210`,
  przez `Password::sendResetLink()` z tą samą ważnością `auth.passwords.users.expire`).
  Została nietknięta: brak `shouldSend`, a `waznosc($minut)` dalej czyta
  konfigurację w chwili wykonania zadania. Skutek jest identyczny z tym, co
  naprawiłeś: martwy link w skrzynce i zdanie o ważności liczone od startu workera.
  Raport `docs/security/TERMIN_RESETU_I_ZAPROSZENIA_889.md` wymienia jako
  niepoprawione tylko logowanie linkiem — tej klasy nie wspomina wcale, więc nie
  jest to opisane ograniczenie.

Reszta gałęzi wypadła dobrze: czerwień podana z liczbami (16 porażek, 52 asercje)
i przyczyną, uzupełnienia czterech istniejących testów są konieczne, nie osłabiające,
`tests/Feature/ZaufaneHostyTest.php:314` nie wywraca się na nowym odczycie bazy.
Werdykt audytu: **usterka do naprawy przed PR-em** — wyłącznie z powodu punktu wyżej.
