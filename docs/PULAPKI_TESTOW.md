# Pułapki testów — dziesięć rzeczy, które w tym repozytorium naprawdę przeszły

Ten plik nie jest wykładem o testowaniu. To lista pomyłek, które **w tym
projekcie** przeszły przez zielone CI i zostały wykryte dopiero przez
kontrolę ujemną albo przez zewnętrzny audyt.

Każda ma tu wpis, bo każda kosztowała czyjąś pracę i każda wróci.

Zasada nadrzędna, z której cała ta lista wynika (`AGENTS.md` §10):

> **Test, który przechodzi także po zepsuciu tego, czego pilnuje, nie jest
> testem.** Kontrola ujemna nie jest formalnością — jest jedynym dowodem,
> że test cokolwiek mierzy.

---

## 1. Asercja na całym HTML-u łapie to samo słowo skądinąd

**Złapała: sześć osób, w tym autora tego pliku.**

Strona zawiera nawigację, prawą szynę, licznik komentarzy, stopkę i pusty
stan. Każde z nich ma te same słowa i te same liczby, co element, który
sprawdzasz. `assertSee('3')` na stronie wpisu przechodzi, bo „3" jest
w liczniku powiadomień.

Najgorszy wariant: test przechodzi z **innego powodu, niż myślisz**, i mówi
Ci, że funkcja działa, gdy nie działa. Zdarzyło się dokładnie tak przy
eager-loadingu tagów na profilu: wynik był poprawny, ale z powodu odnośników
w prawej szynie, nie z powodu karty wpisu.

**Co robić:** wycinaj konkretną sekcję i sprawdzaj w niej. W repozytorium są
trzy gotowe wzorce — użyj któregoś, nie wymyślaj czwartego:

| Wzorzec | Gdzie |
|---|---|
| `wycinek($html, $od, $do)` | `tests/Feature/OnboardingZnajdzZnajomychTest.php` |
| `DOMXPath` z zakresem sekcji | `tests/Feature/LandingJakDzialaPrzedTablicaTest.php` |
| wycinanie pasa/sekcji po identyfikatorze | `tests/Feature/StronaPowitalnaPasyTest.php`, `tests/Feature/OdkrywaniePustyStanTest.php` |
| `trescEkranu()` — sama zawartość `<main>` | `tests/Support/WycinaObudoweEkranu.php` |

## 1b. …a najczęstszym cudzym źródłem tego słowa jest `<title>` STRONY

**Złapała: dziesięć asercji naraz, w dziesięciu plikach, przy przeglądzie
12.09.2026.**

Pułapka 1 mówi o nawigacji, szynie i stopce. W praktyce najczęstszym
źródłem fałszywego trafienia okazał się `<head>` — bo **tytuł karty
przeglądarki jest zwykle tym samym zdaniem, co nagłówek ekranu**, a do tego
powtarza się w `<meta name="description">`, `og:title` i `og:image:alt`.
Jedno zdanie stoi więc w dokumencie cztery razy, zanim ktokolwiek spojrzy
na treść.

Zmierzone (sabotaż: skasowany nagłówek ekranu, `<head>` nietknięty — wszystkie
te asercje **przeszły**):

| Ekran | Asercja | Skąd naprawdę przechodziła |
|---|---|---|
| `/o-kuking` | `assertStringContainsString('O Kuking', $html)` | tylko `<title>` i `<meta>` — po D-145 nagłówek brzmi „O kuKING" i tego napisu nie ma w treści **ani razu** |
| `/login` | `assertSee('Zaloguj się')` | `<title>` + `<meta>` + przycisk belki dla gościa |
| `/napisz-do-nas` | `assertSee('Napisz do nas')` | `<title>` + 4 × `<meta>` + odnośnik stopki (na każdym ekranie) |
| 404 | `assertSee('Nie znaleźliśmy tej strony')` | `<title>` + `<meta>` |
| `/` (gość) | `assertSee('Pokaż, co dziś ugotowałeś')` | `<title>` + `<meta>` |
| `/dodaj/zdjecie` | `assertSee('Dodaj zdjęcie')` | `<title>` |
| tryb gotowania (gość) | `assertSee('Załóż konto')` | przycisk belki dla gościa |
| ekran zaproszenia | `assertSee('Załóż konto')` | `<title>` + przycisk belki |
| `/@ja` (główka) | `assertSee('Dodaj zdjęcie profilowe')` | skrót w prawej szynie |
| `/@ja` (szyna) | `assertStringContainsString('Dodaj zdjęcie profilowe', $html)` | podpis pod awatarem w główce |

**Co robić:** przy asercji „widać X na tym ekranie" wycinaj `<main>`
(`trescEkranu()`). Dwa ostatnie wiersze pokazują, że to nie wystarcza, gdy
ten sam napis stoi w treści **i** w szynie: wtedy trzeba wybrać stronę,
o którą chodzi w danym pliku (`trescEkranu()` albo `SzynaKolejneEkranyTest::szyna()`),
bo inaczej test pilnuje „gdziekolwiek", a nie tego jednego miejsca.

**Czego NIE zwężać:** asercji „X nie ma". Te zostają na całym dokumencie —
szersze spojrzenie jest tam ostrożniejsze, nie słabsze.

