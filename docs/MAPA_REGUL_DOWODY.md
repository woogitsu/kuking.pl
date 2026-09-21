# Mapa: reguła AGENTS.md → test → dowód z mutacji

Data: 2026-09-20. Gałąź: `narzedzia/kontrola-ujemna-v2`. Baza: `origin/main` `6d49ee60`.

Ten dokument odpowiada na jedno pytanie: **gdzie projekt wierzy sobie na słowo?**
Lista reguł BEZ dowodu jest tu produktem głównym. Lista reguł z dowodem jest tłem.

## Trzy różne rzeczy, które łatwo pomylić

| stopień | co znaczy | ile reguł |
|---|---|---|
| **A. dowód z mutacji** | jest test, zepsuliśmy pilnowany kod i test oblał | **11 z 75** + mierzalna część R47 |
| **B. jest test, brak dowodu** | test istnieje i przechodzi; nikt nie sprawdził, czy oblewa | **48 z 75** |
| **C. brak strażnika** | żaden test nie odnosi się do reguły | **16 z 75** |

**R47 liczy się dalej w C, nie w A, i nie podbija liczby 11.** Reguła ma siedem
składników; pięć dostało 20.09 strażnika z dowodem z mutacji, dwa zostały bez
niego świadomie (rozpisane przy wierszu R47). Wiersz dziedziczy koszt najdroższego
składnika, więc przeniesienie go do A byłoby dokładnie tym fałszywym wpisem w sekcji
A, przed którym ostrzega akapit niżej. Liczba 11 zmieni się dopiero wtedy, gdy
któraś reguła będzie zamknięta w CAŁOŚCI.

Stopień B to nie jest „prawie A". Kampania mutacyjna pokazała dziewięć przypadków,
w których test o właściwej nazwie przechodził także po zepsuciu kodu.
Nazwa testu nie jest dowodem, że test czegokolwiek pilnuje.

**Najgroźniejszy błąd tej mapy nie jest w sekcji C, tylko w sekcji A.**
Luka w sekcji C jest widoczna i ktoś ją w końcu zamknie. Fałszywy wpis w sekcji A
jest niewidoczny i **cytowany jako dowód** — bo to jest ta sekcja, którą wszyscy
traktują jako pewną. Dlatego każde zastrzeżenie do wiersza z sekcji A jest tu
zapisane przy nim, a nie w przypisie; dziś dotyczy to R49.
(Obserwacja sesji `codex-45`, 20.09.2026.)

## Korekta liczby, którą podawałem wcześniej

W kampanii padło **31 zabitych mutacji**. To NIE jest 31 reguł AGENTS.md z dowodem.
Po złączeniu z mapą:

- część zabitych mutacji dowodzi obietnic z `docs/legal/` (RODO art. 15/17/20 — katalogi
  `paczka.txt` i `kasowanie.txt`, 13 mutacji), a nie reguł z AGENTS.md;
- kilka mutacji przypada na tę samą regułę (sześć mutacji w Policy → jedna reguła R49);
- kilka trafia w kod, dla którego w AGENTS.md nie ma reguły normatywnej.

**11 z 75, nie 31 z 75.** Podaję to, bo różnica zmienia ocenę stanu projektu,
a poprzednia liczba brzmiała lepiej, niż było.

## C. Reguły BEZ ŻADNEGO STRAŻNIKA — 16

Cztery z nich sprawdziłem osobno przez grep w `tests/`, żeby „brak" nie był
zaniedbaniem rozpoznania: `tailwind.config`, nazwy z zakazu overengineeringu,
`sqlite`, liczba pozycji nawigacji — zero trafień w każdym przypadku.

