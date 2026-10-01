# AUDYT_MAX_GPT6_ASTRA_2026-10-01

**Repozytorium:** woogitsu/kuking.pl  
**Data audytu:** 2026-10-01  
**Zakres:** Secure SDLC, AppSec, architektura, poprawność, baza danych, CI/CD, niezawodność, wydajność, testy, frontend/PWA, UX, dostępność, prywatność, GDPR/DSA, dostawcy, produkt i roadmapa.

## Zakres i ograniczenia

Źródłem kodu był aktualny snapshot gałęzi `main` pobrany przez GitHub API/plugin. Audytowany commit: `9d56e45ae6c9a052f0de679334c01159a17192ee`.

Drzewo zawierało 6492 wpisy. Zewidencjonowano:

- 173 branche,
- 1034 zwykłe Issues,
- 1360 Pull Requestów,
- 11 470 testów wykonanych w ostatnim zielonym CI.

Analiza kodu dotyczy przede wszystkim aktualnego `main`. Branche zostały zinwentaryzowane, ale nie przeprowadzono pełnego przeglądu zawartości każdego brancha plik po pliku, ponieważ lokalny clone GitHuba był zablokowany sieciowo.

Nie wykonywano testów obciążeniowych, brute force, fuzzingu przeciw produkcji, prób dostępu do cudzych danych ani aktywnego testowania Railway, Cloudflare, R2, poczty lub innych dostawców.

GitHub connector odrzucił operację tworzenia Issue komunikatem:

`MCP tool call requires approval, but approval policy is never`

Użytkownik udzielił autoryzacji, ale interfejs nie zezwolił na zapis. Nie obchodzono tej blokady.

## 1. Executive summary

Kuking jest ambitnym modularnym monolitem Laravel/PostgreSQL z wyraźną obietnicą produktu: osobiste archiwum przepisów połączone z dowodem gotowania i lekką warstwą społecznościową.

Kod realizuje większość podstawowego przepływu:

- tworzenie przepisu,
- publikowanie,
- zdjęcia,
- moderację,
- import,
- komentarze,
- obserwowanie,
- „Ugotowałem”,
- eksport,
- usuwanie danych.

Projekt nie powinien jeszcze przechodzić do publicznego otwarcia rejestracji.

Największe blokery dotyczą:

1. otwartych kwestii prawnych i dostawców;
2. niezweryfikowanego restore/DR;
3. publicznej i niechronionej gałęzi `main`;
4. niespójności między kolejką bazy a transakcjami importu;
5. publicznego version history zachowującego usunięte dane;
6. defektów bieżącego UI Livewire.

Do zamkniętej alfy projekt może być dopuszczony po potwierdzeniu tych warunków i ograniczeniu napływu użytkowników do kontrolowanej grupy.

Najlepszy kierunek techniczny to dalszy rozwój modularnego monolitu, PostgreSQL i database queue. Nie ma dowodu uzasadniającego mikroserwisy, Kubernetes, Redis, Kafka, osobny SPA, nową wyszukiwarkę ani WebSockety.

## 2. Stan projektu

### Mocne strony

- Wyraźnie opisany produkt i ograniczenia architektoniczne w `AGENTS.md`, `docs/PRODUCT.md`, `docs/FEATURES.md`, `docs/ARCHITECTURE.md` i `docs/DECISIONS.md`.
- Modularne granice domenowe w `app/Domain`.
- Wiele operacji korzysta z Policy, Form Request, UUID, prywatnych dysków i krótkotrwałych URL-i.
- Uploady mają limity rozmiaru, kontrolę typu/magicznych bajtów, przetwarzanie obrazu i usuwanie EXIF/GPS.
- Jest rozbudowany model moderacji, reporter appeal i ledger zgód AI.
- Aktualny CI jest zielony: cztery party testowe wykonały łącznie 11 470 testów bez skipów.
- Dokumentacja cold startu uczciwie zakłada ręczną obsługę pierwszych użytkowników zamiast sztucznego importu treści.

### Największe ryzyka

