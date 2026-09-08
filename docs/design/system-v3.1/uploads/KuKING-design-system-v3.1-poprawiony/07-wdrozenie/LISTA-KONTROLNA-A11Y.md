# Lista kontrolna dostępności — przechodzi się ją przed wypuszczeniem ekranu

Rozszerza `02-co-obowiazuje/A11Y_CHECKLIST.md` o to, co wnoszą nowe
komponenty: wycięty uśmiech w znaku, plakietkę cichą 15 px, podkład pod
tekstem na zdjęciu, schowany wybór pliku, trzecią kolumnę zawsze, sprite ikon
i `:target` zamiast skryptu.

Cel: **WCAG 2.2 AA**, ale przy tej grupie odbiorców to jest podłoga, nie
sufit. Reguła produktowa mówi 48 px na kontrolkę, a nie 24, i 4.5:1 na tekst
nawet tam, gdzie norma dopuszcza 3:1.

**Jak się tego używa:** przechodzisz sekcje po kolei, na jednym ekranie.
Każdy punkt ma być do zrobienia w **pół minuty**. Jeśli trwa dłużej, problem
jest z ekranem, nie z metodą.

**Jeden ekran to cztery przejścia:** motyw jasny, motyw ciemny, skala tekstu
140 %, szerokość 320 px. Sprawdzanie samego „normalnego” widoku przepuszcza
dokładnie te usterki, które dotykają naszej grupy najczęściej — bo to ona
włącza większy tekst.

---

## K. Klawiatura

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| K-1 | **„Przejdź do treści” jest pierwszy** | Kliknij w pasek adresu, potem jeden raz Tab | Pojawia się widoczny napis `Przejdź do treści`; Enter przenosi za nawigację | Nic się nie pojawia (`.tylko-dla-czytnika:focus-visible` nie działa) albo pierwszy Tab wchodzi w logotyp |
| K-2 | **Cały ekran samym Tabem** | Tab do końca strony, potem Shift+Tab z powrotem | Każdy przycisk, link, pole i chip jest osiągalny; nic nie trzyma fokusu | Element klikalny, którego Tab pomija; fokus wpada w pętlę |
| K-3 | **Kolejność fokusu zgadza się z kolejnością wzrokową** | Tab, patrząc na pierścień | Pierścień idzie z góry na dół i z lewej na prawo | Fokus skacze do szyny w środku kolumny treści albo wraca do belki |
| K-4 | **Pierścień widać na każdym tle** | Tab przez przyciski główne, poboczne, ciche i „Usuń” | Pierścień 3 px, na przyciskach kolorowych z 2 px halo w kolorze podłoża | Pierścień styka się bezpośrednio z terakotą (kontrast 1.0–1.2:1) — znaczy, że reguła `.card .btn:focus-visible` nie złapała tego kontenera |
| K-5 | **Wybór zdjęcia da się obsłużyć z klawiatury** | Tab do obszaru „Dodaj zdjęcie”, Enter albo spacja | Otwiera się okno wyboru pliku; obwódka jest widoczna **na etykiecie**, nie na schowanym polu | Tab przeskakuje obszar (input schowany przez `display:none`, nie przez `position:absolute`) albo fokus jest niewidoczny |
| K-6 | **Podsumowanie błędów prowadzi do pola** | Wyślij pusty formularz, Tab do pierwszego linku w podsumowaniu, Enter | Strona skacze do pola i **pole ma obwódkę** (`.field:target`), bez JavaScriptu | Skok następuje, ale nie widać, do którego pola — brak `id` na opakowaniu pola |
| K-7 | **Nawigacja działa przy wyłączonym skrypcie** | Przeglądarka z wyłączonym JavaScriptem albo Playwright `javaScriptEnabled: false` | Oba paski nawigacji, logowanie i wysłanie formularza działają | Cokolwiek wymaga skryptu — to jest złamanie twardego ograniczenia nr 3, nie usterka |
| K-8 | **Esc nie jest jedyną drogą wyjścia** | Otwórz każde miejsce, które coś zasłania | Jest widoczny przycisk z tekstem („Zamknij”, „Zostaw”), a Esc jest dodatkiem | Wyjście tylko przez Esc albo tylko przez kliknięcie w tło |

---

## C. Czytnik ekranu

