# Audyt skalowalności — czy obecny stack wytrzyma tysiące użytkowników

**Data:** 16 września 2026.
**Gałąź bazowa:** `main` w commicie `1c557aa`.
**Zlecenie właściciela:** sprawdzić, czy dzisiejsze technologie udźwigną wzrost
do kilkuset, a docelowo kilku tysięcy użytkowników online — **żeby zmiany zrobić
teraz, a nie wtedy, gdy portal urośnie i zacznie działać wolno**. Właściciel
jawnie dopuścił zmianę hostingu, jeśli obecny okaże się nieodpowiedni.

**Metoda:** cztery równoległe agenty, po jednym na wymiar (baza danych, warstwa
aplikacji, infrastruktura i hosting, pipeline zdjęć), plus weryfikacja
najważniejszych twierdzeń w źródle przez prowadzącego audyt.

---

## Czego ten dokument NIE jest

**To nie jest pomiar produkcji.** Nie ma tu ani jednej liczby zdjętej
z działającego serwisu. Wartości req/s w §3 to szacunki z arytmetyki: rozmiar
feedu × tempo przewijania × liczba użytkowników.

**Istnieje natomiast pomiar lokalny** — `docs/infra/evidence/load581/` z 15
września, wykonany rzetelnie i z jawnie nazwanymi ograniczeniami. Sekcja §2a
opiera się na nim i jest jedyną częścią tego dokumentu popartą liczbami
z przyrządu. Tam, gdzie liczba pochodzi z wcześniejszego pomiaru zapisanego
w repozytorium (RSS przy przetwarzaniu zdjęcia), jest to powiedziane wprost.

**Trzy rzeczy, których nie dało się sprawdzić w tym kontenerze** i które
są tu oznaczone jako niepotwierdzone, a nie jako fakty:

1. `vendor/laravel/framework` jest pusty, więc **nie zweryfikowano, czy sterownik
   kolejki `database` używa `SKIP LOCKED`**. Ma to znaczenie dopiero przy drugim
   workerze (§4.2).
2. Ceny i limity Railway pochodzą z komentarzy w `.railway/railway.ts` i
   `docs/infra/INFRA_DECISION.md`, **nie ze sprawdzenia panelu Railway dzisiaj**.
   Przed decyzją kosztową trzeba je potwierdzić u źródła.
3. Konfiguracji Cloudflare **nie da się odczytać z repozytorium**. Wniosek
   o braku cache dla `/zdjecia/*` opiera się na tym, że `INFRA_DECISION.md`
   wymienia Cache Rules i tej ścieżki wśród nich nie ma — to mocna przesłanka,
   ale panel trzeba obejrzeć.

---

## Streszczenie na jedną stronę

**Kod jest gotowy na skalę. Infrastruktura nie jest — i to nie z winy Railway,
tylko dlatego, że konfiguracja infrastruktury nigdy nie została wdrożona.**

Warstwa aplikacyjna została zaprojektowana z wyraźną świadomością skali:
paginacja kursorowa zamiast OFFSET, indeksy częściowe, feed liczony przy
odczycie bez fan-outu, powiadomienie o „ugotowałem" jako pojedynczy INSERT bez
rozsyłania do obserwujących, ciężkie operacje w kolejce, liczniki przeliczane
komendą zamiast `Cache::remember()` na żądanie. To są decyzje, które zwykle
trzeba wprowadzać po fakcie, pod presją. Tutaj już są.

Wąskie gardła leżą gdzie indziej i są trzy:

| # | Problem | Kiedy zaboli |
|---|---|---|
| 1 | **Nie ma żadnej kopii bazy danych** | już dziś — to nie jest problem skali |
| 2 | **Produkcja to jeden kontener, jedna replika**, web + worker + scheduler razem | setki online |
| 3 | **Każde zdjęcie w serwisie przechodzi przez PHP** i nie jest cache'owane na brzegu | setki online |

Pierwszy punkt nie ma nic wspólnego ze skalowaniem i jest ważniejszy niż cała
reszta tego dokumentu razem wzięta.

**Rekomendacja hostingowa w jednym zdaniu:** zostajemy na Railway, bo problemem
nie jest Railway, tylko to, że nigdy nie uruchomiono na nim zaplanowanej
konfiguracji — a obraz Docker jest przenośny, więc decyzję o zmianie dostawcy
można bez kosztu odłożyć do momentu, gdy będą liczby z produkcji.

