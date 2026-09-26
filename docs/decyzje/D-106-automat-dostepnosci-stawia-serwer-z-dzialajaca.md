## D-106 · Automat dostępności stawia serwer z DZIAŁAJĄCĄ pocztą — bo dwa ekrany odzyskania dostępu mają w stanie zapasowym nagłówek i dwa przyciski zamiast formularza

**Data:** 11 września 2026 · Spłata długu siedmiu stron publicznych z **D-099**
· Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Rdzeń obowiązuje
> (`MAIL_MAILER=smtp` dla procesu serwera,
> `przeszkodaWFormularzachOdzyskania()` kończąca kodem 1 —
> `scripts/dostepnosc.mjs:811,824,1449`). Nieaktualna jest sekcja „Czego ta
> decyzja NIE robi". Zdanie „**nie spłaca dwóch ekranów wejścia kontem
> Google** — `wejdz/google/domknij` i `wejdz/google/polacz` zostają długiem, a
> powód i sprawdzone drogi na skróty są wypisane przy nich w
> `PomiarDostepnosciObejmujeStronyPubliczneTest`" jest dziś nieprawdziwe w obu
> członach. Dług spłacił moduł OAuth (#345): oba adresy są mierzone
> (`scripts/fixtures/oauth-dostepnosc.mjs:8-13`), a job CI zbiera z tego
> artefakt (`.github/workflows/ci.yml:1535`). Odesłanie „po powód" prowadzi
> dziś donikąd: na liście `WYJATKI` w
> `tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php:75-79` tych
> dwóch adresów już nie ma, a argumentacja została usunięta razem z długiem.
> Na granicy: wyliczenie „automat wykonuje **wyłącznie** żądania GET oraz trzy
> formularze logowania i włączenie 2FA" przestało być pełne — moduł OAuth
> przechodzi też przez formularze `…/polacz` i `…/domknij`.

### Co się okazało przy dopisywaniu siedmiu ekranów

D-099 zostawiło **siedem stron publicznych** na liście świadomych wyjątków
w `PomiarDostepnosciObejmujeStronyPubliczneTest` jako dług nazwany:
`o-kuking`, `pomoc`, `odwolanie` (gość), `nie-pamietam-hasla`,
`logowanie/link`, `logowanie/kod` i `cofnij-usuniecie-konta`. Ten PR dopisuje
je do `EKRANY`. Najważniejsza z nich jest `/nie-pamietam-hasla`: to droga,
którą człowiek wchodzi **dopiero wtedy, gdy mu coś nie wyszło**.

Dopisanie jej samo z siebie niczego jednak nie mierzyło. Ekran ma **dwa
stany**, rozstrzygane przez `App\Support\Poczta::dziala()` — czyli przez to,
czy Laravel w ogóle ma czym wysłać list:

Zmierzone 11 września przy 320 px, Chromium 153 — liczby są z pomiaru, nie
z oka:

| ekran | stan | węzłów w `<main>` | znaków tekstu | pól formularza |
|---|---|---|---|---|
| `/nie-pamietam-hasla` | poczta działa | 14 | 315 | **1** |
| `/nie-pamietam-hasla` | poczta nie działa | 6 | 257 | **0** |
| `/logowanie/link` | poczta działa | 21 | 891 | **1** |
| `/logowanie/link` | poczta nie działa | 6 | 271 | **0** |

W stanie „poczta działa" jest akapit, **karta z formularzem** (pole adresu
z etykietą i podpowiedzią, Turnstile, dwa przyciski) i bloki wyjaśnień pod
spodem. W stanie zapasowym — nagłówek, jedno zdanie „napisz do nas" i dwa
przyciski.

**Różnica w samej liczbie węzłów nie jest tu pointą** (osiem i piętnaście
węzłów to niewiele). Pointą jest ta ostatnia kolumna: w stanie zapasowym
**nie ma ani jednego pola formularza**, więc cała klasa rzeczy, których ten
automat pilnuje — etykieta pola, opis pod polem, nazwa dostępna przycisku
wysyłki, kontrast pola, rozmiar celu, zawijanie rzędu przycisków przy 320 px
— nie ma na czym zadziałać. Zielony wynik nad takim ekranem nie mówi nic
o formularzu, bo formularza tam nie było.

