# #30 — pokrycie obietnicy trwałości i najmniejszy zakres materiałów offline

## 1. Audyt pokrycia: dziś NIE publikować „Twoje przepisy nie zginą”

**Rekomendacja: NIE dla stałego dodatkowego claimu i NIE dla używania go
kontekstowo jako bezwarunkowej gwarancji.** Nie ma wykazanego odtworzenia
produkcyjnej bazy z kopii, a ochrona zdjęć przed logicznym usunięciem pozostaje
nieodebrana. Eksport użytkownika zmniejsza ryzyko tylko wtedy, gdy człowiek
zdąży go pobrać i zachowa pliki. Nie zastępuje kopii serwisu.

Audyt: **20.09.2026**, odczyt Railway około **19:00–19:06 UTC**.
Źródło kodu: `gpt/offline-obietnica`,
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`; początkowo czyste drzewo.
Status dokumentu: **projekt i rekomendacja do decyzji właściciela**.
Nie jest to decyzja właściciela, wdrożenie ani odbiór produkcyjny.

### 1.1 Baza: istnieje mechanizm, nie ma dowodu produkcyjnej ścieżki

| Dowód | Co ustalono | Czego nie dowodzi |
|---|---|---|
| **Własny odczyt Railway**, `list_projects`, `list_services`, `get_service_config` | W projekcie `ideal-exploration`, środowisku `production`, są dwa serwisy: `Postgres` i `kuking.pl`. **Nie ma `kopia-bazy`**. Konfiguracje tych dwóch usług nie zawierają `cronSchedule`. Postgres ma obraz `ghcr.io/railwayapp-templates/postgres-ssl:18` | Nie wyklucza ręcznej kopii u właściciela, usługi poza tym projektem ani Volume Backups/PITR. Tag obrazu nie jest zapytaniem o rzeczywistą wersję serwera |
| **Własny odczyt Railway**, `list_variables` dla aplikacji | Lista nazw nie zawiera `AWS_KOPIE_BUCKET`, `AWS_KOPIE_ACCESS_KEY_ID`, `AWS_KOPIE_SECRET_ACCESS_KEY`, `LOG_BLAD_WEBHOOK_URL`. Wartości są ukryte przez OAuth; żadnych sekretów nie odczytano | Nie jest testem dostarczenia alarmu; nie sprawdza ustawień panelu Cloudflare |
| **Własny odczyt kodu**, `docker/kopia/kopia-bazy.sh`, `docker/kopia/Dockerfile`, `.railway/railway.ts` | Jest osobny obraz `postgres:18`, zrzut custom, pełne przeczytanie archiwum, szyfrowanie CMS, wysyłka szyfrogramu i `.meta`, kontrola rozmiaru, retencja. Domyślnie 30 dni, minimum 7 potwierdzonych kopii, alarm po 36 h; limit pojedynczego pliku 5 GiB | Deklaracja serwisu nie uruchamia go. Odczyt całego archiwum przez `pg_restore --file=/dev/null` nie wykonuje SQL na docelowym serwerze. Minimum 7 może zachować stare kopie dłużej niż 30 dni |
| **Własny odczyt kodu**, `scripts/proba-odtworzenia.sh` | Odtwarza przez `pg_restore --exit-on-error` do chronionej nazwą i odciskiem instancji bazy próbnej. Mierzy tabele/wiersze, migracje oraz zachowanie ograniczeń. `--petla-lokalna` woła **`scripts/kopia-lokalna.sh`**, nie cały kontener wysyłający do R2 | Lokalna pętla nie sprawdza crona Railway, prawdziwego R2, produkcyjnego klucza ani czasu odzyskania całej usługi |
| **Własny odczyt kodu**, `scripts/proba-wycofania.sh` | Na osobnej bazie wykonuje migracje w obie strony, porównuje schemat i sprawdza częściowe cofnięcia | To próba migracji na pustej bazie. Nie odtwarza danych użytkowników, zdjęć ani kopii z R2 |
| **[pomiar cudzy: `docs/infra/KOPIE_I_ODTWORZENIE.md` §5.1, 17 IX]** | Lokalny PostgreSQL 18.6, 50 tabel, 192 wiersze, 80 migracji, odtworzenie 2 s | To rzeczywisty opis próby na PG18, a nie samo założenie zgodności. Nie jest jednak pomiarem Railway ani produkcyjnym RTO |
| **[pomiar cudzy: ten sam dokument §5.1, 18 IX]** | Zbudowany kontener, PG18.6 → szyfrowanie → MinIO → pobranie → odszyfrowanie → odtworzenie; 50 tabel i 202 wiersze zgodne ze źródłem | MinIO nie dowodzi działania tokenów, jurysdykcji i konfiguracji Cloudflare R2. Ten pomiar nie jest powtórzony w tej sesji |
| **Własny odczyt dokumentacji repo**, `KOPIE_I_ODTWORZENIE.md` §5 | Tabela próby **produkcyjnej** jest pusta; próby deweloperskie stoją osobno | Brak wpisu nie dowodzi, że nikt nigdy nie wykonał ręcznej kopii. Oznacza brak dowodu potrzebnego do obietnicy |

**Werdykt:** mechanizm odtwarzania ma sensowne testy i opisane rzeczywiste
próby PG18. Nie wolno pisać, że wszystko jest tylko atrapą. Nie wolno też
przepisać lokalnej zieleni jako dowodu ochrony produkcji. Bieżący odczyt
potwierdza brak zadeklarowanej automatyzacji offsite; nie potwierdza całkowitej
liczby wszystkich możliwych kopii poza nią.

Roadmapa §11 „PWA + hardening” jest **częściowo wykonana**: mechanizm kopii,
czujka i narzędzia odtworzenia istnieją. Warunek „restore przetestowany”
w „Closed alpha gate” pozostaje **nieudokumentowany dla produkcji**.
D-043, D-049, D-143 i D-192 opisują tę granicę. D-192 z 12 IX nie zastępuje
późniejszych prób z 17–18 IX.

**Sprzeczności źródeł:** stary początek `KOPIE_I_ODTWORZENIE.md` nadal mówi,
że R2 nie istnieje. D-118 wraz z adnotacją z 20 IX wskazuje ten błąd;
nie używam go jako ustalenia o dzisiejszym storage. D-043 mówi o wymaganiu
planu Pro, ale §5.3 dokumentu kopii opisuje nierozstrzygnięty rozjazd między
panelem a dokumentacją Railway. Nie rekomenduję zakupu planu na podstawie
tego starego zdania. Dostępność i odbiór Volume Backups/PITR trzeba sprawdzić
osobno; udostępnione tu narzędzia nie zwróciły historii tych kopii.

### 1.2 Zdjęcia: trwałość R2 nie jest ochroną przed DELETE

Własny przegląd `config/filesystems.php`, `docker/`, `scripts/`,
`.railway/railway.ts`, `docs/MEDIA_PIPELINE.md` i dokumentów infrastruktury
nie znalazł wdrożonego procesu kopii zdjęć ani konfiguracji wersjonowania,
replikacji lub rygla dla ich kopii. Dysk `r2_kopie` służy **kopiom bazy**.
Oryginał i wariant zdjęcia nie są niezależną warstwą odzyskiwania: kasowanie
mediów może usunąć oba, a zrzut PostgreSQL zawiera jedynie metadane.

**[pomiar cudzy: D-118 i `LOKALIZACJA_DANYCH_R2.md` §6a, 18 IX]** Zdjęcia są
w R2, lecz osobny bucket kopii nie został uruchomiony. Aktualne #617 pozostaje
otwarte. **Nie odczytałem zalogowanego panelu/API Cloudflare**, więc brak
konfiguracji w repo nie jest dowodem, że nikt nie ustawił ochrony ręcznie.
Wynik brzmi: **ochrona niepotwierdzona**, a nie „sprawdziłem wszystkie buckety”.

Aktualna dokumentacja Cloudflare, odczytana 20 IX:

- S3 API R2 oznacza operacje wersjonowania i replikacji bucketów jako
  niewdrożone. Nie projektujemy rozwiązania „włącz wersjonowanie S3 w R2”.
  [Zgodność S3 API](https://developers.cloudflare.com/r2/api/s3/api/).
- R2 ma **Bucket Locks**: blokadę usuwania i nadpisania obiektów według
  prefiksu oraz okresu. Administrator konfiguracji może usunąć regułę.
  To nie jest nieodwracalny S3 Object Lock compliance ani ochrona przed
  przejęciem całego konta. [Bucket Locks](https://developers.cloudflare.com/r2/buckets/bucket-locks/).
- Koniec blokady nie usuwa pliku. Osobny lifecycle wykonuje usunięcie
  asynchronicznie, zwykle w ciągu doby od terminu; to nie twarda gwarancja.
  [Object lifecycles](https://developers.cloudflare.com/r2/buckets/object-lifecycles/).

**Rekomendacja zależności:** domknąć #617 przed komunikatem o trwałości zdjęć.
Najpierw uzgodnić retencję z #8, następnie kopię w oddzielnym, prywatnym
buckecie z osobnymi poświadczeniami. Aplikacja nie dostaje praw do kopii;
proces kopiujący nie dostaje uprawnienia do zmiany konfiguracji rygla.
Nie ryglować żywego `incoming/`: utrudni to działający proces usuwania kont.
Reguły retencji z §6a są projektem, nie wykonaniem ani opinią prawną.

### 1.3 Co dokładnie jest zbadane w tej sesji

Przed zmianą dokumentacji przygotowano runtime wskazanym skryptem floty.
Własny odczyt SQL potwierdził: baza `kuking_flota_gpt-offline-obietnica`,
rola `kuking`, **127.0.0.1:55439, PostgreSQL 18.6**.

| Wykonana kontrola | Wynik | Granica dowodu |
|---|---|---|
| `php artisan test`: `DataExportTest`, `EksportWygladObietnicePaczkiTest`, `PetlaOdtworzeniaJestJednaKomendaTest`, `KopiaBazyPozaRailwayemTest`, `CichyBrakKopiiBazyDajeAlarmTest`, `AlarmKopiiNieUfaBrakowiWyjatkuTest` | **78 testów, 365 asercji, PASS**, 4,13 s | ZIP jest rzeczywiście składany i czytany, storage i poczta są podstawione; zdjęcia testowe nie dowodzą jakości renderowania prawdziwych fotografii. Czujki nie wysyłają wiadomości do ludzi |
| `bash tests/skrypty/kopia-bazy.sh` | **167 sprawdzeń, PASS**, także odrzucenie obciętych/uszkodzonych archiwów i błędów retencji | Prawdziwe OpenSSL, `pg_restore`, archiwum i lokalny HTTP; `pg_dump` i część S3 są podstawione. Bez produkcyjnej bazy, Cloudflare i budowania obrazu |
| Pint `--test`, `app/` i `tests/` w runtime | **PASS, 1007 plików** | Kontrola formatowania, bez modyfikowania PHP |

`PetlaOdtworzeniaJestJednaKomendaTest` sprawdza rozpoznawanie argumentów,
odmowy i zgodność instrukcji — nie uruchamia pełnego odtworzenia.
`ProbaOdtworzeniaTest` uruchamia prawdziwe lokalne zrzuty i restore przez
`tests/skrypty/proba-odtworzenia.sh`, ale **nie został tu uruchomiony**:
runtime nie ma `.git`, a wyliczanie sufiksu w tym skrypcie prowadzi do wspólnej
`kuking_zrodlo_proby_glowny`. To jawnie dopuszczone pominięcie ze zlecenia.
Nie ma podstaw, by wynik tego niewykonanego testu nazwać zielonym lub czerwonym.

Nie uruchomiono pełnej suity, `check.sh`, `proba-wycofania.sh` ani nowej próby
restore produkcyjnego. Zakres to dokument projektowy i wskazane pomiary,
bez zmian schematu lub kodu; pełny przebieg zawiera także testy tworzące
pomocnicze bazy poza bazą tego stanowiska. Odczyt skryptu wycofania nie został
przedstawiony jako jego wykonanie. Nie dodano testu ani regresji produktowej;
nie ma poprawki, dla której należałoby wykonywać nową mutację red–green.

## 2. Rekomendacja o haśle i warunki ponownej oceny

**Odrzucić proponowane hasło w obecnym brzmieniu.** Nawet po domknięciu
kopii backup dobowy pozostawia okno możliwej utraty danych, retencja jest
ograniczona, a świadome usunięcie konta ma działać. Absolutne „nie zginą”
obiecuje więcej niż taki system zapewnia. Nie dopisuję tego jako zatwierdzonej
decyzji do `docs/DECISIONS.md` i nie zamykam issue #30.

Proponowany kierunek tekstu do zatwierdzenia:

> Pobierz swoje przepisy. Czytaj je także bez internetu.

Obok musi być instrukcja pobrania, rozpakowania i zachowania całego folderu,
a nie sugestia, że sama rejestracja zapewnia trwałość. To dodatkowy argument
przy społeczności, bez zmiany głównego pozycjonowania produktu.

Przed silniejszym, mierzalnym komunikatem o kopiach potrzebne są:

1. Działający harmonogram i pierwszy kompletny szyfrogram produkcyjnej bazy,
   pobrany **z prawdziwego R2**, zgodność skrótu, odtworzenie w odizolowanej
   instancji PG18. Zapis wersji, daty i wieku kopii, liczników, integralności
   relacji oraz sond zachowania. Porównanie liczby wierszy nie wykryje
   zmienionej treści; potrzeba także porównania treści kontrolnego zestawu.
2. Potwierdzenie odczytu klucza prywatnego z niezależnego miejsca, zgodności
   `APP_KEY` z odtwarzanymi danymi i działania aplikacji na przywróconej bazie.
   Bez wykonywania zaległych maili/jobów z kopii. Miarą RTO jest czas do
   odzyskania działania, nie sam `pg_restore`.
3. Odebrane #617: kopia zdjęć, odtworzenie kontrolnych mediów, sumy/rozmiary,
   ponownie działające warianty, skuteczność rygla i późniejszego usuwania.
4. Wskazany odbiorca alarmu i sprawdzona dostawa; monitoring braku przebiegu,
   także gdy sama aplikacja nie działa. Próbę z wysłaniem do człowieka należy
   przeprowadzić jako osobno upoważnioną czynność, nie w tym audycie.
5. Właściciel zatwierdza docelowe RPO/RTO i retencję, a pomiary je potwierdzają.
   Propozycja do kosztorysu: kopia dobowa, **cel** RPO ≤24 h przy zdrowym
   harmonogramie; RTO dopiero po pomiarze. Nie obiecujemy odzyskania danych,
   których nie objęła żadna udana kopia.

Próby nie mogą przywrócić usuniętych kont lub zdjęć do publicznego serwisu.
Po całkowitej utracie bazy sama przywrócona stara baza **nie wie** o późniejszych
żądaniach usunięcia. Potrzebna jest niezależna, chroniona lista tych decyzji
albo procedura odtworzenia ich z nowszego źródła; bez niej odbiór odzyskiwania
jest niepełny. Zakres i retencję tego rejestru należy rozstrzygnąć w #617/#8.

## 3. Najmniejsza wersja offline: użyć tego, co już działa

### 3.1 Dwa znaczenia „materiałów offline”

Własny odczyt [issue #30](https://github.com/woogitsu/kuking.pl/issues/30)
wraz z komentarzem: aktualny opis mówi o **materiale do druku dla KGW/UTW**,
nie o budowie eksportu od nowa; eksport #2 uznaje za wykonany. Załączone
zlecenie prosi dodatkowo o projekt technicznego minimum. Ten dokument
obejmuje oba znaczenia, zachowując zakaz implementacji.

**Wynik pomiaru na nietkniętym drzewie:** eksport już tworzy ZIP z `index.html`,
`przepisy/*.html`, `wpisy.html`, lokalnymi `zdjecia/*`, `dane.json` i instrukcją
`CZYTAJ-TO-NAJPIERW.txt`. Przepisy są do czytania i wydruku bez sieci.
Nie potrzeba nowego formatu, biblioteki PDF ani aplikacji offline.

### 3.2 Zakres V1-minimum

| Wchodzi | Granica |
|---|---|
| Obecna paczka własnych treści, prywatnych wpisów i szkiców | Stan z chwili przygotowania, bez późniejszej synchronizacji. Nie obiecywać kompletnej historii `recipe_versions` |
| Zdjęcia objęte istniejącym planem eksportu | Materiały niegotowe, odrzucone i usunięte mają jawne ograniczenia. Nie obiecywać każdego zdjęcia, które kiedykolwiek wysłano |
| Zeszyt w obecnym zakresie | Dla zapisanych przepisów: tytuł, autor, własna notatka i data zapisu. **Nie pełna treść cudzych przepisów**. Widoczne zapisane wpisy mogą mieć treść; niewidoczne są wykazane liczbowo |
| Wydruk pojedynczego własnego przepisu z HTML | Funkcja drukowania przeglądarki; „zapisz jako PDF” to wybór systemowy, nie nowy serwerowy generator |
| Jednostronicowa instrukcja KGW/UTW | Uczy użycia istniejącej paczki, nie gwarantuje przetrwania serwisu |

Feed, publikowanie i komentowanie bez internetu, prywatny cache PWA,
synchronizacja zmian i konflikty wersji **nie wchodzą**. `public/sw.js`
celowo przechowuje zasoby statyczne i ekran braku sieci, nie prywatny HTML.
Koszt i ryzyko odsłonięcia treści na wspólnym urządzeniu są niepotrzebne do
czytania pobranego przepisu.

Pełne zachowanie cudzych przepisów zapisanych do Zeszytu to **osobna decyzja**:
potrzebne reguły uprawnień do kopii, zachowania po zmianie widoczności/blokadzie,
autorstwa i późniejszego usunięcia. Nie utrwalamy tej nowej polityki asercją.

### 3.3 Ekrany, tabele, trasy, przechowywanie

| Obszar | V1-minimum | Rozszerzenie tylko po decyzji |
|---|---|---|
| Ustawienia konta | Istniejące `/ustawienia/twoje-dane`, „Przygotuj paczkę z moimi danymi”, lista i „Pobierz paczkę” — **zero wymaganych zmian** | Doprecyzowanie instrukcji dopiero gdy test z użytkownikami wykaże problem |
| Zeszyt | Bez nowego eksportu i bez zmiany ekranu | Jeden opisany link do istniejących ustawień; nie przycisk obiecujący pobranie wszystkich cudzych przepisów |
| Tabele | Istniejące `data_exports` oraz dane źródłowe `recipes`, `recipe_ingredients`, `ingredients`, `units`, `recipe_steps`, `media`, `collections`, `collection_items`; reszta zgodnie z obecnym eksportem konta | **Nowe tabele: 0. Migracje: 0** dla proponowanego minimum i samego linku |
| Wykonanie | `GenerateUserExport`, istniejąca kolejka `low`, paczka przygotowywana na żądanie w tle | Bez nowego serwisu i bez synchronicznego pakowania zdjęć w żądaniu HTTP |
| Trasy/model/widoki | Wykorzystanie istniejących tras żądania i pobrania, `DataExport`, `pages/settings/data` i `exports/*` | **Nowe trasy i modele: 0**. Ewentualna zmiana Zeszytu: 1 istniejący widok, osobne zadanie |
| Storage | Istniejący prywatny dysk eksportów, domyślnie `r2_eksporty` przy R2; TTL domyślnie 7 dni z konfiguracji | Pobraną lokalnie paczką zarządza użytkownik; TTL linku nie usuwa jego lokalnej kopii |
| Ochrona | Istniejące uwierzytelnianie, sprawdzenie właściciela, podpis czasowy, limit i jeden aktywny eksport | Nie tworzyć publicznych linków, obejść Policy ani osobnego eksportu omijającego te kontrole |

Paczka całego konta może zawierać prywatne treści i dane kontaktowe właściciela.
Nie jest materiałem do rozdawania na spotkaniu. Instrukcja do rozdania nie
zawiera żadnych danych uczestników. Odrębna paczka „tylko moje przepisy” może
zmniejszyć to ryzyko, lecz jest nowym zakresem eksportu, poza #30 i poza minimum.

### 3.4 Projekt jednej kartki KGW/UTW — tekst roboczy do zatwierdzenia

**Nagłówek:** Pobierz swoje przepisy. Czytaj je także bez internetu.

**Wprowadzenie:** W Kuking pokazujesz, co gotujesz, i poznajesz inne kuchnie.
Swoje przepisy możesz też zachować na komputerze i wydrukować.

1. Zaloguj się i otwórz „Twoje dane” w ustawieniach konta.
2. Wybierz „Przygotuj paczkę z moimi danymi”. Gdy będzie gotowa, wybierz
   „Pobierz paczkę”. Do przygotowania i pobrania potrzebujesz internetu.
3. Rozpakuj pobrany plik ZIP. Zachowaj cały folder, także folder ze zdjęciami.
4. Otwórz `index.html`, a potem wybrany własny przepis. Teraz możesz czytać
   go bez internetu. Na komputerze użyj Ctrl+P, jeśli chcesz go wydrukować.
5. Zachowaj dodatkową kopię, na przykład na pendrivie. Po dodaniu kolejnych
   przepisów pobierz nową paczkę.

**Widoczna informacja:** Paczka zawiera także prywatne dane Twojego konta.
Zachowaj ją dla siebie. Zapisane w Zeszycie cudze przepisy nie są w niej
kopiowane w całości. Paczka informuje też o zdjęciach, których nie zawiera.

**Projekt składu:** A4, jedna kolumna, duże pismo (docelowo co najmniej 14 pt),
bez emoji, adres `kuking.pl/ustawienia/twoje-dane` zapisany tekstem.
Kod QR może być dodatkiem, nigdy jedyną drogą. Kolorystyka i znak z aktualnej
konstytucji marki; czytelność także na wydruku czarno-białym.
To specyfikacja i tekst, **nie gotowy, zatwierdzony plik do druku**.
Przed drukiem potrzebna próba rozpakowania i odczytu na Windows, Androidzie
i iOS oraz krótki test instrukcji z uczestnikami 50+. Nie twierdzimy, że
te próby przeglądarkowe albo badania już wykonano.

## 4. Koszt wdrożenia i utrzymania

### 4.1 Nakład pracy — szacunek, nie pomiar czasu realizacji

Osobodzień oznacza około 8 godzin pracy. Przedziały zakładają dostęp do
konfiguracji usług i brak zmiany polityki eksportowania cudzych treści.

| Zakres | Szacunek | Co jest w środku |
|---|---:|---|
| Materiał do #30 na istniejącej funkcji | **1–2 dni** | Redakcja, skład, wydruk próbny, test instrukcji i poprawki; organizacja spotkania poza tym czasem |
| Opcjonalny link z Zeszytu | **0,5–1 dzień** | Jeden widok, test drogi użytkownika, czytelność 320 px/200%, bez nowej tabeli |
| Produkcyjny odbiór istniejącej kopii bazy #193/#594 | **1–3 dni** | Konfiguracja, pierwszy przebieg, pobranie, odszyfrowanie, restore i dowód; bez oczekiwania na decyzje/dostępy |
| Prosta kopia zdjęć: pełne generacje + test odzyskania #617 | **3–5 dni** | Izolacja dostępu, manifesty i sumy, kopiowanie, czujka, odbiór usuwania/odzyskiwania, procedura |
| Optymalizacja kopii zdjęć do przyrostów i deduplikacji | **dodatkowe 3–5 dni** | Spójne manifesty, odwołania do wersji, bezpieczne sprzątanie; nie robić przed pomiarem skali |

Minimum #30 wymaga **0 dni budowy nowego eksportu**. DR jest istniejącym
zobowiązaniem infrastrukturalnym, nie kosztem wymyślonym wyłącznie dla ulotki.
Czas oczekiwania na potwierdzenie wygaśnięcia pełnej retencji zdjęć wyniesie
około miesiąca; nie mieści się w osobodniach implementacji. Pilotaż krótkiej
retencji sprawdza mechanizm, nie dowodzi upływu okna produkcyjnego.

### 4.2 Storage zdjęć — nie mylić x2 z miesięczną historią

Punkt cennika, odczyt 20 IX: **R2 Standard 0,015 USD/GB-miesiąc**, klasa A
4,50 USD/mln operacji, B 0,36 USD/mln; darmowy pułap odpowiednio 10 GB-miesiąc,
1 mln i 10 mln. Egress R2 bez opłaty. Zaokrąglenia dotyczą jednostek
rozliczeniowych. [Cennik Cloudflare](https://developers.cloudflare.com/r2/pricing/).

Poniżej własny model kosztu: `S` to GB **wszystkich chronionych obiektów**
(oryginały i warianty, a nie tylko rozmiar bazy). Nie odczytano rozmiaru
produkcyjnych bucketów, więc nie jest to prognoza rachunku Kuking.

| Wariant | Zajęte miejsce | Koszt storage łącznie przy S=10 / 100 / 1000 GB, USD/mies. |
|---|---|---:|
| Jedna warstwa źródłowa | S | 0,15 / 1,50 / 15 |
| Druga pełna kopia | 2S | 0,30 / 3 / 30 |
| Codzienna pełna generacja, rygiel 30 dni, lifecycle po 31 dniach | około **33–34S** ze źródłem, przy 32–33 przechowywanych generacjach | 4,95–5,10 / 49,50–51 / 495–510 |

Kwoty przed darmowym pułapem, podatkami i operacjami; wspólnego darmowego
pułapu nie odejmować oddzielnie dla każdego bucketu. Dodatkowy koszt kopii x2
to odpowiednio 0,15 / 1,50 / 15 USD. Liczba generacji nie jest twardym limitem:
opóźnione kasowanie wymaga alarmu i rezerwy budżetu.

**Rekomendacja do #617:** przy małym, zmierzonym zbiorze zacząć od pełnych
dobowych generacji pod nowymi kluczami z manifestem `disk/key/bytes/sha256`
oraz datą wykonania. Pozwala to zachować oddzielną wersję przed nadpisaniem,
bez własnego mechanizmu deduplikacji. Nie propagować DELETE jak w lustrzanym
`sync --delete`. Rygiel dotyczy generacji kopii, lifecycle jej późniejszego
usunięcia. Odczyt/kompletność manifestu jest warunkiem zaliczenia przebiegu.

**Dlaczego nie wystarczy „x2 + lock 30 dni”:** pojedyncza kopia starego pliku
po upływie rygla przestaje być chroniona, a lifecycle usunie ją nawet wtedy,
gdy oryginał jest nadal ważny. Potrzebny cykl nowych generacji albo odrębny
projekt kopii przyrostowej. Ten drugi może zbliżyć koszt do `2S + H + M`
(historia nadpisanych/usuniętych bajtów `H` i manifesty `M`), ale nie jest
prostym przełącznikiem R2 i wymaga testów sprzątania. Zwykłe lustro x2 bez
historii nie spełnia celu #617.

Dla `N` obiektów i pełnej kopii dobowej budżet operacji to co najmniej
`30N` zapisów oraz `30N` odczytów w miesiącu, plus paginacja, HEAD, manifesty
i multipart. Przykład: 100 tys. obiektów to co najmniej 3 mln zapisów i 3 mln
odczytów. Przy całkowicie wolnym darmowym pułapie oznacza około **9 USD**
za zapisy i 0 USD za te odczyty; przy już zużytym pułapie około **14,58 USD**
przed dodatkowymi operacjami i zaokrągleniami. Małe zdjęcia mogą kosztować
więcej w operacjach niż w samej przestrzeni.

Przy kopii poza R2 trzeba doliczyć stawkę dostawcy docelowego, odczyt przy
restore, operacje i transfer procesu kopiującego. Drugi bucket na tym samym
koncie ogranicza skutki utraty tokenu aplikacji, lecz nie utraty konta
Cloudflare. Osobne konto/dostawca daje inną granicę awarii, wymaga osobnego
odbioru dostępu, lokalizacji i kosztorysu. Nie podaję fikcyjnej ceny bez
wyboru tego dostawcy. Nie zakładam, że R2 ma włączoną natywną replikację.

### 4.3 Baza, paczki użytkownika i czas backupu

- Dla dobowego zaszyfrowanego zrzutu wielkości `D` GB i około 30 kopii:
  orientacyjnie `30D × 0,015 USD` miesięcznie w R2, przed pułapem i operacjami.
  Dochodzi czas kontenera, transfer wychodzący z Railway i miejsce tymczasowe.
  Minimum liczby kopii może przedłużyć retencję. Nie zmierzono produkcyjnego D.
- Istniejące eksporty: przy `E` nowych paczkach dziennie, średnio `Z` GB
  i TTL 7 dni przestrzeń jest rzędu `7EZ` GB. Przykład E=10, Z=0,2:
  14 GB, 0,21 USD/miesiąc przed darmowym pułapem. To nie obejmuje odczytów
  zdjęć, pracy kolejki, wysyłki do storage i kosztów sieci hostingu.
- Czas pełnej kopii zależy od S, liczby plików i przepustowości. Dolna granica
  transferu 100 GB przy efektywnym 10 MB/s to około 2 h 47 min, bez narzutu
  API i sprawdzania sum. To obliczenie, nie pomiar infrastruktury.
- Nie mnożyć lokalnych 1–2 sekund restore przez wielkość produkcji i nie
  podawać wyniku jako RTO. Do czasu wchodzą alarm, reakcja, dostęp do klucza,
  pobranie, odtworzenie bazy/zdjęć, weryfikacja i przywrócenie działania.

## 5. Co to zabiera

| Obowiązek | Proponowana częstotliwość | Koszt i konsekwencja |
|---|---|---|
| Sprawdzanie świeżości i kompletności kopii | Po każdym przebiegu + niezależna czujka | Operacje API, przechowywanie manifestów, obsługa alarmów; sama obecność pliku nie wystarcza |
| Przegląd wyników i budżetu | Co tydzień, 15–30 min | Nazwany operator i zastępstwo; cisza alarmu nie dowodzi działania |
| Odtworzenie bazy i kontrolnych zdjęć | Co miesiąc i po istotnej zmianie | Budżet **2–4 h pracy/miesiąc** na małej skali, plus czas automatu; to założenie do sprawdzenia |
| Próba utraty dostępu do głównego środowiska | Co kwartał | Dostęp do kodu, kluczy i instrukcji bez Railway; zależność kopii od tych samych kont |
| Kontrola usuwania starych kopii i decyzji usunięcia danych | Każdy przebieg + okresowa próba kontrolna | Kopie nie mogą po restore wskrzeszać danych usuniętych później; dłuższa retencja to dodatkowa odpowiedzialność |
| Odczyt paczki offline i próba wydruku | Po zmianach eksportu i przegląd okresowy | Windows/Android/iOS, zdjęcia, polskie znaki, brak sieci, duży tekst, błędy/niepełne media |
| Aktualność kartki KGW/UTW | Po zmianie ścieżki lub nazw przycisków | Nowy skład i wymiana papierowych egzemplarzy; starej ulotki nie aktualizuje wdrożenie |

Właściciel bierze także koszt pomocy przy rozpakowaniu ZIP i bezpiecznym
przechowywaniu prywatnych danych na domowym komputerze. Eksporty zużywają
kolejkę i dysk; przy jednym workerze zadanie w toku może opóźnić inne prace
mimo priorytetu `low`. Nowy format lub osobny wariant paczki podwaja część
obowiązków testowych — dlatego minimum pozostaje przy obecnym formacie.

Ryzyko reputacyjne jest konkretne: po haśle „nie zginą” użytkownik może
zrezygnować z własnej kopii. Utrata nieodtwarzalnego zdjęcia lub rodzinnego
przepisu będzie wtedy także złamaniem obietnicy. Usunięcie hasła później
nie cofnie tej decyzji użytkownika.

## 6. Decyzje właściciela i kolejność dalszej pracy

1. **Claim:** przyjąć rekomendację NIE i zatwierdzić tekst ograniczony do
   pobrania/czytania offline? Po decyzji wpisać ją do `docs/DECISIONS.md`;
   nie ogłaszać hasła jako zatwierdzonego na podstawie tego projektu.
2. **#30:** zatwierdzić kartkę i zamówić skład + próbę z KGW/UTW, czy świadomie
   odłożyć materiał? Minimalna praca nie wymaga nowego eksportu.
3. **Baza #193/#594:** wskazać osobę odpowiedzialną i termin konfiguracji oraz
   produkcyjnego ćwiczenia. Rozstrzygnąć plan/Backups w panelu; nie kupować
   Pro w ciemno. Wynik w tabeli §5 dokumentu kopii.
4. **Zdjęcia #617:** zaakceptować pełne generacje na start po zmierzeniu S/N
   i budżetu, czy zlecić droższą implementacyjnie kopię przyrostową? Czy
   ochrona ma obejmować także utratę konta Cloudflare (osobny dostawca/konto)?
5. **Retencja #8/#617:** uzgodnić okno, sposób usuwania oraz niezależny zapis
   decyzji o usunięciu. Rozróżnić minimum ochrony, termin sprzątania i czas
   faktycznego skasowania. Ten dokument nie ustanawia nowej polityki prawnej.
6. **Zeszyt:** pozostać przy tytułach/notatkach dla cudzych przepisów?
   Rekomendacja TAK dla minimum. Rozszerzenie ma osobny koszt i decyzję
   uprawnień; nie dokładać go przy okazji hasła.
7. **Eksploatacja:** zatwierdzić operatora, zastępstwo i budżet prób.
   Bez tego jednorazowy restore nie stanowi trwałego pokrycia obietnicy.

Nie ma tu nowych migracji, tras, modeli, widoków ani zmian usług. Wycofanie
tego dokumentu jest wycofaniem dokumentacji; baza i działanie aplikacji
pozostają nietknięte. Nie wykonano push, PR, komentarza w issue ani wysyłki
wiadomości do ludzi. Nie uznano #30, #193/#594 lub #617 za zamknięte.

## 7. Źródła i odtworzenie własnych kontroli

Odczytane zgłoszenia: [#30](https://github.com/woogitsu/kuking.pl/issues/30),
[#617](https://github.com/woogitsu/kuking.pl/issues/617).
Powiązania operacyjne: #193, #594, #120, #619; zobowiązania prawne: #8.

Najważniejsze pliki bazowego SHA:

- `docs/ROADMAP.md` §11 i Closed alpha gate; `docs/DECISIONS.md` D-043,
  D-049, D-078, D-114, D-118, D-143, D-192.
- `docs/infra/KOPIE_I_ODTWORZENIE.md` §5, §5.1–5.3;
  `docs/infra/LOKALIZACJA_DANYCH_R2.md` §6a (projekt #617, w tym sprostowania).
- `docker/kopia/kopia-bazy.sh`, `docker/kopia/s3.sh`,
  `docker/kopia/Dockerfile`, `scripts/proba-odtworzenia.sh`,
  `scripts/proba-wycofania.sh`, `tests/skrypty/proba-odtworzenia.sh`,
  `tests/Feature/ProbaOdtworzeniaTest.php`.
- `app/Jobs/GenerateUserExport.php`,
  `app/Domain/Users/Exports/CollectUserExportData.php`,
  `app/Domain/Users/Exports/ExportPhotoPlan.php`, `resources/views/exports/`,
  `resources/views/pages/settings/data.blade.php`, `config/kuking.php`,
  `config/filesystems.php`, `routes/web.php`, `public/sw.js`.

Polecenia wykonane z PowerShell przez `wsl --exec` (bez konwersji MSYS;
w Git Bash należy użyć wymaganego `MSYS_NO_PATHCONV=1`):

```powershell
wsl --distribution Ubuntu --exec bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-offline-obietnica
wsl --distribution Ubuntu --exec bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-offline-obietnica tests/Feature/DataExportTest.php tests/Feature/EksportWygladObietnicePaczkiTest.php tests/Feature/PetlaOdtworzeniaJestJednaKomendaTest.php tests/Feature/KopiaBazyPozaRailwayemTest.php tests/Feature/CichyBrakKopiiBazyDajeAlarmTest.php tests/Feature/AlarmKopiiNieUfaBrakowiWyjatkuTest.php
wsl --distribution Ubuntu --exec bash /home/mateusz/flota/gpt-offline-obietnica-run/tests/skrypty/kopia-bazy.sh
wsl --distribution Ubuntu --exec /opt/kuking-php-8.4-avif/bin/php /home/mateusz/flota/gpt-offline-obietnica-run/vendor/bin/pint --test /home/mateusz/flota/gpt-offline-obietnica-run/app /home/mateusz/flota/gpt-offline-obietnica-run/tests
```

Pierwsza próba uruchomienia testów z filtrem zawierającym `|` przez zwykłe
`wsl` została błędnie zinterpretowana przez powłokę i nie stanowi wyniku
całego zestawu. Poprawiony przebieg powyżej podaje jawne pliki i przeszedł.

### Stan przekazania stanowiska

Po pomiarach powiązanie Gita tego worktree przestało działać:
`git status` i `git rev-parse HEAD` zwracają `fatal: not a git repository: (NULL)`.
Użytkownik potwierdził, że prawdopodobnie trwa przebudowa stanowisk.
**Commit nie został wykonany; nie ma SHA nowego commita.** Nie naprawiano
wspólnych metadanych Gita w trakcie cudzej przebudowy. Początkowy SHA podany
wyżej jest źródłem audytu, nie SHA dostarczonej zmiany.

Własne porównanie SHA-256 z kopią runtime przygotowaną przed dokumentem:
**693 pliki w `app/`, `database/`, `resources/`, `routes/`; zero różnic.**
Dokument odczytano ponownie, sprawdzono UTF-8 i arytmetykę kosztorysu.

Po przywróceniu stanowiska: potwierdzić właściwą gałąź i bazowy SHA, sprawdzić
zakres zmian i commitować wyłącznie `docs/product/OFFLINE_I_OBIETNICA_30.md`.
Proponowany komunikat: „Oceń pokrycie obietnicy trwałości i koszt materiałów offline”.
Push i PR nadal należą do kolejki koordynatora, nie do tego stanowiska.
