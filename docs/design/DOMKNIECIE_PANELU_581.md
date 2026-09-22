# Domknięcie portu panelu moderacji — odbiór #581

Podstawa: `main` **5ecbcce684c3746d53914c28f3fd8f40669baa40**.
Gałąź: `fix/581-domkniecie-panelu`. Worktree: `kuking-581-domkniecie`.

Ten odbiór **nie powtarza portu z #587** i nie otwiera na nowo zamkniętych
pakietów. Port jest scalony i wdrożony; #637, #640, #641, #642 i #643 domknęły
kolejne stany. Zadaniem tej tury było sprawdzić, **co jeszcze nie jest zgodne
z systemem marki**, i naprawić wyłącznie to.

---

## 1. Wniosek, od którego trzeba zacząć

**Panel nie jest „nadal na starym stylu".** Zmierzone, nie założone:

| Pomiar | Wynik |
|---|---|
| Ekrany panelu korzystające ze wspólnej ramy `<x-panel-moderacji>` | **14 z 14** |
| Reguły CSS dotyczące panelu z zaszytym kolorem (zamiast tokenu) | **0 ze 147 (0,0%)** |
| Te same reguły w pozostałej części serwisu | 23 z 1014 (2,3%) |
| Ekrany z pustym stanem używające wspólnego `<x-empty-state>` | 9 z 9 stanów stronicowych |
| Przyciski w panelu bez klasy `btn` | **0** |
| Zakładki niezgodne ze wzorcem `nav.tabs > a.tab` + `aria-current` | **0** |
| Ekrany bez `title` albo bez nazwy ekranu w pasku panelu | **0** |

Pod względem tokenów panel jest **czystszy niż reszta aplikacji**. Historyczna
przyczyna z treści issue (`marka-rama.css` wyłączające `[data-tryb-panelu]`)
jest dziś świadomą granicą między ramą publiczną a ramą panelu, nie brakiem
portu. Złotej nawigacji nie ma w kodzie ani śladu.

Znalezione niezgodności są **dwie** i obie są punktowe. Opisane niżej.

---

## 2. Co naprawione

### 2.1. Dwa ekrany miały akapit wprowadzający bez żadnej reguły

`pages/admin/sygnaly.blade.php` i `pages/admin/tag-promotions.blade.php`
otwierały się akapitem `class="lead"`. **Takiej reguły nie ma w żadnym arkuszu
i nigdy nie było** — `git log -S'.lead'` na `resources/css/app.css` nie pokazuje
ani jednego commita, który by ją dodawał. Klasa trafiła do tych widoków przy
ich powstaniu (7 i 10 września 2026), czyli **przed** portem marki, i port jej
nie wychwycił, bo nie psuła niczego widocznie — po prostu nic nie robiła.

Zmierzone na **zbudowanym** arkuszu (`public/build/assets/app-CIpqez7v.css`,
135 535 B, runtime `kuking-panel581-runtime`):

| Selektor | Wystąpień |
|---|---|
| `.lead` | **0** |
| `.text-lead` | 2 |
| `.text-title-sm` | 1 |
| `.czeka-pierwszy` | 1 |

