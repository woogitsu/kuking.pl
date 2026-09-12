# Pułapki testów — osiem rzeczy, które w tym repozytorium naprawdę przeszły

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
| `/logowanie` | `assertSee('Zaloguj się')` | `<title>` + `<meta>` + przycisk belki dla gościa |
| `/napisz-do-nas` | `assertSee('Napisz do nas')` | `<title>` + 4 × `<meta>` + odnośnik stopki (na każdym ekranie) |
| 404 | `assertSee('Nie znaleźliśmy tej strony')` | `<title>` + `<meta>` |
| `/` (gość) | `assertSee('Pokaż, co dziś ugotowałeś')` | `<title>` + `<meta>` |
| `/wpis/nowy` | `assertSee('Dodaj zdjęcie')` | `<title>` |
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

---

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

Dwie zasady o kodzie, które z tego zostają (D-079, obowiązują szerzej niż
miejsce zapisu):

1. **Gwarancję daje constraint albo blokada, nie `exists()` w PHP.**
   `exists()` jest dobre na ładny komunikat i tam zostaje.
2. **Blokada bez rewalidacji pod nią nie pilnuje niczego** — serializuje, ale
   nie mówi żądaniu, że świat zmienił się, gdy ono czekało.
