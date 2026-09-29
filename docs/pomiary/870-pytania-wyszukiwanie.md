# Pomiar #870 — odnajdywanie wcześniejszych pytań Poradźcie po słowach z tytułu

**Status: przygotowany pomiar, nie funkcja.** Issue #870 (P3) jest jasne:
„propozycja wymagająca decyzji roadmapowej, nie zgoda na implementację”
i „najpierw pomiar potrzeby”. Ten dokument i narzędzia w repozytorium dają
część pomiaru, którą da się zrobić bez ludzi i bez produkcji (koszt SQL,
powodzenie, dostępność treści), oraz protokół na resztę. Wyszukiwarki pytań
w aplikacji **nie dodano**.

Zakaz stosu (AGENTS.md §3) nie blokuje tematu: wszystko poniżej to
PostgreSQL, który już jest w projekcie (`pg_trgm`, `unaccent`); pomiar nie
zakłada osobnego silnika, bazy wektorowej ani AI. Lista „V2, ale nie teraz”
i „Nie wcześnie” w `docs/FEATURES.md` nie zawiera wyszukiwania pytań.

Data: 29 września 2026. Baza odniesienia: gałąź `claude/paczka-c`.

## Co jest w repozytorium

| Plik | Po co |
|---|---|
| `scripts/pomiar-870.php` | Zakłada syntetyczne pytania w lokalnej bazie i porównuje obecną drogę (lista `/pytania`) z trzema wariantami szukania po tytule zbudowanymi na tym samym `QuestionList::query()`. Mierzy powodzenie, czas, liczbę zapytań, `EXPLAIN (ANALYZE, BUFFERS)` bez indeksu i z indeksem trigramowym założonym tylko w bazie pomiarowej, oraz sprawdza, czy niedostępne pytania nie przeciekają. |
| `scripts/pomiar-870-liczba-pytan.sql` | Zapytanie dla właściciela: ile publicznych pytań naprawdę jest. Same agregaty, transakcja tylko do odczytu. |
| `tests/Feature/SkryptPomiaru870OdmawiaPozaBazaPomiarowaTest.php` | Skrypt czyści własne wiersze, więc test pilnuje bezpiecznika: odmowa (kod wyjścia 1) poza bazą `kuking_pomiar_870*`. |

Skrypt nie profiluje aplikacji, niczego w niej nie loguje, nie łączy się z API
AI i odmawia pracy na produkcji, hoście innym niż lokalny i bazie o innej nazwie.

```bash
createdb kuking_pomiar_870
DB_DATABASE=kuking_pomiar_870 php artisan migrate --force
DB_DATABASE=kuking_pomiar_870 php scripts/pomiar-870.php 2000 5 > wynik-870.json
```

Argumenty: liczba pytań w tle (domyślnie 2000) i powtórzenia (domyślnie 5).
Dane deterministyczne (ziarno 870): 400 kont aktywnych, 1 zbanowane, 1 widz
z blokadami w obie strony, pytania w tle z podobnymi tytułami („Dlaczego
sernik opada po wyjęciu z piekarnika?”), połowa bez opisu (`body` = null),
około 0,8 odpowiedzi na pytanie (żeby licznik odpowiedzi z `QuestionList`
kosztował realnie), 12 pytań-celów z rzadkim tematem rozłożonych w czasie.

## Warianty

Wszystkie budują się na `QuestionList::query($widz)`, więc widoczność, blokady,
status autora, publikacja i flaga działu są dokładnie takie jak na liście
(kryterium odbioru z issue). Wariant dokłada tylko warunek na tytule:

| Wariant | Zapytanie |
|---|---|
| S1 | `title ILIKE '%fraza%'` — ciągły fragment, wrażliwy na ogonki |
| S2 | każde słowo frazy: `kuking_normalize(title) LIKE '%słowo%'` — słowa niekoniecznie obok siebie, bez ogonków |
| S3 | `fraza <% kuking_normalize(title)` — trigramy `pg_trgm`, próg 0,5 jak w `ProgPodobienstwa`, sortowanie po `word_similarity` |