Minimum: **NVDA na Windowsie** (Ctrl+Alt+N) i **VoiceOver na iPhonie**
(potrójne kliknięcie bocznego przycisku). Nasza grupa używa telefonu częściej
niż komputera, więc VoiceOver na iPhonie nie jest opcjonalny.

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| C-1 | **Nagłówki tworzą spis treści** | NVDA, klawisz `H` przez cały ekran | Jeden `h1`, poziomy schodzą po kolei bez przeskoków | Dwa `h1`; skok z `h2` na `h4`; `.karta-tytul` jako `h2` w środku listy kart, gdy nad listą nie ma `h2` |
| C-2 | **Każdy przycisk ogłasza swój cel** | NVDA, Tab | „Ugotowałem, przycisk”, „Napisz komentarz, link” | „przycisk” bez nazwy — znaczy, że ikona nie ma `aria-hidden`, a tekstu nie ma |
| C-3 | **Ikony ze sprite'u milczą** | Sprawdź znacznik: każde `<svg><use>` | Ma `aria-hidden="true"` i `focusable="false"`, a obok stoi tekst | Czytnik czyta „grafika” przy każdej pozycji nawigacji |
| C-4 | **Kropka rozdzielająca metadane milczy** | NVDA na karcie wpisu | Czyta „7 września, 18:20, publicznie” | Czyta „kropka środkowa” — brak `aria-hidden` na `.karta-meta-kropka` |
| C-5 | **`kuKING` nie jest literowany** | NVDA i VoiceOver na nagłówku `kuKINGi na dziś` | Czyta „kukingi na dziś” | Czyta „ku-ka-i-en-gie” — to jest właśnie to, co `COPY_STYLE.md` §2 oznacza jako `[do potwierdzenia testem]`; `aria-label` ma to naprawiać i trzeba to potwierdzić, a nie założyć |
| C-6 | **Zdjęcie potrawy ma opis, awatar dekoracyjny nie ma** | Przejdź obrazki klawiszem `G` | Zdjęcie dania: opis mówi, co jest na talerzu. Awatar obok wypisanego imienia: `alt=""` | Awatar czytany drugi raz po imieniu; zdjęcie z opisem „zdjęcie” albo z nazwą pliku |
| C-7 | **Trzecia kolumna nie jest pustym punktem orientacyjnym** | NVDA, lista punktów orientacyjnych (`D`) | Kolumna pusta nie ma `aria-label` i nie jest ogłaszana albo nie istnieje w drzewie | Ogłaszane „Obok treści, region” bez zawartości — D-102 każe zostawić ją pustą wizualnie, nie w drzewie |
| C-8 | **Szyna w dwóch kopiach czytana raz** | NVDA na tablicy przy 1440 px i przy 390 px | Treść szyny czytana **raz** | Czytana dwa razy — obie kopie (`.tylko-szerokie` i `.tylko-waskie`) są w drzewie. Przełącznik musi używać `display: none`, nie `visibility` ani przezroczystości |
| C-9 | **Zmiana ogłaszana bez przerywania** | Zapisz szkic, obserwuj czytnik | „Szkic zapisany.” ogłoszone przez `aria-live="polite"`, fokus się nie rusza | Fokus skacze na plakietkę; albo `aria-live="assertive"` przerywa pisanie |
| C-10 | **Wiersz nieprzeczytany mówi, że jest nieprzeczytany** | NVDA na `/powiadomienia` | W treści wiersza jest **słowo** — plakietka `nowe` albo tekst dla czytnika | Wiersz różni się tylko wyglądem. Arkusz daje tło i pionową kreskę, ale czytnik nie widzi ani jednego, ani drugiego — słowo musi być w znaczniku. **Patrz U-2 na końcu.** |
| C-11 | **Bieżąca pozycja nawigacji ma `aria-current="page"`** | NVDA na obu paskach | Ogłasza „bieżąca strona” | Bieżąca pozycja odróżnia się tylko wyglądem |

---