**Uwaga o `bezStopki()`:** ten wzorzec zdejmuje stopkę i tylko stopkę.
Na trafienia z `<title>` i z belki **nie wystarcza** — sprawdzone na
`/o-kuking`, gdzie po zdjęciu stopki napis „O Kuking" nadal był w dokumencie
trzy razy.

---

## 2. Test skanujący pliki przechodzi, gdy nie znajduje ŻADNEGO pliku

**Złapała: test, który był zielony przy pięciu żywych ukośnikach rodzajowych.**

Test przechodzący po `resources/views/` i szukający wzorca przechodzi także
wtedy, gdy glob jest zły, ścieżka się zmieniła albo wzorzec nie łapie niczego.
Zero trafień to dla niego sukces.

**Co robić — kontrola ujemna jest tu ODWROTNA niż zwykle:** wstaw tymczasowo
treść, która łamie regułę, i sprawdź, że test **OBLEWA**. Do tego asercja na
minimalną liczbę przeskanowanych plików:

```php
$this->assertGreaterThan(100, $przeskanowane, 'Skan nie czyta plików — zła ścieżka?');
```

Bez tej asercji przeniesienie katalogu wyłącza test bez jednego czerwonego
przebiegu.

### 2b. Ta sama dziura wraca przez ZAWĘŻENIE — i jest wtedy lepiej ukryta

**Złapało: `scripts/kaskada-martwe-reguly.mjs --tylko`, 20.09.2026.**

Strażnik z bramką zawężaną argumentem (`--tylko <fragment>`) ma dwa różne
zbiory: to, co MIERZY, i to, na czym zapada WERDYKT. Samokontrole pisze się
zwykle dla pierwszego — „zero zmierzonych konfiguracji to błąd przyrządu" —
i one działają. Werdykt tymczasem zapada na drugim, a ten bywa pusty przy
pomiarze, który przebiegł bez zarzutu.

Skutek jest gorszy niż zwykłe zero z §2, bo **wygląda na wynik**: konsola
wypisuje tysiące zbadanych reguł, pełną listę szerokości i motywów, po czym
melduje zieleń na zbiorze pustym. Literówka w zawężeniu albo zmiana nazwy
klasy wyłącza bramkę bez jednego czerwonego przebiegu i bez jednego
podejrzanego wiersza w logu.

**Co robić:** samokontrola musi stać przy ZBIORZE WERDYKTU, nie przy pomiarze.
Zawężenie, które nie objęło ani jednego realnego przedmiotu badania, to błąd
przyrządu (kod 2), nie wynik pozytywny. I licz przedmioty, nie dopasowania
tekstowe: selektor obecny w arkuszu, ale bez nosiciela na mierzonych stronach,
jest „niezmierzony", a nie „czysty".

---

## 3. Twój sabotaż może być za słaby — i uznasz dobry test za atrapę

**Złapała: dwóch agentów niezależnie, w tym samym tygodniu.**

Oba próbowały zepsuć `catch (UniqueConstraintViolationException)` podmieniając
go na `\RuntimeException`. Test nadal przechodził — i obaj byli o krok od
wniosku, że test niczego nie pilnuje.

Przyczyna: `UniqueConstraintViolationException → QueryException →
PDOException → RuntimeException`. Sabotaż łapał wyjątek równie dobrze jak
oryginał. Prawdziwym sabotażem było dopiero `\LogicException`, czyli inna
gałąź hierarchii.

**Co robić:** kontrola ujemna, która nie oblewa, ma **dwie** możliwe
przyczyny — zły test albo zły sabotaż. Sprawdź drugą, zanim uwierzysz
w pierwszą.

## 3b. …a może być też za mocna i trafiać nie tam, gdzie myślisz

Odwrotny przypadek z tej samej sesji: dwie kontrole ujemne **przeszły**, i to
wykryło błąd w testach, nie w kodzie. Dwa testy bariery bazodanowej trafiały
w tę samą gałąź warunku wyzwalacza, więc drugi nie sprawdzał niczego.

**Każda gałąź warunku potrzebuje własnego testu i własnego sabotażu.**

---

## 4. Asercja tylko negatywna przechodzi, gdy mechanizm nie działa wcale

**Złapała: trzy testy widoczności, sprawdzające, że zbanowany nie wychodzi
w wynikach wyszukiwania.**

`assertStringNotContainsString('zbanowany_kucharz', $html)` przechodzi
również wtedy, gdy wyszukiwarka **nie zwraca nikogo** — bo wtedy nie ma
w HTML-u nikogo, więc nie ma i zbanowanego. Przechodzi też po literówce
w szukanym łańcuchu.

Zmierzone: podstawienie `->take(0)` na wyniku zapytania nie oblało żadnego
z tych trzech testów.

**Co robić:** przy każdej asercji „czegoś nie ma" dołóż **kontrolę dodatnią**
w tym samym teście — drugą, widoczną rzecz pasującą do tego samego warunku.
Dopiero para „widoczna wyszła, ukryta nie wyszła" dowodzi, że mechanizm
pracował.

To samo dotyczy testów sprawdzających, że coś się **nie zmieniło**: trzy
testy „adres konta zostaje bez zmian" przeszłyby także wtedy, gdyby zmiana
adresu nie działała nigdy. Czwarty test musi być kontrolą dodatnią.

