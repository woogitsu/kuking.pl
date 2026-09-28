# Poradźcie — licznik pytań, pomiar i obsługa flagi (#372)

Stan na 25.09.2026 (wieczór). Dział jest **włączony na produkcji**:
`GET https://kuking.pl/pytania` zwrócił 200 (odczyt z sesji, 25.09.2026).
Tej zmiany nie wykonywał agent. Rozdział 1 opisuje pomiar zrobiony PRZED
włączeniem. Rozdział 2 opisuje decyzję właściciela z tego samego dnia: licznik
liczony w tle. Rozdział 3 to lista kontrolna i sposób wyłączenia.

## 1. Pomiar kosztu „Czeka na odpowiedź (N)”

### Co mierzono i dlaczego

`QuestionController::index()` przy **każdym** wejściu na `/pytania` wykonuje
dwa zapytania: stronę listy (`cursorPaginate`, 16 wierszy) i osobny
`QuestionList::query($widz, true, $tag)->count()` po całym zbiorze pytań
dla etykiety „Czeka na odpowiedź (N)”. Kursor ogranicza listę, ale nie
licznik. Licznik zależy od widza (blokady w obie strony, widoczność
„dla obserwujących”), więc wspólny cache jednej liczby byłby niepoprawny
(audyt backendu 22.09.2026 w treści #372).

### Jak odtworzyć

```bash
# na klastrze lokalnym kontenera (rola z prawem CREATE DATABASE)
su postgres -c "python3 scripts/pomiar-pytan-372.py --wyniki /tmp/pomiar-372"
```

- `scripts/pomiar-pytan-372.py` tworzy własną bazę `kuking_pomiar_pytan_<czas>`,
  ładuje dane, mierzy, zakłada indeks z migracji, mierzy znowu i kasuje bazę
  (`--zostaw`, żeby ją zachować). Odmawia pracy na bazie bez tego przedrostka.
- `scripts/pomiar-pytan-372-dane.sql` — podzbiór schematu (tabele i indeksy,
  które czyta zapytanie, przepisane 1:1 z migracji) oraz dane syntetyczne,
  deterministyczne (md5 z numeru wiersza).
- `scripts/pomiar-pytan-372-zapytania.sql` — zapytania odtworzone z tego, co
  buduje Laravel dla `QuestionList::query()` przy włączonej fladze. **Zmiana
  `QuestionList` albo `scopeWidoczneDla` wymaga poprawienia tego pliku.**
- Nie wymaga `vendor/` ani aplikacji — sam `psql` i Python 3.

### Dane syntetyczne

| Tabela | Wiersze | Rozkład |
|---|---:|---|
| `posts` | 200 000 | rok publikacji; 85% publiczne / 10% dla obserwujących / 5% prywatne; 95% opublikowane; 1% miękko usunięte |
| w tym pytania | 10 000 (5%) | co dwudziesty wpis |
| `comments` | 396 809 | dania: 0–4 komentarze (śr. 2); pytania: koszyki niżej |
| `users` | 20 001 | 98,5% aktywne; 100 kont zbanowanych / w usuwaniu jako autorzy „nieliczących się” odpowiedzi |
| `follows` | 199 630 | w tym widz pomiaru obserwuje 500 osób |
| `blocks` | 5 188 | w tym widz: blokuje 100 osób, 100 osób blokuje jego |
| `post_tags` | 296 534 | 0–3 tagi na wpis, rozkład skośny; najpopularniejszy `tag-0` ma 33 032 wpisy (16,5%) |

Koszyki odpowiedzi na pytania: 55% ma 1–4 widoczne główne odpowiedzi,
20% zero komentarzy, 10% tylko dopisek pod ukrytym komentarzem, 7% tylko
odpowiedzi ukryte / miękko usunięte / z usuniętą treścią, 5% tylko od kont
zbanowanych lub w usuwaniu, 3% tylko od osób w blokadzie z widzem.

### Środowisko

PostgreSQL **16.13** w kontenerze sesji (produkcja: PostgreSQL 18 na Railway),
4 vCPU współdzielone, `shared_buffers = 128MB`, `work_mem = 4MB`,
`random_page_cost = 4` (domyślny), `jit = on` (domyślny). Bufory ciepłe
(rozgrzewka przed pomiarem). Czasy to mediana z 7 × `EXPLAIN (ANALYZE, BUFFERS)`;
kontener jest współdzielony, więc rozrzut min–max bywa ±30%.

### Wyniki (pomiar przed zmianą, 25.09.2026)

| Zapytanie | Wynik N | Bez indeksu | Z `posts_questions_published_idx` |
|---|---:|---:|---:|
| licznik — gość | 3 453 | 81,8 ms | 81,6 ms |
| licznik — gość + `tag-0` | 588 | 134,1 ms | 124,3 ms |
| licznik — zalogowany (blokady, obserwacje) | 3 660 | **452,6 ms** | **123,0 ms** |
| licznik — zalogowany + `tag-0` | 629 | 136,4 ms | 138,7 ms |
| lista „Najnowsze”, 16 wierszy | 16 | 0,69 ms | 0,62 ms |
| lista „Czeka na odpowiedź”, 16 wierszy | 16 | 1,03 ms | 0,90 ms |

Rozmiar indeksu przy 10 000 pytań: **392 kB**. Tabela pochodzi z przebiegu
sprzed ujednolicenia definicji odpowiedzi (rozdział 2). Pełne plany
z przebiegu po zmianie, z nową definicją i zapytaniami licznika w tle:
`docs/design/evidence/questions372/pomiar-licznika/`. Liczby są w granicach
rozrzutu pomiaru.

### Co pokazały plany

1. **Gość, bez indeksu:** `Parallel Seq Scan on posts` (pełny skan, żeby
   znaleźć 5% pytań) oraz `Parallel Hash Right Anti Join` z
   `Parallel Seq Scan on comments` (~394 000 wierszy). Z indeksem strona
   `posts` idzie po `posts_questions_published_idx`, ale **pełny skan
   `comments` zostaje** — to on dominuje (~60 z ~80 ms).
2. **Zalogowany, bez indeksu:** `Bitmap Index Scan on posts_author_published_idx`
   po ~193 000 pozycjach (w praktyce pełny przegląd) i zawyżony koszt planu
   (~960 000), który **włączał JIT: ~250–280 ms z ~450–500 ms** szło na
   kompilację. Z indeksem: `Bitmap Index Scan on posts_questions_published_idx`
   (~9 400 pozycji), koszt ~160 000, JIT bez optymalizacji (~25 ms).
   Anty-złączenie z `comments` idzie wtedy pętlą po `comments_post_idx`.
3. **Tag:** planista szacuje ~500 wierszy na tag (średnia), a `tag-0` ma
   33 000, więc zaczyna od `post_tags` i sprawdza 33 000 wpisów po kluczu.
   Indeks tego nie zmienia; to efekt skrajnie skośnego tagu w danych
   syntetycznych. Tag przeciętny jest tani.
4. **Listy** (16 wierszy) są tanie z indeksem i bez niego — kursor działa.

Eksperymenty dodatkowe na tej samej bazie (z indeksem, niezapisane w skrypcie):

| Ustawienie | licznik gościa | licznik zalogowanego |
|---|---:|---:|
| domyślne | ~104 ms | ~123 ms |
| `jit = off` | ~105 ms | ~82 ms |
| `random_page_cost = 1.1` | **~39 ms** (pętla po `comments_post_idx` zamiast hash) | ~64 ms |
| `enable_hashjoin = off` | ~36 ms | ~106 ms |
| + indeks częściowy `comments (post_id) WHERE parent_id IS NULL AND …` (7,7 MB) | ~79 ms (planista dalej wybiera hash) | ~112 ms |

### Wniosek

- **Indeks częściowy jest potrzebny i dodany:** migracja
  `2026_09_25_200000_add_questions_published_index_to_posts`
  (`posts (published_at DESC, id DESC) WHERE kind = 'question' AND deleted_at IS NULL AND status = 'published'`),
  `CONCURRENTLY`, rollback bezstratny (`docs/DATABASE.md`, rozdział
  „Indeks częściowy opublikowanych pytań”). Usuwa pełny przegląd `posts`
  i skok JIT u zalogowanego (452 → 123 ms przy 200 000 wpisów).
- **Indeks na `comments` nie pomaga** — nie dodany.
- **Przy dzisiejszej skali produkcji (6 kont) koszt jest pomijalny.** Przy
  200 000 wpisów / ~400 000 komentarzy licznik kosztuje ~80–140 ms na każde
  wejście na `/pytania`. W repozytorium nie ma zapisanego budżetu czasu
  zapytania; to decyzja właściciela (pytanie niżej).
- Największa pozostała dźwignia to **ustawienie serwera**, nie kod:
  przy `random_page_cost = 1.1` (typowe dla SSD) licznik gościa spada do ~40 ms.
  Ustawienia bazy produkcyjnej nie były sprawdzane (brak dostępu z sesji).

### Czego nie sprawdzono

- **PostgreSQL 18 i sprzętu Railway** — pomiar na 16.13 w kontenerze sesji.
  Planista 18 może wybrać inaczej; `EXPLAIN` na produkcji po włączeniu
  (punkt 3.3) to jedyny wiarygodny odczyt.
- **Pełnego schematu** — podzbiór tabel bez kolumn, których zapytanie nie
  czyta (`topic_id`, `display_mode`, …). Szerszy wiersz `posts` zwiększa
  liczbę stron przy skanie bitmapowym.
- **p95 całej strony pod obciążeniem** — mierzone pojedyncze zapytania,
  nie żądania HTTP równolegle. Brak `vendor/` w sesji wyklucza uruchomienie
  aplikacji.
- **Zapytań dokładnie z PDO** — SQL odtworzony ręcznie z kodu (z literałami
  zamiast parametrów). Test `IndeksPytanOpublikowanychTest` sprawdza w CI,
  że SQL z prawdziwego `QuestionList` trafia w indeks.
- Ustawień produkcyjnej bazy (`random_page_cost`, `jit`, `work_mem`).

## 2. Licznik liczony w tle (decyzja właściciela 25.09.2026)

### Co wybrano

**Liczba gościa w tle plus dokładna poprawka widza liczona po indeksie.**
Kod: `App\Domain\Questions\PytaniaBezOdpowiedzi`.

- **G**, liczba gościa: publiczne, opublikowane pytania aktywnych autorów bez
  żadnej widocznej odpowiedzi, razem i osobno dla każdego aktywnego tagu.
  Liczy ją `przelicz()` w tle, wynik leży w cache aplikacji
  (`CACHE_STORE=database`, tabela `cache`, klucz `pytania:czeka-na-odpowiedz`,
  `Cache::forever`). Odsłona czyta go jednym `Cache::get()`.
- **Zalogowany widz W** dostaje tę samą liczbę, jaką dałoby pełne zapytanie
  `QuestionList::query(W, true, tag)->count()`:

  `N(W) = G − (1) + (2) + (3)`

  1. pytania z G, których autor jest w blokadzie z W (w dowolną stronę) — ich W
     nie widzi;
  2. niepubliczne pytania, które W widzi (własne i „dla obserwujących” od
     obserwowanych), bez odpowiedzi dla W;
  3. publiczne pytania, na które odpowiadały wyłącznie osoby w blokadzie
     z W — dla wszystkich odpowiedziane, dla W nie.

  Każdy składnik zaczyna od małego zbioru: blokad widza, obserwowanych albo
  własnych pytań. Składniki (1) i (3) liczymy tylko wtedy, gdy W ma
  jakąkolwiek blokadę.

**Dlaczego to jest ten sam zbiór co pełne zapytanie.** Odpowiedź widoczna
dla W to odpowiedź widoczna globalnie, której autor nie jest w blokadzie z W.
Pytanie bez odpowiedzi globalnie jest więc bez odpowiedzi także dla W.

- Publiczne pytania z G, których autor nie jest w blokadzie z W, należą
  do zbioru W. Odejmujemy tylko (1).
- Publiczne pytania zbioru W spoza G to pytania odpowiedziane globalnie, ale
  wyłącznie przez osoby w blokadzie z W. To jest (3).
- Niepubliczne pytania zbioru W to dokładnie (2).

Test `LicznikPytanBezOdpowiedziTest::test_licznik_zgadza_sie_z_pelnym_zapytaniem_listy`
porównuje oba wyniki dla czterech widzów i dwóch tagów.

**Świeżość.**
- Zdarzenia modeli (`AppServiceProvider::odswiezajLicznikPytan`): zapis albo
  usunięcie pytania, komentarza pod pytaniem i tagu pytania zleca po commicie
  zadanie `PrzeliczPytaniaBezOdpowiedzi` (`ShouldBeUniqueUntilProcessing` —
  seria zapisów daje jedno przeliczenie; kolejka `low`, za pracą ludzi —
  D-052). Nowa odpowiedź odświeża licznik, gdy worker dojdzie do zadania.
- Harmonogram `kuking:policz-pytania` co 5 minut łapie resztę: zmianę
  statusu konta autora, scalenie tagów, zapisy z pominięciem modeli. Przy
  wyłączonym dziale czyści cache.
- Poprawki (1)–(3) liczymy na żywo. Między zapisem a przeliczeniem N(W) może
  więc przez chwilę mieszać świeżą poprawkę ze starym G. Wynik jest obcinany
  od dołu do zera.
- Pusty cache (świeże wdrożenie) liczymy raz, w żądaniu. Nie pokazujemy wtedy
  zera, które byłoby nieprawdą.

**Koszt w ścieżce zapisu:**
- przy komentarzu: jedno `exists()` po kluczu głównym wpisu i jeden wiersz
  w `jobs`, tylko gdy dział jest włączony;
- przy daniu: nic;
- samo przeliczenie (ok. 220 ms przy 200 000 wpisów) idzie w workerze.

### Odrzucone warianty

- **Jedna globalna liczba dla wszystkich** — omijałaby blokady i widoczność
  „dla obserwujących”, czego #372 i AGENTS.md zabraniają.
- **Cache per widz z TTL** — pierwsze wejście każdej osoby płaci pełny
  COUNT, a nowa odpowiedź musiałaby czyścić cache wszystkich widzów.
- **Przybliżenie „ok. N”** — nie jest potrzebne, skoro wersja dokładna
  kosztuje milisekundy. Zostaje w zapasie, gdyby poprawka (2) zaczęła rosnąć
  (niżej).
- **Redis / licznik w osobnym magazynie** — wykluczone przez AGENTS.md §3.

### Ujednolicona definicja odpowiedzi

`App\Domain\Questions\OdpowiedzNaPytanie::zawez()`. Odpowiedź to komentarz,
który spełnia trzy warunki:
- jest najwyższego poziomu;
- ma treść;
- napisała go **inna osoba niż pytająca**.

Do 25.09.2026 lista `/pytania` i licznik liczyły dopisek autora pod własnym
pytaniem jako odpowiedź, a kolejka gospodarza nie. Pytanie znikało wtedy
z „Czeka na odpowiedź”, choć nikt na nie nie odpowiedział. Z tej definicji
korzystają: `QuestionList::visibleAnswers()` (lista, `answer_count`, filtr,
licznik) i `UnansweredContent::answers()` (kolejka i mediana gospodarza).
Regresję pilnuje `QuestionListTest::test_komentarz_autora_pod_wlasnym_pytaniem_nie_jest_odpowiedzia`.

**Poza zakresem:** strona pytania (`PostController::show`) liczy
`answerCount` w JSON-LD i `suggestedAnswer` ze wszystkich widocznych
komentarzy głównych, także autora. To osobna, drobna zmiana SEO.

### Pomiar przed i po

Ten sam zbiór syntetyczny (200 000 wpisów, 10 000 pytań, ok. 397 000
komentarzy), PostgreSQL 16.13, z indeksem `posts_questions_published_idx`.
Widz pomiaru ma 200 osób w blokadzie (100 blokuje, 100 blokuje jego)
i obserwuje 500 osób — to przypadek skrajny. Mediana z 7 ×
`EXPLAIN (ANALYZE, BUFFERS)`, zapytania z `scripts/pomiar-pytan-372-zapytania.sql`.

| Na każde wejście na `/pytania` | Przed (pełny COUNT) | Po (w tle + poprawka) |
|---|---:|---:|
| gość | 95 ms | **0,04 ms** (odczyt po kluczu z `cache`) |
| gość + tag | 149 ms | **0,04 ms** |
| zalogowany bez blokad | 128 ms | **~13 ms** (tylko (2)) |
| zalogowany z 200 blokadami | 128 ms | **~58 ms** (blokady 0,15 + (1) 6,3 + (2) 13,0 + (3) 39,1) |

W tle, po zapisie dotyczącym pytania i co 5 minut: `licznik_gosc`
(~95 ms) plus `przeliczenie_tagi` (~126 ms).

Zgodność na danych pomiaru: G = 3 453, (1) = 35, (2) = 7, (3) = 235;
3 453 − 35 + 7 + 235 = **3 660** — tyle samo co pełne `licznik_konto`.

**Gdzie to zacznie rosnąć.**
- (2) przegląda indeks wszystkich opublikowanych pytań (≈ 9 400 pozycji) i
  filtruje po autorze, więc rośnie z liczbą pytań, nie z całą tabelą.
- (3) rośnie z liczbą komentarzy osób w blokadzie z widzem.

Jeśli (2) przekroczy ~50 ms (rząd 40 000 pytań), kolejnym krokiem jest
indeks częściowy na niepubliczne pytania albo przybliżenie „ok. N”.
Obie zmiany wymagają decyzji właściciela.

## 3. Obsługa flagi (włączenie, kontrola, wyłączenie)

Włączenie to decyzja właściciela i zmiana zmiennej środowiskowej na
produkcji. **Nie wykonuje jej agent.**

### 3.1. Przed włączeniem (stan: flaga już włączona 25.09.2026)

Flaga jest już włączona. Punkty 1–2 trzeba sprawdzić **po scaleniu tej
gałęzi**: wdrożona migracja indeksu i działający worker kolejki, bo to on
przelicza licznik.

1. Na `main` jest migracja `2026_09_25_200000_add_questions_published_index_to_posts`
   i wdrożenie po niej zakończyło się sukcesem.
2. Indeks istnieje i jest ważny (zapytanie tylko do odczytu):
   ```sql
   SELECT c.relname, i.indisvalid FROM pg_index i
   JOIN pg_class c ON c.oid = i.indexrelid
   WHERE c.relname = 'posts_questions_published_idx';
   ```
   Oczekiwane: jeden wiersz, `indisvalid = t`.
3. Zmienna nie jest zapisana w `.railway/railway.ts`. Czyta ją nie tylko
   warstwa HTTP, lecz także modele i akcje (`Post::scopeEnabledKinds`,
   `Notification`, `PublishPost`, `QuestionNotificationContext`), więc każdy
   proces, który buduje listy albo powiadomienia, musi widzieć tę samą
   wartość. Dzisiejsza produkcja chodzi w roli `all` (jedna usługa); po
   rozbiciu ról zmienna musi trafić do wszystkich trzech naraz. Jeśli ma ją
   nieść IaC — dopisać do wspólnego zestawu i do `ZmienneRailwayaPerRolaTest`.

### 3.2. Włączenie

1. W zmiennych usługi aplikacji na produkcji ustawić
   `KUKING_QUESTIONS_ENABLED=true`.
2. Konfiguracja jest zapieczona przy starcie kontenera (`php artisan optimize`
   w `docker/entrypoint.sh`), więc **zmiana działa dopiero po ponownym
   wdrożeniu/restarcie** usługi.

### 3.3. Co sprawdzić po włączeniu

1. `https://kuking.pl/pytania` → 200, widać „Poradźcie” z opisem i
   „Czeka na odpowiedź (N)”; `/pytania?filtr=bez-odpowiedzi` i
   `/pytania?tag=<slug>` działają; `/pytania/zadaj` bez logowania prowadzi
   do logowania.
2. Zalogowane konto zadaje pytanie → pytanie na liście; gospodarz dostaje
   alert „pierwsze pytanie w Kuking” z przyciskiem prowadzącym do zakładki
   „Pytania” panelu „Bez odpowiedzi”; odpowiedź innej osoby zdejmuje pytanie
   z kolejki i z „Czeka na odpowiedź”.
3. Zakładka „Wpisy” panelu nie pokazuje pytań; „Pytania” ma własną medianę.
4. Strona pytania ma `QAPage` w JSON-LD; zwykłe danie go nie ma.
5. Koszt licznika na prawdziwej bazie — dla gościa (tylko odczyt):
   ```sql
   EXPLAIN (ANALYZE, BUFFERS)
   -- zapytanie `licznik_gosc` z scripts/pomiar-pytan-372-zapytania.sql
   ```
   Przy obecnej skali oczekiwane pojedyncze milisekundy. Zapisać wynik
   w komentarzu #372. Powtórzyć, gdy liczba pytań przekroczy ~2 000 albo
   komentarzy ~100 000, i porównać z tabelą wyżej.
6. Logi błędów (kanał 500) przez pierwszą dobę.
7. Licznik w tle: po odpowiedzi na pytanie licznik spada w ciągu kilku
   sekund (worker kolejki). `php artisan kuking:policz-pytania` wypisuje
   „Czeka na odpowiedź: N”, a N zgadza się z przyciskiem na `/pytania`
   oglądanym jako gość. W `failed_jobs` nie ma
   `PrzeliczPytaniaBezOdpowiedzi`.

### 3.4. Jak wyłączyć

1. Ustawić `KUKING_QUESTIONS_ENABLED=false` (albo usunąć zmienną — domyślnie
   `false`) i ponownie wdrożyć/zrestartować usługę.
2. Skutek: `/pytania` i zakładka „Pytania” w panelu → 404, pytania znikają
   z list i strumieni (`Post::scopeEnabledKinds`), alert o pierwszym pytaniu
   traci przycisk „Zobacz” zamiast prowadzić na 404.
3. **Dane zostają** — flaga niczego nie kasuje. Ponowne włączenie przywraca
   pytania i odpowiedzi w stanie sprzed wyłączenia.
4. Indeksu nie trzeba cofać przy wyłączeniu flagi (392 kB na 10 000 pytań,
   bez pytań praktycznie pusty). `down()` migracji zdejmuje go bezstratnie,
   gdyby był niepotrzebny.

## 4. Pytania do właściciela

1. **Ustawienia bazy produkcyjnej.** Czy sprawdzić (tylko odczyt)
   `random_page_cost` i `jit` na Railway? Przy `random_page_cost = 1.1`
   pełny COUNT gościa spadł w pomiarze ze ok. 100 do ok. 39 ms. Dotyczy
   dziś już tylko przeliczenia w tle. Zmiana ustawień bazy to osobna decyzja.
2. **Kolejność wdrożenia.** Flaga jest włączona, a migracja indeksu i licznik
   w tle są dopiero na gałęzi `claude/372-pytania-link-tagu`. Do scalenia
   produkcja liczy pełny COUNT przy każdym wejściu. Przy dzisiejszej skali to
   pojedyncze milisekundy, więc nie jest to pilne.
3. **Strona pytania.** Czy ujednolicić też `answerCount` i `suggestedAnswer`
   w JSON-LD (bez komentarzy autora pytania)? To osobna, drobna zmiana.
