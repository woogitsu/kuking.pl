## D-055 · Druga para oczu to model OpenAI, który podnosi rękę — nigdy nie zamyka drzwi

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Właściciel: *„model AI będzie, OpenAI daje darmowy model moderation coś tam"*,
a doprecyzowując: *„omni-moderation-latest, jego wprowadzić trzeba do
moderowania takiego, że przetwarza i daje »alarm« w panelu i ewentualnie na
maila"*.

Publikowane wpisy i komentarze — a przy wpisach także **zdjęcia** — idą do
`omni-moderation-latest`. Wynik powyżej naszego progu staje się kolejnym
`Sygnal`-em w tym samym zadaniu, które liczy sygnały lokalne z **D-052**,
i kończy się dokładnie tak samo: jedną pozycją w kolejce moderatora z powodem
napisanym po polsku. Treść zostaje widoczna, autor niczego nie zauważa.

### TO ŁAPIE INNĄ KLASĘ TREŚCI NIŻ NASZ REALNY PROBLEM

Moderation API ocenia **nienawiść, przemoc, treści seksualne
i samookaleczenie**. **Spamu nie ocenia w ogóle** — a spam jest tym, co
przyjdzie razem z falą z Garnek.pl: „zarobki z domu", odnośniki, numery
telefonu. To jest **uzupełnienie** sygnałów z D-052, nie ich zamiennik.
Zapisane wprost, bo inaczej ktoś uzna, że skoro jest AI, to spam mamy
załatwiony, i wyłączy tamte trzy jako zbędne.

### NAJWIĘKSZA WARTOŚĆ SĄ TU ZDJĘCIA

Kuking stoi na fotografiach obiadów wrzucanych przez nieznajomych. To jest
jedyna treść w tym serwisie, której **nikt nie przeczyta**, dopóki ktoś jej
nie zgłosi — tekst przynajmniej mija się z ludzkim okiem w feedzie. Wersja
`omni` ocenia obrazy i to jest powód, dla którego ta decyzja w ogóle ma
wartość większą niż „mamy AI".

Zdjęcie idzie jako `data:` z wariantu `thumb` przekodowanego do JPEG: wariant
nie ma EXIF-u, czyli współrzędnych kuchni, a `data:` zamiast adresu, bo
publiczny adres dla OpenAI byłby publiczny także dla wszystkich innych.

### DANE WYCHODZĄ POZA EOG — I DLATEGO NAJPIERW DOKUMENTY

Wysłanie treści do OpenAI to powierzenie przetwarzania podmiotowi w USA.
Zrobione RAZEM z kodem, nie po nim:

- `resources/legal/polityka-prywatnosci.md` — OpenAI w tabeli podmiotów
  przetwarzających, osobny akapit „co wysyłamy i czego NIE wysyłamy" oraz
  drugi wyjątek w akapicie o przekazywaniu poza EOG;
- `resources/legal/zasady.md` punkt 12 — informacja dla użytkownika, że treść
  jest oceniana maszynowo, i wprost, że **żadne z tych narzędzi niczego nie
  ukrywa, nie usuwa, nie blokuje ani nie ogranicza zasięgu** (DSA art. 14
  ust. 1);
- `UzasadnienieDecyzji::skadSprawa()` — autor decyzji dowiaduje się, że treść
  wskazało narzędzie oceniające maszynowo, a nie czyjeś zgłoszenie (art. 17
  ust. 3 lit. b i c).

Pilnuje tego `PolitykaPrywatnosciWymieniaKazdaUslugeTest` z PR #224: obecność
klasy `App\Moderacja\KlientOpenAI` w kodzie oblewa test, dopóki w polityce nie
padnie słowo „OpenAI".

**Do API nie idzie NIC identyfikującego autora** — ani adres e-mail, ani nazwa
konta, ani identyfikator wpisu, ani adres IP. To nie jest ostrożność na zapas,
tylko warunek tego, co napisaliśmy w polityce, i jedyny powód, dla którego ta
funkcja mieści się w minimalizacji danych (`AGENTS.md` §7). Treści prywatne
nie wychodzą w ogóle.

### GRANICA TA SAMA CO W D-052, TYLKO WAŻNIEJSZA

