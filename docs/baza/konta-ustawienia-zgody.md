# Konta (`users`) — ustawienia, zgody, e-mail, sesja

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

## Tabele MVP

### profile_username_redirects
Dawna nazwa profilu: `/@stara-nazwa` przekierowuje 301 na aktualny profil
tej samej osoby (decyzja właściciela z 1.10.2026, wiersz w D-333; migracja
`2026_10_01_100000_create_profile_username_redirects_table`). Adres z nazwą
trafia na wydrukowane karty z kodem QR, do SMS-ów i zakładek, więc zmiana
nazwy w ustawieniach nie może go zabijać — jak `recipe_slug_redirects` przy
zmianie tytułu przepisu.

- **`username varchar(40) PRIMARY KEY`** — dawna nazwa, ZAWSZE małymi
  literami (CHECK `profile_username_redirects_format_check`:
  `^[a-z0-9_]{3,40}$`). Adres profilu nie rozróżnia wielkości liter
  (`profiles_username_lower_unique`), więc klucz jest w postaci
  kanonicznej, a jedna dawna nazwa prowadzi do najwyżej jednej osoby;
- **`user_id uuid NOT NULL`** → `users` (`ON DELETE CASCADE`) — OSOBA, nie
  nazwa docelowa. Cel liczymy przy żądaniu z `profiles.username`, więc
  łańcuch A → B → C kończy się na C bez pętli i bez wiszących wierszy;
- `created_at timestamptz`. Indeks `profile_username_redirects_user_idx`
  (`user_id`) obsługuje kasowanie przy wymazaniu konta i kaskadę.

**Reguły (`App\Domain\Users\DawneNazwyProfilu`).**
- Zapis przy zmianie nazwy w `App\Domain\Users\Actions\ZapiszProfil`, w tej
  samej transakcji co `UPDATE profiles` (kontroler tylko woła akcję). Zmiana
  samej wielkości liter („Basia" → „basia") niczego nie zapisuje.
- **Żywy profil ma pierwszeństwo.** Przekierowania szukamy dopiero, gdy pod
  nazwą nie ma profilu. Dawnej nazwy nic nie rezerwuje: ktokolwiek może ją
  zająć (zmiana nazwy albo rejestracja), a wtedy wiersz znika w tej samej
  transakcji (`zajmij()`), żeby nie ożył po kolejnej zmianie nazwy przez
  nowego właściciela. Powrót do własnej dawnej nazwy kasuje własny wiersz.
- **Przekierowanie ma prawa profilu, nie większe.** Przed 301 pytamy
  `UserPolicy::viewProfile` widza; odmowa (konto zbanowane lub w trakcie
  usuwania, blokada w którąkolwiek stronę) to 404 — nagłówek `Location`
  zdradziłby nową nazwę osoby, której profilu widz nie widzi.
- Obejmuje `/@nazwa`, `/@nazwa/obserwujacy`, `/@nazwa/obserwowani` i kanał
  Atom `/@nazwa/kanal` (zapytanie w adresie zostaje). Trasy zapisu
  (`obserwuj`, `blokuj`, `ukryj`) NIE przekierowują — formularz zawsze niesie
  aktualną nazwę.
- **Wymazanie konta** (`EraseAccountData`) kasuje wszystkie dawne nazwy
  osoby (RODO art. 17): dawna nazwa jest daną osobową i kluczykiem do nowej.
  Konto `erased` zostaje pod anonimową nazwą, bez śladu poprzednich.
  Wiersze osób, które nie żądały wymazania, żyją bez limitu czasu — dopóki
  nazwy nie zajmie ktoś inny albo konto nie zostanie usunięte.

**Rollback:** `DROP TABLE` bez strażnika (D-088 chroni wartości semantyczne;
tu nic groźnego nie wraca). Cena: dawne adresy i karty z kodem QR sprzed
zmiany nazwy wracają do 404.

### users
Konto:
- id;
- email;
- password;
- status;
- `role varchar(20) NOT NULL DEFAULT 'user'` — `user` \| `moderator` \|
  `admin`. **CHECK jest w bazie**: `users_role_check`
  (`CHECK (role IN ('user','moderator','admin'))`), założony razem z tabelą
  w migracji `0001_01_01_000001_create_users_table`, obok
  `users_status_check` i `users_text_scale_check`. Do 19 września 2026 stało
  tu zdanie „**Bez CHECK-a w bazie**" — nieprawdziwe od pierwszego dnia
  projektu i groźne właśnie dlatego, że nikt go nie sprawdzał: czytelnik
  planujący czwartą rolę wychodził z założenia, że wystarczy dopisać stałą
  w PHP, a baza odrzuci mu `INSERT` bez migracji. Wartości pilnuje więc baza,
  a warstwa PHP dokłada nazwy i drogę: stałe `ROLE_*` w `App\Models\User`
  i jedyna droga nadania roli, komenda `kuking:nadaj-role`, która zapisuje
  zmianę do `audit_log` (`user.role_changed`, D-039). Dołożenie roli to
  **zmiana schematu**: migracja podmieniająca CHECK, nie sama stała.
  **Nigdy w `$fillable`** (AGENTS.md §7) — razem ze `status` i `email`;
