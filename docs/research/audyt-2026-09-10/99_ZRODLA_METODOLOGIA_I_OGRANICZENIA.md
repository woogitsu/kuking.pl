# Źródła, metodologia i ograniczenia audytu Kuking.pl

**Data:** 10.09.2026

## Zakres źródeł

Audyt wykorzystał:

1. prywatne repozytorium GitHub `woogitsu/kuking.pl` przez konektor GitHub,
2. aktualny `main`, zamrożony do pełnego przekroju na `cee15a56fa82985d852b2724a880e425cb83dd9d`,
3. delta-review jednego późniejszego commita `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`,
4. otwarte issues i komentarze w repo,
5. dokumentację architektury, produktu, decyzji, legal i infrastruktury,
6. oficjalne źródła internetowe dla standardów, prawa i aktualnych wersji zależności.

## Najważniejsze źródła zewnętrzne

### RODO

- EUR-Lex, Rozporządzenie (UE) 2016/679: art. 7 (warunki zgody), art. 28 (procesorzy), art. 30 (rejestr czynności przetwarzania).
- https://eur-lex.europa.eu/eli/reg/2016/679/oj

### DSA / Polska — stan sprawdzony 10.09.2026

- UKE, 04.09.2026, komunikat o uchwaleniu przez Sejm ustawy wdrażającej DSA i roli Prezesa UKE jako koordynatora usług cyfrowych:
  https://www.uke.gov.pl/uslugi-cyfrowe/aktualnosci/sejm-uchwalil-ustawe-wdrazajaca-przepisy-aktu-o-uslugach-cyfrowych-prezes-uke-koordynatorem-ds-uslug-cyfrowych%2C26.html
- Prezydent RP, 09.01.2026, informacja o wecie wcześniejszej ustawy:
  https://www.prezydent.pl/aktualnosci/wydarzenia/prezydent-podpisal-osiem-ustaw-trzy-zawetowal%2C112911

W czasie audytu nie znalazłem oficjalnego źródła potwierdzającego, że nowa ustawa z września 2026 została już podpisana i ogłoszona. Status należy sprawdzić ponownie w dniu decyzji o starcie.

### WCAG / PWA

- W3C WCAG 2.2, kryterium 1.3.4 Orientation.
- https://www.w3.org/WAI/WCAG22/Understanding/orientation.html
- W3C Web App Manifest — `orientation`.
- https://www.w3.org/TR/appmanifest/

### SEO / sitemap

- Google Search Central — limity sitemap: do 50 000 URL oraz 50 MB nieskompresowanego pliku.
- https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap

### GitHub Actions / supply chain

- GitHub Docs — Secure use reference: pełny commit SHA jest jedynym immutable sposobem przypięcia akcji.
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions
- GitHub Docs — Dependabot configuration options (`ignore`, `update-types`).
- https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference

### Aktualność stacku

Sprawdzono w dniu audytu oficjalne rejestry/źródła:

- Packagist `laravel/framework`,
- npm `vite`,
- npm `playwright`.

Wniosek: zależności aplikacji są świeże; audyt nie wykazał problemu typu „projekt stoi na starej generacji frameworka”.

## Metodologia

Każde ustalenie było kwalifikowane jako:

- **błąd/ryzyko potwierdzone kodem**, jeśli wynika bezpośrednio z implementacji,
- **rozjazd dokumentacja–kod**, jeśli źródła w repo mówią różne rzeczy,
- **bramka operacyjna**, jeśli repo nie może dowieść konfiguracji panelu lub stanu usługi zewnętrznej,
- **ryzyko produktowe/UX**, gdy wynika z przepływu użytkownika, dokumentacji produktu lub realnego testu grupy docelowej,
- **aktualny fakt zewnętrzny**, jeśli został sprawdzony w sieci w dniu audytu.

Priorytety:

- P0 — blocker startu / utrata danych / poważne ryzyko prawne lub bezpieczeństwa,
- P1 — wysokie ryzyko głównej ścieżki,
- P2 — istotne, ale może poczekać po ograniczonej becie,
- P3 — porządek/usprawnienie.

## Ważne ograniczenia

Nie miałem bezpośredniego dostępu do paneli:

