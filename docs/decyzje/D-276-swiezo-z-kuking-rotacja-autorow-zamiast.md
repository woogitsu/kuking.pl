## D-276 — „Świeżo z Kuking”: rotacja autorów zamiast jednego wpisu od osoby (#1807, #1781, zmienia #940, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781, kryteria #1807) · Status: **obowiązuje**

### Problem

`DiscoverFeed` brał `DISTINCT ON (author_id)` — najnowszy wpis każdej osoby
w całej sekwencji (#940). Seria jednej osoby przestała zasłaniać innych, ale
głębokość Odkrywania równała się liczbie aktywnych autorów: przy dziesięciu
osobach dziesięć kart i koniec. Starsze wpisy nie wracały nigdy, a osoby
publikujące często stały zawsze na górze.

### Decyzja

Rotacja (round-robin): najpierw najnowszy wpis każdej osoby, potem drugi
każdej i tak dalej. W SQL: `row_number() OVER (PARTITION BY author_id ORDER BY
published_at DESC, id DESC)` po wszystkich bramkach widoczności, sort
`runda, published_at DESC, id DESC`, paginacja kursorowa po tej trójce.

To reguła z listy D-275 „równość autorów” — `runda` liczy wpisy TEJ osoby,
nie cudze reakcje. Strażnik `FeedNieSortujePoMierzeReakcjiTest` przechodzi
bez wpisu w rejestrze (alias `rotacja.runda` nie pasuje do słów reakcji).

**Stabilny kursor.** Nowy wpis osoby w trakcie przeglądania przesunąłby jej
starsze wpisy o rundę dalej i jeden wróciłby na następnej stronie. Dlatego
dalsze strony liczą rundy z wpisów do chwili pierwszej strony — parametr
`stan` (unix, sekundy) w odnośniku „Pokaż więcej”. Wartość z adresu to tylko
pozycja w czasie; bramki widoczności liczą się zawsze od teraz. Kursor
i `stan` działają tylko razem: brak `stan`, śmieci, przyszłość albo wartość
starsza niż doba odrzucają TAKŻE kursor i lista zaczyna się od początku
(przegląd #1781) — stary kursor z nową chwilą po cichu gubiłby albo
powtarzał wpisy. Granica ma sekundową
dokładność (tak zapisuje `published_at` Eloquent), więc zdublować się może
najwyżej wpis dodany w tej samej sekundzie co pierwsza strona. Ukrycie albo
zablokowanie osoby w trakcie przeglądania może przesunąć jej wpisy o rundę
wcześniej i jeden pominąć — świadomie przyjęte, wraca przy następnym wejściu.

**Pusty stan z wyjściem.** Rozróżnia „nic nowego” od „część ukrywasz” (dziś:
blokady zrobione PRZEZ widza; blokada, którą ktoś odciął widza, nie zdradza
się) i zawsze prowadzi do listy ukrytych (gdy dotyczy), tablicy na dziś
i „Dodaj wpis”.

**Własne wpisy widza stoją w rotacji jak każdy autor (decyzja właściciela,
26 września 2026, #1567).** Odkrywanie nie odsiewa wpisów zalogowanej osoby
i ich nie wyróżnia: jej najnowszy wpis stoi w pierwszej rundzie obok
najnowszego wpisu każdej innej osoby, drugi — w drugiej. Po publikacji
człowiek widzi swój wpis na „Świeżo z Kuking” i wie, że się zapisał, a nie
zajmuje przez to więcej miejsca niż inni. Tak samo na Starcie osoby, która
nikogo nie obserwuje (feed zastępczy z #1318): jej wpisy „tylko dla
obserwujących” wchodzą do jej rund, nie obok nich. Pilnuje
`OdkrywanieRotacjaAutorowTest::test_wlasne_wpisy_widza_stoja_w_rotacji_jak_kazdy_autor`.

**Wpis z własną treścią (D-274) w rotacji.** D-274 mówiło „liczy się do
limitu jednego wpisu na autora”. Po rotacji to samo znaczy: wpis z własną
treścią po ukryciu przepisu zajmuje miejsce w rundach swojego autora jak
każdy inny jego wpis — nowszy wpis tej osoby stoi rundę wcześniej. Pilnuje
`ListyWpisuZWlasnaTresciaTest::test_wpis_z_wlasna_trescia_po_ukryciu_przepisu_liczy_sie_do_rund_autora`.

Automatyczna część tablicy „kuKINGi na dziś” (`DailyBoard`) zostaje bez zmian
— pokazuje dzień, jeden wpis od osoby.

### Zdanie do strony „Jak dobieramy wpisy” (#1811)

> W „Świeżo z Kuking” najpierw widzisz najnowszy wpis każdej osoby (także
> swój), potem drugi każdej i tak dalej. Nikt nie stoi wyżej dlatego, że publikuje częściej
> albo zebrał więcej reakcji. Wpisów osób, które ukrywasz albo blokujesz, tu
> nie ma.

### Wycofanie

Bez migracji. Przywrócić `DISTINCT ON` z #940 w `DiscoverFeed::paginate()`
i testy `OdkrywanieJedenWpisNaAutoraTest` z historii gita.

📄 `app/Domain/Feed/DiscoverFeed.php` · `tests/Feature/OdkrywanieRotacjaAutorowTest.php` ·
`tests/Feature/OdkrywaniePustyStanZWyjsciemTest.php` · `resources/views/components/pusty-stan-odkrywania.blade.php`