---

## 5. Narzędzie może zameldować sukces, nie robiąc nic

**Złapała: własny skrypt czekający na CI i job „Test dymny po deployu".**

Pierwszy odpowiedział „ZIELONE" na odpowiedź, w której wszystkie joby stały
w kolejce. Drugi kończył się jako `success`, mając sam krok testu dymnego
`skipped` — 249 przebiegów pod rząd.

**Co robić:** pytaj o **wynik kroku**, nie o status całości.
`steps.<id>.outcome`, nie `job.status`. I wymagaj, żeby każdy oczekiwany
element był obecny i ukończony — brak wyniku to nie „w porządku", to „nie
wiemy".

Ta sama zasada rządzi bramkami w `docs/OTWARCIE.md`: **`NIE WIEMY` liczy się
jako nieprzejście, nie jako sukces.**

### 5b. …a najdroższą odmianą tego jest KONTROLA UJEMNA, która niczego nie zepsuła

19 września 2026 ten sam wzorzec trafił **cztery razy w czterech niezależnych
pakietach**, zawsze tak samo: ktoś mutował źródło, wzorzec `sed` nie trafiał,
plik zostawał nietknięty, test przechodził — i przebieg wyglądał na poprawnie
wykonaną kontrolę ujemną. **Dowód był wart zero, a raport twierdził coś
przeciwnego.** To jest gorsze niż brak kontroli: brak widać, a no-op wygląda
jak robota.

Trzy odmiany tej jednej choroby, wszystkie zaobserwowane:

1. **Mutacja nie trafiła.** Wzorzec przestał pasować po niewinnym
   przeformatowaniu kodu albo po zmianie nazwy zmiennej.
2. **Test był czerwony JUŻ PRZED mutacją.** Czerwień po mutacji niczego nie
   dowodzi, a wygląda identycznie.
3. **Czerwień z niewłaściwego powodu.** Test padł na braku bazy, timeoucie
   albo literówce w samej mutacji — nie na tym, czego pilnuje.

**Co robić: nie pisz kontroli ujemnej ręcznie.** Przepuść ją przez
`scripts/kontrola-ujemna.sh`, który wszystkie trzy odmiany odcina
konstrukcyjnie:

```bash
scripts/kontrola-ujemna.sh   --nazwa 'docięcie numeru strony drugiej listy — #646'   --plik app/Support/PaginationLinks.php   --zamien 'min($other->currentPage(), $other->lastPage())'   --na '$other->currentPage()'   --oczekuj 'page.*999'   --json storage/kontrola-646.json   -- vendor/bin/phpunit tests/Feature/ZeszytPaginacjaObuListTest.php
```

Przyrząd liczy podmiany (PHP `str_replace`, zwykły łańcuch, nie wyrażenie
regularne) i **odmawia uruchomienia testu**, gdy podmian było zero — komunikat
`ODMOWA_NO_OP`, kod wyjścia 2. Wymaga też kontroli dodatniej przed mutacją
(kod 5, gdy test był już czerwony) i wzorca `--oczekuj`, bez którego nie
odróżnia dowodu od awarii środowiska (kod 4 przy czerwieni z innej przyczyny).
Źródło wraca w `trap` na EXIT, INT i TERM, ze sprawdzeniem MD5 i mtime —
także wtedy, gdy test zginie w połowie.

Sam przyrząd ma własną kontrolę ujemną: `tests/skrypty/kontrola-ujemna.sh`
podaje mu m.in. mutację, która **nie trafia**, i sprawdza, że odmawia zamiast
zameldować sukces. Bez tego byłby kolejnym narzędziem z dokładnie tą wadą,
którą naprawia. Przebieg jest w `scripts/check.sh` — nie wymaga bazy i trwa
poniżej sekundy.

**Dlaczego łańcuch, a nie wyrażenie regularne.** Łańcuch albo jest w pliku,
albo go nie ma. Wyrażenie regularne ma trzecią możliwość — „pasuje do czegoś
innego, niż myślałeś" — i to ona dała połowę no-opów z 19 września.

---

## 6. Test na jednym połączeniu nie dowodzi zachowania przy dwóch

**Dotyczy: wszystkich ośmiu P1 współbieżnościowych z audytu.**

`RefreshDatabase` trzyma dane w niezatwierdzonej transakcji, więc drugie
połączenie ich nie zobaczy. Prawdziwego przeplotu dwóch procesów w PHPUnicie
nie odtworzysz.

To **nie zwalnia z testu** — zwalnia z udawania, że dowodzi więcej, niż
dowodzi. Da się deterministycznie wymusić ten przeplot, który był usterką:
pobrać model, wykonać drugą operację, potem dokończyć pierwszą. To pokazuje
dokładnie tę pomyłkę, która była w kodzie (akcja ufa modelowi podanemu
z zewnątrz), i oblewa się po jej cofnięciu.

**Co robić:** napisz test wymuszający przeplot, a **ograniczenie napisz wprost
w docblocku** — czego ten test nie dowodzi. W tym repozytorium jest na to
wzorzec: `tests/Feature/PotwierdzenieAdresuNieWyprzedzaAnulowaniaTest.php` oraz cały
katalog `tests/Feature/Wyscigi/` (siedem testów, m.in.
`IdempotencjaZgloszeniaWyscigTest`, `EksportDanychRaceTest`,
`ModerationDecideRaceTest`).