## KO. Kontrast

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| KO-1 | **Cała paleta** | `node 01-fundamenty/kontrast.mjs` | `Sprawdzonych par: 70. Nie przechodzi: 0.`, kod wyjścia 0 | Jakikolwiek wiersz „nie przechodzi”. Nie wypuszczać |
| KO-2 | **Nowy kolor nie wchodzi bez pary** | Przegląd kodu | Każdy nowy hex ma dopisaną parę w `01-fundamenty/kontrast.mjs` i przeliczony wynik | Nowy kolor bez pary. Tak powstało 2.32:1 na bieżącej pozycji nawigacji w trybie ciemnym: 55 par nie objęło jednej, bo w jasnym była poprawna |
| KO-3 | **Tekst na zdjęciu** | Otwórz kartę z `.zdjecie-z-napisem` na zdjęciu **jasnym** (biały talerz, mąka, śnieg) | Napis czytelny, bo `.zdjecie-podklad` jest pełny u dołu; policzone 18.37:1 (jasny) i 21.00:1 (ciemny) | Podkład włączany „gdy trzeba” — nie da się tego ocenić z góry, następne zdjęcie będzie inne |
| KO-4 | **Plakietka cicha 15 px** | Zmierz kontrast `ink-muted` na tle, na którym stoi | 6.36:1 na `surface-sunken`, 7.01:1 na `surface`, 7.54:1 na karcie | 15 px to jedyny rozmiar poniżej 16 px w systemie. Wolno go użyć **tylko** dla informacji, która jest dostępna gdzie indziej. Data i „konto przykładowe” — tak. Cena, termin, ostrzeżenie — nie |
| KO-5 | **Obwódka pola i przycisku pobocznego** | Narzędzia przeglądarki, próbnik | ≥ 3:1 wobec sąsiedztwa: 3.87:1 (na tle strony), 4.16:1 (na karcie), 3.51:1 (wobec własnego tła pola) | Obwódka jaśniejsza „dla spokoju” — to najczęstsza szybka poprawka, która psuje ten próg |
| KO-6 | **Najciaśniejsza para w palecie** | Przegląd kodu przy każdej zmianie `--color-brand-solid` | W trybie ciemnym biel na `#C1502A` daje **4.72:1** przy progu 4.5 | Ktokolwiek przyciemni ten kolor „żeby ładniej wyglądał” — margines wynosi 0.22 i to jest cała rezerwa |
| KO-7 | **Automat na wszystkich ekranach** | `node scripts/dostepnosc.mjs` | 0 naruszeń `serious` i `critical` na 11 ekranach × 4 warianty | Jakiekolwiek NOWE naruszenie względem `storage/dostepnosc-przed.json` |

---

## S. Skala tekstu i powiększenie

To jest sekcja, która w tym produkcie łapie najwięcej. Skala tekstu jest
**ustawieniem konta**, nie zoomem przeglądarki, i mnoży wyłącznie typografię —
odstępy, promienie i wysokości kontrolek zostają.

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| S-1 | **Skala w ogóle działa** | Na `<html>` ustaw `data-text-scale="140"`, potem w konsoli `getComputedStyle(document.body).fontSize` | **25.2 px** (18 × 1.4) | 18 px — arkusz nie zna tej wartości. Dzisiejszy `03-kod-wygladu/tokens.css` zna wyłącznie `112`, `125` i `150`; `90` i `140` dokłada dopiero nowy plik |
| S-2 | **Każdy krok ustawienia coś zmienia** | `/ustawienia/czytelnosc`, przejdź wszystkie kroki | Każdy krok zmienia rozmiar tekstu | Krok, po którym nic się nie dzieje — baza i arkusz mają inne listy wartości |
| S-3 | **Brak przewijania w poziomie przy 320 px i 140 %** | `node 07-wdrozenie/sprawdz-uklad.mjs` (5 szerokości × 3 skale) albo ręcznie w narzędziach | `Żadna strona nie przewija się w poziomie` | Każdy wiersz `strona przewija się w poziomie (X > Y)`. Sprawdź belkę górną jako pierwszą — to ona rozpycha najczęściej |
| S-4 | **Nic nie jest ucięte** | 320 px, skala 140 %, przejrzyj ekran wzrokiem | Tekst się zawija, przyciski robią się wyższe | Napis ucięty wielokropkiem albo schowany za `overflow: hidden` — najczęściej dlatego, że ktoś dał `height` zamiast `min-height` |
| S-5 | **Dolny pasek: pięć podpisów mieści się** | 320 px, skala 150 % | Pięć podpisów czytelnych, każdy w całości | Podpis ucięty. Poprawka jest w arkuszu, sekcja 16: `.bottom-nav-item { min-width: 0; overflow-wrap: anywhere; hyphens: auto }`. Jeśli podpis nadal ucieka, ktoś ją nadpisał |
| S-9 | **Belka górna mieści się na najwęższym ekranie** | 320 px i skala 150 %, na stronie, która używa belki **bez otoczki** | Belka zawija się w dwa wiersze, pole wyszukiwania znika (poniżej 64rem „Szukaj” jest pozycją dolnego paska). Zmierzone na `03-szablony/tablica.html`: `.topbar-akcje` 207 px przy 100 %, 273 px przy 150 %, dokument 320 px | Dokument szerszy niż okno. **Nie sprawdzaj tego w galerii** — patrz A.0 |
| S-6 | **Zoom przeglądarki 200 %** | Ctrl/Cmd i `+` do 200 % przy oknie 1280 px | Brak przewijania w poziomie, treść się przekłada | Dwie kolumny wciśnięte na siłę zamiast przełamania |
| S-7 | **Skala 90 % nie schodzi poniżej progu** | `data-text-scale="90"` | Tekst podstawowy 16.2 px | Cokolwiek poniżej 16 px w tekście ciągłym |
| S-8 | **Długie słowo nie rozpycha strony** | 320 px, powiadomienie „ugotowała/ugotował”, komentarz z wklejonym adresem | Słowo łamie się w środku, strona zostaje na 320 px | Strona szersza niż okno — sprawdź, czy `overflow-wrap: break-word` na `body` przetrwało |

