## D-242 — Wyjęcie z zeszytu jest odwracalne co do notatki: rdzeń z #1110 na ekranach z #1168 (#775, D-224, D-230, D-231, 22 września 2026)

**Decyzja właściciela: rdzeń z #1110, ekrany z #1168.** #1168 weszło na
`main` samo, z rdzeniem, który przy wyjęciu nadal kasował notatkę
bezpowrotnie. Tabela `collection_items` nie ma miękkiego kasowania ani
historii, więc po `detach()` notatki własnej („mniej soli", „dla Ani bez
orzechów") nie ma skąd odtworzyć. Utrata cudzej notatki jest nieodwracalna,
a brak ekranu da się naprawić później — dlatego cofanie ma pierwszeństwo,
a ekrany z #1168 zostają, bo bez nich nie ma jak wskazać zeszytu.

### Co się zmienia wobec stanu po #1168

1. **`remove()` oddaje zdjęte wiersze, nie liczbę.**
   `SaveRecipeToCollection::remove()` i `SavePostToCollection::remove()`
   zwracają listę `{collection_id, note, created_at}` zamiast `int`. Liczba
   w komunikacie to `count()` tej listy, więc nadal jest faktyczna, nie
   deklarowana (D-230, D-231).
2. **Nowe `restore()`** odkłada zdjęte wiersze tam, skąd zeszły — z notatką
   i z pierwotnym `created_at`, więc zeszyt nie przestawia się na górę listy.
   Nie nadpisuje świeższego wiersza (ktoś zdążył zapisać ponownie), nie sięga
   zeszytu, który zniknął albo nigdy nie był tej osoby.
3. **Droga powrotu czeka w sesji, nie we flashu.** Flash żyje jedno żądanie,
   a droga powrotu ma trzy (DELETE, GET z przyciskiem, POST po kliknięciu).
   `saveRecipe()` i `savePost()` sprawdzają najpierw, czy to nie jest powrót
   po wyjęciu; gdyby zadziałały jak zwykły zapis, rzecz wróciłaby do zeszytu
   DOMYŚLNEGO z pustą notatką. Powrót jest jednorazowy. Przycisk brzmi
   **„Przywróć do zeszytu"**, nie „Zapisz ponownie", bo to jest teraz prawda.
4. **Pytanie przed akcją na stronie przepisu zdjęte** (sprostowanie D-230).
   Zakres ujawnia zdanie NAD przyciskiem, związane z nim przez
   `aria-describedby`: przy jednym zeszycie formularz niesie `collection_id`
   i nazywa ten zeszyt, przy kilku stoi ostrzeżenie z liczbą zeszytów
   i zapowiedzią przycisku powrotu.
5. **Jedna reguła własnego zeszytu** (`regulyWlasnegoZeszytu()`) dla zapisu
   i dla wyjęcia — dwie kopie granicy „nie wyjmiesz z cudzego zeszytu"
   rozjechałyby się przy pierwszej poprawce, a kontrola ujemna
   `scripts/kontrole-negatywne-alfa08.py` wymaga, żeby ta lista stała w kodzie
   dokładnie raz.

**Bez zmian:** jedna droga wyjęcia na ekran i napisy „Usuń z tego zeszytu" /
„Usuń z zeszytu" (D-231), edycja zeszytu (#777), licznik karty zeszytu (#774).

**Numer.** D-230 i D-231 są na `main` zajęte przez #1168, a D-232–D-241 oraz
D-243 (numery na gałęziach, nie na `main` w chwili tego wpisu) przez inne
gałęzie. Ta decyzja nosiła najpierw D-241, który wcześniej
wypchnęła `flota/scal-786` (#966), więc ustąpiła na D-242 (D-235: ustępuje
strona, która wzięła cudzy numer). Potem obie gałęzie ustąpiły sobie
nawzajem naraz: o 23:54Z `flota/scal-786` oddała D-242 tej decyzji i wzięła
D-243, a o 23:59Z ta decyzja — nie widząc tamtego pchnięcia, bo hak
`pre-push` trwa kilkanaście minut — przeszła na D-243. D-243 pierwsza
opublikowała `flota/scal-786`, więc ta decyzja wraca na D-242, który tamta
gałąź jej zostawiła. Cudzej gałęzi nie przenumerowano.

Dowody: `tests/Feature/WyjecieZZeszytuNieKasujeInnychZeszytowTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`scripts/wyjecie-z-zeszytu.mjs`.
