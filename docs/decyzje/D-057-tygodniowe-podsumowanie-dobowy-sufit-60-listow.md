## D-057 · Tygodniowe podsumowanie: dobowy sufit 60 listów, wysyłka rozłożona na dni, wypisanie bez logowania

**Data:** 10 września 2026 · Issue #11 · Status: **obowiązuje**

Tygodniowe podsumowanie od gospodarza **istnieje**. Zgoda była zbierana od
7 września (`users.wants_weekly_digest`, opt-in), ale nie było czym wysyłać —
ekran `/ustawienia/prywatnosc` mówił wprost „Tych listów jeszcze nie
wysyłamy". Teraz mówi prawdę w drugą stronę.

Jedno polecenie (`kuking:wyslij-podsumowania`), jedno zadanie w harmonogramie
(**codziennie o 08:30**, `withoutOverlapping()`), jeden wyłącznik
(`KUKING_DIGEST_WLACZONY`, domyślnie `false`).

### 1. Co jest w liście — i czego w nim NIE ma

Trzy sekcje, w tej kolejności:

1. **„Ktoś ugotował z Twojego przepisu"** — imię, nazwa potrawy, cytat
   z notatki, przycisk **„Podziękuj"** prowadzący na ekran „Komuś wyszło".
   Pierwsza, bo `AGENTS.md` §1 stawia „Ugotowałem" wyżej niż jakikolwiek lajk,
   a `docs/product/RETENTION_LOOPS.md` §1 nazywa „ktoś zwrócił się do mnie"
   najsilniejszym powodem powrotu, jaki ten produkt ma.
2. **„Nowe osoby przy Twoim gotowaniu"** — kto zaczął obserwować. Też
   osobiste, a przy tym jedyna rzecz, którą ma nowa osoba bez ani jednego
   przepisu.
3. **„Co pokazali ludzie, których obserwujesz"** — do trzech wpisów,
   chronologicznie.

Na końcu **jedno pytanie od gospodarza** z konfiguracji
(`KUKING_DIGEST_PYTANIE`) — jedyna część treści, którą właściciel zmienia co
tydzień bez wdrożenia — i podpis imieniem (`config('kuking.community.host_name')`,
D-037). Adres nadawcy jest skrzynką, na którą da się odpisać, i list mówi
o tym wprost.

**Czego nie ma i dlaczego:**

