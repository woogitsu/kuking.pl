## D-092 · Analityka odwiedzin to Cloudflare Web Analytics — bo cena, a przy okazji żaden nowy dostawca i żaden nowy przepływ danych

**Data:** 10 września 2026 · **Decyzja właściciela; rozstrzygnęła CENA** · Status:
**obowiązuje** · Zastępuje pierwszą wersję tego wpisu (Plausible), niescaloną

> **O numerze.** Ten wpis miał już raz treść — kończył się wnioskiem
> „Plausible hostowany w UE". Właściciel tę wersję **odrzucił tego samego dnia**,
> zanim gałąź została scalona. Numer **zostaje D-092**, bo decyzja jest ta sama
> („jaka analityka odwiedzin"), tylko z inną odpowiedzią. Branie nowego numeru
> na zmienioną odpowiedź rozsypuje dziennik na dwa wpisy, z których jeden trzeba
> by czytać jako „nieaktualny" — a nic w kodzie nie powiedziałoby, który.

### Skąd to pytanie i dlaczego odpowiedź nie brzmi „Google Analytics"

Właściciel chciał wiedzieć dwie rzeczy, których nasza własna analityka nie
umie powiedzieć: **skąd ludzie przychodzą** i **które strony oglądają**.
`App\Domain\Analytics\*` (jedenaście klas) liczy zdarzenia, które powstają
W BAZIE — publikacje, „Ugotowałem", tygodniowe WAC. O kimś, kto wszedł na
stronę powitalną i wyszedł, nie wie nic i wiedzieć nie może. Pytanie było
więc dobre.

Kosztem Google Analytics są ciasteczka. A opublikowana polityka prywatności
mówi dziś użytkownikom dwie rzeczy, sprawdzone w kodzie przed tą decyzją
i wtedy prawdziwe:

> Statystyki liczymy sami, w naszej własnej bazie — **nie korzystamy z żadnego
> zewnętrznego narzędzia analitycznego** (ani Google Analytics, ani żadnego
> innego).

> **Nie używamy żadnych plików cookies do statystyk ani do reklam.** Dlatego
> nie pytamy Cię o zgodę na cookies i nie zasłaniamy serwisu banerem — nie ma
> na co jej udzielać.

Pierwsze zdanie i tak musiało się zmienić — każde zewnętrzne narzędzie je
łamie. Drugie **nie musiało**, i to jest cała treść tej decyzji. GA kazałoby
postawić baner zgody: dodatkową przeszkodę na wejściu, do klikania przez
osoby 50+, przy produkcie, którego całym założeniem jest, żeby nie stawiać
przeszkód. Zapłacilibyśmy banerem za odpowiedź, którą da się dostać za darmo.
**Google Analytics nie wraca do rozważenia** i nie wraca też PostHog (patrz
niżej, „Martwa deklaracja w AGENTS.md").

### Co rozstrzygnęło: cena, powiedziana wprost

Pierwsza wersja tej decyzji wybrała **Plausible** — hostowany w UE, bez
ciasteczek, 9 € miesięcznie. Właściciel odrzucił go po przedstawieniu kosztu,
jego słowami:

> „Szkoda mi 9 eur miesięcznie na takie coś… Wolę w coś innego zainwestować"

To jest **prawdziwy powód tej zmiany i dlatego stoi tu wprost**, a nie
przebrany w argument techniczny. Dziennik decyzji, który ukrywa, że o czymś
zdecydowała cena, jest gorszy niż brak wpisu: następny agent szukałby
technicznej wady Plausible, której nie ma.

### Co rozstrzygnęło drugi raz: Cloudflare już tu jest

Po przedstawieniu alternatyw właściciel wybrał **Cloudflare Web Analytics**
(darmowy beacon JS). Rozstrzygnął argument, którego pierwsza wersja tego wpisu
nie miała, bo nie było wtedy powodu go szukać:

**Cloudflare przetwarza już KAŻDE żądanie do kuking.pl.** Jest naszym DNS-em,
CDN-em i WAF-em przed Railwayem (`docs/infra/INFRA_DECISION.md`: „DNS dla
`kuking.pl`, CDN/WAF przed Railway"), i właśnie dlatego `bootstrap/app.php`
ma ustawione zaufane proxy — bez tego „Laravel widzi IP Cloudflare zamiast
użytkownika". Włączenie analityki tej samej firmy **nie wysyła jej ani jednego
nowego bajta**: pokazuje nam to, co ona i tak obsługuje.

Z tego wynika rzecz, która jest połową wartości tej zmiany i której nie dawał
żaden inny kandydat: **w polityce prywatności nie doszedł ani nowy dostawca,
ani trzeci akapit o przekazywaniu danych poza EOG.** Cloudflare, Inc. (USA)
stoi tam od Turnstile'a (D-050) — w tabeli dostawców i w akapicie o transferze,
z podstawą **EU-US Data Privacy Framework** plus standardowe klauzule umowne.
Polityka obiecuje sama sobie: „Jeśli w przyszłości dojdzie kolejny dostawca
spoza EOG, dopiszemy go do tabeli wyżej i napiszemy tutaj, na jakiej podstawie
dane do niego trafiają — zanim trafi tam pierwszy rekord". Tutaj obietnica
była spełniona z góry; dopisany został **nowy CEL** przy tym samym dostawcy
(analityka odwiedzin obok „sprawdzenia, czy formularz wypełnia człowiek"),
tak żeby czytelnik widział, że ta sama spółka robi u nas teraz dwie rzeczy.

### Co odrzucono i dlaczego — mierzone, nie brane na słowo

Kandydaci: **Plausible**, **Umami** i **beacon Cloudflare**. Twarde
wymagania: zero ciasteczek, zero zapisu na urządzeniu człowieka, brak
profilowania między serwisami.

**Zachowanie skryptów sprawdziłem, pobierając je i czytając**, zamiast wierzyć
stronom marketingowym — bo to jest zdanie, które trafia do dokumentu prawnego:

| | Plausible (`plausible.io/js/script.js`) | Umami (`cloud.umami.is/script.js`) | **Cloudflare** (`static.cloudflareinsights.com/beacon.min.js`) |
|---|---|---|---|
| `document.cookie` | 0 wystąpień | 0 wystąpień | **0 wystąpień** (słowo „cookie" nie pada w pliku w żadnej postaci) |
| `sessionStorage`, `indexedDB` | 0 | 0 | **0** |
| `localStorage` | tylko **odczyt** flagi `plausible_ignore`, którą człowiek ustawia sam | tylko **odczyt** flagi `umami.disabled` | **0 — nie zagląda tam wcale** |
| zapis na urządzeniu (`setItem`) | brak | brak | **brak** (`setItem` i `getItem`: 0 wystąpień) |

Czyli **na tym kryterium przechodzą wszystkie trzy** i nie ono rozstrzygnęło —
tak samo jak w pierwszej wersji tego wpisu. Beacon Cloudflare wypada tu
o włos lepiej niż dwaj pozostali (nie ma w nim NIC, co dotyka pamięci
przeglądarki), ale gdyby chodziło tylko o to, Plausible wystarczyłby.

Rozstrzygnęły trzy rzeczy, w tej kolejności:

1. **Cena.** Plausible: 9 € miesięcznie. Cloudflare Web Analytics: 0 zł,
   w planie, który już mamy. Przy serwisie prowadzonym przez jedną osobę to
   jest argument, nie wymówka — patrz cytat wyżej.
2. **Kto jest podmiotem i ilu ich jest.** Umami Cloud prowadzi Umami
   Software, Inc. — spółka z Delaware z siedzibą w San Francisco. Nawet
   z regionem UE dla danych sam dostawca zostaje spoza EOG, czyli w naszej
   polityce dopisujemy **TRZECI akapit o przekazywaniu danych poza EOG**,
   obok Turnstile i OpenAI. Plausible (Plausible Insights OÜ, Estonia,
   serwery Hetznera) nie dokładał akapitu o transferze, ale dokładał
   **czwartego dostawcę** do tabeli. Cloudflare nie dokłada ani jednego, ani
   drugiego. Przy dokumencie, który właściciel czyta linijka po linijce, to
   jest różnica na korzyść zrozumiałości, nie tylko formalna.
3. **Self-host odpada z powodu architektury, nie niechęci.** Umami
   samodzielnie hostowany to druga usługa (Node) z własną bazą na Railwayu;
   Plausible samodzielnie hostowany dokłada do tego jeszcze ClickHouse.
   `AGENTS.md` §3 mówi: modularny monolit, bez mikroserwisów i bez kolejnej
   bazy. Dokładanie drugiego procesu do utrzymywania po to, żeby wiedzieć,
   skąd przychodzą odwiedzający, jest złą wymianą. Cloudflare Web Analytics
   **nie ma wariantu samodzielnie hostowanego wcale** — i dlatego przestaje
   obowiązywać uzasadnienie z pierwszej wersji tego wpisu („host w zmiennej
   środowiskowej, żeby dało się przenieść na własną instancję"): nie ma czego
   przenosić, a zmienna sugerowałaby, że jest.

**Nie wraca temat pikseli śledzących** (osobna otwarta sprawa, #204).
**Nie znika nasza analityka serwerowa**: beacon jest jej uzupełnieniem, nie
zamiennikiem. Jedno odpowiada na „ile osób ugotowało w tym tygodniu", drugie
na „skąd przychodzą i które strony oglądają, zanim cokolwiek u nas zrobią" —
i `App\Domain\Analytics\*` zostaje bez jednej zmiany.

### Dlaczego dalej NIE MA banera — i dlaczego to nie jest naciąganie

`docs/legal/COMPLIANCE.md` §5.2 stawia granicę tam, gdzie stawia ją ePrivacy
i PKE: zgody wymaga **przechowywanie informacji na urządzeniu końcowym albo
uzyskiwanie dostępu do tej, która już tam jest** — a nie sam fakt liczenia
czegokolwiek. Beacon Cloudflare nie robi ani jednego, ani drugiego (patrz
tabela wyżej).

Ten sam dokument, w §5.3, **odradza** próbę „cookieless analytics" bez
konsultacji prawnej. Ta rada dotyczyła jednak **PostHoga** i zachowuje ważność
tam, gdzie dotyczyła: PostHog bez identyfikatorów to **konfiguracja**, którą
da się cofnąć jednym przełącznikiem w cudzym panelu — i wtedy dokument prawny
przestaje być prawdziwy, a nikt się o tym nie dowie.

**Czy o beaconie Cloudflare da się uczciwie napisać to samo, co napisałem
o Plausible — że brak ciasteczek jest właściwością narzędzia, a nie
ustawieniem? Da się, i jest to zmierzone mocniej.** W pliku nie ma ani jednego
odwołania do `document.cookie`, `localStorage`, `sessionStorage`, `indexedDB`,
`setItem` ani `getItem`; słowo „cookie" nie pada w nim w żadnej postaci.
Kodu, który nie ma czym zapisać na urządzeniu, nie da się do tego namówić
przełącznikiem w panelu. Identyfikator odsłony powstaje z
`crypto.randomUUID()` w pamięci karty i ginie razem z nią, bo nie ma go gdzie
odłożyć. Skrypt dodatkowo **czyści adresy przed wysłaniem** (funkcja
`cleanLocation`): usuwa query string, fragment oraz login i hasło z URL-a,
więc identyfikator wklejony w link typu `?utm_id=…` do Cloudflare nie dojedzie.
Ryzyko, przed którym ostrzegała §5.3 — cicha zmiana zachowania pod
niezmienionym dokumentem — tu nie występuje. Zapisane w COMPLIANCE.md §5.5.

**Czego ten pomiar NIE uprawnia napisać, i dlatego nie napisałem tego nigdzie:**
że na urządzeniu nie ma żadnego ciasteczka Cloudflare. Proxy i WAF Cloudflare
to warstwa stojąca przed serwisem niezależnie od tej decyzji (np. bot
management), której nie mierzyłem. Beacon nie dokłada do niej nic, ale to jest
osobna sprawa do przeglądu konfiguracji Cloudflare — wypisana wprost
w COMPLIANCE.md §5.5 jako niezamknięta.

### Wpięcie — trzy rzeczy, które łatwo zrobić źle

1. **Bez zmiennej środowiskowej nie ma ANI ŚLADU znacznika w HTML-u.**
   Nie „wyłączona flagą", tylko nieobecna. Lokalnie, w testach i w CI cisza.
   Ten sam wzorzec co puste klucze Turnstile (D-050), i tak samo bez osobnej
   flagi „włącz analitykę" — dałaby stan „włączone, ale bez tokenu", czyli
   skrypt wysyłający zdarzenia donikąd.
2. **Konfiguracja przez `config/kuking.php`, nigdy `env()` w widoku.**
   Na produkcji konfiguracja jest zbuforowana i `env()` poza plikiem configu
   oddaje `null` — czyli znacznik z pustym tokenem: skrypt, który się ładuje
   i nic nie liczy. Z tego samego powodu **ani widok, ani reguła CSP nie
   powtarzają adresów literałem**: liczą je z konfiguracji przez
   `App\Support\AnalitykaCloudflare`. Powtórzenie w dwóch miejscach
   gwarantuje, że przy zmianie jedno zostanie w tyle i skrypt zostanie po
   cichu zablokowany.
3. **CSP w DWÓCH dyrektywach, z DWOMA RÓŻNYMI HOSTAMI** — i to jest tu
   pułapka grubsza niż przy Plausible, gdzie oba adresy były tym samym hostem
   i jedna wartość obsługiwała obie dyrektywy. Zmierzone w `beacon.min.js`:

   | co | adres | dyrektywa |
   |---|---|---|
   | pobranie pliku | `https://static.cloudflareinsights.com/beacon.min.js` | `script-src` |
   | wysyłka zdarzeń (`navigator.sendBeacon`, zapasowo `XMLHttpRequest`) | `https://cloudflareinsights.com/cdn-cgi/rum` | `connect-src` |

   Host zdarzeń jest **bez `static.`** i jest to podłańcuch hosta skryptu —
   czyli `str_contains` na nagłówku CSP dawałby wynik dodatni dla hosta,
   którego tam nie ma. `connect-src` jest w naszej polityce wypisana osobno,
   więc **nie dziedziczy nic z `default-src 'self'`**. Brak drugiej linijki
   daje stronę bez usterki, pusty dziennik i pusty panel Cloudflare. Pilnują
   tego **dwa osobne testy**, po jednym na dyrektywę, plus trzeci na to, czego
   żaden z nich nie widzi: że te dwa hosty są RÓŻNE (wpisanie jednego w oba
   miejsca zdałoby oba pierwsze testy).

   Znacznik ma kształt (`token` z panelu, świadomie BEZ pola `version`:
   zmierzone w skrypcie, jego obecność przełącza adres zdarzeń na ścieżkę
   względną na naszej domenie — tak działa automatyczne wstrzyknięcie przez
   proxy — i wtedy host dopuszczony w `connect-src` opisywałby nieprawdę):

   ```html
   <script defer src="https://static.cloudflareinsights.com/beacon.min.js"
           data-cf-beacon='{"token":"<token>"}'></script>
   ```

Host analityki wchodzi do CSP **tylko wtedy, gdy analityka jest włączona** —
tak samo jak host Turnstile (issue #12): polityka opisuje to, co strona
naprawdę ładuje, a każdy obcy host w `script-src` poszerza powierzchnię ataku.

### Co właściciel musi zrobić ręcznie — DWIE rzeczy, nie jedna

1. **Założyć serwis w panelu i wpisać token w Railwayu.** Cloudflare →
   Web Analytics → Add a site → `kuking.pl`. Cloudflare pokaże gotowy
   znacznik `<script>`; z niego potrzebna jest sama wartość pola `token`.
   W Railwayu jedna zmienna: `CLOUDFLARE_ANALYTICS_TOKEN=<token>`. Do tego
   czasu serwis chodzi bez analityki i nic nie pada. Opis stoi w `.env.example`.
   Beacon identyfikuje serwis **tokenem**, a nie nazwą domeny — dlatego jedna
   zmienna, a nie dwie jak przy Plausible.
2. **Wyłączyć automatyczne wstrzykiwanie beacona** (Web Analytics →
   ustawienia serwisu). Cloudflare umie wstrzyknąć ten sam skrypt w locie, na
   ruchu przechodzącym przez proxy. **O tym najłatwiej zapomnieć i skutek jest
   cichy:** my stawiamy znacznik w layoucie, więc przy włączonym wstrzykiwaniu
   strona dostanie **dwa** beacony — każda odsłona policzy się dwa razy, a
   nasze testy CSP będą opisywać nieprawdę (wstrzyknięta wersja podaje
   `version`, czyli wysyła zdarzenia na INNY adres niż ten, który dopuszczamy
   w `connect-src`). Znacznik stawiamy sami świadomie: wstrzyknięcie jest poza
   repozytorium, poza recenzją i poza testami, więc nie da się go ani
   przejrzeć, ani zepsuć w kontrolowany sposób — a to jest dokładnie ten
   rodzaj „działa, dopóki ktoś czegoś nie przestawi w cudzym panelu", przed
   którym broni cała reszta tej decyzji.

### Uboczne znalezisko 1: `DokumentyPrawneNieKlamiaTest` był za słaby

Kontrola ujemna do tej zmiany wykryła usterkę w istniejącym teście, starszą
niż ta decyzja i **niezależną od tego, którego dostawcę wybraliśmy**.
`test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy` pytał o CAŁY dokument
(„czy gdziekolwiek stoi zdanie zaprzeczające"), więc jedno prawdziwe zdanie
usprawiedliwiało każde inne wystąpienie nazwy: dopisanie do polityki zdania
**„Do statystyk używamy Google Analytics"** testu NIE OBLAŁO, bo obok stało
prawdziwe „Nie korzystamy z Google Analytics".

Sprawdzenie chodzi teraz po KAŻDYM wystąpieniu nazwy z osobna, w jego własnym
zdaniu — i po poprawce ten sam sabotaż oblewa (powtórzony jako kontrola ujemna
przy tej zmianie dostawcy, żeby poprawka nie została po cichu cofnięta).
Lista narzędzi zakazanych liczy się przy tym z kodu, więc wpięty dostawca
wypada z niej sam, a jego obecności w dokumencie pilnuje z drugiej strony
`PolitykaPrywatnosciWymieniaKazdaUslugeTest` — szukając nazwy
**„Cloudflare Web Analytics"**, a nie samego „Cloudflare", które stoi
w polityce od Turnstile'a i od R2.

### Uboczne znalezisko 2: martwa deklaracja `PostHog (EU)` w AGENTS.md

`AGENTS.md` §3 wymieniał w tabeli stacku `| Analityka | PostHog (EU) |`.
Zmierzone tego dnia: **PostHoga nie ma w kodzie ani jednej linijki** — ani
pakietu, ani `env('POSTHOG_KEY')` w `config/`, ani jednego wywołania. Były
tylko puste `POSTHOG_KEY` w `.env.example` i w dwóch jobach `ci.yml`, których
**nic nie czytało**. Ta linijka była nieprawdziwa od dawna.

To ta sama klasa błędu co martwy odnośnik `D-066`: **deklaracja, która wygląda
na rozstrzygnięcie i zatrzymuje szukanie.** Agent czytający tabelę stacku
kończył temat analityki na „jest PostHog", zamiast zobaczyć, że nie ma nic.
Poprawione w `AGENTS.md` i w `README.md`, martwe `POSTHOG_KEY` usunięte
z `.env.example` i z `ci.yml`.

**Zostało jedno miejsce, świadomie nietknięte:** `.railway/railway.ts` (linie
422–423) dalej podaje `POSTHOG_KEY` i `POSTHOG_HOST` na środowiska Railwaya.
Nic tych zmiennych nie czyta, więc są martwe, ale ten plik nie należał do tej
pracy i pilnuje wdrożenia produkcyjnego — do usunięcia osobno, razem
z przeglądem zmiennych na Railwayu.

**Pliki:** `config/kuking.php` · `app/Support/AnalitykaCloudflare.php`
(z przemianowania `app/Support/Plausible.php`) ·
`app/Http/Middleware/ApplySecurityHeaders.php` ·
`resources/views/components/layout.blade.php` · `.env.example` ·
`.github/workflows/ci.yml` · `AGENTS.md` · `README.md` ·
`resources/legal/polityka-prywatnosci.md` · `docs/legal/COMPLIANCE.md` §2.2, §5.2, §5.5 ·
`tests/Feature/AnalitykaBezCiasteczekTest.php` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` ·
`tests/Feature/PolitykaPrywatnosciWymieniaKazdaUslugeTest.php`
