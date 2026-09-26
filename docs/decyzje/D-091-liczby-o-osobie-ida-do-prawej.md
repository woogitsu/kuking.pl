## D-091 · Liczby o osobie idą do prawej szyny na szerokim ekranie, a na wąskim zostają w karcie — dwa egzemplarze w HTML, jeden na ekranie, bez JavaScriptu

**Zgłoszenie właściciela, dosłownie:** „jestem na profilu użytkownika, patrz
prawa kolumna jest marnowana, można tam dać info o użytkowniku (ile wpisów,
przepisów, obs, obserwuj itp itd, a nie na środku przez co wpisy są dużo
niżej".

> **Adnotacja z 20 września 2026 (audyt rejestru) — ROZBIEŻNOŚĆ OPISANA,
> NIEROZSTRZYGNIĘTA.** Tytuł obiecuje „dwa egzemplarze w HTML, jeden na
> ekranie". W kodzie egzemplarz jest JEDEN:
> `resources/views/pages/profile/show.blade.php:257` renderuje
> `<x-liczby-profilu … wariant="karta" />`, a `wariant="szyna"` ani klasy
> `profil-liczby-szyna` nie emituje żaden plik w `resources/views/`.
> `resources/views/components/szyna-profilu.blade.php` deklaruje wprawdzie
> `$stats`, ale nigdzie ich nie używa i kontroler ich tam nie podaje.
>
> Strażnik podany w tym wpisie egzekwuje dziś **zakaz** obiecanego wariantu:
> `tests/Feature/ProfilLiczbyWPrawejSzynieTest.php:28` nazywa się
> `test_liczby_wystepuja_raz_w_dokumencie` i asertuje
> `assertStringNotContainsString('profil-liczby-szyna', $html)` oraz
> `assertSame(1, substr_count($html, 'class="profil-liczniki '))`. W
> `resources/css/ekran-profilu.css:294-316` zostały reguły
> `.profil-liczby-szyna` / `.profil-liczby-karta`, których nic nie trafia.
>
> **Czego audyt NIE ustalił:** czy decyzję wdrożono i później odwrócono, czy
> nigdy jej nie wykonano, a strażnik napisano pod stan zastany. To dwie różne
> historie i dwie różne naprawy — przywrócić wariant szyny albo wycofać wpis
> razem z martwym CSS-em. Żaden późniejszy wpis tego nie odwraca, a dziennik
> cytuje D-091 dalej jako obowiązujące. **Werdykt należy do właściciela.**

**Stan przed zmianą.** Karta profilu (`pages/profile/show.blade.php`) miała pod
opisem osoby pięć osobnych wierszy po 48 px: wpisy, przepisy, „razy
Ugotowałem", obserwujący, obserwowani. Pod nimi rząd przycisków, dopiero pod
całą kartą zakładki, nagłówek miesiąca i pierwszy wpis. Prawa szyna profilu
ISTNIAŁA od issue #205 (`x-szyna-profilu`: „Twoje skróty" na własnym profilu,
„Co gotuje" i „Zeszyty" na cudzym), ale na cudzym profilu bez tagów i bez
publicznych zeszytów nie dostawała ANI JEDNEGO bloku — i to jest ten pusty
pas z prawej strony na zrzucie właściciela.

### Dlaczego dwa egzemplarze w dokumencie, a nie jeden przestawiany

Bo przestawić się nie da. `<aside class="app-rail">` jest RODZEŃSTWEM
`<main>`, nie jego wnętrzem: żadne `order`, `float` ani `grid-area` nie wsunie
elementu z szyny do środka karty profilu. Jedynym narzędziem byłby skrypt
przenoszący węzeł przy zmianie szerokości okna — a `AGENTS.md` §5 wymaga, żeby
ważne rzeczy działały bez JavaScriptu. Zostaje więc jedna treść wypisana dwa
razy (składnik `x-liczby-profilu`, żeby nie były to dwie kopie do rozjechania)
i PARA reguł w `ekran-profilu.css`, która pokazuje dokładnie jeden egzemplarz.

Egzemplarz schowany przez `display: none` wypada z drzewa dostępności, więc
czytnik ekranu czyta te liczby raz, a nie dwa razy.

### Dlaczego próg 80rem, a nie 64rem

80rem to próg, na którym w `app.css` w ogóle POWSTAJE trzecia kolumna.
Poniżej niego `.app-rail` nie znika — **ląduje pod treścią**, czyli pod całym
archiwum wpisów. Przeniesienie liczb do szyny „na stałe" zepchnęłoby je na
telefonie kilkanaście ekranów przewijania w dół. Na wąskim widać więc
egzemplarz w karcie i to jest stan sprawdzany przy 320, 360 i 414 px.

