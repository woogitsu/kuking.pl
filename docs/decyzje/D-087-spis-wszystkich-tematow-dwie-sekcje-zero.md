## D-087 · Spis wszystkich tematów (`tags.index`) — dwie sekcje, zero rankingu

**Data:** 10 września 2026 · Status: **obowiązuje**

Druga połowa issue #273 (pierwsza — słownik tagów, D-026 — jest na `main`
od 7 września). Baza tagów bez strony, na której da się je zobaczyć, nie
rozwiązuje problemu; strona bez tagów też nie — cytat z issue.

### Co strona pokazuje

Nowa trasa publiczna `GET /tagi` (`tags.index`), bez logowania, w dwóch
sekcjach:

1. **„Polecane tematy"** — `Tag::promowane()` (D-021, „tag promowany —
   lista gospodarza"), w kolejności redakcyjnej z panelu
   `/admin/tagi-promowane` (`tag_promotions.position`). To jest dosłownie
   „promowanymi tagami" z cytatu PROD3 w issue #273.
2. **„Wszystkie tematy A-Z"** — `Tag::aktywne()` (czyli bez tagów
   ukrytych i scalonych), **alfabetycznie po `name`**, stronicowane
   istniejącym wzorcem „Pokaż więcej" (`<x-show-more>`), nie infinite
   scroll.

