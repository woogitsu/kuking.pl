## D-060 · Kolejka z terminem sama się zgłasza: powiadomienie dla administratora i liczniki przy pozycjach panelu liczone poza żądaniem

**Data:** 10 września 2026 · **Zgłoszenie właściciela** · Status: **obowiązuje**

Właściciel przeszedł pierwszy raz całą ścieżkę moderacyjną na produkcji: ukrył
treść, dostał jako użytkownik powiadomienie o decyzji, złożył odwołanie —
i wtedy: *„Odwołanie jest w »Odwołania«, ale nie mam jako admin/moderator
powiadomienia, że jakieś odwołanie jest, i w »Odwołania« nie ma takiego
kwadracika jak przy Powiadomieniach, że np. są 2 nieodczytane odwołania. Plus
ten panel jest nieczytelny, zlewa się cały tekst"*.

### 1. ODWOŁANIE POWIADAMIA ADMINISTRATORA, NIE WSZYSTKICH MODERATORÓW

Odwołanie ma **termin odpowiedzi** (`Appeal::responseDeadline()` — siedem dni
roboczych z `docs/legal/MODERATION_PLAYBOOK.md` §3, obiecane człowiekowi
w każdym szablonie decyzji i wypisane na ekranie kolejki). Kolejka, o której
nikt nie wie, że coś w niej leży, to termin, który upływa po cichu — a to jest
zobowiązanie z DSA art. 20 i z regulaminu §8, nie uprzejmość.

Nowy typ powiadomienia w serwisie (`Notification::TYPE_APPEAL_FILED`) idzie
**wyłącznie do kont z rolą `admin`**, bo tylko one mogą sprawę zamknąć
(`UserPolicy::resolveAppeals()`, **D-039**). Moderator kolejkę widzi, ale nie
rozstrzyga — powiadomienie dla niego byłoby wezwaniem do czynności, której nie
może wykonać, a to najkrótsza droga do tego, żeby ludzie przestali czytać
powiadomienia z panelu w ogóle. Moderator dostaje zamiast tego licznik przy
pozycji „Odwołania" w menu, na każdej stronie panelu.

Powiadomienie powstaje **bez `actor`**, choć osobę składającą odwołanie znamy.
`NotifyUser` odmawia utworzenia powiadomienia, gdy między nadawcą a odbiorcą
jest blokada — i tutaj byłoby to dziurą: wystarczyłoby zablokować konto
administratora, żeby zawiadomienie o własnym odwołaniu nigdy nie powstało,
a termin płynął dalej. Ta sama decyzja obsługuje odwołanie zgłaszającego,
który konta nie musi mieć wcale (art. 16 ust. 2 lit. c).

### 1a. POCZTA: NIE NA KAŻDE ODWOŁANIE, ALE TAK NA TERMIN, KTÓRY ZARAZ MINIE

**Listu na każde odwołanie nie ma i mieć nie będzie.** EmailLabs daje 300
listów na dobę na CAŁY serwis (**D-047**), z tego samego wiadra co
potwierdzenia rejestracji, a jego jawny podział (**D-057**,
`config/kuking.php` → `poczta`) zostawia dziś 20 listów zapasu. Termin to
siedem DNI ROBOCZYCH, nie godzin, więc list wysłany w sekundzie złożenia
odwołania nie kupuje nic, czego nie kupuje powiadomienie w panelu z licznikiem
widocznym na każdym ekranie — a przy fali migracyjnej z masową moderacją
potrafiłby konkurować o wiadro z rejestracjami. Sufit na taki list byłby
w dodatku gorszy niż jego brak: odcinałby dokładnie te zawiadomienia
o terminie prawnym, które miał chronić.

**List wychodzi natomiast wtedy, gdy termin jest BLISKO albo już MINĄŁ, a
sprawy nikt nie zamknął** — czyli wtedy, gdy powiadomienie w serwisie właśnie
zawiodło, bo nikt do panelu nie zajrzał. `kuking:pilnuj-terminow-odwolan`
(codziennie 07:10) wysyła **najwyżej jeden list na dobę**, na jeden adres
(`moderation.model.alarm_email`), i tylko w dniach, w których naprawdę coś
wisi. Próg: `moderation.appeal_reminder_working_days` (2 dni robocze).

