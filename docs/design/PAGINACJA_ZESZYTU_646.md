# Paginacja dwóch list zeszytu — #646

Status: naprawa scalona i wdrożona. Ten plik powstał po fakcie, w odbiorze
z 18 września 2026 — pakiet #646 (PR #651) nie zostawił własnego raportu
w repozytorium, a `docs/design/PAGINACJA_PRZEPISU_652.md` opisuje wyłącznie
ekran przepisu. Nie jest to nowa implementacja ani ponowna naprawa.

## Co jest w kodzie

Ekran zeszytu ma dwa paginatory na jednym adresie: przepisy pod `page`
i zapisane wpisy pod `wpisy`. Do #646 każdy przycisk „Pokaż więcej" budował
adres wyłącznie ze swojego numeru, więc przejście jednej listy cofało drugą
na stronę pierwszą.

Naprawa weszła w PR #651 jako `609328b53b6e87d99fbf178f46dbb2157feeeed7`.
Pakiet #652 (PR #654, `84186922f2c9c5bb91d2fd9f352a86eb9b8cf935`) wyniósł
regułę do wspólnego `App\Support\PaginationLinks::preserveOtherPage()`,
z którego korzystają oba kontrolery:

- `CollectionController::show()` — dwa wywołania, w obie strony,
- `RecipeController::show()` — to samo dla komentarzy i wykonań.

Helper dokleja wyłącznie rozwiązaną stronę drugiej listy i tylko wtedy, gdy
jest większa od jednego; numer docina do **własnej** ostatniej strony tej
listy. Nie kopiuje dowolnego query stringa. Zmienia odnośniki, nie bieżącą
odpowiedź — dwa numery wpisane ręcznie poza zakres nadal dają puste listy.

Regresja Laravel: `tests/Feature/ZeszytPaginacjaObuListTest.php` — oba
kierunki, listy o różnej liczbie stron (docięcie do własnej, nie cudzej
ostatniej strony), listy trzystronicowe (strona bieżąca, nie ostatnia),
numery puste/zerowe/ujemne/tekstowe/tablicowe/przepełnione, obce parametry
adresu, cudzy publiczny zeszyt oraz przekierowanie gościa na logowanie.

## Odbiór lokalny — 18 września 2026

Wykonany na **dokładnie wdrożonym SHA** `55877e2b5c0aff04d93e6db75f079c7e61d4df5d`
(Alfa 0.65). Wcześniejszy przebieg na innym drzewie odrzucono, bo widoki
`pages/collections/show.blade.php` i `pages/recipes/show.blade.php` różniły
się od main (etykieta okruszków i adres pustego stanu); pliki paginacji były
identyczne, ale dowód powtórzono w całości.

Środowisko: własny klon `/home/mateusz/kuking-DB-zeszyt`, jawny
`APP_BASE_PATH`, PostgreSQL `127.0.0.1:55439`, osobna baza
`kuking_d_b_browser` ze strefą UTC, serwer `artisan serve` na porcie 8153.
Nie dotykano produkcji ani cudzych baz. Scena: zeszyt prywatny,
**25 przepisów (3 strony po 12) i 13 zapisanych wpisów (2 strony)** — listy
celowo o różnej długości, żeby było widać, którego paginatora dotyczy
docięcie.

### Rzeczywiste przejścia, trzy sposoby obsługi

Kolejność kroków identyczna w każdym przebiegu; dane z
[`evidence/zeszyt646/raport.json`](evidence/zeszyt646/raport.json).

| krok | adres | przepisy | wpisy |
| --- | --- | --- | --- |
| start | (bez parametrów) | 01–12 | 01–12 |
| „Pokaż więcej przepisów" | `page=2` | 13–24 | 01–12 |
| „Pokaż więcej zapisanych wpisów" | `page=2&wpisy=2` | 13–24 | 13 |
| „Pokaż więcej przepisów" | `wpisy=2&page=3` | 25 | 13 |
| Wstecz | `page=2&wpisy=2` | 13–24 | 13 |

Trzecia kolumna jest sednem #646: po przejściu wpisów przepisy **zostają**
na stronie 2, a po kolejnym przejściu przepisów wpisy **zostają** na
stronie 2. Czwarty krok pokazuje dodatkowo docięcie — wpisy mają tylko dwie
strony, więc do adresu trafia `wpisy=2`, nie `wpisy=3`.

Wynik identyczny dla:

- **myszy** — prawdziwe kliknięcia, desktop 1440×900;
- **dotyku** — `tap()` na emulowanym Pixelu 5 (Playwright `hasTouch`),
  390×844;
- **klawiatury** — `Enter` na odnośniku z fokusem, 1440×900.

Mysz i dotyk traktowano jako scenariusze podstawowe, klawiaturę zachowano
obok nich. **To jest emulacja dotyku w Chromium, nie test na fizycznym
telefonie.**

### Widok i fokus

Zrzuty oglądane, nie tylko zapisane. Kadr
[`obie-listy-1440-jasny.png`](evidence/zeszyt646/obie-listy-1440-jasny.png)
i jego odpowiedniki pokazują na jednym ekranie koniec listy przepisów
(„Przepis 24" + „Pokaż więcej przepisów") i zaraz pod nim sekcję „Zapisane
wpisy" z samym „Wpis 13 kontrolny" — czyli obie listy na stronie drugiej
naraz. To samo przy 390 px z dotykiem, w obu motywach.

