## D-069 · Wejście kontem Google: własny kod, `email_verified` jako warunek, konta łączymy tylko za zgodą człowieka, bez Turnstile

**Data:** 10 września 2026 · Issue #258 · **Decyzja właściciela co do terminu**
· Status: **obowiązuje**

> **JEDNA RZECZ W TYM WPISIE ZOSTAŁA ZMIENIONA, ZANIM COKOLWIEK POSZŁO NA
> PRODUKCJĘ: gdzie leży powiązanie.** Rozstrzygnięcie 4 mówi niżej „w bazie
> zostają DWIE kolumny na `users`" i wskazuje tabelę `tozsamosci_zewnetrzne`
> jako właściwy kształt „przy drugim dostawcy". Drugi dostawca (Facebook)
> został w tym czasie **zamówiony przez właściciela wprost**, więc próg
> powrotu został przekroczony jego własnym kryterium — powiązania leżą od
> tej pory w tabeli `tozsamosci_zewnetrzne`: patrz **D-098**. Reszta tego
> wpisu — trzy reguły łączenia kont, `email_verified` jako warunek, zakres
> `openid email profile`, brak Turnstile, brak sprawdzania podpisu tokenu —
> **obowiązuje bez zmian**, a D-098 jej nie podważa. Czytając niżej
> „`google_sub`" i „`google_connected_at`", czytaj „wiersz
> w `tozsamosci_zewnetrzne`".

Kuking wpuszcza na konto **kontem Google**. Droga jest **dodatkowa**, nigdy
jedyna: hasło i wiadomość z linkiem (D-056) zostają na ekranie logowania
obok niej. Adresy: `/wejdz/google`, `/wejdz/google/wroc`,
`/wejdz/google/domknij`, `/wejdz/google/polacz`.

### DLACZEGO TERAZ, PRZED PODPISANIEM UMOWY POWIERZENIA

