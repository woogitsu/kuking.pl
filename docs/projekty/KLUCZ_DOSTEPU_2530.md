# Projekt: opcjonalny klucz dostępu (passkey/WebAuthn) do logowania (#2530)

> **Status: PROJEKT. Bez kodu.** Budowa jest wstrzymana z trzech niezależnych
> powodów (§1). Każdy z nich wymaga decyzji właściciela, nie agenta.

## 1. Dlaczego zatrzymane przed kodem

1. **Brak biblioteki WebAuthn w zależnościach.** `composer.json` i `vendor/`
   nie mają żadnej implementacji WebAuthn/FIDO2 (są tylko `spomky-labs/pki-framework`
   i `web-token/*`, pośrednio z `minishlink/web-push` — to nie jest weryfikator
   WebAuthn). Laravel 13 nie ma tego w standardzie. AGENTS.md §3 każe nie
   dokładać biblioteki bez uzasadnienia, a samo issue mówi: „Wybrać utrzymywaną
   integrację dopiero po decyzji i ocenie zgodności ze stackiem”. Nie
   uruchamiano `composer require`, bo dodanie pakietu wymaga decyzji, nie
   tylko działającej sieci.
2. **Weryfikacja bez biblioteki = własna kryptografia.** Ręczne parsowanie
   CBOR (`attestationObject`), kluczy COSE, `authenticatorData`, flag UP/UV/BE/BS
   i konwersja COSE → PEM pod `openssl_verify` to dokładnie to, czego issue
   zakazuje („Nie projektować własnej kryptografii”). Odrzucone.
3. **Issue samo stawia warunki wstępne**: próba z osobami 50+ przed budową,
   zatwierdzenie zakresu, odzyskiwania, wspieranych urządzeń i relacji do 2FA
   przez właściciela. Żaden z tych punktów nie jest dziś odhaczony, a
   `docs/decyzje/` nie zawiera rozstrzygnięcia.

## 2. Wybór biblioteki — do decyzji właściciela (W-1)

| Pakiet | Za | Przeciw |
|---|---|---|
| `web-auth/webauthn-lib` (Spomky-Labs) | pełna specyfikacja L2/L3, aktywnie utrzymywany, ten sam autor co `web-token/*` i `spomky-labs/pki-framework`, które już są w locku | duży; ciągnie `symfony/serializer` i kilka pakietów; trzeba sprawdzić zgodność wersji `web-token/*` z `minishlink/web-push` |
| `lbuchs/webauthn` | jeden mały pakiet bez zależności, prosty | mniejszy zespół, wolniejsze wydania, mniej kontroli (np. `backupState`) |

Front: **bez nowego pakietu npm**. `navigator.credentials.create/get` jest
w przeglądarce, potrzebna jest tylko konwersja base64url ↔ `ArrayBuffer`
(kilkanaście linijek w `resources/js`, z testem `node --test`).

Atestacja: **`none`** (nie pytamy o model urządzenia, nie trzymamy AAGUID —
minimalizacja; patrz W-6).

## 3. Model danych (migracja po akceptacji — skill `kuking-migracja`)

Tabela `klucze_dostepu`:

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid FK → `users`, `ON DELETE CASCADE` | |
| `credential_id` | bytea, `UNIQUE` | identyfikator poświadczenia od urządzenia |
| `public_key` | bytea | klucz publiczny COSE — **nie** klucz prywatny, nie biometria |
| `sign_count` | bigint ≥ 0, `CHECK` | wykrywanie sklonowanego klucza (gdy urządzenie liczy) |
| `backup_eligible`, `backup_state` | boolean | czy klucz jest synchronizowany (iCloud, Google, menedżer haseł) — do komunikatu „klucz może być na kilku Twoich urządzeniach” |
| `nazwa` | varchar(60), `CHECK` niepusta po `btrim` | nadana przez osobę, np. „Telefon Ani” |
| `created_at`, `last_used_at` | timestamptz | |

- **Nic z tego w `$fillable`** (`credential_id`, `public_key`, `sign_count` to
  poświadczenia — AGENTS.md §7, `WrazliweKolumnyPozaMasowymPrzypisaniemTest`);
  zapis jedną nazwaną akcją (`DodajKluczDostepu`), zmiana nazwy osobną metodą.
- Limit kluczy na konto (propozycja 10, `config/kuking.php`).
- `down()`: tabela bez wartości semantycznych innych tabel — `DROP TABLE`
  po sprawdzeniu, że jest pusta; gdy nie jest, **odmawia** (D-088), bo
  usunięcie kluczy po cichu odcina ludziom drogę wejścia, której używają.
- `docs/DATABASE.md` + `docs/baza/`.

## 4. Przepływy

### 4.1 Dodanie klucza (Ustawienia → Bezpieczeństwo → „Klucze dostępu”)

1. Ekran (skill `kuking-ekran`) z listą kluczy i przyciskiem „Dodaj klucz
   dostępu”. Formularz prosi o **obecne hasło** w tym samym formularzu,
   `Hash::check()` pod limitem `confirm_password` — wzór D-245. Sama otwarta
   sesja nie wystarcza (wymóg issue).