Model podnosi rękę, nigdy nie zamyka drzwi. Żadnego automatycznego ukrywania,
wyciszania ani blokowania na podstawie wyniku — poz. 3.10
(`docs/INSPIRATION_DECISIONS.md`) powstała dokładnie na taką sytuację. Model
uczony głównie na angielszczyźnie będzie się mylił na polskim, a już
zwłaszcza na języku, jakim mówi o jedzeniu siedemdziesięcioletnia kobieta
z Podkarpacia. Fałszywy alarm kosztuje jedną pozycję w kolejce i nic więcej.

**Nie używamy pola `flagged` z API.** Progi trzymamy u siebie
(`moderation.model.prog`, domyślnie 0,5): cudza decyzja przy polszczyźnie
i kuchni bywa hojna — „zabiłam kurę na rosół", „krwisty stek", „ubić pianę" —
a każde trafienie kosztuje uwagę jedynego moderatora. Kategorie pilne mają
próg NIŻSZY (0,2): tam wolimy fałszywy alarm od przeoczenia.

### POCZTA: ZBIORCZO, BO INACZEJ PRZESTANIE BYĆ CZYTANA

Jeden list na każdą oznaczoną treść zamieniłby przy fali migracyjnej skrzynkę
moderatora w śmietnik — a skończyłoby się tym, że przestałby te listy
otwierać, czyli alarm przestałby działać dokładnie wtedy, gdy jest potrzebny.

- **Podsumowanie zbiorcze** raz dziennie o 07:00
  (`kuking:podsumowanie-automatu`). Nie wychodzi, gdy nie ma o czym pisać.
- **List natychmiastowy** wyłącznie dla `KategorieModeracji::PILNE` — treści
  seksualnych i wszystkiego, co dotyczy dzieci. To jest CAŁA lista i ma taka
  zostać: gdyby „pilne" znaczyło pięć rzeczy, rozróżnienie przestałoby
  cokolwiek znaczyć.

Drugi, niezależny powód tego ograniczenia: EmailLabs na planie darmowym daje
**300 listów dziennie**, dzielone z potwierdzeniami rejestracji. Alarmy
moderacyjne nie mogą zjeść limitu potrzebnego na to, żeby ktoś w ogóle mógł
założyć konto.

### JEDNO ZADANIE, NIE DWA

Ocena modelem dolicza się do sygnałów lokalnych w tym samym
`PrzeanalizujTresc`. Dwa osobne zadania próbowałyby postawić dwa oznaczenia
tej samej treści, a indeks `reports_jeden_automat_na_tresc` (D-052)
przepuściłby tylko to, które wygrało wyścig — ocena modelu potrafiłaby wtedy
przepaść dlatego, że wpis zawierał numer telefonu.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia:** wyczyszczenie `OPENAI_MODERATION_KEY`.
   `KlientOpenAI::oceniamy()` oddaje wtedy `false`, żadne żądanie nie
   wychodzi, sygnały lokalne z D-052 działają dalej bez zmian.
2. **Wyłączenie samych listów:** wyczyszczenie `KUKING_MODEL_ALARM_EMAIL` —
   zostaje sama kolejka w panelu.
3. **Wycofanie kodu:** rewert commita. **Nie ma migracji ani zmiany
   schematu** — `automat_model` to kolejna wartość w `reports.reason`, kolumna
   bez CHECK-u.
4. Przy trwałym wycofaniu trzeba zdjąć OpenAI z polityki prywatności
   i z punktu 12 zasad — dokument nie może wymieniać dostawcy, do którego nic
   nie wychodzi.

**Zmiana wymaga:** pomiaru z `kuking:raport-sygnalow`, nie wrażenia.
Podniesienie albo obniżenie progu to zmiana liczby pozycji w kolejce —
i wyłącznie tego.

📄 `app/Moderacja/KlientOpenAI.php` · `app/Moderacja/OcenaModelem.php` ·
`app/Moderacja/WynikOceny.php` · `app/Moderacja/KategorieModeracji.php` ·
`app/Notifications/PilnyAlarmModeracyjny.php` ·
`app/Notifications/PodsumowanieKolejkiAutomatu.php` ·
`app/Console/Commands/PodsumowanieAutomatu.php` ·
`app/Jobs/PrzeanalizujTresc.php` · `config/kuking.php` (`moderation.model`) ·
`routes/console.php` · `resources/legal/polityka-prywatnosci.md` ·
`resources/legal/zasady.md` (punkt 12) ·
`app/Domain/Moderation/UzasadnienieDecyzji.php` ·
`tests/Feature/ModeracjaModelemTest.php` ·
`docs/legal/SYGNALY_AUTOMATU.md` §8 · `docs/MODERATION.md`
