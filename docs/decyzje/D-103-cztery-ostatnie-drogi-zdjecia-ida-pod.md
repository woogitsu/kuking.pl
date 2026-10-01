## D-103 · Cztery ostatnie drogi zdjęcia idą pod tę samą blokadę co wpis — a awatar dostaje osobne rozwiązanie na ODPINANIE

**Issue:** #285 (MEDIA-01, P1). **Data:** 10.09.2026.
**Stoi na:** D-083 (jedna blokada wiersza `media`, pliki po commicie),
D-079 (jedna kolejność blokad + rewalidacja POD blokadą).

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ten wpis domyka D-083,
> ale nie postawił tam adnotacji — została dopisana 20 września 2026 przy
> D-083. Sam D-103 ma pokrycie: cztery drogi są zamknięte
> (`app/Domain/Recipes/Actions/PublishRecipe.php:227` przed
> `Recipe::create()`, `app/Domain/Media/Actions/PrzypnijAwatar.php:149`,
> odpinanie awatara atomowym `UPDATE … WHERE` w
> `app/Http/Controllers/Settings/AvatarSettingsController.php:158`).

To jest **wykonanie D-083**, nie nowa decyzja o mechanizmie — sekcja „Co
zostaje otwarte" tamtego wpisu wskazuje dokładnie te cztery drogi. Numer jest
tu potrzebny z jednego powodu: **jedna z nich nie dała się zamknąć wzorcem**
i została rozwiązana inaczej (§3 niżej). Reszta to skopiowanie protokołu,
który już stoi.

### Stan sprzed zmiany — sprawdzony w plikach

- `AvatarSettingsController::update()` — `$profile->update(['avatar_media_id' => …])`
  bez transakcji i bez blokady wiersza `media`.
- `PublishRecipe::handle()` — `hero_media_id` i `source_scan_media_id`
  wkładane prosto z `$attributes` do `$payload`.
- `PublishRecipe::stepMediaId()` — zwracało `media_id` po sprawdzeniu samej
  WŁASNOŚCI (`exists()`, bez blokady).

Wszystkie cztery kolumny mają `nullOnDelete()` (migracje
`2026_09_05_000200_create_profiles_table` i `2026_09_05_000400_create_recipes_tables`),
więc skasowanie wiersza `media` przez sprzątacza **nie zgłaszało konfliktu
klucza obcego** — po cichu zerowało kolumnę. Objaw jest ten sam co przy
`post_media` z `cascadeOnDelete()` opisanym w D-083: ani wyjątku, ani wpisu
w logu. Zostaje profil albo przepis wskazujący na nic, a jedyny egzemplarz
zdjęcia człowieka nie istnieje już nigdzie.

### 1. Trzy drogi przepisu — jedno wywołanie `zablokuj()`, przed `INSERT`-em

`recipes.hero_media_id`, `recipes.source_scan_media_id` i `recipe_steps.media_id`
kończą się w tej samej akcji, więc blokada jest **jedna, na wszystkie zdjęcia
tego zapisu naraz**. Trzy osobne wywołania blokowałyby w trzech grupach, a dwa
równoległe zapisy, w których to samo zdjęcie raz jest główne, a raz stoi przy
kroku, zakleszczyłyby się nawzajem. `zablokuj()` sortuje po `id`, więc jedno
zapytanie daje jedną, globalnie deterministyczną kolejność.

Lista kandydatów obejmuje też zdjęcia **przenoszone**: główne i skan
przepisywane przez `RecipeController::update()` z poprzedniego stanu oraz
zdjęcia kroków dziedziczone po tożsamości. Kusi, żeby ich nie blokować —
przecież są już przypięte. Ale `syncSteps()` **kasuje wiersze kroków i tworzy
je od nowa**, więc odwołanie do zdjęcia kroku przestaje i zaczyna istnieć
w tej samej transakcji: zwykła edycja tytułu przepisu byłaby oknem na
skasowanie zdjęcia, które od miesięcy wisi przy kroku.

Mapa tożsamości kroków (`$istniejaceKroki`) przeniosła się z `syncSteps()` do
`handle()`, bo lista do zablokowania musi być gotowa **przed** zapisem wiersza
przepisu. Odczyt jest teraz jeden zamiast dwóch; dwa odczyty tej samej rzeczy
w jednej transakcji to dwie okazje, żeby się rozjechały.

