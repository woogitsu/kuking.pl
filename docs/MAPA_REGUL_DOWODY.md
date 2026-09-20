# Mapa: reguła AGENTS.md → test → dowód z mutacji

Data: 2026-09-20. Gałąź: `narzedzia/kontrola-ujemna-v2`. Baza: `origin/main` `6d49ee60`.

Ten dokument odpowiada na jedno pytanie: **gdzie projekt wierzy sobie na słowo?**
Lista reguł BEZ dowodu jest tu produktem głównym. Lista reguł z dowodem jest tłem.

## Trzy różne rzeczy, które łatwo pomylić

| stopień | co znaczy | ile reguł |
|---|---|---|
| **A. dowód z mutacji** | jest test, zepsuliśmy pilnowany kod i test oblał | **11 z 75** |
| **B. jest test, brak dowodu** | test istnieje i przechodzi; nikt nie sprawdził, czy oblewa | **48 z 75** |
| **C. brak strażnika** | żaden test nie odnosi się do reguły | **16 z 75** |

Stopień B to nie jest „prawie A". Kampania mutacyjna pokazała dziewięć przypadków,
w których test o właściwej nazwie przechodził także po zepsuciu kodu.
Nazwa testu nie jest dowodem, że test czegokolwiek pilnuje.

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
| R47 | 7 | `.env` w repo, hardcoded hasło admina, wyłączanie CSRF | **bezpieczeństwo** — brak skanu repozytorium |
| R59 | 9 | AI nie generuje masowo publicznych przepisów pod SEO | |
| R60 | 10 | testy chodzą na PostgreSQL, nie na SQLite | nic nie oblewa na SQLite |
| R63 | 11 | kod po angielsku | |
| R64 | 11 | adresy URL po polsku (wyjątki `/home`, `/login`, `/register`) | |
| R66 | 11 | zakaz „content", „explore", „engage", „creator", „tapnij" | |
| R73 | 12 | anty-wzorce: streaki, punkty, rankingi, algorytmiczny feed, wyeksponowane lajki | **najszersza reguła produktowa bez strażnika** |

Trzy z tej listy uważam za pilniejsze od reszty: **R47** (bo skutkiem jest wyciek),
**R73** (bo to jest obietnica tożsamości produktu i najłatwiej ją naruszyć przypadkiem)
i **R60** (bo gdy ktoś przestawi testy na SQLite, cała reszta mapy przestaje znaczyć,
co znaczy — a nic tego nie zauważy).

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
