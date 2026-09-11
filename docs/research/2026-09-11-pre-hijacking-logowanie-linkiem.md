# Pre-account-hijacking na logowaniu linkiem: pomiar i trzy warianty do podpisu (#317)

**Data:** 11.09.2026 · **Issue:** #317 (P1, usterka bezpieczeństwa) ·
**Gałąź:** `claude/pre-hijacking-pomiar`

**Zakres:** POMIAR I ROZSTRZYGNIĘCIE DO PODPISU, nie naprawa.
**Zachowanie logowania linkiem w tej gałęzi nie zmienia się ani o jeden znak.**
`WyslijLinkDoLogowania.php`, `LoginLinkController.php` i widoki
`auth/login-link*` są **czytane i cytowane, nie edytowane**. Wszystkie numery
linii w tym dokumencie sprawdzone na `origin/main` = **8fa3cc9**, czyli JUŻ PO
scaleniu PR #325 (ten PR zmienił w tej ścieżce same teksty: list mówi teraz
o przycisku „Zaloguj mnie w Kuking" zamiast o „zielonym przycisku"). Nowe pliki
w tej gałęzi to komenda pomiarowa, dwie klasy domenowe, jeden test i ten
dokument.

**`docs/DECISIONS.md` nie jest w tej gałęzi ruszony i numeru decyzji ten
dokument NIE zajmuje.** Rozstrzygnięcie należy do właściciela, a wpis dostanie
numer po jego decyzji — w tym dokumencie przyszła decyzja jest konsekwentnie
oznaczana jako **D-???**. Powód jest też techniczny: równolegle pracuje kilku
agentów, na `main` najwyższy wpis to dziś D-106, a D-107 wziął już ktoś inny,
więc dopisek pod zgadniętym numerem rozjechałby odsyłacze. **D-084 i D-086 są
świadomie niewykorzystane i tych dziur nie wolno zapełniać.**

---

## 1. Droga wejścia — sprawdzona przy pliku, nie przepisana z issue

Issue #317 opisuje sześć kroków. Wszystkie sześć potwierdzam przy pliku,
a **cztery rzeczy okazały się inne, niż mówi issue** — trzy z nich są gorsze.
Wypisuję je w §1.2, bo to one, a nie sama droga, decydują o wyborze wariantu.

### 1.1. Krok po kroku: kto co wpisuje, jaki wiersz powstaje, który warunek przepuszcza

| # | Co się dzieje | Gdzie to jest w kodzie |
|---|---|---|
| 1 | Napastnik wchodzi na `/rejestracja`. Rejestracja jest otwarta domyślnie (`KUKING_REGISTRATION_OPEN`, domyślnie `true`) | `config/kuking.php:264`, `RegisterController.php:40,50` |
| 2 | Wpisuje **adres ofiary** i **swoje** hasło. Walidacja przepuszcza, bo na tym adresie konta jeszcze nie ma (`Rule::unique('users','email')`) | `RegisterController.php:130` |
| 3 | `RegisterController` woła `ZalozKonto::handle()` **bez** argumentu `emailPotwierdzony` i **bez** `dowodAdresu` (bo napastnik nie przyszedł z zaproszenia) | `RegisterController.php:191-210` |
| 4 | Wartość domyślna parametru to `false`, więc adres wchodzi przez `assignEmail($email, false)` | `ZalozKonto.php:77`, `ZalozKonto.php:117` |
| 5 | **POWSTAJE WIERSZ**: `users` z `email` = adres ofiary, `password` = skrót hasła napastnika, `status='active'`, `role='user'`, **`email_verified_at = NULL`** | `User.php:847-853` (`'email_verified_at' => $potwierdzony ? now() : null`) |
| 6 | Na skrzynkę **ofiary** idzie list z potwierdzeniem adresu (`event(new Registered(...))`). Ofiara go ignoruje albo nie rozumie — a gdyby kliknęła, trafia na trasę pod `auth`, czyli na ekran logowania, na które nie zna hasła | `ZalozKonto.php:152`, `routes/web.php:455,461-463` |
| 7 | Napastnik czeka. Konto **działa w pełni**: publikuje, komentuje, „Ugotowałem" — patrz §1.2 punkt A | — |
| 8 | Ofiara przychodzi na `/logowanie/link` i wpisuje swój adres | `routes/web.php:275-278` |
| 9 | `WyslijLinkDoLogowania::handle()` znajduje konto po adresie i pyta `wolnoWyslac()` | `WyslijLinkDoLogowania.php:105-118` |
| 10 | **WARUNEK, KTÓRY PRZEPUSZCZA.** `wolnoWyslac()` sprawdza trzy rzeczy: czy konto istnieje, czy status nie jest w `STATUSY_ZAMKNIETEGO_KONTA`, czy to nie moderator. **O potwierdzeniu adresu nie pyta.** `grep -n 'email_verified\|hasVerifiedEmail'` na tym pliku → **0 trafień**, potwierdzone na `origin/main` (61eb189) | `WyslijLinkDoLogowania.php:326-339` |
| 11 | Powstaje wiersz `login_link_tokens` (skrót HMAC, 30 minut) i list z linkiem idzie **na prawdziwą skrzynkę ofiary** | `WyslijLinkDoLogowania.php:120-176` |
| 12 | Ofiara klika. Ekran z przyciskiem pokazuje nazwę wyświetlaną **napastnika** i skrót adresu **ofiary** | `login-link-confirm.blade.php:22-25` |
| 13 | Ofiara klika „Zaloguj mnie". `store()` zużywa token pod dwiema blokadami, sprawdza status, rolę i 2FA — **o potwierdzeniu adresu nie pyta tu też** — i loguje `remember: true` | `LoginLinkController.php:317-421` |

