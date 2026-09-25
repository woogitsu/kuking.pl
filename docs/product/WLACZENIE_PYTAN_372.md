# Poradźcie — pomiar licznika i instrukcja włączenia flagi (#372)

Stan na 25.09.2026. Flaga `KUKING_QUESTIONS_ENABLED` na produkcji jest
**wyłączona** (`config/kuking.php`, domyślnie `false`; `/pytania` → 404).
Ten dokument niczego nie włącza. Zbiera to, co trzeba wiedzieć, zanim
właściciel zdecyduje o włączeniu, oraz co sprawdzić po nim.

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

### Wyniki (przebieg końcowy, 25.09.2026)

| Zapytanie | Wynik N | Bez indeksu | Z `posts_questions_published_idx` |
|---|---:|---:|---:|
| licznik — gość | 3 453 | 81,8 ms | 81,6 ms |
| licznik — gość + `tag-0` | 588 | 134,1 ms | 124,3 ms |
| licznik — zalogowany (blokady, obserwacje) | 3 660 | **452,6 ms** | **123,0 ms** |
| licznik — zalogowany + `tag-0` | 629 | 136,4 ms | 138,7 ms |
| lista „Najnowsze”, 16 wierszy | 16 | 0,69 ms | 0,62 ms |
| lista „Czeka na odpowiedź”, 16 wierszy | 16 | 1,03 ms | 0,90 ms |

Rozmiar indeksu przy 10 000 pytań: **392 kB**. Pełne plany:
`docs/design/evidence/questions372/pomiar-licznika/`.

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
  (punkt 2.3) to jedyny wiarygodny odczyt.
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

## 2. Instrukcja włączenia flagi

Włączenie to decyzja właściciela i zmiana zmiennej środowiskowej na
produkcji. **Nie wykonuje jej agent.**

### 2.1. Przed włączeniem

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

### 2.2. Włączenie

1. W zmiennych usługi aplikacji na produkcji ustawić
   `KUKING_QUESTIONS_ENABLED=true`.
2. Konfiguracja jest zapieczona przy starcie kontenera (`php artisan optimize`
   w `docker/entrypoint.sh`), więc **zmiana działa dopiero po ponownym
   wdrożeniu/restarcie** usługi.

### 2.3. Co sprawdzić po włączeniu

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

### 2.4. Jak wyłączyć

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

## 3. Pytania do właściciela

1. **Budżet licznika.** Czy ~80–140 ms na każde wejście na `/pytania` przy
   200 000 wpisów to akceptowalny próg, po którym dopiero wracamy do tematu
   (licznik przybliżony, wyświetlany warunkowo albo liczony asynchronicznie —
   bez pomijania blokad)? Dziś, przy 6 kontach, to pojedyncze milisekundy.
2. **Ustawienia bazy produkcyjnej.** Czy sprawdzić (tylko odczyt)
   `random_page_cost` i `jit` na Railway? Przy `random_page_cost = 1.1`
   licznik gościa w pomiarze spadł z ~100 do ~40 ms. Zmiana ustawień bazy to
   osobna decyzja.