---

## W. Tryb wysokiego kontrastu (forced-colors)

Windows → Ustawienia → Ułatwienia dostępu → Kontrast → włącz motyw
kontrastowy. Chrome i Edge respektują `forced-colors: active`.

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| W-1 | **Pola i przyciski mają obramowanie** | Otwórz `/dodaj/zdjecie` i `/register` | Każde pole i przycisk ma obrys `ButtonText` | Pole bez obrysu. **Najczęstsza przyczyna po tej zmianie:** nowy arkusz łapie `.btn, .field-input, .card`, a dotychczasowy łapał `.btn, .field input, .field select, .field textarea`. Pole bez klasy `.field-input` wypada z reguły |
| W-2 | **Pierścień fokusu nadal widoczny** | Tab przez formularz | Obrys `Highlight` | Fokus znika, bo `box-shadow` w trybie wymuszonym nie jest rysowany — technika halo z `.btn:focus-visible` **nie działa** w forced-colors i dlatego reguła `:focus-visible { outline: 3px solid Highlight }` musi zostać |
| W-3 | **Obszar wyboru zdjęcia jest widoczny** | `/dodaj/zdjecie` | Kreskowana ramka `.wybor-zdjecia` widoczna | Znika — obszar jest wtedy niewidzialny, a schowany input nie daje żadnej wskazówki, że tu się klika |
| W-4 | **Znak marki nie znika** | Belka górna | Garnek widoczny, uśmiech jako dziura | Znak zamienia się w plamę. Wersje z uśmiechem malowanym na biało (`kuking-icon-smile-inverse.svg`) robią to zawsze; `kuking-znak-wyciety.svg` nie |
| W-5 | **Stan wybrany nie ginie** | `/dodaj/przepis`, karty „Kto ma widzieć ten przepis?” | Zaznaczoną kartę poznać po samym polu wyboru, nie tylko po tle | Tło z `.choice:has(input:checked)` znika w forced-colors, a jeśli poza tłem nic się nie zmienia, nie widać wyboru |

---

## R. Ruch

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| R-1 | **Ustawienie systemowe jest respektowane** | Narzędzia przeglądarki → Rendering → `prefers-reduced-motion: reduce` | Nic nie animuje się, nic nie przesuwa | Przejście nadal trwa — reguła z `@layer base` używa `!important` właśnie po to, żeby nikt jej nie nadpisał |
| R-2 | **Nic nie rusza się samo** | Otwórz ekran i nie dotykaj przez minutę | Nic nie miga, nie przewija się, nie zmienia | Karuzela ruszająca sama. Karuzela zdjęć jest jedynym miejscem z ruchem w poziomie i musi być sterowana wyłącznie przez człowieka |
| R-3 | **Wciśnięcie przycisku nie przesuwa układu** | Kliknij i przytrzymaj przycisk w karcie | Przycisk schodzi o 1 px, nic wokół się nie rusza | Przesuwa się cała karta — `transform` na złym elemencie |
| R-4 | **Najechanie nie przesuwa karty** | Najedź na kartę wpisu | Zmienia się cień | Karta unosi się i przeskakuje pod kursorem |