**Zdjęcie, którego nie udało się zablokować, wypada — przepis zapisuje się bez
niego.** Tak samo jak wpis w D-083 i z tego samego powodu: zdjęcie wypada
z listy tylko wtedy, gdy sprzątacz już je przejął, czyli nie z winy człowieka,
który właśnie zapisuje przepis. Odmowa zapisu zabrałaby mu wszystko, co
wpisał, żeby ukarać go za cudze sprzątanie.

`exists()` w `stepMediaId()` **zostaje** mimo blokady pytającej o to samo
(D-079 §4). Ono służy KOMUNIKATOWI, nie gwarancji, i te dwie odpowiedzi znaczą
co innego: „to nie jest Twoje zdjęcie" jest pomyłką do poprawienia, a „to
zdjęcie właśnie odchodzi" nie jest niczyją pomyłką.

### 2. Kolejność blokad — `media` przed `users`, i to jest ZMIERZONE

D-083 przyjęło z dokumentacji, że `SELECT … FOR UPDATE` zderza się z blokadą,
którą PostgreSQL bierze sam przy sprawdzaniu klucza obcego, i zapisało wprost,
że **nie jest to sprawdzone maszynowo**. Sprawdzone zostało teraz — dwiema
sesjami `psql` na PostgreSQL 18, z `statement_timeout` jako miarą „czeka /
nie czeka":

| sesja 1 trzyma | operacja sesji 2 | wynik |
|---|---|---|
| `users FOR UPDATE` | `UPDATE profiles SET avatar_media_id = …` | **przechodzi** |
| `media FOR UPDATE` | `UPDATE profiles SET avatar_media_id = …` | **czeka** |
| `media FOR KEY SHARE` | `UPDATE profiles SET avatar_media_id = …` | **przechodzi** |
| `users FOR UPDATE` | `INSERT INTO recipes (…)` | **czeka** |
| `media FOR UPDATE` | `INSERT INTO recipes (…)` | **czeka** |

Z tego wynikają dwie rzeczy:

1. **Blokada zdjęć musi stać przed `Recipe::create()`.** `INSERT INTO recipes`
   bierze i wiersz `users` (klucz obcy `author_id`), i wiersz `media` (klucz
   obcy `hero_media_id`), więc kolejność wychodzi `media` → `users` — ta sama,
   którą po D-083 biorą `PublishPost` i `RecordCookedEvent`.
2. **Awatar NIE wchodzi przez `ZamekKonta`.** D-075 ogłasza „konto najpierw"
   i pierwszy odruch każe je tu zastosować. Pomiar mówi, że zapis awatara
   **nie dotyka wiersza `users`** — klucz obcy `profiles.user_id` się nie
   zmienia, więc silnik pomija jego sprawdzenie. Wciągnięcie tej drogi pod
   `ZamekKonta` dołożyłoby `users FOR UPDATE` PRZED wierszem `media`, czyli
   **drugą kolejność blokad w repozytorium** — dokładnie tę rodzinę usterek,
   którą tego samego dnia naprawiały D-093 i PR #311.

„Konto najpierw" obowiązuje tam, gdzie operacja konta naprawdę dotyka —
nie jest zaklęciem do dopisania wszędzie, gdzie w pobliżu stoi użytkownik.

### 3. Awatar: odmienność, której wzorzec z D-083 NIE zamyka

Issue przewiduje, że awatar może się wyłamać, bo **zastępuje** poprzednie
zdjęcie zamiast dokładać nowe. Sprawdzone: samo zastąpienie wzorca nie łamie.
Stare zdjęcie przestaje być używane w chwili commitu, ale sprzątacz pyta „czy
używane" pod blokadą TEGO starego wiersza i czyta `profiles` ponownie — przed
naszym commitem widzi „używane", po commicie „nieużywane". Obie odpowiedzi są
prawdziwe w chwili, w której padają. Stare zdjęcie ma zniknąć; po to człowiek
wgrał nowe.

Wyłamuje się co innego, o czym issue nie mówi: **odpinanie**.
`AvatarSettingsController::destroy()` czytał `$profile->avatar`, a potem zerował
kolumnę bezwarunkowo — czyli ufał modelowi podanemu z zewnątrz (D-079 §3):

1. karta A otwiera „Zdjęcie profilowe" i czyta awatar = X;
2. karta B wgrywa nowe zdjęcie Y — kolumna wskazuje już Y;
3. karta A klika „Usuń zdjęcie" i zapisuje `NULL`, **odpinając Y**.

