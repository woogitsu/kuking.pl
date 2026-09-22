# Plan scalania 28 gałęzi floty — 20.09.2026

Odpowiada na jedno pytanie: **w jakiej kolejności to scalać, żeby nie stracić dnia
na konflikty.**

Konwencja raportowania: **bez adnotacji = zmierzone w tym dokumencie** poleceniami
gita na repozytorium kanonicznym `C:\Users\matma\Documents\Codex\kuking.pl`.
Twierdzenia przejęte oznaczone `[pomiar cudzy: źródło]`. Czego nie sprawdziłem —
napisane wprost.

Nic nie zostało scalone, przepchnięte ani zmienione na cudzych gałęziach. Symulacja
kolejności szła przez `git merge-tree --write-tree` i `git commit-tree`; obiekty
luźne, żadnej gałęzi nie ruszono.

---

## 0. Dwie rzeczy do przeczytania, zanim zacznie się scalać

### 0.1. Gałęzi jest 28, nie 29

`/home/mateusz/flota/do-pchniecia.txt` ma 29 pozycji, ale pierwsza —
`fix/684-widget-zaslania` — stoi już w `/home/mateusz/flota/pchniete.txt`.
Do scalenia zostaje **28 gałęzi, 113 commitów** (suma `git rev-list --count 534e0a51..<gałąź>`).

### 0.2. Baza floty jest o jeden commit za `origin/main` — i ten commit gryzie