**I to właśnie wariant zapasowy widziałby każdy przebieg, wszędzie.** `.env`
deweloperski ma `MAIL_MAILER=log`, a job `dostepnosc` w `ci.yml` —
`MAIL_MAILER=array`; oba są dla `Poczta` niedostarczające. Formularza
odzyskania hasła nie oglądał więc dotąd ani jeden przebieg tego automatu,
w żadnym środowisku — a raport zapisałby dla tego ekranu „✓".

To jest dokładnie ta klasa fałszywej zieleni, przed którą ostrzega nagłówek
`scripts/dostepnosc.mjs` („pusty ekran przechodzi każdy test dostępności, nie
sprawdzając niczego") — tylko o jeden stopień dalej niż w D-099. Tam ekranu
na liście NIE BYŁO. Tu ekran jest na liście, wynik jest zielony, a mierzona
jest jego wersja bez tego, co może się zepsuć: bez etykiety pola, bez opisu
pod polem, bez rzędu przycisków zawijającego się przy 320 px.

### Decyzja

**1. Serwer stawiany przez automat dostaje sterownik poczty, który
dostarcza.** `podnies_serwer()` uruchamia `php artisan serve`
z `MAIL_MAILER=smtp` (stała `STEROWNIK_POCZTY_DO_POMIARU`), celującym
w `MAIL_HOST`/`MAIL_PORT` z konfiguracji.

Nie wysyła to ani jednego listu i nic nie osłabia. Automat wykonuje wyłącznie
żądania GET oraz trzy formularze logowania i włączenie 2FA — żadna z tych dróg
poczty nie rusza (`grep` po `Mail::`, `notify(` i `Notification::`
w `app/Http/Controllers` nie trafia w żaden z tych kontrolerów).
`EsmtpTransport` powstaje lokalnie i otwiera gniazdo dopiero przy pierwszym
liście, którego tu nie ma — to ta sama własność, na której stoi samo
`Poczta::dziala()`.

**2. Wejście w ten stan jest SPRAWDZANE, nie zakładane.** Zaraz po podniesieniu
serwera `przeszkodaWFormularzachOdzyskania()` pobiera oba adresy i wymaga
w odpowiedzi pola `name="email"`. Brak pola kończy przebieg **kodem 1
z nazwanym ekranem**, zamiast przepuścić pomiar wariantu zapasowego.

Niepowodzenie jest tu BŁĘDEM, nie pominięciem — z tego samego powodu co przy
przeliczonym układzie i przy sprawdzeniu korzenia dla czcionki 200% (D-099):
**pomiar w nieznanym stanie jest gorszy niż jego brak.** Bez tej kontroli
zmiana `Poczta::dziala()`, inny sterownik w środowisku albo zapamiętana
konfiguracja po cichu wracają do dwóch akapitów, a raport dalej pokazuje ✓ —
czyli usterkę nie do odróżnienia od poprawnego wyniku.

**3. Przy `ADRES=…` niczego nie przestawiamy.** Wtedy mierzymy cudzą instancję
w stanie, w jakim ją zastaliśmy; sprawdzenie zostaje i powie, w którym stanie
ona jest.

### Dlaczego to NIE jest sprzeczne z D-099, choć wygląda podobnie

D-099 mówi: „automat mierzy stronę W TYM STANIE, W KTÓRYM WYDAJE JĄ PRODUKT".
Ktoś czytający tamto zdanie dosłownie mógłby tu zarzucić odwrotność: przecież
dziś produkt poczty nie wysyła, więc stanem produktowym jest właśnie wariant
zapasowy.

Różnica jest w tym, czego tamto zdanie broniło. D-099 zabrania mierzyć stan,
**w którym nie jest żaden człowiek** — układ złapany w połowie przeliczania,
desktop z podwojonym tekstem, kolory czytane w trakcie przemalowania. Tu jest
odwrotnie: oba stany są prawdziwe i oba ktoś zobaczy, tylko jeden z nich ma
formularz, a drugi jest jego podzbiorem (akapit i dwa `.btn`, czyli komponenty
mierzone na kilkudziesięciu innych ekranach). Wybieramy ten, który niesie
ryzyko — a nie ten, który akurat wychodzi z domyślnej zmiennej środowiskowej.

Gdyby kiedyś wariant zapasowy dostał własny układ (nie akapit i dwa przyciski,
tylko coś swojego), ma wejść na listę jako **osobny ekran**, a nie zastąpić ten
z formularzem.

### Czego ta decyzja NIE robi

**Nie podnosi żadnego progu, nie wyłącza reguły i nie przenosi niczego do
„ostrzeżeń produktowych".** 23 ostrzeżenia „focus częściowo zasłonięty"
(25–75%) zostają tam, gdzie były. Zestaw kontrol zatrzymujących CI jest
niezmieniony i dalej pilnowany przez `PomiarDostepnosciKonczyKodemJedenTest`.

**Nie zmienia ani jednej linijki produktu.** Zero zmian w `app/`, w widokach
i w arkuszu stylów — siedem dopisanych ekranów przy pełnym przebiegu (4
warianty axe, 6 szerokości × 3 skale układu) dało **0 naruszeń, 0 przepełnień
w poziomie, 0 focusów zasłoniętych w 100%**. Zmienia się wyłącznie to, ile
serwis jest oglądany.

**Nie rusza konfiguracji poczty w `.env`, w `.env.example` ani w `ci.yml`.**
Sterownik jest podstawiany procesowi serwera na czas przebiegu i nigdzie nie
zostaje.

**Nie spłaca dwóch ekranów wejścia kontem Google.**
`wejdz/google/domknij` i `wejdz/google/polacz` zostają długiem — powód
i sprawdzone drogi na skróty są wypisane przy nich w
`PomiarDostepnosciObejmujeStronyPubliczneTest`. Krótko: obie wymagałyby albo
włazu pozwalającego zapisać do sesji tożsamość „potwierdzoną przez Google" bez
przejścia przez Google (a wiersz w `tozsamosci_zewnetrzne` JEST drogą wejścia
na konto — D-098), albo atrapy dostawcy tożsamości, bo wymiana kodu na token
idzie z serwera i Playwright nie ma czego przechwycić.

### Kontrola ujemna

| kontrola | sabotaż | wynik |
|---|---|---|
| kompletność listy `EKRANY` | skreślenie `nie-pamietam-hasla` z `EKRANY` | test OBLAŁ i wymienił `nie-pamietam-hasla` z nazwy |
| axe ogląda nowy ekran | `<img>` bez `alt` w `forgot-password.blade.php` | kod 1, `[critical] nie pamiętam hasła / jasny: image-alt` |
| pomiar układu ogląda nowy ekran | `<pre>` z długim ciągiem tamże | kod 1, `nie pamiętam hasła / 320 px: 880 px przy 320 px okna` |
| kontrola stanu poczty | `STEROWNIK_POCZTY_DO_POMIARU = 'log'` | kod 1 z nazwanym ekranem, przed pierwszym pomiarem |
| czwarty kontekst dla `/logowanie/kod` | usunięcie gałęzi `przedKodem2FA` | kod 1: „odesłał na /login" + „axe nie zbadał 1 z 39" |

Każdy sabotaż był przed uruchomieniem sprawdzony w pliku (`grep`), a po
kontroli cofnięty — `git diff` na widoku jest pusty.

### Jak to wycofać

Dwa niezależne kawałki. Skreślenie `MAIL_MAILER` ze środowiska procesu
w `podnies_serwer()` razem z `przeszkodaWFormularzachOdzyskania()` wraca do
mierzenia wariantu zapasowego obu ekranów. Skreślenie siedmiu pozycji
z `EKRANY` wraca do niepełnego pomiaru — i oblewa `PomiarDostepnosciObejmuje…`,
bo te siedem nie stoi już na liście wyjątków. Żadne z nich nie dotyka danych,
schematu ani wyglądu serwisu.

**Zmiana wymaga:** rozstrzygnięcia, który z dwóch stanów ekranu odzyskania
dostępu jest tym mierzonym — i napisania tego wprost, razem z odpowiedzią na
pytanie, kto ogląda drugi.

📄 `scripts/dostepnosc.mjs` ·
`tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php` ·
`app/Support/Poczta.php` (czytane, nietknięte) ·
D-099 · D-056 · D-098 · D-022 · D-050