Od tej chwili ofiara gotuje na koncie napastnika: wrzuca zdjęcia, przepisy
rodzinne, „Ugotowałem". Napastnik zna hasło, więc widzi wszystko, co ofiara
doda — i nic w serwisie nie mówi ofierze, że coś jest nie tak.

### 1.2. Cztery rzeczy, których w issue nie ma (trzy są gorsze, jedna węższa)

**A. Konto z niepotwierdzonym adresem nie jest niczym ograniczone — i to jest
zmierzone, nie domniemane.** `grep` po całym `app/`, `routes/` i `bootstrap/`:
middleware `verified` **nie występuje ani raz**, a `hasVerifiedEmail()` jest
wołane w **trzech** miejscach i żadne z nich nie jest bramką
(`EmailVerificationController:37,59` — dwa razy „już potwierdzony, idź na
`/home`" — oraz `CollectUserExportData:90`, gdzie wartość tylko wchodzi do
paczki z danymi). **W tym repozytorium nie ma dziś ani jednego miejsca, które
wymaga potwierdzonego adresu.** Ma to dwie konsekwencje: konto napastnika jest
w pełni użyteczne (świadomie — `RegisterController.php:31-35` mówi wprost, że
weryfikacja nie blokuje pierwszej publikacji), a **zamknięcie logowania
linkiem jest jedyną rzeczą, jaką potwierdzenie adresu w ogóle zmienia** —
naprawa nie „odblokuje" przy okazji niczego innego.

> **Osobne, mniejsze ustalenie do własnego issue.** Dwa komentarze w kodzie
> mówią, że potwierdzenie adresu jest wymagane „do rzeczy nieodwracalnych
> (zmiana e-maila, eksport danych)" (`RegisterController.php:31-35`) i „tylko
> przy eksporcie danych" (`routes/web.php:458-459`). **Kod tego nie robi** —
> zmiana adresu i eksport pytają o HASŁO (`EmailSettingsController.php:83,111`,
> `DataSettingsController.php:205`), nie o potwierdzenie. Nie ruszam tego
> w tym zleceniu (to nie pomiar i nie ta ścieżka), ale te dwa zdania kłamią
> i ktoś się o nie oprze.

**B. Ofiara nie może przejąć konta, na które właśnie weszła.** Zmiana adresu
e-mail wymaga obecnego hasła (`EmailSettingsController.php:83,111`) i zmiana
hasła też (`SecuritySettingsController.php:44,56`). Ofiara zalogowana linkiem
hasła napastnika nie zna, więc **nie ma z tego ekranu żadnej drogi do
wyrzucenia go z konta.** Issue mówi „napastnik zna hasło i ma dostęp do
wszystkiego" — jest gorzej: ofiara nie ma czym mu tego dostępu odebrać, nawet
gdy się domyśli.

**C. Jest druga droga wejścia na to samo niepotwierdzone konto — i ona
napastnika WYRZUCA.** `/nie-pamietam-hasla` o potwierdzenie adresu nie pyta
tak samo jak logowanie linkiem, ale kończy się **ustawieniem nowego hasła**
i wywołaniem `invalidateSessions()` (`PasswordResetController.php:129`), które
kasuje sesje, `remember_token` **i oczekujący link do logowania**
(`User.php:1085-1108`). Czyli: reset hasła jest dziś drogą, którą ofiara
przejmuje konto i zamyka napastnikowi drzwi, a logowanie linkiem jest drogą,
którą wchodzi **obok niego**, nie zmieniając niczego. Ta asymetria jest, moim
zdaniem, najważniejszą rzeczą do wykorzystania w naprawie (patrz Wariant B)
i w issue jej nie ma.

