> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Szablony — trzy ekrany, pięć plików

Makiety trzech najważniejszych ekranów serwisu, każda jako **jeden responsywny
plik HTML**: ten sam plik działa przy 320, 390 i 1440 px. Bez JavaScriptu, bez
atrybutów `style=`, bez wartości koloru, rozmiaru i odstępu wpisanych z ręki.

## Jak otworzyć

Podwójne kliknięcie w plik. Nic nie trzeba instalować — makiety linkują
`../podglad/podglad.css`, który jest zbudowany z `01-fundamenty/tokens.css`
i `02-komponenty/komponenty.css`.

Jeśli ktoś zmieni któryś z tych dwóch arkuszy, trzeba przebudować podgląd:

```
node podglad/zbuduj-podglad.mjs
```

Dwie rzeczy, które warto zrobić w przeglądarce, bo makiety są na nie
przygotowane:

- **tryb ciemny** — w konsoli `document.documentElement.dataset.theme = 'dark'`
  (w serwisie robi to przełącznik w stopce, ten sam, który jest w makiecie);
- **większy tekst** — `document.documentElement.dataset.textScale = '150'`.

## Co który plik pokazuje

| plik | ekran | kształt treści |
|---|---|---|
| `tablica.html` | 08 `/home` — w interfejsie **Start** | strumień kart + prawa szyna |
| `przepis.html` | 07 `/przepisy/…` | długi dokument z przyklejonym panelem obok |
| `dodaj-przepis-krok-1.html` | 13 `/dodaj/przepis` | krok 1: co to jest |
| `dodaj-przepis-krok-2.html` | 13 `/dodaj/przepis/skladniki` | krok 2: składniki |
| `dodaj-przepis-krok-3.html` | 13 `/dodaj/przepis/kroki` | krok 3: kroki i publikacja |

Nazwa pliku `tablica.html` jest robocza i pochodzi z numeracji zrzutów.
**W interfejsie ten ekran nazywa się „Start"** — „tablica" jest na liście słów,
których nie używamy (`BRAND_EXTENDED.md` §1.1).

Kroki są ze sobą połączone: „Dalej" i „Wstecz" prowadzą do sąsiednich plików,
a „Opublikuj przepis" na kroku 3 otwiera `przepis.html`. Można przejść całą
drogę klikaniem, tak jak człowiek.

## Które decyzje widać na którym ekranie

| decyzja | gdzie to widać |
|---|---|
| **D-101** interlinia tytułu, który się zawija | tytuły kart w `tablica.html` przy 320 px |
| **D-102** trzecia kolumna zawsze, także pusta | wszystkie pięć plików ma szynę; na przepisie jest w niej panel, na formularzu pomoc do kroku |
| **D-103** plakietka „konto przykładowe" cicha | trzecia karta w `tablica.html` i pierwsza karta w „Komu wyszło" — plakietka stoi w wierszu metadanych, po kropce |
| **D-105** projekt stoi na zdjęciach, a brak zdjęcia ma własny wygląd | karta „Zakwas po dziesięciu dniach" (`karta-bez-zdjecia`) i drugie „Ugotowałem" na przepisie |
| **D-106** oznaczamy **wymagane**, nie „(nieobowiązkowe)" | trzy pola w całym kreatorze: nazwa przepisu (krok 1), składniki (krok 2), kroki (krok 3) — zdanie „Wymagane są trzy pola" stoi nad krokiem 1 |
| **D-107** wybór pliku przez `<label for>` | „Dodaj zdjęcie" na kroku 1 i „Skan kartki z przepisem" na kroku 3 — nigdzie nie ma „Choose File" |
| **D-108** trzy strony zamiast jednej | trzy pliki, pasek „Krok X z 3" i link „Otwórz formularz na jednej stronie" pod krokiem 1 |
| **D-109** pole ma szerokość swojej treści | krok 1: porcje i minuty w `field-input-liczba`, nazwa przepisu w `-srednie`; krok 3: rok w `-rok`, „po kim" w `-krotkie`, historia w `-dlugie` |
| **D-110** jeden akcent na powierzchnię | na każdym ekranie dokładnie jeden przycisk główny: „Dodaj zdjęcie tego, co ugotowałeś" (Start), „Ugotowałem" (przepis), „Dalej"/„Opublikuj przepis" (kroki). Numery kroków przepisu są atramentem, nie terakotą |
| **D-111** układ trzyma do 150% | sprawdzone automatem, patrz niżej |

