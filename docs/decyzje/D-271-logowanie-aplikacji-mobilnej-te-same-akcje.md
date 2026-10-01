## D-271 — Logowanie aplikacji mobilnej: te same akcje co WWW, 2FA przez zaszyfrowane wyzwanie, lista urządzeń (25 września 2026)

**Data:** 25 września 2026 · Etap 2 API (D-270) · Status: **obowiązuje**

### Co

1. **`POST /api/v1/tokeny`** (login + hasło + `device_name`) i
   **`POST /api/v1/tokeny/kod`** (drugi krok) idą przez TE SAME akcje co
   formularze WWW. Logika pierwszego kroku wyszła z `LoginController` do
   `App\Domain\Security\Actions\SprawdzHasloPrzyLogowaniu`, drugiego —
   z `TwoFactorChallengeController` do `SprawdzKodDrugiegoSkladnika`.
   Kontrolery WWW i API są teraz adapterami tych samych reguł (D-014).
2. **Wspólne koszyki limitów.** Trzy koszyki `LimitProbHasla`, koszyk prób
   kodu po koncie (`TwoFactorAuthenticator::kluczLimituProb`) i prefiksy
   `throttle:` (`login`, `two_factor`) są te same dla obu dróg. Zgadujący nie
   podwoi budżetu, przeskakując między formularzem a aplikacją.
3. **Konto z 2FA nie dostaje tokenu przed kodem.** Pierwszy krok zwraca 202
   z zaszyfrowanym wyzwaniem (`App\Domain\Api\WyzwanieDwuetapowe`): konto,
   odcisk jego stanu (ten sam co w sesji WWW, #931), nazwa urządzenia,
   termin 10 minut. Bez tabeli — API nie ma sesji. Zmiana
   hasła, statusu albo 2FA unieważnia wyzwanie od razu.
   **Wyzwanie jest jednorazowe (#1972).** Ma losowe `id`; po poprawnym
   kodzie, a przed wydaniem tokenu, `WyzwanieDwuetapowe::zuzyj()` zakłada
   znacznik zużycia przez `Cache::add()` (sklep `database`: `INSERT … ON
   CONFLICT DO NOTHING`, więc z równoległych żądań przechodzi jedno).
   Powtórzone wyzwanie dostaje 422 na polu `challenge` bez sprawdzania kodu
   — nie spala kodu zapasowego ani prób z limitu. Błędny kod wyzwania nie
   zużywa. Znacznik żyje minutę dłużej niż wyzwanie.
4. **Stan konta przy każdym żądaniu z tokenem**
   (`EnsureApiAccountIsActive`, odpowiednik `EnsureAccountIsActive`):
   zamknięte konto → token ginie, 401 `konto_zamkniete`; zawieszone → odczyt
   tak, zapis 403 `konto_zawieszone`, wylogowanie zawsze. Zdania te same co
   na WWW.
5. **Tokeny giną razem z sesjami** (D-270) — zmiana hasła, „wyloguj inne
   urządzenia", blokada, zawieszenie, usunięcie konta.
6. **Ekran „Urządzenia z dostępem"** (`/ustawienia/urzadzenia`): lista
   tokenów, odcięcie jednego i wszystkich. Bez hasła, świadomie: akcja
   wyłącznie odbiera dostęp. Dostępna także przy zawieszeniu. Właścicielem
   tokenu rozstrzyga `PersonalAccessTokenPolicy` — moderator też nie ma
   dostępu, token to poświadczenie, nie treść.
7. **Najwyżej 10 urządzeń na konto** (`kuking.api.max_urzadzen`); kolejne
   logowanie odcina używane najdawniej, zamiast odmawiać.
8. Wydanie i odwołanie tokenu zostawia wpis w `audit_log`
   (`account.api_token_created`, `account.api_token_revoked`,
   `account.api_tokens_revoked_all`).

### Znany ubytek względem WWW: brak Turnstile

Turnstile (D-050) jest captchą przeglądarkową; aplikacja nie ma jej jak
pokazać. Logowanie w API chronią tylko limity z punktu 2 i limit na adres IP
z `BramaApi`. To świadome i zapisane, nie przeoczone. Uzupełnieniem w etapie
aplikacji jest atestacja urządzenia (Play Integrity / App Attest) — osobna
decyzja, gdy aplikacja będzie istnieć.

**Zmiana wymaga:** pomiaru ataku na logowanie przez API, którego limity nie
łapią (wtedy atestacja albo zamknięcie `POST /api/v1/tokeny` flagą).

📄 `app/Http/Controllers/Api/V1/TokenController.php` ·
`app/Domain/Security/Actions/` · `app/Domain/Api/` ·
`app/Http/Middleware/EnsureApiAccountIsActive.php` ·
`app/Http/Controllers/Settings/DevicesSettingsController.php` ·
`tests/Feature/Api/LogowanieApiTest.php` · `tests/Feature/UrzadzeniaZDostepemTest.php`
