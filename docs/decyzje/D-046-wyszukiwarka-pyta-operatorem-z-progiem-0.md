## D-046 · Wyszukiwarka pyta operatorem `<%` (`word_similarity`) z progiem 0,5, nie `%` z 0,12

**Data:** 9 września 2026 · **Decyzja właściciela** (issue #187) · Status: **obowiązuje**

Operator `%` z pg_trgm mierzy podobieństwo frazy do **całego** tytułu, więc
żeby literówka w długim tytule w ogóle trafiała („sernk" wobec „sernik babci
haliny" to 0,18), próg musiał zjechać do 0,12. Przy takim progu długa fraza
jest podobna do prawie wszystkiego. Zmierzone na bazie 40 000 przepisów,
w której nie ma ani jednej sajgonki: fraza „sajgonki z krewetkami" zwracała
**1 526 wyników**, „rosół" znajdował „Rogaliki", „barszcz" — „Bogracz",
„pierogi" — „Piernik". To nie wygląda na wyszukiwarkę, która czegoś nie ma;
wygląda na zepsutą.

**Co wybrano.** Operator `<%` — „czy fraza jest podobna do najlepiej
pasującego FRAGMENTU tekstu". Długość tytułu przestaje karać trafienie
(„sernk" wobec „sernik babci haliny" to już 0,67), więc próg może być wysoki,
a wysoki próg wycina śmieci. Ten sam indeks GIN, **zero migracji**.

**Dlaczego próg 0,5, a nie domyślne 0,6 z issue.** Bo 0,6 gubi rzeczy, po
które ludzie przychodzą: „rosul" (tak wygląda „rosuł" bez ogonków) przestaje
znajdować rosół, „piergi" przestaje znajdować pierogi, a „kotlet schabowy
z ziemniakami" znajduje 45 przepisów zamiast 232. Przy 0,5 wszystkie trzy
wracają, a kanarki („sajgonki z krewetkami", „kartacze", „tortilla
z kurczakiem") dalej zwracają zero.

**Co ta decyzja KOSZTUJE — zmierzone, nie oszacowane.** Ciężka literówka
fonetyczna przestaje działać: „gołombki" nie znajduje już „Gołąbków" (0,42
przy progu 0,5) ani w wyszukiwarce, ani w podpowiedziach tagów. Długa fraza
opisowa przestaje zaciągać dania pokrewne po jednym słowie: „pierogi ruskie
babci haliny" nie pokazuje już „Pierogów z mięsem". Pełna lista zgubionych
trafień, z nazwami, jest w `docs/research/WYDAJNOSC.md` §3.4b — właściciel
podejmował tę decyzję, widząc cenę.

**Zakres.** Zmiana objęła OBIE ścieżki podobieństwa: `SearchQuery::recipes()`
i czwartą gałąź `TagSuggester`. Dwie ścieżki z dwoma różnymi progami
znaczyłyby, że słowo „podobne" ma w jednym produkcie dwa znaczenia zależnie
od pola, w które człowiek pisze. `SearchQuery::people()` nie używa operatora
podobieństwa (dopasowuje `LIKE`) i została bez zmian.

**Kolejność wyników** poszła za operatorem: `word_similarity` DESC, potem
`similarity` DESC. Rozstrzygnięte pomiarem, nie teorią — przy samym
`similarity` 722 przepisy „Pierogi …" stały za pierwszym „Piernikiem".

**Zmiana wymaga:** powtórzenia pomiaru z §3.4b. Próg to jedna stała
(`App\Support\ProgPodobienstwa::PROG`) i jedno miejsce — jeśli ktoś uzna, że
„gołombki" są ważniejsze niż czystość wyników przy „pierogach", zejście do
0,4 jest zmianą jednej liczby. Ale to jest decyzja produktowa, nie techniczna.

📄 `app/Support/ProgPodobienstwa.php` · `app/Domain/Search/SearchQuery.php` ·
`app/Domain/Tags/TagSuggester.php` · `tests/Feature/TrafnoscWyszukiwarkiTest.php` ·
`docs/research/WYDAJNOSC.md` §3.4b · `docs/DATABASE.md`
