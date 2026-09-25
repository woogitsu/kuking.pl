# Rejestr czynności przetwarzania — art. 30 ust. 1 RODO

Stan: gałąź `robota/bramka-startowa`, od `main` = `534e0a51`, 20 września 2026.

**Skąd wzięła się treść tego dokumentu.** Każda czynność niżej jest
**wyprowadzona z kodu tego repozytorium**, nie z wyobraźni i nie z polityki
prywatności. Tam, gdzie twierdzenie stoi na pliku, plik jest wskazany.
Tam, gdzie czegoś z kodu wyprowadzić się nie da — dane rejestrowe,
inspektor ochrony danych, umowy — stoi jawne
`DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:`. Pole zmyślone byłoby tu gorsze niż
puste: rejestr pokazuje się organowi, a nie sobie.

**To nie jest porada prawna.** Przed pokazaniem tego dokumentu komukolwiek
z zewnątrz powinien go przeczytać prawnik — w szczególności kolumnę
„podstawa prawna", bo to jedyna kolumna, której kod nie rozstrzyga.

**Dlaczego rejestr jest obowiązkowy mimo jednoosobowej skali.** Zwolnienie
z art. 30 ust. 5 RODO obejmuje wyłącznie przetwarzanie *okazjonalne*,
bez ryzyka dla praw i wolności. Prowadzenie kont użytkowników serwisu
społecznościowego okazjonalne nie jest. Szerzej: `COMPLIANCE.md` §2.5.

---

## 1. Administrator i dane kontaktowe (art. 30 ust. 1 lit. a)

Wszystkie poniższe dane stoją w `config/kuking.php` (`kuking.podmiot`)
i są porównywane z treścią regulaminu i polityki przez
`DokumentyPrawneNieKlamiaTest::test_tozsamosc_administratora_zgadza_sie_z_konfiguracja`.

| Pole | Wartość |
|---|---|
| Administrator | SAMSUFI Spółka z ograniczoną odpowiedzialnością |
| Adres | Jagiellońska 4A, 19-120 Knyszyn, Polska |
| KRS | 0000901262 |
| NIP | 5423435334 |
| REGON | 388971059 |
| Adres e-mail spółki | biuro@samsufi.pl |
| Adres kontaktowy serwisu | kontakt@kuking.pl (`config/kuking.php` → `kuking.community.contact_email`) |
| Serwis | Kuking.pl |

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:**

- **Przedstawiciel administratora** (art. 27 RODO) — nie dotyczy, jeżeli
  spółka ma siedzibę w Polsce; wpisać „nie dotyczy" albo dane, jeśli jednak
  jest.
- **Inspektor ochrony danych (IOD)** — czy został wyznaczony, a jeśli tak:
  imię, nazwisko i adres kontaktowy. Z kodu tego nie widać i nie da się
  wywnioskować. Jeśli IOD nie ma, właściwym wpisem jest **„nie wyznaczono,
  bo nie zachodzi żadna z przesłanek art. 37 ust. 1 RODO"** — ale ocena,
  czy nie zachodzi, należy do prawnika, nie do tego dokumentu.
- **Współadministrowanie** — czy z kimkolwiek zawarto umowę z art. 26 RODO.
  Z kodu widać jeden przypadek, w którym pojawia się **osobny**
  administrator (Meta przy logowaniu Facebookiem, §4), ale to nie jest
  współadministrowanie; patrz `REJESTR_UMOW_POWIERZENIA.md`.
- **Osoba, która utrzymuje ten rejestr** i data ostatniego przeglądu.

---

## 2. Kategorie osób, których dane dotyczą (art. 30 ust. 1 lit. c)

Kod zna dokładnie cztery kategorie. Trzecia i czwarta bywają pomijane
w rejestrach pisanych „z głowy", a obie są tu realne:

1. **Zarejestrowani użytkownicy serwisu** — osoby, które założyły konto.
2. **Osoby niezalogowane, które korzystają z formularzy publicznych** —
   „Napisz do nas" i zgłoszenie nielegalnej treści działają **bez konta**
   (`config/kuking.php`, uzasadnienie przy formularzu zgłoszenia: wymóg
   konta wykluczałby dokładnie tych, dla których formularz istnieje).
