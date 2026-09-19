# Strony tagów — #370

Stan: **scalone i wdrożone**, odbiór produkcji niedomknięty.
PR #669 scalony 18.09.2026 jako `397a742` (Alfa 0.63). Produkcja **w chwili
pomiaru opisanego niżej** (18.09.2026, 13:10–13:24 UTC) stała na `55877e2`
(Alfa 0.65), CI main 35331870558 i Deploy 35333641106: success. To nie jest
deklaracja o zawsze aktualnym SHA — produkcja rusza dalej, a każdy wynik
w tym pliku ma przy sobie datę i SHA, na którym powstał.
Zdanie o „jeszcze niewdrożonej Alfie 0.63” i baza `e22b79d` poniżej to snapshot
sprzed scalenia; zostawiamy go jako zapis przebiegu prac.

## Zachowanie

Strona tagu zachowuje chronologiczną listę wpisów i istniejące obserwowanie.
Nagłówek dodaje zaproszenie lub notatkę gospodarza, kolaż oraz odnośnik do
formularza z wybranym tagiem. Promowane tagi otrzymują karty ze zdjęciami;
pozostały spis pozostaje alfabetyczną listą. Nie powstaje nowy typ treści ani ranking.

`TagCollage` wybiera najwyżej pięć gotowych publicznych zdjęć, po jednym od
autora. Filtry publikacji, aktywności autora, powiązanego przepisu i blokad
widza działają przed selekcją. Remisy mają pełny porządek po identyfikatorach.
Jedno zapytanie okienkowe wybiera rekordy wielu tagów, a relacje są ładowane
zbiorczo. Zdjęcia nadal przechodzą przez `Media::url()` i kontrolę dostępu.

Formularz przyjmuje istniejący aktywny tag z parametru `tag`; tag scalony
rozwiązuje do aktywnego celu. Nie tworzy tagów na podstawie adresu. Stare
wejście formularza ma pierwszeństwo, także gdy użytkownik usunął ostatni tag.

## Dowody lokalne

- Formularz i dotychczasowa obsługa tagów: 25 testów / 100 asercji PASS.
  Cztery fizyczne mutacje kontrolera wykryte, po każdej przywrócone MD5 i mtime,
  końcowy wynik dodatni. Testy obejmują rzeczywiste logowanie, usunięcie tagu,
  walidację i publikację przez istniejący formularz.
- Domena kolażu: 9 testów / 262 asercje PASS, Pint i PHPStan PASS.
  Trzy fizyczne negatywy publiczności, deduplikacji autorów i blokad wykryte;
  MD5/mtime przywrócone, końcowy wynik dodatni.
- Integracja stron oraz powiązane rodziny: 62 testy / 612 asercji PASS.
  Obejmuje render 30 promowanych kart, odnośniki do wpisów i formularza,
  pusty stan, brak zagnieżdżonych odnośników oraz escapowanie notatki.
  Jest to test HTTP/HTML, nie ogląd kompozycji w przeglądarce.
- PHPStan domeny tagów i obu kontrolerów: bez błędów kodu. Konfiguracja
  ograniczonego przebiegu wyłącza zgłaszanie nieużytych wykluczeń baseline,
  ponieważ standardowy baseline obejmuje również testy spoza tego zakresu.

## Pomiar dużego zbioru

Izolowana baza `kuking_370_collage_tests`, port 55439, UTC, poczta `array`.
Transakcja: 10 000 wpisów, 30 000 zdjęć, 1000 autorów, 30 tagów; dodatkowo
nieprzypięte rekordy wzorcowe fabryk. Po `ANALYZE`, cztery próbki:

| Zestaw | Czas | Liczba zapytań |
|---|---:|---:|
| 2 tagi | 11,4–15,7 ms | 5 |
| 30 tagów | 58,7–62,5 ms | 5 |

