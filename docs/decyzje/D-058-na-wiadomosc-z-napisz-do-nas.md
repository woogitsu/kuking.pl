## D-058 · Na wiadomość z „Napisz do nas" odpisuje się Z PANELU, synchronicznie, ze stanem wysyłki przy każdym liście

**Data:** 10 września 2026 · **Zgłoszenie właściciela** · Status: **obowiązuje**

Zgłoszenie brzmiało dosłownie: *„Wiadomości do nas — widzę je, przychodzą,
ale jak mam odpisać? Nie ma nigdzie funkcji »odpisz osobie«, tylko notatka
dla siebie."* I tak było: ekran `/admin/wiadomosci/{id}` miał stan (Nowa /
W trakcie / Załatwiona), notatkę wewnętrzną i odnośnik `mailto:`. Odpisywało
się więc z własnego programu poczty, a w serwisie nie zostawał ŻADEN ślad,
że odpowiedź poszła — poza zdaniem, które moderator sam sobie zapisał.

Od teraz na karcie wiadomości jest pole „Treść odpowiedzi" i przycisk
„Wyślij odpowiedź". List wychodzi pocztą serwisu, a jego treść i stan
wysyłki zostają przy wiadomości, widoczne po odświeżeniu.

### WYSYŁKA JEST SYNCHRONICZNA — TO NAJWAŻNIEJSZA DECYZJA W TYM WPISIE

Każdy inny list w Kuking idzie kolejką i słusznie: nikt nie czeka przed
ekranem na powiadomienie. Ten jeden czeka, i to nie jest niekonsekwencja.