**D. Jedno zawężenie wobec issue — droga wymaga OTWARTEJ rejestracji.**
Krok pierwszy idzie przez `/rejestracja`, a ta trasa odpowiada 503 przy
`KUKING_REGISTRATION_OPEN=false` (`RegisterController.php:40,50`). Druga droga
do założenia konta — z zaproszenia (D-085) — **tworzy konto z adresem już
potwierdzonym** (`ZalozKonto.php:103-107`: adres bierze się z wiersza w bazie,
`emailPotwierdzony = true`), więc **tą drogą pre-hijackingu zrobić nie da
się**. Nie zmierzyłem, jaka wartość `KUKING_REGISTRATION_OPEN` stoi dziś na
produkcji — to jedna z rzeczy, które właściciel wie, a ja nie (`docs/OTWARCIE.md`
mówi o zamkniętej alfie, ale `.env` produkcyjnego nie widziałem). Jeśli
rejestracja jest tam zamknięta, droga jest **dziś nieosiągalna dla nowych
kont** i sprawa z P1 robi się pracą do wykonania przed otwarciem, a nie tej
nocy. **To jest jedyne pytanie, na które odpowiedź może zmienić pilność, i nie
umiem na nie odpowiedzieć z repozytorium.**

### 1.3. Czego droga NIE omija (sprawdzone, żeby nie przecenić usterki)

- **2FA.** Konto z potwierdzoną weryfikacją dwuetapową trafia po kliknięciu
  na `/logowanie/kod` (`LoginLinkController.php:408-413`). Napastnik, który
  chce dostać się do konta ofiary z 2FA, tą drogą nie wejdzie — ale tu
  kierunek ataku jest odwrotny (to ofiara wchodzi na konto napastnika),
  a napastnik 2FA sobie nie włączy, bo to popsułoby mu cały plan.
- **Konta zamknięte i konta obsługi serwisu.** `wolnoWyslac()` odrzuca oba
  (`WyslijLinkDoLogowania.php:332-338`) — i to jest dokładnie ta część, którą
  pomiar z §2 odejmuje od liczby kosztu.
