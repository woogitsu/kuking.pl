# Audyt dostępności i użyteczności — 19 września 2026

Niezależny audyt UX i dostępności wykonany na `origin/main` =
`5ecbcce`. **Jedna poprawka**, potwierdzona pomiarem przed i po; reszta
raportu to wynik kontroli oraz jawnie nazwane granice. Nic nie jest zamykane —
w szczególności **#684 i #569 zostają otwarte**.

Zakres świadomie wąski: audyt i celowane poprawki, bez przebudowy
niezwiązanych funkcji. Copy i układ nietknięte poza tym, co uzasadnione niżej.

---

## 1. Cztery warstwy — co czym jest w tym raporcie

| Warstwa | Co zrobiono | Czego to NIE dowodzi |
|---|---|---|
| Kod | odczyt `resources/js/szybki-wyglad.js`, `resources/css/szybki-wyglad.css`, `routes/web.php` | nie jest oglądem przeglądarkowym |
| Automat | `scripts/dostepnosc.mjs` (axe-core + układ), nowy `scripts/audyt-przykrycia.mjs` | automat łapie ~30% problemów dostępności |
| Pomiar ręczny skryptowany | prawdziwe kółko myszy, prawdziwe przeciągnięcie palcem, prawdziwe kliknięcia i dotknięcia | to emulacja w Chromium, nie fizyczne urządzenie |
| Czytnik ekranu | **nie wykonano** | patrz §6 — to jest brak, nie wynik |

Środowisko: izolowana kopia w WSL, PostgreSQL **wyłącznie `127.0.0.1:55439`**,
bazy `kuking_audyt_browser` i `kuking_a11y` (obie do wyrzucenia), Chromium
z Playwrighta, PHP 8.4.24, Node 24.19. Produkcji nie dotykano w ogóle.

---

## 2. Automaty — wynik

`node scripts/dostepnosc.mjs` na `5ecbcce`:

```
naruszeń: 0, blokujących: 0, przepełnień w poziomie: 0, rozjazdów belki: 0,
niespójnych szerokości: 0, rozjazdów tablicy dnia: 0, focus zasłonięty w 100%: 0,
focus częściowo zasłonięty: 0, belka ponad 33% okna: 0
Zbadane ekrany — axe: 47/47, układ: 52/53.
```

Dolna belka mieści się w progu 33% okna także przy tekście 140% (23,5%)
i czcionce przeglądarki 200% (26,8%). Dowód:
[`evidence/audyt684/axe-dostepnosc.json`](evidence/audyt684/axe-dostepnosc.json).

**To jest zielone i to jest właśnie problem, o którym mówi issue #26**: axe
czyta drzewo dokumentu. Element przykryty przez pływający panel jest w drzewie
kompletny — ma nazwę dostępną, rolę, kontrast i cel dotknięcia 48 px. Dla axe
to wynik pozytywny. Dla człowieka z myszą albo palcem to przycisk, w który nie
da się trafić. Ta klasa usterek wymaga **zmierzenia ułożonej strony**, nie
sprawdzenia drzewa — stąd nowe narzędzie w §3.

`układ: 52/53` — jeden ekran pomiaru układu nie został zmierzony. Nie badano
przyczyny; poza zakresem tego audytu, odnotowane jako obserwacja.

---

## 3. Usterka, którą znalazł audyt i którą poprawiono

### Problem

**Podpowiedź „Wygląd" przykrywa sterowanie i nie da się jej ominąć myszą ani
palcem.** Dotyczy każdej strony serwisu, bo widget jest w układzie globalnym.

`aside.szybki-wyglad-podpowiedz` stoi `position: fixed; z-index: 26`
w prawym dolnym rogu. Przy **niskim oknie** — telefon w poziomie, małe okno na
pulpicie, powiększenie na telefonie — po przewinięciu na dół siada dokładnie
na przełączniku motywu w stopce. Zmierzone: **przykrycie 3536 px²** na
przycisku 68×52 px, **9 z 9 punktów próbnych trafia w podpowiedź**, zero
w przycisk.

Przełącznik motywu nie jest przypadkowym celem: razem z metryczką wersji to
jedyne dwa elementy, którym `docs/DECISIONS.md` **D-051** świadomie pozwolił
odstąpić od reguł UX 50+. Zasłonięcie akurat jego trafia w miejsce, które
właściciel wyjął spod ogólnej reguły jako ważne.

### Przyczyna

