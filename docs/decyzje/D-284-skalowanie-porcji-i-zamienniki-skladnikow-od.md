## D-284 — Skalowanie porcji i zamienniki składników od autora, bez AI (V2, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Zakres dopuszczony przez
właściciela 26 września 2026 (funkcje V2, D-282) · Dotyczy `docs/FEATURES.md` (V2:
„skalowanie porcji”, „zamienniki”), #750/#1642 (porcje w setnych)

**Problem.** Strona przepisu pokazywała ilości wyłącznie na liczbę porcji
autora. Kto gotuje dla dwojga z przepisu na sześć osób, liczył w pamięci —
a „⅓ szklanki razy ⅓” to rachunek, którego przy garnku nikt nie chce robić.
Autor nie miał też miejsca na „zamiast masła: margaryna”; wpisywał to w uwagę
do składnika (placeholder kreatora wprost podpowiadał „albo masło roślinne”).

**Decyzja.**

1. **Skalowanie porcji na stronie przepisu.** Nad listą składników widz ma
   „Na ile porcji?” z przyciskami „− Mniej” / „Więcej +” (linki GET
   `?porcje=N#skladniki`, działają bez JavaScriptu). Zakres 1–100, krok do
   pełnej liczby; wartość z przepisu autora zawsze przyjęta. Po przeliczeniu:
   „Przeliczone na N porcji. Autor podał ilości na M porcji…” i link
   „Pokaż ilości z przepisu”. Zła wartość w adresie pokazuje przepis autora
   i zdanie, co zrobić. Przepis bez liczby porcji nie ma wyboru.
2. **Ilość czytana z tekstu wiersza w chwili pokazania, nic nie jest
   zapisywane.** Składnik to jedno pole wolnego tekstu (D-017), `quantity`
   i `unit_id` są puste. `App\Domain\Recipes\Porcje\PrzeliczSkladnik` szuka
   liczby na początku wiersza, po myślniku/dwukropku albo przed znaną
   jednostką; „szklanka mąki” bez liczby to jedna szklanka. Przeliczana jest
   tylko ta jedna liczba, reszta zdania autora zostaje co do znaku.
3. **Zaokrąglenie kuchenne** (`IloscKuchenna`): g/dag/ml do kroku 0,1 → 0,5 →
   1 → 5 → 10 → 50 zależnie od wielkości; kg/l dziesiętnie co 0,05; łyżki,
   szklanki, sztuki i rzeczy bez jednostki — ułamki ½ ¼ ¾ ⅓ ⅔ (⅛ poniżej ¼)
   do 5, połówki do 10, całości powyżej. Wynik nigdy nie jest zerem.
4. **Nie przeliczamy:** składnika „Bez ilości” (`no_amount`, #44), szczypty,
   odrobiny, „do smaku”, „ile weźmie”, „na oko”, „według uznania” i wiersza
   bez liczby. Jednostki słowem odmieniamy („1 łyżka / 3 łyżki / 5 łyżek /
   ½ łyżki”), skrótów nie („200 g”).
5. **Zamienniki od autora:** nowa kolumna
   `recipe_ingredients.substitutes varchar(300) NULL` (CHECK: nie pusta), pole
   „Czym można to zastąpić (nieobowiązkowe)” w kreatorze i w formularzu bez
   JavaScriptu, na stronie przepisu i w trybie gotowania linia
   „Zamiast tego: …” (18 px) pod składnikiem. Zamiennik jest w eksporcie
   danych (`przepisy[].skladniki[].zamienniki`), w eksporcie HTML przepisu
   i w `recipe_versions.snapshot`.

**Znana granica.** Rzeczownika bez jednostki nie odmieniamy: „2 jajka” razy
2,5 daje „5 jajka”, „1 cebula” razy 1,5 — „1½ cebula”. Poprawna odmiana
wymaga słownika odmiany produktów. Łagodzi to informacja „Przeliczone na N
porcji” i powrót jednym dotknięciem. Tryb gotowania pokazuje ilości autora
(parametr `porcje` nie przechodzi do `/gotuj`) — do decyzji, czy przenosić.

**Czego świadomie NIE ma w tym kroku — propozycja na później.** Zamienniki
podpowiadane przez AI. Model AI projektu ma być według zlecenia OpenAI
„GPT-6 Luna”, wybierany konfiguracją — w repozytorium takiego wpisu jeszcze
nie ma (dziś `config/kuking.php` zna tylko `omni-moderation-latest` do
moderacji), więc to też część przyszłego issue. Propozycja: przy składniku bez zamiennika autorskiego przycisk
„Podpowiedz zamiennik” (na żądanie widza, nie automatycznie), wynik wyraźnie
podpisany jako podpowiedź automatu, a nie słowo autora, nigdy nie zapisywany
w przepisie bez zgody autora; alergeny i bezpieczeństwo żywności — zgodnie
z `docs/decyzje/PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`. Wymaga osobnego issue
z kosztami, limitem zapytań (`config/kuking.php`) i decyzją właściciela.

**W kodzie.** `app/Domain/Recipes/Porcje/` (`WyborPorcji`, `PrzeliczSkladnik`,
`IloscKuchenna`, `JednostkaKuchenna`), `resources/views/pages/recipes/_wybor-porcji.blade.php`,
migracja `2026_09_26_100000_add_substitutes_to_recipe_ingredients`. Testy:
`tests/Unit/PrzeliczSkladnikTest.php`, `tests/Feature/SkalowaniePorcjiNaStroniePrzepisuTest.php`,
`tests/Feature/ZamiennikiSkladnikowTest.php`, `tests/Feature/CofniecieMigracjiNieKasujeZamiennikowTest.php`.

### Wycofanie
Skalowanie nie zmienia danych — wycofanie kodu przywraca stronę sprzed D-284.
Kolumna `substitutes`: `down()` migracji odmawia, gdy choć jeden składnik ma
zamiennik (D-088); wtedy wycofujemy sam kod i zostawiamy kolumnę, albo po
zapisaniu danych ustawiamy `KUKING_ROLLBACK_KASUJ_ZAMIENNIKI=true`.
