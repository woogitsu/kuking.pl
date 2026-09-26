## D-026 · Baza tagów pochodzi ze słownika w pliku, a stare nazwy są scalane, nie dublowane

**Data:** 7 września 2026 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „dane w DWÓCH
> plikach JSON, czytanych przez `TagSeeder`… razem 1419 tagów i 2448 aliasów"
> opisuje nieaktualne źródło danych. `database/seeders/TagSeeder.php:104-106`
> deklaruje TRZY pliki — doszedł `slownik-tagow-v1.1.json` (27 nazw
> kanonicznych), którego nie opisuje żadna decyzja w tym rejestrze. Liczby
> 1419/2448 są przez to zaniżone, a `docs/DATABASE.md:3203` powtarza je za tym
> wpisem, więc ta sama nieprawda stoi w dwóch dokumentach naraz. Mechanizmy
> opisane niżej — scalanie starych nazw zamiast dublowania, zakaz tagów
> dietetycznych — działają i mają test
> (`tests/Feature/SlownikTagowTest.php:47-51`).

Początkowa baza tagów (SPEC §1.4) była wpisana na sztywno w `TagSeeder`:
651 nazw i 53 aliasy, ułożone przeze mnie przy okazji implementacji D-021.
Zamówiony osobno słownik ma 1250 nazw kanonicznych i 2366 aliasów, w 13
kategoriach, i jest ułożony pod polską kuchnię domową oraz pod grupę 50+ —
dwie kategorie istnieją tylko dlatego: `pamiec` („przepis po babci",
„z rodzinnego zeszytu") i `okolicznosci` („dla wnuków", „z czerstwego
chleba", „mało zmywania", „dla niejadka"). Poprzednia baza nie miała ani
jednego takiego tagu.

### Co odrzucono

**Zostawienie starej bazy i wpięcie słownika obok.** Zmierzone: 43 nazwy ze
starej bazy nowy słownik traktuje jako alias czegoś innego („marchewka" →
„marchew", „schabowy" → „kotlet schabowy", „pieczenie" → „pieczone").
Wpięcie obok daje 43 pary żywych tagów na jedno pojęcie — czyli dokładnie
to rozsypanie taksonomii, przed którym cała ta baza ma chronić („zakwas /
na zakwasie / chleb zakwas / ZAKWAS — po miesiącu nie ma czego obserwować").
**Zaniechanie nie było tu neutralne.**

**Wyrzucenie starej bazy w całości.** Zmierzone: słownik nie ma 293 pojęć,
które stara baza miała — w tym podstawowych składników („kapusta", „seler",
„fasola", „olej", „orzechy"), części mięsa i klasyków bez odpowiednika
(„zrazy", „tatar", „sękacz"). Wymiana jednego kompletu na drugi zabierałaby
je bez powodu.

**Dodanie kolumny na `sezonowy`.** 226 tagów w słowniku ma podpowiedź
sezonu. Nic w kodzie nie umie z niej korzystać, a funkcji sezonowości nie
ma. Kolumna bez drogi zapisu i odczytu to ten sam błąd, który opisuje
zadanie o minutniku kroku (kompletna funkcja za polem, którego nikt nie
umie ustawić). Informacja zostaje w pliku.

### Co wybrano

**Dane w dwóch plikach JSON, czytanych przez `TagSeeder`:**
`slownik-tagow.json` (dostarczony, nietknięty — razem z polem `uwagi`,
44 rozstrzygnięciami autora, z odsyłaczami do WSJP PAN i Listy Produktów
Tradycyjnych MRiRW) oraz `slownik-tagow-uzupelnienia.json` (169 pojęć,
których słownik nie ma ani jako nazwy, ani jako aliasu).
Razem **1419 tagów i 2448 aliasów**. Dwa pliki, a nie jeden, żeby kolejna
wersja słownika podmieniała JEDEN plik bez scalania cudzych zmian w środku
listy.

Z poprzedniej bazy świadomie NIE przeniesiono nazw angielskich i modnych
(„cookies", „smoothie bowl", „chia pudding"), fraz zamiast pojęć („obiad
w piętnaście minut"), nazwy marki („termomix" — słownik ma potoczne
`w termomiksie` małą literą) oraz tagów **„fit", „dieta odchudzająca"
i „dieta sportowca"**, które łamią tę samą regułę o języku dietetycznym,
jaką postawiono słownikowi. Test tego pilnuje, więc nie wrócą.

**Dziesięć pojęć ogólnych dołożonych po pomiarze podpowiedzi.** Wgranie
słownika pozwoliło zmierzyć coś, czego na 651 tagach nie było widać: dla
każdej złożonej nazwy sprawdzone, czy jej pierwsze słowo istnieje
samodzielnie. Nie istniało dla `barszcz`, `kotlety`, `krem`, `kasza`, `sok`,
`syrop`, `pasta`, `placki`, `nalewka`, `ser` — więc wpisanie samego słowa
„barszcz" podpowiadało „barszcz biały", rozstrzygając za człowieka, którego
barszczu mu trzeba. Nie jest to zarzut do słownika: jego uwaga 25 mówi, że
nazwy ogólne i odmiany celowo współistnieją, po prostu tych dziesięciu
zabrakło.

**Ranking podpowiedzi doprecyzowany, bo przy 1419 tagach przestał
wystarczać.** SPEC §1.5 mówił „dokładne dopasowanie początku nazwy →
dokładny alias → trigram", a wszystkie trafienia z pierwszej gałęzi miały
tę samą wagę — czyli ich kolejność brała się z fizycznej kolejności wierszy.
Zmierzone: wpisane „chleb" dawało jako pierwszą podpowiedź „chlebek
bananowy", a wpisane „marchewka" — „marchewkę z groszkiem", mimo że
„marchewka" jest dokładnym aliasem „marchwi". Ten sam błąd w dwóch
miejscach: dopasowanie DOKŁADNE przegrywało z częściowym. Nowa kolejność:
dokładna nazwa → dokładny alias → początek nazwy od najkrótszej → trigram,
a na końcu alfabet, żeby ta sama fraza dawała ZAWSZE tę samą listę
(`docs/UX_50_PLUS.md`: przewidywalność przed bogactwem).

**Kolizja aliasu z istniejącym tagiem: scalenie, nie odrzucenie** —
`MergeTags` (SPEC §1.8), ale WYŁĄCZNIE gdy stary tag jest pusty
i redakcyjny: `is_seeded`, `active`, bez wpisów, bez obserwujących, bez
promocji i sam nieobecny w słowniku. Tag, którego ktoś już użył albo który
powstał z ręki człowieka, zostaje nietknięty — alias jest wtedy odrzucany
i zgłaszany w raporcie, a decyzja zostaje przy człowieku. Cena tego
zaniechania (dwa tagi na jedno pojęcie do czasu decyzji) jest niższa niż
cena scalenia komuś tagu, którego używa.

**`MergeTags` powstało przy tej okazji i to jest osobne ustalenie.**
Kolumny `tags.status = 'merged'` i `tags.merged_into_tag_id` istniały od
migracji `create_tags_tables`, a mechanizm ich czytania był kompletny:
strona tagu przekierowuje, podpowiedzi wykluczają, `ResolveTagsForPost`
rozwiązuje wpisaną nazwę do tagu kanonicznego. Ustawiał je natomiast
wyłącznie `forceFill` w testach — mimo że komentarz modelu `Tag` i komentarz
migracji odsyłały do `MergeTags` jako do istniejącej klasy. **Trzeci taki
przypadek w tym repozytorium** (po minutniku kroku i po D-018): reguła
zapisana w jednej warstwie, a w drugiej niewykonalna.

**Zmierzony efekt uboczny:** `php artisan db:seed` na czystej bazie kończył
się wyjątkiem, bo `DemoSeeder` tworzył tag „chleb na zakwasie", który
`TagSeeder` już wstawił (`UNIQUE(normalized_name)`). Naprawione: `DemoSeeder`
idzie teraz przez `ResolveTagsForPost`, czyli tę samą bramkę, co prawdziwy
formularz wpisu — a „zupy" rozwiązuje się przy okazji do kanonicznego
„zupa", zamiast tworzyć drugi tag na to samo.

📄 `database/seeders/dane/slownik-tagow.json` ·
`database/seeders/dane/slownik-tagow-uzupelnienia.json` ·
`database/seeders/dane/README.md` · `database/seeders/TagSeeder.php` ·
`app/Domain/Tags/Actions/MergeTags.php` ·
`app/Domain/Tags/TagSuggester.php` · `tests/Feature/SlownikTagowTest.php` ·
`tests/Feature/PodpowiedziNaPelnymSlownikuTest.php` ·
`tests/Feature/TagSeederZeSlownikaTest.php` ·
`tests/Feature/ScalanieTagowTest.php`