Odsłanianie przykrytej treści w `resources/js/szybki-wyglad.js` wisi na
zdarzeniu **`focusin`**: uchwyt chowa podpowiedź (`hint.hidden = true`),
gdy fokus trafi poza widget, i dodatkowo doprzewija stronę
(`window.scrollBy`, l. 186). Klawiatura dostaje więc pełną mitygację.

Mysz i dotyk **nie wywołują `focusin` na przykrytym elemencie** — kliknięcie
nigdy do niego nie dochodzi, więc fokus nie ma jak tam trafić. Oba uchwyty
`pointerdown` w tym pliku robiły co innego: jeden zapamiętywał stan na
`summary`, drugi zamykał panel poza jego obszarem. **Żaden nie dotykał
podpowiedzi.**

Sprostowanie do opisu #684: nieprawdą jest, że „całe odsłanianie wisi na
`focusin`". `geometry()` chodzi także na `resize`, `scroll`, przez
`ResizeObserver` i przy starcie oraz sprawdza `.field-error`. Wyłącznie na
`focusin` wisi **doprzewinięcie i schowanie podpowiedzi** — i to wystarczy,
żeby skutek dla wskaźnika był dokładnie taki, jak opisuje issue.

### Kroki odtworzenia

1. Pierwsza wizyta (pusty `localStorage`), okno **320×512** albo **390×520**,
   dowolny motyw.
2. Wejdź na stronę główną. Podpowiedź „Dopasuj rozmiar tekstu i wygląd strony"
   jest widoczna w prawym dolnym rogu.
3. Przewiń kółkiem myszy albo palcem na sam dół strony.
4. Spróbuj kliknąć albo dotknąć przycisku „Włącz ciemny wygląd" w stopce.

Wynik przed poprawką: kliknięcie ląduje na podpowiedzi. Przycisk jest
widoczny i wygląda na aktywny, ale nie reaguje.

### Wpływ na użytkownika

Osoba używająca myszy lub palca — czyli **scenariusz podstawowy** — nie może
przełączyć motywu na małym albo niskim ekranie przy pierwszej wizycie. Nie ma
komunikatu ani żadnej wskazówki: przycisk po prostu milczy. Przy grupie 50+ to
jest moment, w którym człowiek uznaje, że serwis jest zepsuty. Klawiatura
działa, więc problem jest niewidoczny dla każdego, kto testuje Tabem.

### Poprawka

`resources/js/szybki-wyglad.js` — podpowiedź ustępuje wskaźnikowi tak samo,
jak już ustępowała klawiaturze:

```js
const ustapWskaznikowi = () => { if (!hint.hidden) hint.hidden = true; };
const pozaPodpowiedzia = event => { if (!hint.contains(event.target)) ustapWskaznikowi(); };
listen(document, 'wheel', ustapWskaznikowi, {passive: true});
listen(document, 'pointerdown', pozaPodpowiedzia);
listen(document, 'touchstart', pozaPodpowiedzia, {passive: true});
```

Trzy świadome decyzje w tych pięciu linijkach:

- **`wheel` i `touchstart`/`pointerdown`, ale NIE `scroll`.** `scroll` leci
  także po `window.scrollTo` z kodu — chowałby podpowiedź bez udziału
  człowieka. Te trzy zdarzenia to ten sam sygnał „czytam stronę, nie
  podpowiedź", co `focusin`, tylko dla wskaźnika.
- **`touchstart` obok `pointerdown`**, bo w pomiarze sam `pointerdown` nie
  wystarczył przy przewijaniu palcem.
- **`hidden` bez `forgetHint()`** — to nie jest „Rozumiem". Nic nie trafia do
  `localStorage`, więc podpowiedź wróci przy następnej wizycie i nadal zrobi
  swoje. Zabieramy jej wyłącznie prawo do blokowania celu.

**Czego NIE zmieniono i dlaczego.** Pierwsze podejście rezerwowało miejsce na
dole strony (`padding-bottom` powiększone o wysokość podpowiedzi). Działało,
ale kosztowało **157 px pustki pod stopką na każdej stronie**, a przy 768×500
wypychało stopkę poza okno przy pełnym przewinięciu. Odrzucone jako droższe
od problemu — zmierzone, nie oszacowane. Globalnego CSS, układu ani copy nie
ruszono.

### Dowód po poprawce

Ta sama macierz, ten sam pomiar, **prawdziwe interakcje** (kółko myszy,
przeciągnięcie palcem przez CDP, rzeczywiste kliknięcia i dotknięcia):