3. **Osoby trzecie widoczne w treściach publikowanych przez użytkowników** —
   ktoś na zdjęciu w tle, ktoś opisany w przepisie „po mamie". Serwis ich
   nie zbiera, ale je przetwarza, bo leżą w cudzej treści. Polityka
   prywatności mówi to wprost w §2.
4. **Osoby zgłaszające treści i osoby zgłaszane** — w sprawie moderacyjnej
   występują obie strony i obie są podmiotami danych.

---

## 3. Czynności przetwarzania (art. 30 ust. 1 lit. b, c, d, f)

Kolumna „termin usunięcia" podaje **to, co egzekwuje kod**, a nie to, co
byłoby ładne. Gdzie kod nie egzekwuje niczego, napisane jest, że nie
egzekwuje.

### 3.1 Prowadzenie konta i uwierzytelnianie

- **Cel:** założenie konta, wejście na nie, odzyskanie dostępu, potwierdzenie
  adresu e-mail, dwuetapowa weryfikacja.
- **Dane:** adres e-mail, hasło jako nieodwracalny skrót, status konta,
  ustawienia (język, skala tekstu, motyw), oświadczenie o wieku ≥ 16 lat
  (`app/Http/Controllers/Auth/RegisterController.php` — `age_confirmed`),
  data ostatniej wizyty.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — wykonanie umowy (regulamin).
- **Odbiorcy:** Railway (hosting i baza), EmailLabs (listy z potwierdzeniem
  i linkiem), Cloudflare Turnstile (ochrona formularzy — §3.9).
- **Termin usunięcia:** do usunięcia konta; po zgłoszeniu usunięcia konto
  czeka **30 dni** w stanie `pending_delete`
  (`config/kuking.php` → `delete_grace_days`), potem dane kasuje
  `kuking:usun-wygasle-konta`. Wygasłe żądania zmiany adresu e-mail kasuje
  `kuking:sprzataj-zmiany-adresu`, wygasłe zaproszenia —
  `kuking:sprzataj-zaproszenia`. Żetony resetu hasła (`password_reset_tokens`, klucz: adres
  e-mail) kasuje co noc `kuking:sprzataj-resety-hasel`, a przy wymazaniu konta —
  `EraseAccountData` (audyt B5 pkt 6).

### 3.2 Profil publiczny

- **Cel:** pokazanie użytkownika innym ludziom w serwisie.
- **Dane:** nazwa użytkownika, nazwa wyświetlana, opis, zdjęcie profilowe.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway, Cloudflare R2 (zdjęcie profilowe). Od D-240
  zdjęcie profilowe **nie** idzie do OpenAI — brak potwierdzonej zgody.
- **Termin usunięcia:** do zmiany przez użytkownika albo do usunięcia konta.

### 3.3 Publikowanie treści

- **Cel:** publikowanie wpisów, przepisów, komentarzy, „Ugotowałem"
  i zeszytów — to jest sama usługa.
- **Dane:** tekst treści, wcześniejsze wersje przepisu, powiązania między
  treściami; a także **wszystko, co użytkownik sam o sobie albo o kimś
  napisze** — łącznie z danymi, o które serwis nie pyta (dieta, zdrowie,
  osoby trzecie). Polityka prywatności §2 mówi o tym wprost.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway, OpenAI — tylko treść publiczna (§3.7).