### Gość to osobny przypadek, nie powtórka

Gość dostaje `.app-body-solo` — JEDNĄ kolumnę na każdej szerokości. Jego szyna
leci pod treścią nawet przy 1512 px. Dlatego reguła chowająca liczby w karcie
jest zawężona przez `:not(.app-body-solo):not(.app-body-powitalny)`, a bloku
w szynie w ogóle mu nie wysyłamy (`@auth`). Bez tego zawężenia gość przy
1280 px straciłby liczby z karty, a jedyny drugi egzemplarz leżałby na dole
strony — czyli poprawka układu byłaby dla niego regresją.

### Co się NIE zmieniło i dlaczego to jest ważne

Liczby dalej pochodzą z jednej tablicy `stats` w `ProfileController::show()`.
Drugi egzemplarz NIE liczy sobie sam: pomiar zapytań na cudzym profilu
oglądanym przez zalogowanego daje siedem agregatów (pięć z `stats`, jeden
z paginacji archiwum, jeden z licznika powiadomień w belce) — tyle samo co
przed zmianą. Kolejność liczb, ich odmiana (`x-licznik-profilu`,
`App\Support\Odmiana`) i adresy odnośników obserwujących/obserwowanych są bez
zmian; w szynie odnośnik dalej obejmuje całą komórkę i ma 48 px pola
klikalnego.

### §12 (bez rankingów) — granica przesunięta w opisie, nie w rzeczy

Komentarz w `x-szyna-profilu` mówił dotąd „żadnej liczby obserwujących",
jednym tchem z zakazem rankingów. To było zlanie dwóch różnych rzeczy.
`AGENTS.md` §12 zakazuje PORÓWNYWANIA LUDZI ZE SOBĄ — miejsc w tabeli, odznak,
„najaktywniejszych". Nie zakazuje pokazania, ile ta osoba ma własnych wpisów;
te same pięć liczb stało przez cały ten czas w karcie dwa centymetry wyżej.
Granica zostaje ostra: blok nie sortuje, nie wyróżnia, nie nagradza i nie ma
progu „od ilu to już dużo". **Zera pokazujemy** — chowanie ich zamieniłoby
informację w wyróżnienie, czyli w ranking wpisany w puste miejsce.

### Osobno: nazwa przycisku „Ugotowałem" dostaje cudzysłów

Właściciel zauważył, że „0 razy Ugotowałem" na CUDZYM profilu brzmi jak zdanie
w pierwszej osobie. Sprawdzone: samo brzmienie jest umyślne i udokumentowane
dwa razy — `BRAND_EXTENDED.md` §3 każe nazwy własne funkcji pisać z wielkiej
litery i nie odmieniać („trzy razy Ugotowałem"), a wyjątek
w `TekstyNiePrzypisujaPlciTest::WYJATKI` brzmi „nazwa przycisku
**w cudzysłowie**". Cudzysłowu w interfejsie jednak nie było — i bez niego nic
nie odróżniało nazwy przycisku od czasownika. Poprawiona została więc
INTERPUNKCJA, a nie brzmienie: „4 razy „Ugotowałem”". Zamiana na neutralny
rzeczownik („4 wykonania") byłaby szóstą nazwą tej samej funkcji i złamałaby
regułę „nazwa funkcji jest jedna i nie ma synonimów" z tego samego dokumentu.

### Czego ta zmiana NIE dowodzi

Testy PHP dowodzą, że oba egzemplarze są w dokumencie po jednym razie i że
para reguł w arkuszu istnieje w dokładnie jednej postaci. **Nie dowodzą, że na
ekranie widać jeden.** To sprawdza dopiero `scripts/dostepnosc.mjs` w sekcji
„Liczby o osobie (karta czy prawa szyna)": mierzy `getClientRects()` obu
egzemplarzy przy 360, 1280 i 1512 px, dla gościa i dla zalogowanego, i oblewa,
gdy widać oba naraz albo żadnego.

📄 `resources/views/components/liczby-profilu.blade.php` ·
`resources/views/components/szyna-profilu.blade.php` ·
`resources/views/components/licznik-profilu.blade.php` ·
`resources/views/pages/profile/show.blade.php` ·
`resources/css/ekran-profilu.css` ·
`scripts/dostepnosc.mjs` ·
`tests/Feature/ProfilLiczbyWPrawejSzynieTest.php` ·
`tests/Feature/NaglowekProfiluOdmieniaLicznikiTest.php` ·
`docs/brand/BRAND_EXTENDED.md` §3 · issue #205 · D-054
