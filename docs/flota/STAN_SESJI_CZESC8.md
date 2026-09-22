# Stan sesji, część 8 — cztery raporty produktowe i infrastrukturalne

Zapisane 20 września 2026. Cztery stanowiska zdały raporty, których nie było
w STAN_SESJI.md ani w częściach 2–7. Poniżej wierny zapis tego, co w nich stoi:
liczby, granice pomiaru, jawne pominięcia, decyzje właściciela i pułapki wdrożeniowe.

| Stanowisko | Plik raportu | Zgłoszenia |
|---|---|---|
| `gpt-offline-obietnica` | `docs/product/OFFLINE_I_OBIETNICA_30.md` oraz `docs/product/MATERIALY_OFFLINE_OBIETNICA_30.md` | #30 (+ #617, #193/#594, #8) |
| `gpt-grupy-tematyczne` | `docs/product/GRUPY_TEMATYCZNE_22_PROJEKT_I_KOSZT.md` | #22 |
| `gpt-planer-zakupy` | `docs/product/PLANER_ZAKUPY_27.md` | #27 |
| `gpt-redis-ha` | `docs/infra/REDIS_HA_DECYZJE_603_604.md` | #603, #604 |

Wspólne dla wszystkich czterech: bazowy commit `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
czyste drzewo przed pomiarem, **żadne stanowisko nie wykonało commita** (blokada
metadanych Gita — opis przy każdej sekcji), żadne nie pchało, nie otwierało PR-a,
nie komentowało issues i nie wysyłało wiadomości do ludzi.

---

## `gpt/offline-obietnica` — #30 (obietnica trwałości i materiały offline)

Dwa dokumenty tego samego stanowiska: `OFFLINE_I_OBIETNICA_30.md` (audyt +
rekomendacja) i `MATERIALY_OFFLINE_OBIETNICA_30.md` (pokrycie, minimum offline,
koszt). Treść jest zbieżna; różnice w liczbach pomiarów opisane niżej.

### WERDYKT O OBIETNICY „Twoje przepisy nie zginą”: **NIE WOLNO jej dziś złożyć**

Sformułowanie z raportu: **„Rekomendacja: NIE dla stałego dodatkowego claimu
i NIE dla używania go kontekstowo jako bezwarunkowej gwarancji”** oraz
**„Odrzucić proponowane hasło w obecnym brzmieniu”**. Drugi dokument powtarza:
„Rekomendacja: NIE dla bezwarunkowego claimu — zarówno stałego, jak i kontekstowego”.

Powód: **nie ma wykazanego odtworzenia produkcyjnej bazy z kopii, a ochrona zdjęć
przed logicznym usunięciem pozostaje nieodebrana.** Eksport użytkownika zmniejsza
ryzyko tylko wtedy, gdy człowiek zdąży go pobrać i zachowa pliki; nie zastępuje
kopii serwisu. Utrata zdjęcia przed eksportem nie zostanie przez eksport odwrócona.

Dodatkowo: **nawet po domknięciu kopii** backup dobowy pozostawia okno możliwej
utraty danych, retencja jest ograniczona, a świadome usunięcie konta ma działać.
Absolutne „nie zginą” obiecuje więcej, niż taki system zapewnia. Rekomendacja
pozostaje aktualna także po odbiorze technicznym.

Zaproponowany zamiennik do zatwierdzenia:

> Pobierz swoje przepisy. Czytaj je także bez internetu.

Stanowisko **nie dopisało tego do `docs/DECISIONS.md` i nie zamknęło #30**.

### Co ustalono o bazie

- Odczyt Railway ok. **19:00–19:06 UTC**, projekt `ideal-exploration`
  (`77044ca0-2cf4-4be1-bcd8-fdb6c4d83047`), środowisko `production`
  (`ea146c13-dc55-4a4f-a386-0835f650f9ce`): są **dwa serwisy** — `Postgres`
  i `kuking.pl`. **Nie ma serwisu `kopia-bazy`.** Żaden z dwóch nie ma
  `cronSchedule`. Obraz Postgresa: `ghcr.io/railwayapp-templates/postgres-ssl:18`.
- Lista nazw zmiennych aplikacji **nie zawiera** `AWS_KOPIE_BUCKET`,
  `AWS_KOPIE_ACCESS_KEY_ID`, `AWS_KOPIE_SECRET_ACCESS_KEY` ani
  `LOG_BLAD_WEBHOOK_URL`. Wartości ukryte przez OAuth, żadnych sekretów nie odczytano.
- Kod kopii (`docker/kopia/kopia-bazy.sh`, `Dockerfile`, `.railway/railway.ts`):
  osobny obraz `postgres:18`, zrzut custom, pełne przeczytanie archiwum,
  szyfrowanie CMS, wysyłka szyfrogramu i `.meta`, kontrola rozmiaru, retencja.
  **Domyślnie 30 dni, minimum 7 potwierdzonych kopii, alarm po 36 h,
  limit pojedynczego pliku 5 GiB.**
- Tabela próby **produkcyjnej** w `docs/infra/KOPIE_I_ODTWORZENIE.md` §5 jest **pusta**.

### ZASTRZEŻENIA GRANICY POMIARU (baza) — nie skracać

- **Deklaracja serwisu nie uruchamia go.** Serwis kopii zadeklarowany
  w `.railway/railway.ts` nie jest uruchomiony w tym środowisku.
- Odczyt całego archiwum przez `pg_restore --file=/dev/null` **nie wykonuje SQL
  na docelowym serwerze** — to nie jest dowód odtworzenia.
- Minimum 7 kopii **może zachować stare kopie dłużej niż 30 dni**.
- `scripts/proba-odtworzenia.sh --petla-lokalna` woła `scripts/kopia-lokalna.sh`,
  **nie cały kontener wysyłający do R2**. Lokalna pętla nie sprawdza crona Railway,
  prawdziwego R2, produkcyjnego klucza ani czasu odzyskania całej usługi.
- `scripts/proba-wycofania.sh` to próba migracji **na pustej bazie**; nie odtwarza
  danych użytkowników, zdjęć ani kopii z R2.
- Pomiary cudze przytoczone jawnie: `KOPIE_I_ODTWORZENIE.md` §5.1 z **17 IX**
  (lokalny PostgreSQL **18.6, 50 tabel, 192 wiersze, 80 migracji, odtworzenie 2 s**)
  i z **18 IX** (kontener PG18.6 → szyfrowanie → **MinIO** → pobranie →
  odszyfrowanie → odtworzenie, **50 tabel i 202 wiersze** zgodne ze źródłem).
  **MinIO nie dowodzi działania tokenów, jurysdykcji i konfiguracji Cloudflare R2.**
  Ten pomiar **nie został powtórzony w tej sesji**.
- Użyte API Railway **nie zwraca harmonogramów Volume Backups ani stanu PITR** —
  to **nieustalone dzisiaj**. Brak serwisu kopii nie dowodzi braku każdej możliwej
  kopii ręcznej lub zarządzanej. Stanowisko **odmawia podania „zero kopii”** jako
  dzisiejszej liczby.
- **Nie podano produkcyjnego RPO/RTO** — brak pomiaru, który je wyznacza.
  Tag obrazu nie jest zapytaniem o rzeczywistą wersję serwera.
- Roadmapa §11 „PWA + hardening” jest **częściowo wykonana**; warunek
  „restore przetestowany” w „Closed alpha gate” pozostaje **nieudokumentowany
  dla produkcji**. D-043, D-049, D-143, D-192 opisują tę granicę; **D-192 z 12 IX
  nie zastępuje późniejszych prób z 17–18 IX**.

### Sprzeczności źródeł (pułapka przy czytaniu dokumentacji)

- Stary początek `KOPIE_I_ODTWORZENIE.md` **nadal twierdzi, że R2 nie istnieje**.
  D-118 wraz z adnotacją z 20 IX wskazuje ten błąd; nie wolno go używać jako
  ustalenia o dzisiejszym storage.
- D-043 mówi o wymaganiu planu Pro, ale §5.3 dokumentu kopii opisuje
  **nierozstrzygnięty rozjazd między panelem a dokumentacją Railway**.
  **Nie rekomendować zakupu planu Pro na podstawie tego starego zdania.**

### Zdjęcia: trwałość R2 to nie ochrona przed DELETE

- Przegląd `config/filesystems.php`, `docker/`, `scripts/`, `.railway/railway.ts`,
  `docs/MEDIA_PIPELINE.md` **nie znalazł wdrożonego procesu kopii zdjęć** ani
  konfiguracji wersjonowania, replikacji czy rygla. Dysk `r2_kopie` służy
  **kopiom bazy**. Zrzut PostgreSQL zawiera **jedynie metadane** `media`, nie bajty.
- `GenerateUserExport::copyToTemp()` przy błędzie odczytu zdjęcia **loguje
  pominięcie i zwraca `null`** — job może wydać paczkę mimo brakującego pliku.
  **Status `ready` nie jest dowodem kompletności mediów.**
- **Zastrzeżenie:** nie odczytano zalogowanego panelu/API Cloudflare. Brak
  konfiguracji w repo **nie jest dowodem, że nikt nie ustawił ochrony ręcznie**.
  Wynik brzmi **„ochrona niepotwierdzona”**, a nie „sprawdziłem wszystkie buckety”.
  Nazwy `AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET` istnieją na
  serwisie Railway; to nie dowód retencji ani odtworzenia zawartości.

Dokumentacja Cloudflare odczytana 20 IX:

- S3 API R2 oznacza **wersjonowanie i replikację bucketów jako niewdrożone**
  (`GetBucketVersioning`/`PutBucketVersioning`). **Nie projektować rozwiązania
  „włącz wersjonowanie S3 w R2”** — to nie jest gotowy przełącznik.
- R2 ma **Bucket Locks**: blokada usuwania i nadpisania wg prefiksu i okresu,
  ale **administrator konfiguracji może usunąć regułę**. To **nie** jest
  nieodwracalny S3 Object Lock compliance ani ochrona przed przejęciem konta.
- Koniec blokady **nie usuwa pliku** — osobny lifecycle usuwa asynchronicznie,
  zwykle w ciągu doby od terminu; **to nie twarda gwarancja**.
- Zdanie „R2 trzyma wtedy stare wersje” z §6a `LOKALIZACJA_DANYCH_R2.md`
  **nie jest dowodem dostępności wersjonowania** — nie budować na nim projektu.

### PUŁAPKA: dlaczego „x2 + lock 30 dni” nie wystarczy

Pojedyncza kopia starego pliku po upływie rygla przestaje być chroniona,
a **lifecycle usunie ją nawet wtedy, gdy oryginał jest nadal ważny**.
Potrzebny jest cykl nowych generacji albo odrębny projekt kopii przyrostowej.
**Zwykłe lustro x2 bez historii nie spełnia celu #617.** Nie stosować
`sync --delete` (propagacja DELETE). Nie ryglować żywego `incoming/` —
utrudni to działający proces usuwania kont.

### PUŁAPKA: przywrócona baza nie zna późniejszych żądań usunięcia

Po całkowitej utracie bazy sama przywrócona stara baza **nie wie** o żądaniach
usunięcia złożonych po jej utworzeniu. Potrzebna jest **niezależna, chroniona
lista tych decyzji** albo procedura odtworzenia ich z nowszego źródła.
Bez niej odbiór odzyskiwania jest niepełny. Zakres i retencja rejestru — #617/#8.
Odzyskane dane **nie wracają automatycznie do publicznego produktu**.

### Pomiary własne tej sesji

Runtime: baza `kuking_flota_gpt-offline-obietnica`, rola `kuking`,
**127.0.0.1:55439, PostgreSQL 18.6**.

| Kontrola | Wynik | Granica dowodu |
|---|---|---|
| `php artisan test` (`DataExportTest`, `EksportWygladObietnicePaczkiTest`, `PetlaOdtworzeniaJestJednaKomendaTest`, `KopiaBazyPozaRailwayemTest`, `CichyBrakKopiiBazyDajeAlarmTest`, `AlarmKopiiNieUfaBrakowiWyjatkuTest`) | **78 testów, 365 asercji, PASS, 4,13 s** | ZIP rzeczywiście składany i czytany; storage i poczta podstawione; zdjęcia testowe nie dowodzą jakości renderowania prawdziwych fotografii; czujki nie wysyłają wiadomości do ludzi |
| `bash tests/skrypty/kopia-bazy.sh` | **167 sprawdzeń, PASS** (w tym odrzucenie obciętych/uszkodzonych archiwów i błędów retencji) | Prawdziwe OpenSSL, `pg_restore`, archiwum i lokalny HTTP; **`pg_dump` i część S3 podstawione**; bez produkcyjnej bazy, Cloudflare i budowania obrazu |
| Pint `--test` (`app/`, `tests/`) | **PASS, 1007 plików** | tylko kontrola formatowania |

Drugi dokument podaje **inny** przebieg testowy: filtr
`DataExportTest|EksportWygladObietnicePaczkiTest|EksportMowiOZdjeciach|EksportDrukPodzialStronTest|EksportNieObiecujeTerminuTest|ServiceWorkerOdswiezaMarkeTest|KazdaMigracjaMaWycofanieTest`
— **56 testów, 634 asercje, wszystkie zaliczone, 5,66 s**; ten sam wynik
`kopia-bazy.sh` (**167**) oraz **Pint PASS, 1155 plików** w izolowanym runtime.
Obie liczby Pinta (1007 i 1155) pochodzą z tego samego stanowiska, dla różnych zakresów.

### CZEGO STANOWISKO NIE ZROBIŁO I DLACZEGO

- **`ProbaOdtworzeniaTest` / `tests/skrypty/proba-odtworzenia.sh` — NIE uruchomiono.**
  Powód: runtime nie ma `.git`, a wyliczanie sufiksu w skrypcie daje pusty sufiks,
  który skrypt zastępuje `_glowny` — użyłby więc **wspólnej bazy
  `kuking_zrodlo_proby_glowny`** i wykonał na niej DROP/CREATE, wchodząc w cudze
  stanowiska. To **jawnie dopuszczone pominięcie ze zlecenia**.
  **„Nie ma podstaw, by wynik tego niewykonanego testu nazwać zielonym lub czerwonym.”**
- Nie uruchomiono pełnej suity, `check.sh`, `proba-wycofania.sh` ani nowej próby
  restore produkcyjnego. Nie budowano obrazu, nie wykonano nowego pełnego dump/restore.
- **Nie wykonano produkcyjnego restore, inspekcji panelu R2, destrukcyjnych prób
  mediów, włączania backupów ani zmian retencji.**
- Odczyt skryptu wycofania **nie został przedstawiony jako jego wykonanie**.
- Nie dodano testu ani regresji produktowej — nie ma poprawki wymagającej red–green.
- Nie zmieniono schematu, tras, modeli, widoków, infrastruktury ani `docs/DECISIONS.md`.
- Nie badano otwierania ZIP/HTML na Androidzie/iPhonie — **nie rozszerzać
  instrukcji komputerowej na telefony bez próby**.
- Próbę wysłania alarmu do człowieka celowo odłożono jako **osobno upoważnioną
  czynność**, nie do wykonania w audycie.
- Pierwsza próba uruchomienia testów z filtrem zawierającym `|` przez zwykłe `wsl`
  została **błędnie zinterpretowana przez powłokę i nie stanowi wyniku** —
  poprawiony przebieg podaje jawne pliki.

### Minimum offline (#30) — 0 nowych tabel, 0 migracji, 0 tras

Eksport **już dziś** tworzy ZIP z `index.html`, `przepisy/*.html`, `wpisy.html`,
lokalnymi `zdjecia/*`, `dane.json` i `CZYTAJ-TO-NAJPIERW.txt`. **Nie potrzeba
nowego formatu, biblioteki PDF ani aplikacji offline.** Minimum #30 wymaga
**0 dni budowy nowego eksportu**; w rekomendowanym minimum: **0 nowych tabel,
0 migracji, 0 nowych tras, 0 wymaganych zmian ekranów.** Nie tworzyć tabeli
`offline_recipes`. TTL eksportu: domyślnie **7 dni** wg `config/kuking.php`.

Poza zakresem: PWA cache prywatnych stron, synchronizacja, feed/publikacja/
komentowanie offline, pełne cudze przepisy z Zeszytu (tylko tytuł, autor,
własna notatka i data zapisu), serwerowy PDF, import ZIP, aplikacja mobilna.
**Paczka konta zawiera prywatne dane — nie jest materiałem do rozdawania
na spotkaniu.**

Aktualne #30 mówi o **materiale do druku dla KGW/UTW** i wprost **zabrania
ponownego otwierania eksportu #2**; zlecenie sesji dołożyło projekt technicznego
minimum — oba znaczenia obsłużono bez implementacji. Projekt kartki A4
(wersja 1: jedna kolumna, ≥14 pt; wersja 2: dwustronna karta) to **specyfikacja
i tekst, nie zatwierdzony plik do drukarni**.

### Koszty (#30 i zależności)

Osobodzień = ok. 8 h.

| Zakres | Szacunek |
|---|---|
| Materiał do #30 na istniejącej funkcji | **1–2 dni** |
| Opcjonalny link z Zeszytu | **0,5–1 dzień** (drugi dokument: 4–8 h) |
| Produkcyjny odbiór istniejącej kopii bazy #193/#594 | **1–3 dni** (drugi dokument: 8–16 h) |
| Prosta kopia zdjęć: pełne generacje + test odzyskania #617 | **3–5 dni** (drugi dokument: 24–48 h) |
| Optymalizacja do przyrostów i deduplikacji | **dodatkowe 3–5 dni** (drugi dokument: +16–32 h) |
| Redakcja/skład/korekta karty KGW/UTW | 8–16 h |
| Próby pobrania i wydruku z 3–5 osobami | 8–16 h |

Sumy z drugiego dokumentu: minimum redakcyjne z badaniem **16–32 h**;
domknięcie ochrony DB i mediów **32–64 h**; razem **48–96 h**.
**„To nie znaczy, że do tej pory wolno opublikować claim.”**
Czas oczekiwania na potwierdzenie wygaśnięcia pełnej retencji zdjęć — **około
miesiąca**; nie mieści się w osobodniach implementacji. Pilotaż krótkiej retencji
sprawdza mechanizm, **nie dowodzi upływu okna produkcyjnego**.

Cennik R2 (odczyt 20 IX): **Standard 0,015 USD/GB-miesiąc**, klasa A
**4,50 USD/mln**, klasa B **0,36 USD/mln**; darmowy pułap **10 GB-miesiąc,
1 mln A, 10 mln B** (wspólny dla konta — **nie odejmować osobno dla każdego
bucketu**). Egress R2 bez opłaty.

| Wariant | Zajęte miejsce | S=10 / 100 / 1000 GB, USD/mies. |
|---|---|---|
| Jedna warstwa źródłowa | S | 0,15 / 1,50 / 15 |
| Druga pełna kopia | 2S | 0,30 / 3 / 30 |
| Codzienna pełna generacja, rygiel 30 dni, lifecycle po 31 dniach | ok. **33–34S** (32–33 generacje) | 4,95–5,10 / 49,50–51 / 495–510 |

Drugi dokument podaje **wariant 31–32 pełnych kopii** dla samego dodatkowego
storage: **4,65–4,80 / 46,50–48,00 / 465–480 USD/mies.** dla M = 10 / 100 / 1000 GB.
**31–32 (i 33–34) to przykład budżetowy, nie górna gwarancja** — opóźnione
kasowanie może zwiększyć zbiór.

Operacje: dla `N = 100 000` obiektów i 30 pełnych kopii miesięcznie to **≥3 mln
zapisów (A) i ≥3 mln odczytów (B)**, przed paginacją, HEAD, manifestami i multipart.
Przy całkowicie wolnym pułapie ok. **9 USD** za zapisy i 0 USD za odczyty;
przy zużytym pułapie ok. **14,58 USD**. **Małe zdjęcia mogą kosztować więcej
w operacjach niż w przestrzeni.**

Transfer: 100 GB przy efektywnym **10 MB/s → ok. 2 h 47 min**; przy **20 MB/s
→ ok. 83 min** w jedną stronę, ok. **167 min** dla odczytu + zapisu.
**To obliczenie, nie pomiar infrastruktury.** Jeśli bajty idą przez Railway,
doliczyć jego egress — darmowy egress R2 tego nie znosi.

Kopie bazy: przy dobowym zaszyfrowanym zrzucie `D` GB i ok. 30 kopiach
orientacyjnie `30 × D × 0,015 USD/mies.` **Nie zmierzono produkcyjnego D.**
Eksporty: przy `E`=10 paczek dziennie po `Z`=0,2 GB i TTL 7 dni → **14 GB,
0,21 USD/mies.**; wariant miesięczny: 100 paczek × 0,2 GB → **4,67 GB-mies.**

**NIE mnożyć lokalnych 1–2 sekund restore przez wielkość produkcji i nie podawać
wyniku jako RTO.** Do RTO wchodzą alarm, reakcja, dostęp do klucza, pobranie,
odtworzenie bazy i zdjęć, weryfikacja i przywrócenie działania.

Koszt eksploatacji: przegląd co tydzień 15–30 min; odtworzenie bazy i kontrolnych
zdjęć co miesiąc, budżet **2–4 h pracy/miesiąc** (drugi dokument: ćwiczenie DB
+ media 1–2 h/mies., kwartalna szersza próba 2–4 h, razem **3–6 h/mies.**
bez incydentów). Próba utraty dostępu do głównego środowiska — co kwartał.

**Ryzyko reputacyjne (cytat sensu):** po haśle „nie zginą” użytkownik może
zrezygnować z własnej kopii; utrata nieodtwarzalnego zdjęcia będzie wtedy także
złamaniem obietnicy, a **usunięcie hasła później nie cofnie tej decyzji użytkownika**.

### DECYZJE WŁAŚCICIELA (#30)

1. **Claim:** przyjąć rekomendację NIE i zatwierdzić tekst ograniczony do
   pobrania/czytania offline? Po decyzji wpisać do `docs/DECISIONS.md`;
   **nie ogłaszać hasła jako zatwierdzonego na podstawie tego projektu**.
2. **#30:** zatwierdzić kartkę i zamówić skład + próbę z KGW/UTW, czy świadomie
   odłożyć materiał? (Odłożenie/odrzucenie zapisać przy odbiorze #30.)
3. **Baza #193/#594:** wskazać osobę odpowiedzialną i termin konfiguracji oraz
   produkcyjnego ćwiczenia. Rozstrzygnąć plan/Backups w panelu —
   **nie kupować Pro w ciemno**.
4. **Zdjęcia #617:** pełne generacje na start po zmierzeniu S/N i budżetu, czy
   droższa kopia przyrostowa? Czy ochrona ma obejmować także **utratę konta
   Cloudflare** (osobny dostawca/konto)?
5. **Retencja #8/#617:** okno, sposób usuwania, **niezależny zapis decyzji
   o usunięciu**. Rozróżnić minimum ochrony, termin sprzątania i czas
   faktycznego skasowania. Dokument **nie ustanawia nowej polityki prawnej**.
6. **Zeszyt:** pozostać przy tytułach/notatkach dla cudzych przepisów?
   **Rekomendacja TAK** dla minimum; rozszerzenie ma osobny koszt i decyzję uprawnień.
7. **Eksploatacja:** zatwierdzić operatora, zastępstwo i budżet prób.
   **Bez tego jednorazowy restore nie stanowi trwałego pokrycia obietnicy.**
8. **RPO/RTO:** dobowy harmonogram **nie oznacza zmierzonego RPO ≤24 h** —
   to dopiero **cel** przy zdrowym harmonogramie; RTO dopiero po pomiarze.
9. **Zakres awarii:** rozdzielić token aplikacji od administracji kopii.

Pięć warunków „przed silniejszym komunikatem” (§2 pierwszego dokumentu)
i pięć kryteriów odbioru (§1.3 drugiego) sprowadzają się do: działający
harmonogram + pierwszy kompletny szyfrogram z **prawdziwego R2** + zgodność
skrótu + odtworzenie w izolowanej PG18 + **porównanie treści kontrolnego zestawu**
(samo porównanie liczby wierszy nie wykryje zmienionej treści) + potwierdzenie
odczytu klucza prywatnego z niezależnego miejsca + zgodność `APP_KEY`
+ działanie aplikacji na przywróconej bazie (**bez wykonywania zaległych
maili/jobów z kopii**) + odebrane #617 + wskazany odbiorca alarmu ze sprawdzoną
dostawą (**alarm wymagający działającej aplikacji nie zastępuje niezależnego
nadzoru awarii całego środowiska**) + zatwierdzone RPO/RTO + **manifest łączący
kopię bazy z mediami** (nie wystarczy odzyskać dwa niezgodne czasowo zbiory).

### Stan przekazania / PUŁAPKA PRZY SCALANIU

Po pomiarach powiązanie Gita worktree przestało działać: `git status`
i `git rev-parse HEAD` zwracają **`fatal: not a git repository: (NULL)`**.
**Commit nie został wykonany; nie ma SHA nowego commita.** Nie naprawiano
wspólnych metadanych w trakcie cudzej przebudowy.

Kontrola własna: porównanie SHA-256 z kopią runtime przygotowaną przed dokumentem —
**693 pliki w `app/`, `database/`, `resources/`, `routes/`; zero różnic.**
Jedyną edycją w worktree było dodanie tych dokumentów.

**Po przywróceniu stanowiska:** potwierdzić gałąź i bazowy SHA, sprawdzić zakres
zmian i commitować **wyłącznie** `docs/product/OFFLINE_I_OBIETNICA_30.md`
(i odpowiednio `MATERIALY_OFFLINE_OBIETNICA_30.md`). Proponowany komunikat:
„Oceń pokrycie obietnicy trwałości i koszt materiałów offline”.
**Push i PR należą do kolejki koordynatora, nie do stanowiska.**

---

## `gpt/grupy-tematyczne` — #22 (grupy tematyczne)

### Wniosek

Grupy są **V1 za bramką**, nie V2 ani zakazem na zawsze. `docs/ROADMAP.md`:
„Planner/groups/forks dopiero gdy WAC i D30 pokazują powroty”.
**Nie odczytano danych produkcyjnych, więc dokument nie stwierdza, czy bramka
jest dziś spełniona.** Przygotowanie projektu nie otwiera bramki.

Rekomendacja: po otwarciu bramki pilotaż **2–3 publicznych grup**, zakładanych
przez administratora marki z uzgodnionym założycielem. Bez zaproszeń, prywatnych
treści i obowiązkowego wyboru grupy w zwykłym formularzu.

**D-021 i D-159 nadal obowiązują: tag pozostaje tagiem; nowa grupa musi mieć
realną różnicę funkcjonalną, a nie być przemianowanym tagiem.**
Wariant minimum **nie zamyka całego issue** — prywatność członkowska i zakładanie
grup przez każdego wymagają osobnego zatwierdzenia.

### CZY POWSTAŁ KOSZT MODERACYJNY: **TAK**

Raport ma osobną sekcję §6 „Koszt moderacyjny i prywatność” z jawnym modelem
godzin, macierzą uprawnień, liczbą kolejek i procedurą porzuconej grupy.

**Jawny wzór kosztu (przyrost ponad dzisiejszą moderację, nie prognoza ruchu):**

`H/tydzień = (10×G + 4×R + 8×E + 20×A + 30×O) / 60`

gdzie G = liczba grup, R = lokalne oceny zgłoszeń, E = dodatkowe globalne oceny
z konfliktu grupowego, A = odwołania, O = sprawy przejęcia opieki.
**Minuty 10/4/8/20/30 są założeniami planistycznymi.** Nie dodawać drugi raz
zwykłej globalnej oceny wpisu już uwzględnionej w bieżącym budżecie.

| Scenariusz | Podstawienie | Przyrost |
|---|---|---|
| Spokojny pilotaż | G=3, R=10, E=2, A=1, O=0 | **106 min = 1,8 h/tydz.** |
| Większy ruch | G=10, R=50, E=10, A=5, O=1 | **510 min = 8,5 h/tydz.** |

Rozruch: **2–3 h szkolenia na opiekuna** (3 grupy = **6–9 h**) oraz
**4–8 h marki** na przećwiczenie eskalacji i osierocenia.
**Nie obejmuje to czasu zwykłego komentowania i budowania relacji.**
**Zastrzeżenie:** przy lokalnej decyzji spór może trwać znacznie dłużej niż 4 min;
po dwóch tygodniach pilotażu **zastąpić założenia zmierzonym czasem obsługi**
i skorygować liczbę otwartych grup.

**Kolejki:** obecne **5 kategorii w `KolejkiPanelu` pozostaje**; **0 nowych
centralnych kolejek zgłoszeń**, **1 nowy rodzaj lokalnego widoku kolejki**
(czyli G ograniczonych widoków przy G grupach). Istniejąca kolejka odwołań
dostaje nowy typ decyzji. Dochodzi **1 filtr operacyjny grup bez opiekuna**.
**To mniej ekranów niż dwie niezależne skrzynki, lecz więcej pracy: jedna sprawa
może wymagać zarówno lokalnej, jak i globalnej oceny.**

**Powiadomienia:** w minimum **3 nowe rodziny** — lokalna decyzja i jej cofnięcie,
obsługa odwołania lokalnego, powierzenie/odebranie odpowiedzialności za grupę.
Dołączenie, odejście i nowy wpis **nie wysyłają powiadomień do całej grupy**.
**Nie wolno wyciszyć istniejącego „Ugotowałem”.**

**Porzucona grupa (propozycja, nie wdrożona automatyka):** cotygodniowy przegląd;
po **30 dniach** braku aktywności opiekunów marka sprawdza sytuację (sam licznik
wizyt nie dowodzi zaniedbania); po ręcznej próbie i **14 dniach** bez rozwiązania
grupa idzie do archiwum albo zostaje jawnie przekazana. Zamknięte konto ostatniego
opiekuna → **niezwłoczne wstrzymanie nowych publikacji**. Oryginały i eksport
autora pozostają dostępne.

**Przed startem:** przypisać zastępstwo, budżet godzin i **próg zaległości
zatrzymujący otwieranie kolejnych grup**. Regulamin, role, odwołania i retencję
**zweryfikować z osobą odpowiedzialną za prawo**; dokument nie rozstrzyga
nowych obowiązków prawnych.

### Koszt techniczny

| Pakiet minimum | Osobodni |
|---|---|
| Domknięcie decyzji, scenariusze, konsultacja obsługi moderacji | 2–3 |
| 4 tabele, 6 migracji, constraints, rollback, dokumentacja | 3–5 |
| Akcje, Policy, członkostwo, role, współbieżność | 3–5 |
| Ekrany, publikacja, feed grupy, profil i wyszukiwanie | 4–6 |
| Moderacja lokalna, powiadomienia, odwołania, osierocenie | 5–8 |
| Eksport, kasowanie konta, macierz regresji, dostępność, pomiary zapytań | 6–9 |
| **Suma bez rezerwy** | **23–36** |
| **Z rezerwą 25%, zaokrąglone w górę** | **29–45** |

To ok. **6–9 tygodni** jednej osoby przy 5 dniach pracy tygodniowo.
Koszt pieniężny: `(29–45) × stawka za osobodzień` **plus** godziny z §6;
**brak uzgodnionej stawki, więc nie podano kwoty.**

| Wariant | Przyrost |
|---|---|
| Pozostać przy tagach | **0 dni** implementacji grup |
| Publiczne V1-minimum | **23–36 dni + rezerwa** |
| Prywatne grupy i zaproszenia | **dodatkowo 12–20 dni** przed rezerwą |
| Zakładanie przez każdego | **dodatkowo 4–7 dni** przed rezerwą |
| Wielokrotne przypisanie lub podgrupy | **brak uczciwej wyceny w tym projekcie** |

Infrastruktura minimum: przy 3 grupach po 100 członków, 1000 powiązanych wpisach
i 100 decyzjach — **1403 nowe rekordy podstawowe**, bez indeksów i audytu.
**Nie kopiujemy zdjęć do nowych bucketów i nie wysyłamy nowego wpisu do N osób.**
Koszt zapytań, rozmiar dysku i p95 **trzeba dopiero zmierzyć**;
**sam model nie uzasadnia Redisa ani mikroserwisu.**

Budżet UI: **4 nowe rodziny widoków** (lista/własne grupy, grupa, zarządzanie,
lokalna moderacja) i zmiany w **7 rodzinach istniejących ekranów**.
**Nie twierdzimy, że szkic spełnia WCAG bez testu działającego interfejsu.**

### Dane: 4 tabele, 6 migracji (projekt, nie stan bazy)

M1 `groups` (UUID, `tag_id` UNIQUE FK RESTRICT, `founder_id` nullable SET NULL,
status active/archived/hidden z CHECK, `timestamptz`; **bez przełącznika
prywatności „na przyszłość”**), M2 `group_members` (PK `(group_id,user_id)`,
role member/moderator z CHECK; **rola założyciela wynika z M1, nie z drugiego
niespójnego pola**), M3 `group_posts` (`post_id` UNIQUE — jeden wpis w jednej
grupie w pilotażu; status active/withdrawn/hidden), M4 `group_moderation_actions`
(**historia lokalna nie podmienia globalnego `posts.status`**),
M5 kontekst zgłoszeń (nullable FK `reports.group_id`, nowy cel `group`),
M6 odwołania lokalne (nullable FK `appeals.group_moderation_action_id`,
CHECK dokładnie jednego źródła decyzji).

**Skan `app`, `database/migrations`, `routes` NIE znalazł implementacji
`groups`, `group_members`, `group_posts`, `Group`, `GroupMember`.**
`database/reference/schema_future.sql` zawiera **szkic** `groups` i `group_members`,
bez pełnych CHECK-ów, cyklu życia, powiązania treści i lokalnej moderacji —
**nie nadaje się do uruchomienia wprost**.

### PUŁAPKI PRZY WDROŻENIU I SCALANIU

- **`moderation_actions_one_per_report` nie pozwala po prostu dopisać obok drugiej
  decyzji** — stąd osobna tabela decyzji lokalnych w koszcie.
- **Sprawdzenie ostatniego opiekuna wymaga testu współbieżności; zwykły CHECK
  nie zapewni reguły obejmującej wiele wierszy i tabel.**
- Przekazanie grupy: transakcja, blokada grupy i członkostw, weryfikacja następcy,
  aktualizacja założyciela, audyt. Usunięcie/zablokowanie konta **nie może
  blokować realizacji praw użytkownika** — grupa idzie do archiwum do przejęcia.
- **Wycofanie:** najpierw wyłączyć wejścia i zapisy grup, zachowując odczyt
  historii spraw. **Nie usuwać danych grup przez powrót starego kodu.**
  `down()` **ma odmówić**, jeśli usunąłby członkostwo, ukrycie, sprawę albo
  odwołanie, którego ponowne `up()` nie odtworzy. Test odmowy **i** kontrola
  dodatnia obowiązkowe. **Backup nie zastępuje strażnika D-088.**
- Indeksy M3 wymagają **EXPLAIN na rzeczywistym zapytaniu** po
  `posts.published_at, posts.id`; sama obecność indeksu nie obiecuje czasu odpowiedzi.
- **Publiczna grupa nie jest obietnicą zamkniętej rozmowy** — wpis pojawia się
  poza grupą na dotychczasowych zasadach. **Odejście z grupy nie usuwa
  publicznych wypowiedzi z internetu.**
- Wariant prywatny: **samo schowanie karty grupowej zostawia publiczny permalink** —
  przynależność musi ograniczać również oryginał przepisu. Zmiana grupy prywatnej
  na publiczną **nie może ujawniać historycznych wpisów bez decyzji ich autorów**.
- Dołączenie **nie zmienia feedu Start** i nie obserwuje automatycznie autora ani
  tagu. **Nie ma szóstej pozycji nawigacji mobilnej.**

### Pomiar własny (istniejący fundament, nie grupy)

Baza `kuking_flota_gpt-grupy-tematyczne`, `kuking`, `127.0.0.1:55439`.

| Zestaw | Wynik |
|---|---|
| `TagPlacePagesTest` | 5 testów / 36 asercji |
| `TagPreselectionTest` | 6 / 40 |
| `TagiObserwowanieTest` | 24 / 72 |
| `FeedTagowNiePokazujeCudzegoPrzepisuTest` | 4 / 21 |
| **Razem** | **39 testów / 169 asercji, zielone** |

Pint `--test`: **PASS, 1155 plików**, bez zmian formatowania.

### CZEGO NIE ZROBIONO

- **Nie wykonano oglądu produkcji, badań użytkowników ani benchmarku przyszłych grup.**
- Nie napisano testów utrwalających nierozstrzygnięte wybory produktowe.
- Nie uruchamiano pełnego zestawu aplikacji, testów przeglądarkowych ani CI —
  zakres jest dokumentacyjny.
- **`ProbaOdtworzeniaTest` nie uruchamiano; nie zgłasza się dla niego ani sukcesu,
  ani awarii.**
- Bez kodu produkcyjnego, migracji, tras, modeli, widoków, nowych zależności,
  zmian schematu, push i PR. Decyzji **nie wpisano do `docs/DECISIONS.md`**.
- Historyczne twierdzenie z komentarza #22, że **nie ma użytkowników**, jest
  **pomiarem cudzym**, nie ustaleniem tej sesji. Porównanie z Garnkiem to
  uzasadnienie autora issue, **nie zmierzony tu efekt retencyjny**.
- Oznaczenie „#40-tagi-miejsce” ze zlecenia **nie jest numerem issue GitHub** —
  sprawdzone #40 dotyczy wygaśnięcia zawieszeń. Właściwe materiały:
  `docs/research/tematy-i-pytania-2026-09-11/`, PR #376,
  `docs/design/STRONY_TAGOW_370.md`, D-222 (#681). **PR #376 nie jest uchyleniem
  bramki dla członkostw i prywatnych grup z #22.**
- Pierwsza próba filtra z `|` **nie uruchomiła zestawu poprawnie** — powtórzono
  każdy zestaw osobno.
- Pomocnicza próba odczytu połączenia **zawiodła przez BOM przed `export`**:
  klient próbował domyślnego gniazda 5432 i skończył błędem braku bazy.
  **Nie wykonał migracji ani zapisu.** Właściwe testy szły przez `testuj.sh`
  z jawnym portem 55439.

### DECYZJE WŁAŚCICIELA (#22) — 10 pytań przed implementacją

1. **Bramka:** jakie WAC, okres i liczebność dojrzałej kohorty wystarczą?
   Odczytać `kuking:wac` i `kuking:raport` na uprawnionym źródle produkcyjnym.
   **`PowrotPoDniach` mierzy wizytę co najmniej N dni po rejestracji, nie dokładnie
   dzień 30; `CookRetentionCohorts` osobno mierzy gotowanie w tygodniu 4.
   Nie mieszać tych definicji** ani nie opierać decyzji na jednej osobie
   i efektownym procencie.
2. **Wartość:** brakuje już członkostwa i lokalnej odpowiedzialności, czy
   wystarczą tagi? Pierwsze kosztuje **29–45 dni**, drugie nie wymaga kodu grup.
3. **Kto zakłada:** administrator z opiekunem (minimum) czy każdy
   (**+4–7 dni** i nowa centralna kolejka)?
4. **Prywatność:** tylko publiczne czy także członkowskie (**+12–20 dni**
   i nowa granica bezpieczeństwa)? Czy ukryta lista członków odpowiada potrzebie?
5. **Relacja z tagiem i publikacja:** jedna grupa na tag, jedna na wpis, bez
   automatycznego przypisania i bez obowiązkowego selektora — akceptowane?
6. **Role:** czy lokalny moderator może tylko ukrywać/przywracać powiązanie,
   a sankcje wobec członków zostają u marki?
7. **Spory:** czy marka bierze każde zgłoszenie i odwołanie od decyzji lokalnej?
   **Bez tego nie delegować moderacji.**
8. **Osierocenie i zamknięcie:** przyjąć ręczny przegląd **30+14 dni**,
   zastępstwo oraz archiwizację zamiast kasowania cudzych wpisów?
9. **Ludzie i budżet:** kto obsłuży pilotaż, kto zastąpi, przy jakiej zaległości
   wstrzymujemy nowe grupy?
10. **Odbiór:** jaki wynik publikacji, retencji i zaległości to sukces, a jaki
    powód zatrzymania? **Ustalić przed pilotażem, nie po odczytaniu wyniku.**

**Nie uznawać liczby członków za dowód retencji.**

### PUŁAPKA PRZY SCALANIU — commit zablokowany

Po pomiarach **zniknął katalog metadanych** wskazany przez `.git`:
`C:/Users/matma/Documents/Codex/kuking.pl/.git/worktrees/gpt-grupy-tematyczne`.
Git uruchomiony przy repozytorium kanonicznym **odnajduje inne repozytorium
nadrzędne `C:/Users/matma/Documents/Codex`** — **nie wykonano w nim zapisu**.
Nie podejmowano naprawy ani tworzenia zastępczej gałęzi.
**Dokument pozostaje lokalny, bez nowego SHA.**

---

## `gpt/planer-zakupy` — #27 (planer i lista zakupów)

### CO WYSZŁO: **ODŁOŻYĆ** — Zeszyt pokrywa pierwszą połowę problemu

**„Rekomendacja: odłożyć implementację #27. Najpierw sprawdzić potrzebę na
istniejącym Zeszycie i udokumentować spełnienie bramki V1.”**
To rekomendacja do decyzji właściciela, **nie zamknięcie zgłoszenia** ani nowa
decyzja projektowa.

**Zeszyt już rozwiązuje pierwszą połowę problemu:** pozwala zebrać wybrane przepisy
w prywatnym folderze (np. na najbliższy tydzień) i do nich wrócić.
**Nie ustala dat, nie sprawdza zapasów i nie tworzy listy do sklepu.**
Przy kilku daniach i zakupach zapisywanych na kartce może jednak wystarczyć.

Stanowisko **świadomie nie przygotowało ekranów ani schematu wdrożeniowego
V1-minimum**: warunek przejścia do tego etapu w zleceniu nie został wykazany.

### Zastrzeżenia (granice wniosku)

- To **hipoteza potrzeby, nie wynik rozmów z użytkownikami.** Persona Ani
  w `docs/PRODUCT.md` wskazuje planer jako potrzebę późniejszą — **to nie dowód,
  że osoby 50+ porzucają gotowanie przez brak kalendarza.**
- **Brak pomiaru** częstotliwości problemu i powrotów do planowania.
- **Nie przyjęto za fakt** ogólnej tezy, że planery są nieużywane — w tym zleceniu
  **nie badano innych serwisów**.
- **Bieżące produkcyjne WAC/D30 są NIEUSTALONE.** Cytat: **„»Nie wykazano
  spełnienia« nie znaczy »zmierzono niespełnienie«.”**
- `docs/product/COLD_START.md` §9 podaje **WAC ≥80 i D30 publikujących ≥25%**
  dla bramki wzrostu 200 → 2000 — **to inna bramka**, nie automatyczna zgoda na planer.
- W `docs/ROADMAP.md` **nie ma liczbowego progu ani długości okresu oceny**.
- **Pomiar cudzy:** komentarz w #27 z 09.09.2026
  (`issuecomment-5602928667`) stwierdzał brak implementacji i niespełnioną bramkę —
  **ta ocena nie jest przenoszona na 20.09.2026**.
- `AGENTS.md` §12 wyłącza planer i zakupy z MVP; `docs/FEATURES.md` umieszcza je w V1.

### Dwie korekty założeń z opisu #27

1. `database/reference/schema_future.sql` jest **szkicem oznaczonym „Nie uruchamiać
   automatycznie”**. Zawiera nagłówek `meal_plans`, ale **nie pozycje planu ani
   tabele zakupów**. **To nie jest gotowa baza funkcji.**
2. Pytanie o premium odwołuje się do dawnej hipotezy. Aktualny
   `docs/MONETIZATION.md` uznaje je za **przedwczesne**; przychód nie uzasadnia
   funkcji. Projekt **nie proponuje płatności ani ograniczania Zeszytu**.

### Luka w Zeszycie wykryta przy odczycie

Akcja domenowa przyjmuje `note` i eksport ją obsługuje, ale
**formularz wyboru zeszytu i `saveRecipe()` NIE przekazują notatki** —
nie ma więc dostępnego planowania terminów. Unikalny zapis przepisu w zeszycie
**nie jest listą powtarzalnych zdarzeń**. **Listy zakupów i odhaczania nie ma
w badanych trasach, modelach i migracjach** — nie udawać jej publicznym wpisem
ani opisem folderu.

### Pomiar własny

`php artisan test --filter Zeszy --compact` przez skrypt floty:
**123 testy przeszły, 1174 asercje, 21,78 s.** Obejmuje m.in.
`ZapisDoWybranegoZeszytuTest`, `WyborZeszytuMaWalidacjeTest`,
`IdempotentnyZapisDoZeszytuTest`, `ZawartoscZeszytuTest`,
`ZeszytNiedostepneZapisyTest`, `Visibility/ZeszytWidocznoscTest`,
`ZeszytBezKluczaGlownegoTest` i przypadek eksportu zapisanej notatki z `DataExportTest`.
Pint `--test`: **PASS, 1155 plików.**
Runtime `/home/mateusz/flota/gpt-planer-zakupy-run`, baza
`kuking_flota_gpt-planer-zakupy`, `kuking`, `127.0.0.1:55439`, **bez dowiązania `vendor`**.

**GRANICA:** to pomiar aplikacji przez testy HTTP i PostgreSQL,
**nie ogląd przeglądarki ani badanie łatwości obsługi przez człowieka.
Zielone testy nie dowodzą, że folder tygodniowy wystarczy ludziom.**

### Koszty wariantów (własny szacunek, nie pomiar i nie wycena)

Dzień = 8 h pracy jednej osoby znającej projekt, z testami, dokumentacją i odbiorem.
**Nie sumować wariantów — to alternatywy.**

| Wariant | Przyrost danych i interfejsu | Szacunek |
|---|---|---|
| **A.** Obecny prywatny Zeszyt + kartka/notatka | 0 tabel, 0 migracji, 0 ekranów | **0 dni implementacji; 3–5 dni pracy badawczej** rozłożone na 3 tygodnie |
| **B.** Zeszyt + jeden opcjonalny termin | 0 tabel, **1 migracja** kolumny daty (+ ewentualny indeks) w `collection_items` | **4–7 dni** |
| **C.** Osobny plan i prosta lista online | ok. **4 tabele, 2 migracje, 2 nowe ekrany** + zmiany wejść | **17–29 dni** |
| **D.** Pełny zakres #27 z offline i łączeniem składników | zakres C + synchronizacja, reguły jednostek, konflikty, prywatność urządzenia | **27–49 dni** |

Rozbicie C: rozstrzygnięcia/dane/migracje/wycofanie 2–4; plan 3–5; lista 3–5;
formularze i dojścia 3–5; niedostępny przepis/eksport/usunięcie konta 2–4;
testy uprawnień, regresji, odbiór 50+/320 px/200%, dokumentacja 4–6.
**Razem 17–29.** D dodaje **6–12 dni** na offline/synchronizację i **4–8 dni**
na bezpieczne łączenie składników — **razem dodatkowe 10–20 dni**.

**PUŁAPKA C:** **C nie spełnia wymogu offline z #27** — nie wolno ogłaszać nim
realizacji tego zgłoszenia. **Jeśli brak sieci jest głównym problemem badanych,
C odpada, nawet gdy jest tańsze.**

**PUŁAPKA B:** **nie wolno dopisać daty do tekstowego `note` i obiecywać
poprawnego sortowania.** Istnienie kolumny notatki nie usuwa kosztu formularza,
Policy, walidacji, eksportu, testów i wycofania bez utraty danych.
Pojedynczy termin nie obsłuży wielu wykonań tego samego przepisu.
Do rozstrzygnięcia: prywatność daty w publicznym zeszycie, przenoszenie terminu,
usunięcie zapisu, ponowne gotowanie.

**PUŁAPKA sumowania składników:** grupowanie po działach sklepu **nie wynika
z `recipe_ingredients.group_name`** („Ciasto”, „Farsz”). Normalizacja nazw
w `ingredients` **też nie rozstrzyga**, czy wolno sumować „cebula” i „cebula
czerwona”, sztuki i gramy albo ilości niepodane.

Potencjalne tabele (gdyby moduł wrócił): `meal_plans`, `meal_plan_items`,
`shopping_lists`, `shopping_list_items`. **Dwie migracje mogą tworzyć po dwie
tabele — to nie cztery gotowe pliki.** **Nie wystarczy FK do użytkownika:**
konto jest również anonimizowane, więc prywatne dane muszą wejść do istniejącej
procedury usuwania i eksportu. **D-088 wyklucza ciche usunięcie dokonanych
wyborów przy rollbacku.**

### Co ta funkcja zabiera

- **Uwagę i prostotę** — człowiek musi odróżnić „chcę zachować”, „chcę ugotować
  wtedy” i „ugotowane”.
- **Czas zespołu** — rezerwa planistyczna na utrzymanie **0,5–1,5 dnia miesięcznie
  dla C, 1–3 dni dla D** przy małej skali; **to nie zmierzony koszt hostingu**.
- **Miejsce i zasady życia danych:** przy 1000 planujących, 52 tygodniach, 7 daniach
  i 30 pozycjach zakupów tygodniowo historia roczna to **52 tys. planów
  + 364 tys. pozycji planu + 52 tys. list + 1,56 mln pozycji list = 2,028 mln
  wierszy**. **To obliczenie z założeń, nie prognoza ruchu ani pomiar bazy.**
  **Upływ niedzieli nie jest zgodą na skasowanie planu.**
- **Nowe ryzyko prywatności offline** — lista zostaje na urządzeniu; ustalić
  zachowanie przy wylogowaniu, zmianie konta i cofnięciu dostępu do przepisu.
  **Bez tej decyzji nie rozszerzać service workera o prywatne dane.**
  **Współdzielenie linkiem nie jest „darmowym” obejściem kont domowników —
  link staje się uprawnieniem.**
- **Powiadomienia** nie są konieczne i nie wchodzą do porównania C/D.
  **Sam zapis w planie nie powinien powiadamiać autora przepisu.**
  **„Ugotowałem” zachowuje swoje reguły bez zmian.**
- **Zaplanowanie nie może automatycznie utworzyć „Ugotowałem” ani podnieść WAC.**

**Moderacja:** osobna mechanika **nie jest potrzebna w wariancie wyłącznie
prywatnym** — nie ma publikacji, rankingu, zgłoszeń od innych ani nowej kolejki.
Nadal potrzebne: Policy właściciela, limity rozmiaru i liczby zapisów, bezpieczne
wyświetlanie tekstu, respektowanie dostępu do powiązanych przepisów.
**Prywatność nie daje prawa oglądania zablokowanego przepisu.**
Udostępnianie planów/list **zmieniłoby tę ocenę** i wymaga osobnej decyzji.

### Propozycja badania (NIE wykonanego)

**„Propozycja badania do zatwierdzenia, nie wykonane badanie ani kontakt
z użytkownikami”:** 6–8 osób 50+ gotujących regularnie, różne sposoby robienia
zakupów. Najpierw rozmowa o ostatnich rzeczywistych zakupach, potem **trzy tygodnie
obserwacji**. **Nie pytać wyłącznie „czy chcesz planer”**, nie wysyłać
cotygodniowego przypomnienia wymuszającego użycie. Koszt **3–5 dni** w wariancie A;
czas kalendarzowy dłuższy. **Mała próba pozwala znaleźć przeszkody, nie oszacować
retencję całej społeczności.** W raporcie zapisać liczniki **i mianowniki**,
także rezygnacje i brak poprawy.

**Powrót do projektu wymaga OBU dowodów:** zaakceptowanego raportu WAC/D30
z produkcji **oraz** powtarzalnej potrzeby niepokrytej prostszym rozwiązaniem.
Wynik „folder wystarcza” kończy temat bez nowego modułu; „potrzebna jest tylko
data” kieruje do oceny B; dopiero problem planu i zakupów uzasadnia V1-minimum.
**Nie tworzyć testu kodu, który narzuca jedną z tych decyzji właścicielowi.**

### DECYZJE WŁAŚCICIELA (#27)

1. Czy przyjąć **odłożenie #27** i badanie wariantu A (0 dni implementacji,
   3–5 dni pracy badawczej), czy wskazać istniejący dowód potrzeby?
2. Jakie **definicje, progi, okres i minimalna liczebność danych** otwierają
   bramkę V1? Czy dostępny jest aktualny raport do takiej oceny?
3. Dopiero po pozytywnym wyniku: wystarczy termin przy zapisie czy oddzielny plan?
   **B = 4–7 dni, C = 17–29, D = 27–49.**
4. Jeżeli moduł wróci: czy **offline jest warunkiem wydania**, jak długo zostaje
   historia, jak traktować niedostępny przepis? Współdzielenie i przypomnienia —
   osobny zakres i ponowne oszacowanie.

**„Nie ma potrzeby odpowiadać na pytania 3–4, żeby przyjąć rekomendację odłożenia.”**

### CZEGO NIE ZROBIONO

- Nie zmieniono kodu produkcyjnego, testów, schematu ani zależności.
  **Nie powstał prototyp, nowa trasa, widok ani migracja.**
- Nie zmieniono roadmapy ani dziennika decyzji — **rekomendacja nie udaje
  decyzji właściciela**.
- Nie uruchamiano pełnego zestawu testów, buildu ani przeglądarki.
  **`ProbaOdtworzeniaTest` nie wchodzi w filtr `Zeszy`; nie deklaruje się jego
  zaliczenia** ani odtworzenia historycznej kolizji bazy.
- Nie mierzono produkcji, nie wysyłano wiadomości, nie zmieniano issue #27,
  nie wykonano push ani PR.
- Nazwy `17-zeszyt-zapisy` i `47-zeszyt-droga` ze zlecenia **nie zostały
  jednoznacznie zmapowane** na historyczne zgłoszenia; **#17 („Komuś wyszło”)
  i #47 (macierz widoczności) to inne zadania**. Potwierdzonym powiązanym
  zgłoszeniem jest zamknięte **#644** (wybór zeszytu).
- Pierwsza próba z filtrem złożonym z alternatyw **została błędnie rozdzielona
  przez granicę Windows/WSL (`command not found`) i nie liczy się jako wynik testów.**

### PUŁAPKA PRZY SCALANIU — commit zablokowany

`git status` i `git diff --check` kończą się **`fatal: not a git repository: (NULL)`**.
Wskaźnik `.git` stanowiska wskazywał
`C:/Users/matma/Documents/Codex/kuking.pl/.git/worktrees/gpt-planer-zakupy`,
a `git rev-parse --resolve-git-dir` potwierdził, że **ten cel nie jest już
katalogiem Git**. `git rev-parse --show-toplevel --git-common-dir` z lokalizacji
kanonicznej rozpoznało **inny, nadrzędny klon `C:/Users/matma/Documents/Codex`
i `../.git`**. Na początku sesji te same polecenia działały (gałąź `gpt/planer-zakupy`,
SHA bazowe, czyste drzewo). **Przyczyny zmiany nie ustalono.**
**Nie powstał commit — nie ma końcowego SHA.** Po odzyskaniu: sprawdzić gałąź
i różnice, dodać wyłącznie ten dokument, komunikat
„Oceń zasadność i koszt planera oraz listy zakupów”.

---

## `gpt/redis-ha` — #603 (Redis) i #604 (PostgreSQL HA)

Status: **materiał do decyzji, bez wdrożenia i bez podstaw do zamknięcia obu zgłoszeń.**

### Wniosek

**Na razie zostawić kolejkę i cache w PostgreSQL.** Obecne odczyty zasobów nie
pokazują presji, a lokalna próba nie wykazała powtarzalnej istotnej szkody
od ruchu infrastrukturalnego. **Nie oznacza to, że zmierzono pełny produkcyjny
udział SQL — tego pomiaru nadal brakuje. Nie nazywamy braku telemetrii dowodem
zerowego kosztu.**

### HA: AUTOMATYCZNE PRZEŁĄCZENIE, nie replika do ręcznej promocji

**Cytat z raportu: „Railway HA ma automatyczne przełączenie, nie tylko replikę
do ręcznej promocji.”** [Oficjalna procedura konwersji](https://docs.railway.com/databases/postgresql-ha)
opisuje **Patroni, etcd i HAProxy, automatyczną promocję standby** i **zerwanie
trwających połączeń podczas failover**. Dostęp do odczytów na standby istnieje,
ale jego włączenie wymaga osobnej decyzji o spójności i zmian w aplikacji.

**Granice tej obietnicy — nie mylić przy awarii:**

- **Klient musi się ponownie połączyć; transakcja nie przeżywa awarii.**
- Domyślna topologia to **primary + 2 standby, 3 etcd, 3 instancje HAProxy —
  w kalkulacji 9 instancji**, nie sam primary z jedną kopią.
- `postgres-patroni/src/patroni/config.rs` ma domyślnie
  **`PATRONI_SYNCHRONOUS_MODE=false`, TTL lidera 45 s, pętlę 10 s, retry 17 s**.
  **To nie jest zmierzona konfiguracja przyszłego klastra w panelu.**
  Dlatego **marketingowe „<10 sekund” z README NIE staje się tutaj RTO.**
- **Replikacja asynchroniczna może stracić ostatnie zatwierdzone zapisy.**
  Wybór synchronizacji zmienia kompromis dostępność/trwałość/opóźnienie.
- **HA nie zwiększa przepustowości zapisów pojedynczego primary.**
- **HA nie cofa omyłkowego DELETE, który replikuje się na standby.**
- **Nie dowiedziono rozmieszczenia w niezależnych domenach awarii** ani
  odporności na utratę regionu. Dostępność witryny nadal ogranicza pojedynczy
  web `all`, R2 i reszta ścieżki.
- **Nie zakładać, że backup konwersji zastępuje sprawdzoną kopię #594.**

**Warunki konwersji (spełnione po odczycie):** oficjalny obraz z przypiętą wersją
14–18 i brak własnego start command. **Konwersji nie uruchamiano.**

**PUŁAPKA WDROŻENIOWA:** konwersja **przerywa połączenia i zmienia endpointy**;
referencje zmiennych w projekcie są przepinane, ale **literalne adresy wymagają
ręcznej zmiany**. **Powrót do standalone wymaga oryginalnego węzła jako lidera.
Pozostawione wolumeny nadal kosztują.** To procedura z dokumentacji,
**nie odebrany rollback Kuking**.

**Rozdzielanie odczytów:** szablon Railway (SHA `3f3faee6f105f3355c2bc3c64110a3f7f795a527`),
`haproxy/src/template.rs` wystawia port **5433** dla standby, sprawdza sondę
Patroni pod `/replica` i wybiera backend przez **leastconn** (README mówi
round-robin — **przyjmujemy kod, nie ten skrót**). Zwykły endpoint 5432 idzie
do primary. **Nie ma automatycznego rozpoznawania SELECT i rozdzielania SQL
przez proxy.** `config/database.php` Kuking **nie ma dziś osobnych read/write
hosts** — **włączenie HA samo nie zmniejszy obciążenia odczytami primary**.
**Spóźniona replika nie może ponownie odsłonić ukrytej treści.**
**Samo Laravel `sticky` nie gwarantuje świeżości między kolejnymi żądaniami.**

### PRÓG DLA REDISA (#603) — propozycja do zatwierdzenia

Kolejność: **obserwacja → diagnoza przyczyny → porównanie wariantów → decyzja.**
**Wartości nie zamieniono w testy produktu ani konfigurację.**

| Sygnał | Proponowany próg i okno | Co robimy |
|---|---|---|
| Udział queue + cache + locks w DB time | **≥20% w 3 kolejnych oknach 15 min, ≥1000 SQL/okno** | zbieramy reprezentatywną próbę; **same 20% bez szkody użytkownika nie wystarczają** |
| Pogorszenie biznesowego SQL | **p95 lub p99 ≥20% ponad dopasowaną bazę odniesienia** i jednocześnie naruszone uzgodnione SLO | korelacja z infrastrukturą, plany, czekanie na blokady |
| Czas stron | kandydat z #598: **p95 >1 s / p99 >2 s przez 10 min, ≥100 żądań** | właściciel zatwierdza SLO; **startu PHP z przyrządu nie liczyć jako tej metryki** |
| Tabela `jobs` | **10 tys. wierszy → przegląd; 100 tys. → pilna diagnoza**; albo **p95 pop >10 ms przez 15 min** | najpierw delayed/ready/reserved, indeks/plan, bloat, autovacuum |
| Wiek gotowego zadania | obecne **600 s** | sprawdzić żywego workera i koszt media; **Redis nie przyspiesza dekodowania zdjęć** |
| Połączenia | ostrzeżenie **50**, krytyczne **125**; planowany budżet **<50** | wg #598; **to nie jest samodzielny trigger Redisa** |
| Blokady | **p95 czekania >100 ms przez 15 min albo deadlock >0** | najpierw rodzaj blokady i skrócenie sekcji krytycznej; **blokady transakcyjne zostają w PG** |
| CPU / I/O | **CPU >70% faktycznego limitu przez 15 min** razem z pogorszeniem SQL | **IOPS mierzyć z hosta/metryk, nie utożsamiać QPS z IOPS** |

**WARUNEK ZGODY NA MIGRACJĘ:** na izolowanym środowisku o zasobach zbliżonych
do Railway, z mieszanym obciążeniem z #605, **co najmniej 3 powtórzenia** muszą
pokazać poprawę **p95/p99 biznesowego SQL o ≥20%** po usunięciu badanego ruchu
infrastruktury, bez pogorszenia błędów i trwałości. **Dopiero wtedy** porównujemy
koszt Redis/Valkey z optymalizacją PG. **Liczby 20%, 100 ms i okna są propozycją
operacyjną, nie fizyczną granicą technologii.**

**Próg QPS:** ma wynikać z ostatniego stabilnego poziomu takiej próby — alarm przy
**70% zmierzonego poziomu**, na którym pierwszy raz naruszono SLO, przy tym samym
miksie zapytań. **Dziś ta wartość pozostaje NIEZNANA.
„Sztuczna stała »Redis od 1000 QPS« byłaby nieuczciwa.”**

### Odczyt produkcji (Railway, bez SQL i zmian)

Projekt `ideal-exploration`, środowisko `production`. Zasoby: **168 godzin,
próbka co 300 s, po 2017 próbek na metrykę.**

| Sygnał | Wynik | Ograniczenie |
|---|---|---|
| CPU PostgreSQL: śr. / maks. próbka | **0,001991 / 0,012728 vCPU** | nie znamy udziału rodzajów SQL |
| RAM PostgreSQL: śr. / maks. / ostatnia | **0,08513 / 0,09553 / 0,08823 GB** | — |
| Dysk: śr. / ostatnia | **0,12501 / 0,126713856 GB** | metryka `DISK_USAGE_GB`, **nie `pg_database_size()`** |
| Topologia aplikacji | **1 replika, `kuking-entrypoint all`** | rozdzielenie #595 **nie jest wdrożone** |
| PostgreSQL | obraz `postgres-ssl:18`, 1 replika, **Amsterdam** | brak własnego start command |
| Dostępność konwersji HA | potwierdzona w konfiguracji usługi | domyślnie 2 standby, 3 etcd, 3 HAProxy |
| Połączenia o 17:25 i 18:25 UTC | **2 zajęte: 1 active, 1 idle; limit 500, dostępne 497** | active obejmuje pomiar — **to nie peak** |
| Kolejka, 5 próbek 17:30–18:30 UTC | **0 gotowych, 0 zaległości, 0 zawieszonych; 0 nowych failed, 4 historyczne** | nie liczy zadań odłożonych na przyszłość ani rozmiaru tabeli |

**GRANICA POMIARU PRODUKCJI:** brak calls/s i DB time według tabel, brak p95/p99
SQL, IOPS, czasu GC, lock waits i rozmiaru `jobs`. **Metryka dysku usługi nie
pozwala oddzielić bazy Kuking od WAL, metadanych i pozostałych plików.**
Szczyty krótsze od interwału mogą pozostać niewidoczne; średnie nie wyznaczają
maksymalnej przepustowości. **Nie podstawiano lokalnej bazy testowej pod produkcję.**
Nie wykonywano połączeń PostgreSQL poza lokalnym portem 55439 ani nie dodawano
instrumentacji do działającego serwisu.

### Pomiar lokalny (niezmieniona aplikacja)

Przed przyrządem: `UmowaKolejkiTest` **4 PASS, 8 asercji**.
Przyrząd w `scripts/infra603/`, poza kodem uruchamianym przez serwis.
PHP **8.4.24**, baza `kuking_flota_gpt-redis-ha`, `kuking`, port **55439**.
Dane syntetyczne: **1 konto, 200 wpisów, 40 przepisów**; w drugim przebiegu
dodatkowo **6 JPEG 3,84 Mpx**. Brak zewnętrznej poczty, R2 i webhooków.
**20 żądań na trasę po rozgrzewce**, wszystkie 200 i niepuste HTML.

| 60 żądań: odkryj / szukaj / przepis po 20 | Liczba SQL | Czas SQL, przebieg 1 | Czas SQL, przebieg 2 |
|---|---|---|---|
| Dane aplikacji | 800 | **630,35 ms** | **1005,42 ms** |
| Sesje | 180 | **390,86 ms** | **834,72 ms** |
| Cache | 180 | **68,74 ms** | **101,94 ms** |
| Kolejka i `cache_locks` w tych odsłonach | **0** | 0 | 0 |

**Cache to 15,5% liczby SQL, ale tylko 6,3% / 5,2% mierzonego czasu SQL.
Sesje to 35,9% / 43,0% czasu. Przeniesienie samej kolejki i cache'u nie usuwa
kosztu sesji.** Te udziały dotyczą dokładnie tej próbki, **nie dnia produkcji**.

| Osobny scenariusz | Wynik |
|---|---|
| Bezczynny worker, 4 kolejki, `--sleep=1`, ok. 10 s | **40 SQL jobs + 31 cache; 25,37 ms (próba 1) / 39,89 ms (próba 2); ok. 7 SQL/s — nie 7 połączeń** |
| 200 cykli cache put/get + lock acquire/release | **400 SQL cache, 405 / 407 SQL locks** |
| 6 rzeczywistych `ProcessUploadedImage` | **6 ready, 0 failed, 1,97 s**; SQL: dane 24 / 57,91 ms, queue 25 / 7,86 ms, cache 22 / 4,95 ms |
| GC 2000 przeterminowanych sesji | **1 DELETE, 10,38 ms**, sprawdzono 2000 usunięć |
| Odczyt i usunięcie 2000 przeterminowanych kluczy cache | **2 SQL, 18,31 ms** |

**Granice przyrządu:** `DB::listen` zbiera liczby i czas **udanych** SQL po stronie
klienta PHP; **nie zbiera tekstu SQL ani bindings**; ten czas obejmuje komunikację
i oczekiwanie i **nie jest czasem CPU PostgreSQL**; **nie obejmuje osobno
BEGIN/COMMIT, autovacuum, WAL/fsync ani nieudanych prób SQL**. Klasyfikator
**nie jest uniwersalny** dla dowolnych joinów, CTE i zapytań administracyjnych.
**Nowy gość na każde żądanie — to nie rozkład ruchu realnych zalogowanych osób.**
Losowe GC sesji wyłączono **wyłącznie w przyrządzie** i zmierzono osobno.
**DatabaseStore usuwa wygasłe klucze przy odczycie — ta próba nie udaje
nieistniejącego okresowego `cache:prune`.** Nie zmierzono dużego prune failed
jobs/batches, autovacuum ani wielodniowego bloatu.

### Tabela `jobs` — konkretny punkt obserwacji

3 serie po 200 prawdziwych `DatabaseQueue::pop('high')`, rekordy odłożone o dobę,
brak gotowego zadania, payload 1024 B, `ANALYZE` po załadowaniu.
**To nie test odbioru 100 tys. gotowych zadań.**

| Wiersze jobs | Rozmiar z indeksami | p95 SQL: próba 1 / 2 | p99 SQL: próba 1 / 2 |
|---|---|---|---|
| 0 | 24 576 B | 0,33 / 0,84 ms | 0,39 / 2,86 ms |
| 1 000 | 1 261 568 B | 0,37 / 0,95 ms | 0,46 / 2,52 ms |
| 10 000 | 12 066 816 B | 1,18 / 6,60 ms | 1,63 / 9,74 ms |
| 100 000 | 120 012 800 B | **14,75 / 56,10 ms** | **17,86 / 69,11 ms** |

**Ready, reserved i delayed mierzyć oddzielnie; sama liczba wierszy nie mówi o lag.
To nie uniwersalny limit Postgresa ani automatyczna decyzja o Redisie.**

### CZEGO EKSPERYMENT NIE DOWIÓDŁ (QPS i przyczynowość)

3 pary A/B: ten sam SELECT 20 publicznych wpisów, 1000 wykonań, najpierw sam,
potem obok czterech procesów generujących cache/lock/polling przez 12 s.
**Klucze blokad różne per proces — to NIE test kontencji jednego klucza.**

| Przebieg | Łączny QPS infrastruktury w 3 próbach | p95 biznesowego SQL bez → z infrastrukturą |
|---|---|---|
| 1 | **5028 / 4745 / 5078** | 0,37→0,37; 0,35→0,37; 0,45→0,42 ms |
| 2 | **1386 / 2028 / 1836** | 0,48→0,71; 0,96→0,76; 0,71→0,85 ms |

Host współdzielony: **load average 9,71/10,09** na początku/końcu pierwszego
przebiegu, **13,46/24,06** drugiego. **Nie odejmowano cudzego obciążenia.**
Stała kolejność A/B i krótka próba dodatkowo ograniczają wnioskowanie.
**„Nie wyznaczono wiarygodnego progu nasycenia QPS produkcji.”
5 tys. krótkich SQL/s lokalnie nie znaczy 5 tys. żądań HTTP/s na Railway.
Nie wybieramy wygodniejszej liczby i nie przenosimy jej do produkcyjnego alarmu.**

### Jak uzupełnić brakujące pomiary produkcji

Przez **7 dni**, w tym szczyt i wdrożenie, zbierać **minutowe agregaty**: calls
i czas dla sessions/cache/cache_locks/jobs/danych, histogram czasów SQL biznesowych,
liczbę błędów, ready/reserved/delayed per kolejka, wiek najstarszego gotowego
zadania, bytes i dead tuples tabel, lock waits, deadlocks, CPU i I/O.
**Obecne czujki co godzinę / 15 min są za rzadkie.**
Jeśli `pg_stat_statements` działa — różnice calls/total_exec_time między próbkami,
odrzucić okno po resecie, **nie resetować globalnych statystyk**.
**Agregaty `pg_stat_statements` NIE dostarczą p95/p99** — potrzebny histogram
z instrumentacji bez treści, bindings, URL i identyfikatorów osób.
**Nie wyłączać sesji, limiterów ani locków na produkcji** w celu uzyskania
wariantu „bez infrastruktury”.

### Co zabierze awaria Redis/Valkey (wnioski z kodu, NIE wykonany test awarii)

| Zakres przeniesienia | Skutek niedostępności / utraty stanu |
|---|---|
| Tylko queue | dispatch może rzucać wyjątek, odbiór staje; **nowe zdjęcia nie osiągają `ready`**, eksporty nie powstają, poczta i czyszczenie CDN czekają |
| Cache domyślny | także odczyty stron ze stopką/licznikami i limiterami mogą kończyć się błędem; **nie ma automatycznego przełączenia z `redis` na PG — sama definicja `failover` jej nie aktywuje** |
| Cache limits i budżet poczty | liczniki logowania/2FA i dobowy budżet listów z **D-076** przestają być dostępne; **utrata kluczy może odnowić limit, choć wysłane listy nie cofają się**; `DziennyBudzetListow` łapie `LockTimeoutException`, **a nie każdy wyjątek połączenia** |
| Locks harmonogramu | `withoutOverlapping` i przyszłe `onOneServer` z #595 zależą od wspólnego cache; **utrata blokady przy działającym procesie może dopuścić duplikat** |
| Sesje w PG | Redis nie wylogowuje, ale awaria cache nadal może uniemożliwić przejście żądania |
| „Ugotowałem” | `NotifyUser` zapisuje **synchronicznie w PostgreSQL**, nie przez kolejkę Redis — **nie pada razem z kolejką**; całą ścieżkę może jednak zablokować middleware/cache |
| Awaria w roli `all` | nadzorca ma ograniczoną tolerancję szybkich śmierci procesów — **przetestować, czy awaria workera nie eskaluje do restartu wspólnego kontenera web**; #595 zmniejsza tę zależność |

**PUŁAPKA: „Nie wolno automatycznie ratować locków sterownikiem `array`/`file`
ani przełączać tylko części procesów na inną bazę locków” — powstaną dwa
niezależne mechanizmy, a nie wspólna blokada.**
**Transakcyjne `pg_advisory_xact_lock` komentarzy/tagów i blokady wierszy
NIE przechodzą do Redisa przez zmianę `CACHE_STORE` i powinny zostać w PostgreSQL.**

### Warunki przed ewentualnym wdrożeniem Redisa

1. **Zapewnić klienta:** obecny `Dockerfile` **nie instaluje `phpredis`**,
   a `composer.json` **nie deklaruje Predis**. **Sama zmiana zmiennych nie wystarczy.**
   Valkey wymaga potwierdzenia zgodności klienta/wersji.
2. **Dostosować `retry_after`: database ma 960 s, szablon Redis 90 s, eksport ma
   timeout 900 s.** Obecny `UmowaKolejkiTest` bada database.
   **Przełączenie bez zmiany i regresji grozi ponownym wydaniem trwającego joba.**
3. **Zmienić czujkę kolejki:** `StanKolejki` czyta twardo `jobs` — po migracji
   **pokazywałaby pustą, zdrową kolejkę, podczas gdy Redis ma zaległość**.
   Alarm ma dochodzić także podczas awarii cache (#598/#599).
4. **Oddzielić ulotny cache od kolejki/locków/limitów.** **Logiczny numer bazy
   Redis nie izoluje limitu RAM, eviction ani awarii procesu.** Dla stanu
   krytycznego: brak eviction, alarm RAM/dysku, świadomie wybrana trwałość.
5. **Zatwierdzić RPO kolejki.** **AOF z fsync co sekundę może stracić ostatnią
   sekundę zapisów; snapshot RDB ma większe okno. HA nie zastępuje trwałości.**
6. Próba odcięcia sieci, restartu, utraty klucza i pełnej pamięci na stagingu:
   brak podwójnego listu/eksportu, odzyskane pending media, zachowane formularze,
   czytelny błąd, działający alarm. **Zweryfikować atomowość DB + enqueue:
   dwóch magazynów nie obejmuje jedna transakcja PostgreSQL.**

**Wycofanie migracji:** zatrzymać producentów, rozliczyć queued/reserved/delayed,
opróżnić lub jawnie przenieść zadania z identyfikacją duplikatów, przełączyć
wszystkich konsumentów i producentów razem, zachować starą usługę do rozliczenia.
**„Samo ustawienie `database` z powrotem NIE przeniesie zadań z Redisa.”
Nie czyścić locków działających zadań.**

### KOSZTY (PLN)

Kurs **1 USD = 3,7998 PLN**, tabela NBP **182/A/NBP/2026** z 18.09.2026 (odczyt 20.09).
**To przeliczenie, bez podatku i marży przewalutowania; nie jest fakturą.**
Cennik Railway: **RAM 10 USD/GB/mies., CPU 20 USD/vCPU/mies., wolumen
0,15 USD/GB/mies., egress 0,05 USD/GB.** Minimum **Hobby 5 USD = 19,00 zł**,
**Pro 20 USD = 76,00 zł** (obejmują zużycie całego workspace —
**nie dodawać minimum drugi raz do każdej usługi**).
**Nie ustalono aktualnego planu ani pozostałego kredytu konta.**
Model: `PLN = 3,7998 × (10M + 20C + 0,15V + 0,05E)`.

| Scenariusz | USD/mies. | **PLN netto/mies.** |
|---|---|---|
| Dzisiejszy pojedynczy PG (projekcja średnich z 7 dni) | 0,91 | **3,46** |
| **HA**: D = 0,126713856 GB (metryka dysku usługi) | 11,43–23,48 | **43,44–89,22** |
| **HA**: D ×10 = 1,26713856 GB, RAM/CPU bez zmian | 11,94–23,99 | **45,39–91,17** |
| **HA**: D ×10 i większe obciążenie (każdy PG 1 GB / 0,1 vCPU, etcd/proxy wariant większy) | 41,99 | **159,56** |
| **Redis** pojedynczy: 0,25 GB RAM, 0,01 CPU, 1 GB dysku | 2,85 | **10,83** |
| **Redis** pojedynczy: 1 GB RAM, 0,05 CPU, 2 GB dysku | 11,30 | **42,94** |

Założenia **na jedną instancję** (wariant mały → większy): PostgreSQL ×3 —
0,25 → 0,5 GB RAM, 0,02 → 0,05 vCPU, D dysku; etcd ×3 — 0,05 → 0,1 GB RAM,
0,005 → 0,01 vCPU, 0,01 → 0,05 GB dysku; HAProxy ×3 — 0,025 → 0,05 GB RAM,
0,002 → 0,005 vCPU. **„Te wielkości HA są założeniami do budżetu, nie zmierzonym
minimum ani gwarancją zmieszczenia się w nim.” Zweryfikować minimum 24 h na stagingu.**

**HA zastępuje pojedynczy PG: modelowany przyrost przy D to ok. 39,98–85,76 zł/mies.**
ponad projekcję obecnego PG, **zanim rozliczymy minimum/kredyt workspace**.
Dla samego klastra na Pro minimalny rachunek: **76,00–89,22 zł** przy D
i **76,00–91,17 zł** przy 10D; **realny rachunek obejmuje także aplikację
i inne zużycie workspace**. **Podwojenie usług w czasie próby stagingowej
jest dodatkowo płatne proporcjonalnie do czasu.**

**PUŁAPKA INTERPRETACYJNA:** wzrost samego dysku ×10 dodaje tu tylko **1,95 zł/mies.** —
**„nie oznacza to, że dziesięciokrotny ruch kosztuje o 1,95 zł więcej”.**
Przyrost CPU/RAM, WAL i pracy indeksów zależy od ruchu.

Koszty **nie obejmują**: dodatkowego WAL ponad D, backupów, PITR, dodatkowych
wolumenów po rollbacku i publicznego egress. **PITR ma osobne zużycie bucketu
i egress archiwum** — 0,015 USD/GB-mies. plus egress; **nie zmierzono retencji/WAL,
więc nie dodano fikcyjnej kwoty**.
**Podane 11–43 zł NIE obejmuje Redis HA** (Sentinel/HAProxy) ani **dwóch osobnych
instancji** dla ulotnego cache i trwałej kolejki — dwie takie same instancje
podwajają koszt zasobów. **Valkey nie wyceniono jako oddzielnej oferty dostawcy.**

Model w `evidence/redis-ha/costs.json`, przeliczenie `python3 scripts/infra603/costs.py`.

### Zależności przejęte jako pomiary cudze

- **`gpt/monitoring`, commit `c3c51041732cb5a5939e2934911eb8fbb4edcaa2`,
  `docs/infra/MONITORING_ODBIOR_2026_09_20.md` §1–3:** lokalnie pojedynczy slot
  HTTP / worker / scheduler zajmuje po 1 połączeniu; kontrola 6 procesów dała 6.
  Produkcyjny `max_threads=4` z #598. Model `2 × (4R + Q + S) + 4` daje
  **16 / 24 / 18 / 26** dla podstawy / dodatkowego web / dodatkowego media /
  obu zmian. **To obliczenia budżetu, nie zmierzone piki.**
- **`gpt/rozbicie-uslug`, commit `6fc9621738f2e7b9b7baab3bef99e6aa76365d72`,
  `ROZDZIELENIE_ROL_595_600_POMIARY.md` i `ROZDZIELENIE_ROL_595_600.md`:**
  przygotowano podział ról i ochronę `onOneServer()`, **bez wdrożenia** —
  nie naliczane jako istniejące usługi.

### DECYZJE WŁAŚCICIELA (#603 / #604)

| Decyzja | Warianty i koszt |
|---|---|
| **Redis teraz czy po danych?** | Zalecenie: **po danych**; koszt usługi dziś **0 dodatkowo**. Najpierw tydzień pomiarów i próba #605. Przedwczesne włączenie: **11–43 zł/mies. na jedną instancję** plus testy trwałości, limity, monitoring i obsługa awarii. |
| **Jak długo może nie działać baza i ile zapisów wolno utracić?** | RTO/RPO wybiera właściciel. **HA z asynchronicznym standby preferuje dostępność; wariant synchroniczny ogranicza ryzyko utraty kosztem opóźnień i możliwej odmowy zapisów. NIE ZATWIERDZONO ŻADNEJ WARTOŚCI.** |
| **Czy kupić HA już przy małej bazie?** | Można **dla dostępności, niezależnie od QPS**. Najpierw sprawdzona kopia/restore **#594**, odbiorca alarmu **#599** i budżet. **Obecne niskie CPU nie rozstrzyga wartości ciągłości działania.** |
| **Czy rozdzielać odczyty?** | Teraz **brak dowodu potrzeby**. Później wydzielone bezpieczne zapytania i testy laga/prywatności; **sam HA ich nie rozdziela.** |
| **SLO i budżet** | Zatwierdzić progi z §3 oraz miesięczny budżet i ostrzeżenie kosztowe. **Nie ustawiać twardego limitu odcinającego produkcję jako zamiennika alarmu.** |

**Odbiór HA dopiero na odrębnym stagingu:** odczyt konfiguracji i kosztów węzłów;
backup i odtworzenie; zapis oznaczonych transakcji przed awarią; utrata primary;
pomiar czasu do pierwszego **udanego i poprawnego zapisu aplikacji**, brakujących
potwierdzonych transakcji i duplikatów; powrót starego węzła jako standby;
utrata jednego etcd/proxy; test reconnectów HTTP/worker/scheduler; test lag
obejmujący blokadę użytkownika i ukrycie wpisu; cofnięcie do standalone i koszt
pozostawionych wolumenów. **„Nie uznawać samego zielonego statusu Patroni
za odbiór Kuking.”**

### CZEGO NIE ZROBIONO

**Nie wykonano:** push, PR, komentarzy/zamknięcia issues, Railway plan/apply,
utworzenia usług/kont, zmian zmiennych, SQL produkcyjnego, testów awarii
produkcji/stagingu, wysyłek do ludzi, uruchomienia konwersji HA,
włączenia `pg_stat_statements`.
**Brak aktualnego podziału produkcyjnego SQL, maksymalnego QPS i pomiaru kosztu
działającego HA pozostaje jawny.**
Nie wprowadzono poprawki zachowania aplikacji, migracji ani nowej zależności —
nie ma więc regresji wymagającej testu przed poprawką.
**Progi produktowe nie zostały zamienione w asercje.**
Wycofanie pakietu = usunięcie dokumentów i przyrządów z commita; zachowanie
portalu i schemat bazy bez zmian.

**Odtworzenie (Git Bash na Windows):**

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-redis-ha
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-redis-ha-run/scripts/infra603/run.sh
```

**PUŁAPKA:** `run.sh` odtwarza **wyłącznie własną bazę** — **nie uruchamiać obok
testów na tej samej bazie**. Sprawdza host/port/nazwę/użytkownika przed migracją,
buduje assety i **odmawia produkcji**. Pierwsza próba HTTP wykryła **brak manifestu
Vite**; po buildzie powtórzono cały pomiar — **wynik błędnego żądania nie wszedł
do tabel**. Wyniki kopiować ze `storage/infra603` **przed ponowną synchronizacją
runtime**. Surowe JSON skrócono bez usuwania próbek.
Kontrole projektu i przyrządu opisuje `REDIS_HA_ODBIOR_603_604.md`.