Y zostaje bez odwołania, więc po dobie karencji zabiera je sprzątacz razem
z plikami. Człowiek traci zdjęcie, którego nie usuwał, i nie dostaje o tym ani
jednego zdania. W grupie 50+ dwie otwarte karty tej samej strony to nie
przypadek brzegowy, tylko sposób obsługi komputera.

**Rozwiązane jednym zdaniem SQL, nie blokadą.** `PrzypnijAwatar::odepnij()`
zeruje kolumnę warunkiem `avatar_media_id = <to zdjęcie>` i patrzy, ile wierszy
zmieniła; zero znaczy „profil wskazuje już na co innego" i wtedy nie kasujemy
ani wiersza, ani plików, tylko mówimy o tym człowiekowi. Gwarancję daje wtedy
atomowy `UPDATE … WHERE` (D-079 §4), a nie `exists()` w PHP — i nie dokłada
blokady, której inne drogi na `profiles` nie biorą, więc nie ma jak ustawić się
w kolejce w innej kolejności niż one.

**Przypięcie awatara odmawia WYJĄTKIEM, nie cichym pominięciem** — i tym różni
się od trzech pozostałych dróg. Przy wpisie i przy przepisie zniknięcie jednego
zdjęcia zostawia całą treść, którą człowiek wpisał. Ten ekran ma jedno pole
i jedną czynność: „Zdjęcie zapisane." przy niezmienionym zdjęciu byłoby
kłamstwem — tym samym, które ten formularz już raz naprawiał (`required` przy
pustym pliku).

Reguła wyszła przy okazji z kontrolera do `App\Domain\Media\Actions\PrzypnijAwatar`
(`AGENTS.md` §4): dopóki stała w kontrolerze, drugi endpoint na to samo pole
zaczynałby od zera.

### Czego świadomie NIE zrobiono

- **Żadnej migracji ani zmiany schematu.** Znacznik `status = 'deleted'`
  wprowadzony w D-083 wystarcza; `media_status_check` dopuszcza tę wartość od
  pierwszej migracji tabeli.
- **Nie ruszono `KasujZdjecie` ani `OsieroconeZdjecia`.** Strona sprzątająca
  została domknięta w D-083 i nic w niej nie brakuje — brakowało wyłącznie
  drugiej strony sekcji krytycznej.
- **Nie dodano `zablokujJedno()`** ani innego cukru na `ZdjeciaDoPrzypiecia`.
  Jedno miejsce blokuje listę, drugie jeden element z listy — druga metoda
  robiłaby to samo pod inną nazwą, a metoda z jednym wywołującym to API
  wymyślone na zapas.
- **Nie odtworzono przeplotu na dwóch połączeniach.** Ograniczenie jest to samo
  co w D-083 i wynika z `RefreshDatabase` (`docs/PULAPKI_TESTOW.md`, pułapka 6);
  nowe jest tylko to, że sama serializacja blokad przestała być założeniem
  i jest zmierzona (tabela w §2).

### Czego test NIE pilnuje

`tests/Feature/CzteryDrogiZdjeciaPodBlokadaTest.php` sprawdza **kontrakt**, po
jednym teście na drogę, każdy z własną kontrolą dodatnią w tej samej kolumnie.
Rozdzielność jest sprawdzona kontrolą ujemną: zepsucie jednej drogi oblewa
dokładnie jeden test (tabelka w opisie PR).

Nie jest pilnowany strażnik `DB::transactionLevel() === 0` w
`ZdjeciaDoPrzypiecia` — pod `RefreshDatabase` poziom transakcji nigdy nie jest
zerem. Nie jest też pilnowany pomiar z §2: to własność silnika zmierzona raz,
poza zestawem testów, a nie zachowanie naszego kodu.

📄 `app/Domain/Media/Actions/PrzypnijAwatar.php` ·
`app/Domain/Recipes/Actions/PublishRecipe.php` ·
`app/Http/Controllers/Settings/AvatarSettingsController.php` ·
`app/Domain/Media/ZdjeciaDoPrzypiecia.php` (bez zmian, wzorzec) ·
`tests/Feature/CzteryDrogiZdjeciaPodBlokadaTest.php` ·
`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php` ·
issue #285, D-083, D-079, D-075, D-093
