## D-056 · Logowanie linkiem e-mail: link prowadzi na ekran z przyciskiem, ważny 30 minut, hasło zostaje drogą równoległą

**Data:** 10 września 2026 · Issue #25 · Status: **obowiązuje**

Kuking wpuszcza na konto **linkiem wysłanym pocztą**. Droga jest równorzędna
z hasłem i widoczna wprost na ekranie logowania, a nie schowana pod „innymi
opcjami". Adres: `/logowanie/link`.

### DLACZEGO — TO NIE JEST WYGODA

`docs/research/AUDIENCE_50_PLUS.md`: **tylko 12,3% osób w wieku 65-74 ma
podstawowe umiejętności cyfrowe** (GUS 2025), hasło i e-mail są murem,
a „ktoś mi pomógł założyć konto" jest normą. Właściciel spodziewa się fali
migracyjnej z Garnek.pl — setek kont zakładanych w kilka dni przez osoby, dla
których to jest pierwsze własne konto od lat. Część z nich zapomni hasła
w tym samym tygodniu, w którym je ustawiła.

Dla nich logowanie linkiem jest **drogą podstawową**, nie awaryjną, i tak jest
zaprojektowane: budżet listów, limity i teksty na ekranie liczone są na duży
odsetek użytkowników, nie na garstkę.

**Hasło zostaje jako droga równoległa.** Nie odbieramy nikomu tego, co już
umie — a przy okazji nie zamykamy nikogo w skrzynce pocztowej, do której może
stracić dostęp. Ekran logowania pokazuje obie drogi obok siebie.

### ROZSTRZYGNIĘCIE 1: LINK NIE LOGUJE OD RAZU — PROWADZI NA EKRAN Z PRZYCISKIEM

Kliknięcie w list otwiera stronę Kuking z jednym przyciskiem „Zaloguj mnie".
Dopiero ten przycisk (POST) zużywa token i tworzy sesję. Samo wejście pod
adres (GET) **niczego nie zużywa i nikogo nie loguje**.

Powód jest zmierzalny, nie estetyczny: **skanery odnośników w poczcie
otwierają linki z listów, zanim zrobi to człowiek.** Robią to Outlook Safe
Links, bramki antywirusowe operatorów i część klientów pocztowych — i robią to
metodą GET. Gdyby GET logował i kasował token jednorazowy, właściciel konta
dostawałby „ten link już nie działa" **przy pierwszym własnym kliknięciu**,
za każdym razem, bez żadnego wytłumaczenia. Ryzyko było wypisane wprost
w issue #25 z dopiskiem `[do sprawdzenia w praktyce]`; rozstrzygamy je
projektem, a nie obserwacją, bo koszt jednego kliknięcia więcej jest zerowy,
a koszt pomyłki to zamknięta droga wejścia dla całej grupy.

Przy okazji wraca zasada, którą HTTP ma od zawsze: **GET nie zmienia stanu.**
Skaner prawie nigdy nie wykonuje POST-a z tokenem CSRF.