Issue #234 ustaliło, co dzieje się z listem, którego EmailLabs nie przyjmie:
`TransportException`, `--tries=3`, po ~6 minutach wiersz w `failed_jobs`
i **cisza**. Przy powiadomieniu to zła, ale znośna cena. Przy odpowiedzi na
wiadomość od człowieka cena jest inna i nie do przyjęcia: moderator kliknąłby
„Wyślij", zobaczył „wysłano", oznaczył sprawę jako załatwioną i przeszedł do
następnej — a osoba po drugiej stronie nigdy nie dostałaby odpowiedzi i nikt
by o tym nie wiedział. Kolejka zamieniłaby więc jedną cichą awarię (brak
funkcji „odpisz") w drugą, gorszą, bo z fałszywym potwierdzeniem.

Zamiast tego:

```text
1. zapis wiersza odpowiedzi ze stanem „wysyłka w toku"   ← PRZED wysyłką
2. wysyłka w tym samym żądaniu HTTP
3. zapis PRAWDZIWEGO wyniku: „wysłana" + godzina  albo  „nie udało się" + powód
4. wpis w `audit_log` — przy obu wynikach
```

Krok 1 jest przed krokiem 2 świadomie. Gdyby wiersz powstawał po udanej
wysyłce, przerwanie procesu (koniec limitu czasu PHP, restart kontenera na
Railway) zostawiłoby list w drodze i ZERO śladu w serwisie — moderator
napisałby to samo drugi raz. Przy dzisiejszej kolejności ten sam wypadek
zostawia na ekranie zdanie „Nie wiadomo, czy ten list wyszedł", czyli prawdę.

Koszt: żądanie trwa tyle, ile odpowiedź API EmailLabs
(`services.emaillabs.limit_czasu`). Płaci go jedna osoba, kilka razy dziennie,
i to ona ten koszt wybrała.

### `mailto:` ZOSTAJE — ALE JAKO DROGA AWARYJNA, NAZWANA PO IMIENIU

Rozważona alternatywa: **wyrzucić `mailto:` całkowicie**, bo dwie drogi to
zaproszenie do rozjazdu („odpisałem z Gmaila i zapomniałem odhaczyć").
Argument jest prawdziwy, ale przegrywa z jednym scenariuszem: gdy poczta
serwisu nie działa, wyrzucenie `mailto:` znaczy, że **nie da się odpisać
w ogóle** — a wiadomości, które w takim momencie przychodzą, to bardzo często
„nie dostałem od was maila". Zabranie drogi awaryjnej dokładnie wtedy, kiedy
jest potrzebna, jest gorsze niż ryzyko rozjazdu. Drugi taki scenariusz:
odpowiedź wymagająca załącznika (zrzut ekranu, plik z danymi) — tego formularz
w panelu świadomie nie umie.

Rozjazd ograniczamy inaczej, kosztem trzech linii w widoku: `mailto:` **nie
stoi już obok adresu jako główna droga**, tylko w zwiniętym bloku
`<details>` pod formularzem, podpisanym „Poczta nie działa albo trzeba wysłać
załącznik", z jednym zdaniem: *„zapisz w notatce, co odpisałeś — bo tej drogi
serwis nie widzi"*. Domyślna droga jest jedna i jest nią formularz.

### JEDNA ODPOWIEDŹ CZY WĄTEK: **WIELE ODPOWIEDZI, ALE NIE WĄTEK**

Trzy możliwości i granica przebiega między drugą a trzecią:

1. **Jedna odpowiedź na wiadomość** (trzy kolumny w `contact_messages`) —
   ODRZUCONE. Moderator pisze „sprawdzamy" i dwa dni później „naprawione";
   to są dwa listy, oba wysłane. Kolumna kazałaby drugi albo nadpisać
   (znika ślad tego, co naprawdę wyszło — czyli dokładnie to, czego ten
   ekran ma zacząć pilnować), albo uniemożliwić. Twardszy powód: **każdy list
   ma własny stan wysyłki**, a jedna kolumna `status` nie ma jak opowiedzieć
   „pierwszy nie wyszedł, drugi wyszedł".
2. **Wiele odpowiedzi wychodzących, każda z własnym stanem** (osobna tabela
   `contact_message_replies`) — WYBRANE.
3. **Pełny wątek z odpowiedziami człowieka** — ODRZUCONE i to jest granica
   tej zmiany. Kuking **nie odbiera poczty**: nie ma ani webhooka
   przychodzącego, ani IMAP-a, ani skrzynki, do której serwis by zaglądał.
   Odpowiedź człowieka na nasz list wraca na `kontakt@kuking.pl` — i wraca
   tam CELOWO, bo `Reply-To` wskazuje właśnie tę skrzynkę. Wątek w panelu
   wymagałby odbierania poczty, czyli osobnej funkcji z własnym ryzykiem
   (parsowanie cudzych listów, załączniki, spam) i bez zmierzonej potrzeby
   przy kilku wiadomościach dziennie.

Panel pokazuje więc **to, co wyszło Z NIEGO**, w kolejności wysyłania, i nie
udaje pełnej korespondencji. To ograniczenie jest napisane na ekranie wprost,
a nie zostawione do odkrycia: *„Odpowiedź tej osoby wróci na
kontakt@kuking.pl — nie na ten ekran, bo Kuking poczty nie odbiera"*.

### NADAWCĄ JEST SERWIS, `Reply-To` PROWADZI TAM, GDZIE KTOŚ CZYTA

```text
From:     kontakt@kuking.pl  (config('mail.from'))       ← serwis
Reply-To: kontakt@kuking.pl  (kuking.community.contact_email)
```

Prywatny adres moderatora nie wychodzi na zewnątrz ani w `From`, ani
w `Reply-To`. Trzy powody: osoba pisała do serwisu i odpowiedź ma przyjść od
serwisu; moderator ma prawo do własnej skrzynki bez cudzej korespondencji;
lista moderatorów nie jest informacją publiczną.

`Reply-To` ustawiamy JAWNIE, choć dziś to ten sam adres co `From` — bo te
dwie wartości są w konfiguracji niezależne (`MAIL_FROM_ADDRESS`
i `KUKING_CONTACT_EMAIL`), a pierwszego dnia, w którym ktoś ustawi nadawcę na
adres techniczny, `Reply-To` będzie tym, co decyduje, czy odpowiedź człowieka
dotrze do skrzynki, którą ktokolwiek otwiera.

### LIMIT 300 LISTÓW DZIENNIE: **SPRAWDZONE — WŁASNEGO SUFITU NIE POTRZEBUJE**

Sprawdzone, nie założone. Stan faktyczny na dziś: **wspólnego licznika całej
poczty w repozytorium nie ma** — mówi to o sobie wprost
`App\Domain\Security\DziennyBudzetListow`, jedyny sufit dzienny w kodzie,
należący do **jednej** funkcji (logowanie linkiem, D-056,
`login_link.dzienny_budzet` = 120). Reszta puli EmailLabs (300/dobę na planie
darmowym, dzielone z potwierdzeniami rejestracji, przypomnieniami hasła
i alarmami moderacyjnymi) jest pilnowana **projektowo**: listy natychmiastowe
tylko dla kategorii pilnych, resztę zbiera jedno podsumowanie na dobę
(`PilnyAlarmModeracyjny`, `kuking:podsumowanie-automatu`).

**Dlaczego odpowiedzi nie potrzebują tego, co potrzebowało logowanie
linkiem.** Tamten sufit powstał, bo prośbę o list wywołuje ktokolwiek
z zewnątrz, a pięciuset ludzi zachowujących się zupełnie normalnie zjada
dobową pulę, nie przekraczając żadnego limitu. Tutaj list wywołuje jedna
osoba, po zalogowaniu, z obowiązkowym 2FA, pisząc treść własnymi słowami —
fan-outu nie ma z czego zrobić. Odpowiedzi to garść listów dziennie, czyli
**poniżej 2% puli**.

Własny sufit dzienny nic by więc nie chronił, a zrobiłby rzecz szkodliwą:
odmówiłby wysłania odpowiedzi człowiekowi, który już czeka, w imieniu
budżetu, którego nikt nie mierzy. Gdyby kiedyś powstał prawdziwy, **wspólny**
licznik poczty (np. razem z tygodniowym digestem), **to on** ma być jednym
miejscem tej decyzji.

Co ZOSTAŁO zrobione zamiast sufitu: **własny klucz limitu zapytań**
`limits.kontakt_odpowiedz` = **20 na 10 minut**, a nie wspólny `moderacja`
(120/10). Tamten limit jest świadomie najwyższy w serwisie, bo jego skutkiem
jest wiersz w bazie; tutaj skutkiem jest list wysłany do człowieka i zjedzony
budżet poczty. Sesja moderatora użyta maszynowo pod limitem `moderacja`
wypaliłaby połowę dobowej puli w dziesięć minut i zabrała ludziom możliwość
odzyskania hasła. Dwadzieścia to wielokrotność tego, co człowiek zdąży
napisać, i szósta część tego, co mogłaby wysłać przejęta sesja.

### DANE OSOBOWE: CO DOKŁADNIE ZOSTAJE W BAZIE

- **Treść odpowiedzi** — w `contact_message_replies.body`, z kaskadą
  `ON DELETE CASCADE` na wiadomość. Znika więc **razem z wiadomością**, czyli
  12 miesięcy od jej załatwienia (D-045). Kaskada jest W BAZIE, nie
  w modelu, bo retencja robi masowy `DELETE` i modeli nie dotyka.
- **Adresu, na który list poszedł, NIE ZAPISUJEMY.** Jest już w bazie raz —
  `contact_messages.contact_email` (gość) albo `users.email` (konto).
  Trzecia kopia tej samej danej przeżywałaby anonimizację konta
  (`EraseAccountData` anonimizuje, nie kasuje) i zamieniłaby wiersz techniczny
  w mały, niezależny zbiór adresów e-mail.
- **Powód odmowy** (`error`) przechodzi przez redakcję adresów — ta sama
  lekcja co audyt A6-01: komunikat od cudzej strony niesie dane, których
  autor kodu tam nie włożył (Symfony wypisuje odrzucony adres wprost).
- **`audit_log`** dostaje `admin.contact_reply_sent` albo
  `admin.contact_reply_failed`: aktor, wiadomość jako podmiot i `reply_id`
  w metadanych. Bez treści i bez adresu — dziennik zapisuje FAKT i AKTORA
  (poz. 3.2 z `docs/INSPIRATION_DECISIONS.md`). Nieudana próba też zostawia
  ślad, bo „ktoś próbował odpisać i nie wyszło" jest odpowiedzią na pytanie,
  które kiedyś padnie.
- **Polityka prywatności** dostała o tym jedno zdanie w wierszu o „Napisz do
  nas". Dokument nie może milczeć o danych, które trzymamy.

### CZEGO W LIŚCIE NIE MA: CYTATU ORYGINALNEJ WIADOMOŚCI

Standardowe w każdym systemie zgłoszeń, a tu ryzykowne: adresu gościa nikt nie
weryfikuje — wpisuje się go ręcznie i można się pomylić o literę. Cytat
znaczyłby, że pod obcy adres idzie zdanie w rodzaju „nie mogę się zalogować,
mieszkam z siostrą, która ma to samo nazwisko". Wysyłamy więc datę i rodzaj
wiadomości — tyle, żeby człowiek rozpoznał własną sprawę.

### WYSŁANIE ODPOWIEDZI NIE ZMIENIA STANU WIADOMOŚCI

„Odpisałem" nie znaczy „załatwione": odpowiedź bywa pytaniem dodatkowym, po
którym sprawa jest bardziej otwarta niż przedtem. Automatyczne przestawienie
na „Załatwiona" ruszyłoby przy okazji `handled_at`, czyli **zegar retencji**
(D-045), dla sprawy, której nikt nie zamknął. Ekran mówi to wprost, przy
wyborze stanu.

### CO JESZCZE NAPRAWIŁ TEN PR

`ContactMessage::adresDoOdpowiedzi()` oddawało adres konta **wymazanego**
(`usuniete+<uuid>@konto.kuking.pl` — adres z naszej domeny technicznej, bez
skrzynki). Dopóki panel tylko pokazywał `mailto:`, było to niedogodnością.
Odkąd naprawdę wysyła listy, byłaby to wysyłka w próżnię z zielonym
„wysłano" — więc konto wymazane nie ma teraz adresu do odpowiedzi i ekran
mówi, że nie da się odpisać.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia** — nie ma przełącznika i celowo: pole
   odpowiedzi albo istnieje, albo nie. Najbliższą rzeczą jest zdjęcie trasy
   `admin.contact.reply`; formularz przestaje się wtedy renderować
   (`route()` rzuci), więc to nie jest droga produkcyjna.
2. **Wycofanie kodu:** rewert commita, potem
   `php artisan migrate:rollback --step=1` (kolejność ma znaczenie:
   `contact_messages` nie da się cofnąć, dopóki stoi tabela odpowiedzi).
   Znika formularz, wraca `mailto:` jako droga główna, wiadomości zostają.
3. **Przed cofnięciem migracji na produkcji:**
   `pg_dump --data-only --table=contact_message_replies > odpowiedzi.sql` —
   w tabeli leżą listy, które naprawdę poszły do ludzi.
4. Przy trwałym wycofaniu trzeba zdjąć zdanie o zapisywaniu odpowiedzi
   z polityki prywatności — dokument nie może opisywać danych, których nie ma.

**Zmiana wymaga:** przy „wątku" — funkcji odbierania poczty, nie samego
pomysłu. Przy wysyłce w kolejce — mechanizmu, który pokazuje moderatorowi
PRAWDZIWY wynik wysyłki po fakcie (dzisiejsze `failed_jobs` nim nie jest).
Przy sufitcie dziennym — prawdziwego, wspólnego licznika poczty.

📄 `app/Http/Controllers/Admin/WiadomosciController.php` ·
`app/Domain/Contact/Actions/WyslijOdpowiedz.php` ·
`app/Domain/Contact/Actions/BrakAdresuDoOdpowiedzi.php` ·
`app/Mail/OdpowiedzNaWiadomosc.php` ·
`resources/views/mail/odpowiedz-na-wiadomosc.blade.php` ·
`app/Models/ContactMessageReply.php` · `app/Models/ContactMessage.php` ·
`app/Policies/ContactMessagePolicy.php` · `app/Support/OdzyskiwalneDane.php` ·
`database/migrations/2026_09_10_200000_create_contact_message_replies_table.php` ·
`resources/views/pages/admin/wiadomosc.blade.php` · `routes/web.php` ·
`config/kuking.php` (`limits.kontakt_odpowiedz`) ·
`resources/legal/polityka-prywatnosci.md` §2 ·
`docs/DATABASE.md` (`contact_message_replies`) ·
`tests/Feature/OdpowiedzNaWiadomoscDoNasTest.php` ·
`tests/Feature/CofniecieMigracjiWiadomosciTest.php`