Nie pisz zamiast tego testu pozornego, który „sprawdza współbieżność" przez
dwa wywołania pod rząd.

---

## 6b. …a od 11.09.2026 jest na to grupa `dwa-polaczenia`

Pułapka 6 opisuje stan, w którym prawdziwego przeplotu nie da się odtworzyć
**w zwykłym przebiegu**. To się nie zmieniło i nie zmieni. Zmieniło się to, że
obok zwykłego przebiegu stoi teraz osobna grupa, która przeplot odtwarza
naprawdę: `tests/Dwa/`, uruchamiana `./scripts/testy-dwa-polaczenia.sh`,
na własnej bazie `kuking_race_*`, bez `RefreshDatabase`, z uczestnikami
w osobnych procesach (D-105).

Nie jest to zwolnienie z pułapki 6: **test w `tests/Feature/` nadal niczego
nie dowodzi o dwóch połączeniach** i nadal ma to napisane w docblocku.
Jeśli Twoja poprawka dotyczy kolejności blokad i chcesz ją ZMIERZYĆ, a nie
uzasadnić — dołóż test do grupy `dwa-polaczenia` i przeczytaj najpierw
pułapkę 7, bo bez niej ta grupa daje fałszywe zielone.

---

## 7. Dwa „połączenia", które są jednym, dają zielone przy zepsutym kodzie

**Złapała: pierwsze trzy pomiary audytu blokad z 10.09.2026, w których jawny
`SELECT … FOR UPDATE` „nikogo nie blokował".**

Przyczyna była jedna: `pg_connect()` z tym samym ciągiem połączenia zwraca
**to samo połączenie**, dopóki nie poprosi się jawnie o
`PGSQL_CONNECT_FORCE_NEW`. Dwa uchwyty w zmiennych `$a` i `$b` były jedną
sesją PostgreSQL — a sesja nie blokuje samej siebie. Wszystko „przechodziło".

To jest pułapka groźniejsza od pozostałych sześciu, bo zielony wynik wygląda
wtedy jak dowód poprawności kodu, a jest dowodem tego, że nic nie zmierzono.

**Zmierzone przy zakładaniu grupy `dwa-polaczenia` (D-105).** Ten sam
scenariusz — dwie egzekucje kasowania konta na parze, która obserwuje się
wzajemnie — przeciwko temu samemu ZEPSUTEMU kodowi (`EraseAccountData`
cofnięte do kolejności ról, czyli stan sprzed D-093), w jednym przebiegu:

| Wariant | Wynik |
|---|---|
| oba „połączenia" zepięte w jedno (uczestnicy po kolei, na jednym połączeniu) | **ZIELONY** — trzy razy pod rząd |
| dwa naprawdę osobne połączenia (uczestnicy w osobnych procesach) | **CZERWONY**: `SQLSTATE[40P01] … deadlock detected … while deleting tuple (0,7) in relation "follows"` |

Zepsuty kod, ten sam test, ta sama baza. Różnicę robi wyłącznie to, czy
połączenia są naprawdę dwa.

**Co robić:**

1. **Nie ufaj temu, że masz dwa połączenia — ZMIERZ to.** Jedna asercja:
   `SELECT pg_backend_pid()` z obu i `assertNotSame`. Jest w `setUp()` klasy
   `Tests\Dwa\TestDwochPolaczen` i kosztuje jedno zapytanie.
2. **Zmierz też, że konflikt JEST WIDZIANY** — bo różne backendy to za mało,
   gdy test pyta o niewłaściwy zasób. Wzorzec: blokada doradcza założona na
   pierwszym połączeniu i `pg_try_advisory_xact_lock()` na drugim, które musi
   zwrócić `false`. Przy jednym połączeniu zwróciłoby `true`, bo blokady
   doradcze są w obrębie sesji wznawialne — czyli kontrola oblewa się głośno
   zamiast przepuścić fałszywą zieleń.
3. **W PDO** nie ma `PGSQL_CONNECT_FORCE_NEW` i nie jest potrzebny: każde
   `new PDO` to osobne połączenie — **o ile nie poprosisz o
   `PDO::ATTR_PERSISTENT`**. To jedno ustawienie zamienia całą grupę
   w atrapę, dlatego stoi w kodzie jawnie wyłączone, z komentarzem.
4. **Uczestnik wyścigu w osobnym PROCESIE** spełnia tę zasadę
   konstrukcyjnie — własne połączenie ma z definicji, nie z ustawienia, które
   da się przypadkiem zgubić.

---

## 8. `git checkout -- <plik>` cofa nie tylko sabotaż, ale i niezacommitowaną naprawę

**Złapała: koordynatora tej sesji, na trzech kontrolach ujemnych z rzędu —
i wszystkie trzy wyglądały na udane.**