Reszta nowego interfejsu (strona powitalna, przepis, koniec onboardingu,
„o nas", kreator przepisu) używa `text-lead` — klasy, którą Tailwind 4
generuje z tokenu `--text-lead` z bloku `@theme` w `tokens.css`. Te dwa ekrany
panelu renderowały więc pierwsze zdanie ekranu wielkością tekstu podstawowego
(18 px) zamiast wprowadzenia (22 px).

Poprawka: `class="lead"` → `class="text-lead"` w obu plikach. Bez zmiany treści.

### 2.2. Jeden ekran nazywał się inaczej w pasku, w tytule i w nagłówku

`pages/admin/bez-odpowiedzi-inne.blade.php` obsługuje **dwa** rodzaje treści
(przepisy i „Ugotowałem"). Nagłówek `<h1>` nazywał rodzaj wprost, ale
`<x-layout title="…">` oraz `<x-panel-moderacji ekran="…">` miały wpisane
na sztywno ogólne „Bez odpowiedzi". Pasek panelu stał więc bezpośrednio nad
nagłówkiem i mówił co innego niż on.

Dwa pozostałe ekrany tej samej rodziny (wpisy, pytania) nazywają rodzaj
w obu miejscach — niespójny był wyłącznie ten jeden.

To nie jest kosmetyka: pasek panelu istnieje po to, żeby człowiek wiedział,
**gdzie jest**, gdy wejdzie na ekran z odnośnika w treści, a nie z menu
(uzasadnienie w `components/panel-moderacji.blade.php`). Pasek nazywający
ekran inaczej niż nagłówek odbiera sobie tę jedną funkcję. Tytuł karty jest
dodatkowo jedyną etykietą przy kilku otwartych kartach panelu.

Poprawka: jedna zmienna `$nazwaEkranu` zasila wszystkie trzy miejsca.

---

## 3. Czego świadomie NIE zmieniono

- **Własne podsumowanie błędów w `reports.blade.php`.** Ekran ma do dwudziestu
  pięciu formularzy na stronie; `<x-error-summary>` w każdym dałoby dwadzieścia
  pięć `role="alert"` na jeden błąd. Dwa pola (`reason_code`,
  `suspend_days_custom`) mają dziś `id` w innej konwencji niż `x-field`, więc
  wspólny komponent wysłałby moderatora pod nieistniejącą kotwicę. Odstępstwo
  jest opisane w samym pliku i uzasadnione — nie ruszam go.
- **Brak `<x-blad-grupy>` w panelu.** Grupy wyboru w `appeals` i `wiadomosc`
  mają własne `id` i własny `field-error` wpięty w podsumowanie (#641, #643).
  Zamiana na wspólny komponent nie zmieniłaby zachowania, a ruszyłaby ekrany
  odebrane w innych pakietach.
- **Drobne puste stany w sekcjach formularzy** (`daily-board`,
  `kolaz-powitalny`, `uzytkownik`) używają `<p class="meta">`, a nie
  `<x-empty-state>`. To pusta lista **wewnątrz** formularza, nie pusty stan
  strony — wstawienie tam znaku Kuking i przycisku akcji byłoby błędem.
- **Zachowanie moderacji, uprawnienia, dane i trasy.** Ani jedna zmiana.

---

## 4. Kontrole

| Kontrola | Wynik |
|---|---|
| `git diff --check` | czysto |
| `vendor/bin/pint --test` | **PASS**, 1125 plików |
| `vendor/bin/phpstan analyse --no-progress` | **`[OK] No errors`** |
| Testy panelu i moderacji (`--filter 'Panel\|Moderac\|BezOdpowiedzi\|Sygnal\|Kolejk\|Wiadomosc\|Bramk\|Decyzje'`) | **507 passed (2955 assertions)** |
| Nowe regresje | **7 passed (39 assertions)** |
| Kontrole ujemne | **5/5** czerwonych na właściwej asercji, 5/5 przywróceń bajt w bajt |
| Ogląd w przeglądarce | **36 przebiegów**, zero przewijania w poziomie, zero niezgodnych nazw (§5) |

Dowód kontrol ujemnych: `docs/design/evidence/panel581d/kontrola-ujemna.log`
— dla każdego sabotażu plik, test, wynik, powód czerwieni (asercja, nie błąd),
fraza z konkretnej asercji oraz MD5 i mtime po przywróceniu. Kopie bajtów poza
repozytorium, przywracanie `cp -p`, nigdy `git checkout --` ani `git stash`.

Wśród sabotaży jest jeden **drugiego stopnia**: skasowanie CAŁEGO paska panelu
z ekranu rodziny — żeby asercje o treści paska nie przechodziły przez jego
zniknięcie.

### Uwaga o jednym oblanym teście, który nie był usterką

Pierwszy przebieg zestawu panelu pokazał `PortMarkiMaWlasnaBramkeCiTest` na
czerwono („pominięto `scripts/katalog-tagow.mjs`"). Przyczyna była **w moim
środowisku, nie w kodzie**: skrypt synchronizujący kopię wykonawczą nie
przenosił katalogów `.github` i `scripts`, więc test czytał **starszy** plik
`ci.yml`. Po uzupełnieniu synchronizacji ten sam test przechodzi.
Odnotowane, bo czerwień bez przeczytanej przyczyny nie jest informacją.

---

## 5. Ogląd w przeglądarce

Wykonany. Prawdziwe logowanie **formularzem i kodem TOTP** — bez tworzenia
sesji z pominięciem autoryzacji. Własna baza `kuking_581d_browser` na
`127.0.0.1:55439`, własny build assetów, `php artisan serve` na porcie 8137.

**36 przebiegów:** cztery ekrany × 320/390/768/1440 px × motyw jasny i ciemny
(32), oraz cztery przebiegi **rzeczywistego zoomu 200 %** przez Chrome
DevTools Protocol (`pageScaleFactor: 2` + `deviceMetrics` 320 CSS px,
potwierdzone `devicePixelRatio = 2` i `innerWidth = 320`).

| Co mierzone | Wynik |
|---|---|
| Przewijanie w poziomie | **0 na 36 przebiegów** |
| Nazwa ekranu niezgodna między nagłówkiem, tytułem a paskiem | **0 na 36** |
| Rozmiar akapitu wprowadzającego | **22 px w każdym z 18 przebiegów**, gdzie akapit występuje (przed poprawką: 18 px) |
| Zoom 200 %: 320 CSS px, `dpr` 2, brak przewijania, nazwy zgodne | 4 na 4 |

Dowody: `evidence/panel581d/oglad.json` oraz trzy obejrzane kadry
(320 jasny, 1440 ciemny, zoom 200 %).

### 5.1. Tekst poniżej 18 px — sprawdzone, nie jest usterką panelu

Sonda zgłosiła po dwa elementy na ekran: `p.meta`, `span.meta` i odnośnik
wewnątrz nich. To klasa `.meta` z `font-size: var(--text-help)` — **16 px**,
zdefiniowana raz, globalnie.

Kontrola porównawcza tą samą sondą na stronach **spoza panelu**:

| Strona | Elementów poniżej 18 px |
|---|---|
| `/` (powitalna) | 21 (19 × 16 px, 2 × 8 px) |
| `/o-kuking` | 23 (21 × 16 px, 2 × 8 px) |
| ekran panelu | **1–2** |

Panel ma takiego tekstu **mniej** niż strony publiczne. To decyzja systemu
projektowego (`--text-help`), nie pozostałość starego stylu panelu.

### 5.2. Pływający „Wygląd" zasłania fragment akapitu — i moja poprawka to POGŁĘBIA

To jedyny niewygodny wynik oglądu i podaję go wprost. Sterowanie wyglądem
(`components/szybki-wyglad`, `position: fixed`, `z-index: 25`) stoi w prawym
dolnym rogu **na każdej stronie serwisu**. Przy 320 px nachodzi na akapit
wprowadzający. Zmierzone na tym samym ekranie, w dwóch wariantach klasy:

| Wariant | Rozmiar tekstu | Akapit (top–bottom) | Nakładanie z przyciskiem |
|---|---|---|---|
| `lead` — stan **przed** poprawką | 18 px | 529–780 | **40 px w pionie** |
| `text-lead` — stan **po** poprawce | 22 px | 529–870 | **54 px w pionie** |

Wniosek uczciwy: **zasłanianie istniało przed tą zmianą** i bierze się
z pływającego sterowania, nie z typografii. Moja poprawka **powiększa je
o 14 px**, bo poprawnie sformatowany akapit jest wyższy.

Czego to **nie** znaczy: nic nie jest ukryte trwale — element jest `fixed`,
więc przewinięcie odsłania tekst, a samo pływające sterowanie jest świadomym
elementem układu obecnym na całym serwisie. Nie nazywam tego więc usterką
wprowadzoną przez ten pakiet ani nie naprawiam globalnego układu przy okazji
portu panelu. **Zostawiam to do decyzji recenzenta** wraz z pomiarem
(`evidence/panel581d/zaslanianie-wyglad.json`); gdyby rezerwa pod pływającym
sterowaniem miała powstać, jest to zmiana wspólnego układu, a nie panelu.

---

## 6. Czego ten odbiór NIE dowodzi

- Ogląd objął **cztery ekrany, których dotyczy zmiana**, nie wszystkie
  czternaście powierzchni panelu. Pozostałych ekranów ta tura nie zmieniała
  i nie mierzyła na nowo — ich odbiór pochodzi z pakietów #587/#637/#640–643.
- Zmierzono **tekst 100 %**. Wariantu 140 % w tej turze nie przebiegano;
  wcześniejsze pakiety mają go w swoich raportach.
- Jeden silnik: **Chromium** (Playwright). Bez Firefoksa i bez WebKita.
- Dane lokalne i demonstracyjne. Nie dotykano produkcji ani danych
  użytkowników. Nie wykonywano żadnej decyzji moderacyjnej.
- Pomiary z §1 są **statyczne** (odczyt źródeł i zbudowanego arkusza).
  Dowodzą, że panel korzysta z tokenów i wspólnych komponentów — nie
  zastępują obejrzenia każdej powierzchni.

---

## 7. Znalezione poza zakresem — do osobnych zgłoszeń

1. **Ta sama martwa klasa `lead` żyje w dwóch widokach spoza panelu:**
   `pages/questions/index.blade.php:3` i `pages/settings/tags.blade.php:15`.
   Ten pakiet ich nie rusza, bo dotyczy panelu. Poprawka jest identyczna
   (`lead` → `text-lead`) i warta osobnego, wąskiego zgłoszenia.
2. Regresja `PanelUzywaTypografiiMarkiTest` pilnuje dziś wyłącznie ekranów
   panelu. Gdyby powstał strażnik obejmujący wszystkie widoki, wykryłby tę
   klasę wszędzie — ale byłby to strażnik całego serwisu, nie panelu.

---

## 8. Środowisko

Kopia wykonawcza `/home/mateusz/kuking-581d` na dysku Linuksa, z własnym
`vendor`. PostgreSQL wyłącznie `127.0.0.1:55439`, **własna baza
`kuking_581d_tests`** (UTC) założona na tę turę i przeznaczona do wyrzucenia.
Poczta `array`, kolejka `sync`. Cudzych baz (`kuking_581_tests`,
`kuking_581_browser`, `kuking_581_acceptance`) nie dotykano. `main` bez zmian.
