## D-062 · List, który nie wyszedł, zostawia ślad w bazie i zapala `/health` — a alarmu pocztą o awarii poczty nie wysyłamy

**Data:** 10 września 2026 · Issue #234 · Status: **obowiązuje**

Do dziś odmowa dostawcy kończyła się tak: `OdmowaEmailLabs` wywracała zadanie,
worker robił trzy próby (`--tries=3 --backoff=10,60,300`, czyli wszystkie
w około sześciu minutach), zadanie lądowało w `failed_jobs` — i **cisza**.
Adresat nie dowiadywał się nigdy. Właściciel tylko wtedy, gdy sam z siebie
uruchomił `php artisan queue:failed` albo `kuking:sprawdz-poczte`.

Najgorsze było to, że **wyglądało to identycznie jak sukces**: kolejka pusta,
`/health` zielony, w panelu nic. A chodziło o potwierdzenia rejestracji,
przypomnienia hasła i logowanie linkiem — czyli listy, na które człowiek
czeka przed ekranem. W grupie 50+ osoba, która nie dostała potwierdzenia, nie
napisze reklamacji: uzna, że serwis nie działa, i odejdzie.

### 1. Co powstało

Jedna tabela (`mail_failures`), jedna kategoria odmowy, jedno sprawdzenie
w `/health`, jedna komenda i jedno zdanie na ekranie dla człowieka.

| Warstwa | Co robi |
|---|---|
| `App\Poczta\PowodOdmowy` | Cztery kategorie: `limit_dobowy`, `przejsciowa`, `trwala`, `nieznana`. Każda z gotowym zdaniem „co zrobić" |
| `App\Poczta\TransportEmailLabs` | Ustala kategorię **w chwili odmowy** — jako jedyny widzi kod HTTP i kody błędów dostawcy |
| `App\Poczta\ZapiszNieudanyList` | Słuchacz `JobFailed`: zamienia ostatnią, przegraną próbę w wiersz `mail_failures` i wpis `Log::error` |
| `/health` → `checks.listy` | `degraded` z kodem `listy_przepadaja` albo `limit_poczty_wyczerpany`, dopóki ktoś nie odhaczy |
| `kuking:nieudane-listy` | Co przepadło, komu, dlaczego, co z tym zrobić. `--odhacz` gasi alarm |
| ekran „Potwierdź adres e-mail" | Przestaje obiecywać list, który nie wyszedł, i przestaje odsyłać do „Spamu" po wiadomość, której tam nie ma |

### 2. Dlaczego `JobFailed`, a nie zdarzenia poczty ani wnętrze transportu

`MessageSending` leci przed wysyłką, `MessageSent` tylko po udanej — żadne
z nich nie mówi o porażce nic. Wyjątek transportu leci przy **każdej** z trzech
prób, więc zapisywanie śladu stamtąd dałoby trzy wiersze o jednym liście
i wpis nawet wtedy, gdy druga próba się udała.

`JobFailed` leci **dokładnie raz**: w chwili, w której worker uznaje zadanie za
przegrane. To jest ta sama chwila, w której list naprawdę przepada. Warunkiem
zapisu jest `TransportExceptionInterface` gdziekolwiek w łańcuchu przyczyn —
czyli **typ, nie treść komunikatu** — więc ślad powstaje tak samo przy naszym
`emaillabs`, jak przy uśpionym `smtp` (D-047), a zgadywanie z tekstu nie
przestanie działać po cichu przy zmianie wersji biblioteki (lekcja z W7-07).

**Słuchacz nie ma prawa rzucić i cała jego treść jest w `try`.** To jest
najważniejsza własność tego kodu: wiersz w `failed_jobs` zapisuje INNY
słuchacz tego samego zdarzenia (`WorkCommand::logFailedJob`, rejestrowany
dopiero przy starcie `queue:work`), a nasz — zarejestrowany w dostawcy usług —
leci pierwszy. Gdyby padł, zabrałby diagnostyce payload, czyli jedyną rzecz,
z której da się list ponowić. Zamiana „nikt się nie dowie" na „nikt się nie
dowie i nie ma czego ponowić" byłaby poprawką w złą stronę. Pilnuje tego
`NieudanyListZostawiaSladTest::test_awaria_zapisu_sladu_nie_przewraca_obslugi_bledu`.