- **Termin usunięcia:** do usunięcia treści albo konta. Przy usunięciu konta
  **decyduje użytkownik** (`users.delete_scope`, D-022): domyślnie tekst
  zostaje zanonimizowany („Użytkownik usunięty"), po zaznaczeniu haczyka
  jest kasowany razem z wpisami, przepisami, komentarzami, wykonaniami
  i zeszytami.

### 3.4 Zdjęcia

- **Cel:** publikowanie zdjęć potraw i zdjęć profilowych.
- **Dane:** piksele (mogą przedstawiać osoby, wnętrza, dokumenty);
  w oryginale także EXIF, w tym data i współrzędne GPS.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Cloudflare R2 (`config/filesystems.php`), OpenAI — ale
  **wyłącznie miniatura zdjęcia publicznego wpisu**, przekodowana, bez
  EXIF-u, najwyżej 320 px; zdjęcie profilowe nie (§3.7, D-240).
- **Termin usunięcia:** do usunięcia zdjęcia przez użytkownika; **przy
  usunięciu konta kasowane są WSZYSTKIE**, razem z cache CDN-u (D-018) —
  bo anonimizacja podpisu nie zmienia niczego w pikselach. Zdjęcia
  nieprzypięte do żadnej treści kasuje `kuking:sprzataj-osierocone-zdjecia`.

### 3.5 Relacje w serwisie

- **Cel:** obserwowanie i blokowanie innych użytkowników, obserwowanie tagów.
- **Dane:** identyfikator obserwującego i obserwowanego, przy blokadzie —
  adres IP osoby blokującej (`app/Http/Controllers/SocialController.php`).
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** do usunięcia relacji albo konta.

### 3.6 Moderacja treści, zgłoszenia i odwołania (DSA)

- **Cel:** przyjęcie zgłoszenia nielegalnej treści (art. 16 DSA), decyzja
  moderatora z uzasadnieniem (art. 17 DSA), rozpatrzenie odwołania.
- **Dane:** dane zgłaszającego (także bez konta — wtedy sam adres e-mail),
  dane zgłaszanego, treść zgłoszenia, powód, decyzja, uzasadnienie,
  odwołanie i jego wynik.
- **Podstawa:** art. 6 ust. 1 lit. c RODO (obowiązek prawny z DSA)
  oraz art. 6 ust. 1 lit. f RODO (bezpieczeństwo platformy). Przeniesienie
  podstawy retencji na lit. f jest decyzją właściciela po zewnętrznej
  ocenie prawnej — `docs/decyzje/ADR_RETENCJE.md` §10.
- **Odbiorcy:** Railway, EmailLabs (potwierdzenie przyjęcia i informacja
  o decyzji idą e-mailem), Cloudflare Turnstile (formularz zgłoszenia).
- **Termin usunięcia:** **36 miesięcy od zamknięcia sprawy**
  (`config/kuking.php` → `moderation.case_retention_months`), egzekwuje
  `kuking:sprzataj-sprawy-moderacyjne`. Sprawy otwarte nie są kasowane
  niezależnie od wieku.

### 3.7 Automatyczna wstępna ocena treści (OpenAI) — przekazanie poza EOG

- **Cel:** podniesienie do kolejki moderatora treści, które mogą dotyczyć
  przemocy, nienawiści, treści seksualnych albo samookaleczenia — po to,
  żeby jedyny moderator zobaczył je szybciej.
- **Dane, które faktycznie wychodzą** (sprawdzone w kodzie,
  `app/Moderacja/KlientOpenAI.php`): **wyłącznie oceniana treść** —
  tekst wpisu albo komentarza (przycięty do 8000 znaków, `ocenTekst()`)
  albo zdjęcie wpisu jako `data:` URI z **wariantu przekodowanego**, czyli bez
  EXIF-u i bez GPS-u (`ocenObraz()`), o dłuższym boku najwyżej 320 px
  zmierzonym z bajtów. Wychodzi **wyłącznie treść publiczna** — widoczna
  dla gościa bez konta w chwili wysyłki (`app/Moderacja/GranicaWysylki.php`).
  **Zdjęcie profilowe nie wychodzi** (D-240). Żądanie niesie dwa pola: `model`
  i `input`. **Nie wychodzi** adres e-mail, nazwa konta, identyfikator
  wpisu ani adres IP — kod nie ma gdzie ich wpisać, bo `zapytaj()` buduje
  ciało żądania wyłącznie z przekazanej treści.
- **Czego ten kanał nie robi:** wynik nie ukrywa treści, nie ogranicza jej
  zasięgu i nie blokuje konta — trafia wyłącznie do kolejki człowieka.
  To nie jest więc zautomatyzowane podejmowanie decyzji w rozumieniu
  art. 22 RODO. **Ta kwalifikacja jest do potwierdzenia przez prawnika.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO — uzasadniony interes
  w bezpieczeństwie serwisu.
- **Odbiorca:** OpenAI, L.L.C. (Stany Zjednoczone).
- **Podstawa przekazania poza EOG:** polityka prywatności wskazuje
  uczestnictwo OpenAI, L.L.C. w **EU-US Data Privacy Framework** oraz
  standardowe klauzule umowne. **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:**
  data sprawdzenia wpisu na liście `dataprivacyframework.gov` i numer/data
  zawartych SCC — z kodu tego nie widać i widać nie będzie.
- **Termin usunięcia:** po stronie OpenAI, zgodnie z jego warunkami usługi.
  **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** deklarowany okres przechowywania
  treści przekazanych do API moderacji, odczytany z aktualnych warunków.
- **Wyłączenie jest możliwe bez zmiany kodu:** brak klucza
  (`kuking.moderation.model.klucz`) znaczy, że funkcja nie działa
  i **żadne żądanie nie wychodzi** (`KlientOpenAI::oceniamy()`).

### 3.8 Logowanie kontem Google i kontem Facebooka

- **Cel:** dodatkowa, dobrowolna droga wejścia na konto obok hasła i linku.
- **Dane:** od Google — potwierdzenie tożsamości, adres e-mail razem
  z informacją, czy jest potwierdzony, oraz imię; od Facebooka —
  identyfikator konta (inny dla każdego serwisu), imię i adres e-mail,
  **bez informacji, czy adres jest potwierdzony**, a bywa, że bez adresu
  w ogóle. W bazie zostaje identyfikator zewnętrzny i data połączenia
  (tabela `tozsamosci_zewnetrzne`). Tokenów dostępu serwis nie
  przechowuje.
- **Kod:** `app/Http/Controllers/Auth/GoogleLoginController.php`,
  `app/Http/Controllers/Auth/FacebookLoginController.php`,
  `app/Http/Controllers/Auth/FacebookDeauthorizeController.php`.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — wykonanie umowy na żądanie
  osoby, która tę drogę wybrała.
- **Odbiorcy:** Google Ireland Limited / Google LLC; Meta Platforms Ireland
  Limited. **Meta występuje tu jako OSOBNY ADMINISTRATOR**, nie jako
  podmiot przetwarzający — przetwarza dane użytkownika Facebooka na
  własnych zasadach i własną odpowiedzialność.
- **Przekazanie poza EOG:** przy Google — grupa Google przetwarza dane
  także w USA, podstawą jest DPF i SCC. Przy Meta **administrator nie
  przekazuje danych poza EOG**: kontrahentem jest spółka irlandzka,
  a dalsze przetwarzanie w grupie Meta dzieje się na jej własnych
  podstawach, nie na zlecenie Kuking.
- **Termin usunięcia:** do usunięcia konta albo rozłączenia powiązania.

### 3.9 Ochrona formularzy przed automatami (Cloudflare Turnstile)

- **Cel:** odróżnienie człowieka od automatu przy **siedmiu** publicznych
  formularzach: rejestracja, logowanie, link do zalogowania, odzyskiwanie
  hasła, cofnięcie usunięcia konta, „Napisz do nas", zgłoszenie
  nielegalnej treści (`config/kuking.php` → `turnstile.miejsca`; pilnuje
  `RozjazdyAudytuZgodnosciTest::test_polityka_wymienia_kazdy_formularz_za_turnstile`).
- **Dane:** adres IP i techniczne cechy przeglądarki. **Treść formularza
  ani adres e-mail do Cloudflare nie idą.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO — uzasadniony interes w obronie
  przed zakładaniem kont automatem.
- **Odbiorca:** Cloudflare, Inc. (USA) — przekazanie poza EOG, podstawą
  DPF i SCC.
- **Termin usunięcia:** po stronie Cloudflare; serwis nie trzyma kopii.
- **Wyłączenie jest możliwe bez zmiany kodu:** puste klucze znaczą, że
  widget się nie renderuje i nikt nikogo nie odpytuje.

### 3.10 Bezpieczeństwo i dziennik zdarzeń

- **Cel:** wykrywanie nadużyć, próby logowania, dowód wykonania żądań
  usunięcia konta.
- **Dane:** **skrót** adresu IP (samego adresu w bazie nie ma; skrót liczony
  z kluczem żyjącym poza bazą), znacznik czasu, typ zdarzenia.
- **Podstawa:** art. 6 ust. 1 lit. f RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** **12 miesięcy**
  (`config/kuking.php` → `audit_log.retention_months`), egzekwuje
  `kuking:sprzataj-audyt`. **Wyjątek trwały:** wpisy dokumentujące złożenie,
  cofnięcie albo wykonanie żądania usunięcia konta zostają na stałe — są
  dowodem, że usunięcie się odbyło.

### 3.11 Powiadomienia w serwisie

- **Cel:** poinformowanie o zdarzeniach dotyczących użytkownika.
- **Dane:** treść powiadomienia, informacja o przeczytaniu.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** **3 miesiące**
  (`config/kuking.php` → `notifications.retention_months`), egzekwuje
  `kuking:sprzataj-powiadomienia`. **Wyjątek:** powiadomienia o decyzji
  moderacyjnej i o wyniku odwołania żyją do upływu terminu na odwołanie —
  co najmniej 6 miesięcy (regulamin §8). Skrócenie tego wyjątku odbierałoby
  prawo, które jeszcze przysługuje.

### 3.12 Poczta transakcyjna

- **Cel:** potwierdzenie adresu, przypomnienie hasła, link do zalogowania,
  powiadomienia e-mailem.
- **Dane:** adres e-mail odbiorcy, treść listu. **Dostawca dokłada od siebie
  obrazek liczący otwarcia** — moment otwarcia, adres IP i program pocztowy.
  Serwis tych danych nie odczytuje i nie używa; wyłącznik jest w panelu
  dostawcy, nie w kodzie.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorca:** EmailLabs (Vercom S.A., Poznań) — dane zostają w Polsce.
  Kod: `config/mail.php` (własny sterownik `emaillabs`),
  `app/Domain/Security/DziennyBudzetListow.php`.
- **Ślad nieudanego listu (`mail_failures`):** rodzaj listu, powód odmowy,
  zamaskowany komunikat, `user_id` odbiorcy — bez adresu i treści. Odhaczone
  ślady kasowane po `kuking.poczta.retencja_dni` (90) dniach przy kolejnym
  zapisie (`ZapiszNieudanyList`); przy wymazaniu konta `user_id` → `NULL`
  (audyt B5 pkt 9).
- **Termin usunięcia:** do usunięcia konta. **DO UZUPEŁNIENIA PRZEZ
  WŁAŚCICIELA:** jak długo EmailLabs trzyma logi wysyłek i otwarć —
  to jest okres po jego stronie i widać go tylko w umowie albo w panelu.

### 3.13 Tygodniowe podsumowanie e-mailem

- **Cel:** jeden list na tydzień z tym, co i tak widać w serwisie.
- **Dane:** adres e-mail, data wysłania ostatniego listu.
- **Podstawa:** **art. 6 ust. 1 lit. a RODO — zgoda.** Jedyna czynność
  w tym rejestrze oparta na zgodzie. Wycofanie: odnośnik na dole każdego
  listu, bez logowania i bez pytania o powód.
- **Odbiorcy:** Railway, EmailLabs.
- **Termin usunięcia:** data ostatniej wysyłki żyje tak długo jak konto
  (jedna nadpisywana wartość, nie historia). Treść listu nie jest
  archiwizowana.

### 3.14 Formularz „Napisz do nas"

- **Cel:** przyjęcie zgłoszenia usterki albo pytania i odpisanie na nie.
- **Dane:** treść wiadomości, adres e-mail (jeśli podany), adres strony
  w serwisie bez części po znaku zapytania, numer wydania serwisu,
  treść i data naszej odpowiedzi. **Adresu IP ani danych przeglądarki
  serwis tu nie zapisuje.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO; przy osobie z kontem także
  lit. b.
- **Odbiorcy:** Railway, EmailLabs, Cloudflare Turnstile.
- **Termin usunięcia:** **12 miesięcy od załatwienia sprawy**
  (`config/kuking.php` → `kontakt.retention_months`), egzekwuje
  `kuking:sprzataj-wiadomosci`. Wiadomości niezałatwione nie są kasowane
  w ogóle.

### 3.15 Statystyka odwiedzin (Cloudflare Web Analytics)

- **Cel:** wiedza, czy serwis komukolwiek się przydaje.
- **Dane:** adres otwieranej strony i adres odnośnika — **oba bez części po
  znaku zapytania**, rodzaj i wersja przeglądarki, czasy wczytania; kraj
  dolicza Cloudflare z samego połączenia. **Bez ciasteczek i bez zapisu
  na urządzeniu**; identyfikator odsłony losowany w pamięci na jedno
  wczytanie strony.
- **Kod:** `app/Support/AnalitykaCloudflare.php`; bezciasteczkowość pilnują
  `AnalitykaBezCiasteczekTest` i `WdrozenieAnalitykiOdwiedzinTest`.
- **Podstawa:** art. 6 ust. 1 lit. f RODO. Prawo komunikacji elektronicznej
  nie wchodzi w grę, bo nie ma ani zapisu, ani odczytu na urządzeniu
  (D-092, `COMPLIANCE.md` §5.5).
- **Odbiorca:** Cloudflare, Inc. (USA) — przekazanie poza EOG, DPF i SCC.
- **Termin usunięcia:** agregaty po stronie Cloudflare; serwis nie trzyma
  kopii.

### 3.16 Własne sygnały produktowe

- **Cel:** sprawdzenie, czy ludzie wracają i czy funkcje działają.
- **Dane:** zdarzenia korzystania z aplikacji, w miarę możliwości bez
  danych wskazujących wprost; osobno jedna nadpisywana data ostatniej
  wizyty na konto.
- **Podstawa:** art. 6 ust. 1 lit. f RODO.
- **Odbiorcy:** Railway. **Nikt poza nim** — te liczby powstają w naszej
  bazie i nigdzie nie wychodzą.
- **Termin usunięcia:** **90 dni**
  (`config/kuking.php` → `analytics.signal_retention_days`), egzekwuje
  `kuking:sprzataj-sygnaly`. Dane zbiorcze zostają dłużej.

### 3.17 Obsługa praw osób — eksport i usunięcie konta

- **Cel:** realizacja art. 15, 17 i 20 RODO.
- **Dane:** paczka z danymi użytkownika, zamówienie usunięcia konta,
  zakres usunięcia (`users.delete_scope`).
- **Podstawa:** art. 6 ust. 1 lit. c RODO — obowiązek prawny.
- **Odbiorcy:** Railway, Cloudflare R2 (paczka leży na dysku obiektowym).
- **Termin usunięcia:** paczka **7 dni**, kasuje `kuking:sprzataj-eksporty`
  (także plik próby, która padła przed zapisaniem paczki); przy wymazaniu
  konta znika od razu cały katalog paczek konta (audyt B5 pkt 4);
  konto po karencji 30 dni — `kuking:usun-wygasle-konta`.
- **Znane ograniczenie, opisane osobno:** paczka **nie zawiera** ośmiu
  kategorii danych, które serwis przechowuje (tożsamości zewnętrzne,
  wiadomości „Napisz do nas", zgłoszenia i decyzje moderacyjne, dziennik
  audytu, zdarzenia analityczne, wcześniejsze wersje przepisów, dziennik
  zgód, obserwowane tagi). Pełny wykaz i warianty rozwiązania:
  `DECYZJE_WLASCICIELA_R1_R6_DPA.md` §R1. To jest **otwarta decyzja
  właściciela**, nie stan docelowy.

### 3.18 Urodziny — życzenia od gospodarza (issue #1755)

- **Cel:** życzenia urodzinowe od gospodarza serwisu.
- **Dane:** dzień i miesiąc urodzin, **bez roku** (`users.birthday_day`,
  `users.birthday_month`).
- **Podstawa:** art. 6 ust. 1 lit. a RODO — zgoda wyrażona dobrowolnym
  podaniem daty; wycofanie przyciskiem „Usuń datę” w `/ustawienia/urodziny`.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** do usunięcia daty przez osobę albo do wymazania
  konta (`EraseAccountData` zeruje obie kolumny). W eksporcie: `konto.urodziny`.

---

## 4. Kategorie odbiorców (art. 30 ust. 1 lit. d)

Lista jest zweryfikowana wobec kodu (`COMPLIANCE.md` §7.2) i potwierdzona
wobec zmiennych środowiskowych usługi produkcyjnej
[pomiar cudzy: sesja prowadząca floty, Railway, 20.09.2026 — zmienne
`AWS_*`, `EMAILLABS_*`, `FACEBOOK_CLIENT_*`, `GOOGLE_CLIENT_*`,
`OPENAI_MODERATION_KEY`, `CLOUDFLARE_ANALYTICS_TOKEN`, `TURNSTILE_*`;
wartości zamaskowane, więc potwierdzony jest **fakt konfiguracji**, nie
treść kluczy]. Żaden odbiorca z tej listy nie jest martwy i żadnego nie
brakuje.

| Odbiorca | Rola | Co dostaje | Kraj |
|---|---|---|---|
| Railway | podmiot przetwarzający | cała aplikacja i baza | deklarowana UE — **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** region usługi odczytany z panelu |
| Cloudflare R2 | podmiot przetwarzający | zdjęcia i ich warianty, paczki eksportu | **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** lokalizacja bucketu; `AWS_DEFAULT_REGION` ma domyślnie `auto` |
| Cloudflare Turnstile | podmiot przetwarzający | adres IP i cechy przeglądarki przy siedmiu formularzach | USA |
| Cloudflare Web Analytics | podmiot przetwarzający | adres strony, odnośnik, rodzaj przeglądarki, czas wczytania | USA |
| OpenAI | podmiot przetwarzający | treść wpisu i pomniejszone zdjęcie, bez danych wskazujących osobę | USA |
| EmailLabs (Vercom S.A.) | podmiot przetwarzający | adres e-mail odbiorcy i treść listu | Polska |
| Google | podmiot przetwarzający przy logowaniu | potwierdzenie tożsamości, e-mail, imię | Irlandia / USA |
| Meta | **osobny administrator** | zakres po stronie Meta przy logowaniu Facebookiem | Irlandia (dalej w grupie Meta) |

**Odbiorcy, których NIE ma i nigdy nie było:** Sentry, PostHog, Google
Analytics, Matomo, Plausible. Wpisanie ich do rejestru byłoby deklaracją
przetwarzania, którego nie ma; pilnuje tego
`DokumentyPrawneNieKlamiaTest::test_dokument_wewnetrzny_nie_wymienia_narzedzi_ktorych_nie_uzywamy`.

**Odbiorcami nie są** organy publiczne, którym dane mogą zostać przekazane
w ramach konkretnego postępowania (art. 4 pkt 9 RODO) — w tym organy
ścigania przy ścieżce z art. 18 DSA (`MODERATION_PLAYBOOK.md` §7.1).

---

## 5. Przekazania do państw trzecich (art. 30 ust. 1 lit. e)

| Przekazanie | Co wychodzi | Deklarowana podstawa | Czego brakuje |
|---|---|---|---|
| OpenAI, L.L.C. (USA) | treść wpisu/komentarza i pomniejszone zdjęcie, bez EXIF-u i bez danych wskazujących osobę (`app/Moderacja/KlientOpenAI.php`) | EU-US Data Privacy Framework + standardowe klauzule umowne | **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** data sprawdzenia wpisu na liście DPF, dokument SCC i jego data |
| Cloudflare, Inc. (USA) — Turnstile i Web Analytics | adres IP i cechy przeglądarki; adresy stron | EU-US Data Privacy Framework + SCC | jw. |
| Google LLC (USA) — tylko przy logowaniu kontem Google | potwierdzenie tożsamości, e-mail, imię | EU-US Data Privacy Framework + SCC | jw. |

**Meta świadomie nie jest w tej tabeli.** Kontrahentem jest Meta Platforms
Ireland Limited z siedzibą w UE, więc **administrator nie dokonuje tu
przekazania poza EOG**. To, że grupa Meta przetwarza dane także w USA,
dzieje się na jej własnych podstawach, jako osobnego administratora.

**Najważniejsze zdanie tej sekcji:** z kodu widać, **co** wychodzi i **do
kogo**. Tego, **na jakiej podstawie**, z kodu nie widać nigdy — to jest
dokument w szafie, nie plik w repozytorium. Dlatego każdy wiersz ma kolumnę
„czego brakuje", i dlatego ta sekcja nie jest zamknięta.

---

## 6. Ogólny opis środków bezpieczeństwa (art. 30 ust. 1 lit. g)

Wszystkie poniższe są zmierzone w kodzie; szczegóły i uzasadnienia stoją
w `SECURITY_BASELINE.md`.

- **Hasła** przechowywane jako nieodwracalne skróty, nigdy jako tekst
  (polityka mówi to wprost, a `DokumentyPrawneNieKlamiaTest::test_nie_mowimy_ze_haslo_jest_zaszyfrowane`
  pilnuje, żeby dokument nie nazywał tego szyfrowaniem).
- **Połączenie wyłącznie po HTTPS**; ciasteczko sesji na produkcji jest
  oznaczone `secure`, `httponly` i `samesite=lax`. Podstawą jest pomiar
  `https://kuking.pl/login` z 20 września 2026, a nie test w repozytorium:
  wartość `SESSION_SECURE_COOKIE` ustawiona jest w panelu dostawcy i z kodu
  jej nie widać. Domyślnik chroniący przed jej usunięciem nie został
  zatwierdzony — patrz gałąź `bramka-startowa`.
- **Adres IP w dzienniku audytu przechowywany jako skrót** liczony z kluczem
  żyjącym poza bazą — sam zrzut tabeli adresu nie oddaje.
- **EXIF i GPS zdejmowane ze zdjęć** przez przekodowanie
  (`SECURITY_BASELINE.md` §5.3); poza serwer wychodzi wyłącznie wariant
  przekodowany.
- **Autoryzacja przez Policy przy każdym wejściu** — UUID w adresie nie jest
  autoryzacją (`AGENTS.md`).
- **Ograniczenia liczby prób** przy logowaniu, rejestracji, odzyskiwaniu
  hasła i wysyłce poczty (`SECURITY_BASELINE.md` §4).
- **Ochrona formularzy publicznych** przez Cloudflare Turnstile (§3.9).
- **Kanał błędów nie wynosi danych osobowych** —
  `BladTrafiaNaWebhookBezDanychOsobowychTest`.
- **Automatyczne kasowanie po terminie** — dziesięć komend retencji,
  wymienionych przy poszczególnych czynnościach; ich istnienia pilnuje
  `DokumentyPrawneNieKlamiaTest::test_komendy_wymienione_w_procedurach_istnieja`.
- **Dostęp po stronie administratora ma jedna osoba** — ta, która prowadzi
  serwis; dwuetapowa weryfikacja obowiązkowa dla kont z uprawnieniami
  moderatora (`SECURITY_BASELINE.md` §2).
- **Kopie zapasowe bazy** — `scripts/kopia-lokalna.sh`,
  `kuking:sprawdz-kopie`.

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:**

- **Maksymalny czas życia kopii zapasowej** zawierającej dane osoby, która
  usunęła konto. To jest dziś jedyna luka w opisie retencji, o której
  wiadomo, że jest luką — `COMPLIANCE.md` §7.1 i §2.8. Bez tej liczby
  rejestr nie mówi, kiedy dane naprawdę znikają, tylko kiedy znikają
  z bazy roboczej.
- **Umowy powierzenia** z każdym podmiotem przetwarzającym — stan do
  odhaczenia: `REJESTR_UMOW_POWIERZENIA.md`.
- **Data ostatniego przeglądu tego rejestru** i osoba, która go zrobiła.

---

## 7. Czego ten rejestr świadomie nie rozstrzyga

1. **Czy podstawa prawna wpisana przy każdej czynności jest właściwa.**
   Kod pokazuje, co się dzieje; kwalifikacja prawna należy do prawnika.
2. **Czy potrzebna jest ocena skutków (DPIA).** `COMPLIANCE.md` §2.5 mówi,
   że prawdopodobnie nie dla podstawowego zakresu, i wskazuje dwa obszary
   do rozważenia — moderację i ewentualne skanowanie zdjęć.
3. **Czy trzeba wyznaczyć IOD.**
4. **Czy test równoważenia przy art. 6 ust. 1 lit. f wypada na naszą
   korzyść** w każdym z siedmiu miejsc, w których się na tę podstawę
   powołujemy. Testu równoważenia w repozytorium nie ma i nie powinno go
   pisać narzędzie, które sprawdza samo siebie.