---

## F. Formularze

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| F-1 | **Etykieta jest widoczna nad polem** | Spójrz na formularz | Każde pole ma widoczną etykietę; podpowiedź w środku pola jest dodatkiem, nigdy jedyną informacją | Etykieta wyłącznie jako `placeholder` — znika w chwili, w której człowiek zaczyna pisać, czyli dokładnie wtedy, gdy jest potrzebna |
| F-2 | **Etykieta jest powiązana z polem** | Kliknij w etykietę | Fokus wchodzi do pola | Nie wchodzi — brak `for`/`id` |
| F-3 | **Podpowiedź jest w opisie pola** | NVDA, wejdź w pole | Czytnik czyta etykietę **i** podpowiedź | Czyta samą etykietę — podpowiedź przeniesiona nad pole (`.field-podpowiedz`) wypadła z `aria-describedby` |
| F-4 | **Oznaczone są pola wymagane, nie nieobowiązkowe** | Spójrz na formularz przepisu | Słowo `wymagane` przy trzech polach, zero gwiazdek | Gwiazdka (czytnik czyta „gwiazdka”) albo `(nieobowiązkowe)` kilkanaście razy pod rząd |
| F-5 | **Zdanie o wymaganych polach jest prawdziwe** | Policz | „Wymagane są trzy pola” i pól z `wymagane` jest dokładnie trzy, a walidacja serwera nie wymaga niczego więcej | Cztery pola albo walidacja odrzuca formularz z powodu pola, przy którym nic nie stało |
| F-6 | **Błąd mówi, co jest nie tak i co zrobić** | Wyślij zły formularz | Dwa zdania: co się stało i co zrobić. Np. „To zdjęcie waży za dużo. Maksymalny rozmiar to 15 MB — wybierz mniejsze zdjęcie.” | „Nieprawidłowa wartość”, kod HTTP, nazwa pola z bazy |
| F-7 | **Błąd jest przy polu i w podsumowaniu** | Wyślij zły formularz | Oba naraz; podsumowanie ma `role="alert"`, pole ma `aria-invalid` i `aria-describedby` | Tylko jedno z dwóch |
| F-8 | **Poprawne dane nie znikają** | Wypełnij formularz, zepsuj jedno pole, wyślij | Wszystko poza tym polem zostaje | Formularz czyści się — przy formularzu przepisu to znaczy stratę pół godziny pracy człowieka |
| F-9 | **Wygasła sesja nie kasuje tekstu** | Poczekaj na wygaśnięcie tokenu i wyślij | Formularz wraca z treścią i świeżym tokenem, jedno kliknięcie „Wyślij jeszcze raz” | Treść przepadła. To jest już rozwiązane (`OdzyskanyFormularz`) i nie wolno tego zgubić przy przebudowie kreatora |
| F-10 | **Pole ma szerokość swojej treści** | Spójrz na formularz przepisu | Rok 8 znaków, porcje 10, nazwa składnika 22, tytuł 40, akapit na całą szerokość | Wszystkie pola jednakowe — prostokąt na 40 znaków pod pytaniem „Ile porcji?” mówi człowiekowi, że oczekujemy zdania |
| F-11 | **Wyłączony przycisk mówi, dlaczego** | Znajdź przycisk `disabled` | Obok stoi zdanie, co zrobić, żeby zadziałał | Wyłączony i milczy |
| F-12 | **Akcja niszcząca stoi z dala od zapisu** | Ekran z „Usuń” | Oddzielona kreską i odstępem (`.danger-zone`) | „Zapisz” i „Usuń” obok siebie w jednym rzędzie |
| F-13 | **Wybór pliku po polsku** | `/dodaj/zdjecie` | Widać `Dodaj zdjęcie` i zdanie pomocnicze; nigdzie `Choose File` ani `No file chosen` | Angielski napis w środku obszaru |
| F-14 | **Po wybraniu pliku widać, że coś się stało** | Wybierz plik przy wyłączonym skrypcie | Cokolwiek się zmienia | Nic się nie zmienia. **To jest znany koszt D-107** — patrz P-6 w `WDROZENIE.md`. Jeśli decyzja zapadła, punkt zamienia się w: po wysłaniu formularza widać miniaturę i napis „Zmień zdjęcie” |

---

## T. Treść

