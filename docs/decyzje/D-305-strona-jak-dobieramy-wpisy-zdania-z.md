## D-305 — Strona „Jak dobieramy wpisy”: zdania z rejestru, każde z dowodem w kodzie (#1811, #1781, 26 września 2026)

**Data:** 26 września 2026 · Decyzja właściciela (#1781, kryteria #1811) · Status: **obowiązuje**

### Decyzja

Strona `/jak-dobieramy-wpisy` (trasa `feed-rules`, publiczna) opisuje po kolei
każdą listę wpisów: Start, „Świeżo z Kuking”, tablicę na dziś i polecane tagi,
wyszukiwarkę, tygodniowy e-mail, ukrywanie i „czego nie robimy”. Zdania stoją
w `App\Domain\Feed\JakDobieramyWpisy`, widok rysuje wyłącznie je, a
`JakDobieramyWpisyMowiPrawdeTest` (wzorem `TabelaStackuMowiPrawdeTest`, D-104)
trzyma dla każdego klucza dowody w trzech dozwolonych kształtach: `test:`
(metoda testu istnieje), `kod:` (plik zawiera fragment), `config:` (liczba
w zdaniu = wartość konfiguracji). Zdanie bez dowodu, dowód bez zdania, liczba
bez konfiguracji i akapit dopisany wprost w widoku oblewają. Trzy obietnice
mają testy zachowania w tym samym pliku: reakcje, „Ugotowałem” i komentarze
nie zmieniają kolejności (Start i Odkrywanie); w bazie nie ma miejsca na zapis,
kto oglądał który wpis; wybór gospodarza jest podpisany.

Nigdzie nie piszemy „nie mamy systemu rekomendacji” — dobór wpisów jest systemem
rekomendacji w rozumieniu DSA, tyle że prostym i jawnym; test skanuje widoki
i dokumenty prawne.

**Linia w „Świeżo z Kuking”.** Pod nagłówkiem stała linia „Skąd te wpisy i jak
to zmienić” → strona; przy aktywnych ukryciach druga: „Ukrywasz wpisy N osób.
Zmień” (albo „Ukrywasz N wpisów”, gdy ukryte są tylko wpisy) → Ustawienia →
Ukryte. Liczby tylko z ukryć tego widza (`Ukrycia::ileOsob()`, `ileWpisow()`).
Odnośnik „Jak działa kolejność?” na Starcie prowadzi teraz na tę stronę.
Na stronie zalogowany ma odnośniki do obserwowanych osób, tagów i „Ukrytych”.

**Wybór gospodarza podpisany.** AGENTS.md §8 dopuszcza wybór gospodarza
„oznaczony w interfejsie jako jego wybór”, a tablica go nie oznaczała. Od teraz
pozycja z `daily_picks` ma napis „Wybór gospodarza” (`DailyBoard` zwraca
`wybrane`); pozycje dołożone przez automat do sufitu — nie.

**Słowo „tag”**, nie „temat” (decyzja właściciela z 11.09, `JednoSlowoNaTagiTest`).

Regulamin (§2, „Jak dobieramy wpisy”) odsyła do strony — zmiana ogłoszona
według D-306.

### Wycofanie

Bez migracji: usunąć trasę `feed-rules`, `JakDobieramyWpisy`, widok, linie
w `discover.blade.php`/`home.blade.php` i test; napis na tablicy zostawić
(wymaga go AGENTS.md §8).

📄 `app/Domain/Feed/JakDobieramyWpisy.php` · `resources/views/pages/static/jak-dobieramy-wpisy.blade.php` ·
`tests/Feature/JakDobieramyWpisyMowiPrawdeTest.php` · `app/Domain/Feed/DailyBoard.php` · D-275 · D-276 · D-277 · D-278 · D-279 · D-280
