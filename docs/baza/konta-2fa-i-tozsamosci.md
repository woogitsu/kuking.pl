# Konta — 2FA, indeksy panelu, logowanie zewnętrzne

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

#### Weryfikacja dwuetapowa (2FA / TOTP) — moderator i admin

Migracja `2026_09_06_120000_add_two_factor_to_users_table` (issue #12).
`docs/SECURITY_PRIVACY_LEGAL.md`: „MFA obowiązkowe dla adminów" — konto
moderatora widzi zgłoszenia, cudze ukryte treści i odwołania, więc samo
hasło już nie wystarcza jako jedyna ochrona.

**DLACZEGO TOTP, NIE KOD E-MAILEM.** Serwis nie ma dziś działającego SMTP
(zadanie po stronie właściciela) — drugi składnik oparty o e-mail zależałby
od kanału, który nie działa. TOTP liczy kod lokalnie w aplikacji telefonu
(Google Authenticator, Aegis, 1Password…), offline, z samego sekretu
i aktualnego czasu. Biblioteka: `pragmarx/google2fa` (RFC 6238, jedna
zależność — `paragonie/constant_time_encoding`) plus `bacon/bacon-qr-code`
do narysowania kodu QR jako SVG bez żadnego wywołania sieciowego (patrz
`App\Domain\Security\TwoFactorAuthenticator` — uzasadnienie wyboru obu
bibliotek jest w komentarzu klasy).

Kolumny na `users`:

- `two_factor_secret` — sekret TOTP, **zaszyfrowany** (cast `encrypted`
  w `App\Models\User`). Wyciek kopii bazy nie może oddawać drugiego
  składnika logowania.
- `two_factor_backup_codes` — kody zapasowe, **wyłącznie jako tablica
  skrótów** (cast `encrypted:array`, każdy element to `Hash::make()`, nigdy
  kod wprost). Kod jest USUWANY z tablicy po zużyciu — to jednocześnie
  realizuje „kod działa raz" i nie potrzebuje osobnej kolumny na zliczanie.
- `two_factor_confirmed_at` — 2FA jest zapisane na koncie od razu przy
  wejściu na ekran włączenia (żeby kod QR nie zmieniał się przy
  odświeżeniu), ale NIEAKTYWNE, dopóki człowiek nie poda pierwszego
  poprawnego kodu. Dopiero wtedy ta kolumna się wypełnia — i dopiero wtedy
  `User::hasTwoFactorConfirmed()` zaczyna wymagać kodu przy logowaniu
  i wejściu do `/admin`.
- `two_factor_last_used_at` — **NIE jest to `timestamptz`**, mimo nazwy: to
  surowy licznik czasu Uniksa zwracany przez `Google2FA::verifyKeyNewer()`,
  używany wyłącznie do odrzucenia PONOWNIE wpisanego kodu (ochrona przed
  atakiem powtórzenia — bez tego ten sam sześciocyfrowy kod, ważny przez
  całe okno tolerancji ±30 s, dałoby się użyć dwukrotnie). Aplikacja nigdy
  nie odpytuje tej kolumny funkcjami dat, tylko przekazuje ją z powrotem do
  tej samej biblioteki — stąd `bigint`, nie `timestamptz`.

```sql
ALTER TABLE users
ADD CONSTRAINT users_two_factor_confirmed_requires_secret_check
CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL);
```

Bez tego CHECK dałoby się (błędem aplikacji albo ręczną operacją na bazie)
zapisać konto z `confirmed_at` bez sekretu — czyli konto, które wymaga kodu
2FA, ale nie ma z czego go policzyć. Baza tego po prostu nie przyjmie
(AGENTS.md §6: ograniczenie ma być w bazie, nie tylko w walidacji PHP).

**Limit prób** kodu (`config('kuking.limits.two_factor')`, domyślnie 5 prób
na minutę) liczy się PO KONCIE, nie po adresie IP — kod ma sześć cyfr, więc
bez limitu jest do odgadnięcia, a limit tylko po IP omijałby rozproszony
atak z wielu adresów.

**Blokada `/admin/**`:** middleware `EnsureModeratorHasTwoFactor` (alias
`moderator.2fa`), zawsze DRUGI w trasie po `moderator` — dzięki temu zwykły
użytkownik nadal dostaje 404 z `EnsureUserIsModerator`, zanim dotrze do
sprawdzenia 2FA. Moderator bez potwierdzonego 2FA widzi jasny ekran
z przyciskiem do włączenia (403), nie ścianę.

**Rollback: ODMAWIA, gdy ktokolwiek ma 2FA potwierdzone** (D-238, zasada
D-088). `down()` zdejmuje CHECK i wszystkie cztery kolumny, więc każde konto
z włączonym 2FA traci sekret i kody zapasowe — bezpowrotnie, bo sekret jest
zaszyfrowany i nie ma go skąd odtworzyć.

Stało tu wcześniej, że to „świadomy powrót do stanu sprzed tej zmiany,
nikt nie zostaje zablokowany". To prawda i dlatego właśnie jest groźne:
cofnięcie nie wybija nikogo z serwisu, tylko po cichu ZDEJMUJE OCHRONĘ.
Cykl `rollback` → `migrate` (czyli to, co robi `migrate:refresh`) zostawia
kolumny puste, a razem z nimi znika CHECK pilnujący niezmiennika — konto
moderatora, o którym właściciel wie, że jest chronione dwoma składnikami,
wraca do samego hasła i nikt się o tym nie dowiaduje.

Dlatego `down()` liczy `two_factor_confirmed_at IS NOT NULL` i przy
niezerowym wyniku rzuca wyjątek z instrukcją, **zanim** wykona cokolwiek
niszczącego — także zanim zdejmie CHECK. Świadome cofnięcie przepuszcza
zmienna `KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK=1`.

Sam sekret **bez** potwierdzenia nie blokuje niczego: to konto w trakcie
włączania 2FA, które po prostu zaczyna włączanie od nowa. Na świeżym
środowisku cofnięcie działa bez pytania, więc `migrate:refresh` w CI
i u dewelopera chodzi jak dotąd. Pilnuje tego
`tests/Feature/CofniecieMigracji2faOdmawiaTest.php`.

**Zgubiony telefon i kody zapasowe naraz — jak wrócić do konta.** Serwis nie
ma dziś SMTP, więc nie ma samoobsługowego „wyślij link odzyskiwania".
Jedyna droga to `php artisan kuking:2fa-wylacz {login}` — komenda konsolowa
wymagająca dostępu do serwera, uruchamiana PO zweryfikowaniu tożsamości tej
osoby poza serwisem. Celowo bez ścieżki samoobsługowej: samoobsługowy reset
2FA zwykłym linkiem unieważniałby sens 2FA (ktoś, kto ukradnie samo hasło,
resetowałby drugi składnik tą samą drogą).

#### Indeksy pod listę kont w panelu moderacji

Migracja `2026_09_09_400000_add_moderation_list_indexes_to_users`
(`/admin/uzytkownicy`).

| Indeks | Do czego |
|---|---|
| `users_created_at_idx (created_at DESC)` | domyślna kolejność listy („kto przyszedł ostatnio") i filtr zakresu dat rejestracji |
| `users_email_trgm_idx gin (kuking_normalize(email) gin_trgm_ops)` | szukanie konta po fragmencie adresu e-mail |

**Dlaczego dopiero teraz.** `users` nie było tabelą, po której się CHODZI —
czytało się z niej pojedyncze konto po `id` albo po `lower(email)` przy
logowaniu, a na jedno i drugie indeks jest od pierwszego dnia. Przy dwudziestu
kontach (D-012) sortowanie całej tabeli było bez znaczenia. Założenie
o skali się zmieniło: właściciel zapowiada przejście grupy użytkowniczek
z Garnek.pl, czyli setki, a potem tysiące kont.

**Indeks na WYRAŻENIU, nie na kolumnie** — ten sam powód co przy
`ingredients_name_trgm_idx`: warunek pyta o `kuking_normalize(email)`, więc
indeks na surowym `email` nie zostałby użyty. Pilnuje tego test
`PanelUzytkownicyTest::test_indeksy_listy_kont_sa_uzywalne_dla_swoich_zapytan`,
który pyta o to PLANER (`EXPLAIN` przy `enable_seqscan = off`), a nie samą
obecność indeksu w `pg_indexes`.

**Świadomie BEZ `(status, created_at)`** — zakładki filtra zawężają po
statusie, ale `active` to będzie zdecydowana większość wierszy, więc dla
najczęstszego widoku taki indeks nie daje nic ponad `users_created_at_idx`,
a kosztowałby każdy zapis do `users` (te lecą przy odświeżaniu
`ostatnio_widziany_at`). Wraca, gdy zawieszonych kont będą tysiące.

**Świadomie BEZ generowanej kolumny `email_search`.** Wzorzec `*_search`
(niżej) jest domyślny i słuszny tam, gdzie recheck operatora `%` chodzi po
dziesiątkach tysięcy kandydatów. Tutaj zapytanie robi jeden moderator kilka
razy dziennie, a kolumna oznaczałaby DRUGĄ kopię adresu e-mail w tabeli —
więcej danych osobowych w bazie za oszczędność, której na tym ekranie nie
da się zauważyć. Indeks przechowuje trigramy, nie adres.

**Rollback:** `down()` kasuje oba indeksy. Bezstratny — indeks nie trzyma
danych, których nie ma w tabeli; ekran działa bez nich dalej, tylko wolniej.

#### Wejście kontem Google — powiązanie leży w `tozsamosci_zewnetrzne`

Kolumn `users.google_sub` ani `users.google_connected_at` **nie ma**
i nigdy nie było na produkcji: pierwsza wersja tej migracji je dokładała,
ale została przepisana przed scaleniem (D-098). Powiązania z dostawcami
tożsamości mieszkają w osobnej tabeli — patrz `tozsamosci_zewnetrzne` niżej.

### facebook_connection_proofs

Migracja `2026_09_27_120000_create_facebook_connection_proofs` (issue #2085).
Jeden wiersz na konto: dziesięciominutowy, jednorazowy dowód kontroli nad
obecnym, potwierdzonym adresem Kuking, używany tylko przy połączeniu lub
ponownym uaktywnieniu Facebooka. `token_hash` to HMAC losowego tokenu z listu;
`session_hash` wiąże link z tą samą przeglądarką, `facebook_id_hash` z
rozpoznaną tożsamością dostawcy, a `account_state_hash` z hasłem, adresem,
2FA (także kodami zapasowymi), rolą, statusem i generacją sesji. Pod blokadą konta token jest kasowany
w tej samej transakcji co powiązanie. `UNIQUE (user_id)` unieważnia poprzedni
link przy kolejnej prośbie; `UNIQUE (token_hash)` zapobiega kolizji.

Retencja (issue #2319): wygasły dowód nie działa od razu (warunek `expires_at`
w `FacebookConnectionConfirmation`), a fizyczny wiersz kasuje co noc o 07:20 UTC
`kuking:sprzataj-dowody-facebooka` (`PrzedawnioneDowodyFacebooka`,
`expires_at < now()`, opcja `--na-sucho`). Wymazanie konta usuwa dowód jawnie
w `EraseAccountData` — kaskada `ON DELETE CASCADE` nie zadziała, bo kont się nie
kasuje (D-022). Bez indeksu na `expires_at`: najwyżej jeden wiersz na konto.

Rollback usuwa tylko oczekujące dowody. Nie usuwa istniejących powiązań i
zamyka, zamiast otwierać, rozpoczęte próby połączenia; można poprosić o nowy
link po ponownym wdrożeniu.

### tozsamosci_zewnetrzne

Migracja `2026_09_10_500000_create_tozsamosci_zewnetrzne_table`
(issue #258, D-069, **D-098**).

Jeden wiersz = „to konto Kuking wchodzi także kontem u TEGO dostawcy,
o TYM identyfikatorze, od TEJ chwili".

- `id` — `bigserial`, klucz główny. Encja nie jest publiczna (nie ma
  własnego adresu i nikt jej nie widzi), więc UUID-a tu nie ma —
  `AGENTS.md` §6 wymaga UUID dla encji **publicznych**;
- `user_id` — `uuid NOT NULL`, klucz obcy na `users` z `ON DELETE CASCADE`;
- `dostawca` — `varchar(20) NOT NULL`, `google` albo `facebook`; listę
  rozszerza MIGRACJA, nie stała w PHP (patrz niżej);
- `identyfikator` — `varchar(255) NOT NULL`, identyfikator konta u dostawcy
  (`sub` z tokenu tożsamości Google, `user_id` z Graph API Facebooka);
- `connected_at` — `timestamptz NOT NULL DEFAULT now()`, od kiedy;
- `dostep_odebrany_at` — `timestamptz NULL` (migracja
  `2026_09_11_700000_dodaj_znacznik_odebrania_dostepu`, issue #259), kiedy
  człowiek odebrał nam dostęp u dostawcy. `NULL` znaczy „powiązanie żywe";
- `zgoda_potwierdzona_at` — `timestamptz NULL` (migracja
  `2026_09_24_120000_dodaj_granice_zgody_dostawcy`, issue #1025), kiedy
  człowiek ostatni raz wszedł przez dostawcę, czyli ostatni raz potwierdził
  nam dostęp. `NULL` znaczy „od założenia powiązania nie było ponownego
  wejścia" i wtedy granicą jest `connected_at`.

#### `zgoda_potwierdzona_at` — granica dla starych powiadomień

Poprawny podpis `signed_request` nie wygasa. Bez granicy czasu to samo
powiadomienie o odebraniu dostępu, dostarczone ponownie PO tym, jak człowiek
znów wszedł kontem Facebooka, usypiało powiązanie drugi raz — nadpisując
nowszą decyzję człowieka. Kontroler czyta więc `issued_at` i znacznik
`dostep_odebrany_at` zapala tylko wtedy, gdy
`COALESCE(zgoda_potwierdzona_at, connected_at) <= issued_at`. Starsza
wiadomość kończy się spokojnym `200` i niczego nie zmienia.

Kolumnę ustawia `User::cofnijOdebranieDostepu()` przy **każdym** wejściu
kontem Facebooka, nie tylko po uśpieniu: wejście znaczy, że w tej chwili
dostęp był dany, więc spóźnione powiadomienie wystawione wcześniej też jest
nieaktualne. `connected_at` zostaje nietknięte — odpowiada na „od kiedy",
a nie „kiedy ostatnio".

Polityka `issued_at`: brak, inny typ niż liczba całkowita, zero i wartość
więcej niż 5 minut w przyszłości to `400`, jak zły podpis. Sam wiek
wiadomości nie odrzuca — rozstrzyga granica zgody.

**Rollback ODMAWIA** (D-088), gdy w kolumnie jest choć jedna data: po
ponownym `migrate` kolumna wróciłaby pusta, a stare powiadomienia znów
mogłyby usypiać powiązania — bez błędu do zauważenia. Wymuszenie:
`KUKING_ROLLBACK_KASUJ_GRANICE_ZGODY=true`.

#### `dostep_odebrany_at` — dlaczego znacznik, a nie skasowanie wiersza

Facebook woła `POST /wejdz/facebook/odebranie-dostepu` (pole `Deauthorize
callback URL` w panelu Meta), gdy ktoś usunie naszą aplikację w swoich
ustawieniach Facebooka. Bez tej kolumny nie mieliśmy gdzie tego zapisać:
wiersz zostawał jakby nic się nie stało, a człowiek dowiadywał się dopiero
z nieudanego logowania, którego nikt mu nie tłumaczył.

**Skasowanie wiersza byłoby najgorszą z możliwych reakcji.** Kto wszedł do
Kuking wyłącznie kontem Facebooka i nigdy nie ustawił hasła (w `password`
leży skrót wartości losowej, której nie zna nikt, także my), straciłby
JEDYNĄ drogę wejścia, jaką zna — przez kliknięcie w ustawieniach Facebooka,
którego skutków nikt mu nie zapowiedział. Poprawne dane u nas nie znikają
(`AGENTS.md`).

Znacznik **gaśnie sam**, gdy człowiek znów przejdzie przez ekran zgody
Facebooka (`FacebookLoginController::wpusc()`). Odmowa wejścia komuś, kto
właśnie tę zgodę oddał na nowo, byłaby karą za skorzystanie z własnych
ustawień.

Ekran `Ustawienia → Bezpieczeństwo` ma dzięki temu **trzy** stany, nie dwa:
niepołączone, połączone i **uśpione** („Facebook przestał nas wpuszczać…"),
z działającym przyciskiem prowadzącym na ekran zgody (D-053 — żadnego
martwego przycisku).

**Kolumna jest ogólna, nie „facebookowa"** — odebranie dostępu ma też Google
w panelu swojego konta. Dziś powiadamia nas o tym tylko Facebook, bo tylko
on wysyła `signed_request` na nasz adres.

**Ograniczenie panelu Meta:** pole `Deauthorize callback URL` jest jedno na
aplikację, a jedna aplikacja obsługuje produkcję i staging — **staging tych
powiadomień nie dostanie.** To nie jest usterka do naprawienia w kodzie.

**Rollback ODMAWIA** (D-088), gdy w kolumnie jest choć jedna data: usunięcie
kolumny kasuje informację „ta osoba odebrała nam dostęp", po ponownym
`migrate` kolumna wraca pusta i serwis znów twierdzi, że powiązanie jest
żywe — bez błędu do zauważenia. Wymuszenie:
`KUKING_ROLLBACK_KASUJ_ZNACZNIKI_ODEBRANIA=true`.

**Dlaczego tabela, a nie kolumny na `users`.** D-069 rozstrzygnęło inaczej
(dwie kolumny) i wtedy miało rację: jeden dostawca, a tabela byłaby
budowaniem „na przyszłość", czego zabrania `AGENTS.md` §3. Przyszłość
została w tym czasie **nazwana i zamówiona** — właściciel poprosił wprost
o logowanie kontem Google **oraz** kontem Facebooka. Przy dwóch dostawcach
byłyby cztery kolumny, przy trzecim sześć, a przy każdym z nich osobny
indeks częściowy i osobny CHECK „obie kolumny albo żadna". Tabela zamienia
to na jeden kształt wiersza, a warunek „obie kolumny albo żadna" znika
całkiem: wiersz istnieje albo nie istnieje. D-069 samo wskazało ten kształt
jako właściwy „przy drugim dostawcy".

**Co trzymamy i dlaczego akurat tyle.** `identyfikator` to `sub` z tokenu
tożsamości — **jedyna wartość, którą Google obiecuje jako trwałą**. Adres
e-mail da się u Google zmienić, a w Google Workspace da się nadać adres
osoby, która odeszła z firmy, komuś innemu; `sub` zostaje ten sam przez całe
życie konta. Dlatego kolejne wejścia rozpoznajemy po `sub`, a **adres służy
dokładnie raz** — przy pierwszym połączeniu. `connected_at` odpowiada na
pytanie „od kiedy", którego `audit_log` nie utrzyma (jest sprzątany
z czasem), a które jest cechą konta.

**Czego w tej tabeli nie ma, świadomie:** tokenu dostępu, tokenu
odświeżania (żądanie idzie z `access_type=online`, więc Google go NAM NIE
WYSTAWIA), tokenu tożsamości, zdjęcia z Google (D-061 — zdjęcie z zewnątrz
weszłoby poza naszą moderację), adresu e-mail (mamy go na `users`; dwie
kopie rozjechałyby się przy pierwszej zmianie adresu) i nazwy konta
u dostawcy. Pilnuje tego wprost
`LogowanieKontemGoogleTest::test_w_bazie_nie_ma_gdzie_zapisac_tokenu_google`.

**Co pilnuje BAZA, a nie PHP:**

```sql
ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_check
  CHECK (dostawca IN ('google'));

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_identyfikator_check
  CHECK (identyfikator ~ '^\S{1,255}$');

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_identyfikator_unique
  UNIQUE (dostawca, identyfikator);

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_konto_unique
  UNIQUE (dostawca, user_id);
```

1. **jedno konto u dostawcy = jedno konto Kuking.** Bez tego dwa nasze konta
   mogłyby wskazywać ten sam `sub`, a „wejdź kontem Google" wybierałoby to,
   które baza akurat poda pierwsze;
2. **jedno konto Kuking nie ma DWÓCH Google'i.** Tego ograniczenia wersja na
   kolumnach nie potrzebowała (kolumna jest jedna) i właśnie dlatego trzeba
   je było napisać wprost: bez niego „połącz" wołane dwa razy dokładałoby
   drugi wiersz;
3. **zamknięta lista dostawców** — literówka („googel") nie ma prawa cicho
   założyć nowego rodzaju powiązania. Listę rozszerza MIGRACJA, czyli
   decyzja widoczna w przeglądzie kodu. **Facebooka na tej liście NIE MA
   i to jest celowe** — wchodzi razem ze swoim kodem, bo warunki wejścia są
   u niego inne (nie oddaje `email_verified`, patrz D-098);
4. **kształt identyfikatora** — niepusty, bez znaków białych, do 255 znaków
   (OpenID Connect Core §2). Nie zawężamy do samych cyfr, choć dziś Google
   nadaje wartości 21-cyfrowe: zawężenie do dzisiejszego kształtu CUDZEGO
   identyfikatora zamknęłoby logowanie w dniu, w którym Google go zmieni,
   i nie chroniłoby przed niczym;
5. **`ON DELETE CASCADE`** — powiązanie nie ma sensu bez konta. Kont
   w Kuking się jednak **nie kasuje, tylko anonimizuje** (D-022), więc
   kaskada nie jest drogą, którą powiązanie znika w praktyce: robi to jawnie
   `EraseAccountData` (`$fresh->tozsamosciZewnetrzne()->delete()`, razem
   z nadpisaniem hasła). Kaskada jest siatką na wypadek realnego `DELETE`
   (`migrate:fresh`, sprzątanie danych zasianych, przyszłe twarde usunięcie):
   wiersz-sierota trzymałby identyfikator konta Google wskazujący w pustkę
   i **blokowałby** ponowne połączenie tego konta Google z czymkolwiek.

**`TozsamoscZewnetrzna` ma PUSTE `$fillable`** (`AGENTS.md` §7, ta sama
zasada co `status`, `role` i `email` na `users`). Wiersz w tej tabeli JEST
drogą wejścia na konto: kto go założy, wchodzi jednym kliknięciem. Wiersze
powstają wyłącznie przez `User::connectGoogle()`, a ta metoda jest wołana
pod blokadą wiersza konta (`ZamekKonta`, D-079), żeby o dostępie nie
rozstrzygał stan sprzed sprawdzenia warunków.

**ROLLBACK — ODMAWIA, gdy komuś zabrałby wejście na konto.** Konto założone
drogą Google **nigdy nie miało hasła** (w `password` leży skrót wartości
losowej, której nie zna nikt), więc `DROP TABLE` zabiera tej osobie jedyną
drogę wejścia, jaką zna — i robi to nieodwracalnie, bo razem z tabelą znikają
identyfikatory. `down()` sprawdza więc, czy jest choć jeden wiersz, i odmawia,
mówiąc, ilu kont to dotyczy i co zrobić zamiast tego:

```bash
# WYCOFANIE FUNKCJI BEZ MIGRACJI (to jest właściwa droga):
KUKING_WEJSCIE_GOOGLE=false   # + restart serwisu

# JEŚLI NAPRAWDĘ trzeba skasować powiązania — powiedz to wprost:
KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE=true php artisan migrate:rollback --step=1
```

Kolejność przy wycofywaniu kodu i migracji razem: **NAJPIERW KOD, POTEM
MIGRACJA** — inaczej trasy `/wejdz/google` odwołują się do nieistniejącej
tabeli. Sprawdza to `CofniecieMigracjiGoogleOdmawiaTest` (obie strony:
odmowa przy powiązanych kontach ORAZ przejście na świeżym środowisku, bo
migracja, która nie cofa się nigdy, jest równie zła).
