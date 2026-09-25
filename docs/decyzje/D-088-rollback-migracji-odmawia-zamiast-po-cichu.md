## D-088 · Rollback migracji ODMAWIA, zamiast po cichu zamienić „usuń wszystko" na „usuń minimum" (MIG-01, #287)

**Data:** 10 września 2026 · **Naprawa błędu z audytu** (issue #287, trzecia
warstwa audytu 10.09.2026, znalezisko MIG-01) · Status: **obowiązuje**

### Co było zepsute — potwierdzone na prawdziwej bazie, nie w teorii

Migracja `2026_09_07_500000_add_erased_status_and_delete_scope_to_users`
dodaje kolumnę `users.delete_scope` (`minimum` | `everything`, D-022) —
zakres, jaki człowiek wybrał na ekranie usuwania konta. Jej `down()` kasowała
tę kolumnę bez warunku, a `up()` przy ponownym uruchomieniu backfillowała
brakującą wartość jako `minimum` dla każdego konta w usuwaniu, bo to jedyna
wartość, jaką umiała wtedy nadać.

Sprawdzone ręcznie na `kuking_test_wt_mig01`, cyklem, który CI wykonuje jako
`migrate:refresh`: konto zgłoszone realną metodą `markForDeletion('everything')`
→ `php artisan migrate:rollback` → `php artisan migrate` → w bazie
`delete_scope = 'minimum'`. Kolumna nie zniknęła z widoku, CHECK-i wróciły
poprawne, żaden wiersz nie zginął — i właśnie dlatego nikt by tego nie
zauważył: to jest cicha podmiana ZNACZENIA decyzji, nie usterka techniczna.
`EraseAccountData::chceUsunacTresci()` czyta tę kolumnę 30 dni później i
zrealizowałaby węższy zakres, niż człowiek naprawdę wybrał.

