# Migracje danych i `wdrozenia`

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

## Migracja danych: zamrożone wycinki komentarzy (20.09.2026)

`2026_09_23_120000_usun_zamrozone_wycinki_komentarzy` — **migracja danych, nie
schematu.** Nie dodaje, nie usuwa i nie zmienia ani jednej kolumny.

**Co robi.** Zdejmuje klucz `excerpt` z `notifications.data` w wierszach typu
`comment.created` i `comment.replied`. Operator `data - 'excerpt'` na `jsonb`
zostawia resztę kluczy nietkniętą; warunek `jsonb_exists(data, 'excerpt')`
zawęża zapis do wierszy, które ten klucz naprawdę mają.

**Dlaczego.** Decyzja właściciela D-229: wycinek treści komentarza liczy się
teraz z **aktualnej** treści, a eksport RODO zmienia się razem z ekranem.
Uzasadnieniem było zdanie „paczka ma pokazywać, co o kimś trzymamy dziś" —
a zamrożone kopie sprzed zmiany (do 120 znaków cudzego tekstu) leżały dalej
w bazie, tyle że nikt ich nie czytał. Decyzja rozstrzyga tę różnicę na
**„nie trzymamy"**, nie „nie czytamy".

**Zakres jest wąski celowo.** `excerpt` zostaje w innych typach powiadomień,
bo tam nie został zastąpiony niczym żywym — skasowanie zabrałoby treść,
której nic nie odtworzy.

**WYCOFANIE NIE PRZYWRACA DANYCH.** `down()` jest świadomie puste: kasujemy
wartości, których nie ma skąd odczytać z powrotem, a `down()` wpisujące
cokolwiek wpisałoby wartość zmyśloną. Migrację można cofnąć bez błędu, ale
**to nie jest przywrócenie**.

**Bez kopii bazy — decyzja właściciela z 23.09.2026.** Migracja wchodzi bez
osobnej kopii zapasowej przed uruchomieniem: „to jeszcze nie produkcja, nie ma
prawdziwych użytkowników". Po jej wykonaniu skasowanych wycinków nie odtworzy
**nic** — ani `down()`, ani kopia. Gdyby Kuking miał już prawdziwych
użytkowników, ta sama migracja wymagałaby kopii przed uruchomieniem.

**Dlaczego rollback tu NIE odmawia (D-088, AGENTS.md §6).** Odmowa w `down()`
jest dla wartości semantycznych, które cykl `down()` → `migrate` po cichu
odwraca (zgoda, zakres usunięcia, widoczność). Tu takiej wartości nie ma:
ponowne `up()` znów tylko zdejmuje klucz, a kod sprzed tej zmiany, który
czytał `data.excerpt`, przy braku klucza pokazuje powiadomienie **bez
wycinka** — mniej treści, nie inna decyzja człowieka. Odmowa musiałaby być
przy tym bezwarunkowa (w bazie nie zostaje ślad, które wiersze straciły
wycinek), czyli blokowałaby `migrate:refresh` w CI na zawsze — a to D-088
nazywa błędem tej samej wagi w drugą stronę. Stąd świadomie pusty `down()`
z uzasadnieniem w kodzie.

