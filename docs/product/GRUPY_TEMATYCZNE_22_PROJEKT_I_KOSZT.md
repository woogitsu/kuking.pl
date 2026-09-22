# Grupy tematyczne — projekt i koszt V1-minimum (#22)

Data: 20 września 2026. Status: **propozycja do decyzji właściciela, bez implementacji**.
Zlecenie: [#22](https://github.com/woogitsu/kuking.pl/issues/22).
Baza odczytu i pomiarów: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/grupy-tematyczne`.

## 1. Wniosek i granica decyzji

Grupy są **V1 za bramką**, nie V2 ani zakazem na zawsze. `docs/ROADMAP.md`
stanowi: „Planner/groups/forks dopiero gdy WAC i D30 pokazują powroty”.
Nie odczytano danych produkcyjnych, więc ten dokument **nie stwierdza, czy
bramka jest dziś spełniona**. Przygotowanie projektu nie otwiera bramki.

Najmniejsza sensowna grupa dodaje do istniejącego miejsca rozmowy trzy rzeczy:
świadome członkostwo, odpowiedzialnego opiekuna i możliwość porządkowania
wpisów tylko wewnątrz grupy. Sam filtr tagu już istnieje. Nie warto sprzedawać
jego ponownego zbudowania jako nowej mechaniki.

Rekomendacja: po otwarciu bramki pilotaż **2–3 publicznych grup**, zakładanych
przez administratora marki z uzgodnionym założycielem. Bez zaproszeń, prywatnych
treści i obowiązkowego wyboru grupy w zwykłym formularzu. Wycena: **23–36
osobodni**, plus 25% rezerwy, czyli **29–45 osobodni**. To estymacja własna,
nie pomiar czasu implementacji ani termin dostarczenia. Szczegóły w §7.

Wariant minimum świadomie zawęża pełne #22: prywatność członkowska i zakładanie
grup przez każdego pozostają do osobnego zatwierdzenia. Nie zamyka całego issue.
D-021 i D-159 nadal obowiązują: tag pozostaje tagiem; nowa grupa musi mieć
realną różnicę funkcjonalną i zaakceptowaną nazwę, a nie być przemianowanym tagiem.

## 2. Co już jest — dowód przed projektowaniem

### Odczyt własny kodu i dokumentacji

| Element | Istniejący fundament | Czego jeszcze nie zapewnia |
|---|---|---|
| Miejsce tematyczne | `TagController`, `pages/tags/index` i `show`, `TagCollage`, `TagPublicStats`: katalog, zdjęcia, strona tagu | Członkostwa, odpowiedzialności i lokalnych sankcji |
| Powroty do tagu | `tag_follows`, `TagFeed`, `TagFollowController` | Obserwowanie nie jest zgodą na członkostwo ani listę członków |
| Redakcja | `tag_promotions`, panel tagów promowanych, D-021 | Przypisania opiekuna z uprawnieniami do konkretnej grupy |
| Publikacja z miejsca | Link do formularza z `?tag=slug`, zachowanie wyboru po logowaniu i walidacji | Świadomego powiązania wpisu z grupą |
| Zeszyt | `Collection`, `CollectionPolicy`, `collection_items`: własne zapisy wpisów i przepisów | Wspólnego zarządzania, ról i moderacji grupowej; nie przerabiać zeszytu na grupę |
| Strona główna | `FeedController::home`: obserwowani → tagi → odkrywanie jako wybór źródła; nie suma trzech list | Subskrypcji grup; dołączenie nie powinno zmieniać tej kolejności |
| Bezpieczeństwo | Policy, blokady, widoczność treści i przepisów, dostęp do zdjęć przez rodzica | Granicy uprawnień moderatora grupy |
| Moderacja | `reports`, `moderation_actions`, `appeals`, audyt i powiadomienia | Niezależnej decyzji lokalnej obok decyzji marki |

Skan `app`, `database/migrations` i `routes` nie znalazł implementacji
`groups`, `group_members`, `group_posts`, `Group` ani `GroupMember`.
`database/reference/schema_future.sql` zawiera **szkic**, nie migracje:
`groups` i `group_members`. Brakuje w nim m.in. pełnych CHECK-ów, cyklu życia,
powiązania treści i lokalnej moderacji. Nie nadaje się do uruchomienia wprost.

Oznaczenie „#40-tagi-miejsce” ze zlecenia nie jest numerem issue GitHub:
sprawdzone #40 dotyczy wygaśnięcia zawieszeń. Odpowiadające temu obszarowi
materiały są w `docs/research/tematy-i-pytania-2026-09-11/`, PR #376,
`docs/design/STRONY_TAGOW_370.md` i D-222 (#681). PR #376 zezwalał na rozwój
stron tagów i pytań przy małej społeczności; nie odczytuję go jako uchylenia
bramki dla członkostw i prywatnych grup z #22.

### Pomiar własny na nietkniętym drzewie

Istniejące testy HTTP uruchomiono w kopii WSL, przed dopisaniem dokumentu.
PostgreSQL: `127.0.0.1:55439`, użytkownik `kuking`, dedykowana baza
`kuking_flota_gpt-grupy-tematyczne`, przygotowanie skryptami floty.

| Zestaw | Wynik | Co rzeczywiście sprawdzono |
|---|---:|---|
| `TagPlacePagesTest` | 5 testów / 36 asercji | Zdjęcia, wejście do publikacji, pusty tag, prywatne zdjęcie poza katalogiem, stała liczba zapytań dla 2 i 30 promowanych kart |
| `TagPreselectionTest` | 6 / 40 | Logowanie, publikacja, alias/scalenie, usunięcie wyboru, zachowanie także pustego wyboru po błędzie |
| `TagiObserwowanieTest` | 24 / 72 | Obserwowanie, onboarding, brak duplikatów, pierwszeństwo obserwowanych ludzi, prywatność i blokady |
| `FeedTagowNiePokazujeCudzegoPrzepisuTest` | 4 / 21 | Tytuł i zdjęcie przepisu nie wyciekają przez tag ani jego strumień |
| **Razem** | **39 / 169, zielone** | Istniejący fundament; nie dowód działania przyszłych grup |

Odtworzenie: po `przygotuj-runtime.sh gpt-grupy-tematyczne` uruchomić
`testuj.sh gpt-grupy-tematyczne --filter NAZWA_ZESTAWU` dla kolejnych czterech
wierszy, szeregowo. Z Git Bash wymagane `MSYS_NO_PATHCONV=1`; pełne polecenia
uruchomienia WSL są w zleceniu. Pierwsza próba przekazania alternatywy z `|`
przez powłoki nie uruchomiła zestawu poprawnie; powtórzono osobno każdy zestaw.

Nie wykonano oglądu produkcji, badań użytkowników ani benchmarku przyszłych grup.
Nie napisano testów utrwalających nierozstrzygnięte wybory produktowe.
Historyczne twierdzenie w komentarzu #22, że nie ma użytkowników, jest
**[pomiar cudzy: komentarz w #22]**, a nie ustaleniem tej sesji.
Porównanie z Garnkiem jest uzasadnieniem autora issue, nie zmierzonym tu efektem
retencyjnym ani wynikiem nowego badania konkurencji.

## 3. V1-minimum — propozycja zachowania

1. Administrator marki otwiera grupę na wniosek konkretnego opiekuna. Jeden
   kanoniczny tag może wskazywać najwyżej jedną aktywną grupę. Powiązanie służy
   odnalezieniu grupy; **tag nie przypisuje automatycznie wpisu ani osoby**.
2. Grupa jest publiczna. Gość czyta publiczne wpisy; zalogowana uprawniona osoba
   dołącza lub odchodzi jednym działaniem. Nie ma akceptacji podań. Lista członków
   nie jest publiczna; uczestnictwo widzi członek i osoby uprawnione do obsługi.
3. Dołączenie nie obserwuje automatycznie autora ani tagu. Nie zmienia feedu
   Start. Własne grupy dostępne przez „Moje”; odkrywanie przez „Szukaj” i stronę
   powiązanego tagu. Nie ma szóstej pozycji nawigacji mobilnej.
4. Grupa ma osobny chronologiczny strumień z „Pokaż więcej”. To te same wpisy,
   komentarze, zdjęcia i przepisy, a nie kopie. Sortowanie po czasie publikacji
   i UUID; przypięcie starego wpisu nie wynosi go na początek.
5. Publikacja rozpoczęta w grupie używa istniejącego formularza z widocznym
   kontekstem grupy i możliwością jego usunięcia. Ogólne „Dodaj” nie dostaje
   obowiązkowego wyboru. Po publikacji własną publiczną treść można przypisać
   z jej strony do jednej grupy. Zapis wpisu i przypisania jest atomowy.
6. Autor przypisuje wyłącznie własny publiczny wpis lub własny publiczny
   przepis przez jego istniejącą reprezentację wpisu. Bez cudzych przepisów,
   pytań i wykonań jako osobnych elementów grupy w pilotażu. Nie powstaje drugi
   system komentarzy. „Ugotowałem” nadal powiadamia autora według obecnych zasad.
7. Nie zmieniamy prywatności automatycznie. Gdy wybrana treść jest prywatna,
   formularz każe usunąć przypisanie lub świadomie zmienić odbiorców. Zachowuje
   tekst, tagi i wybory po walidacji. Późniejsze ograniczenie widoczności usuwa
   treść z publicznego strumienia grupy, bez zmiany oryginału ani jego archiwum.
8. Odejście członka nie usuwa jego wpisów. Autor może je odpiąć, edytować lub
   usunąć zgodnie z istniejącą Policy. Usunięcie grupy przez założyciela oznacza
   potwierdzoną archiwizację: zamknięcie nowych publikacji i członkostw, bez
   skasowania cudzego dorobku. Twarde usunięcie to późniejsza procedura retencji.

### Co odpada i jaki koszt to usuwa

| Poza minimum | Powód i koszt, którego unikamy |
|---|---|
| Grupy prywatne i zaproszenia | Nowa granica dostępu do treści, mediów, miniatur, udostępniania, wyszukiwania i eksportów; podania, cofnięcia zaproszeń, incydenty ujawnienia |
| Samodzielne zakładanie przez każdego | Spam nazw, duplikaty, puste grupy i nowa kolejka zatwierdzania |
| Podgrupy, wielokrotne przypisanie wpisu | Dziedziczenie ról, spory o moderatora, duplikaty i kilka niezależnych ukryć tej samej treści |
| Własne regulaminy i głosowania | Interpretowanie sprzecznych zasad, odwołania od lokalnych głosowań, presja większości |
| Czat, osobne forum, galerie i kopie przepisów | Dodatkowe modele, wyszukiwanie, retencja, moderacja i koszt synchronizacji |
| Ranking, masowe zapraszanie, powiadomienie o każdym wpisie | Presja publikowania, spam i koszt wysyłek proporcjonalny do liczby członków |
| Samodzielne bany członków przez moderatora grupy | Kolejna klasa sankcji, terminy, odwołania i ponowne dołączanie; w minimum takie sprawy rozstrzyga marka |

## 4. Dane i migracje — lista przyszłych zmian, nie stan bazy

Proponowane **4 nowe tabele i 6 logicznych migracji**. Nazwy robocze.
Nie ma nowych zależności, brokera ani osobnego serwisu.

| Migracja | Zakres i reguły |
|---|---|
| M1 `groups` | UUID, `tag_id` UNIQUE FK do `tags` (RESTRICT), nazwa, opis, `founder_id` nullable FK do `users` (SET NULL), status active/archived/hidden z CHECK, daty `timestamptz`. Bez przełącznika prywatności „na przyszłość”. Założyciel to odpowiedzialny administrator grupy; NULL uruchamia obsługę osierocenia. Soft delete/archiwizacja, nie kaskada kasująca treść. |
| M2 `group_members` | PK `(group_id,user_id)`, FK do grup i użytkowników, jawne CASCADE przy ostatecznym usunięciu rodzica, `role` member/moderator z CHECK, `joined_at`. Rola założyciela wynika z M1, nie z drugiego niespójnego pola. Nie tworzymy tabeli ról. Ról nie przyjmujemy przez masowe przypisanie z formularza. |
| M3 `group_posts` | UUID dla adresowalnej relacji i FK do grupy/wpisu (RESTRICT dla historii spraw); `post_id` UNIQUE ogranicza wpis do jednej grupy w pilotażu. Status active/withdrawn/hidden z CHECK, daty i indeks grupy/statusu. Przy ponownym dodaniu używamy tej samej relacji; wycofanie nie obchodzi kary hidden. Ostateczne czyszczenie dopiero po retencji spraw. |
| M4 `group_moderation_actions` | UUID, FK do relacji `group_posts`, aktora i opcjonalnego zgłoszenia; action hide/restore, uzasadnienie, stan poprzedni, data. Relacje do aktora SET NULL, do sprawy i celu RESTRICT. Historia lokalna nie podmienia globalnego `posts.status`. Akcje na grupie jako całości wydaje marka istniejącą ścieżką. |
| M5 kontekst zgłoszeń | Nullable FK `reports.group_id`, nowy cel `group` w CHECK/mapach typów dla zgłoszenia nazwy/opisu grupy. Zgłoszenie wpisu nadal wskazuje wpis. Bez drugiego zgłoszenia na to samo kliknięcie i bez rozszerzania dostępu lokalnego moderatora do danych zgłaszającego. |
| M6 odwołania lokalne | Nullable FK `appeals.group_moderation_action_id`; dotychczasowy FK decyzji staje się nullable, CHECK dokładnie jednego źródła decyzji, osobne indeksy unikalne dla strony odwołującej się i każdej decyzji. Zachować istniejące odwołania oraz niezależność autora i zgłaszającego. |

Założyciel musi być czynnym członkiem podczas zwykłego działania grupy.
Przekazanie grupy: transakcja, blokada grupy i członkostw, weryfikacja następcy,
aktualizacja założyciela i audyt. Usunięcie/zablokowanie konta nie może blokować
realizacji praw użytkownika: grupa przechodzi do archiwum do czasu przejęcia.
Sprawdzenie ostatniego opiekuna wymaga testu współbieżności; zwykły CHECK nie
zapewni reguły obejmującej wiele wierszy i tabel.

Przypisywanie sprawdza Policy wpisu, przepisu i grupy oraz status członka w
nazwanej akcji domenowej, także gdy wywołana jest bez HTTP. Statusy kont i
blokady nadal obowiązują. Indeksy M3 wymagają EXPLAIN na rzeczywistym zapytaniu
po `posts.published_at, posts.id`; nie obiecujemy czasu odpowiedzi z samej
obecności indeksu.

Każda migracja przy implementacji: test schematu i danych, aktualizacja
`docs/DATABASE.md`, jawne FK/CHECK i opis rollbacku. Ten projekt nie dopisuje
przyszłych tabel do dokumentu opisującego **dzisiejszy stan** bazy.

**Wycofanie:** najpierw wyłączyć wejścia i zapisy grup, zachowując odczyt historii
spraw i dostęp autora do oryginału. Nie usuwać danych grup przez powrót starego
kodu. `down()` może przejść na pustych tabelach; ma odmówić, jeśli usunąłby
członkostwo, ukrycie, sprawę albo odwołanie, którego ponowne `up()` nie odtworzy.
Test odmowy i kontrola dodatnia obowiązkowe. Dla M5/M6 odtwarzać stare constraints
wyłącznie bez nowych typów/kontekstów. Backup nie zastępuje strażnika D-088.

## 5. Ekrany i miejsca integracji

| Miejsce | Zmiana i ryzyko |
|---|---|
| Strona tagu / katalog / Szukaj | Odnośnik do powiązanej grupy; lista grup alfabetyczna lub redakcyjna, nigdy ranking. Bez drugiego słownika tematów. |
| Nowa strona grupy | Opis, opiekun, stan grupy, dołącz/odejdź, publikacja, chronologiczna lista. Widoczne wyjaśnienie publiczności treści. |
| „Moje” | Własne grupy i droga odejścia; bez publicznej listy członkostw na profilu. |
| Publikacja wpisu i kreator przepisu | Opcjonalny kontekst przy wejściu z grupy, zachowanie po błędzie/autosave/logowaniu, walidacja końcowej publikacji. Bez obowiązkowego nowego kroku. |
| Karta i szczegóły wpisu/przepisu | Jedna informacja o grupie i droga do niej, odpięcie własnej treści. Ukrycie lokalne nie znika globalnie. |
| Start / FollowingFeed / TagFeed | Bez nowego źródła i bez domieszania członkostw. Publiczny wpis może być widoczny dzięki obserwowaniu autora/tagu, nie dzięki dołączeniu. Testy chronologii i braku duplikatów pozostają. |
| Profil / archiwum / zeszyt | Oryginał zachowuje autora i dostęp niezależnie od archiwizacji grupy. Nie promować członkostw ani pokazywać prywatnych metadanych. |
| Nowe zarządzanie grupą | Członkowie, nadanie/odebranie lokalnego moderatora, przekazanie grupy, archiwizacja; potwierdzenia destrukcji. |
| Lokalna moderacja | Widok zgłoszeń ograniczony do grupy, ukryj/przywróć z powodem, odnośnik do decyzji; bez panelu globalnego. |
| Panel marki i odwołania | Kontekst grupy i decyzja lokalna obok globalnej, przejęcie/archiwizacja, odwołania od lokalnych decyzji. |
| Powiadomienia, eksport i kasowanie konta | Decyzje, odwołania, przekazanie roli; eksport własnych członkostw i decyzji, anonimizacja aktora, brak kasowania cudzych treści wraz z grupą. |

Budżet UI: 4 nowe rodziny widoków (lista/własne grupy, grupa, zarządzanie,
lokalna moderacja), zmiany w 7 rodzinach istniejących ekranów z tabeli.
Formularze nadal minimum 18 px, cele 48 px, jawne etykiety, błędy przy polu i
u góry, klawiatura, 320 px i zoom 200%. Nie twierdzimy, że szkic spełnia WCAG
bez testu działającego interfejsu.

## 6. Koszt moderacyjny i prywatność

### Uprawnienia proponowane do zatwierdzenia

| Rola | Uprawnienia | Granica |
|---|---|---|
| Członek | Dodanie własnej treści, odpięcie, odejście, zgłoszenie, odwołanie | Nie edytuje cudzej treści |
| Moderator grupy | Ukrywa/przywraca powiązanie wpisu w swojej grupie, podaje powód | Nie zmienia oryginału, nie banuje konta, nie widzi adresów e-mail/IP, nie rozstrzyga odwołania |
| Założyciel | Uprawnienia lokalnego moderatora, opis, dobór opiekunów, przekazanie i archiwizacja | Nie kasuje dorobku innych, nie nadaje globalnych ról |
| Moderator marki | Globalna moderacja treści, kontrola działań grupy, eskalacja nadużycia opiekuna | Lokalne przywrócenie nie cofa globalnej sankcji |
| Administrator marki | Otwiera/przejmuje grupę i rozstrzyga odwołania zgodnie z istniejącą bramką roli | Każda zmiana audytowana; brak automatycznego przekazania „najaktywniejszemu” |

**Publiczna grupa nie jest obietnicą zamkniętej rozmowy.** Jej wpis pojawia się
poza grupą na dotychczasowych zasadach: profil, tag, obserwowani, wyszukiwanie,
zeszyt i link udostępnienia. Nadal obowiązują blokady, status autora, status
przepisu i dostęp do mediów. Sam fakt członkostwa nie daje dostępu do niczyjej
prywatnej treści. Odejście z grupy nie usuwa publicznych wypowiedzi z internetu.

Zgłoszenie trafia **raz do marki**, z kontekstem grupy; lokalny moderator ma
ograniczony widok tej sprawy. Jego decyzja nie zamyka globalnego zgłoszenia.
Dzisiejsze `moderation_actions_one_per_report` nie pozwala po prostu wpisać
obok drugiej decyzji — stąd osobna tabela decyzji lokalnych w koszcie.
Skarga na opiekuna trafia tylko do marki, bez ujawniania jej opiekunowi.
Zgłoszenia prawne pozostają w obecnej ścieżce operatora.

**Liczba kolejek:** obecne 5 kategorii w `KolejkiPanelu` pozostaje; **0 nowych
centralnych kolejek zgłoszeń**, **1 nowy rodzaj lokalnego widoku kolejki**, czyli
G ograniczonych widoków przy G grupach. Istniejąca kolejka odwołań dostaje nowy
typ decyzji. Dochodzi 1 filtr operacyjny grup bez opiekuna, bez osobnej skrzynki
wiadomości. To mniej ekranów niż dwie niezależne skrzynki, lecz więcej pracy:
jedna sprawa może wymagać zarówno lokalnej, jak i globalnej oceny.

W minimum dochodzą **3 rodziny powiadomień**: lokalna decyzja i jej cofnięcie,
obsługa odwołania lokalnego, powierzenie/odebranie odpowiedzialności za grupę.
Dołączenie, odejście i nowy wpis nie wysyłają powiadomień do całej grupy.
Nie wolno wyciszyć istniejącego „Ugotowałem”.

### Porzucona grupa

Propozycja operacyjna: cotygodniowy przegląd. Po 30 dniach braku aktywności
opiekunów marka sprawdza sytuację; sam licznik wizyt nie dowodzi zaniedbania.
Po ręcznej próbie ustalenia opieki i 14 dniach bez rozwiązania grupa przechodzi
do archiwum albo zostaje jawnie przekazana chętnemu opiekunowi. Zamknięte konto
ostatniego opiekuna powoduje niezwłoczne wstrzymanie nowych publikacji w grupie.
Oryginały i eksport autora pozostają dostępne. Terminy to propozycja do decyzji,
nie wdrożona automatyka. W tej sesji nie wysłano żadnych wiadomości.

### Godziny pracy — jawny model kosztu

Estymacja przyrostu ponad dzisiejszą moderację, nie prognoza ruchu:

`H/tydzień = (10×G + 4×R + 8×E + 20×A + 30×O) / 60`

G = liczba grup; R = lokalne oceny zgłoszeń; E = dodatkowe globalne oceny
wynikające z konfliktu grupowego; A = odwołania; O = sprawy przejęcia opieki.
Minuty 10/4/8/20/30 są założeniami planistycznymi. Nie dodajemy drugi raz
zwykłej globalnej oceny wpisu już uwzględnionej w bieżącym budżecie.

| Scenariusz | Podstawienie | Przyrost |
|---|---|---:|
| Spokojny pilotaż | G=3, R=10, E=2, A=1, O=0 | 106 min = 1,8 h/tydz. |
| Większy ruch | G=10, R=50, E=10, A=5, O=1 | 510 min = 8,5 h/tydz. |

Do tego rozruch: **2–3 h szkolenia na opiekuna** (3 grupy = 6–9 h) i
**4–8 h marki** na przećwiczenie eskalacji oraz osierocenia. Nie obejmuje to
czasu zwykłego komentowania i budowania relacji. Przy lokalnej decyzji spór
może trwać znacznie dłużej niż 4 min; po dwóch tygodniach pilotażu zastąpić
założenia zmierzonym czasem obsługi i skorygować liczbę otwartych grup.

Przed startem: przypisać zastępstwo, budżet godzin i próg zaległości zatrzymujący
otwieranie kolejnych grup. Regulamin, opis ról, odwołania i retencję zweryfikować
z osobą odpowiedzialną za prawo; dokument nie rozstrzyga nowych obowiązków prawnych.

## 7. Koszt techniczny i warianty

Osobodzień = 8 godzin pracy nad projektem, implementacją, przeglądem i testami.
Przedziały własne, oparte na liczbie granic i integracji, bez pomiaru prototypu.

| Pakiet minimum | Osobodni |
|---|---:|
| Domknięcie decyzji, scenariusze i konsultacja obsługi moderacji | 2–3 |
| 4 tabele, 6 migracji, constraints, rollback i dokumentacja | 3–5 |
| Akcje, Policy, członkostwo, role, współbieżność | 3–5 |
| Ekrany, publikacja, feed grupy, profil i wyszukiwanie | 4–6 |
| Moderacja lokalna, powiadomienia, odwołania i osierocenie | 5–8 |
| Eksport, kasowanie konta, macierz regresji, dostępność i pomiary zapytań | 6–9 |
| **Suma bez rezerwy** | **23–36** |
| **Z rezerwą 25%, zaokrąglone w górę** | **29–45** |

To około 6–9 tygodni jednej osoby przy 5 dniach pracy tygodniowo, bez oczekiwania
na badania, akceptację i kolejkę wdrożeń. Praca agentów nie znosi przeglądu ról,
odwołań i testów prywatności. Koszt pieniężny: `(29–45) × stawka za osobodzień`
plus godziny obsługi z §6; brak uzgodnionej stawki, więc nie podajemy pozornej kwoty.

| Wariant | Przyrost względem minimum | Co kupujemy |
|---|---:|---|
| Pozostać przy tagach | 0 dni implementacji grup | Nadal działa miejsce i obserwowanie, brak ról i członkostwa; koszt bieżącej redakcji pozostaje |
| Publiczne V1-minimum | 23–36 dni + rezerwa | Pełna odpowiedzialność lokalna i członkostwo |
| Prywatne grupy i zaproszenia | dodatkowo 12–20 dni przed rezerwą | Granica prywatności we wszystkich kanałach i cykl zaproszeń/podań |
| Zakładanie przez każdego | dodatkowo 4–7 dni przed rezerwą | Limity, wnioski/nadużycia nazw, duplikaty i kolejka otwierania grup |
| Wielokrotne przypisanie lub podgrupy | brak uczciwej wyceny w tym projekcie | Wymaga osobnej decyzji i macierzy dziedziczenia, nie „jeszcze jednego pola” |

Prywatny wariant wymaga osobnego przeglądu co najmniej: `PostPolicy`,
`RecipePolicy`, scope'ów list, komentarzy, `DostepDoZdjecia`, podglądów linków,
wyszukiwarki, sitemap, kolaży, liczników, zeszytów, powiadomień i cache.
Przynależność musi ograniczać również oryginał przepisu — samo schowanie
karty grupowej zostawia publiczny permalink. Wyjście/wykluczenie odbiera dostęp
innym, nie autorowi do własnego archiwum/eksportu. Zmiana grupy prywatnej na
publiczną nie może ujawniać historycznych wpisów bez decyzji ich autorów.
Zgłoszenia pozostają dostępne marce na kontrolowanej, audytowanej drodze.
Te warunki tłumaczą dodatkowy koszt; nie są gotowym projektem grup prywatnych.

Koszt infrastruktury minimum: O(G+M+P+A) rekordów dla grup, członkostw,
powiązań i decyzji; przy 3 grupach po 100 członków, 1000 powiązanych wpisach
oraz 100 decyzjach to 1403 nowe rekordy podstawowe, bez indeksów i audytu.
Nie kopiujemy zdjęć do nowych bucketów ani nie wysyłamy nowego wpisu do N osób.
Koszt zapytań i rozmiar dysku trzeba dopiero zmierzyć, podobnie jak p95;
nie obiecujemy „bezpłatnie”, ale sam model nie uzasadnia Redis ani mikroserwisu.

## 8. Co to zabiera portalowi

| Prosta ścieżka dziś | Co zabierają grupy | Jak ograniczyć stratę |
|---|---|---|
| Zdjęcie + kilka słów → publikacja | Pytanie „gdzie to dać?” i obawa przed złym wyborem | Brak obowiązkowego wyboru; kontekst tylko z grupy albo odpięcie po publikacji |
| Obserwuję osobę, widzę jej gotowanie | Druga relacja i drugi strumień | Grupy poza Startem, bez automatycznego obserwowania |
| Jeden tag opisuje treść | Konkurencja słów „tag”, „grupa”, „zeszyt” | Grupa oznacza ludzi i opiekę; tag klasyfikuje; zeszyt pozostaje własnym zapisem |
| Wiem, kto widzi wpis | Fałszywe poczucie zamkniętej rozmowy | Tylko publiczny pilotaż i jawny opis odbiorców przed publikacją |
| Jedna moderacja | Dwie decyzje o tym samym wpisie i spory z opiekunem | Lokalna decyzja dotyczy relacji z grupą, globalna treści; jedna droga odwołania do marki |
| Jedna społeczność | Rozdrobnienie małego ruchu, puste miejsca i dyżury | 2–3 grupy wynikające z aktywności tagów, bez automatycznego tworzenia |
| Powiadomienia o rozmowie i ugotowaniu | Hałas przy dołączeniach i nowych wpisach | Bez masowych powiadomień; tylko odpowiedzialność i decyzje |

W pilotażu porównać czas i powodzenie zwykłej publikacji przed/po, pytania
„gdzie kliknąć?”, udział wpisów bez odpowiedzi, aktywnych autorów i obciążenie
moderacji. Z osobami 50+ sprawdzić też „gdzie jest mój wpis po wyjściu z grupy”.
Nie uznawać liczby członków za dowód retencji.

## 9. Otwarta lista decyzji właściciela — 10 odpowiedzi przed implementacją

1. **Bramka:** jakie WAC, okres i liczebność dojrzałej kohorty wystarczą? Odczytać
   `kuking:wac` i `kuking:raport` na uprawnionym źródle produkcyjnym. `PowrotPoDniach`
   mierzy wizytę co najmniej N dni po rejestracji, nie dokładnie dzień 30;
   `CookRetentionCohorts` osobno mierzy gotowanie w tygodniu 4. Nie mieszać tych
   definicji ani nie opierać decyzji na jednej osobie i efektownym procencie.
2. **Wartość:** czy brakuje już członkostwa i lokalnej odpowiedzialności, czy
   wystarczą obecne tagi? Pierwsze kosztuje 29–45 dni, drugie nie wymaga kodu grup.
3. **Kto zakłada:** administrator z opiekunem (minimum) czy każdy (+4–7 dni
   i nowa centralna kolejka)?
4. **Prywatność:** tylko publiczne (minimum) czy także członkowskie (+12–20 dni
   i nowa granica bezpieczeństwa)? Czy ukryta lista członków odpowiada potrzebie?
5. **Relacja z tagiem i publikacja:** jedna grupa na tag, jedna na wpis, bez
   automatycznego przypisania i bez obowiązkowego selektora — czy akceptowane?
6. **Role:** czy lokalny moderator może tylko ukrywać powiązanie i przywracać,
   a sankcje wobec członków zostają u marki? Szersze uprawnienia zwiększą zakres.
7. **Spory:** czy marka bierze każde zgłoszenie i odwołanie od decyzji lokalnej,
   z opisanym rozdzieleniem historii? Bez tego nie delegować moderacji.
8. **Osierocenie i zamknięcie:** czy przyjąć ręczny przegląd 30+14 dni,
   zastępstwo oraz archiwizację zamiast skasowania cudzych wpisów?
9. **Ludzie i budżet:** kto obsłuży pilotaż, kto go zastąpi i przy jakiej
   zaległości wstrzymujemy nowe grupy? Potwierdzić szkolenie i godziny z §6.
10. **Odbiór:** jaki wynik publikacji, retencji i zaległości uznajemy za sukces,
    a jaki za powód zatrzymania? Ustalić przed pilotażem, nie po odczytaniu wyniku.

Po decyzjach: uaktualnić zakres #22 i dopiero wtedy rozpisać implementację.
Odbiór wymaga testów Policy i akcji domenowych, negatywnych kontroli nowych
strażników, współbieżności przekazania/odejścia, dwóch zakresów moderacji,
eksportu i usuwania konta, rollbacków oraz badania publikacji i dostępności.
Niniejszy dokument pozostawia te decyzje otwarte; nie wpisuje ich jako
obowiązujących do `docs/DECISIONS.md`.

## 10. Granica wykonanej pracy

Jedyna zmiana repozytorium: ten dokument. Bez kodu produkcyjnego, migracji,
tras, modeli, widoków, nowych zależności, zmian schematu produkcji, push i PR.
Lokalne testy odtwarzały wyłącznie istniejącą funkcję na własnej bazie.
Pełnego zestawu aplikacji, testów przeglądarkowych i CI nie uruchamiano: zakres
jest dokumentacyjny, a pomiar fundamentu obejmuje cztery wskazane zestawy.
`ProbaOdtworzeniaTest` nie uruchamiano; nie zgłaszamy dla niego ani sukcesu,
ani awarii. Pint (`vendor/bin/pint --test`) przeszedł: 1155 plików. Nie zmieniał formatowania.

**Commit zablokowany:** po wykonaniu pomiarów zniknął wskazany przez `.git` katalog metadanych `C:/Users/matma/Documents/Codex/kuking.pl/.git/worktrees/gpt-grupy-tematyczne`. Git uruchomiony przy repozytorium kanonicznym odnajduje inne repozytorium nadrzędne `C:/Users/matma/Documents/Codex`. Nie wykonano w nim zapisu. Właściciel potwierdził, że naprawą zajmuje się inny model; nie podejmowano naprawy ani tworzenia zastępczej gałęzi. Dokument pozostaje lokalny, bez nowego SHA.

Końcowy jawny odczyt połączenia potwierdził bazę `kuking_flota_gpt-grupy-tematyczne`, właściciela `kuking` i `127.0.0.1:55439`. Wcześniejsza pomocnicza próba tego odczytu zawiodła przez BOM przed `export`; klient próbował domyślnego gniazda 5432 i zakończył się błędem braku bazy. Nie wykonał migracji ani zapisu. Właściwe testy używały skryptu floty z jawnym portem 55439.