**Ta sama choroba, którą audyt znalazł już raz jako DB2**
(`2026_09_07_400000_default_weekly_digest_to_off`, `down()` przywracający
`DEFAULT true` dla zgody na cotygodniowy przegląd) — a ten drugi przypadek
zostaje jawnie POZA tą naprawą: sprawdza go równolegle inna gałąź
(`claude/dowod-zgody-na-digest`, PR #270).

### Zasada, nie tylko łatka na jedną migrację

> `down()` nie ma prawa przywracać stanu groźnego ani zmieniać znaczenia
> decyzji człowieka. Przy wartościach semantycznych (zgoda, zakres usunięcia,
> widoczność, prywatność zeszytu) rollback ma **odmówić**, gdy nie da się
> wartości odtworzyć wiernie — zgadywanie cichą wartością domyślną jest
> najgorszą z opcji, bo nie zostawia śladu błędu.

Przegląd całego `database/migrations/` pod tym kątem (krok obowiązkowy przy
tej naprawie) znalazł jeszcze trzy miejsca o podobnym kształcie
(`down()` kasuje kolumnę, `up()` nadaje jej DEFAULT przy ponownym uruchomieniu),
świadomie ZOSTAWIONE poza zakresem #287:

- `2026_09_06_210000_add_theme_to_users` (`theme`, `DEFAULT 'light'`) —
  preferencja WYGLĄDU, nie zgoda ani dane osobowe; rollback zresetowałby
  wybór ciemnego motywu, nie decyzję o danych;
- `2026_09_06_120000_add_display_mode_to_posts` (`display_mode`,
  `DEFAULT 'normal'`) — decyzja AUTORA o prezentacji TREŚCI wpisu, nie
  o własnych danych ani zgodzie;
- `2026_09_06_120000_add_two_factor_to_users_table` — `down()` kasuje sekret
  i kody zapasowe 2FA CAŁKOWICIE (nie ma backfillu, bo nie ma jak odtworzyć
  sekretu), a nie podmienia go cichą wartością domyślną; udokumentowane
  w samej migracji jako świadomy, awaryjny powrót do stanu sprzed funkcji.

Żadne z tych trzech nie dotyczy zgody ani zakresu usunięcia danych — nie
zostały naprawione w tym PR-ze, zgodnie z zawężeniem zlecenia do „decyzji
użytkownika o jego danych albo o zgodzie". Jeśli produkt kiedyś uzna
preferencję wyglądu albo prezentacji treści za wartą tej samej ochrony,
to osobna decyzja, nie rozszerzenie tej.

### Uzupełnienie z 11 września 2026: ten przegląd był NIEKOMPLETNY

**Data uzupełnienia:** 11 września 2026 · PR #327 · zamyka #287

Zdanie „żadne z tych trzech nie dotyczy zgody ani zakresu usunięcia danych"
jest prawdziwe o tych trzech i **niekompletne jako przegląd**. Przegląd
`database/migrations/` przy #287 przeoczył czwarty przypadek tej samej
choroby — `2026_09_06_140000_add_memories_to_users_and_posts` — i nie
wymienił go wcale, ani jako naprawionego, ani jako świadomie pominiętego.
Migracja łamie regułę tego wpisu **w jej własnych słowach**, bo reguła
nazywa **widoczność** wprost.

Zmierzone na prawdziwej bazie cyklem `migrate:rollback` → `migrate`, czyli
tym, co robi `migrate:refresh` w CI i awaryjny rollback wdrożenia:

```text
PRZED:    memories_enabled=false  hide_as_memory=true
PO CYKLU: memories_enabled=true   hide_as_memory=false
```

Po ludzku: **wyłącznik, którym osoba w żałobie wyłączyła wspomnienia, włącza
się sam, a schowany wpis z przepisem po mamie wraca na stronę główną.** Obie
kolumny są `NOT NULL DEFAULT`, więc kolejny `migrate` odtwarza je jako
**odwrotność** obu decyzji.

Dlaczego to NIE jest ten sam przypadek co `theme` i `posts.display_mode`,
pominięte wyżej świadomie i słusznie: to nie jest preferencja wygody.
Własna migracja nazywa pokazanie takiego wpisu bez ostrzeżenia „okrutnym",
`WspomnieniaTest` mówi o „zrobieniu komuś przykrości drugi raz, po tym jak
poprosił, żeby przestać", a kolumna siedzi w ustawieniach **prywatności**
(`PrivacySettingsController`), nie wyglądu.

**Naprawione:** `down()` liczy osobno konta z wyłączonymi wspomnieniami
i schowane wpisy **przed pierwszym `dropColumn`** i odmawia z instrukcją.
Dwie gałęzie warunku mają **osobne** sabotaże w kontroli ujemnej, bo dwa
liczniki nie są ozdobą: konto z włączonymi wspomnieniami i jednym schowanym
wpisem nie ma nic w pierwszym liczniku, a ma co stracić. Sabotaż
„strażnik za `dropColumn`" oblewa wszystkie cztery testy. Stan zakładany
przez **prawdziwe trasy** (`settings.privacy`, `wspomnienia.ukryj`), nie
ręcznym `UPDATE`.

**Czego nie zrobiono i to jest decyzja, nie przeoczenie:** testu skanującego
wszystkie migracje pod tym wzorcem. Heurystyka „`down()` kasuje kolumnę,
którą `up()` nadaje z `DEFAULT`" trafia w każdą zwykłą kolumnę i wymagałaby
ręcznie utrzymywanej listy wyjątków — czyli tego samego co reguła
w `AGENTS.md` §6, tylko z pozorem automatu.

**Wniosek szerszy od jednej migracji, i to jest właściwa treść tego
uzupełnienia:** reguła żyła **tylko** w `docs/DECISIONS.md`, a jedno z jej
złamań chodziło dalej po `main`. Dlatego reguła stoi od 11 września
w `AGENTS.md` §6 — tam, gdzie miała trafić od początku — razem z tabelką
trzech przypadków tej choroby. **Zapisanie reguły w dzienniku nie jest jej
wdrożeniem.**

Osobno, z tego samego PR-a: w komentarzu tamtego `down()` stało „Przy
cofaniu na produkcji najpierw kopia obu kolumn". Zdanie prawdziwe
i konkretne, a jako zabezpieczenie bezwartościowe — przenosiło całą ochronę
na czyjąś pamięć w jedynym momencie, w którym nikt nie czyta komentarzy
w migracjach. **Opis rollbacku nie jest strażnikiem rollbacku**;
zabezpieczeniem jest `throw`.

**Pliki:** `database/migrations/2026_09_06_140000_add_memories_to_users_and_posts.php` ·
`tests/Feature/CofniecieMigracjiNieWlaczaWspomnienTest.php` · `AGENTS.md` §6 ·
`docs/DATABASE.md`

### Naprawa

`down()` liczy `delete_scope = 'everything'` w całej tabeli PRZED jakąkolwiek
operacją i rzuca `RuntimeException` z instrukcją (nie cichym `DELETE` ani
`UPDATE`), gdy choć jedno konto ma tę wartość — ten sam wzorzec odmowy co
`2026_09_10_400100_one_active_data_export_per_user` (D-078) i
`2026_09_07_800000_appeals_open_to_reporters`. Na koncie z `minimum`, albo na
świeżej bazie bez żadnego wyboru, rollback nadal przechodzi bez pytania —
inaczej „naprawą" byłoby zablokowanie rollbacku na zawsze, błąd tej samej
wagi w drugą stronę.

### Kontrola

Test `tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php`
przechodzi PRAWDZIWY cykl `markForDeletion()` → `migrate:rollback --path`,
nie sprawdza tylko kształtu schematu. Kontrola ujemna: przywrócenie
oryginalnego `down()` (bez strażnika) obala test na asercji treści wyjątku;
przywrócenie poprawki — zielono, `git diff` puste.

**Pliki:** `database/migrations/2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php` ·
`tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php` ·
`docs/DATABASE.md`
