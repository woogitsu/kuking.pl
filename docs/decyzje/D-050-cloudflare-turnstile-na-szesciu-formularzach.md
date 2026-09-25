## D-050 · Cloudflare Turnstile na sześciu formularzach publicznych — warunek wysłania, nie filtr. Brak tokenu odrzuca

**Data:** 9 września 2026 · Issue #217 · **Decyzja właściciela** · Status: **obowiązuje**
· **Zaostrzone tego samego dnia, po wdrożeniu PR #218 — patrz sekcja o braku tokenu**

Turnstile w trybie **Managed** stoi na **sześciu** formularzach publicznych —
wszędzie tam, gdzie do serwisu wchodzi ktoś niezalogowany: `/register`,
`/login`, `/nie-pamietam-hasla`, `/cofnij-usuniecie-konta`, `/napisz-do-nas`
i `/zglos-nielegalna-tresc`. Weryfikacja tokenu idzie po stronie serwera,
na `https://challenges.cloudflare.com/turnstile/v0/siteverify`, **własnym
cienkim klientem** na `Illuminate\Support\Facades\Http` — żadnej nowej paczki
Composera, tak samo jak transport poczty w D-047.

### CO TA DECYZJA ODWRACA

`docs/INSPIRATION_DECISIONS.md` poz. **1.11** brzmiała: „Captcha przy
rejestracji — **REJECT**: bariera wejścia dla osób 50+ jest większa niż zysk;
zamiast tego sygnały pasywne (poz. 3.6)". Ta pozycja jest od dziś **ADAPT**
i wskazuje na ten wpis.

Odwraca ją **właściciel**, słowami: *„captcha trzeba normalnie zrobić, ten od
cloudflare jest nieinwazyjny"*. I ma rację co do faktu: rozstrzygnięcie z 1.11
dotyczyło captchy, jaką się wtedy znało — obrazków z przejściami dla pieszych,
na których osoba 65-letnia utyka i rezygnuje. **Turnstile w trybie Managed
w przeważającej większości przypadków nie prosi o nic**: sprawdza sygnały
przeglądarki i przepuszcza w tle. Bariera, o której mówiła poz. 1.11, po
prostu nie ma tu miejsca.

Drugi powód jest niezależny od Turnstile: dwa nasze dokumenty mówiły w tej
sprawie co innego (`SECURITY_BASELINE.md` §4 przewidywał captchę przy
logowaniu), a rozjazd między dokumentami jest gorszy niż brak dokumentów.

Zostaje jednak istota tamtego sprzeciwu i to ona kształtuje całą resztę tej
decyzji: **nie wolno postawić przed człowiekiem 50+ bramki, przez którą może
nie przejść** — a jeśli już się ją stawia, to razem z drogą obok niej.

### BRAK TOKENU ODRZUCA WYSŁANIE — I PIERWOTNIE BYŁO ODWROTNIE

Stan faktyczny:

```text
brak tokenu          → ODRZUCAMY     (osobny komunikat mówiący, co zrobić,
                                      + wpis w dzienniku)
token nieprawdziwy   → ODRZUCAMY     (inny komunikat: sprawdzenie wygasło)
Cloudflare nie odpowiada → PRZEPUSZCZAMY + ostrzeżenie w dzienniku
zły sekret po naszej stronie → PRZEPUSZCZAMY + `Log::error`
brak kluczy w konfiguracji → PRZEPUSZCZAMY, nikogo nie pytamy
```