Drugi zysk jest ludzki: człowiek **widzi, na jakie konto wchodzi**, zanim
wejdzie („zalogujesz się jako Basia, b***@wp.pl"). Z jednej skrzynki korzysta
czasem całe małżeństwo.

### ROZSTRZYGNIĘCIE 2: LINK ŻYJE 30 MINUT, NIE 15

Issue #25 proponowało 15 minut. **Odstępujemy od tego świadomie.**

Piętnaście minut to liczba z serwisów, w których człowiek siedzi przy
komputerze i czeka na list. Nasza droga wygląda inaczej i wynika wprost
z researchu: prośba idzie z komputera, a poczta jest w telefonie w drugim
pokoju. „Idź po telefon, odblokuj, znajdź list wśród czterdziestu innych,
przeczytaj, kliknij" to realnie kilkanaście minut. Link wygasający w połowie
tej drogi jest gorszy niż jego brak, bo daje komunikat o błędzie komuś, kto
zrobił wszystko dobrze — **i sam generuje ruch pocztowy**, bo ta osoba prosi
o drugi list z tej samej, skończonej puli.

Górna granica bierze się z `docs/legal/SECURITY_BASELINE.md` §3: link resetu
hasła ma żyć **maksymalnie 60 minut**. Link logujący jest **mocniejszy** od
tamtego (wchodzi na konto od razu, nie prosi o ustawienie nowego hasła), więc
jego okno nie ma prawa być dłuższe — połowa tamtego jest właściwą proporcją.

Wartość to jedna liczba w `config/kuking.php`
(`login_link.waznosc_minut`, `KUKING_LOGOWANIE_LINKIEM_WAZNOSC`).

### ROZSTRZYGNIĘCIE 3: LINK DZIAŁA NA KAŻDYM URZĄDZENIU

Link **nie jest** związany z sesją ani z przeglądarką, z której poszła prośba.
Kto otworzy go na innym telefonie, zobaczy dokładnie ten sam ekran
z przyciskiem i wejdzie na konto normalnie.

Wiązanie linku z sesją proszącego jest znaną praktyką i tutaj byłoby błędem:
**droga „poproś na komputerze, kliknij na telefonie" jest u nas drogą typową,
a nie brzegową.** Zabezpieczenie, które zamyka główną ścieżkę, nie jest
zabezpieczeniem, tylko usterką z dobrym uzasadnieniem.

Rekompensujemy to gdzie indziej: ekran przed zalogowaniem mówi, na jakie konto
wchodzi, a token żyje krótko i tylko raz.

### ROZSTRZYGNIĘCIE 4: KOMU LINKU NIE WYSYŁAMY

Nie wysyłamy go na adres bez konta, na konto **zamknięte** (zablokowane,
zgłoszone do usunięcia, wymazane) i na konto **moderatora albo administratora**
(wprost z zakresu issue #25: tam obowiązuje hasło + 2FA). Konto z 2FA link
dostaje — ale go **nie omija**, patrz niżej.

We wszystkich tych przypadkach **odpowiedź formularza jest identyczna** jak
przy wysłaniu listu. Inaczej formularz odpowiadałby na pytania „czy tu jest
konto", „czy zostało zablokowane" i „czy ta osoba jest moderatorem".

Żeby cisza nie zamieniła się w pułapkę, ekran `/logowanie/link` **mówi wprost
i dla wszystkich jednakowo**, że kont obsługi serwisu ta droga nie obejmuje.
Moderator czyta więc wyjaśnienie zamiast czekać na list, a nikt niczego się
o cudzym koncie nie dowiaduje.

### CO TA DROGA NIE OMIJA

- **2FA.** Konto z potwierdzoną weryfikacją dwuetapową po kliknięciu „Zaloguj
  mnie" trafia tam, gdzie trafia po poprawnym haśle: na `/logowanie/kod`, tą
  samą sesyjną ścieżką (`logowanie.2fa.user_id`) obsługiwaną przez
  `TwoFactorChallengeController`. **Link zastępuje hasło, nie drugi składnik.**
- **Panel moderacji.** `EnsureModeratorHasTwoFactor` zostaje nietknięty, a kont
  z rolą `moderator`/`admin` ta droga w ogóle nie dotyczy.
- **Blokadę konta.** Stan konta sprawdzamy **ponownie przy wejściu** — między
  prośbą a kliknięciem mogła zapaść decyzja moderacyjna.

### CO UNIEWAŻNIA OCZEKUJĄCY LINK

Kasowanie tokenu wisi na `User::invalidateSessions()`, czyli na tej samej
metodzie, którą wołają: zmiana hasła, reset hasła, „wyloguj mnie z innych
urządzeń", blokada, zawieszenie i zgłoszenie usunięcia konta. **Jedno miejsce,
a nie sześć wywołań do zapamiętania** — bo link e-mail jest wejściem na konto
tak samo jak sesja, a każda z tych sytuacji ma jeden powód: „ktoś inny mógł
mieć dostęp". Zostawienie wtedy ważnego linku znaczyłoby, że po zmianie hasła
napastnik dalej ma otwarte drzwi (ten sam błąd, który przy oczekującej zmianie
adresu naprawiało #195).

Do tego: **nowa prośba unieważnia poprzedni link** (`user_id` jest unikalne),
a użycie kasuje wiersz w tej samej transakcji, pod `lockForUpdate()` — bez tego
dwa równoległe kliknięcia mogłyby wpuścić dwa razy.

### TOKEN W BAZIE LEŻY WYŁĄCZNIE JAKO SKRÓT

`login_link_tokens.token_hash` to **HMAC-SHA256** (`App\Support\Skrot`, ta sama
konstrukcja co `audit_log.ip_hash` i klucze limitera). Token jawny żyje przez
jedno wywołanie akcji i wychodzi tylko do listu. CHECK w bazie wymusza kształt
skrótu (`^[0-9a-f]{64}$`), a token jest z alfabetu `Str::random()` — więc
zapisanie go wprost baza odrzuci.

Skrót **szybki**, a nie bcrypt jak w `password_reset_tokens`: bcrypt spowalnia
zgadywanie wartości o niskiej entropii (hasło człowieka), a tu wartością jest
64 losowe znaki. Za to bcrypt uniemożliwiłby wyszukanie wiersza po skrócie —
trzeba by wstawić do adresu jeszcze identyfikator wiersza, czyli wynieść do
listu jedną informację więcej bez żadnego zysku.

**Znana i przyjęta własność:** powiadomienie jest kolejkowane (`ShouldQueue`),
więc token w postaci jawnej przechodzi przez payload zadania w `jobs`, a przy
nieudanej wysyłce zostaje w `failed_jobs`. Jest to dokładnie ta sama własność
co przy resecie hasła, gdzie Laravel serializuje token tak samo. Wiersz `jobs`
żyje sekundy; token z `failed_jobs` i tak przestaje działać po 30 minutach,
a listu, którego wysyłka padła, nikt nie dostał.

### RACHUNEK LISTÓW — I CO SIĘ DZIEJE, GDY PULA PADNIE W ŚRODKU DNIA

EmailLabs na planie darmowym daje **300 listów na dobę na cały serwis**
(D-047). Z tego samego wiadra idą potwierdzenia rejestracji, przypomnienia
hasła, powiadomienia i decyzje moderacyjne.

**Ile ta funkcja realnie dołoży przy 500 kontach.** Sesja trwa 7 dni
i „zapamiętaj mnie" jest domyślne, więc jedna osoba potrzebuje nowego
logowania mniej więcej raz w tygodniu. Przy 500 kontach i 30% wracających
dziennie (150 osób) daje to około **20 logowań dziennie**; jeśli 60% z nich
wybierze link, to **12 listów**, a z powtórkami („nie doszło", „wygasł") —
**15-20 listów na dobę**. To jest 5-7% puli i nie jest problemem.

**Problemem jest tydzień migracji, nie stan ustalony.** Gdy 200 osób zakłada
konto jednego dnia (200 potwierdzeń rejestracji) i 100 z nich prosi jeszcze
tego samego dnia o link, pula 300 listów kończy się **przed wieczorem** — i to
nie przez logowanie linkiem samo w sobie, tylko przez sumę. Przy tysiącach
kont, o których mówi właściciel, plan darmowy nie wystarcza w ogóle.

Stąd **dobowy budżet listów tej jednej funkcji**: `login_link.dzienny_budzet`,
domyślnie **120** (dwie piąte puli). To nie jest limit zapytań i nie zastępuje
go: limity chronią pojedyncze konto i pojedynczy adres IP, a ten sufit chroni
**potwierdzenia rejestracji przed logowaniem linkiem**. Bez niego pierwszą
rzeczą, która przestaje działać w dniu fali, jest wejście nowych ludzi — przy
czym przyczyna siedzi kilka warstw dalej i nie widać jej znikąd.

Budżet zajmuje się **dopiero przy wysłanym liście**, nigdy przy samym wysłaniu
formularza — inaczej automat wpisujący nieistniejące adresy wyczerpałby pulę
w kilka minut, nie wysławszy ani jednego listu.

**Po wyczerpaniu budżetu nie milczymy.** Formularz mówi wprost: „dzisiaj
wysłaliśmy już wszystkie listy z linkiem, jakie mieliśmy na dziś, więc ten nie
wyjdzie — nie czekaj na niego", i odsyła do hasła oraz do człowieka pod
adresem kontaktowym. Cicha odmowa byłaby tu najgorszym możliwym zachowaniem —
to ten sam kształt awarii co `MAIL_MAILER=log`.

**CO SIĘ DZIEJE, GDY LIMIT DOSTAWCY PADNIE MIMO TO — sprawdzone w kodzie.**
`TransportEmailLabs` traktuje odmowę API jako `OdmowaEmailLabs`, czyli
`TransportException`. Zadanie w kolejce **nie czeka do jutra**: worker chodzi
z `--tries=3 --backoff=10,60,300` (`docker/entrypoint.sh`), więc ponawia po
10 s, 60 s i 300 s — łącznie **około sześciu minut** — a potem list ląduje
w `failed_jobs` i **przepada**. Odpowiedź „spróbuje jutro" jest nieprawdziwa.

Kto się o tym dowiaduje? **Człowiek — nikt.** Widział „wysłaliśmy list" i będzie
czekał. Operator dowie się tylko wtedy, gdy sam zajrzy: `php artisan
queue:failed` albo `kuking:sprawdz-poczte`, które ostrzega o niepustej tabeli
`failed_jobs`. Automatycznego powiadomienia o nieudanym liście **nie ma** —
i to jest luka szersza niż to issue (dotyczy też potwierdzeń rejestracji
i resetu hasła), więc zostaje zapisana tutaj jako znana, a nie załatana przy
okazji. Dobowy budżet jest odpowiedzią na tę lukę od strony **zapobiegania**:
skoro nie umiemy zauważyć utraconego listu, mamy nie doprowadzać do sytuacji,
w której listy zaczynają przepadać seriami.

### LIMITY

| Gdzie | Ile | Po czym liczone |
|---|---|---|
| `limits.login_link` | 5 / 60 min | adres IP (trasa `POST /logowanie/link`) |
| `login_link.limit_na_adres` | 3 / 60 min | **skrót adresu e-mail** |
| `limits.login_link_wejscie` | 10 / 10 min | adres IP (trasa `POST /logowanie/link/wejdz`) |
| `login_link.dzienny_budzet` | 120 listów / dobę | cały serwis |

Licznik po adresie e-mail rusza przy **każdym** wysłaniu formularza, także dla
adresu bez konta — inaczej samo „ten formularz mnie jeszcze nie zatrzymał"
odpowiadałoby na pytanie, czy konto istnieje. Klucz liczy się po skrócie
(`Skrot::hmac`), żeby cudzy adres nie leżał jawnie w tabeli `cache` — ta sama
lekcja co przy `App\Support\KluczeLimitow`.

### TURNSTILE — SIÓDME MIEJSCE

Formularz „wyślij mi link" dołącza do rodziny chronionej Turnstile (D-050,
D-053) jako `logowanie_linkiem`, na tych samych zasadach co
`/nie-pamietam-hasla`: brak tokenu **odrzuca**, `<noscript>` z osobnym zdaniem
o tym, czego konkretnie nie da się teraz zrobić, i osobne komunikaty dla
„nie ma tokenu" i „token zły". Bez kluczy Turnstile nic się nie renderuje
i nic nie blokuje.

### ZNANE, PRZYJĘTE RYZYKO

Dobowy budżet jest **teoretycznie** wąskim kanałem enumeracyjnym: licznik
rusza tylko przy realnie wysłanym liście, więc ktoś, kto ustawi się dokładnie
na ostatniej jednostce budżetu, może z zachowania formularza wywnioskować
jeden bit („czy tamten adres ma konto"). Wymaga to trafienia w granicę co do
jednego listu i daje najwyżej jeden bit na dobę. Alternatywa — zajmowanie
budżetu przy każdym wysłaniu formularza — otwiera **realną** blokadę usługi
za kilka złotych. Wybieramy ryzyko teoretyczne zamiast praktycznego i zapisujemy
je tutaj, zamiast udawać, że go nie ma.

### JAK TO WYŁĄCZYĆ

`KUKING_LOGOWANIE_LINKIEM=false` — jedna zmienna, restart, **bez wdrażania
migracji i bez danych do posprzątania**. Wejście z ekranu logowania znika
(martwego przycisku nie zostaje, D-053), formularz i wszystkie linki będące
w drodze odpowiadają ekranem „ta droga jest teraz zamknięta, zaloguj się
hasłem". Konta działają dalej, bo hasło nigdy nie przestało być drogą
równoległą.

Węższe zakręcenia bez wyłączania całości: `KUKING_LOGOWANIE_LINKIEM_BUDZET=0`
(dziś nie wysyłamy już nic, ale linki w drodze dalej działają),
`TURNSTILE_NA_LOGOWANIU_LINKIEM=false` (zdejmuje captchę z tego formularza).

Tabelę kasuje `php artisan migrate:rollback --step=1` i jest to bezstratne dla
kont — kolejność wycofywania: **najpierw kod, potem migracja**.

**Zmiana wymaga:** przemyślenia trzech rzeczy naraz. Skrócenie linku poniżej
30 minut wraca do problemu „telefon w drugim pokoju" i podnosi zużycie poczty;
zalogowanie od razu po GET oddaje link skanerom pocztowym; podniesienie
budżetu bez zmiany planu u dostawcy przenosi awarię na potwierdzenia
rejestracji.

📄 `app/Http/Controllers/Auth/LoginLinkController.php` ·
`app/Domain/Security/WyslijLinkDoLogowania.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`app/Models/LoginLinkToken.php` · `app/Models/User.php`
(`invalidateLoginLinks()`) · `app/Notifications/LinkDoLogowania.php` ·
`resources/views/mail/link-do-logowania.blade.php` ·
`resources/views/auth/login-link*.blade.php` ·
`resources/views/auth/login.blade.php` · `routes/web.php` ·
`config/kuking.php` (`login_link`, `limits.login_link*`,
`turnstile.miejsca.logowanie_linkiem`) ·
`database/migrations/2026_09_10_100000_create_login_link_tokens_table.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`tests/Feature/TurnstileWymagaPotwierdzeniaTest.php` ·
`docs/DATABASE.md` · `docs/legal/SECURITY_BASELINE.md` §3