**Jak to się wpisuje w podział wiadra:** bez własnego sufitu, wewnątrz
**rezerwy transakcyjnej** — razem z dobowym podsumowaniem kolejki automatu
(**D-055**) to najwyżej 2 listy z tych 100. Sufit jest narzędziem na funkcje,
które wysyłają wiele listów naraz (logowanie linkiem, digest), a nie na te,
które wysyłają jeden. Rachunek jest dopisany wprost w komentarzu sekcji
`poczta` w `config/kuking.php`, żeby nie stał w drugim miejscu obok tamtego.

List nie niesie treści odwołania ani nazw ludzi — tylko ile spraw wisi i do
kiedy. Ten sam powód co przy `PilnyAlarmModeracyjny`: poczta leży potem
w cudzej skrzynce, a przeczytać sprawę trzeba w panelu, za logowaniem i 2FA.

### 2. LICZNIKI PRZY POZYCJACH PANELU — ZERO `COUNT(*)` NA ODSŁONĘ

Pięć kolejek („Bez odpowiedzi", „Zgłoszenia", „Sygnały automatu",
„Odwołania", „Wiadomości do nas") dostaje plakietkę z liczbą tego, co czeka.
Menu stoi na KAŻDEJ stronie panelu, więc pięć `COUNT(*)` w widoku byłoby pięcioma
zapytaniami na każdą odsłonę — najdroższymi dokładnie wtedy, gdy kolejki są
pełne. Rozwiązanie jest wzięte w całości z licznika społeczności w stopce
(`LiczbaKukingow`, issue #38): przeliczanie schodzi poza ścieżkę żądania,
a widok tylko czyta gotową wartość.

- **jeden wpis w cache na wszystkie pięć liczb**, nie pięć wpisów: produkcja
  chodzi na `CACHE_STORE=database`, więc pięć kluczy zamieniłoby pięć
  `COUNT(*)` na pięć `SELECT`-ów i nie rozwiązałoby niczego;
- **świeżość ze zdarzeń modeli** `Appeal`, `Report`, `ContactMessage`
  (`AppServiceProvider`) — te trzy tabele zmieniają się kilka razy na dobę,
  a licznik pokazujący „1" po zamknięciu ostatniej sprawy kłamie raz i traci
  zaufanie na zawsze;
- **`Post` i `Comment` haka nie mają** — publikacja wpisu i komentarz to
  główna akcja produktu (AGENTS.md §1) i nie dokładamy do niej zapytań po to,
  żeby licznik miękkiej kolejki „Bez odpowiedzi" (progi 6 i 24 godziny) był
  świeży co do sekundy. Tę liczbę odświeża harmonogram co pięć minut
  (`kuking:policz-kolejki`), który jest jednocześnie siatką bezpieczeństwa na
  świeże wdrożenie z pustym cache;
- **zero nie pokazuje niczego**, a nie „0": pięć zer na każdym ekranie panelu
  mówi tyle samo, co ich brak, tylko zajmuje uwagę;
- **licznik znaczy „to czeka na Ciebie"**, nie „tyle jest wszystkiego" —
  sprawy w stanie `reviewing`/`in_progress` są już u człowieka i nie są
  liczone;
- **czytnik ekranu słyszy pełne zdanie**: widoczna cyfra ma `aria-hidden`,
  obok stoi ten sam licznik słowami, więc pozycja czyta się jako „Odwołania,
  2 czekają". Słowo („czeka"/„czekają", `Odmiana::rzeczownik`) jest jedno dla
  wszystkich pięciu pozycji, bo „nowe" wymagałoby trzech różnych form
  przymiotnika dla trzech różnych rodzajów rzeczowników.

Pomiar, nie założenie (AGENTS.md §3): `LicznikiKolejekBezZapytanTest` mierzy,
że odczyt liczników nie wykonuje ani jednego zapytania i że liczba zapytań
ekranu panelu nie rośnie, gdy kolejki puchną z 2 do 22 pozycji.

### 3. HIERARCHIA NA `/admin/odwolania`

Karta odwołania miała osiem bloków o jednej wadze i jednym odstępie, a trzy
z jej nagłówków sekcji stały na `--text-title-sm` (24 px) — tam, gdzie stoi
tytuł karty. Moderator szuka tam DWÓCH rzeczy: co ta osoba napisała i jaka
była pierwotna decyzja.

Panel nie jest przemalowany. Zmieniają się cztery rzeczy: odstęp między
blokami rośnie (a wewnątrz bloku maleje), treść do czytania dostaje formę
cytatu z krawędzią z boku, nagłówki sekcji schodzą do roli etykiet (18 px,
`--color-ink-muted`, dalej `<h3>` w znaczniku), a formularz jest odcięty
kreską i największym odstępem na karcie. **Nic, co się CZYTA, nie schodzi
poniżej 18 px** — mniejsze (16 px, wspólna klasa `.meta`) są wyłącznie
metadane, ten sam nazwany wyjątek co metryczka wersji w stopce (**D-051**).

### 4. DWIE USTERKI ZE ZRZUTU Z PRODUKCJI

- **„powód: tresci-dla-doroslych"** — surowy kod z bazy pokazany człowiekowi.
  Polska nazwa („Nagość albo przemoc (punkt 5)") leżała w `PodstawaDecyzji` od
  początku i szła już w tej postaci do autora treści. Nowa metoda
  `PodstawaDecyzji::etykieta()` jest jednym źródłem tej nazwy dla panelu;
  kody techniczne serwisu (`appeal_overturned`, `automat-falszywy-alarm`)
  dostały własne etykiety, a swobodny tekst z decyzji sprzed słownika jest
  pokazywany NAZWANY tym, czym jest („powód wpisany ręcznie: …"), bo dla
  starych spraw to jedyny ślad tego, co wtedy postanowiono. Ta sama usterka
  siedziała na `/admin/uzytkownicy/{konto}` i jest naprawiona tym samym
  wywołaniem.
- **„decyzję podjął(-ęła) Mateusz"** — konstrukcji zakładającej rodzaj nie da
  się przeczytać na głos (AGENTS.md §11). Zostaje forma bezosobowa
  („decyzję podjęto — Mateusz"), ten sam zabieg co w PR #235, który usunął
  jedenaście takich miejsc z serwisu i tego jednego nie objął.

**Ryzyko:** menu boczne (`components/layout.blade.php`) jest plikiem, który
w tym samym tygodniu ruszały prace nad stopką, prawą szyną i trybem panelu.
Zmiana jest tu wąska (jeden blok `@php` i pięć pozycji listy), ale scalanie
wymaga uwagi.

**Wycofanie:** nie ma migracji ani zmiany schematu. Cofnięcie to usunięcie
komponentu `<x-licznik-kolejki>` z pięciu pozycji menu, zadania
`kuking:policz-kolejki` z harmonogramu i haków ze `AppServiceProvider`;
powiadomienia typu `appeal.filed`, które zostaną w tabeli, wyświetlą się jako
pozycja bez własnego brzmienia, więc przy trwałym wycofaniu należy je usunąć
razem z gałęzią `@case` w widoku powiadomień.

📄 `app/Domain/Moderation/KolejkiPanelu.php` ·
`app/Domain/Moderation/Actions/PowiadomOOdwolaniu.php` ·
`app/Domain/Moderation/Actions/FileAppeal.php` ·
`app/Domain/Moderation/Actions/FileReporterAppeal.php` ·
`app/Domain/Moderation/PodstawaDecyzji.php` ·
`app/Console/Commands/PoliczKolejki.php` ·
`app/Console/Commands/PilnujTerminowOdwolan.php` ·
`app/Notifications/TerminOdwolaniaBlisko.php` ·
`config/kuking.php` (`moderation.appeal_reminder_working_days`, komentarz `poczta`) ·
`app/Models/Notification.php` ·
`app/Providers/AppServiceProvider.php` · `routes/console.php` ·
`resources/views/components/licznik-kolejki.blade.php` ·
`resources/views/components/layout.blade.php` ·
`resources/views/pages/admin/appeals.blade.php` ·
`resources/views/pages/admin/uzytkownik.blade.php` ·
`resources/views/pages/notifications.blade.php` ·
`resources/css/ekran-odwolan.css` · `resources/css/app.css` ·
`tests/Feature/PowiadomienieOOdwolaniuTest.php` ·
`tests/Feature/LicznikiKolejekBezZapytanTest.php` ·
`tests/Feature/LicznikiKolejekPaneluTest.php` ·
`tests/Feature/KolejkaOdwolanCzytelnoscTest.php` ·
`tests/Feature/TerminOdwolaniaPilnowanyPocztaTest.php` ·
`docs/legal/MODERATION_PLAYBOOK.md` §3