2. Konta bez hasła (tylko Google/Facebook/link) — **W-3**: (a) jak D-245,
   najpierw ustawić hasło przez „Nie pamiętam hasła”; albo (b) świeży link
   e-mail jako potwierdzenie. Projekt zakłada (a), bo nie tworzy nowej drogi.
3. Serwer generuje wyzwanie: 32 losowe bajty (`random_bytes`), w **sesji**
   pod kluczem z rodzajem operacji `rejestracja`, `user_id` i terminem
   (propozycja 120 s). `rp.id` = host z `APP_URL`, `user.id` = losowy uchwyt
   konta (nie e-mail, nie UUID konta — W-6), `excludeCredentials` = klucze konta,
   `userVerification = required`, `residentKey = required` (logowanie bez
   wpisywania adresu).
4. Przeglądarka tworzy klucz; formularz wysyła wynik zwykłym POST-em z
   tokenem CSRF.
5. Weryfikacja przez bibliotekę: typ `webauthn.create`, wyzwanie, origin ==
   `APP_URL`, skrót RP ID, flagi UP i UV, algorytm z listy (ES256, RS256).
   Wyzwanie jest **zdejmowane z sesji przed weryfikacją** (`pull`) — drugie
   wysłanie tego samego wyniku dostaje „wyzwanie nieaktualne”.
6. Zapis pod blokadą wiersza konta; `audit_log` `security.passkey_added`
   (bez `credential_id` i klucza); e-mail „Do konta dodano klucz dostępu” z
   instrukcją, co zrobić, jeśli to nie Ty (wzór zmiany hasła).

### 4.2 Logowanie

