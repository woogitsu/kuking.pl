# Audyt bezpieczeństwa Kuking.pl — 15 września 2026

**Badany kod:** gałąź `main` w commicie `61360bf` („Merge pull request #578 …").
**Zakres:** `app/`, `routes/`, `config/`, `bootstrap/`, `database/migrations/`,
`resources/views/`, `docker/`, `Dockerfile`, `.railway/`, `.github/workflows/`,
`composer.lock`, `package-lock.json`, dokumenty w `docs/` i `resources/legal/`
tam, gdzie kod składa obietnice, które da się sprawdzić.
**Tryb:** tylko odczyt kodu. Żaden plik aplikacji nie został zmieniony.

Audyt prowadziło pięć równoległych ścieżek: cztery agenty czytające kod po
jednym obszarze (uwierzytelnianie i konto · autoryzacja i moderacja ·
wejście/wyjście/media · prywatność, dane i kolejka) oraz przegląd
infrastruktury i zależności. Każde znalezisko o wadze Wysokie i Średnie
zostało po raz drugi odczytane w kodzie przez osobę składającą ten dokument.

## Jak czytać oznaczenia

| Oznaczenie | Znaczenie |
|---|---|
| **ZMIERZONE** | ścieżka w kodzie przeczytana od wejścia do skutku; wskazane pliki i linie |
| **WYWNIOSKOWANE** | wniosek z kodu aplikacji plus znane zachowanie frameworka, którego nie dało się tu potwierdzić (patrz „Ograniczenia") |
| **Krytyczne / Wysokie / Średnie / Niskie / Informacyjne** | waga: prawdopodobieństwo × skutek dla osoby 50+, która ufa temu serwisowi |

## Ograniczenia tego audytu — co NIE zostało zmierzone

1. **Testy i PHPStan nie zostały uruchomione.** Polityka sieciowa środowiska
   audytu blokuje pobieranie paczek z GitHuba (403 z `codeload.github.com`),
   więc `composer install` nie zbudował `vendor/`. Wszystkie stwierdzenia
   o zachowaniu klas Laravela (recaller, `SerializesModels`, reguła `url`,
   Markdown w `MailMessage`) są oparte na znanym zachowaniu Laravela 10–13
   i oznaczone jako WYWNIOSKOWANE. Każde z nich ma w sekcji „Naprawa" jedną
   linijkę, którą można zweryfikować lokalnie w minutę.
2. **Konfiguracja produkcji w Railway nie jest widoczna z repozytorium.**
   Wartości `SESSION_SECURE_COOKIE`, `KUKING_ZAUFANE_PRZESKOKI`,
   `signed_url_minutes`, publiczność bucketów R2 — to, co mówi
   `.railway/railway.ts`, przyjęto za zamiar, nie za stan. Poprzednie audyty
   (`docs/AUDYT_2026-09.md`, `docs/AUDYT_GPT_2026-09.md` G02) zostawiły to
   samo nierozstrzygnięte.
3. **Dwa znaleziska zweryfikowano po publikacji raportu** (PR #582): D-01
   odtworzono wykonaniem i przeniesiono do ZMIERZONE, a A-01 **częściowo
   wycofano** — zmiana hasła unieważnia recallery, wbrew pierwotnemu
   twierdzeniu. Oba sprostowania są wpisane przy znaleziskach, nie schowane
   w historii gita.
4. **Zależności:** `composer audit --locked` (baza Packagist) i `npm audit`
   zostały uruchomione i **nie wykazały żadnej znanej podatności**.
   Skan historii gita pod kątem sekretów (klucze AWS, Slack, Discord, klucze
   prywatne, `APP_KEY`) — czysty; jedyne trafienia to placeholdery
   w dokumentacji i `base64:AAAA…` w CI.

---

## 1. Streszczenie na jedną stronę

**Kuking.pl nie ma dziury klasy „każdy może wejść na cudze konto" ani
„każdy może przeczytać cudze prywatne dane po adresie".** Model autoryzacji
(Policy na każdej trasie z identyfikatorem), ochrona przed masowym
przypisaniem, CSP z nonce, hashowane tokeny jednorazowe, obsługa OAuth,
anonimizacja IP i konsekwentne limity zapytań są na poziomie, jakiego
rzadko się widzi w projekcie tej wielkości. Dwa ustalenia poprzedniego audytu
GPT, G04 (transakcja „Ugotowałem" + powiadomienie) i G05 (wymazanie relacji
i sekretów 2FA), **są naprawione** i mają testy regresyjne.

Znaleziono **39 pozycji**: 0 krytycznych, **3 wysokie**, **10 średnich**,
14 niskich, 12 informacyjnych. Trzy wysokie to:

1. **A-01 · „Wyloguj inne urządzenia" nie wylogowuje, a włączenie 2FA nie
   wyrzuca już zalogowanych.** Każde logowanie wymusza ciasteczko „zapamiętaj
   mnie" (~400 dni), którego żadna z tych dwóch operacji nie rotuje. Komunikat
   „Wylogowaliśmy wszystkie inne urządzenia" jest nieprawdziwy, a drugi
   składnik nie obejmuje sesji sprzed jego włączenia. Zmiana hasła działa
   poprawnie — pierwotna wersja raportu twierdziła inaczej i to sprostowano.
2. **B-01 · Moderator może zbanować administratora, innego moderatora
   i siebie**, także na własnym zgłoszeniu. Utrata jedynego admina jest
   odwracalna tylko z konsoli.
3. **D-01 · Zakolejkowany digest tygodniowy niesie w payloadzie pełny wiersz
   `users` odbiorcy** (e-mail, hash hasła, `remember_token`, szyfrogram 2FA),
   a `failed_jobs` nie ma retencji. To są dokładnie trzy człony ciasteczka
   „zapamiętaj mnie", więc zrzut tej tabeli daje gotowy klucz do konta, nie
   tylko hashe do łamania offline. **Potwierdzone wykonaniem** w PR #582:
   wszystkie cztery markery znalezione w 5041 bajtach payloadu.

Wspólny mianownik średnich: **spójność granic**. Kilka publicznych formularzy
z hasłem omija trzykoszykowy limiter logowania (A-02, B-04), formularz DSA
wysyła list z domeny Kuking na dowolny adres z treścią zgłaszającego (C-01),
blokada użytkownika działa także na moderatora (B-02), wykonanie zbanowanego
kucharza widać pod bezpośrednim linkiem (B-03), a wymazanie konta i eksport
danych nie obejmują kilku tabel pobocznych, o których polityka prywatności
składa obietnice (D-03, D-04, D-05).

### Kolejność wykonania (rekomendowana)

| Krok | Pozycje | Szacunek | Dlaczego w tej kolejności |
|---|---|---|---|
| 1 | A-01 | 2–4 h | Przycisk „wyloguj inne urządzenia" dziś kłamie, a 2FA nie obejmuje już zalogowanych; poprawka to rotacja `remember_token` w jednej metodzie plus dwa testy na dwóch klientach |
| 2 | D-01 + D-02 | 3–5 h | Zdejmuje poświadczenia z tabeli kolejki i dodaje retencję `failed_jobs`; potem dopiero można spokojnie robić zrzuty bazy do restore-testów |
| 3 | B-01 | 2–3 h | Hierarchia ról w `decide()` + test „moderator nie banuje admina" |
| 4 | A-02, B-04 | 2–3 h | Jeden wspólny wzorzec: stan konta przed hasłem, wspólny komunikat, koszyki `KluczeLimitow`, Turnstile |
| 5 | C-01 | 1–2 h | `url:http,https` + host własny + adres w liście jako kod |
| 6 | B-02, B-03, B-05 | 2–4 h | Trzy zmiany w Policy + decyzja produktowa w `docs/DECISIONS.md` |
| 7 | D-03, D-04, D-05 | 4–8 h | Domknięcie obietnic z polityki prywatności (retencja, wymazanie, eksport) |
| 8 | E-01, A-03 | 2–3 h | `/health` za limitem lub tokenem; hasło przy łączeniu Facebooka + rozłączanie |
| 9 | reszta niskich | wg okazji | głównie jedno-liniowe |

---

## 2. Tabela wszystkich znalezisk

| ID | Waga | Obszar | Jedno zdanie | Pewność |
|---|---|---|---|---|
| A-01 | **Wysokie** | Konto | „Wyloguj inne urządzenia" i włączenie 2FA nie unieważniają ciasteczka „zapamiętaj mnie"; zmiana hasła unieważnia (sprostowane) | ZMIERZONE (kod aplikacji i frameworka v13.30.1); regresja HTTP do wykonania |
| A-02 | Średnie | Konto | Cofnięcie usunięcia konta i odwołanie gościa to wyrocznie hasła poza koszykami per konto, z różnicującym komunikatem; odwołanie bez Turnstile | ZMIERZONE |
| A-03 | Średnie | Konto | Połączenie konta Facebooka bez hasła i bez drogi rozłączenia | ZMIERZONE |
| A-04 | Niskie | Konto | Czas odpowiedzi logowania zdradza istnienie konta (bcrypt tylko dla istniejących) | ZMIERZONE |
| A-05 | Niskie | Konto | Stan „po haśle, przed kodem 2FA" bez terminu i bez powtórnego sprawdzenia statusu konta | ZMIERZONE |
| A-06 | Niskie | Konto | Dokumentacja obiecuje „eksport wymaga potwierdzonego e-maila", kod tego nie sprawdza | ZMIERZONE |
| A-07 | Niskie | Konto | Turnstile fail-open i bez sprawdzenia `hostname`/`action` | ZMIERZONE (świadome) |
| A-08 | Informacyjne | Konto | Token tożsamości Google czytany bez weryfikacji podpisu (dopuszczalne dla wymiany kodu, groźne przy One Tap) | ZMIERZONE |
| A-09 | Informacyjne | Konto | `session.secure` bez wartości domyślnej, zależne wyłącznie od env | ZMIERZONE |
| B-01 | **Wysokie** | Moderacja | Moderator może zbanować/zawiesić admina, moderatora i siebie, także na własnym zgłoszeniu | ZMIERZONE |
| B-02 | Średnie | Autoryzacja | Blokada założona na moderatora ukrywa przed nim zgłoszone treści (404 w panelu) | ZMIERZONE |
| B-03 | Średnie | Autoryzacja | Wykonanie zbanowanego kucharza znika z list, ale jest publiczne pod bezpośrednim adresem, ze zdjęciem w cache `public` | ZMIERZONE |
| B-04 | Niskie | Autoryzacja | Odwołanie gościa omija 2FA i pozwala złożyć odwołanie w imieniu konta (duplikuje A-02 od strony skutku) | ZMIERZONE |
| B-05 | Niskie | Moderacja | Moderator nie widzi ukrytego wpisu, ale widzi ukryty przepis; przywracanie i odwołania „w ciemno" | ZMIERZONE |
| B-06 | Informacyjne | Autoryzacja | Moderator widzi każde zdjęcie po UUID, także z prywatnych treści, których strony nie zobaczy; bez audytu | ZMIERZONE |
| B-07 | Informacyjne | Moderacja | Panel „Bez odpowiedzi" pozwala komentować wpisy `followers` bez Policy `view` | ZMIERZONE |
| B-08 | Informacyjne | Moderacja | Autor może edytować komentarz ukryty przez moderację (zmiana dowodu po decyzji) | ZMIERZONE |
| C-01 | Średnie | Wejście / poczta | Formularz DSA wysyła list od Kuking na dowolny adres z 2000-znakowym „adresem" zgłaszającego renderowanym jako Markdown | ZMIERZONE (kod) / WYWNIOSKOWANE (Markdown) |
| C-02 | Niskie | XSS | `source_url` z regułą `url` bez schematów trafia do `href`; CSP blokuje `javascript:` | WYWNIOSKOWANE |
| C-03 | Niskie | SQL / DoS | Wyszukiwarka nie escapuje `%`, `_`, `\` w LIKE — pełny skan zamiast indeksu | ZMIERZONE |
| C-04 | Niskie | CSRF | Wypisanie i ponowny zapis do digestu przez GET, podpisem bez daty ważności (to samo co D-06) | ZMIERZONE |
| C-05 | Niskie | Media | Tymczasowe uploady Livewire bez kontroli typu; `preview_mimes` zawiera `svg` | ZMIERZONE |
| C-06 | Informacyjne | DoS | Dekodowanie obrazu do 25 Mpx w żądaniu HTTP przy `memory_limit=256M` | ZMIERZONE (świadome) |
| C-07 | Informacyjne | Media | Prywatne zdjęcia przez 302 na podpisany URL R2 — przekazywalny przez `signed_url_minutes` | ZMIERZONE |
| C-08 | Informacyjne | Prywatność | Oryginały zachowują EXIF poza GPS (prywatny bucket) | ZMIERZONE |
| D-01 | **Wysokie** | Kolejka / dane | Payload digestu niesie pełne wiersze `users` (hash hasła, `remember_token`, sekret 2FA) i leży w `failed_jobs` bez retencji | **ZMIERZONE** — odtworzone wykonaniem, PR #582 |
| D-02 | Średnie | Kolejka / dane | Tokeny jednorazowe (link logowania, zaproszenie, reset) jawnie w `jobs`/`failed_jobs`; brak `queue:prune-failed` | ZMIERZONE |
| D-03 | Średnie | RODO | `weekly_digest_sends` to historia bez retencji, polityka obiecuje „jedną nadpisywaną wartość" | ZMIERZONE |
| D-04 | Średnie | RODO | Wymazanie konta zostawia dane w `notifications` innych osób, `contact_messages`, `mail_failures`, `product_signals` | ZMIERZONE |
| D-05 | Średnie | RODO | Eksport danych bez zgód, tożsamości zewnętrznych, wiadomości do nas, zgłoszeń, dziennika bezpieczeństwa | ZMIERZONE |
| D-06 | Niskie | RODO | Trwały link „wracam" (ponowna zgoda) przez GET (to samo co C-04) | ZMIERZONE |
| D-07 | Niskie | Audyt | Wyłączenie 2FA komendą konsolową bez wpisu w `audit_log` | ZMIERZONE |
| D-08 | Niskie | Kod | `unserialize()` payloadu kolejki bez `allowed_classes` | ZMIERZONE |
| D-09 | Informacyjne | Repo | Pusty plik `object_key` w katalogu głównym | ZMIERZONE |
| D-10 | Informacyjne | Repo | `.gitignore` bez `*.dump`, `*.sql`, `*.dump.cms` | ZMIERZONE |
| D-11 | Informacyjne | RODO | `dziennik_zgod` append-only bez zdania o retencji w polityce | ZMIERZONE |
| E-01 | Średnie | Infrastruktura | `/health` publiczny, bez limitu, przy każdym wywołaniu zapisuje do R2 i ujawnia stan konfiguracji integracji | ZMIERZONE |
| E-02 | Niskie | Infrastruktura | Ta sama polityka CSP wysyłana dwa razy (wymuszana i Report-Only) — podwójne raporty, brak wartości | ZMIERZONE |
| E-03 | Informacyjne | Infrastruktura | Caddy i Laravel ufają wszystkim proxy (`0.0.0.0/0`, `at: '*'`); bezpieczne tylko dopóki jedynym wejściem jest brzeg Railway i liczba przeskoków jest zmierzona | ZMIERZONE |

---

## 3. Znaleziska szczegółowe

### A. Uwierzytelnianie, sesje, cykl życia konta

#### A-01 · Wysokie · „Wyloguj inne urządzenia" i włączenie 2FA nie unieważniają ciasteczka „zapamiętaj mnie"

> **SPROSTOWANIE z 15.09.2026, po weryfikacji w PR #582.** Pierwotna wersja tego
> znaleziska twierdziła, że recaller przeżywa także **zmianę hasła**. To było
> błędne i zostało wycofane. Sprawdzenie w `laravel/framework` **v13.30.1**
> (dokładnie ta wersja stoi w `composer.lock`), plik
> `src/Illuminate/Auth/SessionGuard.php:234-243`: `userFromRecaller()` porównuje
> `hash_equals($this->hashPasswordForCookie($userPassword), $recallerHash)`,
> czyli **hash hasła jest częścią ciasteczka i zmiana hasła unieważnia recallery
> na wszystkich urządzeniach, bez `AuthenticateSession`**. Pozostała część
> znaleziska stoi i jest opisana niżej.

**Pliki**
- `app/Http/Controllers/Settings/SecuritySettingsController.php:63-69` (`updatePassword`), `:109` (`logoutOtherSessions`) — komunikat „Wylogowaliśmy wszystkie inne urządzenia" w `:80` i `:112`.
- `app/Models/User.php:1242-1257` (`invalidateSessions()`) — kasuje wiersze w `sessions` i tokeny linków; **nie rusza `remember_token`**. To samo `confirmTwoFactor()` (`:1325`), `markForDeletion/suspend/ban` (`:1110/1178/1195`).
- `bootstrap/app.php` — grupa `web` bez `Illuminate\Session\Middleware\AuthenticateSession`.
- Framework: `Illuminate\Auth\SessionGuard::userFromRecaller()` (v13.30.1, `:234-243`) i `logoutOtherDevices()` (`:752-786`), który **przemusza rehash hasła** i dlatego wywala pozostałe urządzenia. Aplikacja tej metody **nie woła**.
- Każde logowanie: `Auth::login($user, remember: true)` — `LoginController.php:155`, `LoginLinkController.php:421`, `GoogleLoginController.php:464,607`, `FacebookLoginController.php:521,674`, `RegisterController.php:238`. Widok logowania nie ma pola wyboru.
- Kontrast: `PasswordResetController.php:138` rotuje `remember_token` poprawnie.

**Co robi kod.** Laravel wystawia recaller `id|remember_token|hash(hasła)` (domyślnie ~400 dni). `SessionGuard::login()` tworzy `remember_token` tylko gdy jest pusty, więc token jest **stały dla konta**. Przy odzyskiwaniu sesji `userFromRecaller()` sprawdza dwie rzeczy: czy `remember_token` pasuje i czy trzeci segment odpowiada **bieżącemu** hashowi hasła.

Stąd podział:

| Operacja | Czy unieważnia recallery na innych urządzeniach | Dlaczego |
|---|---|---|
| Zmiana hasła (`updatePassword`) | **Tak** | zmienia się hash hasła, trzeci segment przestaje pasować |
| Reset hasła (`PasswordResetController:138`) | **Tak** | dodatkowo jawnie rotuje `remember_token` |
| **„Wyloguj inne urządzenia"** (`logoutOtherSessions`) | **Nie** | kasuje wiersze w `sessions`, nie rusza `remember_token` ani hasła |
| **Włączenie 2FA** (`confirmTwoFactor`) | **Nie** | hasło bez zmian, więc recaller dalej pasuje |
| `ban` / `suspend` / `markForDeletion` | formalnie nie, w praktyce tak | recaller działa, ale `EnsureAccountIsActive` wylogowuje przy pierwszym żądaniu |

Dwa wiersze oznaczone **Nie** są treścią tego znaleziska.

**Scenariusz 1 — przycisk, który kłamie.** Osoba loguje się w bibliotece. W domu klika „Wyloguj inne urządzenia" **bez zmiany hasła** i czyta na ekranie „Wylogowaliśmy wszystkie inne urządzenia zalogowane na to konto" (`SecuritySettingsController:112`). Komputer w bibliotece przy kolejnym otwarciu kuking.pl jest dalej zalogowany. Komunikat jest nieprawdziwy, a to jest jedyna samoobsługowa droga, jaką ma osoba, która nie chce zmieniać hasła.

**Scenariusz 2 — 2FA, które nie obejmuje już zalogowanych.** Osoba włącza uwierzytelnianie dwuskładnikowe, wierząc, że od tej chwili wejście wymaga kodu. Urządzenie zalogowane wcześniej odzyskuje sesję z recallera **z pominięciem kodu** i robi to nawet przez ~400 dni (`TwoFactorChallengeController.php:106` daje `remember: false` tylko nowym logowaniom). Włączenie drugiego składnika nie wyrzuca nikogo, kto już jest w środku.

**Naprawa**
1. W `User::invalidateSessions()` rotować token: `$this->setRememberToken(Str::random(60)); $this->save();`, a bieżącej przeglądarce wystawić nowy recaller przez ponowne `Auth::login($user, remember: true)`. To załatwia oba scenariusze naraz i **nie wymaga hasła**, więc działa też przy włączaniu 2FA.
2. Alternatywa dla samego przycisku: `Auth::logoutOtherDevices($request->string('current_password'))` — metoda frameworka, która wymusza rehash hasła. Wymaga podania hasła, więc nie nadaje się do ścieżki 2FA.
3. Rozważyć `Auth::setRememberDuration()` na np. 30 dni (zgodnie z `SESSION_LIFETIME=43200` z `.railway/railway.ts`) zamiast domyślnych ~400.
4. **Test regresyjny musi objąć obie operacje osobno i użyć dwóch niezależnych klientów HTTP.** Odczyt kodu tego nie zastąpi: klient A loguje się i zachowuje ciasteczko recallera, klient B klika „Wyloguj inne urządzenia" (bez zmiany hasła), po czym żądanie klienta A z samym recallerem musi skończyć się jako gość. Drugi test to samo dla włączenia 2FA. Test na zmianę hasła też warto mieć, ale on przechodzi już dziś.

#### A-02 · Średnie · Publiczne formularze z hasłem poza trzykoszykowym limiterem, z różnicującym komunikatem

**Pliki**
- `app/Http/Controllers/AccountDeletionController.php:103` — `Hash::check` dla dowolnego konta z `findByLogin`; trasa `routes/web.php:421-423` (`throttle:5,60` po IP, Turnstile jest).
- `app/Http/Controllers/AppealController.php:130` (`guestStore`) — `Hash::check`; trasa `routes/web.php:535-537` (`throttle:5,60` po IP; **bez Turnstile**).
- `app/Domain/Users/Actions/CancelAccountDeletion.php:22-28` — sprawdzenie „czy `pending_delete`" **po** weryfikacji hasła, z innym komunikatem niż złe hasło. Analogicznie `AppealController.php:135-138`.

**Dlaczego problem.** `LoginController` ma trzy koszyki (para/konto/adres, `config/kuking.php:571-574`) właśnie dlatego, że limit po IP nie widzi ataku rozproszonego. Te dwa formularze wołają `Hash::check` na tym samym haśle, mają tylko limit po IP i przy poprawnym haśle mówią coś innego niż przy złym. To wyrocznia hasła bez koszyka per konto i bez 2FA (B-04 opisuje ten sam formularz od strony skutku).

**Scenariusz.** Z puli 100 adresów: 500 prób/h na jedno konto przez `POST /odwolanie`, bez śladu w `logowanie:konto:*`. Po trafieniu zwykłe logowanie (konto bez 2FA).

**Naprawa.** Najpierw stan konta, dopiero potem hasło; jeden komunikat dla „złe hasło" i „nie dotyczy"; `KluczeLimitow::konto()` i `KluczeLimitow::para()` + `RateLimiter::hit` przy porażce; `TurnstileJestPotwierdzony::reguly('odwolanie')`; wpis `AuditLogEntry` przy porażce; test na identyczność komunikatów.

#### A-03 · Średnie · Połączenie konta Facebooka bez hasła i bez rozłączenia

**Pliki:** `app/Http/Controllers/Auth/FacebookLoginController.php:276-311` (`dlaZalogowanego`), `:556-600` (`link()` — CSRF, `ZamekKonta`, brak `Hash::check`), `routes/web.php:482-520`; komunikat „napisz do nas… a potem wróć" (`:296-300`) zamiast trasy rozłączenia.

**Dlaczego problem.** Dodanie nowej, niezależnej od hasła drogi wejścia ma wagę wyłączenia 2FA lub zmiany e-maila — a te w repozytorium konsekwentnie wymagają hasła. Tu wystarcza otwarta sesja. Po powiązaniu ofiara nie zobaczy ani nie usunie obcego powiązania.

**Scenariusz.** Ktoś przy odblokowanym telefonie ofiary łączy własny Facebook. Od tej chwili wchodzi przyciskiem „Wejdź kontem Facebooka"; zmiana hasła i „wyloguj inne" tego nie odcinają (konta z 2FA są chronione przez `wpusc()`, `:509-514`).

**Naprawa.** `current_password` w koszyku `confirm_password` przed `link()`; lista powiązań z `tozsamosci_zewnetrzne` w ustawieniach + POST „Rozłącz" z hasłem i audytem; list „połączono konto Facebooka" jak `ZgloszonaZmianaAdresu`.

#### A-04 · Niskie · Enumeracja kont przez czas odpowiedzi

`app/Http/Controllers/Auth/LoginController.php:97` — `$user === null || ! Auth::validate(...)`: bcrypt wykonuje się tylko dla istniejącego loginu. To samo w `AccountDeletionController.php:103` i `AppealController.php:130`. Naprawa: przy braku konta `Hash::check($password, <stały prekomputowany hash>)`.

#### A-05 · Niskie · Stan „po haśle, przed kodem 2FA" bez terminu ważności

`LoginController.php:148-152`, `LoginLinkController.php:412-416`, `GoogleLoginController.php:600-603`, `FacebookLoginController.php:516-519` zapisują `logowanie.2fa.user_id`; `TwoFactorChallengeController.php:38-46,104-106` sprawdza tylko istnienie konta i `hasTwoFactorConfirmed()`, nie `STATUSY_ZAMKNIETEGO_KONTA`, a klucz żyje tak długo jak sesja. Naprawa: znacznik czasu w sesji (10 min) i powtórne sprawdzenie statusu przed `Auth::login`.

#### A-06 · Niskie · „Eksport wymaga potwierdzonego e-maila" — tylko w komentarzach

`routes/web.php:566-567`, docblock `RegisterController.php` vs `DataSettingsController.php:109-140` (`requestExport` bez `hasVerifiedEmail()`); middleware `verified` nie występuje w trasach. Dane nie wyciekają (pobranie: `auth` + `signed` + właściciel), ale dokumentacja i kod się rozjeżdżają. Naprawa: `abort_unless($user->hasVerifiedEmail(), 403)` z komunikatem po polsku albo poprawić komentarze.

#### A-07 · Niskie · Turnstile fail-open, bez `hostname`/`action`

`app/Turnstile/KlientTurnstile.php:76,140,163-167` — `Nierozstrzygniety` przy braku kluczy, błędzie sieci, HTTP ≠ 2xx; `TurnstileJestPotwierdzony::validate` wtedy przepuszcza. Świadoma decyzja (jest log + `/health` `degraded`). Zalecenie: porównywać `hostname` z `ZaufaneHosty::nazwy()`; rozważyć fail-closed na rejestracji przy serii `Nierozstrzygniety`.

#### A-08 · Informacyjne · Token Google bez weryfikacji podpisu JWT

`app/Google/KlientGoogle.php:199-240` sprawdza `iss`, `aud`, `exp`, `nonce`, `email_verified`, nie sprawdza podpisu RS256. Dla tokenu z bezpośredniej wymiany kodu po TLS z `client_secret` i PKCE jest to dopuszczalne (OIDC Core §3.1.3.7 pkt 6). Ostrzeżenie: `tozsamoscZTokenu()` nie może nigdy dostać tokenu z przeglądarki (One Tap).

#### A-09 · Informacyjne · `session.secure` bez wartości domyślnej

`config/session.php:174` — `env('SESSION_SECURE_COOKIE')` bez domyślnej. `.railway/railway.ts:217` ustawia `true`, ale z repozytorium nie da się tego potwierdzić. Zalecenie: `env('SESSION_SECURE_COOKIE', app()->isProduction())`.

### B. Autoryzacja, kontrola dostępu, moderacja

#### B-01 · Wysokie · Brak hierarchii ról w decyzji moderacyjnej

**Pliki**
- `app/Http/Controllers/Admin/ModerationController.php:139-380` (`decide()`), `:328` (`ModeratedContent::osoba($cel)`), `:568-585` (`applyAction()` → `suspend()`/`ban()`).
- `app/Domain/Moderation/Actions/ReportContent.php:104-160` — `authorize()` sprawdza tylko `view`/`viewProfile`.
- `app/Models/ModerationAction.php:77-90` — `DOZWOLONE['user']` zawiera `suspend`, `ban`.
- `app/Policies/UserPolicy.php` — `moderate()` = `isModerator()`; brak ability porównującej role.

**Co robi kod.** `decide()` sprawdza `authorize('moderate', User::class)` i wykonuje karę na `subject_user_id` bez sprawdzenia roli ukaranego, tożsamości z moderatorem ani tego, czy moderator jest autorem zgłoszenia.

**Scenariusz.** Moderator (złośliwy lub z przejętym kontem) zgłasza profil admina (`/zglos/{type}/{id}` z typem `user` i UUID konta przechodzi), w `/admin/zgloszenia` wybiera `action=ban` na własnym zgłoszeniu. `ban()` unieważnia sesje, `EnsureAccountIsActive` wylogowuje. Odwołania rozpatruje tylko admin (`UserPolicy::resolveAppeals`) — przy jedynym adminie projekt zostaje bez adminów aż do `kuking:nadaj-role` z konsoli.

**Naprawa.** Nowa ability `UserPolicy::punish(User $moderator, User $target)`: odmowa gdy `$target->isAdmin()` (chyba że działa admin), gdy `$target->is($moderator)`, gdy `$report->reporter_id === $moderator->getKey()`; moderator na moderatora — tylko admin. Testy: moderator nie banuje admina; admin zawiesza moderatora; nikt nie rozstrzyga własnego zgłoszenia.

#### B-02 · Średnie · Blokada użytkownika działa także na moderatora

`app/Policies/RecipePolicy.php:33`, `PostPolicy.php:40`, `CommentPolicy.php:52`, `CookedEventPolicy.php:21` — `hasBlockRelationWith()` zwraca `false` **przed** rozstrzygnięciem widoczności i niezależnie od `isModerator()`. `app/Domain/Social/Actions/BlockUser.php` nie ogranicza roli celu. Panel `resources/views/pages/admin/reports.blade.php:77,115-119` nie renderuje treści, więc moderator zablokowany przez autora dostaje 404 na `/wpisy/{post}` i decyduje w ciemno. Kontrast: `DostepDoZdjecia.php:153,200,253` — tu moderator blokadę omija.

**Naprawa.** W czterech Policy przenieść blokadę za gałąź moderatora (jak w `DostepDoZdjecia::wlascicielLubModerator`) albo zakazać blokowania kont `isModerator()` w `BlockUser`. Zapisać decyzję w `docs/DECISIONS.md`. Test: „moderator zablokowany przez autora otwiera zgłoszony wpis".

#### B-03 · Średnie · Wykonanie zbanowanego kucharza widoczne pod bezpośrednim adresem

`app/Policies/CookedEventPolicy.php:133` — sprawdza wyłącznie `STATUS_PENDING_DELETE`; `app/Models/CookedEvent.php` `scopeWidoczneDla` używa `dostepnyJakoAutor()` (wycina `banned` i `pending_delete`). Zdjęcia idą za Policy (`DostepDoZdjecia` → `cooked_event_media`), a `MediaController` daje im `Cache-Control: public`. Komentarz w Policy (`:112-117`) przyznaje, że decyzja produktowa jest otwarta. To ta sama klasa błędu co G01 z audytu GPT, tylko dla statusu `banned`.

**Naprawa.** W `view()` zamienić warunek na `! $event->user->jestDostepnyJakoAutor()`; moderator przechodzi wcześniejszą gałęzią. Test: zbanowany kucharz → `cooked.show` 404 dla gościa i obcego, 200 dla moderatora.

#### B-04 · Niskie · Odwołanie gościa omija 2FA

`app/Http/Controllers/AppealController.php:112-150` — przy poprawnym haśle i istniejącej decyzji można złożyć odwołanie w imieniu konta bez kodu 2FA i bez wpisu w `AuditLogEntry`. Wektor hasła opisany w A-02. Naprawa: wymagać kodu 2FA gdy `hasTwoFactorConfirmed()`, logować próby.

#### B-05 · Niskie · Niespójne `view` dla ukrytych treści

`PostPolicy.php:21-23` (ukryty wpis: tylko autor) vs `RecipePolicy.php:15-17` i `CommentPolicy.php:47,58-60` (autor lub moderator). Po `hide` moderator i admin rozpatrujący odwołanie nie widzą wpisu, o którym decydują (`admin.reports.restore`, `ResolveAppeal::cofnij()`). Naprawa: ujednolicić do wzoru z `RecipePolicy`.

#### B-06 · Informacyjne · Moderator widzi każde zdjęcie po UUID

`app/Domain/Media/DostepDoZdjecia.php:153,200,253` — `true` dla `isModerator()` przed Policy rodzica, także dla `visibility=private`. Uzasadnione moderacją zdjęć; zalecenie: wpis w `docs/DECISIONS.md` i `AuditLogEntry` przy dostępie do zdjęcia, którego rodzic jest dla moderatora niewidoczny (jak `admin.user_viewed`).

#### B-07 · Informacyjne · „Bez odpowiedzi" bez Policy `view`

`app/Http/Controllers/Admin/BezOdpowiedziController.php:95-97` (`odpowiedz()` — tylko `authorize('moderate')`), lista `:313-316` obejmuje `VISIBILITY_FOLLOWERS`. Zgodne z celem funkcji; odnotowane.

#### B-08 · Informacyjne · Edycja ukrytego komentarza

`app/Policies/CommentPolicy.php` `update()` nie sprawdza statusu: autor w 15 minut może zmienić treść komentarza ukrytego przez moderację (zmiana dowodu po decyzji). Naprawa: `&& $comment->status === Comment::STATUS_PUBLISHED`.

### C. Wejście, kodowanie wyjścia, media, SQL, CSRF, poczta

#### C-01 · Średnie · Formularz DSA wysyła list od Kuking na dowolny adres z treścią zgłaszającego

**Pliki**
- `app/Http/Controllers/ZgloszenieNielegalnejTresciController.php:109` — `target_url => ['required','string','max:2000']` (bez reguły `url`, bez ograniczenia do własnego hosta); `notifier_email` = `nullable|email:rfc`.
- `app/Domain/Moderation/Actions/ZglosNielegalnaTresc.php:140-141` — `Notification::route('mail', $zgloszenie->notifier_email)->notify(...)`.
- `app/Notifications/PotwierdzenieZgloszeniaNielegalnejTresci.php:48` — `->line((string) $this->zgloszenie->target_url)`; ten sam wzorzec w `DecyzjaWSprawieZgloszenia.php:74-75`.

**Co robi kod.** Anonimowy formularz przyjmuje dowolny e-mail i dowolny 2000-znakowy ciąg jako „adres strony", po czym wysyła na ten adres list od Kuking, w którym ciąg jest linią Markdown (`MailMessage::line`). HTML jest escapowany, ale składnia `[tekst](https://adres)` daje klikalny link (`allow_unsafe_links=false` blokuje tylko `javascript:` — WYWNIOSKOWANE).

**Scenariusz.** `notifier_email = ofiara@…`, `target_url = "[Twoje konto Kuking zostanie usunięte — potwierdź tutaj](https://zly-host.example/login)"`. Ofiara dostaje autentyczny, podpisany DKIM list z domeny Kuking z linkiem phishingowym. Turnstile i `throttle legal_notice = 3,60` utrudniają masówkę, nie celowany phishing na osoby 50+.

**Naprawa.** `['required','url:http,https','max:2000']` + reguła „host równy `parse_url(config('app.url'), PHP_URL_HOST)`" (kontroler i tak rozbiera ścieżkę, `:112-115`); w liście wypisywać wyłącznie znormalizowany adres własnej domeny lub renderować jako kod; rozważyć potwierdzenie adresu przed wysłaniem czegokolwiek poza numerem sprawy.

#### C-02 · Niskie · `source_url` z regułą `url` bez listy schematów

`app/Http/Controllers/RecipeController.php:540`, `resources/views/components/recipe-wizard.blade.php` (`validateAboutStep`), `resources/views/pages/recipes/show.blade.php:393` — `<a href="{{ $recipe->source_url }}">`. Reguła `url` bez parametrów dopuszcza m.in. `data:` i `javascript://%0A…`. CSP z nonce (`ApplySecurityHeaders`) blokuje `javascript:` w przeglądarkach z CSP. Naprawa: `url:http,https` w obu miejscach + test.

#### C-03 · Niskie · LIKE bez escapowania `%`, `_`, `\`

`app/Domain/Search/SearchQuery.php:177-182, 289-291` — `'%'.$needle.'%'` wprost; `normalize()` (`:351-354`) tylko obcina. `app/Http/Controllers/Admin/UzytkownicyController.php:277` ma `doLike()` — panel escapuje, publiczna wyszukiwarka nie. Nie jest to SQL injection (parametry wiązane), ale `q=%` dopasowuje wszystko w 4 gałęziach `UNION ALL` i wymusza `similarity` na całej tabeli z pominięciem indeksu trigramowego. Naprawa: wspólny helper (`addcslashes($needle, '%_\\')` + `ESCAPE '\'`) w `SearchQuery`, `TagSuggester.php:123` i panelu.

#### C-04 · Niskie · Zmiana zgody na digest przez GET, podpis bez daty (= D-06)

`routes/web.php:158-164` — `match(['get','post'])` dla `wypisz` i `wracam` z `signed`; `app/Domain/Digest/OdnosnikWypisania.php:48,53` — `URL::signedRoute` bez wygaśnięcia. Skanery poczty wykonują GET (wypisanie bez wiedzy), a `wracam` jest wieczny (przekazany list = ponowny zapis kiedykolwiek). Naprawa: GET → strona z przyciskiem POST; POST bez CSRF tylko dla `List-Unsubscribe-Post`; `wracam` przez `temporarySignedRoute` (7–30 dni).

#### C-05 · Niskie · Tymczasowe uploady Livewire bez kontroli typu

`config/livewire.php:156-163` — `rules => ['required','file','max:…']`, `preview_mimes` z `svg`, `mp4`, `wav`. Ostateczną barierą jest `RozpoznanieZdjecia` w `StoreUploadedImage` (magic bytes, biała lista MIME) — działa. Ale zarejestrowana trasa `livewire/preview-file/{filename}` mogłaby przez 5 min oddać SVG jako `image/svg+xml` z originu aplikacji (CSP i `nosniff` łagodzą). Naprawa: `mimetypes:` z `LimityZdjec::dozwoloneTypy()`, `preview_mimes => ['jpg','jpeg','png','webp']`.

#### C-06 · Informacyjne · Dekodowanie obrazu w żądaniu HTTP

`app/Domain/Media/PodgladOdRazu.php` (`ImageManager::gd()->read()` w request), `config/kuking.php:248-249` (`podglad.max_megapixels=25`), `docker/php.ini` `memory_limit=256M`. 25 Mpx w GD to ~125–200 MB; równoległe uploady kilku kont mogą wysycić workery. Ograniczenia: `throttle post=20,10`, 15 MB, `max_megapixels=50` z nagłówka przed dekodowaniem. Świadomy koszt; monitorować OOM, rozważyć 12–16 Mpx.

#### C-07 · Informacyjne · Prywatne zdjęcia przez 302 na podpisany URL R2

`app/Http/Controllers/MediaController.php` — Policy rodzica, potem `redirect()->away($dysk->temporaryUrl(...))` z `Cache-Control: private, no-store`. Podpisany adres jest przekazywalny przez `signed_url_minutes`. Standardowy kompromis; utrzymać krótki czas, oryginał nigdy w `warianty()` (nie znaleziono ścieżki, która by go dodawała).

#### C-08 · Informacyjne · Oryginały z EXIF poza GPS

`app/Domain/Media/UsunGps.php` zeruje tylko IFD GPS; oryginał z modelem aparatu i datą leży w prywatnym buckecie. Warianty publiczne przekodowane (EXIF znika). Zgodne z `docs/MEDIA_PIPELINE.md`.

### D. Prywatność, dane, kolejka, logi

#### D-01 · Wysokie · Payload digestu niesie pełne wiersze `users`

> **POTWIERDZONE WYKONANIEM (PR #582).** Sonda na rzeczywistych klasach
> `TrescDigestu`, `PodsumowanieTygodnia` i `SendQueuedMailable` wykonała
> `serialize(clone $job)` na dwóch niezapisanych modelach `User` z jawnie
> syntetycznymi atrybutami. W 5041 bajtach wyniku znalazły się **wszystkie
> cztery markery**: hash hasła i `remember_token` odbiorcy **oraz**
> obserwującego. `shouldBeEncrypted = false`. Nie wykonano pełnego
> `Queue::createPayload()`, zapisu do `jobs`, wysyłki ani odczytu produkcji —
> potwierdza to **zbędne powielenie atrybutów w serializowanym zadaniu**,
> a nie publiczny wyciek. Oznaczenie zmienione z WYWNIOSKOWANE na ZMIERZONE.

**Pliki**
- `app/Console/Commands/WyslijPodsumowaniaTygodnia.php:309` — `Mail::to($osoba->email)->queue($list)`.
- `app/Mail/PodsumowanieTygodnia.php:37-39` — `use SerializesModels; __construct(public TrescDigestu $tresc)`.
- `app/Domain/Digest/TrescDigestu.php:32-40` — zwykły obiekt z `public readonly User $odbiorca` i tablicami modeli (`ZbierzTresciDigestu.php:130-279`).
- `routes/console.php` — brak `queue:prune-failed`; `MartweZadania` jest ręczna.

**Co robi kod (WYWNIOSKOWANE).** `SerializesModels` redukuje do identyfikatorów tylko **bezpośrednie** właściwości Mailable będące modelem lub kolekcją. `$tresc` jest zwykłym obiektem, więc `serialize()` schodzi w głąb i zapisuje modele z **pełną tablicą atrybutów** (`$hidden` dotyczy tylko `toArray()`): `email`, `password`, `remember_token`, `two_factor_secret`, `two_factor_backup_codes` odbiorcy oraz atrybuty autorów wpisów i nowych obserwujących.

**Dlaczego problem.** Payload leży w `jobs` do wysłania, a po wyczerpaniu prób **bezterminowo** w `failed_jobs`. Zrzut bazy ujawnia hashe haseł i `remember_token` poza tabelą `users`, o której myśli się przy rotacji.

Sedno jest ostrzejsze, niż wygląda na pierwszy rzut oka: ciasteczko recallera ma postać `id|remember_token|hash(hasła)` (patrz A-01). Payload digestu zawiera **wszystkie trzy człony naraz** — identyfikator, token i hash hasła — czyli komplet potrzebny do **sfabrykowania ważnego ciasteczka „zapamiętaj mnie"** dla odbiorcy listu i dla każdego, kto go obserwuje. To już nie jest „wyciek hashy do złamania offline", tylko gotowy klucz do konta, ważny aż do zmiany hasła. Dodatkowo treść listu jest zamrożoną kopią sprzed rezerwacji.

**Scenariusz.** Awaria EmailLabs w dniu wysyłki → dziesiątki zadań w `failed_jobs` z pełnymi wierszami użytkowników, na zawsze.

**Weryfikacja (1 min):** `serialize(new PodsumowanieTygodnia($tresc))` i `grep password`.
**Naprawa.** Do Mailable przekazywać tylko identyfikatory i budować `TrescDigestu` w `content()` po stronie workera, albo dać `TrescDigestu` metody `__serialize`/`__unserialize` redukujące modele do kluczy; `Schedule` z `queue:prune-failed --hours=168`.

**Test regresyjny — uwaga na kształt asercji.** Sprawdzaj **brak konkretnych sekretów**: `password`, `remember_token`, `two_factor_secret`, `two_factor_backup_codes`. **Nie** asercję „payload nie zawiera znaku `@`”: adres odbiorcy jest prawidłowym i potrzebnym elementem zadania pocztowego, więc taki test albo od razu oblewa, albo zostanie obejściem. (Pierwsza wersja tego raportu proponowała właśnie `@` — poprawione po uwadze w PR #582.)

#### D-02 · Średnie · Tokeny jednorazowe jawnie w `jobs`/`failed_jobs`

`app/Notifications/LinkDoLogowania.php:47-53`, `ZaproszenieDoZalozeniaKonta.php:52-58`, `UstawienieNowegoHasla.php:32`, `UstawienieHaslaZamiastLinku.php:89`, `PotwierdzenieNowegoAdresu.php:32-45`; `app/Domain/Security/WyslijZaproszenieDoRejestracji.php:229` (`AnonymousNotifiable` z adresem osoby bez konta). W bazie leżą skróty (`token_hash` — poprawnie), ale w kolejce wartość jawna, a `failed_jobs` nie ma retencji (`MartweZadania.php:191` sam to przyznaje). Sprzeczne z obietnicą „najwyżej dobę" dla zaproszeń. Naprawa: `queue:prune-failed --hours=48` w harmonogramie; zaproszenia bez kolejki (`sync`) albo krótsza retencja jako jedyna realna bariera.

#### D-03 · Średnie · `weekly_digest_sends` bez retencji

`database/migrations/2026_09_10_400000_create_weekly_digest_sends_table.php:132-145` — jeden wiersz na osobę na tydzień; zero `delete` w `app/`, brak zadania w `routes/console.php`; `EraseAccountData.php` nie tyka tabeli. `resources/legal/polityka-prywatnosci.md:36` obiecuje „jedną, nadpisywaną wartość, a nie historię wysyłek". Naprawa: nocne kasowanie starszych niż 14 dni + `delete()` w wymazaniu, albo zmiana zdania w polityce; test retencji.

#### D-04 · Średnie · Wymazanie konta zostawia dane w tabelach pobocznych

`app/Domain/Users/Actions/EraseAccountData.php` dotyka: `users/profiles/follows/blocks/tag_follows/media`, `pending_email_changes:226`, `tozsamosci_zewnetrzne:244`, `data_exports:341-345`, `sessions:352-355`, treści przy `everything:569-580`. Pozostaje:
1. `notifications` innych osób z `username` (`FollowUser.php:114`) i `excerpt` komentarza (`PublishComment.php:197,211`) wymazanego — ukryte w UI (`Notification::scopeVisibleTo`), ale w bazie i w eksporcie innych (`CollectUserExportData::notifications`).
2. `contact_messages.user_id` (`PrzyjmijWiadomosc.php:62`) — polityka `:37` obiecuje „przestaje być z nim powiązana".
3. `mail_failures.user_id` — wiersze bez `zauwazony_at` nigdy nie kasowane (`ZapiszNieudanyList.php` `posprzataj()`).
4. `product_signals.user_id` — do 90 dni.
Naprawa: w transakcji wymazania `update(['user_id'=>null])` dla 2–4, `Notification::where('user_id',$id)->delete()`, czyszczenie `data->username/excerpt` gdzie `actor_id = $id`; rozszerzyć `WymazanieKontaUsuwaRelacjeI2FATest`.

#### D-05 · Średnie · Eksport danych niekompletny

`app/Domain/Users/Exports/CollectUserExportData.php:69-80` — brak: `dziennik_zgod`, `tozsamosci_zewnetrzne` (polityka §3 mówi, że to przechowujemy), `contact_messages` autora, `reports` i `appeals` użytkownika, `audit_log` dotyczący konta (typ + czas, bez `ip_hash`), `weekly_digest_sends`. Naprawa: sekcje `zgody`, `polaczone_konta`, `wiadomosci_do_nas`, `zgloszenia`, `dziennik_bezpieczenstwa` w `dane.json`; asercje w `DataExportTest`.

#### D-06 · Niskie · Trwały link „wracam" przez GET

Patrz C-04. Zgoda (art. 6.1.a) udzielana kliknięciem GET, które wykonują automaty; link nie wygasa i pozostaje ważny po zmianie decyzji.

#### D-07 · Niskie · Wyłączenie 2FA z konsoli bez audytu

`app/Console/Commands/WylaczDwuetapowaWeryfikacje.php:65` — `disableTwoFactor()` bez `AuditLogEntry` (dla porównania `NadajRole.php:108` loguje). Dopisać `security.2fa_disabled_by_operator`.

#### D-08 · Niskie · `unserialize()` bez `allowed_classes`

`app/Poczta/ZapiszNieudanyList.php:288` — `unserialize($polecenie)` na payloadzie z `jobs`. Źródło to własna baza, ale koszt ograniczenia klas jest zerowy.

#### D-09 · Informacyjne · Pusty `object_key`

Dodany w `dbd480c` (2026-09-12) jako pusty plik — artefakt przekierowania w shellu. Do usunięcia.

#### D-10 · Informacyjne · `.gitignore` bez zrzutów bazy

Brak `*.dump`, `*.dump.cms`, `*.sql`, `*.sql.gz`; `tests/skrypty/kopia-bazy.sh:303` produkuje `baza/kuking-…Z.dump.cms`. `scripts/kopia-lokalna.sh` odmawia zapisu w repo — bariera tylko dla tego skryptu.

#### D-11 · Informacyjne · `dziennik_zgod` bez zdania o retencji

Tabela append-only (triggery `dziennik_zgod_bez_zmian`, `…_bez_czyszczenia`, migracja `2026_09_10_400000_create_dziennik_zgod_table.php:182-215`) — słusznie jako dowód zgody, ale polityka nie mówi, że historia zgód jest bezterminowa. Jedno zdanie.

### E. Infrastruktura, konfiguracja, zależności

#### E-01 · Średnie · `/health` publiczny, bez limitu, zapisuje do R2 i ujawnia stan konfiguracji

**Pliki:** `routes/web.php:93` — `Route::get('/health', HealthController::class)` bez `throttle` i bez ograniczenia hosta; `app/Http/Controllers/HealthController.php:273-279` — odpowiedź zawiera `environment` i tablicę `checks` (m.in. `turnstile_bez_kluczy`, `google_bez_kluczy`, `facebook_bez_kluczy`, `analityka_bez_tokenu`, `poczta_nie_wysyla`, `zadania_nieudane`, `listy_przepadaja`, `limit_poczty_wyczerpany`); `:661-690` — każde wywołanie robi `put()` + `get()` na dysku `kuking.media.disk` (R2) i kilka zapytań do bazy; `:759-786` — przy awarii dzwoni na webhook (z odstępem 30 min).

**Dlaczego problem.** (a) Każdy w internecie dowiaduje się, które zabezpieczenie jest wyłączone (np. „Turnstile bez kluczy" = „boty przejdą rejestrację", „limit poczty wyczerpany" = „dziś nikt nie dostanie linku logowania"). (b) Bez limitu jedna pętla `curl` generuje operacje zapisu w R2 (koszt) i obciążenie bazy; `ZaufaneHosty::HEALTHCHECK_RAILWAY` pokazuje, że intencją było wywołanie przez Railway. Komunikaty są kodami z zamkniętego zbioru (`POWODY`), a `getMessage()` nie wychodzi — to zostało zrobione dobrze.

**Naprawa.** `throttle:health` (np. `12,1`) na trasie; szczegółowe `checks` tylko przy nagłówku `X-Health-Token` równym `KUKING_HEALTH_TOKEN` (lub dla hosta `healthcheck.railway.app`), publicznie sam `status`; zapis próbki do R2 nie częściej niż raz na minutę (cache).

#### E-02 · Niskie · CSP wysyłane dwukrotnie

`app/Http/Middleware/ApplySecurityHeaders.php` ustawia `Content-Security-Policy` i `Content-Security-Policy-Report-Only` z **identyczną** polityką. Każde naruszenie trafia do `/_csp` dwa razy (podwójne logi, szybsze zużycie `throttle csp_report=60,1`). Report-Only ma sens tylko dla polityki **ostrzejszej** niż wymuszana. Usunąć albo wpisać tam zaostrzenie, które ma być następne.

#### E-03 · Informacyjne · Zaufanie do proxy „wszyscy"

`docker/Caddyfile` — `trusted_proxies static 0.0.0.0/0 ::/0`; `bootstrap/app.php` — `trustProxies(at: '*')` z nagłówkami FOR/PORT/PROTO (bez HOST — dobrze) i `NormalizeForwardedFor` przed nim. Konstrukcja jest bezpieczna **dokładnie wtedy**, gdy jedynym wejściem do kontenera jest brzeg Railway, a `KUKING_ZAUFANE_PRZESKOKI` równa się rzeczywistej liczbie proxy. Jeśli przed Railway stanie Cloudflare (proxied), przeskoki trzeba przestawić na 2, inaczej limity per IP będą liczone po adresach Cloudflare. To udokumentowane w `config/proxy.php`; odnotowuję, bo konsekwencje pomyłki to A-02/B-04 w wersji bez żadnego limitu.

#### Zależności i sekrety — czysto

- `composer audit --locked`: brak znanych podatności w `composer.lock`.
- `npm audit` (z dev i bez): 0 podatności.
- Skan historii gita i drzewa pod kątem kluczy AWS/Slack/Discord/Google, kluczy prywatnych, `APP_KEY`, plików `.env`/`.sql`/`.pem`: brak prawdziwych sekretów. `docs/audyt-gpt-2026-09-dowody/` zawiera tylko sumy kontrolne.
- `Dockerfile`: wieloetapowy, `composer` usuwany z obrazu, zejście z roota przez `setpriv`, `APP_DEBUG=false` wypalone w `ENV`, `.dockerignore` wyklucza `.env*`, `tests`, `docs`, `.git`. `docker/php.ini`: `expose_php=Off`, `display_errors=Off`, `disable_functions` z `exec/system/proc_open`, `session.cookie_secure=1`. Caddy zdejmuje `Server` i `X-Powered-By`, blokuje pliki `.env*`, katalog `.git` i pliki Markdown.
- CI: akcje przypięte do wersji głównych (nie do SHA — do rozważenia), `permissions:` zadeklarowane, brak `pull_request_target`, sekrety tylko w `deploy.yml`/`preview.yml` na `workflow_dispatch`.

---

## 4. Co sprawdzono i jest solidne

To nie jest lista kurtuazyjna. Każdy punkt to miejsce, w którym szukano
błędu i go nie znaleziono; odniesienia pozwalają to powtórzyć.

**Konto i uwierzytelnianie**
- `User::$fillable` (`app/Models/User.php:200-207`) = `locale, text_scale, theme, wants_weekly_digest, age_confirmed_at, memories_enabled`. `status`, `role`, `email`, `password`, `email_verified_at`, `two_factor_*`, `data_erased_at`, `is_seeded` — tylko `forceFill` w nazwanych metodach. `role` zmienia wyłącznie `kuking:nadaj-role` (konsola, potwierdzenie, audyt, ochrona ostatniego admina). CHECK-i w bazie. Testy `WrazliweKolumnyPozaMasowymPrzypisaniemTest`, `PodniesienieRoliZadaniemHttpTest`.
- Logowanie hasłem: trzy koszyki + `throttle`, `Auth::validate` (2FA nieomijalne), odmowa dla kont zamkniętych, `session()->regenerate()` przed `login`, pełne wylogowanie.
- Link logowania: token 64 znaki, w bazie HMAC-SHA256 z `APP_KEY`, jeden na konto, `lockForUpdate`, GET nie zużywa / POST zużywa, odmowa dla moderatorów, identyczna odpowiedź dla adresu bez konta, dzienny budżet listów.
- Zaproszenia: adres z wiersza w bazie, zużycie w transakcji z założeniem konta.
- Rejestracja: `Password::min(10)->uncompromised()`, `ReservedUsername`, Turnstile, `registration_open`.
- Reset hasła: broker Laravela, rotacja `remember_token`, anulowanie oczekującej zmiany e-maila, linki przez `AdresKanoniczny` (host z `APP_URL`, nie z nagłówka).
- 2FA: sekret i kody `encrypted`, kody zapasowe bcrypt i jednorazowe pod `lockForUpdate`, ochrona przed powtórnym TOTP, limit po koncie i IP, wyłączenie i nowe kody za hasłem, QR lokalnie, `moderator.2fa` na `/admin/**`, żadna droga OAuth/link nie omija 2FA.
- Zmiana e-maila: hasło, list na nowy + informacyjny na stary, `signed`, blokada, sprawdzenie zajętości przy potwierdzeniu.
- OAuth Google: `state` + `nonce` + PKCE S256, `email_verified`, łączenie po e-mailu tylko z kontem o potwierdzonym adresie u nas (blokada pre-account-takeover), brak przechowywania tokenów dostawcy. Facebook: `state`, `appsecret_proof`, adres traktowany jako niepotwierdzony, deauthorize z HMAC + `hash_equals`.
- `EnsureAccountIsActive` globalnie; zawieszony tylko czyta, z jawną listą wyjątków RODO/DSA.

**Autoryzacja**
- Każda trasa z `{post}`, `{recipe}`, `{comment}`, `{cookedEvent}`, `{collection}` → `authorize(...)`; `{notification}`, `{export}`, `{zmiana}` zawężone do `request()->user()`; `{media}` → `whereUuid` + biała lista wariantów + Policy rodzica; cache `public` tylko gdy anonim też ma dostęp.
- `media_ids`/`steps.*.mediaId`/`hero_media_id` zawsze przez `ZdjeciaDoPrzypiecia::zablokuj(owner_id, …)` — podstawienie cudzego UUID kończy się `null`.
- Jedyny komponent Livewire (`recipe-wizard`): `#[Locked]` na `recipeId/heroMediaId/sourceScanMediaId/juzOpublikowany`, `Gate::authorize('update')` w `mount()` i `existingRecipe()`.
- `scopeWidoczneDla` konsekwentnie wycina blokady i statusy ukrywające; feed, odkrywanie, tagi, szukaj, sitemap, tablica dnia, kolaż — bez wycieków prywatnych i `followers`.
- Pełna tabela pokrycia tras w raporcie cząstkowym B (zachowana w §6).

**Wejście i wyjście**
- Jedyne `{!! !!}` w Blade: stała mapa ikon SVG, `JsonLd::encode` z `JSON_HEX_*`, Markdown z `resources/legal/*.md` (`html_input=escape`, `allow_unsafe_links=false`), QR z BaconQrCode, deklaracja XML sitemapy. Brak `@html`, `Js::from`, `x-html`, interpolacji w `x-data`/`onclick`. Szablony poczty wyłącznie `{{ }}`.
- CSP z nonce bez `unsafe-inline`, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`; `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS w produkcji.
- CSRF: wyjątki tylko `_csp`, `podsumowanie/wypisz/*` (RFC 8058), deauthorize FB — każdy chroniony inaczej.
- SQL: wszystkie `whereRaw/selectRaw/orderByRaw/DB::select` z wiązanymi parametrami; interpolowane fragmenty ze stałych; sortowanie w panelu z białej listy.
- Walidacja: limity długości, `in:` dla enumów, tablice ograniczone (`MAX_INGREDIENTS`, `MAX_STEPS`, `photos max:6`, tagi), `klucz_wyslania` = UUID.
- Media: magic bytes przez `getimagesize`, biała lista MIME, rozszerzenie z wykrytego MIME, klucz = UUID (brak path traversal), `max_megapixels` przed dekodowaniem, osobny dysk wariantów.
- Open redirect: `NotificationController::wlasnyAdres` tylko ścieżki względne i własny host. SSRF: wszystkie `Http::` na adresy z konfiguracji.
- Limity: każdy formularz zapisujący ma `throttle` z osobnym koszykiem w `config/kuking.php['limits']`; 429 logowane bez PII, formularz odzyskiwany z filtrem pól wrażliwych (`OdzyskiwalneDane`).

**Dane i prywatność**
- Anonimizacja rdzenia konta (`EraseAccountData.php:270-292,319`), idempotentne kasowanie zdjęć z obu dysków + purge CDN, egzekucja karencji nocą.
- Eksport: prywatny bucket, `signed` + właściciel, TTL 7 dni, jeden aktywny na konto (unikalny indeks), `failure_reason` jako kod, zadanie niesie tylko `dataExportId`.
- Retencja zgodna z polityką dla `notifications`, `audit_log`, spraw moderacyjnych, `contact_messages`, `product_signals`, `pending_email_changes`, `registration_invites`; testy `Retencja*Test`.
- Logi: brak e-maili, IP, tokenów; adresy maskowane; IP tylko jako HMAC; webhook błędów bez komunikatu wyjątku i bez argumentów śladu.
- Analityka: brak publicznego endpointu na sygnały; CHECK blokuje `query_text`; Cloudflare bez cookie.
- Zgody: tabela append-only z triggerami, domyślna zgoda `false` + `BramkaDomyslnejZgody`, `List-Unsubscribe-Post`.
- Kolejka: zadania w `app/Jobs` niosą tylko identyfikatory (wyjątek: D-01/D-02 dotyczą Mailable/Notification), dispatch po commit, bariera `UNIQUE(user_id, week_start)` przed `Mail::queue`.
- Baza: unikalne `lower(email)`/`lower(username)`, CHECK-i, FK z jawnym `onDelete`, brak `truncate()` w `app/`, seedery odmawiają na `production`.
- **G04 i G05 z audytu GPT: naprawione**, z testami `AwariaPowiadomieniaNieRozdzielaWykonaniaTest` i `WymazanieKontaUsuwaRelacjeI2FATest`.

---

## 5. Uwagi do poprzednich audytów

| Ustalenie GPT | Stan 15.09.2026 |
|---|---|
| G01 (wykonanie w karencji publiczne pod adresem) | Naprawione dla `pending_delete`; **ta sama klasa dla `banned` otwarta — B-03** |
| G04 (transakcja Ugotowałem + powiadomienie) | Naprawione, test istnieje |
| G05 (wymazanie relacji i 2FA) | Naprawione, test istnieje; **tabele poboczne nadal otwarte — D-04** |
| G12 (akcja domenowa omija Policy dla prywatnego przepisu) | Nie badane ponownie w tym audycie |
| G02, G03, G09 (bramki właścicielskie) | Poza zasięgiem odczytu repozytorium — patrz „Ograniczenia" |

---

## 6. Pokrycie tras (autoryzacja)

| Trasa | Middleware | Ability / zawężenie | Wynik |
|---|---|---|---|
| `GET /`, `/odkryj`, `/szukaj` | web (+throttle) | `publiclyVisible`/`widoczneDla` | OK (C-03) |
| `GET /health` | web | — | **E-01** |
| `GET /sitemap.xml`, `/robots.txt` | web | `publiclyVisible` + status autora | OK |
| `GET/POST /napisz-do-nas*` | throttle + Turnstile | publiczne, celowo | OK |
| `GET/POST /podsumowanie/wypisz/{user}` i `GET/POST /podsumowanie/wracam/{user}` | signed + throttle | podpis | C-04 / D-06 |
| `POST /motyw` | throttle | własny user / cookie | OK |
| `GET /przepisy/{recipe}` | web | `RecipePolicy::view` | OK (B-02) |
| `GET/POST /przepisy/{recipe}/gotuj` | throttle | `RecipePolicy::view` | OK |
| `GET /wpisy/{post}` | web | `PostPolicy::view` | OK (B-02, B-05) |
| `GET /ugotowane/{cookedEvent}` | web | `CookedEventPolicy::view` | **B-03** |
| `GET /zdjecia/{media}/{wariant}` | whereUuid + whereIn + throttle | `DostepDoZdjecia` → Policy rodzica | OK (B-06) |
| `GET /@{username}`, `/@{username}/obserwujacy`, `/@{username}/obserwowani` | web | `UserPolicy::viewProfile` + blokady | OK |
| grupa `guest` (register/login/reset/link/google/kod) | guest + throttle | — | OK (A-04, A-05) |
| `POST /cofnij-usuniecie-konta` | guest + throttle + Turnstile | hasło | **A-02** |
| `GET/POST /odwolanie` (gość) | throttle | login + hasło | **A-02 / B-04** |
| `/wejdz/facebook/*` | throttle / signed_request | — | A-03 |
| `GET/POST /zgloszenie/{report}/odwolanie` | signed + throttle | podpis + `jestZgloszeniemPrawnym` | OK |
| `GET /home`, `/witaj/zainteresowania`, `/witaj/ludzie`, `/witaj/gotowe` | auth | `widoczneDla`, `FollowUser` | OK |
| `/potwierdz-email/*` | auth (+signed) | własny user | OK |
| `GET /dodaj`, `POST /dodaj/zdjecie` | auth + throttle | `media_ids` po `owner_id` | OK |
| `POST /wpisy/{post}/komentarz` | auth | `PostPolicy::comment` | OK |
| `GET/PUT/DELETE /wpisy/{post}*` | auth | `PostPolicy::update/delete` | OK |
| `POST /wspomnienia/{post}/ukryj` | auth | `PostPolicy::update` | OK |
| `PUT/DELETE /komentarze/{comment}` | auth | `CommentPolicy::update/delete` | OK (B-08) |
| `GET/POST /dodaj/przepis*`, `PUT/DELETE /przepisy/{recipe}` | auth | `RecipePolicy::update/delete` | OK |
| `POST /przepisy/{recipe}/komentarz` | auth | `RecipePolicy::view` | OK |
| `GET/POST /przepisy/{recipe}/ugotowalem` | auth | `RecipePolicy::cook` | OK |
| `POST/DELETE /ugotowane/{id}/*` | auth | `CookedEventPolicy::view/delete/celebrate` | OK |
| `GET/POST/DELETE /zeszyt*` | auth | `CollectionPolicy`, własne | OK |
| `POST/DELETE /przepisy/{recipe}/zapisz`, `/wpisy/{post}/zapisz` | auth | `view` celu + `collection_id` własny | OK |
| `POST/DELETE /tag/{tag}/obserwuj` | auth | status tagu | OK |
| `POST/DELETE /@{u}/obserwuj`, `/@{username}/blokuj` | auth | `UserPolicy::follow`, `BlockUser` | OK (B-02) |
| `GET /powiadomienia*` | auth | własne | OK |
| `/ustawienia/**` | auth + throttle | własny user / `ProfilePolicy::update` | OK (A-06) |
| `GET /ustawienia/twoje-dane/pobierz/{export}` | auth + signed | `user_id === user` | OK |
| `GET/POST /ustawienia/e-mail/*` | auth (+signed) | `where user_id` | OK |
| `GET/POST /odwolanie/{action}` | auth | `subject_user_id === user` | OK |
| `GET/POST /zglos/{type}/{id}` | auth | `ReportContent::authorize` | OK (B-01: brak zakazu zgłaszania admina/siebie) |
| `GET /zgloszenia*` | auth | `reporter_id`, `ReportPolicy::view` | OK |
| `GET/POST /zglos-nielegalna-tresc*` | throttle + Turnstile | publiczne (DSA art. 16) | **C-01** |
| `GET /admin/zgloszenia`, `POST .../{report}`, `.../przywroc` | auth + moderator + 2fa | `moderate` | **B-01** |
| `/admin/sygnaly`, `/admin/wiadomosci*`, `/admin/odwolania*` | auth + moderator + 2fa | `moderate` / `ContactMessagePolicy` / `resolveAppeals` | OK |
| `GET/POST /admin/bez-odpowiedzi*` | auth + moderator + 2fa | `moderate` | OK (B-07) |
| `/admin/kolaz-powitalny`, `/admin/kuking-na-dzis`, `/admin/tagi-promowane*`, `/admin/uzytkownicy*` | auth + moderator + 2fa | `moderate` (+ audyt `admin.user_viewed`) | OK |
| `GET /tagi`, `/tag/{tag}` | web | `publiclyVisible`, ukryte tagi → 404 | OK |
| `POST /_csp` | throttle, bez CSRF | nic nie zapisuje | OK (E-02) |
| Livewire `/livewire/update` (recipe-wizard) | web | `Gate::authorize('update')`, `#[Locked]` | OK (C-05) |

---

## 7. Zasady, których ten audyt się trzymał

- Znalezisko bez ścieżki w kodzie nie weszło do tabeli.
- Tam, gdzie zachowanie zależy od frameworka, którego nie dało się tu
  załadować, stoi WYWNIOSKOWANE i jednolinijkowy sposób sprawdzenia.
- Trzy znaleziska pokrywają się parami (A-02/B-04, C-04/D-06) — zostawione
  jako osobne pozycje, bo wskazują różne skutki tego samego kodu, ale
  liczone jako jedna poprawka w planie.
- Zgodnie z `AGENTS.md`: każda poprawka bezpieczeństwa = test regresyjny;
  zmiany schematu (D-03, D-04) = migracja + `docs/DATABASE.md` + rollback.
