# Kuking.pl — baza bezpieczeństwa (Laravel 13 + PostgreSQL + S3/R2)

> Dokument techniczny, wykonalny przez zespół deweloperski. Nie zastępuje audytu bezpieczeństwa — traktuj to jako minimum przed publicznym startem, nie jako pełne pokrycie. Powiązane: `COMPLIANCE.md` (kontekst prawny RODO/DSA dla logów i incydentów), `MODERATION_PLAYBOOK.md` (procedura CSAM przy uploadzie).

---

## 1. Nagłówki HTTP

Konfiguracja przez middleware (np. pakiet `spatie/laravel-csp` lub własny middleware ustawiający nagłówki ręcznie — dla mniejszego projektu ręczny middleware jest łatwiejszy do audytu).

### Content-Security-Policy (CSP) — realna propozycja dla Livewire + Alpine.js

> **STAN NA WRZESIEŃ 2026 — TO JEST JUŻ WDROŻONE, nie propozycja.**
> Kod: `app/Http/Middleware/ApplySecurityHeaders.php`, testy:
> `tests/Feature/PolitykaBezpieczenstwaTest.php`, odbiornik zgłoszeń:
> `app/Http/Controllers/CspReportController.php` (issue #12).
>
> Wymuszamy `default-src 'self'` i `script-src 'self' 'nonce-…'` — bez
> `unsafe-inline` i bez `unsafe-eval`. Nonce powstaje w middleware przed
> wyrenderowaniem widoku (`Vite::useCspNonce()`), więc `@vite`,
> `@livewireScripts` i `<x-json-ld>` dopisują go sobie same. `unsafe-eval`
> przestało być potrzebne dzięki `livewire.csp_safe = true` (bundel Alpine
> bez `new Function`).
>
> **Jedyna niedomknięta dyrektywa:** `style-src` ma jeszcze `unsafe-inline`,
> bo w widokach zostało 355 atrybutów `style="…"` w 57 plikach. Nonce ich nie
> ratuje — działa na elementy `<style>`, a nie na atrybut `style`. Nagłówek
> `Report-Only` jest ustawiony ostrzej (`style-src` z samym nonce), żeby
> mierzyć dokładnie tę pozostałość, a nie coś, co jest już w porządku.

Livewire i Alpine wymagają dopuszczenia inline `<script>` generowanych przez Livewire (do przesyłania stanu komponentów) — to najczęstsza pułapka przy pisaniu CSP dla tego stacku. Praktyczne podejście: **nonce per-request** zamiast `unsafe-inline`.

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'nonce-{RANDOM_PER_REQUEST}' https://cdnjs.cloudflare.com;
  style-src 'self' 'nonce-{RANDOM_PER_REQUEST}' https://fonts.googleapis.com;
  font-src 'self' https://fonts.gstatic.com;
  img-src 'self' data: https://<twoja-domena-r2-lub-cdn>;
  media-src 'self' https://<twoja-domena-r2-lub-cdn>;
  connect-src 'self' https://<host-posthog-eu>;
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
  object-src 'none';
  upgrade-insecure-requests;
```

Uwagi praktyczne:
- Wygeneruj `nonce` w middleware (np. `Str::random(16)` zakodowane base64) i przekaż go do layoutu Blade jako zmienną — Livewire i Alpine wspierają `x-nonce`/konfigurację nonce od Alpine 3.x (`Alpine.csp = true` / atrybut na `<script>`), sprawdź wersję Alpine użytą w projekcie [do weryfikacji zgodności wersji].
- Jeśli pełny nonce okaże się zbyt pracochłonny na start, **tymczasowy, gorszy, ale realistyczny kompromis**: `script-src 'self' 'unsafe-inline'` **tylko** dopóki nie wdrożysz nonce — jawnie zapisz to jako dług techniczny, nie zostawiaj bez świadomości.
- `img-src`/`media-src` muszą wskazywać dokładną domenę R2/CDN, z której serwujesz zdjęcia — nie `*`.
- Wdrażaj CSP najpierw w trybie **`Content-Security-Policy-Report-Only`** przez tydzień, zbierając raporty (endpoint `report-uri`/`report-to`), zanim przełączysz na tryb wymuszający — inaczej ryzykujesz ukrytą awarię UI wykrytą dopiero przez użytkowników.

### Pozostałe nagłówki

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()
X-Frame-Options: DENY
```

- **HSTS**: włącz dopiero, gdy HTTPS jest w pełni stabilny na wszystkich subdomenach (w tym ewentualne subdomeny CDN) — `preload` dodawaj świadomie, bo wpis do listy preload jest praktycznie nieodwracalny w krótkim terminie.
- **Permissions-Policy**: Kuking nie potrzebuje geolokalizacji, mikrofonu, kamery ani płatności w MVP — zablokuj je wszystkie domyślnie; odblokuj pojedynczo, gdy faktyczna funkcja tego wymaga (np. przyszły upload zdjęcia z kamery może wymagać `camera=(self)`).
- `X-Frame-Options: DENY` jest redundantny wobec `frame-ancestors 'none'` w CSP, ale zostaw dla przeglądarek/proxy nierespektujących CSP.

---

## 2. Sesje, cookies, wylogowanie, 2FA

- **Cookies sesji:** `secure=true`, `httponly=true`, `samesite=lax` (nie `strict`, bo `strict` łamie powrót z linków e-mail typu reset hasła w niektórych przeglądarkach) — w `config/session.php`.
- **Rotacja ID sesji** przy logowaniu i przy zmianie uprawnień (Laravel robi to domyślnie przez `Auth::login()` + regenerację sesji — upewnij się, że nic w kodzie nie wyłącza tego zachowania).
- **Wylogowanie z innych urządzeń:** Laravel udostępnia `Auth::logoutOtherDevices($password)` — wystaw to jako opcję w ustawieniach konta ("Wyloguj wszystkie inne urządzenia"), wymagaj ponownego podania hasła przy tej akcji.
- **Invalidacja sesji przy zmianie hasła:** wymuś wylogowanie wszędzie poza bieżącym urządzeniem automatycznie przy zmianie hasła (nie tylko jako opcja) — to standard, nie luksus.
- **2FA — kiedy:**
  - **Obowiązkowe dla kont administracyjnych/moderatorskich** od dnia startu — to konta z realną władzą nad treścią i danymi innych osób.
  - **Opcjonalne dla zwykłych użytkowników** w MVP (TOTP, np. `pragmarx/google2fa-laravel` lub wbudowane wsparcie Fortify) — nie blokuj startu na to, ale zostaw to w roadmapie V1, zwłaszcza gdy pojawią się konta z większym zasięgiem (popularni twórcy przepisów = częstszy cel przejęcia konta).

---

## 3. Hasła, reset, enumeracja kont

- **Hashing:** domyślny `bcrypt` Laravela jest wystarczający; rozważ `argon2id` (`config/hashing.php`, driver `argon`) jeśli chcesz wyższy koszt obliczeniowy — dla MVP `bcrypt` z rounds ≥ 12 jest akceptowalny.
- **Minimalna długość hasła:** **min. 10 znaków** (nie wymagaj sztucznej kombinacji wielkich liter/cyfr/znaków specjalnych — to zniechęca użytkowników 50+ i nie poprawia realnego bezpieczeństwa; długość > złożoność).
- **Sprawdzanie wycieków — `Password::uncompromised()`:** użyj reguły walidacji Laravela opartej o Have I Been Pwned k-anonymity API przy rejestracji i zmianie hasła:
  ```php
  use Illuminate\Validation\Rules\Password;

  Password::min(10)->uncompromised();
  ```
  To realny, tani do wdrożenia i skuteczny mechanizm — nie pomijaj go.
- **Reset hasła:** token jednorazowy, ważny **max 60 minut**, unieważniany po użyciu; link resetu **nie może** zawierać samego adresu e-mail w URL bez tokenu.
- **Enumeracja kont — świadoma decyzja:**
  - Formularz "zapomniałem hasła" **zawsze** wyświetla ten sam komunikat ("jeśli konto istnieje, wysłaliśmy e-mail") niezależnie od tego, czy e-mail istnieje w bazie.
  - Formularz rejestracji **może** ujawniać "ten e-mail jest już zajęty" — to typowy kompromis UX vs. bezpieczeństwo; dla Kuking (serwis społecznościowy, nie bankowość) akceptowalne jest zachowanie standardowego UX rejestracji, ale **dodaj rate-limit** na sprawdzanie dostępności e-maila, żeby uniemożliwić masowe skanowanie bazy.
  - Komunikat logowania: "nieprawidłowy e-mail lub hasło" (nigdy nie mów osobno "zły e-mail" vs "złe hasło").

---

## 4. Rate limity per endpoint

Propozycja konkretnych limitów (Laravel `RateLimiter::for()` w `AppServiceProvider`/`bootstrap`, per IP i per konto tam gdzie ma to sens):

| Endpoint | Limit | Uwaga |
|---|---|---|
| Logowanie | 5 prób / 15 min / IP + konto (trzy koszyki, `login_limits`) | Po przekroczeniu: czasowy lockout konta (nie trwały). **Cloudflare Turnstile stoi na tym formularzu ZAWSZE, nie „po przekroczeniu"** — D-050. Captcha i limity to dwie różne obrony i działają obok siebie: limity widzą atak rozproszony po adresach, captcha widzi automat w przeglądarce. |
| Rejestracja | 5 kont / godzinę / IP; 20 / dzień / IP | Chroni przed masowym zakładaniem kont-botów |
| Reset hasła (żądanie) | 3 / godzinę / IP + e-mail | Zapobiega spamowaniu skrzynki ofiary |
| Upload zdjęcia | 30 / godzinę / konto (nowe konto <7 dni: 10/godzinę) | Ogranicza koszt storage/processing przy nadużyciu |
| Komentarz | 1 / 10 sekund, max 20/godzinę dla konta <7 dni | Zgodnie z `MODERATION_PLAYBOOK.md` sekcja spam |
| Zgłoszenie treści (report) | 20 / dzień / konto | Zapobiega zalewaniu kolejki moderacji |
| Wyszukiwanie (search) | 60 / minutę / IP | Chroni bazę przed nadmiernym obciążeniem `pg_trgm` przy skanowaniu |
| Publikacja posta/przepisu | bez sztywnego limitu bazowego, ale throttle przy nietypowym wzroście częstotliwości (np. >10/godzinę dla konta <30 dni → oznacz do przeglądu) | Nie karać aktywnych, prawdziwych użytkowników |

Wszystkie limity: zwracaj `429 Too Many Requests` z nagłówkiem `Retry-After`, nie generyczny błąd 500.

### Cloudflare Turnstile (D-050, issue #217)

Stan **faktyczny**, nie plan. Turnstile w trybie Managed stoi na sześciu
formularzach publicznych: `/register`, `/login`, `/nie-pamietam-hasla`,
`/cofnij-usuniecie-konta`, `/napisz-do-nas`, `/zglos-nielegalna-tresc`.

Dwie rzeczy, które trzeba czytać razem z tabelą wyżej, żeby nie wyciągnąć
z niej fałszywego wniosku o poziomie ochrony:

1. **Turnstile NIE ZASTĘPUJE limitów zapytań** i nie pozwala ich poluzować.
   Zestaw z tabeli obowiązuje bez zmian.
2. **Brak tokenu (wyłączony JavaScript) NIE BLOKUJE wysłania formularza** —
   `AGENTS.md` §5. Odrzucany jest wyłącznie token, który przyszedł i którego
   Cloudflare nie uznał; niedostępność Cloudflare również przepuszcza.
   Turnstile jest więc **filtrem taniego ruchu automatycznego, nie bramką
   dostępu**, i tak trzeba go liczyć w każdej ocenie ryzyka. Pełne
   uzasadnienie i droga wycofania: `docs/DECISIONS.md` D-050.

---

## 5. Upload zdjęć — pipeline bezpieczeństwa

Zgodnie z założeniami z `docs/MEDIA_PIPELINE.md` (flow: signed upload → background job → validation → EXIF strip → resize → moderation → ready), konkretne wymagania bezpieczeństwa na każdym etapie:

### 5.1 Walidacja pliku (nie ufaj rozszerzeniu ani nagłówkowi `Content-Type` z klienta)

- **Magic bytes:** sprawdź rzeczywisty typ pliku po pierwszych bajtach (np. przez `finfo_file()` / bibliotekę typu `league/mime-type-detection`, której Laravel/Flysystem i tak używa wewnętrznie) — porównaj z deklarowanym rozszerzeniem i odrzuć niezgodność.
- **Dozwolone typy:** whitelist (`image/jpeg`, `image/png`, `image/webp`, `image/avif`) — **nigdy** blacklist.
  Lista jest w `config/kuking.php` → `media.accepted_mime_types` i musi zawierać
  wyłącznie formaty, które NAPRAWDĘ umiemy otworzyć, a nie te, które umiemy
  rozpoznać. HEIC/HEIF były tu kiedyś, bo rozpoznaje je `mime_content_type()` —
  ale `getimagesize()` ich nie czyta (PHP nie ma `IMAGETYPE_HEIC`), a GD nie
  dekoduje. Efektem była obietnica bez pokrycia. Pilnuje tego test
  `ObiecujemyTylkoFormatyKtoreUmiemyTest`.
- **Limit rozmiaru pliku:** np. 15 MB per zdjęcie (dopasuj do realnych potrzeb telefonów użytkowników 50+, które często robią duże zdjęcia).
- **Limit megapikseli (decompression bomb):** sprawdź wymiary **przed** pełnym dekodowaniem obrazu (np. z nagłówków pliku, nie ładując całego bitmapa do pamięci) i odrzuć obrazy powyżej rozsądnego limitu (np. 40–50 megapikseli) — to chroni przed atakiem typu "mały plik, gigantyczny rozpakowany bitmap", który potrafi zjeść całą pamięć procesu przetwarzającego.
- **Czy plik faktycznie się dekoduje:** spróbuj dekodować obraz biblioteką przetwarzania (np. Intervention Image / Imagick) w izolowanym procesie/joblu — błąd dekodowania = odrzucenie, nie próba "naprawy" pliku.
- **Checksum (SHA-256):** licz i zapisuj (`media.checksum_sha256` już w schemacie) — przydatne do deduplikacji i do potencjalnego porównania z bazami znanych złośliwych/nielegalnych plików w przyszłości.

### 5.2 Nie ufaj oryginałowi — generuj nowy plik

- **Nigdy nie serwuj publicznie oryginalnego, przesłanego przez użytkownika pliku bez przetworzenia.** Zawsze re-enkoduj obraz od zera (dekoduj do surowego bitmapa, potem enkoduj na nowo do docelowego formatu/rozmiaru) — to jedyny skuteczny sposób na pozbycie się złośliwych payloadów ukrytych w metadanych/strukturze pliku (steganografia, exploit w parserze konkretnego formatu, polyglot files).
- Warianty do wygenerowania (zgodnie z `MEDIA_PIPELINE.md`): thumb 320px, feed 960px, large 1600px, w WebP/AVIF z fallbackiem JPEG.
- **Oryginał** (jeśli w ogóle przechowywany do reprocessingu) trzymaj w **prywatnym** buckecie/prefiksie, niedostępnym publicznie, z krótkim okresem retencji zgodnym z polityką (patrz `COMPLIANCE.md` retencja).

### 5.3 Strip EXIF/GPS

- Usuwaj **cały** blok EXIF przy re-enkodowaniu (Intervention Image / Imagick usuwają metadane domyślnie przy re-save, ale **zweryfikuj to jawnym testem** — nie zakładaj domyślnego zachowania biblioteki bez sprawdzenia).
- Szczególnie krytyczne: **GPS** (lokalizacja domu użytkownika przy zdjęciu z kuchni) i **dane urządzenia** (model telefonu, czasem numer seryjny obiektywu) — to bezpośrednie ryzyko prywatności dla grupy 50+, która rzadko wie, że zdjęcie z telefonu zawiera współrzędne.
- Zachowaj tylko to, co jest jawnie potrzebne produktowo (np. orientację EXIF **przed** re-enkodowaniem, żeby zdjęcie nie wyszło obrócone — potem i tak usuwasz resztę metadanych).

### 5.4 Nazwy obiektów i brak wykonywalnych ścieżek

- **Nigdy** nie używaj oryginalnej nazwy pliku od użytkownika jako klucza obiektu w S3/R2 (`object_key`) — generuj losowy identyfikator (UUID, zgodnie ze schematem `media.id`) + rozszerzenie wymuszone przez wykryty rzeczywisty typ MIME, nie przez nazwę pliku.
- Struktura klucza obiektu: prefiks logiczny (np. `media/{yyyy}/{mm}/{uuid}.webp`) — bez segmentów pochodzących bezpośrednio od danych użytkownika (unikaj path traversal przez np. `../../` w nazwie pliku wpisanej do klucza).
- Upewnij się, że bucket/CDN serwujący zdjęcia **nie wykonuje** żadnego kodu po stronie serwera (czysty static file serving) — R2/S3 są z natury bezpieczne pod tym względem, ale zweryfikuj konfigurację Cloudflare (Workers/Functions podpięte pod tę samą domenę mogłyby to zmienić).

### 5.5 Signed URL

- Upload: generuj **presigned PUT URL** z krótkim czasem ważności (np. 5–15 minut), ograniczony do konkretnego `object_key`, `Content-Type` i maksymalnego rozmiaru (S3/R2 wspiera `Content-Length-Range` w policy).
- Odczyt (jeśli treść prywatna, np. `visibility=private`): presigned GET URL z krótkim czasem ważności (np. kilka minut do godziny) zamiast trwale publicznego URL — dla treści `public` zwykły publiczny URL przez CDN jest OK i wydajniejszy.
- Po stronie backendu: endpoint "prepare upload" **musi** weryfikować autoryzację użytkownika i limity (rate limit z sekcji 4) **przed** wygenerowaniem signed URL — sam signed URL nie jest miejscem kontroli dostępu.

### 5.6 Ochrona przed SSRF przy importach (V2 — import z URL/zdjęcia)

Gdy pojawi się import przepisu z zewnętrznego URL (`FEATURES.md` V2):
- **Nie pozwalaj** backendowi na dowolne żądania HTTP do adresu podanego przez użytkownika bez zabezpieczeń — klasyczny SSRF pozwala zaatakować wewnętrzną sieć (np. metadata endpoint Railway/chmury, `169.254.169.254`, wewnętrzne usługi).
- Wymagane zabezpieczenia: whitelist protokołów (`http`/`https` tylko), **rozwiązywanie DNS i blokowanie adresów prywatnych/loopback/link-local** (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16, oraz analogiczne zakresy IPv6) **po** rozwiązaniu DNS (nie tylko sprawdzenie samego stringa URL — DNS rebinding omija taką naiwną kontrolę), timeout, limit rozmiaru pobieranej odpowiedzi, wykonywanie żądania z izolowanego workera bez dostępu do wewnętrznej sieci produkcyjnej.

---

## 6. Autoryzacja

- **Policy dla każdego modelu z właścicielem/widocznością:** `Post`, `Recipe`, `Media`, `CookedEvent`, `Collection` (i każdy kolejny model UGC) — jawna `PostPolicy`, `RecipePolicy` itd., rejestrowane w `AuthServiceProvider`, wywoływane przez `$this->authorize()` w każdym kontrolerze/akcji Livewire, **nigdy** poleganie tylko na warunku w widoku (Blade `@can` chowa przycisk, ale nie chroni endpointu — to tylko UX, nie bezpieczeństwo).
- **IDOR (Insecure Direct Object Reference):** każdy endpoint przyjmujący ID zasobu (np. `/recipes/{recipe}/edit`) musi przejść przez Policy sprawdzającą, czy zalogowany użytkownik jest właścicielem/ma prawo do akcji — **niezależnie od tego, czy ID jest zgadywalne czy nie**.
- **Dlaczego UUID w URL nie zastępuje autoryzacji:** UUID (np. `recipes/3f2a...`) chroni przed **enumeracją** (nie da się łatwo zgadnąć ID kolejnego rekordu, jak przy autoincrement), ale **nie chroni przed IDOR** — jeśli ktoś zna/przechwyci konkretny UUID (np. z linku udostępnionego, logów, historii przeglądarki na współdzielonym komputerze, lub po prostu z publicznego URL-a przepisu), a endpoint nie sprawdza właściciela, atakujący i tak wykona akcję. UUID to **obfuskacja**, nie **autoryzacja** — te dwie rzeczy trzeba wdrożyć osobno, i to Policy jest tą właściwą warstwą.
- Wizualne domyślne testy: dla każdego nowego endpointu zapisz test "użytkownik B nie może edytować/usunąć zasobu użytkownika A" — to tani, wysoko-wartościowy test regresyjny.

---

## 7. Audit log

### Co logować

- Logowania (udane i nieudane), zmiana hasła, zmiana e-maila, włączenie/wyłączenie 2FA.
- Akcje moderacyjne: kto, co, kiedy, jaka decyzja, jakie uzasadnienie (wymagane też przez DSA Art. 17 — patrz `COMPLIANCE.md`).
- Zmiany uprawnień/ról (np. nadanie roli moderatora/admina).
- Usunięcie konta / żądanie eksportu danych (dla dowodu realizacji praw RODO).
- Nietypowe wzorce: masowe pobieranie danych, nagły wzrost częstotliwości akcji z jednego konta/IP.

### Czego NIE logować

- **Haseł** — nigdy, w żadnej formie, nawet zahashowanej w logu ogólnym (hash trzymaj tylko w tabeli `users`, nie duplikuj w logach).
- **Pełnych tokenów sesji/API/reset hasła** — jeśli musisz logować fakt użycia tokenu, loguj jego hash lub prefiks, nie wartość w całości.
- **Treści prywatnych wiadomości** (jeśli/gdy pojawi się DM w V1+) — loguj metadane zdarzenia (kto, kiedy, do kogo), nie treść.
- **Pełnych danych karty płatniczej** (nieaktualne w MVP bez płatności, ale zasada na przyszłość — nigdy nie przechodzi przez Twoje logi, obsługuje to wyłącznie procesor płatności zgodny z PCI-DSS).
- **Zbędnego PII w logach błędów aplikacji (Sentry)** — skonfiguruj `before_send` / data scrubbing, żeby usuwać e-maile, hasła, nagłówki `Authorization`/`Cookie` z eventów wysyłanych do Sentry **przed** wysyłką, nie polegaj tylko na domyślnych ustawieniach.

---

## 8. Backupy i restore drill (procedura do przejścia w 30 minut)

### Backupy

- Automatyczne, codzienne kopie zapasowe bazy PostgreSQL (Railway ma wbudowane backupy — zweryfikuj częstotliwość i retencję w planie, jakiego używacie).
- Backup przechowywany **poza** tym samym środowiskiem produkcyjnym (np. eksport do osobnego, kontrolowanego storage) — nie polegaj wyłącznie na jednym mechanizmie dostawcy hostingu.
- Backupy zdjęć: R2/S3 ma wersjonowanie obiektów — rozważ włączenie (chroni przed przypadkowym nadpisaniem/usunięciem), z rozsądnym limitem czasu retencji starych wersji.
- Backup **musi** być zaszyfrowany at-rest (domyślne u większości dostawców, ale zweryfikuj) i dostęp do niego ograniczony (nie ten sam zestaw uprawnień co codzienny dostęp deweloperski).

### Restore drill — konkretna procedura (cel: 30 minut, wykonywana cyklicznie, np. raz na kwartał)

1. **[0–5 min]** Wybierz najnowszy dostępny backup bazy danych (nie testowy — realny, "wczorajszy").
2. **[5–15 min]** Przywróć backup do **osobnego, izolowanego środowiska** (np. tymczasowa instancja Railway/lokalny kontener Postgres) — **nigdy nie testuj restore na środowisku produkcyjnym**.
3. **[15–20 min]** Uruchom podstawowy zestaw sprawdzeń: liczba rekordów w kluczowych tabelach (`users`, `posts`, `recipes`) zgadza się z oczekiwaniem, losowy rekord użytkownika ma poprawne dane, aplikacja startuje i łączy się z przywróconą bazą bez błędów migracji.
4. **[20–25 min]** Sprawdź, czy przywrócona baza poprawnie odwołuje się do storage zdjęć (czy `object_key` z bazy odpowiada realnie istniejącym obiektom w R2 z tego okresu — częsty błąd: baza i storage backupowane w różnym rytmie, co po realnym incydencie ujawnia się jako "martwe" odnośniki do zdjęć).
5. **[25–30 min]** Zapisz wynik ćwiczenia (data, backup użyty, co zadziałało, co nie) — **jeśli coś nie zadziałało, to jest właśnie powód, dla którego robi się to ćwiczenie zanim zdarzy się prawdziwy incydent**, nie odkładaj naprawy.

**Zasada:** jeśli restore drill nigdy nie został przetestowany, **nie masz backupu — masz tylko nadzieję, że backup działa.**

---

## 9. Sekrety i CI

- Wszystkie sekrety (klucze API, dane dostępowe do bazy, klucze podpisujące) w **zmiennych środowiskowych** (Railway variables), **nigdy** w repozytorium (w tym w historii commitów — jeśli sekret trafi do gita, rotacja klucza jest obowiązkowa, samo usunięcie commitu nie wystarcza).
- `.env.example` w repo zawiera tylko nazwy zmiennych, nigdy realne wartości.
- **Dependabot** (lub Renovate) włączony na repozytorium — automatyczne PR-y na aktualizacje zależności z podatnościami.
- **`composer audit`** uruchamiany w CI (GitHub Actions) na każdy PR i cyklicznie (np. cron tygodniowy) — buduj to jako krok blokujący merge przy krytycznych podatnościach, ostrzegawczy przy niższych.
- Rotacja kluczy dostępowych do R2/S3, Sentry, PostHog **co najmniej raz w roku** lub natychmiast po podejrzeniu wycieku/odejściu osoby z dostępem.
- Ograniczaj uprawnienia kluczy API do minimum potrzebnego (np. klucz R2 używany przez aplikację nie powinien mieć uprawnień do usuwania całego bucketa, jeśli aplikacja tego nie robi).

---

## 10. Odpowiedź na incydent — krótki runbook

1. **Wykrycie** — alert (Sentry, monitoring, zgłoszenie użytkownika) lub podejrzenie własne.
2. **Ocena wstępna (pierwsze 30 minut):** co się stało, czy dane osobowe są zagrożone/ujawnione, czy incydent trwa (aktywny atak) czy już się zakończył.
3. **Powstrzymanie:** jeśli atak trwa — odetnij dostęp (rotacja kluczy, wylogowanie sesji, wyłączenie zagrożonego endpointu/feature flag), zanim zaczniesz szczegółową analizę.
4. **Zabezpieczenie dowodów:** zrzuty logów, zrzut stanu bazy (jeśli to możliwe bez zakłócania działania) — przed czyszczeniem czegokolwiek.
5. **Ocena prawna (w ciągu godzin, nie dni):** czy to "naruszenie ochrony danych osobowych" w rozumieniu RODO wymagające zgłoszenia do UODO w ciągu 72h (patrz `COMPLIANCE.md` sekcja 2.7) — **licznik startuje od momentu, gdy się dowiedziałeś, nie od momentu wystąpienia incydentu**.
6. **Naprawa:** usunięcie przyczyny (patch, zmiana konfiguracji), weryfikacja że luka faktycznie zamknięta (nie tylko objaw).
7. **Komunikacja:** jeśli wymagane — zgłoszenie do UODO (szablon przygotowany z wyprzedzeniem, patrz `COMPLIANCE.md`), powiadomienie użytkowników jeśli wysokie ryzyko dla nich, transparentna, konkretna informacja (nie marketingowe "bezpieczeństwo jest dla nas priorytetem" bez treści).
8. **Post-mortem (w ciągu tygodnia):** co się stało, dlaczego, co zmieniamy żeby się nie powtórzyło — krótki, spisany dokument, nie tylko rozmowa w zespole.

---

## Źródła

- [Laravel 13 — Security documentation](https://laravel.com/docs/12.x/authentication) (dokumentacja oficjalna — zweryfikować sekcję dla wersji 13 po jej pełnym wydaniu)
- [Content Security Policy — MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy)
- [Alpine.js CSP build documentation](https://alpinejs.dev/advanced/csp)
- [Have I Been Pwned — Password API (k-anonymity)](https://haveibeenpwned.com/API/v3#PwnedPasswords)
- [OWASP — Server-Side Request Forgery Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server-Side_Request_Forgery_Prevention_Cheat_Sheet.html)
- [OWASP — Insecure Direct Object References (IDOR)](https://owasp.org/www-community/attacks/Insecure_Direct_Object_Reference)
- Kontekst prawny retencji logów i obowiązku zgłaszania naruszeń: `COMPLIANCE.md` (RODO Art. 33–34)
- Wewnętrzne źródło produktowe: `bp/kuking-platform/docs/MEDIA_PIPELINE.md`, `bp/kuking-platform/docs/SECURITY_PRIVACY_LEGAL.md`
