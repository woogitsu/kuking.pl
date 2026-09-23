# Mapa numerów decyzji D-xxx — wszystkie gałęzie

Stan na 21.09.2026. `origin/main` = `f56f97f0` (141 gałęzi zdalnych + 2 lokalne
jeszcze niepchnięte). Metoda: liczone są wyłącznie **nadane nagłówki** `## D-xxx`
dodane przez gałąź wobec `origin/main` (linia `+## D-xxx ...`), nie każde
wystąpienie numeru w tekście. Narzędzie: `_wspolne/kolizje-w-kolejce.sh`
(uruchomione dla wszystkich gałęzi zdalnych, plus ręcznie dołożone
`flota/prog-postgresa` i `flota/kontrakt-nazw-baz`, które są tylko lokalnie
w repozytorium kanonicznym — jeszcze nie na origin).

**Poprawka wykryta przy tym przebiegu (opisana w sekcji "Nowe znalezisko —
błąd ekstrakcji" niżej):** samo dopasowanie linii `+## D-xxx` nie wystarczy —
trzeba wziąć numer WYŁĄCZNIE z pozycji nagłówka, nie każdy numer wspomniany
w tej samej linii tytułu (np. odsyłacz „... + D-224 ...” w tytule D-225).
Bez tej dodatkowej poprawki narzędzie zgłosiłoby fałszywą kolizję D-224.

## 1. Numery zajęte na `main` (stan wyjściowy)

D-204 … D-222, D-224. **D-223 to celowa dziura** — zarezerwowana dla rodziny
kaskady, nie ma jeszcze nagłówka na `main`. Brak duplikatów nagłówków na `main`
(sprawdzone).

## 2. Kolizje — numer nadany przez więcej niż jedną gałąź

### D-223 (6 gałęzi — WIĘCEJ niż znane 5)

| gałąź | odwołań w kodzie | uwagi |
|---|---:|---|
| `flota/martwe-kaskady` | **16** | najmłodsza w linii rodowej niżej |
| `gpt-n1-powiadomienia` | 13 | bliźniak z `notyfikacja-zywa` (patrz niżej) |
| `notyfikacja-zywa` | 12 | bliźniak z `gpt-n1-powiadomienia` |
| `robota/kaskada-straznik` | 11 | przodek `flota/scal-915` i `flota/martwe-kaskady` |
| `flota/scal-915` | 11 | potomek `robota/kaskada-straznik`, przodek `flota/martwe-kaskady` |
| `praca/izolacja-bazy-klon` | 2 | **NIEŻYWA/KONFLIKTOWA** — patrz uwaga niżej |

**Rodowód, nie trzy niezależne roszczenia:** `robota/kaskada-straznik` →
`flota/scal-915` → `flota/martwe-kaskady` to jeden łańcuch przodek→potomek
(`git merge-base --is-ancestor` potwierdzone w obu kierunkach dla każdej pary
sąsiedniej). Realny spór o D-223 to więc **3 niezależne strony**:
kaskada (reprezentant `flota/martwe-kaskady`, 16) kontra
`gpt-n1-powiadomienia` (13) kontra `notyfikacja-zywa` (12) — to zgadza się
z zapisem w `KOLEJNOSC_SCALANIA.md` §h) „kaskada 16 kontra para powiadomień
13/12”. `gpt-n1-powiadomienia` i `notyfikacja-zywa` NIE są w relacji
przodek/potomek (sprawdzone) — to niezależne, bliźniacze zgłoszenia tej samej
funkcji (zgodne z opisaną gdzie indziej „miną migracyjną” między nimi).

`praca/izolacja-bazy-klon`: `git merge-tree` zgłasza **realne konflikty
treści** wobec `main` (m.in. `tests/Unit/NazwaTestowejBazyTest.php`,
`tests/bootstrap.php`) i diff wobec `main` kasuje 381 linii `docs/DECISIONS.md`
(165 plików, -14358/+2998 ogółem) — gałąź jest daleko w tyle za `main`
i wygląda na porzuconą/nieaktualną próbę tego samego tematu co
`flota/kontrakt-nazw-baz`. Ta sama gałąź **nadpisuje też istniejący na `main`
D-224** innym tekstem (patrz sekcja 3) — kolejny sygnał, że to stara gałąź
licząca numery od zera, a nie aktywny kandydat do scalenia.

