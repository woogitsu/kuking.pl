# „0 wpisów" w spisie tagów — ustalenie i poprawka (#681)

Stan: lokalny odbiór zakończony, bez wdrożenia. Dotyczy jednego, jawnie
otwartego punktu z #681: *„Osobno zbadać zgłoszone 0 wpisów po dodaniu wpisu:
sprawdzić status/widoczność, powiązanie kanonicznego tagu i licznik."*

## 1. Czego ten pakiet NIE robi

Fotograficzny spis A–Z z #681 **jest już wdrożony** — PR #688, odbiór
w [`FOTOGRAFICZNE_TAGI_681.md`](FOTOGRAFICZNE_TAGI_681.md), commit main
`0f30f07`. Szeroka rama katalogu, kwadratowe kafle ze zdjęciem publicznego
wpisu, wariant bez fotografii, statystyki z #369 i puste stany istnieją
i mają swój odbiór. Ten pakiet ich **nie przepisuje**.

Świadomie **nie poszerzam** strony pojedynczego tagu (`/tag/{slug}`). Szeroka
rama w katalogu wchodzi przez `:has(> .tag-directory-intro)` w `marka-rama.css`,
a strona tagu zostaje w kolumnie 760 px — tak jak każdy inny strumień wpisów
w serwisie. Zmiana tego byłaby zmianą decyzji marki o szerokości kolumny
czytania, a nie uzupełnieniem braku.

## 2. Co było zgłoszone i co się naprawdę dzieje

Właściciel: dodałem wpis z tagiem, a tag pokazuje „0 wpisów".

**Liczba jest prawidłowa i celowa.** `TagController::index()` liczy wpisy przez
`publiclyVisible()->tylkoOdAktywnychAutorow()`, czyli treść widoczną dla
wszystkich (D-087). Dzięki temu ta sama liczba znaczy to samo dla każdego
i powstaje jednym zapytaniem dla całej strony, bez N+1.