| # | Co sprawdzić | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| T-1 | **Zero słów z listy zakazanej** | `node 07-wdrozenie/sprawdz-paczke.mjs` | `BŁĘDY (twarde reguły): 0` | Trafienie w pliku HTML. Automat sprawdza tekst **po** usunięciu znaczników, więc `--container-content` przechodzi, a napis „content” nie |
| T-2 | **Zero wykrzykników i zero emoji** | Ten sam automat | 0 | Choćby jeden. Wykrzyknik jest w produkcie zakazany twardo |
| T-3 | **Gra słowem `kuKING` najwyżej raz na ekran** | Policz w **wyrenderowanej** stronie przy danej szerokości, nie w kodzie źródłowym | Najwyżej raz | Dwa razy. Uwaga na fałszywy alarm: tablica ma dwie kopie szyny (`.tylko-szerokie` i `.tylko-waskie`), z których widać zawsze jedną — licząc w źródle wyjdzie dwa, a człowiek zobaczy jeden |
| T-4 | **`kuKING` nie pada w błędzie ani w tekście prawnym** | Przejrzyj komunikaty błędów, moderację, regulamin, politykę | Zero | Żart w miejscu, w którym człowiek ma problem |
| T-5 | **Nazwa funkcji jest jedna** | Porównaj etykiety w całym serwisie | „Zapisz” albo „Zapisuję”, nie oba; jedna postać plakietki konta przykładowego | Synonimy. Dziś w paczce plakietka ma **pięć** postaci, a akcja zapisu **dwie** — `WDROZENIE.md`, P-3 i P-7 |
| T-6 | **Liczba, którą pokazujesz, da się policzyć** | Dla każdej liczby na ekranie zadaj pytanie „z czego to liczę?” | Każda ma źródło w bazie | Liczba obserwujących (zakazana wprost), ranking bez pomiaru, „Popularne teraz”, tag sezonowy z liczbą przepisów |
| T-7 | **Zdanie, które coś obiecuje, ma dowód w kodzie** | Sekcja 8 w `WDROZENIE.md` | Każdy napis ma wskazany mechanizm | Zdanie o eksporcie, prywatności albo braku reklam bez sprawdzenia, czy jest prawdziwe |
| T-8 | **Ikona nigdy nie jest jedynym opisem akcji** | Przejrzyj ekran | Każda ikona ma tekst obok | Sam dzwonek, samo kółko, samo „⋮” |
| T-9 | **Kolor nigdy nie jest jedynym sygnałem** | Przejrzyj cztery stany: bieżąca pozycja dolnego paska, wiersz nieprzeczytany, pole z błędem, karta wybrana | Kreska 3 px u góry pozycji · pionowa kreska przy krawędzi wiersza · obwódka pola grubsza (3 px zamiast 2) · obwódka i tło karty | Sam kolor. Wszystkie cztery sygnały są w arkuszu i wszystkie cztery da się skasować jedną „szybką poprawką dla czystości”. **Patrz U-1 i U-2** |
| T-10 | **Nic nie sugeruje wieku odbiorcy** | Przeczytaj cały ekran | Zero | „senior”, „dla starszych”, „łatwy nawet dla…”, „intuicyjny” |

---

## A. Co da się sprawdzić automatem

Gotowe polecenia. Kolejność ma znaczenie: dwa pierwsze **budują** pliki,
które sprawdzają następne. Sprawdzanie stanu sprzed przebudowy mierzy
poprzednią wersję i wypada zielono na czymś, czego już nie ma.

```bash
# 1. Zbuduj to, co jest wynikiem — tokeny i arkusz podglądu
node 01-fundamenty/zbuduj-tokeny.mjs      # składa tokens.json z policzonymi kontrastami
node podglad/zbuduj-podglad.mjs           # składa podglad/podglad.css z tokens.css + komponenty.css

# 2. Kontrast — 70 par, jasny i ciemny. Kod 1 przy niepowodzeniu
node 01-fundamenty/kontrast.mjs

# 3. Twarde ograniczenia paczki — skrypt w widoku, style=, wartości z ręki,
#    słowa zakazane w tekście widocznym, wykrzykniki. Kod 1 przy błędzie
node 07-wdrozenie/sprawdz-paczke.mjs

# 4. Układ w prawdziwej przeglądarce: 5 szerokości × 3 skale tekstu,
#    przewijanie w poziomie i minimum 48 px na kontrolkę. Kod 1 przy błędzie
node 07-wdrozenie/sprawdz-uklad.mjs
```

