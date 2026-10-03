# Wspólne gotowanie — projekt autoryzacji i rdzenia (#2385, etap 1)

**Status:** projekt do wdrożenia w etapie 1 · 1 października 2026 · limit do trzech pomocników od 1 października 2026
**Decyzja właściciela (1.10.2026):** budujemy PEŁNĄ sesję wspólnego gotowania
(link zaproszenia, role, wspólny postęp). Wiersz w D-333 („bez nowego numeru”).
**Offline (#1904) jest wstrzymany** decyzją właściciela — sesja działa online
i NIE obiecuje pracy bez sieci. Z kryteriów zgłoszenia „offline/reconnect”
odpada więc to, co wymaga trybu offline; zostaje uczciwy komunikat przy braku
sieci (patrz „Odświeżanie”).

Ten dokument jest pisany PRZED kodem i kod go wykonuje. Tam, gdzie wymagana
jest decyzja właściciela, wybrano wariant bezpieczniejszy i zapisano pytanie
(sekcja „Pytania do właściciela”).

## 1. Czym jest sesja (i czym nie jest)

**Uzupełnienie #2485/#2486.** Lista składników w sesji pokazuje zapisany
zamiennik autora przy właściwym składniku, a lista kroków pokazuje zdjęcia
przypięte do właściwych instrukcji przez istniejący komponent `x-photo`.
Komponent podaje tylko wygenerowany wariant przez trasę z kontrolą dostępu;
gdy wariantu brak (także po odrzuceniu bez wariantów), pozostaje komunikat
bez adresu oryginału. Zdjęcia wielu kroków ładują się leniwie. Są to fragmenty
istniejącego przepisu, więc członkostwo w sesji i prawo do oglądania przepisu
muszą przejść przed renderowaniem także tych fragmentów; sesja nie zapisuje
ich kopii. Zmiana nie dodaje AI ani nowych uprawnień.

Sesja = jeden przepis, jedna osoba **gospodarz** (zakłada), do niej dołączają
**pomocnicy** (wszyscy tym samym linkiem, który wpuszcza do trzech osób) — **do trzech** (decyzja
właściciela z 1.10.2026, patrz pytanie 1). Wszyscy widzą ten sam przepis i **ten
sam wspólny postęp kroków**. Nie ma czatu, wiadomości prywatnych, rankingu,
feedu, publikacji ani powiadomień — AGENTS.md §12, zgłoszenie #2385
(„bez DM”). To nie jest grupa w rozumieniu #22 (jeden przepis, krótkie życie).

Sesja jest **prywatna dla uczestników** i **niezależna od zapisu „Ugotowałem”**:
zakończenie sesji niczego nie publikuje i nie zapisuje wykonania (wykonanie
przepisu pozostaje prywatne do osobnej publikacji — `RecordCookedEvent`,
nietknięte). Sesja nie zmienia przepisu.

Świadomie MAŁY zakres (wg „Proponowanego kierunku” ze zgłoszenia): jeden
przepis, dwie role, **gospodarz i do trzech pomocników**. Limit to
`kuking.wspolne_gotowanie.max_pomocnikow` (domyślnie 3; w etapie 1 było 1 —
zmiana jednej liczby plus to, co poniżej: limit pod blokadą wiersza sesji,
blokady między pomocnikami, widok i eksport dla wielu osób).

## 2. Kto może założyć sesję

- Zalogowane, **aktywne** konto (zawieszone czyta, nie zakłada — jak
  `CookingProgressPolicy::create`).
- Przepis, który ta osoba **widzi** wg `RecipePolicy::view` (UUID/slug nie jest
  autoryzacją). Sesja na własnym prywatnym przepisie jest dozwolona, ale
  pomocnik go nie zobaczy (sekcja 4) — ekran zakładania mówi to wprost,
  zanim powstanie link.
- Jedna sesja na parę (gospodarz, przepis) — unikalny indeks. Ponowne
  „Gotuj z kimś” zwraca istniejącą, niewygasłą sesję. Najwyżej
  `max_sesji_gospodarza` (5) naraz.
- Tylko przepis, który ma kroki (sesja jest o krokach).

## 3. Zaproszenie

- **Wielorazowy link (do 3 osób)** z losowym tokenem (40 znaków). W bazie leży wyłącznie
  **SHA-256 tokenu** (`token_hash`, jak `collection_invitations`, D-302);
  token istnieje w odpowiedzi jeden raz i nie da się go odczytać z bazy ani
  z eksportu. Kolumna jest poświadczeniem — poza `$fillable`.
- **Wygasa po 24 h** (`link_godziny`), nigdy później niż sesja; kończąc sesję
  gospodarz kasuje też zaproszenia.
  Jeden aktywny link naraz: nowy link unieważnia poprzedni.
- **Link wielorazowy do 3 osób (decyzja właściciela z 1.10.2026; zastępuje
  wcześniejsze „jeden link = jedna osoba”).** Jeden żywy link (`pending`,
  niewygasły, nieodwołany) przyjmuje kolejne osoby, aż sesja ma tylu pomocników,
  ile pozwala `max_pomocnikow` (3). Przyjęcie **nie zużywa** linku i nie zmienia
  wiersza zaproszenia. Link kończy się trzema drogami: wygasa (`link_godziny`),
  gospodarz go odwołuje, gospodarz tworzy nowy (nowy unieważnia stary; częściowy
  unikalny indeks „najwyżej jeden żywy link na sesję” zostaje). Czwartą drogą
  jest usunięcie pomocnika przez gospodarza: odwołuje żywy link, bo inaczej
  usunięta osoba wróciłaby nim od razu. Wyjście własne pomocnika linku nie rusza.
  Ryzyko, które właściciel przyjął: **każdy, kto dostanie link, może dołączyć**
  (np. link przekazany dalej albo wklejony na grupowy czat) — dlatego ekran
  gospodarza ostrzega zdaniem „Każdy, kto dostanie ten link, może dołączyć — do
  3 osób.”, a odwołanie jest zawsze pod ręką („Odwołaj link”). Mitygacje, które
  zostają: link ważny 24 h i nie dłużej niż sesja, każdy dołączający widzi przepis
  wg `RecipePolicy::view`, brak blokady z gospodarzem i z obecnymi pomocnikami,
  a gospodarz usuwa każdego. Przy pełnym komplecie strona linku mówi to samo
  zdanie co każda odmowa, a gospodarz nie utworzy nowego linku, dopóki nie
  usunie któregoś pomocnika.
- **Droga powrotna uczestnika.** Pomocnik, który zamknął kartę, nie musi mieć
  linku: wchodzi ze Startu (wiersz „Gotujesz razem: <przepis> — wróć” dla trwających
  sesji, tylko gdy przepis nadal widzi), a ponowne otwarcie linku — także
  wygasłego — przekierowuje uczestnika do sesji. Odwołany link nie ma skrótu,
  więc po odwołaniu zostaje droga przez Start. Sesja gospodarza z zamkniętym
  kontem jest dla pomocnika niewidoczna (404), zawieszenie nie.
- **Odwołanie przez gospodarza:** „Odwołaj link” (status `revoked`, kasuje
  skrót tokenu). Odwołanie nie wyrzuca osób, które już weszły. Gospodarz kończąc
  sesję kasuje też wszystkie zaproszenia.
- **Przyjęcie** dzieje się pod blokadą wiersza sesji i zamkiem pary kont (kolejność
  blokad: konta `ZamekPary`, potem sesja; zaproszenie czytane dopiero pod blokadą
  sesji). Limit, odwołanie, nowy link i przyjęcie szereguje ten sam wiersz sesji:
  dwie różne osoby przy jednym wolnym miejscu to dokładnie jedna wchodząca;
  przyjęcie stojące w kolejce za odwołaniem dostaje odmowę. Osoba, która już jest
  pomocnikiem, i klika „Dołączam” drugi raz, dostaje sukces bez skutku (tylko
  dopóki link jest żywy).
- Wejście na adres linku (GET) **niczego nie zużywa** i nie ujawnia treści
  przepisu osobie bez uprawnień (sekcja 4). Link bez dostępu = jedno zdanie:
  „Nie możesz dołączyć do tej sesji. Poproś gospodarza o nowy link.” — to samo
  zdanie dla tokenu nieznanego, wygasłego, odwołanego, przy komplecie osób i dla braku
  dostępu do przepisu (adres nie jest wyrocznią).
- Podgląd linku nie pokazuje „Dołączam”, gdy już istnieje blokada z którymś
  obecnym pomocnikiem, nawet jeśli jest wolne miejsce. Stosuje tę samą regułę
  co zapis, bez podawania tożsamości ani powodu odmowy. Gdy pomocnik odejdzie,
  sam fakt dawnej blokady nie zamyka ważnego linku. POST zawsze ponawia kontrolę
  na świeżym stanie pod blokadą sesji, bo warunki mogły się zmienić po GET.
- Limit żądań: istniejący `zaproszenia` (20/10 min) dla tras zaproszeń,
  `cooking_krok` (60/min) dla odhaczeń i odczytu stanu. Nowych kluczy limitów
  nie dokładamy.
- Odwołany wiersz zaproszenia żyje do końca sesji (to ślad „kto kiedy”),
  potem znika razem z nią.

## 4. Kto może dołączyć (autoryzacja — sedno projektu)

Przyjęcie linku wymaga **wszystkich** warunków, sprawdzanych **pod zamkiem pary
kont** (`ZamekPary`, ta sama kolejność blokad co przy zaproszeniu do zeszytu,
D-080/D-302), potem pod blokadą wiersza sesji, i na wierszach odczytanych pod
zamkiem (kolejność blokad: konta → sesja → zaproszenie, patrz „Kolejność
blokad” w sekcji 11):

1. osoba jest **zalogowana** (gość trafia na logowanie i wraca na link);
2. osoba ma **aktywne** konto (`mozeCzytac()` do podglądu, `isActive()` do
   dołączenia);
3. osoba **nie jest gospodarzem**;
4. **nie ma blokady** między osobą a gospodarzem w żadną stronę (odmowa
   tym samym zdaniem — nie zdradzamy, że ktoś kogoś zablokował);
5. osoba **widzi przepis wg `RecipePolicy::view`** (to obejmuje blokadę wobec
   AUTORA przepisu, widoczność „dla obserwujących”, konto autora zbanowane,
   stan przepisu). **Link nie daje dostępu do treści osobie bez uprawnień.**
   Prywatny przepis gospodarza = pomocnik nie dołączy (nikt poza autorem go
   nie widzi). Tytuł przepisu na stronie linku pokazujemy dopiero PO
   pozytywnym `view`;
6. sesja jest aktywna (nie zakończona, nie wygasła) i jest wolne miejsce
   (policzone pod blokadą wiersza sesji, na świeżym odczycie);
7. **osoba nie ma blokady z żadnym z obecnych pomocników** (w żadną stronę) —
   zablokowani wzajemnie nie siedzą w jednej sesji. Odmowa tym samym zdaniem
   co każda inna; nie zdradza, kogo ktoś zablokował.

**Dostęp jest sprawdzany przy każdym żądaniu, nie tylko przy dołączeniu.**
Ekran sesji, odhaczenie i odczyt stanu przechodzą przez `CookingSessionPolicy`
(członek sesji) **oraz** `RecipePolicy::view` dla oglądającego. Gdy autor
zmieni widoczność przepisu na prywatną, zablokuje pomocnika albo przepis
zniknie — pomocnik traci treść natychmiast (widzi komunikat bez treści
przepisu). Wyjątek: gospodarz zawsze musi widzieć przepis (inaczej sesja jest
martwa i zostaje sprzątnięta przy wygaśnięciu).

Osoba niebędąca członkiem sesji dostaje **404** (jakby sesji nie było), także
zalogowana i także gospodarz innej sesji — `denyAsNotFound`. UUID sesji w
adresie niczego nie otwiera.

## 5. Role i uprawnienia

| Akcja | Gospodarz | Pomocnik |
|---|---|---|
| Zobaczyć przepis i postęp (jeśli widzi przepis) | tak | tak |
| Odhaczyć / cofnąć krok | tak | tak (jeśli aktywne konto) |
| Minutniki | lokalne w przeglądarce każdej osoby (D-333/#2016 — minutniki nie są synchronizowane); nie ma uprawnień do różnicowania | j.w. |
| „Zacznij od początku” (wyczyść odhaczenia) | tak | nie |
| Utworzyć / odwołać link | tak | nie |
| Usunąć pomocnika | tak | — |
| Wyjść z sesji | — (kończy sesję) | tak |
| Zakończyć sesję (kasuje dane) | tak | nie |
| Zobaczyć pozostałych uczestników i kto odhaczył krok | tak | tak |

Pomocnicy mają te same prawa między sobą: każdy może odhaczyć i cofnąć każdy
krok (także odhaczony przez kogoś innego — to wspólna lista, nie rejestr
własności), a żaden nie zarządza pozostałymi.

**Zawieszenie a wyjście (#2889).** Pomocnik może opuścić istniejącą sesję,
a gospodarz ją zakończyć także podczas zawieszenia czasowego albo
bezterminowego. W HTTP są to wyłącznie dwa nazwane wyjątki bramki konta:
`wspolne-gotowanie.leave` i `wspolne-gotowanie.destroy`. Rolę nadal sprawdza
`CookingSessionPolicy`; obca osoba i niewłaściwa rola dostają neutralne 404.
Wyjście usuwa tylko udział pomocnika i podnosi rewizję o jeden, zachowując
odhaczenia, ich podpisy oraz istniejący link. Zakończenie kasuje tylko dane
tej sesji z jej odhaczeniami, udziałami i linkami. Zawieszenie nadal blokuje
zapis i cofanie kroków, czyszczenie postępu oraz tworzenie i odwoływanie
zaproszeń. Zamknięte konto (`banned`, `pending_delete`, `erased`) nie dostaje
tych wyjątków. Regresję mierzy pełny HTTP w
`ZawieszoneKontoOpuszczaWspolneGotowanieTest`, z rzeczywistym `User::suspend()`
i pełnym porównaniem danych oraz rewizji.

Dlaczego pomocnik może odhaczać: to cały sens „pomagam”. Dlaczego nie może
wyczyścić postępu: jedno przypadkowe kliknięcie nie powinno kasować pracy
dwóch osób. Odhaczenie jest **ustawieniem** („ten krok: zrobiony/nie”), nie
przełączeniem — idempotentne.

Przypisywanie kroków i składników do osób (z treści zgłoszenia) **nie wchodzi
do etapu 1**; wchodzi „kto odhaczył” (audyt), a przypisanie jest propozycją na
etap 2 (pytanie 3).

## 6. Wspólny postęp i odświeżanie

- Odhaczenie, cofnięcie i „Zacznij od początku” wymagają aktywnego konta
  odczytanego pod dotychczasowym zamkiem `FOR KEY SHARE`, przed zamkiem sesji.
  Publiczne `User::suspend()` bierze `FOR UPDATE` konta, więc zawieszenie
  zatwierdzone przed tym odczytem powoduje odmowę bez zmiany kroków i rewizji
  (#2879). W odwrotnej kolejności zapis kończy się przed zawieszeniem.
  Wstępna Policy na starym modelu nie zastępuje tego sprawdzenia. Nie jest to
  obietnica serializacji z dowolnym gołym `UPDATE status` (`NO KEY UPDATE`
  jest zgodne z `KEY SHARE`), tylko z rzeczywistą publiczną akcją zawieszenia.
- Stan: tabela `cooking_session_steps` — jeden wiersz = „ten krok jest
  zrobiony”, klucz główny `(session_id, step_id)`. Zapis `INSERT … ON CONFLICT
  DO NOTHING`, więc **dwa równoczesne odhaczenia tego samego kroku dają jeden
  wiersz i żadnego błędu**; cofnięcie to `DELETE`. Kolumny `done_by_id` i
  `done_at` to **audyt** („Zrobiła Basia, 18:42”). Krok usunięty z przepisu
  znika razem z wierszem (klucz obcy).
- `revision` sesji rośnie o 1 przy każdej **realnej** zmianie (odhaczenie
  już odhaczonego nie zmienia niczego i rewizji nie podnosi). To jest to, co
  widzi druga osoba, żeby zauważyć zmianę. Formularz niesie rewizję, którą
  osoba widziała; przy rozbieżności dostaje **jedno zdanie** („Ktoś z sesji
  zmienił postęp. Widzisz teraz jego aktualny stan, a Twoje kliknięcie zostało
  zapisane.”) — jak w #2016. Rewizja rośnie przy zmianie od KAŻDEJ osoby
  (odhaczenie, cofnięcie, dołączenie, wyjście, usunięcie pomocnika, zmiana
  składu przez blokadę), więc przy wielu osobach pas „Odśwież” pokazuje się
  po zmianie od któregokolwiek z pozostałych.
- **Bez WebSocketów i bez `wire:poll`** (AGENTS.md §3). Dwie ścieżki:
  1. **Przycisk „Odśwież”** (zwykły link, działa bez JS) — zawsze widoczny.
  2. **Krótki polling tylko jako ulepszenie**: istniejący skrypt
     `postep-gotowania.js` (ten sam co w #2016) co 30 s, tylko gdy karta jest
     widoczna, pyta `GET /gotowanie-razem/{cookingSession}/stan` o samą rewizję (JSON, jedno zapytanie po
     kluczu) i, gdy jest inna, pokazuje pas „Ktoś z sesji zmienił postęp —
     Odśwież”; po pokazaniu pasa przestaje pytać. **Nie przeładowuje strony
     sam** (gotujący ma brudne ręce i czyta krok; niespodziewane
     przeładowanie jest gorsze od pasa). Koszt: do czterech osób (gospodarz + 3) × 2 żądania/min ×
     czas gotowania (1–2 h) ≈ 480–960 lekkich żądań na sesję; limit
     `cooking_krok` 60/min jest szeroko powyżej. Uzasadnienie (AGENTS.md §3 wymaga pomiaru kosztu):
     bez pasa wspólny postęp bywa nieaktualny aż do ręcznego odświeżenia, a
     to jest istota funkcji; bez JS zostaje przycisk. Pomiar wdrożeniowy:
     `http_requests` dla trasy `wspolne-gotowanie.stan`.
  Błąd sieci przy pytaniu o rewizję jest po cichu pomijany (tak jak w #2016);
  odhaczenie wykonuje się zwykłym formularzem, więc przeglądarka sama zgłosi
  brak połączenia. **Nie** kolejkujemy odhaczeń offline i nie pokazujemy stanu
  „niesynchronizowane” (#1904 wstrzymany).

## 7. Retencja

- Sesja ważna **24 h od założenia** (`retencja_godziny`), stały termin
  (bez przedłużania „ruchem” — sesja nie staje się trwałą historią konta).
- Po terminie: wygasła sesja jest **niewidoczna** (odczyt ignoruje ją, 404/
  komunikat), a nocne `kuking:sprzataj-wspolne-gotowanie` **kasuje** sesję
  z odhaczeniami, pomocnikami i zaproszeniami. Odczyt nie zależy od tego, że
  harmonogram żyje (jak `cooking_progress`).
- **Zakończenie przez gospodarza kasuje sesję od razu**, w jednej
  transakcji (wiersze potomne znikają kluczem obcym). Pomocnik wchodzący potem
  na adres dostaje 404 / komunikat „Tej sesji już nie ma”. Dostęp ginie w
  chwili kasowania — nie ma stanu „zakończona, ale jeszcze czytelna”.
- Odejście pomocnika / usunięcie go przez gospodarza kasuje jego wiersz
  uczestnika; **odhaczenia zostają** (to praca w gotowaniu), ale tracą
  podpis (`done_by_id = NULL`) tylko przy usunięciu KONTA; przy zwykłym
  wyjściu zachowują podpis do końca sesji (sesja trwa najwyżej 24 h).

## 8. Blokada, usunięcie konta, eksport, wymazanie

- **Blokada** w którąkolwiek stronę między gospodarzem a pomocnikiem kończy
  udział pomocnika natychmiast (kontrakt `Users` → implementacja w `Recipes`,
  wołane przez `BlockUser` pod zamkiem pary — tak jak `KoniecWspolnychZeszytow`,
  graf modułów bez cykli #971). Przyjęcie linku wyścigujące się z blokadą jest
  szeregowane wierszem konta osoby, która jest stroną obu operacji.
- **Blokada między dwoma pomocnikami tej samej sesji: wypada ZABLOKOWANY,
  blokujący zostaje** (rozstrzygnięte przy wielu pomocnikach). Powody: osoba,
  która się zabezpiecza, nie traci przez to sesji; zablokowany widzi to samo,
  co przy usunięciu przez gospodarza („Nie jesteś już uczestnikiem tej sesji”,
  bez słowa o blokadzie), więc nie dostaje sygnału, że ktoś go zablokował;
  blokujący nie musi niczego wybierać. Odhaczenia zablokowanego zostają do
  końca sesji (to praca w gotowaniu). Rewizja sesji rośnie, pozostali widzą
  zmianę składu. Wariant „wypada ten, kto zablokował” odrzucony: karałby
  osobę, która właśnie się chroni. Wariant „wypada, kto dołączył później”
  odrzucony: bywa, że wypadłby blokujący. Blokada osoby spoza sesji niczego
  w niej nie rusza.
- **Nowe przyjęcie po blokadzie:** osoba zablokowana (w którąkolwiek stronę)
  z którymkolwiek obecnym pomocnikiem dostaje odmowę (warunek 7, sekcja 4).
- **Usunięcie konta** (każdy zakres): sesje gospodarza znikają w całości;
  udziały pomocnika znikają; `done_by_id` tej osoby w cudzych sesjach
  → `NULL` („osoba, która usunęła konto”); pozostali pomocnicy i ich podpisy
  zostają bez zmian. Wymazanie gospodarza kończy sesję ze wszystkimi pomocnikami.
- **Eksport (RODO art. 15):** sekcja `wspolne_gotowanie` w `dane.json` — sesje
  niewygasłe, w których osoba jest gospodarzem lub pomocnikiem: rola, tytuł
  przepisu (tylko gdy osoba go widzi — jak w `postep_gotowania`), numery
  kroków odhaczonych PRZEZ TĘ OSOBĘ, termin ważności. Bez tokenów i skrótów,
  bez danych pozostałych uczestników — przy trzech pomocnikach każda paczka
  zawiera tylko własne odhaczenia, a nazwy gospodarza i pozostałych pomocników
  są widoczne na ekranie sesji, ale nie są wydawane w paczce (to ich dane;
  pilnuje tego `WspolneGotowanieWieluPomocnikowTest`).
  Wpisy w `InwentarzDanychKonta` dla każdej kolumny wskazującej na konto.
- **Wymazanie:** kończenie sesji i „wyjdź” są jawnymi akcjami na ekranie;
  nie ma osobnego „wymaż wszystko” — sesja i tak żyje najwyżej 24 h.

## 9. Prywatność i zgodność

- Dane: identyfikator osoby, rola, który krok zrobiła i kiedy — **do 24 h**
  (albo do zakończenia). Brak adresu IP, brak treści wpisanych przez
  użytkownika (nie ma pól tekstowych), brak lokalizacji.
- Podstawa: wykonanie usługi, którą osoba świadomie uruchomiła (art. 6 ust. 1
  lit. b). Brak profilowania, brak zgód, brak ciasteczek. Polityka
  prywatności dostaje drobną poprawkę bieżącej wersji (nowa kategoria danych
  i jej retencja), bez nowej wersji z paskiem — decyzja koordynatora w
  zleceniu; patrz pytanie 5.
- Uczestnicy widzą nawzajem: nazwę wyświetlaną/konta gospodarza i wszystkich
  pomocników (także pomocnik pozostałych pomocników — to wspólny ekran) oraz
  „kto zrobił krok”. Nic ponad to. Strona linku mówi to przyszłemu pomocnikowi
  przed kliknięciem „Dołączam”. Sesja nie pojawia się
  w profilu, w feedzie, w wyszukiwarce ani w statystykach publicznych.
- `X-Robots`/`noindex` i `Cache-Control: private` na ekranach sesji; token w
  adresie linku: `Referrer-Policy: no-referrer` na stronie linku (token nie
  wycieka do zewnętrznych zasobów).
- Wgląd moderatora: brak. Sesja nie jest treścią do moderacji; moderator nie
  ma drogi do jej ekranu (`CookingSessionPolicy` bez wyjątku dla ról).

## 10. Model danych (skrót; szczegóły w `docs/DATABASE.md`)

- `cooking_sessions` — `id`, `recipe_id` (FK, cascade), `host_id` (FK,
  cascade), `status` (pole sterujące: `active`), `revision`, `expires_at`,
  znaczniki. Unikalny `(host_id, recipe_id)`.
- `cooking_session_participants` — `(session_id, user_id)` klucz główny,
  `role` (CHECK = `helper`), `joined_at`. Gospodarz jest w `host_id`, nie tu.
- `cooking_session_steps` — `(session_id, step_id)` klucz główny, `done_by_id`
  (FK `ON DELETE SET NULL`), `done_at`.
- `cooking_session_invitations` — `id`, `session_id` (FK, cascade),
  `token_hash` (częściowy unikalny indeks), `status`
  (`pending|accepted|revoked`), `expires_at`, `accepted_by_id`
  (FK `SET NULL`), `responded_at`. Żywy (`pending`) link ma skrót, odwołany go
  nie ma (CHECK). Od decyzji z 1.10.2026 o linku wielorazowym `accepted` i
  `accepted_by_id` nie są ustawiane (nieużywane pozostałości, bez migracji).
- Pola sterujące i poświadczenia (`status`, `role`, `token_hash`, klucze
  osób, `revision`, `expires_at`) **nigdy w `$fillable`** (wszystkie modele
  mają pusty `$fillable`; zapis przez nazwane akcje).
- Rollback (D-088): `down()` odmawia, gdy istnieje niewygasła sesja
  (decyzje ludzi w toku), wymuszenie `KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1`;
  na świeżej bazie i przy samych wygasłych sesjach przechodzi bez pytania.

## 11. Warstwy

`App\Domain\Recipes\Gotowanie\Wspolne\` — `ZalozWspolneGotowanie`,
`ZaprosDoWspolnegoGotowania`, `DolaczDoWspolnegoGotowania`,
`PostepWspolnegoGotowania`, `KoniecWspolnegoGotowania` (impl. kontraktu z
`Users`), `SprzatanieWspolnegoGotowania`. Kontroler cienki:
`WspolneGotowanieController`. Polityka: `CookingSessionPolicy`. Każdy zapis
pod `lockForUpdate` wiersza sesji; zamek pary kont przy dołączaniu.

### Kolejność blokad (wiele osób, wiele wyścigów)

Jedna kolejność we wszystkich akcjach sesji: **konta → wiersz sesji →
(zaproszenie, udziały, odhaczenia)**.

- Przyjęcie linku: `ZamekPary(osoba, gospodarz)` (konta `FOR UPDATE` rosnąco po
  id), potem wiersz sesji, dopiero potem odczyt zaproszenia. Wiersz sesji jest
  JEDYNYM miejscem, które szereguje limit pomocników, tworzenie i odwołanie
  linku oraz przyjęcie; zaproszenia nie blokujemy osobno (blokada zaproszenia
  przed sesją zakleszczała się z gospodarzem unieważniającym ten link pod
  blokadą sesji — test na dwóch połączeniach).
- Zamek pary NIE wystarcza do limitu i rewizji: choć gospodarz jest wspólny dla
  każdej pary (osoba, gospodarz) i jego wiersz konta szereguje dwa przyjęcia,
  odczyt rewizji i liczby pomocników ma być świeży pod blokadą sesji — inaczej
  przyjęcie gubiłoby zmianę rewizji zrobioną równocześnie przez odhaczenie
  (które zamka pary nie bierze).
- Odhaczenie ma klucz obcy `done_by_id → users`, więc potrzebuje wiersza konta
  osoby (`KEY SHARE`), który koliduje z cudzym `FOR UPDATE` z zamka pary.
  Dlatego odhaczenie najpierw bierze `KEY SHARE` na koncie odhaczającego,
  potem wiersz sesji. Bez tego gospodarz odhaczający krok w chwili, gdy ktoś
  dołącza do jego sesji albo gdy blokuje pomocnika, zakleszczał się (40P01) —
  błąd istniał już przy jednym pomocniku i wyszedł dopiero w teście wyścigu.
- Blokada (`KoniecWspolnegoGotowaniaImpl::miedzy`): pod zamkiem pary kont
  wybiera sesje, w których strony się spotykają, blokuje je rosnąco po id,
  dopiero potem rusza udziały.

## 12. Testy (z kontrolą ujemną)

Token cudzy / nieznany / wygasły / odwołany / komplet osób w sesji; przepis prywatny
(link nie otwiera, tytuł się nie ujawnia); przepis „dla obserwujących” bez
obserwowania; blokada gospodarz↔pomocnik przed i po dołączeniu; zawieszone
konto (czyta, nie odhacza); nie-członek = 404 dla ekranu, odhaczenia i
stanu; pomocnik nie wyczyści, nie zaprosi, nie zakończy; wyścig dwóch
odhaczeń tego samego kroku (grupa `dwa-polaczenia`), dwóch przyjęć
jednego linku, dwóch RÓŻNYCH osób przy jednym wolnym miejscu (wchodzi
dokładnie jedna), przyjęcia linku z równoczesnym odhaczeniem (rewizja rośnie o
dwa), nowego linku gospodarza z przyjęciem starego (bez zakleszczenia) i
odhaczenia z równoczesną blokadą pomocnika przez gospodarza; do trzech
pomocników: limit, blokady w obie strony i między pomocnikami, widok, eksport
i wymazanie każdego z uczestników (`WspolneGotowanieWieluPomocnikowTest`); wygaśnięcie i sprzątanie; wymazanie konta; eksport; migracja
(schemat, CHECK-i, rollback odmawia/przechodzi).

## Pytania do właściciela (z wybranym bezpieczniejszym wariantem domyślnym)

1. **Ile osób?** ROZSTRZYGNIĘTE (decyzja właściciela z 1.10.2026): gospodarz i
   **do trzech pomocników** (`max_pomocnikow` = 3). **Link: ROZSTRZYGNIĘTE
   (decyzja właściciela z 1.10.2026) — wielorazowy, wpuszcza do 3 osób**
   (sekcja 3), zamiast „jeden link = jedna osoba”; blokada między pomocnikami —
   wypada zablokowany (sekcja 8). Pierwotnie wybrano jednego pomocnika jako
   wariant bezpieczniejszy.
2. **Wersja przepisu.** Zgłoszenie mówi i „konkretna wersja”, i „aktualna
   wersja”. Wybrano **aktualną** (jak tryb gotowania i #2016; krok usunięty z
   przepisu wypada z sesji). Czy sesja ma zamrażać wersję z chwili startu?
3. **Przypisywanie kroków i składników** do osób — poza etapem 1 (dużo pojęć
   na ekranie 50+). Czy budować w etapie 2, czy zostawić samo „kto zrobił”?
4. **Powiadomienie gospodarza o dołączeniu pomocnika.** Wybrano: **brak**
   (gospodarz widzi to na ekranie sesji; brak nowego typu powiadomienia i
   kanału). Czy chcesz powiadomienie w serwisie?
5. **Polityka prywatności.** Wybrano drobną poprawkę bieżącej wersji (zdanie
   w tabeli kategorii: sesja do 24 h) bez nowego numeru wersji i bez paska.
   Czy to wystarczy prawnie, czy ma być wersja „istotna” (pasek, D-327)?
6. **Czas życia.** Stałe 24 h od założenia. Czy sesja ma się przedłużać
   przy aktywności (kosztem dłuższego przechowywania)?
7. **Sesja na prywatnym przepisie gospodarza.** Wybrano: dozwolone, ale
   pomocnik nie dołączy (przepis niewidoczny dla niego). Czy ma istnieć
   świadome „pokaż ten prywatny przepis pomocnikowi na czas sesji”? (Nie
   budujemy: to inna zasada widoczności niż `RecipePolicy`, wymaga osobnej
   decyzji.)