1. Na `/login` przycisk „Zaloguj się kluczem dostępu” — **`type="submit"`
   w osobnym formularzu POST** (jak przyciski Google/Facebook po #2751), żeby
   bez JavaScriptu nie był martwy: bez JS serwer odpowiada ekranem
   „Twoja przeglądarka nie obsługuje tu klucza dostępu — zaloguj się hasłem
   albo linkiem z e-maila” (D-053: ta droga wymaga JS, więc musi mieć jawną
   alternatywę).
2. Z JS: POST pobiera wyzwanie `logowanie` (w sesji, 120 s, bez `user_id`),
   `navigator.credentials.get` z `userVerification = required`, wynik idzie
   POST-em.
3. Weryfikacja: typ `webauthn.get`, wyzwanie zdjęte z sesji (jednorazowe),
   origin, RP ID, UP+UV, podpis kluczem z `klucze_dostepu` wyszukanym po
   `credential_id`, `userHandle` zgodny z kontem, `sign_count` rośnie (gdy
   ≠ 0); spadek licznika = odmowa + `audit_log` `security.passkey_clone_suspected`.
4. **Ta sama końcówka co pozostałe drogi** (D-271): stan konta (zablokowane,
   zawieszone, `pending_delete`, wymazane — te same odmowy i komunikaty co
   przy haśle), `session()->regenerate()`, `ZapamietajMnie::zZadania()`,
   `audit_log` `auth.login` z metodą `passkey`, `last_used_at`.
5. **2FA (W-4)** — propozycja: klucz zastępuje **hasło, nie drugi składnik**,
   dokładnie jak link (`LoginLinkController::store()`): konto z
   `hasTwoFactorConfirmed()` idzie do `login.two_factor`. Alternatywa (klucz
   z UV jako pełne 2FA) zmienia model uprawnień — duża decyzja.
6. **Moderatorzy i administratorzy (W-5)** — propozycja: do osobnej decyzji
   dodanie klucza na koncie z rolą jest wyłączone; bramki panelu i wymagane
   2FA bez zmian.
7. Limity w `config/kuking.php`: `passkey_wyzwanie` (np. 10/min na IP)
   i `passkey_logowanie` (te same trzy koszyki co `login_limits`: para
   IP+poświadczenie, poświadczenie, adres). Turnstile — **W-7** (przycisk stoi
   przed logowaniem; ale podpis kluczem i tak wymaga urządzenia).

### 4.3 Usunięcie i utrata klucza

- „Usuń ten klucz” przy każdym kluczu (POST, potwierdzenie hasłem jak przy
  dodaniu — **W-3**), Policy `KluczDostepuPolicy::delete` (właściciel klucza;
  UUID w adresie nie jest autoryzacją), `audit_log`, e-mail.
- **Utrata klucza nie blokuje konta**: hasło, link e-mail i Google/Facebook
  zostają bez zmian; klucz nigdy nie jest jedyną drogą, bo dodać go można
  tylko do konta, które ma hasło (4.1 pkt 2a) — więc „usunięcie ostatniej
  drogi wejścia” nie zachodzi. Ekran mówi: „Zgubiony telefon? Usuń jego klucz
  tutaj po zalogowaniu hasłem albo linkiem z e-maila”.
- **Reset/zmiana hasła (W-8)** — propozycja: klucze zostają (to inne
  poświadczenie), ale e-mail o zmianie hasła mówi, ile kluczy ma konto i gdzie
  je usunąć. „Wyloguj inne urządzenia” nie usuwa kluczy.
- Podniesienie roli do moderatora/administratora: klucze konta zostają, ale
  przestają działać do decyzji W-5 (sprawdzane przy każdym logowaniu, nie
  tylko przy dodaniu).

## 5. Dane osobowe

- **Polityka prywatności**: nowy wiersz w tabeli §2 („Klucz dostępu, jeśli go
  dodasz”: nazwa nadana przez Ciebie, daty dodania i ostatniego użycia,
  publiczna część klucza i jej identyfikator; nie mamy klucza prywatnego ani
  odcisku palca czy twarzy — te zostają w urządzeniu; podstawa: wykonanie
  umowy; retencja: do usunięcia klucza albo konta) i zdanie o synchronizacji
  przez usługę wybraną przez osobę. Właściciel 2.10 zdecydował „wersjonowanie
  bez zmian”, a dziś `resources/legal/polityka-prywatnosci.md` jest bajt
  w bajt równy `resources/legal/archiwum/polityka-prywatnosci-2026-09-30.md`
  (`cmp`) — **ta sama zmiana w obu plikach**, sprawdzona `cmp` przed PR-em,
  i `kuking.zgody.zmiana_*.istotna` ustawione jawnie (D-327; W-9: istotna czy
  drobna).
- **`InwentarzDanychKonta`**: `klucze_dostepu.user_id` → `EKSPORT`, sekcja
  `klucze_dostepu` (nazwa, `created_at`, `last_used_at`, `backup_state`);
  `credential_id`, `public_key`, `sign_count` w paczce **nie** wychodzą
  (poświadczenie). `EksportObejmujeKazdaTabeleKontaTest` wymusi wpis.
- **Eksport**: nowa sekcja w `dane.json`, wzmianka w polityce §4.
- **Wymazanie**: `EraseAccountData` usuwa wiersze jawnie (nie tylko kaskadą,
  bo wymazanie anonimizuje, a nie kasuje `users`); test, że po wymazaniu klucz
  nie loguje.
- **Logi**: żadnego `credential_id`, `clientDataJSON`, `userHandle` ani
  wyzwania w logach i w `audit_log.metadata`.

## 6. Testy (po akceptacji)

Serwerowa strona testowana na prawdziwych podpisach: test generuje parę
kluczy ES256 przez `openssl_pkey_new`, sam składa `clientDataJSON`
i `authenticatorData` (atestacja `none`) i podpisuje — bez mocka biblioteki.

Dodatnie: dodanie, logowanie, „Zapamiętaj mnie” zaznaczone/odznaczone,
zmiana nazwy, usunięcie, eksport, wymazanie.
Ujemne: zły origin; zły RP ID; zły podpis; brak flagi UV; wyzwanie powtórzone,
wygasłe, z innej operacji (rejestracja użyta do logowania i odwrotnie);
poświadczenie innego konta; usunięty klucz; spadek `sign_count`; konto
zablokowane/zawieszone/`pending_delete`/wymazane; konto z 2FA nie wchodzi bez
kodu; rola moderatora (W-5); dodanie bez hasła i ze złym hasłem; limit
kluczy; dwa równoległe logowania tym samym wyzwaniem (jedno wchodzi);
Policy cudzego klucza (`KazdaTrasaZIdentyfikatoremPodPolicyTest`); przycisk
bez JS prowadzi do instrukcji; `node --test` konwersji base64url i obsługi
`NotAllowedError` / `NotSupportedError` po polsku. Strażnicy: tekst ≥ 18 px,
przyciski ≥ 48 px, `novalidate`, `TekstyNiePrzypisujaPlciTest`,
`DokumentyPrawneNieKlamiaTest`, `cmp` polityki z archiwum. Osobno: odbiór na
rzeczywistych urządzeniach (W-2) — test automatyczny tego nie zastąpi.

## 7. Decyzje właściciela (przed kodem)

- **W-0** Czy budować w ogóle — po próbie z osobami 50+ (dodanie, powrót,
  anulowanie okna systemowego, utrata telefonu), porównanej z linkiem i Google.
- **W-1** Biblioteka: `web-auth/webauthn-lib` czy `lbuchs/webauthn` (§2).
- **W-2** Wspierane urządzenia i przeglądarki do odbioru.
- **W-3** Potwierdzenie przy dodaniu i usunięciu: hasło (D-245) czy świeży
  link e-mail; konta bez hasła.
- **W-4** Relacja do 2FA: klucz zastępuje hasło (propozycja) czy oba składniki.
- **W-5** Konta z rolą: wyłączone do osobnej decyzji (propozycja).
- **W-6** Minimalizacja: bez AAGUID i atestacji (propozycja).
- **W-7** Turnstile na pobraniu wyzwania logowania.
- **W-8** Los kluczy po resecie/zmianie hasła.
- **W-9** Zmiana polityki istotna czy drobna (D-327).