Usterką był **brak zdania, które to łączy**: autor widzi swój wpis na stronie
tagu (z odznaką „Tylko dla mnie"), a w spisie ma zero — i nic tego nie
tłumaczy.

### Sonda na izolowanej bazie (55439, `kuking_681_testy`)

Sześć hipotez, każda zbudowana i odczytana ze strony:

| przypadek | licznik w `/tagi` | strona tagu (oczami autora) | ocena |
|---|---|---|---|
| wpis **prywatny** | 0 wpisów | wpis widoczny, „Tylko dla mnie" | **to jest zgłoszony przypadek** |
| wpis **tylko dla obserwujących** | 0 wpisów | wpis widoczny | **to samo** |
| **szkic** | 0 wpisów | pusty stan | poprawne, `published()` odcina wcześniej |
| wpis publiczny bez zdjęcia | 1 wpis | wpis widoczny | poprawne |
| autor zawieszony | 0 wpisów | pusty stan | poprawne i celowe (audyt A5) |
| wpis pod tagiem scalonym | 0 wpisów | pusty stan | **artefakt sondy**, patrz niżej |

**Powiązanie kanonicznego tagu jest sprawne.** Ostatni wiersz powstał przez
ręczne podpięcie wpisu pod tag, który już był scalony — czego prawdziwa ścieżka
nie robi: `MergeTags` przepina wiersze `post_tags` na tag docelowy
(`UPDATE post_tags ... SET tag_id = <cel>`). Nie znalazłem drogi, którą
scalenie zostawiałoby wpisy pod starym tagiem.

### Kontrola prywatności — osobno i dokładnie

Zanim cokolwiek zmieniłem, sprawdziłem po DOM i po treści wpisu, komu prywatny
wpis się pokazuje:

| widz | karty wpisu | treść wpisu w HTML | pusty stan |
|---|---|---|---|
| gość | 0 | **nie** | TAK |
| autor | 1 | tak (to jego wpis) | nie |
| obca zalogowana osoba | 0 | **nie** | TAK |

**Wycieku nie ma.** Pierwsze podejście sugerowało inaczej — moje wyrażenie
regularne liczyło `post-card` wielokrotnie w jednej karcie. Dlatego pomiar
został powtórzony po DOM; twierdzenia o prywatności nie opieram na `grep`.

## 3. Co zostało zmienione

Dwie zmiany, obie tekstowe. **Zero zmian w zapytaniach, widoczności
i uprawnieniach.**

1. **Strona tagu** — gdy zalogowana osoba ma na tej stronie własne wpisy, które
   nie wchodzą do liczby publicznej, dostaje jedno zdanie: ile ich jest, że
   widzi je tylko ona, i że spis liczy wpisy widoczne dla wszystkich.
   Liczone z `$posts` (już wczytane), bez dodatkowego zapytania.
2. **Wstęp w `/tagi`** — dopowiedziane wprost: „Wpis prywatny albo tylko dla
   obserwujących do tej liczby nie wchodzi, nawet gdy jest Twój."

Wyjaśnienie dotyczy **wyłącznie własnych wpisów**. Nie pokazuje się gościowi,
obcej osobie ani moderatorowi patrzącemu na cudzą treść — inaczej samo zdanie
byłoby kanałem informacji „ktoś tu ma coś ukrytego".

### Czego świadomie nie zrobiłem

Rozważałem policzenie w `/tagi` liczby per widz, żeby autor widział tam swój
wpis. Odrzucone: łamie D-087 (ta sama liczba dla każdego), dokłada zapytanie
na stronę katalogu i psuje gwarancję braku N+1, której pilnują istniejące
testy. Liczba zostaje publiczna; zmienia się tylko to, czy da się ją
zrozumieć.

## 4. Dowody

Stanowisko: izolowany runtime `/home/mateusz/kuking-681-run`, PostgreSQL
**127.0.0.1:55439**, bazy `kuking_681_run` (ogląd) i `kuking_681_testy` (testy).
Bez danych produkcji.

- **Regresja** `tests/Feature/ZeroWpisowWSpisieTagowTest.php`: 6 testów,
  36 asercji, PASS. Obejmuje wpis prywatny i „tylko dla obserwujących"
  (data provider), gościa i obcą osobę, wpis publiczny jako kontrolę dodatnią,
  szkic oraz moderatora.
- **Pełne testy**: 4236 testów, 82399 asercji, PASS (378,9 s).
- **PHPStan**: bez błędów. **Pint**: 1124 pliki, PASS.
- **`git diff --check`**: czysto.
- **Pomiar katalogu** istniejącym `scripts/katalog-tagow.mjs` (ten sam, co w CI)
  przeciwko lokalnemu serwerowi: **`K681_OK 36 konfiguracji`** —
  320/360/390/414/768/1440 px × jasny/ciemny × skala 70/100/140, bez poziomego
  przepełnienia, z kaflem fotograficznym i podpisem autora.
- **Pomiar strony tagu** (moja zmiana): **38 konfiguracji PASS** — te same
  36 plus dwa przebiegi rzeczywistego zoomu 200 % (`deviceScaleFactor: 2`,
  640 px CSS, oba motywy). Mierzone: obecność akapitu, brak przepełnienia
  strony i własnego, mieszczenie się w kolumnie oraz **minimalny rozmiar
  pisma**.
- **Zrzuty**: katalog 1440 jasny i 390 ciemny; strona tagu 1440 jasny
  i 390 ciemny oczami autora oraz 390 jasny oczami gościa (pusty stan).

### Kontrole ujemne — fizyczne

Psute były **prawdziwe pliki** w worktree, z kopią poza repozytorium:

| co zepsute | wynik |
|---|---|
| usunięcie całego wyjaśnienia z `pages/tags/show.blade.php` | **6 testów oblanych** |
| licznik w `TagController` liczy też wpisy niepubliczne | **2 testy oblane** (dokładnie asercje „0 wpisów") |

Po przywróceniu: 6/6 PASS. Zgodność przywrócenia sprawdzona `cmp` —
bajt w bajt; `show.blade.php` md5 `505c24a9243c8d36cab2da18c505ac0b`,
`TagController.php` md5 `6ab0bc0d9252353070b84f31ccd83c61` i identyczny z HEAD.
**Ograniczenie:** `cp -p` przywraca czas modyfikacji z dokładnością do sekundy,
więc nanosekundy nie są identyczne z oryginałem; zgodność treści jest pełna.

### Poprawka znaleziona własnym pomiarem

Pierwsza wersja akapitu dziedziczyła klasę `.meta` = **16 px**, poniżej
twardego minimum 18 px z AGENTS.md §7. Wykrył to pomiar rozmiaru pisma; akapit
przeniesiony na tekst podstawowy (18 px przy skali 100 %, 25,2 px przy 140 %).
To ten sam wniosek, który padł w review #681 przy podpisie autora zdjęcia.

**Kontrast:** akapit nie wprowadza nowej pary barw — używa tokenu tekstu
podstawowego na tle strony, czyli pary już pilnowanej przez
`scripts/kontrast-marki.mjs`, która przechodzi w ramach `npm run build`.
Odczytane z przeglądarki: jasny `rgb(21,23,20)` na `rgb(243,244,241)`,
ciemny `rgb(244,245,241)` na `rgb(21,23,20)`.

## 5. Ograniczenia tego odbioru

- To jest **ogląd lokalny na danych testowych**. Zdjęcia w fixture to jednolite
  pola barwne, nie potrawy; nie dowodzą dopasowania fotografii do nazw tagów.
- Emulacja okien Chromium, **nie fizyczny telefon** i nie Safari.
- Zrzuty i pomiary robi Playwright; nie zastępują oglądu człowieka.
- Zasłanianie treści przez pływający widżet „Wygląd" jest widoczne na zrzutach
  katalogu i pozostaje osobnym zgłoszeniem **#684** — ten pakiet go nie rusza.
- Fotograficzny wariant kafla nadal **nie ma odbioru na rzeczywistych
  publicznych danych produkcji** (stan z `FOTOGRAFICZNE_TAGI_681.md`); ta
  poprawka tego nie zmienia.
- Wyjaśnienie patrzy na **bieżącą stronę wyników**. Jeśli własny niepubliczny
  wpis wypadnie na dalszą stronę paginacji, zdanie pojawi się dopiero tam.
  Uznane za właściwe: tłumaczy to, co widać.

## 6. Zdarzenie w środowisku — do wiadomości

W trakcie przygotowania stanowiska uruchomiłem `migrate:fresh` i `db:seed`
przeciwko **127.0.0.1:5432, baza `kuking`** zamiast wyznaczonego portu 55439.
Przyczyna: `.env` runtime'u zachował domyślne `DB_PORT=5432`/`DB_DATABASE=kuking`
— moje podmiany w tym pliku nie utrzymały się (w katalogu pojawił się też
`.env.bak-703` spoza tej sesji). Właściciel potwierdził, że nie było tam
niczego wartościowego.

Wnioski wdrożone w tym pakiecie: wszystkie polecenia dostają **jawne parametry
połączenia** ze środowiska, a przed każdym uruchomieniem chodzi **bramka**,
która przerywa pracę, gdy aplikacja celuje w cokolwiek poza
`55439`/`kuking_681_*`. Testy były izolowane poprawnie od początku — zawsze
z jawnym `DB_PORT=55439`.