**Propozycja:** numer zostaje przy `flota/martwe-kaskady` (16, już
potwierdzone przez właściciela). `gpt-n1-powiadomienia` i `notyfikacja-zywa`
przenumerować — dopóki nie wiadomo, która z bliźniaczych gałęzi w ogóle
przetrwa (mina migracyjna), obie i tak nie mogą scalić się jako D-223.
`praca/izolacja-bazy-klon` wymaga osobnej decyzji właściciela (żywa czy
martwa), zanim jej numer w ogóle ma znaczenie.

### D-225 (5 gałęzi — WIĘCEJ niż znane 4, NOWE ZNALEZISKO zmienia rachunek)

| gałąź | odwołań w kodzie | uwagi |
|---|---:|---|
| `naprawa/ci-hybryda-runnerow` | **28** | **NOWA gałąź w tej kolizji — nie było jej na liście zlecenia** |
| `jedna-droga` | 22 | tu właściciel już zdecydował, że numer zostaje |
| `fix/732-wspolny-licznik-poczty` | 2 | patrz też D-226 |
| `flota/scal-786` | 2 | ma i tak przejść na D-228 (patrz niżej) |
| `gpt-cloudflare-cache` | 1 | ma zero odwołań do numeru w innych plikach niż `docs/DECISIONS.md` |

**To zmienia obraz z `KOLEJNOSC_SCALANIA.md` §g):** decyzja właściciela
(„numer zostaje przy `jedna-droga`, 22 odwołania”) została podjęta, **zanim
`naprawa/ci-hybryda-runnerow` (28 odwołań) w ogóle wypłynęła** jako pretendent
do D-225. Wg czystego kryterium „więcej odwołań” to `naprawa/ci-hybryda-runnerow`
powinna dziś zatrzymać numer, nie `jedna-droga`. `git merge-tree` potwierdza,
że ta gałąź realnie coś wnosi (drzewo różni się od `main`) — to nie jest pusta
gałąź ani duch. Diff wobec `main` jest duży i głównie ujemny (-7989/+1054,
83 pliki), ale to miara odległości, nie wkładu (patrz §i w
`KOLEJNOSC_SCALANIA.md`) — gałąź jest po prostu daleko w tyle za obecnym
`main` w innych sprawach, co nie unieważnia jej D-225.

**Propozycja:** NIE zgaduję, kto wygrywa — to sprzeczne z już podjętą decyzją
właściciela. **Zgłaszam do ponownej decyzji właściciela**: albo (a) trzymać się
raz podjętej decyzji dla `jedna-droga` mimo słabszej liczby odwołań, albo
(b) zastosować kryterium konsekwentnie i oddać numer `naprawa/ci-hybryda-runnerow`.
Pozostałe trzy (`fix/732…`, `flota/scal-786`, `gpt-cloudflare-cache`) i tak
przenumerowują się niezależnie od tego rozstrzygnięcia — żadna nie ma więcej niż 2.

### D-226 (3 gałęzie — REMIS, zgodnie z zapowiedzią zlecenia)

| gałąź | odwołań w kodzie |
|---|---:|
| `fix/732-wspolny-licznik-poczty` | 1 |
| `flota/kontrakt-nazw-baz` | 1 |
| `flota/scal-786` | 1 |

**Remis — nie zgaduję.** Trzy identyczne liczby odwołań (1, 1, 1) to dokładnie
ten wzorzec fałszywej symetrii, przed którym ostrzega `KOLEJNOSC_SCALANIA.md`
§h) — ale tu każda z trzech osobno sprawdzonych gałęzi rzeczywiście dodaje
własny nagłówek `## D-226` z innym tematem:
- `fix/732-wspolny-licznik-poczty` → „Droga zgłoszenia z DSA art. 16 ma
  obejście, a nie wyższy próg”,
- `flota/kontrakt-nazw-baz` → „Przyrządy pomiarowe rozpoznają rodzinę baz,
  nie pojedyncze nazwy” (to ta sama treść co D-225 na `flota/scal-786` —
  `flota/kontrakt-nazw-baz` to już przenumerowana wersja `flota/scal-786`,
  zgodnie z zapisem w zleceniu: D-225→D-228, ale D-226 tam **zostało bez
  zmiany numeru**, stąd nadal koliduje),
  # ^ innymi słowy: `flota/scal-786` i `flota/kontrakt-nazw-baz` to w
  # praktyce ta sama linia pracy na dwóch stanowiskach (jedna przed,
  # druga po części przenumerowania) — realny spór o D-226 jest więc
  # między *dwiema* stronami: {`flota/scal-786` / `flota/kontrakt-nazw-baz`}
  # kontra `fix/732-wspolny-licznik-poczty`, obie po 1 odwołaniu.