1. Publiczna `main` nie ma ochrony ani wymaganych checków.
2. Legal/Privacy/DSA nie są domknięte przed publicznym startem.
3. Backup i restore nie są udowodnione świeżym ćwiczeniem.
4. Importy są dispatchowane w transakcjach, ale `DB_QUEUE_CONNECTION` nie jest wymuszane ani sprawdzane.
5. UI ma defekt zapisu rewizji Livewire i defekt `contentRevision` po potwierdzeniu alergenów.
6. Publiczny version history może zachowywać usunięte dane osobowe (#2390).

## 3. Liczby

| Miara | Wynik |
|---|---:|
| P0 nowych, niezdublowanych | 0 |
| P1 nowych, niezdublowanych | 1 |
| P2 nowych, niezdublowanych | 4 |
| P3 nowych, niezdublowanych | 1 |
| IDEA | 8 |
| CONFIRMED nowych | 2 |
| STRONG EVIDENCE nowych | 4 |
| HYPOTHESIS | 4 |
| Prywatne security findings | 3 |
| Zwykłe Issues przejrzane z API | 1034 |
| Pull Requesty przejrzane z API | 1360 |
| Branche zinwentaryzowane | 173 |
| Nowe Issues utworzone | 0 |
| Testy w ostatnim zielonym CI | 11 470 |

## 4. Top problems

| Priorytet | ID / Issue | Problem | Pewność |
|---|---|---|---|
| P1 | `PERF-001` | Import może być niespójny z transakcją domeny. | STRONG EVIDENCE |
| P1 | #8, #594, #193, #2390, #611, #2025 | Publiczny start nie ma zamkniętego pakietu legal/DR/privacy/deploy safety. | CONFIRMED / STRONG |
| P1 | #2394, #2373 | Wizard ma regresje w rewizji autosave i ochronie przed utratą danych. | CONFIRMED |
| P2 | `DB-004` | Konkurencyjne generowanie slugu może zakończyć się unique violation. | STRONG EVIDENCE |
| P2 | `A-001` | `UnfollowUser` nie używa locka pary stosowanego przez `FollowUser`. | STRONG EVIDENCE |
| P2 | `UX-001`, `UX-002` | Problemy z semantyką błędów formularza i kroków onboardingu. | CONFIRMED |
| P2 | #2381, #1306, `SEC-003` | Ryzyka CSP/trust-proxy/logowania raportów CSP. | STRONG / CONFIRMED |

## 5. Security

Sprawdzono:

- uwierzytelnianie,
- sesje,
- reset haseł,
- magic/login links,
- Sanctum,
- CSRF,
- Policy/BOLA/IDOR,
- mass assignment,
- uploady,
- R2,
- signed URLs,
- redirecty,
- webhooki,
- rate limiting,
- enumerację,
- blokady kont,
- moderację,
- serializację jobów,
- logowanie,
- nagłówki,
- CSP/CORS/cookies,
- Docker,
- GitHub Actions.

Nie stwierdzono nowej, potwierdzonej luki P0 wymagającej natychmiastowego publicznego zgłoszenia.

### Prywatne findings bezpieczeństwa

Zgodnie z `SECURITY.md` poniższe identyfikatory nie powinny być publikowane jako zwykłe Issues.

- **SEC-001 — CSP dopuszcza szeroki `https:` dla obrazów.** Powiązane z #2381.
- **SEC-002 — trust proxy/origin zależy od poprawnego ustawienia topologii.** Powiązane z #1306.
- **SEC-003 — raporty CSP zapisują wartości pochodzące od żądającego bez jawnej normalizacji znaków sterujących na granicy kontrolera.**

Nie znaleziono haseł, tokenów ani kluczy, które powinny zostać ujawnione.

## 6. Architecture

Architektura modularnego monolitu jest adekwatna do obecnego etapu. Domeny są rozdzielone, ale granice transakcji i współbieżności nie są wszędzie konsekwentnie stosowane.

### A-001 — `UnfollowUser` omija lock pary

- **Pewność:** STRONG EVIDENCE.
- **Priorytet:** P2.
- **Plik:** `app/Domain/Social/Actions/UnfollowUser.php`.
- `UnfollowUser` wykonuje bezpośrednie `following()->detach()`.
- `FollowUser::handle()` serializuje operację przez `ZamekPary`.
- Równoległe follow i unfollow mogą przeplatać się tak, że follow zostanie zapisany po zakończeniu unfollow.

Rekomendacja: wspólny lock pary, transakcja i test konkurencyjny dla obu kierunków.

## 7. Bugs / correctness

- **PERF-001:** import dispatchowany w transakcji może być niespójny z rollbackiem domeny.
- **DB-004:** `GenerateRecipeSlug` korzysta z `exists()` przed insertem.
- **FE-001 → #2394/#2334:** `recipe-wizard.blade.php:1247-1248` używa bezpośredniego przypisania `$wire.editRevision`.
- **FE-002 → #2373:** potwierdzenie alergenów aktualizuje stan alergenów, ale nie zwiększa `$this->contentRevision`.
- **UX-001:** `resources/views/components/wybor-formy.blade.php:36-77` nie wiąże poprawnie komunikatu błędu z radiami.
- **UX-002:** onboarding pokazuje „Krok N z 3” zwykłymi spanami bez semantyki `aria-current="step"`.

## 8. Database

### DB-001 + DB-002 — timestampy

Migracje używają `timestampTz` między innymi dla:

- `comments.body_removed_at`,
- `contact_message_replies.sending_started_at`,
- `audit_recorded_at`.

Jednocześnie:

- `Comment` nie ma odpowiednich castów;
- `ContactMessageReply::casts()` obejmuje tylko `sent_at`;
- `failed_jobs.failed_at` jest zwykłym `timestamp`, bez strefy.

Rekomendacja: ujednolicić typy, casty i testy serializacji w UTC.

### DB-004 — atomowość slugu

`GenerateRecipeSlug` wykonuje `exists()` przed insertem. Dwie równoległe publikacje mogą wybrać ten sam slug. Unique constraint ochroni bazę, ale użytkownik może otrzymać błąd zamiast kolejnego poprawnego slugu.

Rekomendacja:

- pozostawić unique constraint;
- dodać ograniczony retry po konflikcie;
- wygenerować nowy deterministyczny kandydat;
- dodać test konkurencyjny.

## 9. Infrastructure / CI/CD / reliability

### Istniejące problemy

- **INF-001 → #611/#4:** `main` ma `protected:false`, brak required checks i brak enforcementu.
- **INF-002 → #595/D-333:** `.railway/railway.ts` opisuje rozdzielone usługi, a `docker/entrypoint.sh` nadal zakłada `APP_ROLE=all`.
- **INF-003 → #599/#713:** `docker/healthcheck.sh` nie potwierdza działania workera/schedulera.
- **INF-005 → #120/#193/#594/#617:** backup, offsite dump, restore drill i R2 DR nie są potwierdzone aktualnym dowodem.
- **INF-007 → #2025:** deploy może uruchomić SHA bez zielonego exact-SHA CI.
- **INF-010:** monitor backupu może zwrócić sukces przy wyłączonym backupie.

### PERF-001 — queue connection i transakcje importu

- **Pewność:** STRONG EVIDENCE.
- **Priorytet:** P1.
- **Pliki:**
  - `config/queue.php:39-69`,
  - `ZlecImportZAdresu.php:64-91`,
  - `ZlecImportZPdf.php:104-139`,
  - `ZlecImportPrzepisu.php:172-218`.

Konfiguracja ma `after_commit=false`, a joby są dispatchowane wewnątrz transakcji.

Jeśli `DB_QUEUE_CONNECTION` różni się od `DB_CONNECTION`, job może:

- stać się widoczny przed commitem;
- przeżyć rollback domeny;
- wskazywać rekord, którego nie ma.

Rekomendacja:

- wymusić wspólną connection;
- ustawić `after_commit=true` albo dispatchować po commicie;
- dodać startup assertion;
- dodać test rollbacku i awarii workera.

## 10. Performance

Nie ma dowodu, że obecny etap wymaga Redis, Kafka, mikroserwisów lub nowej wyszukiwarki.

Należy monitorować:

- importy,
- przetwarzanie obrazów,
- listy feedu,
- version history,
- storage growth,
- failed jobs,
- czas odpowiedzi,
- cache PWA.

Orientacyjnie:

- przy 1k użytkowników wystarczy obecny monolit po domknięciu indeksów i limitów;
- przy 10k trzeba dodać metryki kolejki i slow query;
- przy 100k dopiero dane z pomiarów mogą uzasadnić rozdzielenie workera lub odczytów;
- przy 1M potrzebny byłby osobny plan pojemnościowy.

## 11. Testing

Zielony CI z 11 470 testami jest mocnym sygnałem, ale liczba testów nie dowodzi pełnej ochrony.

Brakujące klasy testów:

- macierz authorization;
- API/UI parity;
- follow/unfollow concurrency;
- slug allocation concurrency;
- rollback transakcji importu;
- retry/idempotencja jobów;
- migracje PostgreSQL z `timestamptz`;
- CSP/log injection;
- Livewire autosave;
- allergen reconfirmation;
- axe/keyboard/screen-reader;
- restore backupu;
- export/delete consistency.

## 12. Frontend / PWA

Service worker ma wersjonowane cache i testy fixture aktualizacji. Nie potwierdzono nowej awarii cache/offline.

Najważniejsze problemy:

- #2394/#2334: poprawić zapis `editRevision` przez wspierany mechanizm Livewire.
- #2373: każda zmiana treści musi zwiększać `contentRevision`.
- `powiadomienia-push.js` nie ma jawnego timeoutu `AbortController`; to hipoteza.
- CSP obrazów i trust proxy są opisane osobno.

## 13. UX / accessibility / 50+

Dokumenty wymagają:

- minimum 18 px tekstu;
- dużych targetów;
- etykiet;
- polskich błędów;
- 320 px;
- 200% zoom;
- braku zależności od hover/swipe.

Problemy:

- **UX-001:** radio/fieldset nie wiąże błędu walidacji z kontrolką dla czytnika ekranu.
- **UX-002:** progres onboardingu nie ma semantycznego current step.
- Potrzebna jest ręczna kontrola klawiatury, zoomu i czytnika ekranu.
- Istniejące obserwacje o tekście błędów i kontraście należy utrzymać w #15/#713.

## 14. Privacy / GDPR

`resources/legal/polityka-prywatnosci.md` opisuje Railway, Cloudflare, R2, OpenAI i EmailLabs.

Dokument stwierdza, że umowy powierzenia nie są jeszcze podpisane i mają zostać podpisane przed otwarciem rejestracji.

Najważniejsze:

- **#8 P0:** prawnik, DPA, ROPA/art. 30, CSAM path, effective date, transfery i EmailLabs.
- **#2390 P1:** publiczny version history zachowuje usunięte dane osobowe.
- **#204:** EmailLabs tracking pixel wymaga ponownego potwierdzenia.
- Eksport/usuwanie danych trzeba sprawdzić razem z backupami, audit logs i wersjami treści.
- Należy zbudować aktualną ROPA.

## 15. DSA / legal / copyright / minors

`docs/legal/MODERATION_PLAYBOOK.md` opisuje więcej niż publiczne `resources/legal/zasady.md`.

Playbook zawiera między innymi informacje o:

- odwołaniu,
- terminach,
- retencji.

Publiczny tekst jest dużo ogólniejszy i sprowadza kontakt do „napisz do nas”.

Do domknięcia pozostają:

- notice-and-action;
- statement of reasons;
- dostępne odwołanie;
- retencja dowodów;
- prawa autorskie;
- reguły dla małoletnich;
- procedura CSAM;
- wersjonowanie akceptacji regulaminu.

## 16. Vendors / processors / transfers

| Dane | Komponent | Dostawca | Ryzyko |
|---|---|---|---|
| IP, request metadata, treść żądań | Cloudflare | Cloudflare | proxy/logi, retencja, transfer |
| Aplikacja, logi, DB | Railway | Railway/PostgreSQL | region logów, DPA, backup |
| Zdjęcia | R2 | Cloudflare R2 | bucket policy, region, lifecycle, DR |
| Tekst i resized photos | AI integration | OpenAI | DPA, transfer, retencja |
| E-mail i tracking | EmailLabs/Vercom | Polska | DPA, pixel, retencja |
| CI/dependency metadata | GitHub Actions | GitHub | OIDC, secrets, artifacts |

Każdy dostawca wymaga potwierdzenia:

- roli controller/processor;
- regionu;
- retencji;
- usuwania;
- subprocessors;
- transferów;
- podstawy prawnej.

## 17. Documentation inconsistencies

- README mówi o „3631 testach”, a aktualny CI wykonał 11 470.
- README nadal pokazuje „Alfa 0.09”.
- `SECURITY.md` twierdzi, że repozytorium jest prywatne, a API wskazuje publiczną widoczność.
- `BACKLOG.md` sam przyznaje, że jest nieaktualny.
- `docs/audyt/2026-09-30-infra-niezawodnosc.md` odnosi się do starego/przyszłego SHA.
- `MODERATION_PLAYBOOK.md` i `zasady.md` nie opisują tych samych praw odwoławczych.
- README zawiera obietnicę „Twoje przepisy nie zginą”, mimo że dokument offline ostrzega przed bezwarunkowym twierdzeniem.

## 18. Product

Podstawowa obietnica jest zrozumiała:

- użytkownik zapisuje przepis;
- gotuje go;
- wraca do archiwum;
- otrzymuje sygnał społeczny.

Najlepiej działającym wyróżnikiem jest pętla:

`Ugotowałem → reakcja/odpowiedź → powrót`

Problemy produktowe:

- pusty feed przy braku obserwowanych osób;
- brak gwarantowanej pierwszej odpowiedzi człowieka;
- niejednoznaczny status treści;
- zbyt późne wyjaśnianie prywatności i moderacji;
- ryzyko budowania funkcji przed domknięciem podstaw.

Nie budować teraz:

- DM;
- live chat;
- marketplace;
- payouts;
- punktów;
- masowych importów;
- generowania treści AI.

## 19. Missing functionality

### Przed publicznym startem

- podpisane DPA;
- ROPA;
- procedura CSAM;
- regulamin/polityka z datą i wersjonowaniem;
- restore drill PostgreSQL i R2;
- RPO/RTO;
- branch protection;
- required CI;
- deploy po zielonym exact-SHA;
- zamknięcie #2390, #2373 i #2394;
- testy konkurencyjne;
- queue rollback;
- accessibility smoke suite.

### Wkrótce

- dashboard cold startu;
- metryki pierwszego przepisu;
- metryki pierwszego „Ugotowałem”;
- czas odpowiedzi człowieka;
- worker heartbeat;
- alerty backupów;
- storage lifecycle.

### Później

- rekomendacje;
- kolekcje;
- sezonowość;
- testy pojemności stagingu;
- formalny plan pojemnościowy.

### Nie budować teraz

DM, live chat, marketplace, wypłaty, punkty, masowe importy, AI rewrite, mikroserwisy, Kubernetes, Kafka, Redis, osobna aplikacja SPA i nowa wyszukiwarka bez danych uzasadniających koszt.

## 20. Things to remove/simplify

- Usunąć ręcznie utrzymywaną liczbę testów i wersję alfa z README albo generować je z CI.
- Zredukować równoległe dokumenty backlogów i audytów.
- Ujednolicić komponenty błędów i kroków wizardu.
- Zastąpić warunkowe deploye jednym jawnie opisanym gate’em.
- Nie dodawać nowych usług infrastrukturalnych.

## 21. Product opportunities

1. Concierge onboarding.
2. Widoczny „co dalej?” po publikacji.
3. Tygodniowy rytuał gotowania.
4. Powiadomienia tylko wtedy, gdy wnoszą wartość.
5. Czytelny status treści.
6. Pomiar pierwszej wartości i retencji.
7. Publiczny mechanizm odwołania.
8. Wzmocnienie prywatnego archiwum i eksportu.

## 22. Infrastructure opportunities

- Dashboard deploy SHA/CI SHA/worker/backup/restore/R2/queue.
- Codzienny test integralności backupu.
- Okresowy restore na izolowanym środowisku.
- Startup checks dla connection i wymaganych sekretów.
- OIDC dla GitHub Actions.
- Pinowanie third-party actions SHA.
- Alerty o storage, logach, dead jobs i pełnym dysku.

## 23. Long-term direction

Pozostać przy modularnym monolicie.

Inwestować w:

- kontrakty domenowe;
- transakcje;
- idempotencję;
- obserwowalność;
- zgodność prawną;
- UX dla grupy 50+.

Rozdzielenie workera lub odczytów rozważyć dopiero po pomiarach kolejki, bazy i storage.

## 24. Hypotheses requiring further validation

- **H-001:** brak timeoutu w `powiadomienia-push.js` może pozostawić przycisk disabled.
- **H-002:** fallback `setpriv` może uruchomić aplikację jako root.
- **H-003:** wyłączone `proc_open` może złamać import PDF po aktywacji funkcji.
- **H-004:** Dependabot/composer może nie obejmować wszystkich aktualizacji.

Nie tworzyć z tych hipotez Issues bez dodatkowego potwierdzenia.

## 25. Issue index

### Nowe Issues przygotowane, lecz nieutworzone

| ID | Priorytet | Obszar | Status |
|---|---|---|---|
| AUD-001 | P1 | Infrastructure/database | Nieutworzone — approval gate |
| AUD-002 | P2 | Database | Nieutworzone — approval gate |
| AUD-003 | P2 | Social/architecture | Nieutworzone — approval gate |
| AUD-004 | P2 | Accessibility | Nieutworzone — approval gate |
| AUD-005 | P2 | Accessibility/UX | Nieutworzone — approval gate |
| AUD-006 | P3 | Database | Nieutworzone — approval gate |

### AUD-001

**Tytuł:** `[AUDYT][P1][INFRASTRUCTURE] Importy muszą być spójne z transakcją i database queue`

Importy dispatchują joby w obrębie transakcji, ale konfiguracja nie wymusza wspólnej queue connection ani `after_commit`.

Dowód:

- `config/queue.php:39-69`;
- `ZlecImportZAdresu.php:64-91`;
- `ZlecImportZPdf.php:104-139`;
- `ZlecImportPrzepisu.php:172-218`.

Rozjazd env może utworzyć job wskazujący niezatwierdzony rekord albo pozostawić job po rollbacku.

Rekomendacja:

- wymusić `DB_QUEUE_CONNECTION=database`;
- ustawić `after_commit=true`;
- dispatchować po commicie;
- dodać startup assertion;
- dodać test rollbacku.

### AUD-002

**Tytuł:** `[AUDYT][P2][DATABASE] Alokowanie slugu przepisu musi być atomowe`

`GenerateRecipeSlug` sprawdza dostępność przez `exists()` przed insertem.

Dwie równoległe publikacje mogą wybrać ten sam slug i jedna zakończy się błędem unique.

Rekomendacja:

- pozostawić unique constraint;
- dodać ograniczony retry;
- wygenerować kolejny deterministyczny slug;
- dodać test równoległych publikacji.

### AUD-003

**Tytuł:** `[AUDYT][P2][SOCIAL] Unfollow musi używać tego samego locka pary co Follow`

`UnfollowUser` wykonuje `detach()` bez `ZamekPary`, podczas gdy `FollowUser` serializuje operację.

Rekomendacja:

- wspólny lock dla obu kierunków;
- transakcja;
- idempotencja;
- test interleavingu web/API.

### AUD-004

**Tytuł:** `[AUDYT][P2][ACCESSIBILITY] Powiązać błąd walidacji wyboru formy z radiami`

`resources/views/components/wybor-formy.blade.php:36-77` wskazuje pomoc przez `aria-describedby`, ale błąd nie ma stabilnego id i brak `aria-invalid`.

Rekomendacja:

- stabilne id błędu;
- `aria-describedby` obejmujące pomoc i błąd;
- `aria-invalid`;
- focus na summary po nieudanym submit.

### AUD-005

**Tytuł:** `[AUDYT][P2][UX] Nadać onboardingowi semantykę bieżącego kroku`

Onboarding pokazuje `Krok N z 3` zwykłym tekstem bez `aria-current="step"` i bez semantycznej grupy.

Rekomendacja:

- `role="group"`;
- nazwa kroku;
- bieżący krok;
- poprawne ukrycie kroków nieaktywnych;
- test 320 px i 200% zoom.

### AUD-006

**Tytuł:** `[AUDYT][P3][DATABASE] Ujednolicić casty i typy znaczników czasu`

Timestampy ze strefą w migracjach nie są konsekwentnie castowane w modelach, a `failed_jobs.failed_at` jest bez strefy.

Rekomendacja:

- ustalić UTC/timestamptz;
- dodać casty modeli;
- dodać migrację kompatybilną z danymi;
- dodać testy serializacji i porównań.

## 26. Existing issues confirmed by audit

- #8 — legal review, DPA/ROPA, CSAM, transfery, effective date, EmailLabs.
- #10 — DSA/reporting gap.
- #30 — bezwarunkowa obietnica trwałości w README.
- #35 — Web Push design/limits.
- #120 — R2 real bucket gate.
- #193 — offsite pg_dump/restore drill.
- #204 — EmailLabs tracking pixel.
- #2290 — statement timeout.
- #2298 — public repo/fork risk.
- #2302 — infra bundle.
- #2373 — stale content revision.
- #2380 — notification race.
- #2381 — szeroki CSP.
- #2382 — R2 gate sprawdzający nieużywany Sentry.
- #2383 — response splitting w phone proxy.
- #2390 — publiczny version history i usunięte PII.
- #2394/#2334 — Livewire autosave revision.
- #595/D-333 — Railway split services.
- #599/#713 — worker/scheduler health.
- #611/#4 — branch protection/CI gate.
- #2025 — deploy bez exact green CI SHA.

## 27. Security findings NOT published as Issues

- `SEC-001`: szeroka polityka CSP dla obrazów.
- `SEC-002`: konfiguracja trust proxy/origin zależna od topologii.
- `SEC-003`: wartości raportów CSP pochodzące z żądania wymagają sanitizacji przed logowaniem.

Nie podano payloadów, danych, sekretów ani instrukcji wykorzystania.

## 28. Recommended execution order

1. Zamknąć #8, DPA, ROPA, CSAM, regulamin, transfery i retencję.
2. Wykonać restore drill PostgreSQL/R2.
3. Włączyć branch protection i exact-SHA CI gate.
4. Naprawić queue/transaction contract.
5. Zamknąć #2390, #2373 i #2394.
6. Naprawić slug race, follow/unfollow lock i timestamp contracts.
7. Naprawić accessibility, UX, moderation copy i onboarding.
8. Dopiero potem otworzyć kontrolowaną closed alpha.

## 29. Suggested roadmap

### BEFORE LAUNCH

- Legal/Privacy/DSA.
- DPA/ROPA.
- CSAM.
- Backup restore.
- R2 DR.
- Branch protection.
- Deploy gate.
- Queue atomicity.
- #2390/#2373/#2394.
- Testy authorization/concurrency/accessibility.
- Aktualizacja dokumentów.

### FIRST 30 DAYS

- Kontrolowana closed alpha.
- Concierge onboarding.
- SLA pierwszej odpowiedzi.
- Dashboard cold startu.
- Worker/backup/storage alerting.
- Cotygodniowy review incidentów.
- Korekta UX na podstawie sesji użytkowników 50+.

### NEXT 90 DAYS

- Pomiar retencji.
- Pomiar pętli „Ugotowałem”.
- Bezpieczne rekomendacje.
- Kolekcje/sezonowość.
- Restore drill.
- Test pojemności stagingu.
- Porządkowanie historycznych audytów.

### LATER

Zmiany skalujące tylko po metrykach: osobny worker/odczyty, rozbudowane rekomendacje, większy storage lifecycle.

## 30. Sources

- W3C WCAG 2.2: https://www.w3.org/TR/WCAG22/
- GDPR: https://eur-lex.europa.eu/eli/reg/2016/679/oj
- Digital Services Act: https://eur-lex.europa.eu/eli/reg/2022/2065/oj
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- OWASP API Security: https://owasp.org/API-Security/
- Laravel: https://laravel.com/docs
- PostgreSQL: https://www.postgresql.org/docs/current/
- GitHub protected branches: https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches
- GitHub Actions OIDC: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/about-security-hardening-with-openid-connect
- GitHub self-hosted runners: https://docs.github.com/en/actions/hosting-your-own-runners/about-self-hosted-runners

## Quality gate

| Kontrola | Wynik |
|---|---|
| Coverage check | Częściowo zaliczona — pełny clone wszystkich branchy nie był możliwy |
| Evidence check | Zaliczona |
| Duplicate check | Zaliczona |
| Contradiction check | Zaliczona |
| Security disclosure check | Zaliczona |
| Legal source check | Zaliczona |
| Provider check | Częściowo zaliczona — regiony/DPA wymagają potwierdzenia |
| Documentation drift check | Zaliczona |
| Product check | Zaliczona |
| False-positive check | Zaliczona z ograniczeniem |

### Wynik końcowy

Repozytorium ma dobrą bazę inżynierską i szeroki zestaw testów, ale nie spełnia jeszcze warunków bezpiecznego publicznego startu.

Najpierw trzeba zamknąć:

- legal;
- DR;
- deploy safety;
- data integrity;
- privacy;
- krytyczne błędy Livewire.

Nowe Issues zostały przygotowane, ale nie zostały zapisane z powodu blokady GitHub connectora.