| # | § | reguła | uwaga |
|---|---|---|---|
| R12 | 3 | Tailwind bez `tailwind.config.js` | |
| R13 | 3 | zakaz mikroserwisów, Kafki, GraphQL, SPA, Redisa, WebSocketów, K8s, CQRS | grep po nazwach: zero trafień w `tests/` |
| R14 | 4 | kontroler cienki, reguła domenowa w `app/Domain` | |
| R15 | 4 | modularny monolit — bez osobnych pakietów z `app/Domain/*` | |
| R21 | 5 | etykieta pola zawsze widoczna; placeholder nie jest etykietą | **dostępność**, sprawdzone osobno |
| R28 | 5 | nawigacja mobilna maksymalnie 5 pozycji | testy nawigacji nie liczą pozycji |
| R35 | 6 | UUID dla encji publicznych, `timestamptz` dla czasu | |
| R36 | 6 | JSONB tylko dla danych półstrukturalnych | |
| R41 | 6 | `theme` i `posts.display_mode` świadomie bez strażnika | brak testu pilnującego, że strażnika NIE dodano |
| R47 | 7 | `.env` w repo, hardcoded hasło admina, wyłączanie CSRF | **siedem zakazów, nie trzy**; pięć ma dowód z mutacji od 20.09, w C zostają **dwa** — rozpisane niżej |
| R59 | 9 | AI nie generuje masowo publicznych przepisów pod SEO | |
| R60 | 10 | testy chodzą na PostgreSQL, nie na SQLite | nic nie oblewa na SQLite |
| R63 | 11 | kod po angielsku | |
| R64 | 11 | adresy URL po polsku (wyjątki `/home`, `/login`, `/register`) | |
| R66 | 11 | zakaz „content", „explore", „engage", „creator", „tapnij" | |
| R73 | 12 | anty-wzorce §12 — **sześć zakazów, nie jeden**; rozpisane niżej | **najszersza reguła produktowa bez strażnika** |

Trzy z tej listy uważam za pilniejsze od reszty: **R47** (bo skutkiem jest wyciek;
pięć z siedmiu jej składników zamknięte 20.09, dwa zostają tutaj),
**R73** (bo to jest obietnica tożsamości produktu i najłatwiej ją naruszyć przypadkiem)
i **R60** (bo gdy ktoś przestawi testy na SQLite, cała reszta mapy przestaje znaczyć,
co znaczy — a nic tego nie zauważy).

### R47 rozpisane: siedem zakazów, pięć mierzalnych dziś

Ten sam zabieg co przy R73 i ten sam powód: wiersz dziedziczył koszt najdroższego
składnika, więc cała reguła wyglądała na niemierzalną („skan repozytorium" brzmi
jak osobny projekt). Po rozdzieleniu pięć składników jest mierzalnych **i zmierzonych**.

| zakaz z §7 | da się zmierzyć? | czym |
|---|---|---|
| plik poświadczeń widoczny dla gita (`.env`, `auth.json`, `*.pem`, `*.key`) | **tak, mocno** | skan drzewa + reguły `.gitignore`; git niepotrzebny, bo runtime WSL nie dostaje `.git` |
| reguła `.gitignore` zdjęta po cichu | **tak, mocno** | zapadka po liście dzisiejszych reguł — bez niej skasowanie linii `.env` przechodzi na CI zawsze |
| hasło wpisane tekstem (`Hash::make('…')`, `bcrypt('…')`, `assignPassword('…')`) | **tak, mocno** | kształt WYWOŁANIA, nie słowo — `Str::random(64)` trafieniem nie jest |
| hasło tablicą w seederze, fabryce albo migracji | **tak** | tam R47 pęka najczęściej: „konto administratora do testów" |
| niepusta wartość domyślna poświadczenia (`env('X_SECRET', '…')`, `.env.example`) | **tak** | pusty ciąg trafieniem nie jest, inaczej rejestr spuchłby o siedem martwych wpisów |
| ochrona CSRF zdejmowana | **tak, mocno** | żywa lista `PreventRequestForgery::$neverVerify` równa rejestrowi, `allowSameSite`/`originOnly` na `false`, zero `withoutMiddleware` poza `bootstrap/app.php` |
| **czy POWÓD wpisu w rejestrze jest uczciwy** | **nie** | „ten adres woła serwer Facebooka" sprawdza się czytając cudze API, nie automatem |
| **poświadczenie pod nazwą, która na poświadczenie nie wygląda** | **nie** | kto zaszywa hasło omyłkowo, nazywa je jakkolwiek; grep po słowach da zielone o zerowej mocy |

