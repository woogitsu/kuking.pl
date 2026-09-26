## D-274 — „Jeden wpis na autora” (#940) jest nadrzędny wobec wpisu z własną treścią (#1377) (25 września 2026)

**Data:** 25 września 2026 · Status: **obowiązuje** · Decyzja właściciela ·
Dotyczy **#940**, **#1377**, PR-ów #1584, #1590, #1628

**Problem.** #1377 każe zostawić na listach wpis z WŁASNĄ treścią, gdy
przepis, na który wskazuje, stanie się niedostępny (prywatny, tylko dla
obserwujących, usunięty, ukryty przez moderację). #940 pokazuje na
„Świeżo z Kuking” i stronie powitalnej najwyżej jeden wpis od osoby —
najnowszy, który widz może zobaczyć. Testy #1584/#1590 zakładały, że autor
ma na odkrywaniu jednocześnie zapowiedź przepisu i starszy wpis z treścią,
co z #940 jest niemożliwe, więc CI było czerwone.

**Decyzja.** Reguła #940 jest nadrzędna. Wpis z własną treścią zostaje na
liście po ukryciu przepisu (bez tytułu, sluga i zdjęcia przepisu na karcie),
ale **nadal liczy się do limitu jednego wpisu na autora** — zajmuje to samo
jedno miejsce co każdy inny wpis tej osoby. Nowszy widoczny wpis autora go
wypiera; czysta zapowiedź niedostępnego przepisu nie zajmuje miejsca, bo
w ogóle nie jest widoczna. Strona tagu, profil i feed obserwowanych nie mają
limitu #940 i pokazują wpis z treścią zawsze, gdy widz może go otworzyć.

**W kodzie.** Bez zmian w zapytaniach: `DISTINCT ON (author_id)` z #940
działa na zbiorze już przefiltrowanym przez
`zWidocznymPrzepisemAlboWlasnaTrescia()`. Pilnuje tego
`ListyWpisuZWlasnaTresciaTest::test_wpis_z_wlasna_trescia_po_ukryciu_przepisu_liczy_sie_do_rund_autora`
(po D-276 w brzmieniu „liczy się do rund autora”)
(kontrola ujemna: pominięcie jednego wpisu na autora w „Świeżo z Kuking”
wywraca ten test), a `test_kontrola_dodatnia_*` sprawdza na odkrywaniu
najnowszy wpis autora, nie dwa naraz.

### Wycofanie
Decyzja nie zmienia schematu ani danych. Zmiana reguły (np. wyjątek od #940
dla wpisów z treścią) wymaga nowej decyzji właściciela i zmiany zapytania
listy odkrywania.