---

## 1. Rzecz najpilniejsza, niezwiązana ze skalą: nie ma kopii bazy

`docs/infra/KOPIE_I_ODTWORZENIE.md:981` mówi wprost: **„Nikt nigdy nie zrobił
ręcznego zrzutu — tabela w §5 jest pusta."**

Stan faktyczny:

* Serwis `kopia-bazy` jest w pełni opisany w IaC (`.railway/railway.ts:955`),
  razem z własnym obrazem (`docker/kopia/Dockerfile`) i osobnym tokenem R2
  z prawem zapisu. Kod jest gotowy.
* Ale `railway config apply` nigdy nie zostało uruchomione (§2), więc **ten
  serwis nie istnieje w Railway**.
* Plan poniżej Pro nie ma Volume Backups ani PITR.
* Czujka `kuking:sprawdz-kopie` jest wyłączona, bo nie ma czego sprawdzać.
* Ćwiczenie odtworzenia nigdy nie zostało wykonane.

Wniosek: **dziś awaria bazy oznacza utratę wszystkiego.** Wszystkich kont,
wszystkich przepisów, wszystkich zdjęć powiązanych z rekordami. Zdjęcia
przetrwają w R2, ale bez bazy nikt nie wie, czyje są ani do czego należą.

To jest P0 w sensie dosłownym: **przed każdą inną pozycją w tym dokumencie**.
Kopia, której nigdy nie odtworzono, nie jest kopią — dlatego pierwszym krokiem
jest ręczny `pg_dump` do bucketa i jednorazowe odtworzenie go do osobnej bazy,
a dopiero potem automat.

---

## 2. Produkcja to jeden kontener — i to jest sufit przepustowości

`.railway/railway.ts:120` ustawia `PRODUCTION_SPLIT_SERVICES = true`, ale
komentarz nad tą flagą zawiera sprostowanie zmierzone 9 września: **`railway
config apply` nie zostało uruchomione ani razu**. Produkcja to jeden serwis
`kuking.pl`, startowany komendą `kuking-entrypoint all` — czyli tryb, w którym
serwer WWW, worker kolejki i scheduler dzielą jeden kontener, jeden procesor
i jeden budżet pamięci.

Skutki przy wzroście:

* **Twardy sufit przepustowości.** Jedna replika, FrankenPHP w trybie
  klasycznym (bez Octane, świadomie — patrz `Dockerfile`), czyli pełny bootstrap
  Laravela na każde żądanie. Realistycznie niskie setki req/s dla stron
  dotykających bazy. Dodanie drugiej repliki dziś **nie jest bezpieczne**,
  bo zduplikowałoby scheduler (digest wysłany dwa razy) i workera.
* **Upload zdjęcia spowalnia stronę wszystkim.** Przetwarzanie obrazu to
  zmierzone 161–452 MB RSS i 1,3–4,6 s czasu procesora
  (`docs/MEDIA_PIPELINE.md`). Ten koszt konkuruje o ten sam procesor, co
  obsługa feedu — a zdjęcia są rdzeniem produktu, więc to nie jest przypadek
  brzegowy, tylko główna ścieżka.
* **Jedna awaria zabiera wszystko.** To się już wydarzyło: 5–6 września,
  3,5 godziny niedostępności, gdy planowe zakończenie `queue:work` zamknęło
  cały kontener razem z serwerem WWW. Sam entrypoint został naprawiony,
  ale architektura jednokontenerowa została.
* **Deploy przerywa przetwarzanie kolejki**, bo restartuje wszystko naraz.

**Co zrobić:** wykonać `railway config plan` → `apply` najpierw na staging,
potem na produkcji, rozbijając na `web` / `worker` / `scheduler`. Uwaga
praktyczna: nazwy serwisów w IaC nie odpowiadają dziś niczemu istniejącemu
w Railway (produkcyjny serwis nazywa się `kuking.pl`, nie `web`), więc pierwszy
`apply` wymaga uwagi — `plan` pokaże, co dokładnie zostanie utworzone i usunięte.

---

## 2a. Co mówi istniejący pomiar k6 (i czego nie mówi)

`docs/infra/evidence/load581/RAPORT.md` — baseline z 15 września, wykonany na
obrazie produkcyjnym (FrankenPHP, tryb klasyczny, OPcache, cache konfiguracji
z produkcyjnego entrypointu), przy **twardym limicie 2 CPU / 2 GiB** dla web,
czyli w przybliżeniu na budżecie produkcyjnym.