Numer `2026_09_23_120000` — przenumerowane z `2026_09_20_120000` przy scalaniu
z main (PR #1180), żeby migracja stała po najnowszej migracji na main.

Strażnik: `tests/Feature/MigracjaCzysciZamrozoneWycinkiTest.php` — sprawdza
trzy kierunki naraz (wycinek znika, reszta kluczy zostaje, obce typy są
nietknięte), powtórzone uruchomienie i kontrolę dodatnią na wypadek, gdyby
warunek przestał trafiać w jakikolwiek wiersz.

## `wdrozenia` i `wdrozenia_funkcje` — numer wersji z końcówką (issue #1932, D-318)

Dwie tabele odpowiadają na dwa różne pytania.

### `wdrozenia`

„Które wdrożenie to było" — jeden wiersz na KAŻDY commit, który realnie
trafił na produkcję pod daną etykietą.

- `id bigint` (bigincrements) — tabela wewnętrzna (dziennik operacyjny), nie
  encja publiczna, więc bez UUID — ten sam wybór co `audit_log.id`;
- `commit varchar(40) NOT NULL UNIQUE` — pełny SHA-1 gita
  (`RAILWAY_GIT_COMMIT_SHA`, patrz `App\Support\Wersja::commit()`). UNIQUE
  daje idempotencję: ten sam commit zarejestrowany drugi raz (redeploy bez
  zmiany kodu) nie zakłada drugiego wiersza;
- `etykieta varchar(40) NOT NULL` — `kuking.wersja.etykieta` w chwili
  rejestracji, np. „Alfa 0.68". Etap produktu podbija się ręcznie i rzadko —
  ta kolumna jest jego migawką, nie referencją na żywo;
- `numer int NOT NULL` — kolejny numer wdrożenia POD TĄ ETYKIETĄ, liczony
  jako `MAX(numer) WHERE etykieta = ?) + 1`. Wraca do 1 przy KAŻDEJ nowej
  etykiecie (podbicie dużego numeru, AGENTS.md §3);
- `created_at timestamptz`;
- `UNIQUE (etykieta, numer)` — niezmiennik z drugiej strony: nawet gdyby
  blokada doradcza w akcji (niżej) kiedyś przestała działać, baza nie
  przyjmie dwóch wierszy z tym samym numerem pod tą samą etykietą.

**Bezpieczeństwo przy równoległym starcie.** `numer` liczy
`App\Domain\Wydania\Actions\ZarejestrujWdrozenie::handle()` wewnątrz
`DB::transaction()`, która NAJPIERW bierze
`pg_advisory_xact_lock(hashtext($etykieta))` — dwa równoległe starty (np.
redeploy uruchomiony tuż po poprzednim) nie mogą dać tego samego numeru:
drugi czeka na zwolnienie blokady (koniec transakcji pierwszego) i dopiero
wtedy liczy `MAX` na nowo. Test na dwóch prawdziwych połączeniach:
`tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`.

**Kto zapisuje.** Komenda `kuking:zarejestruj-wdrozenie --po-gotowosci`,
uruchamiana w tle przez `docker/entrypoint.sh` (role `web` i `all`) DOPIERO,
gdy lokalny `/health` nowego kontenera odpowie 2xx — NIE w `preDeployCommand`
(ten kończy się przed seedem, importem i healthcheckiem, więc nieudany
rollout zużywał numer; audyt z 28 września 2026, #1932). W tabeli są więc
tylko wdrożenia, których kontener wstał. Kontener, który nie odpowie w limicie
(domyślnie 300 s), nie zapisuje niczego — ani `wdrozenia`, ani
`wdrozenia_funkcje`. Patrz `docs/infra/DEPLOYMENT_RUNBOOK.md`. Lokalnie
i w podglądach bez `RAILWAY_GIT_COMMIT_SHA` komenda kończy się bez błędu,
nic nie zapisując.

**Kto czyta.** `App\Support\Wersja::numerWdrozenia()` — dla BIEŻĄCEGO
commita, z cache'em (10 minut, klucz niesie commit), bo metoda woła się
z KAŻDEJ stopki na KAŻDEJ stronie. Brak wiersza (lokalnie, w testach, przy
awarii bazy) daje `null` bez błędu — `Wersja::etykietaZNumerem()` wraca
wtedy do samej etykiety, bez końcówki.

### `wdrozenia_funkcje`

„Pod jakim numerem funkcja pojawiła się PIERWSZY RAZ" — jeden wiersz na
KAŻDY nagłówek `###`, zapisywany, póki jeszcze stoi w sekcji
„## Najnowsze zmiany" pliku `resources/nowosci/tresc.md` (strona „Co
nowego", issue #1909), i czytany PÓŹNIEJ niezależnie od tego, w której
sekcji ten sam nagłówek dziś stoi (D-318, dopisek).

- `id bigint` (bigincrements);
- `etykieta varchar(40) NOT NULL`;
- `naglowek_slug varchar(160) NOT NULL` — slug GFM nagłówka
  (`App\Support\SlugGfm`, ten sam algorytm co kotwice wydań #1909). Strona
  dopasowuje po slugu, nie po pełnym tekście — dopisanie zdania do akapitu
  nie tworzy nowego wiersza (patrz niżej);
- `naglowek_tekst varchar(300) NOT NULL` — pełny tekst nagłówka, do
  czytelności w bazie i diagnozy;
- `numer int NOT NULL` — numer wdrożenia (z `wdrozenia.numer`), pod którym
  ten nagłówek pojawił się PIERWSZY RAZ, pod etykietą zapisaną OBOK niego
  w tym samym wierszu (patrz niżej);
- `created_at timestamptz`;
- `UNIQUE (naglowek_slug)` — jeden wiersz na nagłówek W CAŁEJ TABELI, NIE
  na parę (etykieta, slug). **Decyzja właściciela z 26 września 2026
  (D-318, dopisek):** dopisek „od …" zostaje NA STAŁE, także gdy nagłówek
  przechodzi z „## Najnowsze zmiany" do sekcji nazwanego wydania (np.
  „## Alfa 0.69") — nagłówek trzyma tekst (i slug) bez zmian przy
  przenosinach, więc slug sam w sobie jest kluczem trwałym, niezależnym od
  etykiety, pod którą wiersz akurat powstał. Zapis idzie przez
  `INSERT ... ON CONFLICT (naglowek_slug) DO NOTHING`: nagłówek widziany już
  wcześniej — czy to pod TĄ SAMĄ etykietą (dopisano kolejne zdanie do tego
  samego akapitu i wdrożono ponownie), czy pod WCZEŚNIEJSZĄ etykietą sprzed
  podbicia dużego numeru — NIE dostaje nowego, późniejszego numeru: zostaje
  przy numerze i etykiecie pierwszego pojawienia. To jest sens
  „od Alfa 0.68.NNN": data pierwszego pojawienia się, nie data ostatniej
  edycji ani bieżąca etykieta aplikacji.

**Kto zapisuje.** Ta sama komenda i ta sama transakcja co `wdrozenia` —
`ZarejestrujWdrozenie::handle()` zapisuje NOWE nagłówki zaraz po wstawieniu
wiersza `wdrozenia`, w tej samej transakcji, więc obie tabele albo obie się
zmieniają, albo żadna. Skanuje WYŁĄCZNIE sekcję „## Najnowsze zmiany" —
celowo NIE sekcje wydań: tabela była pusta w chwili wdrożenia tej funkcji,
więc skanowanie już wydanych sekcji przypisałoby świeżo policzony numer
funkcjom sprzed tygodni (patrz komentarz klasy `ZarejestrujWdrozenie`).
Trwałość dopisku przy przenosinach nagłówka do sekcji wydania załatwia sam
slug (wyżej), nie ponowne skanowanie.

**Kto czyta.** `NowosciController` — dla KAŻDEGO nagłówka `###` w CAŁYM
dokumencie (nie tylko w „## Najnowsze zmiany" — patrz decyzja właściciela
wyżej), którego slug ma wiersz w tabeli, dokleja kursywną linijkę
„_od {etykieta}.{numer}_", biorąc etykietę i numer Z TEGO WIERSZA, nie
bieżącą etykietę aplikacji. Brak wiersza nie wywala strony — nagłówek
zostaje bez dopisku (to dotyczy też nagłówków z wydań SPRZED wprowadzenia
tej funkcji, #1932 — nikt im nie przypisuje numeru wstecznie).

### Rollback (D-088)

`down()` obu tabel ODMAWIA, gdy którakolwiek ma choć jeden wiersz: numer
wdrożenia jest już POKAZANY ludziom (stopka, „od Alfa 0.68.NNN"), a cofnięcie
migracji na wypełnionej bazie i kolejny `migrate` zacząłby liczyć numery od 1
dla każdej etykiety, mieszając je ze starymi. Na świeżej bazie (obie tabele
puste) `down()` przechodzi bez pytania. Test odmowy i kontrola dodatnia:
`tests/Feature/DziennikWdrozenCofnieciePrzyWartosciachTest.php`.