| Pomiar | Przed (`5ecbcce`) | Po |
|---|---|---|
| macierz 160 konfiguracji, elementów nieosiągalnych | **50** w 26 konfiguracjach | **0** |
| macierz 160 konfiguracji, poziome przewijanie | 0 | 0 |
| celowany pomiar przełącznika motywu, 28 konfiguracji | 16 × NIEOSIĄGALNY (0/9 punktów, 3536 px²) | 0 × NIEOSIĄGALNY (9/9 punktów, 0 px²) |

Dowody: [`evidence/audyt684/przykrycia-przed.json`](evidence/audyt684/przykrycia-przed.json),
[`evidence/audyt684/przykrycia-po.json`](evidence/audyt684/przykrycia-po.json),
[`evidence/audyt684/regresja-684.json`](evidence/audyt684/regresja-684.json).

### Regresja

`scripts/szybki-wyglad.mjs` → `sprawdzPodpowiedzUstepujeWskaznikowi()`, wołana
z istniejącego przebiegu widgetu (zadanie CI „Port marki"). Osiem konfiguracji:
320×512, 320×420, 384×512, 390×520 × kółko i dotyk. Każda sprawdza trzy rzeczy:

1. podpowiedź jest widoczna na starcie,
2. po geście **ustępuje**,
3. po odświeżeniu **wraca** — bo to nie było „Rozumiem".

Plus kontrola dodatnia: dotknięcie samej podpowiedzi **nie** chowa jej po
cichu; od tego jest przycisk „Rozumiem".

**Kontrola ujemna wykonana:** na pliku `szybki-wyglad.js` z `origin/main`
regresja oblewa komunikatem `PODPOWIEDZ_NIE_USTAPILA 320x512 kolko`.
Przebieg PASS → FAIL → PASS. Cały istniejący przebieg widgetu (36 geometrii,
wariant bez JS, zapis gościa, reset, 429, Escape) przechodzi po poprawce bez
zmian.

### Pułapka pomiaru, którą warto zapamiętać

Pierwsze przebiegi „po poprawce" pokazywały, że dotyk nadal nie działa.
Przyczyna była w pomiarze, nie w kodzie: przy 320×512 podpowiedź zajmuje
~296×156 px w dolnej części okna, a mój gest startował od 75% wysokości —
**czyli na niej**. `hint.contains(event.target)` było prawdziwe i podpowiedź
słusznie zostawała. Wyglądało to na niedziałającą poprawkę, a było źle
wycelowanym palcem. Gest musi omijać podpowiedź; zapisane w komentarzu
regresji.

Druga pułapka z tego samego przebiegu: pomiar chodził na buildzie zostawionym
przez kontrolę ujemną. Runner pomiaru buduje teraz assety zawsze.

---

## 4. Nowe narzędzie weryfikacji

`scripts/audyt-przykrycia.mjs` — mierzy to, czego axe z definicji nie
zmierzy: czy element interaktywny jest **osiągalny wskaźnikiem**. Dla każdego
widocznego elementu sprawdza `document.elementFromPoint` w dziewięciu punktach;
element jest nieosiągalny dopiero wtedy, gdy **żaden** nie trafia w niego.

Macierz: 4 szerokości (320/390/768/1440) × 2 motywy × 3 warianty pisma
(100%, 140%, zoom 200%) × 10 ścieżek = 160 konfiguracji. Przewijanie
**kółkiem myszy**, nie `window.scrollTo` — bo `scrollTo` nie niesie informacji
o tym, że przy ekranie siedzi człowiek, i produkuje usterki, do których nikt
nie może dojść. Ta zmiana metody sama w sobie zdjęła fałszywe trafienia.

Skrypt odmawia startu na `DB_PORT=5432` i na bazie spoza wzorca
`kuking_audyt_*`, bo robi `migrate:fresh`.

---

## 5. Granica interpretacji zoomu 200% — ważna dla czytania wyników

Zoom 200% przy oknie 320 px daje **160 px CSS**, przy 390 px — **195 px**.
Obie wartości są **poniżej 320 px CSS**, czyli poniżej podłogi, którą deklaruje
`docs/UX_50_PLUS.md`. „Przy 200% powiększenia" i „przy szerokości 320 px" są
tam **dwoma osobnymi warunkami**, nie jednym złożonym.

Pierwszy przebieg mierzył je razem i wyprodukował 10 konfiguracji z poziomym
przewijaniem oraz 40 nieosiągalnych elementów — **wszystkie spoza obiecanej
obwiedni**. Po ograniczeniu wariantu zoomu do szerokości, w których zostaje
co najmniej 320 px CSS (czyli od 640 px w górę), poziome przewijanie wynosi
**0 w całej macierzy**.

Zgłaszanie tamtych trafień jako usterek byłoby wymaganiem czegoś, czego
produkt nigdzie nie obiecał. Odnotowane jako obserwacja: **poniżej 320 px CSS
strona przewija się w poziomie**. Czy to ma być wspierane, jest decyzją
właściciela, nie ustaleniem audytu.

---

## 6. Czego ten audyt NIE sprawdził

Każdy punkt jest brakiem dowodu, nie wynikiem pozytywnym.

- **Czytnika ekranu nie uruchomiono.** Ani NVDA, ani VoiceOver. Odczyt drzewa
  dostępności to co innego niż odsłuch — mówi o tym wprost **#569**,
  którego jedyny pozostały warunek to właśnie rzeczywisty odsłuch. Ten audyt
  go **nie domyka** i nie próbował.
- **Fizycznego urządzenia nie badano.** Wszystkie wąskie i niskie okna to
  emulacja w Chromium. Gest dotyku to `Input.dispatchTouchEvent`, nie palec.
- **Panelu moderacji nie objął pomiar przykrycia.** Wejście wymaga konta
  moderatora i potwierdzonego 2FA. Panel ma własny pomiar axe i układu
  w `scripts/dostepnosc.mjs` (trzy ekrany), ale osiągalności wskaźnikowej tam
  nie mierzono.
- **Ekranu przepisu i trybu gotowania nie zmierzono narzędziem z §4.** Skrypt
  nie znalazł w scenie demonstracyjnej publicznego odnośnika do przepisu na
  `/odkryj` ani na stronie głównej i pominął obie ścieżki — widać to w logu
  przebiegu. Ekran przepisu jest natomiast w macierzy axe.
- **Kolejności Tab i sensu etykiet nie oceniano systematycznie.** Automat
  sprawdza istnienie nazwy dostępnej, nie jej trafność. To jest robota dla
  człowieka i pozostaje do zrobienia.
- **Lighthouse nie uruchomiono** w tym przebiegu.
- **Windows High Contrast (`forced-colors`) i `prefers-reduced-motion`** —
  niesprawdzone.

---

## 7. Obserwacje bez poprawki

Zapisane, świadomie nienaprawione — każda wymagałaby zmiany poza zakresem
tego audytu.

1. **Martwa reguła CSS.** `szybki-wyglad.css:28` celuje w
   `.szybki-wyglad:not([open]) > .szybki-wyglad-podpowiedz`, a podpowiedź jest
   **rodzeństwem** widgetu, nie jego dzieckiem
   (`components/szybki-wyglad.blade.php`). Selektor nie ma prawa trafić.
   Nic się przez to nie psuje — reguła przywracała tylko `display: block`,
   który i tak obowiązuje.
2. **Tryb przepływu nie uwalnia podpowiedzi.** `data-wyglad-w-przeplywie`
   zdejmuje `position: fixed` z samego widgetu, ale `szybki-wyglad.css:35`
   nadaje podpowiedzi własne `position: fixed`, więc zostaje ona nad treścią
   także w tym trybie. Po poprawce z §3 nie ma to skutku dla wskaźnika.
3. **Rozważane i odrzucone:** rozszerzenie `geometry()` o wykrywanie
   przykrycia dowolnego elementu interaktywnego i przechodzenie w tryb
   przepływu. Ryzyko oscylacji: tryb przepływu zmienia wysokość dokumentu,
   co zmienia to, co jest na dole, co zmienia wynik pomiaru. Mechanizm
   `.field-error` znosi to, bo jest rzadki i chwilowy; stała podpowiedź
   wywoływałaby go bez przerwy.
4. **Dotknięcie treści podpowiedzi nic nie robi.** Chowa ją dopiero
   „Rozumiem" albo — po tej poprawce — gest poza nią. Czy całe pudełko ma być
   klikalne, jest decyzją produktową, nie usterką dostępności.

---

## 8. Stan issues

| Issue | Co ten audyt wnosi | Co zostaje |
|---|---|---|
| **#684** | przyczyna potwierdzona i zawężona, poprawka + regresja z kontrolą ujemną, 50 → 0 nieosiągalnych w macierzy 160 konfiguracji | odbiór na fizycznym urządzeniu; kafle kolażu tagów z opisu issue nie były osobno mierzone w tym przebiegu |
| **#569** | **nic** — czytnika ekranu nie uruchomiono | cały pozostały zakres: rzeczywisty odsłuch pozostałego czasu minutnika |

**Żadne issue nie jest tym zamykane.**