**Dwa dolne zostają w sekcji C** — z tego samego powodu, dla którego zostały tam trzy
dolne zakazy R73. Asercja po samej obecności tekstu przesunęłaby wiersz z C do A,
nie zmieniając niczego w rzeczywistości, a fałszywy wpis w sekcji A jest gorszy niż
luka w C (patrz akapit o najgroźniejszym błędzie tej mapy).

Strażnik: `tests/Feature/PoswiadczeniaPozaRepozytoriumTest.php`, idiom przejęty
z `WrazliweKolumnyPozaMasowymPrzypisaniemTest` — skan odmawia domyślnie, rejestr
otwiera z powodem, `test_rejestry_nie_maja_martwych_wpisow` pilnuje drugiej strony,
a `test_skan_naprawde_czyta_kod_i_drzewo` jest kontrolą dodatnią wymaganą przez
pułapkę 2 (skan bez trafień przechodzi).

**Jeden powód jest ZMIERZONY, nie obiecany.** `DemoSeeder` zaszywa hasło konta
moderatora demo, a wpis w rejestrze tłumaczy to zdaniem „bo seeder odmawia na
produkcji". `test_seeder_z_zaszytym_haslem_odmawia_na_produkcji` odpala ten seeder
ze środowiskiem `production` i liczy konta — czyli sprawdza sam powód, a nie to,
że powód został napisany.

#### Siedem mutacji, siedem zabitych (20.09.2026, runtime WSL, baza własna)

| mutacja | co oblało |
|---|---|
| `.env.local` z sekretem w drzewie (nieobjęty `.gitignore`) | `zaden plik poswiadczen nie stoi w repozytorium` |
| skasowana linia `.env` z `.gitignore` | trzy testy, w tym kontrola dodatnia |
| `Hash::make(Str::random(64))` → `Hash::make('Admin123!')` w `TrescZalazkowaSeeder` | `zadne haslo nie jest zaszyte w kodzie` + kontrola dodatnia |
| bramka `app()->environment('production')` w `DemoSeeder` → `false` | `seeder z zaszytym haslem odmawia na produkcji` |
| dopisany czwarty adres do `validateCsrfTokens(except: …)` | `ochrona csrf nie jest wylaczana` |
| `APP_KEY=base64:…` wpisany do `.env.example` | `zadne poswiadczenie nie ma wartosci domyslnej` + kontrola dodatnia |
| `Route::post(…)->withoutMiddleware([ValidateCsrfToken::class])` w `routes/web.php` | `ochrona csrf nie jest wylaczana` |

Każda mutacja cofnięta osobno i sprawdzona przez `cmp`/`md5sum` — drzewo wróciło
bajt w bajt.

**Czego ten strażnik NIE mierzy, choć nazwa mogłaby sugerować, że mierzy:** historii
gita. Plik `.env` złożony do repozytorium i skasowany następnym commitem dalej siedzi
w historii, a skan chodzi po drzewie roboczym. Do tego trzeba osobnego narzędzia
i osobnej decyzji (rotacja sekretów, przepisanie historii) — to nie jest asercja.

### R73 rozpisane: sześć zakazów o sześciu różnych kosztach

Jeden wiersz mapy dziedziczy koszt najdroższego składnika — i dlatego R73
wyglądała na niemierzalną. Po rozdzieleniu połowa z niej jest mierzalna dziś.

| zakaz z §12 | da się zmierzyć? | czym |
|---|---|---|
| algorytmiczny feed | **tak, mocno** | żadne ORDER BY w kodzie feedu i tablicy nie kluczuje po agregacie; dziś wszystkie idą po `published_at`, `id`, `position` |
| publiczne rankingi użytkowników | **tak, mocno** | ten sam kształt, inny zbiór plików: zapytanie o użytkowników nie sortuje po agregacie ich treści |
| wyeksponowane liczniki lajków | **tak** | D-081 jest jedynym wyjątkiem i ma granice wypisane wprost; domyka R75 |
| streaki i punkty za liczbę postów | tylko po nazwie | kto doda gamifikację, nie nazwie jej `streak` |
| masowy import cudzych przepisów | tylko po nazwie | |
| sztuczne konta | tylko po nazwie | |