- `status_expires_at` — kiedy kara mija (patrz niżej);
- `punishment_status`, `punishment_expires_at` — kara (`suspended`/`banned`)
  ODŁOŻONA na czas cyklu usunięcia konta (issue #980, patrz niżej). Pola
  sterujące: poza `$fillable`, zapisuje je wyłącznie `User` pod blokadą;
- `delete_requested_at` — kiedy zgłoszono usunięcie konta (status `pending_delete`);
- `data_erased_at` — kiedy karencja się WYKONAŁA, dane zostały zanonimizowane
  (patrz niżej);
- `delete_scope` — ZAKRES usunięcia wybrany przez człowieka: `minimum`
  (domyślny — teksty zostają zanonimizowane) albo `everything` (patrz niżej);
- `locale` — język konta, `varchar(10) NOT NULL DEFAULT 'pl'`. Dziś
  **zawsze `pl`** (`ZalozKonto` wpisuje `'pl'` na sztywno, innego wyboru nie
  ma nigdzie w interfejsie); kolumna stoi, bo eksport danych ją oddaje
  (`jezyk`), a dołożenie języka po fakcie do tabeli z prawdziwymi kontami
  kosztuje więcej niż jedna kolumna dzisiaj;
- `age_confirmed_at timestamptz NULL` — kiedy padło oświadczenie o wieku.
  Wymóg prawny, nie preferencja użytkownika. Wpisuje ją `ZalozKonto`
  wartością `now()` na OBU drogach zakładania konta (hasłem i przez Google),
  a eksport danych oddaje ją jako `wiek_potwierdzony`. Kolumna jest
  `NULL`-owalna dla wierszy z fabryk i seederów — **`NULL` nie znaczy
  „ktoś odmówił"**: odmowa nie zakłada konta wcale, więc taki wiersz by nie
  powstał;
- text_scale;
- theme (patrz niżej);
- `wants_weekly_digest` — zgoda na cotygodniowy przegląd, stan BIEŻĄCY (patrz
  niżej); historia jej udzielania i wycofywania leży w `dziennik_zgod`;
- `weekly_digest_sent_at` — kiedy poszło ostatnie podsumowanie (patrz niżej);
- verified timestamps.

#### `pwa_prompt_state` — jednorazowa propozycja instalacji (#278)

Migracja `2026_09_16_200000_add_pwa_prompt_state_to_users` dodaje nullable
`varchar(16)` z CHECK: `eligible`, `offered`, `requested`, `dismissed`,
`installed`. `NULL` oznacza brak kwalifikacji. Kolumna nie trafia do
`$fillable`; przejścia wykonuje `App\Domain\Pwa\InstallPrompt` warunkowym
UPDATE. Zamknięte konta nie zmieniają stanu.

Kwalifikacja wymaga poprzedniej aktywności sprzed co najmniej 24 godzin
i nawigacji HTML zalogowanej osoby. Jest to przybliżenie powrotu po przerwie,
nie wykrywanie zamknięcia przeglądarki. Żądanie w tle zachowuje poprzedni
czas w sesji jako kandydata ważnego godzinę, przypisanego do tego konta;
nie kwalifikuje samo i nie przedłuża tego okna. Następna nawigacja zużywa
kandydata. Dzięki temu tracker aktywności nadal obsługuje żądania tła,
ale prefetch nie odbiera kwalifikacji późniejszemu otwarciu strony.
Rezerwacja `offered` blokuje kolejne
propozycje również po odświeżeniu. `requested` oznacza wybranie przycisku,
a `installed` zgłoszenie zdarzenia `appinstalled` przez klienta; samo
zaakceptowanie okna instalacji nie wystarcza. Stan nie jest spisem
zainstalowanych urządzeń i nie wykrywa późniejszego odinstalowania.

Odmowa jest przypisana do konta, bez identyfikatora lub odmowy w localStorage.
Eksport oddaje `konto.stan_zachety_instalacji`, a wymazanie konta zeruje pole.
Kontekst zapisu jest szyfrowany, związany z kontem i sesją, ważny godzinę;
endpoint dodatkowo wymaga zwykłej ochrony CSRF.

**Rollback:** migracja odmawia usunięcia kolumny, jeśli istnieje stan
`offered`, `requested`, `dismissed` lub `installed`, którego ponowna migracja
nie odtworzy. Dla `NULL`/`eligible` wycofanie jest dozwolone. Kontrola i DDL
są w jednej transakcji z blokadą tabeli. Nie kasować decyzji ludzi w celu
wymuszenia rollbacku.

Migracja `2026_09_16_210000_add_pwa_product_signals` rozszerza zamknięty
słownik `product_signals` o `pwa_prompt_shown`, `pwa_install_requested`,
`pwa_prompt_dismissed`, `pwa_installed`. Każdy ma puste `properties` i podlega
dotychczasowej retencji 90 dni. Wyświetlenie zapisuje się po potwierdzeniu
widoczności panelu przez klienta, nie przy samej rezerwacji. Telemetria jest
pomocnicza: awaria jej zapisu nie cofa decyzji. Rollback tej migracji usuwa
wyłącznie te cztery rodzaje telemetrii i przywraca wcześniejszy CHECK;
nie zmienia `users.pwa_prompt_state`.

#### `terms_notice_dismissed_version` — pasek „Zmieniliśmy regulamin” (#1811, D-306)

Migracja `2026_09_26_120000_add_terms_notice_dismissed_version_to_users`
dodaje nullable `date` bez wartości domyślnej (w PostgreSQL zmiana samego
katalogu, bez przepisywania tabeli). Wartość to data wersji regulaminu
(`kuking.zgody.wersja_regulaminu`), przy której osoba zamknęła pasek;
`NULL` — żadnego jeszcze nie zamknęła. Pasek widzi zalogowane konto założone
przed dniem wersji (strefa `kuking.strefa`), którego wartość jest pusta albo
starsza od bieżącej wersji (`App\Domain\Zgody\ZmianaRegulaminu`). Kolumna
poza `$fillable`; zapisuje ją tylko `ZmianaRegulaminu::zamknij()` (POST
`/regulamin/zmiana/zamknij`). Zamknięcie paska NIE jest akceptacją
regulaminu — to ślad, że komunikat dotarł. Eksport oddaje
`konto.pasek_zmiany_regulaminu_zamkniety_dla_wersji`, wymazanie konta zeruje
pole.

**Rollback (D-088):** migracja odmawia usunięcia kolumny, gdy choć jedno konto
ma wartość — po ponownym `migrate` pasek „jednorazowy” wróciłby do każdego,
kto go zamknął, i zniknąłby ślad powiadomienia. Przy samych `NULL` wycofanie
przechodzi. Kontrola i DDL w jednej transakcji z blokadą tabeli. Nie zerować
kolumny w celu wymuszenia rollbacku; wycofać sam kod paska. Testy:
`ZmianaRegulaminuTest::test_rollback_odmawia_gdy_ktos_zamknal_pasek`
i kontrola dodatnia `test_rollback_przechodzi_gdy_nikt_nie_zamknal_paska`.

#### `policy_notice_dismissed_version` — pasek „Zmieniliśmy politykę prywatności” (D-327, D-332)

Migracja `2026_09_29_180000_add_policy_notice_dismissed_version_to_users`,
bliźniak `terms_notice_dismissed_version`: nullable `date` bez wartości
domyślnej (zmiana samego katalogu, AGENTS.md §6). Wartość to data wersji
polityki (`kuking.zgody.wersja_polityki`), przy której osoba zamknęła pasek;
`NULL` — żadnego jeszcze nie zamknęła. Pasek widzi zalogowane konto założone
przed dniem wersji, którego wartość jest pusta albo starsza
(`App\Domain\Zgody\ZmianaPolityki`). Kolumna poza `$fillable`; zapisuje ją
tylko `ZmianaPolityki::zamknij()` (POST `/prywatnosc/zmiana/zamknij`).
Zamknięcie paska NIE jest zgodą — to ślad, że komunikat dotarł. Eksport oddaje
`konto.pasek_zmiany_polityki_zamkniety_dla_wersji`, wymazanie konta zeruje pole.

**Rollback (D-088):** `down()` odmawia usunięcia kolumny, gdy choć jedno konto
ma wartość (jak przy `terms_notice_dismissed_version`). Testy:
`ZmianaPolitykiTest::test_rollback_odmawia_gdy_ktos_zamknal_pasek` i kontrola
dodatnia `test_rollback_przechodzi_gdy_nikt_nie_zamknal_paska`.

#### `sprzeciw_statystyk_at` — sprzeciw wobec statystyk (RODO art. 21, #2277)

Migracja `2026_09_30_163000_add_sprzeciw_statystyk_at_to_users`: nullable
`timestamptz` bez wartości domyślnej (zmiana samego katalogu, AGENTS.md §6).
Wartość to chwila kliknięcia „Nie licz mnie w statystykach” w ustawieniach
prywatności; `NULL` — sprzeciwu nie ma. Kolumna poza `$fillable`; ustawia ją
i zdejmuje wyłącznie `App\Domain\Users\Actions\PrzestawSprzeciwWobecStatystyk`
(POST/DELETE `/ustawienia/prywatnosc/statystyki`). Zgłoszenie zeruje przy tym
`ostatnio_widziany_at` i odpina `product_signals` tej osoby (`user_id = NULL`).
Póki kolumna jest ustawiona, `ZapiszSygnal` nie zapisuje zdarzeń tej osoby
(sprawdzenie pod `FOR SHARE`, razem z `data_erased_at`),
`ZanotujOstatniaWizyte` nie zapisuje daty wizyty (także w samym `UPDATE`),
a układ strony nie wstawia skryptu Cloudflare Web Analytics. Eksport oddaje
`konto.sprzeciw_wobec_statystyk_od`, wymazanie konta zeruje pole.

**Rollback (D-088):** `down()` odmawia usunięcia kolumny, gdy choć jedno konto
zgłosiło sprzeciw — po ponownym `migrate` serwis znów liczyłby te osoby.
Przy samych `NULL` przechodzi. Testy:
`SprzeciwWobecStatystykTest::test_rollback_odmawia_gdy_ktos_zglosil_sprzeciw`
i kontrola dodatnia `test_rollback_przechodzi_gdy_nikt_nie_zglosil_sprzeciwu`.

#### `wants_weekly_digest` — zgoda, o którą trzeba było zapytać

Migracja `2026_09_07_400000_default_weekly_digest_to_off`.

Kolumna powstała z `DEFAULT true`, a formularz rejestracji o tę zgodę
**nigdy nie pytał** — `resources/views/auth/register.blade.php` ma tylko
`age_confirmed` i `terms_accepted`. Każde nowe konto wstawało więc zapisane
na wysyłkę, o którą nikt go nie zapytał, a polityka prywatności opiera tę
wysyłkę na art. 6 ust. 1 lit. a RODO — czyli na zgodzie.

```sql
ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false;
UPDATE users SET wants_weekly_digest = false WHERE wants_weekly_digest = true;
```

Migracja rusza także ISTNIEJĄCE wiersze, i wolno jej, bo nie ma czego
stracić — oba fakty zmierzone:

1. zgody nie da się wyrazić przy rejestracji (pola nie ma), więc żadne
   `true` w bazie nie pochodzi z decyzji człowieka, tylko z tego `DEFAULT`;
2. cotygodniowego przeglądu **nie ma w kodzie w ogóle** — zero mailable'i,
   zero notyfikacji, zero jobów, a żadne odczytanie tej kolumny nie jest
   klauzulą `where` wybierającą odbiorców. Nic nie zostało wysłane.

Kto chce ten przegląd, włącza go haczykiem na `/ustawienia/prywatnosc`.
Pilnuje tego test KONTROLNY w `ZgodaNaPrzegladNieJestDomyslnaTest` — bez
niego reszta tego testu przechodziłaby także wtedy, gdyby ktoś przez pomyłkę
zabetonował pole na `false` i odebrał ludziom możliwość zapisania się.

> **Uzupełnienie, 10 września 2026 (issue #11, D-057).** Punkt 2 wyżej
> („cotygodniowego przeglądu nie ma w kodzie w ogóle") opisywał stan
> z 7 września i **przestał być prawdą**: wysyłka istnieje
> (`kuking:wyslij-podsumowania`, harmonogram codziennie o 08:30). Zapis
> zostaje tutaj nienaruszony, bo uzasadnia MIGRACJĘ, która wtedy ruszyła
> istniejące wiersze — a wtedy naprawdę nie było czego stracić. Dziś taka
> migracja byłaby odebraniem komuś zgody, którą świadomie wyraził.

**Rollback tej migracji NIE przywraca `DEFAULT true`** (poprawka z 10 września,
audyt DB2, D-072). `down()` jest świadomie pusty: `DEFAULT false` zostaje także
po cofnięciu, bo wysyłka już istnieje i techniczny rollback zapisywałby NOWE
konta na prawdziwy mailing, o który formularz rejestracji nadal nie pyta.
Asymetria jest pełna i jawna — `up()` przestawia `DEFAULT` oraz istniejące
wiersze, `down()` nie przywraca ani jednego, ani drugiego. Tej migracji nie da
się więc cofnąć „wiernie historycznie" i tak ma być: wierny rollback wraca do
stanu groźnego, a listu wysłanego bez zgody nie da się odwołać. Powrót do
opt-outu wymaga jawnego `ALTER TABLE users ALTER COLUMN wants_weekly_digest SET
DEFAULT true` z ręki i dopisania pola zgody do rejestracji.

Niezależnie od drogi, którą `DEFAULT true` mógłby wrócić (ręczny `ALTER`,
przywrócenie bazy z kopii sprzed migracji, rollback na starszym wydaniu kodu),
**wysyłka odmawia startu**: `App\Domain\Digest\BramkaDomyslnejZgody` pyta
`information_schema` przy każdym uruchomieniu `kuking:wyslij-podsumowania`
i przy domyślnym `true` kończy kodem 1, z komunikatem mówiącym, co zrobić.
Przebieg `--na-sucho` przechodzi (nic nie wysyła), ale ostrzega. Pilnuje tego
`RollbackNieWlaczaDigestuTest` — asercją na `information_schema`, nie na
komentarzu w migracji.

#### `weekly_digest_sent_at` — kiedy poszedł ostatni list

Migracja `2026_09_10_100000_add_weekly_digest_sent_at_to_users_table`.

`timestamptz NULL`, bez wartości domyślnej. `NULL` znaczy „jeszcze nigdy nie
dostał" i w dniu tej migracji jest w tym stanie **każde** konto, bo digest do
tej pory nie wyszedł ani razu.

Kolumna robi trzy rzeczy naraz i każda z nich jest konieczna:

1. **pilnuje obietnicy „jeden e-mail tygodniowo, nigdy więcej"** złożonej
   wprost na ekranie `/ustawienia/prywatnosc` — wybór odbiorców odrzuca
   każdego, kto dostał list w ciągu ostatnich
   `config('kuking.digest.odstep_dni')` dni;
2. **czyni zadanie odpornym na powtórne uruchomienie tego samego dnia** —
   `withoutOverlapping()` chroni tylko przed dwoma JEDNOCZESNYMI przebiegami,
   nie przed dwoma po kolei;
3. **wyznacza kolejność wysyłki**, bo ta nie mieści się w jednej dobie:
   konto pocztowe ma limit 300 listów dziennie na cały serwis
   (`docs/decyzje/POCZTA.md` §1), więc podsumowania idą partiami przez kilka
   dni, w kolejności `weekly_digest_sent_at ASC NULLS FIRST` — „kto czeka
   najdłużej, ten pierwszy".

```sql
ALTER TABLE users ADD COLUMN weekly_digest_sent_at timestamptz NULL;

CREATE INDEX users_weekly_digest_kolejka_idx
    ON users (weekly_digest_sent_at ASC NULLS FIRST)
    WHERE wants_weekly_digest;
```

Indeks jest **częściowy**, bo jedyne zapytanie czytające tę kolumnę zawsze
zaczyna od `wants_weekly_digest = true`, a takich kont jest mniejszość (zgoda
jest opt-in od migracji wyżej). `NULLS FIRST` w indeksie zgadza się
z `ORDER BY` w `App\Domain\Digest\OdbiorcyDigestu`, żeby Postgres nie musiał
i tak sortować wyniku.

Kolumna **nie jest w `$fillable`** — ten sam powód co `ostatnio_widziany_at`
(AGENTS.md §7). Zapisuje ją wyłącznie
`App\Domain\Digest\OdbiorcyDigestu::zarezerwuj()` (a przy liście próbnym
`--tylko` — `::oznaczWyslane()`). Masowe przypisanie z żądania pozwoliłoby
cofnąć czyjś znacznik i wysłać mu drugi list w tym samym tygodniu, wbrew
obietnicy z ekranu ustawień.

> **Uzupełnienie, 10 września 2026 (audyt QUEUE-01, D-077).** Punkt 2 wyżej
> („czyni zadanie odpornym na powtórne uruchomienie") był prawdą tylko dla
> przebiegu, który **doszedł do końca**. Znacznik stawiało jedno zapytanie po
> całej pętli, więc awaria po zakolejkowaniu listów nie zostawiała po nich
> żadnego śladu i następny przebieg wysyłał je drugi raz. Odporność daje
> teraz bariera w bazie — `weekly_digest_sends`, sekcja niżej — a ta kolumna
> jest od 10 września zapisywana **osobno dla każdej osoby, w jednej
> transakcji z rezerwacją i PRZED wysłaniem listu**. Sama, bez tabeli
> rezerwacji, nadal by nie wystarczyła: jest porównaniem, czyli odczytem
> przed zapisem, a między nimi jest luka na dwa przebiegi równoległe.

**Rollback.** `down()` kasuje kolumnę i indeks. Bezpieczny, ale nie bez
skutku i trzeba to nazwać: razem z kolumną znika pamięć o tym, komu już
wysłano, więc pierwszy przebieg po przywróceniu napisze także do tych,
którzy dostali list wczoraj. Rollback robi się WYŁĄCZNIE razem
z `KUKING_DIGEST_WLACZONY=false`, nie „przy okazji".

Pilnują tego `TygodniowePodsumowanieTest` (odstęp, powtórne uruchomienie,
dobowy limit) i `WypisanieZPodsumowaniaTest`.

**Dowód udzielenia i wycofania zgody NIE JEST w `users`** — ma własną tabelę
`dziennik_zgod` (opisaną niżej w tym dokumencie, D-072). Dwóch kolumn z datami
(`..._consented_at` / `..._withdrawn_at`) świadomie tu nie ma: przy ciągu
włącz → wyłącz → włącz trzecia zmiana nadpisuje pierwszą i historia,
o którą chodzi, ginie.

#### `text_scale` — rozmiar tekstu, od 11 września także W DÓŁ

Kolumna powstała z migracją tworzącą `users`, z `CHECK (text_scale BETWEEN
90 AND 140)`. Migracja `2026_09_11_600000_rozszerz_skale_tekstu_w_dol`
przesuwa dolną granicę na **70**.

```sql
ALTER TABLE users DROP CONSTRAINT users_text_scale_check;
ALTER TABLE users ADD CONSTRAINT users_text_scale_check
    CHECK (text_scale BETWEEN 70 AND 140);
```

**Co to znaczy w pikselach.** Token `--text-body` to `1.125rem × skala`:

| skala | `--text-body` | podpis w ustawieniach |
|---|---|---|
| 70 | 12,6 px | Bardzo mały |
| 80 | 14,4 px | Mały |
| 90 | 16,2 px | Trochę mniejszy |
| **100** | **18,0 px** | **Zwykły — domyślny, bez zmian** |
| 112 | 20,2 px | Trochę większy |
| 125 | 22,5 px | Duży |
| 140 | 25,2 px | Bardzo duży |

**Dlaczego to nie łamie zasady „tekst ≥ 18 px" z `AGENTS.md`.** Ta zasada
opisuje, co człowiek widzi, ZANIM czegokolwiek dotknie — czyli domyślny
wygląd serwisu. Domyślna skala zostaje 100%. Niżej schodzi wyłącznie ten, kto
sam tak ustawi, i tylko na swoim koncie. Ustawienie czytelności działające
w jedną stronę jest ustawieniem połowicznym; zgłosił to właściciel serwisu,
dla którego 18 px jest za duże.

**Trzy miejsca muszą się zgadzać** — `kuking.text.scales`,
`resources/css/tokens.css` i ten CHECK. Rozjechały się już raz: 8 września
konfiguracja oferowała 140, arkusz znał 150, a CHECK nie pozwalał 150
powstać — skutkiem czego „Bardzo duży" zapisywał się na koncie i NIE ROBIŁ
NIC. Pilnuje tego `SkalaTekstuDzialaTest`, od 11 września razem z podpisami
(`kuking.text.scale_labels`).

**Rollback ODMAWIA** (D-088), gdy choć jedno konto ma zapisane mniej niż 90 —
bo zwężenie CHECK-a wymagałoby podniesienia tym kontom skali, czyli zmiany
cudzego świadomego ustawienia bez słowa. Komunikat mówi, ilu kont to dotyczy,
i podaje drogę bez migracji: usunięcie trzech mniejszych rozmiarów z
konfiguracji i z arkusza (nowe konta ich nie zobaczą, stare zachowają swój
wybór). Wymuszenie: `KUKING_ROLLBACK_PODNIES_SKALE_TEKSTU=true`. Sprawdza to
`CofniecieSkaliTekstuOdmawiaTest`, razem z kontrolą dodatnią (na świeżej
bazie cofnięcie ma przejść bez pytania) i z kontrolą wąskości (konto
z rozmiarem 140 nie może zostać przestawione przy okazji).

#### `theme` — jasny/ciemny wygląd (D-019)

**Zgłoszenie właściciela:** telefon sam przełączał stronę w tryb nocny, choć
nikt o to nie prosił — arkusz stylów szedł za `prefers-color-scheme`
systemu, migracja `2026_09_06_210000_add_theme_to_users`.

```sql
ALTER TABLE users ADD COLUMN theme varchar(10) NOT NULL DEFAULT 'light';
ALTER TABLE users ADD CONSTRAINT users_theme_check
    CHECK (theme IN ('light','dark'));
```

- `light` — domyślny dla KAŻDEGO konta, także już istniejącego;
- `dark` — wyłącznie na jawne życzenie, `/ustawienia/czytelnosc` albo
  szybki przełącznik w stopce.

Świadomie tylko dwie wartości, bez trzeciej „jak w systemie" — patrz
uzasadnienie w samej migracji i w `docs/DECISIONS.md` (D-019): dodanie jej
przywróciłoby dokładnie to zachowanie (motyw zmieniający się sam, bez
pytania), które ta kolumna ma wyłączyć.

Gość (bez konta) dostaje ten sam wybór w ciasteczku, nie na koncie —
`App\Http\Controllers\ThemeController`, nazwa ciasteczka w
`config('kuking.theme.cookie')`.

CHECK jest w bazie, nie tylko w PHP — z tego samego powodu co
`posts.display_mode` wyżej w tym dokumencie: walidator da się ominąć nowym
endpointem, `CHECK` nie.

**Rollback:** `php artisan migrate:rollback --step=1`. `down()` zdejmuje CHECK
i kasuje kolumnę; traci się wyłącznie WYBÓR WYGLĄDU. Żadne konto, wpis ani
zdjęcie nie ginie — bezpieczne na produkcji w trakcie awarii.

### users
Adres e-mail jest zapisywany **małymi literami** (mutator `User::email`,
normalizacja w `User::normalizeEmail()`). Wszystkie miejsca, które szukają
konta po adresie — rejestracja, logowanie, przypomnienie hasła i sam reset —
przepuszczają wpisaną wartość przez tę jedną funkcję. Wcześniej walidacja
pytała bazę o wartość surową, więc „Jan@Example.com" przechodziło
`Rule::unique` i dopiero PostgreSQL odbijał duplikat: **HTTP 500 na
rejestracji** zamiast komunikatu „na ten adres jest już konto" (audyt A25).

**Unikalność bez rozróżniania wielkości liter.** Unikalny indeks funkcyjny
`users_email_lower_unique` na `lower(email)` (migracja
`2026_09_06_120000_add_email_case_insensitive_unique_index`, issue #109).

```sql
CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email));
```

Mutator wyżej to warstwa PHP i obowiązuje **tylko** zapisom przez Eloquenta.
`DB::table('users')->insert()`, seeder, przyszły import i ręczna naprawa danych
w `psql` podczas incydentu omijają go w całości, a `UNIQUE (email)` porównuje
bajty, więc `Jan@example.com` wchodziło obok `jan@example.com`. Przy dwóch
takich kontach `User::findByLogin()` trafia raz na jedno, raz na drugie:
człowiek loguje się i widzi „zniknięte" przepisy, a link do zmiany hasła
dotyczy tylko jednego z kont — bez sposobu, żeby zgadnąć którego.
AGENTS.md §6: walidacja w PHP jest dodatkiem, nie zamiennikiem.

Indeks jest **funkcyjny**, a nie `citext` na kolumnie: `citext` zmieniłby
zachowanie każdego porównania w kodzie, także tam, gdzie nikt się tego nie
spodziewa. Indeks robi jedną rzecz — pilnuje, kto może zająć adres — i jest
tym samym rozwiązaniem co `profiles_username_lower_unique`.

Stary `users_email_unique` **zostaje**. Nowy jest od niego silniejszy, ale to
stary obsługuje zwykłe `where('email', ?)` z `findByLogin()`; indeks funkcyjny
takiego zapytania nie obsłuży.

Migracja **nie scala ani nie kasuje kont**. Gdyby w bazie były już dwa adresy
różniące się tylko wielkością liter, zgłasza je po nazwie i przerywa — decyzję,
które konto zostaje, podejmuje człowiek. Rollback to `DROP INDEX IF EXISTS`,
bez utraty danych; `users_email_unique` zostaje nietknięty przez cały czas,
więc nawet w trakcie rollbacku adres nie zduplikuje się co do znaku.

#### `email` i `email_verified_at` poza `$fillable` (issue #195)

Ta sama reguła co przy `status` i `role` (AGENTS.md §7): adres e-mail to jedyna
droga odzyskania konta, więc jego zmiana jest zmianą **stanu konta**, nie
edycją profilu. Gdyby stał w `$fillable`, dowolny `update($request->all())` —
także taki, który o adresie w ogóle nie myśli — potrafiłby przestawić konto na
cudzą skrzynkę, a stamtąd wystarczy „nie pamiętam hasła".

Adres zapisują wyłącznie dwie nazwane drogi: `User::assignEmail()`
(rejestracja i potwierdzona zmiana) oraz `EraseAccountData` (anonimizacja,
D-022). Sama zmiana adresu na koncie idzie przez
`pending_email_changes` — patrz niżej. Pilnuje tego
`AdresEmailPozaMasowymPrzypisaniemTest`.

#### `session_generation` — generacja sesji konta (issue #1046)

`integer NOT NULL DEFAULT 0`, CHECK `users_session_generation_check`
(`session_generation >= 0`). Migracja
`2026_09_24_100000_add_session_generation_to_users`. Poza `$fillable`: pisze ją
wyłącznie `User::invalidateSessions()`, jednym `UPDATE` razem z rotacją
`remember_token` (`session_generation = session_generation + 1`).

Po co: skasowanie wierszy w `sessions` nie jest trwałym unieważnieniem.
Żądanie rozpoczęte przed „wyloguj wszędzie”, resetem/zmianą hasła, blokadą,
zawieszeniem albo zgłoszeniem usunięcia, które w trakcie nadaje sesji nowy
identyfikator (logowanie z ciasteczka „zapamiętaj mnie”, hasłem, linkiem,
2FA), zapisuje wiersz z powrotem `INSERT`-em na końcu odpowiedzi. Każde
logowanie zapisuje w sesji generację konta (listener `Login`
w `AppServiceProvider`), a `App\Http\Middleware\SprawdzGeneracjeSesji`
w grupie `web` wylogowuje sesję z generacją inną niż bieżąca. Brak klucza
w sesji znaczy `0`, więc wdrożenie nikogo nie wylogowuje. Pomiar na dwóch
procesach: `tests/Feature/SesjaPoUniewaznieniuNieWracaTest.php`.

Rollback: `down()` kasuje wiersze `sessions` kont z `session_generation > 0`
i usuwa kolumnę. Bez tego cykl down/up wyzerowałby licznik i sesja odrzucona
przed rollbackiem znów byłaby zgodna. Skutek to tylko ponowne logowanie tych
kont; nie ginie żadna treść ani decyzja. Licznik żyje w `users`, nie
w magazynie sesji, więc zmiana sterownika sesji (#603) go nie dotyczy.

#### `is_seeded` — treść zalążkowa na produkcji, ale jawnie oznaczona (D-025)

Migracja `2026_09_07_700000_add_is_seeded_to_users`. Boolean, domyślnie
`false`, bez backfillu (żadne wcześniejsze konto nie pochodzi z pliku).

**Ten sam kształt co `tags.is_seeded`** (migracja
`2026_09_07_100000_create_tags_tables`), i z tego samego powodu: **atrybut
POCHODZENIA danych, nie nowy system widoczności obok `status`/`role`.**
Konto z `is_seeded = true` przechodzi przez dokładnie te same bramki co
każde inne — `status` rozstrzyga, czy może czytać/pisać, `role`, czy
moderuje. Ta kolumna nic w tych bramkach nie zmienia; dokłada tylko dwie
rzeczy, obie **czytające** kolumnę, żadna jej nie interpretująca jako nowy
poziom uprawnień:

1. **Etykietę w interfejsie**, wszędzie tam, gdzie serwis pokazuje AUTORA —
   profil, karta wpisu, karta przepisu, komentarz (komponent
   `x-konto-przykladowe`, czytany przez `User::isSeeded()`). D-025 wprost:
   „przy koncie, nie tylko w regulaminie — nikt nie czyta regulaminu, żeby
   dowiedzieć się, czy pisze do człowieka".
2. **Wykluczenie z Weekly Active Cooks i z kohorty retencji**
   (`App\Domain\Analytics\CookEligibility::tylkoLiczeni()` — dawne
   `excludedUserIds()` zniknęło w #1309, wykluczenie jest teraz filtrem SQL,
   nie listą UUID w PHP) — dwanaście person publikuje z definicji plikowej
   i nie ma zasilać liczby, która ma mierzyć żywą społeczność (issue #114,
   ten sam powód, dla którego ta metoda już wyklucza gospodarza i konta
   testowe).

**Dlaczego na `users`, nie na `profiles`.** Wszystkie cztery miejsca z punktu
1 i tak już ładują `User` (`$post->author`, `$recipe->author`,
`$comment->author`), a `is_seeded` jest faktem o KONCIE (kto może się nim
posługiwać), nie o publicznej twarzy — ten sam podział, jaki `profiles` już
ma wobec `users` gdzie indziej w tym pliku.

**Świadomie poza `User::$fillable`**, tym samym powodem co `status`/`role`
(komentarz przy `$fillable` w `User`, AGENTS.md §7): jedyne miejsce, które to
ustawia, to `Database\Seeders\TrescZalazkowaSeeder`, wprost przez
`DB::table('users')->insert()` — ten sam wzorzec zapisu, którego używa
`TagSeeder::utworzBrakujaceTagi()` dla `tags.is_seeded`.

**Skąd te konta.** `database/seeders/dane/tresc-zalazkowa.json` — dwanaście
person, czterdzieści przepisów, osiemdziesiąt wpisów, sześćdziesiąt
komentarzy (D-025, `docs/DECISIONS.md`). Seeder jest idempotentny: drugie
uruchomienie na tej samej bazie nic nie zmienia (dopasowanie po
`lower(username)` dla kont, po `(author_id, title)`/`(author_id, body)` dla
treści) i nigdy nie dotyka konta, którego ta sama nazwa użytkownika należy
już do prawdziwego człowieka — wtedy import tego jednego konta jest
pomijany i zgłaszany w raporcie, dokładnie jak `TagSeeder` przy kolizji
z tagiem utworzonym ręcznie.

**Co ta kolumna świadomie NIE rozstrzyga** — i D-025 zostawia to wprost
otwarte: co się stanie z tymi dwunastoma kontami, gdy do serwisu dołączą
prawdziwi ludzie. Zostawienie ich na zawsze zamienia etykietę w stały
element serwisu; usunięcie kont zabrałoby treść, do której realni
użytkownicy mogli już coś dopisać (komentarz, „Ugotowałem"). Decyzja
właściciela, do podjęcia przed otwarciem rejestracji.

**Rollback — odmawia, gdy są konta z pliku (D-088, audyt B3 W4).** `down()`
zdejmuje kolumnę tylko wtedy, gdy żadne konto nie ma `is_seeded = true`.
Inaczej rzuca wyjątek z liczbą takich kont i instrukcją, co zrobić ręcznie
(wycofać sam kod; a przy cofaniu schematu najpierw zapisać
`SELECT id FROM users WHERE is_seeded = true` i odtworzyć to po powrocie).
Powód: po cichym cofnięciu kolejny `migrate` przywraca kolumnę z
`DEFAULT false`, więc persony stają się nie do odróżnienia od ludzi — bez
etykiety, w WAC i „Liczbie Kukingów" (`CookEligibility`, `LiczbaKukingow`),
w liście kont dotkniętych naprawą #317 (`KontaBezPotwierdzonegoAdresu`).
Wcześniej ochroną było tylko zdanie w tym dokumencie, a AGENTS.md §6 mówi
wprost, że to nie jest zabezpieczenie. Sprawdzenie idzie pod
`LOCK TABLE users IN ACCESS EXCLUSIVE MODE`, w transakcji migracji. Świeża
baza i baza bez kont z pliku przechodzą bez pytania. Test odmowy i dwie
kontrole dodatnie: `tests/Feature/CofniecieMigracjiNieGubiKontZalazkowychTest.php`.

#### `onboarding_zakonczony_at` — pierwsze kroki zakończone albo pominięte (#985)

Migracja `2026_09_24_130000_add_onboarding_zakonczony_at_to_users`.
`timestampTz`, nullable, bez indeksu (czytana tylko dla zalogowanego konta).

**Po co.** Onboarding przerwany zamknięciem karty nie miał drogi powrotu.
`null` znaczy „pokaż na Starcie odnośnik »Dokończ pierwsze kroki«".
`User::onboardingDoDokonczenia()` jest jedynym miejscem decyzji: prowadzi do
`/witaj/ludzie`, gdy konto ma już zapisane zainteresowania, inaczej do
`/witaj/zainteresowania`; zawieszone konto (tylko odczyt) przypomnienia nie
dostaje. Po zalogowaniu NIC nie przekierowuje — `intended` zostaje nietknięte
dla każdej drogi logowania.

**Kto zapisuje.** Wyłącznie żądania POST z CSRF w `OnboardingController`:
`saveFollows()` („Dalej" na ostatnim kroku), `skip()` („Pomiń ten krok")
i `dismiss()` („Nie przypominaj"), i tylko gdy wartość jest pusta. GET
`/witaj/gotowe` niczego nie zapisuje — przeglądarka może go pobrać prefetchem,
a to po cichu zdjęłoby przypomnienie. `DemoSeeder` (`db:seed`) ustawia
znacznik kontom demonstracyjnym, łącznie z moderatorem. Nic jej nie zeruje, więc ponowny powrót do wcześniejszego kroku czy stary
formularz nie przywracają przypomnienia. Poza `$fillable`.

**Backfill.** `up()` ustawia `created_at` wszystkim kontom istniejącym przed
migracją — nie wiemy, czy skończyły onboarding, a przypomnienie pokazane nagle
wszystkim byłoby gorsze od jego braku u kilku osób.

**Rollback:** `down()` zdejmuje kolumnę bez strażnika D-088. Ponowny `up()`
oznacza każde konto jako zakończone, więc cofnięcie może najwyżej wyłączyć
przypomnienie kontom w trakcie onboardingu — nigdy nie włącza go komuś, kto
wybrał „Nie przypominaj". Żaden inny wiersz nie ginie. Backfill i cykl
`down()` → `up()` sprawdza `OnboardingMigracjaZnacznikaTest` na PostgreSQL.

#### `ostatnio_widziany_at` — znacznik ostatniej wizyty (bramka V1, issue #114/#115)

Migracja `2026_09_08_200000_add_last_seen_to_users_table`. `timestampTz`,
nullable, bez wartości domyślnej, z osobnym B-tree indeksem
(`users_ostatnio_widziany_idx`).

**Po co.** `docs/ROADMAP.md` kończy bramkę V1 zdaniem „Planner/groups/forks
dopiero gdy WAC i D30 pokazują powroty" — a przed tą kolumną nic w bazie nie
mówiło, kiedy ktokolwiek ostatnio był w serwisie, więc D7/D30 nie dały się
policzyć wcale. `php artisan kuking:raport` czyta tę kolumnę przez
`App\Domain\Analytics\AktywniWTygodniu` i `App\Domain\Analytics
\PowrotPoDniach`.

**Kolumna, nie trzeci sygnał w `product_signals` — rozstrzygnięcie, nie
domysł.** Pełne uzasadnienie stoi w komentarzu samej migracji; w skrócie:
retencja `product_signals` (90 dni) NIE jest tym, co przesądza — jest dłuższa
niż 30 dni, więc D30 dałoby się policzyć nawet z sygnałów. Przesądza kształt
tabeli: `product_signals_signal_name_check` zamyka `signal_name` na nazwane,
rzadkie zdarzenia. Początkowo były dwa; późniejsze migracje dodały zdarzenia
przeglądu tygodniowego i jednorazowej propozycji PWA. Nadal nie jest to
ogólny dziennik każdej wizyty (migracja
`2026_09_06_220000_create_product_signals_table`, kontrastowana tam wprost
z generalnym serwisem `product_events`, którego to repozytorium nie buduje —
AGENTS.md §3). Log odwiedzin na KAŻDE żądanie każdego konta zmieniłby ten
charakter i rósłby bez końca, wymagając własnej retencji; nadpisywana
kolumna na `users` nie rośnie w ogóle — jeden wiersz na konto, zawsze.

**Zapis throttlowany, nie przy każdym żądaniu.** `App\Http\Middleware
\AktualizujOstatniaWizyte` (globalny, w grupie `web`, w `bootstrap/app.php`,
zaraz PO `EnsureAccountIsActive`) woła `App\Domain\Analytics
\ZanotujOstatniaWizyte`, która zapisuje co najwyżej raz na
`config('kuking.analytics.last_seen_throttle_minutes')` minut (domyślnie 15)
na osobę — próg i uzasadnienie liczby stoją w `config/kuking.php`. Zapis idzie
przez `DB::table('users')->update()`, nie przez `$user->save()`: kolumna jest
świadomie POZA `User::$fillable` (ten sam powód co `status`/`role` — nikt nie
ma ustawić jej masowym przypisaniem z żądania) i zwykły zapis Eloquenta
dotknąłby też `updated_at`, fałszując „kiedy dane konta naprawdę się
zmieniły".

**`NULL` znaczy „nigdy niewidziany od czasu wdrożenia kolumny"**, nie „przed
chwilą" ani „bardzo dawno" — `AktywniWTygodniu`/`PowrotPoDniach` traktują
`NULL` jako „nie liczy się do aktywnych/do powrotu", nigdy jako zero.

**Dana osobowa — w eksporcie RODO.** `App\Domain\Users\Exports
\CollectUserExportData` przepisuje ją do paczki jako `konto.ostatnio_widziany`
z tego samego powodu co `usuniecie_konta_zgloszone` obok niej.

**Zerowana przy anonimizacji konta.** `App\Domain\Users\Actions
\EraseAccountData::handle()` ustawia tę kolumnę na `NULL` w tym samym
`forceFill()`, który anonimizuje e-mail i hasło — `resources/legal
/polityka-prywatnosci.md` (sekcja 2) obiecuje wprost, że ten znacznik znika
wraz z usunięciem/anonimizacją konta, więc kod musi to robić, nie tylko
dokument to twierdzić (`tests/Feature/AccountDeletionPurgeTest.php` pilnuje
tego assercją).

**Rollback:** bezpieczny bez zastrzeżeń. Kolumna jest WYŁĄCZNIE czytana przez
raport — żadna reguła autoryzacji, limitu ani widoczności jej nie używa.
`down()` kasuje indeks i kolumnę; jedyny skutek to utrata najnowszego
znacznika dla każdego konta, a middleware odbuduje go od nowa przy
najbliższej wizycie.
