## D-085 · Adres bez konta dostaje zaproszenie do rejestracji, a nie ciszę — z adresem potwierdzonym klikiem i jednorazowością liczoną na utworzeniu konta

**Data:** 10 września 2026 · Zgłoszenie właściciela (ta sama sprawa co #258) ·
Status: **obowiązuje**

> **O numerze.** Szkic tej funkcji powstał w sesji zabitej przez limit i w całym
> kodzie powoływał się na „D-067" — wpis o tym numerze **nigdy nie został
> napisany**, a numer należy do innej pracy. Cały kod odwołuje się teraz do
> D-085, czyli do tego wpisu.

### Skąd to się wzięło — prawdziwe zdarzenie, nie hipoteza

63-letnia osoba chciała założyć konto. Odbiła się o walidację nazwy
użytkownika, przeszła na ekran „Wyślij mi link do zalogowania" (bo jego tekst
brzmiał „podaj adres, na który zakładasz konto"), wpisała swój adres i zobaczyła
zielone **„Wysłaliśmy wiadomość na e\*\*\*@gmail.com"**. Nie przyszło nic — pod
tym adresem nie było konta, a ten ekran świadomie odpowiada identycznie dla
adresu z kontem i bez konta (D-056). Czekała na wiadomość, która nie miała
przyjść.

Prośba właściciela brzmiała wprost: *„jak nie ma konta, a zrobi zaloguj się
przez email, to żeby dostała tego maila i po kliknięciu w link w mailu przechodzi
do strony już tworzenia konta"*.

### Decyzja

Adres, na którym **nie ma konta**, dostaje wiadomość z linkiem prowadzącym na
dokończenie **zakładania konta**. Konto powstaje z adresem **już potwierdzonym**
i bez drugiej wiadomości weryfikacyjnej.

Ta funkcja **nie zamyka #258** (logowanie kontem Google). Ma to samo źródło —
tę samą osobę i to samo zdarzenie — ale to inna droga i tamto zgłoszenie zostaje
otwarte.

### Prywatność z D-056 wychodzi z tego MOCNIEJSZA, nie słabsza

To był warunek, pod którym ta zmiana w ogóle mogła powstać.

D-056 zapisało znane, przyjęte ryzyko: dobowy budżet poczty zajmował się tylko
przy realnie wysłanym liście, więc adres bez konta go nie ruszał — a kto ustawił
się dokładnie na ostatniej jednostce budżetu, mógł z zachowania formularza
wyczytać jeden bit („czy tamten adres ma konto"). **Po tej zmianie oba przypadki
wysyłają wiadomość i oba zajmują ten sam budżet, więc tej różnicy nie ma już
wcale.**

Zostaje różnica słabsza i o piętro niżej: sufit zaproszeń jest osobny i niższy
(40 wobec 120), więc na jego granicy adres bez konta przestaje generować wysyłkę
wcześniej. Ekran milczy o tym identycznie w obu przypadkach — cena jest taka, że
w dniu nadużycia osoba bez konta wiadomości nie dostanie, i dlatego sufit stoi
2–4 razy wyżej niż realne zapotrzebowanie.

### Co rozstrzygnięto po drodze

**Sufit wewnątrz sufitu, nie obok.** Zaproszenie zajmuje miejsce w budżecie
logowania linkiem (120) **i dodatkowo** we własnym (40), więc podział wiadra 300
listów nie zmienia się ani o jeden list. Drugi licznik jest konieczny, bo ta
zmiana otwiera wektor, którego wcześniej nie było: do 10 września adres bez konta
nie generował żadnej wysyłki, więc automat wpisujący wymyślone adresy nie
potrafił wysłać ani jednej wiadomości. Teraz potrafi, a limit po IP (5/60 min)
przepuszcza z jednego łącza 120 próśb na dobę — dokładnie tyle, ile ma cały
budżet logowania linkiem.

**Adres bierze się z wiersza w bazie, nie z pola w formularzu.** To jest
warunek, pod którym wolno postawić `email_verified_at` bez wysłania listu.
Zaproszenie leży w sesji jako sam **identyfikator wiersza**; kto podmieni pole
`email` w formularzu rejestracji, nie zmieni niczego, bo ta wartość nie jest
wtedy w ogóle czytana. Gdyby dało się ją podmienić, powstałoby konto
z potwierdzonym adresem, którego nikt nigdy nie potwierdził — czyli gotowa droga
do konta na cudzej skrzynce, z „nie pamiętam hasła" jako dalszym ciągiem.

**Jednorazowość liczy się na UTWORZENIU KONTA, nie na przejściu ekranu.**
Ani GET z wiadomości, ani POST przyjmujący zaproszenie niczego nie zużywają —
GET dlatego, że skanery odnośników w programach pocztowych otwierają każdy adres
przed człowiekiem (ta sama lekcja co w D-056), a POST dlatego, że **za nim stoi
jeszcze cały formularz rejestracji, o który ta osoba już raz się odbiła**. Gdyby
zaproszenie ginęło wcześniej, pierwsza pomyłka w nazwie użytkownika odbierałaby
jej link bezpowrotnie — czyli powtarzałaby ten sam błąd, który to wszystko
naprawia. Wiersz kasuje `RegisterController::store()` w tej samej transakcji,
w której powstaje konto, pod `lockForUpdate()`.

**24 godziny, nie 30 minut.** Poświadczenie jest słabsze niż link do logowania
(nie wpuszcza nigdzie, prowadzi na pusty formularz), a droga po jego kliknięciu
dłuższa. Nie więcej niż 24 h, bo tyle żyje zamówiona zmiana adresu, a tamto
poświadczenie jest mocniejsze od tego.

**Musi być wyjście „chcę konto na inny adres".** Adres z zaproszenia jest
w formularzu niezmienny, a sesja żyje długo — bez przycisku porzucenia człowiek,
który kliknął link ze złej skrzynki (z jednej korzysta czasem całe małżeństwo),
widziałby ten sam adres przy każdym wejściu na `/register` i nie miałby jak z tego
wyjść.

### Wyścig o unikalność adresu — usterka, którą ta praca znalazła i naprawiła

Szkic kasował poprzednie zaproszenie i zakładał nowe **bez przechwycenia
konfliktu**, mimo że `registration_invites.email` jest unikalne. Dwie prośby
naraz o ten sam adres przechodziły oba `DELETE` i obie szły do `INSERT`; druga
odbijała się o constraint i przewracała żądanie na **500**.

Przewracała je **wyłącznie na ścieżce adresu BEZ konta** — adres z kontem nie
dochodzi do tej klasy, a jego własny wyścig `WyslijLinkDoLogowania` sprowadza do
302 (D-075). Para równoległych próśb odpowiadała więc **500 dla adresu bez konta
i 302 dla adresu z kontem**: dokładnie ta wyrocznia „kto ma konto w Kuking",
którą D-075 dopiero co zamknęło, tylko odbita w lustrze. Po ludzkiej stronie tego
wyścigu stoi zwykły dwuklik „Wyślij".

Blokady wiersza konta — tej z D-075 — nie ma tu na czym postawić: konta nie ma,
a `SELECT ... FOR UPDATE` na nieistniejącym wierszu nie blokuje niczego. Zostaje
druga połowa tamtej konstrukcji: konflikt sprowadzamy do tej samej neutralnej
odpowiedzi co każda inna odmowa, oddając przy okazji miejsce w dobowym suficie.

Przy okazji sufit zaproszeń przeszedł na **`sprobujZarezerwowac()`** — szkic
używał pary „sprawdź, a potem zajmij", czyli tego, co D-076 usunęło z reszty
serwisu.

### Jak to wycofać

`KUKING_ZAPROSZENIA_DO_REJESTRACJI=false` — jedna zmienna, bez wycofywania
migracji i bez ruszania logowania linkiem. Adres bez konta wraca do zachowania
z D-056, ekran odpowiada dalej identycznie, a linki będące w drodze dostają
ekran po polsku zamiast błędu. Wycofanie samej migracji: patrz
`docs/DATABASE.md`, sekcja `registration_invites`.

**Pliki:** `app/Domain/Security/WyslijZaproszenieDoRejestracji.php` ·
`app/Domain/Security/ZaproszenieWSesji.php` ·
`app/Http/Controllers/Auth/RegistrationInviteController.php` ·
`app/Http/Controllers/Auth/RegisterController.php` ·
`app/Models/RegistrationInvite.php` ·
`app/Domain/Compliance/PrzedawnioneZaproszenia.php` ·
`database/migrations/2026_09_10_400000_create_registration_invites_table.php` ·
`tests/Feature/ZaproszenieDoRejestracjiTest.php` ·
`tests/Feature/EkranZaproszeniaTest.php` ·
`tests/Feature/RejestracjaZZaproszeniaTest.php`