**Trzech dolnych nie należy pilnować gripem po słowach.** Zielone o niemal zerowej
mocy przesuwa wiersz z sekcji C do sekcji A, nie zmieniając niczego w rzeczywistości.
Lepiej, żeby zostały w sekcji C z adnotacją „mierzalne tylko przez przegląd człowieka".

**Jak zamienić „dziś nie ma rankingu" na „nikt nie doda go przypadkiem".**
Repozytorium ma na to gotowy idiom i nie trzeba go wymyślać:
`WrazliweKolumnyPozaMasowymPrzypisaniemTest` łączy skan odmawiający domyślnie,
poziom NIETYKALNE, rejestr wyjątków z powodem i `test_rejestr_nie_ma_martwych_wpisow`.
Przełożone na R73 gwarancja brzmi: **dodanie sortowania po liczbie wymaga dopisania
wiersza do rejestru z uzasadnieniem** — czyli wejścia drzwiami, a nie oknem.
Asercja nie musi przewidzieć przyszłej gamifikacji.

Dwie pułapki do wpisania w projekt, zanim ktoś zacznie pisać:
- **§2 tego rejestru** — skan, który nie znajduje żadnego pliku, przechodzi.
  Potrzebna kontrola dodatnia, że naprawdę czyta kod feedu; wzór gotowy
  w `test_skan_naprawde_czyta_modele`.
- **Bez dowodu z mutacji** strażnik wyląduje w stopniu B i mapa urośnie o wiersz,
  który nic nie znaczy. Mutacja jest tania: podmienić jedno `orderByDesc('published_at')`
  na sortowanie po agregacie i sprawdzić, że test oblewa.

Rekomendacja: budować **jeden**, nie trzy — algorytmiczny feed, bo koszt pomyłki
jest tam najwyższy, R55 pokrywa go do połowy, a rejestr raz postawiony przyjmie
dwa pozostałe bez przepisywania.

#### Pomiar na nietkniętym kodzie — i dlaczego zmienił projekt asercji

