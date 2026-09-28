# Audyt A5 — bezpieczeństwo i prywatność (25 września 2026)

Stan: `origin/main` @ `a1d9943c`. Audyt tylko do odczytu: bez połączeń z produkcją, bez zmian w kodzie aplikacji.
Punkt odniesienia: `docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md` (identyfikatory A-xx … E-xx w tabeli statusów niżej).

## Znaleziska krytyczne

**Brak.** Nie znaleziono IDOR-u, obejścia Policy, mass assignment pola sterującego, serwowania oryginału z EXIF/GPS ani sekretu w repozytorium.

## Znaleziska

Pewność: **T**: potwierdzone tymczasowym testem (usuniętym po uruchomieniu), **K**: czytanie kodu, **W**: wywnioskowane (zachowanie zależne od środowiska, którego nie uruchamiano).

| # | Tytuł | Waga | Plik:linia | Scenariusz nadużycia | Proponowana poprawka | Pewność | Issue/PR |
|---|---|---|---|---|---|---|---|
| A5-01 | Caddy nadpisuje `Referrer-Policy: no-referrer` ustawiane przez Laravel na stronach z sekretem w adresie | średnia | `docker/Caddyfile:65`; `app/Http/Middleware/ApplySecurityHeaders.php:129-132` | Blok `header` zawiera usunięcia (`-Server`), więc Caddy odracza operacje do chwili zapisu odpowiedzi, a jego `set` wygrywa z Laravelem. Osoba otwiera `/nowe-haslo/{token}?email=…`, `/logowanie/link/{token}` albo `/zaproszenie/{token}` i wysyła formularz. Następna strona, już z beaconem Cloudflare, ma w `document.referrer` pełną ścieżkę z tokenem i e-mailem. Tę drogę miał zamknąć #1052. | W Caddy `?Referrer-Policy "strict-origin-when-cross-origin"` (ustaw tylko, gdy brak) albo usunąć tę linię. Dopisać Referrer-Policy do `CaddySpojnyZNaglowkamiLaravelaTest`. | K/W (semantyka odraczania z dokumentacji Caddy, bez uruchomienia) | regresja zamkniętego #1052; brak otwartego |
| A5-02 | Pełne komunikaty wyjątków (SQL z wartościami: e-mail, hash hasła) trafiają do stderr, czyli do logów Railway | średnia | `bootstrap/app.php:539-557` (callback `report()` nie zatrzymuje domyślnego logowania); `app/Jobs/GenerateUserExport.php:216-224`; `app/Domain/Compliance/PrzedawnioneSprawyModeracyjne.php:331-334`; `app/Http/Controllers/HealthController.php:970-975`; `report(... previous:)` w `app/Domain/Users/Actions/ZalozKonto.php:232,293`, `app/Models/AuditLogEntry.php:159` | `QueryException` przy unikalności `users` niesie `Key (email)=(…)` oraz wartości INSERT. Webhook błędów jest odfiltrowany, ale `LOG_CHANNEL=stderr` z `JsonFormatter` zapisuje komunikat i łańcuch `previous` u zewnętrznego dostawcy. Łamie AGENTS.md §7 („PII w logach”). | Procesor Monologa na kanałach `stderr`/`stack`, który zamienia komunikat `QueryException` (także w `previous`) na SQLSTATE plus nazwę constraintu. W jobach logować `BezpiecznyKomunikat::z()` zamiast `getMessage()`. | K | #973 (częściowo), #1357 (argumenty śladu) |
| A5-03 | Formularz DSA wysyła list od Kuking na dowolny adres z treścią atakującego w Markdown (C-01 wciąż otwarte) | średnia | `app/Http/Controllers/ZgloszenieNielegalnejTresciController.php:110-111`; `app/Notifications/PotwierdzenieZgloszeniaNielegalnejTresci.php:48`; `app/Notifications/DecyzjaWSprawieZgloszenia.php:75` | `notifier_email=ofiara@…`, `target_url="[Potwierdź konto](https://zly.example)"`. Ofiara dostaje list podpisany DKIM Kuking z klikalnym linkiem phishingowym. Limit: 3 zgłoszenia na godzinę na IP. | `target_url`: `url:http,https` plus host równy hostowi `app.url`. W liście wypisywać znormalizowaną ścieżkę w bloku kodu (albo wcale). | K | #1636 |
| A5-04 | Eksport RODO zawiera komentarze od zablokowanych, zbanowanych i odchodzących kont | średnia | `app/Domain/Users/Exports/CollectUserExportData.php:518-530` (użycia 248, 284, 312; relacje 204, 258, 294) | Pod wpisem Basi komentują: osoba przez nią zablokowana, osoba zbanowana i osoba w karencji usunięcia. Na stronie wpisu Basia nie widzi żadnego z tych komentarzy, w paczce dostaje wszystkie trzy z nazwą i datą. Łamie obietnicę „komentarze znikną od razu” dla osoby odchodzącej. | Ograniczyć `comments` i `comments.replies` przez `widoczneDla($user)`, jak w `PostController:655-658` i w `notifications()` tej samej klasy. Dopisać test w `DataExportTest`. | T | #1245 |
| A5-05 | `/health` publiczny i bez limitu: przy każdym wywołaniu zapisuje do R2, pyta bazę i ujawnia stan zabezpieczeń (E-01 wciąż otwarte) | średnia | `routes/web.php:104`; `app/Http/Controllers/HealthController.php:300-330,829-851` | Pętla `curl` generuje zapisy i odczyty w R2 oraz zapytania do bazy. Odpowiedź zdradza np. `turnstile_bez_kluczy` i `limit_poczty_wyczerpany`, czyli moment, w którym warto atakować formularze. | `throttle` z limitem w `config/kuking.php`; szczegóły `checks` tylko z tokenem; próbka R2 z cache (około minuty). | K | brak |
| A5-06 | Moderator może przywrócić własną treść ukrytą przez innego moderatora (albo treść osoby o wyższej randze) | średnia | `app/Http/Controllers/Admin/ModerationController.php:542-573`; `app/Domain/Moderation/Actions/RestoreContent.php:44-160` | Moderator X publikuje przepis, Y wydaje decyzję `hide`, X wysyła `POST /admin/zgloszenia/{report}/przywroc` i przepis wraca do `published`. Omija w ten sposób odwołanie, w którym rozstrzyga administrator. `ReportPolicy::decide` (`:82-91`) z odmowami `WLASNE_ZGLOSZENIE` i `SPRAWA_O_CIEBIE` nie jest tu sprawdzane. | Bramka `Gate::inspect('decide', $report)` i reguła rangi w `RestoreContent::handle()` (wołają go też `ResolveAppeal::cofnij()` i komendy). Test regresyjny. | T | #1479 |
| A5-07 | Wykonanie zbanowanego kucharza jest publiczne pod bezpośrednim adresem (B-03 wciąż otwarte) | średnia | `app/Policies/CookedEventPolicy.php:116-133` (sprawdza tylko `pending_delete`) | Gość otwiera `/ugotowane/{uuid}` osoby zbanowanej: 200 z notatką, nazwą i awatarem, a zdjęcia idą z `Cache-Control: public`. Profil tej osoby daje mu 403. | `if (! $event->user->jestDostepnyJakoAutor()) return false;` (moderator przechodzi wcześniejszą gałęzią); zaktualizować `KomusWyszloWidocznoscTest`; decyzja w `DECISIONS.md` (dziś brak). | T | brak (pokrewne #1394) |
| A5-08 | Kreator przepisu (Livewire) na produkcji najpewniej odrzuca każde zdjęcie | średnia (funkcjonalna, nie luka) | `app/Domain/Media/Actions/StoreUploadedImage.php:95,107,211`; `resources/views/components/recipe-wizard.blade.php:606-607,648-649`; `.railway/railway.ts:325` | `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`, a `TemporaryUploadedFile::getRealPath()` zwraca `storage->path()`, czyli dla dysku R2 ścieżkę względną `livewire-tmp/…`. Wtedy `getimagesize()` i `exif_read_data()` nie znajdują pliku i użytkownik dostaje „Ten plik nie wygląda na zdjęcie”. Testy tego nie widzą, bo Livewire wymusza w nich lokalny dysk. Formularz bez JS działa. | Przed `StoreUploadedImage` skopiować plik lokalnie (`readStream` do pliku tymczasowego) i przekazać lokalną ścieżkę. Test z dyskiem nielokalnym. **Najpierw potwierdzić na stagingu.** | K + tinker (`path()` dysku r2); bez próby na produkcji | brak |
| A5-09 | Uploady tymczasowe Livewire: dowolny typ pliku, surowe pliki z GPS w `livewire-tmp/` przez ≥ 24 h (C-05 wciąż otwarte) | niska | `config/livewire.php:156` (brak `mimetypes:`), `:158` (domyślny `throttle:60,1`), `:159-163` (`preview_mimes` z `svg`, `mp4`, `wav`) | Zalogowana osoba wgrywa 60 plików po 15 MB na minutę, dowolnego typu, do bucketu oryginałów. Sprzątanie działa tylko przy następnym uploadzie i za każdym razem listuje cały prefiks R2. Łagodzi to: podgląd nie jest używany i idzie jako załącznik. | `mimetypes:` z `LimityZdjec::dozwoloneTypy()`, `preview_mimes` tylko jpg/jpeg/png/webp, ciaśniejszy throttle, reguła lifecycle R2 (1 dzień) dla `livewire-tmp/`. | K | brak |
| A5-10 | Tokeny jednorazowe jawnie w `jobs`/`failed_jobs`, bez retencji `failed_jobs` (D-02 wciąż otwarte) | niska | `app/Notifications/LinkDoLogowania.php:52-53`; `app/Notifications/ZaproszenieDoZalozeniaKonta.php:57-58`; `app/Notifications/UstawienieNowegoHasla.php:56`; brak `queue:prune-failed` w `routes/console.php` | Zrzut bazy albo odczyt `failed_jobs` daje jawny token zaproszenia lub resetu. Skutek ogranicza wygasanie tokenów. | `ShouldBeEncrypted` na tych trzech powiadomieniach; `queue:prune-failed --hours=48` w harmonogramie. | K | brak |
| A5-11 | Moderator może zamknąć automatyczne oznaczenia własnych treści | niska | `app/Http/Controllers/Admin/SygnalyController.php:96-110` | `POST /admin/sygnaly/odrzuc` z `autor=<własne UUID>` zamyka całą grupę oznaczeń `source=automat` tego autora, zanim zobaczy je ktoś inny. | Odmówić przy `$autorId === $moderator->getKey()`; rozważyć regułę rangi jak w `takeDownContentOf`. | K | brak |
| A5-12 | Licznik „N zapisów nie jest dla Ciebie dostępnych” w cudzym publicznym zeszycie | niska | `app/Http/Controllers/CollectionController.php:316`; `resources/views/pages/collections/show.blade.php:87-99` | Obca osoba na `/zeszyt/{uuid}` widzi „1 zapis nie jest dla Ciebie dostępny”, czyli istnienie prywatnej, usuniętej albo zablokowanej pozycji. | Liczyć i pokazywać tylko właścicielowi zeszytu. | T | #1297 |
| A5-13 | Moderator nie widzi ukrytego wpisu, choć widzi ukryty przepis (B-05) | niska | `app/Policies/PostPolicy.php:24-27` wobec `app/Policies/RecipePolicy.php:15-17` | Moderator rozpatruje przywrócenie albo odwołanie i dostaje 404 na `/wpisy/{uuid}`, więc decyduje w ciemno. | Ujednolicić z `RecipePolicy` (autor albo moderator) i dopisać test. | T | #1018 |
| A5-14 | Moderator otwiera każde zdjęcie po UUID, także z treści prywatnych, bez śladu w audycie (B-06) | niska | `app/Domain/Media/DostepDoZdjecia.php:253-256` | Moderator odczytuje skan rodzinnego przepisu ze szkicu i nie zostaje po tym ślad. | Decyzja w `DECISIONS.md` i `AuditLogEntry`, gdy rodzic zdjęcia jest dla moderatora niewidoczny. | K | #1360 (pokrewne #1359) |
| A5-15 | Livewire `update` bez limitu: zapis i publikacja w kreatorze omijają limiter `post` | niska | trasa `livewire-*/update`; `resources/views/components/recipe-wizard.blade.php:401-540` | Zalogowana osoba skryptem woła `saveDraft`/`publish` setki razy na minutę: zapisy do bazy i analiza treści bez limitu. | `RateLimiter::attempt` z kluczem z `config/kuking.php` w `persist()` albo throttle na trasie update. | K | brak |
| A5-16 | Caddy bez `request_body max_size` | niska | `docker/Caddyfile` (brak dyrektywy); `docker/php.ini:70,84,85` (`post_max_size=112M`) | Anonim wysyła równolegle POST-y po 112 MB na dowolną trasę (np. `/login`). PHP buforuje je do `/tmp`, zanim zadziała CSRF i throttle. | `request_body { max_size 112MB }` globalnie, niższy limit poza trasami z uploadem. | K | brak |
| A5-17 | Porządek w repo: `DB_PASSWORD` wypełnione w `.env.example`, pusty `object_key` śledzony, `.gitignore` bez wzorców zrzutów (D-09, D-10) | niska | `.env.example:37` (lokalna wartość deweloperska, nie produkcyjna); `object_key`; `.gitignore` | `git add .` po zrzucie bazy do katalogu repo wciąga zrzut. | `*.dump`, `*.sql`, `*.dump.cms` w `.gitignore`; usunąć `object_key`; puste `DB_PASSWORD`. | K | brak |
| A5-18 | Rejestr wyjątków w `WrazliweKolumnyPozaMasowymPrzypisaniemTest` nazywa nieistniejące symbole | niska (dokumentacja) | `tests/Feature/WrazliweKolumnyPozaMasowymPrzypisaniemTest.php:209,224,281` | `AddComment` (jest `PublishComment`), `STATUS_NEW` (jest `Report::STATUS_OPEN`), `decided_by` przypisane do `AppealController` (faktycznie `ResolveAppeal.php:76`). Uzasadnienia są merytorycznie prawdziwe, nazwy mylą kolejny audyt. | Poprawić nazwy. | K | brak |

Uwaga bez wagi: administrator może rozpatrzyć odwołanie od decyzji dotyczącej własnej treści (`ResolveAppeal` sprawdza tylko karencję własnej decyzji, `:163`). To najwyższa rola, ale nikt nie zapisał tego jako świadomej zgody. Warto dopisać decyzję.

## Status znalezisk z audytu 2026-09-15

| ID | Status | Dowód / uwaga |
|---|---|---|
| A-01 | naprawione | `User::invalidateSessions()` rotuje `remember_token` (`app/Models/User.php:1448-1453`); włączenie 2FA je woła (`TwoFactorSettingsController.php:148`). Issue #930 jest wciąż otwarte, do weryfikacji i zamknięcia. |
| A-02 | częściowo | trzy koszyki `LimitProbHasla` są w obu formularzach; odwołanie gościa bez Turnstile i z różnicującym komunikatem (`AppealController.php:167`) |
| A-03 | otwarte | `FacebookLoginController::link()` (`:490`) bez hasła; brak „Rozłącz” |
| A-04 | otwarte | `LoginController.php:100`: brak hasha-atrapy |
| A-05 | częściowo | odcisk stanu konta sprawdzany powtórnie (`TwoFactorAuthenticator.php:111`); brak terminu ważności |
| A-09 | otwarte | `config/session.php:174` bez wartości domyślnej; Railway ustawia `true` |
| B-01 | naprawione | `UserPolicy::sanctionAccount` z rangą; luka pokrewna przy przywracaniu to A5-06 |
| B-02 | otwarte | blokada działa przed gałęzią moderatora (`PostPolicy.php:44`, `CookedEventPolicy.php:21`) |
| B-03 | otwarte | A5-07 |
| B-04 | otwarte | `AppealController::guestStore` bez 2FA |
| B-05 | otwarte | A5-13 |
| B-06 | otwarte | A5-14 |
| B-07 | otwarte (zgodne z celem) | `BezOdpowiedziController.php:111` |
| B-08 | naprawione | `CommentPolicy.php:93-94` |
| C-01 | otwarte | A5-03 |
| C-03 | naprawione | `uciecznijLike` w wyszukiwarce |
| C-04 / D-06 | częściowo | `wracam` przez GET tylko pyta, zapis przez POST; `wypisz` przez GET zmienia stan, podpis bez daty (`OdnosnikWypisania.php:48,53`) |
| C-05 | otwarte | A5-09 |
| C-06, C-07, C-08 | otwarte (świadome) | podpis prywatny ucięty do 5 min (`MediaController.php:147`); surowe pliki `livewire-tmp/` mają pełny GPS (A5-09) |
| D-01 | naprawione | `TrescDigestu::__serialize()` zostawia identyfikatory |
| D-02 | otwarte | A5-10 |
| D-08 | otwarte | `app/Poczta/ZapiszNieudanyList.php:288` `unserialize()` bez `allowed_classes` |
| D-09, D-10 | otwarte | A5-17 |
| E-01 | otwarte | A5-05 |
| E-02 | otwarte (opisane jako zamierzone) | `ApplySecurityHeaders.php:261-277` |
| E-03 | bez zmian | `trusted_proxies 0.0.0.0/0` w Caddy, `trustProxies(at:'*')`; bezpieczne tylko przy wejściu wyłącznie przez brzeg Railway (#1306) |

## Co sprawdzono i jest poprawne

- **Autoryzacja tras.** `KazdaTrasaZIdentyfikatoremPodPolicyTest` i `WrazliweKolumnyPozaMasowymPrzypisaniemTest` przechodzą (15 testów, 927 asercji). Test obejmuje każdą trasę z `{`, sprawdzaną dla pięciu ról. Wyłączenia są zasadne:
  - tokeny jednorazowe;
  - `storage.local*` z osobnym testem podpisu;
  - `livewire.preview-file`;
  - trasy „unsave/unfollow/block”, zakresem przypięte do `$request->user()`.
- **Panel moderacji.** Wszystkie 26 tras `admin/*` stoją za `auth`, `EnsureUserIsModerator` i `EnsureModeratorHasTwoFactor`. Kontrolery dodatkowo wołają `authorize('moderate')`.
- **Identyfikatory w treści żądania.** `media_ids`, `collection_id`, `parent_id` komentarza i kolejność zdjęć są ograniczone do właściciela albo do widocznej treści.
- **Jedyny komponent Livewire (kreator).** Identyfikatory są `#[Locked]`, `Gate::authorize('update')` działa przy montowaniu i przy zapisie, a `steps[*].mediaId` jest sprawdzane co do `owner_id`.
- **Mass assignment.**
  - `User::$fillable` ma same preferencje; `status`, `role`, hasło, tokeny i `two_factor_*` idą przez `forceFill` w nazwanych metodach.
  - Nigdzie nie ma `$request->all()` w `create`/`update`/`fill`, nie ma też `unguard`.
  - `PublishRecipe` buduje payload z jawnej listy pól.
- **Widoczność.**
  - Feedy: obserwowani, odkrywanie, tag, tablica dnia, kolaż, pytania.
  - Wyszukiwarka, sitemapa (tylko treści publiczne od dostępnych autorów), JSON-LD i OpenGraph (tylko przepis publiczny ze zdjęciem `ready`).
  - Profil, obserwujący, wspomnienia, digest (przeliczany przed wysyłką), powiadomienia (`scopeVisibleTo`), zeszyty i komentarze na stronach.
  - RSS, Atom i oEmbed nie istnieją.
- **Media.**
  - Każdy punkt przyjęcia (wpis, pytanie, „Ugotowałem”, awatar, formularz przepisu, kreator) przechodzi przez `ObslugiwaneZdjecie` i/lub `StoreUploadedImage`:
    - rozmiar w bajtach;
    - magic bytes przez `getimagesize()`;
    - biała lista JPEG/PNG/WebP/AVIF;
    - limit 50 Mpx przed dekodowaniem.
  - Klucz zapisu to `incoming/{owner}/{Y/m}/{uuid}.{ext z wykrytego MIME}`.
  - Warianty są zawsze przekodowane do WebP bez metadanych. Oryginał trafia tylko do eksportu właściciela, po `UsunGps`.
  - `MediaController` serwuje wyłącznie warianty, sprawdza Policy rodzica i ustawia `private, no-store` dla treści prywatnych.
  - Nie ma pobierania awatarów z URL, więc nie ma SSRF.
  - Formularze zgłoszeń i kontaktu nie mają pól plików.
  - Eksport RODO: osobny prywatny dysk, podpisany i wygasający link z kontrolą właściciela, pobranie w audycie, nazwy w ZIP ze `Str::slug` i UUID.
- **Logi i poczta.**
  - `WebhookBleduHandler` wysyła tylko pola z listy dozwolonych, bez komunikatu.
  - Logi aplikacji nie zawierają e-maili, IP, tokenów ani treści; `audit_log` trzyma HMAC IP.
  - Szablony `resources/views/mail/*` używają `{{ }}`, nigdzie nie ma `{!! !!}`.
  - „Napisz do nas” nie wysyła auto-odpowiedzi na podany adres.
- **Konfiguracja.**
  - `APP_DEBUG` domyślnie `false`. Seedery i komendy nie mają stałych haseł.
  - CSP z nonce, bez `unsafe-inline` i `unsafe-eval`; `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, Permissions-Policy, COOP, HSTS.
  - Wyjątki CSRF są tylko trzy, każdy z własną ochroną: `_csp`, wypisanie RFC 8058 z podpisem, `odebranie-dostepu` Facebooka z HMAC.
  - Nie ma CORS ani tras `api/*`.
  - Sesja: `encrypt`, `http_only`, `lax`, `secure` na Railway.
  - Wszystkie publiczne POST mają throttle z `config/kuking.php`: logowanie, 2FA, rejestracja, reset, link logowania, kontakt, DSA, odwołania, `_csp`, wyszukiwarka, podpowiedzi tagów, zaproszenie.
- **Sekrety.**
  - W historii gita nie ma `.env`, `.pem` ani kluczy.
  - Wzorce kluczy (`sk-`, `AKIA`, `ghp_`, `AIza`, `BEGIN PRIVATE`) występują tylko w dokumentacji i testach.
  - `config/*` nie ma domyślnych wartości sekretów.
  - Dowody w `docs/security/evidence/` to dane z fabryk testowych.
- **Zależności.** `composer audit`: brak znanych podatności. `npm audit` (z dev i bez dev): 0 podatności.

## Metoda i ograniczenia

- Przegląd kodu w czterech obszarach, prowadzony równolegle: autoryzacja z mass assignment, widoczność, logi z pocztą i konfiguracją, media.
- Uruchomione testy: istniejące strażniki tras i kolumn oraz tymczasowe testy potwierdzające A5-04, A5-06, A5-07, A5-12 i A5-13. Tymczasowe testy zostały usunięte.
- Nie uruchamiano Caddy (A5-01, A5-16) ani R2 (A5-08). Tych znalezisk trzeba dowieść na stagingu przed poprawką.