**Pierwsza wersja tej decyzji (PR #218, ten sam dzień) mówiła co innego: brak
tokenu PRZEPUSZCZAŁ.** Wynikało to wprost z zasady „ważne funkcje działają bez
JavaScriptu" (`AGENTS.md` §5): Turnstile jest widgetem JS, wersji bez JS nie
ma, więc jedynym sposobem pogodzenia obu rzeczy było przepuszczanie pustego
pola. Reguła nazywała się wtedy `TurnstileNieJestPodrobiony`, a testy
`test_*_bez_tokenu_*` pilnowały, żeby nikt tego „nie dokręcił".

**Właściciel zmienił tę zasadę dla tych sześciu miejsc**, dosłownie: *„w tych
newralgicznych miejscach niech JS będzie obowiązkowo jak ta rejestracja itp,
tam gdzie można się obejść to spoko, ale lepiej żeby był z wygody"*.
Uzasadnienie jest faktyczne, nie ideologiczne: nasi ludzie wchodzą
z nowoczesnych telefonów albo z komputera i JavaScript mają — przeglądarka
z wyłączonym skryptem to dziś przypadek pojedynczy, a captcha przepuszczająca
puste pole nie chroni przed niczym, bo skrypt masowo zakładający konta po
prostu tego pola nie wysyła. Filtr, który każdy automat obchodzi jedną
pominiętą wartością, jest ozdobą. Zmianę w samym `AGENTS.md` §5 wprowadza
właściciel.

Turnstile jest więc **warunkiem wysłania tych sześciu formularzy**, a nie
filtrem taniego ruchu. Reguła nazywa się `App\Rules\TurnstileJestPotwierdzony`
i jest **implicit** (`public bool $implicit = true`) — bez tego Laravel nie
wołałby jej dla pola pustego albo nieobecnego, czyli dokładnie dla przypadku,
o który tu chodzi, i zaciśnięcie byłoby pozorne. `required` w sześciu
kontrolerach dałoby ten sam skutek, ale z laravelowym komunikatem o „polu
cf-turnstile-response", którego nikt na ekranie nie zrozumie.

### CO MUSI IŚĆ RAZEM Z ZACIŚNIĘCIEM — TO JEST WAŻNIEJSZE NIŻ SAMO ZACIŚNIĘCIE

Samo odrzucanie to jedna linijka. Wartość tej zmiany leży w tym, żeby **nikt
nie został przed martwym przyciskiem**. Bez poniższych czterech rzeczy
zaciśnięcie zamienia rzadką awarię w cichą utratę użytkownika — człowiek
klika „Załóż konto", dostaje komunikat o czymś, czego nie widzi na ekranie,
i odchodzi.

1. **`<noscript>` przy każdym z sześciu formularzy**, w miejscu, gdzie
   normalnie stoi widget (`resources/views/components/turnstile.blade.php`).
   Zdanie jest **osobne dla każdego formularza**, bo człowiek ma się
   dowiedzieć nie tego, jakiej technologii wymagamy, tylko czego konkretnie
   nie da się teraz zrobić: „Do założenia konta potrzebny jest włączony
   JavaScript…", „Do wysłania linku do nowego hasła…", „Do wysłania
   zgłoszenia…". „Wymagany JavaScript" nad formularzem odzyskiwania hasła nie
   mówi nikomu, że właśnie nie odzyska hasła.
2. **Osobny komunikat na wypadek, gdy JavaScript JEST włączony, a token i tak
   nie przyszedł** — bo skrypt widgetu się nie dociągnął (słabe łącze,
   blokada reklam, Cloudflare nieosiągalny z tej sieci). To NIE jest ten sam
   przypadek co token podrobiony i nie wolno im dać wspólnego tekstu:
   przy podrobionym sprawdzenie było widoczne i wygasło („wyślij formularz
   jeszcze raz"), przy braku tokenu na ekranie nie ma NICZEGO, czego brakuje,
   więc trzeba powiedzieć wprost, że sprawdzenie się nie wczytało, i co z tym
   zrobić. Kolejność rad jest celowa: najpierw „wyślij jeszcze raz" (nieudana
   walidacja przerysowuje stronę z `old()`, więc przy okazji drugi raz próbuje
   pobrać skrypt i nie kosztuje ani jednego wpisanego znaku), dopiero potem
   JavaScript i blokada reklam, na końcu adres e-mail.
3. **Droga wyjścia dla człowieka, który utknął: adres e-mail, pod którym
   siedzi człowiek** (`kuking.community.contact_email`) — w `<noscript>` jako
   klikalny `mailto:` i w komunikacie odrzucenia jako tekst. Dotyczy to także
   rejestracji i logowania, i nie jest ozdobą: **nie wolno odesłać takiej
   osoby na `/napisz-do-nas`**, bo tamten formularz ma dokładnie to samo
   sprawdzenie i jest dla niej równie zamknięty. Adres jest jedyną drogą,
   która nie zależy od tego, co się właśnie zepsuło. Przy `/zglos-nielegalna-tresc`
   ma to dodatkowy ciężar: DSA art. 16 ust. 1 każe trzymać mechanizm „łatwo
   dostępny", a formularz, który potrafi odmówić, przestaje nim być bez
   drugiej drogi.
4. **Licznik, czyli ślad w dzienniku.** Każde odrzucenie z powodu braku tokenu
   zapisuje `Log::warning` z nazwą miejsca — **bez adresu IP i bez czegokolwiek,
   co człowiek wpisał w formularz** (`AGENTS.md` §7). Zaciśnięcie jest
   zakładem („nasi ludzie mają JavaScript"), a zakład bez licznika jest wiarą,
   nie decyzją: po tygodniu musi dać się odpowiedzieć na pytanie, ilu ludzi
   odbiło się od którego formularza. Świadomie **nie** idzie to do
   `product_signals` (`ZapiszSygnal`): `signal_name` jest tam zamknięty
   CHECK-iem w bazie, więc nowa nazwa zdarzenia znaczy migrację — a droga
   wycofania niżej obiecuje „bez migracji, bez danych do posprzątania" i ta
   obietnica jest tu więcej warta niż wygodniejszy wykres. Gdyby liczby
   okazały się niepokojące, przeniesienie tego do sygnałów jest osobną,
   świadomą pracą z migracją i wpisem w `docs/DATABASE.md`.

**Niedostępność cudzej usługi nadal nie zamyka rejestracji i to się NIE
zmieniło.** Timeout, HTTP 5xx, odpowiedź w nieznanym kształcie, literówka
w `TURNSTILE_SECRET_KEY` — w każdym z tych przypadków formularz przechodzi,
a ostrzeżenie idzie do dziennika. Odwrotna decyzja („nie wiem" = odrzucamy)
wyglądałaby na bezpieczniejszą i byłaby najgorszym możliwym błędem w tym
miejscu: awaria u Cloudflare albo jeden zły znak w panelu Railway zamykałby
naraz rejestrację, odzyskiwanie hasła i formularz z DSA art. 16 — a z zewnątrz
wyglądałoby to jak działający serwis. Zaciśnięcie dotyczyło człowieka, który
nie przysłał tokenu, a nie naszej ani cudzej awarii.

**Brak kluczy w konfiguracji też nie blokuje niczego** — patrz sekcja niżej.
Inaczej CI i praca lokalna (jedno i drugie bez kluczy) stanęłyby na sześciu
formularzach naraz, a `<noscript>` straszyłby brakiem JavaScriptu na
formularzu, który i tak przechodzi bez tokenu.

### LOGOWANIE I COFNIĘCIE USUNIĘCIA KONTA — TAK, ZAWSZE (DECYZJA WŁAŚCICIELA)

Pierwotny szkic #217 przewidywał na `/login` i `/cofnij-usuniecie-konta`
wariant „dopiero po nieudanych próbach", z obawy przed podatkiem od wieku:
codzienna droga naszych ludzi obłożona captchą za cudze skrypty.
**Właściciel tę obawę oddalił** — i argument jest rzeczowy, nie autorytatywny:
skoro widget zwykle nie wymaga żadnej interakcji, to nie ma bariery, przed
którą trzeba by bronić. Wariant „po nieudanych próbach" **nie powstał** i nie
jest już potrzebny; gdyby kiedyś miał powstać, musiałby czytać koszyki
z `login_limits` i jest osobną pracą.

**Trzy koszyki `login_limits` zostają bez zmian.** Turnstile ich nie zastępuje
i nie wolno go traktować jak ich zamiennika: limity widzą atak rozproszony po
adresach (W7-01), captcha widzi automat w przeglądarce. To dwie różne obrony
i chcemy obu naraz.

Pierwsza wersja tego wpisu miała tu jeszcze jedno zdanie: „bez JavaScriptu
logowanie działa dalej". **Już nie działa** — wypowiedź właściciela o JS
w newralgicznych miejscach objęła również logowanie, wprost („jak ta
rejestracja itp"). Bez tokenu logowanie jest odrzucane tak samo jak
rejestracja, z tym samym komunikatem i tą samą drogą wyjścia; pilnuje tego
`test_logowanie_bez_tokenu_jest_odrzucane_ze_zrozumialym_komunikatem`.

Cena jest realna i trzeba ją nazwać: człowiek, któremu widget się nie
dociągnie, nie wejdzie na własne konto. Dlatego przy logowaniu — tak samo jak
przy rejestracji — w komunikacie stoi adres e-mail, a nie odesłanie na
`/napisz-do-nas`, które byłoby dla niego ślepą uliczką.

`docs/legal/SECURITY_BASELINE.md` §4 mówi o tym teraz to samo, co kod.

### BRAK KLUCZY NIC NIE PSUJE — I WŁAŚNIE DLATEGO MUSI BYĆ WIDOCZNY

Bez `TURNSTILE_SITE_KEY` i `TURNSTILE_SECRET_KEY` widget się nie renderuje,
reguła nikogo nie odpytuje i nikogo nie zatrzymuje. To jest dobre zachowanie
domyślne (lokalnie, w CI, w testach i do czasu wgrania kluczy na produkcję nic
się nie psuje) — i jednocześnie **dokładnie ta klasa awarii, na którą ten
projekt nadział się już kilka razy: narzędzie melduje sukces, nie robiąc nic**
(`MAIL_MAILER=log`, martwy `kuking.media_disk`, limit `upload` niepodpięty do
żadnej trasy, job dostępności z #215).

Dlatego jest twardy sygnał, **spójny z tym, co już mamy, zamiast nowego
mechanizmu**: `/health` dostał czwarte sprawdzenie, `turnstile`. Gdy
`APP_ENV=production`, którekolwiek miejsce jest włączone, a kluczy nie ma —
odpowiedź niesie `status: degraded` i `checks.turnstile.error =
turnstile_bez_kluczy`, a `HealthController::check()` zapisuje `Log::error`,
czyli sygnał idzie też na webhook błędów i do Sentry.

Sprawdzenie jest **NIEKRYTYCZNE** (HTTP 200, nie 503) i to jest ta sama
decyzja co przy dysku ze zdjęciami: healthcheck oddający 503 już raz położył
ten serwis, a serwis bez captchy jest o wiele lepszy niż serwis w pętli
restartów. Monitoring ma pilnować **treści** odpowiedzi.

Poza produkcją i przy świadomie wyłączonych wszystkich miejscach sygnału nie
ma — stały `degraded` byłby szumem, który uczy ignorować to pole.

### UX 50+ I POLITYKA BEZPIECZEŃSTWA

Widget **nie jest jedynym nośnikiem informacji**: nad obcą ramką stoi zdanie
po polsku („Zanim wyślesz, sprawdzamy, że formularza nie wypełnia automat.
Zwykle dzieje się to samo i nie musisz nic robić."), bo inaczej osoba 60+
widzi w środku formularza ramkę nie wiadomo czego i nie wie, czy czekać.
Blok stoi **nad** `.form-actions`, więc przycisk wysyłki zostaje tam, gdzie
był, i zostaje przy swoich 48 px; tekst zostaje przy 18 px.

Komunikat odrzucenia **nie każe odświeżać strony** — najczęstszym powodem
odrzucenia jest wygaśnięcie sprawdzenia (token żyje 5 minut), czyli trafia to
w osobę, która pisała długo, a odświeżenie skasowałoby jej tekst. Mówi więc:
wyślij formularz jeszcze raz (wszystkie pola wracają przez `old()`, widget
wystawia świeży token), a jeśli nie pomoże — napisz do nas.

Drugi komunikat, ten o braku tokenu, jest **osobnym tekstem** i tak ma zostać
(uzasadnienie w sekcji o zaciśnięciu wyżej). `<noscript>` stoi wewnątrz tego
samego bloku co widget, więc trafia dokładnie tam, gdzie człowiek szuka
brakującego elementu, i nie rusza przycisku wysyłki. Ramka `.notice`, nie
`.field-help`: to jest zdanie do przeczytania, a nie podpowiedź pod polem —
tekst zostaje przy pełnym rozmiarze, a nie przy rozmiarze pomocniczym.

CSP dostaje `https://challenges.cloudflare.com` w `script-src` i `frame-src`,
**wyłącznie wtedy, gdy Turnstile ma klucze** — polityka opisuje to, co strona
naprawdę ładuje. Nie dokładamy `style-src 'unsafe-inline'`, o którym mówią
niektóre poradniki: style widgetu żyją wewnątrz jego ramki, a `unsafe-inline`
skasowałoby cały efekt issue #107.

### DROGA WYCOFANIA (bez wdrożenia, bez migracji)

1. **Wyłączenie w jednym miejscu:** wyczyść `TURNSTILE_SITE_KEY`
   i `TURNSTILE_SECRET_KEY` w Railway i zrestartuj serwis. Widget znika,
   walidacja przestaje kogokolwiek odpytywać, wszystkie sześć formularzy
   działa jak przed tą zmianą. Żeby `/health` nie zgłaszał wtedy `degraded`,
   ustaw też `TURNSTILE_NA_REJESTRACJI=false` i pozostałe pięć — brak kluczy
   jest błędem tylko wtedy, gdy konfiguracja obiecuje ochronę.
2. **Wyłączenie punktowe:** jeden formularz sprawia kłopot — ustaw jego
   zmienną na `false` (np. `TURNSTILE_NA_ZGLOSZENIU=false`).
3. **Wycofanie samego zaciśnięcia, bez zdejmowania Turnstile:** takiej
   zmiennej NIE MA i nie została dodana świadomie. Turnstile, który przepuszcza
   puste pole, nie chroni przed niczym (automat po prostu tego pola nie wysyła),
   więc przełącznik „captcha, ale bez wymagania tokenu" byłby przełącznikiem
   między ochroną a jej pozorem — a takie wpisy w konfiguracji to dokładnie ta
   klasa usterki, której pilnuje reszta tego repozytorium. Wycofanie idzie
   punktem 1 albo 2 wyżej: `TURNSTILE_NA_LOGOWANIU=false` zdejmuje z jednego
   formularza widget, walidację i wymóg tokenu naraz.
4. **Wycofanie kodu:** rewert commita. Nie ma migracji, nie ma zmiany
   schematu, nie ma danych do posprzątania — Turnstile nie zapisuje niczego
   do bazy.

**Zmiana wymaga:** pomiaru, nie wrażenia — i teraz jest czym mierzyć.
Odrzucenia z braku tokenu są w dzienniku, z nazwą miejsca, więc pytanie „czy
zamknęliśmy komuś drzwi" ma odpowiedź liczbową, a nie tylko wrażeniową.
Gdyby ktoś chciał poluzować zaciśnięcie „bo przeszkadza", potrzebny jest ten
ślad plus to, co przyszło na adres kontaktowy — a nie odwrotna intuicja.
Gdyby ktoś chciał zdjąć `<noscript>` albo połączyć oba komunikaty w jeden
„bo się powtarzają" — to jest cofnięcie tej decyzji do połowy: zostaje
zamknięta bramka bez tabliczki, co jest gorsze niż jedno i drugie osobno.

📄 `app/Support/Turnstile.php` · `app/Turnstile/KlientTurnstile.php` ·
`app/Turnstile/WynikTurnstile.php` · `app/Rules/TurnstileJestPotwierdzony.php`
(do 9 września 2026: `TurnstileNieJestPodrobiony`) ·
`resources/views/components/turnstile.blade.php` ·
`app/Http/Controllers/HealthController.php` ·
`app/Http/Middleware/ApplySecurityHeaders.php` · `config/kuking.php`
(`turnstile`) · `.env.example` · `.railway/railway.ts` ·
`tests/Feature/TurnstileWymagaPotwierdzeniaTest.php` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` (krok 8A) ·
`docs/INSPIRATION_DECISIONS.md` poz. 1.11 ·
`docs/legal/SECURITY_BASELINE.md` §4
