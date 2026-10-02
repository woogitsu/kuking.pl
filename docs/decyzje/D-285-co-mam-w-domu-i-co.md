## D-285 — „Co mam w domu” i „Co ugotuję z tego, co mam”: prywatna lista, dopasowanie bez AI, jawna reguła doboru (V2, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Wykonanie **D-282**
(decyzja właściciela z 26 września 2026 dopuszczająca funkcje V2 z
`docs/FEATURES.md`: *pantry* i *„co ugotuję z tego, co mam”*) · Gałąź
`claude/v2-pantry`

**Problem.** Kuking ma słownik składników i składniki przepisów zapisane
wierszami (`recipe_ingredients.ingredient_text`, `ingredient_id` →
`ingredients`), ale nie ma jak odpowiedzieć na najczęstsze kuchenne pytanie:
„co zrobię z tym, co mam w lodówce?”. Wyszukiwarka odpowiada na jedno słowo,
nie na listę produktów.

**Decyzja — zakres v1 (bez AI).**

1. **Prywatna lista „Co mam w domu”** (`/co-mam-w-domu`, wejście z zeszytu):
   jedno duże pole, przycisk „Dodaj do listy”, lista z przyciskami „Usuń”.
   Podpowiedzi pod polem pochodzą ze słownika składników, ale **tylko
   z przepisów publicznych, opublikowanych, od aktywnych kont** — słownik
   rośnie też ze szkiców i przepisów „tylko dla mnie”, a podpowiedź z nich
   zdradzałaby cudzą linijkę. Kolejność podpowiedzi: najkrótsza nazwa
   najpierw, potem alfabet (nie „najczęściej używane”). Podpowiedzi rysuje
   skrypt jako przyciski (18 px, 48 px), nie `<datalist>`; bez skryptu pole
   i przycisk działają tak samo (D-053). Lista jest widoczna wyłącznie dla
   właściciela (`PantryItemPolicy`, bez wyjątku dla moderatora), trafia do
   paczki danych (`InwentarzDanychKonta`, sekcja `co_mam_w_domu`) i znika
   przy wymazaniu konta (`EraseAccountData`). Limit: 150 produktów —
   egzekwowany pod blokadą wiersza właściciela listy (`CoMamWDomu::dodaj()`
   bierze `lockForUpdate()` na wierszu `users` przed liczeniem i zapisem),
   bo bez niej dwa równoległe żądania na koncie z 149 produktami mogły oba
   przejść limit (#1958, test na dwóch połączeniach:
   `tests/Dwa/PantryLimitNaDwochPolaczeniachTest.php`).
2. **„Co ugotuję z tego, co mam”** (`/co-ugotuje`): przepisy, w których
   pasuje co najmniej jeden składnik z listy, a przy każdym zdanie
   „Masz 5 z 7 składników. Brakuje: …” (brakujące linijki dosłownie tak,
   jak napisał je autor przepisu).

**Reguła doboru — jednym zdaniem, pokazana też na ekranie**
(`CoUgotuje::REGULA`): *najpierw przepisy, do których masz najwięcej — czyli
brakuje w nich najmniej składników z Twojej listy; przy tej samej liczbie
brakujących najpierw te, które zajmują najmniej czasu.* Przepis bez podanego
czasu idzie na koniec remisu (ta sama zasada co „Do 30 minut”), a czas
publikacji i `id` tylko ustalają kolejność między stronami. **Żadna reakcja
innych ludzi** („Ugotowałem”, zapisy, obserwujący, komentarze) nie wpływa na
kolejność — AGENTS.md §8 i §12. `FeedNieSortujePoMierzeReakcjiTest` skanuje
teraz cały katalog `app/Domain/Pantry` (kotwica: `CoUgotuje.php`), a dwa
sortowania po wyrażeniu i kolejność podpowiedzi mają wpisy w jego rejestrze.
Kontrola ujemna wykonana: `orderByDesc('cooked_events_count')` w `CoUgotuje`
oblewa strażnika. Widoczność przepisów: ta sama co przy otwarciu
(`published()` → `widoczneDla()`, aktywne konto autora, blokady w obie strony).