- **Wyrocznię o istnieniu konta.** Odpowiedź formularza jest dziś
  nieodróżnialna i pilnuje tego test porównujący CAŁĄ odpowiedź
  (`LogowanieLinkiemTest.php:371-405`, wzorzec z #284). Każdy wariant z §3
  musi to utrzymać.

### 1.4. Dlaczego Google tego nie tworzy

D-069 reguła 2 (`GoogleLoginController.php:634-645`) odmawia połączenia konta
Google z kontem Kuking, którego adres jest u nas niepotwierdzony — i jej
komunikat mówi wprost, że to zabezpieczenie przed tym właśnie atakiem.
Google zamyka więc tę drogę **po swojej stronie**; problem jest w logowaniu
linkiem i jest starszy. Ta sama rzecz była już raz nazwana w #258 punkt 1
(„klasyczne miejsce na przejęcie konta, jeśli zaufa się adresowi bez
weryfikacji") i w #259, gdzie brak `email_verified` u Facebooka jest wypisany
jako główny koszt tamtej drogi.

---

## 2. Pomiar

### 2.1. Co powstało

| Plik | Rola |
|---|---|
| `app/Domain/Security/KontaBezPotwierdzonegoAdresu.php` | reguła „kto się liczy" — jedno miejsce, wzorem `CookEligibility` |
| `app/Domain/Security/PomiarKontBezPotwierdzenia.php` | wynik: dwanaście liczb + procenty |
| `app/Console/Commands/PoliczKontaBezPotwierdzenia.php` | `php artisan kuking:konta-bez-potwierdzenia` — tylko formatuje |
| `tests/Feature/PomiarKontBezPotwierdzeniaTest.php` | kontrola dodatnia, zgodność z `wolnoWyslac()`, pusta baza |

Komenda **tylko czyta** — kilka `SELECT count(*)`, ani jednego zapisu, ani
jednego listu. Wolno ją uruchomić na produkcji i po to powstała.

### 2.2. Dlaczego to nie jest jedna liczba

Issue pyta „ile jest dziś kont bez `email_verified_at`". Ta liczba jest
**zawyżona** i nie widać tego z zewnątrz:

1. **`EraseAccountData` przy anonimizacji konta sam ustawia
   `email_verified_at = NULL`** (`EraseAccountData.php:289`, D-022), razem
   z adresem, hasłem i nazwą. Każde konto po wykonanej karencji siedzi więc
   w naiwnym `whereNull(...)` — a straci przez naprawę **zero**, bo na status
   `erased` link nie idzie już dziś.
2. **Konta obsługi serwisu** (`moderator`, `admin`) linku nie dostają
   w ogóle (D-056, rozstrzygnięcie 4).
3. **Konta zalążkowe** (`is_seeded`, D-025) to persony z pliku, nie ludzie.

Komenda wypisuje więc liczbę z pytania **oraz każde odjęcie osobno**, a na
końcu liczbę, która naprawdę odpowiada na „komu zamkniemy drzwi":
**konto bez potwierdzenia, którego status przepuszcza link, nie zalążkowe,
nie obsługa serwisu.** `suspended` **w tej liczbie zostaje** — zawieszenie nie
jest w `STATUSY_ZAMKNIETEGO_KONTA`, więc takie konto link dziś dostaje.

„Żywe" znaczy: ma choć jeden wpis, przepis, komentarz albo „Ugotowałem".
Liczymy też szkice i wpisy prywatne (nieopublikowany przepis rodzinny jest
dokładnie tą rzeczą, którą człowiek traci razem z drogą wejścia); nie liczymy
treści skasowanej miękko.

### 2.3. Wynik na bazie lokalnej

```
$ php artisan kuking:konta-bez-potwierdzenia          # baza `kuking`, dane z DemoSeeder
Wszystkich kont w bazie: 15
Bez potwierdzonego adresu (samo `email_verified_at IS NULL`): 0 — 0% wszystkich kont
KONTA, KTÓRYM ZAMKNIĘCIE TEJ DROGI NAPRAWDĘ ZABIERA WEJŚCIE: 0 — 0% wszystkich kont
```

**Ta liczba nic nie mówi o produkcji i nie wolno jej tak przeczytać.**
Wszystkie 15 kont lokalnych pochodzi z `DemoSeeder`, który zakłada je
z adresem potwierdzonym. Zerowy wynik jest tu **dowodem, że komenda chodzi na
prawdziwej bazie i nie wywraca się**, a nie pomiarem populacji.
**Liczby dla produkcji nie zmierzyłem — do bazy produkcyjnej nie mam i nie
chcę mieć dostępu.** Komenda jest gotowa do uruchomienia u właściciela.

### 2.4. Kontrola dodatnia: podstawione dane, w których znam odpowiedź

Osobna baza `kuking_pomiar_317` (utworzona i po pomiarze usunięta; bazy
`kuking` ani produkcyjnej to nie dotknęło), `php artisan migrate`, potem
**dziesięć wierszy wstawionych ręcznie SQL-em** — 3 konta z potwierdzonym
adresem i 7 bez, w tym: 2 puste rejestracje, 1 z jednym wpisem, 1
zablokowane, 1 `erased`, 1 moderator, 1 zalążkowe. Oczekiwany wynik znałem
przed uruchomieniem, bo sam go wstawiłem.

```
Wszystkich kont w bazie: 10
Bez potwierdzonego adresu (samo `email_verified_at IS NULL`): 7 — 70% wszystkich kont

Z tej liczby ODPADA (bo linku do logowania te konta NIE DOSTAJĄ JUŻ DZIŚ):
 konta zamknięte — zablokowane, w trakcie usuwania, usunięte: 2
 konta zalążkowe z pliku (is_seeded): 1
 konta obsługi serwisu — moderator, administrator: 1

KONTA, KTÓRYM ZAMKNIĘCIE TEJ DROGI NAPRAWDĘ ZABIERA WEJŚCIE: 3 — 30% wszystkich kont
 z tego ŻYWE (mają wpis, przepis, komentarz albo „Ugotowałem”): 1
 z tego PUSTE REJESTRACJE (nie zrobiły nic): 2

Rozbicie żywych po rodzaju wpisu (konto może być w kilku wierszach naraz):
 mają wpis: 1
 mają przepis: 0
 mają komentarz: 0
 mają „Ugotowałem”: 0

Byli tu kiedykolwiek zalogowani (ostatnio_widziany_at): 2
```

Wszystkie dwanaście liczb zgadza się z tym, co wstawiłem — co do jednej.

Drugi raz to samo, z drugiej strony, na populacji 16 kont w teście
`PomiarKontBezPotwierdzeniaTest::test_pomiar_na_populacji_o_znanym_skladzie_podaje_dokladnie_te_liczby`
(13 bez potwierdzenia → 3 zamknięte, 1 zalążkowe, 2 obsługa → **7
dotkniętych, z tego 4 żywe**), z osobną asercją na KAŻDĄ liczbę, bo jedna
asercja przechodziłaby także wtedy, gdyby pozostałe jedenaście liczyło coś
innego, niż mówi ich nazwa.

### 2.5. Że pomiar liczy to, co twierdzi

Odjęcia z §2.2 twierdzą „linku i tak nie dostają". To jest sprawdzalne tylko
jednym sposobem i test
`test_pomiar_zgadza_sie_z_tym_komu_dzis_wolno_wyslac_link` robi dokładnie to:
wysyła prawdziwy formularz i liczy listy. Pierwsza asercja jest **dodatnia**
i jest zapisem usterki z #317 na żywo — **konto z pustym `email_verified_at`
list z wejściem na konto DOSTAJE**. Bez niej cztery asercje „nie dostał"
przechodziłyby także przy niedziałającej poczcie (`PULAPKI_TESTOW.md` §4).

Ten test jest też jedyną rzeczą, która zauważy rozjazd pomiaru
z `wolnoWyslac()`: gdy ta metoda kiedyś dostanie czwarty warunek — na
przykład ten z naprawy #317 — test **oblewa** i każe poprawić pomiar razem
z kodem.

### 2.6. Kontrole ujemne

| # | Sabotaż | Wynik |
|---|---|---|
| 1 | `dotknieci()` bez `whereNotIn('role', …)` | **OBLANE** — „9 is identical to 7" i „3 is identical to 1" |
| 2 | `dotknieci()` bez `where('is_seeded', false)` | **OBLANE** — „8 is identical to 7" |
| 3 | `osiagalniLinkiem()` bez odcięcia `STATUSY_ZAMKNIETEGO_KONTA` | **OBLANE** — „10 is identical to 7" |
| 4 | `dotknieciZywi` bez nawiasu (`orWhereHas` rozrywa warunki roli i statusu) | **OBLANE** — „5 is identical to 4" |
| 5 | `whereNull('email_verified_at')` → `whereNotNull` | **OBLANE** — „3 is identical to 13" |
| 6 | komenda wypisuje `dotknieciZywi` tam, gdzie policzyła `dotknieci` | **OBLANE** — brak oczekiwanego wiersza w wyjściu |
| 7 | `wolnoWyslac()` **naprawione** (dodany warunek `email_verified_at === null`) | **OBLANE** — „notification was not sent"; test przyszłej naprawy nie przepuści po cichu |

Po każdym sabotażu kod przywrócony, `git status` czysty.

**Pułapka 3 z `PULAPKI_TESTOW.md` złapała mnie po drodze i warto to zapisać:**
sabotaż nr 5 przy pierwszej próbie „przeszedł", co wyglądało na test-atrapę.
Przyczyną był **zły sabotaż, nie zły test** — podstawienie przez `perl` wewnątrz
podwójnych cudzysłowów basha nie weszło do pliku. Powtórzone z weryfikacją
(`grep` na zmienionej linii przed uruchomieniem testu) oblało natychmiast.
To samo zdarzyło się sabotażowi nr 7, gdzie backticki w łańcuchu wykonał bash.
**Kontrola ujemna bez sprawdzenia, że sabotaż naprawdę wszedł do pliku, jest
warta tyle, co jej brak.**

---

## 3. Trzy warianty naprawy z policzonym kosztem

Wspólny wymóg dla wszystkich trzech, z którego nie wolno zejść:
**odpowiedź formularza `/logowanie/link` musi zostać nieodróżnialna dla adresu
z kontem i bez konta** (D-056, D-075, D-085) i musi tego pilnować test
porównujący CAŁĄ odpowiedź, nie jedno słowo. Komunikat „ten adres nie jest
potwierdzony" jest **wykluczony we wszystkich wariantach** — mówi wprost, że
konto istnieje.

### Wariant A — odmowa po cichu

**Co trzeba napisać.** Jeden warunek w `WyslijLinkDoLogowania::wolnoWyslac()`
(4 linie) plus komentarz. Nic więcej: `handle()` oddaje wtedy `false` tą samą
furtką, którą wychodzi konto zamknięte, kontroler zwalnia miejsce w budżecie
(`LoginLinkController.php:189-196`) i oddaje **ten sam komunikat co dziś**.

**Co się stanie z kontami bez potwierdzonego adresu.** Tracą drogę, której
używają. Zostaje im hasło (jeśli je pamiętają) i `/nie-pamietam-hasla`, które
dla niepotwierdzonego adresu działa i **wyrzuca napastnika** (§1.2 C) — ale
nikt im tego nie powie, bo formularz linku musi milczeć.

**Czy łamie D-056.** Nie. Wychodzi tą samą gałęzią co dwa istniejące
przypadki ciszy, więc nieodróżnialność zostaje bez jednej zmiany w kontrolerze.

**Co widzi człowiek.** Dokładnie to samo zdanie co dziś („Jeśli na adres
b***@wp.pl jest konto w Kuking, wysłaliśmy tam wiadomość z jednym
przyciskiem…") — **i nic nie przychodzi.** To jest cicha ściana: ten sam
kształt porażki, o który odbiła się prawdziwa 63-letnia osoba i który D-085
naprawiało dla adresu bez konta (`WyslijLinkDoLogowania.php:41-47`). Wariant A
wprowadza ją z powrotem, tylko dla innej grupy.

**Testy:** 4. Odmowa dla niepotwierdzonego · konto potwierdzone dalej dostaje
(kontrola dodatnia) · nieodróżnialność całej odpowiedzi · aktualizacja
`PomiarKontBezPotwierdzeniaTest` (§2.5 oblewa po tej zmianie i ma oblać).

**Ryzyko.** Liczba `dotknieci` z §2.3 to liczba ludzi, którzy z dnia na dzień
przestają wchodzić na konto i nie dowiedzą się, dlaczego. Przy niezerowej
liczbie żywych kont ten wariant jest tańszy dla programisty i droższy dla
ludzi niż pozostałe dwa. **Napastnik zostaje na koncie** — wariant A zamyka
wejście ofierze, nie usuwa napastnika.

### Wariant B — inny list: potwierdzenie skrzynki zamiast wejścia na konto

Kierunek z issue #317, rozpisany. Zamiast odmawiać, wysyłamy **inną
wiadomość**: nie link wchodzący na konto, tylko list „potwierdź, że ta
skrzynka jest Twoja" — a po potwierdzeniu **unieważniamy hasło napastnika**
i prosimy o ustawienie nowego.

**Co trzeba napisać.**

1. Rozgałęzienie w `WyslijLinkDoLogowania::handle()` (nie w kontrolerze —
   kontroler nie ma prawa zobaczyć, czy konto istnieje; to jest już rozpisane
   w komentarzu tej klasy dla D-085 i idzie tą samą drogą).
2. Nowa akcja domenowa + powiadomienie + token. **Nie da się użyć istniejącej
   weryfikacji adresu**, bo `verification.verify` żyje pod middleware `auth`
   (`routes/web.php:455,461`) — a tu człowiek właśnie **nie jest** zalogowany
   i to jest cały punkt. Do przemyślenia: nowa tabela obok
   `login_link_tokens`, czy kolumna „rodzaj" w tej istniejącej (wtedy CHECK
   i migracja + `docs/DATABASE.md` + rollback, `AGENTS.md` §6).
3. Dwie trasy: **GET z ekranem i przyciskiem** oraz POST, który coś zmienia.
   To nie jest ozdoba, to rozstrzygnięcie 1 z D-056: skanery odnośników
   w poczcie otwierają linki GET-em przed człowiekiem, a tutaj GET, który
   potwierdza adres i kasuje hasło, byłby oddany tym skanerom.
4. Potwierdzenie ustawia `email_verified_at`, woła `invalidateSessions()`
   (kasuje sesje napastnika, `remember_token` i oczekujący link —
   `User.php:1085-1108`), zeruje hasło na losowe i przeprowadza człowieka
   przez ustawienie nowego. Wpis w `audit_log` przy każdym kroku.
5. Teksty według `docs/brand/COPY_STYLE.md`, w tym **zdanie o tym, że na
   koncie jest już cudza treść** — bo po potwierdzeniu ofiara dostaje konto
   z wpisami napastnika i nie wolno jej tym zaskoczyć.

**Co się stanie z kontami bez potwierdzonego adresu.** Nie tracą nic: dalej
wchodzą pocztą, tylko po drodze potwierdzają adres i ustawiają hasło. Koszt
dla człowieka: dwa kliknięcia i jedno pole więcej **raz w życiu**.

**Czy łamie D-056.** Nie — i jest pod tym względem **mocniejszy od stanu
dzisiejszego**. W obu przypadkach wychodzi jeden list i formularz oddaje jedno
zdanie („sprawdź skrzynkę"), więc nieodróżnialność zostaje; budżet dobowy
poczty zajmuje się jednakowo, czyli znika też to teoretyczne wąskie gardło
enumeracyjne, które D-056 zapisało jako znane ryzyko, a D-085 zamknęło dla
adresu bez konta.

**Co widzi człowiek.** Na ekranie: to samo zdanie co dziś. W skrzynce: list
mówiący, co się stało i co zrobić („na Twój adres jest u nas konto, którego
nikt nie potwierdził — potwierdź, że skrzynka jest Twoja, ustawisz nowe hasło
i wejdziesz"). Potem ekran z jednym przyciskiem, potem jedno pole na hasło.

**Testy:** 10-14. Droga szczęśliwa · GET niczego nie zużywa · token
jednorazowy i wygasający · potwierdzenie unieważnia hasło i sesje napastnika
(kontrola ujemna: bez `invalidateSessions()` napastnik wchodzi dalej) ·
konto z potwierdzonym adresem dostaje **stary** link, nie nowy · odpowiedź
nieodróżnialna dla trzech przypadków (konto potwierdzone, niepotwierdzone,
brak konta) · 2FA nie jest omijane · konto zamknięte i moderator nadal nic nie
dostają · budżet poczty zajmowany raz · pełna droga z sześciu kroków issue
jako test regresyjny z kontrolą ujemną.

**Ryzyko.** Największy nakład z trzech i najwięcej nowych powierzchni
(tabela, dwie trasy, dwa widoki, nowy list). Do rozstrzygnięcia przez
właściciela zostaje pytanie, którego kod nie rozstrzyga: **czy ofiara ma
dostać konto z cudzą treścią, czy raczej puste konto i cudzą treść do
skasowania.**

### Wariant C — zamknąć to po stronie rejestracji, logowania linkiem nie ruszać

Rejestracja idzie **zawsze** przez dowód posiadania skrzynki — czyli tym
mechanizmem, który **już jest w repozytorium** i który D-085 zbudowało:
`RegistrationInvite` + `ZaproszenieWSesji` + `ZalozKonto($dowodAdresu)`
(`ZalozKonto.php:85-107`). Dziś wchodzi się nim tylko z formularza logowania
linkiem; tu stałby się jedyną drogą. Konto z niepotwierdzonym adresem
przestaje być stanem osiągalnym dla **nowych** kont, a `wolnoWyslac()`
zostaje nietknięte.

**Co trzeba napisać.** Zmianę w `RegisterController` (dziś w rękach innego
agenta — koordynacja obowiązkowa), rozszerzenie zaproszeń o wejście
z `/rejestracja`, teksty dwuekranowej rejestracji, migracja nie jest
potrzebna.

**Co się stanie z kontami bez potwierdzonego adresu.** **Nic** — i to jest
zarówno największa zaleta, jak i dyskwalifikująca wada tego wariantu: liczba
`dotknieci` z §2.3 to liczba kont, dla których **dziura zostaje otwarta**.
Wariant C zamyka drogę nowym napastnikom i nie zamyka jej tym, którzy już
czekają. Jest połową naprawy, a nie naprawą.

**Czy łamie D-056.** Nie dotyka go w ogóle.

**Co widzi człowiek.** Rejestracja z jednego ekranu robi się dwuetapowa:
adres → skrzynka → resztę danych. D-085 pokazało, że ta droga działa, ale to
jest zmiana **głównego leja wejścia** dla grupy, w której 12,3% osób
w wieku 65-74 ma podstawowe umiejętności cyfrowe — czyli ryzyko produktowe
większe niż sama usterka.

**Testy:** 6-8, plus przejrzenie wszystkiego, co dziś zakłada konto
z niepotwierdzonym adresem.

### Porównanie na jednym ekranie

| | A — odmowa | B — inny list | C — rejestracja |
|---|---|---|---|
| Zamyka dziurę dla istniejących kont | tak | tak | **nie** |
| Zamyka dziurę dla nowych kont | tak | tak | tak |
| Usuwa napastnika z konta | **nie** | tak | nie dotyczy |
| Koszt dla kont bez potwierdzenia | **tracą wejście, bez wyjaśnienia** | dwa kliknięcia raz w życiu | zero |
| Łamie D-056 | nie | nie (mocniejszy) | nie dotyczy |
| Nowa migracja | nie | prawdopodobnie tak | nie |
| Testy | 4 | 10-14 | 6-8 |
| Rusza pliki innych gałęzi | `WyslijLinkDoLogowania` | `WyslijLinkDoLogowania` + nowe | **`RegisterController`** |

---

## 4. Rekomendacja

**Wariant B.** Jest jedynym z trzech, który zamyka drogę wejścia, nie
otwierając żadnej innej i nie zamykając niczego ludziom: konta bez
potwierdzonego adresu dalej wchodzą pocztą, tylko po drodze raz potwierdzają
skrzynkę i ustawiają hasło — a to przy okazji jako jedyne **usuwa napastnika
z konta**, czego wariant A nie robi wcale i co §1.2 B pokazuje jako rzecz,
której ofiara nie umie zrobić sama. Wariant A jest o rząd wielkości tańszy
w napisaniu, ale płaci za to cichą ścianą — tym samym kształtem porażki,
który ten projekt raz już świadomie usunął w D-085, po tym jak odbiła się
o niego prawdziwa osoba z grupy docelowej; przy niezerowej liczbie żywych kont
bez potwierdzenia (§2.3) jest to koszt ponoszony przez ludzi, żeby oszczędzić
dzień pracy. Wariant C jest połową naprawy i dodatkowo rusza główny lej
wejścia, czyli bierze największe ryzyko produktowe za najmniejszy zysk
bezpieczeństwa. **Pomiar z §2 nie zmienia tego wyboru — zmienia tylko
pilność:** jeśli `dotknieci` wyjdzie zero, wariant B można spokojnie
zaplanować, a jeśli wyjdzie kilkadziesiąt żywych kont, warto najpierw
sprawdzić, czy `KUKING_REGISTRATION_OPEN` na produkcji nie jest już `false`
(§1.2 D), bo wtedy dziura i tak nie przyjmuje nowych przypadków.

### 4.1. PROPOZYCJA treści decyzji — do podpisu, nie wpis obowiązujący

Poniższe **nie jest** wpisem w `docs/DECISIONS.md` i numeru nie ma świadomie.
To gotowy szkielet, który właściciel może przyjąć, odrzucić albo przepisać;
numer nadaje dopiero on, przy wpisywaniu.

> **D-??? · Adres niepotwierdzony nie dostaje linku wchodzącego na konto, tylko
> list potwierdzający skrzynkę — a potwierdzenie unieważnia hasło**
>
> Prośba o link na adres, na którym konto istnieje, ale nikt nie potwierdził
> skrzynki, wysyła **inną wiadomość**: nie wejście na konto, a potwierdzenie
> adresu. Po kliknięciu (GET pokazuje ekran, POST zmienia stan — D-056
> rozstrzygnięcie 1) adres zostaje potwierdzony, `invalidateSessions()` kasuje
> sesje i oczekujące linki, hasło zostaje unieważnione i człowiek ustawia nowe.
> **Odpowiedź formularza `/logowanie/link` pozostaje nieodróżnialna** dla
> adresu z kontem potwierdzonym, z kontem niepotwierdzonym i bez konta —
> w każdym z trzech przypadków wychodzi jeden list i jedno zdanie na ekranie
> (D-056, D-075, D-085). Nie zmienia się nic w drodze kont z potwierdzonym
> adresem.
>
> Powód: patrz ten dokument §1 (droga przejęcia) i §3 (dlaczego nie odmowa
> i nie zmiana rejestracji).

---

## 5. Czego NIE zmierzyłem

- **Liczby kont na produkcji.** Komenda jest gotowa; bazy produkcyjnej nie
  dotknąłem i nie mam do niej dostępu.
- **Wartości `KUKING_REGISTRATION_OPEN` na produkcji** — a od niej zależy,
  czy droga jest dziś osiągalna dla nowych kont (§1.2 D).
- **Czy ktoś już tak zrobił.** `audit_log` ma
  `account.login_link_requested` i `account.login_link_used`, więc dałoby się
  poszukać wejść linkiem na konta z pustym `email_verified_at` — ale to
  zapytanie do bazy produkcyjnej i osobne zlecenie.
- **Facebooka** (#259). Facebook nie podaje `email_verified` w ogóle, więc
  reguła 2 z D-069 nie ma tam z czego powstać i ten sam atak wraca trzecią
  drogą. Poza zakresem #317, ale wybór wariantu tutaj przesądza, co da się
  napisać tam.