- Railway,
- Cloudflare,
- EmailLabs,
- zewnętrznego uptime monitora,
- umów/DPA poza repo,
- rzeczywistej bazy produkcyjnej.

Dlatego stwierdzenia typu „R2 jest niegotowe”, „monitor nie działa” lub „brak DPA” są formułowane ostrożnie: jeśli repo/issue mówi, że bramka jest otwarta, raport mówi **brak dowodu zamknięcia / wymaga weryfikacji**, a nie udaje odczytu panelu, którego nie wykonano.

## Ograniczenie dotyczące GitHub Actions

Konektorowy odczyt `fetch_commit_workflow_runs` użyty podczas audytu filtruje runy do zdarzeń `pull_request`. Pusty wynik nie pozwala więc wnioskować o braku runu wywołanego pushem. Pierwotne nadmierne wnioskowanie zostało skorygowane w raporcie 05 i raporcie końcowym.

## Ograniczenie dotyczące zmiany `main`

Repo było aktywnie rozwijane w trakcie audytu. Aby uniknąć niespójnego snapshotu:

- pełny audyt wykonano na `cee15a56`,
- po zakończeniu sprawdzono `main`,
- doszedł jeden commit `e3cf6ab5`,
- jego diff został przejrzany,
- wpływ na ustalenia został opisany w raportach 06 i 14.

## Zasada interpretacji

Najważniejsza zasada tego audytu:

> **„zaimplementowane” nie znaczy „działa na produkcji”, a „procedura istnieje” nie znaczy „odtworzenie zostało wykonane”.**

Ta różnica jest główną osią rekomendacji końcowej.


## Druga warstwa audytu — współbieżność i awarie częściowe

Po audycie przekrojowym wykonano dodatkowy przegląd scenariuszowy na
`e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`.

Metoda drugiej warstwy nie pytała tylko „czy happy path działa”, lecz:
- co się stanie przy dwóch równoległych żądaniach;
- co się stanie po crashu między DB a kolejką/API/storage;
- czy inwariant jest w bazie, czy wyłącznie w sekwencji PHP `exists() → insert`;
- czy operacja ma idempotency key;
- czy `withoutOverlapping()` faktycznie chroni między replikami;
- czy telemetria rozróżnia `queued`, `accepted` i `delivered`.

Najważniejsze klasy sprawdzone w tej warstwie:
- `ConfirmEmailChange`, `CancelEmailChange`, `RequestEmailChange`;
- `WyslijLinkDoLogowania`, `LoginLinkController`;
- `DziennyBudzetListow`, `WyslijPodsumowaniaTygodnia`, `OdbiorcyDigestu`;
- `DataSettingsController`, `GenerateUserExport`, `CleanUpDataExports`;
- `EraseAccountData`;
- `SearchQuery`, `User`, Policy i zakresy widoczności;
- `routes/console.php`, `HealthController`.

### Dodatkowe źródła prawne

- EDPB — Anonymisation / pseudonymisation:
  https://www.edpb.europa.eu/topics/ai-and-technology/anonymisation-pseudonymisation_en
- EDPB — Guidelines 02/2026 on Anonymisation; na dzień 10.09.2026 dokument był wersją do konsultacji publicznych:
  https://www.edpb.europa.eu/public-consultations/guidelines-022026-on-anonymisation_en
- EDPB — Guidelines 01/2022 on data subject rights — Right of access, wersja finalna:
  https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-012022-data-subject-rights-right-access_en
- EUR-Lex — Rozporządzenie (UE) 2016/679:
  https://eur-lex.europa.eu/eli/reg/2016/679/oj

### Ograniczenie testów współbieżności

Konektor GitHub daje przegląd kodu, ale nie uruchamia równoległych transakcji
na produkcyjnej bazie. Znaleziska race condition wynikają z konkretnego
interleavingu dopuszczonego przez kod i schema. Kryterium zamknięcia wymaga
testów na prawdziwym PostgreSQL z co najmniej dwoma niezależnymi połączeniami
lub procesami — zwykły test jednowątkowy nie wystarczy.

## Trzecia warstwa audytu — autoryzacja, media, integracje i rollbacki