Metaznaki `%` i `_` idą przez `FrazaWyszukiwania::doLike()` (jak w #753). Dla S3
fraza nie jest cytowana, bo to nie jest `LIKE`.

Dla każdego z 12 celów cztery rodzaje frazy: **słowa z tytułu** (dwa
zapamiętane słowa, np. „zakwas opada”), **bez ogonków** („sledzie”),
**literówka** (przestawione litery) i **inna forma wyrazu** („zakwasu”
zamiast „zakwas”). Powodzenie = właściwe pytanie jest na pierwszej stronie
wyników (15 pozycji, `kuking.feed.page_size`). Cel bywa z `body` = null
(połowa) — wynik nie zależy od opisu.

## Wyniki lokalne (PostgreSQL 18.6)

Środowisko: 4 rdzenie dzielone z innymi agentami, więc czasy szumią. Czas =
mediana z 5 przebiegów całego zapytania po 12 celach i 4 rodzajach fraz.
Liczby są z dwóch przebiegów (2 000 i 20 000 pytań w tle), oba dla gościa
i dla zalogowanego z blokadami; powodzenie wyszło **identyczne** dla obu
rozmiarów i obu widzów.

### Powodzenie (pierwsza strona, 12 celów)

| Wariant | Słowa z tytułu | Bez ogonków | Literówka | Inna forma wyrazu |
|---|---:|---:|---:|---:|
| S1 `ILIKE %fraza%` | 5/12 | 3/12 | 0/12 | 0/12 |
| S2 słowa AND, znormalizowane | **12/12** | **12/12** | 0/12 | 1/12 |
| S3 trigramy `<%` | 11/12 | 11/12 | 5/12 | 9/12 |

Wnioski jakościowe:

- S1 gubi to, co pamiętają ludzie: jeśli słowa nie stoją obok siebie w tytule
  („zakwas jest za suchy” a szukane „zakwas suchy”) albo brak ogonków, nie
  znajduje nic. Nie nadaje się jako domyślne.
- S2 jest dokładne dla słów z tytułu, z ogonkami i bez. Nie zna literówek
  ani odmiany.
- S3 łapie odmianę i część literówek, ale nie wszystkie (5/12 i 9/12), a przy
  podobnych tytułach jedno z 12 nie zmieściło się na pierwszej stronie
  (przy 200 pytaniach w tle było 12/12). „Rozumienia języka” nie ma i nie
  należy go obiecywać (zgodnie z granicami issue): ustalone zachowanie to
  „znajduje tytuły zawierające te słowa, a trigramy pomagają przy drobnych
  różnicach”.

### Czas i plany

| Pytań | Wariant | Indeks | Czas żądania: gość / zalogowany | `EXPLAIN` wykonanie: gość / zalogowany |
|---:|---|---|---:|---:|
| 2 000 | S1 | brak | 8,7 / 10,5 ms | 2,1 / 5,6 ms |
| 2 000 | S2 | brak | 15,3 / 16,7 ms | 23,6 / 21,3 ms |
| 2 000 | S2 | GIN trigram | 2,6 / 5,0 ms | 0,4 / 0,5 ms |
| 2 000 | S3 | brak | 74 / 85 ms | 41 / 49 ms |
| 2 000 | S3 | GIN trigram | 16 / 19 ms | 10,0 / 13,1 ms |
| 20 000 | S1 | brak | 75 / 85 ms | 56 / 59 ms |
| 20 000 | S2 | brak | 129 / 112 ms | 361 / 35 ms* |
| 20 000 | S2 | GIN trigram | 1,3 / 2,9 ms | 3,1 / 13,0 ms |
| 20 000 | S3 | brak | 571 / 589 ms | 515 / 393 ms |
| 20 000 | S3 | GIN trigram | 91 / 93 ms | 66 / 103 ms |

\* Zalogowany dostał plan po `posts_questions_published_idx` (kolejność
kursora, filtr po drodze), gość — skan sekwencyjny. Plan zależy od statystyk i
selektywności frazy; nie wyciągam z tej jednej komórki wniosku o różnicy
między widzami.

- Liczba zapytań na zadanie: **1** (główne zapytanie listy z podzapytaniem
  licznika odpowiedzi; eager load kart dochodzi jak na dzisiejszej liście).
- Bez indeksu każdy wariant to skan sekwencyjny `posts` z filtrem; koszt rośnie
  liniowo z liczbą pytań. Trigramy bez indeksu (S3) dochodzą do ~0,6 s przy 20 000.
- Indeks trigramowy `GIN (kuking_normalize(title) gin_trgm_ops) WHERE kind = 'question'`
  zmierzony w bazie pomiarowej: 256 kB przy 2 000 pytań, 2 072 kB przy 20 000.
  S2 spada do pojedynczych milisekund, S3 do ~90 ms (sortowanie po
  `word_similarity` wymaga policzenia dla kandydatów). To **pomiar przed
  doborem indeksu, nie migracja**: w aplikacji odpowiednikiem byłaby kolumna
  generowana `title_search` + GIN, jak `recipes.title_search`
  (`2026_09_09_100000_materialize_search_columns`), czyli osobna migracja z
  rollbackiem i wpisem w `docs/DATABASE.md` po decyzji.
- Uczciwe granice: przebieg 100 000 pytań przerwałem (S3 bez indeksu ~3 s na
  zapytanie, kilkadziesiąt minut przebiegu) — rząd wielkości ponad to, co
  Poradźcie może mieć wkrótce. Wnioskowanie o 100 000 z tych liczb jest
  ekstrapolacją. Indeks częściowy `posts_questions_published_idx` już istnieje
  (#372) i nie pomaga w szukaniu po tytule.

### Obecna droga (lista `/pytania`, najnowsze, gość)

| Pytań widocznych dla gościa | Stron do celu (średnio) | Stron do celu (najwyżej) | Czas jednej strony |
|---:|---:|---:|---:|
| 2 014 | 67,7 | 129 | 5,7 ms |
| 20 014 | 667,7 | 1 279 | 3,8 ms |

Cele są rozłożone równomiernie w czasie, więc to średnia po całym archiwum;
świeże pytanie jest na pierwszej stronie. Filtry „bez odpowiedzi” i tagi
skracają drogę tylko wtedy, gdy człowiek pamięta tag albo stan. To czas
zapytań, nie czytania — jak długo trwa przewijanie, mierzy krok 2.

### Dostępność treści: nic nie przecieka

Sześć pytań niedostępnych dla gościa (ukryte, usunięte, autor zbanowany, tylko
dla obserwujących, prywatne, szkic) i dwa niedostępne dla widza (autor
zablokowany przez widza, autor blokujący widza) z unikalnym słowem w tytule.
Szukane każdym wariantem, jako gość i jako widz:

| Widz | Niedostępnych w wynikach | Licznik trafień słowa | Oczekiwane |
|---|---:|---:|---|
| gość | 0 | 2 | 2 (dwa pytania z blokadami są dla gościa publiczne) |
| zalogowany z blokadami | 0 | 0 | 0 |

Wynik jest gwarantowany konstrukcją (wspólna baza `QuestionList`), a nie
osobnym filtrem; pomiar potwierdza, że dokładanie warunku na tytule nie
otwiera drogi obejściem. Przy implementacji ten sam scenariusz musi być
testem z kontrolą ujemną (zamiana `->where` na `->orWhere` musi go zaświecić).

## Krok właściciela 1 — ile pytań jest do odnalezienia (produkcja, odczyt)

Wymaga produkcji, więc nie został wykonany:

```bash
psql "$DATABASE_URL" -X -f scripts/pomiar-870-liczba-pytan.sql
```

Zapytanie zwraca jeden wiersz liczb (bez tytułów i treści). **Do raportu
wklej tylko ten wiersz.** Jeśli publicznych pytań jest tyle, ile mieści się
na 1–2 stronach `/pytania` (15 na stronę), szukanie po tytule nie ma czego
szukać: pomysł zostaje odłożony, a issue zamknięte z opisem „za mało pytań”.
Jeżeli dział jest jeszcze wyłączony (`KUKING_QUESTIONS_ENABLED`), pomiar
potrzeby wraca po jego włączeniu.

## Krok właściciela 2 — badanie z 5–8 osobami

Czas i rezygnację człowieka mierzą tylko ludzie; skrypt tego nie zastępuje.

1. Wybrać 10–20 **publicznych** pytań i dla każdego napisać frazę tak, jak
   ktoś by ją pamiętał („było pytanie, dlaczego chleb opada”). Fraza nie może
   zawierać prywatnych treści; pytania są publiczne.
2. Każda z 5–8 osób dostaje 4–5 fraz i szuka **obecną drogą** (lista, filtr
   „bez odpowiedzi”, tagi). Zapisywane: czy znalazła właściwe pytanie, czas do
   znalezienia (stoper), czy zrezygnowała. Bez nagrywania ekranu i bez notatek
   z prywatnych treści.
3. Porównanie z „prostym szukaniem po tytule” wymaga prototypu: wariant S2
   (słowa AND, znormalizowane) to jedno zapytanie na `QuestionList` i jedno
   pole na `/pytania`; nie jest w repozytorium, bo issue wymaga najpierw
   kroku 2 z obecną drogą. Jeśli czasy i rezygnacje przy obecnej drodze są złe,
   prototyp za flagą można przygotować jako osobne zadanie.
4. Nie deklarować poprawy retencji ani spadku duplikatów bez danych; badanie
   mierzy wyłącznie znalezienie, czas i rezygnację.

| Osoba | Fraza | Znalazła? | Czas | Zrezygnowała? | Uwagi |
|---|---|---|---|---|---|
| | | | | | |

## Jeśli potrzeba się potwierdzi — decyzje do podjęcia przed kodem

1. **Gdzie**: sekcja głównego „Szukaj” (`SearchController`, obecnie
   przepisy/ludzie/szybkie, a „Wszystko” nie obejmuje pytań) czy pole na
   `/pytania`. Pole na `/pytania` jest mniejsze (bez zmiany kontraktu
   „Wszystko”, bez nowej sekcji SEO) i pasuje do „wróć do rozmowy”.
2. **Wariant**: S2 z indeksem jest najtańszy i dokładny dla zapamiętanych
   słów; S3 dodaje tolerancję odmiany i literówek za cenę ~90 ms przy 20 000
   i nierówności wyników. Możliwe też S2 najpierw, S3 jako dopełnienie tylko
   przy braku wyników. Decyzja produktowa, nie techniczna.
3. **Zakres**: tytuł (pytanie zawsze ma tytuł, `posts_kind_title_check`) czy
   także `body`; treść opisów wymaga własnej kolumny/indeksu, więc nie „przy okazji”.
4. **Schemat**: kolumna `title_search` + GIN (migracja, test, rollback,
   `docs/DATABASE.md`, D-088) tylko jeśli pomiar produkcyjny pokaże, że S2
   bez indeksu jest za wolne przy realnej liczbie pytań.
5. **Bezpieczeństwo wejścia**: fraza `string` z `max:` (jak
   `SearchQuery::MAX_PHRASE_LENGTH` = 120), pusty tekst, tablica zamiast
   tekstu (`?q[]=`) i jej limit dają komunikat po polsku, nie 500; paginacja
   kursorem zachowuje frazę i filtry; wynik pokazuje tytuł, rodzaj treści
   („Pytanie”) i adres `questions.show`; przy braku wyników komunikat mówiący,
   co zrobić (np. „Zadaj własne pytanie”).
6. **UX 50+**: pole z etykietą, tekst ≥ 18 px, przycisk „Szukaj” ≥ 48 px,
   bez hover i bez podpowiedzi na najechanie; stan filtrów widoczny.
7. **Obietnica względem AI (#815)**: dopóki nie istnieje ta wyszukiwarka i
   lista dopuszczonych parametrów, pilot AI nie może obiecywać wyszukiwania
   pytań.
8. **Testy** z fizyczną kontrolą ujemną: wynik słów z tytułu także przy
   `body` = null, brak wycieku niedostępnych (ukryte, usunięte, blokada,
   status autora, flaga działu), granice wejścia, paginacja.
   Zmiana widoczna dla ludzi: wpis w `CHANGELOG.md` i akapit w
   `resources/nowosci/tresc.md`.

## Czego ten pomiar nie sprawdza

- Potrzeby ludzi, czasu przewijania i rezygnacji (krok 2).
- Liczby publicznych pytań na produkcji (krok 1).
- Literówek i odmiany polskiej poza 12 syntetycznymi przypadkami.
- Skali 100 000 pytań i PostgreSQL na Railway (tu lokalny 18.6 na współdzielonym CPU).
- Retencji, duplikatów pytań i jakości odpowiedzi AI.