| Seria | Zadane żądania/s | p95 | Szczyt CPU web |
|---|---:|---:|---:|
| Publiczne strony + obraz | 20 | 33 ms | 28,5% jednego CPU |
| Zalogowany feed, zeszyt, gotowanie | 20 | 52 ms | 42,5% jednego CPU |
| Wyszukiwanie (pg_trgm) | 5 | 54 ms | 14,0% jednego CPU |

Maksymalnie **2 połączenia** do bazy, zero oczekujących na lock, zero
deadlocków, zero 429, zero zaległości w kolejce.

**Co z tego wynika.** Najcięższa mieszanka zużyła przy 20 żądaniach/s około
42% jednego rdzenia z dostępnych dwóch. Ekstrapolując liniowo — a więc
optymistycznie, bo nasycenie nie jest liniowe — daje to rząd **90–100 żądań
użytkowych na sekundę** na jednej replice, zanim procesor stanie się
ograniczeniem. Raport sam podkreśla, że **nie doprowadzono hosta do
nasycenia**, więc jest to oszacowanie sufitu, nie zmierzony sufit.

Potwierdza to jednak rząd wielkości przyjęty w §2 („niskie setki req/s") i —
co ważniejsze — **wyklucza framework jako winowajcę**. Przy p95 rzędu 30–50 ms
i 42% jednego rdzenia nic tu nie wskazuje, że Laravel czy Blade są
ograniczeniem. Raport stawia to wprost: „Nie znaleziono w tym zakresie dowodu,
że framework jest ograniczeniem wymagającym przepisania."

**Czego ten pomiar nie obejmuje** — i to jest istotne, bo dotyczy dokładnie
tych miejsc, które ten audyt uznaje za wąskie gardła:

* **Obraz testowy ważył 2356 bajtów** (syntetyczny WebP 960×640). Prawdziwe
  zdjęcie z telefonu po obróbce to ~177 KB w wariancie feedowym — dwa rzędy
  wielkości więcej. Pomiar mierzył koszt *decyzji Policy i przekierowania*,
  nie transferu.
* **Nie dotykał R2 ani CDN** — dyski były lokalne. Prawdziwy `temporaryUrl()`
  i round-trip do Cloudflare nie zostały zmierzone.
* **Zbiór danych był mały** (1000 wpisów, 300 przepisów). Zapytania feedu
  zachowują się inaczej przy milionach wierszy — tu właśnie ujawniłyby się
  `whereIn` z setkami ID i `withCount` (§5).
* **Nie było mieszanki jednoczesnej** — uploady i czytanie mierzono osobno,
  a to właśnie ich współbieżność jest problemem trybu `all` (§2).
* Mierzono bez opóźnień sieciowych, na 24-rdzeniowym hoście z 31 GiB RAM.

Wniosek praktyczny: **kolejny pomiar powinien mieć realne rozmiary zdjęć,
większy zbiór danych i jednoczesne uploady z czytaniem** — bo dziś zmierzono
głównie tę część systemu, która i tak jest zdrowa.

---

## 3. Każde zdjęcie przechodzi przez PHP — największy mnożnik ruchu

To jest znalezisko o największym wpływie na liczbę żądań, i wynika wprost
z decyzji, która sama w sobie była słuszna.

**Jak to działa dziś.** Adresem zdjęcia jest trasa aplikacji
`/zdjecia/{id}/{wariant}` (`app/Http/Controllers/MediaController.php`). Trasa
sprawdza Policy treści nadrzędnej i przekierowuje (302) na podpisany adres R2.
Zrobiono tak celowo i dobrze: wcześniej adresem był publiczny `cdn.kuking.pl`,
który nikogo o nic nie pytał — kto raz skopiował adres, oglądał zdjęcie także
po zablokowaniu i po przełączeniu przepisu na prywatny. **Prywatności nie wolno
tu cofnąć.** Problemem jest nie sama trasa, tylko to, że nic jej nie odciąża.

**Rachunek.** Podpis żyje 5 minut (`config/kuking.php:275`), a przekierowanie
ma `max-age` równy połowie tego okna, czyli 150 s
(`MediaController.php:163`, uzasadnienie w komentarzu jest poprawne — cache
przekierowania musi być krótszy niż ważność podpisu, inaczej ludzie widzą puste
ramki). Każde zdjęcie to około 6 zapytań SQL po optymalizacji z issue #286.

Przy 1000 użytkowników przeglądających feed, po ~15 zdjęć na ekran, odświeżany
co kilka sekund, mówimy o rzędzie **tysiąca i więcej żądań na sekundę wyłącznie
na zdjęcia** — na tej samej, jednej replice, która obsługuje też HTML i
przetwarza uploady. To przekracza jej możliwości grubo wcześniej niż przy
1000 użytkowników.

**Kluczowe ustalenie:** aplikacja **wysyła poprawny nagłówek `Cache-Control:
public, max-age=150`** — inżyniersko jest to zrobione dobrze. Ale
`INFRA_DECISION.md` pokazuje, że reguła cache Cloudflare dla zdjęć została
wycofana (D-020, bo omijała Policy) i **nie zastąpiono jej regułą dla ścieżki
`/zdjecia/*`**. Nagłówek jest więc przygotowany i nikt go nie konsumuje.
To luka między aplikacją a konfiguracją brzegu, nie błąd w kodzie.

**Co zrobić, po kolei i od najtańszego:**

1. **Cache Rule w Cloudflare dla `/zdjecia/*`**, respektująca `Cache-Control`
   z originu. Zero zmian w kodzie. Do sprawdzenia w panelu: czy Cloudflare
   cache'uje odpowiedzi 302 bez rozszerzenia pliku i jak zachowuje się przy
   ciasteczku sesji — treść prywatna nie może trafić do wspólnego cache.
2. **Wydłużyć TTL podpisu dla treści jawnie publicznej.** Pięć minut to bardzo
   ostrożna wartość; dla zdjęcia w publicznym przepisie godzina nie zmienia
   niczego w modelu zagrożeń, a zmniejsza liczbę żądań ponad dziesięciokrotnie.
   Treść nieopublikowana zostaje przy krótkim oknie.
3. **Cache decyzji Policy między żądaniami** (krok 3 z issue #286, świadomie
   odłożony) — dopiero gdy 1 i 2 nie wystarczą.

Punkty 1 i 2 razem zdejmują większość tego ruchu bez dotykania modelu
prywatności i bez ani jednej nowej usługi.

---

## 4. Baza danych

### 4.1. Schemat jest w dobrym stanie

Nie znaleziono brakujących indeksów na ścieżkach krytycznych. Przeciwnie —
schemat używa indeksów częściowych tam, gdzie mają sens
(`posts_published_idx` z `WHERE deleted_at IS NULL`, `notifications_user_unread_idx`
z `WHERE read_at IS NULL`), a wszystkie feedy używają `cursorPaginate()`
zamiast OFFSET. To ostatnie jest ważniejsze, niż się wydaje: paginacja OFFSET
degraduje się liniowo z głębokością strony i jest klasyczną przyczyną „portal
zwolnił, gdy przybyło treści".

### 4.2. Realne ryzyko: połączenia, nie zapytania

`config/database.php` nie ma poolingu ani połączeń trwałych, a FrankenPHP
działa w trybie klasycznym — więc **liczba równoczesnych połączeń do Postgresa
równa się liczbie równocześnie przetwarzanych żądań HTTP**. Ani
`INFRA_DECISION.md`, ani `docs/DATABASE.md` nie mówią, ile wynosi
`max_connections` na tej instancji.

To jest luka do zamknięcia teraz, ale dokumentacyjnie, nie wdrożeniowo:
**druga replika `web` podwaja zużycie puli połączeń bez żadnej zmiany w kodzie**,
więc limit trzeba znać, zanim się ją doda. PgBouncer w trybie transaction
należy wdrożyć razem z drugą repliką, nie wcześniej.

Ostrzeżenie: różnica między „500 użytkowników online" a „500 równoczesnych
żądań" jest ogromna. Wyczerpanie puli połączeń przyjdzie nie od stałego ruchu,
tylko od skoku — wysyłki maila do wszystkich albo popularnego wpisu.

### 4.3. Sesje, cache i kolejka w Postgresie

Decyzja „wszystko w Postgresie, zero Redisa" była słuszna na start i ma realną
zaletę, o której warto pamiętać: sesje są współdzielone między replikami od
pierwszego dnia, więc skalowanie poziome jest bezpieczne.

Koszt ujawnia się dopiero przy skali. Sesja jest zapisywana **przy każdym
żądaniu** zalogowanego użytkownika — przy 5000 online to rząd 350–500 UPDATE/s
na tabeli `sessions`, bez żadnej wartości biznesowej. Cache w bazie oznacza,
że odciążamy bazę przy pomocy bazy.

To jest P1 „gdy urośnie", nie „teraz". Próg: około 1000–1500 jednoczesnych
sesji. Wtedy warto wrócić do decyzji o Redisie — jako świadoma rewizja zasady
z `AGENTS.md`, poparta pomiarem, a nie jako zmiana przy okazji.

Podobnie kolejka: dziś jeden proces `queue:work` (`docker/entrypoint.sh:363`)
obsługuje wszystkie kolejki szeregowo, więc rywalizacji o wiersze nie ma.
Pojawi się dopiero przy drugim workerze — i **wtedy trzeba sprawdzić, czy
Laravel 13 używa `SKIP LOCKED` dla sterownika `database`**, czego w tym
kontenerze nie dało się zweryfikować (pusty `vendor/`).

---

## 5. Warstwa aplikacji — dwa sprostowania

Audyt aplikacyjny przyniósł dwa ustalenia, które korygują obraz stacku:

**Livewire nie jest główną technologią interakcji tego portalu**, mimo że
`CLAUDE.md` i `AGENTS.md` tak go przedstawiają. Katalog `app/Livewire/` nie
istnieje, `wire:poll` nie występuje w repozytorium ani razu, a jedyne realne
użycie to kreator przepisu (`resources/views/components/recipe-wizard.blade.php`)
— ekran o niskim ruchu. Feed, „ugotowałem", komentarze i wyszukiwarka to zwykłe
kontrolery Blade z JS jako ulepszeniem.

Dla skalowania to **dobra wiadomość**: nie ma tu kosztu, którego się obawiano.
Jest natomiast ryzyko dokumentacyjne — ktoś nowy (albo model) przeczyta
`CLAUDE.md`, uzna, że hot path stoi na Livewire, i dopisze `wire:poll` do
feedu. Przy tysiącach online byłoby to kosztowne. Warto sprostować opis stacku.

**Rate limiting istnieje**, wbrew sugestii z raportu aplikacyjnego, że go brak:
`routes/web.php` zawiera 97 wystąpień `throttle`. Zweryfikowane bezpośrednio.

Pozostałe znaleziska aplikacyjne są niskiego priorytetu: `FollowingFeed`
materializuje listę obserwowanych przez `pluck()` i wstawia ją jako `IN (...)`
— przy 500+ obserwowanych i milionach wpisów warto to zamienić na `JOIN`
do `follows`, ale dopiero po pomiarze. Liczniki komentarzy liczone
`withCount()` zamiast kolumny zdenormalizowanej zaczną boleć tylko na
pojedynczych bardzo popularnych wpisach.

---

## 6. Pipeline zdjęć poza trasą serwowania

* **Czterokrotne dekodowanie oryginału** przy generowaniu wariantów
  (`ProcessUploadedImage.php:146` czyta oryginał w pętli). Dekodowanie jest
  najdroższą częścią operacji — jedno odczytanie i skalowanie w dół obcięłoby
  koszt procesora zauważalnie. P1, znane już wewnętrznie jako PERF3.
* **Upload idzie przez proces PHP do R2**, nie bezpośrednio z przeglądarki
  przez podpisany POST (`StoreUploadedImage.php`). Przy słabym LTE jeden
  uploadujący użytkownik zajmuje proces PHP na cały czas transferu. Przy
  jednym kontenerze to bezpośrednio zmniejsza liczbę żądań, które da się
  obsłużyć równolegle. P1, znane jako PERF2.
* **Ustalenie pozytywne:** tymczasowe pliki uploadu Livewire trafiają do R2,
  nie na ulotny dysk kontenera, więc druga replika odczyta je poprawnie.
  Wynika to jednak z domyślnego fallbacku zmiennej środowiskowej, a nie
  z jawnej konfiguracji — warto to przypiąć testem, bo cichy regres
  (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local`) nie zostałby złapany.
* **Koszt R2 nie jest ryzykiem.** Brak opłat za egress to tu kluczowa
  przewaga; przy 1000 zdjęć dziennie mówimy o groszach miesięcznie za storage.
  Ryzykiem jest procesor aplikacji, nie rachunek za dane.
* **Lazy loading i `srcset` są zrobione dobrze** — `srcset` budowany
  z rzeczywistych szerokości wariantów, `loading="lazy"` domyślnie,
  `fetchpriority="high"` tylko dla priorytetowego obrazka. Bez zastrzeżeń.
* Drobiazg: dokumentacja mówi „WebP/AVIF", kod koduje wyłącznie WebP. Do
  sprostowania w dokumentacji — AVIF przy obecnym koszcie procesora nie jest
  tego wart.

---

## 7. Hosting — czy zmieniać

Właściciel dopuścił zmianę hostingu. Uczciwa odpowiedź brzmi: **jeszcze nie ma
podstaw, żeby ją zrobić, i jest to dobra wiadomość.**

Powód jest konkretny. Railway nie został dziś wyczerpany — on nie został
**włączony**. Produkcja działa w konfiguracji przewidzianej dla fazy alfa
(~50 użytkowników), mimo że plan rozbicia na trzy serwisy istnieje w kodzie
od dawna. Zmiana dostawcy przed wykorzystaniem tego, co już jest opłacone
i zaprojektowane, to przeniesienie nierozwiązanego problemu w nowe miejsce.

Druga rzecz działa na korzyść: **decyzja jest tania do odwrócenia**. Własny
`Dockerfile` zamiast Railpacka (świadoma decyzja, uzasadniona w komentarzu
na górze pliku), cały stan w Postgresie i R2, zero trwałych danych na dysku
kontenera. Ten sam obraz uruchomi się na Fly.io, Hetznerze czy zwykłym VPS
bez zmiany linijki kodu. Nie trzeba więc wybierać hostingu „na wyrost".

### Gdzie Railway ma realne granice

Dwie, obie warte zapamiętania:

* **Brak autoskalowania poziomego.** `numReplicas` to liczba w pliku.
  Skalowanie w górę jest ręczne. Jedyne „auto" to Serverless, działający
  w dół (scale-to-zero), nie w górę. Przy nagłym skoku ruchu nikt nie doda
  repliki za nas.
* **Postgres bez wysokiej dostępności i bez replik do odczytu** w obecnej
  konfiguracji. Przy tysiącach użytkowników pojedyncza instancja bez failoveru
  przestaje być akceptowalna.

  **Do zweryfikowania przed decyzją:** pojawiła się informacja, że Railway
  dokumentuje dziś możliwość przekształcenia Postgresa w klaster wysokiej
  dostępności z automatycznym przełączeniem po awarii. Nie sprawdzono tego
  w panelu ani w aktualnej dokumentacji Railway. Jeśli to prawda, **zmienia
  to plan z §7**: trzeci etap (wyprowadzenie bazy do zewnętrznego dostawcy)
  mógłby się okazać zbędny, a zostałby wyłącznie brak replik do odczytu.
  To jedno sprawdzenie w panelu warte jest więcej niż cała reszta tej sekcji.

Pierwsza granica jest do przeżycia (ruch rośnie tygodniami, nie minutami).
Druga wyznacza moment, w którym trzeba działać — i dotyczy bazy, nie aplikacji.

### Plan w trzech krokach

| Etap | Co robimy | Próg wyzwalający |
|---|---|---|
| **Teraz** | Kopie bazy + `railway config apply` (rozbicie serwisów) + Cache Rule dla `/zdjecia/*`. Zostajemy na Railway. | — |
| **~500–1000 online** | Plan Pro (odblokowuje SMTP i backupy wolumenów), 2+ repliki `web`, PgBouncer, osobny worker dla kolejki `media`, rewizja sesji/cache. | kolejka `jobs` z zaległościami >100, rosnące p95 w godzinach uploadów |
| **~1000–2000 online** | **Postgres wyprowadzamy do zarządzanego dostawcy z HA i replikami do odczytu** (Neon / Crunchy / DigitalOcean). Aplikacja zostaje, gdzie jest — zmienia się `DB_URL`. | pojedyncza baza bez failoveru przestaje być akceptowalnym ryzykiem |

Powyżej tego progu sensowną alternatywą jest **Fly.io** — ma natywne
autoskalowanie i regiony w UE, a ten sam obraz i entrypoint działają bez
zmian. Hetzner z Coolify jest najtańszy w przeliczeniu na procesor, ale
zamienia koszt pieniędzy na koszt czasu: HA bazy, kopie, łatanie i monitoring
przechodzą na właściciela. Bez dedykowanego budżetu operacyjnego to zły
interes. AWS/GCP to dziś nadmiar.

**RODO nie wymaga hostingu w Polsce** — region UE wystarcza, a różnica
latencji Warszawa–Amsterdam (5–10 ms) nie uzasadnia rezygnacji z zarządzanej
platformy. Wybrany region `europe-west4` jest właściwy.

---

## 8. Czego brakuje, żeby w ogóle zauważyć problem

To jest luka osobna od wydajności i warto ją nazwać: **dziś nie ma jak
stwierdzić, że portal zwalnia**, zanim napiszą o tym użytkownicy.

Jest tylko webhook błędów 500 na Slack/Discord. Nie ma APM, nie ma metryk
p95/p99, nie ma alertów progowych. Sentry jest zamiarem (D-041), nie stanem —
nie ma go w `composer.json`.

Bez tego wszystkie progi z §7 są nie do zaobserwowania. „Przy 1000 online
dodajemy replikę" nie jest planem, jeśli nikt nie wie, ile jest online.
To P1, do zrobienia razem z rozbiciem serwisów — nie później.

---

## 9. Kolejność działań

**Zrób teraz, zanim przyjdzie wzrost:**

1. **Ręczny `pg_dump` do R2 i jednorazowe odtworzenie do osobnej bazy.**
   Jedno popołudnie. Bez tego reszta nie ma znaczenia.
2. `railway config plan` → `apply` na staging, potem produkcja: rozbicie
   `web` / `worker` / `scheduler`.
3. Cache Rule w Cloudflare dla `/zdjecia/*` + wydłużenie TTL podpisu dla
   treści publicznej.
4. Ustalić i zapisać `max_connections` Postgresa oraz próg alarmowy.
4a. **Sprawdzić w panelu Railway, czy do serwisu produkcyjnego nie jest
   podpięty wolumen.** Railway nie pozwala replikować usługi z zamontowanym
   wolumenem, więc jeśli tam jest, blokuje krok 2 i trzeba go odpiąć.
   `railway.ts:677` deklaruje, że wolumenów nie używamy (zdjęcia w R2), ale
   `Dockerfile:259` opisuje prawdziwą awarię produkcji „przy podpinaniu
   woluminu" — ktoś go kiedyś podpiął. Z repozytorium nie wynika, czy nadal
   tam jest.
5. Podstawowa obserwowalność: p95, liczba online, długość kolejki.

**Zrób przy ~500–1000 online:**

6. Plan Pro, druga replika `web`, PgBouncer, osobny worker dla `media`.
7. Cache Rules dla publicznych stron anonimowych (przepisy, profile).
8. Jedno dekodowanie obrazu zamiast czterech; upload bezpośrednio do R2.

**Zrób przy ~1000–2000 online:**

9. Postgres do zarządzanego dostawcy z HA i replikami do odczytu.
10. Rewizja sesji i cache (Redis) — po pomiarze, jako świadoma zmiana zasady.

**Drobne, do sprostowania przy okazji:**

11. `CLAUDE.md` / `AGENTS.md`: Livewire nie jest główną technologią interakcji.
12. `docs/MEDIA_PIPELINE.md`: kod generuje WebP, nie „WebP/AVIF".
13. Test przypinający dysk tymczasowych uploadów Livewire do R2.

---

## Ocena końcowa

**Stack jest dobrze dobrany i nie wymaga wymiany.** Laravel z Postgresem
i Blade, bez SPA i bez mikroserwisów, obsłuży tysiące jednoczesnych
użytkowników spokojnie — to nie jest technologia, która się tu wyczerpie.
Sama aplikacja została napisana z myślą o skali staranniej niż większość
projektów na tym etapie.

Ryzyko nie leży w wyborze technologii, tylko w tym, że **zaplanowana
konfiguracja produkcyjna nigdy nie została uruchomiona** — i że brak kopii
bazy sprawia, iż całość stoi dziś na jednym niepodpartym punkcie. Trzy pozycje
z listy „teraz" to praca na dni, nie tygodnie, i zdejmują zdecydowaną większość
ryzyka.

Właściciel zapytał, czy zmieniać technologie, zanim portal urośnie i zwolni.
Odpowiedź brzmi: **technologii nie trzeba zmieniać — trzeba włączyć tę
infrastrukturę, która już została zaprojektowana i opisana.**