Stan w chwili pisania tej listy:

| Automat | Kryterium przejścia | Wynik w chwili pisania |
|---|---|---|
| `kontrast.mjs` | kod wyjścia **0** | 70 par, 0 nie przechodzi |
| `sprawdz-paczke.mjs` | kod wyjścia **0** | 60 plików, 0 błędów twardych; uwagi wyłącznie w dokumentacji cytującej listę zakazaną, w tym w tym pliku |
| `sprawdz-uklad.mjs` | kod wyjścia **0** | 13 stron, 260 pomiarów, 0 problemów |

**Bramką jest kod wyjścia, nie liczba stron.** Liczba stron i plików rośnie,
bo paczka jest w robocie — przepisana do dokumentu starzeje się w tydzień.
Uwagi z `sprawdz-paczke.mjs` w plikach `.md` nie przewracają wyniku i nie
mają: dokumentacja ma prawo cytować listę słów zakazanych, a punkt T-10 tej
listy musi nazwać słowa, których zakazuje.

### A.0 Ograniczenie `sprawdz-uklad.mjs`, o którym trzeba wiedzieć

**Ten automat pilnuje STRONY, nie KOMPONENTU.** Mierzy
`document.scrollWidth` przeciw `clientWidth`, więc komponent szerszy niż okno
**nie zgłosi się**, jeżeli strona zamknęła go w kontenerze
z `overflow-x: auto`.

To nie jest teoria — zdarzyło się w tej paczce:

- **Było.** Belka górna nie mieściła się na telefonie. Zmierzone w Chromium
  przy 320 px: `.topbar-akcje` żądał **363 px**, cała belka kończyła się na
  **613 px**. Automat zgłaszał 12 problemów, wszystkie w `galeria.html`.
- **Potem zzieleniał, zanim przyczyna została naprawiona**, bo galeria
  owinęła podgląd belki w `.przewijana { overflow-x: auto }`. Dla galerii to
  jest rozwiązanie poprawne — pokazuje szeroki komponent na wąskiej stronie.
  Ale zielony wynik znaczył wtedy „żadna strona się nie przewija”, a nie
  „belka mieści się na 320 px”.
- **Jest.** `komponenty.css` sekcja 16 dokłada
  `.topbar-wnetrze { flex-wrap: wrap }`, `.topbar-akcje { flex-wrap: wrap;
  min-width: 0 }` i ukrycie pola wyszukiwania poniżej 64rem. Zmierzone
  **na makiecie `03-szablony/tablica.html`, czyli na belce bez otoczki**:
  przy 320 px `.topbar-akcje` ma **207 px**, przy 320 px i skali 150 % —
  **273 px**, dokument w obu przypadkach 320 px, bez przewijania.

**Reguła do zapamiętania: komponent sprawdza się na stronie, która go używa
bez otoczki.** Zielony automat plus podgląd w przewijanym oknie to nie jest
dowód. Dowodem jest pomiar na makiecie, która ten komponent stawia tak, jak
postawi go widok.

Dla widoku Blade własnego przewijanego okna zresztą nie ma: twarde
ograniczenie nr 7 zakazuje przewijania strony w poziomie, a jedynym
świadomym wyjątkiem jest karuzela zdjęć. Jedyne miejsce, w którym własna
otoczka jest na miejscu, to szeroka tabela w dokumencie prawnym — arkusz ma
na to `.tabela-otoczka`.

### A.1 W repozytorium serwisu

```bash
./scripts/check.sh --dostepnosc        # w ramach kontroli przed wysłaniem
node scripts/dostepnosc.mjs            # axe-core, 11 ekranów × 4 warianty = 44 przebiegi
node scripts/dostepnosc.mjs --szybko   # sam wariant jasny
node scripts/zrzuty-wygladu.mjs        # 60 zrzutów: 15 ekranów × komputer/telefon × jasny/ciemny
```

Wynik `dostepnosc.mjs` idzie do `storage/dostepnosc.json`, bo przy 44
przebiegach lista naruszeń nie mieści się w oknie terminala. Kod wyjścia jest
niezerowy tylko przy wagach `critical` i `serious`.

**Zasada porównania:** przed każdym etapem zapisz
`cp storage/dostepnosc.json storage/dostepnosc-przed.json`. Bramką jest
**brak nowych naruszeń**, nie zero naruszeń — inaczej pierwsza zastana
usterka blokuje wszystko, co po niej.