Każdy temat pokazuje swoją **prawdziwą** liczbę wpisów — także zero, bo
issue zakazuje wprost udawania żywej treści („wolno wgrać puste tematy do
przeglądania, nie wolno wgrać fałszywych wpisów, żeby wyglądały na żywe").
Pusty temat na tej liście prowadzi do tej samej strony `/tag/{slug}`,
która już dziś pokazuje `x-empty-state` „Tu jeszcze nikt nic nie ugotował"
— żadnego nowego stanu pustego nie trzeba było wymyślać.

### Dlaczego kolejność NIE jest rankingiem

Obie sekcje sortują po czymś, co nie zależy od popularności ani od tego, co
ktokolwiek zrobił z treścią:

- sekcja 1 sortuje po **wyborze gospodarza** — to samo pole, którego panel
  admina już używa do ustawienia kolejności listy promowanej; zmiana tej
  kolejności wymaga wejścia do panelu, nie zbierania „Ugotowałem";
- sekcja 2 sortuje **po alfabecie** — deterministyczne, przewidywalne,
  niezależne od ruchu na tagu. Dwa uruchomienia tego samego dnia dają
  identyczną kolejność, niezależnie od tego, ile osób odwiedziło który tag
  w międzyczasie.

Żadna z dwóch sekcji nie sortuje po `posts_count`, po liczbie
obserwujących ani po dacie ostatniego wpisu — to jest właśnie „ważenie
popularności", którego zakazuje `AGENTS.md` i przekazanie pracy z 10.09
(§9 pkt 3-4). Liczba wpisów jest wyłącznie **etykietą przy nazwie**, tak
jak Garnkowe „Jedzonko 34203 zdj." — samo w sobie nigdy nie decyduje
o miejscu tematu na liście.

### Skąd liczba wpisów, żeby była prawdziwa i tania

Liczba przy każdym tagu to `posts` policzone przez
`Post::publiclyVisible()->tylkoOdAktywnychAutorow()` — **ten sam** zakres,
którego komentarz w `Post::scopeTylkoOdAktywnychAutorow()` wymienia wprost
jako przeznaczony m.in. dla „feedu tematów". Świadomie NIE jest to
`Post::widoczneDla($widz)` z widoku pojedynczego tagu:

- `widoczneDla()` liczy się PER WIDZ (blokady, obserwowanie) — na liście
  z jednego zapytania dla setek tagów naraz oznaczałoby to inny wynik dla
  każdej zalogowanej osoby, czyli liczbę, której nie da się ani zmierzyć
  raz, ani wytłumaczyć („dlaczego u mnie 4, a u sąsiada 5?");
- `publiclyVisible()+tylkoOdAktywnychAutorow()` daje **jedną, tę samą**
  liczbę każdej osobie — i jest dokładnie tym, co zobaczy GOŚĆ wchodząc na
  `/tag/{slug}` (bo dla widza `null` `widoczneDla()` redukuje się do tego
  samego warunku). Dla zalogowanej osoby liczba na liście może być **niższa**
  niż to, co zobaczy po wejściu (jej własne wpisy, wpisy obserwowanych z
  widocznością „obserwujący") — nigdy wyższa. Niedoszacowanie w dobrą
  stronę jest bezpieczne z punktu widzenia zakazu sztucznego ruchu; zawyżenie
  nie byłoby.

Liczenie idzie jednym zapytaniem (`withCount(['posts' => ...])`), tą samą
techniką, którą `docs/product/PROSTOTA_JAK_GARNEK.md` §3 pkt 1 proponuje
wprost dla tej strony — bez zapytania na tag, czyli bez N+1. Pilnuje tego
`SpisTematowTest::test_strona_nie_generuje_zapytania_na_kazdy_tag`.

### Czego ta decyzja NIE robi tak, jak sugerował pierwotny szkic

`docs/product/PROSTOTA_JAK_GARNEK.md` §3 pkt 1 (napisany 10 września, przed
tym PR-em) proponował **jedną, niestronicowaną listę** wszystkich aktywnych
tagów — bo w chwili pisania tamtego dokumentu D-021 mówiło o „zamkniętej
liście ok. 30 tagów" (cytat z §5a tego samego pliku). Słownik z D-026,
scalony 7 września, ma **~1250 nazw kanonicznych** — jedna strona bez
podziału renderowałaby więc naraz ponad tysiąc odnośników, co jest dokładnie
tym rodzajem gęstości, przeciw któremu stoi cały ten dokument (por. sekcja
1a tamtego pliku o stronie głównej). Stąd stronicowanie w sekcji 2 —
zachowuje alfabetyczny, nieranking'owy porządek, tylko w kawałkach po
`config('kuking.tags.index_page_size')` (domyślnie 100).

### Co świadomie pominięto

1. **Filtrowanie / szukanie po literze albo kategorii.** `internal_category`
   jest jawnie „nigdy niepokazywana użytkownikowi" (`docs/DATABASE.md`,
   opis kolumny `tags.internal_category`) — użycie jej jako nagłówka sekcji
   publicznej strony złamałoby tę już zapisaną decyzję. Skok alfabetyczny
   (kotwice `#litera-a` itp.) też został pominięty: to jest wzbogacenie UX,
   nie brakujący element zakresu z issue #273, i zwiększa powierzchnię do
   testowania bez potrzeby MVP. Zgłoszone jako pomysł do osobnego issue,
   nie zrobione po cichu.
2. **Wykluczenie tagów promowanych z sekcji „Wszystkie tematy A-Z".**
   Tag promowany pojawia się w obu sekcjach. Wykluczenie wymagałoby
   dodatkowego warunku `whereNotIn` na liście promowanych ID przy każdym
   stronicowaniu; podwójne wystąpienie tego samego tematu (raz w sekcji
   redakcyjnej, raz w alfabetycznej) nie jest mylące — to ten sam wzorzec,
   co "polecane" i "wszystko" w sklepach czy bibliotekach.
3. **Odznaka „obserwujesz" przy tagu dla zalogowanej osoby.** Istnieje już
   na `/ustawienia/tagi` i na `/tag/{slug}`; dokładanie jej tutaj to kolejne
   zapytanie (`tag_follows` dla widza × strona wyników) bez wymogu z issue
   #273 — możliwe do dołożenia później, jeśli ktoś tego zabraknie w testach
   z użytkownikami.

**Pliki:** `routes/web.php` · `app/Http/Controllers/TagController.php` ·
`resources/views/pages/tags/index.blade.php` · `config/kuking.php` ·
`tests/Feature/SpisTematowTest.php`.