Przebieg był taki. Naprawa leżała w katalogu roboczym, **bez commita**. Agent
zepsuł plik celowo, uruchomił test, zobaczył czerwień — i przywrócił plik
przez `git checkout -- <plik>`. To polecenie nie zna pojęcia „sabotaż":
przywraca treść z `HEAD`, czyli stan SPRZED naprawy. Od tej chwili plik
zawierał starą, zepsutą treść, o której agent myślał, że jest naprawiona.

Dalej działo się najgorsze możliwe: drugi i trzeci sabotaż raportowały
czerwień z **pierwszej** usterki, tej cofniętej razem z naprawą. Trzy
kontrole ujemne oblały się trzy razy z tego samego, niewłaściwego powodu —
a w podsumowaniu wyglądało to jak trzy niezależne dowody, że testy mierzą.

Wykryte tylko dlatego, że w komunikacie oblanego testu stała nazwa **nie tego
pliku**, który był sabotowany. Gdyby komunikat był ogólniejszy („asercja nie
przeszła"), pomyłka weszłaby do raportu jako trzy zielone kontrole ujemne.

**Co robić:**

1. **Przed serią kontroli ujemnych zacommituj naprawę** — wtedy `HEAD` jest
   stanem naprawionym i `git checkout -- <plik>` robi to, czego się od niego
   oczekuje.
2. **Albo nie przywracaj z `HEAD` w ogóle**: odłóż kopię (`cp <plik>
   <plik>.kopia`), sabotuj, przywróć przez `cp <plik>.kopia <plik>`. Kopia nie
   wie nic o commitach, więc nie cofnie niczego poza sabotażem.
3. **Czytaj, CZEGO dotyczy komunikat oblanego testu, nie tylko że jest
   czerwony.** Kontrola ujemna, która oblewa się z innego powodu niż Twój
   sabotaż, nie dowodzi niczego — dokładnie tak samo jak sabotaż, który
   w ogóle się nie nałożył i dał bezwartościowe „0 failed" (pułapki 2 i 3).
   To jest siostra tamtych dwóch: **raz nie mierzysz nic, raz mierzysz nie to,
   a wynik w obu przypadkach wygląda na dowód.**
4. Po każdej kontroli ujemnej `git diff` **i** `git status` — pusty diff przy
   niezacommitowanej naprawie znaczy, że naprawy już nie ma, a nie że
   wszystko wróciło na miejsce.

## 8b. …a czerwień, którą czytasz, może pochodzić z maszyny, nie z kodu

**Złapała: 11.09.2026, agenta pracującego równolegle — i kosztowała cudzy czas
na szukanie usterki, której nie było.**

`RegressionTest::test_przetworzenie_odnotowuje_czy_zastosowano_obrot` oblał się
z komunikatem:

```text
Brak pliku źródłowego w storage.
```

Człowiek, który to czyta, idzie szukać błędu w przetwarzaniu zdjęć. A pliku nie
było, bo **skończyło się miejsce na dysku** (w chwili startu zostało 5,7 GB
i malało — kilku agentów pracowało naraz). Zapis padł, plik nie powstał,
a `ProcessUploadedImage` sprawdza tylko, **czy plik jest**:

```php
if ($original === null) {
    throw new \RuntimeException('Brak pliku źródłowego w storage.');
}
```

Brak miejsca, brak uprawnień, skasowanie pliku i nigdy nieudany zapis dają
w tym miejscu jeden, ten sam, mylący komunikat — nazywający OBJAW widziany
przez kod, nie PRZYCZYNĘ. Ten sam test na `main`, uruchomiony osobno,
przechodził.

**Co robić:**

1. **Zanim uwierzysz czerwieni, uruchom ten jeden test osobno** — `--filter`
   na jego nazwie. Usterka w kodzie oblewa się tak samo w pełnym przebiegu
   i w pojedynczym; wyczerpany zasób maszyny zwykle nie.
2. **Sprawdź `df -h /`.** To jedno polecenie odróżnia „test pilnuje czegoś
   zepsutego" od „maszynie skończyło się miejsce".
3. Przy pisaniu własnych komunikatów: jeśli warunek brzmi „czy plik jest",
   to komunikat nie ma prawa twierdzić, że wie, dlaczego go nie ma. Lepiej
   wymienić w nim możliwe przyczyny niż nazwać jedną, nieprawdziwą.

To jest siostra pułapki 8 z drugiej strony: tam czerwień była prawdziwa, ale
z niewłaściwego pliku; tu jest prawdziwa, ale z niewłaściwej warstwy. W obu
razach **czerwień bez przeczytanej przyczyny nie jest informacją.**

## 8c. …a `git stash` jest WSPÓLNY dla wszystkich worktree'ów

**Złapała: 11.09.2026, dwóch agentów naraz — jeden odłożył swoją pracę,
a `git stash pop` zwrócił mu SZEŚĆ PLIKÓW drugiego, z całkiem innego zadania.**

Agent pracujący nad migracjami zrobił `git stash`, a po chwili `git stash pop`
— i dostał pliki bramki R2, nad którą pracował ktoś inny, w innym worktree.
Uratowało to tylko tyle, że zauważył obce nazwy plików; obie prace dało się
odzyskać.

Przyczyna jest konstrukcyjna i warto ją znać dokładnie. `git worktree`
izoluje **katalog roboczy, indeks i `HEAD`** — i na tym kończy izolacja.
Schowek (`refs/stash`), wszystkie refy i cały katalog `.git` są **wspólne**.
Agent, który myśli „mam swój worktree, więc mam swój schowek", zabiera cudzą
pracę bez jednego ostrzeżenia.

**Co robić:** w tym repozytorium **nie używaj `git stash` w ogóle.** Jak
w pułapce 8: zacommituj albo odłóż kopie plików (`cp`). Jedno i drugie jest
Twoje i tylko Twoje.

To jest trzecia rzecz z tej samej rodziny co pułapki 8 i 8b: **polecenie
gita, które w pojedynczej pracy jest bezpieczne, przy kilku agentach naraz
kasuje robotę** — a wygląda przy tym dokładnie tak, jakby zadziałało.

## 9. Test z datą wpisaną na sztywno przechodzi tylko do tej daty

**Złapała: 12.09.2026 o 10:00 UTC — `main` zrobił się czerwony bez ani jednego
commita. Zegar wybił godzinę wpisaną w teście pół tygodnia wcześniej.**

`AccountStatusTest::test_zawieszony_widzi_date_konca_kary_po_polsku` zawieszał
konto do `2026-09-12 10:00:00` i sprawdzał, czy na ekranie widać
„12 września 2026”. Gdy test powstawał, ta data była w przyszłości, więc
`isSuspended()` zwracało prawdę i komunikat się renderował. Tego dnia o 10:00
termin minął: `EnsureAccountIsActive` zdjął karę przy pierwszym żądaniu
(`User::punishmentHasExpired`), ekran słusznie przestał cokolwiek pokazywać
— i test zaczął padać na **sprawnym** kodzie.

Najgorsze jest to, jak taka czerwień wygląda: pada w środku dnia, na gałęzi,
która nie tknęła ani moderacji, ani layoutu, i pierwszy odruch to szukać
winnego wśród świeżo scalonych PR-ów. Sprawdzenie, które kończy poszukiwania
w pół minuty: **uruchom ten jeden test na czystym `main`.** Jeśli pada i tam,
to nie jest niczyja zmiana.

**Co robić:** jeśli test sprawdza FORMAT albo TREŚĆ zależną od daty
— przymroż zegar (`$this->travelTo(Carbon::parse('…', 'UTC'))`) i podaj datę
jawnie. Jeśli sprawdza UPŁYW czasu — licz względem `now()`
(`now()->addDays(7)`, `now()->subMinute()`) i nie wpisuj żadnej daty.
Jedno i drugie w jednym teście to właśnie ta pułapka.

Krótko: **w teście albo data jest stała i zegar też, albo obie są względne.
Stała data przy idącym zegarze to bomba z opóźnionym zapłonem**, która
tyka dokładnie tyle, ile wynosi różnica między dniem napisania a wpisaną datą.

---

---

## 10. Stan STANOWISKA udaje wynik pomiaru

§8b mówi o czerwieni pochodzącej z maszyny — obciążenia, timeoutu, ubitego
procesu. Ta pułapka jest inna i groźniejsza, bo nie wygląda na awarię:
**stanowisko jest ciche, a jego wada zmienia wynik**. Test mówi prawdę
o czymś, o co nikt nie pytał.

19 września 2026 trafiło to cztery razy w jednym dniu, za każdym razem
kosztując osobne śledztwo:

**Klon przez `git archive`.** `.gitattributes` oznacza katalog `.github` jako `export-ignore`,
więc z paczki zniknęło 38 plików i wypadły 22 testy o workflowach CI.
Objaw: „PR psuje testy CI". Prawda: tych plików nie było w stanowisku.

**Symlink na `vendor`.** Dowiązanie przestawia PSR-4 dla `App\` na katalog
dawcy. Objaw: `JednoDekodowanieZdjeciaTest` wywraca się po ośmiu minutach
hooka. Prawda: autoloader ładował cudzy kod. **Vendor się kopiuje, nie
dowiązuje.**

**Nieświeży lokalny `main`.** W repozytorium, po którym chodzi kilka sesji,
gałąź `main` potrafi stać kilkaset commitów wstecz, a `git rev-list --count
origin/main..main` pokazuje wtedy **zero** i niczego nie sygnalizuje.
Gałąź zbudowana na takiej bazie dała 41 porażek drzewa sprzed tygodnia.
Objaw: „regresja w main". Prawda: zła baza. **Bazę bierz z `origin/main`,
nie z lokalnego `main`.**

**Brak bitu wykonywalności.** Skrypt zacommitowany w worktree Windows dostaje
tryb `100644` zamiast `100755`. Objaw: 8 oblanych z 12, wszystkie z kodem
**126** („znaleziono, ale nie da się wykonać"). U autora było 12/12, bo plik
na dysku był wykonywalny — zepsuty był wyłącznie tryb w commicie.
Commituj skrypty przez `git update-index --chmod=+x`.

### Dlaczego to jest osobna pułapka, a nie odmiana §5

W §5 narzędzie **nie robi nic** i melduje sukces. Tutaj narzędzie robi
dokładnie to, o co je poproszono — tylko nie na tym, na czym myślisz.
Wynik jest prawdziwy i bezużyteczny naraz.

### Dwa wzorce, które to wyłapują

**Pytaj o zachowanie, nie o wygląd.** Podgląd pliku przez kilka warstw
powłoki gubi ukośniki: `/^(P581_[A-Z_]+)\s/` wyświetlił się identycznie
jak zepsute `/^(P581_[A-Z_]+)s/`. Rozstrzygnęło dopiero zaimportowanie
modułu i sprawdzenie, co zwraca — wynik był dokładnie odwrotny od
zamierzonego.

**Bisektuj, zanim ogłosisz regresję.** Masowe porażki na gałęzi sprawdź
najpierw na samych scaleniach bazy: jeśli każde z osobna jest zielone,
winna jest baza albo stanowisko, nie kod. To odróżnienie zajęło pięć minut
i oszczędziło zgłoszenia o nieistniejącej regresji.

### Dotyczy to także poleceń pomocniczych

Tego samego dnia `git show 'origin/main:.env.example'` **cicho padło** na
zamianie ścieżki przez powłokę Windows, a skrypt wypisał własne „brak
wpisu" — i na tej podstawie postawiono błędną diagnozę o strefie czasowej.
Polecenie diagnostyczne, którego kodu wyjścia nikt nie sprawdza, jest
kolejnym źródłem tej samej pułapki. `MSYS_NO_PATHCONV=1` rozwiązuje ten
konkretny przypadek; sprawdzanie kodu wyjścia rozwiązuje całą klasę.

## 11. Niedopasowana atrapa HTTP domyślnie wychodzi do prawdziwej sieci

`Http::fake(['api.example/*' => ...])` podstawia tylko pasujący adres. Bez
dodatkowej blokady literówka w domenie albo nowy endpoint może ominąć atrapę
i uruchomić prawdziwy transport podczas testów. Wynik zaczyna wtedy zależeć od
sieci, cudzej usługi i sekretów stanowiska, a test może nawet zmienić dane poza
izolowanym środowiskiem.

Dlatego `Tests\TestCase::setUp()` włącza globalnie
`Http::preventStrayRequests()`. Każdy test używający fasady Laravela musi jawnie
podstawić wszystkie dozwolone adresy. Pilnuje tego
`TestyNieWychodzaDoSieciTest`: niedopasowany loopback kończy się
`StrayRequestException`, a dopasowane żądanie nadal przechodzi i ma sprawdzony
adres, metodę oraz dane.

Granice tej ochrony są równie ważne jak sama ochrona:

- `Http::fake()` bez mapy i wildcard `Http::fake(['*' => ...])` nadal akceptują
  każdy adres — używaj ich tylko wtedy, gdy test naprawdę nie rozstrzyga celu;
- osobna instancja `Illuminate\Http\Client\Factory` nie dziedziczy ustawienia
  fasady i musi dostać własne `preventStrayRequests()`;
- blokada obejmuje klienta HTTP Laravela, nie ręczny Guzzle, SDK storage, cURL,
  proces powłoki ani przeglądarkę;
- uruchamiamy ją po `parent::setUp()`, więc chroni kod wykonywany przez test,
  ale nie żądanie wykonane w trakcie samego bootowania aplikacji.

Nie naprawiaj brakującej atrapy przez globalne `Http::fake()` ani
`allowStrayRequests()`. Dopisz najwęższy wzorzec adresu i zachowaj asercję
pełnego URL-u, metody oraz danych tam, gdzie są częścią kontraktu.

## 12. Strażnik CSS czytający ŹRÓDŁO mierzy inny arkusz, niż dostaje przeglądarka

Ta pułapka jest odmianą §10 („stan stanowiska udaje wynik pomiaru"), ale
stanowiskiem jest tu **budowanie arkusza**. Między `resources/css/*.css`
a `public/build/assets/app-*.css` zmienia się na tyle dużo, że test czytający
źródło potrafi opisywać układ, którego nikt nie zobaczy.

Zmierzone 20 września 2026 przy strażniku martwych reguł (D-223), trzy różnice
naraz, wszystkie milczące:

**Instrukcja `@layer a, b, c;` nie przeżywa budowania.** W źródle stoi ona
w `app.css` linia 1 i to ona ustala kolejność warstw. W zbudowanym arkuszu
**jej nie ma** — zostają same bloki `@layer nazwa { … }`, a kolejność wynika
z ich pierwszego wystąpienia. Strażnik szukający `CSSLayerStatementRule`
dostawał pustą listę warstw, przez co **każdej regule przypisywał tę samą
warstwę**, nie znajdował ani jednego kandydata i wypisywał „✓ nic nie jest
przykryte". Zieleń była prawdziwa składniowo i bezwartościowa merytorycznie.
Do zbudowanego arkusza dochodzi przy okazji wewnętrzna warstwa Tailwinda
`properties`, PRZED `theme` — w źródle jej nie ma wcale.

**Zapytania medialne są budowane w składni zakresowej.** W źródle bywa
`@media (min-width: 48rem)`, w arkuszu stoi `(width >= 48rem)`. Wzorzec
szukający `min-width` znajduje **zero** progów. Narzędzie mierzy wtedy tylko
szerokości, które ktoś wpisał ręcznie, a każdą regułę schowaną pod progiem
bierze za żywą albo za martwą — zależnie od tego, po której stronie progu
przypadkiem stanęło.

**Arkusz bez `@layer` bije każdą warstwę.** Osiem plików `resources/css/*.css`
nie jest owiniętych w żadną warstwę. Kod spoza warstw jest w kaskadzie
PÓŹNIEJSZY niż każda warstwa nazwana — także niż `utilities`. Istnieje więc
warstwa najwyższa, której instrukcja `@layer` nie wymienia, a `grep '@layer'`
po źródle jej nie pokaże, bo ona polega właśnie na BRAKU wpisu.

### Co robić

**Pytaj `getComputedStyle` na wyrenderowanej stronie, nie pliku.** Wynik
kaskady jest jedyną odpowiedzią na pytanie „co widzi użytkownik". Tekst
arkusza wolno wykorzystać do ZAWĘŻENIA listy kandydatów — nigdy do werdyktu.

**Daj narzędziu samokontrolę na pustym przebiegu.** Zero wykrytych warstw,
zero progów, zero zbadanych kandydatów — to są BŁĘDY PRZYRZĄDU, nie wyniki
pozytywne. `scripts/kaskada-martwe-reguly.mjs` kończy się wtedy kodem 2
i właśnie ta samokontrola złapała obie pomyłki opisane wyżej, zanim zrobiły
z niego kolejnego strażnika meldującego zieleń bez pomiaru.

**Przywracaj przez `cssText`, nie przez `setProperty`.** Pomiar, który zdejmuje
deklarację i odtwarza ją z `getPropertyValue`, gubi skróty: dla `padding`
o różnych składowych ta funkcja zwraca pusty łańcuch. „Przywrócona" reguła
zostaje trwale okaleczona, a każdy następny pomiar na tej stronie biegnie po
arkuszu, który poprzedni pomiar zepsuł. To jest §5b przeniesione z plików na
CSSOM — i tak samo jak tam, przywrócenie trzeba SPRAWDZIĆ, a nie założyć.

### Jedna różnica, której ta pułapka NIE dotyczy

Nasze `data-text-scale` z profilu **nie jest** przykładem tej pułapki. Ono
skaluje tokeny tekstu (`--user-text-scale`), a nie `font-size` korzenia, więc
`rem` w regułach układu przy nim nie rośnie i progi zapytań medialnych się nie
ruszają. Mylenie go z powiększeniem pisma w przeglądarce to osobna pomyłka —
i to ona stoi za komentarzem uzasadniającym `7rem` „czytelnością przy skali
tekstu 150%", podczas gdy skali 150% w tym produkcie nie ma w ogóle
(`tokens.css` daje 70/80/90/112/125/140).

## Skąd ta lista

Trzy warstwy zewnętrznego audytu z 10.09.2026
(`docs/research/audyt-2026-09-10/`) znalazły osiem P1 w kodzie i wszystkie
były jednym rodzajem błędu: **inwariant sprawdzany, a potem wykonywany,
zamiast wykonany atomowo.** Przy weryfikowaniu tych ośmiu poprawek wyszło
sześć pułapek wyżej — i to one, nie same poprawki, są tu najtrwalszą
wartością.

Siódma dołączyła 11.09.2026, przy zakładaniu grupy `dwa-polaczenia` (D-105,
issue #314): wyszła z pomiaru zrobionego po to, żeby sprawdzić, czy nowy
szkielet w ogóle cokolwiek mierzy. Okazało się, że przy jednym połączeniu nie
mierzy — i że wygląda przy tym dokładnie tak samo jak wtedy, gdy mierzy.

Ósma dołączyła 11.09.2026 razem z dopiskami 8b i 8c — z tego samego dnia
i tej samej sesji kilkunastu agentów pracujących równolegle. Cała trójka jest
jedyną częścią tej listy, która nie dotyczy kodu testu, tylko **obsługi
pomiaru: czytania własnej kontroli ujemnej, czytania cudzej czerwieni
i narzędzi, które przy kilku agentach naraz zachowują się inaczej, niż
podpowiada intuicja o izolacji**. Ósma wyszła z serii trzech kontroli
ujemnych, które oblały się trzy razy z tego samego, cofniętego wraz z naprawą
powodu. Ta trójka zostaje na liście, bo lista pilnuje nie tylko tego, żeby
test mierzył, ale i tego, żeby jego pomiar był uczciwy — a pomiar czytany
źle albo skasowany przez własne narzędzie nie jest uczciwy.

Dziewiąta dołączyła 12.09.2026 — jedyna na tej liście, która zapaliła się
sama, bez czyjegokolwiek commita, o godzinie wpisanej w test kilka dni
wcześniej. Zostaje tu, bo należy do tej samej rodziny co 8b: **czerwień, którą
czytasz, nie musi pochodzić ze zmiany, którą właśnie oglądasz.**

Dwie zasady o kodzie, które z tego zostają (D-079, obowiązują szerzej niż
miejsce zapisu):

1. **Gwarancję daje constraint albo blokada, nie `exists()` w PHP.**
   `exists()` jest dobre na ładny komunikat i tam zostaje.
2. **Blokada bez rewalidacji pod nią nie pilnuje niczego** — serializuje, ale
   nie mówi żądaniu, że świat zmienił się, gdy ono czekało.