Sprawdzono po pięć różnych autorów na każdy tag. EXPLAIN zwrócił 150 wierszy
dla 30 tagów; końcowe sortowanie około 59 ms. Pomiar obejmuje selekcję i
hydratację domeny, bez renderu strony, pobierania zdjęć i ruchu równoległego.
Nie stanowi gwarancji czasu na produkcji. Dane wycofano; osobny odczyt po
pomiarze potwierdził zero wpisów, mediów i tagów w tej bazie.

Surowe lokalne dowody: `output/collage370/` w worktree #370 oraz
`kuking.pl/output/370-form-tests/`. Trwały wybór dowodów należy dołączyć po
odbiorze końcowych źródeł; katalogi `output` nie są publikacją w repo.

## Do ukończenia

Pierwszy ogląd indeksu ujawnił rozpychanie siatki przez wewnętrzne wymiary
zdjęć: kolaże trzech i pięciu zdjęć nie zachowywały proporcji 4:3, a karty
sąsiednie rozciągały się do ich wysokości. Obrazy pozycjonujemy wewnątrz
komórek, aby rozmiar wyznaczał kontener; karty wyrównujemy do początku.
Pomiar końcowego CSS: 168 konfiguracji PASS (indeks i stany 0–5 zdjęć,
320/360/390/414/768/1440 px, oba motywy, tekst 100/140%). Sprawdzono
ładowanie zdjęć, proporcje 4:3, brak overflow i cele linków minimum 48 px.
Fizyczny negatyw position: static wykryty; kopia poza repo, MD5/mtime
przywrócone, dodatni pomiar PASS. Dowody: output/browser370/ui-results.json
i negative-css.json. Obejrzano jasny indeks desktop i 320 px / 140% / 5 zdjęć.
Ilustracje lokalne oznaczono jako dane testowe; nie są zdjęciami użytkowników.
Pozostaje kontrola fokusu przy nakładaniu przycisku Wygląd na część kolażu.

Do dostarczenia: końcowe zebranie dowodów, zwykły hook, wymagane CI, merge
oraz odbiór produkcji. Nie wykonano testu na fizycznym telefonie.
Pełna marka nadal ma status **CZĘŚCIOWO**.

## Dodatkowy odbiór przeglądarkowy

- Prawdziwy zoom Chromium 200% przez chrome.tabs.setZoom, tekst aplikacji 140%:
  14 scen PASS (oba motywy, indeks i 0–5 zdjęć). Odczytano getZoom=2,
  devicePixelRatio=2 oraz innerWidth=320 przy oknie 640; bez poziomego overflow.
- Klawiatura: 4 sceny (320/1440, oba motywy, tekst 140%), łącznie 20 linków
  zdjęć osiągniętych Tab. Obrys widoczny, środek celu niezasłonięty, cały
  prostokąt w oknie. Enter otworzył przypisany wpis z treścią i zdjęciem.
- Pierwsza wersja pomiaru wymagała h1 na stronie wpisu, którego ten widok
  nie używa. Poprawiono test na rzeczywistą treść i zdjęcie docelowego wpisu;
  nie zmieniano aplikacji w celu spełnienia błędnej asercji.
- Dowody lokalne: output/browser370/zoom-results.json i actions-results.json.
  Nie stanowi to testu fizycznego telefonu ani czytnika ekranu.

Wybrane dowody geometrii, zoomu, klawiatury i negatywu CSS zapisano
w `docs/design/evidence/tags370/`. Zrzuty zawierają oznaczone ilustracje testowe.

## Końcowe review i negatyw Blade

Niezależny przegląd kodu i dokumentacji: brak znalezionych blokerów.
Nie był to dodatkowy przebieg testów ani ogląd zrzutów przez recenzenta.
Usunięcie parametru tag z rzeczywistego Blade wykrył pomiar przeglądarkowy.
MD5/mtime przywrócone; po wyczyszczeniu skompilowanych widoków wynik dodatni PASS.
Gość po kliknięciu trafia do /login. W pierwszej wersji harness błędnie
oczekiwał /logowanie; poprawiono oczekiwanie według routes/web.php.