Flota stoi na `534e0a51`. `origin/main` = **`4c811cc7`** =
`534e0a51` + jeden commit: **„Dodaj droge wyjecia wpisu z zeszytu (audyt L1) (#789)"**.

Ten jeden commit rusza:

```
app/Http/Controllers/CollectionController.php
docs/DECISIONS.md
resources/css/app.css
resources/views/components/layout.blade.php
resources/views/components/post-card.blade.php
scripts/wyjecie-z-zeszytu.mjs
tests/Feature/WpisDaSieWyjacZZeszytuTest.php
```

Pomiar: **trzy gałęzie są czyste wobec `534e0a51`, a konfliktowe wobec `origin/main`.**

| gałąź | `merge-tree` vs `534e0a51` | `merge-tree` vs `origin/main` (`4c811cc7`) |
|---|---|---|
| `flota/zeszyty` | czyste | **KONFLIKT** — `CollectionController.php`, `post-card.blade.php` |
| `flota/kaskada` | czyste | **KONFLIKT** — `docs/DECISIONS.md` |
| `flota/notyfikacja-zywa` | czyste | **KONFLIKT** — `docs/DECISIONS.md` |

Pozostałe 25 gałęzi jest czystych wobec `origin/main` pojedynczo.

**Wniosek:** kto sprawdzi gałąź wobec bazy floty, zobaczy zieleń, której w PR nie będzie.
`flota/zeszyty` i #789 robią **to samo** — wyjęcie wpisu z zeszytu — z dwóch stron.
To nie jest konflikt tekstowy do sklejenia; ktoś musi zdecydować, która droga wyjęcia
zostaje. Patrz §4, zderzenie nr 1.

---

## 1. Tabela gałęzi

`cmt` = commity ponad `534e0a51` (własne + odziedziczone po bazie).
`baza` = na czym gałąź naprawdę stoi (`main` = `534e0a51`).
Obszar wzięty z tematów commitów, nie z nazwy gałęzi.

| # | gałąź | tip | cmt | baza | plików | obszar |
|---|---|---|---|---|---|---|
| 1 | `flota/r73-feed` | `3b63fceb` | 1 | main | 1 | R73 — strażnik przed feedem po popularności |
| 2 | `flota/r49-trasy` | `83fe1852` | 1 | main | 1 | R49 — pomiar furtki „autor widzi swoje" |
| 3 | `gemini/adr-warstwa-merytoryczna` | `38e54b23` | 1 | main | 1 | raport z audytu siedmiu ADR-ów |
| 4 | `robota/stopka-pusty-pas` | `9a9c3db2` | 1 | main | 3 | podwójna rezerwa pod belką dolną |
| 5 | `flota/r47-skan` | `7a304ff7` | 3 | main | 2 | strażnik R47 + **import mapy reguł** |
| 6 | `robota/poswiadczenia-decyzje` | `8510ec82` | 4 | **`flota/r47-skan`** | 6 | hasło demo do `KUKING_DEMO_HASLO`, rodzina `.env` |
| 7 | `flota/straznik-r60` | `a043a093` | 3 | main | 2 | strażnik R60 + **drugi, inny import mapy reguł** |
| 8 | `flota/prog-postgresa` | `d2ffccac` | 5 | **`flota/straznik-r60`** | 6 | próg PostgreSQL 16 → 18 (zmiana reguły w `AGENTS.md`) |
| 9 | `flota/komentarze` | `36b9bddd` | 5 | main | 17 | #757 #759 #760 #761 #762 — komentarze |
| 10 | `flota/notyfikacja-zywa` | `f8e6b444` | 7 | **`flota/komentarze`** | 25 | #758 żywy wycinek powiadomienia + migracja D-223 |
| 11 | `flota/powiadomienia` | `bb1476dc` | 6 | main | 12 | #733 #734 #746 #770 #771 #772 — powiadomienia |
| 12 | `robota/straznik-format` | `79dabafb` | 7 | **`flota/powiadomienia`** | 13 | strażnik gołego `->format()` w widokach |
| 13 | `flota/gotowanie` | `77b6d71a` | 6 | main | 10 | tryb gotowania, minutnik, Wake Lock |
| 14 | `flota/zdjecia-formularze` | `f639f599` | 5 | main | 14 | #742–#745 #747 — zdjęcia, kreator, x-field |
| 15 | `gemini/profil-wykonania` | `e2454a7f` | 6 | main | 11 | #735 #736 #766–#769 — profil i karta wykonania |
| 16 | `flota/kaskada` | `b8743135` | 2 | main | 10 | strażnik kaskady + kontrola ujemna |
| 17 | `flota/dsa-odwolania` | `a6d74940` | 5 | main | 10 | #796–#800 — odwołania, sankcje, terminy |
| 18 | `flota/odwolanie-link` | `ef9bb448` | 7 | **`flota/dsa-odwolania`** | 18 | #798 — życie strony sprawy zależne od stanu sprawy |
| 19 | `flota/zaleglosci-zgloszen` | `03956496` | 6 | **`flota/dsa-odwolania`** | 16 | #797 — komenda obchodząca zaległe potwierdzenia |
| 20 | `robota/bramka-startowa` | `e3876a30` | 5 | main | 7 | rejestr czynności, `secure` na ciasteczku sesji, art. 18 DSA |
| 21 | `flota/prywatnosc-formularz` | `4c8c03c2` | 2 | main | 5 | #792 #795 — formularz prywatności, „Wróć" |
| 22 | `gemini/794-cel-zgloszenia` | `6a493dbb` | 4 | **`flota/prywatnosc-formularz`** | 9 | #794 — rozpoznawalny cel zgłoszenia |
| 23 | `flota/relacje` | `f9fff3bc` | 3 | main | 8 | #780 #791 #793 — blokady i obserwowanie |
| 24 | `flota/nazwa-a-relacje` | `48ce2a91` | 5 | **`flota/relacje`** | 14 | tablica „Poznaj" + ponowne użycie nazwy |
| 25 | `flota/zeszyty` | `7567c007` | 4 | main | 13 | #774–#777 — zeszyty, licznik, prywatność |
| 26 | `flota/wyszukiwarka` | `35337b15` | 4 | main | 7 | #737 #738 #753 #763 — wyszukiwarka |
| 27 | `flota/hero-ekran` | `c6961c09` | 3 | main | 10 | pierwszy ekran + **jedyna gałąź ruszająca `.github/workflows/ci.yml`** |
| 28 | `gemini/dziennik-wgladow-moderatora` | `21dde3a1` | 2 | main | 3 | dziennik wglądów moderatora w chronione zdjęcia |

---

## 2. Gałęzie stojące na innych gałęziach — osiem, nie cztery

Pomiar: `git merge-base --is-ancestor <baza> <gałąź>` dla każdej pary z zestawu.

| gałąź | stoi na | własnych commitów ponad bazą |
|---|---|---|
| `robota/poswiadczenia-decyzje` | `flota/r47-skan` | 1 |
| `flota/notyfikacja-zywa` | `flota/komentarze` | 2 |
| `robota/straznik-format` | `flota/powiadomienia` | 1 |
| `gemini/794-cel-zgloszenia` | `flota/prywatnosc-formularz` | 2 |
| `flota/odwolanie-link` | `flota/dsa-odwolania` | 2 |
| `flota/zaleglosci-zgloszen` | `flota/dsa-odwolania` | 1 |
| `flota/prog-postgresa` | `flota/straznik-r60` | 2 |
| `flota/nazwa-a-relacje` | `flota/relacje` | 2 |

**Cztery z nich nie były znane** przy zlecaniu planu: `poswiadczenia-decyzje` na
`r47-skan`, `straznik-format` na `powiadomienia`, `794-cel-zgloszenia` na
`prywatnosc-formularz`, `zaleglosci-zgloszen` na `dsa-odwolania`.
`flota/dsa-odwolania` jest bazą dla **dwóch** gałęzi naraz.

Wypchnięte przed bazą pokażą w PR cudze commity (np. `robota/straznik-format`
pokazałby sześć commitów #733–#772 zamiast jednego własnego). Kolejność w §5 to
respektuje.

### 2.1. Jedna gałąź ma dublet pod inną nazwą

`flota/notyfikacja-zywa` i `gpt/n1-powiadomienia` wskazują **ten sam commit**
`f8e6b444`. W kolejce pchania jest tylko `flota/notyfikacja-zywa` — dobrze.
Wypchnięcie obu dałoby dwa PR-y o identycznej treści.

---

## 3. Pary z częścią wspólną — werdykt `merge-tree`

Par z niepustą częścią wspólną plików: **48**. Z tego:
**34 pary — czyste (samo sąsiedztwo)**, **14 par — konflikt tekstowy**.

Polecenie: `git merge-tree --write-tree --name-only <A> <B>` (baza trójstronna =
`merge-base(A,B)`), kod wyjścia różny od zera oznacza konflikt.

### 3.1. Konflikty tekstowe (14 par)

| A | B | plik(i) | rodzaj |
|---|---|---|---|
| `flota/r47-skan` | `flota/straznik-r60` | `docs/MAPA_REGUL_DOWODY.md` | **add/add** |
| `flota/r47-skan` | `flota/prog-postgresa` | `docs/MAPA_REGUL_DOWODY.md` | **add/add** |
| `robota/poswiadczenia-decyzje` | `flota/straznik-r60` | `docs/MAPA_REGUL_DOWODY.md` | **add/add** |
| `robota/poswiadczenia-decyzje` | `flota/prog-postgresa` | `docs/MAPA_REGUL_DOWODY.md` | **add/add** |
| `flota/komentarze` | `flota/powiadomienia` | `app/Models/Notification.php` | content |
| `flota/komentarze` | `robota/straznik-format` | `app/Models/Notification.php` | content |
| `flota/notyfikacja-zywa` | `flota/powiadomienia` | `app/Models/Notification.php` | content |
| `flota/notyfikacja-zywa` | `robota/straznik-format` | `app/Models/Notification.php` | content |
| `flota/gotowanie` | `flota/komentarze` | `package.json`, `resources/js/app.js` | content |
| `flota/gotowanie` | `flota/notyfikacja-zywa` | `package.json`, `resources/js/app.js` | content |
| `flota/komentarze` | `flota/zdjecia-formularze` | `resources/views/components/field.blade.php` | content |
| `flota/zdjecia-formularze` | `flota/notyfikacja-zywa` | `resources/views/components/field.blade.php` | content |
| `flota/zdjecia-formularze` | `gemini/profil-wykonania` | `resources/views/components/photo.blade.php` | content |
| `flota/kaskada` | `flota/notyfikacja-zywa` | `docs/DECISIONS.md` | content |

**Czternaście par sprowadza się do sześciu rozstrzygnięć**, bo cztery z nich są
zdublowane przez stosy: `notyfikacja-zywa` zawiera `komentarze`, a `straznik-format`
zawiera `powiadomienia`, więc kto rozstrzygnie raz, ma rozstrzygnięte dla obu.

### 3.2. Czyste sąsiedztwo (34 pary) — nie trzeba nic robić

Wspólne pliki mimo braku konfliktu mają m.in.:
`flota/dsa-odwolania` z `odwolanie-link` / `zaleglosci-zgloszen` / `prywatnosc-formularz` / `794-cel-zgloszenia`,
`flota/gotowanie` z `zdjecia-formularze`, `flota/hero-ekran` z `kaskada` i `stopka-pusty-pas`,
`flota/komentarze` z `gemini/profil-wykonania`, `flota/powiadomienia` z `gemini/profil-wykonania`,
`flota/zeszyty` z `powiadomienia` / `straznik-format` / `nazwa-a-relacje`,
`flota/relacje` z `prywatnosc-formularz` / `794-cel-zgloszenia` / `nazwa-a-relacje`,
`robota/bramka-startowa` z `flota/zaleglosci-zgloszen`,
`flota/zdjecia-formularze` z `kaskada` i `794-cel-zgloszenia`.

Uwaga: `app/Http/Controllers/ReportController.php` i
`app/Http/Controllers/CookedEventController.php` ruszane są przez **pięć** gałęzi każdy,
`resources/views/pages/settings/privacy.blade.php` przez cztery — i **żadna para na
tych plikach nie konfliktuje**. To najliczniejsze pliki wspólne w całym zestawie i
zarazem najspokojniejsze. Sama lista plików nie mówi nic o ryzyku.

### 3.3. Czego tu nie ma

- **Migracji kolidujących nie ma.** W całym zestawie 28 gałęzi jest **jedna**
  migracja: `database/migrations/2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php`
  na `flota/notyfikacja-zywa`. Żadnego wyścigu znaczników czasu.
- **`.github/workflows/ci.yml`** rusza **jedna** gałąź — `flota/hero-ekran`.
  Nie sprawdzałem, co ta zmiana robi z bramką CI; sprawdzić przed scaleniem, bo
  zmiana w `ci.yml` dotyka wszystkich PR-ów po niej.

---

## 4. Miejsca zapalne — potwierdzenie i dwa sprostowania

### Zderzenie nr 1 (najgroźniejsze) — `flota/zeszyty` kontra commit #789 z `origin/main`

**Nie było na liście podejrzanych.** `flota/zeszyty` jest czysta wobec bazy floty
`534e0a51` i **konfliktowa wobec `origin/main`**, na
`app/Http/Controllers/CollectionController.php` i
`resources/views/components/post-card.blade.php`.

Powód nie jest tekstowy. `#789` nazywa się „Dodaj droge wyjecia wpisu z zeszytu",
a `flota/zeszyty` ma commity „Nie kasuj przepisu ze wszystkich zeszytów jednym
niejawnym kliknięciem" i „Daj zapisanemu wpisowi w zeszycie przycisk usuwający,
nie tylko odnośnik". **Dwie niezależne drogi wyjęcia wpisu z zeszytu.**
Sklejenie obu stron konfliktu da użytkownikowi dwa przyciski robiące to samo.

**To jest decyzja właściciela, nie rozwiązanie konfliktu.** Nie domykać na własną rękę.

### Zderzenie nr 2 — `docs/MAPA_REGUL_DOWODY.md`, add/add na czterech gałęziach

**Sprostowanie.** Oczekiwanie brzmiało: „`flota/r47-skan` i `flota/straznik-r60`
ruszają te same komórki z liczbami". Pomiar mówi co innego:

- **Plik nie istnieje na `534e0a51`** (`git cat-file -e 534e0a51:docs/MAPA_REGUL_DOWODY.md` nic nie znajduje).
- Obie gałęzie mają commit „Przenieś mapę reguł z gałęzi narzędziowej" —
  ale o **różnych SHA** (`99819a8a` na r47, `fd232b86` na r60). Dwa niezależne importy
  z gałęzi narzędziowej, w różnych chwilach.
- Wersje różnią się **rozmiarem i układem**: 20 015 B (r47) wobec 16 009 B (r60),
  `git diff --stat` między nimi: **78 wstawień, 32 usunięcia**. Różnią się nawet
  liczbą kolumn w wierszu R60.
- Konflikt jest typu **add/add**, czyli git nie ma wspólnego przodka pliku i nie
  scali niczego automatycznie — ani nawet niekolidujących akapitów.

Gałęzie ruszające plik: `flota/r47-skan`, `robota/poswiadczenia-decyzje` (dziedziczy
po r47), `flota/straznik-r60`, `flota/prog-postgresa` (dziedziczy po r60).

Par konfliktowych jest **cztery**, nie sześć, a przy kolejności z §5
**zostaje jedno rozstrzygnięcie**: gdy r47 i poswiadczenia są już w `main`,
wchodzi `straznik-r60` i wtedy raz trzeba wpisać wiersz R60 w tabelę r47.
`flota/prog-postgresa` wejdzie po tym czysto.

### Zderzenie nr 3 — `app/Models/Notification.php`, cztery gałęzie

**Potwierdzone, z korektą składu.** Plik ruszają: `flota/komentarze`,
`flota/notyfikacja-zywa`, `flota/powiadomienia`, `robota/straznik-format`
(nie trzy — cztery). Ale to **dwa stosy, nie cztery niezależne gałęzie**:
`notyfikacja-zywa` zawiera `komentarze`, `straznik-format` zawiera `powiadomienia`.

`app/Http/Controllers/NotificationController.php` ruszają trzy: `notyfikacja-zywa`,
`powiadomienia`, `straznik-format` — i na tym pliku `merge-tree` **nie znajduje
konfliktu** w żadnej parze (`Auto-merging` bez `CONFLICT`). Konflikt siedzi
wyłącznie w modelu.

Przy kolejności z §5 zostaje **jedno rozstrzygnięcie**, przy wejściu
`flota/powiadomienia` po stosie komentarzy.

### `docs/DECISIONS.md` — sprostowanie w drugą stronę

Oczekiwanie: „dopisuje do niego kilka gałęzi naraz". Pomiar: z 28 gałęzi
dopisują **dwie** — `flota/kaskada` i `flota/notyfikacja-zywa` — i **one ze sobą
konfliktują**. Trzecim pisarzem jest commit `#789` z `origin/main`, stąd obie
gałęzie są czyste wobec bazy floty i konfliktowe wobec `origin/main`.
Koszt: **dwa rozstrzygnięcia** (jedno przy pierwszej z nich wobec #789, drugie
przy drugiej wobec pierwszej). Rozstrzygnięcia tanie — dopisanie wpisów obok siebie.

---

## 5. Kolejność scalania: siedem partii po cztery

Kolejność zweryfikowana **symulacją sekwencyjną**: od `origin/main`, gałąź po
gałęzi, `git merge-tree --write-tree` plus `git commit-tree` niosące drzewo dalej
(przy konflikcie symulacja szła dalej z `-X theirs`, żeby zobaczyć, co wypłynie
później). Konflikty wypisane niżej to dokładnie to, co symulacja pokazała.

**Bilans: 7 ręcznych rozstrzygnięć w 28 scaleniach. 21 gałęzi wchodzi czysto.**

### Partia 1 — rozgrzewka, zero rozstrzygnięć

1. `flota/r73-feed` — 1 plik
2. `flota/r49-trasy` — 1 plik
3. `gemini/adr-warstwa-merytoryczna` — 1 plik
4. `robota/stopka-pusty-pas` — 3 pliki

*Dlaczego tu:* cztery gałęzie o najmniejszej powierzchni, bez ani jednej pary
konfliktowej w całym zestawie. Partia sprawdza, czy bramka CI w ogóle przepuszcza
prace z `534e0a51` na dzisiejszy `main`, zanim ktokolwiek wyda czas na konflikt.
`robota/stopka-pusty-pas` ma część wspólną z `flota/hero-ekran` (partia 7) —
`merge-tree` mówi: czyste.

### Partia 2 — cały klaster mapy reguł u jednej osoby

5. `flota/r47-skan` — czysto
6. `robota/poswiadczenia-decyzje` — czysto (stoi na r47, tu już nic nie dokłada do mapy)
7. `flota/straznik-r60` — **KONFLIKT: `docs/MAPA_REGUL_DOWODY.md`, add/add**
8. `flota/prog-postgresa` — czysto (stoi na r60)

*Dlaczego tu i w tej kolejności:* to jedyny klaster add/add. Rozbicie go na dwie
partie znaczy, że dwie różne osoby będą sklejać dwie wersje tej samej tabeli
w odstępie godzin. Trzymany razem — jedno rozstrzygnięcie, jeden człowiek,
jedna decyzja o układzie kolumn. r47 idzie pierwszy, bo jego wersja mapy jest
**pełniejsza** (20 015 B wobec 16 009 B), więc tańszym ruchem jest dopisanie wiersza
R60 do tabeli r47 niż odwrotnie. `poswiadczenia-decyzje` wciśnięte między nie,
bo musi stać po swojej bazie, a do mapy nic nie dokłada.

**Uwaga:** `flota/prog-postgresa` zmienia **regułę w `AGENTS.md`** (PostgreSQL 16 na 18),
nie sam próg strażnika. To decyzja właściciela już podjęta
`[pomiar cudzy: KOLEJKA_ZADAN.md, „Decyzje właściciela — komplet z 20.09.2026"]`,
ale zmiana `AGENTS.md` dotyka wszystkich po niej. Nie przesuwać jej wcześniej.

### Partia 3 — klaster powiadomień, dwa stosy zderzone raz

9. `flota/komentarze` — czysto
10. `flota/notyfikacja-zywa` — **KONFLIKT: `docs/DECISIONS.md`** (wobec `#789`, nie wobec komentarzy)
11. `flota/powiadomienia` — **KONFLIKT: `app/Models/Notification.php`**
12. `robota/straznik-format` — czysto (stoi na powiadomieniach)

*Dlaczego tu i w tej kolejności:* stos komentarzy wchodzi w całości przed stosem
powiadomień, dzięki czemu model `Notification` sklejany jest **raz**, a nie cztery
razy. Gdyby `powiadomienia` weszły między `komentarze` a `notyfikacja-zywa`,
rozstrzygnięcia byłyby dwa.

Tu wchodzi **jedyna migracja** w całym zestawie
(`2026_09_20_120000_usun_zamrozone_wycinki_komentarzy`, na `notyfikacja-zywa`).
Partia po niej powinna mieć świeżo postawioną bazę.

`flota/notyfikacja-zywa` niesie #758 w wariancie „śledzi treść" — decyzja
właściciela podjęta `[pomiar cudzy: KOLEJKA_ZADAN.md]`. **Nie sprawdzałem**, czy
obiecany „pomiar liczby zapytań przed i po" jest w tej gałęzi.

### Partia 4 — front, cztery niezależne rozstrzygnięcia

13. `flota/gotowanie` — **KONFLIKT: `package.json`, `resources/js/app.js`**
14. `flota/zdjecia-formularze` — **KONFLIKT: `resources/views/components/field.blade.php`**
15. `gemini/profil-wykonania` — **KONFLIKT: `resources/views/components/photo.blade.php`**
16. `flota/kaskada` — **KONFLIKT: `docs/DECISIONS.md`**

*Dlaczego tu:* wszystkie cztery zderzają się z tym, co weszło w partii 3, a nie
ze sobą nawzajem (`gotowanie` z `zdjecia-formularze` czyste, `kaskada` z
`zdjecia-formularze` czyste, `kaskada` z `794` czyste). Cztery rozstrzygnięcia
w jednej partii wyglądają źle, ale każde jest lokalne, w innym pliku i niezależne —
można je rozdać czterem osobom równolegle. **Kolejność wewnątrz jest wiążąca
w jednym miejscu:** `zdjecia-formularze` **musi** iść przed `gemini/profil-wykonania`,
bo to one dwie zderzają się na `photo.blade.php`; odwrotnie koszt jest ten sam,
ale rozstrzygnięcie wypadnie w drugiej gałęzi.

`flota/gotowanie` rusza `package.json` — po tej partii ktoś musi przebudować
zależności przed uruchomieniem testów frontowych. **Nie sprawdzałem**, co dokładnie
`gotowanie` i `komentarze` dopisują do `package.json`; jeśli to ta sama biblioteka
w dwóch wersjach, rozstrzygnięcie jest wyborem wersji, nie sklejeniem linii.

### Partia 5 — DSA, jedna baza i dwoje dzieci

17. `flota/dsa-odwolania` — czysto
18. `flota/odwolanie-link` — czysto (stoi na dsa)
19. `flota/zaleglosci-zgloszen` — czysto (stoi na dsa)
20. `robota/bramka-startowa` — czysto

*Dlaczego tu:* cztery gałęzie, zero konfliktów, mimo że mają siedem wspólnych
plików (`ReportController.php`, `appeals/*.blade.php`, cztery testy DSA).
Baza przed dziećmi, inaczej PR-y #798 i #797 pokażą po pięć cudzych commitów.
`robota/bramka-startowa` dołożona do tej partii, bo ma część wspólną wyłącznie
z `flota/zaleglosci-zgloszen` i jest z nią czysta — lepiej niech wejdzie zaraz po
niej niż tydzień później, gdy okolica zdąży się ruszyć.

**Uwaga merytoryczna, nie konfliktowa:** `flota/dsa-odwolania` ma commit „Dokończ
zaległe potwierdzenie zgłoszenia", a `flota/zaleglosci-zgloszen` — „Obejdź zaległe
potwierdzenia zgłoszeń". Tekstowo czyste (stos), ale to dwie odpowiedzi na to samo
pytanie. Przeczytać razem przed scaleniem. **Nie oceniałem**, czy się dublują.

### Partia 6 — prywatność i relacje, dwa stosy bez konfliktu

21. `flota/prywatnosc-formularz` — czysto
22. `gemini/794-cel-zgloszenia` — czysto (stoi na prywatnosc-formularz)
23. `flota/relacje` — czysto
24. `flota/nazwa-a-relacje` — czysto (stoi na relacje)

*Dlaczego tu:* oba stosy mają część wspólną (`privacy.blade.php`, `ReportController.php`)
i są czyste we wszystkich parach między sobą. Po partii 5, bo `794-cel-zgloszenia`
i `prywatnosc-formularz` dzielą pliki z `dsa-odwolania` i `zaleglosci-zgloszen`
— też czysto, ale taniej mieć DSA już w `main` niż dwa niezależne fronty na
`ReportController.php`.

### Partia 7 — reszta, plus jedna decyzja właściciela

25. `flota/zeszyty` — **KONFLIKT: `CollectionController.php`, `post-card.blade.php`** → §4, zderzenie nr 1
26. `flota/wyszukiwarka` — czysto
27. `flota/hero-ekran` — czysto (**rusza `.github/workflows/ci.yml`**)
28. `gemini/dziennik-wgladow-moderatora` — czysto

*Dlaczego na końcu:* `flota/zeszyty` to jedyne miejsce, gdzie konflikt jest
pytaniem o produkt, a nie o tekst. Postawiony na końcu nie blokuje 24 gałęzi, które
mogą wejść, zanim właściciel zdecyduje. Gdyby decyzja miała się przeciągnąć —
`flota/zeszyty` da się **wyjąć z tej partii bez ruszania niczego innego**: jej części
wspólne z `powiadomienia`, `straznik-format` i `nazwa-a-relacje` są czyste, więc nic
za nią nie stoi.

`flota/hero-ekran` przedostatni świadomie: jako jedyna zmienia `ci.yml`, a zmiana
bramki tuż przed końcem dotyczy najmniejszej liczby PR-ów. **Nie czytałem tej
zmiany** — przeczytać przed scaleniem.

---

## 6. Trzynaście gałęzi z pracą, których w kolejce pchania NIE MA

Znalezione przez `git branch --list`, nieobecne w `do-pchniecia.txt`:

| gałąź | commity ponad `534e0a51` | wobec `origin/main` |
|---|---|---|
| `flota/martwe-kaskady` | 5 | **KONFLIKT** |
| `robota/kaskada-straznik` | 4 | **KONFLIKT** (jest przodkiem `flota/martwe-kaskady`) |
| `robota/retencja-adresow-bez-konta` | 1 | **KONFLIKT** |
| `gpt/n1-powiadomienia` | 7 | dublet `flota/notyfikacja-zywa`, ten sam SHA |
| `gpt/eksport` | 2 | czyste |
| `gpt/kontakt-panel` | 2 | czyste |
| `gpt/kontakt-formularz` | 1 | czyste |
| `gpt/onboarding` | 1 | czyste |
| `gpt/tablica` | 1 | czyste |
| `gemini/retencja-audytu-36m` | 1 | czyste |
| `robota/drobne-decyzje` | 1 | czyste |
| `robota/hero-pierwszy-ekran` | 1 | czyste |
| `robota/martwe-reguly-css` | 1 | czyste |

**Nie badałem ich par konfliktowych z kolejką** — nie należą do zadania.
Zgłaszam, bo `flota/martwe-kaskady` (5 commitów) i `robota/kaskada-straznik`
(4 commity) to widocznie praca, która nigdzie nie jedzie, a `flota/kaskada`
z partii 4 jest z tej samej okolicy. Decyzja, czy dopisać je do kolejki,
należy do sesji prowadzącej.

Dwanaście gałęzi `gpt/*` stoi dokładnie na `534e0a51` — zero commitów, nic do pchania.

---

## 7. Czego w tym planie nie ma

- **Nie uruchamiałem testów** dla żadnej gałęzi. „Czysto" w tym dokumencie znaczy
  „`git merge-tree` nie zgłasza konfliktu tekstowego", a nie „przechodzi bramkę".
  Gałąź może wejść bez konfliktu i oblać `pre-push`.
- **Nie czytałem treści zmian** poza plikami zapalnymi. Ocena „sąsiedztwo,
  nie konflikt" jest oceną gita, nie oceną sensu — dwie gałęzie mogą bezkolizyjnie
  dopisać dwie sprzeczne reguły do tego samego pliku.
- **Nie sprawdzałem, czy PR-y już istnieją** na GitHubie dla którejkolwiek gałęzi.
- **Nie mierzyłem `docs/DATABASE.md`** pod kątem migracji z `flota/notyfikacja-zywa`.
- **Nic nie scaliłem, nie przepchnąłem i nie ruszyłem cudzej gałęzi.**

## 8. Do decyzji właściciela — nie domykać asercją

1. **`flota/zeszyty` kontra `#789`** — dwie drogi wyjęcia wpisu z zeszytu. Która zostaje.
2. **`docs/MAPA_REGUL_DOWODY.md`** — która wersja tabeli jest kanoniczna (r47: 20 015 B,
   szerszy wiersz R47; r60: 16 009 B, inny układ). To wybór formatu dokumentu,
   nie sklejenie akapitów.
3. **`flota/dsa-odwolania` „dokończ" wobec `flota/zaleglosci-zgloszen` „obejdź"** —
   czy obie odpowiedzi na zaległe potwierdzenia mają zostać.
4. **Czy `flota/martwe-kaskady` i `robota/kaskada-straznik`** wchodzą do kolejki
   pchania (9 commitów pracy poza kolejką, obie konfliktowe wobec `origin/main`).
