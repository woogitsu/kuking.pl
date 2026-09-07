# UI kit v2 — co jest wdrożone, a co nie

Porównanie zrobione **przez zrzuty ekranu**: aplikacja lokalna i `kit-v2/html/`
otwarte w tej samej przeglądarce, przy tej samej szerokości okna (1280 px
i 390 px). Nie na oko z opisu.

Data: 6 września 2026. Aktualizacja tego samego dnia — etap C (ekran przepisu).

## Wniosek w jednym zdaniu

Kolory, typografia i logo **są wdrożone i zgadzają się co do wartości**.
Nie zgadza się **układ**: nawigacja, prawa szyna, zakładki feedu i część akcji
na kartach. Dlatego produkt „nie wygląda jak kit", mimo że paleta jest ta sama.

## Tokeny: 39 z 53 identycznych

Porównane maszynowo (`kit-v2/css/tokens.css` przeciw `resources/css/tokens.css`).

**Zgadza się co do znaku:** wszystkie kolory powierzchni, tekstu, marki, akcentu,
błędu, sukcesu i fokusu; cała skala typograficzna (16/18/20/22/24/28/36);
wszystkie odstępy; promienie; oba cienie; tryb ciemny.

**Różni się:**

| token | kit | repozytorium | co z tym |
|---|---|---|---|
| `--leading-title` | 1.25 | **1.4** | nagłówki w aplikacji są luźniejsze niż w kicie |
| `--radius-xl` | 1.5rem | brak | używany w kicie na dużych kartach |
| `--content-max` | 45rem (720 px) | brak | szerokość kolumny treści |
| `--sidenav-width` | 15rem (240 px) | brak | szerokość lewej nawigacji |
| `--control-min` / `--touch-min` | 3rem / 2.75rem | brak jako token | wartości są w kodzie, ale nie jako nazwana zmienna |
| `--font-readable` | Atkinson Hyperlegible | brak | tryb dostępności, nie podstawowa marka |

Nazewnictwo odstępów różni się celowo: kit ma `--space-*`, repozytorium
`--spacing-*` (tak wymaga Tailwind 4). `IMPLEMENTATION_GUIDE.md` §4 punkt 2
mówi wprost: nazwy z repozytorium są źródłem prawdy.

## Układ: to jest cała różnica

### `/home` — desktop

| element | kit | aplikacja |
|---|---|---|
| pole wyszukiwania w belce | **jest**, na środku, pełna szerokość | brak — wyszukiwarka tylko w lewej nawigacji |
| awatar w belce | jest, z rozwijanym menu | brak |
| lewa nawigacja | 5 pozycji: Start, Szukaj, Dodaj, **Moje**, Profil | 7 pozycji, inna kolejność, „Zeszyt" zamiast „Moje" |
| Powiadomienia i Ustawienia | **na dole** lewej kolumny, oddzielone | w jednym ciągu z resztą |
| prawa szyna | **„Mój zeszyt"** (3 zapisane przepisy z miniaturami) + **„Poznaj inspirujących ludzi"** | **brak — układ jednokolumnowy** |
| zakładki feedu | **„Obserwowani / Odkrywaj"** z podkreśleniem | brak; zamiast tego notka tekstowa |
| composer | „Co dziś gotujesz?" + awatar + ikona zdjęcia za pionową kreską | inny tekst, brak separatora |
| „kuKINGi na dziś" | **nie ma tego w kicie** | zajmuje główną kolumnę pod composerem |

### `/home` — telefon

| element | kit | aplikacja |
|---|---|---|
| dolna nawigacja | 5 pozycji z **dużym okrągłym „+" na środku** | 5 równych pozycji, bez wyróżnionego środka |
| podpisy | Start, Szukaj, **Dodaj (w kółku)**, Moje, Profil | Start, Szukaj, Dodaj, Zeszyt, Profil |

### Karta wpisu

| element | kit | aplikacja |
|---|---|---|
| nagłówek | nazwisko + „2 godz. temu **· publicznie**" + menu „…" | nazwisko + data, bez widoczności i bez menu |
| akcje | Ugotowałem · Komentarze (12) · **Zapisz** | Ugotowałem · Komentarze · Zgłoś |

„Zapisz" na wpisie to **nowa funkcja produktowa**, nie brakujący przycisk:
zeszyt przyjmuje dziś wyłącznie przepisy. Wymaga decyzji właściciela.

## Czego kit NIE ma, a produkt ma — i musi zachować

To jest lista rzeczy, których nie wolno zgubić przy przestylowaniu.
`IMPLEMENTATION_GUIDE.md` §4 punkt 4 mówi to samo, krócej:
„nie zmieniaj kontrastów bez ponownego przeliczenia".