Właściciel rozstrzygnął, że funkcja wchodzi **przed kampanią startową**,
świadomie przyjmując, że umowa powierzenia z Google jeszcze nie jest
podpisana (issue #8, prawnik). Powód biznesowy: kampania idzie przez
Facebooka, ludzie klikają z telefonu z Androidem, gdzie konto Google jest już
zalogowane — jedno kliknięcie zamiast wymyślania hasła.

**To jest jedyne otwarte ryzyko tej funkcji i jest ono formalne, nie
techniczne.** Polityka prywatności mówi o tym wprost i nie udaje inaczej:
akapit „Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie
mamy podpisanych" obejmuje od dziś także Google. Wszystko, co dało się
domknąć kodem, jest w tym wpisie domknięte.

Bezpośredni powód biznesowy jest zmierzony na człowieku: 63-letnia mama
właściciela nie założyła konta — odbiła się o walidację nazwy, potem czekała
na wiadomość, która nie miała przyjść (issue #258, issue #25).

### ROZSTRZYGNIĘCIE 1: WŁASNY KOD, BEZ `laravel/socialite`

Kryterium jest to samo, którym **D-065** odesłał trzy pakiety: pakiet wchodzi
wtedy, gdy usuwa **nazwany, dziś istniejący problem**. Zestawienie:

| | `laravel/socialite` | własny kod (`App\Google\KlientGoogle`) |
|---|---|---|
| Co daje | ~30 klas dostawców, z których używamy jednego | jedno przekierowanie + jedno żądanie `POST` z sześcioma polami |
| Na czym stoi | własny klient HTTP i własna warstwa sesji | `Illuminate\Support\Facades\Http` i sesja Laravela — to, co już mamy |
| PKCE dla Google | nie domyślnie — trzeba dopisać kod na jego wnętrznościach | jest od pierwszego dnia, ~10 linii |
| `email_verified` | surowy element tablicy `$user->user` obok wygodnego `getEmail()` | osobne, wymagane pole `TozsamoscGoogle::$emailPotwierdzony` |
| Zdjęcie z Google | pobiera awatar domyślnie | nie prosimy o nie wcale |
| Aktualizacje | cudze | nasze |
| Rozmiar | jedna zależność w `composer.json` na jedną funkcję | ~200 linii w dwóch klasach |

**Rozstrzygnięcie: własny kod.** Dwa powody są decydujące i oba są
o TYM repozytorium, nie o zwyczajach:

1. **`AGENTS.md` §3 wymienia „kolejną bibliotekę, gdy Laravel ma to
   w standardzie" wśród zakazów.** Ta rozmowa to jeden `POST` po TLS-ie.
   Tą samą drogą poszedł transport poczty (**D-047**) i weryfikacja
   Turnstile (**D-050**) — oba działają i oba są krótsze od integracji
   z pakietem.
2. **Dwie rzeczy, których u nas naprawdę potrzebujemy, pakiet robi
   trudniejszymi, nie łatwiejszymi.** `email_verified` jest u nas
   WARUNKIEM wejścia (rozstrzygnięcie 2), a Socialite ustawia je w cieniu
   wygodnego `getEmail()`; PKCE i tak trzeba dopisać samemu. Kod, który
   MUSI zapytać o potwierdzenie adresu, jest bezpieczniejszy niż kod,
   który MOŻE.

**Cena jest uczciwa i zapisana tutaj:** zmiany po stronie Google są od dziś
naszą robotą. Powierzchnia jest jednak mała i stabilna — dwa adresy i jeden
format tokenu, niezmienione u Google od lat. **Próg powrotu do pakietu:**
drugi dostawca tożsamości (Facebook z issue #258 jako „ewentualnie") ALBO
pierwsza zmiana po stronie Google, której nie da się obsłużyć zmianą jednej
stałej. Wtedy Socialite przestaje być „pakietem na jedną funkcję".

**Podpisu tokenu tożsamości świadomie NIE sprawdzamy** i to nie jest
skrót — OpenID Connect Core §3.1.3.7 pkt 6 zwalnia z tego token odebrany
**wprost z punktu tokenu, po TLS-ie**, czyli dokładnie nasz przypadek (nie
bierzemy tokenu z przekierowania w przeglądarce). Alternatywą byłoby
pobieranie i odświeżanie kluczy publicznych Google (JWKS) plus weryfikacja
RS256 — czyli nowa klasa awarii „logowanie padło, bo nie dociągnęliśmy
kluczy" za zerowy zysk. **Sprawdzamy natomiast wszystko inne z tego
paragrafu:** wydawcę (`iss`), odbiorcę (`aud` musi być NASZ identyfikator
klienta — bez tego token wystawiony dla innej aplikacji Google byłby u nas
dobry), termin (`exp`) i `nonce` wiążący token z tą sesją.

### ROZSTRZYGNIĘCIE 2: ŁĄCZENIE KONT — TRZY REGUŁY, KAŻDA ZAMYKA INNY ATAK

To jest najgroźniejsze miejsce całej funkcji: zaufanie adresowi e-mail bez
dowodu jest gotowym przejęciem konta.

**REGUŁA 1. `email_verified` od Google jest WARUNKIEM. Bez niego nie robimy
nic — ani logowania, ani rejestracji.**

Konto Google nie musi być kontem Gmail: da się je założyć na dowolny cudzy
adres (`ktos@wp.pl`) i korzystać z niego, dopóki nie zostanie potwierdzone.
Token niesie wtedy `email_verified: false`. Bez tego warunku napastnik
zakłada konto Google na adres wybranej osoby i wchodzi u nas na JEJ konto —
albo zakłada u nas konto na jej adres, zabierając jej drogę wejścia, zanim
ona przyjdzie. **To jest najkrótsza znana droga przejęcia konta przy
„zaloguj się przez…" i zamyka ją pierwszy warunek.**

**REGUŁA 2. Konta z NIEPOTWIERDZONYM U NAS adresem nie łączymy nigdy, nawet
gdy Google adres potwierdziło. To jest ta reguła, dla której ta funkcja
w ogóle jest bezpieczna.**

Atak, który tym zamykamy, nazywa się **przejęciem z wyprzedzeniem**
(„pre-hijacking") i u nas jest realny, a nie teoretyczny, bo nasza
rejestracja **świadomie nie wymaga potwierdzenia adresu** przed pierwszą
publikacją (`RegisterController`: osoba, która z trudem założyła konto, nie
może zostać odesłana do skrzynki). Przebieg:

```text
1. napastnik zakłada DZIŚ konto na basia@wp.pl — adresu nie kontroluje,
   hasło zna tylko on, adres zostaje niepotwierdzony;
2. konto jest puste, więc niczym nie zwraca uwagi. Napastnik czeka;
3. przychodzi prawdziwa Basia, kontem Google, z adresem POTWIERDZONYM;
4. automatyczne połączenie wpuszcza JĄ do konta NAPASTNIKA;
5. on zna hasło, widzi wszystko, co ona napisze, i ma wejście, o którym
   ona nie wie. Ona nie zauważa niczego — konto jest puste, więc wygląda
   dokładnie jak świeżo założone.
```

Odmowa jest tu **jedynym poprawnym zachowaniem**, bo z naszej strony te dwa
przypadki („to ta sama osoba" i „to napastnik") są **nieodróżnialne**. Ekran
mówi, co zrobić: wejdź hasłem albo linkiem i **potwierdź adres** — wtedy
wejście kontem Google zacznie działać. Napastnik adresu nie potwierdzi, bo
nie ma skrzynki.

**REGUŁA 3. Konta z potwierdzonym adresem łączymy WYŁĄCZNIE po jawnym
potwierdzeniu przez człowieka na naszym ekranie — jedno kliknięcie więcej.**

Sam atak zamykają już reguły 1 i 2: kto przeszedł weryfikację Google dla
tego adresu, kontroluje skrzynkę, a kto kontroluje skrzynkę, mógł dzisiaj
przejąć to konto przez „Nie pamiętam hasła". Ten ekran zamyka coś innego:
**niespodziankę**. Człowiek widzi, na jakie konto wchodzi („zalogujesz się
jako Basia"), zanim wejdzie — ta sama zasada, dla której link z wiadomości
nie loguje od razu, tylko prowadzi na ekran z przyciskiem (D-056). Z jednej
skrzynki korzysta czasem całe małżeństwo, a Google wchodzi domyślnie kontem
ostatnio używanym (dlatego prosimy go o `prompt=select_account`).

**Czego te reguły nie ukrywają.** Ekran odmowy z reguły 2 mówi wprost, że na
tym adresie jest już konto — czyli odpowiada na pytanie, na które formularz
„Nie pamiętam hasła" świadomie nie odpowiada. **Nie jest to nowa wyrocznia:**
żeby tam dojść, trzeba przejść weryfikację adresu po stronie Google, czyli
mieć dostęp do skrzynki — a kto ma do niej dostęp, dowie się tego samego,
prosząc o przypomnienie hasła i czytając własną pocztę. Cena milczenia byłaby
za to realna: odmowa bez powodu, po której człowiek odchodzi.

**Kolejne wejścia rozpoznajemy po `sub`, NIGDY po adresie.** Adres u Google
da się zmienić, a w Google Workspace da się nadać komuś innemu adres osoby,
która odeszła z firmy. Adres służy dokładnie raz — przy pierwszym połączeniu.

### ROZSTRZYGNIĘCIE 3: NAZWA I ZGODY NA EKRANIE DOMKNIĘCIA KONTA

Google nie da nam trzech rzeczy, których wymaga nasza rejestracja: nazwy do
adresu profilu, oświadczenia o wieku i akceptacji regulaminu. Dlatego droga
przez Google **nie zakłada konta po cichu** — prowadzi na
`/wejdz/google/domknij`:

- **dwa pola, tak jak przy rejestracji hasłem** (decyzja właściciela
  z 10 września: imię widoczne dla innych ORAZ nazwa w adresie profilu),
  z **nazwą PODPOWIEDZIANĄ** przez `App\Support\NazwaUzytkownika::wolnaPropozycja()`
  — z imienia z Google, a gdy go nie ma, z początku adresu. Podpowiedź liczy
  SERWER i sprawdza przy tym, czy nazwa jest wolna, więc nie proponujemy
  czegoś, co odbije się o walidację. Adres profilu ma być świadomym wyborem,
  nie czymś, co człowiek odkrywa po fakcie;
- **dwa oświadczenia, PUSTE.** Zaznaczenie ich za człowieka jest ciemnym
  wzorcem, a przy oświadczeniu o wieku dodatkowo bez wartości: oświadczenie
  złożone przez serwer nie jest niczyim oświadczeniem;
- **konto powstaje z adresem OD RAZU POTWIERDZONYM** i **bez hasła**.
  Google potwierdziło adres, więc nie prosimy o to samo drugi raz (ten sam
  wywód co w `User::assignEmail()`); zysk uboczny to ani jeden list
  z dobowej puli 300 (D-047). W kolumnie `password` (`NOT NULL`) leży skrót
  wartości losowej, **której nie zna nikt, także my** — konto ma wtedy dwie
  drogi wejścia: Google i wiadomość z linkiem, a hasło ustawi sobie przez
  „Nie pamiętam hasła", jeśli zechce.

**Zakładanie konta przeniosło się przy tym do `app/Domain`**
(`App\Domain\Users\Actions\ZalozKonto`), bo od dziś woła je DWA miejsca.
Dwie kopie listy „profil, powitanie, oświadczenie o wieku, wiadomość
z potwierdzeniem, wpis w dzienniku, obserwowanie gospodarza" rozjechałyby
się przy pierwszej zmianie (`AGENTS.md` §4), a rozjazd byłby cichy: konto
bez obserwowanego gospodarza wygląda jak konto, tylko pusto się z niego
patrzy. Z tego samego powodu tekst odmowy dla konta zamkniętego (DSA
art. 17 ust. 3) wyszedł z `LoginController` do
`App\Domain\Security\KomunikatZamknietegoKonta` — obowiązek prawny spełniony
na jednym ekranie z dwóch nie jest spełniony.

### ROZSTRZYGNIĘCIE 4: ZAKRES `openid email profile` I NIC WIĘCEJ

Prosimy Google o **potwierdzenie tożsamości, adres e-mail razem z informacją,
czy jest potwierdzony, oraz imię**. Imię służy raz, jako podpowiedź nazwy.

Czego **nie** bierzemy i dlaczego:

- **zdjęcia z Google.** D-061: każde zdjęcie profilowe przechodzi u nas przez
  moderację modelem i własny pipeline przekodowania. Zdjęcie zaciągnięte
  z zewnątrz weszłoby **poza** tę drogę;
- **tokenu odświeżania** — i to nie tak, że go nie zapisujemy: żądanie idzie
  z `access_type=online`, więc Google **nam go nie wystawia**. Token
  odświeżania w naszej bazie byłby trwałym pełnomocnictwem do cudzego konta
  Google, leżącym w serwisie, który go do niczego nie używa;
- **tokenu dostępu i tokenu tożsamości.** Żyją przez jedno wywołanie akcji.
  Nie wołamy żadnego API Google po zalogowaniu — jedno żądanie na całą drogę;
- kontaktów, kalendarza, dysku. Każdy dodatkowy zakres to punkt na ekranie
  zgody, na którym człowiek 60+ ma prawo się wystraszyć i wyjść — i słusznie.

**W bazie zostają DWIE kolumny na `users` i to jest całość:** `google_sub`
(trwały identyfikator konta Google — jedyna wartość, którą Google obiecuje
jako niezmienną) i `google_connected_at` (od kiedy, dla ekranu ustawień
i dla sporu o dostęp; `audit_log` jest sprzątany z czasem, a to jest cecha
konta). Baza pilnuje trzech rzeczy, których PHP nie musi: **unikalności
`google_sub`** (jedno konto Google = jedno konto Kuking; indeks częściowy),
**„obie kolumny albo żadna"** (`num_nonnulls(...) IN (0,2)`) i kształtu
`sub`. Obie kolumny są **poza `$fillable`** — kto ustawi komuś `google_sub`
masowym przypisaniem, ten wchodzi na jego konto jednym kliknięciem; wchodzą
wyłącznie przez `User::connectGoogle()` (ta sama zasada co `status`, `role`
i `email`, `AGENTS.md` §7). **Powiązanie znika razem z hasłem przy
anonimizacji konta** (`EraseAccountData`, D-022) — bez tego losowe hasło nie
chroniłoby niczego.

**Dlaczego kolumny, a nie osobna tabela.** `login_link_tokens`
i `pending_email_changes` (D-048) mają własne tabele, bo to są ŻĄDANIA
Z ŻYCIORYSEM (powstają, wygasają, są zużywane). Powiązanie z Google jest
TRWAŁĄ CECHĄ KONTA, jak adres e-mail. Tabela `tozsamosci_zewnetrzne`
(`provider`, `subject`) będzie właściwym kształtem przy **drugim** dostawcy —
próg powrotu jest tani, a migracja przejściowa to jeden `INSERT ... SELECT`.
Budowanie jej dziś to `AGENTS.md` §3.

> **PRÓG POWROTU ZOSTAŁ PRZEKROCZONY TEGO SAMEGO DNIA, PRZED SCALENIEM.**
> Właściciel poprosił wprost o Google **oraz** Facebooka, więc „przy drugim
> dostawcy" nastąpiło, zanim ta migracja kiedykolwiek chodziła na produkcji.
> Powiązania leżą w tabeli `tozsamosci_zewnetrzne` — **D-098**. Migracja
> przejściowa nie była potrzebna wcale: gałąź nie była scalona, więc
> wystarczyło przepisać migrację. To jest ten tani próg powrotu, o którym
> mowa w akapicie wyżej, wykorzystany w praktyce.

### ROZSTRZYGNIĘCIE 5: TURNSTILE NA TEJ DRODZE NIE STOI

**Rozstrzygnięcie: nie.** Rodzina chronionych formularzy zostaje siedmioosobowa
(D-050, D-053) i ta droga do niej **nie dochodzi** — a to jest odstępstwo,
które trzeba uzasadnić, nie przemilczeć.

Kryterium z D-050 brzmi: Turnstile stoi tam, gdzie **automat wysyła formularz
publiczny i coś nas to kosztuje** — konto do moderowania, list z dobowej
puli, pozycję w kolejce jedynego moderatora, sprawę z terminem z DSA,
zgadywanie haseł. Zestawmy z tym dwie trasy tej funkcji:

- **`/wejdz/google` (GET, kliknięcie przycisku).** Nie jest formularzem
  i nic nie zapisuje — zakłada wartości w sesji i przekierowuje. Turnstile
  jest **warunkiem wysłania formularza**; tutaj nie ma formularza, do którego
  mógłby się przyczepić. Kosztem nadużycia jest jeden wiersz sesji, na który
  odpowiada limit zapytań (`limits.google_wejscie`).
- **`/wejdz/google/domknij` (POST, TU POWSTAJE KONTO).** Ten formularz
  **publiczny nie jest**, i to jest sedno: żeby na niego wejść, trzeba mieć
  w sesji tożsamość, która przeszła ekran zgody Google **i** ma adres
  potwierdzony przez Google. Automat zakładający konta seriami musi więc mieć
  **jedno potwierdzone konto Google na każde konto Kuking** — czyli przejść
  zabezpieczenia antyautomatowe Google, mocniejsze od Turnstile i stojące
  **przed** nim. Dołożenie captchy za tą bramką dodałoby jeden ekran, na
  którym osoba 60+ może utknąć, i ani jednego zamkniętego nadużycia.

Zostają za to obie rzeczy, które D-050 nazywa niezastępowalnymi: **limity
zapytań** — dwa osobne koszyki, żeby kliknięcia nie zjadały budżetu wysłań
formularza — i **wpisy w dzienniku** przy każdej odmowie.

**Zysk uboczny, którego nie planowaliśmy:** to jedyna droga wejścia na tym
ekranie, która **nie potrzebuje JavaScriptu** (Turnstile go potrzebuje,
D-053). Dla osoby, u której skrypt widgetu się nie dociągnął — słabe łącze,
blokada reklam — wejście kontem Google jest od dziś drogą, która zadziała.
To jest argument za tą decyzją, nie przeciw.

### CO TA DROGA NIE OMIJA

- **2FA.** Konto z potwierdzoną weryfikacją dwuetapową trafia na
  `/logowanie/kod`, tą samą sesyjną ścieżką (`logowanie.2fa.user_id`).
  **Google zastępuje hasło, nie drugi składnik.**
- **Konta obsługi serwisu.** Moderator i administrator tą drogą nie wchodzą
  wcale (ten sam zakres co D-056: tam obowiązuje hasło + 2FA). Rolę
  sprawdzamy przy KAŻDYM wejściu, więc powiązanie zrobione przed awansem
  przestaje działać z chwilą nadania roli.
- **Blokadę.** Konto zamknięte (`banned`, `pending_delete`, `erased`) nie
  wchodzi i czyta to samo uzasadnienie z DSA art. 17 co na ekranie hasła.
  Konto **zawieszone wchodzi** — kara jest „tylko do odczytu" i odmowa
  wejścia zamieniałaby ją w blokadę na zawsze (dokładnie ten sam wywód co
  w `LoginController`).
- **Zamkniętej rejestracji.** `account.registration_open` zamyka także tę
  drogę — inaczej zamknięcie rejestracji zamykałoby jedną z dwóch dróg do
  tego samego skutku, czyli nie zamykałoby jej wcale.

### GDY GOOGLE NIE ODPOWIADA ALBO CZŁOWIEK ODMÓWI ZGODY

```text
człowiek klika „Anuluj" u Google  → /login + zdanie po polsku („zgoda nie
                                     została udzielona, nic się nie stało")
Google nie odpowiada / 5xx        → /login + zdanie + `Log::error`
zły sekret albo zły adres powrotu → /login + zdanie + `Log::error` z tym,
                                     co sprawdzić (runbook 8D)
`state` nie pasuje / brak kodu    → /login + JEDNO zdanie dla wszystkich
                                     tych przypadków + `Log::warning`
brak kluczy w konfiguracji        → przycisku NIE MA na ekranie w ogóle
```

**Angielskiego kodu od dostawcy nie pokazujemy nigdy** — nikomu nic nie
mówi. Na `/login`, gdzie człowiek wraca, stoi hasło i „Wyślij mi link do
zalogowania", więc żadna z tych awarii nie zostawia go bez drogi dalej
(D-053: nigdzie martwego przycisku).

**Wyłącznik jest podwójny i to jest celowe.** Puste `GOOGLE_CLIENT_ID` albo
`GOOGLE_CLIENT_SECRET` = tej funkcji nie ma (to jest stan lokalny, w CI
i w testach — środowisko bez kluczy zachowuje się dokładnie jak przed tą
zmianą). `KUKING_WEJSCIE_GOOGLE=false` = świadome wyłączenie z kluczami na
miejscu, bez migracji i bez utraty powiązań. Na produkcji cisza ma być
zakazana: funkcja włączona bez kluczy ma dawać `/health` → `degraded`
z powodem `google_bez_kluczy`, tak samo jak Turnstile bez kluczy — bo inaczej
mielibyśmy drogę wejścia, która melduje sukces, nie istniejąc.

> **SPROSTOWANIE: tego sygnału w `/health` JESZCZE NIE MA.** Kod był napisany
> i został **świadomie wycofany z tego PR-a**: `HealthController` przerabia
> równolegle inne zlecenie (#253/#255), a dwóch agentów w jednym pliku kosztuje
> więcej niż jeden dzień bez tego sygnału. Wywód wyżej obowiązuje i sygnał ma
> dojść — jednym `check('google', ...)` obok tego od Turnstile, z gotowym już
> zdaniem `App\Support\Google::komunikatBrakuKluczy()`. Do tego czasu runbook
> (krok 8D) każe sprawdzić to okiem: jest przycisk na `/login` czy nie ma.

**Cofnięcie MIGRACJI odmawia**, gdy w bazie jest choć jedno powiązane konto,
i mówi, co zrobić. To nie jest ostrożność na zapas: konto założone tą drogą
nigdy nie miało hasła, więc skasowanie `google_sub` zabiera mu jedyną drogę
wejścia, jaką ta osoba zna. `KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE=true`
przepuszcza cofnięcie dla kogoś, kto naprawdę tego chce — ta sama konstrukcja
co przy zeszytach (`CofniecieMigracjiNieKasujeZeszytowTest`).

### CZEGO ŚWIADOMIE NIE ZROBILIŚMY

- **Ekranu „połącz / odłącz konto Google" w ustawieniach.** Dziś powiązanie
  powstaje wyłącznie na drodze wejścia, a odłączenia nie ma w interfejsie —
  jest za to w polityce prywatności zdanie, że wystarczy napisać na adres
  kontaktowy. Powód: `AGENTS.md` §3, jedna funkcja na raz. **Próg powrotu:**
  pierwsza prośba o odłączenie ALBO drugi dostawca. Uwaga na wtedy: odłączenie
  konta, które **nie ma innej drogi wejścia** (nie ustawiło hasła), musi
  odmawiać albo najpierw poprosić o hasło — inaczej ekran ustawień będzie
  zamykał ludziom drzwi jednym kliknięciem.
- **Logowania kontem Facebooka.** Issue #258 wymienia je jako „ewentualnie".
  Drugi dostawca to moment na tabelę `tozsamosci_zewnetrzne`, nie na trzecią
  kolumnę.
- **Sprawdzania podpisu tokenu (JWKS).** Uzasadnienie wyżej,
  rozstrzygnięcie 1.
- **Zdjęcia profilowego z Google.** D-061; opisane w rozstrzygnięciu 4.

📄 `app/Support/Google.php` · `app/Google/KlientGoogle.php` ·
`app/Google/TozsamoscGoogle.php` ·
`app/Http/Controllers/Auth/GoogleLoginController.php` ·
`app/Domain/Users/Actions/ZalozKonto.php` ·
`app/Domain/Security/KomunikatZamknietegoKonta.php` ·
`app/Models/User.php` (`connectGoogle()`, `findByGoogleSub()`) ·
`app/Models/TozsamoscZewnetrzna.php` (D-098) ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Http/Controllers/HealthController.php` ·
`database/migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php` ·
`config/kuking.php` (`google.*`, `limits.google_*`) ·
`resources/views/components/wejdz-google.blade.php` ·
`resources/views/auth/google-finish.blade.php` ·
`resources/views/auth/google-link.blade.php` ·
`resources/legal/polityka-prywatnosci.md` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` krok 8D ·
`tests/Feature/LogowanieKontemGoogleTest.php` ·
`tests/Feature/CofniecieMigracjiGoogleOdmawiaTest.php` ·
issue #258, issue #8 (umowa powierzenia), issue #25 (D-056)