Trzeci przebieg wykonano na snapshotcie:
`fd164ad3a91185d1969a109fd692a269ad9710e3`.

Przegląd scenariuszowy objął m.in.:
- `DostepDoZdjecia`, `MediaController`, `ProcessUploadedImage`, `KasujZdjecie`, `OsieroconeZdjecia`;
- `PublishPost` i sposób przypinania uploadów do treści;
- `RecipePolicy`, `PostPolicy` i uprzywilejowany dostęp moderatora;
- `FollowUser`, `BlockUser`, `UnblockUser` i schemat `follows/blocks`;
- konfigurację session/cache/rate limit;
- `KlientTurnstile` i `PurgePublicMediaCache`;
- migracje `default_weekly_digest_to_off`, `add_erased_status_and_delete_scope_to_users`, `drop_topics`;
- konfigurację Railway pre-deploy.

### Metoda dla nowych race conditions

Znaleziska MEDIA-01 i SOCIAL-01 powstały przez zbudowanie konkretnego dozwolonego
interleavingu dwóch transakcji/żądań. Nie wystarczy zamknąć ich nowym testem
jednowątkowym. Kryterium zamknięcia to test na prawdziwym PostgreSQL z co najmniej
dwoma niezależnymi połączeniami/procesami oraz kontrolowanym punktem synchronizacji.

### Źródła zewnętrzne trzeciej warstwy

- Cloudflare Turnstile — server-side validation:
  https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- Cloudflare Turnstile — hostname management:
  https://developers.cloudflare.com/turnstile/additional-configuration/hostname-management/
- Cloudflare Cache — purge cache:
  https://developers.cloudflare.com/cache/how-to/purge-cache/

Dokumentacja Cloudflare sprawdzana w dniu audytu wskazywała na walidację
`hostname`/`action` jako zalecaną praktykę oraz plan-zależne limity purge. Ponieważ
audyt nie ma dostępu do panelu Cloudflare, brak takiej walidacji w aplikacji został
zakwalifikowany jako P2 hardening, a nie jako udowodnione obejście Turnstile.

### Dodatkowe ograniczenie operacyjne

`.railway/railway.ts` opisuje docelowy stan konfiguracji (m.in. pre-deploy migracji
i `SESSION_ENCRYPT=true`), ale plik IaC nie jest sam w sobie dowodem aktualnej
wartości w panelu Railway. Ustalenia dotyczące produkcyjnego szyfrowania sesji i
liczby replik są więc bramkami weryfikacyjnymi, nie stwierdzeniem o bieżącej
konfiguracji panelu.

## Finalny delta-check po trzeciej warstwie

Przed pakowaniem `main` wskazywał `47dbb6cc9afb4a4000291d655cfc2b9061e6daf8`,
czyli dwa commity nad snapshotem trzeciej warstwy `fd164ad3…`. Compare wykazał
zmiany dokumentacji audytowej/`OTWARCIE.md`, JS/rejestracji i testów UX, ale nie
zmienił plików odpowiedzialnych za MEDIA-01, MEDIA-03, SOCIAL-01 i MIG-01.

Repozytoryjne `docs/research/audyt-2026-09-10/SPRAWDZENIE.md` zgłosiło, że
pierwotna teza o braku reporter lifecycle była błędna. Nie przyjęto tego na
wiare: bezpośrednio sprawdzono `ReportContent`, `NotifyReporterDecision` oraz
aktualny `MODERATION_PLAYBOOK.md`. Kod domyka receipt/decision dla zwykłego
reportera, a playbook nadal mówi, że tego nie robi. Raporty końcowe zostały
skorygowane: usunięto fałszywy P0, pozostawiono P1 drift dokumentacji.

Finalna weryfikacja operacyjna zapisana w `docs/OTWARCIE.md` podaje również:
- realne wychodzące wiadomości produkcyjne — potwierdzone;
- open tracking EmailLabs sprzeczny z polityką — nadal niezamknięty;
- nowe zdjęcia signed-R2 — potwierdzone częściowo z zewnątrz;
- stare zdjęcia na wolumenie oraz pełna bramka #120 — nadal niezamknięte;
- offsite backup i restore drill — nadal niezamknięte.