- **55 policzonych par kontrastu** i tokeny `-tint-ink` / `-solid`, których kit
  nie rozróżnia. Bez nich bieżąca pozycja nawigacji miała w trybie ciemnym
  kontrast 2.32 przy wymaganych 4.5 (issue #26).
- **Skala tekstu użytkownika** (`--user-text-scale`): cała typografia jest przez
  nią mnożona, odstępy celowo nie. Kit ma wartości stałe.
- **Wszystko działa bez JavaScriptu.** Kit jest statycznym HTML-em, więc nie
  odpowiada na pytanie, jak zachowa się zakładka albo menu „…" przy wyłączonym
  skrypcie. W tym produkcie to warunek, nie ulepszenie (AGENTS.md §5).
- **Automat dostępności** przy każdej zmianie: axe na 14 ekranach w czterech
  wariantach plus pomiar przewijania w poziomie przy 320–768 px.

---

## Etap C — ekran przepisu (wdrożony 6 września 2026)

| element z kitu (ekrany 02 i 06) | co jest w aplikacji |
|---|---|
| okruszki „Start › Przepisy” | **jest** |
| hero: zdjęcie obok panelu | **jest**; bez zdjęcia panel bierze całą szerokość zamiast zostawiać pustą połowę |
| kafle: czas / porcje / poziom | **jest**, ale tylko te, które autor podał — kafel „—” nie jest informacją |
| akcje w panelu (Zapisz, Ugotowałem) | **jest**, w pionie i na pełną szerokość panelu; dochodzi trzecia, „Gotuję” |
| „Skąd ten przepis?” w panelu | **jest**, razem ze zdjęciem kartki |
| składniki obok kroków | **jest** (dwie kolumny od 60rem, składniki pierwsze niżej) |
| składniki z kolumną ilości | **nie i nie będzie** — D-017 |
| kroki z tytułami („Przygotuj ciasto”) | **nie i nie będzie** — D-017 |
| „Jak wyszło innym?” | jest jako „Komu wyszło”; sam napis zmieni #38 |
| znak „Uśmiech” w przycisku „Ugotowałem” | **świadomie nie**: znak rysuje garnek kolorem bieżącym, a uśmiech kolorem powierzchni — na tle marki wychodzi biała plama bez uśmiechu |

Główna akcja przeniosła się z **dołu strony** do panelu przy zdjęciu. To jest
największa zmiana produktowa w tym etapie, nie kosmetyczna: „Ugotowałem”
leżało pod krokami, czyli widział je tylko ten, kto przewinął cały przepis.

Nowe: `wide` w `<x-layout>` podnosi sufit kolumny z 45rem do całej szerokości
po nawigacji. Sufit czytelności nie znika — przenosi się na pojedyncze bloki
z ciągłym tekstem (`.kolumna-czytania`).

### Zostaje z etapu C do zrobienia

- `CookedCard` w układzie z kitu („Jak wyszło innym?” z paskiem liczb
  i przyciskiem „Zobacz N wpisów”) — dziś to lista kart jedna pod drugą.

### Nadal otwarte z listy tokenów

`--leading-title` (1.25 w kicie, 1.4 u nas) zostaje **1.4**. Luźniejszy
nagłówek jest tu decyzją dla grupy 50+, a nie rozjazdem — zmiana dotknęłaby
każdego ekranu i należy do osobnej decyzji, nie do przestylowania przepisu.

---

## Konflikt kitu z COPY_STYLE — rozstrzygnięty na rzecz COPY_STYLE (7 IX 2026)

`docs/HANDOVER.md` wymieniał to jako pracę do issue #38: „kit mówi »Jak wyszło
innym?«, a aplikacja »Komu wyszło«". **Zmierzone: to nie jest błąd aplikacji.**

- `docs/brand/COPY_STYLE.md` §6 („Ugotowałem", wiersz „sekcja pod przepisem")
  mówi **„Komu wyszło"** — i ten dokument jest wprost wiążący dla każdego
  tekstu widocznego dla użytkownika.
- `docs/design/kit-v2/html/02_desktop_recipe.html` i
  `docs/design/prototype/recipe.html` mówią **„Jak wyszło innym?"**.
- `resources/views/pages/recipes/show.blade.php` mówi **„Komu wyszło"**,
  czyli zgadza się z COPY_STYLE.

Do przepisania jest więc KIT, nie aplikacja — a dokładniej: makiety HTML
w tym katalogu są materiałem projektowym z wcześniejszego etapu i tam, gdzie
mówią coś innego niż COPY_STYLE, **wiąże COPY_STYLE**. Nie zmieniam samych
plików makiet, bo są zapisem tego, co projektant narysował; zmieniam status
tej rozbieżności z „zaległość w aplikacji" na „makieta jest starsza niż
decyzja o głosie marki".

### Przy okazji zmierzone: tekstów do przepisania po #38 jest mniej, niż zapisano

Przeskanowałem wszystkie widoki (`resources/views/**/*.blade.php`) pod listę
słów zakazanych z `docs/brand/BRAND_EXTENDED.md` §2.1 (`feed`, `explore`,
`content`, `onboarding`, `punkty`, `poziom`, `odznaka`, `senior`, `kolekcja`,
`swipe`, `mniam`…), pod wykrzykniki (twardy zakaz w produkcie) i pod emoji.

**W tekstach widocznych dla człowieka: zero trafień.** Wszystkie dwadzieścia
trafień siedzi w KOMENTARZACH w kodzie widoków — i to takich, które wprost
tłumaczą, dlaczego czegoś nie ma: `ikona.blade.php` wypisuje emoji, których
nawigacja już NIE używa; `home.blade.php` cytuje zakazane „wspaniały dzień?!"
jako przykład tekstu, którego nie piszemy; `karuzela-zdjec.blade.php` cytuje
zakaz swipe'a z `UX_50_PLUS.md`. To jest dokładnie ta klasa fałszywego
trafienia, przed którą trzeba się bronić przy pisaniu takiego pomiaru:
pierwsza wersja mojego skryptu zgłosiła 74 problemy, z czego wszystkie były
zmiennymi PHP (`$user`) i operatorem `!==` w blokach `@php`.

Wniosek dla #38: zostaje praca redakcyjna nad KONKRETNYMI ekranami (kit etap
D), a nie przegląd całego interfejsu pod kątem słów zakazanych — ten przegląd
jest zrobiony i wychodzi czysto.