Motyw bierze się z konta (`users.theme`), nie z samego
`prefers-color-scheme` — pierwszy przebieg zapisał ciemny motyw przy
logowaniu z ciemnego kontekstu (D-019) i trzeba było ustawiać go jawnie
przed każdą serią. Potwierdzone tłem `rgb(243, 244, 241)` dla jasnego
i `rgb(21, 23, 20)` dla ciemnego oraz `data-theme` w `<html>`.

Fokus sprawdzony **prawdziwymi Tabami**, bez programowego `focus()`, dla
przycisku „Pokaż więcej zapisanych wpisów", przy 320, 390 i 1440 px w obu
motywach — sześć scen, wszystkie `:focus-visible = true`, pierścień
`0 0 0 2px <tło>, 0 0 0 5px <kolor fokusu>`, brak poziomego przepełnienia,
przycisk w całości w oknie. Wysokość przycisku 73 px przy 320 px
(tekst łamie się na dwie linie) i 50,5 px przy 390 i 1440 px.

## Dwie pułapki pomiaru z tego odbioru

Obie są tą samą rodziną błędu: **narzędzie pomiarowe mierzy samo siebie albo
własny stan przejściowy**. Warte zapamiętania, bo obie prowadziły do
fałszywego wniosku.

**1. Obrys fokusu odczytany w trakcie animacji.** `.btn` ma
`transition: box-shadow`, więc odczyt `getComputedStyle` bezpośrednio po
`Tab` zwraca wartość z początku przejścia —
`rgba(0,0,0,0) 0px 0px 0px 0px, rgba(0,0,0,0) 0px 0px 0px 0px` — a zrzut
zrobiony w tej samej chwili nie ma pierścienia. Wygląda to na **brak obrysu
fokusu na każdym przycisku serwisu** i takie właśnie było pierwsze odczytanie
w tym odbiorze; szykowało się głośne i nieprawdziwe zgłoszenie. Po odczekaniu
na koniec przejścia pierścień jest i w wartości obliczonej, i na zrzucie.
Nie jest to błąd; zgłoszenia nie założono.

**2. `pgrep -f` dopasowujący własną linię poleceń.** Pętla oczekująca na cudzy
przebieg testów czekała w rzeczywistości na siebie: szukany wzorzec występował
w linii poleceń tej samej powłoki, więc `pgrep` zawsze coś znajdował. Push
nigdy nie ruszył, a z zewnątrz wyglądało to na długo działający hook. To samo
dotyczy pytania „czy mój hook jeszcze trwa" zadanego tym samym wzorcem —
odpowiedź zawsze brzmi „trwa". Trzeba wykluczyć własny PID albo rozbić wzorzec.

## Czego ten odbiór NIE obejmuje

- **Rzeczywistego zoomu 200% na ekranie zeszytu.** Playwright nie ustawia
  zoomu przeglądarki; skala tekstu i `deviceScaleFactor` to co innego.
  Scen prawdziwego zoomu jest cztery, ale dla **ekranu przepisu** (#652) —
  patrz `evidence/pagination652/zoom/`.
- **Fizycznego telefonu.** Był wyłącznie Pixel 5 emulowany w Chromium.
- **Pełnej macierzy 320/360/390/414/768/1440 × dwa motywy × dwie skale
  tekstu.** Sprawdzono 320, 390 i 1440 px w obu motywach dla fokusu
  i geometrii oraz 390 i 1440 px dla przejść.
- **Produkcji.** Patrz niżej.

## Granica dowodu produkcyjnego — 18 września 2026

Odczyt produkcji `https://kuking.pl`, 13:10–13:24 UTC. Stopka:
`Alfa 0.65 · wydanie 18 września 2026, 12:12 · 55877e2`. `/health` → 200,
`degraded` wyłącznie przez historyczne `zadania_nieudane`. Porównanie przez
API GitHub: wdrożony `55877e2` jest 32 commity przed `609328b` (#651)
i 29 commitów przed `8418692` (#654), **0 wstecz** — obie naprawy są na żywo.

Ekran zeszytu stoi za `auth`:

- `GET /zeszyt` → 302 → `/login`
- `GET /zeszyt/{collection}` z dowolnym identyfikatorem → 302 → `/login`

Bez uprawnionej sesji nie da się obejrzeć żadnego zeszytu, a #646 wymaga
zeszytu z **ponad 12 przepisami i ponad 12 zapisanymi wpisami naraz**
(`config/kuking.php`: `collections.saved_posts_page_size = 12`, rozmiar
strony przepisów 12). Znany dostępny zeszyt ma 0 przepisów i 3 wpisy, więc
nie wystawia nawet jednego przycisku „Pokaż więcej", a bez dwóch przycisków
scenariusz #646 nie istnieje na ekranie.

**Nie dodawano treści na produkcji, żeby ten warunek spełnić.** To jest
granica dowodu, a nie wynik pozytywny. Odbiór produkcyjny #646 pozostaje
niewykonany i tylko z tego powodu issue jest otwarte — nie z powodu braku
naprawy w kodzie.