- `flota/scal-786` → identyczny temat co `flota/kontrakt-nazw-baz` (patrz wyżej).

Ponieważ `flota/scal-786` i `flota/kontrakt-nazw-baz` niosą **tę samą treść**
D-226 (jedna gałąź to ewolucja drugiej), realny spór sprowadza się do dwóch
stron z identyczną liczbą odwołań (1 kontra 1) — **prawdziwy remis, zgłaszam
do decyzji właściciela**, nie zgaduję.

## 3. Osobny przypadek: D-224 nadpisane, nie zdublowane

`praca/izolacja-bazy-klon` nie „nadaje” nowego D-224 obok istniejącego —
**usuwa** istniejący nagłówek D-224 z `main` i wstawia w to miejsce inny tekst
pod tym samym numerem (`git diff` pokazuje `-## D-224 — Wpis wychodzi z
zeszytu...` / `+## D-224 — Przyrządy pomiarowe...`). To nie jest kolizja
dwóch gałęzi nadających ten sam wolny numer — to gałąź licząca numerację od
stanu `main` sprzed dodania obecnego D-224, w dodatku z realnymi konfliktami
scalenia (patrz sekcja D-223 wyżej). Traktuję to jako sygnał, że cała gałąź
wymaga osobnej weryfikacji (żywa/martwa), nie jako pozycję do rozstrzygnięcia
kryterium liczby odwołań.

## 4. Numery bezsporne w tej turze (zgodnie z zleceniem, potwierdzone)

- **D-227** — wyłącznie `flota/prog-postgresa` (lokalnie, nie na origin).
- **D-228** — wyłącznie `flota/kontrakt-nazw-baz`.
- Cztery pojedyncze, niesporne numery na innych gałęziach: `D-066`
  (`claude/teksty-sciezki-uzytkownika`), `D-070`
  (`claude/priorytet-w-kolejce-moderacji`), `D-073`
  (`claude/pwa-orientacja-i-sitemap`), `D-074`
  (`claude/dokumentacja-nie-klamie`) — poniżej zakresu `main` (main ma już te
  numery zajęte swoją własną historią, ale te cztery gałęzie nie nadpisują ich
  nagłówków na `main`, tylko dodają nowe niżej w pliku/historii; nie są
  kolizją, wypisane dla kompletności spisu).

## 5. Pierwszy naprawdę wolny numer i ciąg kolejnych

Żadna zbadana gałąź (141 zdalnych + 2 lokalne) nie dotyka `D-229` ani wyższych.

**Pierwszy wolny: D-229.** Kolejne wolne: **D-230, D-231, D-232, D-233 …**
(ciągiem, bez dziur, dopóki ktoś ich nie zajmie).

## 6. Nowe znalezisko — błąd ekstrakcji w metodzie liczenia

Pierwszy przebieg tego narzędzia (kopiujący `grep -oE 'D-[0-9]{3}'` na całej
dopasowanej linii nagłówka) zgłosił fałszywą kolizję: `jedna-droga` rzekomo
nadaje D-224. Przyczyna: jej prawdziwy nagłówek to
`## D-225 — Jedna droga wyjęcia wpisu z zeszytu, a zakres wybiera ekran
(#775, #776 + D-224, 20 września 2026)` — tytuł **wspomina** D-224 jako
powiązaną decyzję, a `grep -oE` złapał oba numery z tej samej linii, nie tylko
ten w pozycji nagłówka. Naprawa: wyciągać numer wyłącznie z pozycji zaraz po
`#{1,6} `, np. `sed -E 's/^\+#{1,6} (D-[0-9]{3}).*/\1/'`. **To ta sama rodzina
błędu co fałszywe D-052 opisane w `kolizje-w-kolejce.sh`** — sam skrypt
`kolizje-w-kolejce.sh` w obecnym kształcie WCIĄŻ ma ten błąd (ekstrahuje przez
`grep -oE 'D-[0-9]{3}'` na linii nagłówka, nie tylko pozycję nagłówka) i przy
kolejnym uruchomieniu może dać fałszywy alarm dla dowolnej gałęzi, której tytuł
decyzji wspomina inny numer. Warto to poprawić przy najbliższej okazji — nie
zrobiłem tego sam, bo zlecenie nie obejmowało zmiany narzędzia, tylko spis.

## 7. Czego NIE zrobiono (zgodnie z zleceniem)

Żadna gałąź nie została przenumerowana, żaden commit nie powstał na cudzej
gałęzi, nic nie zostało wypchnięte, żaden PR nie został otwarty.