**Dopasowanie składników (bez AI).** Jedna funkcja w bazie,
`public.kuking_rdzenie_skladnika(text)`: `kuking_normalize()` (małe litery,
bez polskich znaków) → podział na słowa → odrzucenie liczb i słów
jednoliterowych → **słownik form krótkich słów**
(`public.kuking_formy_skladnikow()`: mąka/mąki/mąkę → `maka`, mak/maku →
`mak`, ser/sera → `ser` …) → poza słownikiem „liczba mnoga prosta” tylko
dla dłuższych słów (słowo > 5 liter na „-ow” traci „ow”, słowo > 4 liter
traci końcową samogłoskę; krótsze zostają całe). Produkt z listy pasuje do
linijki składnika, gdy **wszystkie** jego rdzenie są w rdzeniach linijki
(`<@`). Z tej samej funkcji generują się kolumny `pantry_items.rdzenie`
i `klucz`, więc reguła nie ma drugiej kopii w PHP. `UNIQUE (user_id, klucz)`
nie pozwala dopisać „jajko”, gdy na liście są „Jajka”.

Znane granice tej prostoty (świadome, do rewizji po pomiarze):
- dopełniacz i inne przypadki spoza „liczby mnogiej prostej” nie łączą się:
  „cukru” ≠ „cukier”, „jajek” ≠ „jajka”;
- ~~rdzenie bywają wspólne dla różnych rzeczy: „mąka” i „mak” (oba `mak`)~~ —
  **naprawione (#1969)**: przy krótkim słowie końcówka niesie znaczenie,
  więc rdzeń krótszy niż 4 litery powstaje już tylko ze słownika form, gdzie
  każda forma jest wpisana ręcznie; krótkie słowo spoza słownika pasuje
  tylko w tej samej formie (brak dopasowania zamiast fałszywego „masz”).
  Test par: `CoUgotujeTest::test_rdzenie_nie_lapia_sie_nawzajem_a_odmiany_dalej_pasuja`
  (mąka ≠ mak, mak ≠ makaron, lód ≠ lody …; mąka = mąki/mąkę, ser = sera …);
- produkt ogólny pasuje do odmiany: „mleko” zalicza „mleko kokosowe”,
  „ser” — „ser pleśniowy”;
- **doprecyzowanie błędu #2613:** rdzeń produktu wymieniony wyłącznie po
  słowie „bez” nie zalicza całej linijki. „Sól” nie potwierdza „masła bez soli”,
  natomiast samo „masło” nadal potwierdza tę linijkę. Zakres „bez …” kończy
  interpunkcja (przecinek, średnik, kropka, wykrzyknik albo pytajnik), więc
  produkt zapisany osobno poza przeczeniem może pasować. Przy niejasnej
  końcówce do granicy interpunkcji reguła zachowawczo pokazuje brak;
- sól, pieprz i woda liczą się jak każdy inny składnik — kto ich nie wpisze,
  zobaczy je w „Brakuje”.

**Propozycja na później (NIE wdrożona): dopasowanie przez AI.** Gdy pomiar
pokaże, że powyższe granice realnie przeszkadzają, dopasowanie produktu do
linijki składnika może zaproponować model OpenAI „GPT-6 Luna”, wybierany
konfiguracją, nie kodem — wzorcem z D-282 (`config/kuking.php` + zmienna
w `.env`/`.env.example`, np. `config('kuking.pantry.dopasowanie_ai.model')`
i `klucz` z `env()`; brak klucza = funkcja wyłączona i nic nie pada; klucz
nigdy w kodzie). Ta gałąź nie dodaje tych kluczy konfiguracji, bo nic ich
jeszcze nie czyta. Granice propozycji: model dostaje wyłącznie parę
„nazwa produktu / linijka składnika” (bez danych konta), zwraca tylko
tak/nie, wynik trafia do pamięci podręcznej po parze rdzeni, reguła bez AI
zostaje ścieżką domyślną i awaryjną (timeout, budżet, awaria), a model
**nie wpływa na kolejność** — reguła doboru zostaje zdaniem wyżej. Przed
włączeniem: zestaw ~100 par testowych i porównanie z regułą bez AI, tak jak
w #815; bez wyraźnej poprawy — nie włączać.

**Czego ta decyzja nie zmienia.** AGENTS.md §12 nadal wymienia spiżarnię
jako „świadomie nie budujemy teraz” — dopisanie wyjątku tam jest decyzją
właściciela, której ta gałąź nie podejmuje za niego.

### Wycofanie
Ekrany znikają po zdjęciu tras `pantry.*` i wejścia z zeszytu — bez
ruszania bazy. Cofnięcie schematu (`2026_09_28_233700_create_pantry_items_table`)
usuwa tabelę i obie funkcje, ale przy niepustej tabeli **odmawia** (D-088):
listy to dane wpisane przez ludzi. Świadome wymuszenie po kopii:
`KUKING_ROLLBACK_KASUJE_SPIZARNIE=1`. Pilnuje
`CofniecieMigracjiNieKasujeListCoMamWDomuTest` (odmowa, kontrola dodatnia
na pustej tabeli, wymuszenie).