### 3. Alarmu pocztą NIE WYSYŁAMY — i to jest decyzja, nie zapomnienie

Kusi, żeby wysłać list na `KUKING_MODEL_ALARM_EMAIL`, tak jak robią to
`kuking:podsumowanie-automatu` i `kuking:pilnuj-terminow-odwolan`. Nie robimy
tego z trzech powodów, w tej kolejności:

1. **Najczęstszy przypadek to wyczerpany limit dobowy.** Wtedy list o awarii
   odbije się identycznie jak ten, o którym miał donieść — alarm nie dojdzie
   dokładnie wtedy, gdy jest najbardziej potrzebny.
2. **Pętla.** Nieudany alarm jest sam nieudanym listem, więc zostawia własny
   ślad, o którym trzeba by donieść. Wersja warunkowa („alarmuj tylko przy
   odmowie trwałej") wymaga bezpiecznika, którego nie da się sprawdzić inaczej
   niż na produkcji — a issue #234 zabrania wprost powiadomień, które mogą się
   zapętlić.
3. **Ślad ma nie zależeć od kanału, który właśnie padł.** Stąd trzy miejsca,
   z których żadne nie jest pocztą: **wiersz w bazie** (przeżywa restart,
   wdrożenie i `queue:flush`), **`/health`** (odpytywany przez Railway
   i monitoring zewnętrzny co kilka minut — to on jest tu automatem) oraz
   **`Log::error`** w dzienniku serwera.

> **SPROSTOWANIE DO §3, ZROBIONE PRZY SCALANIU Z `main` 10 września 2026.**
> Ten punkt twierdził wcześniej, że `Log::error` „przy ustawionym
> `LOG_BLAD_WEBHOOK_URL` (D-041) idzie na webhook, a przy `vars.SENTRY_ORG`
> do Sentry". **Sprawdzone w kodzie: nieprawda**, i to nieprawda kosztowna,
> bo cała ta decyzja opierała na niej zdanie „właściciel ma szansę dowiedzieć
> się bez zaglądania". Kanał `blad_webhook` NIE jest częścią stosu domyślnego
> (`config/logging.php`: `stack` → `LOG_STACK`, a `.env.example` ustawia
> `LOG_STACK=single`); woła się do niego JAWNIE, z `bootstrap/app.php` przy
> raportowaniu wyjątku i z `App\Domain\Contact\DzwonekOperatora`. Sentry'ego
> nie ma w `composer.json` wcale (D-041,
> `docs/infra/INFRA_DECISION.md` — sprostowanie z tego samego dnia).
>
> Zwykłe `Log::error()` zostaje więc w pliku na serwerze i **nikogo nie budzi**.
> Wynika z tego dwie rzeczy, obie już zrobione:
>
> 1. **Ostrzeżenie o kończącej się puli (§6) dzwoni na `blad_webhook` JAWNIE**,
>    obok wpisu w dzienniku serwera — bo `/health` o suficie nie mówi nic
>    i bez tego był to sygnał, którego nie widzi nikt.
> 2. **Automatem, który zamienia `checks.listy` w dzwonek, jest `/health`, nie
>    dziennik** — a `/health` dzwoni na ten kanał dopiero z PR #255
>    (`HealthController::powiadomWebhook()`). Do czasu scalenia #255 jedynym
>    automatem nad `checks.listy` jest monitoring zewnętrzny czytający TREŚĆ
>    odpowiedzi. Zależność jest więc jednokierunkowa i nazwana: #253 zostawia
>    ślad, #255 sprawia, że ktoś go usłyszy.
>
> Nie zmienia się nic w kierunku decyzji: obowiązkowe zostają **wiersz
> w bazie i `/health`**, bo tylko one nie zależą od tego, czy właściciel
> ustawił adres webhooka.

Odpowiedź na pytanie z issue („skąd właściciel się dowie") brzmi więc:
**z monitoringu `/health`, który już istnieje i już jest odpytywany**, a przy
zaglądaniu — z `kuking:nieudane-listy` i z `kuking:sprawdz-poczte`, które teraz
liczy przepadłe listy osobno od resztki `failed_jobs`.

### 4. Człowiek, który czekał, dowiaduje się na ekranie — jednym zdaniem o faktach

Ekran „Potwierdź adres e-mail" mówił zawsze „Wysłaliśmy wiadomość" i kończył
radą „Zajrzyj do folderu «Spam»". Gdy dostawca listu nie przyjął, oba zdania
były nieprawdą, a rada wysyłała człowieka na poszukiwanie wiadomości, która
nigdy nie powstała.

Teraz, gdy w `mail_failures` leży ślad dla TEGO konta świeższy niż
`kuking.poczta.okno_prawdy_godzin` (domyślnie 24 h), ekran mówi: *„Ostatnia
wiadomość nie dotarła. Wysłaliśmy ją 10.09 o 14:22, ale nasz dostawca poczty
jej nie przyjął — więc nie ma jej ani w Twojej skrzynce, ani w folderze
«Spam»"* — i podaje adres kontaktowy. Rada o „Spamie" jest wtedy
**podmieniona**, nie dołożona.

Zdanie opisuje **zdarzenie z przeszłości, z datą**, a nie stan („listy do
Ciebie nie wychodzą"). Dzięki temu zostaje prawdziwe także po udanym
ponowieniu i nie potrzebuje w bazie żadnego znacznika „już naprawione", czyli
drugiego stanu do pilnowania.

**Świadomie zawężone do potwierdzenia adresu.** Reset hasła i logowanie
linkiem odpowiadają identycznie dla adresu z kontem i bez konta (D-056), więc
zdanie „Twój list nie wyszedł" na tamtych ekranach byłoby wyrocznią „kto ma
konto w Kuking" — czyli wyciekiem. Tam prawdę mówi już `App\Support\Poczta`,
gdy poczta nie wychodzi w ogóle.

### 5. „Nie wyszedł teraz" ≠ „nie wyjdzie nigdy"

Kategoria nie steruje ponawianiem i `--tries` **zostaje na trzech** (issue #234
mówi wprost: nie podnosić). Kategoria mówi CZŁOWIEKOWI, co zrobić, i rozdziela
kod w `/health`:

| Kategoria | Skąd się bierze | Co robi właściciel |
|---|---|---|
| `limit_dobowy` | HTTP 429 **albo** słowo o limicie w błędzie dostawcy — także przy HTTP 2xx | Nie powtarza dziś. Po północy `queue:retry`, a jeśli się powtarza — płatny plan |
| `przejsciowa` | HTTP 5xx, 408, zerwane połączenie | `queue:retry` — najprawdopodobniej wystarczy |
| `trwala` | pozostałe 4xx i 207 (zły adres, zły klucz, odrzucony nadawca) | Powtarzanie nic nie da; trzeba coś zmienić |
| `nieznana` | odpowiedź, której nie umiemy odczytać | Traktuje jak awarię, nie jak coś, co samo przejdzie |

**Słowa o limicie sprawdzamy PRZED kodem HTTP** i to nie jest kolejność
przypadkowa: dostawcy wysyłają „limit exceeded" także z kodem 2xx i 4xx,
a klasyfikacja po samym kodzie kazałaby wtedy właścicielowi szukać usterki
w konfiguracji przez cały dzień, w którym wystarczyło poczekać do północy.

### 6. Ostrzeżenie ZANIM pula się skończy

`DziennyBudzetListow::sprobujZarezerwowac()` zapisuje `Log::error` i dzwoni na
kanał `blad_webhook`, gdy zużycie sufitu przekroczy
`kuking.poczta.prog_ostrzezenia_procent` (domyślnie 80%) — **raz na dobę na
funkcję**, bo alarm, który się powtarza, uczy się ignorować. To jedyny
wyprzedzający sygnał, jaki ten serwis ma, i odpowiada na czwarty punkt
issue #234: przejście na płatny plan ma dać się zrobić dzień wcześniej, nie
w dniu awarii. Na webhook idzie **jedno zdanie z samymi liczbami i nazwą
funkcji**, w dzienniku serwera zostaje pełny kontekst — bo webhook wychodzi do
usługi, nad którą nie mamy kontroli (audyt A6-01).

**OSTRZEŻENIE STOI PO ODDANIU BLOKADY SUFITU, NIE W `zajmij()`** — i to jest
poprawka zrobiona przy scalaniu z D-076, nie szczegół stylu. Gałąź #253
powstała, gdy `zajmij()` chodziło samo; D-076 zrobiło z niego drugi krok
atomowej rezerwacji **pod `Cache::lock()`**. Wołanie ostrzeżenia stamtąd
wkładałoby do sekcji krytycznej dobowego sufitu zapis do dziennika,
`Cache::add()` i żądanie HTTP z limitem trzech sekund — a `CZEKANIE_SEKUND`
w tej klasie to **dwie**. Jedno ostrzeżenie „zaraz zabraknie listów"
odmawiałoby więc wysyłki wszystkim żądaniom, które w tym czasie czekają na
blokadę: sygnał o kończącej się puli sam by ją zabierał. Pilnuje tego
`SufitPocztyOstrzegaZawczasuTest::test_ostrzezenie_pada_dopiero_po_oddaniu_blokady_sufitu`,
który zdobywa tę samą blokadę z wnętrza słuchacza dziennika.

Skutek uboczny, przyjęty świadomie: droga listu próbnego
`kuking:wyslij-podsumowania --tylko`, która woła `zajmij()` wprost, nie
ostrzega o niczym. I nie powinna — ten list wychodzi ŚWIADOMIE ponad sufitem
(D-076), przy właścicielu patrzącym w konsolę, a stan puli wypisuje mu
`php artisan kuking:sprawdz-poczte`.

### 7. Alarm gaśnie po ODHACZENIU, nie po czasie

`/health` mówi `degraded`, dopóki w `mail_failures` leży choć jeden wiersz
z `zauwazony_at IS NULL`. **Bez okna czasowego** — i to jest sedno: alarm
z oknem „ostatniej godziny" gaśnie sam, czyli awaria z drugiej w nocy jest
o świcie znowu niewidoczna. To ta sama cicha porażka, tylko o kilka godzin
późniejsza.

Cena, przyjęta świadomie: `/health` może stać w `degraded` przez wiele godzin.
`listy` nie są na liście `KRYTYCZNE`, więc trasa oddaje dalej **HTTP 200**
i Railway nie restartuje z tego powodu niczego (ta lekcja jest stara —
healthcheck oddający 503 już raz położył ten serwis). Odhaczenie to jedna
komenda, po przeczytaniu: `php artisan kuking:nieudane-listy --odhacz`.

### 8. Czego świadomie NIE zrobiliśmy

- **Pozycji w panelu moderacji.** Trzy inne gałęzie pracują równolegle
  w `resources/views/pages/admin/**`, a alarm, który już działa bez panelu
  (`/health` + dziennik + komenda), nie jest wart kolizji. Panel jest
  naturalnym miejscem na to później — jako osobna praca, nie doklejona tutaj.
- **Podnoszenia `--tries` i własnego ponawiania.** Issue #234 zabrania
  pierwszego, a drugie byłoby drugim systemem kolejek obok tego, który już
  jest.
- **Drugiej tabeli na ostrzeżenia o limicie.** Ostrzeżenie z §6 nie jest
  awarią i nie ma czego odhaczać — wiersz w bazie sugerowałby, że jest.
- **Zapisu adresu odbiorcy.** Jest w payloadzie zadania i w koncie; trzecia
  kopia byłaby trzecią rzeczą do skasowania przy żądaniu RODO.

### 9. Zmiana wymaga

Przemyślenia obu połówek naraz. Dodanie piątej kategorii do `PowodOdmowy` bez
migracji kończy się cichym `QueryException` w słuchaczu, czyli **utratą śladu
przy pierwszej awarii nowego typu** — dlatego CHECK w bazie powstaje z listy
`PowodOdmowy::wartosci()`, a `NieudanyListZostawiaSladTest` porównuje jedno
z drugim. Dołożenie okna czasowego do sondy `/health` cofa §7. Owinięcie
`ZapiszNieudanyList` czymkolwiek, co rzuca, cofa §2.

Pilnują tego: `NieudanyListZostawiaSladTest`,
`SufitPocztyOstrzegaZawczasuTest`, `PocztaPrzezApiEmailLabsTest`,
`HealthNieZdradzaSzczegolowTest`, `PodzialLimituPocztyTest`.

### 10. Spotkanie z D-076, D-077 i D-078 (dopisane przy scalaniu z `main`)

Ta gałąź powstała PRZED trzema decyzjami, które weszły na `main` tego samego
dnia. `git` nie pokazał ani jednego konfliktu tekstowego w kodzie poza jedną
metodą — a mimo to trzy rzeczy wymagały rozstrzygnięcia:

1. **D-076 (atomowa rezerwacja dobowego budżetu).** Jedyny prawdziwy konflikt
   merytoryczny i jedyny naprawiony kodem: ostrzeżenie z §6 przeniesione
   z `zajmij()` do `sprobujZarezerwowac()`, poza blokadę. Wyprowadzenie
   w §6.
2. **D-077 (rezerwacja `(osoba, tydzień)` przed wysłaniem digestu).**
   **Nie koliduje — a `mail_failures` domyka jedną świadomą lukę tamtej
   decyzji.** D-077 §5 pisze wprost: „nie ma sposobu, żeby dowiedzieć się
   z bazy, którym osobom list przepadł — wiersz rezerwacji wygląda identycznie
   dla «wysłano» i dla «padło po rezerwacji»". Od tej gałęzi jest sposób:
   wiersz `mail_failures` z `user_id` i `rodzaj`. Nie zmienia to kierunku
   D-077 (rezerwacja ZOSTAJE, ta osoba nie dostaje listu za ten tydzień) —
   zmienia tylko to, że właściciel WIE, komu przepadł, i może napisać innym
   kanałem.
   **Czego to nadal nie łapie, powiedziane wprost:** ślad powstaje na
   `JobFailed`, czyli dla zadania, które WESZŁO do kolejki i tam przegrało.
   Gdyby samo `Mail::queue()` rzuciło synchronicznie (np. padła baza kolejki),
   rezerwacja tygodnia zostaje, listu nie ma i śladu też nie ma. To jest
   dokładnie kierunek pomyłki wybrany w D-077 §5 i tej gałęzi nie wolno go
   po cichu odwracać — dlatego niczego tu nie „naprawiono".
3. **D-078 (sygnał digestu mówi `weekly_digest_queued`).** Nie koliduje i nie
   dubluje się: D-078 poprawiło NAZWĘ metryki, która zawyżała skuteczność
   wysyłki, bo liczyła zakolejkowanie jako wysłanie. Ta gałąź dokłada to,
   czego tamta nazwa nie mogła mieć — **twardy ślad o liście, który przegrał
   w workerze**. Jedno mówi „zakolejkowaliśmy tyle", drugie „tyle przepadło";
   razem dają liczbę, której do dziś nie było. Żadnego `delivered` ani
   `opened` ta gałąź nie wprowadza (#204 zostaje zamknięte na „nie").

📄 `app/Poczta/PowodOdmowy.php` · `app/Poczta/BezpiecznyKomunikat.php` ·
`app/Poczta/ZapiszNieudanyList.php` · `app/Poczta/OdmowaEmailLabs.php` ·
`app/Poczta/TransportEmailLabs.php` · `app/Models/MailFailure.php` ·
`app/Providers/PocztaServiceProvider.php` ·
`app/Http/Controllers/HealthController.php` ·
`app/Http/Controllers/Auth/EmailVerificationController.php` ·
`app/Console/Commands/NieudaneListy.php` ·
`app/Console/Commands/SprawdzPoczte.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`resources/views/auth/verify-email.blade.php` ·
migracja `2026_09_10_500000_create_mail_failures_table` ·
`config/kuking.php` (`poczta`) · `docs/DATABASE.md` ·
`docs/infra/MONITORING_BLEDOW.md`