| Nie ma | Powód |
|---|---|
| jakiegokolwiek rankingu („najaktywniejsi", „najpopularniejsze", „top") | `AGENTS.md` §12 zabrania publicznych rankingów. „Najaktywniejsi w tym tygodniu" jest rankingiem, choćby był miły — każda sekcja jest chronologiczna |
| propozycji nieznajomych („osoby, które warto poznać") | To jest redakcyjny wybór gospodarza, nie coś, co wolno złożyć zapytaniem. Każde automatyczne „warto poznać" jest rankingiem pod inną nazwą |
| komentarzy pod treściami adresata | Mają już własne, natychmiastowe powiadomienie (`RETENTION_LOOPS.md` §3.1). W liście po tygodniu byłyby drugą wiadomością o tej samej rzeczy |
| **zdjęć** | `Media::url()` prowadzi na trasę `media.show`, która sprawdza uprawnienia PATRZĄCEGO — a klient pocztowy jest niezalogowany. Zdjęcia albo by się nie pokazały, albo trzeba by dla poczty poluzować dostęp do cudzych zdjęć. Pierwsze jest brzydkie, drugie jest wyciekiem. Do tego większość klientów pocztowych blokuje obrazki domyślnie, więc list i tak musi działać bez nich. **To jest odstępstwo od zakresu w issue #11** („alt teksty przy zdjęciach") — zakres zakładał, że zdjęcia będą |
| śledzenia otwarć i kliknięć | Patrz §6 |

**Pustego listu nie wysyłamy.** Gdy żadna z trzech sekcji nic nie ma, list nie
wychodzi — „lepiej nic niż e-mail o niczym" (issue #11 pkt 7). Pytanie
gospodarza **nie liczy się do treści**: jest jedno dla wszystkich i takie samo
co tydzień, więc gdyby wystarczało, serwis rozsyłałby pięciuset osobom to samo
zdanie i nazywał je podsumowaniem.

> **Odstępstwo od litery issue #11, świadome.** Kryterium akceptacji brzmiało
> „użytkownik bez zdarzeń osobistych nie dostaje pustego digestu". U nas list
> wychodzi także wtedy, gdy nie ma nic osobistego, ale **jest** coś od osób,
> które adresat obserwuje. Powód: spodziewana fala z Garnek.pl to setki osób,
> które w pierwszym tygodniu nie mają ani jednego przepisu, więc nie mogą mieć
> nic osobistego — a digest jest dla nich głównym powodem powrotu. „Halina,
> którą obserwujesz, pokazała pierogi" nie jest pustym listem. Pusty jest
> dopiero list bez żadnej z trzech sekcji i taki nie wychodzi.

### 2. Limit 300 listów na dobę — rachunek, nie życzenie

Konto EmailLabs na planie STARTUP daje **300 listów na dobę na CAŁY serwis**
(`docs/decyzje/POCZTA.md` §1). Jedno wiadro: potwierdzenia rejestracji,
przypomnienia haseł, ostrzeżenia o zmianie adresu, powiadomienia moderacyjne,
logowanie linkiem (issue #25) i to podsumowanie.

Podział wiadra (`config/kuking.php`, sekcja `poczta`):

| Funkcja | Sufit na dobę |
|---|---:|
| logowanie linkiem e-mail (issue #25) | 120 |
| **tygodniowe podsumowanie** | **60** |
| rezerwa na pocztę bez sufitu (rejestracja, hasła, moderacja) | 100 |
| zapas | 20 |
| **razem** | **300** |

**Podsumowanie bierze 60, nie 120.** Kierunek pomyłki jest wybrany świadomie:
podsumowanie, które nie doszło, jest niczym — potwierdzenie rejestracji, które
nie doszło, kończy komuś przygodę z serwisem, zanim się zaczęła. W tygodniu
fali z Garnek.pl rejestracje mają wygrać, nie biuletyn.

Sumy nikt nie policzy sam z siebie — to trzy liczby w trzech sekcjach
konfiguracji, a każdy sufit widzi tylko siebie. Dlatego rachunek jest
wykonywany w teście: **`PodzialLimituPocztyTest`**. Gdy padnie, obniża się
sufit, a nie podnosi limit dostawcy: ta liczba opisuje cudzy plan taryfowy.

### 3. Ile to naprawdę zajmie listów — 100, 500 i 2 000 kont

Zgoda jest opt-in (`DEFAULT false` od 7 września), więc pisze się tylko do
tych, którzy się zapisali. Kolumna „zapisanych" niżej to założenie o połowie
kont, a wiersz „przy pełnej zgodzie" pokazuje najgorszy przypadek.

| Kont | Zapisanych (~50%) | Listów/tydzień | Dni wysyłki przy 60/dobę | Mieści się w tygodniu? |
|---:|---:|---:|---:|---|
| 100 | ~50 | ~50 | 1 | **tak**, z dużym zapasem |
| 100 | 100 (pełna zgoda) | 100 | 2 | **tak** |
| 500 | ~250 | ~250 | 5 | **tak**, ale bez zapasu |
| 500 | 500 (pełna zgoda) | 500 | 9 | **NIE** — patrz niżej |
| 2 000 | ~1 000 | ~1 000 | 17 | **NIE** |

**Próg jest jeden i twardy: 60 × 7 = 420 listów tygodniowo.** Powyżej niego
obietnica „jeden e-mail tygodniowo" przestaje być prawdą po DRUGIEJ stronie:
część ludzi dostaje list co ósmy, dziewiąty, dziesiąty dzień. Nic się nie
psuje i nic nie krzyczy — kolejka po prostu przestaje schodzić do zera.

Dlatego komenda **zapisuje w dzienniku**, ilu ludzi zostało w kolejce po
dzisiejszej wysyłce (`OdbiorcyDigestu::ileCzeka()`). To jedyny widoczny
objaw, że plan darmowy przestał wystarczać. Wtedy przechodzi się na
**EmailLabs Essential (99–129 zł/mies. do 100 tys. listów, bez limitu
dziennego)** — `docs/decyzje/POCZTA.md` §4. Do tego czasu 500 kont z połowiczną
zgodą mieści się w pięciu dniach.

**Co się przez to traci: wspólny piątek.** `RETENTION_LOOPS.md` §4 chciał
jednej wysyłki w piątek o 17:00. Przy 60 listach dziennie „wszyscy w piątek"
kończy się na sześćdziesięciu kontach. Wybieramy obietnicę, którą da się
dotrzymać („jeden list na tydzień"), a nie tę, której nie da się („zawsze
w piątek"). Kolejność wysyłki to `weekly_digest_sent_at ASC NULLS FIRST` —
„kto czeka najdłużej, ten pierwszy" — więc dzień tygodnia ustala się dla
każdej osoby sam i potem jest stały.

### 4. Co się dzieje, gdy limit padnie w połowie wysyłki — sprawdzone, nie założone

**„Wróci do kolejki i spróbuje jutro" jest NIEPRAWDĄ.** Ustalone w kodzie:

1. odmowa EmailLabs (limit dobowy odrzuca list tak samo jak każdy inny błąd)
   kończy się wyjątkiem `OdmowaEmailLabs` z
   `App\Poczta\TransportEmailLabs::rozstrzygnij()`;
2. to wywraca zadanie w kolejce, a worker chodzi z `--tries=3
   --backoff=10,60,300` (`docker/entrypoint.sh`);
3. trzy próby mieszczą się więc w **sześciu minutach od pierwszej** — czyli
   wszystkie tej samej doby, wszystkie ponad limitem, wszystkie odrzucone;
4. czwartej nie ma. List ląduje w `failed_jobs` i **przepada**.

**Kto się o tym dowie: nikt sam z siebie.** Adresat — nigdy. Sentry nie ma
(D-041: błędy 500 idą webhookiem, ale nieudane zadania kolejki nie idą
nikąd). Jedyne miejsce, które w ogóle liczy `failed_jobs`, to
`kuking:sprawdz-poczte`, uruchamiane ręcznie. Przekroczenie limitu w środku
wysyłki **cicho zjadłoby część biuletynów**.

Dlatego sufit działa **przed** wstawieniem listu do kolejki, po naszej
stronie, a nie „wyślijmy i zobaczmy, co odbije". Odrzucenie przez dostawcę
jest wtedy awarią, a nie normalnym trybem pracy.

Sufitu pilnuje `App\Domain\Security\DziennyBudzetListow` — **ta sama klasa co
przy logowaniu linkiem**, z własnym kluczem licznika i własnym kluczem
konfiguracji. Dwa niezależne liczniki jednego wiadra rozjechałyby się przy
pierwszej zmianie którejkolwiek liczby, a rozjazd dwóch kopii tej samej
reguły jest w tym repozytorium usterką, nie niedogodnością (ta sama lekcja co
martwy wpis `limits.upload` i `kuking.media_disk`).

Różnica wobec logowania linkiem: tam miejsce w budżecie zajmuje się **po**
udanej wysyłce, żeby automat z fałszywymi adresami nie wyczerpał puli
formularzem. Tutaj zajmuje się **przy wstawieniu do kolejki**, bo listę
odbiorców składa harmonogram z kont, które mają zgodę i potwierdzony adres —
nie ma tu nikogo, kto mógłby zalać formularz, a jest ryzyko odwrotne: sześćdziesiąt
listów w kolejce, z których część przepadnie po cichu.

**Tempo.** Listy wychodzą rozsunięte o `KUKING_DIGEST_ODSTEP_SEKUND`
(domyślnie 20 s), czyli cała paczka schodzi w około dwadzieścia minut. Sto
wiadomości w jednej minucie jest samo w sobie sygnałem spamowym
(`docs/decyzje/POCZTA.md` §5 pkt 5).

### 5. Zgoda i wypisanie się

To jest poczta **produktowa, nie transakcyjna** — podstawą jest zgoda
(art. 6 ust. 1 lit. a RODO), nie wykonanie umowy. Stąd cztery rzeczy:

1. **Ustawienie zostaje na `/ustawienia/prywatnosc`**, tam gdzie już było.
   Osobnego `/ustawienia/powiadomienia` **nie zakładamy** — sprawdzone, taki
   ekran nie istnieje, a zakładanie go w tym samym tygodniu, w którym trzy
   inne gałęzie dotykają ustawień i tekstów interfejsu, byłoby dokładaniem
   kolizji do funkcji, która i tak działa. Sam tekst pola wyboru zmieniony:
   przestał obiecywać listy, których nie ma, i zaczął mówić, co w nich będzie.
2. **Odnośnik wypisania w KAŻDYM liście** — w wersji HTML, w wersji tekstowej
   (pełnym adresem, bo w zwykłym tekście nie ma czego kliknąć poza tym, co
   widać) oraz w nagłówkach `List-Unsubscribe` i `List-Unsubscribe-Post`
   (RFC 8058), którymi Gmail i Outlook pokazują własny przycisk przy nadawcy.
3. **Wypisanie działa BEZ LOGOWANIA i jednym kliknięciem.** Autoryzacją jest
   **podpis** (`URL::signedRoute`), nie identyfikator w adresie — `AGENTS.md`
   §7 („UUID w adresie NIE JEST autoryzacją") zostaje w mocy. Bez daty
   ważności, w odróżnieniu od paczki z danymi: odnośnik ma działać także
   w liście sprzed pół roku, wyciągniętym z archiwum skrzynki, bo dokładnie
   wtedy ktoś się rozmyśla. Wygasający odnośnik wypisania mówiłby wtedy „nie
   da się wypisać".
4. **Bez ankiety „dlaczego"** (issue #11 pkt 6).

Powód nie jest tylko uprzejmościowy. Człowiek, który nie pamięta hasła,
zamiast wypisać się klika w skrzynce „to jest spam" — a to psuje
dostarczalność **całej** poczty Kuking, łącznie z resetami haseł
(`docs/decyzje/POCZTA.md` §3). Wyjście musi być łatwiejsze niż donos.

**Trasa działa na `GET` i to jest wybór, nie przeoczenie.** Skanery odnośników
w firmowej poczcie otwierają linki z treści, więc `GET` potrafi kogoś wypisać
bez jego wiedzy. Ekran po wypisaniu ma dlatego przycisk powrotny — jeden,
duży, na tej samej stronie — żeby naprawa też była jednym kliknięciem.
Odwrotna kolejność (najpierw zapytaj, potem wypisz) byłaby wyborem, w którym
pomyłka skanera kosztuje mniej, a pomyłka człowieka więcej.

`podsumowanie/wypisz/*` jest **drugim i jedynym poza `_csp`** adresem wyjętym
spod ochrony CSRF, bo `POST` z nagłówka `List-Unsubscribe-Post` wysyła klient
pocztowy, który tokenu nie ma skąd wziąć. Ochroną tej trasy jest podpis.
Droga powrotna (`podsumowanie/wracam/*`) **świadomie** pod CSRF zostaje — tam
klika człowiek na naszej stronie, a bez tokenu byłaby drogą do zapisania
kogoś z powrotem.

**Polityka prywatności** dostała osobny wiersz w §2: co wysyłamy, na jakiej
podstawie, jak zgodę wycofać i że nie sprawdzamy otwarć ani kliknięć.

### 6. Zdarzenia analityczne: dwa z czterech

Issue #11 wymieniało cztery: wysłany, otwarty, kliknięty, wypisany. Wdrożone
są **`weekly_digest_queued` i `weekly_digest_unsubscribed`** (`product_signals`,
zbiór nazw rozszerzony migracją, nie zdjęciem CHECK-a). Pierwszy nazywał się
do 10 września `weekly_digest_sent` — przemianowany przy **D-078**, bo
powstaje zaraz po `Mail::queue()` i nie wie nic o doręczeniu.

„Otwarty" wymaga niewidzialnego obrazka śledzącego w treści listu,
„kliknięty" — podmiany każdego odnośnika na przekierowanie przez nasz serwer.
Obie techniki zapisują, kiedy konkretna osoba czytała pocztę i z jakiego
adresu IP. Polityka prywatności obiecuje czegoś takiego nie robić, a własny
transport ma nawet wyłącznik śledzenia po stronie dostawcy
(`X-TRACKING-OFF`) — **domyślnie włączony**. Dokładanie własnego śledzenia
byłoby cofnięciem tamtej decyzji tylnymi drzwiami.

Do jedynego progu, po którym cokolwiek robimy — **„wypisy > 1% na wysyłkę"**,
`RETENTION_LOOPS.md` §6 wiersz 5 — wystarczy wiedzieć, ile listów wyszło
i ile osób się wypisało. Otwarcia byłyby miłe, ale nie są progiem.

`properties` niosą **wyłącznie liczby** (ile pozycji miała każda sekcja) —
żadnego adresu, żadnych nazw, żadnych tytułów (AGENTS.md §7).

### 7. Godzina: 08:30, nie piątek 17:00

- **Po 8:00**, czyli po ciszy nocnej z `RETENTION_LOOPS.md` §3.2. List
  przychodzący w nocy jest rano jednym z wielu, a przy telefonie na szafce
  nocnej bywa też budzikiem.
- **Rano, nie o 17:00.** Tamta godzina jest dobra dla kogoś, kto wychodzi
  z biura i planuje weekend. Nasza grupa czyta pocztę przy porannej kawie,
  a o 17:00 jest w kuchni — czyli robi dokładnie to, o czym ten list
  opowiada, i nie patrzy wtedy w telefon.
- **Nie równo o pełnej godzinie:** o 08:00 tyka `kuking:zdejmij-wygasle-kary`
  (`hourly()` = minuta 00 każdej godziny). Cała lista zadań jest świadomie
  porozsuwana — patrz komentarz przy sprzątaniu zmian adresu.
- **Daleko od nocnego bloku sprzątania** (03:20–04:50).

### 8. Jak to wyłączyć

`KUKING_DIGEST_WLACZONY=false`. Jedna zmienna, bez wdrożenia, bez migracji,
bez ruszania harmonogramu — zadanie dalej chodzi i po prostu nic nie robi.
**Domyślnie jest wyłączone** i to nie jest ostrożność na zapas: digest to
jedyna poczta w tym serwisie wychodząca bez czynności człowieka bezpośrednio
przed wysyłką, więc pomyłka w danych albo w treści rozchodzi się od razu do
wszystkich zapisanych i nie da się jej cofnąć. Włącza się ją po sprawdzeniu
listu na własnej skrzynce:

```bash
php artisan kuking:wyslij-podsumowania --na-sucho
php artisan kuking:wyslij-podsumowania --tylko=woogitsu
```

### 9. Zmiana wymaga

Przemyślenia obu połówek naraz. Podniesienie `digest.dzienny_limit` bez
obniżenia innego sufitu przewraca rachunek z §2 i pierwszą rzeczą, która
przestaje działać, jest potwierdzenie rejestracji. Postawienie znacznika
`weekly_digest_sent_at` po doręczeniu zamiast przy kolejkowaniu psuje
obietnicę „jeden list w tygodniu" w dniu, w którym kolejka się zatka.
Usunięcie warunku o pustym liście zamienia digest z powodu powrotu w powód
do wypisania się.

Pilnują tego: `TygodniowePodsumowanieTest`, `WypisanieZPodsumowaniaTest`,
`PodsumowanieSzanujePrywatnoscTest`, `PodsumowanieBezWachlarzaZapytanTest`,
`PodzialLimituPocztyTest`, `ObietnicaTygodniowegoMailaTest`.

📄 `app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Domain/Digest/OdbiorcyDigestu.php` ·
`app/Domain/Digest/ZbierzTresciDigestu.php` ·
`app/Domain/Digest/TrescDigestu.php` ·
`app/Domain/Digest/OdnosnikWypisania.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`app/Mail/PodsumowanieTygodnia.php` ·
`app/Http/Controllers/PodsumowanieTygodniaController.php` ·
`resources/views/mail/podsumowanie-tygodnia.blade.php` (+ `-tekst`) ·
`routes/console.php` · `routes/web.php` · `config/kuking.php` (`poczta`, `digest`) ·
`docs/DATABASE.md` (`users.weekly_digest_sent_at`, `product_signals`) ·
`resources/legal/polityka-prywatnosci.md` §2