### A.2 Czego automat nie złapie i trzeba zrobić ręką

Automaty łapią to, co da się zmierzyć na jednym elemencie. Nie łapią:

- **kolejności fokusu** (K-3) — axe widzi, że fokus jest, nie widzi, że skacze;
- **czy komunikat błędu mówi, co zrobić** (F-6) — widzi, że jest powiązany;
- **czy zdanie na ekranie jest prawdziwe** (T-7) — to jest sekcja 8
  `WDROZENIE.md` i praca człowieka;
- **czy plakietka cicha nie niesie informacji, której nigdzie indziej nie ma**
  (KO-4);
- **czy podkład pod tekstem na zdjęciu wystarczy na najjaśniejszym zdjęciu,
  jakie ktoś wrzuci** (KO-3) — automat mierzy zdjęcie, które akurat jest;
- **czy `kuKING` nie brzmi w NVDA jak bełkot** (C-5);
- **czy człowiek wie, co ma kliknąć** — na to jest test z trzynastoma osobami
  (`A11Y_CHECKLIST.md` §D), a mierzoną wartością jest liczba momentów,
  w których pada pytanie „Co mam teraz kliknąć?”. Cel: zero na scenariuszu
  dodania zdjęcia.

---

## U. Dwie usterki znalezione przy pisaniu tej listy — obie naprawione

Zapis zostaje w formie **było / jest / jak sprawdzone**, bo obie należą do
tej samej klasy błędu (kolor jako jedyny sygnał) i obie wrócą, jeśli ktoś
uzna te reguły za ozdobę i skróci je „dla czystości”.

### U-1. Bieżąca pozycja dolnego paska — sygnał niezależny od koloru

**Było.** `komponenty.css` ustawiał wyłącznie kolor i grubość:

```css
.bottom-nav-item[aria-current="page"] { color: var(--color-brand-tint-ink); font-weight: 800; }
```

Dzisiejszy `03-kod-wygladu/app.css` (wiersze 410–413) miał do tego kreskę
`box-shadow: inset 0 3px 0 0 var(--color-brand)`, a
`STAN_WDROZENIA_KITU.md`, etap D, zapisuje ją jako świadomą poprawkę:
„bieżąca pozycja kolorem i pogrubieniem — **jest**, plus pasek 3 px u góry —
**kolor nigdy nie jest jedynym sygnałem (WCAG 1.4.1)**”. Nowa warstwa
cofała ją, nie odnotowując, że cofa.

**Jest.** `komponenty.css`, wiersze 237–241:

```css
.bottom-nav-item[aria-current="page"] {
  color: var(--color-brand-tint-ink);
  font-weight: 800;
  box-shadow: inset 0 3px 0 0 var(--color-brand-solid);
}
```

`inset box-shadow`, a nie `border-top` — inaczej pozycja urosłaby o 3 px
i pasek drgałby przy przejściu między zakładkami.

**Jak sprawdzone.** Punkt T-9 tej listy: patrz na dolny pasek w obu motywach
i przy skali 140 %. Bieżąca pozycja ma mieć kreskę u góry, nie sam kolor.

### U-2. Wiersz nieprzeczytany — sygnał niezależny od koloru

**Było.** Jedyna różnica między wierszem przeczytanym a nieprzeczytanym była
w tle:

```css
.wiersz-nieprzeczytany { background-color: var(--color-brand-tint); }
```

Czytnik ekranu tła nie widzi, a osoba słabiej rozróżniająca barwy nie odróżni
`#F5E4DC` od `#FFFFFF` z pewnością.

**Jest.** `komponenty.css`, wiersze 853–856 — tło **plus** pionowa kreska
przy krawędzi:

```css
.wiersz-nieprzeczytany {
  background-color: var(--color-brand-tint);
  box-shadow: inset var(--spacing-1) 0 0 0 var(--color-brand-solid);
}
```

**Czego arkusz nadal nie wymusi.** Kreska pomaga oku, ale nie czytnikowi
ekranu. Znacznik musi do tego nieść **słowo** — plakietkę `nowe` albo tekst
wyłącznie dla czytnika. Komentarz przy tej regule mówi to wprost, a punkt
C-10 tej listy to sprawdza. **Reguła: `.wiersz-nieprzeczytany` nigdy bez
słowa w treści wiersza.**