Przed napisaniem asercji przejrzałem **wszystkie 177 sortowań w `app/`**. Nic nie
sortuje publicznych treści po popularności. Ale dwa miejsca wywróciłyby asercję
w kształcie, który podałem najpierw („żadne ORDER BY nie kluczuje po agregacie"):

- **`app/Domain/Feed/DailyBoard.php:302`** — `orderByDesc('ostatnie.ostatnia_publikacja')`
  sortuje po podzapytaniu `MAX(published_at)`. To **jest** agregat i **jest** zgodne
  z regułą, bo agreguje CZAS, nie popularność.
- **`app/Domain/Search/SearchQuery.php:214`** — `word_similarity(…) DESC` sortuje
  po trafności wyszukiwania. Algorytmiczne, legalne, nie jest feedem.

**Właściwe kryterium nie brzmi „agregat kontra kolumna", tylko „co jest liczone".**
`MAX(published_at)` to czas. `COUNT(obserwujących)` to popularność. Asercja musi
zakazywać sortowania po **mierze cudzych reakcji** — wykonaniach, zapisach
w zeszytach, obserwujących, komentarzach — a nie po agregatach w ogóle.
Oba miejsca wyżej wchodzą do rejestru wyjątków z powodem, nie do zakazu.

Gdybym napisał asercję bez tego pomiaru, **strażnik urodziłby się czerwony
i wyglądałoby to na jego usterkę**, a nie na błąd w kryterium.

**Trzecie znalezisko, dokładnie tego kształtu co §5c `PULAPKI_TESTOW`.**
W `DailyBoard.php:299–301` stoi komentarz: „Sortujemy po tym, KIEDY ktoś ostatnio
coś pokazał, nie po tym, ile ma obserwujących." Reguła R73 **jest już zapisana
w kodzie** — jako komentarz przy jednym zapytaniu, nie jako asercja. Ten sam wzorzec
co lekcja o SIGPIPE przy `entrypoint-nadzor.sh`: projekt wie, zapisał to przy pliku,
i nie chroni to następnego zapytania, które napisze ktoś inny.

## B. Reguły ze strażnikiem, którego nikt nie zepsuł — 48

Wymieniam te, przy których rozpoznanie zaznaczyło, że test pokrywa **węższy zakres
niż reguła** — bo to są miejsca, gdzie stopień B jest najbardziej mylący.

| # | reguła | co test naprawdę sprawdza |
|---|---|---|
| R11 | próg wierszy sprawdzalnych w tabeli stacku | kształty wierszy, bez progu |
| R17 | przyciski ≥ 48 px | tokeny CSS i eksport, nie przegląd przycisków w widokach |
| R20 | żadna funkcja nie wymaga hover, swipe, long-press, gestu od krawędzi | tylko karta wpisu i tylko hover |
| R25 | akcja destrukcyjna potwierdzana i odsunięta | potwierdzenie bez JS; odsunięcie niepilnowane |
| R26 | 200% powiększenia i 320 px | trzy punktowe ekrany, nie przegląd |
| R27 | cel WCAG 2.2 AA | że pomiar obejmuje strony publiczne, nie pełny audyt AA |
| R33 | zmiana schematu = migracja + test + docs + rollback | docs i rollback tak; **wymóg „każda migracja ma test" — nie** |
| R34 | prawdziwe klucze obce i CHECK-i | FK tak, CHECK-i punktowo |
| R37 | wersje przepisów od pierwszego dnia | istnienie zapisu, nie wymuszenie przy każdej zmianie |
| R50 | pięć pytań: auth → authz → walidacja → limit → audyt | limit i authz; **audytu dla każdego endpointu nie pilnuje nic** |
| R51 | limity w `config/kuking.php`, nie po trasach | że limit jest, nie skąd pochodzi wartość |
| R55 | feed chronologiczny, bez rankingu | chronologia tak, zakaz rankingu nie |
| R61 | bugfix zawiera test regresyjny; test bez kontroli ujemnej nie jest dowodem | wzorzec stosowany lokalnie, nic go nie wymusza dla nowego testu |
| R65 | nazwy zdarzeń `snake_case` po angielsku | zamknięty zbiór nazw w bazie, nie konwencja |
| R67 | `kuKING` to mieszkaniec, nie komplement | dokumenty marki; widoki nieskanowane |
| R71 | zero emoji, najwyżej jeden wykrzyknik na ekran | emoji tak; wykrzyknik tylko w dokumentach |
| R75 | licznik nigdzie nie sortuje i nie ma go na tablicy dnia, w wyszukiwarce, na powitaniu | próg i obecność tak, **nieobecność nie** |

R11, R27 i R75 mają wspólny kształt: test pilnuje, że coś JEST, a reguła mówi też,
czego NIE MA BYĆ. Dowód nieobecności jest droższy i dlatego go nie napisano.

## A. Reguły z dowodem z mutacji — 11

| # | reguła | mutacja | test, który oblał |
|---|---|---|---|
| R03 | autor gotujący własny przepis nie dostaje powiadomienia | `nie powiadamiamy nikogo o jego wlasnej akcji` | `UgotowalemZawszePowiadamiaAutoraTest` |
| R04 | konto zamknięte nie dostaje powiadomienia | `granica to czy moze CZYTAC, nie czy konto jest aktywne` | `GospodarzIPowiadomieniaTest` |
| R05 | zawieszony autor powiadomienie dostaje | ta sama mutacja, druga strona granicy | `GospodarzIPowiadomieniaTest` |
| R06 | blokada w którąkolwiek stronę | `NotifyUser nie tworzy wiersza, gdy miedzy osobami jest blokada` | `PowiadomieniaOdZablokowanychTest` + `PowiadomieniaWidocznoscTest` |
| R07 | ustawienia użytkownika nie wyciszają powiadomień | `wyciszanie w oknie dotyczy wylacznie powiadomien o STANIE` | `UgotowalemZawszePowiadamiaAutoraTest` |
| R42 | `status` i `role` nigdy w `$fillable` | `status i rola nie przychodza z zadania` | `SecurityTest` + `WrazliweKolumnyPozaMasowymPrzypisaniemTest` |
| R43 | żadnego poświadczenia w `$fillable` | trzy mutacje: hasło, e-mail, stan obsługi wiadomości | `WrazliweKolumnyPozaMasowymPrzypisaniemTest` i in. |
| R49 | UUID w adresie nie jest autoryzacją | **sześć** mutacji w `app/Policies/*` + `akcja domenowa tez przechodzi przez Policy` | testy dziedzinowe, po jednym na Policy |
| R53 | widoki nie pokazują zdjęcia w stanie innym niż `ready` | `zdjecie niegotowe nie jest serwowane` | `KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest` |
| R55 | feed obserwowanych chronologicznie, treści kont nieaktywnych nie wypływają | `odkrywanie pokazuje wylacznie wpisy publiczne`, `serwis nie promuje tresci kont nieaktywnych` | `FeedTest` |
| R58 | moderacja pomocnicza — treść ukryta nie zdradza istnienia | `komentarz ukryty przez moderacje nie wraca zakresem` | `KomentarzePolicyZgadzaSieZListaTest` |
| R47 **(pięć z siedmiu składników)** | poświadczenia poza repozytorium, brak zaszytych haseł, CSRF niezdejmowany | **siedem** mutacji: `.env.local` w drzewie, zdjęta reguła `.gitignore`, `Hash::make('Admin123!')`, bramka produkcyjna `DemoSeeder` → `false`, czwarty wyjątek CSRF, `APP_KEY` w `.env.example`, `withoutMiddleware` na trasie | `PoswiadczeniaPozaRepozytoriumTest` |

**Zastrzeżenie do R47.** Ten wiersz opisuje PIĘĆ z siedmiu składników reguły i dlatego
sam wiersz R47 zostaje w sekcji C. Dwa składniki — uczciwość powodu w rejestrze
i poświadczenie pod nazwą, która na poświadczenie nie wygląda — nie mają strażnika
i mieć go nie będą bez przeglądu człowieka. Osobno: skan chodzi po drzewie roboczym,
więc **nie widzi historii gita** — sekret złożony i skasowany następnym commitem
przechodzi.

**Zastrzeżenie do R49.** Sześć zabitych mutacji dowodzi, że **ciała Policy** są pilnowane
przez testy dziedzinowe. Nie dowodzą, że `KazdaTrasaZIdentyfikatoremPodPolicyTest` —
nazwany strażnik tej reguły, ten od spisu tras — naprawdę strzeże. To osobna mutacja,
której nie zrobiłem: trzeba usunąć trasę ze spisu i sprawdzić, czy test oblewa.

## Reguła, przy której mutacja przeżyła i to było poprawne — R09, R44

- **R09** (`klucz_wyslania`, jedno wysłanie = jedno powiadomienie): mutacja przeżyła,
  werdykt **mutacja bez znaczenia** — zawężenie do kucharza jest nieosiągalne z cudzym
  kluczem, bo wyszukanie działa wyłącznie w `catch` po zadziałaniu indeksu unikalnego
  na `(user_id, klucz_wyslania)`. Nazwa, komentarz w kodzie i nazwa testu sugerowały
  lukę autoryzacyjną. Nie ma jej.
- **R44** (rejestr wyjątków `ip_hash`/`checksum_sha256`): mutacja przeżyła, bo trafione
  kolumny są w jawnym rejestrze wyjątków tego samego testu. Też bez znaczenia.

Oba wpisano do `ocena:` w katalogu. Katalog bez rozstrzygnięcia jest bezużyteczny.

## Jedna reguła do decyzji właściciela, nie do naprawy przeze mnie

Mutacja `nieopublikowany przepis nie przyjmuje wykonania` przeżyła pełny przebieg.
To **dziura w pokryciu, nie luka w autoryzacji**: moderator gotujący przepis schowany
przez moderację wyzwala powiadomienie do autora, mówiące mu, że ktoś ugotował z przepisu
niewidocznego dla nikogo poza moderacją. To pytanie produktowe. Eskalowane, nie naprawione.

## Kiedy ta mapa będzie skończona

Nie jest i nie udaje, że jest. Skończy się, gdy każdy wiersz z sekcji C ma strażnika,
a każdy wiersz z sekcji B ma dowód z mutacji. Dziś: **11 z 75**.

Do prowadzenia mapy wystarczy `scripts/mutacje.sh` z katalogiem w `tests/mutacje/`.
Przyrząd odmawia wydania werdyktu, gdy mutacja nie weszła — więc wiersz „dowód jest"
nie może tu powstać z no-opa.