## Korekta regresji historycznego spisu

Pełny hook wykrył jedną porażkę SpisTematowTest: selektor .chip nie obejmował
nowych kart promowanych. Test sprawdza teraz osobno kolejność href, nazwę
i licznik na każdej karcie. Bezpośredni PHPUnit: 10 testów / 52 asercje PASS.
Fizyczne odwrócenie foreach w Blade wykryte, MD5/mtime przywrócone, wynik
dodatni PASS. Artefakty negative-order.json i targeted.py w katalogu dowodów.

Review wskazał wcześniejszą lukę fixture: Zupy(5), Barszcz(1) odpowiadały
również kolejności malejącej popularności. Dodano trzeci tag Salatki(8),
aby kolejność gospodarza różniła się od alfabetu i obu sortowań liczby wpisów.
10 testów / 55 asercji PASS. Fizyczny sortByDesc(posts_count) w Blade
wywołał właściwą porażkę href: oczekiwano Zupy, otrzymano Salatki.
Przywrócono MD5/mtime i potwierdzono wynik dodatni. Osobna baza form-tests;
nie zmieniano źródeł działającego pełnego hooka f26f727.


## Granica dowodu produkcji — 18 września 2026

Odbiór wyłącznie odczytowy, GET-y HTTP bez sesji, bez zapisu i bez danych
demonstracyjnych na produkcji. **Odczyt HTML, nie interakcja w przeglądarce.**

Publicznej strony tagu z danymi na produkcji nie ma, bo nie ma **żadnego**
publicznego tagu. Potwierdzone czterema drogami, nie samą listą: `/tagi` → 200
z `Tagi jeszcze się nie pojawiły`; `/szukaj?sekcja=przepisy` → 200 z
`Nie ma jeszcze polecanych tagów.`; pięć prób bezpośrednich adresów strony
pojedynczego tagu (`/tag/{tag}`) → 404; zero odnośników do strony pojedynczego
tagu na stronie głównej, `/odkryj`, trzech stronach wpisów
i trzech profilach publicznych. Szczegóły i cytaty: `STATYSTYKI_TAGOW_369.md`.

Nie są zatem potwierdzone na produkcji: kolaż z prawdziwymi zdjęciami, reguła
jednego zdjęcia od osoby na żywych danych, CTA „Dodaj wpis z tym tagiem” przy
istniejącym tagu ani bogate karty tagów promowanych. Potwierdzony jest wyłącznie
pusty stan obu powierzchni. Nie dodawaliśmy tagów ani wpisów, żeby to obejść.

## Mysz i dotyk na kolażu — pomiar lokalny 18 września 2026

Dotychczasowe dowody osiągalności kolażu były **klawiaturowe** (`actions.mjs`:
Tab, obrys, `elementFromPoint` w środku elementu, Enter). Brakowało wskaźnika,
a to on jest scenariuszem podstawowym. Uzupełniamy ten pomiar.

