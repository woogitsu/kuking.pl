# #30 — pokrycie obietnicy, minimum offline i koszt

## 1. Audyt pokrycia: dziś NIE publikować hasła „Twoje przepisy nie zginą”

**Rekomendacja: NIE dla bezwarunkowego claimu — zarówno stałego, jak i kontekstowego.**
Nie ma przedstawionego dowodu odtworzenia produkcyjnej bazy z automatycznej kopii
ani potwierdzonej ochrony zdjęć przed logicznym usunięciem. Działający eksport
nie uzupełnia tych braków: pomaga dopiero osobie, która pobrała paczkę przed
awarią, i obejmuje ograniczony zakres danych. Utrata zdjęcia przed eksportem
nie zostanie przez eksport odwrócona.

**Data audytu:** 20.09.2026. **Stan kodu na początku pomiaru:**
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/offline-obietnica`,
czyste drzewo. Dokument jest projektem do decyzji, nie wdrożeniem ani wpisem
udającym zatwierdzoną decyzję właściciela. Kod produkcyjny pozostaje nietknięty.

Oznaczenia dowodów: **[pomiar własny]** — wykonany w tej sesji;
**[odczyt kodu]** — stwierdzenie o badanym commicie, nie produkcji;
**[pomiar cudzy: źródło]** — historyczny wynik z dokumentacji;
**[projekt / szacunek]** — propozycja, nie stan istniejący.

### 1.1 Baza: mechanizm jest znacznie dalej niż jego odbiór produkcyjny

| Warstwa | Ustalenie | Co wolno z niego wywnioskować |
|---|---|---|
| Roadmapa | [odczyt kodu] `docs/ROADMAP.md` §11 zawiera backups i restore drill; „Closed alpha gate” wymaga przetestowanego restore | Sam zapis celu nie jest jego zaliczeniem |
| Wytworzenie kopii | [odczyt kodu] `docker/kopia/kopia-bazy.sh`: zgodność wersji, `pg_dump`, odczyt całego archiwum przez `pg_restore --file=/dev/null`, CMS, wysłanie szyfrogramu i metadanych, potwierdzenie, retencja; obraz `postgres:18` | To realny mechanizm, ale kontrola archiwum nie wykonuje SQL na nowym serwerze |
| Odtworzenie | [odczyt kodu] `scripts/proba-odtworzenia.sh`: wczytanie do pustej bazy próbnej, kontrola tabel, liczników wierszy, migracji, ograniczeń i zachowania wyzwalaczy; opcjonalne porównanie ze źródłem | To użyteczne narzędzie ćwiczenia, a nie zapis, że ćwiczenie produkcyjne wykonano. Zgodność liczników nie oznacza zgodności każdej wartości wiersza |
| Wycofanie migracji | [odczyt kodu] `scripts/proba-wycofania.sh`: migracje w dół i w górę oraz porównanie schematu | Nie pobiera produkcyjnego backupu i nie dowodzi odzyskania treści użytkowników |
| Test kopii | [pomiar własny] `tests/skrypty/kopia-bazy.sh`: **167/167** sprawdzeń | Prawdziwe szyfrowanie, archiwum i odczyt `pg_restore`, lokalne HTTP i kontrolowane odpowiedzi. `pg_dump` jest podstawiony; nie jest to rozmowa z R2 ani dump produkcji |
| Test odtworzenia | [odczyt kodu] `tests/Feature/ProbaOdtworzeniaTest.php` wywołuje `tests/skrypty/proba-odtworzenia.sh`; ten wykonuje prawdziwe lokalne dump/restore i kontrole ujemne | W tej sesji **nieuruchomiony** ze względu na wspólną nazwę bazy źródłowej; szczegóły w §6 |
| PostgreSQL 18 | [pomiar cudzy: `docs/infra/KOPIE_I_ODTWORZENIE.md` §5.1, 17 IX] lokalna pętla PG 18.6, 192 wiersze; [pomiar cudzy: tamże, 18 IX] obraz kontenera + PG 18.6 + MinIO, 202 wiersze | Nie wolno twierdzić, że sprawdzano wyłącznie PostgreSQL 16. Nie wolno też nazwać MinIO odbiorem R2 ani lokalnych sekund produkcyjnym RTO |
| Działający automat | [pomiar własny] Railway API, `production`: tylko `kuking.pl` i `Postgres`; obydwa bez `cronSchedule`, serwisu `kopia-bazy` brak | Zadeklarowany w `.railway/railway.ts` serwis nie jest uruchomiony w tym środowisku |
| Konfiguracja alarmu | [pomiar własny] lista nazw zmiennych z `get_service_config` aplikacji nie zawiera `AWS_KOPIE_*` ani `LOG_BLAD_WEBHOOK_URL` | Nie ma dowodu skonfigurowania czujki tą drogą. Odczyt pokazuje nazwy zmiennych bez sekretów; nie jest inspekcją efektywnej konfiguracji procesu |
| Backupy zarządzane | [ograniczenie] użyte API nie zwraca harmonogramów Volume Backups ani stanu PITR | **Nieustalone dzisiaj.** Brak serwisu kopii nie dowodzi braku każdej możliwej kopii ręcznej/zarządzanej |

Odczyt Railway: projekt `ideal-exploration`
(`77044ca0-2cf4-4be1-bcd8-fdb6c4d83047`), środowisko `production`
(`ea146c13-dc55-4a4f-a386-0835f650f9ce`). Konfiguracja Postgresa wskazuje
`ghcr.io/railwayapp-templates/postgres-ssl:18`; jest to odczyt obrazu,
nie zapytanie SQL do serwera produkcyjnego. Wszystkie zapytania tej sesji do
PostgreSQL szły wyłącznie do lokalnego `127.0.0.1:55439`.

**Werdykt dla bramki:** prace wykonane częściowo, ale „restore przetestowany”
w znaczeniu produkcyjnego odzyskania danych pozostaje **nieudowodnione**.
Nie podaję dzisiejszej liczby wszystkich kopii jako „zero”: takie historyczne
zdanie występuje w dokumentach, lecz nie sprawdziłem wszystkich magazynów.
Nie podaję też produkcyjnego RPO/RTO, bo nie mam pomiaru, który je wyznacza.

Aktualne [#193](https://github.com/woogitsu/kuking.pl/issues/193), odczytane
w tej sesji, wymaga prawdziwego dumpu i restore oraz odsyła do #594/#595.
Koryguje dwa stare założenia dokumentacji: dostępność Volume Backups/PITR
trzeba ustalić dla bieżącego planu, a wdrożenie kopii ma przejść przez plan
infrastruktury #595. Nie proponuję ręcznego deployu według starej instrukcji
ani uruchomienia `railway config apply` w ramach tego zadania.

### 1.2 Zdjęcia: odporność nośnika nie jest możliwością cofnięcia DELETE

**[odczyt kodu]** `pg_dump` zawiera rekordy `media`, ale nie bajty zdjęć.
`config/filesystems.php` rozdziela media, warianty, eksporty i kopie bazy;
rozdzielenie przeznaczeń nie jest drugą kopią zdjęć. W badanych `docker/`,
`scripts/`, `.railway/` i konfiguracji nie znaleziono wdrożonego zadania
kopiującego zdjęcia z historią odzyskiwania. Dokument
`docs/infra/LOKALIZACJA_DANYCH_R2.md` §6a wyraźnie oznacza rozwiązanie jako
**rekomendację niewykonaną**. [#617](https://github.com/woogitsu/kuking.pl/issues/617)
pozostaje otwarte i żąda testu odzyskania kontrolnych mediów.

**[ograniczenie]** Nie wykonano uwierzytelnionego odczytu konfiguracji bucketów
Cloudflare. Nie ustalono aktywnych locków, lifecycle, osobnego konta ani kopii
poza R2. Ich brak w kodzie nie dowodzi braku ręcznej konfiguracji. Na dziś
nie ma jednak dowodu, którym można pokryć hasło. Nazwy `AWS_BUCKET`,
`AWS_PUBLIC_BUCKET` i `AWS_EXPORTS_BUCKET` istnieją na serwisie Railway;
to nie jest dowód retencji ani odtworzenia zawartości.

**[odczyt aktualnej dokumentacji dostawcy, 20 IX]**

- Zwykłe usunięcie obiektu jest nieodwracalne; redundancja R2 zabezpiecza
  trwałość magazynu, a nie historię świadomie wykonanych operacji.
  Źródła: [usuwanie obiektów](https://developers.cloudflare.com/r2/objects/delete-objects/),
  [trwałość R2](https://developers.cloudflare.com/r2/reference/durability/).
- Macierz S3 oznacza `GetBucketVersioning`/`PutBucketVersioning` i operacje
  konfiguracji replikacji jako niewdrożone. Nie można wycenić rozwiązania
  „włączymy wersjonowanie R2” jak gotowego przełącznika. Wersje pod osobnymi
  kluczami i kopiowanie musiałby zapewnić osobny proces.
  [Zgodność S3](https://developers.cloudflare.com/r2/api/s3/api/).
- Bucket Locks blokują usuwanie i nadpisywanie przez określony czas, lecz
  administrator konfiguracji może zdjąć regułę. Lock nie jest kopią poza R2
  ani zabezpieczeniem przed przejęciem całego konta administracyjnego.
  [Bucket Locks](https://developers.cloudflare.com/r2/buckets/bucket-locks/).
- Lock sam nie usuwa starych kopii. Wymagana jest osobna polityka lifecycle;
  wykonanie usuwania jest asynchroniczne. Trzeba zmierzyć skutek, nie tylko
  odczytać regułę. [Lifecycle](https://developers.cloudflare.com/r2/buckets/object-lifecycles/).

W §6a istniejącego dokumentu zdanie „R2 trzyma wtedy stare wersje” nie jest
dowodem dostępności wersjonowania. Nie należy na nim budować projektu.

### 1.3 Co musi domknąć pokrycie techniczne

To kryteria odbioru prac #594/#193/#617, **nie czynności wykonane w tym zadaniu**:

1. Ustalić faktyczne Volume Backups/PITR i spis kopii; uruchomić zaplanowany
   offsite dump. Potwierdzić co najmniej dwa kolejne automatyczne przebiegi,
   odbiór alarmu i wykrywanie braku nowej kopii. Alarm wymagający działającej
   aplikacji nie zastępuje niezależnego nadzoru awarii całego środowiska.
2. Pobrać rzeczywisty zaszyfrowany dump z docelowego R2, odszyfrować kluczem
   z procedury awaryjnej, odtworzyć w izolowanej bazie PG 18. Sprawdzić
   migracje, ograniczenia, relacje, wybrane treści oraz działanie aplikacji.
   Zapisać identyfikator kopii, wersje, moment danych i czas całej procedury.
3. Odtworzyć własne kontrolne zdjęcie po usunięciu/nadpisaniu w środowisku
   próbnym: porównać bajty, checksum, warianty i powiązanie z przepisem.
   Osobno udowodnić odmowę DELETE tokenem kopiującym i późniejsze usunięcie
   kontrolnej kopii przez lifecycle. Bez operacji na cudzych zdjęciach.
4. Połączyć odzyskiwanie bazy i mediów: manifest kopii musi pozwalać dobrać
   właściwe pliki do odtwarzanego stanu bazy. Nie wystarczy odzyskać dwa
   niezależne, niezgodne czasowo zbiory.
5. Rozstrzygnąć i wdrożyć sposób zachowania aktualnej listy żądań usunięcia
   również po cofnięciu bazy. Stary backup tej listy jest niewystarczający:
   nie zna żądań złożonych po jego utworzeniu. Przy braku wiarygodnej listy
   odzyskane dane nie wracają automatycznie do publicznego produktu.

Po odbiorze nadal rekomenduję precyzyjny komunikat o pobraniu własnej kopii,
a nie absolutne „nie zginą”. Backup ma skończone okno, może być starszy od
ostatniej edycji i nie chroni przed wszystkimi zdarzeniami. D-114 wymaga
pokrycia obietnicy; nie daje prawa zamienić udanego ćwiczenia w gwarancję
wieczystego przechowywania.

## 2. Minimum offline: wykorzystać to, co już istnieje

### 2.1 Dwa znaczenia słowa „offline”

Aktualne [#30](https://github.com/woogitsu/kuking.pl/issues/30), odczytane
20 IX, mówi o **materiale drukowanym dla KGW/UTW** i decyzji o claimie;
wprost zabrania ponownego otwierania eksportu #2. Zlecenie tej sesji prosi
dodatkowo o projekt funkcji offline. Odpowiedź na oba potrzeby: materiał
uczący pobierania **istniejącego archiwum**, bez drugiego systemu eksportu.

**[pomiar własny]** Testy na nietkniętym kodzie wytworzyły prawdziwy ZIP,
sprawdziły strony HTML, zdjęcia, JSON, odnośniki względne i kontrolę dostępu.
Magazyny w testach były lokalnymi `Storage::fake()` — nie produkcyjnym R2.

| Element V1-minimum | Stan / projekt |
|---|---|
| Format | Istniejący ZIP: `index.html`, `przepisy/*.html`, `wpisy.html`, `zdjecia/*`, `dane.json`, `CZYTAJ-TO-NAJPIERW.txt`. HTML ma osadzone style; podstawowe czytanie nie wymaga serwera |
| Własne przepisy | Obecne treści, także szkice; składniki, kroki i dostępne własne zdjęcia. Nie obiecywać pełnej historii wszystkich wersji ani kopii skasowanych treści |
| Zeszyt | Cudze przepisy: tytuł, autor, własna notatka i data zapisania; **bez pełnych składników, kroków i cudzych zdjęć**. Zapisane dostępne wpisy mają tekst; wpisy niedostępne reprezentuje liczba. Nie reklamować tego jako całego Zeszytu do gotowania offline |
| Generowanie | Istniejące żądanie użytkownika → `data_exports` → kolejka database → `GenerateUserExport`. Nie nowe synchroniczne żądanie trwające do spakowania wszystkich zdjęć |
| Przechowywanie | Przejściowo istniejący dysk eksportów; domyślnie 7 dni według `config/kuking.php`. Pobrany ZIP zostaje na urządzeniu człowieka i nie wygasa z serwerowym linkiem |
| Internet | Potrzebny do zamówienia i pobrania. Po rozpakowaniu można czytać lokalne pliki. Nowe zmiany wymagają nowego pobrania |
| Zakres prywatności | Archiwum konta zawiera również dane osobiste; nie jest paczką do rozsyłania całemu KGW. Materiał uczy zachowania go dla siebie |

Źródła kodowe: `app/Jobs/GenerateUserExport.php`,
`app/Domain/Users/Exports/CollectUserExportData.php::collections()`,
`app/Domain/Users/Exports/ExportPhotoPlan.php`, `resources/views/exports/`.

**Dodatkowa granica [odczyt kodu, bez osobnej reprodukcji]:**
`GenerateUserExport::copyToTemp()` przy błędzie odczytu zdjęcia loguje
pominięcie i zwraca `null`; job może wydać paczkę mimo brakującego pliku.
Status `ready` nie jest więc dowodem kompletności mediów. Przed materiałem
obiecującym „wszystkie zdjęcia” potrzebny byłby osobny pomiar tego scenariusza
i decyzja o komunikowaniu braków; nie naprawiam eksportu przy okazji #30.

### 2.2 Co odpada i dlaczego

- Pełna aplikacja offline, cache prywatnych stron i synchronizacja zmian:
  konflikty edycji, wylogowanie, blokady i wspólne urządzenia tworzą nowy
  produkt. `public/sw.js` celowo przechowuje zasoby statyczne i ekran offline.
- Feed, nowe komentarze, publikacja i „Ugotowałem” bez sieci: wymagają
  aktualnych uprawnień i zapisu zdarzeń. Archiwalne własne komentarze w ZIP
  nie oznaczają działającej funkcji komentowania offline.
- Pełne cudze przepisy i ich zdjęcia: to rozszerzenie zakresu własności,
  zgód i konsekwencji późniejszego ukrycia. Wymaga osobnego zgłoszenia
  i decyzji, nie automatycznego skopiowania zawartości Zeszytu.
- Nowy serwerowy PDF, aplikacja mobilna, import ZIP do Kuking i automatyczne
  pobieranie w tle: zbędne do odczytu gotowego HTML. Wydruk z przeglądarki
  zostaje istniejącą drogą; nie dodajemy pakietu do generowania PDF.

### 2.3 Tabele, migracje, trasy i ekrany

**W rekomendowanym minimum: 0 nowych tabel, 0 migracji, 0 nowych tras,
0 wymaganych zmian ekranów aplikacji.** Nie tworzyć tabeli `offline_recipes`.

Istniejące źródła: `users`/`profiles`, `recipes`, `recipe_ingredients`,
`recipe_steps`, `media`, `collections`/`collection_items`, a dla pełnej
paczki także wpisy i wykonania wraz z powiązaniami. `recipe_versions`
pozostaje historią przepisu, nie backupem poza bazą. Obsługę wykonania
zapewniają istniejące `data_exports` i `jobs`.

| Ekran / plik | Minimum | Opcja późniejsza, poza #30 |
|---|---|---|
| `/ustawienia/twoje-dane`, `pages/settings/data.blade.php` | Korzystać z „Przygotuj paczkę z moimi danymi” i „Pobierz paczkę”; opisać je na karcie drukowanej | Korekta tylko jeśli badanie wykaże niezrozumiałą drogę |
| Zeszyt, `pages/collections/index.blade.php` i `show.blade.php` | Bez zmian i bez nowego przycisku sugerującego pełny eksport cudzych przepisów | Odnośnik do istniejących „Twoich danych” z jawną granicą zakresu; szacunek 4–8 h wraz z testami |
| `exports/index`, `recipe`, `readme` | Istniejące pliki do czytania/drukowania | Zmiany wyłącznie po pomiarze usterki lub decyzji produktowej |
| Landing / `/o-kuking` | Ten dokument nie zmienia copy ani głównego hasła | Właściciel zleca osobno usunięcie niepokrytych obietnic, jeśli zaakceptuje rekomendację |

Wycofanie minimum polega na wycofaniu materiału z dystrybucji; nie wymaga
rollbacku bazy i nie unieważnia paczek już pobranych przez użytkowników.

### 2.4 Projekt materiału KGW/UTW do zatwierdzenia

Jedna dwustronna karta A4: z przodu społeczność i możliwość zabrania własnych
treści, z tyłu instrukcja dla osoby pobierającej plik na komputerze.
Duży druk, krótka kolumna, numerowane kroki; adres tekstowy obok ewentualnego
QR, aby skanowanie nie było warunkiem. Skład ma stosować konstytucję marki
i gotowy znak, bez wymyślania nowej wersji logo. Na ekranie odpowiedniki
zachowują 18 px i cele 48 px; w druku sprawdzić czytelność przy skali 100%.

Proponowana treść argumentu, **bez hasła o niezniszczalności**:

> Gotujemy po swojemu.
>
> Pokaż, co dziś gotujesz, i poznaj inne kuchnie.
> Własne przepisy możesz też pobrać na komputer.
> Po pobraniu i rozpakowaniu paczki przeczytasz je bez internetu.

Proponowana instrukcja na odwrocie:

1. Zaloguj się i otwórz „Twoje dane”: `kuking.pl/ustawienia/twoje-dane`.
2. Wybierz „Przygotuj paczkę z moimi danymi”.
3. Gdy paczka będzie gotowa, wybierz „Pobierz paczkę” przed datą podaną obok.
4. Rozpakuj plik ZIP. Otwórz `index.html` w rozpakowanym folderze.
5. Otwórz wybrany przepis i sprawdź zdjęcia. Zachowaj cały folder razem.

Towarzyszący tekst granic:

> Paczka zawiera także prywatne dane konta — zachowaj ją dla siebie.
> Cudze przepisy zapisane w Zeszycie nie są w niej pełnymi przepisami.
> Zdjęcia skasowane lub odrzucone nie trafią do paczki.
> Po zmianie swoich przepisów pobierz nową paczkę.

To projekt treści i układu, **nie zatwierdzona ulotka ani gotowy plik do
drukarni**. W tej sesji nie badano otwierania ZIP/HTML na Androidzie/iPhonie;
nie rozszerzać instrukcji komputerowej na telefony bez próby. Nie ma
obietnicy powiadomienia przed zamknięciem portalu — to pozostaje w #8.

## 3. Domknięcie ochrony zdjęć: wariant i rzeczywisty koszt

### 3.1 Rekomendowany wariant do wykonania w #617

**[projekt]** Osobny prywatny bucket kopii, odseparowane poświadczenia,
bez dostępu aplikacji do kopii i bez uprawnienia procesu kopiującego do
edycji locków. Do ochrony przed przejęciem administratora potrzebne jest
również osobne konto/tożsamość administracyjna albo kopia u innego dostawcy.
Drugi bucket tego samego konta nie wystarcza na ten scenariusz.

Punkt wyjścia dla małego zbioru: **codzienny pełny snapshot pod nowymi
kluczami**, manifest obiektów, rozmiarów, checksum i powiązań; lock 30 dni,
lifecycle po 31 dniach, zgodnie z kierunkiem `LOKALIZACJA_DANYCH_R2.md` §6a.
Przed wyborem zmierzyć liczbę i łączny rozmiar mediów. Kopiować przetworzone,
dopuszczone pliki i potrzebne warianty, nie utrwalać tymczasowych surowych
uploadów z EXIF/GPS. Zakres historycznych oryginałów wymaga inwentaryzacji.

Zakończony snapshot musi mieć manifest opublikowany dopiero po weryfikacji
wszystkich plików. Brak obiektu i częściowy przebieg mają alarmować. Nie
stosować synchronizacji z propagacją usunięć. Przy kopiach i bazie z różnych
chwil trzeba jawnie raportować niespójności, nie ogłaszać kompletności.

**Pułapka projektu x2:** jednorazowe skopiowanie każdego pliku i lifecycle
31 dni usuwa także kopie wciąż aktywnych, starszych zdjęć. Nie zapewnia
stałego zabezpieczenia. Rotacja pełnych snapshotów rozwiązuje tę lukę kosztem
miejsca. Wariant przyrostowy wymaga wersjonowania kluczy, manifestów,
kontrolowanego odnawiania ochrony i usuwania tylko niepotrzebnych wersji;
nie można przypisać mu kosztu i prostoty zwykłego mirroru.

Retencja 30/31 dni jest propozycją do decyzji #8/#617, nie nową obietnicą
prawną. Nie nakładać locka na żywe zdjęcia, które usuwa aplikacja.
Odtworzenie musi respektować nowsze żądania usunięcia (§1.3), a kopiowanie
nie może odnawiać retencji danych, które już mają zniknąć.

### 3.2 Storage — obliczenie, nie faktura

**[szacunek]** `M` = GB wszystkich chronionych zdjęć i wariantów; `D` = GB
starych/usuniętych wersji przechowywanych dodatkowo. Nie zmierzono `M` ani `D`
na produkcji. Poniższe przykłady zakładają stały rozmiar przez miesiąc.

R2 Standard: 0,015 USD/GB-miesiąc; operacje A 4,50 USD/mln i B
0,36 USD/mln, egress bez opłaty R2. Darmowy próg to 10 GB-miesiąc,
1 mln A i 10 mln B, współdzielony z pozostałym użyciem rozliczanego konta.
Rozliczenie zaokrągla jednostki w górę. Źródło, sprawdzone 20 IX:
[cennik R2](https://developers.cloudflare.com/r2/pricing/).

| Ilość mediów `M` | Jedna dodatkowa kopia `+M` | Aktywne media + kopia `2M` | 31–32 pełne kopie dobowe: sam dodatkowy storage |
|---:|---:|---:|---:|
| 10 GB | 0,15 USD/mies. | 0,30 USD/mies. | 4,65–4,80 USD/mies. |
| 100 GB | 1,50 USD/mies. | 3,00 USD/mies. | 46,50–48,00 USD/mies. |
| 1 000 GB | 15,00 USD/mies. | 30,00 USD/mies. | 465–480 USD/mies. |

Tabela przed darmowym progiem, podatkami, operacjami i kosztem uruchamiania.
31–32 to budżetowy przykład działania lifecycle, **nie górna gwarancja**:
opóźnienie kasowania może zwiększyć zbiór. Chronione aktywne media pozostają
dodatkowo w podstawowym magazynie.

Wariant przyrostowy zużywa orientacyjnie `M + D + narzut rotacji` dodatkowo,
ale oszczędność trzeba zmierzyć. Kwota dla innego dostawcy wymaga jego
oferty, jurysdykcji i kosztu odczytu; nie przenoszę na niego cennika R2.

Przykład operacji: `N = 100 000` obiektów, 30 pełnych kopii w miesiącu,
po jednym odczycie i zapisie = około **3 mln A + 3 mln B**, przed listami,
HEAD, multipart, ponowieniami i próbami odtworzenia. Przy całym wolnym
progu R2 zostaje około **9 USD za A**, B mieści się w progu. To nie jest
koszt operacji dla miliona zdjęć ani koszt gwarantowany.

Transport i czas: przy przepływie 20 MB/s przesłanie 100 GB w jedną stronę
to około **83 minuty**; odczyt + zapis wykonywane kolejno około 167 minut,
bez kosztu małych plików. To rachunek przy założonej przepustowości, nie
pomiar R2. Kopiowanie po stronie magazynu może ograniczyć transfer przez
wykonawcę; wymaga sprawdzenia dla wybranych kont i uprawnień. Jeśli bajty
przechodzą przez Railway, doliczyć jego egress według aktualnej taryfy,
czas CPU/RAM i ewentualny dysk roboczy — darmowy egress R2 tego nie znosi.

Kopie bazy rozliczać osobno: przy dziennym dumpie `B` GB i 30 zachowanych
kopii sam storage to orientacyjnie `30 × B × 0,015 USD/mies.`. To nie twardy
limit: kod zachowuje minimum 7 potwierdzonych kopii także przy ich większym
wieku. Potrzebne są ponadto obliczenia, transfer i nadzór.

### 3.3 Nakład pracy

Szacunki jednej osoby w godzinach pracy, bez wyceny prawnej, oczekiwania na
dostępy i usług drukarni; **nie pomiar ani deklarowany termin dostarczenia**.

| Pozycja | Nakład | Co jest wynikiem |
|---|---:|---|
| Nowy system eksportu w minimum | **0 h** | Nie powstaje; wykorzystujemy istniejący |
| Redakcja, skład i korekta karty KGW/UTW | 8–16 h | Materiał do zatwierdzenia i późniejszego druku |
| Próby pobrania, rozpakowania i wydruku z 3–5 osobami, poprawki instrukcji | 8–16 h | Dowód zrozumiałości, osobno komputer i ewentualne telefony |
| Odbiór gotowego mechanizmu kopii DB, konfiguracja i pełny restore w #193/#594 | 8–16 h | Dowód działania produkcyjnej ścieżki, nie tylko zielony test |
| Inwentaryzacja mediów, snapshoty, manifest, uprawnienia, alarm, próby delete/restore w #617 | 24–48 h | Ochrona zdjęć i procedura odzyskania |
| Wariant przyrostowy zamiast pełnych snapshotów | dodatkowe 16–32 h | Pomiar oszczędności i testy rotacji/odzyskiwania |

Minimum redakcyjne z badaniem: **16–32 h**. Domknięcie technicznej ochrony
DB i mediów: **32–64 h**, bez rozstrzygnięcia retencji i procesu respektowania
nowszych żądań usunięcia. Razem **48–96 h** prac, jeśli właściciel wybierze
oba strumienie i prostą strategię snapshotów. To nie znaczy, że do tej pory
wolno opublikować claim. Potwierdzenie wygasania przy 31 dniach wymaga też
czasu kalendarzowego; krótki test reguły próbnej nie dowodzi skutku reguły
produkcyjnej po miesiącu.

Sam eksport ma koszt już dziś: dla `E` paczek miesięcznie o średnim rozmiarze
`Z` GB, trzymanych 7 dni, storage wynosi orientacyjnie `E × Z × 7/30` GB-mies.
Przykład: 100 paczek po 0,2 GB → 4,67 GB-mies., przed zaokrągleniem i progiem.
Oddzielnie kosztuje odczyt zdjęć, generowanie ZIP i miejsce tymczasowe.

## 4. Co to zabiera

- **Stała odpowiedzialność operacyjna:** właściciel procesu i zastępca,
  przechowywanie kluczy, odbiór alarmów i aktualizowanie instrukcji.
  Propozycja: cotygodniowy przegląd 15–30 min, comiesięczne ćwiczenie
  DB + kontrolne media 1–2 h, kwartalna szersza próba 2–4 h. Orientacyjnie
  3–6 h/mies. bez incydentów; to koszt pracy, nie samego storage.
- **Regularne kontrole:** ciągły nadzór świeżości i kompletności kopii,
  ponowne ćwiczenie po zmianie obrazu PG, szyfrowania, tokenów, bucketów
  lub pipeline zdjęć. Wynik zapisany z datą, zakresem, RPO/RTO i brakami.
- **Nowe obowiązki prywatności:** izolacja kopii, koniec retencji,
  niedopuszczenie do ponownej publikacji usuniętych danych. Rozstrzygnięcie
  należy do #8; dokument nie ustanawia podstawy prawnej przechowywania.
- **Wsparcie człowieka:** gdzie znaleźć ZIP, jak go rozpakować, dlaczego
  link wygasł, dlaczego archiwum nie ma pełnych cudzych przepisów, jak
  zachować prywatność na wspólnym komputerze.
- **Budżet i czas:** kopie historyczne, transfer, pełny restore, testy
  integralności, przechowywanie odzyskanego środowiska i jego sprzątanie.
- **Ryzyko reputacyjne:** hasło bez granic przypisuje serwisowi
  odpowiedzialność także za dane jeszcze niezapisane, celowo usunięte,
  zdjęcia brakujące przed eksportem i jedyny ZIP zgubiony przez człowieka.
  Awaria po takim haśle podważa wiarygodność całej społeczności.

## 5. Otwarte decyzje właściciela

| Decyzja | Rekomendacja | Alternatywa i koszt |
|---|---|---|
| Dodatkowy claim | Odrzucić „Twoje przepisy nie zginą”; używać zdania o możliwości pobrania własnych przepisów | Odłożyć materiał, jeśli ma zależeć wyłącznie od tego hasła; nie uznawać ryzyka za usunięte przez akceptację copy |
| Materiał KGW/UTW teraz? | Karta ucząca istniejącego eksportu po próbie z odbiorcami | Świadomie odłożyć/odrzucić; zapisać to przy odbiorze #30 |
| Model ochrony zdjęć | Pomiar `M/N` → osobna kopia z historią; pełne snapshoty tylko jeśli zmieszczą się w budżecie | Przyrostowe: więcej pracy i testów; brak kopii: jawnie przyjęte ryzyko, bez hasła o trwałości |
| Zakres awarii | Rozdzielić token aplikacji od administracji kopii; zdecydować, czy objąć także utratę konta Cloudflare | Osobne konto/dostawca zwiększa niezależność, lecz wymaga dodatkowej administracji i wyceny |
| Utrata najnowszych zmian | Ustalić docelowy RPO i RTO; dobowy harmonogram nie oznacza jeszcze zmierzonego RPO ≤24 h | Krótszy odstęp i PITR mogą zmniejszyć straty kosztem konfiguracji, transferu i obsługi |
| Retencja i żądania usunięcia | #8 + #617: określić okno, rzeczywisty koniec retencji i niezależny od cofanej bazy rejestr wyłączeń | Nie uruchamiać nowej retencji z domyślnego założenia autora projektu |
| Pełne cudze przepisy w Zeszycie offline | Nie w V1-minimum ani #30 | Osobna decyzja o zakresie i osobne zgłoszenie; nie rozstrzygać testem |
| Właściciel ćwiczeń i budżetu | Wyznaczyć konkretną osobę, zastępcę i miesięczny limit kosztu | Bez tego procedura nie ma odpowiedzialnego wykonawcy |

Po decyzji wpisać zatwierdzone rozstrzygnięcie do `docs/DECISIONS.md`.
Ten dokument **nie zamyka #30**: nie ma jeszcze zatwierdzonego materiału
ani decyzji właściciela. Rozstrzygnięcie procedury zamknięcia serwisu
pozostaje w #8, zgodnie z treścią issue.

## 6. Co zmierzono samodzielnie i czego nie wykonano

**Własny pomiar przed edycją dokumentu:**

- Czyste drzewo i SHA wskazane w §1.
- WSL, PostgreSQL **18.6**, `127.0.0.1:55439`, właściciel bazy `kuking`,
  baza `kuking_flota_gpt-offline-obietnica`.
- `php artisan test` przez wspólny `testuj.sh`, filtr:
  `DataExportTest|EksportWygladObietnicePaczkiTest|EksportMowiOZdjeciach|EksportDrukPodzialStronTest|EksportNieObiecujeTerminuTest|ServiceWorkerOdswiezaMarkeTest|KazdaMigracjaMaWycofanieTest`
  — **56 testów, 634 asercje, wszystkie zaliczone**, 5,66 s.
- `bash tests/skrypty/kopia-bazy.sh` — **167 sprawdzeń zaliczonych**,
  także odrzucenie uszkodzonego/obciętego archiwum i niebezpiecznej retencji.
- `vendor/bin/pint` — **PASS, 1155 plików** w izolowanym runtime.
- Odczyty Railway `get_status` i `get_service_config` oraz odczyt treści
  issues #30, #193, #617. Nie pobierano wartości sekretów.

**Reprodukcja:** przygotować runtime wspólnym `przygotuj-runtime.sh
gpt-offline-obietnica`, następnie uruchomić filtr przez `testuj.sh`.
Przy Git Bash zachować `MSYS_NO_PATHCONV=1`; filtr z `|` przekazać jako
jeden argument. Test skryptu kopii i Pint uruchamiano z runtime
`/home/mateusz/flota/gpt-offline-obietnica-run`, z PHP 8.4 i klientem PG 18.

**Jawne pominięcia:**

- `ProbaOdtworzeniaTest` oraz `tests/skrypty/proba-odtworzenia.sh`:
  własny odczyt funkcji wyliczającej nazwę dał pusty sufiks w runtime;
  skrypt zastępuje go `_glowny`, więc użyłby wspólnej
  `kuking_zrodlo_proby_glowny`. Zgodnie ze zleceniem pominięty, bez
  uruchamiania jego DROP/CREATE i bez ingerencji w cudze stanowiska.
- Nie wykonano pełnego zestawu aplikacji, całego wahadła migracji,
  budowy obrazu ani nowego pełnego dump/restore. Zadanie zmienia tylko
  dokument; historyczne próby przytoczono jawnie jako cudze pomiary.
- Nie przeprowadzono produkcyjnego restore, inspekcji panelu R2,
  destrukcyjnych prób mediów, włączenia backupów ani zmian retencji.
- Nie zmieniono schematu, tras, modeli, widoków, infrastruktury ani
  dziennika decyzji. Nie dodano testu betonującego wybór produktu.
- Nie wysłano wiadomości, komentarza w issue, pushu ani PR-a.

**Granica odbioru tego zadania:** gotowy projekt z kosztami i dowodami,
a nie działająca nowa ochrona danych czy zatwierdzona obietnica marki.

### Przekazanie do lokalnego commita

W trakcie zadania wskazany worktree utracił połączenie z metadanymi Gita:
`git status` i `git rev-parse HEAD` zwracają `fatal: not a git repository:
(NULL)`. Na początku sesji oba działały i potwierdziły gałąź oraz SHA z §1.
Właściciel poinformował, że zajmuje się tym inny model; nie naprawiano
rejestracji ani repozytorium kanonicznego.

**SHA nowego commita: brak — blokada repozytorium.** Gotowy plik to
`docs/product/MATERIALY_OFFLINE_OBIETNICA_30.md`. Po przywróceniu właściwego
repozytorium należy sprawdzić gałąź i zakres zmian, a następnie zacommitować
wyłącznie ten dokument, np. „Oceń pokrycie obietnicy i koszt materiałów offline”.
Nie pushować; przekazać commit kolejce floty. Nie udało się wykonać końcowego
`git diff` z powodu tej samej blokady. Jedyną edycją w worktree wykonaną
przez autora tego audytu było dodanie i uzupełnienie niniejszego dokumentu.
