# Przed zaproszeniem ludzi — co odhaczyć, zanim wyjdzie pierwsze zaproszenie

**Sporządzone:** 21.09.2026. **Podstawa kodu:** `origin/main` = `65327e69`,
najnowszy commit repozytorium kanonicznego `f56f97f0` (21.09.2026).
**Metoda:** `docs/legal/BRAMKA_BETY.md` i `docs/OTWARCIE.md` wzięte jako punkt
wyjścia, każda pozycja zweryfikowana w bieżącym kodzie i w dokumentach
z ostatnich dni (do 20–21.09). Tam gdzie stan zmienił się od dokumentu
źródłowego — napisane wprost, z datą.

**To NIE jest audyt kodu.** To jest lista rzeczy do zrobienia w panelach
(Railway, Cloudflare, R2, EmailLabs) i kilku decyzji właściciela, żeby
bezpiecznie zaprosić pierwsze osoby.

---

## TABELA GŁÓWNA — zrób w tej kolejności

| # | Co zrobić | Gdzie | Co się stanie, jeśli tego nie zrobisz |
|---|---|---|---|
| **1** | **Ustaw `LOG_BLAD_WEBHOOK_URL`** (adres webhooka Slack/Discord) jako zmienną środowiskową serwisu produkcyjnego. | Railway → projekt `kuking` → serwis `kuking.pl` → Variables → dodaj `LOG_BLAD_WEBHOOK_URL`. | **Dziś (potwierdzone odczytem panelu 18.09.2026) tej zmiennej NA PRODUKCJI NIE MA.** Cała reszta tej listy — błędy 500, awarie bazy, `/health` w stanie `degraded`, nieudane zadania w kolejce — jest owszem logowana poprawnie, ale kanał alarmowy jest **wyłączony i nic nie wysyła, bez wyjątku i bez ostrzeżenia** (to świadome zachowanie kodu: pusty URL = cisza). Dla człowieka: pierwsza poważna awaria (np. baza nie odpowiada, poczta przestaje wychodzić) **nikogo nie obudzi** — dowiesz się od zaproszonej osoby, która napisze „nie mogę się zalogować", a nie z alertu. |
| **2** | **Uruchom bramkę R2 (#120) i wypełnij tabelę wyniku.** Komenda: `railway ssh -- php artisan kuking:bramka-r2 --zapis`, potem panel Cloudflare (krok po kroku: `docs/infra/BRAMKA_R2.md` §2a) i ustaw `KUKING_R2_PUBLICZNE_ADRESY`. | Railway (konsola) + panel Cloudflare R2. | Bez tego nie masz dowodu, że oryginały zdjęć (z pełnym EXIF — data, model aparatu, **współrzędne GPS kuchni**) nie są publicznie dostępne. Aplikacyjna część jest zamknięta w kodzie, ale **serwerowa i panelowa część nigdy nie została przebiegnięta na produkcji** (potwierdzone 17.09: zmienna `KUKING_R2_PUBLICZNE_ADRESY` nie istnieje ani na produkcji, ani w pliku IaC — sprawdzone dziś ponownie, nadal jej nie ma). Dla człowieka: ktoś kto wgra zdjęcie kuchni ze zdjęcia zrobionego telefonem, może nieświadomie ujawnić adres domu. **Dobra wiadomość:** `cdn.kuking.pl` nie istnieje w DNS i sama trasa aplikacyjna do zdjęć jest zamknięta i przetestowana — to nie jest dziura otwarta na oścież, tylko niepotwierdzona. |
| **3** | **Włącz automatyczną kopię bazy (#193) — załóż bucket, dwa tokeny, klucz szyfrujący i serwis `kopia-bazy` w Railway.** | Panel Cloudflare R2 (nowy bucket) + Railway (nowy serwis cron) — pełna lista kliknięć: `docs/infra/KOPIE_I_ODTWORZENIE.md` §7.3. | **Liczba kopii bazy produkcyjnej wynosi dziś ZERO** (potwierdzone 18.09.2026, ponownie sprawdzone w kodzie 21.09 — serwis `kopia-bazy` w `.railway/railway.ts` istnieje tylko jako deklaracja, nie jest założony w Railway). Kod jest gotowy i **przetestowany end-to-end na kontenerze z prawdziwym S3** (18.09: pełna pętla `pg_dump` → szyfrowanie → PUT → GET → odszyfrowanie → `pg_restore`, zgodność 50/50 tabel), ale sam serwis nigdy nie wystartował na produkcji. Railway na planie Free/Hobby **nie robi żadnych kopii sam** (Volume Backups i PITR to funkcje planu Pro). Dla człowieka: gdyby coś poszło nie tak na produkcji (błąd migracji, przypadkowe `DELETE`, awaria Railway), **stracisz WSZYSTKIE konta, przepisy, zdjęcia i komentarze bezpowrotnie, bez możliwości przywrócenia.** Przy dziesięciu zaproszonych osobach ryzyko jest małe liczbowo, ale nieodwracalne. |
| **4** | **Wygeneruj klucz `OPENAI_MODERATION_KEY` i wpisz go w Railway; sprawdź komendą `php artisan kuking:sprawdz-model`.** | Panel OpenAI (API key z dostępem do `/v1/moderations`) → Railway Variables. Instrukcja: `docs/infra/DEPLOYMENT_RUNBOOK.md` krok 8B. | Polityka prywatności **obiecuje** użytkownikom automatyczną ocenę treści i zdjęć modelem AI pod kątem przemocy, nienawiści, treści seksualnych i samookaleczenia — to jest część opisu usługi, nie dodatek. Bez klucza ta funkcja jest **cicho wyłączona**: kod sam to mówi wprost w komentarzu („z zewnątrz NIE DA SIĘ ODRÓŻNIĆ działającej moderacji od wyłączonej"), a `/health` **tego nie sprawdza w ogóle** (jedyna integracja z tej rangi, która nie ma tam swojej sondy — Turnstile, Google, Facebook i analityka mają). Dla człowieka: zdjęcia obiadów nikt nie czyta, dopóki ich nie zgłosi — to jedyna „druga para oczu" poza jednym moderatorem. Bez klucza jedynym zabezpieczeniem jest ręczne zgłoszenie przez innego użytkownika. Przy 10 zaufanych osobach ryzyko jest niskie, ale to jest też rozjazd między tym, co obiecuje dokument prawny, a tym, co naprawdę się dzieje. |
| **5** | **Wyłącz piksel otwarć w panelu EmailLabs** (śledzenie, czy ktoś otworzył e-mail) i sprawdź surowy HTML realnie doręczonego listu. | Panel EmailLabs → ustawienia śledzenia (Open Tracking / Click Tracking) → wyłącz. | Polityka prywatności mówi, że tego mechanizmu nie ma, a w realnie doręczonych listach (potwierdzone 09.09.2026) są dwa mechanizmy śledzenia otwarć (`click.kuking.pl/track/o/…` — piksel i zapasowy `background:url`). To jest rozjazd dokument-vs-rzeczywistość, nie da się tego wyłączyć kodem (#204, #713 wątek A3) — wyłącznik jest wyłącznie w panelu dostawcy. Dla człowieka: dostaje list z ukrytym mechanizmem śledzenia, o którym polityka prywatności mówi, że go nie stosujemy. |
| **6** | **Zbadaj `zadania_nieudane` w `failed_jobs` na produkcji, zanim je skasujesz.** | `railway ssh` (konsola) → `SELECT queue, payload, exception FROM failed_jobs LIMIT 20;` albo `php artisan queue:failed`. | `/health` na produkcji dziś pokazuje `status: degraded` z jedynym powodem `kolejka: zadania_nieudane` (issue #713, wątek A1) — sonda tylko LICZY wiersze, nie mówi co i dlaczego padło. Nikt jeszcze nie zajrzał do treści tych zadań. Może to być coś nieszkodliwego (stary test) albo coś ważnego (np. e-mail, który nie doszedł, eksport RODO, który się nie wykonał). Dla człowieka: dopóki nie wiadomo co tam jest, nie wolno tego skasować „żeby /health był zielony" — możesz skasować jedyny ślad tego, że czyjeś zgłoszenie/e-mail/eksport danych nie doszło. |
| **7** | **Potwierdź `SESSION_SECURE_COOKIE=true` i realne wartości innych zmiennych bezpieczeństwa NA PRODUKCJI** (nie w `railway.ts`). | Railway → Variables (odczyt, nie edycja). | `.railway/railway.ts` **nigdy nie zostało zastosowane przez `railway config apply`** (potwierdzone wielokrotnie w dokumentach z 9–18.09, stan nie zmienił się do dziś — plik opisuje stan DOCELOWY, nie faktyczny). To znaczy, że **nie wolno zakładać**, że którakolwiek wartość z tego pliku (w tym `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, limity pamięci, `KUKING_ZAUFANE_PRZESKOKI`) faktycznie stoi na produkcji — trzeba to sprawdzić bezpośrednio w panelu. `.env.example` (plik lokalny) ma `SESSION_SECURE_COOKIE=false`, co nie jest dowodem na produkcję, ale pokazuje, że nikt tego jawnie nie zweryfikował. Dla człowieka: jeśli ciasteczko sesji nie jest oznaczone jako „secure", w rzadkich scenariuszach (downgrade z HTTPS) sesja mogłaby wyciec — mało prawdopodobne za Cloudflare, ale niepotwierdzone. |

---

## Reszta ustaleń z macierzy `BRAMKA_BETY.md` — zweryfikowane, NIEAKTUALNE

Te pozycje **wyglądają na blokujące w dokumencie źródłowym, ale nie są** — sprawdzone w kodzie i w dokumentach z ostatnich dni:

- **„`MAIL_MAILER=log`: reset hasła nie dochodzi do nikogo"** — to była pozycja opisująca `.env.example` (ustawienie LOKALNE), nie produkcję. Na produkcji stoi `MAIL_MAILER=emaillabs`, poczta realnie wychodzi (potwierdzone 09–10.09.2026 przez panel dostawcy i relację odbiorcy), `/health` pokazuje `poczta: ok`. **Zamknięte, dokument sam to już odnotowuje.**
- **Tożsamość i adres administratora w dokumentach prawnych** — zamknięte 8 września: SAMSUFI sp. z o.o., KRS 0000901262, dane stoją w regulaminie i polityce, egzekwowane testem `DokumentyPrawneNieKlamiaTest`.
- **Okresy retencji danych** — zamknięte, siedem komend sprzątających z konkretnymi okresami w `config/kuking.php`.
- **`cdn.kuking.pl` jako aktywna, publiczna domena bucketu wariantów** — WYCOFANE decyzją D-020 (6 września): adresem zdjęcia jest dziś trasa aplikacji `/zdjecia/{media}/{wariant}`, a `cdn.kuking.pl` nie istnieje w DNS (zmierzone z zewnątrz 10.09). Ryzyko z pozycji #2 w tabeli głównej jest **inne** — dotyczy potwierdzenia, że bucket i tak nie jest osiągalny inną drogą (r2.dev, błędnie skonfigurowana domena), nie tego, że `cdn.kuking.pl` świeci.
- **SEC-01 / nagłówki proxy (W7-01, granica zaufania Cloudflare)** — aplikacyjna część zamknięta i dowiedziona przebiegiem testów na PostgreSQL 18 (8 września, PR #139). Token krawędziowy (`X-Kuking-Edge-Token`) zostaje otwarty, ale to zabezpieczenie dodatkowe na wypadek pominięcia Cloudflare — nie blokuje 10 zaproszeń.

---

## BLOKUJE — nie zapraszaj, dopóki nie zrobione

1. Pozycja **1** (kanał alarmowy `LOG_BLAD_WEBHOOK_URL`) — bez tego jesteś ślepy na WSZYSTKO poniżej. Potwierdzone niezależnie przez otwarte P0 **#599**.
2. Pozycja **3** (kopia bazy i restore drill, **#594**, dawniej #193) — utrata danych byłaby bezpowrotna, a właściciel zgłoszenia opisał to wprost jako „najpilniejszą pozycję całego audytu". Nowy szczegół (21.09): samo odtworzenie starej kopii może **przywrócić dane osób, które w międzyczasie zażądały usunięcia konta** — restore drill musi to uwzględnić.
3. Pozycja **2** (bramka R2, **#120**) — dowód, że oryginały zdjęć (z pełnym EXIF, w tym GPS) nie wyciekają. Aplikacyjnie gotowe, na prawdziwych bucketach nieprzebiegnięte.
4. **Jurysdykcja UE bucketów R2 (#619)** — polityka prywatności obiecuje przechowywanie danych w Unii Europejskiej; poszlaka w kodzie (kształt `AWS_ENDPOINT` bez segmentu jurysdykcji) sugeruje, że tej gwarancji może nie być. Rozstrzyga to ten sam przebieg komendy co pozycja 3 (`kuking:bramka-r2 --zapis`) plus jedno spojrzenie w panel Cloudflare.
5. **Przegląd dokumentów prawnych przez prawnika (#8)** — `docs/OTWARCIE.md` i `docs/legal/COMPLIANCE.md` zgodnie stawiają to jako decyzję właściciela, nie kod. Regulamin i polityka prywatności są dziś spójne z kodem (testowane automatycznie), ale **nikt spoza tego procesu nie potwierdził, że są zgodne z prawem** — a przy pierwszych realnych osobach zaczynają obowiązywać naprawdę. Powiązane realne braki: brak rejestru czynności przetwarzania (ROPA), brak kodowej ścieżki dla CSAM (tylko playbook), niepodpisane umowy powierzenia wymienione wprost w samej polityce.

## WARTO PRZED BETĄ (nie blokuje dziesięciu zaufanych osób, ale zrób szybko)

5. Pozycja **4** (klucz moderacji OpenAI) — przy 10 znajomych ryzyko niskie, przy szerszym gronie już nie.
6. Pozycja **5** (piksel EmailLabs) — rozjazd z polityką prywatności, łatwe do naprawienia w panelu.
7. Pozycja **6** (zbadać `failed_jobs`) — żeby wiedzieć, czy `/health` ukrywa coś ważnego.
8. Pozycja **7** (potwierdzić zmienne bezpieczeństwa na produkcji) — bo `railway.ts` nie jest dowodem na nic, dopóki nie zostanie zastosowany.
9. **`CLOUDFLARE_ZONE_ID` / `CLOUDFLARE_PURGE_TOKEN`** — brak ich na produkcji (potwierdzone przez właściciela) oznacza, że czyszczenie cache CDN po skasowaniu zdjęcia jest **wyłączone**: skasowane zdjęcie (po usunięciu konta, moderacji, cofnięciu wgrania) może nadal być serwowane z cache Cloudflare przez jakiś czas. Kod loguje to jawnie jako `warning`, ale ten log **i tak donikąd nie trafia** (patrz pozycja 1) — dwie luki nakładają się na siebie.
10. **Ćwiczenie odtworzenia kopii bazy (restore drill, #9)** — samo posiadanie kopii (pozycja 3) to nie to samo, co wiedza, że da się ją odtworzyć w realnym czasie. Zrób po założeniu serwisu z pozycji 3.
11. **Testy z osobami 50+ (#15)** — w toku, wykonana 1 z 13 zaplanowanych sesji; znalazła realne problemy UX, których nie złapał żaden automat. Nie blokuje 10 zaproszeń znajomych, ale powinno iść równolegle, zanim ruszy szersza kampania.

## MOŻE POCZEKAĆ

12. **#29 (20-30 realnych osób, 100-150 wpisów przed kampanią marketingową)** — to bramka dla **kampanii** (np. wejście przez media, Garnek.pl), nie dla zaproszenia 10 znajomych. Sam dokument mówi „NIE DA SIĘ Z KODU" — to praca właściciela (rozmowy z KGW/UTW), nie coś do odhaczenia w panelu.
13. **Migracja starych zdjęć z wolumenu kontenera do R2** (`kuking:przenies-zdjecia`) — dotyczy zdjęć sprzed wdrożenia R2; jeśli takich zdjęć produkcyjnie nie ma (nowy serwis), nieistotne.
14. **Test dymny po deployu (smoke test, #12 z `OTWARCIE.md`)** — kod już nie pozwala mu być cicho pomijanym (błąd `::error` gdy pominięty), ale wymaga jednego realnego przebiegu, by to potwierdzić.
15. **`railway config apply`** — samo uruchomienie IaC nie jest pilne, dopóki pozycje 1–3 są zrobione ręcznie w panelu; ale warto wiedzieć, że dopóki nie zostanie uruchomione, `docs/.railway/railway.ts` jest dokumentacją intencji, nie stanu faktycznego.
16. **Umowy powierzenia danych (DPA)** z Cloudflare, EmailLabs, OpenAI, Railway — RODO dopuszcza formę elektroniczną (regulamin usługi), sprawdzenie nie jest pilne technicznie, ale prawnik (pozycja 4) powinien to potwierdzić przy okazji.

---

## Cztery grupy ustaleń — szczegóły

### 1. Zmienne środowiskowe brakujące na produkcji

Z ok. 340 wywołań `env()` w `config/` prawie wszystkie mają sensowne wartości
domyślne albo dotyczą sterowników, których Kuking.pl w ogóle nie używa
(MySQL, Redis, SQS — aplikacja jest na sztywno spięta z PostgreSQL/R2/EmailLabs
w `.railway/railway.ts`). Realnie brakujące i mające znaczenie:

| Klucz | Skutek braku | Blokuje? |
|---|---|---|
| `LOG_BLAD_WEBHOOK_URL` | kanał alarmowy całkowicie wyłączony (potwierdzone: nie istnieje na produkcji) | **TAK — patrz pozycja 1** |
| `KUKING_R2_PUBLICZNE_ADRESY` | bramka R2 (#120) nie da się przejść, zwraca „NIE WIEMY" | **TAK — patrz pozycja 2** |
| `OPENAI_MODERATION_KEY` | moderacja modelem AI cicho wyłączona, mimo obietnicy w polityce prywatności | warto przed betą |
| `CLOUDFLARE_ZONE_ID` / `CLOUDFLARE_PURGE_TOKEN` | czyszczenie cache CDN po skasowaniu zdjęcia wyłączone | warto przed betą |
| `KUKING_MODEL_ALARM_EMAIL` | istnieje na produkcji (wartość nieznana z tej sesji), ale bez #1 alert i tak nie ma dokąd trafić drogą webhooka — do zweryfikowania, czy adres jest realnie czytaną skrzynką | powiązane z pozycją 1 |
| `AWS_LEGACY_BUCKET` / `AWS_LEGACY_URL` | zamierzony no-op — kod jawnie sprawdza pusty bucket i pomija stary dysk; istotne tylko jeśli produkcja ma zdjęcia sprzed migracji do R2 | nie blokuje |

**Krytyczne zastrzeżenie:** `.railway/railway.ts` deklaruje dziesiątki zmiennych
przez `ctx.shared.*` (Turnstile, Google, Facebook, Sentry, PostHog, EmailLabs,
R2), ale **`railway config apply` nigdy nie zostało uruchomione** na tym
projekcie (potwierdzone w dokumentach z 9.09 do 18.09, stan bez zmian).
Realny zestaw zmiennych na produkcji może odbiegać od tego pliku w obie
strony. Właściciel potwierdził, że **faktycznie na produkcji istnieje tylko
`CLOUDFLARE_ANALYTICS_TOKEN`** z grupy Cloudflare — resztę (`ZONE_ID`,
`PURGE_TOKEN`) i `AWS_LEGACY_*` potwierdzono jako nieobecne. **Jedyny pewny
sposób, żeby wiedzieć co naprawdę jest ustawione: odczyt panelu Railway
Variables, nie ten plik.**

### 2. Co żyje wyłącznie w cudzych panelach

| Co | Gdzie sprawdzić | Jak poznać, że jest dobrze |
|---|---|---|
| `cdn.kuking.pl` → bucket wariantów (#120) | Cloudflare DNS + R2 → bucket → Settings → Custom Domains | Rekord DNS nie istnieje (potwierdzone 10.09) — **i ma tak zostać**, decyzja D-020. Sprawdź, że nikt tego nie odtworzył. |
| Bucket oryginałów — publiczny czy prywatny, `r2.dev` wyłączone | R2 → bucket `kuking-media` (oryginały) → Settings | `kuking:bramka-r2 --zapis` (komenda w repo) odpytuje to z zewnątrz prawdziwym żądaniem — pozycja 2 w tabeli głównej |
| Cloudflare: strefa, reguły cache, Turnstile | Cloudflare → DNS / Cache Rules / Turnstile | Reguła „Cache Everything" na `cdn.kuking.pl` **nie ma istnieć** (D-020); Turnstile — dwa klucze w Railway, sprawdzone przez `/health` (sonda `turnstile`) |
| EmailLabs: piksel otwarć | Panel EmailLabs → Open/Click Tracking | Wyłączone + surowy HTML realnego listu bez `track/o/` — pozycja 5 |
| Railway: harmonogramy, wolumeny, domeny | Railway → serwis → Settings | Serwis `kopia-bazy` **nie istnieje jeszcze** — pozycja 3. Domeny produkcyjne (`kuking.pl`, `www.kuking.pl`) — sprawdzić, że wskazują na serwis `kuking.pl`, nie na nieistniejący `web` |
| R2: jurysdykcja bucketów (#619) | R2 → bucket → Settings → Location | Sprawdzenie 12 komendy `kuking:bramka-r2` rozstrzyga to dla bucketów, do których sięga aplikacja (endpoint z segmentem `eu`); bucket kopii (#193) i przyszła kwarantanna (#602) — tylko panel |

### 3. Czy `/health` mówi prawdę

`app/Http/Controllers/HealthController.php` — sondy i ich skutek:

| Sonda | Co sprawdza | Zmienia status ogólny (degraded) | Zmienia HTTP (503) |
|---|---|---|---|
| `database` | `SELECT 1` | tak | **tak** |
| `migrations` | czy tabela migracji nie jest pusta | tak | **tak** |
| `media` | zapis/odczyt na dysku zdjęć, spójność `public/storage` | tak | nie |
| `turnstile` / `google` / `facebook` / `analityka` (tylko produkcja) | czy funkcja włączona w configu ma faktycznie klucze | tak | nie |
| `poczta` | czy jest czym wysyłać pocztę | tak | nie |
| `kolejka` | `COUNT(*) FROM failed_jobs` | tak | nie |
| `listy` | nieodhaczone niedostarczone maile | tak | nie |

**Czego `/health` przemilcza:**
- **Moderacja modelem AI (OpenAI)** — nie ma tam żadnej sondy, mimo że inne integracje tej samej rangi (Turnstile, Google, Facebook, analityka) mają. Kod sam to nazywa lukę w komentarzu.
- **Czyszczenie cache CDN** (`PurgePublicMediaCache`) — brak `CLOUDFLARE_ZONE_ID`/`_TOKEN` daje `Log::warning` i cichy sukces zadania; `/health` o tym nie wie.
- **Kanał alarmowy** — `/health` może poprawnie zgłosić `degraded`, ale to nie znaczy, że ktokolwiek się dowie (patrz pozycja 1) — sam `/health` nie sprawdza, czy jego własny alarm dochodzi.

Mechanizm alertowy: kanał `blad_webhook` (`config/logging.php`) ma **sztywno
ustawiony próg `level: error`**, nie dziedziczy `LOG_LEVEL`. `Log::warning(...)`
(używane m.in. przy braku kluczy Cloudflare/OpenAI) **nigdy tam nie trafi** —
to jest zamierzone (ostrzeżenia nie mają budzić nikogo w nocy), ale skutek
uboczny: żadna z „cichych" degradacji w tym dokumencie nie wywoła alertu, nawet
gdyby kanał miał adres. `HealthController` loguje porażki sond przez
`Log::error`, więc te akurat trafiają poprawnie — o ile kanał ma dokąd wysłać
(pozycja 1).

**#713, wątek A1 (`zadania_nieudane`):** potwierdzone na produkcji (19.09.2026)
— `/health` zwraca `degraded` wyłącznie z tego powodu. Sonda tylko liczy
wiersze `failed_jobs`, nie ujawnia treści (świadomie, żeby nie pokazywać
stack trace'ów w publicznej odpowiedzi). Nikt jeszcze nie zajrzał do środka —
patrz pozycja 6.

### 4. Zgłoszenia P0 / bramki startowe — stan (zweryfikowany na żywo przez `gh issue view`, 21.09.2026)

Wszystkie siedem sprawdzonych zgłoszeń jest **OPEN**, wszystkie autorstwa
`matmaxalez` (konto właściciela — nie ma osobnego bota; treść bywa pisana
przez model audytujący, ale konto jest jego), wszystkie mają **świeże
komentarze z 16-21 września** — żadne nie jest porzucone ani nieaktualne.

| # | O co chodzi | Stan (na dziś, z GitHuba) | Kategoria |
|---|---|---|---|
| **#8** (P0) | Przegląd regulaminu/polityki przez prawnika | Dokumenty gotowe i testowane, ale **to jawnie zadanie dla człowieka + prawnika** — ostatni komentarz triażu wprost mówi „nie brać do realizacji przez agenta". Powiązane z #619 | **BLOKUJE** (decyzja właściciela) |
| **#15** (P0) | Testy z użytkownikami 50+ | Materiały gotowe (scalone w #250), kryterium zamknięcia to **13 odbytych sesji z żywymi ludźmi** — 1 z 13 wykonana. Ostatni komentarz: dwie rzeczy do zrobienia przed kolejną sesją (seeder treści, ryzyko HEIC z #119) + decyzja rekrutacyjna właściciela | Nie blokuje 10 znajomych, ale to twarda bramka przed **publiczną** betą — patrz niżej |
| **#29** (P0) | Cold start — pierwszych 20 realnych użytkowników | Narzędzia gotowe (panel „bez odpowiedzi", treść zalążkowa), brakuje **realnych ludzi i metryki „drugi wpis w 7 dni"**. Najświeższy komentarz (21.09) rozbudowuje raport o tę metrykę | Formalnie to sam **cel** zapraszania, nie przeszkoda do niego — 10 zaproszeń to część drogi do #29, nie coś zablokowane przez #29 |
| **#120** (P0) | Bramka R2 — dowód, że bucket oryginałów nie jest publiczny | Aplikacyjnie gotowe; wciąż otwarte, bo **nikt nie potwierdził tego na prawdziwym Cloudflare R2** (brak dostępu do panelu z sesji agentów). Nowy komentarz (sesja 79) dokłada warunek integralności migracji zdjęć (#1031) przed odbiorem | **BLOKUJE — patrz pozycja 2** |
| **#594** (P0) | Pierwszy zrzut bazy i restore drill | Opisane wprost jako „najpilniejsza pozycja całego audytu". Backup service jest w IaC, ale konfiguracja Railway (#595) jeszcze niezastosowana, restore drill nigdy nieprzećwiczony na produkcyjnym kształcie danych. **Nowy wymóg z komentarza 21.09: drill musi też ponownie nałożyć usunięcia RODO wykonane PO dacie kopii** — inaczej odtworzenie bazy przywróciłoby dane, które ktoś legalnie kazał skasować | **BLOKUJE razem z #193 — patrz pozycja 3** |
| **#619** (P0) | Jurysdykcja UE bucketów R2 | Polityka prywatności obiecuje „dane w UE", a w repo nie ma na to dowodu — Location Hint to nie gwarancja, trzeba `jurisdiction=eu` na endpointzie. Sprawdzenie 12 komendy `kuking:bramka-r2` to rozstrzyga dla bucketów aplikacji, ale **panel Cloudflare wciąż niesprawdzony** (sesje agentów trafiają na ekran logowania) | **BLOKUJE — część pozycji 2, bezpośrednio powiązane z #8** |
| **#713** (P2, nie P0) | „Dług weryfikacyjny" — rejestr nieprzeglądniętych rzeczy, w tym A1 (`zadania_nieudane`) i A3 (piksel EmailLabs) | Sam w sobie nie jest formalną bramką startową — to lista robocza odsyłająca m.in. do #120, #619, #29 | Elementy A1 i A3 rozbite na pozycje 6 i 5 wyżej |

**Dodatkowe P0 znalezione przy okazji (nie były w zleceniu, ale bezpośrednio
potwierdzają ustalenia z tego dokumentu):**
- **#595** — „Uruchom `railway config apply`" — to jest dokładnie luka opisana
  w pozycji 7 tego dokumentu (`.railway/railway.ts` nigdy niezastosowany).
- **#599** — „P0: monitoring wydajności, queue/DB, zewnętrzny uptime i alerty
  operacyjne" — to jest dokładnie luka z pozycji 1 (brak `LOG_BLAD_WEBHOOK_URL`)
  opisana z innej strony, jako osobne zgłoszenie P0. Dwa niezależne źródła
  (czytanie kodu i to zgłoszenie) wskazują to samo jako najpilniejsze.
- **#598** — budżet połączeń PostgreSQL i próg alarmowy — powiązane z #599.
- **#597** — reguła cache Cloudflare dla `/zdjecia/*` — powiązane z pozycją 2 (R2).
- **#697** (część #713, wątek C4) — **nikt nigdy nie przeszedł pełnej ścieżki
  logowania hasłem end-to-end na żywo** (CSP naprawiony, ale nieodebrany
  realnym logowaniem). Warto przejść ręcznie raz przed pierwszym zaproszeniem
  — to 5 minut, nie projekt.
- **#941, #775** — dwa świeże (21.09) zgłoszenia P0 o wycieku widoczności
  (strona tagu pokazuje zapowiedź przepisu poza jego uprawnieniami) i
  o nieujawnionym zakresie działania „Usuń z zeszytu". Nie były w zleceniu
  tego audytu i nie zostały tu zweryfikowane w kodzie — wspominane wyłącznie
  jako sygnał, że lista P0 rośnie i warto ją przejrzeć osobno przed szerszym
  zaproszeniem, nie tylko przy pierwszych dziesięciu znajomych.

---

## Podsumowanie dla właściciela

**Pięć rzeczy naprawdę blokują bezpieczne zaproszenie pierwszych osób.**
Cztery to praca w panelach, nie w kodzie: **(1)** podłącz adres webhooka
alarmowego w Railway (potwierdza to niezależnie też issue #599), **(2)**
przebrnij bramkę R2 do końca — komenda + panel Cloudflare (#120), **(3)**
załóż serwis automatycznej kopii bazy i przećwicz jej odtworzenie, **razem
z ponownym nałożeniem usunięć RODO wykonanych po dacie kopii** (#594),
**(4)** potwierdź jurysdykcję UE bucketów R2 tym samym przebiegiem co #120,
bo dziś polityka prywatności obiecuje coś, na co repozytorium nie ma dowodu
(#619). Piąta to decyzja, a nie czynność: **(5)** przegląd dokumentów
prawnych przez prawnika (#8) — sam projekt jawnie oznacza to jako niegotowe.

Reszta (klucz moderacji AI, piksel EmailLabs, sprawdzenie `failed_jobs`,
potwierdzenie zmiennych bezpieczeństwa na produkcji, jedno ręczne przejście
ścieżki logowania hasłem) to rzeczy warte zrobienia w ciągu pierwszego
tygodnia, nie przed pierwszym kliknięciem „wyślij zaproszenie".

**Pytanie z sesji zamykającej: czy da się dziś zaprosić dziesięć osób bez
ryzyka, że coś im nie zadziała albo że stracimy ich dane?** Technicznie strona
działa, poczta wychodzi, hasła się resetują, a granica proxy/Cloudflare jest
zamknięta i przetestowana. Ale **nie** — nie z czystym sumieniem, dopóki
brakuje kopii bazy (#193/#594) i kanału alarmowego (#1): gdyby coś poszło źle
w pierwszym tygodniu, nie miałbyś ani jak się o tym dowiedzieć, ani jak
odzyskać danych tych dziesięciu osób. Obie rzeczy to kwestia godzin pracy w
panelu, nie tygodni — warto je zamknąć przed, nie po.