Runtime: własny klon natywny WSL `kuking-DA-tagi`, serwer `127.0.0.1:8074`,
osobna baza `kuking_d_a_tests` na `127.0.0.1:55439` (kopia fixture kolażu
z odbioru #370), poczta `array`. Bez dotykania produkcji.

48 konfiguracji: 6 szerokości (320/360/390/414/768/1440) × oba motywy ×
tekst 100/140% × dwa sposoby wskazywania (**mysz** i **dotyk**, osobne konteksty
przeglądarki, dotyk z `hasTouch`). Trzy stany strony:

| Stan | Co mierzy | Wynik |
|---|---|---|
| A — stały bywalec, podpowiedź wyglądu zamknięta, kafel przewinięty do środka okna | scenariusz podstawowy | **0 zasłoniętych środków, 0 nieosiągalnych, 0 poziomego przewijania** |
| B — kolaż doprowadzony dolną krawędzią do dolnej krawędzi okna | skrajne przewinięcie | 4 konfiguracje z jednym kaflem nieosiągalnym |
| C — pierwsza wizyta, widoczna podpowiedź „Dopasuj rozmiar tekstu i wygląd strony” | stan powitalny | **8 konfiguracji** z jednym kaflem nieosiągalnym |

W stanie A wykonano **80 rzeczywistych interakcji** — realne kliknięcie myszą
i realne dotknięcie każdego z pięciu kafli, przy 320 i 1440 px, obu motywach
i obu skalach. Wszystkie 80 otworzyły dokładnie przypisany wpis (`80/80 OK`).

**Skreślone 18.09.2026 wieczorem:** stało tu zdanie, że CTA „Dodaj wpis z tym
tagiem” otwiera dla gościa `/login` myszą i dotykiem, a jego wysokość nie
schodzi poniżej 48 px. `pointer-results.json` nie zawiera ani jednego pola
o CTA, o celu `/login` ani o wysokości przycisku, a `pointer.mjs` w ogóle tego
nie mierzy. Twierdzenie nie miało pokrycia w załączonym dowodzie i zostaje
wycofane, a nie przeniesione gdzie indziej. Czego brakuje, żeby je postawić —
patrz „Brakujące dowody” na końcu tego pliku.

Wyniki: `evidence/tags370/pointer-results.json`, skrypt `evidence/tags370/pointer.mjs`.
Obejrzano `pointer/A-light-100-1440.png` (układ prawidłowy: nagłówek, zdanie
`Publicznie: 5 zdjęć od 5 osób.`, CTA, kolaż 5 kafli, podpis), `pointer/B-light-140-320.png`
oraz `pointer/C-dark-140-390.png`. Ilustracje w kolażu są oznaczone jako dane
testowe, nie są zdjęciami użytkowników. To nie jest test na fizycznym telefonie
ani rzeczywisty zoom 200% — te zakresy pokrywają wcześniejsze sekcje tego raportu.

### Dwa zasłonięcia pochodzące ze wspólnego widgetu „Wygląd”

Oba przypadki B i C pochodzą z globalnego widgetu `szybki-wyglad`, nie z kodu
tagów, dlatego **nie ruszamy tu ani CSS, ani JS tego widgetu** — sprawa jest
zgłoszona osobno.

- **B.** Przy 320 px i tekście 140%, gdy kolaż zostanie przewinięty dolną
  krawędzią do dołu okna, pływający przycisk `Wygląd` przykrywa piąty kafel
  w całości: wszystkie dziewięć punktów próbnych trafia w przycisk, pole
  wspólne 8351 px² przy kaflu 144×69 px. Dotyczy obu motywów, myszy i dotyku.
  Doprzewinięcie o 120 px w górę przywraca dostęp we wszystkich przypadkach
  (`Bpo`: 0 nieosiągalnych), więc kafel nie jest trwale utracony.
- **C.** Przy 360 i 390 px oraz tekście 100%, na pierwszej wizycie stała
  podpowiedź `aside.szybki-wyglad-podpowiedz` (szerokość `min(360px, 100vw−24px)`,
  `position: fixed`, `z-index: 26`) przykrywa jeden kafel w całości — pole
  wspólne 25 862 px² przy 336×79 px oraz 27 709 px² przy 366×86 px. Znika po
  „Rozumiem” albo po dowolnej zmianie wyglądu.

Przyczyna jest wspólna i widoczna w źródle, ale węziej, niż stało tu
pierwotnie. **Nie jest prawdą, że „cała logika odsłaniania wisi na
`focusin`”** — to zdanie zostało 18.09.2026 wieczorem sprostowane po uwadze
z przeglądu. W `resources/js/szybki-wyglad.js` (stan `origin/main`, `3f315b3`):

- `geometry()` (l. 36) chodzi też poza fokusem — `ResizeObserver` (l. 199),
  `resize` (l. 202), `scroll` (l. 203), start (l. 205) i `toggle` (l. 156);
- `geometry()` sprawdza `.field-error` (l. 59–62) i ustawia
  `data-wyglad-w-przeplywie` (l. 63–66), więc tryb przepływu działa również
  bez zdarzenia fokusu;
- **jedyne, co faktycznie wisi wyłącznie na `focusin`, to doprzewinięcie**:
  `window.scrollBy(0, shift)` występuje w tym pliku raz, w handlerze `focusin`
  (l. 186).

Wniosek ograniczamy więc do tego, co pokazuje pomiar: **kafle kolażu
obsługiwane wskaźnikiem nie są odsłaniane** — mysz i dotyk nie dostają
doprzewinięcia, które dostaje klawiatura. Nie jest to regresja wprowadzona przez #370 — widget
zachowuje się tak nad każdą treścią — ale przy kolażu skutek jest widoczny,
bo kafel bywa jedynym wejściem do wpisu w tym miejscu strony.

## Ponowny odczyt produkcji — 18 września 2026, 18:54 UTC

**Poprzednia sekcja przestała opisywać dzisiejszą produkcję i zostaje jako
wynik historyczny z własną datą i SHA.** Ten odczyt wykonano wieczorem tego
samego dnia, wyłącznie GET-ami bez sesji. Produkcja stała wtedy na
`3f315b3` (Alfa 0.67, wydanie 18 września 2026, 20:53); pomiar z 13:10–13:24
UTC dotyczył `55877e2` (Alfa 0.65). Zapis:
[`evidence/produkcja/odczyt-20260918T1854Z.json`](evidence/produkcja/odczyt-20260918T1854Z.json).

Zmieniło się to, co przesądzało o wcześniejszym wniosku: **publiczne tagi już
są**. Spis tagów wymienia dwa — `ciasto` i `sernik` — i oba mają stronę
z HTTP 200. Zdanie „nie ma żadnego publicznego tagu” było prawdziwe o 13:24
UTC i nieprawdziwe o 18:54 UTC; nie poprawiamy go wstecz, tylko datujemy.

Co z tego wynika dla #370, ściśle w granicach odczytu HTML:

| Rzecz | Stan o 18:54 UTC | Czego to jeszcze nie domyka |
|---|---|---|
| strona tagu z danymi | tag o slugu ciasto → 200, kolaż obecny: `class="tag-collage tag-collage--1"`, kafel prowadzi na stronę wpisu o identyfikatorze 01a0a6ae, `aria-label="Zobacz wpis: Ewa Kapica"` | kolaż ma **jeden** kafel od **jednej** osoby |
| pusty stan strony tagu | tag o slugu sernik → 200, „Tu jeszcze nikt nic nie ugotował” | — |
| CTA przy istniejącym tagu | „Dodaj wpis z tym tagiem” obecne w HTML na stronie tagu ciasto | to obecność w HTML, **nie** kliknięcie ani pomiar wysokości |
| reguła „jedno zdjęcie od osoby” | nierozstrzygnięta | przy jednej osobie z jednym zdjęciem obie reguły dają ten sam wynik |

Pięć slugów sprawdzonych rano (`obiad`, `zupy`, `deser`, `cukinia`, `bigos`)
nadal zwraca 404 — nie powstały nowe tagi poza tymi dwoma na liście.

## Brakujące dowody #370 — co dokładnie trzeba wykonać

Nie zamykamy #370 na tym odbiorze. Brakuje trzech rzeczy i każda ma
kryterium zaliczenia:

1. **Kolaż na żywych danych z regułą „jedno zdjęcie od osoby”.**
   Scenariusz: wejść odczytowo na stronę tagu, który ma publiczne, gotowe
   zdjęcia od **co najmniej trzech różnych osób**. Potrzebne dane: taki tag
   na produkcji — dziś go nie ma (`ciasto` ma jednego autora). Kryterium
   zaliczenia: w `div[data-tag-collage]` liczba kafli równa liczbie
   **różnych** autorów, nie liczbie zdjęć, i żaden autor nie występuje dwa
   razy (`aria-label="Zobacz wpis: …"`).
2. **CTA „Dodaj wpis z tym tagiem” jako interakcja.**
   Scenariusz: rzeczywiste kliknięcie myszą i dotknięcie CTA na
   `/tag/{istniejący}` w przeglądarce oraz pomiar wysokości przycisku.
   Potrzebne: przeglądarka sterowana przez agenta na lokalnym runtime
   z fixture kolażu (na produkcji robimy wyłącznie odczyt). Kryterium:
   gość ląduje na `/login`, wysokość przycisku ≥ 48 px w każdej
   z 12 kombinacji 320/390/1440 px × oba motywy × tekst 100/140%.
3. **Zgodność zrzutów ze stanem C.** W repozytorium są
   `pointer/C-dark-140-320.png` i `pointer/C-dark-140-390.png`, obie ze skalą
   **140%**, podczas gdy osiem spornych konfiguracji stanu C ma skalę
   **100%** (360 i 390 px). Zrzuty nie ilustrują więc konfiguracji, o których
   mówi tabela. Kryterium: zrzut `C` dla `light-100-360` i `dark-100-390`
   pokazujący przykrycie kafla przez `aside.szybki-wyglad-podpowiedz`.

## Domknięcie braków nr 2 i nr 3 — 19 września 2026

Z trzech braków wymienionych wyżej **dwa są zamknięte, jeden zostaje**.
Wszystko poniżej wykonano na **lokalnym** runtime — na produkcji nie
wykonano w tej sesji ani jednej operacji poza odczytem.

Runtime: `/home/mateusz/kuking-odbior-pasek/app`, serwer `127.0.0.1:8091`
(`artisan serve --no-reload`), jednorazowa baza `kuking_pasek_370` na
`127.0.0.1:55439`, poczta `array`. Fixture: tag `odbior-kolaz-5`,
**pięć publicznych wpisów od pięciu różnych autorek**, każdy z jednym
zdjęciem `ready` z kompletem wariantów. Ilustracje są rysowane na miejscu
i mają wpisany w obraz napis `DANE TESTOWE` — **nie są zdjęciami
użytkowników**. Skrypt fixture: [`evidence/tags370/fixture-kolaz5.php`](evidence/tags370/fixture-kolaz5.php).

### Brak nr 2 — CTA „Dodaj wpis z tym tagiem" jako interakcja: ZAMKNIĘTY

24 rzeczywiste interakcje: 12 kombinacji (320/390/1440 px × oba motywy ×
tekst 100/140%) × dwa wejścia (`page.mouse.click` i `page.touchscreen.tap`).
Scenariusz A — stały bywalec, podpowiedź wyglądu zamknięta.

| Co mierzone | Wynik |
|---|---|
| konfiguracji | 24 / 24 PASS |
| wysokość przycisku | **50,5 – 91 px**, nigdzie poniżej 48 px |
| gdzie ląduje gość | `/login` we **wszystkich 24** przypadkach |
| środek przycisku zasłonięty | nigdzie (`elementFromPoint` = sam przycisk) |
| poziomy overflow | nigdzie |

`href` przycisku niesie wybrany tag (`/dodaj/zdjecie?tag=odbior-kolaz-5`),
więc po zalogowaniu formularz dostaje ten tag — ale **tego kroku ta sekcja
nie mierzy**, kończy się na `/login`. Pokrywa go
`Dowody lokalne` wyżej (25 testów / 100 asercji na formularzu).

Motyw i skalę tekstu ustawiono **prawdziwym formularzem aplikacji**
(`#szybki-motyw`, `#szybka-skala`, „Zapisz wygląd"), nie podrobionym
ciasteczkiem — ciasteczka Laravela są szyfrowane. Po zapisie odczytano
z `<html>` atrybuty `data-theme` i `data-text-scale` i to one, a nie
intencja skryptu, są w dowodzie.

Wyniki: [`evidence/tags370/cta-results.json`](evidence/tags370/cta-results.json),
skrypt [`evidence/tags370/cta-stanC.mjs`](evidence/tags370/cta-stanC.mjs).

### Brak nr 3 — zgodność zrzutów ze stanem C: ZAMKNIĘTY

Brakowało zrzutów w **tej samej skali**, co sporne konfiguracje: osiem
wpisów `C_PODPOWIEDZ_NIEOSIAGALNY` w `pointer-results.json` dotyczy skali
**100%** przy 360 i 390 px, a w repozytorium leżały wyłącznie zrzuty 140%.
Dorobiono brakujące dwa:

| Zrzut | Podpowiedź widoczna | Prostokąt podpowiedzi | Kafli nieosiągalnych | Pole wspólne |
|---|---|---|---:|---:|
| [`pointer/C-light-100-360.png`](evidence/tags370/pointer/C-light-100-360.png) | tak | 336 × 156 px przy (12, 508) | 2 | 12 902 px² |
| [`pointer/C-dark-100-390.png`](evidence/tags370/pointer/C-dark-100-390.png) | tak | 360 × 156 px przy (18, 508) | 2 | 15 425 px² |

Oba zrzuty **obejrzano**. Widać na nich dokładnie to, o czym mówi tabela
stanu C: stała podpowiedź „Dopasuj rozmiar tekstu i wygląd strony."
z przyciskiem „Rozumiem" leży na kolażu i przykrywa kafle.

**Liczby nie są tożsame z `pointer-results.json` i nie udajemy, że są.**
Tamten pomiar chodził na innym runtime i innym fixture (`kuking_d_a_tests`,
port 8074) i dawał 1 kafel nieosiągalny oraz pole 25 862 / 27 709 px².
Tu wychodzą 2 kafle i mniejsze pole wspólne, bo nagłówek tagu ma inną
długość tekstu, więc kolaż stoi w innym miejscu. **Zjawisko jest to samo
i jest odtwarzalne**; sama liczba zależy od treści nad kolażem. Zrzuty
ilustrują teraz właściwą skalę — i tylko to było kryterium.

Przyczyna pozostaje ta, co wyżej: widget `szybki-wyglad` jest globalny,
nie należy do #370 i jest zgłoszony osobno. Nie ruszano tu jego CSS ani JS.

### Brak nr 1 — kolaż na żywych danych: ZOSTAJE OTWARTY

Wymaga tagu, który ma na **produkcji** publiczne gotowe zdjęcia od co
najmniej trzech różnych osób. Odczyt produkcji z 18.09 znalazł dwa
publiczne tagi, z czego `ciasto` ma **jednego** autora z jednym zdjęciem,
a `sernik` jest pusty. Przy jednym autorze reguła „jedno zdjęcie od osoby"
i reguła „jedno zdjęcie na wpis" dają ten sam wynik, więc nadal nie ma na
czym ich rozróżnić. **Nie tworzymy w tym celu treści na produkcji.**

Reguła jest natomiast potwierdzona lokalnie i to potwierdzenie jest
mocniejsze niż odczyt HTML: fixture ma pięciu autorów i pięć zdjęć,
a kolaż renderuje `tag-collage--5` z pięcioma kaflami o pięciu różnych
`aria-label="Zobacz wpis: …"`. Dowód domenowy (dedupilkacja autorów,
publiczność, blokady) to 9 testów / 262 asercje z trzema fizycznymi
negatywami — sekcja `Dowody lokalne`. Brakuje wyłącznie warstwy
produkcyjnej, i to jest cała treść tego braku.