## Odpowiedź na problemy z dzisiejszego serwisu

1. **Kolumna 45rem i puste marginesy** → jedna szerokość strony (belka, siatka
   i stopka zaczynają się w tym samym miejscu), trzecia kolumna z treścią,
   której nie ma w kolumnie głównej.
2. **Karta powtarza cztery rzeczy** → została nazwa autora, jedna linia
   metadanych i **tytuł wpisu**, którego dziś nie ma. Oko siada na tytule, nie
   na nazwisku i nie na plakietce.
3. **Wszystkie przyciski tej samej wagi** → w stopce karty „Napisz komentarz"
   jest obrysowany, „Zapisz" cichy, licznik komentarzy jest zwykłym linkiem.
4. **Szyna powtarzała kolumnę główną** → w szynie jest to, czego obok nie ma:
   szkic czekający na dokończenie i „kuKINGi na dziś" (osoby i dania spoza
   strumienia). Ani jeden wpis nie powtarza się między kolumnami.
5. **Typografia prawie jednolita** → tytuł 24/800, treść 20/400, autor 18/700,
   metadane 15/600 w `ink-muted`.
6. **Pole szukania szersze niż treść** → pole siedzi w środkowej kolumnie tej
   samej siatki co treść pod spodem; widać to na `tablica.html` przy 1440 px.
7. **„Choose File / No file chosen"** → D-107.
8. **„WszyscyTakże osoby bez konta"** → `.choice` z osobnym `.choice-help`.
9. **„(nieobowiązkowe)" kilkanaście razy** → D-106.
10. **Formularz pokazuje wszystko naraz** → D-108.
11. **Pola jednakowymi prostokątami** → D-109.

## Rzeczy, które trzeba wiedzieć, zanim się to wdroży

**Dwie kopie tej samej treści, przełączane szerokością.** Trzecia kolumna
(`.app-rail`) znika poniżej 80rem, więc treść, która na szerokim ekranie siedzi
w szynie, ma drugą kopię w kolumnie głównej opatrzoną klasą `.tylko-waskie`.
W danym momencie widoczna jest dokładnie jedna — druga jest `display: none`,
czyli nie ma jej też w drzewie dostępności i czytnik ekranu czyta ją raz.
W Blade to jest jeden `@include` użyty dwa razy, nie dwa różne kawałki kodu.
Dotyczy panelu przepisu i sekcji „kuKINGi na dziś".

**Zdjęcia się powtarzają.** W `podglad/zdjecia/` jest pięć zdjęć potraw, więc
te same pierogi są i w strumieniu, i na przepisie, i w „Komu wyszło". To jest
ograniczenie danych demonstracyjnych, nie projektu — D-105 punkt 2 mówi wprost,
że **przed betą trzeba zasiać dane demonstracyjne prawdziwymi zdjęciami**.
Trzy z pięciu plików mają niecałe 100 px szerokości, dlatego w szynie stoją
w rozmiarze zbliżonym do natywnego, a duże kadry biorą tylko te dwa, które są
odpowiednio duże.

**W makiecie nie ma stanu błędu.** `.error-summary`, `.field-error` i
`.alert-blad` są w arkuszu komponentów i w galerii; formularz w makiecie
pokazuje stan, w którym wszystko jest wypełnione poprawnie, żeby było widać
docelowy układ, a nie awarię.

**Blok `<style>` na górze każdego pliku.** To jest jedna propozycja do
`02-komponenty/komponenty.css`, wklejona w pięć plików w identycznej postaci —
nie pięć różnych łatek. Każda reguła ma numer i uzasadnienie w komentarzu.
Po przeniesieniu jej do arkusza komponentów bloki znikają z makiet bez żadnej
innej zmiany w kodzie.

## Jak to zostało sprawdzone

```
node 07-wdrozenie/sprawdz-uklad.mjs     # przeglądarka: 320/390/768/1024/1440 px × tekst 100/140/150%
node 07-wdrozenie/sprawdz-paczke.mjs    # skrypt, style=, wartości spoza tokenów, słowa zakazane
```

Na dzień oddania: **200 pomiarów układu bez ani jednego przewijania w poziomie
i bez kontrolki niższej niż 48 px**, oraz **zero złamanych twardych reguł**
w kontroli paczki. Do tego zrzuty każdego pliku przy 390 i 1440 px oraz przy
390 px ze skalą tekstu 150% — oglądane i poprawione (belka, szyna, szerokość
pola składnika).
